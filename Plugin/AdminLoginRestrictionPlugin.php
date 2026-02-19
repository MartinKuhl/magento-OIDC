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
 * Includes a safety net: if OIDC-only mode is enabled but the OIDC
 * button is hidden, normal login is allowed to prevent lockout.
 */
class AdminLoginRestrictionPlugin
{
    private readonly OAuthUtility $oauthUtility;

    private readonly LoggerInterface $logger;

    /**
     * Constructor
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
     * Safety net: if OIDC-only is enabled but the OIDC button is NOT shown,
     * allow normal login to prevent complete lockout.
     *
     * @param  string $password
     * @throws AuthenticationException
     */
    public function beforeLogin(Auth $subject, string $username, $password): null
    {
        $isDisabled = $this->oauthUtility->getStoreConfig(
            OAuthConstants::DISABLE_NON_OIDC_ADMIN_LOGIN
        );

        if (!$isDisabled) {
            return null;
        }

        // Allow OIDC-authenticated logins (token marker from OidcCallback)
        if ($password === \MiniOrange\OAuth\Model\Auth\OidcCredentialAdapter::OIDC_TOKEN_MARKER) {
            return null;
        }

        // Safety net: if OIDC button is hidden, do NOT block normal login
        $showAdminLink = $this->oauthUtility->getStoreConfig(
            OAuthConstants::SHOW_ADMIN_LINK
        );

        if (!$showAdminLink) {
            $this->logger->warning(
                'MiniOrange OIDC: OIDC-only admin login is enabled but the OIDC '
                . 'button is hidden. Allowing normal login to prevent lockout. '
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
