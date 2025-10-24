<?php

namespace MiniOrange\OAuth\Controller\Adminhtml\Account;

use Magento\Backend\App\Action\Context;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthMessages;
use MiniOrange\OAuth\Controller\Actions\BaseAdminAction;
use MiniOrange\OAuth\Helper\Curl;


/**
 * This class handles the action for endpoint: mospsaml/account/Index
 * Extends the \Magento\Backend\App\Action for Admin Actions which 
 * inturn extends the \Magento\Framework\App\Action\Action class necessary
 * for each Controller class
 */
class Index extends BaseAdminAction
{
    private $options = array (
        'registerNewUser',
        'loginExistingUser',
        'removeAccount');

    private $registerNewUserAction;
    private $loginExistingUserAction;
    public function __construct(\Magento\Backend\App\Action\Context $context,
                                \Magento\Framework\View\Result\PageFactory $resultPageFactory,
                                \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
                                \Magento\Framework\Message\ManagerInterface $messageManager,
                                \Psr\Log\LoggerInterface $logger,
                                \MiniOrange\OAuth\Controller\Actions\RegisterNewUserAction $registerNewUserAction,
                                \MiniOrange\OAuth\Controller\Actions\LoginExistingUserAction $loginExistingUserAction)
    {
        //You can use dependency injection to get any class this observer may need.
        parent::__construct($context,$resultPageFactory,$oauthUtility,$messageManager,$logger);
        $this->registerNewUserAction = $registerNewUserAction;
        $this->loginExistingUserAction = $loginExistingUserAction;
    }


    /**
     * The first function to be called when a Controller class is invoked. 
     * Usually, has all our controller logic. Returns a view/page/template 
     * to be shown to the users.
     *
     * This function gets and prepares all our SP config data from the 
     * database. It's called when you visis the moasaml/account/Index
     * URL. It prepares all the values required on the SP setting
     * page in the backend and returns the block to be displayed. 
     *
     * @return \Magento\Backend\Model\View\Result\Page
     */
    public function execute()
    {
        try{
            $params = $this->getRequest()->getParams();  //get params
            if($this->isFormOptionBeingSaved($params)) // check if form options are being saved
            {
                $keys 			= array_values($params);
                $operation 		= array_intersect($keys,$this->options);            
                if(count($operation) > 0) {  // route data and proccess
                    $this->_route_data(array_values($operation)[0],$params); 
                    $this->oauthUtility->flushCache();
                }
                $this->oauthUtility->reinitConfig();
            }   

        }catch(\Exception $e){
            $this->messageManager->addErrorMessage($e->getMessage());
  $this->oauthUtility->customlog($e->getMessage())  ;
        }
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu(OAuthConstants::MODULE_DIR.OAuthConstants::MODULE_BASE);
        $resultPage->addBreadcrumb(__('Account Settings'), __('Account Settings'));
        $resultPage->getConfig()->getTitle()->prepend(__(OAuthConstants::MODULE_TITLE));
        return $resultPage;
    }


    /**
	 * Route the request data to appropriate functions for processing.
	 * Check for any kind of Exception that may occur during processing 
	 * of form post data. Call the appropriate action.
	 *
	 * @param $op refers to operation to perform
	 * @param $params
	 */
	private function _route_data($op,$params)
	{
		switch ($op) 
		{
			case $this->options[0]:
				$this->registerNewUserAction->setRequestParam($params)
                    ->execute();						                    break;
            case $this->options[1]:
				$this->loginExistingUserAction->setRequestParam($params)
                     ->execute();                                           break;
            case $this->options[2]:
				$this->goBackToRegistrationPage(); 						    break;
		}
	}

    /**
     * Is the user allowed to view the Account settings.
     * This is based on the ACL set by the admin in the backend.
     * Works in conjugation with acl.xml
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(OAuthConstants::MODULE_DIR.OAuthConstants::MODULE_ACCOUNT);
    }

    private function goBackToRegistrationPage()
    {
        $this->oauthUtility->setStoreConfig(OAuthConstants::CUSTOMER_EMAIL,'');
        $this->oauthUtility->setStoreConfig(OAuthConstants::CUSTOMER_PHONE,'');
        $this->oauthUtility->setStoreConfig(OAuthConstants::REG_STATUS,'');
        $this->oauthUtility->setStoreConfig(OAuthConstants::TXT_ID,'');
        $this->oauthUtility->setStoreConfig(OAuthConstants::TOKEN,'');
        $this->oauthUtility->setStoreConfig(OAuthConstants::API_KEY,'');
        $this->oauthUtility->setStoreConfig(OAuthConstants::CUSTOMER_KEY,''); 
        $this->oauthUtility->setStoreConfig(OAuthConstants::CUSTOMER_NAME,''); 
        $this->oauthUtility->setStoreConfig(OAuthConstants::CUSTOMER_LNAME,''); 
        $this->oauthUtility->setStoreConfig(OAuthConstants::CUSTOMER_FNAME,''); 
    }
}