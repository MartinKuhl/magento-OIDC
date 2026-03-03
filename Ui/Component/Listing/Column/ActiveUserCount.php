<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Ui\Component\Listing\Column;

use Magento\Framework\DB\Select;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use MiniOrange\OAuth\Model\ResourceModel\UserProvider\CollectionFactory;

/**
 * Virtual column that renders the live count of OIDC-linked users
 * per provider, filtered by user_type (admin or customer).
 *
 * Uses an INNER JOIN to the actual user table so that deleted users
 * are automatically excluded from the count.
 */
class ActiveUserCount extends Column
{
    private CollectionFactory $collectionFactory;

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

        $providerIds = array_column($dataSource['data']['items'], 'id');
        $counts = $this->getActiveCounts($providerIds, $userType);

        foreach ($dataSource['data']['items'] as &$item) {
            $item[$fieldName] = $counts[(int)$item['id']] ?? 0;
        }

        return $dataSource;
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
