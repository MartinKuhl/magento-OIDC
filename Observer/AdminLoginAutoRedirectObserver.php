<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Observer;

use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\HttpFactory as ResponseFactory;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\UrlInterface;
use MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps\CollectionFactory;

/**
 * Redirects unauthenticated admins to the IdP authorize URL
 * when auto-redirect is enabled and exactly one provider is configured.
 *
 * Loop guard: sets a session flag after the first redirect attempt.
 * If the flag is already present (= user came back from IdP without
 * successful auth), the redirect is skipped to prevent an infinite loop.
 */
class AdminLoginAutoRedirectObserver implements ObserverInterface
{
    private const SESSION_GUARD_KEY = 'oidc_admin_redirect_attempted';

    public function __construct(
        private readonly CollectionFactory $providerCollectionFactory,
        private readonly SessionManagerInterface $session,
        private readonly UrlInterface $url,
        private readonly ActionFlag $actionFlag
    ) {
    }

    public function execute(Observer $observer): void
    {
        /** @var RequestInterface $request */
        $request = $observer->getEvent()->getData('request');

        // Guard: already redirected in this session → skip (prevent loop)
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
}
