<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Block\Adminhtml\Provider\Edit\Tab;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;

/**
 * Login Options tab — per-provider SSO button visibility, auto-creation, and login restrictions.
 */
class LoginOptions extends Template implements TabInterface
{
    /** @var string */
    protected $_template = 'MiniOrange_OAuth::provider/tab/loginoptions.phtml';

    /** @var Registry */
    private readonly Registry $registry;

    /**
     * @param Context  $context
     * @param Registry $registry
     * @param array    $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        array $data = []
    ) {
        $this->registry = $registry;
        parent::__construct($context, $data);
    }

    /**
     * Return the current provider data, or an empty array for new providers.
     *
     * @return array<string, mixed>
     */
    public function getProviderData(): array
    {
        $provider = $this->registry->registry('current_oidc_provider');
        return $provider ? $provider->getData() : [];
    }

    /**
     * @inheritDoc
     */
    public function getTabLabel(): Phrase|string
    {
        return __('Login Options');
    }

    /**
     * @inheritDoc
     */
    public function getTabTitle(): Phrase|string
    {
        return __('Login Options');
    }

    /**
     * @inheritDoc
     */
    public function canShowTab(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function isHidden(): bool
    {
        return false;
    }

    // ── Profile Sync Getters ──────────────────────────────────

    public function getSyncCustomerProfileOnSso(): bool
    {
        return (bool) ($this->provider?->getData('sync_customer_profile_on_sso') ?? false);
    }

    public function getSyncCustomerAddressOnSso(): bool
    {
        return (bool) ($this->provider?->getData('sync_customer_address_on_sso') ?? false);
    }

    public function getSyncCustomerGroupOnSso(): bool
    {
        return (bool) ($this->provider?->getData('sync_customer_group_on_sso') ?? false);
    }

    public function getSyncAdminProfileOnSso(): bool
    {
        return (bool) ($this->provider?->getData('sync_admin_profile_on_sso') ?? false);
    }

    public function getSyncAdminRoleOnSso(): bool
    {
        return (bool) ($this->provider?->getData('sync_admin_role_on_sso') ?? false);
    }
}
