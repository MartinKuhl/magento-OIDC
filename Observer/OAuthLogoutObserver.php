<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Observer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\PhpEnvironment\Response as HttpResponse;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Model\Service\RpInitiatedLogoutService;

/**
 * Observer for customer logout events. Handles RP-Initiated Logout
 * by redirecting to the IdP's end_final session_endpoint with id_token_hint
 * and post_logout_redirect_uri parameters.
 *
 * Supports Authelia fallback: If the endpoint path ends with /logout
 * (no end_session_endpoint in OIDC discovery), uses /logout?rd=<url>
 * instead of the standard OIDC parameters.
 *
 * Uses the per-provider endsession_endpoint via oidc_provider_id in session.
 * Sets oidc_logout_guard cookie before redirect so that
 *        CustomerLoginAutoRedirectObserver suppresses the auto-redirect
 *        on the returning login page (cookie survives session destruction).
 */
class OAuthLogoutObserver implements ObserverInterface
{
    /** Logout-guard cookie name — must match CustomerLoginAutoRedirectObserver
     * @var string */
    private const LOGOUT_GUARD_COOKIE = 'oidc_logout_guard';

    /** @var \M2Oidc\OAuth\Helper\OAuthUtility */
    private readonly \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility;

    /** @var ResponseInterface */
    protected ResponseInterface $_response;

    /** @var CookieManagerInterface */
    private readonly CookieManagerInterface $cookieManager;

    /** @var CookieMetadataFactory */
    private readonly CookieMetadataFactory $cookieMetadataFactory;

    /** @var CustomerSession */
    private readonly CustomerSession $customerSession;

    /** @var UrlInterface */
    private readonly UrlInterface $url;

    /** @var RpInitiatedLogoutService */
    private readonly RpInitiatedLogoutService $rpInitiatedLogoutService;

    /**
     * @param \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility
     * @param ResponseInterface                 $response
     * @param CookieManagerInterface            $cookieManager
     * @param CookieMetadataFactory             $cookieMetadataFactory
     * @param CustomerSession                   $customerSession
     * @param UrlInterface                      $url
     * @param RpInitiatedLogoutService          $rpInitiatedLogoutService
     */
    public function __construct(
        \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility,
        ResponseInterface $response,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        CustomerSession $customerSession,
        UrlInterface $url,
        RpInitiatedLogoutService $rpInitiatedLogoutService
    ) {
        $this->oauthUtility             = $oauthUtility;
        $this->_response                = $response;
        $this->cookieManager            = $cookieManager;
        $this->cookieMetadataFactory    = $cookieMetadataFactory;
        $this->customerSession          = $customerSession;
        $this->url                      = $url;
        $this->rpInitiatedLogoutService = $rpInitiatedLogoutService;
    }

    /**
     * Handle logout event: RP-Initiated Logout redirect to IdP.
     *
     * Flow:
     *  1. Delete OIDC auth cookie (best-effort).
     *  2. Read id_token + provider_id from customer session.
     *  3. Resolve endsession_endpoint (per-provider → store-config fallback).
     *  4. Build logout URL (Authelia-style or standard OIDC).
     *  5. Set oidc_logout_guard cookie so auto-redirect is suppressed on return.
     *  6. Clear id_token from session and redirect.
     *
     * @param Observer $observer
     */
    #[\Override]
    public function execute(Observer $observer): void
    {
        // ── 1. Cookie cleanup (best-effort) ──────────────────────────────────
        try {
            $oidcCookie = $this->cookieManager->getCookie('oidc_customer_authenticated');
            if ($oidcCookie !== null) {
                $deleteMeta = $this->cookieMetadataFactory
                    ->createPublicCookieMetadata()
                    ->setPath('/')
                    ->setHttpOnly(true)
                    ->setSecure(true);
                $this->cookieManager->deleteCookie('oidc_customer_authenticated', $deleteMeta);
                $this->oauthUtility->customlog('OAuthLogoutObserver: OIDC customer cookie deleted');
            }
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                'OAuthLogoutObserver: Error deleting OIDC cookie: ' . $e->getMessage()
            );
        }

        // ── 2. Read session data ──────────────────────────────────────────────
        $idToken    = (string) $this->customerSession->getData('oidc_id_token');
        $providerId = (int)    $this->customerSession->getData('oidc_provider_id');

