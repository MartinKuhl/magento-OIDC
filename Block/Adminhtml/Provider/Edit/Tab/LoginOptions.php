<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Block\Adminhtml\Provider\Edit\Tab;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps\CollectionFactory;

/**
 * Login Options tab — SSO button visibility, auto-creation,
 * login restrictions, and auto-redirect settings.
 */
class LoginOptions extends Template implements TabInterface
{
    protected $_template = 'MiniOrange_OAuth::provider/tab/loginoptions.phtml';

    private readonly Registry $registry;
    private readonly CollectionFactory $providerCollectionFactory;

    public function __construct(
        Context $context,
        Registry $registry,
        CollectionFactory $providerCollectionFactory,
        array $data = []
    ) {
        $this->registry = $registry;
        $this->providerCollectionFactory = $providerCollectionFactory;
        parent::__construct($context, $data);
    }

    /** @return array<string, mixed> */
    public function getProviderData(): array
    {
        $provider = $this->registry->registry('current_oidc_provider');
        return $provider ? $provider->getData() : [];
    }

    /**
     * Count of configured OIDC providers (needed to disable
     * auto-redirect when 0 or >1 providers exist).
     */
    public function getProviderCount(): int
    {
        return (int) $this->providerCollectionFactory->create()->getSize();
    }

    /** Auto-redirect is only allowed when exactly 1 provider exists. */
    public function isAutoRedirectAllowed(): bool
    {
        return $this->getProviderCount() <= 1;
    }

    public function getTabLabel(): Phrase|string  { return __('Login Options'); }
    public function getTabTitle(): Phrase|string  { return __('Login Options'); }
    public function canShowTab(): bool            { return true; }
    public function isHidden(): bool              { return false; }

    // ── Value Getters ────────────────────────────────────────

    private function providerVal(string $key): bool
    {
        return (bool) (int) ($this->getProviderData()[$key] ?? 0);
    }

    // ── Button Visibility ────────────────────────────────────

    public function getShowAdminLink(): bool
    {
        return $this->providerVal('show_admin_link');
    }

    public function getShowCustomerLink(): bool
    {
        return $this->providerVal('show_customer_link');
    }

    // ── Auto-Create ──────────────────────────────────────────

    public function getAutoCreateAdmin(): bool
    {
        return $this->providerVal('mo_oauth_auto_create_admin');
    }

    public function getAutoCreateCustomer(): bool
    {
        return $this->providerVal('mo_oauth_auto_create_customer');
    }

    // ── Login Restrictions ───────────────────────────────────

    public function getDisableNonOidcAdmin(): bool
    {
        return $this->providerVal('mo_disable_non_oidc_admin_login');
    }

    public function getDisableNonOidcCustomer(): bool
    {
        return $this->providerVal('mo_disable_non_oidc_customer_login');
    }

    // ── Auto Redirect ────────────────────────────────────────

    public function getAutoRedirectAdmin(): bool
    {
        return $this->providerVal('autoredirect_admin');
    }

    public function getAutoRedirectCustomer(): bool
    {
        return $this->providerVal('autoredirect_customer');
    }

    // ── Profile Sync ─────────────────────────────────────────

    public function getSyncCustomerProfileOnSso(): bool
    {
        return $this->providerVal('sync_customer_profile_on_sso');
    }

    public function getSyncCustomerAddressOnSso(): bool
    {
        return $this->providerVal('sync_customer_address_on_sso');
    }

    public function getSyncCustomerGroupOnSso(): bool
    {
        return $this->providerVal('sync_customer_group_on_sso');
    }

    public function getSyncAdminProfileOnSso(): bool
    {
        return $this->providerVal('sync_admin_profile_on_sso');
    }

    public function getSyncAdminRoleOnSso(): bool
    {
        return $this->providerVal('sync_admin_role_on_sso');
    }
}
