<?php

declare(strict_types=1);

use App\Enums\SourceProvider;
use App\Jobs\ReconcilePushedReference;
use App\Jobs\ReconcileReference;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Version;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('can unserialize jobs queued before the worker rename', function (): void {
    $package = Package::factory()
        ->for(Repository::factory())
        ->name('jamie/test')
        ->provider(SourceProvider::GITHUB, '867865331')
        ->create();

    $job = unserialize(serialize(new ReconcilePushedReference(
        $package->source,
        $package,
        'favicon',
        false,
    )));

    expect($job)->toBeInstanceOf(ReconcileReference::class)
        ->and($job->reference)->toBe('favicon');
});

it('serializes imports for the same package version', function (): void {
    $package = Package::factory()
        ->for(Repository::factory())
        ->name('jamie/test')
        ->provider(SourceProvider::GITHUB, '867865331')
        ->create();

    $reference = implode('/', array_fill(0, 40, 'long-reference'));
    $first = new ReconcileReference($package->source, $package, $reference, false);
    $second = new ReconcileReference($package->source, $package, $reference, false);
    [$firstMiddleware] = $first->middleware();
    [$secondMiddleware] = $second->middleware();
    $blockedJob = new class
    {
        public ?int $releasedAfter = null;

        public function release(int $delay): void
        {
            $this->releasedAfter = $delay;
        }
    };
    $secondRan = false;

    $firstMiddleware->handle($first, function () use ($secondMiddleware, $blockedJob, &$secondRan): void {
        $secondMiddleware->handle($blockedJob, function () use (&$secondRan): void {
            $secondRan = true;
        });
    });

    expect(strlen($firstMiddleware->getLockKey($first)))->toBeLessThanOrEqual(250)
        ->and($blockedJob->releasedAfter)->toBe(60)
        ->and($secondRan)->toBeFalse();
});

it('converges stale rebuild jobs on the current branch commit', function (): void {
    Http::fake([
        'https://api.github.com/repositories/867865331' => Http::response(
            File::get(__DIR__.'/../../Fixtures/Github/project.json')
        ),
        'https://api.github.com/repos/packistry/packistry/branches' => Http::response([
            [
                'name' => 'favicon',
                'commit' => ['sha' => '18513692e6f610369a3339fb7fb9c7c4b3491b85'],
                'protected' => false,
            ],
        ]),
        'https://api.github.com/repos/packistry/packistry/zipball/18513692e6f610369a3339fb7fb9c7c4b3491b85' => Http::response(
            File::get(__DIR__.'/../../Fixtures/gitea-jamie-test.zip'),
            headers: ['Content-Type' => 'application/zip'],
        ),
    ]);

    $package = Package::factory()
        ->for(Repository::factory())
        ->name('jamie/test')
        ->provider(SourceProvider::GITHUB, '867865331')
        ->create();

    $staleVersion = Version::factory()
        ->for($package)
        ->name('dev-favicon')
        ->create([
            'metadata' => [
                'source' => [
                    'type' => 'git',
                    'url' => 'https://github.com/packistry/packistry',
                    'reference' => 'stale-reference',
                ],
            ],
        ]);

    $queuedRebuild = new ReconcileReference(
        $package->source,
        $package,
        'favicon',
        false,
    );

    (new ReconcileReference(
        $package->source,
        $package,
        'favicon',
        false,
    ))->handle();

    $queuedRebuild->handle();

    $version = $package->versions()->where('name', 'dev-favicon')->firstOrFail();

    expect($version->is($staleVersion))->toBeTrue()
        ->and($package->versions()->where('name', 'dev-favicon')->count())->toBe(1)
        ->and($version->metadata)
        ->toHaveKey('source.reference', '18513692e6f610369a3339fb7fb9c7c4b3491b85');

    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://api.github.com/repos/packistry/packistry/zipball/18513692e6f610369a3339fb7fb9c7c4b3491b85');
});

it('deletes a version and its archive only when its reference no longer exists', function (
    string $reference,
    bool $isTag,
    string $version,
    string $endpoint,
): void {
    Storage::fake();
    Storage::disk()->put('deleted.zip', 'archive');

    Http::fake([
        'https://api.github.com/repositories/867865331' => Http::response(
            File::get(__DIR__.'/../../Fixtures/Github/project.json')
        ),
        "https://api.github.com/repos/packistry/packistry/$endpoint" => Http::response([]),
    ]);

    $package = Package::factory()
        ->for(Repository::factory())
        ->name('jamie/test')
        ->provider(SourceProvider::GITHUB, '867865331')
        ->has(Version::factory()->name($version)->state(['archive_path' => 'deleted.zip']))
        ->create();

    (new ReconcileReference(
        $package->source,
        $package,
        $reference,
        $isTag,
    ))->handle();

    expect($package->versions()->where('name', $version)->exists())->toBeFalse();
    Storage::disk()->assertMissing('deleted.zip');
})->with([
    'branch' => ['deleted', false, 'dev-deleted', 'branches'],
    'tag' => ['v1.0.0', true, '1.0.0', 'tags'],
]);
