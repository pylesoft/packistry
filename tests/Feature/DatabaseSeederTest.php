<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;

test('the interactive seeder requires an artisan command', function (): void {
    expect(fn () => (new DatabaseSeeder)->run())
        ->toThrow(LogicException::class, 'This seeder requires an Artisan command.');
});
