<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Health;

use M2Oidc\OAuth\Helper\Curl;
use M2Oidc\OAuth\Model\Health\ProviderReachabilityChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ProviderReachabilityChecker.
 *
 * @covers \M2Oidc\OAuth\Model\Health\ProviderReachabilityChecker
 */
class ProviderReachabilityCheckerTest extends TestCase
{
    /** @var Curl&MockObject */
    private Curl $curl;

    /** @var ProviderReachabilityChecker */
    private ProviderReachabilityChecker $checker;

    protected function setUp(): void
    {
        $this->curl    = $this->createMock(Curl::class);
        $this->checker = new ProviderReachabilityChecker($this->curl);
    }

    public function testReturnsNullWhenNothingConfiguredToProbe(): void
    {
        $result = $this->checker->isReachable([]);

        $this->assertNull($result);
    }

    public function testReturnsTrueWhenJwksEndpointReturnsKeysField(): void
    {
        $this->curl->method('sendUserInfoRequest')
            ->with('https://idp.example.com/jwks', [])
            ->willReturn(json_encode(['keys' => [['kty' => 'RSA']]]));

        $result = $this->checker->isReachable(['jwks_endpoint' => 'https://idp.example.com/jwks']);

        $this->assertTrue($result);
    }

    public function testReturnsFalseWhenJwksResponseMissingKeysField(): void
    {
        $this->curl->method('sendUserInfoRequest')->willReturn(json_encode(['error' => 'not_found']));

        $result = $this->checker->isReachable(['jwks_endpoint' => 'https://idp.example.com/jwks']);

        $this->assertFalse($result);
    }

    public function testReturnsFalseWhenJwksResponseIsEmpty(): void
    {
        $this->curl->method('sendUserInfoRequest')->willReturn('');

        $result = $this->checker->isReachable(['jwks_endpoint' => 'https://idp.example.com/jwks']);

        $this->assertFalse($result);
    }

    public function testReturnsFalseWhenFetchThrows(): void
    {
        $this->curl->method('sendUserInfoRequest')->willThrowException(new \RuntimeException('timeout'));

        $result = $this->checker->isReachable(['jwks_endpoint' => 'https://idp.example.com/jwks']);

        $this->assertFalse($result);
    }

    public function testFallsBackToDiscoveryUrlWhenJwksEndpointNotConfigured(): void
    {
        $this->curl->method('sendUserInfoRequest')
            ->with('https://idp.example.com/.well-known/openid-configuration', [])
            ->willReturn(json_encode(['authorization_endpoint' => 'https://idp.example.com/auth']));

        $result = $this->checker->isReachable([
            'well_known_config_url' => 'https://idp.example.com/.well-known/openid-configuration',
        ]);

        $this->assertTrue($result);
    }

    public function testPrefersJwksEndpointOverDiscoveryUrlWhenBothConfigured(): void
    {
        $this->curl->expects($this->once())
            ->method('sendUserInfoRequest')
            ->with('https://idp.example.com/jwks', [])
            ->willReturn(json_encode(['keys' => []]));

        $result = $this->checker->isReachable([
            'jwks_endpoint' => 'https://idp.example.com/jwks',
            'well_known_config_url' => 'https://idp.example.com/.well-known/openid-configuration',
        ]);

        $this->assertTrue($result);
    }
}
