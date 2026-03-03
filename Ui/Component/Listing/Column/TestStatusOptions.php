<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Ui\Component\Listing\Column;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Provides select options for the last_test_status filter in the provider grid.
 */
class TestStatusOptions implements OptionSourceInterface
{
    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => '',        'label' => __('-- Any --')],
            ['value' => 'success', 'label' => __('Success')],
            ['value' => 'failed',  'label' => __('Failed')],
        ];
    }
}
