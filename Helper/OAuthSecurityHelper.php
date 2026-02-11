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
    private const NONCE_CACHE_PREFIX = 'mooauth_nonce_';
    private const STATE_CACHE_PREFIX = 'mooauth_state_';
    private const NONCE_TTL = 120;     // 2 minutes
    private const STATE_TTL = 600;     // 10 minutes

    private CacheInterface $cache;
    private OAuthUtility $oauthUtility;

    public function __construct(
        CacheInterface $cache,
        OAuthUtility $oauthUtility
    ) {
        $this->cache = $cache;
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * Create a one-time nonce that maps to an admin email address.
     * Used to prevent direct URL-based admin login (C1 fix).
     *
     * @param string $email The admin user's email
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
     * Returns the associated email and deletes the nonce so it cannot be reused.
     *
     * @param string $nonce The nonce to redeem
     * @return string|null The email if valid, null if expired/invalid
     */
    public function redeemAdminLoginNonce(string $nonce): ?string
    {
        if (empty($nonce) || !preg_match('/^[a-f0-9]{32}$/', $nonce)) {
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
     * Create a CSRF state token for the OAuth authorization flow.
     *
     * @param string $sessionId The current PHP session ID
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
     * @param string $sessionId The session ID used when the token was created
     * @param string $stateToken The state token to validate
     * @return bool True if valid, false if expired/invalid
     */
    public function validateStateToken(string $sessionId, string $stateToken): bool
    {
        if (empty($sessionId) || empty($stateToken)) {
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
     * Validate a redirect URL to prevent open redirects.
     * Only allows relative paths or URLs on the same host as the Magento base URL.
     *
     * @param string $url The URL to validate
     * @param string $fallback The fallback URL if validation fails (default: '/')
     * @return string The validated URL or the fallback
     */
    public function validateRedirectUrl(string $url, string $fallback = '/'): string
    {
        $url = trim($url);

        if (empty($url)) {
            return $fallback;
        }

        // Allow relative paths (starting with /)
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        // For absolute URLs, validate host matches the Magento store
        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['host'])) {
            return $fallback;
        }

        // Only allow http(s) schemes
        $scheme = $parsed['scheme'] ?? '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $fallback;
        }

        // Compare host against Magento base URL host
        $baseUrl = $this->oauthUtility->getBaseUrl();
        $baseParsed = parse_url($baseUrl);
        $baseHost = $baseParsed['host'] ?? '';

        if (strcasecmp($parsed['host'], $baseHost) !== 0) {
            $this->oauthUtility->customlog(
                "OAuthSecurityHelper: Blocked redirect to external host: " . $parsed['host']
            );
            return $fallback;
        }

        return $url;
    }
}
