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
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Stdlib\DateTime\DateTime;

use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\Data;

/**
 * This class contains some common Utility functions
 * which can be called from anywhere in the module. This is
 * mostly used in the action classes to get any utility
 * function or data from the database.
 */
class OAuthUtility extends Data
{
    /**
     * @var \Magento\Backend\Model\Session 
     */
    protected $adminSession;

    /**
     * @var \Magento\Customer\Model\Session 
     */
    protected $customerSession;

    /**
     * @var \Magento\Backend\Model\Auth\Session 
     */
    protected $authSession;

    /**
     * @var \Magento\Framework\App\Cache\TypeListInterface 
     */
    protected $cacheTypeList;

    /**
     * @var \Magento\Framework\App\Cache\Frontend\Pool 
     */
    protected $cacheFrontendPool;

    /**
     * @var \Magento\Framework\Filesystem\Driver\File 
     */
    protected $fileSystem;

    /**
     * @var \Psr\Log\LoggerInterface 
     */
    protected $logger;

    /**
     * @var \Magento\Framework\App\Config\ReinitableConfigInterface 
     */
    protected $reinitableConfig;

    /**
     * @var \MiniOrange\OAuth\Logger\Logger 
     */
    protected $_logger;

    /**
     * @var \Magento\Framework\App\ProductMetadataInterface 
     */
    protected $productMetadata;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime 
     */
    protected $dateTime;

    /**
     * @var \Magento\Framework\App\Filesystem\DirectoryList 
     */
    protected $directoryList;

    /**
     * Initialize OAuthUtility helper.
     *
     * @param Context                                                                            $context
     * @param UserFactory                                                                        $adminFactory
     * @param CustomerFactory                                                                    $customerFactory
     * @param UrlInterface                                                                       $urlInterface
     * @param WriterInterface                                                                    $configWriter
     * @param Repository                                                                         $assetRepo
     * @param \Magento\Backend\Helper\Data                                                       $helperBackend
     * @param Url                                                                                $frontendUrl
     * @param \Magento\Backend\Model\Session                                                     $adminSession
     * @param Session                                                                            $customerSession
     * @param \Magento\Backend\Model\Auth\Session                                                $authSession
     * @param \Magento\Framework\App\Config\ReinitableConfigInterface                            $reinitableConfig
     * @param TypeListInterface                                                                  $cacheTypeList
     * @param Pool                                                                               $cacheFrontendPool
     * @param \Psr\Log\LoggerInterface                                                           $logger
     * @param \MiniOrange\OAuth\Logger\Logger                                                    $logger2
     * @param File                                                                               $fileSystem
     * @param \Magento\Framework\App\ProductMetadataInterface                                    $productMetadata
     * @param \MiniOrange\OAuth\Model\MiniorangeOauthClientAppsFactory                           $miniorangeOauthClientAppsFactory
     * @param \MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps\CollectionFactory  $clientCollectionFactory
     * @param \MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps                    $appResource
     * @param \Magento\User\Model\ResourceModel\User                                             $userResource
     * @param \Magento\Customer\Model\ResourceModel\Customer                                     $customerResource
     * @param DateTime                                                                           $dateTime
     * @param DirectoryList                                                                      $directoryList
     * @param \Magento\Framework\Encryption\EncryptorInterface                                   $encryptor
     * @param \Magento\Framework\Escaper                                                         $escaper
     */
    public function __construct(
        Context $context, 
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
        parent::__construct(
            $context,
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
            $escaper,
            $logger  
        );
        
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
    }

    /**
     * This function returns phone number as an obfuscated string for display to the user.
     *
     * @param  string $phone references the phone number.
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
     *
     * @param string $txt
     */
    public function customlog(string $txt): void
    {
        $this->isLogEnable() ? $this->_logger->debug($txt) : null;
    }
    /**
     * This function check whether any custom log file exist or not.
     *
     * @psalm-return 0|1
     */
    public function isCustomLogExist(): int
    {
        try {
            $logPath = $this->directoryList->getPath(DirectoryList::VAR_DIR)
                . '/log/mo_oauth.log';
            if ($this->fileSystem->isExists($logPath)) {
                return 1;
            }
        } catch (\Exception $e) {
            $this->logger->debug('Path error while checking log file: ' . $e->getMessage());
        }
        return 0;
    }

