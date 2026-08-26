<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->dropIndex(['provider', 'url']);
            $table->text('url')->change();
            $table->index('provider');
            $table->string('auth_type')->nullable()->after('metadata');
            $table->text('username')->nullable()->after('auth_type');
            $table->text('password')->nullable()->after('username');
            $table->boolean('enabled')->default(true)->after('password');
            $table->timestamp('last_checked_at')->nullable()->after('enabled');
            $table->unsignedBigInteger('legacy_composer_upstream_id')->nullable()->unique()->after('last_checked_at');
        });

        DB::table('composer_upstreams')
            ->orderBy('id')
            ->each(function (object $upstream): void {
                $sourceId = DB::table('sources')->insertGetId([
                    'name' => $upstream->name,
                    'provider' => 'composer',
                    'url' => $upstream->url,
                    'token' => $upstream->token ?? encrypt(''),
                    'secret' => encrypt(Str::random()),
                    'metadata' => '{}',
                    'auth_type' => $upstream->auth_type,
                    'username' => $upstream->username,
                    'password' => $upstream->password,
                    'enabled' => $upstream->enabled,
                    'last_checked_at' => $upstream->last_checked_at,
                    'legacy_composer_upstream_id' => $upstream->id,
                    'created_at' => $upstream->created_at,
                    'updated_at' => $upstream->updated_at,
                ]);

                DB::table('packages')
                    ->where('composer_upstream_id', $upstream->id)
                    ->update(['source_id' => $sourceId]);
            });
    }

    public function down(): void
    {
        DB::table('sources')
            ->where('provider', 'composer')
            ->whereNotNull('legacy_composer_upstream_id')
            ->orderBy('id')
            ->each(function (object $source): void {
                DB::table('packages')
                    ->where('source_id', $source->id)
                    ->update(['source_id' => null]);
            });

        DB::table('sources')
            ->where('provider', 'composer')
            ->whereNotNull('legacy_composer_upstream_id')
            ->delete();

        Schema::table('sources', function (Blueprint $table): void {
            $table->dropIndex(['provider']);
            $table->string('url')->change();
            $table->index(['provider', 'url']);
            $table->dropUnique(['legacy_composer_upstream_id']);
            $table->dropColumn([
                'auth_type',
                'username',
                'password',
                'enabled',
                'last_checked_at',
                'legacy_composer_upstream_id',
            ]);
        });
    }
};
