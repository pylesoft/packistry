<?php

declare(strict_types=1);

namespace App\Composer;

use App\Enums\ComposerUpstreamAuthType;
use App\Exceptions\ComposerUpstreamException;
use App\Models\ComposerUpstream;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

readonly class ComposerUpstreamClient
{
    public function __construct(private ComposerUpstream $upstream) {}

    /** @return array<string, mixed> */
    public function validate(): array
    {
        $response = $this->get($this->endpoint('packages.json'));

        if ($response->failed()) {
            throw new ComposerUpstreamException('Composer upstream connection failed.');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new ComposerUpstreamException('Composer upstream returned invalid JSON.');
        }

        return [
            'composer' => true,
            'metadata_url' => $this->endpoint('p2/%package%.json'),
            'package_count' => isset($payload['packages']) && is_array($payload['packages'])
                ? count($payload['packages'])
                : null,
        ];
    }

    /** @return array{versions: array<string, array<string, mixed>>, etag: string|null, last_modified: string|null, not_modified: bool} */
    public function package(string $name, ?string $etag = null, ?string $lastModified = null): array
    {
        if (preg_match('/^[^\/\s]+\/[^\/\s]+$/', $name) !== 1) {
            throw new ComposerUpstreamException('Package name must be a Composer vendor/name.');
        }

        [$vendor, $package] = explode('/', $name, 2);
        $headers = array_filter([
            'If-None-Match' => $etag,
            'If-Modified-Since' => $lastModified,
        ], fn (?string $value): bool => $value !== null);
        $response = $this->get($this->endpoint('p2/'.rawurlencode($vendor).'/'.rawurlencode($package).'.json'), $headers);
        if ($response->status() === 304) {
            return [
                'versions' => [],
                'etag' => $etag,
                'last_modified' => $lastModified,
                'not_modified' => true,
            ];
        }
        if ($response->status() === 404) {
            throw new ComposerUpstreamException('Package was not found upstream.');
        }
        if ($response->failed()) {
            throw new ComposerUpstreamException('Composer package metadata request failed.');
        }

        $payload = $response->json();
        $versions = is_array($payload) && isset($payload['packages'][$name]) && is_array($payload['packages'][$name])
            ? $payload['packages'][$name]
            : null;

        if ($versions === null) {
            throw new ComposerUpstreamException('Composer upstream returned invalid package metadata.');
        }

        $indexedVersions = [];
        foreach ($versions as $version) {
            if (! is_array($version) || ! isset($version['version']) || ! is_string($version['version'])) {
                throw new ComposerUpstreamException('Composer upstream returned invalid package metadata.');
            }

            $indexedVersions[$version['version']] = $version;
        }

        return [
            'versions' => $indexedVersions,
            'etag' => $response->header('ETag'),
            'last_modified' => $response->header('Last-Modified'),
            'not_modified' => false,
        ];
    }

    public function archive(string $url): Response
    {
        return $this->get($url);
    }

    public function endpoint(string $path): string
    {
        return rtrim($this->upstream->url, '/').'/'.ltrim($path, '/');
    }

    /** @param array<string, string> $headers */
    private function get(string $url, array $headers = []): Response
    {
        $current = $url;

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $request = Http::timeout(30)
                ->connectTimeout(10)
                ->withOptions(['allow_redirects' => false])
                ->withHeaders($headers);

            if ($this->sameOrigin($current, $this->upstream->url)) {
                $request = match ($this->upstream->auth_type) {
                    ComposerUpstreamAuthType::NONE => $request,
                    ComposerUpstreamAuthType::BASIC => $request->withBasicAuth(
                        (string) $this->upstream->username,
                        (string) $this->upstream->password,
                    ),
                    ComposerUpstreamAuthType::BEARER => $request->withToken((string) $this->upstream->token),
                };
            }

            $response = $request->get($current);
            $redirect = $response->redirect();
            if ($redirect === false) {
                return $response;
            }

            $location = $response->header('Location');
            if ($location === '') {
                return $response;
            }

            $current = $this->resolveUrl($current, $location);
        }

        throw new ComposerUpstreamException('Composer upstream redirected too many times.');
    }

    private function sameOrigin(string $left, string $right): bool
    {
        $a = parse_url($left);
        $b = parse_url($right);

        if (! is_array($a) || ! is_array($b) || ! isset($a['scheme'], $a['host'], $b['scheme'], $b['host'])) {
            return false;
        }

        return strtolower($a['scheme']) === strtolower($b['scheme'])
            && strtolower($a['host']) === strtolower($b['host'])
            && $this->port($a) === $this->port($b);
    }

    /** @param array<string, mixed> $url */
    private function port(array $url): ?int
    {
        if (isset($url['port']) && is_int($url['port'])) {
            return $url['port'];
        }

        return match (strtolower((string) ($url['scheme'] ?? ''))) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }

    private function resolveUrl(string $base, string $location): string
    {
        if (filter_var($location, FILTER_VALIDATE_URL) !== false) {
            return $location;
        }

        $parts = parse_url($base);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new ComposerUpstreamException('Composer upstream returned an invalid redirect.');
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (Str::startsWith($location, '//')) {
            return $parts['scheme'].':'.$location;
        }

        if (Str::startsWith($location, '/')) {
            return $origin.$location;
        }

        return $origin.'/'.ltrim(dirname($parts['path'] ?? '/').'/'.$location, '/');
    }
}
