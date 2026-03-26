<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Security;

use Magento\Framework\App\CacheInterface;
use M2Oidc\OAuth\Model\Security\OidcRateLimiter;
use M2Oidc\OAuth\Model\Security\RateLimiterStrategy\FixedWindowStrategy;
use M2Oidc\OAuth\Model\Security\RateLimiterStrategy\StrategyInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OidcRateLimiter + FixedWindowStrategy.
 *
 * OidcRateLimiter is a thin facade; the interesting logic is in FixedWindowStrategy.
 * We test both the facade delegation and the fixed-window algorithm.
 *
 * @covers \M2Oidc\OAuth\Model\Security\OidcRateLimiter
 * @covers \M2Oidc\OAuth\Model\Security\RateLimiterStrategy\FixedWindowStrategy
 */
class OidcRateLimiterTest extends TestCase
{
    // -------------------------------------------------------------------------
    // OidcRateLimiter facade delegation
    // -------------------------------------------------------------------------

    public function testFacadeDelegatesToStrategy(): void
    {
        $strategy = $this->createMock(StrategyInterface::class);
        $strategy->expects($this->once())
            ->method('isAllowed')
            ->with('1.2.3.4')
            ->willReturn(true);

        $limiter = new OidcRateLimiter($strategy);
        $this->assertTrue($limiter->isAllowed('1.2.3.4'));
    }

    public function testFacadeReturnsFalseWhenStrategyDenies(): void
    {
        $strategy = $this->createMock(StrategyInterface::class);
        $strategy->method('isAllowed')->willReturn(false);

        $limiter = new OidcRateLimiter($strategy);
        $this->assertFalse($limiter->isAllowed('1.2.3.4'));
    }

    // -------------------------------------------------------------------------
    // FixedWindowStrategy — first request always allowed
    // -------------------------------------------------------------------------

    public function testFirstRequestIsAllowed(): void
    {
        $cache = $this->buildCacheWith(false);
        $cache->expects($this->once())->method('save');

        $strategy = new FixedWindowStrategy($cache);
        $this->assertTrue($strategy->isAllowed('10.0.0.1'));
    }

    // -------------------------------------------------------------------------
    // FixedWindowStrategy — under-limit requests are allowed
    // -------------------------------------------------------------------------

    public function testUnderLimitRequestIsAllowed(): void
    {
        $data = ['count' => 5, 'start' => time()];
        $cache = $this->buildCacheWith(json_encode($data));
        $cache->expects($this->once())->method('save');

        $strategy = new FixedWindowStrategy($cache);
        $this->assertTrue($strategy->isAllowed('10.0.0.1'));
    }

    // -------------------------------------------------------------------------
    // FixedWindowStrategy — over-limit requests are blocked
    // -------------------------------------------------------------------------

    public function testOverLimitRequestIsBlocked(): void
    {
        $data = ['count' => 10, 'start' => time()];
        $cache = $this->buildCacheWith(json_encode($data));
        $cache->expects($this->never())->method('save');

        $strategy = new FixedWindowStrategy($cache);
        $this->assertFalse($strategy->isAllowed('10.0.0.1'));
    }

    // -------------------------------------------------------------------------
    // FixedWindowStrategy — window resets after expiry
    // -------------------------------------------------------------------------

    public function testWindowResetAfterExpiry(): void
    {
        // start was 120 seconds ago (> 60 s window)
        $data = ['count' => 10, 'start' => time() - 120];
        $cache = $this->buildCacheWith(json_encode($data));
        $cache->expects($this->once())->method('save');

        $strategy = new FixedWindowStrategy($cache);
        $this->assertTrue($strategy->isAllowed('10.0.0.1'));
    }

    // -------------------------------------------------------------------------
    // FixedWindowStrategy — corrupted entry is reset to a fresh window
    // -------------------------------------------------------------------------

    public function testCorruptedCacheEntryResetsToFreshWindow(): void
    {
        $cache = $this->buildCacheWith('NOT-VALID-JSON{{{');
        $cache->expects($this->once())->method('save');

        $strategy = new FixedWindowStrategy($cache);
        $this->assertTrue($strategy->isAllowed('10.0.0.1'));
    }

    public function testMissingCountFieldResetsToFreshWindow(): void
    {
        $data = ['start' => time()]; // missing 'count'
        $cache = $this->buildCacheWith(json_encode($data));
        $cache->expects($this->once())->method('save');

        $strategy = new FixedWindowStrategy($cache);
        $this->assertTrue($strategy->isAllowed('10.0.0.1'));
    }

    // -------------------------------------------------------------------------
    // FixedWindowStrategy — exact boundary conditions
    // -------------------------------------------------------------------------

    public function testNineAttemptsAllowed(): void
    {
        $data = ['count' => 9, 'start' => time()];
        $cache = $this->buildCacheWith(json_encode($data));
        $strategy = new FixedWindowStrategy($cache);
        $this->assertTrue($strategy->isAllowed('10.0.0.1'));
    }

    public function testTenAttemptsBlocked(): void
    {
        $data = ['count' => 10, 'start' => time()];
        $cache = $this->buildCacheWith(json_encode($data));
        $strategy = new FixedWindowStrategy($cache);
        $this->assertFalse($strategy->isAllowed('10.0.0.1'));
    }

    // -------------------------------------------------------------------------
    // FixedWindowStrategy — empty string cache hit treated as fresh window
    // -------------------------------------------------------------------------

    public function testEmptyStringCacheResetToFreshWindow(): void
    {
        $cache = $this->buildCacheWith('');
        $cache->expects($this->once())->method('save');

        $strategy = new FixedWindowStrategy($cache);
        $this->assertTrue($strategy->isAllowed('10.0.0.1'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param string|false $loadReturn
     * @return CacheInterface&MockObject
     */
    private function buildCacheWith($loadReturn): CacheInterface
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn($loadReturn);
        return $cache;
    }
}
