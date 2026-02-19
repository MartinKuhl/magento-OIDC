<?php
namespace MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    /**
     * Collection model for MiniOrange OAuth client apps.
     */
    #[\Override]
    public function _construct(): void
    {
        $this->_init(
            "MiniOrange\\OAuth\\Model\\MiniorangeOauthClientApps",
            "MiniOrange\\OAuth\\Model\\ResourceModel\\MiniorangeOauthClientApps"
        );
    }
}
