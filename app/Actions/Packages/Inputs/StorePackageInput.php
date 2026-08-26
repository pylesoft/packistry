<?php

declare(strict_types=1);

namespace App\Actions\Packages\Inputs;

use App\Actions\Input;
use Spatie\LaravelData\Optional;

class StorePackageInput extends Input
{
    /**
     * @param  string[]  $projects
     */
    public function __construct(
        public int|string $repository,
        public int|string $source,
        public Optional|array $projects,
        public Optional|bool $webhook,
        public Optional|string $name = new Optional,
    ) {
        //
    }
}
