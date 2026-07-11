<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Security\RateLimiterStrategy;

use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

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

    /** @var LoggerInterface PSR logger for degraded-mode warnings */
    private readonly LoggerInterface $logger;

    /** @var bool Whether the degraded-to-fallback warning has been emitted (once per instance) */
    private bool $fallbackWarned = false;

    /**
     * @param CacheInterface                             $cache         Fallback (non-Redis)
     * @param \Magento\Framework\Cache\FrontendInterface $cacheFrontend Redis backend access
     * @param LoggerInterface                            $logger        PSR logger for degraded-mode warnings
     */
    public function __construct(
        CacheInterface $cache,
        \Magento\Framework\Cache\FrontendInterface $cacheFrontend,
        LoggerInterface $logger
    ) {
        $this->cache         = $cache;
        $this->cacheFrontend = $cacheFrontend;
        $this->logger        = $logger;
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
     * Fragility note: this reads Cm_Cache_Backend_Redis's PRIVATE `_client`
     * property via Reflection — there is no public accessor for the underlying
     * Credis_Client. If a colahub/cm_cache_backend_redis (or Magento) upgrade
     * renames or removes that property, or the deployment uses a different
     * Redis-backed cache class (e.g. a Symfony cache adapter), this method
     * returns null and the strategy silently degrades to the non-atomic
     * fallback. A one-time WARNING is logged in that case so operators know
     * the true sliding-window guarantee is not in effect.
     *
     * Returns null if not on a recognized Redis backend.
     */
    private function tryGetRedisClient(): ?\Credis_Client
    {
        try {
            $backend = $this->cacheFrontend->getBackend();
            if (!($backend instanceof \Cm_Cache_Backend_Redis)) {
                $this->warnDegradedOnce(
                    'cache backend ' . get_debug_type($backend) . ' is not Cm_Cache_Backend_Redis'
                );
                return null;
            }
            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
            $ref = new \ReflectionProperty(\Cm_Cache_Backend_Redis::class, '_client');
            $client = $ref->getValue($backend);
            if (!($client instanceof \Credis_Client)) {
                $this->warnDegradedOnce('could not extract Credis_Client from Cm_Cache_Backend_Redis');
                return null;
            }
            return $client;
        } catch (\Throwable $e) {
            $this->warnDegradedOnce('backend inspection failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Emit a single WARNING (per instance) when the Redis sliding window is unavailable.
     *
     * @param string $reason Why the Redis client could not be obtained
     */
    private function warnDegradedOnce(string $reason): void
    {
        if ($this->fallbackWarned) {
            return;
        }
        $this->fallbackWarned = true;
        $this->logger->warning(
            'M2Oidc: SlidingWindowStrategy degraded to the non-atomic in-cache fallback ('
            . $reason . ') — the true sliding-window rate-limit guarantee is not in effect.'
        );
    }
}
