<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Model\ResourceModel\UserProvider;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use MiniOrange\OAuth\Model\UserProvider;
use MiniOrange\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

/**
 * Collection for the miniorange_oauth_user_provider mapping table.
 */
class Collection extends AbstractCollection
{
    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(UserProvider::class, UserProviderResource::class);
    }
}