    /**
     * Delete the custom OAuth log file if it exists.
     *
     * @return void
     */
    public function deleteCustomLogFile(): void
    {
        try {
            $logPath = $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/log/mo_oauth.log';
            if ($this->fileSystem->isExists($logPath)) {
                $this->fileSystem->deleteFile($logPath);
            }
        } catch (\Exception $e) {
            $this->logger->debug('Path error while deleting log file: ' . $e->getMessage());
        }
    }

    /**
     * Check if a value is empty or not set.
     *
     * @param  mixed $value
     * @return bool
     */
    public function isBlank(mixed $value): bool
    {
        return empty($value);
    }

    /**
     * This function checks if cURL has been installed or enabled on the site.
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
     * This function is used to obfuscate and return the email in question.
     *
     * @param  string $email //refers to the email id to be obfuscated
     * @return string obfuscated email id.
     */
    public function getHiddenEmail($email)
    {
        if (trim($email) === '') {
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
    /**
     * Get admin session instance.
     *
     * @return \Magento\Backend\Model\Session
     */
    public function getAdminSession(): \Magento\Backend\Model\Session
    {
        return $this->adminSession;
    }

    /**
     * Set Admin Session Data
     *
     * @param  string $key
     * @param  mixed  $value
     * @return mixed
     */
    public function setAdminSessionData($key, $value)
    {
        return $this->adminSession->setData($key, $value);
    }

    /**
     * Get Admin Session data based of on the key
     *
     * @param  string $key
     * @param  bool   $remove
     * @return mixed
     */
    public function getAdminSessionData($key, $remove = false)
    {
        return $this->adminSession->getData($key, $remove);
    }

    /**
     * Set customer Session Data
     *
     * @param  string $key
     * @param  mixed  $value
     * @return mixed
     */
    public function setSessionData($key, $value)
    {
        return $this->customerSession->setData($key, $value);
    }

    /**
     * Get customer Session data based off on the key
     *
     * @param  string $key
     * @param  bool   $remove
     * @return mixed
     */
    public function getSessionData($key, $remove = false)
    {
        return $this->customerSession->getData($key, $remove);
    }

    /**
     * Set session data for the currently logged-in user.
     *
     * Detects whether the user is in the frontend or backend
     * and stores the data in the appropriate session.
     *
     * @param  string $key
     * @param  mixed  $value
     * @return void
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
     * Check if OAuth/OIDC is configured by verifying that a valid app with required endpoints exists.
     */
    public function isOAuthConfigured(): bool
    {
        $appName = $this->getStoreConfig(OAuthConstants::APP_NAME);
        if ($this->isBlank($appName)) {
            return false;
        }

        try {
            $clientDetails = $this->getClientDetailsByAppName($appName);
        } catch (\Exception $e) {
            return false;
        }

        return !empty($clientDetails['clientID'])
            && !empty($clientDetails['authorize_endpoint'])
            && !empty($clientDetails['access_token_endpoint']);
    }

    /**
     * Check if there is an active user session for frontend or backend.
     */
    public function isUserLoggedIn(): bool
    {
        return $this->customerSession->isLoggedIn()
            || $this->authSession->isLoggedIn();
    }

    /**
     * Get the Current Admin User who is logged in
     */
    public function getCurrentAdminUser(): \Magento\User\Model\User|null
    {
        return $this->authSession->getUser();
    }

    /**
     * Get the Current Admin User who is logged in
     */
    public function getCurrentUser(): \Magento\Customer\Model\Customer
    {
        return $this->customerSession->getCustomer();
    }

    /**
     * Get the admin login url
     */
    public function getAdminLoginUrl(): string
    {
        return $this->getAdminUrl('adminhtml/auth/login');
    }

    /**
     * Get the admin page url
     */
    public function getAdminPageUrl(): string
    {
        return $this->getAdminBaseUrl();
    }

    /**
     * Get the customer login url
     */
    public function getCustomerLoginUrl(): string
    {
        return $this->getUrl('customer/account/login');
    }

    /**
     * Get is Test Configuration clicked
     *
     * @return         bool
     * @psalm-suppress PossiblyUnusedMethod – Called from templates
     */
    public function getIsTestConfigurationClicked()
    {
        return $this->getStoreConfig(OAuthConstants::IS_TEST);
    }

    /**
     * Flush Magento Cache.
     *
     * @param string $from
     * @return void
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
     * @param          string $file
     * @return         string
     * @psalm-suppress PossiblyUnusedMethod – Called dynamically
     */
    public function getFileContents($file)
    {
        return $this->fileSystem->fileGetContents($file);
    }

    /**
     * Put data in the file specified by the path
     *
     * @param          string $file
     * @param          string $data
     * @return         void
     * @psalm-suppress PossiblyUnusedMethod – Called dynamically
     */
    public function putFileContents($file, $data)
    {
        $this->fileSystem->filePutContents($file, $data);
    }

    /**
     * Get the Current User's logout url
     *
     * @return         string
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
     * @return         string
     * @psalm-suppress PossiblyUnusedMethod – Called from templates
     */
    public function getCallBackUrl()
    {
        return $this->getBaseUrl() . OAuthConstants::CALLBACK_URL;
    }

    /**
     * Remove sign-in settings
     *
     * @return         void
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
     * @return         void
     * @psalm-suppress PossiblyUnusedMethod – Called from admin actions
     */
    public function reinitConfig()
    {
        $this->reinitableConfig->reinit();
    }

    /**
     * Check if debug logging is enabled.
     *
     * @return mixed
     */
    public function isLogEnable()
    {
        return (bool) $this->getStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG);
    }

    /**
     * Common log method accessible from all classes.
     *
     * @param string       $msg Debug message to log
     * @param mixed|null   $obj Optional object to dump
     * @psalm-suppress PossiblyUnusedMethod – Called dynamically
     */
    public function logDebug($msg = "", $obj = null): void
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
     * Get client details used in ShowTestResultsAction.
     *
     * @psalm-suppress PossiblyUnusedMethod – Called dynamically
     *
     * @return (mixed|null|string)[]
     *
     * @psalm-return list{string, mixed|null, mixed|null, mixed|null, mixed|null, mixed|null, mixed|null,
     *               mixed|null, mixed|null, mixed|null, mixed, mixed, mixed, mixed}
     */
    public function getClientDetails(): array
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
        return [
            $appName,
            $scope,
            $clientID,
            $clientSecret,
            $authorize_url,
            $accesstoken_url,
            $getuserinfo_url,
            $header,
            $body,
            $endpoint_url,
            $show_customer_link,
            $attribute_email,
            $attribute_username,
            $customer_email
        ];
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
        return $this->productMetadata->getEdition() == 'Community'
            ? 'Magento Open Source'
            : 'Adobe Commerce Enterprise/Cloud';
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
     *
     * Returns empty string on invalid input.
     *
     * @param  string|null $input
     * @return string
     */
    public function decodeBase64(?string $input): string
    {
        if (empty($input)) {
            return '';
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $decoded = base64_decode($input, true);
        return $decoded === false ? '' : $decoded;
    }

    /**
     * Extract the path component from a URL in a safe manner.
     *
     * Returns empty string on failure.
     *
     * @param  string $url
     * @return string
     */
    public function extractPathFromUrl(string $url): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $parsed = parse_url($url);
        if ($parsed === false) {
            return '';
        }
        return $parsed['path'] ?? '';
    }

    /**
     * Parse a URL and return components in a safe manner.
     *
     * Returns empty array on failure.
     *
     * @param  string $url
     * @return array
     */
    public function parseUrlComponents(string $url): array
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $parsed = parse_url($url);
        return is_array($parsed) ? $parsed : [];
    }
}
