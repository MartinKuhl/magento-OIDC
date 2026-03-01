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
 */
class Collection extends BaseCollection implements SearchResultInterface
{
    /** @var AggregationInterface|null */
    private ?AggregationInterface $aggregations = null;

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
