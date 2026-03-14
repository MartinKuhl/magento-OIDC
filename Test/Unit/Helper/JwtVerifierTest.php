<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Helper;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Adapter\Curl as CurlAdapter;
use Magento\Framework\HTTP\Adapter\CurlFactory;
use M2Oidc\OAuth\Helper\JwtVerifier;
use M2Oidc\OAuth\Helper\OAuthUtility;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for JwtVerifier JWT signature validation (Phase 1.1).
 *
 * Uses real RSA-2048 key pairs generated in setUpBeforeClass() so that
 * openssl_verify() is exercised end-to-end without faking cryptographic results.
 *
 * @covers \M2Oidc\OAuth\Helper\JwtVerifier
 */
class JwtVerifierTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Class-level RSA key fixtures (generated once per test run)
    // -------------------------------------------------------------------------

    /** @var \OpenSSLAsymmetricKey RSA private key for signing */
    private static mixed $privateKey;

    /** @var string Base64url-encoded RSA modulus (n) */
    private static string $jwkN;

    /** @var string Base64url-encoded RSA public exponent (e) */
    private static string $jwkE;

    /** @var string Key ID used in tests */
    private static string $kid = 'test-key-1';

    public static function setUpBeforeClass(): void
    {
        // Generate a 2048-bit RSA key pair for signing/verifying
        $keyResource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($keyResource === false) {
            self::fail('openssl_pkey_new() failed — OpenSSL not available in this environment');
        }

        self::$privateKey = $keyResource;

        $keyDetails = openssl_pkey_get_details($keyResource);
        if ($keyDetails === false) {
            self::fail('openssl_pkey_get_details() failed');
        }

        // Extract n and e from the RSA key details
        $rsa = $keyDetails['rsa'];
        self::$jwkN = self::base64UrlEncode($rsa['n']);
        self::$jwkE = self::base64UrlEncode($rsa['e']);
    }

    // -------------------------------------------------------------------------
    // Instance-level mocks
    // -------------------------------------------------------------------------

    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var CacheInterface&MockObject */
    private CacheInterface $cache;

    /** @var CurlFactory&MockObject */
    private CurlFactory $curlFactory;

    /** @var JwtVerifier */
    private JwtVerifier $verifier;

    protected function setUp(): void
    {
        $this->oauthUtility = $this->createMock(OAuthUtility::class);
        $this->cache        = $this->createMock(CacheInterface::class);
        $this->curlFactory  = $this->createMock(CurlFactory::class);

        $this->oauthUtility->method('customlog');
        // Delegate base64 decoding to the real PHP function
        $this->oauthUtility->method('decodeBase64')->willReturnCallback(
            fn(string $data) => base64_decode($data)
        );

        $this->verifier = new JwtVerifier(
            $this->oauthUtility,
            $this->cache,
            $this->curlFactory
        );
    }

    // -------------------------------------------------------------------------
    // Static helpers
    // -------------------------------------------------------------------------

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Build a minimal JWKS JSON string containing the test public key.
     *
     * @param string $kid Key ID to embed in the JWK entry
     */
    private static function buildJwks(string $kid = ''): string
    {
        $key = [
            'kty' => 'RSA',
            'n'   => self::$jwkN,
            'e'   => self::$jwkE,
        ];
        if ($kid !== '') {
            $key['kid'] = $kid;
        }
        return json_encode(['keys' => [$key]]);
    }

    /**
     * Build and sign a JWT with the test private key.
     *
     * @param array       $payload   Claims to encode
     * @param string|null $kid       Key ID for the header (null = omit)
     * @param string      $alg       JWT algorithm header value
     * @param mixed       $privateKey Override key (null = use self::$privateKey)
     */
    private function buildJwt(
        array $payload,
        ?string $kid = 'test-key-1',
        string $alg = 'RS256',
        mixed $privateKey = null
    ): string {
        $header = ['alg' => $alg, 'typ' => 'JWT'];
        if ($kid !== null) {
            $header['kid'] = $kid;
        }

        $headerB64  = self::base64UrlEncode(json_encode($header));
        $payloadB64 = self::base64UrlEncode(json_encode($payload));
        $signingInput = $headerB64 . '.' . $payloadB64;

        $key = $privateKey ?? self::$privateKey;
        openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);

        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    /**
     * Configure the cache mock to return the given JWKS on all load() calls.
     */
    private function cacheAlwaysHit(string $jwksJson): void
    {
        $this->cache->method('load')->willReturn($jwksJson);
    }

    // -------------------------------------------------------------------------
    // Valid JWT
    // -------------------------------------------------------------------------

    public function testValidRs256JwtReturnsPayload(): void
    {
        $payload = [
            'sub' => 'user-1',
            'iss' => 'https://idp.example.com',
            'aud' => 'my-client',
            'exp' => time() + 3600,
        ];

        $token = $this->buildJwt($payload);
        $this->cacheAlwaysHit(self::buildJwks(self::$kid));

        $result = $this->verifier->verifyAndDecode(
            $token,
            'https://idp.example.com/.well-known/jwks.json',
            'https://idp.example.com',
            'my-client'
        );

        $this->assertIsArray($result);
        $this->assertSame('user-1', $result['sub']);
        $this->assertSame('https://idp.example.com', $result['iss']);
    }

    public function testValidJwtWithNullIssuerAndAudienceSkipsThoseChecks(): void
    {
        $payload = [
            'sub' => 'user-2',
            'exp' => time() + 3600,
            // No iss or aud
        ];

        $token = $this->buildJwt($payload);
        $this->cacheAlwaysHit(self::buildJwks(self::$kid));

        $result = $this->verifier->verifyAndDecode(
            $token,
            'https://idp.example.com/.well-known/jwks.json',
            null,    // skip issuer check
            null     // skip audience check
        );

        $this->assertIsArray($result);
        $this->assertSame('user-2', $result['sub']);
    }

    // -------------------------------------------------------------------------
    // Expiration and not-before
    // -------------------------------------------------------------------------

    public function testExpiredJwtReturnsNull(): void
    {
        $payload = [
            'sub' => 'old-user',
            'exp' => time() - 60,   // already expired
        ];

        $token = $this->buildJwt($payload);
        $this->cacheAlwaysHit(self::buildJwks(self::$kid));

        $result = $this->verifier->verifyAndDecode(
            $token,
            'https://idp.example.com/.well-known/jwks.json',
            null,
            null
        );

        $this->assertNull($result, 'Expired JWT should return null');
    }

    public function testNotYetValidJwtReturnsNull(): void
    {
        $payload = [
            'sub' => 'future-user',
            'nbf' => time() + 3600,  // not valid for another hour
            'exp' => time() + 7200,
        ];

        $token = $this->buildJwt($payload);
        $this->cacheAlwaysHit(self::buildJwks(self::$kid));

        $result = $this->verifier->verifyAndDecode(
            $token,
            'https://idp.example.com/.well-known/jwks.json',
            null,
            null
        );

        $this->assertNull($result, 'Token with future nbf should return null');
    }

    // -------------------------------------------------------------------------
    // Issuer and audience validation
    // -------------------------------------------------------------------------

    public function testWrongIssuerReturnsNull(): void
    {
        $payload = [
            'sub' => 'u',
            'iss' => 'https://wrong-idp.example.com',
            'exp' => time() + 3600,
        ];

        $token = $this->buildJwt($payload);
        $this->cacheAlwaysHit(self::buildJwks(self::$kid));

        $result = $this->verifier->verifyAndDecode(
            $token,
            'https://idp.example.com/.well-known/jwks.json',
            'https://idp.example.com',  // expected issuer
            null
        );

        $this->assertNull($result, 'Issuer mismatch should return null');
    }

    public function testMissingIssuerWhenExpectedReturnsNull(): void
    {
        $payload = ['sub' => 'u', 'exp' => time() + 3600]; // no iss claim

        $token = $this->buildJwt($payload);
        $this->cacheAlwaysHit(self::buildJwks(self::$kid));

        $result = $this->verifier->verifyAndDecode(
            $token,
            'https://idp.example.com/.well-known/jwks.json',
            'https://idp.example.com',
            null
        );

        $this->assertNull($result, 'Missing iss when expected should return null');
    }

    public function testWrongAudienceReturnsNull(): void
    {
        $payload = [
            'sub' => 'u',
            'aud' => 'other-client',
            'exp' => time() + 3600,
        ];

        $token = $this->buildJwt($payload);
        $this->cacheAlwaysHit(self::buildJwks(self::$kid));

        $result = $this->verifier->verifyAndDecode(
            $token,
            'https://idp.example.com/.well-known/jwks.json',
            null,
            'my-client'
        );

        $this->assertNull($result, 'Audience mismatch should return null');
    }

    public function testAudienceAsArrayIsAccepted(): void
    {
        $payload = [
            'sub' => 'u',
            'aud' => ['other-client', 'my-client'],  // multi-audience
            'exp' => time() + 3600,
        ];

        $token = $this->buildJwt($payload);
        $this->cacheAlwaysHit(self::buildJwks(self::$kid));

        $result = $this->verifier->verifyAndDecode(
            $token,
            'https://idp.example.com/.well-known/jwks.json',
            null,
            'my-client'
        );

        $this->assertIsArray($result, 'Client present in multi-audience array should be accepted');
    }

    // -------------------------------------------------------------------------
    // Signature verification
    // -------------------------------------------------------------------------

    public function testInvalidSignatureReturnsNull(): void
    {
        // Generate a DIFFERENT key pair for signing
        $wrongKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($wrongKey, 'Could not generate second RSA key');

        $payload = ['sub' => 'u', 'exp' => time() + 3600];
        $token = $this->buildJwt($payload, self::$kid, 'RS256', $wrongKey);

        // JWKS contains the ORIGINAL test key — signature will fail
        // Both cache hit and re-fetch return same JWKS (no rotation scenario)
        $this->cache->method('load')->willReturn(self::buildJwks(self::$kid));
        $this->cache->method('remove');

        // curlFactory is called for the re-fetch after signature failure
        $curl = $this->createMock(CurlAdapter::class);
        $curl->method('setConfig');
        $curl->method('write');
        $curl->method('read')->willReturn(self::buildJwks(self::$kid));
        $curl->method('close');
        $this->curlFactory->method('create')->willReturn($curl);

        $result = $this->verifier->verifyAndDecode(
            $token,
            'https://idp.example.com/.well-known/jwks.json',
            null,
            null
        );

        $this->assertNull($result, 'JWT signed with wrong key should return null');
    }

    // -------------------------------------------------------------------------
    // Missing kid falls back to first key
    // -------------------------------------------------------------------------

    public function testMissingKidFallsBackToFirstJwksKey(): void
    {
        // JWT has no kid in header
        $payload = ['sub' => 'u', 'exp' => time() + 3600];
        $token = $this->buildJwt($payload, null);  // null = no kid

        // JWKS has one key with a kid — should still match since kid is absent in JWT
        $this->cacheAlwaysHit(self::buildJwks('any-kid'));

        $result = $this->verifier->verifyAndDecode(
            $token,
            'https://idp.example.com/.well-known/jwks.json',
            null,
            null
        );

        $this->assertIsArray($result, 'JWT without kid should match first JWKS key');
    }

    // -------------------------------------------------------------------------
    // Key rotation retry
    // -------------------------------------------------------------------------

    public function testKeyRotationTriggersJwksCacheInvalidationAndRetry(): void
    {
        // This test verifies that when the cached JWKS produces a failed signature,
        // the verifier clears the cache and re-fetches. The re-fetch returns the
        // correct key, and verification succeeds.

        $payload = ['sub' => 'rotated-user', 'exp' => time() + 3600];
        $token = $this->buildJwt($payload, self::$kid);

        // Build a JWKS with a DIFFERENT (wrong) key for the initial cache hit
        $wrongKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($wrongKey);
        $wrongDetails  = openssl_pkey_get_details($wrongKey);
        $wrongJwks = json_encode(['keys' => [[
            'kty' => 'RSA',
            'kid' => self::$kid,
            'n'   => self::base64UrlEncode($wrongDetails['rsa']['n']),
            'e'   => self::base64UrlEncode($wrongDetails['rsa']['e']),
        ]]]);

        // cache->load: first call (initial lookup) = wrong key, second call (after clear) = correct key
        $this->cache->expects($this->exactly(2))
            ->method('load')
            ->willReturnOnConsecutiveCalls($wrongJwks, self::buildJwks(self::$kid));

        // cache->remove must be called once to invalidate the stale entry
        $this->cache->expects($this->once())->method('remove');

        // The re-fetch hits the curl factory (cache miss on second load triggers HTTP fetch)
        // Actually: after remove(), load() is called again which returns the correct JWKS
        // so fetchJwks() returns from cache on second load. No curl needed.

        $result = $this->verifier->verifyAndDecode(
            $token,
            'https://idp.example.com/.well-known/jwks.json',
            null,
            null
        );

        $this->assertIsArray($result, 'Verification should succeed after JWKS cache refresh');
        $this->assertSame('rotated-user', $result['sub']);
    }

    // -------------------------------------------------------------------------
    // jwkToPem conversion
    // -------------------------------------------------------------------------

    public function testJwkToPemProducesValidPublicKey(): void
    {
        // Invoke jwkToPem indirectly by doing a full verify round-trip
        $payload = ['sub' => 'pem-test', 'exp' => time() + 3600];
        $token = $this->buildJwt($payload);

        $jwks = self::buildJwks(self::$kid);
        $this->cacheAlwaysHit($jwks);

        $result = $this->verifier->verifyAndDecode($token, 'https://idp.example.com/jwks', null, null);

        // The fact that verification succeeds proves jwkToPem produced a valid PEM
        $this->assertIsArray($result);
        $this->assertSame('pem-test', $result['sub']);
    }

    // -------------------------------------------------------------------------
    // Malformed JWT
    // -------------------------------------------------------------------------

    public function testMalformedJwtWithWrongPartCountReturnsNull(): void
    {
        $result = $this->verifier->verifyAndDecode(
            'only.two-parts',
            'https://idp.example.com/jwks',
            null,
            null
        );

        $this->assertNull($result, 'JWT with wrong number of parts should return null');
    }

    public function testUnsupportedAlgorithmReturnsNull(): void
    {
        // Build a JWT with HS256 algorithm in header (not supported)
        $header  = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::base64UrlEncode(json_encode(['sub' => 'u']));
        $sig     = self::base64UrlEncode('fakesig');
        $token   = "$header.$payload.$sig";

        $result = $this->verifier->verifyAndDecode(
            $token,
            'https://idp.example.com/jwks',
            null,
            null
        );

        $this->assertNull($result, 'Unsupported algorithm should return null');
    }

    // -------------------------------------------------------------------------
    // decodeWithoutVerification
    // -------------------------------------------------------------------------

    public function testDecodeWithoutVerificationReturnsPayloadWithoutCheckingSignature(): void
    {
        $payload = ['sub' => 'no-verify', 'exp' => time() - 3600]; // even expired

        $headerB64  = self::base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payloadB64 = self::base64UrlEncode(json_encode($payload));
        $token = $headerB64 . '.' . $payloadB64 . '.fake-sig';

        $result = $this->verifier->decodeWithoutVerification($token);

        $this->assertIsArray($result);
        $this->assertSame('no-verify', $result['sub']);
    }
}
