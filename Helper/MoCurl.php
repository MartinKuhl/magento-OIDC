<?php

namespace MiniOrange\OAuth\Helper;

/**
 * This class has been created so that we can override the default
 * behaviour of Magento where it sets verifyPeer and verifyHost
 * as true and create a new function which processes the
 * response coming so that headers can be removed.
 *
 * Keep veriyPeer as false at all times and verifyHost true
 * when pushing to production and false when testing against
 * the test environment.
 */
class MoCurl extends \Magento\Framework\HTTP\Adapter\Curl
{
    protected $_header;
    protected $_body;

    public function __construct()
    {
        /**
         * Check if the Magento Version is less than 2.2 then we have to initialize
         * the Curl Parent class first so that it can initialize the _config attributes.
         * Magento 2.2 and above doesn't have a constructor class so we can't invoke it
         * and will result in an error.
         */
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $productMetadata = $objectManager->get('Magento\Framework\App\ProductMetadataInterface');
        $version = $productMetadata->getVersion();
        if ($version < 2.2) {
            parent::__construct();
        }
        $this->_config['verifypeer'] = false;
        $this->_config['verifyhost'] = false;
        $this->_config['header'] = false;
    }
}
