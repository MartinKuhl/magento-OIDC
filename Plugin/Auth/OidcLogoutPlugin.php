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
 * @package M2Oidc\OAuth\Plugin\Auth
 */

declare(strict_types=1);

namespace M2Oidc\OAuth\Plugin\Auth;

use Magento\Backend\Model\Auth;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\HTTP\Adapter\CurlFactory;
use Magento\Framework\HTTP\PhpEnvironment\Response as HttpResponse;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthUtility;
use Magento\Backend\App\Area\FrontNameResolver;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\UrlInterface;

class OidcLogoutPlugin
{
    /** @var CookieManagerInterface */
    protected CookieManagerInterface $cookieManager;

    /** @var CookieMetadataFactory */
    protected CookieMetadataFactory $cookieMetadataFactory;

    /** @var OAuthUtility */
    protected OAuthUtility $oauthUtility;

    /** @var AuthSession */
    protected AuthSession $authSession;

    /** @var BackendUrlInterface */
    protected BackendUrlInterface $backendUrl;

    /** @var ResponseInterface */
    protected ResponseInterface $response;

    /** @var FrontNameResolver */
    private readonly FrontNameResolver $frontNameResolver;

    /** @var CurlFactory */
    private readonly CurlFactory $curlFactory;

    /** @var UrlInterface */
    private readonly UrlInterface $url;

    /** @var ResourceConnection */
    private readonly ResourceConnection $resourceConnection;

    /**
     * Initialize OIDC logout plugin.
     *
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory  $cookieMetadataFactory
     * @param OAuthUtility           $oauthUtility
     * @param AuthSession            $authSession
     * @param BackendUrlInterface    $backendUrl
     * @param ResponseInterface      $response
     * @param FrontNameResolver      $frontNameResolver
     * @param CurlFactory            $curlFactory
     * @param UrlInterface           $url
     * @param ResourceConnection     $resourceConnection
     */
    public function __construct(
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        OAuthUtility $oauthUtility,
        AuthSession $authSession,
        BackendUrlInterface $backendUrl,
        ResponseInterface $response,
        FrontNameResolver $frontNameResolver,
        CurlFactory $curlFactory,
        UrlInterface $url,
        ResourceConnection $resourceConnection
    ) {
        $this->cookieManager         = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->oauthUtility          = $oauthUtility;
        $this->authSession           = $authSession;
        $this->backendUrl            = $backendUrl;
        $this->response              = $response;
        $this->frontNameResolver     = $frontNameResolver;
        $this->curlFactory           = $curlFactory;
        $this->url                   = $url;
        $this->resourceConnection    = $resourceConnection;
    }

