<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps\Grid;

use Magento\Framework\Api\Search\AggregationInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps\Collection as BaseCollection;

/**
 * Grid collection for the provider listing UI component.
 *
 * Extends the base collection and implements SearchResultInterface
 * as required by Magento's UI DataProvider::searchResultToOutput().
 *
 * Adds a computed `users_created` column via a LEFT JOIN subquery
 * that counts rows in m2oidc_oauth_user_provider per provider.
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
    #[\Override]
    protected function _renderFiltersBefore(): void
    {
        $connection = $this->getConnection();
        $subSelect = $connection->select()
            ->from(
                $this->getTable('m2oidc_oauth_user_provider'),
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
    #[\Override]
    public function setItems(?array $items = null): self
    {
        return $this;
    }

    /**
     * Get aggregations.
     *
     * @psalm-suppress InvalidNullableReturnType,NullableReturnStatement
     */
    #[\Override]
    public function getAggregations(): ?AggregationInterface
    {
        return $this->aggregations;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function setAggregations($aggregations): self
    {
        $this->aggregations = $aggregations;
        return $this;
    }

    /**
     * Get search criteria.
     *
     * @psalm-suppress InvalidNullableReturnType,NullableReturnStatement
     */
    #[\Override]
    public function getSearchCriteria(): ?SearchCriteriaInterface
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function setSearchCriteria(?SearchCriteriaInterface $searchCriteria = null): self
    {
        return $this;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getTotalCount(): int
    {
        return (int) $this->getSize();
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function setTotalCount($totalCount): self
    {
        return $this;
    }
}
