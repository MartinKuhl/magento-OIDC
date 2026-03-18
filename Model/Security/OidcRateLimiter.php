<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Security;

use Magento\Framework\App\CacheInterface;

/**
 * IP-based rate limiter for the OIDC callback endpoint.
 *
 * Counts requests per IP address within a fixed window using Magento's
 * cache backend (Redis in production, file cache in development).
 *
 * Fixed-window strategy: the window start time is recorded on the first
 * request and all subsequent increments use the remaining TTL so that the
 * window never slides forward on activity.
 *
 * Default: 10 attempts per 60 seconds per IP address.
 */
class OidcRateLimiter
{
    private const MAX_ATTEMPTS = 10;
    private const WINDOW_SECONDS = 60;
    private const CACHE_PREFIX = 'oidc_rate_limit_';

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
     * Check whether a request from the given identifier (IP address) is allowed.
     *
     * Increments the attempt counter within a fixed time window. Returns false
     * when the counter exceeds MAX_ATTEMPTS within WINDOW_SECONDS.
     *
     * The window start is stored alongside the count so that increments never
     * reset the TTL (true fixed window, not sliding window).
     *
     * @param  string $identifier IP address or other unique client identifier
     * @return bool   True if the request is allowed, false if it should be blocked
     */
    public function isAllowed(string $identifier): bool
    {
        $key     = self::CACHE_PREFIX . hash('sha256', $identifier);
        $raw     = $this->cache->load($key);

        if ($raw === false || $raw === '') {
            // First request in this window — create entry with full TTL
            $entry = json_encode(['count' => 1, 'start' => time()], JSON_THROW_ON_ERROR);
            $this->cache->save($entry, $key, [], self::WINDOW_SECONDS);
            return true;
        }

        /** @var array{count: int, start: int} $data */
        $data    = json_decode($raw, true);
        $elapsed = time() - (int) ($data['start'] ?? 0);

        if ($elapsed >= self::WINDOW_SECONDS) {
            // Window has expired — start a fresh window
            $entry = json_encode(['count' => 1, 'start' => time()], JSON_THROW_ON_ERROR);
            $this->cache->save($entry, $key, [], self::WINDOW_SECONDS);
            return true;
        }

        $count = (int) ($data['count'] ?? 0);
        if ($count >= self::MAX_ATTEMPTS) {
            return false;
        }

        // Increment within the current window; preserve the original window expiry
        // by using the remaining seconds as the TTL instead of the full window.
        $remaining = self::WINDOW_SECONDS - $elapsed;
        $entry     = json_encode(['count' => $count + 1, 'start' => $data['start']], JSON_THROW_ON_ERROR);
        $this->cache->save($entry, $key, [], max(1, $remaining));
        return true;
    }
}
