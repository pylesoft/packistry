<?php

declare(strict_types=1);

use App\Enums\SourceProvider;
use App\Jobs\ReconcileReference;
use App\Models\Package;
use App\Models\Repository;
use Illuminate\Support\Facades\Queue;

it('queues tag reconciliation', function (Repository $repository, SourceProvider $provider, ...$args): void {
    Queue::fake();

    $package = Package::factory()
        ->for($repository)
        ->name('vendor/test')
        ->provider($provider)
        ->create();

    webhook($repository, $package->source, ...$args)
        ->assertAccepted();

    Queue::assertPushed(
        ReconcileReference::class,
        fn (ReconcileReference $job): bool => $job->package->is($package)
            && $job->reference === 'v1.0.0'
            && $job->isTag === true
    );
})
    ->with(rootAndSubRepository())
    ->with(providerPushEvents(ref: 'v1.0.0'));
