<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Ui\Component;

use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider as BaseDataProvider;

/**
 * UI Grid DataProvider for plain AbstractModel collections.
 *
 * The Magento base DataProvider calls getCustomAttributes() on each row, which
 * only works for EAV-backed AbstractExtensibleModel items. Our provider model
 * extends AbstractModel, so we override searchResultToOutput() to use getData().
 */
class DataProvider extends BaseDataProvider
{
    /**
     * @inheritDoc
     *
     * @return array<string, mixed>
     */
    protected function searchResultToOutput(SearchResultInterface $searchResult): array
    {
        $items = [];
        foreach ($searchResult->getItems() as $item) {
            /** @psalm-suppress UndefinedInterfaceMethod */
            // @phpstan-ignore-next-line
            $items[] = $item->getData();
        }
        return [
            'items'        => $items,
            'totalRecords' => $searchResult->getTotalCount(),
        ];
    }
}
