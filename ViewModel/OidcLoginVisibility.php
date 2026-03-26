<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\User\Model\ResourceModel\User\CollectionFactory as AdminUserCollectionFactory;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use M2Oidc\OAuth\Helper\OAuthUtility;

/**
 * Determines whether the OIDC login button should be visible
 * on admin and customer login pages.
 *
 * All checks are provider-based: iterates over active providers from
 * m2oidc_oauth_client_apps and evaluates per-provider flags
 * (show_*_link, m2oidc_disable_non_oidc_*, m2oidc_auto_create_*).
 *
 * No core_config_data dependency for visibility/restriction decisions.
 */
class OidcLoginVisibility implements ArgumentInterface
{
    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var AdminUserCollectionFactory */
    private readonly AdminUserCollectionFactory $adminUserCollectionFactory;

    /** @var CustomerCollectionFactory */
    private readonly CustomerCollectionFactory $customerCollectionFactory;

    /**
     * Constructor.
     *
     * @param OAuthUtility               $oauthUtility
     * @param AdminUserCollectionFactory $adminUserCollectionFactory
     * @param CustomerCollectionFactory  $customerCollectionFactory
     */
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
     * Returns true when at least one active admin provider has:
     * - show_admin_link = 1
     * - valid OIDC configuration (clientID, authorize_endpoint, access_token_endpoint)
     * - either auto-create admin enabled OR at least one admin user exists
     */
    public function isAdminButtonVisible(): bool
    {
        foreach ($this->oauthUtility->getAllActiveProviders('admin') as $provider) {
            if ((int) ($provider['show_admin_link'] ?? 0) !== 1) {
                continue;
            }
            if (!$this->isProviderConfigured($provider)) {
                continue;
            }
            if (!empty($provider['m2oidc_auto_create_admin'])) {
                return true;
            }
            if ($this->hasAdminUsers()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the customer OIDC login button should be shown.
     *
     * Returns true when at least one active customer provider has:
     * - show_customer_link = 1
     * - valid OIDC configuration
     * - either auto-create customer enabled OR at least one customer exists
     */
    public function isCustomerButtonVisible(): bool
    {
        foreach ($this->oauthUtility->getAllActiveProviders('customer') as $provider) {
            if ((int) ($provider['show_customer_link'] ?? 0) !== 1) {
                continue;
            }
            if (!$this->isProviderConfigured($provider)) {
                continue;
            }
            if (!empty($provider['m2oidc_auto_create_customer'])) {
                return true;
            }
            if ($this->hasCustomers()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if non-OIDC customer login is disabled.
     *
     * Returns true when ANY active customer provider with a visible button
     * has m2oidc_disable_non_oidc_customer_login = 1.
     */
    public function isNonOidcCustomerLoginDisabled(): bool
    {
        foreach ($this->oauthUtility->getAllActiveProviders('customer') as $provider) {
            if ((int) ($provider['show_customer_link'] ?? 0) !== 1) {
                continue;
            }
            if ((int) ($provider['m2oidc_disable_non_oidc_customer_login'] ?? 0) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if non-OIDC admin login is disabled.
     *
     * Returns true when ANY active admin provider with a visible button
     * has m2oidc_disable_non_oidc_admin_login = 1.
     */
    public function isNonOidcAdminLoginDisabled(): bool
    {
        foreach ($this->oauthUtility->getAllActiveProviders('admin') as $provider) {
            if ((int) ($provider['show_admin_link'] ?? 0) !== 1) {
                continue;
            }
            if ((int) ($provider['m2oidc_disable_non_oidc_admin_login'] ?? 0) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate that a provider row has the minimum required OIDC fields.
     *
     * @param array<string, mixed> $provider
     */
    private function isProviderConfigured(array $provider): bool
    {
        return !empty($provider['clientID'])
            && !empty($provider['authorize_endpoint'])
            && !empty($provider['access_token_endpoint']);
    }

    /**
     * Check if at least one admin user exists (COUNT with LIMIT 1).
     */
    private function hasAdminUsers(): bool
    {
        $collection = $this->adminUserCollectionFactory->create();
        $collection->setPageSize(1);
        return $collection->getSize() > 0;
    }

    /**
     * Check if at least one customer exists (COUNT with LIMIT 1).
     */
    private function hasCustomers(): bool
    {
        $collection = $this->customerCollectionFactory->create();
        $collection->setPageSize(1);
        return $collection->getSize() > 0;
    }
}
