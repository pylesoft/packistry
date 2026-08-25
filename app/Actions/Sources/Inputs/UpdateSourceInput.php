<?php

declare(strict_types=1);

namespace App\Actions\Sources\Inputs;

use App\Actions\Input;
use App\Enums\ComposerUpstreamAuthType;
use SensitiveParameter;
use Spatie\LaravelData\Optional;

class UpdateSourceInput extends Input
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public Optional|string $name,
        public Optional|string $url,
        #[SensitiveParameter] public Optional|string $token,
        public Optional|array|null $metadata = new Optional,
        public Optional|ComposerUpstreamAuthType $authType = new Optional,
        #[SensitiveParameter] public Optional|string|null $username = new Optional,
        #[SensitiveParameter] public Optional|string|null $password = new Optional,
        public Optional|bool $enabled = new Optional,
    ) {}
}
