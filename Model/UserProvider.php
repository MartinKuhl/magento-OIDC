<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Model for the miniorange_oauth_user_provider mapping table.
 * Tracks which OIDC provider created each Magento user.
 */
class UserProvider extends AbstractModel
{
    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(\MiniOrange\OAuth\Model\ResourceModel\UserProvider::class);
    }
}
