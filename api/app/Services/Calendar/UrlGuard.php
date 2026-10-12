<?php

namespace App\Services\Calendar;

use App\Services\Calendar\Exceptions\InvalidFeedException;

/**
 * Validates user-supplied feed URLs before the server fetches them (SSRF protection).
 */
class UrlGuard
{
    /**
     * Normalise webcal:// to https:// and reject anything that is not a public http(s) URL.
     */
    public function normalize(string $url): string
    {
        $url = trim($url);
        $url = preg_replace('#^webcals?://#i', 'https://', $url);

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');

        if (! $parts || ! in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new InvalidFeedException('Only http://, https:// and webcal:// URLs are supported.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidFeedException('URLs containing credentials are not supported.');
        }

        if (! config('timejump.ical.allow_private_hosts')) {
            $this->assertPublicHost($parts['host']);
        }

        return $url;
    }

    private function assertPublicHost(string $host): void
    {
        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);

        if ($ips === []) {
            throw new InvalidFeedException("Could not resolve the host [{$host}].");
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidFeedException('Feeds on private, loopback or reserved network addresses are not allowed.');
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $records
        )));
    }
}
