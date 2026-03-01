<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Test\Integration\Service;

use PHPUnit\Framework\TestCase;
use MiniOrange\OAuth\Model\Service\OidcSessionRegistry;

/**
 * Unit tests for FEAT-02: OidcSessionRegistry (TEST-06).
 *
 * The registry maps (sub, sid) pairs to PHP session IDs using Magento's
 * cache layer.  A mock CacheInterface is used so no real cache backend
 * is needed.
 *
 * Storage format: JSON {"php_session_id":..., "sub":..., "sid":...}
 */
class OidcSessionRegistryTest extends TestCase
{
    /** @var OidcSessionRegistry */
    private OidcSessionRegistry $registry;

    /** @var \Magento\Framework\App\CacheInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $cache;

    protected function setUp(): void
    {
        $this->cache    = $this->createMock(\Magento\Framework\App\CacheInterface::class);
        $this->registry = new OidcSessionRegistry($this->cache);
    }

    // ---------------------------------------------------------------- register + resolve

    public function testRegisterAndResolveRoundTrip(): void
    {
        $sub          = 'user-sub-123';
        $sid          = 'sess-id-abc';
        $phpSessionId = 'php-session-xyz';
        $expectedKey  = 'oidc_sess_' . sha1($sub . '|' . $sid);
        $expectedJson = json_encode(['php_session_id' => $phpSessionId, 'sub' => $sub, 'sid' => $sid]);

        $this->cache
            ->expects($this->once())
            ->method('save')
            ->with($expectedJson, $expectedKey, $this->anything(), 3600);

        $this->registry->register($sub, $sid, $phpSessionId, 3600);

        // Simulate a cache hit — return the stored JSON
        $this->cache
            ->method('load')
            ->with($expectedKey)
            ->willReturn($expectedJson);

        $resolved = $this->registry->resolve($sub, $sid);
        $this->assertSame($phpSessionId, $resolved);
    }

    public function testResolveReturnNullOnCacheMiss(): void
    {
        $this->cache->method('load')->willReturn(false);

        $result = $this->registry->resolve('unknown-sub', 'unknown-sid');
        $this->assertNull($result);
    }

    // -------------------------------------------------------------------- revoke

    public function testRevokeRemovesSessionFromCache(): void
    {
        $sub = 'user-sub-456';
        $sid = 'sess-id-def';
        $key = 'oidc_sess_' . sha1($sub . '|' . $sid);

        $this->cache
            ->expects($this->once())
            ->method('remove')
            ->with($key);

        $this->registry->revoke($sub, $sid);
    }

    // ------------------------------------------------------------- cache key stability

    public function testCacheKeyIsConsistentForSameInputs(): void
    {
        $sub = 'subject-abc';
        $sid = 'session-id-001';

        $capturedKey1 = null;
        $capturedKey2 = null;

        $cache1 = $this->createMock(\Magento\Framework\App\CacheInterface::class);
        $cache1->method('save')->willReturnCallback(function ($v, $k) use (&$capturedKey1) {
            $capturedKey1 = $k;
            return true;
        });

        $cache2 = $this->createMock(\Magento\Framework\App\CacheInterface::class);
        $cache2->method('save')->willReturnCallback(function ($v, $k) use (&$capturedKey2) {
            $capturedKey2 = $k;
            return true;
        });

        (new OidcSessionRegistry($cache1))->register($sub, $sid, 'session-php-aaa', 600);
        (new OidcSessionRegistry($cache2))->register($sub, $sid, 'session-php-bbb', 600);

        $this->assertSame(
            $capturedKey1,
            $capturedKey2,
            'Cache key must be deterministic for the same (sub, sid) pair.'
        );
    }

    // ----------------------------------------------------------------- default TTL

    public function testDefaultTtlIsAppliedWhenNotSpecified(): void
    {
        $ttlUsed = null;
        $this->cache
            ->method('save')
            ->willReturnCallback(function ($v, $k, $tags, $ttl) use (&$ttlUsed) {
                $ttlUsed = $ttl;
                return true;
            });

        // Call without explicit TTL — the registry uses its default (86400 s)
        $this->registry->register('sub', 'sid', 'php-session-id');

        $this->assertSame(86400, $ttlUsed, 'Default TTL must be 86400 seconds.');
    }

    // ----------------------------------------------------------------- skip empty sub

    public function testRegisterSkipsWhenSubIsEmpty(): void
    {
        $this->cache->expects($this->never())->method('save');
        $this->registry->register('', 'sid', 'php-session-id');
    }

    public function testRegisterSkipsWhenPhpSessionIdIsEmpty(): void
    {
        $this->cache->expects($this->never())->method('save');
        $this->registry->register('sub-abc', 'sid', '');
    }

    // ----------------------------------------------------------------- JSON payload

    public function testStoredPayloadContainsAllFields(): void
    {
        $storedValue = null;

        $this->cache
            ->method('save')
            ->willReturnCallback(function ($v) use (&$storedValue) {
                $storedValue = $v;
                return true;
            });

        $this->registry->register('my-sub', 'my-sid', 'php-sess-abc', 600);

        $this->assertNotNull($storedValue);
        $payload = json_decode((string) $storedValue, true);
        $this->assertIsArray($payload);
        $this->assertSame('php-sess-abc', $payload['php_session_id']);
        $this->assertSame('my-sub', $payload['sub']);
        $this->assertSame('my-sid', $payload['sid']);
    }
}
