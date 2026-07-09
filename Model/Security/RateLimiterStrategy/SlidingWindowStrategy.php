<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Security\RateLimiterStrategy;

use Magento\Framework\App\CacheInterface;

/**
 * Sliding-window rate-limiting strategy.
 *
 * On Redis backends, uses a sorted set (score = microsecond timestamp) with
 * ZREMRANGEBYSCORE + ZCARD + ZADD + EXPIRE to count requests in a true
 * sliding window.  This prevents burst exploitation at window boundaries.
 *
 * On non-Redis backends, falls back to an in-cache JSON list of timestamps,
 * which is non-atomic but acceptable for single-server / low-traffic setups.
 *
 * To activate, inject this strategy via a virtual type in etc/di.xml:
 * <virtualType name="OidcSlidingWindowRateLimiter"
 *              type="M2Oidc\OAuth\Model\Security\OidcRateLimiter">
 *     <arguments>
 *         <argument name="strategy" xsi:type="object">
 *             M2Oidc\OAuth\Model\Security\RateLimiterStrategy\SlidingWindowStrategy
 *         </argument>
 *     </arguments>
 * </virtualType>
 */
class SlidingWindowStrategy implements StrategyInterface
{
    /**
     * @var string
     */
    private const SORTED_SET_PREFIX = 'oidc_rl_';
    /**
     * @var int
     */
    private const MAX_ATTEMPTS      = 10;
    /**
     * @var int
     */
    private const WINDOW_SECONDS    = 60;

    /** @var CacheInterface Non-Redis fallback */
    private readonly CacheInterface $cache;

    /** @var \Magento\Framework\Cache\FrontendInterface */
    private readonly \Magento\Framework\Cache\FrontendInterface $cacheFrontend;

    /**
     * @param CacheInterface                             $cache         Fallback (non-Redis)
     * @param \Magento\Framework\Cache\FrontendInterface $cacheFrontend Redis backend access
     */
    public function __construct(
        CacheInterface $cache,
        \Magento\Framework\Cache\FrontendInterface $cacheFrontend
    ) {
        $this->cache         = $cache;
        $this->cacheFrontend = $cacheFrontend;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function isAllowed(string $identifier): bool
    {
        $client = $this->tryGetRedisClient();
        if ($client instanceof \Credis_Client) {
            return $this->isAllowedRedis($client, $identifier);
        }
        return $this->isAllowedFallback($identifier);
    }

    /**
     * Redis sorted-set sliding window.
     *
     * Uses score = microtime(true) for sub-second precision.
     * The sorted set key is NOT prefixed with the Zend cache prefix because
     * we manage it directly via Redis commands — it lives outside the Magento
     * cache namespace.
     *
     * @param \Credis_Client $client     Redis client instance
     * @param string         $identifier Unique rate-limit key (e.g. client IP)
     */
    private function isAllowedRedis(\Credis_Client $client, string $identifier): bool
    {
        $key = self::SORTED_SET_PREFIX . hash('sha256', $identifier);
        microtime(true);

        try {
            // Remove entries outside the window, then count + add + expire atomically
            $lua = <<<LUA
local key    = KEYS[1]
local now    = tonumber(ARGV[1])
local wstart = tonumber(ARGV[2])
local max    = tonumber(ARGV[3])
local ttl    = tonumber(ARGV[4])

redis.call('ZREMRANGEBYSCORE', key, '-inf', wstart)
local count = redis.call('ZCARD', key)
if count >= max then
    return 0
end
redis.call('ZADD', key, now, now)
redis.call('EXPIRE', key, ttl)
return 1
LUA;
            $result = $client->eval(
                $lua,
                $key
            );
            return (int) $result === 1;
        } catch (\Throwable) {
            // Redis unavailable — fall through to the non-atomic fallback
            return $this->isAllowedFallback($identifier);
        }
    }

    /**
     * Non-atomic fallback: store a JSON list of microsecond timestamps in cache.
     *
     * Suffers from a TOCTOU window under high concurrency.  Use the Redis path
     * in production.
     *
     * @param string $identifier Unique rate-limit key (e.g. client IP)
     */
    private function isAllowedFallback(string $identifier): bool
    {
        $cacheKey = 'oidc_rl_ts_' . hash('sha256', $identifier);
        $raw      = $this->cache->load($cacheKey);
        $now      = microtime(true);
        $windowStart = $now - (float) self::WINDOW_SECONDS;

        if ($raw !== '') {
            try {
                $timestamps = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $timestamps = [];
            }
        } else {
            $timestamps = [];
        }

        if (!is_array($timestamps)) {
            $timestamps = [];
        }

        // Prune stale entries
        $timestamps = array_values(array_filter(
            $timestamps,
            static fn (float $ts): bool => $ts > $windowStart
        ));

        if (count($timestamps) >= self::MAX_ATTEMPTS) {
            return false;
        }

        $timestamps[] = $now;
        $entry = json_encode($timestamps, JSON_THROW_ON_ERROR);
        $this->cache->save($entry, $cacheKey, [], self::WINDOW_SECONDS + 1);
        return true;
    }

    /**
     * Attempt to retrieve the Credis_Client from the cache backend via reflection.
     *
     * Returns null if not on a Redis backend.
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
