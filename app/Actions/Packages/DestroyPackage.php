<?php

declare(strict_types=1);

namespace App\Actions\Packages;

use App\Models\Package;
use App\Models\Version;
use Illuminate\Support\Facades\Storage;

class DestroyPackage
{
    public function handle(Package $package): Package
    {
        $paths = $package->versions()
            ->with('archives')
            ->get()
            ->flatMap(fn (Version $version) => $version->archivePaths())
            ->unique()
            ->values()
            ->toArray();

        dispatch(function () use ($paths): void {
            Storage::disk()->delete($paths);
        });

        $package->delete();

        return $package;
    }
}
