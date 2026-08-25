<?php

declare(strict_types=1);

namespace App\Composer;

use App\CreateFromZip;
use App\Exceptions\ComposerUpstreamException;
use App\Models\Package;
use App\Normalizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

readonly class SynchronizeComposerPackage
{
    public function __construct(private CreateFromZip $createFromZip) {}

    /** @param array<string, mixed>|null $discoveredMetadata */
    public function handle(Package $package, ?array $discoveredMetadata = null): void
    {
        $upstream = $package->composerUpstream;
        if ($upstream === null || ! $upstream->enabled) {
            return;
        }

        $package->loadMissing('repository');
        $client = $upstream->client();
        $metadata = $discoveredMetadata ?? $client->package($package->name, $package->upstream_etag, $package->upstream_last_modified);
        if (($metadata['not_modified'] ?? false) === true) {
            DB::transaction(function () use ($package, $upstream): void {
                $package->forceFill(['upstream_checked_at' => now()])->save();
                $upstream->forceFill([
                    'health_status' => 'healthy',
                    'last_error' => null,
                    'last_checked_at' => now(),
                ])->save();
            });

            return;
        }
        $seen = [];

        $versions = $metadata['versions'] ?? null;
        if (! is_array($versions)) {
            throw new ComposerUpstreamException('Composer upstream returned invalid package metadata.');
        }

        $firstVersion = null;
        foreach ($versions as $candidate) {
            if (is_array($candidate)) {
                $firstVersion = $candidate;
                break;
            }
        }

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
            $existing = $package->versions()->where('name', $normalized)->first();
            $expectedHash = isset($dist['shasum']) && is_string($dist['shasum']) && $dist['shasum'] !== ''
                ? strtolower($dist['shasum'])
                : null;
            $distIdentity = hash('sha256', $dist['url']);
            $existingIdentity = is_array($existing?->metadata)
                ? ($existing->metadata['upstream_dist_identity'] ?? null)
                : null;

            if (
                $existing !== null
                && $existing->archive_path !== null
                && (($expectedHash !== null && strtolower($existing->shasum) === $expectedHash)
                    || ($expectedHash === null && $existingIdentity === $distIdentity))
            ) {
                $existing->forceFill([
                    'metadata' => $this->versionMetadata($versionData, $distIdentity),
                    'upstream_removed_at' => null,
                ])->save();

                continue;
            }

            $response = $client->archive($dist['url']);
            if ($response->failed()) {
                throw new ComposerUpstreamException('Composer package archive download failed.');
            }

            $temporary = tmpfile();
            if ($temporary === false) {
                throw new RuntimeException('Failed to create temporary archive.');
            }

            try {
                $path = stream_get_meta_data($temporary)['uri'] ?? null;
                if (! is_string($path)) {
                    throw new RuntimeException('Temporary archive path is unavailable.');
                }

                file_put_contents($path, $response->body());
                $actualHash = hash_file('sha1', $path);
                if ($actualHash === false || ($expectedHash !== null && strtolower($actualHash) !== $expectedHash)) {
                    throw new ComposerUpstreamException('Composer package archive checksum mismatch.');
                }

                $archiveMetadata = $this->createFromZip->metadata($path);
                if (($archiveMetadata['name'] ?? null) !== $package->name) {
                    throw new ComposerUpstreamException('Composer package archive identity mismatch.');
                }

                $version = $this->createFromZip->create(
                    package: $package,
                    path: $path,
                    version: $versionName,
                );
                $version->forceFill([
                    'metadata' => $this->versionMetadata($versionData, $distIdentity),
                    'upstream_removed_at' => null,
                ])->save();
            } finally {
                fclose($temporary);
            }
        }

        if (is_array($firstVersion)) {
            $package->forceFill([
                'description' => isset($firstVersion['description']) && is_string($firstVersion['description'])
                    ? $firstVersion['description']
                    : $package->description,
                'type' => isset($firstVersion['type']) && is_string($firstVersion['type']) && $firstVersion['type'] !== ''
                    ? $firstVersion['type']
                    : $package->type,
            ])->save();
        }

        DB::transaction(function () use ($package, $metadata, $seen, $upstream): void {
            $package->versions()
                ->whereNotIn('name', $seen)
                ->whereNull('upstream_removed_at')
                ->update(['upstream_removed_at' => now()]);

            $package->forceFill([
                'upstream_checked_at' => now(),
                'upstream_synced_at' => now(),
                'upstream_etag' => $metadata['etag'] ?? $package->upstream_etag,
                'upstream_last_modified' => $metadata['last_modified'] ?? $package->upstream_last_modified,
            ])->save();

            $upstream->forceFill([
                'health_status' => 'healthy',
                'last_error' => null,
                'last_checked_at' => now(),
                'last_synced_at' => now(),
            ])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $version
     * @return array<string, mixed>
     */
    private function versionMetadata(array $version, string $distIdentity): array
    {
        return collect($version)
            ->except(['name', 'version', 'dist', 'type'])
            ->put('upstream_dist_identity', $distIdentity)
            ->all();
    }
}
