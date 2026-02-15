<?php

namespace MiniOrange\OAuth\Helper;

use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Url;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\User\Model\UserFactory;

use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\Data;
use Magento\Framework\Stdlib\DateTime\DateTime;

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
    protected $directoryList;


    /**
     * Initialize OAuthUtility helper.
     *
     * @param \Magento\Framework\App\Helper\Context $context
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */
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
        \MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps\CollectionFactory $clientCollectionFactory,
        \MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps $appResource,
        \Magento\User\Model\ResourceModel\User $userResource,
        \Magento\Customer\Model\ResourceModel\Customer $customerResource,
        DateTime $dateTime,
        DirectoryList $directoryList,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        \Magento\Framework\Escaper $escaper
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
        $this->dateTime = $dateTime;
        $this->directoryList = $directoryList;
        parent::__construct(
            $scopeConfig,
            $adminFactory,
            $customerFactory,
            $urlInterface,
            $configWriter,
            $assetRepo,
            $helperBackend,
            $frontendUrl,
            $miniorangeOauthClientAppsFactory,
            $clientCollectionFactory,
            $appResource,
            $userResource,
            $customerResource,
            $encryptor,
            $escaper
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
        $this->isLogEnable() ? $this->_logger->debug($txt) : null;
    }
    /**
     * This function check whether any custom log file exist or not.
     */
    public function isCustomLogExist()
    {
        try {
            $logPath = $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/log/mo_oauth.log';
            if ($this->fileSystem->isExists($logPath)) {
                return 1;
            }
        } catch (\Exception $e) {
            // Ignore path errors
        }
        return 0;
    }

    /**
     * Check if customer account exists by email.
     *
     * @param string $email
     * @return bool
     */
    public function deleteCustomLogFile()
    {
        try {
            $logPath = $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/log/mo_oauth.log';
            if ($this->fileSystem->isExists($logPath)) {
                $this->fileSystem->deleteFile($logPath);
            }
        } catch (\Exception $e) {
            // Ignore path errors
        }
    }

    /**
     * Check if a value is empty or not set.
     *
     * @param mixed $value
     * @return bool
     */
    public function isBlank(mixed $value): bool
    {
        return empty($value);
    }

    /**
     * This function checks if cURL has been installed
     * or enabled on the site.
     *
     * Magento 2 uses \Magento\Framework\HTTP\Adapter\Curl for HTTP requests,
     * but verifying curl_init availability is a standard check for the underlying library.
     *
     * @return int
     */
    public function isCurlInstalled()
    {
        return function_exists('curl_init') ? 1 : 0;
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
        if (!isset($email) || trim($email) === '') {
            return "";
        }

        $emailsize = strlen($email);
        $partialemail = substr($email, 0, 1);
        $temp = strrpos($email, "@");
        $endemail = substr($email, $temp - 1, $emailsize);
        for ($i = 1; $i < $temp; $i++) {
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
     * @return bool
     * @psalm-suppress PossiblyUnusedMethod – Called from templates
     */
    public function getIsTestConfigurationClicked()
    {
        return $this->getStoreConfig(OAuthConstants::IS_TEST);
    }


    /**
     * Flush Magento Cache.
     *
     * @psalm-suppress PossiblyUnusedParam – $from used for future logging
     */
    public function flushCache(string $from = ""): void
    {
        $types = ['db_ddl'];

        foreach ($types as $type) {
            $this->cacheTypeList->cleanType($type);
        }

        foreach ($this->cacheFrontendPool as $cacheFrontend) {
            $cacheFrontend->getBackend()->clean();
        }
    }


    /**
     * Get data in the file specified by the path
     *
     * @param string $file
     * @return string
     * @psalm-suppress PossiblyUnusedMethod – Called dynamically
     */
    public function getFileContents($file)
    {
        return $this->fileSystem->fileGetContents($file);
    }

    /**
     * Put data in the file specified by the path
     *
     * @param string $file
     * @param string $data
     * @return void
     * @psalm-suppress PossiblyUnusedMethod – Called dynamically
     */
    public function putFileContents($file, $data)
    {
        $this->fileSystem->filePutContents($file, $data);
    }


    /**
     * Get the Current User's logout url
     *
     * @return string
     * @psalm-suppress PossiblyUnusedMethod – Called from templates
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
     *
     * @return string
     * @psalm-suppress PossiblyUnusedMethod – Called from templates
     */
    public function getCallBackUrl()
    {
        return $this->getBaseUrl() . OAuthConstants::CALLBACK_URL;
    }

    /**
     * Remove sign-in settings
     *
     * @return void
     * @psalm-suppress PossiblyUnusedMethod – Called dynamically
     */
    public function removeSignInSettings()
    {
        $this->setStoreConfig(OAuthConstants::SHOW_CUSTOMER_LINK, 0);
        $this->setStoreConfig(OAuthConstants::SHOW_ADMIN_LINK, 0);
    }

    /**
     * Reinitialize config
     *
     * @return void
     * @psalm-suppress PossiblyUnusedMethod – Called from admin actions
     */
    public function reinitConfig()
    {

        $this->reinitableConfig->reinit();
    }

    /**
     * Check if debug logging is enabled
     * Retrieve the current admin user session.
     *
     * @return mixed
     * 
     */
     public function isLogEnable()
     {

       return (bool) $this->getStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG);
     
    }

    /**
     * Common Log Method .. Accessible in all classes through
     * @psalm-suppress PossiblyUnusedMethod – Called dynamically
     **/
    public function log_debug($msg = "", $obj = null)
    {

        if (is_object($msg)) {
            $this->customlog(var_export($msg, true));
        } else {
            $this->customlog($msg);

        }

        if ($obj != null) {
            $this->customlog(var_export($obj, true));


        }
    }

    /**
     * Get client details
     * used in ShowTestResultsAction.php
     * @psalm-suppress PossiblyUnusedMethod – Called dynamically
     */
    public function getClientDetails()
    {
        $appName = $this->getStoreConfig(OAuthConstants::APP_NAME);
        $clientDetails = $this->getClientDetailsByAppName($appName);
        $clientID = $clientDetails["clientID"];
        $clientSecret = $clientDetails["client_secret"];
        $accesstoken_url = $clientDetails["access_token_endpoint"];
        $scope = $clientDetails["scope"];
        $header = $clientDetails["values_in_header"];
        $body = $clientDetails["values_in_body"];
        $getuserinfo_url = $clientDetails['user_info_endpoint'];
        $authorize_url = $clientDetails['authorize_endpoint'];
        $endpoint_url = $clientDetails['well_known_config_url'];
        $show_customer_link = $this->getStoreConfig(OAuthConstants::SHOW_CUSTOMER_LINK);
        $attribute_email = $this->getStoreConfig(OAuthConstants::MAP_EMAIL);
        $attribute_username = $this->getStoreConfig(OAuthConstants::MAP_USERNAME);
        $customer_email = $this->getStoreConfig(OAuthConstants::DEFAULT_MAP_EMAIL);
        return [$appName, $scope, $clientID, $clientSecret, $authorize_url, $accesstoken_url, $getuserinfo_url, $header, $body, $endpoint_url, $show_customer_link, $attribute_email, $attribute_username, $customer_email];
    }

    /**
     * Retrieve the base URL of the store.
     *
     * @return string
     */
    public function getProductVersion()
    {
        return $this->productMetadata->getVersion();
    }

    /**
     * Retrieve the current store URL.
     *
     * @return string
     */
    public function getEdition()
    {
        return $this->productMetadata->getEdition() == 'Community' ? 'Magento Open Source' : 'Adobe Commerce Enterprise/Cloud';
    }

    /**
     * Retrieve the admin base URL.
     *
     * @return string
     */
    public function getCurrentDate()
    {
        try {
            $timezone = $this->scopeConfig->getValue(
                'general/locale/timezone',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );
            $dateTimeZone = new \DateTimeZone($timezone ?: 'UTC');
        } catch (\Exception $e) {
            $dateTimeZone = new \DateTimeZone('UTC');
        }
        $dateTime = new \DateTime('now', $dateTimeZone);
        return $dateTime->format('n/j/Y, g:i:s a');
    }

    /**
     * Decode a base64 encoded string safely.
     * Returns empty string on invalid input.
     *
     * @param string|null $input
     * @return string
     */
    public function decodeBase64(?string $input): string
    {
        if (empty($input)) {
            return '';
        }
        $decoded = base64_decode($input, true);
        return $decoded === false ? '' : $decoded;
    }

    /**
     * Extract the path component from a URL in a safe manner.
     * Returns empty string on failure.
     *
     * @param string $url
     * @return string
     */
    public function extractPathFromUrl(string $url): string
    {
        $parsed = @parse_url($url);
        if ($parsed === false) {
            return '';
        }
        return $parsed['path'] ?? '';
    }

    /**
     * Parse a URL and return components in a safe manner.
     * Returns empty array on failure.
     *
     * @param string $url
     * @return array
     */
    public function parseUrlComponents(string $url): array
    {
        $parsed = @parse_url($url);
        return is_array($parsed) ? $parsed : [];
    }
}
