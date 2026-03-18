<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Block\Adminhtml\Provider\Edit\Tab;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps\CollectionFactory;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

/**
 * Login Options tab — SSO button visibility, auto-creation,
 * login restrictions, and auto-redirect settings.
 *
 * @psalm-suppress DeprecatedClass
 */
class LoginOptions extends Template implements TabInterface
{
    /** @var string */
    protected $_template = 'M2Oidc_OAuth::provider/tab/loginoptions.phtml';

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Registry
     */
    private readonly Registry $registry;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var CollectionFactory
     */
    private readonly CollectionFactory $providerCollectionFactory;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var UserProviderResource
     */
    private readonly UserProviderResource $userProviderResource;

    /**
     * Constructor.
     *
     * @param Context              $context
     * @param Registry             $registry
     * @param CollectionFactory    $providerCollectionFactory
     * @param UserProviderResource $userProviderResource
     * @param array                $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        CollectionFactory $providerCollectionFactory,
        UserProviderResource $userProviderResource,
        array $data = []
    ) {
        $this->registry = $registry;
        $this->providerCollectionFactory = $providerCollectionFactory;
        $this->userProviderResource = $userProviderResource;
        parent::__construct($context, $data);
    }

    /**
     * Return current provider data array from registry.
     *
     * @return array<string, mixed>
     */
    public function getProviderData(): array
    {
        $provider = $this->registry->registry('current_oidc_provider');
        return $provider ? $provider->getData() : [];
    }

    /**
     * Count of configured OIDC providers (needed to disable auto-redirect when 0 or >1 providers exist).
     */
    public function getProviderCount(): int
    {
        return (int) $this->providerCollectionFactory->create()->getSize();
    }

    /**
     * Auto-redirect is only allowed when exactly 1 provider exists.
     */
    public function isAutoRedirectAllowed(): bool
    {
        return $this->getProviderCount() <= 1;
    }

    /**
     * Return the tab label.
     */
    #[\Override]
    public function getTabLabel(): Phrase|string
    {
        return __('Login Options');
    }

    /**
     * Return the tab title.
     */
    #[\Override]
    public function getTabTitle(): Phrase|string
    {
        return __('Login Options');
    }

    /**
     * Determine whether this tab can be shown.
     */
    #[\Override]
    public function canShowTab(): bool
    {
        return true;
    }

    /**
     * Determine whether this tab is hidden.
     */
    #[\Override]
    public function isHidden(): bool
    {
        return false;
    }

    // ── Value Getters ────────────────────────────────────────
    /**
     * Return a boolean provider config value by key.
     *
     * @param string $key Provider data key
     */
    private function providerVal(string $key): bool
    {
        return (bool) (int) ($this->getProviderData()[$key] ?? 0);
    }

    // ── Button Visibility ────────────────────────────────────
    /**
     * Return whether the SSO button is shown on the admin login page.
     */
    public function getShowAdminLink(): bool
    {
        return $this->providerVal('show_admin_link');
    }

    /**
     * Return whether the SSO button is shown on the customer login page.
     */
    public function getShowCustomerLink(): bool
    {
        return $this->providerVal('show_customer_link');
    }

    // ── Auto-Create ──────────────────────────────────────────
    /**
     * Return whether admin users are auto-created on first SSO login.
     */
    public function getAutoCreateAdmin(): bool
    {
        return $this->providerVal('m2oidc_auto_create_admin');
    }

    /**
     * Return whether customer accounts are auto-created on first SSO login.
     */
    public function getAutoCreateCustomer(): bool
    {
        return $this->providerVal('m2oidc_auto_create_customer');
    }

    // ── OIDC User Existence ──────────────────────────────────
    /**
     * Return whether at least one admin user has logged in via this provider.
     */
    public function hasOidcAdminUsers(): bool
    {
        $providerId = (int) ($this->getProviderData()['id'] ?? 0);
        if ($providerId === 0) {
            return false;
        }
        return $this->userProviderResource->countByTypeAndProvider('admin', $providerId) > 0;
    }

    /**
     * Return whether at least one customer has logged in via this provider.
     */
    public function hasOidcCustomerUsers(): bool
    {
        $providerId = (int) ($this->getProviderData()['id'] ?? 0);
        if ($providerId === 0) {
            return false;
        }
        return $this->userProviderResource->countByTypeAndProvider('customer', $providerId) > 0;
    }

    // ── Login Restrictions ───────────────────────────────────
    /**
     * Return whether non-OIDC admin login is disabled.
     */
    public function getDisableNonOidcAdmin(): bool
    {
        return $this->providerVal('m2oidc_disable_non_oidc_admin_login');
    }

    /**
     * Return whether non-OIDC customer login is disabled.
     */
    public function getDisableNonOidcCustomer(): bool
    {
        return $this->providerVal('m2oidc_disable_non_oidc_customer_login');
    }

    // ── Auto Redirect ────────────────────────────────────────
    /**
     * Return whether admins are auto-redirected to the IdP on the login page.
     */
    public function getAutoRedirectAdmin(): bool
    {
        return $this->providerVal('autoredirect_admin');
    }

    /**
     * Return whether customers are auto-redirected to the IdP on the login page.
     */
    public function getAutoRedirectCustomer(): bool
    {
        return $this->providerVal('autoredirect_customer');
    }

    // ── Profile Sync ─────────────────────────────────────────
    /**
     * Return whether customer profile fields are synced on every SSO login.
     */
    public function getSyncCustomerProfileOnSso(): bool
    {
        return $this->providerVal('sync_customer_profile_on_sso');
    }

    /**
     * Return whether customer address is synced on every SSO login.
     */
    public function getSyncCustomerAddressOnSso(): bool
    {
        return $this->providerVal('sync_customer_address_on_sso');
    }

    /**
     * Return whether customer group is synced on every SSO login.
     */
    public function getSyncCustomerGroupOnSso(): bool
    {
        return $this->providerVal('sync_customer_group_on_sso');
    }

    /**
     * Return whether admin profile fields are synced on every SSO login.
     */
    public function getSyncAdminProfileOnSso(): bool
    {
        return $this->providerVal('sync_admin_profile_on_sso');
    }

    /**
     * Return whether admin role is synced on every SSO login.
     */
    public function getSyncAdminRoleOnSso(): bool
    {
        return $this->providerVal('sync_admin_role_on_sso');
    }

    // ── IdP-Initiated SSO ────────────────────────────────────
    /**
     * Return whether IdP-initiated login (OIDC Third-Party Initiated Login §4) is enabled.
     */
    public function getIdpInitiatedEnabled(): bool
    {
        return $this->providerVal('idp_initiated_enabled');
    }
}
