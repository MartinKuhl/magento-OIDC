<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Cache;

/**
 * Provides a single atomic read-and-delete operation for cache entries.
 *
 * The standard Magento CacheInterface exposes separate load() and remove()
 * calls, which creates a TOCTOU (time-of-check / time-of-use) window: two
 * concurrent requests carrying the same one-time token could both pass the
 * load() check before either remove() executes.  This interface abstracts
 * the get-and-delete as a single operation so implementations can use
 * backend-native atomics (e.g. Redis GETDEL) when available.
 *
 * Default preference (etc/di.xml): RedisAtomicCache (Redis GETDEL / Lua — truly
 * atomic; degrades to sequential load + remove when its dedicated Redis
 * connection is unavailable).
 *
 * Alternative: FileAtomicCache (sequential load + remove — safe for
 * single-server deployments without Redis). Switch in etc/di.xml:
 * <preference for="M2Oidc\OAuth\Model\Cache\AtomicCacheInterface"
 *             type="M2Oidc\OAuth\Model\Cache\FileAtomicCache"/>
 */
interface AtomicCacheInterface
{
    /**
     * Store a value under the given identifier.
     *
     * Implementations that use a storage separate from Magento's own
     * CacheInterface (e.g. RedisAtomicCache's dedicated connection) MUST
     * write here so that a later getAndDelete() call can find the value —
     * writes and reads/deletes must share the same storage.
     *
     * @param  string $identifier Full cache key (including any prefix)
     * @param  string $value      Value to store
     * @param  int    $ttl        Time-to-live in seconds
     */
    public function save(string $identifier, string $value, int $ttl): void;

    /**
     * Load a cache entry and remove it in a single operation.
     *
     * Returns null if the key does not exist or has expired.
     * After a successful call the key is guaranteed to be gone.
     *
     * @param  string $identifier Full cache key (including any prefix)
     * @return string|null        Stored value, or null if absent
     */
    public function getAndDelete(string $identifier): ?string;
}
