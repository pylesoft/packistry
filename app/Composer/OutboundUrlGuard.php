<?php

declare(strict_types=1);

namespace App\Composer;

use App\Exceptions\ComposerUpstreamException;
use Closure;

readonly class OutboundUrlGuard
{
    /** @var Closure(string): list<string> */
    private Closure $resolve;

    /** @param (Closure(string): list<string>)|null $resolve */
    public function __construct(?Closure $resolve = null)
    {
        $this->resolve = $resolve ?? self::resolveHost(...);
    }

    /** @return list<string> */
    public function ensureSafe(string $url, bool $requireHttps = false): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new ComposerUpstreamException('Composer upstream URL is invalid.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)
            || array_intersect(['user', 'pass'], array_keys($parts)) !== []) {
            throw new ComposerUpstreamException('Composer upstream URL is unsafe.');
        }
        if ($requireHttps && $scheme !== 'https') {
            throw new ComposerUpstreamException('Authenticated Composer upstreams require HTTPS.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new ComposerUpstreamException('Composer upstream URL is unsafe.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : ($this->resolve)($host);
        if ($addresses === []) {
            throw new ComposerUpstreamException('Composer upstream host could not be resolved safely.');
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                throw new ComposerUpstreamException('Composer upstream URL resolves to an unsafe address.');
            }
        }

        return array_values(array_unique($addresses));
    }

    private function isPublicAddress(string $address): bool
    {
        $packed = @inet_pton($address);
        $mappedPrefix = str_repeat("\0", 10)."\xff\xff";
        if (is_string($packed) && strlen($packed) === 16 && substr($packed, 0, 12) === $mappedPrefix) {
            $mappedAddress = inet_ntop(substr($packed, 12));
            if (! is_string($mappedAddress)) {
                return false;
            }

            $address = $mappedAddress;
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /** @return list<string> */
    private static function resolveHost(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
