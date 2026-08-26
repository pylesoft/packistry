<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SourceProvider;
use App\Jobs\RefreshComposerPackage;
use App\Models\Package;
use Illuminate\Console\Command;

class RefreshComposerPackages extends Command
{
    protected $signature = 'composer-packages:refresh';

    protected $description = 'Dispatch refresh jobs for enrolled Composer packages';

    public function handle(): int
    {
        Package::query()
            ->whereHas('source', fn ($query) => $query
                ->where('provider', SourceProvider::COMPOSER)
                ->where('enabled', true))
            ->where(function ($query): void {
                $query->whereNull('upstream_checked_at')
                    ->orWhere('upstream_checked_at', '<=', now()->subHour());
            })
            ->get()
            ->each(fn (Package $package) => RefreshComposerPackage::dispatchFor($package));

        return self::SUCCESS;
    }
}
