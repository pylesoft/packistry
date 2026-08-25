<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ComposerUpstreamAuthType;
use App\Models\ComposerUpstream;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ComposerUpstream> */
class ComposerUpstreamFactory extends Factory
{
    protected $model = ComposerUpstream::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' Composer',
            'url' => fake()->url(),
            'auth_type' => ComposerUpstreamAuthType::NONE,
            'enabled' => true,
        ];
    }

    public function basic(string $username = 'user@example.com', string $password = 'secret'): static
    {
        return $this->state([
            'auth_type' => ComposerUpstreamAuthType::BASIC,
            'username' => $username,
            'password' => $password,
        ]);
    }

    public function bearer(string $token = 'token'): static
    {
        return $this->state([
            'auth_type' => ComposerUpstreamAuthType::BEARER,
            'token' => $token,
        ]);
    }
}
