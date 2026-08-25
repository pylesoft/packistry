<?php

use App\Enums\Permission;
use App\Models\Package;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\getJson;

it('indexes', function (?User $user, int $status): void {
    getJson('/api/batches')
        ->assertStatus($status);
})->with(guestAndUsers(Permission::BATCH_READ));

it('indexes batches with package snapshots created before upstream mirroring', function (): void {
    user(Permission::BATCH_READ);

    $package = Package::factory()
        ->for(Repository::factory())
        ->create();

    $legacyAttributes = array_diff(Schema::getColumnListing('packages'), [
        'upstream_checked_at',
        'upstream_synced_at',
        'upstream_last_error',
    ]);

    $legacyPackage = Package::query()
        ->select($legacyAttributes)
        ->findOrFail($package->id);

    Bus::batch([])
        ->withOption('package', $legacyPackage)
        ->dispatch();

    getJson('/api/batches')
        ->assertOk()
        ->assertJsonPath('data.0.package.upstream_checked_at', null)
        ->assertJsonPath('data.0.package.upstream_synced_at', null)
        ->assertJsonPath('data.0.package.upstream_last_error', null);
});
