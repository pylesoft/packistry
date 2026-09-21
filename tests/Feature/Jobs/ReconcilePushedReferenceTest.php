<?php

declare(strict_types=1);

use App\Enums\SourceProvider;
use App\Jobs\ReconcilePushedReference;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Version;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

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

    $queuedRebuild = new ReconcilePushedReference(
        $package->source,
        $package,
        'favicon',
        false,
    );

    (new ReconcilePushedReference(
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

it('deletes a version only when its reference no longer exists', function (): void {
    Http::fake([
        'https://api.github.com/repositories/867865331' => Http::response(
            File::get(__DIR__.'/../../Fixtures/Github/project.json')
        ),
        'https://api.github.com/repos/packistry/packistry/branches' => Http::response([]),
    ]);

    $package = Package::factory()
        ->for(Repository::factory())
        ->name('jamie/test')
        ->provider(SourceProvider::GITHUB, '867865331')
        ->has(Version::factory()->name('dev-deleted'))
        ->create();

    (new ReconcilePushedReference(
        $package->source,
        $package,
        'deleted',
        false,
    ))->handle();

    expect($package->versions()->where('name', 'dev-deleted')->exists())->toBeFalse();
});
