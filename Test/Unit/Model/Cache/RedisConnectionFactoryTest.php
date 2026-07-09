<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Cache;

use Magento\Framework\App\DeploymentConfig;
use M2Oidc\OAuth\Model\Cache\RedisConnectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RedisConnectionFactory.
 *
 * The factory opens a real phpredis connection, so these tests exercise the
 * config-parsing and failure-handling paths (missing config, unreachable
 * host) rather than a real Redis round trip — that's covered by manual /
 * integration verification against an actual Redis instance.
 *
 * @covers \M2Oidc\OAuth\Model\Cache\RedisConnectionFactory
 */
class RedisConnectionFactoryTest extends TestCase
{
    /** @var DeploymentConfig&MockObject */
    private DeploymentConfig $deploymentConfig;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->deploymentConfig = $this->createMock(DeploymentConfig::class);
        $this->logger           = $this->createMock(LoggerInterface::class);
    }

    public function testGetConnectionReturnsNullWhenBackendOptionsMissing(): void
    {
        $this->deploymentConfig->method('get')
            ->with('cache/frontend/default/backend_options')
            ->willReturn(null);

        $this->logger->expects($this->never())->method('warning');

        $factory = new RedisConnectionFactory($this->deploymentConfig, $this->logger);
        $this->assertNull($factory->getConnection());
    }

    public function testGetConnectionReturnsNullWhenServerOptionMissing(): void
    {
        $this->deploymentConfig->method('get')
            ->with('cache/frontend/default/backend_options')
            ->willReturn(['port' => '6379']);

        $factory = new RedisConnectionFactory($this->deploymentConfig, $this->logger);
        $this->assertNull($factory->getConnection());
    }

    public function testGetConnectionReturnsNullAndLogsWarningWhenHostUnreachable(): void
    {
        $this->deploymentConfig->method('get')
            ->with('cache/frontend/default/backend_options')
            ->willReturn(['server' => '127.0.0.1', 'port' => '1', 'database' => '0']);

        $this->logger->expects($this->once())->method('warning');

        $factory = new RedisConnectionFactory($this->deploymentConfig, $this->logger);
        $this->assertNull($factory->getConnection());
    }

    public function testGetConnectionMemoizesFailureWithoutReReadingConfig(): void
    {
        $this->deploymentConfig->expects($this->once())
            ->method('get')
            ->with('cache/frontend/default/backend_options')
            ->willReturn(null);

        $factory = new RedisConnectionFactory($this->deploymentConfig, $this->logger);
        $this->assertNull($factory->getConnection());
        $this->assertNull($factory->getConnection());
    }
}
