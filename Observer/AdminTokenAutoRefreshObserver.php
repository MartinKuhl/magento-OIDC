<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use M2Oidc\OAuth\Model\Service\AdminTokenRefreshService;

/**
 * Silently refreshes the OIDC access token before each admin controller action.
 *
 * Listens to `controller_action_predispatch` (adminhtml area). Delegates to
 * AdminTokenRefreshService::refreshIfNeeded(), which operates on the admin
 * AuthSession rather than the customer session.
 *
 * Behaviour mirrors TokenAutoRefreshObserver on the frontend:
 *  - No-op when admin is not logged in or has no OIDC refresh token stored.
 *  - No HTTP call when the token is still fresh (> 60 s from expiry).
 *  - Performs RFC 6749 §6 refresh grant when the token is near or past expiry.
 *  - Failure is non-fatal; AdminTokenRefreshService logs internally.
 */
class AdminTokenAutoRefreshObserver implements ObserverInterface
{
    /**
     * @param AdminTokenRefreshService $adminTokenRefreshService
     */
    public function __construct(
        private readonly AdminTokenRefreshService $adminTokenRefreshService
    ) {
    }

    /**
     * Execute observer.
     *
     * @param Observer $observer
     */
    #[\Override]
    public function execute(Observer $observer): void
    {
        $this->adminTokenRefreshService->refreshIfNeeded();
    }
}
