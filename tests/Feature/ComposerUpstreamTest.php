<?php

declare(strict_types=1);

use App\Composer\OutboundUrlGuard;
use App\Composer\SynchronizeComposerPackage;
use App\CreateFromZip;
use App\Enums\ComposerUpstreamAuthType;
use App\Enums\Permission;
use App\Exceptions\ComposerUpstreamException;
use App\Jobs\RefreshComposerPackage;
use App\Models\ComposerUpstream;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Version;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;
use function PHPUnit\Framework\assertNotNull;

beforeEach(function (): void {
    app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(
        fn (string $host): array => ['93.184.216.34'],
    ));
});

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
        ->and($upstream->getRawOriginal('password'))->not->toBe('license-secret')
        ->and($upstream->last_checked_at)->not->toBeNull();
});

it('requires HTTPS for authenticated upstreams', function (): void {
    user(Permission::COMPOSER_UPSTREAM_CREATE);
    Http::fake();

    postJson('/api/composer-upstreams', [
        'name' => 'Private',
        'url' => 'http://packages.example.test',
        'auth_type' => 'basic',
        'username' => 'buyer@example.test',
        'password' => 'license-secret',
    ])->assertUnprocessable();

    Http::assertNothingSent();
});

it('rejects private literal upstream targets', function (): void {
    user(Permission::COMPOSER_UPSTREAM_CREATE);
    Http::fake();

    postJson('/api/composer-upstreams', [
        'name' => 'Private',
        'url' => 'https://169.254.169.254/latest/meta-data',
        'auth_type' => 'none',
    ])->assertUnprocessable();

    Http::assertNothingSent();
});

it('rejects hostnames that resolve to private addresses', function (): void {
    $guard = new OutboundUrlGuard(fn (string $host): array => ['10.0.0.5']);

    expect(fn () => $guard->ensureSafe('https://packages.example.test'))
        ->toThrow(ComposerUpstreamException::class);
});

it('rejects IPv4-mapped private IPv6 targets', function (): void {
    $guard = new OutboundUrlGuard(fn (string $host): array => ['::ffff:127.0.0.1']);

    expect(fn () => $guard->ensureSafe('https://packages.example.test'))
        ->toThrow(ComposerUpstreamException::class);
});

it('pins the validated address while preserving the request hostname', function (): void {
    $options = [];
    app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(
        fn (string $host): array => ['2001:4860:4860::8888'],
    ));
    Http::globalMiddleware(function ($handler) use (&$options) {
        return function ($request, array $requestOptions) use ($handler, &$options) {
            $options[] = $requestOptions;

            return $handler($request, $requestOptions);
        };
    });
    Http::fake([
        'https://private.example.test/packages.json' => Http::response(['packages' => []]),
    ]);

    ComposerUpstream::factory()->create(['url' => 'https://private.example.test'])->client()->validate();

    expect($options[0]['curl'][CURLOPT_RESOLVE])->toBe([
        'private.example.test:443:[2001:4860:4860::8888]',
    ])->and($options[0]['decode_content'])->toBeFalse();
    Http::assertSent(fn ($request): bool => $request->url() === 'https://private.example.test/packages.json'
        && $request->header('Accept-Encoding')[0] === 'identity');
});

it('retries another validated pinned address after a connection failure', function (): void {
    $options = [];
    $attempts = 0;
    app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(
        fn (string $host): array => ['93.184.216.34', '142.250.72.14'],
    ));
    Http::globalMiddleware(function ($handler) use (&$options) {
        return function ($request, array $requestOptions) use ($handler, &$options) {
            $options[] = $requestOptions;

            return $handler($request, $requestOptions);
        };
    });
    Http::fake(function () use (&$attempts): mixed {
        if (++$attempts === 1) {
            throw new ConnectionException('Connection failed.');
        }

        return Http::response(['packages' => []]);
    });

    ComposerUpstream::factory()->create(['url' => 'https://private.example.test'])->client()->validate();

    expect($options[0]['curl'][CURLOPT_RESOLVE])->toBe(['private.example.test:443:93.184.216.34'])
        ->and($options[1]['curl'][CURLOPT_RESOLVE])->toBe(['private.example.test:443:142.250.72.14']);
});

