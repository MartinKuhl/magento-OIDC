<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Cache;

use Magento\Framework\App\CacheInterface;

/**
 * Non-atomic getAndDelete implementation using the standard Magento CacheInterface.
 *
 * The load() and remove() calls are issued sequentially, which leaves a
 * short TOCTOU window. This is acceptable for single-server deployments.
 * Use RedisAtomicCache for truly atomic behaviour on Redis-backed stores.
 *
 * This class is the default DI preference for AtomicCacheInterface.
 */
class FileAtomicCache implements AtomicCacheInterface
{
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
    public function getAndDelete(string $identifier): ?string
    {
        $value = $this->cache->load($identifier);

        if (in_array($value, [false, null, ''], true)) {
            return null;
        }

        $this->cache->remove($identifier);

        return (string) $value;
    }
}
