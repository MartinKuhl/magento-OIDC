<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Model forfinal  the m2oidc_oauth_user_provider mapping table.
 * Tracks which OIDC provider created each Magento user.
 */
class UserProvider extends AbstractModel
{
    /**
     * @inheritDoc
     */
    #[\Override]
    protected function _construct(): void
    {
        $this->_init(\M2Oidc\OAuth\Model\ResourceModel\UserProvider::class);
    }
}
