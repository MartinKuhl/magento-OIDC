<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Observer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\UrlInterface;
use MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps\CollectionFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * Redirects unauthenticated customers to the IdP authorize URL
 * when auto-redirect is enabled and exactly one provider is configured.
 *
 * Guards:
 * - Post-logout guard: suppresses redirect once after explicit logout.
 * - Loop guard: suppresses redirect if user already came back from IdP
 *   without successful authentication.
 */
class CustomerLoginAutoRedirectObserver implements ObserverInterface
{
    private const SESSION_GUARD_KEY = 'oidc_customer_redirect_attempted';
    private const LOGOUT_FLAG_KEY   = 'oidc_customer_just_logged_out';

    public function __construct(
        private readonly CollectionFactory $providerCollectionFactory,
        private readonly CustomerSession   $customerSession,
        private readonly UrlInterface      $url,
        private readonly ActionFlag        $actionFlag,
        private readonly CookieManagerInterface $cookieManager 
    ) {
    }

    public function execute(Observer $observer): void
    {
        // Guard: skip auto-redirect during OIDC logout flow
        if ($this->cookieManager->getCookie('oidc_logout_guard') === '1') {
            return;
        }
    
        // Already logged in → nothing to do
        if ($this->customerSession->isLoggedIn()) {
            return;
        }

        // Post-logout guard: user explicitly logged out → show login page once, then clear flag
        if ($this->customerSession->getData(self::LOGOUT_FLAG_KEY)) {
            $this->customerSession->unsetData(self::LOGOUT_FLAG_KEY);
            return;
        }

        // Loop guard: already redirected to IdP once → avoid infinite redirect loop
        if ($this->customerSession->getData(self::SESSION_GUARD_KEY)) {
            $this->customerSession->unsetData(self::SESSION_GUARD_KEY);
            return;
        }

        $collection = $this->providerCollectionFactory->create();

        // Exactly one provider must be configured
        if ($collection->getSize() !== 1) {
            return;
        }

        $provider = $collection->getFirstItem();

        // Both flags must be active: non-OIDC login disabled AND auto-redirect enabled
        if (!(int) $provider->getData('mo_disable_non_oidc_customer_login')
            || !(int) $provider->getData('autoredirect_customer')
        ) {
            return;
        }

        // Set loop guard before redirecting to prevent infinite loops
        $this->customerSession->setData(self::SESSION_GUARD_KEY, true);

        $authorizeUrl = $this->url->getUrl('mooauth/actions/sendauthorizationrequest', [
            'provider_id' => $provider->getId(),
        ]);

        /** @var \Magento\Framework\App\Action\Action $controller */
        $controller = $observer->getEvent()->getData('controller_action');
        $controller->getResponse()->setRedirect($authorizeUrl);
        $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, true);
    }
}
