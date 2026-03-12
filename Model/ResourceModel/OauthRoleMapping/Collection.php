<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Model\ResourceModel\OauthRoleMapping;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use MiniOrange\OAuth\Model\OauthRoleMapping;
use MiniOrange\OAuth\Model\ResourceModel\OauthRoleMapping as OauthRoleMappingResource;

/**
 * Collection for miniorange_oauth_role_mappings.
 */
class Collection extends AbstractCollection
{
    /**
     * @inheritDoc
     */
    #[\Override]
    protected function _construct(): void
    {
        $this->_init(OauthRoleMapping::class, OauthRoleMappingResource::class);
    }
}
