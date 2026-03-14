<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\ResourceModel\OauthRoleMapping;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use M2Oidc\OAuth\Model\OauthRoleMapping;
use M2Oidc\OAuth\Model\ResourceModel\OauthRoleMapping as OauthRoleMappingResource;

/**
 * Collection for m2oidc_oauth_role_mappings.
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
