<?php

declare(strict_types=1);

use App\Enums\SourceProvider;
use App\Jobs\ReconcileReference;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Version;
use Illuminate\Support\Facades\Queue;

it('queues branch deletion reconciliation', function (Repository $repository, SourceProvider $provider, ...$args): void {
    Queue::fake();

    $package = Package::factory()
        ->for($repository)
        ->name('vendor/test')
        ->has(Version::factory()->name('dev-feature-something'))
        ->provider($provider)
        ->create();

    webhook($repository, $package->source, ...$args)
        ->assertAccepted();

    expect($package->versions()->count())->toBe(1);

    Queue::assertPushed(
        ReconcileReference::class,
        fn (ReconcileReference $job): bool => $job->package->is($package)
            && $job->reference === 'feature-something'
            && $job->isTag === false
    );
})
    ->with(rootAndSubRepository())
    ->with(providerDeleteEvents(
        refType: 'heads',
        ref: 'feature-something'
    ));
