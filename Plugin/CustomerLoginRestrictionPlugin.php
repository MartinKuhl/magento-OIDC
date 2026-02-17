<?php
/**
 * Customer Login Restriction Plugin
 *
 * Restricts password-based customer logins when OIDC-only mode is
 * enabled. Mirrors AdminLoginRestrictionPlugin for customers.
 *
 * @package MiniOrange\OAuth\Plugin
 */
namespace MiniOrange\OAuth\Plugin;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * Plugin to restrict non-OIDC customer logins.
 *
 * Hooks into the authenticate() method of AccountManagementInterface
 * to block password-based login attempts when configured.
 */
class CustomerLoginRestrictionPlugin
{
    /**
     * @var OAuthUtility
     */
    private OAuthUtility $oauthUtility;

    /**
     * Initialize customer login restriction plugin.
     *
     * @param OAuthUtility $oauthUtility OAuth utility helper
     */
    public function __construct(
        OAuthUtility $oauthUtility
    ) {
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * Block non-OIDC authentication attempts when enabled.
     *
     * Checks if non-OIDC customer login is disabled in configuration.
     * If disabled, throws LocalizedException to prevent password-based
     * login. OIDC logins bypass this check by using
     * setCustomerAsLoggedIn() directly.
     *
     * @param AccountManagementInterface $subject Account management
     * @param string $email Customer email address
     * @param string $password Customer password
     * @return null Always returns null (plugin pattern requirement)
     * @throws LocalizedException If non-OIDC login is disabled
     */
    public function beforeAuthenticate(
        AccountManagementInterface $subject,
        $email,
        $password
    ) {
        // Check if non-OIDC customer login is disabled
        $isDisabled = $this->oauthUtility->getStoreConfig(
            OAuthConstants::DISABLE_NON_OIDC_CUSTOMER_LOGIN
        );

        if (!$isDisabled) {
            return null; // Allow normal authentication
        }

        // Block all password-based login attempts
        $this->oauthUtility->customlog(
            "CustomerLoginRestriction: Blocked non-OIDC login for: "
            . $email
        );
        throw new LocalizedException(__(
            'Password login is disabled. '
            . 'Please use OIDC authentication.'
        ));
    }
}
