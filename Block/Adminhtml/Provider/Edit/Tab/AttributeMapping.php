<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Block\Adminhtml\Provider\Edit\Tab;

use Magento\Authorization\Model\ResourceModel\Role\CollectionFactory as RoleCollectionFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory as GroupCollectionFactory;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthUtility;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;

/**
 * Attribute Mapping tab — OIDC claim to Magento field mapping.
 */
class AttributeMapping extends Template implements TabInterface
{
    /** @var string */
    protected $_template = 'MiniOrange_OAuth::provider/tab/attrsettings.phtml';

    private readonly Registry $registry;
    private readonly RoleCollectionFactory $roleCollectionFactory;
    private readonly OAuthUtility $oauthUtility;

    /** @var GroupRepositoryInterface */
    private readonly GroupRepositoryInterface $groupRepository;

    /** @var SearchCriteriaBuilder */
    private readonly SearchCriteriaBuilder $searchCriteriaBuilder;

    public function __construct(
        Context $context,
        Registry $registry,
        RoleCollectionFactory $roleCollectionFactory,
        OAuthUtility $oauthUtility,
        GroupRepositoryInterface $groupRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        array $data = []
    ) {
        $this->registry               = $registry;
        $this->roleCollectionFactory  = $roleCollectionFactory;
        $this->oauthUtility           = $oauthUtility;
        $this->groupRepository        = $groupRepository;
        $this->searchCriteriaBuilder  = $searchCriteriaBuilder;
        parent::__construct($context, $data);
    }

    public function getProviderData(): array
    {
        $provider = $this->registry->registry('current_oidc_provider');
        if ($provider === null) {
            return [];
        }
        return is_array($provider) ? $provider : $provider->getData();
    }

    /**
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
                'label' => (string) $group->getCode(),
            ];
        }

        return $groups;
    }

    /**
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

    /** @inheritDoc */
    #[\Override]
    public function getTabLabel(): Phrase|string
    {
        return __('Attribute Mapping');
    }

    /** @inheritDoc */
    #[\Override]
    public function getTabTitle(): Phrase|string
    {
        return __('Attribute Mapping');
    }

    /** @inheritDoc */
    #[\Override]
    public function canShowTab(): bool
    {
        return true;
    }

    /** @inheritDoc */
    #[\Override]
    public function isHidden(): bool
    {
        return false;
    }
}
