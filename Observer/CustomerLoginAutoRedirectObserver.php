<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Observer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\UrlInterface;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps\CollectionFactory;

/**
 * Redirects unauthenticated customers to the IdP authorize URL
 * when auto-redirect is enabled and exacfinal tly one provider is configured.
 *
 * Guards (in order of evaluation):
 *  1. Cookie-Guard  – oidc_logout_guard cookie set by OAuthLogoutObserver;
 *                     suppresses auto-redirect once after RP-Initiated Logout
 *                     and deletes the cookie immediately (consume-once).
 *  2. Session-Guard – oidc_customer_redirect_attempted; suppresses redirect
 *                     if the user already came back from the IdP without
 *                     successful authentication (loop prevention).
 */
class CustomerLoginAutoRedirectObserver implements ObserverInterface
{
    /** Session key set before redirecting to IdP (loop guard)
     * @var string */
    private const SESSION_GUARD_KEY = 'oidc_customer_redirect_attempted';

    /**
     * Cookie name – must match OAuthLogoutObserver::LOGOUT_GUARD_COOKIE.
     * Set by OAuthLogoutObserver before the RP-Initiated Logout redirect.
     * @var string
     */
    private const LOGOUT_GUARD_COOKIE = 'oidc_logout_guard';

    /**
     * @param CollectionFactory      $providerCollectionFactory
     * @param CustomerSession        $customerSession
     * @param UrlInterface           $url
     * @param ActionFlag             $actionFlag
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory  $cookieMetadataFactory
     */
    public function __construct(
        private readonly CollectionFactory      $providerCollectionFactory,
        private readonly CustomerSession        $customerSession,
        private readonly UrlInterface           $url,
        private readonly ActionFlag             $actionFlag,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory  $cookieMetadataFactory
    ) {
    }

    /**
     * Evaluate guards and – if all pass – redirect to IdP authorize URL.
     *
     * @param Observer $observer
     */
    #[\Override]
    public function execute(Observer $observer): void
    {
        // ── Guard 1: Post-logout cookie guard ────────────────────────────────
        // OAuthLogoutObserver sets this cookie before redirecting to the IdP.
        // We consume it here (delete immediately) so the customer sees the
        // Magento login page exactly once instead of being looped back to IdP.
        if ($this->cookieManager->getCookie(self::LOGOUT_GUARD_COOKIE) === '1') {
            $this->deleteLogoutGuardCookie();
            return;
        }

        // ── Already logged in → nothing to do ────────────────────────────────
        if ($this->customerSession->isLoggedIn()) {
            return;
        }

        // ── Guard 2: Session loop guard ───────────────────────────────────────
        // Set in step below before the redirect; cleared here on the way back.
        if ($this->customerSession->getData(self::SESSION_GUARD_KEY)) {
            $this->customerSession->unsetData(self::SESSION_GUARD_KEY);
            return;
        }

        // ── Provider check ────────────────────────────────────────────────────
        $collection = $this->providerCollectionFactory->create();

        // Exactly one provider must be configured for auto-redirect
        if ($collection->getSize() !== 1) {
            return;
        }

        $provider = $collection->getFirstItem();

        // Both flags must be active: non-OIDC login disabled AND auto-redirect enabled
        if (!(int) $provider->getData('m2oidc_disable_non_oidc_customer_login')
            || !(int) $provider->getData('autoredirect_customer')
        ) {
            return;
        }

        // ── Redirect to IdP ───────────────────────────────────────────────────
        // Set loop guard BEFORE redirect so it is available on the way back.
        $this->customerSession->setData(self::SESSION_GUARD_KEY, true);

        $authorizeUrl = $this->url->getUrl('m2oidc/actions/sendauthorizationrequest', [
            'provider_id' => $provider->getId(),
        ]);

        /** @var \Magento\Framework\App\Action\Action $controller */
        $controller = $observer->getEvent()->getData('controller_action');
        /** @psalm-suppress UndefinedInterfaceMethod */
        // @phpstan-ignore-next-line
        $controller->getResponse()->setRedirect($authorizeUrl);
        $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, '1');
    }

    /**
     * Delete the oidc_logout_guard cookie (consume-once pattern).
     *
     * HttpOnly=false because the cookie was set as public (readable by JS
     * and server-side observers alike). Secure=true to match the set-metadata.
     */
    private function deleteLogoutGuardCookie(): void
    {
        try {
            $metadata = $this->cookieMetadataFactory
                ->createPublicCookieMetadata()
                ->setPath('/')
                ->setHttpOnly(false)
                ->setSecure(true);

            $this->cookieManager->deleteCookie(self::LOGOUT_GUARD_COOKIE, $metadata);
        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        } catch (\Exception $e) {
            // Intentionally empty: cookie deletion failure is non-critical; it expires naturally (300 s)
        }
    }
}
