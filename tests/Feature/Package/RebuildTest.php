<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Jobs\RefreshComposerPackage;
use App\Models\ComposerUpstream;
use App\Models\Package;
use App\Models\Repository;
use Illuminate\Support\Facades\Bus;

use function Pest\Laravel\postJson;

it('rebuilds a mirrored Composer package from its upstream', function (): void {
    Bus::fake();
    user([Permission::UNSCOPED, Permission::PACKAGE_UPDATE]);

    $package = Package::factory()
        ->for(Repository::factory()->root())
        ->for(ComposerUpstream::factory(), 'composerUpstream')
        ->create([
            'source_id' => null,
            'provider_id' => null,
        ]);

    postJson("/api/packages/{$package->id}/rebuild")
        ->assertOk();

    Bus::assertBatched(function ($batch) use ($package): bool {
        $job = $batch->jobs->first();

        return $job instanceof RefreshComposerPackage
            && $job->packageId === $package->id;
    });
});
