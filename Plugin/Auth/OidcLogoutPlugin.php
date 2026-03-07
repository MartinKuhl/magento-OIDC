<?php

/**
 * OIDC Logout Plugin – Admin RP-Initiated Logout
 *
 * Intercepts Magento\Backend\Model\Auth::logout() via aroundLogout:
 *  1. Reads id_token + provider_id from backend session BEFORE destroy.
 *  2. Calls the original logout() (destroys admin session).
 *  3. Deletes the OIDC admin cookie.
 *  4. Sets a short-lived "oidc_logout_guard" cookie to prevent the
 *     AdminLoginRestrictionPlugin from triggering an immediate re-login.
 *  5. Redirects to the IdP end_session_endpoint (RP-Initiated Logout).
 *
 * IMPORTANT: We use aroundLogout (not afterLogout) because afterLogout
 * fires AFTER Auth::logout() has already destroyed the session — at that
 * point oidc_id_token and oidc_provider_id are no longer readable.
 *
 * @package MiniOrange\OAuth\Plugin\Auth
 */

declare(strict_types=1);

namespace MiniOrange\OAuth\Plugin\Auth;

use Magento\Backend\Model\Auth;
use Magento\Backend\Model\Session as BackendSession;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\HTTP\PhpEnvironment\Response as HttpResponse;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthUtility;

class OidcLogoutPlugin
{
    protected CookieManagerInterface $cookieManager;
    protected CookieMetadataFactory $cookieMetadataFactory;
    protected OAuthUtility $oauthUtility;
    protected BackendSession $backendSession;
    protected BackendUrlInterface $backendUrl;
    protected ResponseInterface $response;

    public function __construct(
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        OAuthUtility $oauthUtility,
        BackendSession $backendSession,
        BackendUrlInterface $backendUrl,
        ResponseInterface $response
    ) {
        $this->cookieManager         = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->oauthUtility          = $oauthUtility;
        $this->backendSession        = $backendSession;
        $this->backendUrl            = $backendUrl;
        $this->response              = $response;
    }

    /**
     * Around admin logout: read session data first, then execute logout,
     * then redirect to IdP end_session_endpoint.
     */
    public function aroundLogout(Auth $subject, callable $proceed): void
    {
        // ── 1. Read session data BEFORE session is destroyed ──
        $idToken    = (string) $this->backendSession->getData('oidc_id_token');
        $providerId = (int) $this->backendSession->getData('oidc_provider_id');

        $this->oauthUtility->customlog(sprintf(
            'OidcLogoutPlugin: Pre-logout — id_token=%s, provider_id=%d',
            $idToken !== '' ? 'present(' . strlen($idToken) . ' chars)' : 'MISSING',
            $providerId
        ));

        // ── 2. Execute the original logout (destroys session) ──
        $proceed();

        // ── 3. Delete OIDC admin cookie ──
        try {
            $metadata = $this->cookieMetadataFactory
                ->createPublicCookieMetadata()
                ->setPath('/')
                ->setHttpOnly(true)
                ->setSecure(true)
                ->setSameSite('Lax');
            $this->cookieManager->deleteCookie('oidc_authenticated', $metadata);
            $this->oauthUtility->customlog('OidcLogoutPlugin: OIDC admin cookie deleted');
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                'OidcLogoutPlugin: Error deleting cookie: ' . $e->getMessage()
            );
        }

        // ── 4. Set logout guard cookie (prevents re-login loop) ──
        try {
            $guardMeta = $this->cookieMetadataFactory
                ->createPublicCookieMetadata()
                ->setPath('/')
                ->setDuration(120)
                ->setHttpOnly(true)
                ->setSecure(true)
                ->setSameSite('Lax');
            $this->cookieManager->setPublicCookie('oidc_logout_guard', '1', $guardMeta);
            $this->oauthUtility->customlog('OidcLogoutPlugin: Logout guard cookie set');
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                'OidcLogoutPlugin: Error setting guard cookie: ' . $e->getMessage()
            );
        }

        // ── 5. Resolve provider and end_session_endpoint ──
        $provider = null;
        $endSessionEndpoint = '';

        if ($providerId > 0) {
            $provider = $this->oauthUtility->getClientDetailsById($providerId);
            if ($provider !== null && !empty($provider['endsession_endpoint'])) {
                $endSessionEndpoint = (string) $provider['endsession_endpoint'];
            }
        }

        // Fallback: global store-config logout URL
        if ($endSessionEndpoint === '') {
            $endSessionEndpoint = (string) $this->oauthUtility
                ->getStoreConfig(OAuthConstants::OAUTH_LOGOUT_URL);
        }

        // No valid endpoint → let Magento handle the redirect to admin login
        if ($endSessionEndpoint === '' || !filter_var($endSessionEndpoint, FILTER_VALIDATE_URL)) {
            $this->oauthUtility->customlog(
                'OidcLogoutPlugin: No valid end_session_endpoint — standard admin logout.'
            );
            return;
        }

        // ── 6. Build RP-Initiated Logout URL ──
        $params = [];

        if ($idToken !== '') {
            $params['id_token_hint'] = $idToken;
        }

        $params['state'] = bin2hex(random_bytes(16));

        $postLogoutUri = $this->resolvePostLogoutRedirectUri($provider);
        if ($postLogoutUri !== '') {
            $params['post_logout_redirect_uri'] = $postLogoutUri;
        }

        $separator = (strpos($endSessionEndpoint, '?') !== false) ? '&' : '?';
        $logoutUrl = $endSessionEndpoint . $separator . http_build_query($params);

        $this->oauthUtility->customlog(sprintf(
            'OidcLogoutPlugin: Redirecting to IdP — endpoint=%s, post_logout_redirect_uri=%s',
            $endSessionEndpoint,
            $postLogoutUri ?: '(none)'
        ));

        // ── 7. Redirect to IdP ──
        if ($this->response instanceof HttpResponse) {
            $this->response->setRedirect($logoutUrl);
            $this->response->sendResponse();
            // Hard exit: prevent Magento from overriding the redirect
            // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
            exit;
        }
    }

    /**
     * Resolve post_logout_redirect_uri for Admin context.
     *
     * Order: 1) provider.post_logout_url  2) Admin base URL
     */
    private function resolvePostLogoutRedirectUri(?array $provider): string
    {
        // 1) Explicit value from provider DB row
        if ($provider !== null && !empty($provider['post_logout_url'])) {
            return rtrim((string) $provider['post_logout_url'], '/') . '/';
        }

        // 2) Admin base URL
        try {
            $adminBaseUrl = $this->backendUrl->getBaseUrl();
            if (!empty($adminBaseUrl) && filter_var($adminBaseUrl, FILTER_VALIDATE_URL)) {
                return rtrim($adminBaseUrl, '/') . '/';
            }
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                'OidcLogoutPlugin: Could not resolve admin base URL: ' . $e->getMessage()
            );
        }

        return '';
    }
}
