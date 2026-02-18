<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Plugin\User;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\User\Observer\Backend\ForceAdminPasswordChangeObserver;
use Magento\Framework\Event\Observer as EventObserver;
use MiniOrange\OAuth\Helper\OAuthUtility;

/**
 * Prevents forced password change redirect for OIDC-authenticated users.
 *
 * Without this plugin, OIDC users would be stuck in a redirect loop
 * to the "change password" page they cannot use.
 */
class OidcForcePasswordChangePlugin
{
    /**
     * Initialize OIDC force password change plugin.
     */
    public function __construct(
        private readonly AuthSession $authSession
    ) {
    }

    /**
     * Around plugin for ForceAdminPasswordChangeObserver::execute()
     *
     * Skips forced password change redirect for OIDC users.
     * Event: controller_action_predispatch
     */
    public function aroundExecute(
        ForceAdminPasswordChangeObserver $subject,
        callable $proceed,
        EventObserver $observer
    ): void {
        if ($this->isOidcSession()) {
            // Clear any password-expired flag that may have been set
            $this->authSession->unsPciAdminUserIsPasswordExpired();
            return;
        }

        $proceed($observer);
    }

    /**
     * Check if current session is OIDC authenticated.
     */
    private function isOidcSession(): bool
    {
        return (bool) $this->authSession->getIsOidcAuthenticated();
    }
}
