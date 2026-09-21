<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Package;
use App\Models\Source;
use App\Normalizer;
use App\Sources\Branch;
use App\Sources\Tag;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ReconcilePushedReference implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 7;

    public int $maxExceptions = 3;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly Source $source,
        public readonly Package $package,
        public readonly string $reference,
        public readonly bool $isTag,
    ) {}

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->lockKey()))
                ->releaseAfter(60)
                ->expireAfter(330)
                ->shared(),
        ];
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        if ($this->package->provider_id === null) {
            throw new RuntimeException("Package {$this->package->name} [{$this->package->id}] has no provider id");
        }

        $client = $this->source->vcsClient();
        $project = $client->project($this->package->provider_id);
        // ponytail: re-listing refs prevents stale queued rebuilds; add exact-ref provider lookups if API volume becomes material.
        $references = $this->isTag ? $client->tags($project) : $client->branches($project);
        $reference = $references->first(
            fn (Branch|Tag $candidate): bool => $candidate->name === $this->reference
        );

        if ($reference !== null) {
            $client->import($this->package, $reference);

            return;
        }

        $version = $this->package->versions()
            ->where('name', $this->version())
            ->first();

        if ($version === null) {
            return;
        }

        Storage::disk()->delete($version->archivePaths()->all());
        $version->delete();
    }

    private function lockKey(): string
    {
        return "package:{$this->package->id}:version:{$this->version()}";
    }

    private function version(): string
    {
        return Normalizer::version(
            $this->isTag ? $this->reference : Normalizer::devVersion($this->reference)
        );
    }
}
