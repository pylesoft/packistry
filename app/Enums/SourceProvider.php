<?php

declare(strict_types=1);

namespace App\Enums;

use App\Sources\Client;
use RuntimeException;

enum SourceProvider: string
{
    case GITEA = 'gitea';
    case GITHUB = 'github';
    case GITLAB = 'gitlab';
    case BITBUCKET = 'bitbucket';
    case COMPOSER = 'composer';

    /** @return list<self> */
    public static function vcsCases(): array
    {
        return array_filter(
            self::cases(),
            fn (self $provider): bool => $provider !== self::COMPOSER,
        );
    }

    public function clientClassString(): string
    {
        if ($this === self::COMPOSER) {
            throw new RuntimeException('Composer sources do not use the VCS client contract.');
        }

        $class = config()->string("services.$this->value.client");

        if (! is_subclass_of($class, Client::class)) {
            throw new RuntimeException($class.' has to implement '.Client::class);
        }

        return $class;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function clientWith(string $token, string $url, ?array $metadata = []): Client
    {
        /** @var Client $client */
        $client = app($this->clientClassString());

        return $client->withOptions(
            token: $token,
            url: $url,
            metadata: $metadata ?? [],
        );
    }
}
