<?php 
namespace MiniOrange\OAuth\Model\ResourceModel;
class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection{
	public function _construct(){
		$this->_init("MiniOrange\OAuth\Model\MiniorangeOauthClientApps","MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps");
	}
}