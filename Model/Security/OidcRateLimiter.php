<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Security;

use M2Oidc\OAuth\Model\Security\RateLimiterStrategy\StrategyInterface;
use M2Oidc\OAuth\Model\Security\RateLimiterStrategy\FixedWindowStrategy;

/**
 * IP-based rate limiter for the OIDC callback endpoint.
 *
 * This class is a thin facade that delegates to an injected StrategyInterface.
 * The default strategy is FixedWindowStrategy (safe for all cache backends).
 * Replace with SlidingWindowStrategy for Redis deployments that need true
 * sliding-window semantics.
 *
 * Default: 10 attempts per 60 seconds per IP address (configurable in strategy).
 *
 * To switch strategy via DI virtual type in etc/di.xml:
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
class OidcRateLimiter
{
    /** @var StrategyInterface */
    private readonly StrategyInterface $strategy;

    /**
     * @param StrategyInterface $strategy Rate-limiting strategy (default: FixedWindowStrategy)
     */
    public function __construct(StrategyInterface $strategy)
    {
        $this->strategy = $strategy;
    }

    /**
     * Check whether a request from the given identifier (IP address) is allowed.
     *
     * @param  string $identifier IP address or other unique client identifier
     * @return bool   True if the request is allowed, false if it should be blocked
     */
    public function isAllowed(string $identifier): bool
    {
        return $this->strategy->isAllowed($identifier);
    }
}
