<?php

declare(strict_types=1);

namespace App;

use App\Enums\PackageType;
use App\Exceptions\ComposerJsonNotFoundException;
use App\Exceptions\FailedToOpenArchiveException;
use App\Exceptions\NameNotFoundException;
use App\Exceptions\VersionNotFoundException;
use App\Models\Package;
use App\Models\Version;
use App\Traits\ComposerFromZip;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CreateFromZip
{
    use ComposerFromZip;

    /**
     * @return array<string, mixed>
     *
     * @throws ComposerJsonNotFoundException
     * @throws FailedToOpenArchiveException
     */
    public function metadata(string $path): array
    {
        return $this->decodedComposerJsonFromZip($path);
    }

    /**
     * @throws VersionNotFoundException
     * @throws ComposerJsonNotFoundException
     * @throws FailedToOpenArchiveException
     * @throws NameNotFoundException
     */
    public function create(
        Package $package,
        string $path,
        ?string $version = null,
    ): Version {
        $decoded = $this->decodedComposerJsonFromZip($path);
        $version ??= $decoded['version'] ?? throw new VersionNotFoundException('no version provided');
        $hash = hash_file('sha1', $path);
        if ($hash === false) {
            throw new RuntimeException('failed to calculate hash');
        }
        $createdVersion = $package->versions()->where('name', Normalizer::version($version))->first();
        $existingArchive = $createdVersion !== null
            ? $createdVersion->archives()->where('shasum', $hash)->first()
            : null;
        $archivePath = is_null($existingArchive)
            ? $package->repository->archivePath(Str::uuid7()->toString().'.zip')
            : $existingArchive->archive_path;
        $storedPath = false;

        if (! Storage::disk()->exists($archivePath)) {
            $this->storeArchive($path, $archivePath);
            $storedPath = is_null($existingArchive);
        }

        try {
            return $this->createFromStoredArchive($package, $archivePath, $hash, $decoded, $version);
        } catch (\Throwable $exception) {
            if ($storedPath) {
                Storage::disk()->delete($archivePath);
            }

            throw $exception;
        }
    }

    public function stageArchive(Package $package, string $path): string
    {
        do {
            $archivePath = $package->repository->archivePath(Str::uuid7()->toString().'.zip');
        } while (Storage::disk()->exists($archivePath));

        try {
            $this->storeArchive($path, $archivePath);
        } catch (\Throwable $exception) {
            Storage::disk()->delete($archivePath);

            throw $exception;
        }

        return $archivePath;
    }

    /** @param array<string, mixed> $decoded */
    public function createFromStoredArchive(
        Package $package,
        string $archivePath,
        string $hash,
        array $decoded,
        ?string $version = null,
    ): Version {
        $version ??= $decoded['version'] ?? throw new VersionNotFoundException('no version provided');
        $name = $decoded['name'] ?? throw new NameNotFoundException('no name provided');
        $currentOrder = Normalizer::versionOrder($version);
        $latestOrder = $package->versions()->max('order');

        if ($latestOrder === null || $currentOrder >= $latestOrder) {
            $package->name = $name;
        }

        $package->description = $decoded['description'] ?? null;
        $package->type = array_key_exists('type', $decoded) && $decoded['type'] !== '' && $decoded['type'] !== null
            ? $decoded['type']
            : PackageType::LIBRARY->value;

        if ($package->isDirty()) {
            $package->save();
        }

        $createdVersion = $package
            ->versions()
            ->where('name', $versionName = Normalizer::version($version))
            ->first() ?? new Version;
        $metadata = $this->versionMetadata($decoded);

        DB::transaction(function () use ($archivePath, $createdVersion, $currentOrder, $hash, $metadata, $package, $versionName): void {
            $createdVersion->package_id = $package->id;
            $createdVersion->name = $versionName;
            $createdVersion->order = $currentOrder;
            $createdVersion->shasum = $hash;
            $createdVersion->archive_path = $archivePath;
            $createdVersion->metadata = $metadata;
            $createdVersion->save();

            $createdVersion->archives()->firstOrCreate(
                ['shasum' => $hash],
                ['archive_path' => $archivePath]
            );
        });

        return $createdVersion;
    }

    private function storeArchive(string $sourcePath, string $archivePath): void
    {
        $stream = fopen($sourcePath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('failed to open archive stream');
        }

        try {
            if (Storage::disk()->put($archivePath, $stream) === false) {
                throw new RuntimeException('failed to store archive stream');
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function versionMetadata(array $decoded): array
    {
        return collect($decoded)->only([
            'description',
            'readme',
            'keywords',
            'homepage',
            'license',

            'authors',
            'support',
            'funding',

            'bin',

            'autoload',
            'autoload-dev',

            'require',
            'require-dev',
            'conflict',
            'provide',
            'replace',
            'suggest',

            'minimum-stability',
            'prefer-stable',

            'scripts',
            'extra',
            'config',
            'repositories',

            'archive',
            'abandoned',

            '_comment',
            'non-feature-branches',
        ])->toArray();
    }
}
