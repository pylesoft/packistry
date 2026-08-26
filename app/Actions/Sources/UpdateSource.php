<?php

declare(strict_types=1);

namespace App\Actions\Sources;

use App\Actions\Sources\Inputs\UpdateSourceInput;
use App\Enums\ComposerSourceAuthType;
use App\Enums\SourceProvider;
use App\Exceptions\FailedToParseUrlException;
use App\Exceptions\InvalidTokenException;
use App\Models\Source;
use App\Normalizer;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;
use Throwable;

class UpdateSource
{
    /**
     * @throws InvalidTokenException|FailedToParseUrlException
     */
    public function handle(Source $source, UpdateSourceInput $input): Source
    {
        if ($source->provider === SourceProvider::COMPOSER) {
            return $this->updateComposerSource($source, $input);
        }

        if (is_string($input->name)) {
            $source->name = $input->name;
        }

        if (is_string($input->url)) {
            $source->url = Normalizer::url($input->url);
        }

        if (is_array($input->metadata)) {
            $source->metadata = [
                ...$source->metadata,
                ...$input->metadata,
            ];
        }

        if (is_string($input->token)) {
            $source->token = encrypt($input->token);
        }

        if ($source->isDirty(['token', 'metadata'])) {
            $source->vcsClient()->validateToken();
        }

        $source->save();

        return $source;
    }

    private function updateComposerSource(Source $source, UpdateSourceInput $input): Source
    {
        $authType = $input->authType instanceof Optional ? $source->auth_type : $input->authType;
        $authChanged = $authType !== $source->auth_type;

        $username = ($input->username instanceof Optional || (! $authChanged && blank($input->username)))
            ? $source->composerUsername()
            : $input->username;
        $password = ($input->password instanceof Optional || (! $authChanged && blank($input->password)))
            ? $source->composerPassword()
            : $input->password;
        $token = ($input->token instanceof Optional || (! $authChanged && blank($input->token)))
            ? $source->composerToken()
            : $input->token;

        if ($authType === ComposerSourceAuthType::BASIC && (blank($username) || blank($password))) {
            throw ValidationException::withMessages(['password' => 'Basic authentication requires a username and password.']);
        }

        if ($authType === ComposerSourceAuthType::BEARER && blank($token)) {
            throw ValidationException::withMessages(['token' => 'Bearer authentication requires a token.']);
        }

        $source->forceFill([
            'name' => $input->name instanceof Optional ? $source->name : $input->name,
            'url' => $input->url instanceof Optional ? $source->url : $this->composerUrl($input->url),
            'metadata' => is_array($input->metadata) ? [...$source->metadata, ...$input->metadata] : $source->metadata,
            'auth_type' => $authType,
            'username' => $authType === ComposerSourceAuthType::BASIC ? encrypt($username) : null,
            'password' => $authType === ComposerSourceAuthType::BASIC ? encrypt($password) : null,
            'token' => encrypt($authType === ComposerSourceAuthType::BEARER ? $token : ''),
            'enabled' => $input->enabled instanceof Optional ? $source->enabled : $input->enabled,
        ]);

        if ($source->enabled && ($source->isDirty(['url', 'auth_type', 'username', 'password', 'token', 'enabled']) || $authChanged)) {
            try {
                $source->composerClient()->validate();
            } catch (Throwable) {
                throw ValidationException::withMessages(['url' => 'The Composer upstream could not be validated.']);
            }

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
}
