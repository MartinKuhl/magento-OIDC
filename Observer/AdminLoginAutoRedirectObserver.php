<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Observer;

use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\UrlInterface;
use MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps\CollectionFactory;

/**
 * Redirects unauthenticated admins to the IdP authorize URL
 * when auto-redirect is enabled and exactly one provider is configured.
 *
 * Guards:
 * - Post-logout cookie: prevents redirect right after an explicit logout.
 * - Session flag: prevents infinite redirect loops when the IdP
 *   returns without a successful authentication.
 */
class AdminLoginAutoRedirectObserver implements ObserverInterface
{
    private const SESSION_GUARD_KEY = 'oidc_admin_redirect_attempted';
    private const LOGOUT_COOKIE_NAME = 'oidc_admin_just_logged_out';

    public function __construct(
        private readonly CollectionFactory       $providerCollectionFactory,
        private readonly SessionManagerInterface $session,
        private readonly UrlInterface            $url,
        private readonly ActionFlag              $actionFlag,
        private readonly CookieManagerInterface  $cookieManager,
        private readonly CookieMetadataFactory   $cookieMetadataFactory
    ) {
    }

    public function execute(Observer $observer): void
    {
        // Guard: skip auto-redirect during OIDC logout flow (prevents re-login loop)
        if ($this->cookieManager->getCookie('oidc_logout_guard') === '1') {
            return;
        }
    
        // Post-logout guard: user explicitly logged out → show login page once
        if ($this->cookieManager->getCookie(self::LOGOUT_COOKIE_NAME)) {
            $this->deleteLogoutCookie();
            return;
        }

        // Loop guard: already redirected once → show normal login page
        if ($this->session->getData(self::SESSION_GUARD_KEY)) {
            $this->session->unsetData(self::SESSION_GUARD_KEY);
            return;
        }

        $collection = $this->providerCollectionFactory->create();

        // Condition: exactly 1 provider
        if ($collection->getSize() !== 1) {
            return;
        }

        $provider = $collection->getFirstItem();

        // Condition: non-OIDC admin login disabled AND auto-redirect enabled
        if (!(int) $provider->getData('mo_disable_non_oidc_admin_login')
            || !(int) $provider->getData('autoredirect_admin')
        ) {
            return;
        }

        // Set loop guard before redirecting
        $this->session->setData(self::SESSION_GUARD_KEY, true);

        // Build authorize URL and redirect
        $authorizeUrl = $this->url->getUrl('mooauth/actions/sendauthorizationrequest', [
            'provider_id' => $provider->getId(),
        ]);

        /** @var \Magento\Framework\App\Action\Action $controller */
        $controller = $observer->getEvent()->getData('controller_action');
        $controller->getResponse()->setRedirect($authorizeUrl);
        $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, true);
    }

    /**
     * Remove the logout cookie after it has been consumed.
     */
    private function deleteLogoutCookie(): void
    {
        $metadata = $this->cookieMetadataFactory
            ->createPublicCookieMetadata()
            ->setPath('/');

        $this->cookieManager->deleteCookie(self::LOGOUT_COOKIE_NAME, $metadata);
    }
}
