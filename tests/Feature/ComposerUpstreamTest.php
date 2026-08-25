<?php

declare(strict_types=1);

use App\Composer\SynchronizeComposerPackage;
use App\Enums\ComposerUpstreamAuthType;
use App\Enums\Permission;
use App\Exceptions\ComposerUpstreamException;
use App\Jobs\RefreshComposerPackage;
use App\Models\ComposerUpstream;
use App\Models\Package;
use App\Models\Repository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;
use function PHPUnit\Framework\assertNotNull;

function composerUpstreamArchive(string $name, string $version): string
{
    $path = tempnam(sys_get_temp_dir(), 'composer-upstream-');
    assertNotNull($path);

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('composer.json', json_encode([
        'name' => $name,
        'version' => $version,
        'description' => "$name $version",
        'autoload' => ['psr-4' => ['Provider\\' => 'src/']],
    ], JSON_THROW_ON_ERROR));
    $zip->addFromString('src/Provider.php', "<?php\n");
    $zip->close();

    $contents = file_get_contents($path);
    unlink($path);
    assertNotNull($contents);

    return $contents;
}

it('validates an upstream without exposing or storing plaintext credentials', function (): void {
    user(Permission::COMPOSER_UPSTREAM_CREATE);

    Http::fake([
        'https://private.example.test/packages.json' => Http::response(['packages' => []]),
    ]);

    postJson('/api/composer-upstreams', [
        'name' => 'Private',
        'url' => 'https://private.example.test',
        'auth_type' => 'basic',
        'username' => 'buyer@example.test',
        'password' => 'license-secret',
    ])->assertCreated()
        ->assertJsonMissing(['password' => 'license-secret'])
        ->assertJsonPath('has_credentials', true);

    $upstream = ComposerUpstream::query()->firstOrFail();
    expect($upstream->password)->toBe('license-secret')
        ->and($upstream->getRawOriginal('password'))->not->toBe('license-secret');
});

it('synchronizes archives and publishes only Packistry distribution URLs', function (): void {
    Storage::fake();
    $repository = Repository::factory()->root()->create();
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    $package = Package::factory()->for($repository)->create([
        'name' => 'test/test',
        'composer_upstream_id' => $upstream->id,
    ]);
    $archive = file_get_contents(__DIR__.'/../Fixtures/project.zip');
    assertNotNull($archive);

    Http::fake([
        'https://private.example.test/p2/test/test.json' => Http::response([
            'packages' => ['test/test' => [[
                'name' => 'test/test',
                'version' => '1.0.0',
                'type' => 'library',
                'description' => 'upstream description',
                'dist' => [
                    'type' => 'zip',
                    'url' => 'https://private.example.test/archive.zip',
                    'shasum' => sha1($archive),
                ],
            ]]],
        ]),
        'https://private.example.test/archive.zip' => Http::response($archive, 200, ['content-type' => 'application/zip']),
    ]);

    app(SynchronizeComposerPackage::class)->handle($package->fresh(['composerUpstream']));

    $version = $package->versions()->firstOrFail();
    expect($version->archive_path)->not->toBeNull()
        ->and($version->metadata)->not->toHaveKey('dist')
        ->and($package->fresh()->description)->toBe('upstream description');
});

it('sends only the configured authentication header', function (ComposerUpstreamAuthType $authType, ?string $header): void {
    $upstream = ComposerUpstream::factory()
        ->state(['url' => 'https://private.example.test'])
        ->when($authType === ComposerUpstreamAuthType::BASIC, fn ($factory) => $factory->basic('buyer@example.test', 'license'))
        ->when($authType === ComposerUpstreamAuthType::BEARER, fn ($factory) => $factory->bearer('token'))
        ->create(['auth_type' => $authType]);

    Http::fake(['https://private.example.test/packages.json' => Http::response(['packages' => []])]);

    $upstream->client()->validate();

    Http::assertSent(function ($request) use ($header): bool {
        return $header === null
            ? ! $request->hasHeader('Authorization')
            : $request->header('Authorization')[0] === $header;
    });
})->with([
    'none' => [ComposerUpstreamAuthType::NONE, null],
    'basic' => [ComposerUpstreamAuthType::BASIC, 'Basic '.base64_encode('buyer@example.test:license')],
    'bearer' => [ComposerUpstreamAuthType::BEARER, 'Bearer token'],
]);

it('does not forward upstream authorization across an origin redirect', function (): void {
    $upstream = ComposerUpstream::factory()->basic()->create(['url' => 'https://private.example.test']);

    Http::fake([
        'https://private.example.test/packages.json' => Http::response('', 302, ['Location' => 'https://cdn.example.test/packages.json']),
        'https://cdn.example.test/packages.json' => Http::response(['packages' => []]),
    ]);

    $upstream->client()->validate();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://private.example.test/packages.json'
        && $request->hasHeader('Authorization'));
    Http::assertSent(fn ($request): bool => $request->url() === 'https://cdn.example.test/packages.json'
        && ! $request->hasHeader('Authorization'));
});

