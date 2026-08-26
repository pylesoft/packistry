<?php

declare(strict_types=1);

namespace App\Actions\Packages;

use App\Actions\Packages\Inputs\StorePackageInput;
use App\Enums\PackageType;
use App\Enums\SourceProvider;
use App\Exceptions\ArchiveInvalidContentTypeException;
use App\Exceptions\ComposerJsonNotFoundException;
use App\Exceptions\ComposerRepositoryException;
use App\Exceptions\FailedToFetchArchiveException;
use App\Exceptions\FailedToOpenArchiveException;
use App\Exceptions\NameNotFoundException;
use App\Exceptions\VersionNotFoundException;
use App\Jobs\Batches\PackageImportBatch;
use App\Jobs\RefreshComposerPackage;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Source;
use App\Sources\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class StorePackage
{
    /**
     * @return Package[]
     *
     * @throws ArchiveInvalidContentTypeException
     * @throws ComposerJsonNotFoundException
     * @throws FailedToFetchArchiveException
     * @throws FailedToOpenArchiveException
     * @throws NameNotFoundException
     * @throws VersionNotFoundException
     * @throws ConnectionException
     * @throws Throwable
     */
    public function handle(StorePackageInput $input): array
    {
        /** @var Repository $repository */
        $repository = Repository::query()->userScoped()->findOrFail($input->repository);

        /** @var Source $source */
        $source = Source::query()->findOrFail($input->source);

        if ($source->provider === SourceProvider::COMPOSER) {
            return [$this->storeComposerPackage($repository, $source, $input)];
        }

        if ($input->projects instanceof Optional || $input->projects === []) {
            throw ValidationException::withMessages(['projects' => 'Select at least one project.']);
        }

        $client = $source->vcsClient();

        $projects = array_map(fn (string $id): Project => $client->project($id), $input->projects);

        $packages = [];

        foreach ($projects as $project) {
            if (! ($input->webhook instanceof Optional) && $input->webhook && ! $project->readOnly) {
                try {
                    $client->createWebhook($repository, $project, $source);
                } catch (RequestException $e) {
                    throw ValidationException::withMessages([
                        'projects' => [
                            "Failed to create webhook for $project->fullName: {$e->response->body()}",
                        ],
                    ]);
                }
            }

            /** @var Package $package */
            $package = $repository
                ->packages()
                ->where('source_id', $source->id)
                ->where('provider_id', $project->id)
                ->first() ?? $repository->packages()->make();

            if (! $package->exists) {
                $package->provider_id = (string) $project->id;
                $package->source_id = $source->id;

                $package->name = $project->fullName;
                $package->type = PackageType::LIBRARY->value;

                $package->save();
            }

            PackageImportBatch::make($source, $package, $project)
                ->dispatch();

            $packages[] = $package;
        }

        return $packages;
    }

    private function storeComposerPackage(Repository $repository, Source $source, StorePackageInput $input): Package
    {
        if (! $source->enabled) {
            throw ValidationException::withMessages(['source' => 'The Composer source is disabled.']);
        }

        if ($input->name instanceof Optional || preg_match('/^[^\/\s]+\/[^\/\s]+$/', $input->name) !== 1) {
            throw ValidationException::withMessages(['name' => 'The package name must be a Composer vendor/name.']);
        }

        $existing = $repository->packageByName($input->name);
        if ($existing !== null && $existing->source_id !== $source->id) {
            throw ValidationException::withMessages(['name' => 'This package is already owned by another source.']);
        }

        try {
            $metadata = $source->composerClient()->package($input->name);
        } catch (ComposerRepositoryException $exception) {
            $notFound = $exception->getMessage() === 'Package was not found upstream.';

            throw ValidationException::withMessages([
                $notFound ? 'name' : 'source' => $notFound
                    ? 'The package could not be found upstream.'
                    : 'The Composer source could not provide package metadata.',
            ]);
        }

        if ($metadata['versions'] === []) {
            throw ValidationException::withMessages(['name' => 'The upstream returned no package versions.']);
        }

        $package = $existing ?? $repository->packages()->make();
        $package->forceFill([
            'name' => $input->name,
            'type' => PackageType::LIBRARY->value,
            'source_id' => $source->id,
            'provider_id' => null,
        ])->save();

        if (RefreshComposerPackage::dispatchFor($package) === null) {
            throw new HttpException(409, 'A refresh for this package is already in progress.');
        }

        return $package;
    }
}
