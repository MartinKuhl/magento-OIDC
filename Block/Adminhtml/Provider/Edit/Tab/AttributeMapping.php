<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Block\Adminhtml\Provider\Edit\Tab;

use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory as RoleCollectionFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthUtility;

/**
 * Attribute Mapping tab — OIDC claim to Magento field mapping.
 */
class AttributeMapping extends Template implements TabInterface
{
    /** @var string */
    protected $_template = 'MiniOrange_OAuth::provider/tab/attrsettings.phtml';

    /** @var Registry */
    private Registry $registry;

    /** @var RoleCollectionFactory */
    private RoleCollectionFactory $roleCollectionFactory;

    /** @var OAuthUtility */
    private OAuthUtility $oauthUtility;

    /**
     * @param Context               $context
     * @param Registry              $registry
     * @param RoleCollectionFactory $roleCollectionFactory
     * @param OAuthUtility          $oauthUtility
     * @param array                 $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        RoleCollectionFactory $roleCollectionFactory,
        OAuthUtility $oauthUtility,
        array $data = []
    ) {
        $this->registry              = $registry;
        $this->roleCollectionFactory = $roleCollectionFactory;
        $this->oauthUtility          = $oauthUtility;
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
     * Return all admin roles as [['value' => id, 'label' => name], ...].
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getAllRoles(): array
    {
        $roles = [];
        $collection = $this->roleCollectionFactory->create();
        $collection->setRolesFilter();
        foreach ($collection as $role) {
            $roles[] = ['value' => (string) $role->getId(), 'label' => (string) $role->getRoleName()];
        }
        return $roles;
    }

    /**
     * Return merged list of standard + received OIDC claims for datalist suggestions.
     *
     * Standard claims are always available. Received claims are populated after the
     * first successful Test OIDC Flow and stored in core_config_data.
     *
     * @return string[]
     */
    public function getOidcClaims(): array
    {
        $received = $this->oauthUtility->getStoreConfig(OAuthConstants::RECEIVED_OIDC_CLAIMS);
        if (!empty($received)) {
            $decoded = json_decode((string) $received, true);
            if (is_array($decoded) && $decoded !== []) {
                // After a test flow: show only the real claims received from the IdP
                sort($decoded);
                return $decoded;
            }
        }
        // Before first test: fall back to standard OIDC spec claims as a reference
        return OAuthConstants::OIDC_STANDARD_CLAIMS;
    }

    /**
     * @inheritDoc
     */
    public function getTabLabel(): Phrase|string
    {
        return __('Attribute Mapping');
    }

    /**
     * @inheritDoc
     */
    public function getTabTitle(): Phrase|string
    {
        return __('Attribute Mapping');
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
}
