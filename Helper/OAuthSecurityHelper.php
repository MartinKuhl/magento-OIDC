<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Helper;

use Magento\Framework\App\CacheInterface;
use M2Oidc\OAuth\Model\Cache\AtomicCacheInterface;

/**
 * Security helper for OIDC authentication flows.
 *
 * Provides cryptographic nonce-based admin login gates (preventing direct URL access),
 * CSRF state tokens for OAuth flows, redirect URL validation, PKCE helpers,
 * ephemeral OIDC auth tokens (C-01), and per-flow OIDC nonces (H-01).
 */
class OAuthSecurityHelper
{
    private const string NONCE_CACHE_PREFIX = 'm2oidc_nonce_';
    /**
     * Cache prefix for customer OIDC login nonces
     */
    private const string CUSTOMER_NONCE_CACHE_PREFIX = 'm2oidc_custnonce_';
    private const string STATE_CACHE_PREFIX = 'm2oidc_state_';
    private const int NONCE_TTL = 300;     // 5 minutes — covers browser round-trips to slow IdPs
    private const int STATE_TTL = 600;     // 10 minutes

    /**
     * Cache prefix for ephemeral OIDC auth tokens (C-01).
     * Key: hash('sha256', token) → value: email address.
     */
    private const string OIDC_AUTH_TOKEN_PREFIX = 'm2oidc_authtoken_';
    /** TTL for ephemeral OIDC auth tokens: long enough to survive a single login round-trip. */
    private const int OIDC_AUTH_TOKEN_TTL = 300; // 5 minutes

    /**
     * Prefix used as a fast, non-secret distinguisher for OIDC auth tokens (C-01).
     * The token itself is a cryptographically random hex string — this prefix merely
     * lets plugin code skip the cache lookup when the password is clearly not an OIDC token.
     */
    private const string OIDC_AUTH_TOKEN_MARKER = 'OIDC_';

    /** Cache prefix for per-flow OIDC id_token nonces (H-01). */
    private const string OIDC_NONCE_CACHE_PREFIX = 'm2oidc_oidcnonce_';
    /** TTL matches STATE_TTL so nonce stays available until state is consumed. */
    private const int OIDC_NONCE_TTL = 600; // 10 minutes

    /** Cache prefix for PKCE code verifiers bridging the customer OAuth redirect. */
    private const string PKCE_VERIFIER_CACHE_PREFIX = 'm2oidc_pkce_verifier_';
    /** TTL for PKCE verifier cache entries: 10 minutes, matching STATE_TTL. */
    private const int PKCE_VERIFIER_TTL = 600;

    /** @var CacheInterface Used for write (save) operations */
    private readonly CacheInterface $cache;

    /**
     * Atomic read-and-delete for one-time-use cache tokens.
     * Eliminates the TOCTOU window between load() and remove().
     *
     * @var AtomicCacheInterface
     */
    private readonly AtomicCacheInterface $atomicCache;

    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /**
     * Initialize OAuth security helper.
     *
     * @param CacheInterface       $cache
     * @param OAuthUtility         $oauthUtility
     * @param AtomicCacheInterface $atomicCache
     */
    public function __construct(
        CacheInterface $cache,
        OAuthUtility $oauthUtility,
        AtomicCacheInterface $atomicCache
    ) {
        $this->cache       = $cache;
        $this->oauthUtility = $oauthUtility;
        $this->atomicCache  = $atomicCache;
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
        $email    = $this->atomicCache->getAndDelete($cacheKey);

        if (in_array($email, [null, '', '0'], true)) {
            return null;
        }

        return $email;
    }

