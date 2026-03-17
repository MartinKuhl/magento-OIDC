<?php

namespace M2Oidc\OAuth\Model;

/**
 * Model for OIDC OAuth client application provider records.
 */
class M2oidcOauthClientApps extends \Magento\Framework\Model\AbstractModel
{
    /**
     * Initialize the resource model.
     */
    #[\Override]
    public function _construct(): void
    {
        $this->_init("M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps");
    }
}
