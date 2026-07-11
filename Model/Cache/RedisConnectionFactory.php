<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Cache;

use Magento\Framework\App\DeploymentConfig;
use Psr\Log\LoggerInterface;

/**
 * Builds a raw phpredis connection from Magento's own cache configuration.
 *
 * RedisAtomicCache needs a real Redis connection to run atomic GETDEL
 * operations, but Magento's cache-frontend/backend object graph varies by
 * install (Cm_Cache_Backend_Redis, a Symfony-cache adapter, etc.) and is not
 * a stable thing to reflect into. Instead, this factory reads the same
 * connection parameters Magento itself uses for the default cache frontend
 * (env.php: cache/frontend/default/backend_options) and opens an independent
 * connection — so it keeps working regardless of which cache backend class
 * Magento constructs internally.
 *
 * Supported topology: a single Redis node addressed by `server` + `port`,
 * with optional `password` (AUTH) and `database` (SELECT index). NOT
 * supported: Redis Sentinel, Redis Cluster, TLS/SSL connections, and ACL
 * usernames (username+password AUTH). On such setups getConnection() returns
 * null and callers fall back to non-atomic load + remove behavior.
 */
class RedisConnectionFactory
{
    /** @var DeploymentConfig Access to env.php cache backend options */
    private readonly DeploymentConfig $deploymentConfig;

    /** @var LoggerInterface PSR logger for connection-failure warnings */
    private readonly LoggerInterface $logger;

    /** @var \Redis|null|false Memoized connection; false means "connection attempted and failed" */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting.Missing -- sniff cannot parse null|false union
    private \Redis|null|false $connection = null;

    /**
     * Constructor.
     *
     * @param DeploymentConfig $deploymentConfig Access to env.php cache backend options
     * @param LoggerInterface  $logger           PSR logger for connection-failure warnings
     */
    public function __construct(DeploymentConfig $deploymentConfig, LoggerInterface $logger)
    {
        $this->deploymentConfig = $deploymentConfig;
        $this->logger           = $logger;
    }

    /**
     * Return a connected Redis client, or null if Redis is not configured or unreachable.
     *
     * The connection is memoized for the lifetime of this instance so repeated
     * getAndDelete()/save() calls within one request (a single OIDC login can
     * make up to 4) don't each pay a fresh connect cost.
     */
    public function getConnection(): ?\Redis
    {
        if ($this->connection === false) {
            return null;
        }

        if ($this->connection instanceof \Redis) {
            return $this->connection;
        }

        try {
            $options = $this->deploymentConfig->get('cache/frontend/default/backend_options');
            if (!is_array($options) || empty($options['server'])) {
                $this->connection = false;
                return null;
            }

            $redis = new \Redis();
            $redis->connect(
                (string) $options['server'],
                (int) ($options['port'] ?? 6379),
                2.5
            );

            if (!empty($options['password'])) {
                $redis->auth((string) $options['password']);
            }

            if (isset($options['database'])) {
                $redis->select((int) $options['database']);
            }

            $this->connection = $redis;
            return $redis;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'M2Oidc: RedisConnectionFactory could not open a dedicated Redis connection'
                . ' (this does not necessarily mean Redis is down — an unsupported topology'
                . ' such as Sentinel/Cluster/TLS/ACL-username auth is a likely cause): '
                . $e->getMessage()
            );
            $this->connection = false;
            return null;
        }
    }
}
