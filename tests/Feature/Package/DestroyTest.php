<?php

declare(strict_types=1);

use App\Actions\Packages\DestroyPackage;
use App\CreateFromZip;
use App\Enums\Permission;
use App\Http\Resources\PackageResource;
use App\Models\Package;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\deleteJson;

it('destroys', function (?User $user, int $status): void {
    /** @var Package $package */
    $package = Package::factory()
        ->for(Repository::factory())
        ->create();

    $response = deleteJson("/api/packages/$package->id")
        ->assertStatus($status);

    if ($status !== 200) {
        return;
    }

    // @todo check if archives are cleaned
    $response->assertExactJson(resourceAsJson(new PackageResource($package)));
})->with([
    ...guestAndUsers(Permission::PACKAGE_DELETE, userWithPermission: 404),
    ...unscopedUser(Permission::PACKAGE_DELETE),
]);

it('deletes every immutable archive owned by the package', function (): void {
    Storage::fake();

    $repository = Repository::factory()->create();
    $package = Package::factory()
        ->for($repository)
        ->create(['name' => 'vendor/package']);

    $createFromZip = app(CreateFromZip::class);
    $createFromZip->create($package, __DIR__.'/../../Fixtures/project.zip', 'dev-main');
    $createFromZip->create($package, __DIR__.'/../../Fixtures/gitea-jamie-test.zip', 'dev-main');

    expect(Storage::allFiles())->toHaveCount(2);

    app(DestroyPackage::class)->handle($package);

    expect(Storage::allFiles())->toBeEmpty();
});
