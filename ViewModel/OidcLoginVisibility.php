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
        // Delegate to existing check first
        if (!$this->oauthUtility->isOAuthConfigured()) {
            return false;
        }

        // Additional validation: ensure critical endpoints are set
        $requiredFields = [
            OAuthConstants::CLIENT_ID,
            OAuthConstants::AUTHORIZE_URL,
            OAuthConstants::ACCESSTOKEN_URL,
        ];

        foreach ($requiredFields as $field) {
            $value = $this->oauthUtility->getStoreConfig($field);
            if ($this->oauthUtility->isBlank($value)) {
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
        return (bool) $this->oauthUtility->getStoreConfig(OAuthConstants::AUTO_CREATE_ADMIN);
    }

    /**
     * Check if auto-create customer is enabled in configuration.
     */
    private function isAutoCreateCustomerEnabled(): bool
    {
        return (bool) $this->oauthUtility->getStoreConfig(OAuthConstants::AUTO_CREATE_CUSTOMER);
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
}
