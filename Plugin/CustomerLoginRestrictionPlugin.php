<?php

declare(strict_types=1);

/**
 * Customer Login Restriction Plugin
 *
 * Restricts password-based customer logins when OIDC-only mode is
 * enabled. Includes safety net to prevent lockout when OIDC button
 * is hidden.
 */
namespace MiniOrange\OAuth\Plugin;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Helper\OAuthConstants;
use Psr\Log\LoggerInterface;

class CustomerLoginRestrictionPlugin
{
    private OAuthUtility $oauthUtility;
    private LoggerInterface $logger;

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
     * Safety net: if OIDC button is hidden, allow normal login.
     *
     * @param AccountManagementInterface $subject
     * @param string $email
     * @param string $password
     * @return null
     * @throws LocalizedException
     */
    public function beforeAuthenticate(
        AccountManagementInterface $subject,
        $email,
        $password
    ) {
        $isDisabled = $this->oauthUtility->getStoreConfig(
            OAuthConstants::DISABLE_NON_OIDC_CUSTOMER_LOGIN
        );

        if (!$isDisabled) {
            return null;
        }

        // Safety net: if OIDC button is hidden, do NOT block normal login
        $showCustomerLink = $this->oauthUtility->getStoreConfig(
            OAuthConstants::SHOW_CUSTOMER_LINK
        );

        if (!$showCustomerLink) {
            $this->logger->warning(
                'MiniOrange OIDC: OIDC-only customer login is enabled but the '
                . 'OIDC button is hidden. Allowing normal login to prevent '
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
