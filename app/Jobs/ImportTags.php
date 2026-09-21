<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Package;
use App\Models\Source;
use App\Sources\Project;
use App\Sources\Tag;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportTags implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public function __construct(
        private readonly Source $source,
        private readonly Package $package,
        private readonly Project $project
    ) {
        //
    }

    public function handle(): void
    {
        $batch = $this->batch();

        $this->source->vcsClient()->tags($this->project)
            ->each(function (Tag $tag) use ($batch): void {
                $batch?->add(new ReconcilePushedReference(
                    $this->source,
                    $this->package,
                    $tag->name,
                    true,
                ));
            });
    }
}
