<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders the is_online field as a coloured HTML badge.
 *
 * Admin users: online when an active row exists in admin_user_session (status = 1).
 * Customer users: online when a visitor row was recorded within the configured
 *                 online-minutes interval.
 */
class OnlineStatus extends Column
{
    /**
     * @inheritDoc
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (isset($dataSource['data']['items'])) {
            $fieldName = $this->getData('name');
            foreach ($dataSource['data']['items'] as &$item) {
                $isOnline = (bool) ($item[$fieldName] ?? false);
                $item[$fieldName] = $isOnline
                    ? '<span style="color:#006400;font-weight:600;">&#9679; Online</span>'
                    : '<span style="color:#aaa;">&#9675; Offline</span>';
            }
        }

        return $dataSource;
    }
}
