<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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
            $table->string('health_status')->default('unknown');
            $table->text('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('composer_upstreams');
    }
};
