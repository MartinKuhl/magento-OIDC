<?php

namespace M2Oidc\OAuth\Model;

class M2oidcOauthClientApps extends \Magento\Framework\Model\AbstractModel
{
    #[\Override]
    public function _construct(): void
    {
        $this->_init("M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps");
    }
}
