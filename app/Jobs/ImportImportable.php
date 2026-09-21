<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\ArchiveInvalidContentTypeException;
use App\Exceptions\ComposerJsonNotFoundException;
use App\Exceptions\FailedToFetchArchiveException;
use App\Exceptions\FailedToOpenArchiveException;
use App\Exceptions\NameNotFoundException;
use App\Exceptions\VersionNotFoundException;
use App\Models\Package;
use App\Models\Source;
use App\Normalizer;
use App\Sources\Importable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ImportImportable implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 7;

    public int $maxExceptions = 3;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(
        private readonly Source $source,
        private readonly Package $package,
        private readonly Importable $importable
    ) {
        //
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        $version = Normalizer::version($this->importable->version());

        return [
            (new WithoutOverlapping("package:{$this->package->id}:version:$version"))
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

    /**
     * @throws FailedToFetchArchiveException
     * @throws ArchiveInvalidContentTypeException
     * @throws FailedToOpenArchiveException
     * @throws ComposerJsonNotFoundException
     * @throws NameNotFoundException
     * @throws VersionNotFoundException
     * @throws ConnectionException
     */
    public function handle(): void
    {
        $this->source->vcsClient()->import(
            package: $this->package,
            importable: $this->importable,
        );
    }
}
