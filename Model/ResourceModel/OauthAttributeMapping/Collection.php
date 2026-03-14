<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\ResourceModel\OauthAttributeMapping;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use M2Oidc\OAuth\Model\OauthAttributeMapping;
use M2Oidc\OAuth\Model\ResourceModel\OauthAttributeMapping as OauthAttributeMappingResource;

/**
 * Collection for m2oidc_oauth_attribute_mappings.
 */
class Collection extends AbstractCollection
{
    /**
     * @inheritDoc
     */
    #[\Override]
    protected function _construct(): void
    {
        $this->_init(OauthAttributeMapping::class, OauthAttributeMappingResource::class);
    }
}
