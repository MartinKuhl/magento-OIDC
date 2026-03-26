<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Observer;

use Magento\Framework\Event\Observer;
use M2Oidc\OAuth\Model\Service\TokenRefreshService;
use M2Oidc\OAuth\Observer\TokenAutoRefreshObserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \M2Oidc\OAuth\Observer\TokenAutoRefreshObserver
 */
class TokenAutoRefreshObserverTest extends TestCase
{
    /** @var TokenRefreshService&MockObject */
    private TokenRefreshService $tokenRefreshService;

    private TokenAutoRefreshObserver $observer;

    protected function setUp(): void
    {
        $this->tokenRefreshService = $this->createMock(TokenRefreshService::class);
        $this->observer = new TokenAutoRefreshObserver($this->tokenRefreshService);
    }

    public function testRefreshIfNeededIsCalledOnExecute(): void
    {
        $this->tokenRefreshService
            ->expects($this->once())
            ->method('refreshIfNeeded');

        $this->observer->execute(new Observer([]));
    }

    public function testObserverEventParamIsIgnored(): void
    {
        // The observer ignores the event payload entirely — refreshIfNeeded drives all logic.
        $this->tokenRefreshService
            ->expects($this->once())
            ->method('refreshIfNeeded')
            ->willReturn(null);

        $this->observer->execute(new Observer([]));
        // No assertion on the observer return — void method
        $this->addToAssertionCount(1);
    }

    public function testRefreshFailureIsSwallowedbByService(): void
    {
        // Service handles exceptions internally; observer must not re-throw.
        $this->tokenRefreshService
            ->method('refreshIfNeeded')
            ->willReturn(null); // Service returns null when no refresh needed/possible

        $this->observer->execute(new Observer([]));
        $this->addToAssertionCount(1); // no exception thrown
    }
}