it('does not expose signed archive URLs in terminal connection errors', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'composer-upstream-');
    assertNotNull($path);
    Http::fake(fn () => throw new ConnectionException('Failed https://cdn.example.test/archive.zip?token=secret'));
    $caught = null;

    try {
        ComposerUpstream::factory()->create(['url' => 'https://private.example.test'])
            ->client()
            ->archive('https://cdn.example.test/archive.zip?token=secret', $path);
    } catch (ComposerUpstreamException $exception) {
        $caught = $exception;
    } finally {
        unlink($path);
    }

    expect($caught)->toBeInstanceOf(ComposerUpstreamException::class)
        ->and($caught->getMessage())->toBe('Composer upstream connection failed.')
        ->and($caught->getPrevious())->toBeNull();
});

it('rejects any user-info component before resolving the host', function (string $url): void {
    $guard = new OutboundUrlGuard(fn (string $host): array => ['93.184.216.34']);

    expect(fn () => $guard->ensureSafe($url))
        ->toThrow(ComposerUpstreamException::class);
})->with([
    'empty username' => 'https://@packages.example.test',
    'password' => 'https://:secret@packages.example.test',
]);

it('rejects upstream URLs containing credentials, queries, or fragments', function (string $url): void {
    user(Permission::COMPOSER_UPSTREAM_CREATE);
    Http::fake();

    postJson('/api/composer-upstreams', [
        'name' => 'Private',
        'url' => $url,
        'auth_type' => 'none',
    ])->assertUnprocessable();

    Http::assertNothingSent();
})->with([
    'username' => 'https://buyer@packages.example.test',
    'query' => 'https://packages.example.test?token=secret',
    'fragment' => 'https://packages.example.test#private',
]);

it('requires connection fields when creating an upstream', function (): void {
    user(Permission::COMPOSER_UPSTREAM_CREATE);
    Http::fake();

    postJson('/api/composer-upstreams', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'url', 'auth_type']);

    Http::assertNothingSent();
});