        // ── Guard: skip IdP redirect for non-OIDC sessions ───────────────────
        // Only call the end_session_endpoint when the customer actually authenticated
        // via OIDC. Without this guard, the global store-config fallback URL would
        // redirect even regular (non-OIDC) customer logouts to the IdP.
        if ($idToken === '' && $providerId === 0) {
            $this->oauthUtility->customlog(
                'OAuthLogoutObserver: Non-OIDC session — skipping IdP end_session_endpoint.'
            );
            return;
        }

        // ── 3. Determine endsession_endpoint ─────────────────────────────────
        $endSessionEndpoint = '';
        $provider           = null;

        // Prefer per-provider endsession_endpoint
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

        // ── 3b. RFC 7009 token revocation (non-fatal, fire-and-forget) ───────────
        $accessToken = (string) $this->customerSession->getData('oidc_access_token');
        $this->rpInitiatedLogoutService->revokeToken($provider, $accessToken, 'OAuthLogoutObserver');

        // ── 4. Build logout URL ───────────────────────────────────────────────
        $isForwardAuth         = $this->rpInitiatedLogoutService->isAutheliaForwardAuthLogout($endSessionEndpoint);
        $postLogoutRedirectUri = $this->rpInitiatedLogoutService->resolvePostLogoutRedirectUri(
            $provider,
            $this->resolveFallbackPostLogoutUri($isForwardAuth)
        );
        $logoutUrl             = $this->rpInitiatedLogoutService->buildLogoutUrl(
            $endSessionEndpoint,
            $idToken,
            'customer:' . bin2hex(random_bytes(16)),
            $postLogoutRedirectUri
        );
        $this->oauthUtility->customlog(
            $isForwardAuth
                ? 'OAuthLogoutObserver: Authelia-style logout → ' . $logoutUrl
                : 'OAuthLogoutObserver: Standard OIDC logout → ' . $logoutUrl
        );

        // ── 5. Set logout-guard cookie (survives session destruction) ─────────
        // Prevents CustomerLoginAutoRedirectObserver from triggering a new
        // OIDC authorization request when Authelia redirects back to /customer/account/login/
        try {
            $guardMeta = $this->cookieMetadataFactory
                ->createPublicCookieMetadata()
                ->setPath('/')
                ->setHttpOnly(false)  // must be readable server-side by the observer
                ->setSecure(true)
                ->setDuration(300);   // 5 minutes — enough to survive the IdP round-trip

            $this->cookieManager->setPublicCookie(self::LOGOUT_GUARD_COOKIE, '1', $guardMeta);
            $this->oauthUtility->customlog('OAuthLogoutObserver: oidc_logout_guard cookie set');
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                'OAuthLogoutObserver: Could not set logout guard cookie: ' . $e->getMessage()
            );
        }

        // ── 6. Clear id_token from session and redirect ───────────────────────
        $this->customerSession->unsetData('oidc_id_token');

        if ($this->_response instanceof HttpResponse) {
            $this->_response->setRedirect($logoutUrl);

            // Force-send the redirect immediately.
            // Without this, Magento's LogoutController overwrites our Location header
            // with its own redirect (to the homepage) after the observer returns.
            $this->_response->sendResponse();

            return;
        }
    }

    /**
     * Resolve the customer-context fallback post_logout_redirect_uri.
     *
     * The per-provider post_logout_url override is applied on top of this by
     * RpInitiatedLogoutService::resolvePostLogoutRedirectUri().
     *
     * Priority:
     *  1) Unified callback URL (standard OIDC) — allows one registered URI for both flows
     *  2) Customer login page URL (Authelia forward-auth only, used in ?rd= parameter)
     *
     * @param bool $isForwardAuth True when the endpoint is Authelia-style
     */
    private function resolveFallbackPostLogoutUri(bool $isForwardAuth): string
    {
        // Authelia uses ?rd=<url> — must be the customer login page, not the callback.
        if ($isForwardAuth) {
            try {
                $loginUrl = $this->url->getUrl('customer/account/login');
                // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
                $parsed   = parse_url($loginUrl);
                $hasScheme = isset($parsed['scheme']) && $parsed['scheme'] !== '' && $parsed['scheme'] !== '0';
                if ($hasScheme && !empty($parsed['host'])) {
                    $path = rtrim($parsed['path'] ?? '', '/') . '/';
                    return $parsed['scheme'] . '://' . $parsed['host'] . $path;
                }
            } catch (\Exception $e) {
                $this->oauthUtility->customlog(
                    'OAuthLogoutObserver: Could not resolve customer login URL: ' . $e->getMessage()
                );
            }

            return '';
        }

        // Standard OIDC: unified callback so one URL covers both admin and customer flows.
        return rtrim($this->url->getUrl('m2oidc/actions/postlogout'), '/');
    }
}
