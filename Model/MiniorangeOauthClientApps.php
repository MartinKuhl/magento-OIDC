<?php 
namespace MiniOrange\OAuth\Model;
class MiniorangeOauthClientApps extends \Magento\Framework\Model\AbstractModel{
	public function _construct(){
		$this->_init("MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps");
	}
}