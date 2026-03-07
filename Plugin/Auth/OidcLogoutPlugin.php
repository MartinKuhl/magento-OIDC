<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Plugin\Auth;

use Magento\Backend\Model\Auth;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\HTTP\PhpEnvironment\Response as HttpResponse;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use MiniOrange\OAuth\Helper\OAuthUtility;

/**
 * Plugin for admin logout: RP-Initiated Logout.
 *
 * Cleans up OIDC cookies and redirects to the IdP's end_session_endpoint
 * with id_token_hint and post_logout_redirect_uri for admin users.
 */
class OidcLogoutPlugin
{
    private const OIDC_AUTH_COOKIE = 'oidc_authenticated';
    private const LOGOUT_COOKIE_NAME = 'oidc_admin_just_logged_out';

    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory  $cookieMetadataFactory,
        private readonly OAuthUtility           $oauthUtility,
        private readonly AdminSession           $adminSession,
        private readonly ResponseInterface      $response,
        private readonly BackendUrlInterface    $backendUrl
    ) {
    }

    /**
     * After admin logout: RP-Initiated Logout redirect to IdP.
     *
     * Reads id_token and provider_id from admin session before it is destroyed,
     * then redirects to endsession_endpoint with id_token_hint.
     */
    public function beforeLogout(Auth $subject): void
    {
        // Capture id_token and provider_id BEFORE session is destroyed
        $this->capturedIdToken    = (string) $this->adminSession->getData('oidc_id_token');
        $this->capturedProviderId = (int) $this->adminSession->getData('oidc_provider_id');
    }

    /** @var string */
    private string $capturedIdToken = '';

    /** @var int */
    private int $capturedProviderId = 0;

    public function afterLogout(Auth $subject): void
    {
        // 1. Delete the OIDC auth cookie
        $deleteMeta = $this->cookieMetadataFactory
            ->createPublicCookieMetadata()
            ->setPath('/');

        try {
            $this->cookieManager->deleteCookie(self::OIDC_AUTH_COOKIE, $deleteMeta);
            $this->oauthUtility->customlog('OidcLogoutPlugin: OIDC cookie deleted');
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                'OidcLogoutPlugin: Error deleting OIDC cookie: ' . $e->getMessage()
            );
        }

        // 2. Set logout guard cookie to suppress auto-redirect
        try {
            $guardMeta = $this->cookieMetadataFactory
                ->createPublicCookieMetadata()
                ->setDuration(120)
                ->setPath('/')
                ->setHttpOnly(true)
                ->setSameSite('Lax');

            $this->cookieManager->setPublicCookie(
                self::LOGOUT_COOKIE_NAME,
                '1',
                $guardMeta
            );
            $this->oauthUtility->customlog('OidcLogoutPlugin: Logout guard cookie set');
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                'OidcLogoutPlugin: Error setting logout cookie: ' . $e->getMessage()
            );
        }

        // 3. RP-Initiated Logout: redirect to IdP endsession_endpoint
        $endSessionEndpoint = '';
        $idToken    = $this->capturedIdToken;
        $providerId = $this->capturedProviderId;

        if ($providerId > 0) {
            $provider = $this->oauthUtility->getClientDetailsById($providerId);
            if ($provider !== null && !empty($provider['endsession_endpoint'])) {
                $endSessionEndpoint = (string) $provider['endsession_endpoint'];
                $this->oauthUtility->customlog(
                    "OidcLogoutPlugin: Using endsession_endpoint for provider_id={$providerId}"
                );
            }
        }

        if ($endSessionEndpoint === '') {
            $endSessionEndpoint = (string) $this->oauthUtility->getStoreConfig(
                \MiniOrange\OAuth\Helper\OAuthConstants::OAUTH_LOGOUT_URL
            );
        }

        if ($endSessionEndpoint === '' || !filter_var($endSessionEndpoint, FILTER_VALIDATE_URL)) {
            return;
        }

        // Build RP-Initiated Logout URL
        $params = [];
        if ($idToken !== '') {
            $params['id_token_hint'] = $idToken;
        }

        // post_logout_redirect_uri = admin login page
        $postLogoutRedirectUri = $this->backendUrl->getUrl('adminhtml/auth/login');
        $params['post_logout_redirect_uri'] = $postLogoutRedirectUri;
        $this->oauthUtility->customlog(
            "OidcLogoutPlugin: post_logout_redirect_uri=" . $postLogoutRedirectUri
        );

        $logoutUrl = $endSessionEndpoint;
        if (!empty($params)) {
            $separator = (strpos($logoutUrl, '?') !== false) ? '&' : '?';
            $logoutUrl .= $separator . http_build_query($params);
        }

        if ($this->response instanceof HttpResponse) {
            $this->response->setRedirect($logoutUrl);
        }
    }
}
