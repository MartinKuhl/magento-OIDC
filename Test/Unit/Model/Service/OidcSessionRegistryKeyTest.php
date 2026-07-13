<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Service;

use Magento\Framework\App\CacheInterface;
use M2Oidc\OAuth\Model\Service\OidcSessionRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OidcSessionRegistry cache-key derivation.
 *
 * buildKey() is private, so the key material is observed through the public
 * register() API with a mocked cache: every register() call saves under the
 * derived primary key, which we capture from CacheInterface::save().
 *
 * Covers:
 *  - The historical concatenation collision ("a|b", "") vs ("a", "b|") now
 *    produces two DIFFERENT cache keys (parts are hashed independently).
 *  - Key derivation is stable: identical (sub, sid) input always yields the
 *    identical key.
 *  - Keys keep the documented "oidc_sess_<64-char hex>" format.
 *
 * @covers \M2Oidc\OAuth\Model\Service\OidcSessionRegistry
 */
class OidcSessionRegistryKeyTest extends TestCase
{
    /** @var CacheInterface&MockObject */
    private CacheInterface $cache;

    /** @var OidcSessionRegistry */
    private OidcSessionRegistry $registry;

    /** @var string[] Cache identifiers captured from save() calls, in order */
    private array $savedKeys = [];

    protected function setUp(): void
    {
        $this->savedKeys = [];
        $this->cache     = $this->createMock(CacheInterface::class);
        $this->cache->method('load')->willReturn(false);
        $this->cache->method('save')->willReturnCallback(
            function (string $data, string $identifier): bool {
                $this->savedKeys[] = $identifier;
                return true;
            }
        );

        $this->registry = new OidcSessionRegistry($this->cache);
    }

    /**
     * Register a (sub, sid) pair and return the derived primary cache key.
     *
     * The primary key is always the first key saved by register(); a non-empty
     * sid additionally saves a secondary sid-index key afterwards.
     */
    private function primaryKeyFor(string $sub, string $sid): string
    {
        $countBefore = count($this->savedKeys);
        $this->registry->register($sub, $sid, 'php-session-id', 'customer', 1);
        $this->assertGreaterThan($countBefore, count($this->savedKeys), 'register() must save at least one entry');
        return $this->savedKeys[$countBefore];
    }

    /**
     * The old buildKey() concatenated "$sub|$sid", so ("a|b", "") and
     * ("a", "b|") hashed identical input. They must now differ.
     */
    public function testFormerCollisionPairProducesDifferentKeys(): void
    {
        $keyA = $this->primaryKeyFor('a|b', '');
        $keyB = $this->primaryKeyFor('a', 'b|');

        $this->assertNotSame($keyA, $keyB);
    }

    /**
     * Same principle with the ambiguity on the other side of the separator.
     */
    public function testShiftedSeparatorPairProducesDifferentKeys(): void
    {
        $keyA = $this->primaryKeyFor('user', '|sid');
        $keyB = $this->primaryKeyFor('user|', 'sid');

        $this->assertNotSame($keyA, $keyB);
    }

    /**
     * Identical (sub, sid) input must always derive the identical key,
     * otherwise back-channel logout could not find registered sessions.
     */
    public function testKeyDerivationIsStable(): void
    {
        $first  = $this->primaryKeyFor('subject-123', 'session-456');
        $second = $this->primaryKeyFor('subject-123', 'session-456');

        $this->assertSame($first, $second);
    }

    /**
     * Keys keep the documented "oidc_sess_<64-char hex>" shape.
     */
    public function testKeyFormatIsPrefixedSha256Hex(): void
    {
        $key = $this->primaryKeyFor('subject-123', 'session-456');

        $this->assertMatchesRegularExpression('/^oidc_sess_[0-9a-f]{64}$/', $key);
    }

    /**
     * resolve() must derive the same key register() used, so a registered
     * entry is found again through the public API.
     */
    public function testResolveUsesSameKeyAsRegister(): void
    {
        $registeredKey = $this->primaryKeyFor('subject-123', 'session-456');

        $loadedKeys = [];
        $cache      = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            function (string $identifier) use (&$loadedKeys) {
                $loadedKeys[] = $identifier;
                return false;
            }
        );
        $registry = new OidcSessionRegistry($cache);
        $registry->resolve('subject-123', 'session-456');

        $this->assertSame([$registeredKey], $loadedKeys);
    }
}
