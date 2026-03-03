<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps\Grid;

use Magento\Framework\Api\Search\AggregationInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps\Collection as BaseCollection;

/**
 * Grid collection for the provider listing UI component.
 *
 * Extends the base collection and implements SearchResultInterface
 * as required by Magento's UI DataProvider::searchResultToOutput().
 *
 * Adds a computed `users_created` column via a LEFT JOIN subquery
 * that counts rows in miniorange_oauth_user_provider per provider.
 */
class Collection extends BaseCollection implements SearchResultInterface
{
    /** @var AggregationInterface|null */
    private ?AggregationInterface $aggregations = null;

    /**
     * Join a COUNT subquery to add the `users_created` virtual column.
     *
     * Called by the Magento collection infrastructure before filters are
     * rendered, giving us a safe place to extend the base SELECT.
     */
    protected function _renderFiltersBefore(): void
    {
        $connection = $this->getConnection();
        $subSelect = $connection->select()
            ->from(
                $this->getTable('miniorange_oauth_user_provider'),
                [
                    'provider_id',
                    'users_created' => new \Zend_Db_Expr('COUNT(*)'),
                ]
            )
            ->group('provider_id');

        $this->getSelect()->joinLeft(
            ['upc' => $subSelect],
            'main_table.id = upc.provider_id',
            ['users_created' => new \Zend_Db_Expr('COALESCE(upc.users_created, 0)')]
        );

        parent::_renderFiltersBefore();
    }

    /**
     * @inheritDoc
     */
    public function setItems(?array $items = null): self
    {
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getAggregations(): ?AggregationInterface
    {
        return $this->aggregations;
    }

    /**
     * @inheritDoc
     */
    public function setAggregations($aggregations): self
    {
        $this->aggregations = $aggregations;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getSearchCriteria(): ?SearchCriteriaInterface
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function setSearchCriteria(?SearchCriteriaInterface $searchCriteria = null): self
    {
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getTotalCount(): int
    {
        return (int) $this->getSize();
    }

    /**
     * @inheritDoc
     */
    public function setTotalCount($totalCount): self
    {
        return $this;
    }
}
