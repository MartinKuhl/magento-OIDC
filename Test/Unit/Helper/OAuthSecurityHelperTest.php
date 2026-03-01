<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Test\Unit\Helper;

use Magento\Framework\App\CacheInterface;
use MiniOrange\OAuth\Helper\OAuthSecurityHelper;
use MiniOrange\OAuth\Helper\OAuthUtility;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OAuthSecurityHelper.
 *
 * All Magento infrastructure (CacheInterface, OAuthUtility) is replaced
 * with PHPUnit mocks so these tests run without a Magento installation.
 *
 * @covers \MiniOrange\OAuth\Helper\OAuthSecurityHelper
 */
class OAuthSecurityHelperTest extends TestCase
{
    /** @var CacheInterface&MockObject */
    private CacheInterface $cache;

    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var OAuthSecurityHelper */
    private OAuthSecurityHelper $helper;

    protected function setUp(): void
    {
        $this->cache        = $this->createMock(CacheInterface::class);
        $this->oauthUtility = $this->createMock(OAuthUtility::class);

        $this->helper = new OAuthSecurityHelper(
            $this->cache,
            $this->oauthUtility
        );
    }

    // -------------------------------------------------------------------------
    // Admin nonce
    // -------------------------------------------------------------------------

    public function testRedeemAdminLoginNonceReturnNullForEmptyNonce(): void
    {
        $this->cache->expects($this->never())->method('load');
        $this->assertNull($this->helper->redeemAdminLoginNonce(''));
    }

    public function testRedeemAdminLoginNonceReturnNullForNonHexNonce(): void
    {
        $this->cache->expects($this->never())->method('load');
        $this->assertNull($this->helper->redeemAdminLoginNonce('not-a-valid-nonce'));
    }

    public function testRedeemAdminLoginNonceReturnNullForWrongLength(): void
    {
        // 30 hex chars — too short
        $this->cache->expects($this->never())->method('load');
        $this->assertNull($this->helper->redeemAdminLoginNonce(str_repeat('a', 30)));
    }

    public function testRedeemAdminLoginNonceReturnNullWhenCacheMissed(): void
    {
        $nonce = str_repeat('a', 32); // valid format
        $this->cache->expects($this->once())
            ->method('load')
            ->willReturn(false);
        $this->assertNull($this->helper->redeemAdminLoginNonce($nonce));
    }

    public function testRedeemAdminLoginNonceReturnsEmailAndDeletesNonce(): void
    {
        $nonce = str_repeat('b', 32);
        $email = 'admin@example.com';

        $this->cache->expects($this->once())
            ->method('load')
            ->willReturn($email);

        $this->cache->expects($this->once())
            ->method('remove');

        $result = $this->helper->redeemAdminLoginNonce($nonce);
        $this->assertSame($email, $result);
    }

    // -------------------------------------------------------------------------
    // Customer nonce
    // -------------------------------------------------------------------------

    public function testRedeemCustomerLoginNonceReturnNullForInvalidFormat(): void
    {
        $this->cache->expects($this->never())->method('load');
        $this->assertNull($this->helper->redeemCustomerLoginNonce('INVALID'));
    }

    public function testRedeemCustomerLoginNonceReturnNullOnCacheMiss(): void
    {
        $nonce = str_repeat('c', 32);
        $this->cache->method('load')->willReturn(false);
        $this->assertNull($this->helper->redeemCustomerLoginNonce($nonce));
    }

    public function testRedeemCustomerLoginNonceReturnNullOnInvalidJson(): void
    {
        $nonce = str_repeat('d', 32);
        $this->cache->method('load')->willReturn('not-json');
        $this->cache->expects($this->once())->method('remove');
        $this->assertNull($this->helper->redeemCustomerLoginNonce($nonce));
    }

    public function testRedeemCustomerLoginNonceReturnsDataAndDeletesNonce(): void
    {
        $nonce = str_repeat('e', 32);
        $payload = json_encode(['email' => 'user@example.com', 'relayState' => '/dashboard']);

        $this->cache->method('load')->willReturn($payload);
        $this->cache->expects($this->once())->method('remove');

        $result = $this->helper->redeemCustomerLoginNonce($nonce);

        $this->assertIsArray($result);
        $this->assertSame('user@example.com', $result['email']);
        $this->assertSame('/dashboard', $result['relayState']);
    }

    // -------------------------------------------------------------------------
    // State token
    // -------------------------------------------------------------------------

    public function testValidateStateTokenReturnsFalseForEmptySessionId(): void
    {
        $this->cache->expects($this->never())->method('load');
        $this->assertFalse($this->helper->validateStateToken('', str_repeat('f', 32)));
    }

    public function testValidateStateTokenReturnsFalseForInvalidFormat(): void
    {
        $this->cache->expects($this->never())->method('load');
        $this->assertFalse($this->helper->validateStateToken('session123', 'not-hex'));
    }

    public function testValidateStateTokenReturnsFalseOnCacheMiss(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->assertFalse(
            $this->helper->validateStateToken('session123', str_repeat('f', 32))
        );
    }

    public function testValidateStateTokenReturnsTrueAndDeletesToken(): void
    {
        $this->cache->method('load')->willReturn('1');
        $this->cache->expects($this->once())->method('remove');

        $this->assertTrue(
            $this->helper->validateStateToken('session123', str_repeat('a', 32))
        );
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
