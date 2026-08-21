<?php

declare(strict_types=1);

use App\Models\Package;
use App\Models\Repository;
use App\Models\Version;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\assertDatabaseHas;

it('backfills immutable revisions for existing version archives', function (): void {
    Schema::drop('version_archives');

    $repository = Repository::factory()->create();
    $package = Package::factory()->for($repository)->create();
    $version = Version::factory()->for($package)->create([
        'archive_path' => $repository->archivePath('existing.zip'),
        'shasum' => 'existing-shasum',
    ]);

    $migration = require database_path('migrations/2026_08_21_130508_create_version_archives_table.php');
    $migration->up();

    assertDatabaseHas('version_archives', [
        'version_id' => $version->id,
        'archive_path' => $repository->archivePath('existing.zip'),
        'shasum' => 'existing-shasum',
    ]);
});
