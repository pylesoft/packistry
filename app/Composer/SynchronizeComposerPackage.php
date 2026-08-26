<?php

declare(strict_types=1);

namespace App\Composer;

use App\CreateFromZip;
use App\Enums\SourceProvider;
use App\Exceptions\ComposerUpstreamException;
use App\Models\Package;
use App\Normalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

readonly class SynchronizeComposerPackage
{
    public function __construct(private CreateFromZip $createFromZip) {}

    /** @param array<string, mixed>|null $discoveredMetadata */
    public function handle(Package $package, ?array $discoveredMetadata = null): void
    {
        $upstream = $package->source;
        if ($upstream === null || $upstream->provider !== SourceProvider::COMPOSER || ! $upstream->enabled) {
            return;
        }

        $package->loadMissing(['repository', 'versions']);
        $client = $upstream->composerClient();
        $metadata = $discoveredMetadata ?? $client->package($package->name, $package->upstream_etag, $package->upstream_last_modified);
        if (($metadata['not_modified'] ?? false) === true) {
            DB::transaction(function () use ($package): void {
                $package->forceFill([
                    'upstream_checked_at' => now(),
                    'upstream_last_error' => null,
                ])->save();
            });

            return;
        }
        $seen = [];

        $versions = $metadata['versions'] ?? null;
        if (! is_array($versions) || $versions === []) {
            throw new ComposerUpstreamException('Composer upstream returned invalid package metadata.');
        }

        $firstVersion = null;
        foreach ($versions as $candidate) {
            if (is_array($candidate)) {
                $firstVersion = $candidate;
                break;
            }
        }

        $existingVersions = $package->versions->keyBy('name');

        /** @var list<array{version: string, normalized: string, metadata: array<string, mixed>, dist_identity: string, archive: array{path: string, shasum: string, composer: array<string, mixed>}|null}> $prepared */
        $prepared = [];
        $stagedPaths = [];
        $published = false;

        try {
            foreach ($versions as $versionData) {
                if (! is_array($versionData)) {
                    throw new ComposerUpstreamException('Composer package metadata contains an invalid version.');
                }
                $versionName = (string) ($versionData['version'] ?? '');
                $dist = $versionData['dist'] ?? null;

                if (
                    $versionName === ''
                    || ! is_array($dist)
                    || ($dist['type'] ?? null) !== 'zip'
                    || ! isset($dist['url'])
                    || ! is_string($dist['url'])
                    || filter_var($dist['url'], FILTER_VALIDATE_URL) === false
                ) {
                    throw new ComposerUpstreamException('Composer package metadata contains an invalid distribution.');
                }

                $normalized = Normalizer::version($versionName);
                $seen[] = $normalized;
                $existing = $existingVersions->get($normalized);
                $expectedHash = isset($dist['shasum']) && is_string($dist['shasum']) && $dist['shasum'] !== ''
                    ? strtolower($dist['shasum'])
                    : null;
                $distReference = isset($dist['reference']) && is_string($dist['reference']) && $dist['reference'] !== ''
                    ? $dist['reference']
                    : null;
                $distIdentity = hash('sha256', $distReference === null ? 'url:'.$dist['url'] : 'reference:'.$distReference);
                $existingIdentity = is_array($existing?->metadata)
                    ? ($existing->metadata['upstream_dist_identity'] ?? null)
                    : null;
                $archive = null;

                if ($existing === null || $existing->archive_path === null
                    || (($expectedHash === null || strtolower($existing->shasum) !== $expectedHash)
                        && ($expectedHash !== null || $existingIdentity !== $distIdentity))) {
                    $temporary = tmpfile();
                    if ($temporary === false) {
                        throw new RuntimeException('Failed to create temporary archive.');
                    }

                    $path = stream_get_meta_data($temporary)['uri'] ?? null;
                    if (! is_string($path)) {
                        fclose($temporary);
                        throw new RuntimeException('Temporary archive path is unavailable.');
                    }

                    try {
                        $response = $client->archive($dist['url'], $path);
                        if ($response->failed()) {
                            throw new ComposerUpstreamException('Composer package archive download failed.');
                        }

                        $actualHash = hash_file('sha1', $path);
                        if ($actualHash === false || ($expectedHash !== null && strtolower($actualHash) !== $expectedHash)) {
                            throw new ComposerUpstreamException('Composer package archive checksum mismatch.');
                        }

                        $archiveMetadata = $this->createFromZip->metadata($path);
                        if (($archiveMetadata['name'] ?? null) !== $package->name) {
                            throw new ComposerUpstreamException('Composer package archive identity mismatch.');
                        }
                        $existingArchive = $existing?->archives()
                            ->where('shasum', $actualHash)
                            ->first();

                        if ($existingArchive !== null && Storage::disk()->exists($existingArchive->archive_path)) {
                            $archivePath = $existingArchive->archive_path;
                        } else {
                            $archivePath = $this->createFromZip->stageArchive($package, $path);
                            $stagedPaths[] = $archivePath;
                        }

                        $archive = [
                            'path' => $archivePath,
                            'shasum' => $actualHash,
                            'composer' => $archiveMetadata,
                        ];
                    } finally {
                        fclose($temporary);
                    }
                }

                $prepared[] = [
                    'version' => $versionName,
                    'normalized' => $normalized,
                    'metadata' => $versionData,
                    'dist_identity' => $distIdentity,
                    'archive' => $archive,
                ];
            }

            DB::transaction(function () use ($existingVersions, $firstVersion, $metadata, $package, $prepared, $seen): void {
                foreach ($prepared as $versionData) {
                    $version = $existingVersions->get($versionData['normalized']);
                    if ($versionData['archive'] !== null) {
                        $version = $this->createFromZip->createFromStoredArchive(
                            package: $package,
                            archivePath: $versionData['archive']['path'],
                            hash: $versionData['archive']['shasum'],
                            decoded: $versionData['archive']['composer'],
                            version: $versionData['version'],
                        );
                        $existingVersions->put($versionData['normalized'], $version);
                    }

                    if ($version === null) {
                        throw new RuntimeException('Prepared Composer package version is unavailable.');
                    }

                    $version->forceFill([
                        'metadata' => $this->versionMetadata($versionData['metadata'], $versionData['dist_identity']),
                        'upstream_removed_at' => null,
                    ])->save();
                }

                $package->versions()
                    ->whereNotIn('name', $seen)
                    ->whereNull('upstream_removed_at')
                    ->update(['upstream_removed_at' => now()]);

                $attributes = [
                    'latest_version' => collect($seen)
                        ->map(fn (string $name) => $existingVersions->get($name))
                        ->filter(fn ($version): bool => $version?->isStable() === true)
                        ->sortByDesc('order')
                        ->first()?->name,
                    'upstream_checked_at' => now(),
                    'upstream_synced_at' => now(),
                    'upstream_last_error' => null,
                    'upstream_etag' => $metadata['etag'] ?? $package->upstream_etag,
                    'upstream_last_modified' => $metadata['last_modified'] ?? $package->upstream_last_modified,
                ];
                if (is_array($firstVersion)) {
                    $attributes['description'] = isset($firstVersion['description']) && is_string($firstVersion['description'])
                        ? $firstVersion['description']
                        : $package->description;
                    $attributes['type'] = isset($firstVersion['type']) && is_string($firstVersion['type']) && $firstVersion['type'] !== ''
                        ? $firstVersion['type']
                        : $package->type;
                }

                $package->forceFill($attributes)->save();
            });
            $published = true;
        } finally {
            if (! $published && $stagedPaths !== []) {
                Storage::disk()->delete($stagedPaths);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $version
     * @return array<string, mixed>
     */
    private function versionMetadata(array $version, string $distIdentity): array
    {
        return collect($version)
            ->except(['name', 'version', 'dist', 'source', 'notification-url', 'type'])
            ->put('upstream_dist_identity', $distIdentity)
            ->all();
    }
}
