<?php

namespace MiniOrange\OAuth\Helper;

use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Url;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\User\Model\UserFactory;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\Curl;
use MiniOrange\OAuth\Helper\Data;
use MiniOrange\OAuth\Helper\Exception\InvalidOperationException;
use MiniOrange\OAuth\Helper\OAuth\SAML2Utilities;
use MiniOrange\OAuth\Helper\OAuth\Lib\AESEncryption;
use Magento\Framework\Stdlib\DateTime\dateTime;


/**
 * This class contains some common Utility functions
 * which can be called from anywhere in the module. This is
 * mostly used in the action classes to get any utility
 * function or data from the database.
 */
class OAuthUtility extends Data
{
    protected $adminSession;
    protected $customerSession;
    protected $authSession;
    protected $cacheTypeList;
    protected $cacheFrontendPool;
    protected $fileSystem;
    protected $logger;
    protected $reinitableConfig;
    protected $_logger;
    protected $productMetadata;
    protected $dateTime;


    public function __construct(
        ScopeConfigInterface $scopeConfig,
        UserFactory $adminFactory,
        CustomerFactory $customerFactory,
        UrlInterface $urlInterface,
        WriterInterface $configWriter,
        Repository $assetRepo,
        \Magento\Backend\Helper\Data $helperBackend,
        Url $frontendUrl,
        \Magento\Backend\Model\Session $adminSession,
        Session $customerSession,
        \Magento\Backend\Model\Auth\Session $authSession,
        \Magento\Framework\App\Config\ReinitableConfigInterface $reinitableConfig,
        TypeListInterface $cacheTypeList,
        Pool $cacheFrontendPool,
        \Psr\Log\LoggerInterface $logger,
        \MiniOrange\OAuth\Logger\Logger $logger2,
        File $fileSystem,
        \Magento\Framework\App\ProductMetadataInterface $productMetadata,
        \MiniOrange\OAuth\Model\MiniorangeOauthClientAppsFactory $miniorangeOauthClientAppsFactory,
        dateTime $dateTime
    ) {
                        $this->adminSession = $adminSession;
                        $this->customerSession = $customerSession;
                        $this->authSession = $authSession;
                        $this->cacheTypeList = $cacheTypeList;
                        $this->cacheFrontendPool = $cacheFrontendPool;
                        $this->fileSystem = $fileSystem;
                        $this->logger = $logger;
                        $this->reinitableConfig = $reinitableConfig;
                        $this->_logger = $logger2;
                        $this->productMetadata = $productMetadata;
                        $this->dateTime=$dateTime;
                       parent::__construct(
                           $scopeConfig,
                           $adminFactory,
                           $customerFactory,
                           $urlInterface,
                           $configWriter,
                           $assetRepo,
                           $helperBackend,
                           $frontendUrl,
                           $miniorangeOauthClientAppsFactory
                       );
    }

    /**
     * This function returns phone number as a obfuscated
     * string which can be used to show as a message to the user.
     *
     * @param $phone references the phone number.
     * @return string
     */
    public function getHiddenPhone($phone)
    {
        $hidden_phone = 'xxxxxxx' . substr($phone, strlen($phone) - 3);
        return $hidden_phone;
    }

    //CUSTOM LOG FILE OPERATION
    /**
     * This function print custom log in var/log/mo_oauth.log file.
     */
    public function customlog($txt)
    {   
        $this->isLogEnable() ? $this->_logger->debug($txt): NULL; 
    }
       /**
    * This function check whether any custom log file exist or not. 
     */ 
    public function isCustomLogExist()
    {   if($this->fileSystem->isExists("../var/log/mo_oauth.log")){
        return 1;
    }elseif($this->fileSystem->isExists("var/log/mo_oauth.log")){
        return 1;
    }
      return 0; 
    }
    
    public function deleteCustomLogFile()
    {if($this->fileSystem->isExists("../var/log/mo_oauth.log")){
        $this->fileSystem->deleteFile("../var/log/mo_oauth.log");
    }elseif($this->fileSystem->isExists("var/log/mo_oauth.log")){
        $this->fileSystem->deleteFile("var/log/mo_oauth.log");
    }
}
    /**
     * This function checks if a value is set or
     * empty. Returns true if value is empty
     *
     * @return True or False
     * @param $value //references the variable passed.
     */
    public function isBlank($value)
    {
        if (! isset($value) || empty($value)) {
            return true;
        }
        return false;
    }


    /**
     * This function checks if cURL has been installed
     * or enabled on the site.
     *
     * @return True or False
     */
    public function isCurlInstalled()
    {
        if (in_array('curl', get_loaded_extensions())) {
            return 1;
        } else {
            return 0;
        }
    }


    /**
     * This function checks if the phone number is in the correct format or not.
     *
     * @param $phone refers to the phone number entered
     * @return bool
     */
    public function validatePhoneNumber($phone)
    {
        if (!preg_match(MoIDPConstants::PATTERN_PHONE, $phone, $matches)) {
            return false;
        } else {
            return true;
        }
    }


