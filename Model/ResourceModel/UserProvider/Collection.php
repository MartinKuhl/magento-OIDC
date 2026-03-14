<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\ResourceModel\UserProvider;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use M2Oidc\OAuth\Model\UserProvider;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

/**
 * Collection for the m2oidc_oauth_user_provider mapping table.
 */
class Collection extends AbstractCollection
{
    /**
     * @inheritDoc
     */
    #[\Override]
    protected function _construct(): void
    {
        $this->_init(UserProvider::class, UserProviderResource::class);
    }
}
