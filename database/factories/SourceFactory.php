<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ComposerSourceAuthType;
use App\Enums\SourceProvider;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name,
            'provider' => fake()->randomElement(SourceProvider::vcsCases()),
            'url' => fake()->url,
            'token' => encrypt(Str::random()),
            'secret' => encrypt(Str::random()),
        ];
    }

    public function provider(SourceProvider $provider): static
    {
        return $this
            ->state([
                'provider' => $provider,
                'secret' => encrypt('secret'),
                'url' => match ($provider) {
                    SourceProvider::GITEA => 'https://gitea.com',
                    SourceProvider::GITLAB => 'https://gitlab.com',
                    SourceProvider::GITHUB => 'https://api.github.com',
                    SourceProvider::BITBUCKET => 'https://api.bitbucket.org',
                    SourceProvider::COMPOSER => 'https://composer.example.com',
                },
            ]);
    }

    public function composer(): static
    {
        return $this->provider(SourceProvider::COMPOSER)->state([
            'token' => encrypt(''),
            'auth_type' => ComposerSourceAuthType::NONE,
            'enabled' => true,
            'last_checked_at' => now(),
        ]);
    }

    public function basic(string $username = 'buyer@example.test', string $password = 'license'): static
    {
        return $this->state([
            'provider' => SourceProvider::COMPOSER,
            'token' => encrypt(''),
            'secret' => encrypt('secret'),
            'auth_type' => ComposerSourceAuthType::BASIC,
            'username' => encrypt($username),
            'password' => encrypt($password),
            'enabled' => true,
        ]);
    }

    public function bearer(string $token = 'token'): static
    {
        return $this->state([
            'provider' => SourceProvider::COMPOSER,
            'secret' => encrypt('secret'),
            'auth_type' => ComposerSourceAuthType::BEARER,
            'token' => encrypt($token),
            'enabled' => true,
        ]);
    }
}
