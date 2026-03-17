<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Observer;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\ObserverInterface;
use M2Oidc\OAuth\Helper\SessionHelper;

/**
 * Observer for session cookie adjustment.
 *
 * Called before final the HTTP response is sent to ensure the session cookie
 * carries the correct SameSite attribute for cross-origin OIDC redirects.
 * Scoped to /m2oidc/ routes only to avoid overhead on non-OIDC pages.
 */
class SessionCookieObserver implements ObserverInterface
{
    /** @var \M2Oidc\OAuth\Helper\OAuthUtility */
    private readonly \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility;

    /** @var SessionHelper */
    private readonly SessionHelper $sessionHelper;

    /** @var RequestInterface */
    private readonly RequestInterface $request;

    /**
     * Initialize session cookie observer.
     *
     * @param \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility
     * @param SessionHelper                     $sessionHelper
     * @param RequestInterface                  $request
     */
    public function __construct(
        \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility,
        SessionHelper $sessionHelper,
        RequestInterface $request
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->sessionHelper = $sessionHelper;
        $this->request = $request;
    }

    /**
     * Force SameSite=None on the session cookie before the response is sent.
     *
     * Only applies to /m2oidc/ routes — no overhead on catalog, checkout, or account pages.
     *
     * @param \Magento\Framework\Event\Observer $observer
     */
    #[\Override]
    public function execute(\Magento\Framework\Event\Observer $observer): void
    {
        // Only rewrite cookies on OIDC callback routes
        /** @psalm-suppress UndefinedInterfaceMethod */
        // @phpstan-ignore-next-line
        if (!str_contains((string) $this->request->getRequestUri(), '/m2oidc/')) {
            return;
        }

        try {
            $this->sessionHelper->forceSameSiteNone();
        } catch (\Exception $e) {
            // Only log critical errors; minor cookie issues should not break the request.
            $this->oauthUtility->customlog("SessionCookieObserver: Critical error - " . $e->getMessage());
        }
    }
}
