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
 * Default preference: FileAtomicCache (load + remove — safe for single-server).
 * Redis preference:   RedisAtomicCache (Lua GETDEL — truly atomic).
 *
 * Switch the preference in etc/di.xml:
 * <preference for="M2Oidc\OAuth\Model\Cache\AtomicCacheInterface"
 *             type="M2Oidc\OAuth\Model\Cache\RedisAtomicCache"/>
 */
interface AtomicCacheInterface
{
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
