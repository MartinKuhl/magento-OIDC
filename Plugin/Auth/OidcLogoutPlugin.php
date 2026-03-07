<?php

/**
 * OIDC Logout Plugin – Admin RP-Initiated Logout
 *
 * Intercepts Magento\Backend\Model\Auth::logout() and:
 *  1. Deletes the OIDC admin cookie.
 *  2. Reads the persisted id_token from the backend session.
 *  3. Resolves the end_session_endpoint from the last-used OIDC provider.
 *  4a. If a valid end_session_endpoint is configured: builds the RP-Initiated
 *      Logout URL (id_token_hint + state + post_logout_redirect_uri) and
 *      redirects the browser to the IdP.
 *  4b. If NO end_session_endpoint is configured: Magento's native admin logout
 *      has already run (afterLogout fires after Auth::logout()), so the admin
 *      session is already destroyed. Return gracefully and let Magento redirect
 *      to the admin login page as usual.
 *
 * post_logout_redirect_uri resolution order (Admin context):
 *  1. provider.post_logout_url  (DB column, if set)
 *  2. Admin base URL            (BackendUrl, trailing slash normalised)
 *
 * IMPORTANT – Authelia validates post_logout_redirect_uri against the
 * client's redirect_uris list (exact string match incl. trailing slash).
 * Register every URI that can be produced here in Authelia's redirect_uris.
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
    /** @var CookieManagerInterface */
    protected CookieManagerInterface $cookieManager;

    /** @var CookieMetadataFactory */
    protected CookieMetadataFactory $cookieMetadataFactory;

    /** @var OAuthUtility */
    protected OAuthUtility $oauthUtility;

    /** @var BackendSession */
    protected BackendSession $backendSession;

    /** @var BackendUrlInterface */
    protected BackendUrlInterface $backendUrl;

    /** @var ResponseInterface */
    protected ResponseInterface $response;

    /**
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory  $cookieMetadataFactory
     * @param OAuthUtility           $oauthUtility
     * @param BackendSession         $backendSession
     * @param BackendUrlInterface    $backendUrl
     * @param ResponseInterface      $response
     */
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
     * After admin logout: delete OIDC cookie and trigger RP-Initiated Logout
     * (or fall back to standard Magento admin logout if no IdP is configured).
     *
     * @param  Auth  $subject
     * @param  mixed $result
     * @return mixed
     */
    public function afterLogout(Auth $subject, $result)
    {
        // --- Cookie cleanup ---
        try {
            $metadata = $this->cookieMetadataFactory
                ->createPublicCookieMetadata()
                ->setPath('/')
                ->setHttpOnly(true)
                ->setSecure(true)
                ->setSameSite('Lax');
            $this->cookieManager->deleteCookie('oidc_authenticated', $metadata);
            $this->oauthUtility->customlog("OidcLogoutPlugin: OIDC admin cookie deleted");
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                "OidcLogoutPlugin: Error deleting cookie: " . $e->getMessage()
            );
        }

        // --- Resolve provider and end_session_endpoint ---
        $providerId = (int) $this->backendSession->getData('oidc_provider_id');
        $provider   = null;
        $endSessionEndpoint = '';

        if ($providerId > 0) {
            $provider = $this->oauthUtility->getClientDetailsById($providerId);
            if ($provider !== null && !empty($provider['endsession_endpoint'])) {
                $endSessionEndpoint = (string) $provider['endsession_endpoint'];
                $this->oauthUtility->customlog(
                    "OidcLogoutPlugin: Using endsession_endpoint for provider_id={$providerId}"
                );
            }
        }

        // Fallback: global store-config logout URL
        if ($endSessionEndpoint === '') {
            $endSessionEndpoint = (string) $this->oauthUtility->getStoreConfig(OAuthConstants::OAUTH_LOGOUT_URL);
        }

        // --- No valid end_session_endpoint: use standard Magento admin logout ---
        // afterLogout fires *after* Auth::logout() has already destroyed the admin
        // session. Returning $result here lets Magento redirect to the admin login
        // page as usual — no additional action required.
        if ($endSessionEndpoint === '' || !filter_var($endSessionEndpoint, FILTER_VALIDATE_URL)) {
            $this->oauthUtility->customlog(
                'OidcLogoutPlugin: No valid end_session_endpoint configured. '
                . 'Falling back to standard Magento admin logout (already completed).'
            );
            return $result;
        }

        // --- Build RP-Initiated Logout URL ---
        $params = $this->buildLogoutParams($provider);

        $separator = (strpos($endSessionEndpoint, '?') !== false) ? '&' : '?';
        $logoutUrl = $endSessionEndpoint . $separator . http_build_query($params);

        $this->oauthUtility->customlog(
            "OidcLogoutPlugin: Redirecting admin to IdP end_session_endpoint. "
            . "post_logout_redirect_uri=" . ($params['post_logout_redirect_uri'] ?? '(none)')
        );

        if ($this->response instanceof HttpResponse) {
            $this->response->setRedirect($logoutUrl);
            $this->response->sendResponse();
        }

        return $result;
    }

    /**
     * Build the query parameters for the end_session_endpoint request.
     *
     * @param  array<string,mixed>|null $provider
     * @return array<string,string>
     */
    private function buildLogoutParams(?array $provider): array
    {
        $params = [];

        // id_token_hint: required by most IdPs to identify the session
        $idToken = (string) $this->backendSession->getData('oidc_id_token');
        if ($idToken !== '') {
            $params['id_token_hint'] = $idToken;
        }

        // state: opaque CSRF protection value
        $params['state'] = bin2hex(random_bytes(16));

        // post_logout_redirect_uri (Admin context)
        $postLogoutUri = $this->resolvePostLogoutRedirectUri($provider);
        if ($postLogoutUri !== '') {
            $params['post_logout_redirect_uri'] = $postLogoutUri;
        }

        return $params;
    }

    /**
     * Resolve the post_logout_redirect_uri for the Admin (backend) context.
     *
     * Resolution order:
     *  1. provider.post_logout_url  (DB column)
     *  2. Admin base URL            (BackendUrlInterface, trailing slash normalised)
     *
     * @param  array<string,mixed>|null $provider
     * @return string  Empty string when no URI can be determined.
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
                "OidcLogoutPlugin: Could not resolve admin base URL: " . $e->getMessage()
            );
        }

        return '';
    }
}