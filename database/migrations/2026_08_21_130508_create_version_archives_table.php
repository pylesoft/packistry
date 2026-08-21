<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('version_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained()->cascadeOnDelete();
            $table->string('shasum', 64);
            $table->string('archive_path');
            $table->timestamps();

            $table->unique(['version_id', 'shasum']);
            $table->unique('archive_path');
        });

        DB::table('versions')
            ->whereNotNull('archive_path')
            ->orderBy('id')
            ->chunkById(500, function ($versions): void {
                DB::table('version_archives')->insert(
                    $versions->map(fn (object $version): array => [
                        'version_id' => $version->id,
                        'shasum' => $version->shasum,
                        'archive_path' => $version->archive_path,
                        'created_at' => $version->created_at,
                        'updated_at' => $version->updated_at,
                    ])->all()
                );
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('version_archives');
    }
};
