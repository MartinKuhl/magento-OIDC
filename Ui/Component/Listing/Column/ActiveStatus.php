<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Virtual column: renders a coloured Active/Inactive badge per provider row.
 * Reads is_active directly from the collection data — no extra DB query.
 */
class ActiveStatus extends Column
{
    /**
     * @inheritDoc
     */
    /**
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @inheritDoc
     *
     * @param  mixed[] $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $fieldName = $this->getData('name');

        foreach ($dataSource['data']['items'] as &$item) {
            $isActive = (bool)(int)($item['is_active'] ?? 1);
            $item[$fieldName] = $isActive
                ? '<span style="color:#3c763d;font-weight:bold;">&#9679; Active</span>'
                : '<span style="color:#c0392b;font-weight:bold;">&#9679; Inactive</span>';
        }

        return $dataSource;
    }
}
