<?php

namespace Tests\Unit;

use App\Services\Calendar\Exceptions\InvalidFeedException;
use App\Services\Calendar\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UrlGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['timejump.ical.allow_private_hosts' => false]);
    }

    public function test_webcal_urls_are_converted_to_https(): void
    {
        $guard = new class extends UrlGuard
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        };

        $this->assertSame('https://example.com/cal.ics', $guard->normalize(' webcal://example.com/cal.ics '));
        $this->assertSame('https://example.com/cal.ics', $guard->normalize('webcals://example.com/cal.ics'));
    }

    #[DataProvider('blockedUrls')]
    public function test_unsafe_urls_are_rejected(string $url): void
    {
        $this->expectException(InvalidFeedException::class);

        (new UrlGuard)->normalize($url);
    }

    public static function blockedUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/cal.ics'],
            'private 10/8' => ['http://10.1.2.3/cal.ics'],
            'private 192.168/16' => ['https://192.168.1.10/cal.ics'],
            'link-local metadata' => ['http://169.254.169.254/latest/meta-data'],
            'ipv6 loopback' => ['http://[::1]/cal.ics'],
            'file scheme' => ['file:///etc/passwd'],
            'ftp scheme' => ['ftp://example.com/cal.ics'],
            'embedded credentials' => ['https://user:pass@example.com/cal.ics'],
        ];
    }

    public function test_hosts_resolving_to_private_addresses_are_rejected(): void
    {
        $guard = new class extends UrlGuard
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34', '10.0.0.8'];
            }
        };

        $this->expectException(InvalidFeedException::class);
        $guard->normalize('https://sneaky.example.com/cal.ics');
    }
}
