<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Cache;

use Magento\Framework\App\CacheInterface;

/**
 * Truly atomic getAndDelete implementation for Redis-backed Magento stores.
 *
 * Uses a Lua script to perform GET + DEL as a single Redis command, eliminating
 * the TOCTOU window present in FileAtomicCache.  The script is compatible with
 * Redis < 6.2; on Redis ≥ 6.2 the native GETDEL command is preferred.
 *
 * Falls back to load+remove when the Redis backend is unavailable or the
 * Credis client cannot be reached (e.g. in integration tests, file cache).
 *
 * To activate, add to etc/di.xml:
 * <preference for="M2Oidc\OAuth\Model\Cache\AtomicCacheInterface"
 *             type="M2Oidc\OAuth\Model\Cache\RedisAtomicCache"/>
 *
 * The key prefix must match the one used by the Cm_Cache_Backend_Redis instance
 * (typically "zc:" for the default Magento cache pool; check cm_cache_backend_redis
 * "key_prefix" in env.php if your setup differs).
 */
class RedisAtomicCache implements AtomicCacheInterface
{
    /**
     * Lua script: atomically GET then DEL a key.
     * Compatible with all Redis versions ≥ 2.6.
     */
    private const string GETDEL_LUA =
        "local v = redis.call('GET', KEYS[1]) " .
        "if v then redis.call('DEL', KEYS[1]) end " .
        "return v";

    /** @var CacheInterface Non-atomic fallback */
    private readonly CacheInterface $cache;

    /** @var \Magento\Framework\Cache\FrontendInterface */
    private readonly \Magento\Framework\Cache\FrontendInterface $cacheFrontend;

    /**
     * Key prefix applied by Cm_Cache_Backend_Redis.
     * Cm uses strtoupper() on the identifier; the prefix is prepended before that.
     * Default "zc:" matches the Magento default Redis cache backend prefix.
     *
     * @var string
     */
    private readonly string $keyPrefix;

    /**
     * @param CacheInterface                                    $cache           Fallback
     * @param \Magento\Framework\Cache\FrontendInterface        $cacheFrontend   Cache frontend for backend access
     * @param string                                            $keyPrefix       Redis key prefix (default "zc:")
     */
    public function __construct(
        CacheInterface $cache,
        \Magento\Framework\Cache\FrontendInterface $cacheFrontend,
        string $keyPrefix = 'zc:'
    ) {
        $this->cache          = $cache;
        $this->cacheFrontend  = $cacheFrontend;
        $this->keyPrefix      = $keyPrefix;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getAndDelete(string $identifier): ?string
    {
        $client = $this->tryGetRedisClient();

        if ($client instanceof \Credis_Client) {
            $redisKey = $this->keyPrefix . strtoupper($identifier);
            try {
                // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
                $value = $client->eval(self::GETDEL_LUA, $redisKey);
                if (!in_array($value, [false, null, ''], true)) {
                    return (string) $value;
                }
                return null;
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
            } catch (\Throwable $e) {
                // Fall through to non-atomic fallback
            }
        }

        // Non-atomic fallback
        $value = $this->cache->load($identifier);
        if (in_array($value, [false, null, ''], true)) {
            return null;
        }
        $this->cache->remove($identifier);
        return (string) $value;
    }

    /**
     * Attempt to retrieve the underlying Credis_Client from the cache backend.
     *
     * Cm_Cache_Backend_Redis does not expose a public getter for its client,
     * so we use reflection.  Returns null if the backend is not Redis or if
     * reflection fails.
     */
    private function tryGetRedisClient(): ?\Credis_Client
    {
        try {
            $backend = $this->cacheFrontend->getBackend();
            if (!($backend instanceof \Cm_Cache_Backend_Redis)) {
                return null;
            }
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
            $ref = new \ReflectionProperty(\Cm_Cache_Backend_Redis::class, '_client');
            $client = $ref->getValue($backend);
            return ($client instanceof \Credis_Client) ? $client : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
