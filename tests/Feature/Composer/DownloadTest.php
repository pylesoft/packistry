<?php

declare(strict_types=1);

use App\CreateFromZip;
use App\Enums\TokenAbility;
use App\Models\Download;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Version;
use Illuminate\Contracts\Auth\Authenticatable;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\getJson;

function makeImmutableArchive(string $marker): string
{
    $path = tempnam(sys_get_temp_dir(), 'packistry_archive_').'.zip';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('composer.json', json_encode([
        'name' => 'test/immutable-package',
        'version' => 'dev-main',
    ], JSON_THROW_ON_ERROR));
    $zip->addFromString('marker.txt', $marker);
    $zip->close();

    return $path;
}

it('keeps previously published development archives downloadable from their locked URLs', function (): void {
    Storage::fake();

    $repository = Repository::factory()->root()->public()->create();
    $package = Package::factory()
        ->for($repository)
        ->name('test/immutable-package')
        ->create();

    $firstArchive = makeImmutableArchive('first');
    $secondArchive = makeImmutableArchive('second');

    try {
        app(CreateFromZip::class)->create($package, $firstArchive, 'dev-main');

        $firstMetadata = getJson($repository->url('/p2/test/immutable-package~dev.json'))
            ->assertOk()
            ->json('packages.test/immutable-package.0.dist');

        app(CreateFromZip::class)->create($package, $secondArchive, 'dev-main');

        $secondMetadata = getJson($repository->url('/p2/test/immutable-package~dev.json'))
            ->assertOk()
            ->json('packages.test/immutable-package.0.dist');

        expect($secondMetadata['url'])->not->toBe($firstMetadata['url'])
            ->and($secondMetadata['shasum'])->not->toBe($firstMetadata['shasum']);

        $this->artisan('archives:clean')->assertSuccessful();

        $firstDownload = getJson($firstMetadata['url'])->assertOk()->streamedContent();
        $secondDownload = getJson($secondMetadata['url'])->assertOk()->streamedContent();

        expect(sha1($firstDownload))->toBe($firstMetadata['shasum'])
            ->and(sha1($secondDownload))->toBe($secondMetadata['shasum'])
            ->and($firstDownload)->toBe((string) file_get_contents($firstArchive))
            ->and($secondDownload)->toBe((string) file_get_contents($secondArchive));
    } finally {
        @unlink($firstArchive);
        @unlink($secondArchive);
    }
});

it('downloads a version', function (Repository $repository, ?Authenticatable $auth, int $status): void {
    $path = __DIR__.'/../../Fixtures/project.zip';
    Package::factory()
        ->for($repository)
        ->name('test/test')
        ->has(
            Version::factory()
                ->fromZip($path, '1.0.0', $repository->archivePath(''))
        )
        ->create();

    $response = getJson($repository->url('/test/test/1.0.0'))
        ->assertHeader('Content-Type', 'application/zip')
        ->assertStatus($status);

    $content = $response->streamedContent();

    expect($content)->toBe((string) file_get_contents($path));

    assertDatabaseHas(Download::class, [
        'package_id' => 1,
        'version_id' => 1,
        'token_id' => $auth === null ? null : 1,
        'ip' => '127.0.0.1',
    ]);

    /** @var Package $package */
    $package = Package::query()->first();
    /** @var Version $version */
    $version = Version::query()->first();

    expect($package->total_downloads)->toBe(1)
        ->and($version->total_downloads)->toBe(1);
})
    ->with(rootAndSubRepository(
        public: true
    ))
    ->with(guestAndTokens(TokenAbility::REPOSITORY_READ, expiredDeployTokenWithAccessStatus: 200));

it('downloads version from private repository', function (Repository $repository, ?Authenticatable $auth, int $status): void {
    $path = __DIR__.'/../../Fixtures/project.zip';

    Package::factory()
        ->for($repository)
        ->name('test/test')
        ->has(
            Version::factory()
                ->fromZip($path, '1.0.0', $repository->archivePath(''))
        )
        ->create();

    getJson($repository->url('/test/test/1.0.0'))
        ->assertStatus($status);

    if ($status === 200) {
        assertDatabaseHas(Download::class, [
            'package_id' => 1,
            'version_id' => 1,
            'token_id' => 1,
            'ip' => '127.0.0.1',
        ]);

        /** @var Package $package */
        $package = Package::query()->first();
        /** @var Version $version */
        $version = Version::query()->first();

        expect($package->total_downloads)->toBe(1)
            ->and($version->total_downloads)->toBe(1);
    }
})
    ->with(rootAndSubRepository())
    ->with(guestAndTokens(
        abilities: TokenAbility::REPOSITORY_READ,
        guestStatus: 404,
        personalTokenWithoutAccessStatus: 404,
        deployTokenWithoutAccessStatus: 404,
        deployTokenWithoutPackagesStatus: 404,
    ));
