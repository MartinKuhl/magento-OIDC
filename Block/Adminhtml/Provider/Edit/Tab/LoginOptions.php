<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Block\Adminhtml\Provider\Edit\Tab;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;

/**
 * Login Options tab — per-provider SSO settings.
 */
class LoginOptions extends Template implements TabInterface
{
    protected $_template = 'MiniOrange_OAuth::provider/tab/loginoptions.phtml';

    private readonly Registry $registry;

    public function __construct(
        Context $context,
        Registry $registry,
        array $data = []
    ) {
        $this->registry = $registry;
        parent::__construct($context, $data);
    }

    public function getProviderData(): array
    {
        $provider = $this->registry->registry('current_oidc_provider');
        return $provider ? $provider->getData() : [];
    }

    // ── Tab Interface ────────────────────────────────────────

    public function getTabLabel(): Phrase|string
    {
        return __('Login Options');
    }

    public function getTabTitle(): Phrase|string
    {
        return __('Login Options');
    }

    public function canShowTab(): bool
    {
        return true;
    }

    public function isHidden(): bool
    {
        return false;
    }

    // ── Profile Sync Getters (konsistent via getProviderData) ─

    private function providerVal(string $key): bool
    {
        return (bool) ($this->getProviderData()[$key] ?? false);
    }

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
