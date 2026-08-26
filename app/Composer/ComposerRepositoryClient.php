<?php

declare(strict_types=1);

namespace App\Composer;

use App\Enums\ComposerSourceAuthType;
use App\Exceptions\ComposerRepositoryException;
use App\Models\Source;
use Composer\MetadataMinifier\MetadataMinifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

readonly class ComposerRepositoryClient
{
    private const int MAX_ARCHIVE_BYTES = 268_435_456;

    private const int MAX_METADATA_BYTES = 16_777_216;

    // Keeps malformed metadata bounded without restricting normal all-history mirrors.
    private const int MAX_PACKAGE_VERSIONS = 10_000;

    public function __construct(
        private Source $source,
        private ?OutboundUrlGuard $urlGuard = null,
    ) {}

    /** @return array<string, mixed> */
    public function validate(): array
    {
        $response = $this->get($this->endpoint('packages.json'));

        if ($response->failed()) {
            throw new ComposerRepositoryException('Composer upstream connection failed.');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new ComposerRepositoryException('Composer upstream returned invalid JSON.');
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
            throw new ComposerRepositoryException('Package name must be a Composer vendor/name.');
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
            throw new ComposerRepositoryException('Package was not found upstream.');
        }
        if ($response->failed()) {
            throw new ComposerRepositoryException('Composer package metadata request failed.');
        }

        $payload = $response->json();
        $versions = is_array($payload) && isset($payload['packages'][$name]) && is_array($payload['packages'][$name])
            ? $payload['packages'][$name]
            : null;

        if ($versions === null) {
            throw new ComposerRepositoryException('Composer upstream returned invalid package metadata.');
        }
        if (count($versions) > self::MAX_PACKAGE_VERSIONS) {
            throw new ComposerRepositoryException('Composer package metadata contains too many versions.');
        }
        if (($payload['minified'] ?? null) === 'composer/2.0') {
            if (collect($versions)->contains(fn ($version): bool => ! is_array($version))) {
                throw new ComposerRepositoryException('Composer upstream returned invalid package metadata.');
            }

            $versions = MetadataMinifier::expand($versions);
        }

        $indexedVersions = [];
        foreach ($versions as $version) {
            if (! is_array($version) || ! isset($version['version']) || ! is_string($version['version'])) {
                throw new ComposerRepositoryException('Composer upstream returned invalid package metadata.');
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

    public function archive(string $url, string $path): Response
    {
        $response = $this->get($url, [], $path);
        $contentLength = $response->header('Content-Length');
        if (($contentLength !== '' && (int) $contentLength > self::MAX_ARCHIVE_BYTES)
            || (is_file($path) && filesize($path) > self::MAX_ARCHIVE_BYTES)) {
            throw new ComposerRepositoryException('Composer package archive exceeds the size limit.');
        }

        return $response;
    }

    public function endpoint(string $path): string
    {
        return rtrim($this->source->url, '/').'/'.ltrim($path, '/');
    }

    /** @param array<string, string> $headers */
    private function get(string $url, array $headers = [], ?string $sink = null): Response
    {
        $current = $url;

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $addresses = $this->guard()->ensureSafe(
                $current,
                $this->source->auth_type !== ComposerSourceAuthType::NONE,
            );
            $response = $this->requestPinned($current, $addresses, $headers, $sink);
            if ($sink === null) {
                $contentLength = $response->header('Content-Length');
                if (($contentLength !== '' && (int) $contentLength > self::MAX_METADATA_BYTES)
                    || strlen($response->body()) > self::MAX_METADATA_BYTES) {
                    throw new ComposerRepositoryException('Composer upstream metadata exceeds the size limit.');
                }
            }
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

        throw new ComposerRepositoryException('Composer upstream redirected too many times.');
    }

    /**
     * @param  list<string>  $addresses
     * @param  array<string, string>  $headers
     */
    private function requestPinned(string $url, array $addresses, array $headers, ?string $sink): Response
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new ComposerRepositoryException('Composer upstream URL is invalid.');
        }

        $host = trim($parts['host'], '[]');
        $port = $this->port($parts);
        if ($port === null) {
            throw new ComposerRepositoryException('Composer upstream URL is invalid.');
        }

        foreach ($addresses as $index => $address) {
            $limit = $sink === null ? self::MAX_METADATA_BYTES : self::MAX_ARCHIVE_BYTES;
            $options = [
                'allow_redirects' => false,
                'on_headers' => static function ($response) use ($limit, $sink): void {
                    $contentEncoding = strtolower($response->getHeaderLine('Content-Encoding'));
                    if ($sink === null && $contentEncoding !== '' && $contentEncoding !== 'identity') {
                        throw new ComposerRepositoryException('Composer upstream returned unsupported content encoding.');
                    }
                    if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                        throw new ComposerRepositoryException('Composer upstream response exceeds the size limit.');
                    }
                },
                'progress' => static function (int $downloadTotal, int $downloadedBytes) use ($limit): void {
                    if ($downloadTotal > $limit || $downloadedBytes > $limit) {
                        throw new ComposerRepositoryException('Composer upstream response exceeds the size limit.');
                    }
                },
            ];
            if (filter_var($host, FILTER_VALIDATE_IP) === false) {
                $options['curl'] = [CURLOPT_RESOLVE => [$this->resolveEntry($host, $port, $address)]];
            }
            if ($sink === null) {
                $options['decode_content'] = false;
                $headers['Accept-Encoding'] = 'identity';
            }

            $request = Http::timeout(30)
                ->connectTimeout(10)
                ->withOptions($options)
                ->withHeaders($headers);

            if ($sink !== null) {
                $request = $request->sink($sink);
            }

            if ($this->sameOrigin($url, $this->source->url)) {
                $request = match ($this->source->auth_type) {
                    ComposerSourceAuthType::NONE, null => $request,
                    ComposerSourceAuthType::BASIC => $request->withBasicAuth(
                        (string) $this->source->composerUsername(),
                        (string) $this->source->composerPassword(),
                    ),
                    ComposerSourceAuthType::BEARER => $request->withToken((string) $this->source->composerToken()),
                };
            }

            try {
                return $request->get($url);
            } catch (ConnectionException) {
                if ($index === array_key_last($addresses)) {
                    throw new ComposerRepositoryException('Composer upstream connection failed.');
                }
            }
        }

        throw new ComposerRepositoryException('Composer upstream connection failed.');
    }

    private function resolveEntry(string $host, int $port, string $address): string
    {
        $pinnedAddress = str_contains($address, ':') ? "[$address]" : $address;

        return "$host:$port:$pinnedAddress";
    }

    private function guard(): OutboundUrlGuard
    {
        return $this->urlGuard ?? app(OutboundUrlGuard::class);
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
            throw new ComposerRepositoryException('Composer upstream returned an invalid redirect.');
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
