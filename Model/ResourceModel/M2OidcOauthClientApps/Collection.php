<?php
namespace M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    /**
     * Collection model for M2Oidc OAuth client apps.
     */
    #[\Override]
    public function _construct(): void
    {
        $this->_init(
            "M2Oidc\\OAuth\\Model\\MiniorangeOauthClientApps",
            "M2Oidc\\OAuth\\Model\\ResourceModel\\MiniorangeOauthClientApps"
        );
    }
}