it('does not forward upstream authorization across a scheme-relative origin redirect', function (): void {
    $upstream = ComposerUpstream::factory()->basic()->create(['url' => 'https://private.example.test']);

    Http::fake([
        'https://private.example.test/packages.json' => Http::response('', 302, ['Location' => '//cdn.example.test/packages.json']),
        'https://cdn.example.test/packages.json' => Http::response(['packages' => []]),
    ]);

    $upstream->client()->validate();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://cdn.example.test/packages.json'
        && ! $request->hasHeader('Authorization'));
});

it('rejects enrollment takeover and scopes the target repository', function (): void {
    $user = user(Permission::PACKAGE_CREATE);
    $allowed = Repository::factory()->create();
    $user->repositories()->attach($allowed);
    $outside = Repository::factory()->create();
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    $manual = Package::factory()->for($allowed)->create(['name' => 'test/test']);

    postJson("/api/composer-upstreams/{$upstream->id}/packages", [
        'repository_id' => $manual->repository_id,
        'name' => $manual->name,
    ])->assertUnprocessable();

    postJson("/api/composer-upstreams/{$upstream->id}/packages", [
        'repository_id' => $outside->id,
        'name' => 'test/test',
    ])->assertNotFound();

    expect($manual->fresh()->composer_upstream_id)->toBeNull();
});

it('requires credentials when changing authentication type and clears obsolete secrets', function (): void {
    user(Permission::COMPOSER_UPSTREAM_UPDATE);
    $upstream = ComposerUpstream::factory()->basic()->create(['url' => 'https://example.com']);
    Http::fake(['https://example.com/packages.json' => Http::response(['packages' => []])]);

    patchJson("/api/composer-upstreams/{$upstream->id}", [
        'auth_type' => 'bearer',
    ])->assertUnprocessable();

    patchJson("/api/composer-upstreams/{$upstream->id}", [
        'auth_type' => 'bearer',
        'token' => 'new-token',
    ])->assertOk();

    $upstream->refresh();
    expect($upstream->token)->toBe('new-token')
        ->and($upstream->username)->toBeNull()
        ->and($upstream->password)->toBeNull();
});

it('preserves write-only credentials when an edit submits blank secret fields', function (): void {
    user(Permission::COMPOSER_UPSTREAM_UPDATE);
    $upstream = ComposerUpstream::factory()->basic('buyer@example.test', 'license-secret')->create([
        'url' => 'https://example.com',
    ]);
    Http::fake(['https://example.com/packages.json' => Http::response(['packages' => []])]);

    patchJson("/api/composer-upstreams/{$upstream->id}", [
        'name' => 'Renamed',
        'auth_type' => 'basic',
        'username' => '',
        'password' => '',
    ])->assertOk();

    expect($upstream->refresh())
        ->name->toBe('Renamed')
        ->username->toBe('buyer@example.test')
        ->password->toBe('license-secret');
});

it('dispatches an observable batch with the package option', function (): void {
    Bus::fake();
    $user = user(Permission::PACKAGE_CREATE);
    $repository = Repository::factory()->create();
    $user->repositories()->attach($repository);
    $upstream = ComposerUpstream::factory()->create();
    Http::fake([
        rtrim($upstream->url, '/').'/p2/test/test.json' => Http::response([
            'packages' => ['test/test' => [['version' => '1.0.0', 'dist' => [
                'type' => 'zip', 'url' => 'https://cdn.example.test/test.zip',
            ]]]],
        ]),
    ]);

    postJson("/api/composer-upstreams/{$upstream->id}/packages", [
        'repository_id' => $repository->id,
        'name' => 'test/test',
    ])->assertStatus(202);

    Bus::assertBatched(fn ($batch): bool => $batch->name === RefreshComposerPackage::class
        && $batch->options['package']->name === 'test/test'
        && $batch->jobs->first() instanceof RefreshComposerPackage);
});

it('deduplicates refresh batches before dispatch and releases the lock after failure', function (): void {
    Bus::fake();
    $upstream = ComposerUpstream::factory()->create();
    $package = Package::factory()->for(Repository::factory()->root()->create())->create([
        'composer_upstream_id' => $upstream->id,
    ]);

    $first = RefreshComposerPackage::dispatchFor($package);
    expect($first)->not->toBeNull()
        ->and(RefreshComposerPackage::dispatchFor($package))->toBeNull();

    $job = null;
    Bus::assertBatched(function ($batch) use (&$job): bool {
        $job = $batch->jobs->first();

        return $job instanceof RefreshComposerPackage;
    });

    $job->failed(null);

    expect(RefreshComposerPackage::dispatchFor($package))->not->toBeNull()
        ->and(Cache::lock('composer-package-refresh:'.$package->id, 7200)->get())->toBeFalse();
});

