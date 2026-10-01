<?php

namespace ST_system\Tests\Unit;

use ST_system\Access;
use ST_system\Tests\TestCase;

final class AccessTest extends TestCase {

    public function testSealRoundTrip(): void {
        $state = ['id' => 42, 'name' => 'Анна', 'nested' => ['a' => [1, 2]]];

        $blob = Access::seal($state, 'secret');

        $this->assertStringNotContainsString('Анна', $blob);
        $this->assertSame($state, Access::unseal($blob, 'secret'));
    }

    public function testSealUsesConfiguredSaltByDefault(): void {
        $blob = Access::seal(['x' => 1]);

        $this->assertSame(['x' => 1], Access::unseal($blob));
        $this->assertSame(['x' => 1], Access::unseal($blob, 'st-tests-salt'));
    }

    public function testUnsealRejectsTamperedOrForeignBlobs(): void {
        $blob = Access::seal(['role' => 'user'], 'secret');

        $tampered = $blob;
        $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 1);

        $this->assertSame([], Access::unseal($tampered, 'secret'));
        $this->assertSame([], Access::unseal($blob, 'other-salt'));
        $this->assertSame([], Access::unseal('short', 'secret'));
        $this->assertSame([], Access::unseal(null, 'secret'));
    }

    public function testXorStreamIsInvolution(): void {
        $data = str_repeat('0123456789', 10);

        $encoded = Access::xorStream($data, 'k');

        $this->assertSame(strlen($data), strlen($encoded));
        $this->assertNotSame($data, $encoded);
        $this->assertSame($data, Access::xorStream($encoded, 'k'));
        $this->assertNotSame($encoded, Access::xorStream($data, 'other'));
    }

    /** @dataProvider cidrCases */
    public function testIpInCidr(string $ip, string $cidr, bool $expected): void {
        $this->assertSame($expected, self::callPrivate(Access::class, 'ipInCidr', $ip, $cidr));
    }

    public function cidrCases(): array {
        return [
            ['192.168.1.10', '192.168.1.0/24', true],
            ['192.168.2.10', '192.168.1.0/24', false],
            ['10.1.2.3', '10.0.0.0/8', true],
            ['10.1.2.3', '0.0.0.0/0', true],
            ['172.16.5.4', '172.16.0.0/12', true],
            ['172.32.0.1', '172.16.0.0/12', false],
            ['192.168.1.10', '192.168.1.10/32', true],
            ['192.168.1.11', '192.168.1.10/32', false],
            ['2001:db8::1', '2001:db8::/32', true],
            ['2001:db9::1', '2001:db8::/32', false],
            ['192.168.1.10', '2001:db8::/32', false],
            ['192.168.1.10', '192.168.1.0/33', false],
            ['bad', '192.168.1.0/24', false],
            ['192.168.1.10', '192.168.1.0', false],
        ];
    }

    /** @dataProvider ipMatchCases */
    public function testIpMatches(string $ip, string $pattern, bool $expected): void {
        $this->assertSame($expected, self::callPrivate(Access::class, 'ipMatches', $ip, $pattern));
    }

    public function ipMatchCases(): array {
        return [
            ['192.168.1.10', '192.168.1.10', true],
            ['192.168.1.10', '192.168.', true],
            ['192.168.1.10', '10.', false],
            ['192.168.1.10', '192.168.1.0/24', true],
            ['::1', '0:0:0:0:0:0:0:1', true],
            ['192.168.1.10', '', false],
        ];
    }

    /**
     * Результат кэшируется в static функции — отдельный процесс на каждый сценарий.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRequestOriginFromHeader(): void {
        $_SERVER['HTTP_ORIGIN'] = 'https://shop.example.com';

        $this->assertSame('https://shop.example.com', Access::getRequestOrigin());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRequestOriginFallsBackToRefererHost(): void {
        $_SERVER['HTTP_REFERER'] = 'https://ref.example.com/page?x=1';

        $this->assertSame('ref.example.com', Access::getRequestOrigin());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testClientIpFromForwardedFor(): void {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7, 10.0.0.1';

        $this->assertSame('203.0.113.7', Access::getClientIp());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testClientIpFromTrustedProxyOnly(): void {
        Access::setConfig(['firewall' => ['proxies' => ['10.0.0.0/8' => 'HTTP_X_REAL_IP']]]);
        $_SERVER['REMOTE_ADDR'] = '10.1.2.3';
        $_SERVER['HTTP_X_REAL_IP'] = '198.51.100.4';

        $this->assertSame('198.51.100.4', Access::getClientIp());
    }

    public function testNormalizeIpExpandsIpv6(): void {
        $this->assertSame('0000:0000:0000:0000:0000:0000:0000:0001', self::callPrivate(Access::class, 'normalizeIp', '::1'));
        $this->assertSame('127.0.0.1', self::callPrivate(Access::class, 'normalizeIp', '127.0.0.1'));
    }
}