it('synchronizes archives and publishes only Packistry distribution URLs', function (): void {
    Storage::fake();
    $repository = Repository::factory()->root()->create();
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    $package = Package::factory()->for($repository)->create([
        'name' => 'test/test',
        'composer_upstream_id' => $upstream->id,
        'upstream_last_error' => 'Previous failure',
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
                'source' => ['type' => 'git', 'url' => 'https://vendor.example.test/private.git'],
                'notification-url' => 'https://vendor.example.test/downloads',
                'dist' => [
                    'type' => 'zip',
                    'url' => 'https://private.example.test/archive.zip',
                    'reference' => 'release-1',
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
        ->and($version->metadata)->not->toHaveKey('source')
        ->and($version->metadata)->not->toHaveKey('notification-url')
        ->and($package->fresh()->description)->toBe('upstream description')
        ->and($package->fresh()->upstream_last_error)->toBeNull();
    Storage::disk()->assertExists($version->archive_path);
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

it('pins each redirect origin to its separately validated address', function (): void {
    $options = [];
    app()->instance(OutboundUrlGuard::class, new OutboundUrlGuard(
        fn (string $host): array => match ($host) {
            'private.example.test' => ['93.184.216.34'],
            'cdn.example.test' => ['142.250.72.14'],
        },
    ));
    Http::globalMiddleware(function ($handler) use (&$options) {
        return function ($request, array $requestOptions) use ($handler, &$options) {
            $options[] = $requestOptions;

            return $handler($request, $requestOptions);
        };
    });
    $upstream = ComposerUpstream::factory()->basic()->create(['url' => 'https://private.example.test']);
    Http::fake([
        'https://private.example.test/packages.json' => Http::response('', 302, ['Location' => 'https://cdn.example.test/packages.json']),
        'https://cdn.example.test/packages.json' => Http::response(['packages' => []]),
    ]);

    $upstream->client()->validate();

    expect($options[0]['curl'][CURLOPT_RESOLVE])->toBe(['private.example.test:443:93.184.216.34'])
        ->and($options[1]['curl'][CURLOPT_RESOLVE])->toBe(['cdn.example.test:443:142.250.72.14']);
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

it('rejects an unsafe redirect before requesting it', function (): void {
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    Http::fake([
        'https://private.example.test/packages.json' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal']),
    ]);

    expect(fn () => $upstream->client()->validate())->toThrow(ComposerUpstreamException::class);

    Http::assertSentCount(1);
});

it('rejects an authenticated upstream redirect to HTTP', function (): void {
    $upstream = ComposerUpstream::factory()->basic()->create(['url' => 'https://private.example.test']);
    Http::fake([
        'https://private.example.test/packages.json' => Http::response('', 302, ['Location' => 'http://cdn.example.test/packages.json']),
    ]);

    expect(fn () => $upstream->client()->validate())->toThrow(ComposerUpstreamException::class);

    Http::assertSentCount(1);
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
        ->and($upstream->password)->toBeNull()
        ->and($upstream->last_checked_at)->not->toBeNull();
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

    Bus::assertBatched(function ($batch): bool {
        $job = $batch->jobs->first();
        $middleware = $job->middleware()[0] ?? null;

        return $batch->name === RefreshComposerPackage::class
            && $batch->options['package']->name === 'test/test'
            && $job instanceof RefreshComposerPackage
            && ! property_exists($job, 'metadata')
            && $job->timeout === 3600
            && $job->tries === 3
            && $job->failOnTimeout
            && $middleware instanceof WithoutOverlapping
            && $middleware->releaseAfter === null
            && $middleware->expiresAfter === 3660;
    });
});

it('distinguishes a missing package from an unavailable upstream during enrollment', function (int $status, string $field): void {
    Bus::fake();
    $user = user(Permission::PACKAGE_CREATE);
    $repository = Repository::factory()->create();
    $user->repositories()->attach($repository);
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    Http::fake([
        'https://private.example.test/p2/test/test.json' => Http::response([], $status),
    ]);

    postJson("/api/composer-upstreams/{$upstream->id}/packages", [
        'repository_id' => $repository->id,
        'name' => 'test/test',
    ])->assertUnprocessable()->assertJsonValidationErrors($field);

    expect($repository->packages()->where('name', 'test/test')->exists())->toBeFalse();
})->with([
    'not found' => [404, 'name'],
    'upstream unavailable' => [503, 'upstream'],
]);

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

it('rejects an upstream-wide refresh when the upstream is disabled', function (): void {
    Bus::fake();
    user(Permission::PACKAGE_UPDATE);
    $upstream = ComposerUpstream::factory()->create(['enabled' => false]);

    postJson("/api/composer-upstreams/{$upstream->id}/refresh")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('upstream');

    Bus::assertNothingBatched();
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

it('expands Composer 2 minified metadata before mirroring it', function (): void {
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    Http::fake([
        'https://private.example.test/p2/test/test.json' => Http::response([
            'minified' => 'composer/2.0',
            'packages' => ['test/test' => [
                [
                    'name' => 'test/test',
                    'version' => '1.0.0',
                    'require' => ['php' => '^8.4'],
                    'dist' => ['type' => 'zip', 'url' => 'https://private.example.test/1.zip'],
                ],
                [
                    'version' => '2.0.0',
                    'dist' => ['type' => 'zip', 'url' => 'https://private.example.test/2.zip'],
                ],
            ]],
        ]),
    ]);

    $versions = $upstream->client()->package('test/test')['versions'];

    expect($versions['2.0.0']['name'])->toBe('test/test')
        ->and($versions['2.0.0']['require'])->toBe(['php' => '^8.4']);
});

it('refreshes an archive when its stable distribution reference changes', function (): void {
    Storage::fake();
    $repository = Repository::factory()->root()->create();
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    $package = Package::factory()->for($repository)->create([
        'name' => 'test/test',
        'composer_upstream_id' => $upstream->id,
    ]);
    $archive = file_get_contents(__DIR__.'/../Fixtures/project.zip');
    assertNotNull($archive);
    $reference = 'release-1';

    Http::fake(function ($request) use (&$reference, $archive) {
        if ($request->url() === 'https://private.example.test/p2/test/test.json') {
            return Http::response([
                'packages' => ['test/test' => [[
                    'name' => 'test/test',
                    'version' => '1.0.0',
                    'dist' => [
                        'type' => 'zip',
                        'url' => 'https://private.example.test/archive.zip',
                        'reference' => $reference,
                    ],
                ]]],
            ]);
        }

        return Http::response($archive, 200, ['content-type' => 'application/zip']);
    });

    app(SynchronizeComposerPackage::class)->handle($package->fresh(['composerUpstream']));
    $reference = 'release-2';
    app(SynchronizeComposerPackage::class)->handle($package->fresh(['composerUpstream']));

    expect(Http::recorded(fn ($request) => $request->url() === 'https://private.example.test/archive.zip'))->toHaveCount(2);
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

it('rejects non-HTTP archive distributions', function (): void {
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    $package = Package::factory()->for(Repository::factory()->root()->create())->create([
        'name' => 'test/test',
        'composer_upstream_id' => $upstream->id,
    ]);
    Http::fake([
        'https://private.example.test/p2/test/test.json' => Http::response([
            'packages' => ['test/test' => [[
                'version' => '1.0.0',
                'dist' => ['type' => 'zip', 'url' => 'file:///etc/passwd'],
            ]]],
        ]),
    ]);

    expect(fn () => app(SynchronizeComposerPackage::class)->handle($package->fresh(['composerUpstream'])))
        ->toThrow(ComposerUpstreamException::class);

    expect($package->versions()->count())->toBe(0);
    Http::assertSentCount(1);
});

it('rejects oversized archives before publishing a version', function (): void {
    Storage::fake();
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    $package = Package::factory()->for(Repository::factory()->root()->create())->create([
        'name' => 'test/test',
        'composer_upstream_id' => $upstream->id,
    ]);
    Http::fake([
        'https://private.example.test/p2/test/test.json' => Http::response([
            'packages' => ['test/test' => [[
                'version' => '1.0.0',
                'dist' => ['type' => 'zip', 'url' => 'https://private.example.test/archive.zip'],
            ]]],
        ]),
        'https://private.example.test/archive.zip' => Http::response('small body', 200, [
            'Content-Length' => '268435457',
        ]),
    ]);

    expect(fn () => app(SynchronizeComposerPackage::class)->handle($package->fresh(['composerUpstream'])))
        ->toThrow(ComposerUpstreamException::class);

    expect($package->versions()->count())->toBe(0);
});

it('rejects oversized metadata responses', function (): void {
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    Http::fake([
        'https://private.example.test/packages.json' => Http::response(['packages' => []], 200, [
            'Content-Length' => '16777217',
        ]),
    ]);

    expect(fn () => $upstream->client()->validate())->toThrow(ComposerUpstreamException::class);
});

it('rejects pathological package version counts', function (): void {
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    Http::fake([
        'https://private.example.test/p2/test/test.json' => Http::response([
            'packages' => ['test/test' => array_fill(0, 10_001, [
                'version' => '1.0.0',
            ])],
        ]),
    ]);

    expect(fn () => $upstream->client()->package('test/test'))
        ->toThrow(ComposerUpstreamException::class);
});

it('publishes no metadata when a later archive fails preflight validation', function (): void {
    Storage::fake();
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    $package = Package::factory()->for(Repository::factory()->root()->create())->create([
        'name' => 'test/test',
        'description' => 'last public snapshot',
        'composer_upstream_id' => $upstream->id,
    ]);
    $firstArchive = composerUpstreamArchive('test/test', '1.0.0');

    Http::fake([
        'https://private.example.test/p2/test/test.json' => Http::response([
            'packages' => ['test/test' => [
                [
                    'version' => '1.0.0',
                    'description' => 'new metadata',
                    'dist' => [
                        'type' => 'zip',
                        'url' => 'https://private.example.test/1.0.0.zip',
                        'shasum' => sha1($firstArchive),
                    ],
                ],
                [
                    'version' => '2.0.0',
                    'dist' => [
                        'type' => 'zip',
                        'url' => 'https://private.example.test/2.0.0.zip',
                        'shasum' => str_repeat('0', 40),
                    ],
                ],
            ]],
        ]),
        'https://private.example.test/1.0.0.zip' => Http::response($firstArchive),
        'https://private.example.test/2.0.0.zip' => Http::response('invalid archive'),
    ]);

    expect(fn () => app(SynchronizeComposerPackage::class)->handle($package->fresh(['composerUpstream'])))
        ->toThrow(ComposerUpstreamException::class);

    expect($package->fresh())
        ->description->toBe('last public snapshot')
        ->upstream_synced_at->toBeNull()
        ->and($package->versions()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBeEmpty();
});

it('rolls back publication and deletes only staged archives when database publication fails', function (): void {
    Storage::fake();
    Storage::disk()->put('preserved.zip', 'existing archive');
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    $package = Package::factory()->for(Repository::factory()->root()->create())->create([
        'name' => 'test/test',
        'description' => 'last public snapshot',
        'composer_upstream_id' => $upstream->id,
    ]);
    $archives = [
        '1.0.0' => composerUpstreamArchive('test/test', '1.0.0'),
        '2.0.0' => composerUpstreamArchive('test/test', '2.0.0'),
    ];
    Http::fake(function ($request) use ($archives): mixed {
        if ($request->url() === 'https://private.example.test/p2/test/test.json') {
            return Http::response(['packages' => ['test/test' => collect($archives)
                ->map(fn (string $archive, string $version): array => [
                    'version' => $version,
                    'description' => 'new metadata',
                    'dist' => [
                        'type' => 'zip',
                        'url' => "https://private.example.test/$version.zip",
                        'shasum' => sha1($archive),
                    ],
                ])->values()->all()]]);
        }

        foreach ($archives as $version => $archive) {
            if ($request->url() === "https://private.example.test/$version.zip") {
                return Http::response($archive);
            }
        }

        return Http::response([], 404);
    });
    $creator = new class extends CreateFromZip
    {
        private int $publications = 0;

        public function createFromStoredArchive(
            Package $package,
            string $archivePath,
            string $hash,
            array $decoded,
            ?string $version = null,
        ): Version {
            if (++$this->publications === 2) {
                throw new RuntimeException('Simulated publication failure.');
            }

            return parent::createFromStoredArchive($package, $archivePath, $hash, $decoded, $version);
        }
    };

    expect(fn () => (new SynchronizeComposerPackage($creator))->handle($package->fresh(['composerUpstream'])))
        ->toThrow(RuntimeException::class, 'Simulated publication failure.');

    expect($package->fresh())
        ->description->toBe('last public snapshot')
        ->upstream_synced_at->toBeNull()
        ->and($package->versions()->count())->toBe(0)
        ->and(Storage::disk()->allFiles())->toBe(['preserved.zip']);
});

it('keeps the last snapshot and records package health on refresh failure', function (): void {
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

    expect($package->fresh()->upstream_last_error)->toBe('Composer upstream synchronization failed.')
        ->and($package->fresh()->upstream_checked_at)->not->toBeNull()
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
    $includeNewVersion = true;

    Http::fake(function ($request) use (&$includeNewVersion, $archive) {
        if ($request->url() === 'https://private.example.test/p2/test/test.json') {
            $versions = [[
                'name' => 'test/test',
                'version' => '1.0.0',
                'dist' => ['type' => 'zip', 'url' => 'https://private.example.test/1.zip', 'shasum' => sha1($archive)],
            ]];
            if ($includeNewVersion) {
                $versions[] = [
                    'name' => 'test/test',
                    'version' => '2.0.0',
                    'dist' => ['type' => 'zip', 'url' => 'https://private.example.test/2.zip', 'shasum' => sha1($archive)],
                ];
            }

            return Http::response(['packages' => ['test/test' => $versions]]);
        }

        return Http::response($archive, 200, ['content-type' => 'application/zip']);
    });

    app(SynchronizeComposerPackage::class)->handle($package->fresh(['composerUpstream']));
    $removedVersion = $package->versions()->where('name', '2.0.0')->firstOrFail();
    $lockedUrl = $repository->archiveUrl($package->name, $removedVersion->name, $removedVersion->shasum);

    $includeNewVersion = false;
    app(SynchronizeComposerPackage::class)->handle($package->fresh(['composerUpstream']));

    getJson($repository->url('/p2/test/test.json'))
        ->assertOk()
        ->assertJsonCount(1, 'packages.test/test')
        ->assertJsonPath('packages.test/test.0.version', '1.0.0');
    getJson($lockedUrl)->assertOk();
    expect($removedVersion->fresh()->upstream_removed_at)->not->toBeNull();
    expect($package->fresh()->latest_version)->toBe('1.0.0');
});

it('rejects an empty upstream snapshot without hiding mirrored versions', function (): void {
    Storage::fake();
    $repository = Repository::factory()->root()->public()->create();
    $upstream = ComposerUpstream::factory()->create(['url' => 'https://private.example.test']);
    $package = Package::factory()->for($repository)->create([
        'name' => 'test/test',
        'composer_upstream_id' => $upstream->id,
    ]);
    $archive = file_get_contents(__DIR__.'/../Fixtures/project.zip');
    assertNotNull($archive);
    $versions = [[
        'name' => 'test/test',
        'version' => '1.0.0',
        'dist' => ['type' => 'zip', 'url' => 'https://private.example.test/1.zip', 'shasum' => sha1($archive)],
    ]];

    Http::fake(function ($request) use (&$versions, $archive) {
        if ($request->url() === 'https://private.example.test/p2/test/test.json') {
            return Http::response(['packages' => ['test/test' => $versions]]);
        }

        return Http::response($archive, 200, ['content-type' => 'application/zip']);
    });

    app(SynchronizeComposerPackage::class)->handle($package->fresh(['composerUpstream']));
    $versions = [];

    expect(fn () => app(SynchronizeComposerPackage::class)->handle($package->fresh(['composerUpstream'])))
        ->toThrow(ComposerUpstreamException::class, 'Composer upstream returned invalid package metadata.');

    expect($package->versions()->firstOrFail()->upstream_removed_at)->toBeNull()
        ->and($package->fresh()->latest_version)->toBe('1.0.0');
});
