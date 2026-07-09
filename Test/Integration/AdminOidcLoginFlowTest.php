<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Integration;

use M2Oidc\OAuth\Helper\OAuthSecurityHelper;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Cache\AtomicCacheInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for OAuthSecurityHelper state token creation/validation and PKCE helpers.
 *
 * Does not require a running Dex provider.  All Magento infrastructure is
 * replaced with PHPUnit mocks backed by an in-memory array cache.
 */
class AdminOidcLoginFlowTest extends TestCase
{
    /** @var array<string,string|false> In-memory cache store */
    private array $cacheStore = [];

    /** @var OAuthUtility&\PHPUnit\Framework\MockObject\MockObject */
    private OAuthUtility $oauthUtility;

    /** @var OAuthSecurityHelper */
    private OAuthSecurityHelper $helper;

    protected function setUp(): void
    {
        $this->cacheStore = [];

        $this->oauthUtility = $this->createMock(OAuthUtility::class);

        $this->oauthUtility
            ->method('customlog');

        $this->oauthUtility
            ->method('decodeBase64')
            ->willReturnCallback(static function (string $encoded): string {
                $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
                return is_string($decoded) ? $decoded : '';
            });

        $atomicCache = $this->createMock(AtomicCacheInterface::class);
        $atomicCache->method('save')->willReturnCallback(function (string $key, string $value): void {
            $this->cacheStore[$key] = $value;
        });
        $atomicCache->method('getAndDelete')->willReturnCallback(function (string $key): ?string {
            $value = $this->cacheStore[$key] ?? false;
            if ($value === false) {
                return null;
            }
            unset($this->cacheStore[$key]);
            return (string) $value;
        });
        $this->helper = new OAuthSecurityHelper($this->oauthUtility, $atomicCache);
    }

    // ---------------------------------------------------------------- admin nonce

    public function testCreateAdminLoginNonceReturnsHexString(): void
    {
        $nonce = $this->helper->createAdminLoginNonce('admin@example.com');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $nonce);
    }

    public function testRedeemAdminLoginNonceReturnsEmailOnFirstUse(): void
    {
        $nonce  = $this->helper->createAdminLoginNonce('admin@example.com');
        $result = $this->helper->redeemAdminLoginNonce($nonce);

        $this->assertSame('admin@example.com', $result);
    }

    public function testRedeemAdminLoginNonceReturnNullOnSecondUse(): void
    {
        $nonce = $this->helper->createAdminLoginNonce('admin@example.com');
        $this->helper->redeemAdminLoginNonce($nonce);

        $this->assertNull($this->helper->redeemAdminLoginNonce($nonce));
    }

    public function testRedeemAdminLoginNonceReturnNullForUnknownNonce(): void
    {
        // Cold cache — nothing stored
        $result = $this->helper->redeemAdminLoginNonce('aabbccdd00112233aabbccdd00112233');

        $this->assertNull($result);
    }

    public function testRedeemAdminLoginNonceReturnNullForInvalidFormat(): void
    {
        // Non-hex string, wrong length
        $this->assertNull($this->helper->redeemAdminLoginNonce('not-a-valid-nonce!!'));
    }

    // ---------------------------------------------------------------- state token

    public function testCreateStateTokenReturnsHexString(): void
    {
        $token = $this->helper->createStateToken('session_abc');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
    }

    public function testValidateStateTokenTrueOnFirstUse(): void
    {
        $token = $this->helper->createStateToken('session_abc');

        $this->assertTrue($this->helper->validateStateToken('session_abc', $token));
    }

    public function testValidateStateTokenFalseOnSecondUse(): void
    {
        $token = $this->helper->createStateToken('session_abc');
        $this->helper->validateStateToken('session_abc', $token);

        $this->assertFalse($this->helper->validateStateToken('session_abc', $token));
    }

    public function testValidateStateTokenFalseForWrongSession(): void
    {
        $token = $this->helper->createStateToken('A');

        $this->assertFalse($this->helper->validateStateToken('B', $token));
    }

    // ---------------------------------------------------------------- relay state encode / decode

    public function testEncodeDecodeRelayStateRoundtrip(): void
    {
        $relayState = '/checkout/cart';
        $sessionId  = 'sess_xyz789';
        $appName    = 'TestApp';
        $loginType  = 'customer';
        $stateToken = str_repeat('a', 32);

        $encoded = $this->helper->encodeRelayState(
            $relayState,
            $sessionId,
            $appName,
            $loginType,
            $stateToken
        );

        $decoded = $this->helper->decodeRelayState($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame($relayState, $decoded['relayState']);
        $this->assertSame($sessionId, $decoded['sessionId']);
        $this->assertSame($appName, $decoded['appName']);
        $this->assertSame($loginType, $decoded['loginType']);
        $this->assertSame($stateToken, $decoded['stateToken']);
    }

    public function testDecodeRelayStateReturnsNullForGarbage(): void
    {
        $this->assertNull($this->helper->decodeRelayState('!!!not_valid!!!!'));
    }
}
