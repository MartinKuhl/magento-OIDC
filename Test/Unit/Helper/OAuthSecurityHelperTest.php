<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Helper;

use M2Oidc\OAuth\Helper\OAuthSecurityHelper;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Cache\AtomicCacheInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OAuthSecurityHelper.
 *
 * All Magento infrastructure (AtomicCacheInterface, OAuthUtility) is replaced
 * with PHPUnit mocks so these tests run without a Magento installation.
 *
 * @covers \M2Oidc\OAuth\Helper\OAuthSecurityHelper
 */
class OAuthSecurityHelperTest extends TestCase
{
    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var AtomicCacheInterface&MockObject */
    private AtomicCacheInterface $atomicCache;

    /** @var OAuthSecurityHelper */
    private OAuthSecurityHelper $helper;

    protected function setUp(): void
    {
        $this->oauthUtility = $this->createMock(OAuthUtility::class);
        $this->atomicCache  = $this->createMock(AtomicCacheInterface::class);

        $this->helper = new OAuthSecurityHelper(
            $this->oauthUtility,
            $this->atomicCache
        );
    }

    // -------------------------------------------------------------------------
    // Admin nonce
    // -------------------------------------------------------------------------

    public function testCreateAdminLoginNonceSavesEmailViaAtomicCache(): void
    {
        $this->atomicCache->expects($this->once())
            ->method('save')
            ->with($this->isType('string'), 'admin@example.com', $this->isType('int'));

        $nonce = $this->helper->createAdminLoginNonce('admin@example.com');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $nonce);
    }

    public function testRedeemAdminLoginNonceReturnNullForEmptyNonce(): void
    {
        $this->atomicCache->expects($this->never())->method('getAndDelete');
        $this->assertNull($this->helper->redeemAdminLoginNonce(''));
    }

    public function testRedeemAdminLoginNonceReturnNullForNonHexNonce(): void
    {
        $this->atomicCache->expects($this->never())->method('getAndDelete');
        $this->assertNull($this->helper->redeemAdminLoginNonce('not-a-valid-nonce'));
    }

    public function testRedeemAdminLoginNonceReturnNullForWrongLength(): void
    {
        // 30 hex chars — too short
        $this->atomicCache->expects($this->never())->method('getAndDelete');
        $this->assertNull($this->helper->redeemAdminLoginNonce(str_repeat('a', 30)));
    }

    public function testRedeemAdminLoginNonceReturnNullWhenCacheMissed(): void
    {
        $nonce = str_repeat('a', 32); // valid format
        $this->atomicCache->expects($this->once())
            ->method('getAndDelete')
            ->willReturn(null);
        $this->assertNull($this->helper->redeemAdminLoginNonce($nonce));
    }

    public function testRedeemAdminLoginNonceReturnsEmailAndDeletesNonce(): void
    {
        $nonce = str_repeat('b', 32);
        $email = 'admin@example.com';

        $this->atomicCache->expects($this->once())
            ->method('getAndDelete')
            ->willReturn($email);

        $result = $this->helper->redeemAdminLoginNonce($nonce);
        $this->assertSame($email, $result);
    }

    // -------------------------------------------------------------------------
    // Customer nonce
    // -------------------------------------------------------------------------

    public function testCreateCustomerLoginNonceSavesPayloadViaAtomicCache(): void
    {
        $this->atomicCache->expects($this->once())
            ->method('save')
            ->with(
                $this->isType('string'),
                $this->callback(function (string $data): bool {
                    $decoded = json_decode($data, true);
                    return $decoded['email'] === 'customer@example.com'
                        && $decoded['relayState'] === '/checkout';
                }),
                $this->isType('int')
            );

        $nonce = $this->helper->createCustomerLoginNonce('customer@example.com', '/checkout');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $nonce);
    }

    public function testRedeemCustomerLoginNonceReturnNullForInvalidFormat(): void
    {
        $this->atomicCache->expects($this->never())->method('getAndDelete');
        $this->assertNull($this->helper->redeemCustomerLoginNonce('INVALID'));
    }

    public function testRedeemCustomerLoginNonceReturnNullOnCacheMiss(): void
    {
        $nonce = str_repeat('c', 32);
        $this->atomicCache->method('getAndDelete')->willReturn(null);
        $this->assertNull($this->helper->redeemCustomerLoginNonce($nonce));
    }

    public function testRedeemCustomerLoginNonceReturnNullOnInvalidJson(): void
    {
        $nonce = str_repeat('d', 32);
        // atomicCache returns invalid JSON — the method deletes-on-read atomically, then
        // returns null because JSON decoding fails (no separate remove() call needed).
        $this->atomicCache->method('getAndDelete')->willReturn('not-json');
        $this->assertNull($this->helper->redeemCustomerLoginNonce($nonce));
    }

    public function testRedeemCustomerLoginNonceReturnsDataAndDeletesNonce(): void
    {
        $nonce = str_repeat('e', 32);
        $payload = json_encode(['email' => 'user@example.com', 'relayState' => '/dashboard']);

        $this->atomicCache->expects($this->once())
            ->method('getAndDelete')
            ->willReturn($payload);

        $result = $this->helper->redeemCustomerLoginNonce($nonce);

        $this->assertIsArray($result);
        $this->assertSame('user@example.com', $result['email']);
        $this->assertSame('/dashboard', $result['relayState']);
    }

    // -------------------------------------------------------------------------
    // State token
    // -------------------------------------------------------------------------

    public function testCreateStateTokenSavesViaAtomicCache(): void
    {
        $this->atomicCache->expects($this->once())
            ->method('save')
            ->with($this->isType('string'), '1', $this->isType('int'));

        $token = $this->helper->createStateToken('session123');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
    }

    public function testValidateStateTokenReturnsFalseForEmptySessionId(): void
    {
        $this->atomicCache->expects($this->never())->method('getAndDelete');
        $this->assertFalse($this->helper->validateStateToken('', str_repeat('f', 32)));
    }

    public function testValidateStateTokenReturnsFalseForInvalidFormat(): void
    {
        $this->atomicCache->expects($this->never())->method('getAndDelete');
        $this->assertFalse($this->helper->validateStateToken('session123', 'not-hex'));
    }

    public function testValidateStateTokenReturnsFalseOnCacheMiss(): void
    {
        $this->atomicCache->method('getAndDelete')->willReturn(null);
        $this->assertFalse(
            $this->helper->validateStateToken('session123', str_repeat('f', 32))
        );
    }

    public function testValidateStateTokenReturnsTrueAndDeletesToken(): void
    {
        $this->atomicCache->expects($this->once())
            ->method('getAndDelete')
            ->willReturn('1');

        $this->assertTrue(
            $this->helper->validateStateToken('session123', str_repeat('a', 32))
        );
    }

    // -------------------------------------------------------------------------
    // PKCE verifier / ephemeral OIDC auth token / OIDC nonce — write paths
    // -------------------------------------------------------------------------

    public function testStorePkceVerifierSavesViaAtomicCache(): void
    {
        $this->atomicCache->expects($this->once())
            ->method('save')
            ->with($this->isType('string'), 'verifier-value', $this->isType('int'));

        $nonce = $this->helper->storePkceVerifier('verifier-value', 1);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $nonce);
    }

    public function testCreateOidcAuthTokenSavesEmailViaAtomicCache(): void
    {
        $this->atomicCache->expects($this->once())
            ->method('save')
            ->with($this->isType('string'), 'admin@example.com', $this->isType('int'));

        $token = $this->helper->createOidcAuthToken('admin@example.com');
        $this->assertStringStartsWith('OIDC_', $token);
    }

    public function testStoreOidcNonceSavesViaAtomicCache(): void
    {
        $this->atomicCache->expects($this->once())
            ->method('save')
            ->with($this->isType('string'), 'the-nonce', $this->isType('int'));

        $this->helper->storeOidcNonce('the-state-token', 'the-nonce');
    }

    // -------------------------------------------------------------------------
    // Relay state encode / decode round-trip
    // -------------------------------------------------------------------------

    public function testEncodeDecodeRelayStateRoundTrip(): void
    {
        $relayState = 'https://example.com/checkout';
        $sessionId  = 'sess_abc123';
        $appName    = 'MyApp';
        $loginType  = 'customer';
        $stateToken = str_repeat('9', 32);

        $encoded = $this->helper->encodeRelayState(
            $relayState,
            $sessionId,
            $appName,
            $loginType,
            $stateToken
        );

        // The OAuthUtility::decodeBase64 is called inside decodeRelayState;
        // wire the mock to call through to the real base64 decode logic.
        $this->oauthUtility->method('decodeBase64')
            ->willReturnCallback(static function (string $encoded): string {
                $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
                return is_string($decoded) ? $decoded : '';
            });

        $decoded = $this->helper->decodeRelayState($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame($relayState, $decoded['relayState']);
        $this->assertSame($sessionId, $decoded['sessionId']);
        $this->assertSame($appName, $decoded['appName']);
        $this->assertSame($loginType, $decoded['loginType']);
        $this->assertSame($stateToken, $decoded['stateToken']);
    }

    public function testDecodeRelayStateReturnNullForGarbage(): void
    {
        $this->oauthUtility->method('decodeBase64')->willReturn('');
        $this->assertNull($this->helper->decodeRelayState('garbage!!!'));
    }

    // -------------------------------------------------------------------------
    // validateRedirectUrl
    // -------------------------------------------------------------------------

    public function testValidateRedirectUrlAllowsRelativePaths(): void
    {
        $this->assertSame(
            '/checkout',
            $this->helper->validateRedirectUrl('/checkout')
        );
    }

    public function testValidateRedirectUrlRejectsProtocolRelative(): void
    {
        // //evil.com is NOT a relative path; parseUrlComponents is called twice:
        // once for the redirect URL, once for the base URL.
        $this->oauthUtility->method('parseUrlComponents')
            ->willReturnOnConsecutiveCalls(
                ['host' => 'evil.com', 'scheme' => 'https'],
                ['host' => 'shop.example.com']
            );
        $this->oauthUtility->method('getBaseUrl')->willReturn('https://shop.example.com/');
        $this->oauthUtility->method('customlog');

        $result = $this->helper->validateRedirectUrl('//evil.com/steal');
        $this->assertSame('/', $result);
    }

    public function testValidateRedirectUrlAllowsSameHost(): void
    {
        $this->oauthUtility->method('parseUrlComponents')
            ->willReturnOnConsecutiveCalls(
                ['host' => 'shop.example.com', 'scheme' => 'https'],
                ['host' => 'shop.example.com']
            );
        $this->oauthUtility->method('getBaseUrl')->willReturn('https://shop.example.com/');

        $result = $this->helper->validateRedirectUrl('https://shop.example.com/checkout');
        $this->assertSame('https://shop.example.com/checkout', $result);
    }

    public function testValidateRedirectUrlBlocksDifferentHost(): void
    {
        $this->oauthUtility->method('parseUrlComponents')
            ->willReturnOnConsecutiveCalls(
                ['host' => 'evil.com', 'scheme' => 'https'],
                ['host' => 'shop.example.com']
            );
        $this->oauthUtility->method('getBaseUrl')->willReturn('https://shop.example.com/');
        $this->oauthUtility->method('customlog');

        $result = $this->helper->validateRedirectUrl('https://evil.com/phish');
        $this->assertSame('/', $result);
    }
}
