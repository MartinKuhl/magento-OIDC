<?php

namespace M2Oidc\OAuth\Model;

/**
 * Model fofinal r M2Oidc OAuth client app records
 */
class MiniorangeOauthClientApps extends \Magento\Framework\Model\AbstractModel
{
    /**
     * Initialize resource model
     */
    #[\Override]
    public function _construct(): void
    {
        $this->_init("M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps");
    }
}
