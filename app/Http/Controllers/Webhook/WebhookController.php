<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ReconcilePushedReference;
use App\Models\Repository;
use App\Models\Source;
use App\Sources\ReferenceEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract readonly class WebhookController extends Controller
{
    protected function repository(): Repository
    {
        return once(function () {
            $path = request()->route('repository');

            if (is_object($path)) {
                abort(401);
            }

            return Repository::query()
                ->queryByPath($path)
                ->firstOrFail();
        });
    }

    abstract public function authorizeWebhook(Request $request): void;

    protected function source(): Source
    {
        return once(function () {
            $sourceId = request()->route('sourceId');

            if (is_object($sourceId)) {
                abort(401);
            }

            return Source::query()
                ->findOrFail($sourceId);
        });
    }

    public function push(ReferenceEvent $event): JsonResponse
    {
        return $this->reconcile($event);
    }

    public function delete(ReferenceEvent $event): JsonResponse
    {
        return $this->reconcile($event);
    }

    private function reconcile(ReferenceEvent $event): JsonResponse
    {
        $package = $this->repository()->packages()
            ->where('source_id', $this->source()->id)
            ->where('provider_id', $event->id())
            ->firstOrFail();

        ReconcilePushedReference::dispatch(
            $this->source(),
            $package,
            $event->shortRef(),
            $event->isTag(),
        );

        return response()->json(status: 202);
    }
}
