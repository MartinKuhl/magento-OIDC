<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Model for m2oidc_oauth_attribute_mappings table.
 *
 * Each row represents one OIDC claim → Magento attribute mapping for a specific
 * provider. The attribute_type column identifies which claim slot (e.g. 'email',
 * 'firstname', 'billing_city') while attribute_name holds the actual OIDC claim
 * key configured by the admin.
 */
class OauthAttributeMapping extends AbstractModel
{
    /**
     * @inheritDoc
     */
    #[\Override]
    protected function _construct(): void
    {
        $this->_init(\M2Oidc\OAuth\Model\ResourceModel\OauthAttributeMapping::class);
    }
}
