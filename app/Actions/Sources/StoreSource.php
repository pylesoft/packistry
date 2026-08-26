<?php

declare(strict_types=1);

namespace App\Actions\Sources;

use App\Actions\Sources\Inputs\StoreSourceInput;
use App\Enums\ComposerSourceAuthType;
use App\Enums\SourceProvider;
use App\Exceptions\FailedToParseUrlException;
use App\Exceptions\InvalidTokenException;
use App\Models\Source;
use App\Normalizer;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;
use Throwable;

class StoreSource
{
    /**
     * @throws InvalidTokenException|FailedToParseUrlException
     */
    public function handle(StoreSourceInput $input): Source
    {
        if ($input->provider === SourceProvider::COMPOSER) {
            return $this->storeComposerSource($input);
        }

        if (! is_string($input->token) || blank($input->token)) {
            throw ValidationException::withMessages(['token' => 'An authentication token is required.']);
        }

        $input->provider->clientWith(
            token: $input->token,
            url: $input->url,
            metadata: $input->metadata,
        )->validateToken();

        $source = new Source;

        $source->name = $input->name;
        $source->provider = $input->provider;
        $source->url = Normalizer::url($input->url);
        $source->token = encrypt($input->token);
        $source->secret = encrypt(Str::random());

        if ($input->metadata !== null) {
            $source->metadata = $input->metadata;
        }

        $source->save();

        return $source;
    }

    private function storeComposerSource(StoreSourceInput $input): Source
    {
        $authType = $input->authType instanceof Optional
            ? ComposerSourceAuthType::NONE
            : $input->authType;
        $username = $input->username instanceof Optional ? null : $input->username;
        $password = $input->password instanceof Optional ? null : $input->password;
        $token = $input->token instanceof Optional ? null : $input->token;

        $this->validateCredentials($authType, $username, $password, $token);

        $source = new Source;
        $source->forceFill([
            'name' => $input->name,
            'provider' => SourceProvider::COMPOSER,
            'url' => $this->composerUrl($input->url),
            'token' => encrypt($authType === ComposerSourceAuthType::BEARER ? (string) $token : ''),
            'secret' => encrypt(Str::random()),
            'metadata' => $input->metadata ?? [],
            'auth_type' => $authType,
            'username' => $authType === ComposerSourceAuthType::BASIC ? encrypt((string) $username) : null,
            'password' => $authType === ComposerSourceAuthType::BASIC ? encrypt((string) $password) : null,
            'enabled' => $input->enabled instanceof Optional ? true : $input->enabled,
        ]);

        if ($source->enabled) {
            $this->validateComposerConnection($source);
            $source->last_checked_at = now();
        }

        $source->save();

        return $source;
    }

    private function composerUrl(string $url): string
    {
        $url = rtrim($url, '/');
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []) {
            throw ValidationException::withMessages(['url' => 'The upstream URL must contain only an HTTP(S) URL and path.']);
        }

        return $url;
    }

    private function validateCredentials(ComposerSourceAuthType $authType, ?string $username, ?string $password, ?string $token): void
    {
        if ($authType === ComposerSourceAuthType::BASIC && (blank($username) || blank($password))) {
            throw ValidationException::withMessages(['password' => 'Basic authentication requires a username and password.']);
        }

        if ($authType === ComposerSourceAuthType::BEARER && blank($token)) {
            throw ValidationException::withMessages(['token' => 'Bearer authentication requires a token.']);
        }
    }

    private function validateComposerConnection(Source $source): void
    {
        try {
            $source->composerClient()->validate();
        } catch (Throwable) {
            throw ValidationException::withMessages(['url' => 'The Composer upstream could not be validated.']);
        }
    }
}
