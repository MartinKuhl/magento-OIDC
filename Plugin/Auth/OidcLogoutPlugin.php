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
 *  5. Redirects to the IdP end_session_endpoint (RP-Initiated Logout)
 *     or /logout?rd= (Authelia fallback).
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
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\HTTP\PhpEnvironment\Response as HttpResponse;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthUtility;
use Magento\Backend\App\Area\FrontNameResolver;

class OidcLogoutPlugin
{
    protected CookieManagerInterface $cookieManager;
    protected CookieMetadataFactory $cookieMetadataFactory;
    protected OAuthUtility $oauthUtility;
    protected AuthSession $authSession;
    protected BackendUrlInterface $backendUrl;
    protected ResponseInterface $response;
    private readonly FrontNameResolver $frontNameResolver;

    public function __construct(
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        OAuthUtility $oauthUtility,
        AuthSession $authSession,
        BackendUrlInterface $backendUrl,
        ResponseInterface $response,
        FrontNameResolver $frontNameResolver
    ) {
        $this->cookieManager         = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->oauthUtility          = $oauthUtility;
        $this->authSession           = $authSession;
        $this->backendUrl            = $backendUrl;
        $this->response              = $response;
        $this->frontNameResolver     = $frontNameResolver;
    }

    /**
     * Around admin logout: read session data first, then execute logout,
     * then redirect to IdP end_session_endpoint.
     */
    public function aroundLogout(Auth $subject, callable $proceed): void
    {
        // ── 1. Read session data BEFORE session is destroyed ──
        $idToken    = (string) $this->authSession->getData('oidc_id_token');
        $providerId = (int) $this->authSession->getData('oidc_provider_id');

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
        $provider           = null;
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

        // ── 6. Build Logout URL ──
        // Zwei Modi:
        //  a) OIDC end_session_endpoint → id_token_hint + post_logout_redirect_uri
        //  b) Authelia Forward-Auth /logout → "rd" mit STATISCHER URL.
        //     NIEMALS die aktuelle Request-URL verwenden — sie enthält einen
        //     dynamischen key/-Token, der nicht als redirect_uri registriert werden kann.
        $isForwardAuthLogout = $this->isAutheliaForwardAuthLogout($endSessionEndpoint);
        $postLogoutUri       = $this->resolvePostLogoutRedirectUri($provider);
        $params              = [];

        if ($isForwardAuthLogout) {
            // Authelia Forward-Auth: "rd" = statische Admin-Base-URL
            if ($postLogoutUri !== '') {
                $params['rd'] = $postLogoutUri;
            }
        } else {
            // Standard OIDC RP-Initiated Logout
            if ($idToken !== '') {
                $params['id_token_hint'] = $idToken;
            }
            $params['state'] = bin2hex(random_bytes(16));
            if ($postLogoutUri !== '') {
                $params['post_logout_redirect_uri'] = $postLogoutUri;
            }
        }

        $separator = (strpos($endSessionEndpoint, '?') !== false) ? '&' : '?';
        $logoutUrl = $endSessionEndpoint . ($params !== [] ? $separator . http_build_query($params) : '');

        $this->oauthUtility->customlog(sprintf(
            'OidcLogoutPlugin: Redirecting to IdP — mode=%s, endpoint=%s, redirect=%s',
            $isForwardAuthLogout ? 'forward-auth(rd)' : 'oidc-rp-logout',
            $endSessionEndpoint,
            $postLogoutUri ?: '(none)'
        ));

        // ── 7. Redirect to IdP ──
        if ($this->response instanceof HttpResponse) {
            $this->response->setRedirect($logoutUrl);
            $this->response->sendResponse();
            // Prevent any further Magento output / redirect overrides
            exit; // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
        }
    }

    /**
     * Resolve post_logout_redirect_uri for Admin context.
     *
     * Order: 1) provider.post_logout_url  2) Admin base URL (static, no key/token!)
     *
     * IMPORTANT: Do NOT use getUrl('adminhtml/auth/login') here — it generates
     * a URL with a dynamic key/-token that cannot be registered as a
     * post_logout_redirect_uri or rd value in Authelia.
     */
    private function resolvePostLogoutRedirectUri(?array $provider): string
    {
        if ($provider !== null && !empty($provider['post_logout_url'])) {
            return rtrim((string) $provider['post_logout_url'], '/') . '/';
        }

        try {
            // Liest Admin-Frontnamen aus DB-Config — identisch zu bin/magento info:adminuri
            $frontName = rtrim($this->frontNameResolver->getFrontName(true), '/');
            $baseUrl   = rtrim($this->backendUrl->getBaseUrl(), '/');
            $adminUrl  = $baseUrl . '/' . $frontName . '/';

            if (filter_var($adminUrl, FILTER_VALIDATE_URL)) {
                return $adminUrl;
            }
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                'OidcLogoutPlugin: Could not resolve admin URL: ' . $e->getMessage()
            );
        }

        return '';
    }

    /**
     * Erkennt Authelia's Forward-Auth /logout-Endpoint.
     *
     * Heuristik: Pfad endet auf /logout, enthält aber kein /oauth2/ oder /oidc/.
     * Damit wird zwischen Authelia-Forward-Auth und echtem OIDC end_session_endpoint
     * unterschieden.
     */
    private function isAutheliaForwardAuthLogout(string $endpoint): bool
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $path = (string) parse_url($endpoint, PHP_URL_PATH);
        return str_ends_with(rtrim($path, '/'), '/logout')
            && !str_contains($path, '/oauth2/')
            && !str_contains($path, '/oidc/');
    }
}
