<?php
namespace MiniOrange\OAuth\Model\ResourceModel;

class MiniOrangeOauthClientApps extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    public function _construct()
    {
        $this->_init("miniorange_oauth_client_apps", "id");
    }
}
