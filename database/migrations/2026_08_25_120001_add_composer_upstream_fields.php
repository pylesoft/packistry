<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table): void {
            $table->foreignId('composer_upstream_id')->nullable()->after('source_id')->constrained('composer_upstreams')->nullOnDelete();
            $table->timestamp('upstream_checked_at')->nullable();
            $table->timestamp('upstream_synced_at')->nullable();
            $table->string('upstream_etag')->nullable();
            $table->string('upstream_last_modified')->nullable();
        });

        Schema::table('versions', function (Blueprint $table): void {
            $table->timestamp('upstream_removed_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('versions', function (Blueprint $table): void {
            $table->dropColumn('upstream_removed_at');
        });

        Schema::table('packages', function (Blueprint $table): void {
            $table->dropForeign(['composer_upstream_id']);
            $table->dropColumn([
                'composer_upstream_id', 'upstream_checked_at', 'upstream_synced_at',
                'upstream_etag', 'upstream_last_modified',
            ]);
        });
    }
};
