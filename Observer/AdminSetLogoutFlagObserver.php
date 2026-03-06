<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Session\SessionManagerInterface;

/**
 * Sets a session flag on admin logout to suppress the OIDC auto-redirect
 * on the subsequent login page visit.
 */
class AdminSetLogoutFlagObserver implements ObserverInterface
{
    private const LOGOUT_FLAG_KEY = 'oidc_admin_just_logged_out';

    public function __construct(
        private readonly SessionManagerInterface $session
    ) {
    }

    public function execute(Observer $observer): void
    {
        $this->session->setData(self::LOGOUT_FLAG_KEY, true);
    }
}
