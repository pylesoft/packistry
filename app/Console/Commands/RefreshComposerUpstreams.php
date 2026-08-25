<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RefreshComposerPackage;
use App\Models\Package;
use Illuminate\Console\Command;

class RefreshComposerUpstreams extends Command
{
    protected $signature = 'composer-upstreams:refresh';

    protected $description = 'Dispatch refresh jobs for enrolled Composer packages';

    public function handle(): int
    {
        Package::query()
            ->whereNotNull('composer_upstream_id')
            ->whereHas('composerUpstream', fn ($query) => $query->where('enabled', true))
            ->where(function ($query): void {
                $query->whereNull('upstream_checked_at')
                    ->orWhere('upstream_checked_at', '<=', now()->subHour());
            })
            ->get()
            ->each(fn (Package $package) => RefreshComposerPackage::dispatchFor($package));

        return self::SUCCESS;
    }
}
