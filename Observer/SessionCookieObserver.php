<?php
namespace MiniOrange\OAuth\Observer;

use Magento\Framework\Event\ObserverInterface;
use MiniOrange\OAuth\Helper\SessionHelper;

/**
 * Observer for session cookie adjustment.
 *
 * Called before the HTTP response is sent to ensure the session cookie
 * carries the correct SameSite attribute for cross-origin OIDC redirects.
 */
class SessionCookieObserver implements ObserverInterface
{
    /** @var \MiniOrange\OAuth\Helper\OAuthUtility */
    protected \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility;

    /** @var \MiniOrange\OAuth\Helper\SessionHelper */
    private readonly \MiniOrange\OAuth\Helper\SessionHelper $sessionHelper;

    /**
     * Initialize session cookie observer.
     *
     * @param \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility
     * @param SessionHelper                         $sessionHelper
     */
    public function __construct(
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        SessionHelper $sessionHelper
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->sessionHelper = $sessionHelper;
    }

    /**
     * Force SameSite=None on the session cookie before the response is sent.
     *
     * @param \Magento\Framework\Event\Observer $observer
     */
    #[\Override]
    public function execute(\Magento\Framework\Event\Observer $observer): void
    {
        try {
            $this->sessionHelper->forceSameSiteNone();
        } catch (\Exception $e) {
            // Only log critical errors; minor cookie issues should not break the request.
            $this->oauthUtility->customlog("SessionCookieObserver: Critical error - " . $e->getMessage());
        }
    }
}
