<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Cache;

use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Truly atomic getAndDelete implementation, backed by a dedicated Redis connection.
 *
 * Uses a Lua script to perform GET + DEL as
 * a single Redis command, eliminating the TOCTOU window present in
 * FileAtomicCache. The connection is opened directly from Magento's own cache
 * configuration (see RedisConnectionFactory) rather than reflected out of
 * whichever cache-backend/adapter object Magento happens to construct — that
 * keeps this working regardless of the active cache backend implementation
 * (legacy Cm_Cache_Backend_Redis, a Symfony-cache adapter, etc.).
 *
 * Falls back to load+remove when the dedicated Redis connection is
 * unavailable (e.g. Redis down, misconfigured, or absent in tests). Falls
 * back independently for save() and getAndDelete() — a Redis blip strictly
 * between the two calls makes the token unredeemable (forces a login retry)
 * rather than silently reusing the racy fallback for only one side of the
 * pair.
 *
 * To activate, add to etc/di.xml:
 * <preference for="M2Oidc\OAuth\Model\Cache\AtomicCacheInterface"
 *             type="M2Oidc\OAuth\Model\Cache\RedisAtomicCache"/>
 */
class RedisAtomicCache implements AtomicCacheInterface
{
    /**
     * Lua script: atomically GET then DEL a key.
     * Compatible with all Redis versions >= 2.6.
     * @var string
     */
    private const GETDEL_LUA =
        "local v = redis.call('GET', KEYS[1]) " .
        "if v then redis.call('DEL', KEYS[1]) end " .
        "return v";

    /** @var CacheInterface Non-atomic fallback when the dedicated Redis connection is unavailable */
    private readonly CacheInterface $cache;

    /** @var RedisConnectionFactory Provides the dedicated Redis connection for atomic operations */
    private readonly RedisConnectionFactory $redisConnectionFactory;

    /** @var LoggerInterface PSR logger for fallback warnings */
    private readonly LoggerInterface $logger;

    /** @var string Key prefix for this module's dedicated Redis keyspace */
    private readonly string $keyPrefix;

    /**
     * @param CacheInterface          $cache                  Fallback for when Redis is unavailable
     * @param RedisConnectionFactory  $redisConnectionFactory Dedicated Redis connection, independent of
     *                                                         Magento's cache backend/frontend classes
     * @param LoggerInterface         $logger                 PSR logger for fallback warnings
     * @param string                  $keyPrefix              Redis key prefix (default "m2oidc_atomic:")
     */
    public function __construct(
        CacheInterface $cache,
        RedisConnectionFactory $redisConnectionFactory,
        LoggerInterface $logger,
        string $keyPrefix = 'm2oidc_atomic:'
    ) {
        $this->cache                  = $cache;
        $this->redisConnectionFactory = $redisConnectionFactory;
        $this->logger                 = $logger;
        $this->keyPrefix              = $keyPrefix;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function save(string $identifier, string $value, int $ttl): void
    {
        $redis = $this->redisConnectionFactory->getConnection();

        if ($redis instanceof \Redis) {
            try {
                $redis->set($this->keyPrefix . $identifier, $value, ['EX' => $ttl]);
                return;
            } catch (\Throwable $e) {
                // Fall through to non-atomic fallback
                $this->logger->debug('M2Oidc: Redis save failed, using non-atomic fallback: ' . $e->getMessage());
            }
        }

        $this->logFallback();
        $this->cache->save($value, $identifier, [], $ttl);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getAndDelete(string $identifier): ?string
    {
        $redis = $this->redisConnectionFactory->getConnection();

        if ($redis instanceof \Redis) {
            $redisKey = $this->keyPrefix . $identifier;
            try {
                // Lua GET+DEL is atomic on every phpredis version and every Redis
                // server, whereas native GETDEL requires a Redis server >= 6.2.
                // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
                $value = $redis->eval(self::GETDEL_LUA, [$redisKey], 1);
                if (!in_array($value, [false, null, ''], true)) {
                    return (string) $value;
                }
                return null;
            } catch (\Throwable $e) {
                // Fall through to non-atomic fallback
                $this->logger->debug(
                    'M2Oidc: Redis getAndDelete failed, using non-atomic fallback: ' . $e->getMessage()
                );
            }
        }

        $this->logFallback();
        $value = $this->cache->load($identifier);
        if (in_array($value, [false, null, ''], true)) {
            return null;
        }
        $this->cache->remove($identifier);
        return (string) $value;
    }

    /**
     * Log the degraded-mode warning once per fallback occurrence.
     */
    private function logFallback(): void
    {
        $this->logger->critical(
            'M2Oidc: RedisAtomicCache falling back to non-atomic cache operation. '
            . 'Token replay protection is degraded. Check Redis connectivity.'
        );
    }
}
