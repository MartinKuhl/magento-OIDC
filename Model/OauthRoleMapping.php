<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Model for m2oidc_oauth_role_mappings table.
 *
 * Each row maps one OIDC group name to a Magento role ID (admin_role) or
 * customer group ID (customer_group) for a specific OIDC provider.
 * The mapping_type column distinguishes the two cases.
 */
class OauthRoleMapping extends AbstractModel
{
    /**
     * @inheritDoc
     */
    #[\Override]
    protected function _construct(): void
    {
        $this->_init(\M2Oidc\OAuth\Model\ResourceModel\OauthRoleMapping::class);
    }
}
