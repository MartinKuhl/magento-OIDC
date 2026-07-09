<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Cache;

use Magento\Framework\App\CacheInterface;
use M2Oidc\OAuth\Model\Cache\FileAtomicCache;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \M2Oidc\OAuth\Model\Cache\FileAtomicCache
 */
class FileAtomicCacheTest extends TestCase
{
    /** @var CacheInterface&MockObject */
    private CacheInterface $cache;

    /** @var FileAtomicCache */
    private FileAtomicCache $atomicCache;

    protected function setUp(): void
    {
        $this->cache       = $this->createMock(CacheInterface::class);
        $this->atomicCache = new FileAtomicCache($this->cache);
    }

    public function testSaveDelegatesToCache(): void
    {
        $this->cache->expects($this->once())
            ->method('save')
            ->with('the-value', 'some-key', [], 300);

        $this->atomicCache->save('some-key', 'the-value', 300);
    }

    public function testGetAndDeleteReturnsNullOnMiss(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->cache->expects($this->never())->method('remove');

        $this->assertNull($this->atomicCache->getAndDelete('some-key'));
    }

    public function testGetAndDeleteReturnsValueAndRemovesKey(): void
    {
        $this->cache->method('load')->with('some-key')->willReturn('the-value');
        $this->cache->expects($this->once())->method('remove')->with('some-key');

        $this->assertSame('the-value', $this->atomicCache->getAndDelete('some-key'));
    }
}
