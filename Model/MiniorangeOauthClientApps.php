<?php

namespace MiniOrange\OAuth\Model;

/**
 * Model for MiniOrange OAuth client app records
 */
class MiniorangeOauthClientApps extends \Magento\Framework\Model\AbstractModel
{
    /**
     * Initialize resource model
     *
     * @return void
     */
    public function _construct()
    {
        $this->_init("MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps");
    }
}
