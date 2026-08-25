<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Composer\SynchronizeComposerPackage;
use App\Models\Package;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RefreshComposerPackage implements ShouldQueue
{
    use Batchable;
    use Queueable;

    private const int LOCK_SECONDS = 7200;

    /** @param array<string, mixed>|null $metadata */
    public function __construct(
        public readonly int $packageId,
        public readonly ?array $metadata = null,
        public readonly ?string $lockOwner = null,
    ) {}

    /** @param array<string, mixed>|null $metadata */
    public static function dispatchFor(Package $package, ?array $metadata = null): ?Batch
    {
        $lock = Cache::lock(self::lockKey($package->id), self::LOCK_SECONDS);

        if ($lock->get() !== true) {
            return null;
        }

        $owner = $lock->owner();

        try {
            return Bus::batch([new self($package->id, $metadata, $owner)])
                ->name(self::class)
                ->withOption('package', $package)
                ->allowFailures()
                ->dispatch();
        } catch (Throwable $exception) {
            Cache::restoreLock(self::lockKey($package->id), $owner)->release();

            throw $exception;
        }
    }

    public function handle(SynchronizeComposerPackage $synchronizer): void
    {
        try {
            /** @var Package|null $package */
            $package = Package::query()->with('composerUpstream')->find($this->packageId);
            if ($package === null || $package->composer_upstream_id === null || $package->composerUpstream?->enabled !== true) {
                $this->releaseLock();

                return;
            }

            $synchronizer->handle($package, $this->metadata);
            $this->releaseLock();
        } catch (Throwable $exception) {
            if (isset($package)) {
                $this->markUnhealthy($package);
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        try {
            $package = Package::query()->with('composerUpstream')->find($this->packageId);
            if ($package !== null) {
                $this->markUnhealthy($package);
            }
        } finally {
            $this->releaseLock();
        }
    }

    private static function lockKey(int $packageId): string
    {
        return 'composer-package-refresh:'.$packageId;
    }

    private function releaseLock(): void
    {
        if ($this->lockOwner !== null) {
            Cache::restoreLock(self::lockKey($this->packageId), $this->lockOwner)->release();
        }
    }

    private function markUnhealthy(Package $package): void
    {
        $package->forceFill(['upstream_checked_at' => now()])->save();
        $package->composerUpstream?->forceFill([
            'health_status' => 'unhealthy',
            'last_error' => 'Composer upstream synchronization failed.',
            'last_checked_at' => now(),
        ])->save();
    }
}
