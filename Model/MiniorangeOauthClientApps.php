<?php

namespace MiniOrange\OAuth\Model;

/**
 * Model for MiniOrange OAuth client app records
 */
class MiniorangeOauthClientApps extends \Magento\Framework\Model\AbstractModel
{
    /**
     * Initialize resource model
     */
    public function _construct(): void
    {
        $this->_init("MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps");
    }
}
