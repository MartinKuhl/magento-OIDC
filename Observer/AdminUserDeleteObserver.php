<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider;

/**
 * Removes the OIDC session activity entry when an admin user is deleted.
 */
class AdminUserDeleteObserver implements ObserverInterface
{
    /**
     * Constructor.
     *
     * @param UserProvider $userProviderResource
     */
    public function __construct(
        private readonly UserProvider $userProviderResource
    ) {
    }

    /**
     * Delete the m2oidc_oauth_user_provider row for the deleted admin user.
     *
     * @param Observer $observer
     */
    #[\Override]
    public function execute(Observer $observer): void
    {
        $user = $observer->getEvent()->getObject();
        if ($user === null) {
            return;
        }
        $userId = (int) $user->getId();
        if ($userId > 0) {
            $this->userProviderResource->deleteMapping('admin', $userId);
        }
    }
}
