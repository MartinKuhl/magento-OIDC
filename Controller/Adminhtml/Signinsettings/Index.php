<?php

namespace MiniOrange\OAuth\Controller\Adminhtml\Signinsettings;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthMessages;
use MiniOrange\OAuth\Helper\OAuth\SAML2Utilities;
use MiniOrange\OAuth\Controller\Actions\BaseAdminAction;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\PageFactory;
use Magento\Customer\Model\ResourceModel\Group\Collection;
use Magento\Framework\Message\ManagerInterface;
use MiniOrange\OAuth\Helper\OAuthUtility;
use Psr\Log\LoggerInterface;



/**
 * This class handles the action for endpoint: mooauth/signinsettings/Index
 * Extends the \Magento\Backend\App\Action for Admin Actions which
 * inturn extends the \Magento\Framework\App\Action\Action class necessary
 * for each Controller class
 */
class Index extends BaseAdminAction implements HttpPostActionInterface, HttpGetActionInterface
{
    /**
     * The first function to be called when a Controller class is invoked.
     * Usually, has all our controller logic. Returns a view/page/template
     * to be shown to the users.
     *
     * This function gets and prepares all our SP config data from the
     * database. It's called when you visis the moasaml/signinsettings/Index
     * URL. It prepares all the values required on the SP setting
     * page in the backend and returns the block to be displayed.
     *
     * @return \Magento\Backend\Model\View\Result\Page
     */
    private $userGroupModel;
    protected $fileFactory;
    protected $_storeManager;
    /**
     * @var \Magento\Framework\App\ProductMetadataInterface
     */
    private $productMetadata;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        OAuthUtility $oauthUtility,
        ManagerInterface $messageManager,
        LoggerInterface $logger,
        Collection $userGroupModel,
        \Magento\Framework\App\Response\Http\FileFactory $fileFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\ProductMetadataInterface $productMetadata
    ) {
        //You can use dependency injection to get any class this observer may need.
        parent::__construct($context, $resultPageFactory, $oauthUtility, $messageManager, $logger);
        $this->_storeManager = $storeManager;
        $this->fileFactory = $fileFactory;
        $this->userGroupModel = $userGroupModel;
        $this->productMetadata = $productMetadata; 
    }
    public function execute()
    {   
        try {
            $params = $this->getRequest()->getParams(); //get params

            // check if form options are being saved
            if ($this->isFormOptionBeingSaved($params)) {
                if($params['option']=='saveSingInSettings')
                {$this->processValuesAndSaveData($params);
                $this->oauthUtility->flushCache();
                $this->messageManager->addSuccessMessage(OAuthMessages::SETTINGS_SAVED);
                $this->oauthUtility->reinitConfig();
                }
                elseif($params['option']=='enable_debug_log') {
                    $debug_log_on = isset($params['debug_log_on']) ? 1 : 0;
                    $log_file_time = time();
                    $this->oauthUtility->setStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG, $debug_log_on);
                    $this->oauthUtility->flushCache();
                    $this->messageManager->addSuccessMessage(OAuthMessages::SETTINGS_SAVED);
                    $this->oauthUtility->reinitConfig();
                    if($debug_log_on == '1')
                    {
                    $this->oauthUtility->setStoreConfig(OAuthConstants::LOG_FILE_TIME,  $log_file_time);
                    }elseif($debug_log_on == '0' && $this->oauthUtility->isCustomLogExist()){
                        $this->oauthUtility->setStoreConfig(OAuthConstants::LOG_FILE_TIME, NULL);
                        $this->oauthUtility->deleteCustomLogFile();
                    }
                }elseif($params['option']=='clear_download_logs'){

                    if(isset($params['download_logs'])) {

                    $fileName = "mo_oauth.log"; // add your file name here
                    if ($fileName) {
                        $filePath = '../var/log/' . $fileName;
                        $content['type'] = 'filename';// type has to be "filename"
                        $content['value'] = $filePath; // path where file place
                        $content['rm'] = 0; // if you add 1 then it will be delete from server after being download, otherwise add 0.
                        if($this->oauthUtility->isLogEnable())
                        { 
                            //Customer Configuration settings.
                            $appName = $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);

                            $clientDetails = null;
                            $collection = $this->oauthUtility->getOAuthClientApps();
                            $this->oauthUtility->log_debug("SendAuthorizationRequest: collection :", count($collection));
                            foreach ($collection as $item) {
                                if ($item->getData()["app_name"] === $appName) {
                                    $clientDetails = $item->getData();
                                }
                            }
                            if($clientDetails){
                            $scope = $clientDetails["scope"];
                            $header = $clientDetails["values_in_header"];
                            $body = $clientDetails["values_in_body"];
                            $authorize_url = $clientDetails['authorize_endpoint'];
                            $accesstoken_url = $clientDetails['access_token_endpoint'];
                            $getuserinfo_url = $clientDetails['user_info_endpoint'];
                            $endpoint_url = $clientDetails['well_known_config_url'];
                            $show_customer_link = $this->oauthUtility->getStoreConfig(OAuthConstants::SHOW_CUSTOMER_LINK);
                            $attribute_email = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_EMAIL );
                            $attribute_username = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_USERNAME);
                            $customer_email = $this->oauthUtility->getStoreConfig(OAuthConstants::DEFAULT_MAP_EMAIL);
                            $plugin_version = OAuthConstants::VERSION;
                           $magento_version =  $this->productMetadata->getVersion(); 
                           $php_version =phpversion();
                            $values = array($appName,$scope, $authorize_url,$accesstoken_url,$getuserinfo_url, $header,$body,$endpoint_url, $show_customer_link,$attribute_email,$attribute_username,$customer_email,$plugin_version,$magento_version,$php_version);
                            //save configuration
                            $this->customerConfigurationSettings($values);
                        }

                        }
                     if($this->oauthUtility->isCustomLogExist() && $this->oauthUtility->isLogEnable())
                     {               
                        return $this->fileFactory->create($fileName, $content, DirectoryList::VAR_DIR);
                     }

                   else
                   {
                    $this->messageManager->addErrorMessage('Please Enable Debug Log Setting First');

                   }
                    } else {
                        $this->messageManager->addErrorMessage('Something went wrong');

                    }
                }
                elseif(isset($params['clear_logs'])){
                    if($this->oauthUtility->isCustomLogExist()){
                        $this->oauthUtility->setStoreConfig(OAuthConstants::LOG_FILE_TIME, NULL);
                        $this->oauthUtility->deleteCustomLogFile();
                        $this->messageManager->addSuccessMessage('Logs Cleared Successfully');
                    }else{
                        $this->messageManager->addSuccessMessage('Logs Have Already Been Removed');
                    }

                }
                }
                
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->oauthUtility->customlog($e->getMessage()) ;
        }
        // generate page
        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend(__(OAuthConstants::MODULE_TITLE));
        return $resultPage;
    }


    /**
     * Process Values being submitted and save data in the database.
     */
    private function processValuesAndSaveData($params)
    {
        $mo_oauth_show_customer_link = isset($params['mo_oauth_show_customer_link']) ? 1 : 0;
        $mo_oauth_show_admin_link = isset($params['mo_oauth_show_admin_link']) ? 1 : 0;
        
        $this->oauthUtility->customlog("SignInSettings: Saving customer link setting: " . $mo_oauth_show_customer_link);
        $this->oauthUtility->customlog("SignInSettings: Saving admin link setting: " . $mo_oauth_show_admin_link);
        
        $this->oauthUtility->setStoreConfig(OAuthConstants::SHOW_CUSTOMER_LINK, $mo_oauth_show_customer_link);
        $this->oauthUtility->setStoreConfig(OAuthConstants::SHOW_ADMIN_LINK, $mo_oauth_show_admin_link);
        
        // Stellen Sie sicher, dass die Config-Werte gespeichert wurden
        $this->oauthUtility->customlog("SignInSettings: Saved customer link value: " . $this->oauthUtility->getStoreConfig(OAuthConstants::SHOW_CUSTOMER_LINK));
        $this->oauthUtility->customlog("SignInSettings: Saved admin link value: " . $this->oauthUtility->getStoreConfig(OAuthConstants::SHOW_ADMIN_LINK));
    }

