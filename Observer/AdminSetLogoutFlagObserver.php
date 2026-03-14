<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * Sets a short-lived cookie on admin logout to suppress the OIDC
 * auto-redirect on the subsequent login page visit.
 *
 * A cookie is used instead of a session flag because Magento
 * destroys the admin session during logout.
 */
class AdminSetLogoutFlagObserver implements ObserverInterface
{
    private const string LOGOUT_COOKIE_NAME = 'oidc_admin_just_logged_out';

    /**
     * Constructor.
     *
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory  $cookieMetadataFactory
     */
    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory  $cookieMetadataFactory
    ) {
    }

    /**
     * Set a short-lived cookie to suppress OIDC auto-redirect after admin logout.
     *
     * @param Observer $observer
     */
    #[\Override]
    public function execute(Observer $observer): void
    {
        $metadata = $this->cookieMetadataFactory
            ->createPublicCookieMetadata()
            ->setDuration(120)
            ->setPath('/')
            ->setHttpOnly(true)
            ->setSameSite('Lax');

        $this->cookieManager->setPublicCookie(
            self::LOGOUT_COOKIE_NAME,
            '1',
            $metadata
        );
    }
}
