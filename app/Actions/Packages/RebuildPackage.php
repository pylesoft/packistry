<?php

declare(strict_types=1);

namespace App\Actions\Packages;

use App\Enums\SourceProvider;
use App\Jobs\Batches\PackageImportBatch;
use App\Jobs\RefreshComposerPackage;
use App\Models\Package;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class RebuildPackage
{
    /**
     * @throws Throwable
     */
    public function handle(Package $package): void
    {
        if ($package->source?->provider === SourceProvider::COMPOSER) {
            if (! $package->source->enabled) {
                throw ValidationException::withMessages(['source' => 'The Composer source is disabled.']);
            }

            if (RefreshComposerPackage::dispatchFor($package) === null) {
                throw new HttpException(409, 'A refresh for this package is already in progress.');
            }

            return;
        }

        $source = $package->source;

        if ($source === null || $package->provider_id === null) {
            throw new RuntimeException("Package $package->name [$package->id] has no source or provider id");
        }

        $project = $source->vcsClient()->project($package->provider_id);

        PackageImportBatch::make($source, $package, $project)
            ->dispatch();
    }
}
