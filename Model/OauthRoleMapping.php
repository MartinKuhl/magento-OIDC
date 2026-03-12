<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Model for miniorange_oauth_role_mappings table.
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
        $this->_init(\MiniOrange\OAuth\Model\ResourceModel\OauthRoleMapping::class);
    }
}
