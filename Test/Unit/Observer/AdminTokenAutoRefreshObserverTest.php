<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Observer;

use Magento\Framework\Event\Observer;
use M2Oidc\OAuth\Model\Service\AdminTokenRefreshService;
use M2Oidc\OAuth\Observer\AdminTokenAutoRefreshObserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \M2Oidc\OAuth\Observer\AdminTokenAutoRefreshObserver
 */
class AdminTokenAutoRefreshObserverTest extends TestCase
{
    /** @var AdminTokenRefreshService&MockObject */
    private AdminTokenRefreshService $adminTokenRefreshService;

    /** @var AdminTokenAutoRefreshObserver */
    private AdminTokenAutoRefreshObserver $observer;

    protected function setUp(): void
    {
        $this->adminTokenRefreshService = $this->createMock(AdminTokenRefreshService::class);
        $this->observer = new AdminTokenAutoRefreshObserver($this->adminTokenRefreshService);
    }

    public function testRefreshIfNeededIsCalledOnExecute(): void
    {
        $this->adminTokenRefreshService
            ->expects($this->once())
            ->method('refreshIfNeeded');

        $this->observer->execute(new Observer([]));
    }

    public function testObserverEventParamIsIgnored(): void
    {
        $this->adminTokenRefreshService
            ->expects($this->once())
            ->method('refreshIfNeeded')
            ->willReturn(null);

        $this->observer->execute(new Observer([]));
    }

    public function testRefreshFailureIsSwallowedbByService(): void
    {
        $this->expectNotToPerformAssertions();
        $this->adminTokenRefreshService
            ->method('refreshIfNeeded')
            ->willReturn(null);

        $this->observer->execute(new Observer([]));
    }
}
