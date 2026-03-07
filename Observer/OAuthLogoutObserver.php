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
 * Supports Authelia fallback: If the endpoint path ends with /logout
 * (no end_session_endpoint in OIDC discovery), uses /logout?rd=<url>
 * instead of the standard OIDC parameters.
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
     */
    #[\Override]
    public function execute(Observer $observer): void
    {
        // ── Cookie cleanup (best-effort) ──
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

        // ── Read session data ──
        $idToken    = (string) $this->customerSession->getData('oidc_id_token');
        $providerId = (int) $this->customerSession->getData('oidc_provider_id');

        // ── Determine endsession_endpoint ──
        $endSessionEndpoint = '';
        $provider = null;

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

        // ── Build logout URL (Standard OIDC or Authelia fallback) ──
        $postLogoutRedirectUri = $this->resolvePostLogoutRedirectUri($provider);
        $logoutUrl = $this->buildLogoutUrl($endSessionEndpoint, $idToken, $postLogoutRedirectUri);

        // Clear session data before redirect (id_token must not persist)
        $this->customerSession->unsetData('oidc_id_token');

        if ($this->_response instanceof HttpResponse) {
            $this->_response->setRedirect($logoutUrl);
        }
    }

    /**
     * Build the full logout redirect URL.
     *
     * Standard OIDC: end_session_endpoint?id_token_hint=…&post_logout_redirect_uri=…
     * Authelia:       /logout?rd=<url>
     *
     * Detection: If the endpoint path ends with /logout we assume Authelia-style.
     */
    private function buildLogoutUrl(
        string $endSessionEndpoint,
        string $idTokenHint,
        string $postLogoutRedirectUri
    ): string {
        $endpoint = rtrim($endSessionEndpoint, '/');
        $parsedPath = parse_url($endpoint, PHP_URL_PATH) ?? '';

        // Authelia detection: path ends with /logout (not /endsession, /v2/logout etc.)
        if (str_ends_with($parsedPath, '/logout')) {
            $url = $endpoint;
            if ($postLogoutRedirectUri !== '') {
                $url .= '?rd=' . urlencode($postLogoutRedirectUri);
            }
            $this->oauthUtility->customlog(
                "OAuthLogoutObserver: Authelia-style logout → " . $url
            );
            return $url;
        }

        // Standard OIDC RP-Initiated Logout
        $params = [];
        if ($idTokenHint !== '') {
            $params['id_token_hint'] = $idTokenHint;
        }
        if ($postLogoutRedirectUri !== '') {
            $params['post_logout_redirect_uri'] = $postLogoutRedirectUri;
        }

        $separator = (strpos($endpoint, '?') !== false) ? '&' : '?';
        $logoutUrl = $endpoint . (!empty($params) ? $separator . http_build_query($params) : '');

        $this->oauthUtility->customlog(
            "OAuthLogoutObserver: Standard OIDC logout → " . $logoutUrl
        );

        return $logoutUrl;
    }

    /**
     * Resolve post_logout_redirect_uri for customer context.
     *
     * Fallback: 1) provider.post_logout_url  2) Store base URL
     */
    private function resolvePostLogoutRedirectUri(?array $provider): string
    {
        // 1) Explicit value from provider DB row
        if ($provider !== null && !empty($provider['post_logout_url'])) {
            return rtrim((string) $provider['post_logout_url'], '/') . '/';
        }

        // 2) Store base URL (current store context)
        try {
            $baseUrl = $this->storeManager->getStore()->getBaseUrl();
            return rtrim((string) $baseUrl, '/') . '/';
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                "OAuthLogoutObserver: Could not resolve store base URL: " . $e->getMessage()
            );
        }

        return '';
    }
}
