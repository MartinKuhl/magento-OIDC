<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Observer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\PhpEnvironment\Response as HttpResponse;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * Observer for customer logout events. Handles redirecting to the
 * configured OAuth/OIDC logout URL when the customer logs out.
 *
 * MP-08: When a numeric provider ID was stored in the customer session at
 * login time, the provider's `endsession_endpoint` takes precedence over
 * the global store-config `OAUTH_LOGOUT_URL`. This enables per-provider
 * IdP-initiated logout in multi-provider deployments.
 */
class OAuthLogoutObserver implements ObserverInterface
{
    /** @var \MiniOrange\OAuth\Helper\OAuthUtility */
    private readonly \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility;

    /** @var ResponseInterface */
    protected ResponseInterface $_response;

    /** @var CookieManagerInterface */
    private readonly CookieManagerInterface $cookieManager;

    /** @var CookieMetadataFactory */
    private readonly CookieMetadataFactory $cookieMetadataFactory;

    /** @var CustomerSession */
    private readonly CustomerSession $customerSession;

    /**
     * Initialize OAuth logout observer.
     *
     * @param \MiniOrange\OAuth\Helper\OAuthUtility  $oauthUtility          OAuth utility helper
     * @param ResponseInterface                      $response              HTTP response interface
     * @param CookieManagerInterface                 $cookieManager         Cookie manager
     * @param CookieMetadataFactory                  $cookieMetadataFactory Cookie metadata factory
     * @param CustomerSession                        $customerSession       Customer session (MP-08)
     */
    public function __construct(
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        ResponseInterface $response,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        CustomerSession $customerSession
    ) {
        $this->oauthUtility          = $oauthUtility;
        $this->_response             = $response;
        $this->cookieManager         = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->customerSession       = $customerSession;
    }

    /**
     * Handle logout event and redirect to the OIDC provider's logout URL.
     *
     * MP-08 behaviour:
     *  1. Read `oidc_provider_id` from the customer session (set at login time
     *     by CheckAttributeMappingAction::setClientDetails()).
     *  2. If a provider ID is found, load the provider row and use its
     *     `endsession_endpoint` column as the logout redirect target.
     *  3. Fall back to the global store-config `OAUTH_LOGOUT_URL` when no
     *     per-provider ID is available (single-provider / legacy mode).
     *
     * Cookie cleanup is best-effort and will not prevent the redirect if it fails.
     *
     * @param Observer $observer Event observer
     */
    #[\Override]
    public function execute(Observer $observer): void
    {
        // Clean up OIDC customer cookie on logout
        try {
            $oidcCookie = $this->cookieManager->getCookie('oidc_customer_authenticated');
            if ($oidcCookie !== null) {
                $cookieMetadata = $this->cookieMetadataFactory
                    ->createPublicCookieMetadata()
                    ->setPath('/')
                    ->setHttpOnly(true)
                    ->setSecure(true);
                $this->cookieManager->deleteCookie('oidc_customer_authenticated', $cookieMetadata);
                $this->oauthUtility->customlog("OAuthLogoutObserver: OIDC customer cookie deleted");
            }
        } catch (\Exception $e) {
            // Silent fail — cookie cleanup is best-effort
            $this->oauthUtility->customlog(
                "OAuthLogoutObserver: Error deleting OIDC cookie: " . $e->getMessage()
            );
        }

        // MP-08: prefer per-provider end_session_endpoint when provider_id is known
        $logoutUrl  = '';
        $providerId = (int) $this->customerSession->getData('oidc_provider_id');

        if ($providerId > 0) {
            $provider = $this->oauthUtility->getClientDetailsById($providerId);
            if ($provider !== null && !empty($provider['endsession_endpoint'])) {
                $logoutUrl = (string) $provider['endsession_endpoint'];
                $this->oauthUtility->customlog(
                    "OAuthLogoutObserver: Using per-provider endsession_endpoint"
                    . " for provider_id={$providerId}"
                );
            }
        }

        // Fall back to global store-config logout URL (single-provider / legacy)
        if ($logoutUrl === '') {
            $logoutUrl = (string) $this->oauthUtility->getStoreConfig(OAuthConstants::OAUTH_LOGOUT_URL);
        }

        if ($logoutUrl === '' || !filter_var($logoutUrl, FILTER_VALIDATE_URL)) {
            return;
        }

        if ($this->_response instanceof HttpResponse) {
            $this->_response->setRedirect($logoutUrl);
        }
    }
}
