<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Security\RateLimiterStrategy;

/**
 * Rate-limiting strategy interface.
 *
 * Implementations decide whether a request identified by $identifier
 * (typically an IP address) should be allowed or blocked.
 *
 * Two built-in strategies are provided:
 *  - FixedWindowStrategy  — default; safe for all cache backends
 *  - SlidingWindowStrategy — truly sliding; requires a Redis backend
 *
 * To switch to the sliding-window strategy, add to etc/di.xml:
 * <virtualType name="OidcSlidingWindowRateLimiter"
 *              type="M2Oidc\OAuth\Model\Security\OidcRateLimiter">
 *     <arguments>
 *         <argument name="strategy" xsi:type="object">
 *             M2Oidc\OAuth\Model\Security\RateLimiterStrategy\SlidingWindowStrategy
 *         </argument>
 *     </arguments>
 * </virtualType>
 * Then inject OidcSlidingWindowRateLimiter wherever OidcRateLimiter is used.
 */
interface StrategyInterface
{
    /**
     * Check whether the identified client is within its rate limit.
     *
     * Each call to isAllowed() counts as one attempt.  Implementations
     * MUST increment the counter on every call (including the first one
     * in a window) before deciding whether the request is allowed.
     *
     * @param  string $identifier Client identifier (IP address or similar)
     * @return bool True if the request is allowed, false if it should be blocked
     */
    public function isAllowed(string $identifier): bool;
}