    /**
     * This function is used to obfuscate and return
     * the email in question.
     *
     * @param $email //refers to the email id to be obfuscated
     * @return string obfuscated email id.
     */
    public function getHiddenEmail($email)
    {
        if (!isset($email) || trim($email)==='') {
            return "";
        }

        $emailsize = strlen($email);
        $partialemail = substr($email, 0, 1);
        $temp = strrpos($email, "@");
        $endemail = substr($email, $temp-1, $emailsize);
        for ($i=1; $i<$temp; $i++) {
            $partialemail = $partialemail . 'x';
        }

        $hiddenemail = $partialemail . $endemail;

        return $hiddenemail;
    }
/***
 * @return \Magento\Backend\Model\Session
 */
    public function getAdminSession()
    {
        return $this->adminSession;
    }

    /**
     * set Admin Session Data
     *
     * @param $key
     * @param $value
     * @return
     */
    public function setAdminSessionData($key, $value)
    {
        return $this->adminSession->setData($key, $value);
    }


    /**
     * get Admin Session data based of on the key
     *
     * @param $key
     * @param $remove
     * @return mixed
     */
    public function getAdminSessionData($key, $remove = false)
    {
        return $this->adminSession->getData($key, $remove);
    }


    /**
     * set customer Session Data
     *
     * @param $key
     * @param $value
     * @return
     */
    public function setSessionData($key, $value)
    {
        return $this->customerSession->setData($key, $value);
    }


    /**
     * Get customer Session data based off on the key
     *
     * @param $key
     * @param $remove
     */
    public function getSessionData($key, $remove = false)
    {
        return $this->customerSession->getData($key, $remove);
    }


    /**
     * Set Session data for logged in user based on if he/she
     * is in the backend of frontend. Call this function only if
     * you are not sure where the user is logged in at.
     *
     * @param $key
     * @param $value
     */
    public function setSessionDataForCurrentUser($key, $value)
    {
        if ($this->customerSession->isLoggedIn()) {
            $this->setSessionData($key, $value);
        } elseif ($this->authSession->isLoggedIn()) {
            $this->setAdminSessionData($key, $value);
        }
    }


    /**
     * Check if the admin has configured the plugin with
     * the Identity Provier. Returns true or false
     */
    public function isOAuthConfigured()
    {
        $loginUrl = $this->getStoreConfig(OAuthConstants::AUTHORIZE_URL);
        return $this->isBlank($loginUrl) ? false : true;
    }


    /**
     * This function is used to check if customer has completed
     * the registration process. Returns TRUE or FALSE. Checks
     * for the email and customerkey in the database are set
     * or not.
     */
    public function micr()
    {
              $email = $this->getStoreConfig(OAuthConstants::CUSTOMER_EMAIL);
        $key = $this->getStoreConfig(OAuthConstants::CUSTOMER_KEY);
        return !$this->isBlank($email) && !$this->isBlank($key) ? true : false;
    }


    /**
     * Check if there's an active session of the user
     * for the frontend or the backend. Returns TRUE
     * or FALSE
     */
    public function isUserLoggedIn()
    {
        return $this->customerSession->isLoggedIn()
                || $this->authSession->isLoggedIn();
    }

    /**
     * Get the Current Admin User who is logged in
     */
    public function getCurrentAdminUser()
    {
        return $this->authSession->getUser();
    }


    /**
     * Get the Current Admin User who is logged in
     */
    public function getCurrentUser()
    {
        return $this->customerSession->getCustomer();
    }


    /**
     * Get the admin login url
     */
    public function getAdminLoginUrl()
    {
        return $this->getAdminUrl('adminhtml/auth/login');
    }

    /**
     * Get the admin page url
     */
    public function getAdminPageUrl()
    {
            return $this->getAdminBaseUrl();
    }

    /**
     * Get the customer login url
     */
    public function getCustomerLoginUrl()
    {
        return $this->getUrl('customer/account/login');
    }

    /**
     * Get is Test Configuration clicked
     */
    public function getIsTestConfigurationClicked()
    {
        return $this->getStoreConfig(OAuthConstants::IS_TEST);
    }


    /**
     * Flush Magento Cache. This has been added to make
     * sure the admin/user has a smooth experience and
     * doesn't have to flush his cache over and over again
     * to see his changes.
     */
    public function flushCache($from = "")
    {

        $types = ['db_ddl']; // we just need to clear the database cache

        foreach ($types as $type) {
            $this->cacheTypeList->cleanType($type);
        }

        foreach ($this->cacheFrontendPool as $cacheFrontend) {
            $cacheFrontend->getBackend()->clean();
        }
    }


    /**
     * Get data in the file specified by the path
     */
    public function getFileContents($file)
    {
        return $this->fileSystem->fileGetContents($file);
    }


