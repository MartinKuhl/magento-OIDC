<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\User\Model\User;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider;
use M2Oidc\OAuth\Observer\AdminUserDeleteObserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \M2Oidc\OAuth\Observer\AdminUserDeleteObserver
 */
class AdminUserDeleteObserverTest extends TestCase
{
    /** @var UserProvider&MockObject */
    private UserProvider $userProviderResource;

    private AdminUserDeleteObserver $observer;

    protected function setUp(): void
    {
        $this->userProviderResource = $this->createMock(UserProvider::class);
        $this->observer = new AdminUserDeleteObserver($this->userProviderResource);
    }

    private function buildObserver(?object $user): Observer
    {
        $event = new \Magento\Framework\Event(['object' => $user]);
        return new Observer(['event' => $event]);
    }

    public function testDeleteMappingCalledWithCorrectUserIdAndType(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(42);

        $this->userProviderResource
            ->expects($this->once())
            ->method('deleteMapping')
            ->with('admin', 42);

        $this->observer->execute($this->buildObserver($user));
    }

    public function testNoOpWhenUserIsNull(): void
    {
        $this->userProviderResource
            ->expects($this->never())
            ->method('deleteMapping');

        $this->observer->execute($this->buildObserver(null));
    }

    public function testNoOpWhenUserIdIsZero(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(0);

        $this->userProviderResource
            ->expects($this->never())
            ->method('deleteMapping');

        $this->observer->execute($this->buildObserver($user));
    }
}
