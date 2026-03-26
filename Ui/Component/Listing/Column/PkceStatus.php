<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Virtual column: rendert ein farbiges PKCE-Badge pro Provider-Zeile.
 * Liest pkce_flow direkt aus den Collection-Daten — kein extra DB-Query.
 */
class PkceStatus extends Column
{
    /**
     * Override to inject dependencies for OIDC PKCE status column rendering.
     *
     * @param ContextInterface   $context
     * @param UiComponentFactory $uiComponentFactory
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
     * @param  array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $fieldName = $this->getData('name');

        foreach ($dataSource['data']['items'] as &$item) {
            $pkceFlow = (string) ($item['pkce_flow'] ?? '');
            $item[$fieldName] = $this->renderBadge($pkceFlow);
        }

        return $dataSource;
    }

    /**
     * Render an HTML badge for the given PKCE flow value.
     *
     * @param string $pkceFlow 'S256' | 'plain' | ''
     * @return string HTML-Badge (kein User-Input — sicher ohne escaping)
     */
    private function renderBadge(string $pkceFlow): string
    {
        return match ($pkceFlow) {
            'S256'  => '<span style="color:#3c763d;font-weight:bold;">&#10003; S256</span>',
            'plain' => '<span style="color:#8a6d3b;font-weight:bold;">&#9888; plain</span>',
            default => '<span style="color:#999;">&#8212; disabled</span>',
        };
    }
}