    /**
     * Put data in the file specified by the path
     */
    public function putFileContents($file, $data)
    {
        $this->fileSystem->filePutContents($file, $data);
    }


    /**
     * Get the Current User's logout url
     */
    public function getLogoutUrl()
    {
        if ($this->customerSession->isLoggedIn()) {
            return $this->getUrl('customer/account/logout');
        }
        if ($this->authSession->isLoggedIn()) {
            return $this->getAdminUrl('adminhtml/auth/logout');
        }
        return '/';
    }


    /**
     * Get/Create Callback URL of the site
     */
    public function getCallBackUrl()
    {
        return $this->getBaseUrl() . OAuthConstants::CALLBACK_URL;
    }

    public function removeSignInSettings()
    {
            $this->setStoreConfig(OAuthConstants::SHOW_CUSTOMER_LINK, 0);
            $this->setStoreConfig(OAuthConstants::SHOW_ADMIN_LINK, 0);
    }
    public function reinitConfig(){

            $this->reinitableConfig->reinit();
    }

        /**
     * This function is used to check if customer has completed
     * the registration process. Returns TRUE or FALSE. Checks
     * for the email and customerkey in the database are set
     * or not. Then checks if license key has been verified.
     */
	public function mclv()
	{
		$token = $this->getStoreConfig(OAuthConstants::TOKEN);
		$isVerified = AESEncryption::decrypt_data($this->getStoreConfig(OAuthConstants::SAMLSP_CKL),$token);
		$licenseKey = $this->getStoreConfig(OAuthConstants::SAMLSP_LK);
		return $isVerified == "true" ? TRUE : FALSE;
	}
    
    /**
     * Check if debug logging is enabled
     * Uses the existing configuration path from the database
     * 
     * @return bool
     */
    public function isLogEnable()
    {
        // Verwende die existierende Konfiguration über die Konstante
        $configValue = $this->getStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG);
        
        // Wenn die neue Config existiert, bevorzuge diese
        $newConfigValue = $this->getStoreConfig('miniorange/oauth/debug_log');
        if ($newConfigValue !== null) {
            return (bool)$newConfigValue;
        }
        
        // Fallback auf alte Konfiguration
        return (bool)$configValue;
    }

    /**
     *Common Log Method .. Accessible in all classes through
     **/
    public function log_debug($msg="", $obj=null){

        if(is_object($msg)){
             $this->customlog("MO OAuth Free : ".print_r($obj,true)) ;
        }else{
             $this->customlog("MO OAuth Free : ".$msg) ;

        }

        if($obj!=null){
         $this->customlog("MO OAuth Free : ".var_export($obj,true))  ;


        }
    }

    /**
     * Get client details
     * used in ShowTestResultsAction.php
     */
    public function getClientDetails()
    {
        $appName = $this->getStoreConfig(OAuthConstants::APP_NAME);       
        $clientDetails = null;
        $collection = $this->getOAuthClientApps();
        $this->log_debug("SendAuthorizationRequest: collection :", count($collection));
        foreach ($collection as $item) {
            if ($item->getData()["app_name"] === $appName) {
                $clientDetails = $item->getData();
            }
        }
        $clientID = $clientDetails["clientID"];
        $clientSecret = $clientDetails["client_secret"];
        $accesstoken_url = $clientDetails["access_token_endpoint"];
        $scope = $clientDetails["scope"];
        $header = $clientDetails["values_in_header"];
        $body = $clientDetails["values_in_body"];
        $getuserinfo_url =$clientDetails['user_info_endpoint'];
        $authorize_url = $clientDetails['authorize_endpoint'];
        $endpoint_url = $clientDetails['well_known_config_url'];
        $show_customer_link = $this->getStoreConfig(OAuthConstants::SHOW_CUSTOMER_LINK);
        $attribute_email = $this->getStoreConfig(OAuthConstants::MAP_EMAIL );
        $attribute_username = $this->getStoreConfig(OAuthConstants::MAP_USERNAME);
        $customer_email = $this->getStoreConfig(OAuthConstants::DEFAULT_MAP_EMAIL);
        return array($appName,$scope,$clientID,$clientSecret,$authorize_url,$accesstoken_url,$getuserinfo_url, $header,$body,$endpoint_url, $show_customer_link,$attribute_email,$attribute_username,$customer_email);
    }

    public function getProductVersion(){
        return  $this->productMetadata->getVersion(); 
    }

    public function getEdition(){
        return $this->productMetadata->getEdition() == 'Community' ? 'Magento Open Source':'Adobe Commerce Enterprise/Cloud';
    }

    public function getCurrentDate(){
        $dateTimeZone = new \DateTimeZone('Asia/Calcutta'); 
        $dateTime = new \DateTime('now', $dateTimeZone);
        return $dateTime->format('n/j/Y, g:i:s a');
    }
}
