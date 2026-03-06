<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Observer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\UrlInterface;
use MiniOrange\OAuth\Model\ResourceModel\Provider\CollectionFactory;

/**
 * Redirects unauthenticated customers to the IdP authorize URL
 * when auto-redirect is enabled and exactly one provider is configured.
 */
class CustomerLoginAutoRedirectObserver implements ObserverInterface
{
    private const SESSION_GUARD_KEY = 'oidc_customer_redirect_attempted';

    public function __construct(
        private readonly CollectionFactory $providerCollectionFactory,
        private readonly CustomerSession $customerSession,
        private readonly UrlInterface $url,
        private readonly ActionFlag $actionFlag
    ) {
    }

    public function execute(Observer $observer): void
    {
        // Already logged in → skip
        if ($this->customerSession->isLoggedIn()) {
            return;
        }

        // Loop guard
        if ($this->customerSession->getData(self::SESSION_GUARD_KEY)) {
            $this->customerSession->unsetData(self::SESSION_GUARD_KEY);
            return;
        }

        $collection = $this->providerCollectionFactory->create();

        if ($collection->getSize() !== 1) {
            return;
        }

        $provider = $collection->getFirstItem();

        if (!(int) $provider->getData('mo_disable_non_oidc_customer_login')
            || !(int) $provider->getData('autoredirect_customer')
        ) {
            return;
        }

        $this->customerSession->setData(self::SESSION_GUARD_KEY, true);

        $authorizeUrl = $this->url->getUrl('mooauth/actions/sendauthorizationrequest', [
            'provider_id' => $provider->getId(),
        ]);

        $controller = $observer->getEvent()->getData('controller_action');
        $controller->getResponse()->setRedirect($authorizeUrl);
        $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, true);
    }
}
