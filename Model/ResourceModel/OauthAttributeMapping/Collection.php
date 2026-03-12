<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Model\ResourceModel\OauthAttributeMapping;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use MiniOrange\OAuth\Model\OauthAttributeMapping;
use MiniOrange\OAuth\Model\ResourceModel\OauthAttributeMapping as OauthAttributeMappingResource;

/**
 * Collection for miniorange_oauth_attribute_mappings.
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
