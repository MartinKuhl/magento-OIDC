<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Test\Unit\Helper;

use Magento\Framework\App\CacheInterface;
use MiniOrange\OAuth\Helper\OAuthSecurityHelper;
use MiniOrange\OAuth\Helper\OAuthUtility;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OAuthSecurityHelper — multi-provider state extension (TEST-05 / MP-02).
 *
 * Verifies that:
 *  - encodeRelayState() embeds providerId under key 'p' when provided
 *  - encodeRelayState() omits 'p' when providerId is null or 0
 *  - decodeRelayState() returns providerId from 'p' key
 *  - decodeRelayState() returns providerId=0 for legacy state (no 'p' key)
 *  - Round-trip (encode → decode) preserves all fields including providerId
 *  - Backward-compat: state encoded without provider_id still decodes cleanly
 *
 * @covers \MiniOrange\OAuth\Helper\OAuthSecurityHelper
 */
class OAuthSecurityHelperMultiProviderTest extends TestCase
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

        // decodeBase64 used by decodeRelayState — delegate to real base64_decode
        $this->oauthUtility
            ->method('decodeBase64')
            ->willReturnCallback(static fn (string $s): string => (string) base64_decode($s, true));

        $this->helper = new OAuthSecurityHelper($this->cache, $this->oauthUtility);
    }

    // -------------------------------------------------------------------------
    // encodeRelayState with providerId
    // -------------------------------------------------------------------------

    public function testEncodeIncludesProviderIdWhenPositive(): void
    {
        $encoded = $this->helper->encodeRelayState(
            '/account/login',
            'sess123',
            'myapp',
            'customer',
            'tok456',
            7
        );

        // Decode manually to inspect payload
        $json = base64_decode(strtr($encoded, '-_', '+/') . '===');
        $data = json_decode($json, true);

        $this->assertArrayHasKey('p', $data, 'Encoded state must contain "p" key for providerId');
        $this->assertSame(7, $data['p']);
    }

    public function testEncodeOmitsProviderIdWhenNull(): void
    {
        $encoded = $this->helper->encodeRelayState(
            '/account/login',
            'sess123',
            'myapp',
            'customer',
            'tok456',
            null
        );

        $json = base64_decode(strtr($encoded, '-_', '+/') . '===');
        $data = json_decode($json, true);

        $this->assertArrayNotHasKey('p', $data, 'State must NOT contain "p" key when providerId is null');
    }

    public function testEncodeOmitsProviderIdWhenZero(): void
    {
        $encoded = $this->helper->encodeRelayState(
            '/account/login',
            'sess123',
            'myapp',
            'customer',
            'tok456',
            0
        );

        $json = base64_decode(strtr($encoded, '-_', '+/') . '===');
        $data = json_decode($json, true);

        $this->assertArrayNotHasKey('p', $data, 'State must NOT contain "p" key when providerId is 0');
    }

    // -------------------------------------------------------------------------
    // decodeRelayState with providerId
    // -------------------------------------------------------------------------

    public function testDecodeReturnsProviderIdFromEncodedState(): void
    {
        $encoded = $this->helper->encodeRelayState(
            '/checkout',
            'sessABC',
            'testapp',
            'admin',
            'csrf999',
            42
        );

        $decoded = $this->helper->decodeRelayState($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame(42, $decoded['providerId']);
    }

    public function testDecodeReturnsZeroProviderIdForLegacyState(): void
    {
        // Build a legacy state WITHOUT the 'p' key
        $legacyData = [
            'r' => '/account',
            's' => 'sessXYZ',
            'a' => 'legacyapp',
            'l' => 'customer',
            't' => 'tokenABC',
            // no 'p'
        ];
        $encoded = rtrim(strtr(base64_encode(json_encode($legacyData)), '+/', '-_'), '=');

        $decoded = $this->helper->decodeRelayState($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame(0, $decoded['providerId'], 'Legacy state without "p" must decode to providerId=0');
    }

    // -------------------------------------------------------------------------
    // Full round-trip
    // -------------------------------------------------------------------------

    public function testRoundTripPreservesAllFields(): void
    {
        $relayState  = 'https://store.example.com/account/dashboard';
        $sessionId   = 'sess-round-trip-001';
        $appName     = 'my-oidc-provider';
        $loginType   = 'customer';
        $stateToken  = 'aabbccdd00112233aabbccdd00112233';
        $providerId  = 3;

        $encoded = $this->helper->encodeRelayState(
            $relayState,
            $sessionId,
            $appName,
            $loginType,
            $stateToken,
            $providerId
        );

        $decoded = $this->helper->decodeRelayState($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame($relayState, $decoded['relayState']);
        $this->assertSame($sessionId, $decoded['sessionId']);
        $this->assertSame($appName, $decoded['appName']);
        $this->assertSame($loginType, $decoded['loginType']);
        $this->assertSame($stateToken, $decoded['stateToken']);
        $this->assertSame($providerId, $decoded['providerId']);
    }

    public function testRoundTripWithoutProviderIdDefaultsToZero(): void
    {
        $encoded = $this->helper->encodeRelayState(
            '/dashboard',
            'sess-no-pid',
            'app',
            'customer',
            'tok'
        );

        $decoded = $this->helper->decodeRelayState($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame(0, $decoded['providerId']);
    }

    // -------------------------------------------------------------------------
    // Negative / invalid inputs
    // -------------------------------------------------------------------------

    public function testDecodeReturnsNullForTamperedState(): void
    {
        $this->assertNull($this->helper->decodeRelayState('not-valid-base64!!!'));
    }

    public function testDecodeReturnsNullForEmptyString(): void
    {
        $this->assertNull($this->helper->decodeRelayState(''));
    }
}
