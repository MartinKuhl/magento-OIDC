<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Validation;

use M2Oidc\OAuth\Model\Validation\SsrfUrlValidator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shared SSRF URL validator (H-09).
 *
 * Verifies that:
 *  - loopback and RFC-1918 private hosts are rejected
 *  - non-HTTPS and malformed URLs are rejected
 *  - public HTTPS URLs are accepted
 *
 * @covers \M2Oidc\OAuth\Model\Validation\SsrfUrlValidator
 */
class SsrfUrlValidatorTest extends TestCase
{
    /** @var SsrfUrlValidator */
    private SsrfUrlValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SsrfUrlValidator();
    }

    // -------------------------------------------------------------------------
    // isAllowedExternalHttpsUrl() — blocked URLs
    // -------------------------------------------------------------------------

    /**
     * @dataProvider blockedUrlProvider
     */
    public function testBlockedUrlsAreRejected(string $url): void
    {
        $this->assertFalse(
            $this->validator->isAllowedExternalHttpsUrl($url),
            "URL '$url' must be rejected"
        );
    }

    /** @return array<string, array{string}> */
    public static function blockedUrlProvider(): array
    {
        return [
            'localhost'            => ['https://localhost/.well-known/openid-configuration'],
            'loopback IPv4'        => ['https://127.0.0.1/.well-known/openid-configuration'],
            'wildcard address'     => ['https://0.0.0.0/auth'],
            '10.x private range'   => ['https://10.0.0.5/auth'],
            '192.168.x range'      => ['https://192.168.1.1/token'],
            '172.16.x range low'   => ['https://172.16.0.1/userinfo'],
            '172.31.x range high'  => ['https://172.31.9.9/jwks'],
            'plain HTTP public'    => ['http://public.example.com/.well-known/openid-configuration'],
            'not a URL'            => ['not a url'],
            'empty string'         => [''],
            'ftp scheme'           => ['ftp://accounts.google.com/config'],
        ];
    }

    // -------------------------------------------------------------------------
    // isAllowedExternalHttpsUrl() — allowed URLs
    // -------------------------------------------------------------------------

    /**
     * @dataProvider allowedUrlProvider
     */
    public function testAllowedUrlsAreAccepted(string $url): void
    {
        $this->assertTrue(
            $this->validator->isAllowedExternalHttpsUrl($url),
            "URL '$url' must be accepted"
        );
    }

    /** @return array<string, array{string}> */
    public static function allowedUrlProvider(): array
    {
        return [
            'google discovery'   => ['https://accounts.google.com/.well-known/openid-configuration'],
            'azure discovery'    => ['https://login.microsoftonline.com/common/v2.0/.well-known/openid-configuration'],
            'custom idp'         => ['https://auth.example.com/oidc/token'],
            '172.15.x is public' => ['https://172.15.0.1/auth'],
            '172.32.x is public' => ['https://172.32.0.1/auth'],
            'public IPv4'        => ['https://8.8.8.8/auth'],
        ];
    }

    // -------------------------------------------------------------------------
    // isPrivateHost()
    // -------------------------------------------------------------------------

    /**
     * @dataProvider privateHostProvider
     */
    public function testPrivateHostsAreDetected(string $host): void
    {
        $this->assertTrue(
            $this->validator->isPrivateHost($host),
            "Host '$host' must be detected as private"
        );
    }

    /** @return array<string, array{string}> */
    public static function privateHostProvider(): array
    {
        return [
            'localhost'        => ['localhost'],
            'loopback IPv4'    => ['127.0.0.1'],
            'loopback IPv6'    => ['::1'],
            'wildcard address' => ['0.0.0.0'],
            '10.x range'       => ['10.0.0.5'],
            '192.168.x range'  => ['192.168.1.1'],
            '172.16.x range'   => ['172.16.0.1'],
            '172.31.x range'   => ['172.31.9.9'],
        ];
    }

    /**
     * @dataProvider publicHostProvider
     */
    public function testPublicHostsAreNotDetectedAsPrivate(string $host): void
    {
        $this->assertFalse(
            $this->validator->isPrivateHost($host),
            "Host '$host' must not be detected as private"
        );
    }

    /** @return array<string, array{string}> */
    public static function publicHostProvider(): array
    {
        return [
            'public domain'      => ['accounts.google.com'],
            'public IPv4'        => ['8.8.8.8'],
            '172.15.x is public' => ['172.15.0.1'],
            '172.32.x is public' => ['172.32.0.1'],
        ];
    }
}
