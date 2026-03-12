<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Ui\Component\DataProvider;

use Magento\Framework\App\ResourceConnection;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * UI DataProvider for the OIDC Session Activity grid.
 *
 * Executes a LEFT JOIN query across:
 *   - miniorange_oauth_user_provider  (OIDC link records)
 *   - miniorange_oauth_client_apps    (provider name)
 *   - customer_entity                 (customer email)
 *   - admin_user                      (admin email + last login)
 *   - customer_log                    (customer last login)
 *
 * Each row represents one user ↔ provider pairing, with the user's email
 * resolved from whichever Magento entity type applies.
 */
class SessionDataProvider extends AbstractDataProvider
{
    private const int DEFAULT_PAGE_SIZE = 20;

    /** @var ResourceConnection */
    private readonly ResourceConnection $resource;

    /** @var array|null Cached query result */
    private ?array $loadedData = null;

    /** @var int Current page (1-based) */
    private int $currentPage = 1;

    /** @var int Page size */
    private int $pageSize = self::DEFAULT_PAGE_SIZE;

    /** @var array<string, string> Sort orders: field => direction */
    private array $orders = ['up.created_at' => 'DESC'];

    /**
     * @param string             $name
     * @param string             $primaryFieldName
     * @param string             $requestFieldName
     * @param ResourceConnection $resource
     * @param array              $meta
     * @param array              $data
     */
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        ResourceConnection $resource,
        array $meta = [],
        array $data = []
    ) {
        $this->resource = $resource;
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * @inheritDoc
     */
    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }

        $connection = $this->resource->getConnection();
        $upTable    = $this->resource->getTableName('miniorange_oauth_user_provider');
        $appsTable  = $this->resource->getTableName('miniorange_oauth_client_apps');
        $ceTable    = $this->resource->getTableName('customer_entity');
        $auTable    = $this->resource->getTableName('admin_user');

        $select = $connection->select()
            ->from(['up' => $upTable], ['id', 'user_type', 'user_id', 'provider_id', 'created_at'])
            ->joinLeft(
                ['p' => $appsTable],
                'p.id = up.provider_id',
                ['provider_name' => 'COALESCE(p.display_name, p.app_name)']
            )
            ->joinLeft(
                ['ce' => $ceTable],
                'ce.entity_id = up.user_id AND up.user_type = \'customer\'',
                ['customer_email' => 'ce.email']
            )
            ->joinLeft(
                ['au' => $auTable],
                'au.user_id = up.user_id AND up.user_type = \'admin\'',
                ['admin_email' => 'au.email', 'admin_last_login' => 'au.logdate']
            )
            ->joinLeft(
                ['cl' => $this->resource->getTableName('customer_log')],
                'cl.customer_id = up.user_id AND up.user_type = \'customer\'',
                ['customer_last_login' => 'cl.last_login_at']
            );

        foreach ($this->orders as $field => $direction) {
            $select->order($field . ' ' . $direction);
        }

        $totalSelect = clone $select;
        $total = (int) $connection->fetchOne(
            $connection->select()->from(['t' => $totalSelect], ['COUNT(*)'])
        );

        $select->limitPage($this->currentPage, $this->pageSize);

        $rows = $connection->fetchAll($select);

        // Resolve unified email and last_login fields
        $items = array_map(static function (array $row): array {
            $row['user_email']  = $row['admin_email'] ?? $row['customer_email'] ?? '';
            $row['last_login']  = $row['admin_last_login'] ?? $row['customer_last_login'] ?? null;
            unset($row['admin_email'], $row['customer_email'], $row['admin_last_login'], $row['customer_last_login']);
            return $row;
        }, $rows);

        $this->loadedData = [
            'items'        => $items,
            'totalRecords' => $total,
        ];

        return $this->loadedData;
    }

    /**
     * @inheritDoc
     */
    public function setLimit(mixed $offset, mixed $size): void
    {
        $offset = (int) $offset;
        $size   = (int) $size;
        // AbstractDataProvider uses setLimit($offset, $size) where $offset is 0-based
        $this->currentPage = (int) floor($offset / max($size, 1)) + 1;
        $this->pageSize    = $size > 0 ? $size : self::DEFAULT_PAGE_SIZE;
        $this->loadedData  = null; // invalidate cache
    }

    /**
     * @inheritDoc
     *
     * Stores sort order for application in the SQL query; avoids collection use.
     */
    public function addOrder($field, $direction)
    {
        // Map grid column names to their qualified SQL equivalents
        $columnMap = [
            'id'               => 'up.id',
            'user_type'        => 'up.user_type',
            'user_id'          => 'up.user_id',
            'provider_id'      => 'up.provider_id',
            'created_at'       => 'up.created_at',
            'provider_name'    => 'provider_name',
            'user_email'       => 'user_email',
            'last_login'       => 'COALESCE(au.logdate, cl.last_login_at)',
        ];

        $sqlField = $columnMap[$field] ?? ('up.' . $field);
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

        $this->orders = [$sqlField => $direction];
        $this->loadedData = null;
    }

    /**
     * @inheritDoc
     *
     * The sessions grid is read-only — no collection is needed.
     *
     * @return never
     */
    public function getCollection()
    {
        throw new \LogicException('SessionDataProvider does not use a collection.');
    }
}
