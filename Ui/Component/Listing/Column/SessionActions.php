<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Actions column renderer for the OIDC Session Activity grid.
 *
 * Adds a Delete link for each session record row.
 */
class SessionActions extends Column
{
    /** @var UrlInterface */
    private UrlInterface $urlBuilder;

    /**
     * @param ContextInterface   $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface       $urlBuilder
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Add Delete URL to each grid row.
     *
     * @param  array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            $id = (int) ($item['id'] ?? 0);

            $item[$this->getData('name')] = [
                'delete' => [
                    'href'    => $this->urlBuilder->getUrl('m2oidc/sessions/delete', ['id' => $id]),
                    'label'   => __('Delete'),
                    'confirm' => [
                        'title'   => __('Delete Session Record'),
                        'message' => __('Are you sure you want to delete session record #%1?', $id),
                    ],
                    'post' => true,
                ],
            ];
        }

        return $dataSource;
    }
}
