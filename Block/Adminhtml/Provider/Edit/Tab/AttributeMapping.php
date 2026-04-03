<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Block\Adminhtml\Provider\Edit\Tab;

use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory as RoleCollectionFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as GroupCollectionFactory;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Provider\MappingRepository;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

/**
 * Attribute Mapping tab — OIDC claim to Magento field mapping.
 *
 * @psalm-suppress DeprecatedClass
 */
class AttributeMapping extends Template implements TabInterface
{
    /** @var string */
    protected $_template = 'M2Oidc_OAuth::provider/tab/attrsettings.phtml';

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Registry
     */
    private readonly Registry $registry;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var RoleCollectionFactory
     */
    private readonly RoleCollectionFactory $roleCollectionFactory;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var OAuthUtility
     */
    private readonly OAuthUtility $oauthUtility;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var GroupRepositoryInterface
     */
    private readonly GroupRepositoryInterface $groupRepository;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var SearchCriteriaBuilder
     */
    private readonly SearchCriteriaBuilder $searchCriteriaBuilder;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var MappingRepository
     */
    private readonly MappingRepository $mappingRepository;

    /**
     * Constructor.
     *
     * @param Context                  $context
     * @param Registry                 $registry
     * @param RoleCollectionFactory    $roleCollectionFactory
     * @param OAuthUtility             $oauthUtility
     * @param GroupRepositoryInterface $groupRepository
     * @param SearchCriteriaBuilder    $searchCriteriaBuilder
     * @param MappingRepository        $mappingRepository
     * @param mixed[]                  $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        RoleCollectionFactory $roleCollectionFactory,
        OAuthUtility $oauthUtility,
        GroupRepositoryInterface $groupRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        MappingRepository $mappingRepository,
        array $data = []
    ) {
        $this->registry               = $registry;
        $this->roleCollectionFactory  = $roleCollectionFactory;
        $this->oauthUtility           = $oauthUtility;
        $this->groupRepository        = $groupRepository;
        $this->searchCriteriaBuilder  = $searchCriteriaBuilder;
        $this->mappingRepository      = $mappingRepository;
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
        if ($provider === null) {
            return [];
        }
        return is_array($provider) ? $provider : $provider->getData();
    }

    /**
     * Return all admin roles as value/label pairs.
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
     * Return all customer groups as [['value' => id, 'label' => name], ...].
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getAllGroups(): array
    {
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $groups = [];

        foreach ($this->groupRepository->getList($searchCriteria)->getItems() as $group) {
            $groups[] = [
                'value' => (string) $group->getId(),
                'label' => $group->getCode(),
            ];
        }

        return $groups;
    }

    /**
     * Return OIDC claim names for autocomplete suggestions in the attribute mapping form.
     *
     * @return string[]
     */
    public function getOidcClaims(): array
    {
        $providerId = (int) $this->getRequest()->getParam('id', 0);

        if ($providerId > 0) {
            $details = $this->oauthUtility->getClientDetailsById($providerId);
            if ($details !== null && !empty($details['received_oidc_claims'])) {
                $decoded = json_decode((string) $details['received_oidc_claims'], true);
                if (is_array($decoded) && $decoded !== []) {
                    sort($decoded);
                    return $decoded;
                }
            }
        }

        return OAuthConstants::OIDC_STANDARD_CLAIMS;
    }

    /**
     * Return existing normalized attribute mapping rows for the current provider.
     *
     * Each element: ['attribute_type' => ..., 'attribute_name' => ..., 'sync_on_sso' => 0|1]
     * Returns an empty array for a new (unsaved) provider.
     *
     * @return array<int, array{attribute_type: string, attribute_name: string, sync_on_sso: int}>
     */
    public function getAttributeMappings(): array
    {
        $providerId = (int) $this->getRequest()->getParam('id', 0);
        if ($providerId <= 0) {
            return [];
        }
        $map  = $this->mappingRepository->getFullAttributeMap($providerId);
        $rows = [];
        foreach ($map as $type => $info) {
            $rows[] = [
                'attribute_type' => $type,
                'attribute_name' => $info['attribute_name'],
                'sync_on_sso'    => $info['sync_on_sso'],
            ];
        }
        return $rows;
    }

    /**
     * Return the list of attribute types supported by the dynamic attribute mapping rows UI.
     *
     * Each element: ['value' => attribute_type, 'label' => human-readable label]
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getAttributeTypeOptions(): array
    {
        return [
            ['value' => 'email',            'label' => (string) __('Email')],
            ['value' => 'username',          'label' => (string) __('Username')],
            ['value' => 'firstname',         'label' => (string) __('First Name')],
            ['value' => 'lastname',          'label' => (string) __('Last Name')],
            ['value' => 'group',             'label' => (string) __('Group / Role')],
            ['value' => 'dob',               'label' => (string) __('Date of Birth')],
            ['value' => 'gender',            'label' => (string) __('Gender')],
            ['value' => 'billing_address',   'label' => (string) __('Billing: Street')],
            ['value' => 'billing_city',      'label' => (string) __('Billing: City')],
            ['value' => 'billing_zip',       'label' => (string) __('Billing: ZIP / Postal Code')],
            ['value' => 'billing_state',     'label' => (string) __('Billing: State / Region')],
            ['value' => 'billing_country',   'label' => (string) __('Billing: Country')],
            ['value' => 'billing_phone',     'label' => (string) __('Billing: Phone')],
        ];
    }

    /**
     * Return the tab label.
     */
    #[\Override]
    public function getTabLabel(): Phrase|string
    {
        return __('Attribute Mapping');
    }

    /**
     * Return the tab title.
     */
    #[\Override]
    public function getTabTitle(): Phrase|string
    {
        return __('Attribute Mapping');
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
}
