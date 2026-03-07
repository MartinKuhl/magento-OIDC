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
use Magento\Store\Model\StoreManagerInterface;
use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * Observer for customer logout events. Handles RP-Initiated Logout
 * by redirecting to the IdP's end_session_endpoint with id_token_hint
 * and post_logout_redirect_uri parameters.
 *
 * MP-08: Per-provider endsession_endpoint via oidc_provider_id in session.
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

    /** @var StoreManagerInterface */
    private readonly StoreManagerInterface $storeManager;

    /**
     * @param \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility
     * @param ResponseInterface                     $response
     * @param CookieManagerInterface                $cookieManager
     * @param CookieMetadataFactory                 $cookieMetadataFactory
     * @param CustomerSession                       $customerSession
     * @param StoreManagerInterface                 $storeManager
     */
    public function __construct(
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        ResponseInterface $response,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        CustomerSession $customerSession,
        StoreManagerInterface $storeManager
    ) {
        $this->oauthUtility          = $oauthUtility;
        $this->_response             = $response;
        $this->cookieManager         = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->customerSession       = $customerSession;
        $this->storeManager          = $storeManager;
    }

    /**
     * Handle logout event: RP-Initiated Logout redirect to IdP.
     *
     * Reads id_token from customer session, builds the end_session_endpoint
     * URL with id_token_hint and post_logout_redirect_uri, then redirects.
     *
     * @param Observer $observer
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
            $this->oauthUtility->customlog(
                "OAuthLogoutObserver: Error deleting OIDC cookie: " . $e->getMessage()
            );
        }

        // Read id_token and provider_id from customer session (set at login time)
        $idToken    = (string) $this->customerSession->getData('oidc_id_token');
        $providerId = (int) $this->customerSession->getData('oidc_provider_id');

        // Determine endsession_endpoint
        $endSessionEndpoint = '';

        // MP-08: prefer per-provider endsession_endpoint
        if ($providerId > 0) {
            $provider = $this->oauthUtility->getClientDetailsById($providerId);
            if ($provider !== null && !empty($provider['endsession_endpoint'])) {
                $endSessionEndpoint = (string) $provider['endsession_endpoint'];
                $this->oauthUtility->customlog(
                    "OAuthLogoutObserver: Using per-provider endsession_endpoint"
                    . " for provider_id={$providerId}"
                );
            }
        }

        // Fall back to global store-config logout URL (single-provider / legacy)
        if ($endSessionEndpoint === '') {
            $endSessionEndpoint = (string) $this->oauthUtility->getStoreConfig(OAuthConstants::OAUTH_LOGOUT_URL);
        }

        if ($endSessionEndpoint === '' || !filter_var($endSessionEndpoint, FILTER_VALIDATE_URL)) {
            return;
        }

        // Build RP-Initiated Logout URL (OIDC RP-Initiated Logout 1.0)
        $logoutUrl = $endSessionEndpoint;
        $params = [];

        if ($idToken !== '') {
            $params['id_token_hint'] = $idToken;
        }

        // post_logout_redirect_uri = store base URL (customer landing page after IdP logout)
        try {
            $postLogoutRedirectUri = $this->storeManager->getStore()->getBaseUrl();
            $params['post_logout_redirect_uri'] = $postLogoutRedirectUri;
            $this->oauthUtility->customlog(
                "OAuthLogoutObserver: post_logout_redirect_uri=" . $postLogoutRedirectUri
            );
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                "OAuthLogoutObserver: Could not determine store base URL: " . $e->getMessage()
            );
        }

        if (!empty($params)) {
            $separator = (strpos($logoutUrl, '?') !== false) ? '&' : '?';
            $logoutUrl .= $separator . http_build_query($params);
        }

        // Clear session data before redirect (id_token must not persist)
        $this->customerSession->unsetData('oidc_id_token');

        if ($this->_response instanceof HttpResponse) {
            $this->_response->setRedirect($logoutUrl);
        }
    }
}
