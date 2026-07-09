<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Cache;

use Magento\Framework\App\CacheInterface;
use M2Oidc\OAuth\Model\Cache\RedisAtomicCache;
use M2Oidc\OAuth\Model\Cache\RedisConnectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RedisAtomicCache.
 *
 * Verifies the dedicated-Redis-connection path (via RedisConnectionFactory)
 * is used when available, and that save()/getAndDelete() fall back
 * independently to the non-atomic CacheInterface path (with a critical log)
 * when the connection is unavailable or a Redis call throws.
 *
 * @covers \M2Oidc\OAuth\Model\Cache\RedisAtomicCache
 */
class RedisAtomicCacheTest extends TestCase
{
    /** @var CacheInterface&MockObject */
    private CacheInterface $cache;

    /** @var RedisConnectionFactory&MockObject */
    private RedisConnectionFactory $redisConnectionFactory;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var RedisAtomicCache */
    private RedisAtomicCache $atomicCache;

    protected function setUp(): void
    {
        $this->cache                  = $this->createMock(CacheInterface::class);
        $this->redisConnectionFactory = $this->createMock(RedisConnectionFactory::class);
        $this->logger                 = $this->createMock(LoggerInterface::class);

        $this->atomicCache = new RedisAtomicCache(
            $this->cache,
            $this->redisConnectionFactory,
            $this->logger
        );
    }

    // -------------------------------------------------------------------------
    // save()
    // -------------------------------------------------------------------------

    public function testSaveUsesRedisWhenConnectionAvailable(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('set')
            ->with('m2oidc_atomic:some-key', 'the-value', ['EX' => 300])
            ->willReturn(true);
        $this->redisConnectionFactory->method('getConnection')->willReturn($redis);

        $this->cache->expects($this->never())->method('save');
        $this->logger->expects($this->never())->method('critical');

        $this->atomicCache->save('some-key', 'the-value', 300);
    }

    public function testSaveFallsBackToCacheWhenConnectionUnavailable(): void
    {
        $this->redisConnectionFactory->method('getConnection')->willReturn(null);

        $this->logger->expects($this->once())->method('critical');
        $this->cache->expects($this->once())
            ->method('save')
            ->with('the-value', 'some-key', [], 300);

        $this->atomicCache->save('some-key', 'the-value', 300);
    }

    public function testSaveFallsBackToCacheWhenRedisThrows(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('set')->willThrowException(new \RedisException('connection lost'));
        $this->redisConnectionFactory->method('getConnection')->willReturn($redis);

        $this->logger->expects($this->once())->method('critical');
        $this->cache->expects($this->once())
            ->method('save')
            ->with('the-value', 'some-key', [], 300);

        $this->atomicCache->save('some-key', 'the-value', 300);
    }

    // -------------------------------------------------------------------------
    // getAndDelete()
    // -------------------------------------------------------------------------

    public function testGetAndDeleteUsesRedisLuaGetDelWhenConnectionAvailable(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('eval')
            ->with(
                $this->stringContains("redis.call('GET', KEYS[1])"),
                ['m2oidc_atomic:some-key'],
                1
            )
            ->willReturn('the-value');
        $this->redisConnectionFactory->method('getConnection')->willReturn($redis);

        $this->cache->expects($this->never())->method('load');
        $this->logger->expects($this->never())->method('critical');

        $this->assertSame('the-value', $this->atomicCache->getAndDelete('some-key'));
    }

    public function testGetAndDeleteReturnsNullWhenRedisKeyMissing(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('eval')->willReturn(false);
        $this->redisConnectionFactory->method('getConnection')->willReturn($redis);

        $this->assertNull($this->atomicCache->getAndDelete('some-key'));
    }

    public function testGetAndDeleteFallsBackToCacheWhenConnectionUnavailable(): void
    {
        $this->redisConnectionFactory->method('getConnection')->willReturn(null);

        $this->logger->expects($this->once())->method('critical');
        $this->cache->expects($this->once())->method('load')->with('some-key')->willReturn('the-value');
        $this->cache->expects($this->once())->method('remove')->with('some-key');

        $this->assertSame('the-value', $this->atomicCache->getAndDelete('some-key'));
    }

    public function testGetAndDeleteFallbackReturnsNullOnCacheMissWithoutCallingRemove(): void
    {
        $this->redisConnectionFactory->method('getConnection')->willReturn(null);

        $this->cache->method('load')->willReturn(false);
        $this->cache->expects($this->never())->method('remove');

        $this->assertNull($this->atomicCache->getAndDelete('some-key'));
    }

    public function testGetAndDeleteFallsBackToCacheWhenRedisThrows(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('eval')->willThrowException(new \RedisException('connection lost'));
        $this->redisConnectionFactory->method('getConnection')->willReturn($redis);

        $this->logger->expects($this->once())->method('critical');
        $this->cache->method('load')->willReturn('the-value');
        $this->cache->expects($this->once())->method('remove')->with('some-key');

        $this->assertSame('the-value', $this->atomicCache->getAndDelete('some-key'));
    }
}
