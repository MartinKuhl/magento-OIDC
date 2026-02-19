<?php

namespace MiniOrange\OAuth\Helper;

use Magento\Framework\App\CacheInterface;

/**
 * Pure PHP JWT verification using openssl_verify().
 *
 * Supports RS256, RS384, RS512 signature verification via JWKS endpoints.
 * No external composer dependencies required.
 */
class JwtVerifier
{
    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var CacheInterface */
    private readonly CacheInterface $cache;

    /** @var \Magento\Framework\HTTP\Adapter\CurlFactory */
    private readonly \Magento\Framework\HTTP\Adapter\CurlFactory $curlFactory;

    /**
     * Initialize JWT verifier.
     *
     * @param OAuthUtility                                $oauthUtility
     * @param CacheInterface                              $cache
     * @param \Magento\Framework\HTTP\Adapter\CurlFactory $curlFactory
     */
    public function __construct(
        OAuthUtility $oauthUtility,
        CacheInterface $cache,
        \Magento\Framework\HTTP\Adapter\CurlFactory $curlFactory
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->cache = $cache;
        $this->curlFactory = $curlFactory;
    }

    /**
     * Verify and decode a JWT id_token using the provider's JWKS endpoint.
     *
     * @param  string      $idToken  The raw JWT string
     * @param  string      $jwksUrl  The JWKS endpoint URL
     * @param  string|null $issuer   Expected issuer (iss claim), null to skip
     * @param  string|null $audience Expected audience (aud claim), null to skip
     * @return array|null Decoded payload array, or null on failure
     */
    public function verifyAndDecode(string $idToken, string $jwksUrl, ?string $issuer, ?string $audience): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            $this->oauthUtility->customlog("JwtVerifier: Invalid JWT format - expected 3 parts, got " . count($parts));
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode($this->base64UrlDecode($headerB64), true);
        if (!$header || !isset($header['alg'])) {
            $this->oauthUtility->customlog("JwtVerifier: Cannot decode JWT header or missing alg");
            return null;
        }

        $algMap = [
            'RS256' => OPENSSL_ALGO_SHA256,
            'RS384' => OPENSSL_ALGO_SHA384,
            'RS512' => OPENSSL_ALGO_SHA512,
        ];

        $alg = strtoupper((string) $header['alg']);
        if (!isset($algMap[$alg])) {
            $this->oauthUtility->customlog("JwtVerifier: Unsupported algorithm: " . $alg);
            return null;
        }

        // Fetch JWKS
        $jwks = $this->fetchJwks($jwksUrl);
        if ($jwks === null) {
            return null;
        }

        // Find the matching public key
        $kid = $header['kid'] ?? null;
        $pem = $this->findPublicKey($jwks, $kid, $alg);
        if ($pem === null) {
            $this->oauthUtility->customlog("JwtVerifier: No matching key found in JWKS for kid=" . ($kid ?? 'null'));
            return null;
        }

        // Verify signature
        $dataToVerify = $headerB64 . '.' . $payloadB64;
        $signature = $this->base64UrlDecode($signatureB64);

        $result = openssl_verify($dataToVerify, $signature, $pem, $algMap[$alg]);
        if ($result !== 1) {
            $this->oauthUtility->customlog("JwtVerifier: Signature verification FAILED");
            return null;
        }

        $this->oauthUtility->customlog("JwtVerifier: Signature verification PASSED");

