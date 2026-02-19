<?php

namespace MiniOrange\OAuth\Helper;

use Magento\Framework\App\CacheInterface;

/**
 * Security helper for OIDC authentication flows.
 *
 * Provides cryptographic nonce-based admin login gates (preventing direct URL access),
 * CSRF state tokens for OAuth flows, and redirect URL validation.
 */
class OAuthSecurityHelper
{
    private const string NONCE_CACHE_PREFIX = 'mooauth_nonce_';
    /**
     * Cache prefix for customer OIDC login nonces
     */
    private const string CUSTOMER_NONCE_CACHE_PREFIX = 'mooauth_custnonce_';
    private const string STATE_CACHE_PREFIX = 'mooauth_state_';
    private const int NONCE_TTL = 120;     // 2 minutes
    private const int STATE_TTL = 600;     // 10 minutes
    private readonly CacheInterface $cache;

    private readonly OAuthUtility $oauthUtility;

    /**
     * Initialize OAuth security helper.
     */
    public function __construct(
        CacheInterface $cache,
        OAuthUtility $oauthUtility
    ) {
        $this->cache = $cache;
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * Create a one-time nonce that maps to an admin email address.
     *
     * Used to prevent direct URL-based admin login (C1 fix).
     *
     * @param  string $email The admin user's email
     * @return string The generated nonce (32-char hex)
     */
    public function createAdminLoginNonce(string $email): string
    {
        $nonce = bin2hex(random_bytes(16));
        $cacheKey = self::NONCE_CACHE_PREFIX . $nonce;
        $this->cache->save($email, $cacheKey, [], self::NONCE_TTL);
        return $nonce;
    }

    /**
     * Redeem (validate and consume) an admin login nonce.
     *
     * Returns the associated email and deletes the nonce so it cannot be reused.
     *
     * @param  string $nonce The nonce to redeem
     * @return string|null The email if valid, null if expired/invalid
     */
    public function redeemAdminLoginNonce(string $nonce): ?string
    {
        if ($nonce === '' || $nonce === '0' || !preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            return null;
        }

        $cacheKey = self::NONCE_CACHE_PREFIX . $nonce;
        $email = $this->cache->load($cacheKey);

        if ($email === false || empty($email)) {
            return null;
        }

        // One-time use: delete immediately
        $this->cache->remove($cacheKey);
        return $email;
    }

    /**
     * Create a one-time nonce for customer OIDC login handoff.
     *
     * Generates a secure 32-character hex nonce and stores the
     * customer email and relay state in cache for session-safe
     * redirect handling.
     *
     * @param string $email Customer email address
     * @param string $relayState Target URL for post-login redirect
     * @return string 32-character hex nonce
     */
    public function createCustomerLoginNonce(
        string $email,
        string $relayState
    ): string {
        $nonce = bin2hex(random_bytes(16));
        $cacheKey = self::CUSTOMER_NONCE_CACHE_PREFIX . $nonce;
        $data = json_encode(
            ['email' => $email, 'relayState' => $relayState],
            JSON_THROW_ON_ERROR
        );
        $this->cache->save($data, $cacheKey, [], self::NONCE_TTL);
        return $nonce;
    }

    /**
     * Redeem (validate and consume) a customer login nonce.
     *
     * Validates the nonce format, retrieves the stored data from
     * cache, and immediately deletes it (one-time use). Returns
     * null if the nonce is invalid, expired, or already used.
     *
     * @param string $nonce The nonce to redeem
     * @return array{email: string, relayState: string}|null
     *         Array with email and relayState on success, null on
     *         failure
     */
    public function redeemCustomerLoginNonce(string $nonce): ?array
    {
        if ($nonce === '' || $nonce === '0'
            || !preg_match('/^[a-f0-9]{32}$/', $nonce)
        ) {
            return null;
        }

        $cacheKey = self::CUSTOMER_NONCE_CACHE_PREFIX . $nonce;
        $data = $this->cache->load($cacheKey);

        if ($data === false || empty($data)) {
            return null;
        }

        // One-time use: delete immediately
        $this->cache->remove($cacheKey);

        try {
            $decoded = json_decode(
                $data,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            return null;
        }

        if (!is_array($decoded)
            || empty($decoded['email'])
            || !isset($decoded['relayState'])
            || !is_string($decoded['email'])
            || !is_string($decoded['relayState'])
        ) {
            return null;
        }

        return [
            'email' => $decoded['email'],
            'relayState' => $decoded['relayState']
        ];
    }

    /**
     * Create a CSRF state token for the OAuth authorization flow.
     *
     * @param  string $sessionId The current PHP session ID
     * @return string The generated state token (32-char hex)
     */
    public function createStateToken(string $sessionId): string
    {
        $token = bin2hex(random_bytes(16));
        $cacheKey = self::STATE_CACHE_PREFIX . hash('sha256', $sessionId . $token);
        $this->cache->save('1', $cacheKey, [], self::STATE_TTL);
        return $token;
    }

    /**
     * Validate and consume a CSRF state token.
     *
     * @param  string $sessionId  The session ID used when the token was created
     * @param  string $stateToken The state token to validate
     * @return bool True if valid, false if expired/invalid
     */
    public function validateStateToken(string $sessionId, string $stateToken): bool
    {
        if ($sessionId === '' || $sessionId === '0' || ($stateToken === '' || $stateToken === '0')) {
            return false;
        }

        if (!preg_match('/^[a-f0-9]{32}$/', $stateToken)) {
            return false;
        }

        $cacheKey = self::STATE_CACHE_PREFIX . hash('sha256', $sessionId . $stateToken);
        $value = $this->cache->load($cacheKey);

        if ($value === false) {
            return false;
        }

        // One-time use: delete immediately
        $this->cache->remove($cacheKey);
        return true;
    }

    /**
     * Encode relay state data as a URL-safe JSON+Base64 string for the OAuth state parameter.
     *
     * @param  string $relayState The original relay state URL
     * @param  string $sessionId  The current PHP session ID
     * @param  string $appName    The OAuth app name
     * @param  string $loginType  Login type (admin or customer)
     * @param  string $stateToken CSRF state token
     * @return string URL-safe base64-encoded JSON string
     */
    public function encodeRelayState(
        string $relayState,
        string $sessionId,
        string $appName,
        string $loginType,
        string $stateToken
    ): string {
        $data = [
            'r' => $relayState,
            's' => $sessionId,
            'a' => $appName,
            'l' => $loginType,
            't' => $stateToken,
        ];
        return rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
    }

    /**
     * Decode a URL-safe JSON+Base64 relay state string.
     *
     * @param  string $encoded The encoded state string
     * @return array|null Associative array with keys: relayState, sessionId, appName,
     *                    loginType, stateToken; or null on failure
     */
    public function decodeRelayState(string $encoded): ?array
    {
        $translated = strtr($encoded, '-_', '+/');
        $decoded = $this->oauthUtility->decodeBase64($translated);
        if ($decoded === '') {
            return null;
        }
        $data = json_decode($decoded, true);
        if (!is_array($data) || !isset($data['r'], $data['s'], $data['a'], $data['l'], $data['t'])) {
            return null;
        }
        return [
            'relayState' => $data['r'],
            'sessionId' => $data['s'],
            'appName' => $data['a'],
            'loginType' => $data['l'],
            'stateToken' => $data['t'],
        ];
    }

    /**
     * Validate a redirect URL to prevent open redirects.
     *
     * Only allows relative paths or URLs on the same host as the Magento base URL.
     *
     * @param  string $url      The URL to validate
     * @param  string $fallback The fallback URL if validation fails (default: '/')
     * @return string The validated URL or the fallback
     */
    public function validateRedirectUrl(string $url, string $fallback = '/'): string
    {
        $url = trim($url);

        if ($url === '' || $url === '0') {
            return $fallback;
        }

        // Allow relative paths (starting with /)
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        // For absolute URLs, validate host matches the Magento store
        $parsed = $this->oauthUtility->parseUrlComponents($url);
        if (empty($parsed['host'])) {
            return $fallback;
        }

        // Only allow http(s) schemes
        $scheme = $parsed['scheme'] ?? '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $fallback;
        }

        // Compare host against Magento base URL host
        $baseUrl = $this->oauthUtility->getBaseUrl();
        $baseParsed = $this->oauthUtility->parseUrlComponents($baseUrl);
        $baseHost = $baseParsed['host'] ?? '';

        if (strcasecmp((string) $parsed['host'], (string) $baseHost) !== 0) {
            $this->oauthUtility->customlog(
                "OAuthSecurityHelper: Blocked redirect to external host: " . $parsed['host']
            );
            return $fallback;
        }

        return $url;
    }
}
