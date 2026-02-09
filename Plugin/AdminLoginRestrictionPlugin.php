<?php

namespace MiniOrange\OAuth\Plugin;

use Magento\Backend\Model\Auth;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Stdlib\CookieManagerInterface;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * Plugin to restrict non-OIDC admin logins when the setting is enabled
 */
class AdminLoginRestrictionPlugin
{
    private $cookieManager;
    private $oauthUtility;

    public function __construct(
        CookieManagerInterface $cookieManager,
        OAuthUtility $oauthUtility
    ) {
        $this->cookieManager = $cookieManager;
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

        // Check if this is an OIDC-authenticated session
        $oidcToken = $this->cookieManager->getCookie('oidc_admin_token');

        if (!$oidcToken) {
            // No OIDC token present, block the login
            $this->oauthUtility->customlog("AdminLoginRestriction: Blocked non-OIDC login attempt for user: " . $username);
            throw new AuthenticationException(
                __('Non-OIDC admin login is disabled. Please use OIDC authentication.')
            );
        }

        return null;
    }
}
