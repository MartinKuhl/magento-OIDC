<?php

declare(strict_types=1);

/**
 * Customer Login Restriction Plugin
 *
 * Restricts password-based customer logins when OIDC-only mode is
 * enabled. Reads per-provider `mo_disable_non_oidc_customer_login` from
 * the miniorange_oauth_client_apps table.  Blocks login when ANY active
 * customer provider has this restriction enabled.
 *
 * Safety net: if no provider shows the customer OIDC button, allow normal
 * login to prevent complete lockout.
 */
namespace MiniOrange\OAuth\Plugin;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Helper\OAuthConstants;
use Psr\Log\LoggerInterface;

class CustomerLoginRestrictionPlugin
{
    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var LoggerInterface */
    private readonly LoggerInterface $logger;

    /**
     * Initialize customer login restriction plugin.
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
     * Block non-OIDC authentication attempts when enabled.
     *
     * Safety net: if no active provider shows the OIDC button, allow normal login.
     *
     * @param  AccountManagementInterface $subject
     * @param  string                     $email
     * @param  string                     $password
     * @throws LocalizedException
     */
    public function beforeAuthenticate(
        AccountManagementInterface $subject,
        string $email,
        $password
    ): null {
        $customerProviders = $this->oauthUtility->getAllActiveProviders('customer');

        // Check if ANY active customer provider has the restriction enabled
        $anyRestricted = false;
        $anyButtonShown = false;
        foreach ($customerProviders as $provider) {
            if (!empty($provider['mo_disable_non_oidc_customer_login'])) {
                $anyRestricted = true;
            }
            if (!empty($provider['show_customer_link'])) {
                $anyButtonShown = true;
            }
        }

        // Fall back to global config for backwards compatibility
        if (!$anyRestricted) {
            $anyRestricted = (bool) $this->oauthUtility->getStoreConfig(
                OAuthConstants::DISABLE_NON_OIDC_CUSTOMER_LOGIN
            );
        }

        if (!$anyRestricted) {
            return null;
        }

        // Safety net: if no provider shows the OIDC button, do NOT block normal login
        if (!$anyButtonShown) {
            $anyButtonShown = (bool) $this->oauthUtility->getStoreConfig(
                OAuthConstants::SHOW_CUSTOMER_LINK
            );
        }

        if (!$anyButtonShown) {
            $this->logger->warning(
                'MiniOrange OIDC: OIDC-only customer login is enabled but no '
                . 'OIDC button is shown. Allowing normal login to prevent '
                . 'lockout. Email: ' . $email
            );
            return null;
        }

        $this->oauthUtility->customlog(
            'CustomerLoginRestriction: Blocked non-OIDC login attempt for: ' . $email
        );

        throw new LocalizedException(
            __('Password-based login is disabled. Please use OIDC authentication.')
        );
    }
}
