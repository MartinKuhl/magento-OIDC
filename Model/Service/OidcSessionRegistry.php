<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Service;

use Magento\Framework\App\CacheInterface;

/**
 * Registry mapping OIDC subject + session IDs to Magento PHP session IDs (FEAT-02).
 *
 * Used by the back-channel logout endpoint to find and destroy the Magento
 * session belonging to a user who has been logged out at the IdP level.
 *
 * Storage is Magento's cache layer (default: file / Redis).
 * TTL defaults to 86400 seconds (24 h) — matching a typical IdP token lifetime.
 * Each key (derived from sub + sid) maps to a JSON array of session entries,
 * supporting multiple concurrent sessions per OIDC identity (e.g. a user
 * logged in as both admin and customer simultaneously).
 *
 * API:
 *   register(sub, sid, phpSessionId, userType, userId, ttl) — call at login time
 *   resolve(sub, sid)                                        — call in back-channel handler
 *   revoke(sub, sid)                                         — call after sessions are destroyed
 */
class OidcSessionRegistry
{
    /** Cache tag used to flush all OIDC session registry entries at once. */
    public const CACHE_TAG = 'm2oidc_oidc_session';

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
     * Appends to the existing list for this sub/sid key so multiple concurrent
     * sessions (e.g. admin + customer with the same OIDC identity) are all
     * tracked. A stale entry with the same PHP session ID is replaced first to
     * handle re-login without accumulating duplicate entries.
     *
     * @param string $sub          OIDC subject identifier (`sub` claim)
     * @param string $sid          OIDC session ID (`sid` claim); may be empty
     * @param string $phpSessionId PHP session ID to be destroyed on back-channel logout
     * @param string $userType     Magento user type: 'customer' or 'admin'
     * @param int    $userId       Magento customer entity_id or admin user_id
     * @param int    $ttl          Cache TTL in seconds (default: 86400)
     */
    public function register(
        string $sub,
        string $sid,
        string $phpSessionId,
        string $userType,
        int    $userId,
        int    $ttl = self::DEFAULT_TTL
    ): void {
        if ($sub === '' || $phpSessionId === '') {
            return;
        }
        $key      = $this->buildKey($sub, $sid);
        $existing = $this->loadEntries($key);
        // Replace any stale entry with the same PHP session ID (re-login case)
        $existing = array_values(
            array_filter($existing, static fn(array $e): bool => $e['php_session_id'] !== $phpSessionId)
        );
        $existing[] = [
            'php_session_id' => $phpSessionId,
            'user_type'      => $userType,
            'user_id'        => $userId,
            'sub'            => $sub,
            'sid'            => $sid,
        ];
        $this->cache->save((string) json_encode($existing), $key, [self::CACHE_TAG], $ttl);
    }

    /**
     * Resolve a (sub, sid) pair to its list of registered session entries.
     *
     * Returns null when no mapping exists or the list is empty.
     * Each entry is an array with keys: php_session_id, user_type, user_id, sub, sid.
     *
     * @param  string $sub OIDC subject identifier
     * @param  string $sid OIDC session ID (may be empty for sub-only lookup)
     * @return array<int, array<string, mixed>>|null List of session entry arrays, or null if not found
     */
    public function resolve(string $sub, string $sid = ''): ?array
    {
        $key     = $this->buildKey($sub, $sid);
        $entries = $this->loadEntries($key);
        return $entries !== [] ? $entries : null;
    }

    /**
     * Remove the cached session mapping after the sessions have been destroyed.
     *
     * @param string $sub OIDC subject identifier
     * @param string $sid OIDC session ID (may be empty)
     */
    public function revoke(string $sub, string $sid = ''): void
    {
        $this->cache->remove($this->buildKey($sub, $sid));
    }

    /**
     * Load and normalise session entries from cache.
     *
     * Handles both the new list format (JSON array) and the legacy single-entry
     * format (JSON object with 'php_session_id' key) so stale cache entries
     * written by older versions of this class do not cause errors.
     *
     * @param  string $key Cache key
     * @return array<int, array<string, mixed>> List of session entry arrays (may be empty)
     */
    private function loadEntries(string $key): array
    {
        $raw = $this->cache->load($key);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        // Legacy format: single object {"php_session_id": "...", ...}
        if (isset($decoded['php_session_id'])) {
            return [$decoded];
        }
        // New format: list of entry objects
        return array_values(
            array_filter($decoded, static fn($e): bool => is_array($e) && isset($e['php_session_id']))
        );
    }

    /**
     * Build a stable, unique cache key for a (sub, sid) pair.
     *
     * @param  string $sub
     * @param  string $sid
     * @return string Cache key, e.g. "oidc_sess_<64-char hash>"
     */
    private function buildKey(string $sub, string $sid): string
    {
        return self::KEY_PREFIX . hash('sha256', $sub . '|' . $sid);
    }
}
