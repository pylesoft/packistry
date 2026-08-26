<?php

declare(strict_types=1);

namespace App\Actions\Sources\Inputs;

use App\Actions\Input;
use App\Enums\ComposerSourceAuthType;
use App\Enums\SourceProvider;
use SensitiveParameter;
use Spatie\LaravelData\Optional;

class StoreSourceInput extends Input
{
    /**
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public string $name,
        public SourceProvider $provider,
        public string $url,
        #[SensitiveParameter] public Optional|string $token,
        public ?array $metadata = [],
        public Optional|ComposerSourceAuthType $authType = new Optional,
        #[SensitiveParameter] public Optional|string|null $username = new Optional,
        #[SensitiveParameter] public Optional|string|null $password = new Optional,
        public Optional|bool $enabled = new Optional,
    ) {}
}