    /**
     * Around admin logout: read session data first, then execute logout, then redirect to IdP end_session_endpoint.
     *
     * @param Auth     $subject The Auth model being intercepted
     * @param callable $proceed The original logout callable
     */
    public function aroundLogout(Auth $subject, callable $proceed): void
    {
        // ── 1. Read session data BEFORE session is destroyed ──
        $idToken     = (string) $this->authSession->getData('oidc_id_token');
        $accessToken = (string) $this->authSession->getData('oidc_access_token');
        $providerId  = (int) $this->authSession->getData('oidc_provider_id');
        $userId      = (int) ($this->authSession->getUser()?->getId() ?? 0);

        $this->oauthUtility->customlog(sprintf(
            'OidcLogoutPlugin: Pre-logout — id_token=%s, provider_id=%d, user_id=%d',
            $idToken !== '' ? 'present(' . strlen($idToken) . ' chars)' : 'MISSING',
            $providerId,
            $userId
        ));

        // ── Guard: skip IdP redirect for non-OIDC sessions ──────────────────
        // Only call the end_session_endpoint when the admin actually authenticated
        // via OIDC. Without this guard, the global store-config fallback URL would
        // redirect even regular (non-OIDC) admin logouts to the IdP, and the
        // oidc_logout_guard cookie would be set unnecessarily.
        if ($idToken === '' && $providerId === 0) {
            $this->oauthUtility->customlog(
                'OidcLogoutPlugin: Non-OIDC session — skipping IdP end_session_endpoint.'
            );
            $proceed();
            return;
        }

        // ── 2. Execute the original logout (destroys session) ──
        $proceed();

        // ── 2b. Explicitly mark admin_user_session as LOGGED_OUT ──
        // Magento Security's afterLogout plugin uses session_id() to find the row, but
        // Auth::logout() destroys/regenerates the session before afterLogout fires, so
        // session_id() may be empty by then and the status update silently fails.
        // We do a direct UPDATE by user_id to guarantee the row is cleared.
        if ($userId > 0) {
            try {
                $connection = $this->resourceConnection->getConnection();
                $connection->update(
                    $this->resourceConnection->getTableName('admin_user_session'),
                    ['status' => 0], // AdminSessionInfo::LOGGED_OUT
                    ['user_id = ?' => $userId, 'status = ?' => 1]
                );
                $this->oauthUtility->customlog(
                    'OidcLogoutPlugin: Marked admin_user_session LOGGED_OUT for user_id=' . $userId
                );
            } catch (\Exception $e) {
                $this->oauthUtility->customlog(
                    'OidcLogoutPlugin: Failed to update admin_user_session status: ' . $e->getMessage()
                );
            }
        }

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

        // ── 5b. RFC 7009 token revocation (non-fatal, fire-and-forget) ──
        if ($provider !== null && !empty($provider['revocation_endpoint']) && $accessToken !== '') {
            $this->revokeToken(
                (string) $provider['revocation_endpoint'],
                $accessToken,
                (string) ($provider['clientID'] ?? ''),
                (string) ($provider['client_secret'] ?? '')
            );
        }

        // ── 6. Build Logout URL ──
        // Zwei Modi:
        //  a) OIDC end_session_endpoint → id_token_hint + post_logout_redirect_uri
        //  b) Authelia Forward-Auth /logout → "rd" mit STATISCHER URL.
        //     NIEMALS die aktuelle Request-URL verwenden — sie enthält einen
        //     dynamischen key/-Token, der nicht als redirect_uri registriert werden kann.
        $isForwardAuthLogout = $this->isAutheliaForwardAuthLogout($endSessionEndpoint);
        $postLogoutUri       = $this->resolvePostLogoutRedirectUri($provider, $isForwardAuthLogout);
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
            $params['state'] = 'admin:' . bin2hex(random_bytes(16));
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
            $postLogoutUri !== '' ? $postLogoutUri : '(none)'
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
     * Order: 1) provider.post_logout_url  2) Unified callback URL (standard OIDC)
     *
     * The unified callback URL (m2oidc/actions/postlogout) allows providers that
     * only accept a single registered Post Logout Redirect URI to serve both the
     * admin and customer logout flows. The context is carried in the `state`
     * parameter that the IdP echoes back verbatim.
     *
     * For Authelia Forward-Auth mode the caller uses the `rd` parameter instead
     * of post_logout_redirect_uri, so this method returns the static admin base
     * URL when $isForwardAuth is true (no dynamic tokens, safe to register).
     *
     * @param array|null $provider       Provider data array or null
     * @param bool       $isForwardAuth  True when the endpoint is Authelia-style
     */
    private function resolvePostLogoutRedirectUri(?array $provider, bool $isForwardAuth = false): string
    {
        if ($provider !== null && !empty($provider['post_logout_url'])) {
            return rtrim((string) $provider['post_logout_url'], '/') . '/';
        }

        // Authelia uses ?rd=<url> — must be the static admin base URL, not the callback.
        if ($isForwardAuth) {
            try {
                // Reads admin front name from DB config — same as bin/magento info:adminuri
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

        // Standard OIDC: build the frontend callback URL from the store base URL.
        // Do NOT use $this->url->getUrl() here — in the admin area that resolves to
        // Magento\Backend\Model\Url, which prepends /admin/ and appends a dynamic
        // CSRF secret key (/key/<hex>). The resulting URL changes every session and
        // cannot be registered as an allowed Post Logout Redirect URI at the IdP.
        $baseUrl = rtrim($this->backendUrl->getBaseUrl(), '/');
        return $baseUrl . '/m2oidc/actions/postlogout';
    }

    /**
     * Erkennt Authelia's Forward-Auth /logout-Endpoint.
     *
     * Heuristik: Pfad endet auf /logout, enthält aber kein /oauth2/ oder /oidc/.
     * Damit wird zwischen Authelia-Forward-Auth und echtem OIDC end_session_endpoint
     * unterschieden.
     *
     * @param string $endpoint The end session endpoint URL to check
     */
    private function isAutheliaForwardAuthLogout(string $endpoint): bool
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $path = (string) parse_url($endpoint, PHP_URL_PATH);
        return str_ends_with(rtrim($path, '/'), '/logout')
            && !str_contains($path, '/oauth2/')
            && !str_contains($path, '/oidc/');
    }

    /**
     * Call the RFC 7009 token revocation endpoint (fire-and-forget).
     *
     * Failure is non-fatal: we log and continue the logout flow.
     *
     * @param string $revocationEndpoint
     * @param string $accessToken
     * @param string $clientId
     * @param string $clientSecret  Plaintext secret (already decrypted by getClientDetailsById)
     */
    private function revokeToken(
        string $revocationEndpoint,
        string $accessToken,
        string $clientId,
        string $clientSecret
    ): void {
        try {
            $curl = $this->curlFactory->create();
            $curl->setConfig(['timeout' => 5]);
            $curl->write(
                'POST',
                $revocationEndpoint,
                '1.1',
                ['Content-Type: application/x-www-form-urlencoded'],
                http_build_query([
                    'token'           => $accessToken,
                    'token_type_hint' => 'access_token',
                    'client_id'       => $clientId,
                    'client_secret'   => $clientSecret,
                ])
            );
            $curl->read();
            $curl->close();
            $this->oauthUtility->customlog(
                'OidcLogoutPlugin: Token revocation request sent to ' . $revocationEndpoint
            );
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                'OidcLogoutPlugin: Token revocation failed (non-fatal): ' . $e->getMessage()
            );
        }
    }
}