private function customerConfigurationSettings( $values)
{   $this->oauthUtility->customlog("......................................................................");
    $this->oauthUtility->customlog("Plugin: OAuth Free : ".$values[12]);
    $this->oauthUtility->customlog("Plugin: Magento version : ".$values[13]." ; Php version: ".$values[14]);
    $this->oauthUtility->customlog("Appname: ".$values[0]);
    $this->oauthUtility->customlog("Scope: ".$values[1]);
    $this->oauthUtility->customlog("Authorize_url: ".$values[2]);
    $this->oauthUtility->customlog("Accesstoken_url: ".$values[3]);
    $this->oauthUtility->customlog("Getuserinfo_url: ".$values[4]);
    $this->oauthUtility->customlog("Header: ".$values[5]);
    $this->oauthUtility->customlog("Body: ".$values[6]);
    $this->oauthUtility->customlog("Well known config url: ".$values[7]);
    $this->oauthUtility->customlog("Show_customer_link: ".$values[8]);
    $this->oauthUtility->customlog("Attribute_email: ".$values[9]);
    $this->oauthUtility->customlog("Attribute_username: ".$values[10]);
    $this->oauthUtility->customlog("Customer_email: ".$values[11]);
    $this->oauthUtility->customlog("......................................................................");
}

    /**
     * Is the user allowed to view the Sign in Settings.
     * This is based on the ACL set by the admin in the backend.
     * Works in conjugation with acl.xml
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(OAuthConstants::MODULE_DIR.OAuthConstants::MODULE_SIGNIN);
    }
}
