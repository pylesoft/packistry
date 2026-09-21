<?php

declare(strict_types=1);

use App\Enums\SourceProvider;
use App\Jobs\ReconcilePushedReference;
use App\Models\Package;
use App\Models\Repository;
use Illuminate\Support\Facades\Queue;

it('queues branch reconciliation', function (Repository $repository, SourceProvider $provider, ...$args): void {
    Queue::fake();

    $package = Package::factory()
        ->for($repository)
        ->name('vendor/test')
        ->provider($provider)
        ->create();

    webhook($repository, $package->source, ...$args)
        ->assertAccepted();

    Queue::assertPushed(
        ReconcilePushedReference::class,
        fn (ReconcilePushedReference $job): bool => $job->package->is($package)
            && $job->reference === 'feature/my-feature'
            && $job->isTag === false
    );
})
    ->with(rootAndSubRepository())
    ->with(providerPushEvents(
        refType: 'heads',
        ref: 'feature/my-feature',
    ));

it('queues reconciliation for the matching repository package', function (Repository $repository, SourceProvider $provider, ...$args): void {
    Queue::fake();

    $otherPackage = Package::factory()
        ->for(Repository::factory())
        ->name('vendor/test')
        ->provider($provider)
        ->create();

    $package = Package::factory()
        ->for($repository)
        ->name('vendor/test')
        ->state([
            'provider_id' => $otherPackage->provider_id,
            'source_id' => $otherPackage->source_id,
        ])
        ->create();

    webhook($repository, $package->source, ...$args)
        ->assertAccepted();

    Queue::assertPushed(
        ReconcilePushedReference::class,
        fn (ReconcilePushedReference $job): bool => $job->package->is($package)
    );
})
    ->with(rootAndSubRepository())
    ->with(providerPushEvents(refType: 'heads'));
