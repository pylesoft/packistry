<?php

declare(strict_types=1);

use App\Enums\SourceProvider;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Source;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills deployed Composer upstreams into sources without reusing ids', function (): void {
    $unification = require database_path('migrations/2026_08_25_130000_unify_composer_upstreams_with_sources.php');
    $cleanup = require database_path('migrations/2026_08_25_140000_remove_legacy_composer_upstream_schema.php');
    $cleanup->down();
    $unification->down();

    Source::factory()->create();
    $url = 'https://private.example.test/'.str_repeat('long-path/', 32);
    $legacyId = DB::table('composer_upstreams')->insertGetId([
        'name' => 'Paid Composer',
        'url' => $url,
        'auth_type' => 'basic',
        'username' => encrypt('buyer@example.test'),
        'password' => encrypt('license-secret'),
        'token' => null,
        'enabled' => true,
        'last_checked_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $package = Package::factory()->for(Repository::factory())->create();
    DB::table('packages')->where('id', $package->id)->update([
        'source_id' => null,
        'composer_upstream_id' => $legacyId,
    ]);

    $unification->up();

    $source = Source::query()
        ->where('legacy_composer_upstream_id', $legacyId)
        ->firstOrFail();
    $indexes = collect(Schema::getIndexes('sources'))->pluck('name');

    expect($source->provider)->toBe(SourceProvider::COMPOSER)
        ->and($source->id)->not->toBe($legacyId)
        ->and($source->composerUsername())->toBe('buyer@example.test')
        ->and($source->composerPassword())->toBe('license-secret')
        ->and($source->url)->toBe($url)
        ->and($package->fresh()->source_id)->toBe($source->id)
        ->and(DB::table('composer_upstreams')->where('id', $legacyId)->exists())->toBeTrue()
        ->and($package->fresh()->composer_upstream_id)->toBe($legacyId)
        ->and($indexes)->toContain('sources_provider_index')
        ->not->toContain('sources_provider_url_index');

    $cleanup->up();

    expect(Schema::hasTable('composer_upstreams'))->toBeFalse()
        ->and(Schema::hasColumn('sources', 'legacy_composer_upstream_id'))->toBeFalse()
        ->and(Schema::hasColumn('packages', 'composer_upstream_id'))->toBeFalse()
        ->and($package->fresh()->source_id)->toBe($source->id);
});

it('reconstructs legacy Composer data when the cleanup migration is rolled back', function (): void {
    $migration = require database_path('migrations/2026_08_25_140000_remove_legacy_composer_upstream_schema.php');
    $repository = Repository::factory()->create();
    $sources = collect([
        Source::factory()->composer()->create(),
        Source::factory()->composer()->basic('buyer@example.test', 'license-secret')->create(),
        Source::factory()->composer()->bearer('api-token')->create(),
    ]);
    $packages = $sources->map(fn (Source $source): Package => Package::factory()
        ->for($repository)
        ->for($source)
        ->create(['name' => "vendor/package-{$source->id}"]));

    $migration->down();

    foreach ($sources as $index => $source) {
        $legacyId = $source->fresh()->legacy_composer_upstream_id;
        $legacy = DB::table('composer_upstreams')->find($legacyId);

        expect($legacy)->not->toBeNull()
            ->and($legacy->name)->toBe($source->name)
            ->and($legacy->url)->toBe($source->url)
            ->and($packages[$index]->fresh()->composer_upstream_id)->toBe($legacyId);

        match ($source->auth_type->value) {
            'none' => expect($legacy->username)->toBeNull()
                ->and($legacy->password)->toBeNull()
                ->and($legacy->token)->toBeNull(),
            'basic' => expect($legacy->username)->toBe($source->getRawOriginal('username'))
                ->and($legacy->password)->toBe($source->getRawOriginal('password'))
                ->and($legacy->token)->toBeNull(),
            'bearer' => expect($legacy->username)->toBeNull()
                ->and($legacy->password)->toBeNull()
                ->and($legacy->token)->toBe($source->getRawOriginal('token')),
        };
    }

    $migration->up();
});

it('refuses to remove legacy schema when source or package mappings are inconsistent', function (): void {
    $migration = require database_path('migrations/2026_08_25_140000_remove_legacy_composer_upstream_schema.php');
    $repository = Repository::factory()->create();
    $source = Source::factory()->composer()->create();
    $otherSource = Source::factory()->composer()->create();
    $package = Package::factory()->for($repository)->for($source)->create();
    $migration->down();

    DB::table('sources')->where('id', $source->id)->update(['provider' => 'github']);

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'mapped to a non-Composer source');

    DB::table('sources')->where('id', $source->id)->update(['provider' => 'composer']);
    DB::table('packages')->where('id', $package->id)->update([
        'composer_upstream_id' => $otherSource->fresh()->legacy_composer_upstream_id,
    ]);

    expect(fn () => $migration->up())
        ->toThrow(RuntimeException::class, 'package mapping is inconsistent');

    expect(Schema::hasTable('composer_upstreams'))->toBeTrue()
        ->and(Schema::hasColumn('sources', 'legacy_composer_upstream_id'))->toBeTrue()
        ->and(Schema::hasColumn('packages', 'composer_upstream_id'))->toBeTrue();

    DB::table('packages')->where('id', $package->id)->update([
        'composer_upstream_id' => $source->fresh()->legacy_composer_upstream_id,
    ]);
    $migration->up();
});