it('retains the refresh lock across retryable failures until terminal failure', function (): void {
    Bus::fake();
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    $package = Package::factory()->for(Repository::factory()->root()->create())->create([
        'name' => 'test/test',
        'composer_upstream_id' => $upstream->id,
    ]);

    RefreshComposerPackage::dispatchFor($package);

    $job = null;
    Bus::assertBatched(function ($batch) use (&$job): bool {
        $job = $batch->jobs->first();

        return $job instanceof RefreshComposerPackage;
    });

    Http::fake([
        'https://private.example.test/p2/test/test.json' => Http::response([], 503),
    ]);

    expect(fn () => $job->handle(app(SynchronizeComposerPackage::class)))->toThrow(ComposerUpstreamException::class)
        ->and(Cache::lock('composer-package-refresh:'.$package->id, 7200)->get())->toBeFalse();

    $job->failed(null);
    $lock = Cache::lock('composer-package-refresh:'.$package->id, 7200);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

it('returns partial results when upstream-wide refresh skips an active package', function (): void {
    Bus::fake();
    $user = user(Permission::PACKAGE_UPDATE);
    $repository = Repository::factory()->create();
    $user->repositories()->attach($repository);
    $upstream = ComposerUpstream::factory()->create();
    $package = Package::factory()->for($repository)->create([
        'composer_upstream_id' => $upstream->id,
    ]);

    RefreshComposerPackage::dispatchFor($package);

    postJson("/api/composer-upstreams/{$upstream->id}/refresh")
        ->assertAccepted()
        ->assertJsonPath('accepted', 0)
        ->assertJsonPath('skipped_count', 1)
        ->assertJsonPath('skipped_package_ids.0', $package->id)
        ->assertJsonPath('batch_ids', []);
});

it('schedules only Composer packages whose hourly refresh is due', function (): void {
    Bus::fake();
    $upstream = ComposerUpstream::factory()->create();
    $repository = Repository::factory()->root()->create();
    $duePackage = Package::factory()->for($repository)->create([
        'composer_upstream_id' => $upstream->id,
        'upstream_checked_at' => now()->subHours(2),
    ]);
    Package::factory()->for($repository)->create([
        'composer_upstream_id' => $upstream->id,
        'upstream_checked_at' => now(),
    ]);

    artisan('composer-upstreams:refresh')->assertExitCode(0);

    Bus::assertBatchCount(1);
    $job = null;
    Bus::assertBatched(function ($batch) use ($duePackage, &$job): bool {
        $job = $batch->jobs->first();

        return $batch->options['package']->is($duePackage);
    });
    $job->failed(null);
});

it('reuses ETag metadata for a not-modified refresh', function (): void {
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    $repository = Repository::factory()->root()->create();
    $package = Package::factory()->for($repository)->create([
        'name' => 'test/test',
        'composer_upstream_id' => $upstream->id,
        'upstream_etag' => '"v1"',
    ]);

    Http::fake([
        'https://private.example.test/p2/test/test.json' => Http::response('', 304),
    ]);

    app(SynchronizeComposerPackage::class)->handle($package->fresh(['composerUpstream']));

    Http::assertSent(fn ($request): bool => $request->header('If-None-Match')[0] === '"v1"');
    expect($package->fresh()->upstream_checked_at)->not->toBeNull();
});

it('rejects an archive belonging to a different Composer package', function (): void {
    Storage::fake();
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    $repository = Repository::factory()->root()->create();
    $package = Package::factory()->for($repository)->create([
        'name' => 'other/test',
        'composer_upstream_id' => $upstream->id,
    ]);
    $archive = file_get_contents(__DIR__.'/../Fixtures/project.zip');
    assertNotNull($archive);

    Http::fake([
        'https://private.example.test/p2/other/test.json' => Http::response([
            'packages' => ['other/test' => [['version' => '1.0.0', 'dist' => [
                'type' => 'zip', 'url' => 'https://private.example.test/archive.zip', 'shasum' => sha1($archive),
            ]]]],
        ]),
        'https://private.example.test/archive.zip' => Http::response($archive),
    ]);

    expect(fn () => app(SynchronizeComposerPackage::class)->handle($package->fresh(['composerUpstream'])))
        ->toThrow(RuntimeException::class);
    expect($package->versions()->count())->toBe(0);
});

it('keeps the last snapshot and marks the upstream unhealthy on refresh failure', function (): void {
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    $repository = Repository::factory()->root()->create();
    $package = Package::factory()->for($repository)->create([
        'name' => 'test/test',
        'composer_upstream_id' => $upstream->id,
    ]);

    Http::fake([
        'https://private.example.test/p2/test/test.json' => Http::response([], 503),
    ]);

    expect(fn () => (new RefreshComposerPackage($package->id))->handle(app(SynchronizeComposerPackage::class)))
        ->toThrow(ComposerUpstreamException::class);

    expect($upstream->fresh()->health_status)->toBe('unhealthy')
        ->and($package->fresh()->versions)->toBeEmpty();
});

it('enrolls Flux and Scramble-shaped provider packages through a real synchronization batch', function (string $name): void {
    Storage::fake();
    $user = user(Permission::PACKAGE_CREATE);
    $repository = Repository::factory()->root()->public()->create();
    $user->repositories()->attach($repository);
    $upstream = ComposerUpstream::factory()->basic('buyer@example.test', 'license')->create([
        'url' => 'https://paid.example.test',
    ]);
    [$vendor, $package] = explode('/', $name, 2);
    $archives = [
        '1.0.0' => composerUpstreamArchive($name, '1.0.0'),
        '1.1.0' => composerUpstreamArchive($name, '1.1.0'),
    ];

    Http::fake(function ($request) use ($name, $vendor, $package, $archives): mixed {
        if ($request->url() === "https://paid.example.test/p2/{$vendor}/{$package}.json") {
            return Http::response(['packages' => [$name => collect($archives)
                ->map(fn (string $archive, string $version): array => [
                    'name' => $name,
                    'version' => $version,
                    'type' => 'library',
                    'dist' => [
                        'type' => 'zip',
                        'url' => "https://paid.example.test/{$package}-{$version}.zip",
                        'shasum' => sha1($archive),
                    ],
                ])->values()->all()]]);
        }

        foreach ($archives as $version => $archive) {
            if ($request->url() === "https://paid.example.test/{$package}-{$version}.zip") {
                return Http::response($archive, 200, ['content-type' => 'application/zip']);
            }
        }

        return Http::response([], 404);
    });

    postJson("/api/composer-upstreams/{$upstream->id}/packages", [
        'repository_id' => $repository->id,
        'name' => $name,
    ])->assertAccepted();

    $mirrored = $repository->packageByName($name);
    expect($mirrored?->composer_upstream_id)->toBe($upstream->id)
        ->and($mirrored?->versions()->count())->toBe(2);

    $metadata = getJson($repository->url("/p2/{$vendor}/{$package}.json"))
        ->assertOk()
        ->json("packages.{$name}");

    expect($metadata)->toHaveCount(2);
    foreach ($metadata as $version) {
        expect($version['dist']['url'])->toStartWith($repository->url('/'));
    }
})->with([
    'Flux Pro' => 'livewire/flux-pro',
    'Scramble Pro' => 'dedoc/scramble-pro',
]);

it('hides removed upstream versions while preserving their locked archive downloads', function (): void {
    Storage::fake();
    $repository = Repository::factory()->root()->public()->create();
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    $package = Package::factory()->for($repository)->create([
        'name' => 'test/test',
        'composer_upstream_id' => $upstream->id,
    ]);
    $archive = file_get_contents(__DIR__.'/../Fixtures/project.zip');
    assertNotNull($archive);
    $includeOldVersion = true;

    Http::fake(function ($request) use (&$includeOldVersion, $archive) {
        if ($request->url() === 'https://private.example.test/p2/test/test.json') {
            $versions = [[
                'name' => 'test/test',
                'version' => '2.0.0',
                'dist' => ['type' => 'zip', 'url' => 'https://private.example.test/2.zip', 'shasum' => sha1($archive)],
            ]];
            if ($includeOldVersion) {
                $versions[] = [
                    'name' => 'test/test',
                    'version' => '1.0.0',
                    'dist' => ['type' => 'zip', 'url' => 'https://private.example.test/1.zip', 'shasum' => sha1($archive)],
                ];
            }

            return Http::response(['packages' => ['test/test' => $versions]]);
        }

        return Http::response($archive, 200, ['content-type' => 'application/zip']);
    });

    app(SynchronizeComposerPackage::class)->handle($package->fresh(['composerUpstream']));
    $oldVersion = $package->versions()->where('name', '1.0.0')->firstOrFail();
    $lockedUrl = $repository->archiveUrl($package->name, $oldVersion->name, $oldVersion->shasum);

    $includeOldVersion = false;
    app(SynchronizeComposerPackage::class)->handle($package->fresh(['composerUpstream']));

    getJson($repository->url('/p2/test/test.json'))
        ->assertOk()
        ->assertJsonCount(1, 'packages.test/test')
        ->assertJsonPath('packages.test/test.0.version', '2.0.0');
    getJson($lockedUrl)->assertOk();
    expect($oldVersion->fresh()->upstream_removed_at)->not->toBeNull();
});
