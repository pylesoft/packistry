<?php

declare(strict_types=1);

use App\Enums\SourceProvider;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Source;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('backfills deployed Composer upstreams into sources without reusing ids', function (): void {
    $migration = require database_path('migrations/2026_08_25_130000_unify_composer_upstreams_with_sources.php');
    $migration->down();

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

    $migration->up();

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
});
