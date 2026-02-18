<?php
namespace MiniOrange\OAuth\Model\ResourceModel;

class MiniOrangeOauthClientApps extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * Resource model for MiniOrange OAuth client apps.
     */
    public function _construct(): void
    {
        $this->_init("miniorange_oauth_client_apps", "id");
    }
}
