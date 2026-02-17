<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\User\Model\ResourceModel\User\CollectionFactory as AdminUserCollectionFactory;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * Determines whether the OIDC login button should be visible
 * on admin and customer login pages.
 *
 * Conditions for hiding the button:
 * - No valid OIDC configuration exists (missing endpoints, client ID, etc.)
 * - No matching OIDC user exists AND auto-create is disabled
 */
class OidcLoginVisibility implements ArgumentInterface
{
    private OAuthUtility $oauthUtility;
    private AdminUserCollectionFactory $adminUserCollectionFactory;
    private CustomerCollectionFactory $customerCollectionFactory;

    public function __construct(
        OAuthUtility $oauthUtility,
        AdminUserCollectionFactory $adminUserCollectionFactory,
        CustomerCollectionFactory $customerCollectionFactory
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->adminUserCollectionFactory = $adminUserCollectionFactory;
        $this->customerCollectionFactory = $customerCollectionFactory;
    }

    /**
     * Check if the admin OIDC login button should be shown.
     *
     * Button is hidden when:
     * - OIDC is not configured (missing endpoints/credentials)
     * - No admin users exist AND auto-create admin is disabled
     */
    public function isAdminButtonVisible(): bool
    {
        if (!$this->hasValidOidcConfiguration()) {
            return false;
        }

        // If auto-create admin is enabled, always show the button
        if ($this->isAutoCreateAdminEnabled()) {
            return true;
        }

        // No auto-create: only show if at least one admin user exists
        return $this->hasAdminUsers();
    }

    /**
     * Check if the customer OIDC login button should be shown.
     *
     * Button is hidden when:
     * - OIDC is not configured (missing endpoints/credentials)
     * - No customers exist AND auto-create customer is disabled
     */
    public function isCustomerButtonVisible(): bool
    {
        if (!$this->hasValidOidcConfiguration()) {
            return false;
        }

        // If auto-create customer is enabled, always show the button
        if ($this->isAutoCreateCustomerEnabled()) {
            return true;
        }

        // No auto-create: only show if at least one customer exists
        return $this->hasCustomers();
    }

    /**
     * Validate that all required OIDC configuration fields are present.
     *
     * Checks: app_name, client_id, authorize_endpoint, access_token_endpoint, user_info_endpoint
     */
    private function hasValidOidcConfiguration(): bool
    {
        // Nutze die bewährte Prüfung aus OAuthUtility
        if (!$this->oauthUtility->isOAuthConfigured()) {
            return false;
        }

        // Zusätzliche Validierung über die tatsächlichen DB-Werte
        $appName = $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
        if ($this->oauthUtility->isBlank($appName)) {
            return false;
        }

        try {
            $clientDetails = $this->oauthUtility->getClientDetailsByAppName($appName);
        } catch (\Exception $e) {
            return false;
        }

        // Prüfe die tatsächlichen DB-Spaltennamen
        $requiredFields = ['clientID', 'authorize_endpoint', 'access_token_endpoint'];

        foreach ($requiredFields as $field) {
            if (empty($clientDetails[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }


    /**
     * Check if auto-create admin is enabled in configuration.
     */
    private function isAutoCreateAdminEnabled(): bool
    {
        $appName = $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
        if ($this->oauthUtility->isBlank($appName)) {
            return false;
        }
        try {
            $clientDetails = $this->oauthUtility->getClientDetailsByAppName($appName);
            return !empty($clientDetails['mo_oauth_auto_create_admin'] ?? false);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if auto-create customer is enabled in configuration.
     */
    private function isAutoCreateCustomerEnabled(): bool
    {
        $appName = $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
        if ($this->oauthUtility->isBlank($appName)) {
            return false;
        }
        try {
            $clientDetails = $this->oauthUtility->getClientDetailsByAppName($appName);
            return !empty($clientDetails['mo_oauth_auto_create_customer'] ?? false);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if at least one admin user exists in the system.
     *
     * Uses a COUNT query with LIMIT 1 for performance –
     * we don't need the actual count, just existence.
     */
    private function hasAdminUsers(): bool
    {
        $collection = $this->adminUserCollectionFactory->create();
        $collection->setPageSize(1);
        return $collection->getSize() > 0;
    }

    /**
     * Check if at least one customer exists in the system.
     *
     * Uses a COUNT query with LIMIT 1 for performance.
     */
    private function hasCustomers(): bool
    {
        $collection = $this->customerCollectionFactory->create();
        $collection->setPageSize(1);
        return $collection->getSize() > 0;
    }

    /**
     * Check if non-OIDC customer login is disabled.
     *
     * Returns true if password-based customer login is disabled
     * in configuration, meaning only OIDC authentication is allowed.
     *
     * @return bool True if non-OIDC customer login is disabled
     */
    public function isNonOidcCustomerLoginDisabled(): bool
    {
        return (bool) $this->oauthUtility->getStoreConfig(
            OAuthConstants::DISABLE_NON_OIDC_CUSTOMER_LOGIN
        );
    }
}
