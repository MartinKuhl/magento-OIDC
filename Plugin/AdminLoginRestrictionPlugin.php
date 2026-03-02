<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Plugin;

use Magento\Backend\Model\Auth;
use Magento\Framework\Exception\AuthenticationException;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Helper\OAuthConstants;
use Psr\Log\LoggerInterface;

/**
 * Plugin to restrict non-OIDC admin logins when the setting is enabled.
 *
 * Reads per-provider `mo_disable_non_oidc_admin_login` from the
 * miniorange_oauth_client_apps table. Blocks non-OIDC logins when ANY
 * active admin provider has this restriction enabled.
 *
 * Safety net: if no active provider shows the OIDC button, allow normal
 * login to prevent complete lockout.
 */
class AdminLoginRestrictionPlugin
{
    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var LoggerInterface */
    private readonly LoggerInterface $logger;

    /**
     * Initialize admin login restriction plugin.
     *
     * @param OAuthUtility    $oauthUtility
     * @param LoggerInterface $logger
     */
    public function __construct(
        OAuthUtility $oauthUtility,
        LoggerInterface $logger
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->logger = $logger;
    }

    /**
     * Block non-OIDC authentication attempts when the restriction is enabled.
     *
     * Safety net: if OIDC-only is enabled but the OIDC button is NOT shown
     * on any provider, allow normal login to prevent complete lockout.
     *
     * @param  Auth   $subject
     * @param  string $username
     * @param  string $password
     * @throws AuthenticationException
     */
    public function beforeLogin(Auth $subject, string $username, $password): null
    {
        // Allow OIDC-authenticated logins (token marker from OidcCallback)
        if ($password === \MiniOrange\OAuth\Model\Auth\OidcCredentialAdapter::OIDC_TOKEN_MARKER) {
            return null;
        }

        $adminProviders = $this->oauthUtility->getAllActiveProviders('admin');

        // Check if ANY active admin provider has the restriction enabled
        $anyRestricted = false;
        $anyButtonShown = false;
        foreach ($adminProviders as $provider) {
            if (!empty($provider['mo_disable_non_oidc_admin_login'])) {
                $anyRestricted = true;
            }
            if (!empty($provider['show_admin_link'])) {
                $anyButtonShown = true;
            }
        }

        // Also fall back to global config for backwards compatibility
        if (!$anyRestricted) {
            $anyRestricted = (bool) $this->oauthUtility->getStoreConfig(
                OAuthConstants::DISABLE_NON_OIDC_ADMIN_LOGIN
            );
        }

        if (!$anyRestricted) {
            return null;
        }

        // Safety net: if no provider shows the OIDC button, fall back to global config
        if (!$anyButtonShown) {
            $anyButtonShown = (bool) $this->oauthUtility->getStoreConfig(
                OAuthConstants::SHOW_ADMIN_LINK
            );
        }

        if (!$anyButtonShown) {
            $this->logger->warning(
                'MiniOrange OIDC: OIDC-only admin login is enabled but no OIDC '
                . 'button is shown. Allowing normal login to prevent lockout. '
                . 'User: ' . $username
            );
            return null;
        }

        // Block all non-OIDC login attempts
        $this->oauthUtility->customlog(
            'AdminLoginRestriction: Blocked non-OIDC login attempt for user: ' . $username
        );

        throw new AuthenticationException(
            __('Non-OIDC admin login is disabled. Please use OIDC authentication.')
        );
    }
}
