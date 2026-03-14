<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Integration;

use Magento\Framework\App\CacheInterface;
use M2Oidc\OAuth\Helper\OAuthSecurityHelper;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Security\OidcRateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for OidcRateLimiter behavior and PKCE challenge computation
 * from OAuthSecurityHelper.
 *
 * Does not require a running Dex provider.  All Magento infrastructure is
 * replaced with PHPUnit mocks backed by an in-memory array cache.
 */
class SecurityPluginsTest extends TestCase
{
    /** @var array<string,string|false> In-memory cache store for rate-limiter tests */
    private array $cacheStore = [];

    /** @var CacheInterface&\PHPUnit\Framework\MockObject\MockObject */
    private CacheInterface $cache;

    /** @var OidcRateLimiter */
    private OidcRateLimiter $rateLimiter;

    /** @var OAuthSecurityHelper */
    private OAuthSecurityHelper $securityHelper;

    protected function setUp(): void
    {
        $this->cacheStore = [];

        $this->cache = $this->createMock(CacheInterface::class);

        $this->cache
            ->method('save')
            ->willReturnCallback(function (string $value, string $key): bool {
                $this->cacheStore[$key] = $value;
                return true;
            });

        $this->cache
            ->method('load')
            ->willReturnCallback(function (string $key) {
                return $this->cacheStore[$key] ?? false;
            });

        $this->cache
            ->method('remove')
            ->willReturnCallback(function (string $key): bool {
                unset($this->cacheStore[$key]);
                return true;
            });

        $this->rateLimiter = new OidcRateLimiter($this->cache);

        $oauthUtility = $this->createMock(OAuthUtility::class);
        $oauthUtility->method('customlog');
        $oauthUtility
            ->method('decodeBase64')
            ->willReturnCallback(static function (string $encoded): string {
                $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
                return is_string($decoded) ? $decoded : '';
            });

        $this->securityHelper = new OAuthSecurityHelper($this->cache, $oauthUtility);
    }

    // ---------------------------------------------------------------- OidcRateLimiter

    public function testRateLimiterAllowsFirstTenRequests(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->assertTrue(
                $this->rateLimiter->isAllowed('1.2.3.4'),
                "Request #{$i} from 1.2.3.4 should be allowed."
            );
        }
    }

    public function testRateLimiterBlocksEleventhRequest(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->isAllowed('1.2.3.4');
        }

        $this->assertFalse(
            $this->rateLimiter->isAllowed('1.2.3.4'),
            'The 11th request must be blocked.'
        );
    }

    public function testRateLimiterTracksIpAddressesSeparately(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->rateLimiter->isAllowed('1.2.3.4');
        }

        // First IP is now blocked
        $this->assertFalse($this->rateLimiter->isAllowed('1.2.3.4'));

        // Different IP must still be allowed
        $this->assertTrue(
            $this->rateLimiter->isAllowed('9.9.9.9'),
            'A fresh IP address must not be affected by another IP\'s limit.'
        );
    }

    // ---------------------------------------------------------------- generateCodeVerifier

    public function testGenerateCodeVerifierIs43CharsUrlSafe(): void
    {
        $verifier = $this->securityHelper->generateCodeVerifier();

        $this->assertSame(43, strlen($verifier), 'Code verifier must be exactly 43 characters.');
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9\-_]+$/',
            $verifier,
            'Code verifier must contain only URL-safe characters.'
        );
    }

    // ---------------------------------------------------------------- computeCodeChallenge

    public function testComputeCodeChallengeS256MatchesRfc7636Vector(): void
    {
        // RFC 7636 Appendix B test vector
        $verifier           = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $expectedChallenge  = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        $challenge = $this->securityHelper->computeCodeChallenge($verifier, 'S256');

        $this->assertSame($expectedChallenge, $challenge);
    }

    public function testComputeCodeChallengePlainReturnsVerifier(): void
    {
        $verifier  = $this->securityHelper->generateCodeVerifier();
        $challenge = $this->securityHelper->computeCodeChallenge($verifier, 'plain');

        $this->assertSame($verifier, $challenge);
    }

    public function testComputeCodeChallengeThrowsOnUnknownMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $verifier = $this->securityHelper->generateCodeVerifier();
        $this->securityHelper->computeCodeChallenge($verifier, 'RS256');
    }

    public function testS256ChallengeIsUrlSafeBase64(): void
    {
        $verifier  = $this->securityHelper->generateCodeVerifier();
        $challenge = $this->securityHelper->computeCodeChallenge($verifier, 'S256');

        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9\-_]+$/',
            $challenge,
            'S256 challenge must use URL-safe base64 without +, /, or = characters.'
        );
    }
}
