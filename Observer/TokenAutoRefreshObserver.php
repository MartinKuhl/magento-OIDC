<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use M2Oidc\OAuth\Model\Service\TokenRefreshService;

/**
 * Silently refreshes the OIDC access token before each frontend controller action.
 *
 * Listens to `controller_action_predispatch` (frontend area). Delegates entirely
 * to TokenRefreshService::refreshIfNeeded(), which:
 *  - Returns immediately (no HTTP call) when the token is still fresh.
 *  - Returns null immediately when no refresh token is stored (non-OIDC customers).
 *  - Performs RFC 6749 §6 refresh grant only when within 60 s of expiry.
 *
 * Failure is non-fatal: TokenRefreshService logs internally and the request
 * proceeds with the existing (potentially stale) token — same behaviour as
 * before the refresh was wired in.
 */
class TokenAutoRefreshObserver implements ObserverInterface
{
    /**
     * @param TokenRefreshService $tokenRefreshService
     */
    public function __construct(
        private readonly TokenRefreshService $tokenRefreshService
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
        $this->tokenRefreshService->refreshIfNeeded();
    }
}