        // Decode payload
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);
        if (!$payload) {
            $this->oauthUtility->customlog("JwtVerifier: Cannot decode JWT payload");
            return null;
        }

        // Validate expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            $this->oauthUtility->customlog("JwtVerifier: Token expired at " . date('Y-m-d H:i:s', $payload['exp']));
            return null;
        }

        // Validate issuer
        if ($issuer !== null && isset($payload['iss']) && $payload['iss'] !== $issuer) {
            $this->oauthUtility->customlog("JwtVerifier: Issuer mismatch - expected: $issuer, got: " . $payload['iss']);
            return null;
        }

        // Validate audience
        if ($audience !== null && isset($payload['aud'])) {
            $aud = $payload['aud'];
            $audMatch = is_array($aud) ? in_array($audience, $aud, true) : ($aud === $audience);
            if (!$audMatch) {
                $this->oauthUtility->customlog("JwtVerifier: Audience mismatch - expected: $audience");
                return null;
            }
        }

        return $payload;
    }

    /**
     * Decode a JWT without verifying the signature.
     *
     * Used as fallback when no JWKS endpoint is configured.
     *
     * @param  string $idToken The raw JWT string
     * @return array|null Decoded payload array, or null on failure
     */
    public function decodeWithoutVerification(string $idToken): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        return is_array($payload) ? $payload : null;
    }

    /**
     * Fetch and parse JWKS from the given URL.
     *
     * @param  string $jwksUrl
     * @return array|null The JWKS keys array, or null on failure
     */
    private function fetchJwks(string $jwksUrl): ?array
    {
        $cacheKey = 'mooauth_jwks_' . hash('sha256', $jwksUrl);
        $cached = $this->cache->load($cacheKey);
        if ($cached) {
            $cachedData = json_decode($cached, true);
            if (isset($cachedData['keys']) && is_array($cachedData['keys'])) {
                return $cachedData['keys'];
            }
        }

        $curl = $this->curlFactory->create();
        $curl->setConfig(
            [
            'header' => false,
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_RETURNTRANSFER' => true,
            ]
        );
        $curl->write('GET', $jwksUrl, '1.1', ['Accept: application/json']);
        $response = $curl->read();
        $curl->close();

        if (empty($response)) {
            $this->oauthUtility->customlog("JwtVerifier: Empty response from JWKS endpoint: " . $jwksUrl);
            return null;
        }

        $data = json_decode($response, true);
        if (!isset($data['keys']) || !is_array($data['keys'])) {
            $this->oauthUtility->customlog("JwtVerifier: Invalid JWKS format from: " . $jwksUrl);
            return null;
        }

        $this->cache->save($response, $cacheKey, [], 86400);

        return $data['keys'];
    }

    /**
     * Find the matching RSA public key from JWKS and convert to PEM.
     *
     * @param  array       $keys The JWKS keys array
     * @param  string|null $kid  Key ID from the JWT header
     * @param  string      $alg  Algorithm from the JWT header
     * @return string|null PEM-encoded public key, or null if not found
     */
    private function findPublicKey(array $keys, ?string $kid, string $alg): ?string
    {
        foreach ($keys as $key) {
            // Must be RSA key type
            if (($key['kty'] ?? '') !== 'RSA') {
                continue;
            }

            // Match by kid if provided
            if ($kid !== null && isset($key['kid']) && $key['kid'] !== $kid) {
                continue;
            }

            // Match by alg if provided in JWKS
            if (isset($key['alg']) && strtoupper((string) $key['alg']) !== $alg) {
                continue;
            }

            // Must have modulus and exponent
            if (!isset($key['n']) || !isset($key['e'])) {
                continue;
            }

            $pem = $this->jwkToPem($key['n'], $key['e']);
            if ($pem !== null) {
                return $pem;
            }
        }

        return null;
    }

    /**
     * Convert JWK RSA modulus (n) and exponent (e) to PEM format.
     *
     * Uses ASN.1 DER encoding — no external dependencies.
     *
     * @param  string $n Base64url-encoded modulus
     * @param  string $e Base64url-encoded exponent
     * @return string|null PEM-encoded public key
     */
    private function jwkToPem(string $n, string $e): ?string
    {
        $modulus = $this->base64UrlDecode($n);
        $exponent = $this->base64UrlDecode($e);

        if ($modulus === '' || $modulus === '0' || ($exponent === '' || $exponent === '0')) {
            return null;
        }

        // Ensure modulus has leading zero byte if high bit is set (ASN.1 unsigned integer)
        if (ord($modulus[0]) > 0x7f) {
            $modulus = "\x00" . $modulus;
        }

        // Build ASN.1 DER structure
        $modBytes = $this->asn1Length(strlen($modulus));
        $expBytes = $this->asn1Length(strlen($exponent));

        // INTEGER modulus
        $modSequence = "\x02" . $modBytes . $modulus;
        // INTEGER exponent
        $expSequence = "\x02" . $expBytes . $exponent;

        // SEQUENCE { modulus, exponent }
        $rsaPublicKey = $modSequence . $expSequence;
        $rsaSequence = "\x30" . $this->asn1Length(strlen($rsaPublicKey)) . $rsaPublicKey;

        // BIT STRING wrapping
        $bitString = "\x00" . $rsaSequence;
        $bitStringDer = "\x03" . $this->asn1Length(strlen($bitString)) . $bitString;

        // AlgorithmIdentifier: OID for rsaEncryption + NULL
        $algorithmIdentifier = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

        // SubjectPublicKeyInfo SEQUENCE
        $publicKeyInfo = $algorithmIdentifier . $bitStringDer;
        $publicKeyInfoDer = "\x30" . $this->asn1Length(strlen($publicKeyInfo)) . $publicKeyInfo;

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($publicKeyInfoDer), 64, "\n")
            . "-----END PUBLIC KEY-----";

        // Verify the PEM is valid
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            $this->oauthUtility->customlog("JwtVerifier: Failed to create public key from JWK");
            return null;
        }

        return $pem;
    }

    /**
     * Encode ASN.1 DER length bytes.
     *
     * @param  int $length
     */
    private function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return pack('C', $length);
        }

        $temp = ltrim(pack('N', $length), "\x00");
        return pack('C', 0x80 | strlen($temp)) . $temp;
    }

    /**
     * Base64url decode (RFC 7515).
     *
     * @param  string $input
     */
    private function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        $translated = strtr($input, '-_', '+/');
        return $this->oauthUtility->decodeBase64($translated);
    }
}
