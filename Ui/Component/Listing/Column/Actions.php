<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Actions column renderer for the OIDC Provider grid.
 *
 * Adds Edit and Delete links for each provider row.
 */
class Actions extends Column
{
    /** @var UrlInterface */
    private UrlInterface $urlBuilder;

    /**
     * @param ContextInterface    $context
     * @param UiComponentFactory  $uiComponentFactory
     * @param UrlInterface        $urlBuilder
     * @param array               $components
     * @param array               $data
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
     * Add Edit and Delete URLs to each grid row.
     *
     * @param  array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            $id = (int) ($item['id'] ?? 0);

            $item[$this->getData('name')] = [
                'edit' => [
                    'href'  => $this->urlBuilder->getUrl('mooauth/provider/edit', ['id' => $id]),
                    'label' => __('Edit'),
                ],
                'delete' => [
                    'href'    => $this->urlBuilder->getUrl('mooauth/provider/delete', ['id' => $id]),
                    'label'   => __('Delete'),
                    'confirm' => [
                        'title'   => __('Delete Provider'),
                        'message' => __('Are you sure you want to delete this OIDC provider?'),
                    ],
                    'post' => true,
                ],
            ];
        }

        return $dataSource;
    }
}
