<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Ui\Component\Listing\Column;

use Magento\Framework\DB\Select;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider\CollectionFactory;

/**
 * Virtual column that renders user counts per provider in "total (active)" format.
 *
 * Total  = all rows in m2oidc_oauth_user_provider for this provider + user_type.
 * Active = subset whose Magento account still exists (INNER JOIN to admin_user /
 *          customer_entity). Deleted accounts lower the active count but not the total.
 *
 * Example: "5 (3)" — 5 OIDC-linked users, 3 accounts still exist in Magento.
 */
class ActiveUserCount extends Column
{
    /** @var CollectionFactory */
    private CollectionFactory $collectionFactory;

    /**
     * Constructor.
     *
     * @param ContextInterface   $context
     * @param UiComponentFactory $uiComponentFactory
     * @param CollectionFactory  $collectionFactory
     * @param array              $components
     * @param array              $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        CollectionFactory $collectionFactory,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * @inheritDoc
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $userType = $this->getData('config/user_type') ?? 'customer';
        $fieldName = $this->getData('name');

        $providerIds  = array_column($dataSource['data']['items'], 'id');
        $totalCounts  = $this->getTotalCounts($providerIds, $userType);
        $activeCounts = $this->getActiveCounts($providerIds, $userType);

        foreach ($dataSource['data']['items'] as &$item) {
            $providerId = (int)$item['id'];
            $total      = $totalCounts[$providerId]  ?? 0;
            $active     = $activeCounts[$providerId] ?? 0;
            $item[$fieldName] = sprintf('%d (%d)', $total, $active);
        }

        return $dataSource;
    }

    /**
     * Count ALL OIDC user mappings grouped by provider (including orphaned/deleted accounts).
     *
     * @param  int[]  $providerIds
     * @param  string $userType 'admin' or 'customer'
     * @return array<int, int> providerId => count
     */
    private function getTotalCounts(array $providerIds, string $userType): array
    {
        if (empty($providerIds)) {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $select     = $collection->getSelect();
        $connection = $collection->getConnection();

        $select->where('main_table.provider_id IN (?)', $providerIds);
        $select->where('main_table.user_type = ?', $userType);

        $select->reset(Select::COLUMNS)
            ->columns([
                'provider_id' => 'main_table.provider_id',
                'cnt'         => new \Zend_Db_Expr('COUNT(*)'),
            ])
            ->group('main_table.provider_id');

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[(int)$row['provider_id']] = (int)$row['cnt'];
        }

        return $result;
    }

    /**
     * Count active (non-deleted) OIDC users grouped by provider.
     *
     * @param  int[]  $providerIds
     * @param  string $userType 'admin' or 'customer'
     * @return array<int, int> providerId => count
     */
    private function getActiveCounts(array $providerIds, string $userType): array
    {
        if (empty($providerIds)) {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $select = $collection->getSelect();
        $connection = $collection->getConnection();

        $select->where('main_table.provider_id IN (?)', $providerIds);
        $select->where('main_table.user_type = ?', $userType);

        // JOIN to verify the user still exists in Magento
        if ($userType === 'admin') {
            $select->joinInner(
                ['u' => $collection->getTable('admin_user')],
                'main_table.user_id = u.user_id',
                []
            );
        } else {
            $select->joinInner(
                ['u' => $collection->getTable('customer_entity')],
                'main_table.user_id = u.entity_id',
                []
            );
        }

        $select->reset(Select::COLUMNS)
            ->columns([
                'provider_id' => 'main_table.provider_id',
                'cnt'         => new \Zend_Db_Expr('COUNT(*)'),
            ])
            ->group('main_table.provider_id');

        $result = [];
        foreach ($connection->fetchAll($select) as $row) {
            $result[(int)$row['provider_id']] = (int)$row['cnt'];
        }

        return $result;
    }
}
