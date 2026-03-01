<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Model\Service;

use Magento\Framework\App\CacheInterface;

/**
 * Registry mapping OIDC subject + session IDs to Magento PHP session IDs (FEAT-02).
 *
 * Used by the back-channel logout endpoint to find and destroy the Magento
 * session belonging to a user who has been logged out at the IdP level.
 *
 * Storage is Magento's cache layer (default: file / Redis).
 * TTL defaults to 86400 seconds (24 h) — matching a typical IdP token lifetime.
 * Each entry is stored under a key derived from the OIDC `sub` and optional `sid`.
 *
 * API:
 *   register(sub, sid, phpSessionId, ttl)  — call at login time
 *   resolve(sub, sid)                       — call in back-channel handler
 *   revoke(sub, sid)                        — call after the session is destroyed
 */
class OidcSessionRegistry
{
    /** Cache tag used to flush all OIDC session registry entries at once. */
    public const CACHE_TAG = 'miniorange_oidc_session';

    /** Default TTL: 24 h, matching a typical access token lifetime. */
    private const DEFAULT_TTL = 86400;

    /** Prefix for cache keys to avoid collisions. */
    private const KEY_PREFIX = 'oidc_sess_';

    /** @var CacheInterface */
    private readonly CacheInterface $cache;

    /**
     * Initialize session registry.
     *
     * @param CacheInterface $cache Magento cache frontend
     */
    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Register an OIDC session mapping at login time.
     *
     * Call this immediately after the Magento customer session is established
     * so that a subsequent back-channel logout request can locate the session.
     *
     * @param string $sub          OIDC subject identifier (`sub` claim)
     * @param string $sid          OIDC session ID (`sid` claim); may be empty
     * @param string $phpSessionId PHP session ID to be destroyed on back-channel logout
     * @param int    $ttl          Cache TTL in seconds (default: 86400)
     */
    public function register(string $sub, string $sid, string $phpSessionId, int $ttl = self::DEFAULT_TTL): void
    {
        if ($sub === '' || $phpSessionId === '') {
            return;
        }
        $key   = $this->buildKey($sub, $sid);
        $value = json_encode(['php_session_id' => $phpSessionId, 'sub' => $sub, 'sid' => $sid]);
        $this->cache->save((string) $value, $key, [self::CACHE_TAG], $ttl);
    }

    /**
     * Resolve a (sub, sid) pair to its Magento PHP session ID.
     *
     * Returns null when the mapping does not exist or has expired.
     *
     * @param  string $sub OIDC subject identifier
     * @param  string $sid OIDC session ID (may be empty for sub-only lookup)
     * @return string|null PHP session ID or null if not found
     */
    public function resolve(string $sub, string $sid = ''): ?string
    {
        $key  = $this->buildKey($sub, $sid);
        $data = $this->cache->load($key);
        // @phpstan-ignore booleanOr.alwaysFalse, identical.alwaysFalse, identical.alwaysFalse
        if ($data === false || $data === null) {
            return null;
        }
        $payload = json_decode((string) $data, true);
        return is_array($payload) ? ($payload['php_session_id'] ?? null) : null;
    }

    /**
     * Remove the cached session mapping after the session has been destroyed.
     *
     * @param string $sub OIDC subject identifier
     * @param string $sid OIDC session ID (may be empty)
     */
    public function revoke(string $sub, string $sid = ''): void
    {
        $this->cache->remove($this->buildKey($sub, $sid));
    }

    /**
     * Build a stable, unique cache key for a (sub, sid) pair.
     *
     * SHA-1 is used for compactness — no cryptographic strength is required here.
     *
     * @param  string $sub
     * @param  string $sid
     * @return string Cache key, e.g. "oidc_sess_<40-char hash>"
     */
    private function buildKey(string $sub, string $sid): string
    {
        return self::KEY_PREFIX . sha1($sub . '|' . $sid);
    }
}
