<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Virtual column: zeigt ob ein JWKS-Endpoint konfiguriert ist.
 * Liest jwks_uri direkt aus den Collection-Daten — kein extra DB-Query.
 */
class JwksStatus extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $fieldName = $this->getData('name');

        foreach ($dataSource['data']['items'] as &$item) {
            $jwksUri = trim((string) ($item['jwks_endpoint'] ?? ''));
            $item[$fieldName] = $this->renderBadge($jwksUri);
        }

        return $dataSource;
    }

    private function renderBadge(string $jwksUri): string
    {
        if ($jwksUri !== '') {
            return '<span style="color:#3c763d;font-weight:bold;">&#10003; configured</span>';
        }

        return '<span style="color:#999;">&#8212; not set</span>';
    }
}
