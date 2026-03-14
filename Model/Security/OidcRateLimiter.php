<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Security;

use Magento\Framework\App\CacheInterface;

/**
 * IP-based rate limiter for the OIDC callback endpoint.
 *
 * Counts requests per IP address within a sliding window using Magento's
 * cache backend (Redis in production, file cache in development).
 *
 * Default: 10 attempts per 60 seconds per IP address.
 */
class OidcRateLimiter
{
    private const int MAX_ATTEMPTS = 10;
    private const int WINDOW_SECONDS = 60;
    private const string CACHE_PREFIX = 'oidc_rate_limit_';

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
     * Increments the attempt counter. Returns false when the counter exceeds
     * MAX_ATTEMPTS within WINDOW_SECONDS.
     *
     * @param  string $identifier IP address or other unique client identifier
     * @return bool   True if the request is allowed, false if it should be blocked
     */
    public function isAllowed(string $identifier): bool
    {
        $key = self::CACHE_PREFIX . hash('sha256', $identifier);
        $count = (int) ($this->cache->load($key) ?: 0);

        if ($count >= self::MAX_ATTEMPTS) {
            return false;
        }

        // Increment counter; preserve the existing TTL only on the first write
        // (Magento's CacheInterface::save() resets the TTL on each call — this is
        // a sliding window approximation: window resets after 60 s of inactivity).
        $this->cache->save((string) ($count + 1), $key, [], self::WINDOW_SECONDS);
        return true;
    }
}
