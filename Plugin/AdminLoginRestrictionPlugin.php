<?php

namespace MiniOrange\OAuth\Plugin;

use Magento\Backend\Model\Auth;
use Magento\Framework\Exception\AuthenticationException;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * Plugin to restrict non-OIDC admin logins when the setting is enabled
 */
class AdminLoginRestrictionPlugin
{
    private $oauthUtility;

    /**
     * Initialize admin login restriction plugin.
     *
     * @param \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility
     */
    public function __construct(
        OAuthUtility $oauthUtility
    ) {
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * Block non-OIDC authentication attempts when the restriction is enabled
     *
     * @param Auth $subject
     * @param string $username
     * @param string $password
     * @throws AuthenticationException
     */
    public function beforeLogin(Auth $subject, $username, $password)
    {
        // Check if non-OIDC admin login is disabled
        $isDisabled = $this->oauthUtility->getStoreConfig(OAuthConstants::DISABLE_NON_OIDC_ADMIN_LOGIN);

        if (!$isDisabled) {
            return null; // Allow normal authentication
        }

        // Allow OIDC-authenticated logins (token marker from Oidccallback)
        if ($password === \MiniOrange\OAuth\Model\Auth\OidcCredentialAdapter::OIDC_TOKEN_MARKER) {
            return null;
        }

        // Block all non-OIDC login attempts
        $this->oauthUtility->customlog("AdminLoginRestriction: Blocked non-OIDC login attempt for user: " . $username);
        throw new AuthenticationException(
            __('Non-OIDC admin login is disabled. Please use OIDC authentication.')
        );
    }
}
