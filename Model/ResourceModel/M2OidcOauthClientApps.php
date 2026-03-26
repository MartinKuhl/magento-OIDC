<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\ResourceModel;

class M2OidcOauthClientApps extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * Resource model for M2Oidc OAuth client apps.
     */
    #[\Override]
    public function _construct(): void
    {
        $this->_init("m2oidc_oauth_client_apps", "id");
    }
}
