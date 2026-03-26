<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Ui\Component\DataProvider;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * UI DataProvider for the OIDC Session Activity grid.
 *
 * Executes a LEFT JOIN query across:
 *   - m2oidc_oauth_user_provider  (OIDC link records)
 *   - m2oidc_oauth_client_apps    (provider name)
 *   - customer_entity                 (customer email)
 *   - admin_user                      (admin email + last login)
 *   - customer_log                    (customer last login)
 *
 * The is_online field is computed via SQL EXISTS subqueries:
 *   - Admin:    active row in admin_user_session (status = 1)
 *   - Customer: customer_log row where last_login_at > last_logout_at and
 *               login is within the configured cookie/session lifetime
 *
 * Each row represents one user ↔ provider pairing, with the user's email
 * resolved from whichever Magento entity type applies.
 */
class SessionDataProvider extends AbstractDataProvider
{
    private const DEFAULT_PAGE_SIZE = 20;

    /** Config path for the frontend session / cookie lifetime in seconds (default 3600). */
    private const XML_PATH_COOKIE_LIFETIME = 'web/cookie/cookie_lifetime';

    /** @var ResourceConnection */
    private readonly ResourceConnection $resource;

    /** @var ScopeConfigInterface */
    private readonly ScopeConfigInterface $scopeConfig;

    /** @var array<string, mixed>|null Cached query result */
    private ?array $loadedData = null;

    /** @var int Current page (1-based) */
    private int $currentPage = 1;

    /** @var int Page size */
    private int $pageSize = self::DEFAULT_PAGE_SIZE;

    /** @var array<string, string> Sort orders: field => direction */
    private array $orders = ['up.created_at' => 'DESC'];

    /**
     * @param string               $name
     * @param string               $primaryFieldName
     * @param string               $requestFieldName
     * @param ResourceConnection   $resource
     * @param ScopeConfigInterface $scopeConfig
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        ResourceConnection $resource,
        ScopeConfigInterface $scopeConfig,
        array $meta = [],
        array $data = []
    ) {
        $this->resource    = $resource;
        $this->scopeConfig = $scopeConfig;
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * @inheritDoc
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }

        $connection        = $this->resource->getConnection();
        $upTable           = $this->resource->getTableName('m2oidc_oauth_user_provider');
        $appsTable         = $this->resource->getTableName('m2oidc_oauth_client_apps');
        $ceTable           = $this->resource->getTableName('customer_entity');
        $auTable           = $this->resource->getTableName('admin_user');
        $adminSessionTable   = $this->resource->getTableName('admin_user_session');
        $customerLogTable    = $this->resource->getTableName('customer_log');
        // Convert session lifetime (seconds) to minutes; fall back to 60 min.
        $cookieLifetimeSecs  = max(60, (int) $this->scopeConfig->getValue(self::XML_PATH_COOKIE_LIFETIME));
        $sessionMinutes      = (int) ceil($cookieLifetimeSecs / 60);

        $isOnlineExpr = new \Zend_Db_Expr(
            'CASE'
            . ' WHEN up.user_type = \'admin\' THEN'
            . ' IF(EXISTS(SELECT 1 FROM ' . $adminSessionTable
            . ' WHERE user_id = up.user_id AND status = 1), 1, 0)'
            . ' WHEN up.user_type = \'customer\' THEN'
            . ' IF(EXISTS(SELECT 1 FROM ' . $customerLogTable
            . ' WHERE customer_id = up.user_id'
            . ' AND last_login_at IS NOT NULL'
            . ' AND (last_logout_at IS NULL OR last_logout_at < last_login_at)'
            . ' AND last_login_at >= DATE_SUB(NOW(), INTERVAL ' . $sessionMinutes . ' MINUTE)), 1, 0)'
            . ' ELSE 0'
            . ' END'
        );

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
            )
            ->columns(['is_online' => $isOnlineExpr]);

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
            'is_online'        => 'is_online',
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
