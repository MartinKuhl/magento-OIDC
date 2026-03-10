<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Observer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Sets a session flag on customer logout to suppress the OIDC auto-redirect
 * on the subsequent login page visit.
 */
class CustomerSetLogoutFlagObserver implements ObserverInterface
{
    private const LOGOUT_FLAG_KEY = 'oidc_customer_just_logged_out';

    /**
     * Constructor.
     *
     * @param CustomerSession $customerSession
     */
    public function __construct(
        private readonly CustomerSession $customerSession
    ) {
    }

    /**
     * Set the logout flag in the customer session.
     */
    #[\Override]
    public function execute(Observer $observer): void
    {
        $this->customerSession->setData(self::LOGOUT_FLAG_KEY, true);
    }
}