    /**
     * Create a one-time nonce for customer OIDC login handoff.
     *
     * Generates a secure 32-character hex nonce and stores the
     * customer email and relay state in cache for session-safe
     * redirect handling.
     *
     * FEAT-09: When $headless=true, the nonce payload includes 'headless: true' so that
     * HeadlessOidcCallback knows to issue a token instead of a session cookie.
     *
     * @param string $email      Customer email address
     * @param string $relayState Target URL for post-login redirect
     * @param bool   $headless   Headless PWA mode (FEAT-09)
     * @return string 32-character hex nonce
     */
    public function createCustomerLoginNonce(
        string $email,
        string $relayState,
        bool $headless = false
    ): string {
        $nonce = bin2hex(random_bytes(16));
        $cacheKey = self::CUSTOMER_NONCE_CACHE_PREFIX . $nonce;
        $payload = ['email' => $email, 'relayState' => $relayState];
        if ($headless) {
            $payload['headless'] = true;
        }
        $data = json_encode($payload, JSON_THROW_ON_ERROR);
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
     * @return array{email: string, relayState: string, headless: bool}|null
     *         Array with email, relayState, and headless flag on success, null on failure
     */
    public function redeemCustomerLoginNonce(string $nonce): ?array
    {
        if ($nonce === '' || $nonce === '0'
            || !preg_match('/^[a-f0-9]{32}$/', $nonce)
        ) {
            return null;
        }

        $cacheKey = self::CUSTOMER_NONCE_CACHE_PREFIX . $nonce;
        $data     = $this->atomicCache->getAndDelete($cacheKey);

        if (in_array($data, [null, '', '0'], true)) {
            return null;
        }

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
            'email'     => $decoded['email'],
            'relayState' => $decoded['relayState'],
            'headless'  => (bool) ($decoded['headless'] ?? false),
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
     * C-03: The cache read and subsequent delete are two separate operations and are
     * therefore not atomic. Risk profile by cache backend:
     *  - File cache (single server): OS-level file locks make concurrent access effectively
     *    serialised — negligible race window.
     *  - Redis (phpredis / Predis): no mutual exclusion between two concurrent PHP-FPM workers
     *    accessing the same key. Two simultaneous callbacks carrying the same state token
     *    could both pass validation before either delete executes.
     *  - Mitigation: use Redis ≥6.2 GETDEL for atomic read-and-delete (see Future Improvement #3
     *    in Docs/Code-Review.md). Until then the residual window is milliseconds and requires
     *    an attacker to replay a token that was never exposed outside the OAuth flow.
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
        $value    = $this->atomicCache->getAndDelete($cacheKey);

        return !in_array($value, [null, '', '0'], true);
    }

    /**
     * Encode relay state data as a URL-safe JSON+Base64 string for the OAuth state parameter.
     *
     * MP-02: Added optional $providerId for multi-provider support. When provided the
     * encoded payload gains a 'p' key (integer). Decoders that receive state without
     * 'p' default to 0 (let callers fall back to getFirstItem()).
     *
     * Headless mode (FEAT-09): When $headless=true the payload gains an 'h' key (int 1).
     * This signals HeadlessOidcCallback to issue a token instead of a session cookie.
     *
     * @param  string   $relayState  The original relay state URL
     * @param  string   $sessionId   The current PHP session ID
     * @param  string   $appName     The OAuth app name
     * @param  string   $loginType   Login type (admin or customer)
     * @param  string   $stateToken  CSRF state token
     * @param  int|null $providerId  Provider row ID (null = omit, backward-compat)
     * @param  bool     $headless    When true, encode headless=1 flag (FEAT-09)
     * @return string URL-safe base64-encoded JSON string
     */
    public function encodeRelayState(
        string $relayState,
        string $sessionId,
        string $appName,
        string $loginType,
        string $stateToken,
        ?int $providerId = null,
        bool $headless = false
    ): string {
        $data = [
            'r' => $relayState,
            's' => $sessionId,
            'a' => $appName,
            'l' => $loginType,
            't' => $stateToken,
        ];
        if ($providerId !== null && $providerId > 0) {
            $data['p'] = $providerId;
        }
        if ($headless) {
            $data['h'] = 1;
        }
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($encoded), '+/', '-_'), '=');
    }

    /**
     * Decode a URL-safe JSON+Base64 relay state string.
     *
     * MP-02: Returns 'providerId' (int, 0 if absent) alongside existing keys for
     * backward-compat with state tokens encoded before this sprint.
     *
     * FEAT-09: Returns 'headless' (bool, false if absent) for headless PWA flow.
     *
     * @param  string $encoded The encoded state string
     * @return array<string, mixed>|null Associative array with keys: relayState, sessionId, appName,
     *                                   loginType, stateToken, providerId, headless; or null on failure
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
            'sessionId'  => $data['s'],
            'appName'    => $data['a'],
            'loginType'  => $data['l'],
            'stateToken' => $data['t'],
            // MP-02: 0 = unknown/legacy (callers use getFirstItem() as fallback)
            'providerId' => isset($data['p']) ? (int) $data['p'] : 0,
            // FEAT-09: headless PWA flow
            'headless'   => !empty($data['h']),
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

        // Reject null bytes and backslashes before any further checks.
        // Backslashes (\) are treated as path separators by some browsers (e.g. old IE/Edge),
        // enabling "/\evil.com" to be interpreted as "//evil.com" (open redirect bypass).
        // Null bytes can bypass downstream string comparisons.
        if (str_contains($url, "\x00") || str_contains($url, '\\')) {
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

    // -------------------------------------------------------------------------
    // PKCE — RFC 7636 (FEAT-01)
    // -------------------------------------------------------------------------

    /**
     * Generate a PKCE code verifier (RFC 7636 §4.1).
     *
     * 32 random bytes → 43 base64url characters (256 bit entropy).
     * Compliant with the 43–128 character requirement using the
     * unreserved character set [A-Z a-z 0-9 - _ ~].
     *
     * @return string 43-character URL-safe base64 string
     */
    public function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Compute a PKCE S256 code challenge from a code verifier (RFC 7636 §4.2).
     *
     * Challenge = BASE64URL(SHA256(ASCII(code_verifier)))
     *
     * @param  string $verifier The code verifier generated by generateCodeVerifier()
     * @param  string $method   The PKCE code challenge method ('S256' or 'plain')
     * @return string URL-safe base64-encoded SHA-256 hash (no padding)
     */
    public function computeCodeChallenge(string $verifier, string $method = 'S256'): string
    {
        if ($method === 'plain') {
            return $verifier; // RFC 7636 §4.2: challenge = verifier
        }
        if ($method === 'S256') {
            return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        }
        throw new \InvalidArgumentException(
            sprintf('Unsupported PKCE code_challenge_method: %s. Use "S256" or "plain".', $method)
        );
    }

    /**
     * Store a PKCE code verifier in cache and return a nonce for cookie transport.
     *
     * Stores the verifier in Magento's shared cache (Redis in production) rather
     * than the PHP session. The PHP session is lost during the OAuth redirect cycle
     * on multi-server/load-balanced deployments; cache + cookie survives the round-trip.
     *
     * @param  string $verifier   The PKCE code verifier (43-char base64url string)
     * @param  int    $providerId The provider row ID (used only for logging)
     * @return string 32-character hex nonce to store in a browser cookie
     */
    public function storePkceVerifier(string $verifier, int $providerId): string
    {
        $nonce    = bin2hex(random_bytes(16));
        $cacheKey = self::PKCE_VERIFIER_CACHE_PREFIX . $nonce;
        $this->cache->save($verifier, $cacheKey, [], self::PKCE_VERIFIER_TTL);
        $this->oauthUtility->customlog(
            "OAuthSecurityHelper: PKCE verifier stored in cache for provider_id={$providerId}"
        );
        return $nonce;
    }

    /**
     * Retrieve and delete a PKCE code verifier from cache (one-time use).
     *
     * Returns null if the nonce is invalid, expired, or already consumed.
     *
     * @param  string $nonce The nonce returned by storePkceVerifier()
     * @return string|null   The code verifier, or null on failure
     */
    public function consumePkceVerifier(string $nonce): ?string
    {
        if ($nonce === '' || $nonce === '0'
            || !preg_match('/^[a-f0-9]{32}$/', $nonce)
        ) {
            return null;
        }

        $cacheKey = self::PKCE_VERIFIER_CACHE_PREFIX . $nonce;
        $verifier = $this->atomicCache->getAndDelete($cacheKey);

        return in_array($verifier, [null, '', '0'], true) ? null : $verifier;
    }

    // -------------------------------------------------------------------------
    // Ephemeral OIDC auth tokens — C-01
    // -------------------------------------------------------------------------

    /**
     * Create a single-use, time-limited OIDC auth token for the given admin email.
     *
     * C-01: Replaces the static OIDC_TOKEN_MARKER constant. The token is stored in
     * cache keyed by a SHA-256 hash of itself (to avoid leaking the raw value in the
     * cache layer). The caller passes the returned token as the "password" argument to
     * Auth::login() so that OidcCredentialAdapter can validate and consume it.
     *
     * @param  string $email The admin user's email address
     * @return string Prefixed 69-char token: 'OIDC_' + 64 hex chars
     */
    public function createOidcAuthToken(string $email): string
    {
        $raw   = bin2hex(random_bytes(32));           // 64 hex chars
        $token = self::OIDC_AUTH_TOKEN_MARKER . $raw; // 'OIDC_' + 64 chars

        $cacheKey = self::OIDC_AUTH_TOKEN_PREFIX . hash('sha256', $token);
        $this->cache->save($email, $cacheKey, [], self::OIDC_AUTH_TOKEN_TTL);

        return $token;
    }

    /**
     * Return true if $password looks like an OIDC auth token (C-01).
     *
     * This is a non-consuming check used by plugins to detect OIDC login attempts
     * without touching the cache. It validates format only — the actual email binding
     * is verified by validateAndConsumeOidcAuthToken().
     *
     * @param  string $password The password/token value to test
     */
    public function isOidcAuthToken(string $password): bool
    {
        // Must start with 'OIDC_' and be followed by exactly 64 lowercase hex chars
        return (bool) preg_match('/^OIDC_[a-f0-9]{64}$/', $password);
    }

    /**
     * Validate an OIDC auth token against the given email and consume it (one-time use).
     *
     * C-01: Verifies that the token was created for the specified email address and
     * immediately removes it from cache to prevent replay. Returns false if the token
     * is malformed, expired, or was issued for a different email.
     *
     * @param  string $email The admin user's email address
     * @param  string $token The token returned by createOidcAuthToken()
     * @return bool True when token is valid and matches email, false otherwise
     */
    public function validateAndConsumeOidcAuthToken(string $email, string $token): bool
    {
        if (!$this->isOidcAuthToken($token)) {
            return false;
        }

        $cacheKey = self::OIDC_AUTH_TOKEN_PREFIX . hash('sha256', $token);
        $stored   = $this->atomicCache->getAndDelete($cacheKey);

        return !in_array($stored, [null, '', '0'], true) && $stored === $email;
    }

    // -------------------------------------------------------------------------
    // Per-flow OIDC id_token nonces — H-01
    // -------------------------------------------------------------------------

    /**
     * Persist a nonce value keyed by its associated state token (H-01).
     *
     * Call this in SendAuthorizationRequest immediately after generating the
     * OAuth state token. The nonce is later retrieved in the callback controller
     * via consumeOidcNonce() and forwarded to JwtVerifier::verifyAndDecode().
     *
     * @param string $stateToken The OAuth CSRF state token (raw, before URL encoding)
     * @param string $nonce      The nonce sent in the authorization request
     */
    public function storeOidcNonce(string $stateToken, string $nonce): void
    {
        $cacheKey = self::OIDC_NONCE_CACHE_PREFIX . hash('sha256', $stateToken);
        $this->cache->save($nonce, $cacheKey, [], self::OIDC_NONCE_TTL);
    }

    /**
     * Retrieve and consume the OIDC nonce associated with a state token (H-01).
     *
     * Returns null if no nonce was stored (e.g. IdP does not support nonce,
     * or the state token has expired). JwtVerifier skips nonce validation when
     * the expected nonce is null.
     *
     * @param  string $stateToken The OAuth CSRF state token (raw)
     * @return string|null The stored nonce, or null if not found
     */
    public function consumeOidcNonce(string $stateToken): ?string
    {
        if ($stateToken === '' || $stateToken === '0') {
            return null;
        }

        $cacheKey = self::OIDC_NONCE_CACHE_PREFIX . hash('sha256', $stateToken);
        $nonce    = $this->atomicCache->getAndDelete($cacheKey);

        return in_array($nonce, [null, '', '0'], true) ? null : $nonce;
    }
}
