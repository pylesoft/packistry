<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureComposerSourcesOwnLegacyPackages();

        Schema::table('packages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('composer_upstream_id');
        });

        Schema::table('sources', function (Blueprint $table): void {
            $table->dropUnique(['legacy_composer_upstream_id']);
            $table->dropColumn('legacy_composer_upstream_id');
        });

        Schema::drop('composer_upstreams');
    }

    public function down(): void
    {
        Schema::create('composer_upstreams', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('url');
            $table->string('auth_type')->default('none');
            $table->text('username')->nullable();
            $table->text('password')->nullable();
            $table->text('token')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::table('sources', function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_composer_upstream_id')->nullable()->unique()->after('last_checked_at');
        });

        Schema::table('packages', function (Blueprint $table): void {
            $table->foreignId('composer_upstream_id')->nullable()->after('source_id')->constrained('composer_upstreams')->nullOnDelete();
        });

        DB::table('sources')
            ->where('provider', 'composer')
            ->orderBy('id')
            ->each(function (object $source): void {
                $authType = $source->auth_type ?? 'none';

                if (! in_array($authType, ['none', 'basic', 'bearer'], true)) {
                    throw new RuntimeException('Cannot restore an unsupported Composer authentication type.');
                }

                $legacyId = DB::table('composer_upstreams')->insertGetId([
                    'name' => $source->name,
                    'url' => $source->url,
                    'auth_type' => $authType,
                    'username' => $authType === 'basic' ? $this->encryptForLegacyCast($source->username) : null,
                    'password' => $authType === 'basic' ? $this->encryptForLegacyCast($source->password) : null,
                    'token' => $authType === 'bearer' ? $this->encryptForLegacyCast($source->token) : null,
                    'enabled' => $source->enabled,
                    'last_checked_at' => $source->last_checked_at,
                    'created_at' => $source->created_at,
                    'updated_at' => $source->updated_at,
                ]);

                DB::table('sources')
                    ->where('id', $source->id)
                    ->update(['legacy_composer_upstream_id' => $legacyId]);

                DB::table('packages')
                    ->where('source_id', $source->id)
                    ->update(['composer_upstream_id' => $legacyId]);
            });
    }

    private function ensureComposerSourcesOwnLegacyPackages(): void
    {
        $upstreamIds = DB::table('composer_upstreams')
            ->pluck('id')
            ->map(fn (int $id): int => $id)
            ->sort()
            ->values();

        $mappedUpstreamIds = DB::table('sources')
            ->whereNotNull('legacy_composer_upstream_id')
            ->pluck('legacy_composer_upstream_id')
            ->map(fn (int $id): int => $id)
            ->sort()
            ->values();

        if ($upstreamIds->all() !== $mappedUpstreamIds->all()) {
            throw new RuntimeException('Cannot remove legacy Composer schema until every upstream is mapped to one source.');
        }

        $sources = DB::table('sources')
            ->select(['id', 'provider', 'legacy_composer_upstream_id'])
            ->get()
            ->keyBy('id');

        if ($sources->contains(fn (object $source): bool => $source->legacy_composer_upstream_id !== null
            && $source->provider !== 'composer')) {
            throw new RuntimeException('Cannot remove legacy Composer schema while an upstream is mapped to a non-Composer source.');
        }

        foreach (DB::table('packages')->whereNotNull('composer_upstream_id')->get(['source_id', 'composer_upstream_id']) as $package) {
            $source = $sources->get($package->source_id);

            if ($source === null
                || $source->provider !== 'composer'
                || (int) $source->legacy_composer_upstream_id !== (int) $package->composer_upstream_id) {
                throw new RuntimeException('Cannot remove legacy Composer schema while a package mapping is inconsistent.');
            }
        }
    }

    private function encryptForLegacyCast(?string $encrypted): ?string
    {
        if ($encrypted === null) {
            return null;
        }

        $decrypted = decrypt($encrypted, false);
        $serialized = @unserialize($decrypted, ['allowed_classes' => false]);
        $credential = is_string($serialized) && serialize($serialized) === $decrypted
            ? $serialized
            : $decrypted;

        return Crypt::encryptString($credential);
    }
};
