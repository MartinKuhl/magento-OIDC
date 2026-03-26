<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Security\RateLimiterStrategy;

use Magento\Framework\App\CacheInterface;

/**
 * Fixed-window rate-limiting strategy.
 *
 * Records the start of a window on the first request and counts subsequent
 * requests within that window.  Once MAX_ATTEMPTS is reached the window is
 * exhausted and further requests are denied until the window expires.
 *
 * The window start is stored alongside the count so that increments never
 * reset the TTL (true fixed window, not sliding window).  This means a burst
 * at the end of a window plus a burst at the start of the next window can
 * together exceed MAX_ATTEMPTS without triggering the limit; use
 * SlidingWindowStrategy to prevent this.
 *
 * Safe for all Magento cache backends (file, Redis, Memcached).
 */
class FixedWindowStrategy implements StrategyInterface
{
    private const CACHE_PREFIX  = 'oidc_rate_limit_';
    private const MAX_ATTEMPTS  = 10;
    private const WINDOW_SECONDS = 60;

    /** @var CacheInterface */
    private readonly CacheInterface $cache;

    /**
     * @param CacheInterface $cache
     */
    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function isAllowed(string $identifier): bool
    {
        $key = self::CACHE_PREFIX . hash('sha256', $identifier);
        $raw = $this->cache->load($key);

        if ($raw === false || $raw === '') {
            $this->save($key, 1, time(), self::WINDOW_SECONDS);
            return true;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }

        if (!is_array($data) || !isset($data['count'], $data['start'])) {
            // Corrupted entry — reset to a fresh window.
            $this->save($key, 1, time(), self::WINDOW_SECONDS);
            return true;
        }

        /** @var array{count: int, start: int} $data */
        $elapsed = time() - $data['start'];

        if ($elapsed >= self::WINDOW_SECONDS) {
            $this->save($key, 1, time(), self::WINDOW_SECONDS);
            return true;
        }

        $count = (int) $data['count'];
        if ($count >= self::MAX_ATTEMPTS) {
            return false;
        }

        $remaining = self::WINDOW_SECONDS - $elapsed;
        $this->save($key, $count + 1, $data['start'], max(1, $remaining));
        return true;
    }

    /**
     * @param string $key
     * @param int    $count
     * @param int    $start
     * @param int    $ttl
     */
    private function save(string $key, int $count, int $start, int $ttl): void
    {
        $entry = json_encode(['count' => $count, 'start' => $start], JSON_THROW_ON_ERROR);
        $this->cache->save($entry, $key, [], $ttl);
    }
}
