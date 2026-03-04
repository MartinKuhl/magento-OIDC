<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Plugin;

use Magento\Backend\Model\Auth;
use Magento\Framework\Exception\AuthenticationException;
use MiniOrange\OAuth\Helper\OAuthUtility;
use Psr\Log\LoggerInterface;

/**
 * Restricts non-OIDC admin logins when the setting is enabled.
 *
 * Reads per-provider `mo_disable_non_oidc_admin_login` from the
 * miniorange_oauth_client_apps table. No core_config_data fallbacks.
 */
class AdminLoginRestrictionPlugin
{
    private readonly OAuthUtility $oauthUtility;
    private readonly LoggerInterface $logger;

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

        if (!$anyRestricted) {
            return null;
        }

        // Safety net: if no provider shows the OIDC button, allow login
        if (!$anyButtonShown) {
            $this->logger->warning(
                'MiniOrange OIDC: OIDC-only admin login is enabled but no OIDC '
                . 'button is shown. Allowing normal login to prevent lockout. '
                . 'User: ' . $username
            );
            return null;
        }

        $this->oauthUtility->customlog(
            'AdminLoginRestriction: Blocked non-OIDC login attempt for user: ' . $username
        );

        throw new AuthenticationException(
            __('Non-OIDC admin login is disabled. Please use OIDC authentication.')
        );
    }
}
