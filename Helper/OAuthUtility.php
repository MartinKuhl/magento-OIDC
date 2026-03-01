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
    /** @var \Magento\Backend\Model\Session */
    protected \Magento\Backend\Model\Session $adminSession;

    /** @var \Magento\Customer\Model\Session */
    protected \Magento\Customer\Model\Session $customerSession;

    /** @var \Magento\Backend\Model\Auth\Session */
    protected \Magento\Backend\Model\Auth\Session $authSession;

    /** @var \Magento\Framework\App\Cache\TypeListInterface */
    protected \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList;

    /** @var \Magento\Framework\App\Cache\Frontend\Pool */
    protected \Magento\Framework\App\Cache\Frontend\Pool $cacheFrontendPool;

    /** @var \Magento\Framework\Filesystem\Driver\File */
    protected \Magento\Framework\Filesystem\Driver\File $fileSystem;

    /** @var \Psr\Log\LoggerInterface */
    protected \Psr\Log\LoggerInterface $logger;

    /** @var \Magento\Framework\App\Config\ReinitableConfigInterface */
    protected \Magento\Framework\App\Config\ReinitableConfigInterface $reinitableConfig;

    /**
     * @var \MiniOrange\OAuth\Logger\Logger
     */
    protected $_logger;

    /** @var \Magento\Framework\App\ProductMetadataInterface */
    protected \Magento\Framework\App\ProductMetadataInterface $productMetadata;

    /** @var \Magento\Framework\Stdlib\DateTime\DateTime */
    protected \Magento\Framework\Stdlib\DateTime\DateTime $dateTime;

    /** @var \Magento\Framework\App\Filesystem\DirectoryList */
    protected \Magento\Framework\App\Filesystem\DirectoryList $directoryList;

    /**
     * Initialize OAuthUtility helper.
     *
     * @param Context $context
     * @param UserFactory $adminFactory
     * @param CustomerFactory $customerFactory
     * @param UrlInterface $urlInterface
     * @param WriterInterface $configWriter
     * @param Repository $assetRepo
     * @param \Magento\Backend\Helper\Data $helperBackend
     * @param Url $frontendUrl
     * @param \Magento\Backend\Model\Session $adminSession
     * @param Session $customerSession
     * @param \Magento\Backend\Model\Auth\Session $authSession
     * @param \Magento\Framework\App\Config\ReinitableConfigInterface $reinitableConfig
     * @param TypeListInterface $cacheTypeList
     * @param Pool $cacheFrontendPool
     * @param \Psr\Log\LoggerInterface $logger
     * @param \MiniOrange\OAuth\Logger\Logger $logger2
     * @param File $fileSystem
     * @param \Magento\Framework\App\ProductMetadataInterface $productMetadata
     * @param \MiniOrange\OAuth\Model\MiniorangeOauthClientAppsFactory $miniorangeOauthClientAppsFactory
     * @param \MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps\CollectionFactory $clientCollectionFactory
     * @param \MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps $appResource
     * @param \Magento\User\Model\ResourceModel\User $userResource
     * @param \Magento\Customer\Model\ResourceModel\Customer $customerResource
     * @param DateTime $dateTime
     * @param DirectoryList $directoryList
     * @param \Magento\Framework\Encryption\EncryptorInterface $encryptor
     * @param \Magento\Framework\Escaper $escaper
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
     */
    public function getHiddenPhone($phone): string
    {
        return 'xxxxxxx' . substr($phone, strlen($phone) - 3);
    }

    // CUSTOM LOG FILE OPERATION

    /**
     * Sensitive field names whose values must be masked before logging (FEAT-05).
     */
    private const SENSITIVE_LOG_KEYS = [
        'client_secret', 'access_token', 'id_token', 'refresh_token', 'password', 'token',
    ];

    /**
     * Write a plain-text message to the custom OIDC log as a JSON entry (FEAT-05).
     *
     * Log format:
     *   {"ts":"2026-01-01T12:00:00+00:00","level":"debug","message":"..."}
     *
     * This replaces the previous plain-text format while keeping the same
     * public signature so all existing call-sites continue to work unchanged.
     *
     * @param string $txt Human-readable log message
     */
    public function customlog(string $txt): void
    {
        if ($this->isLogEnable()) {
            $entry = json_encode([
                'ts'      => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'level'   => 'debug',
                'message' => $txt,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->_logger->debug($entry !== false ? $entry : $txt);
        }
    }

    /**
     * Write a structured JSON log entry with additional context fields (FEAT-05).
     *
     * Sensitive context keys are automatically masked with "***".
     *
     * Example:
     *   $this->oauthUtility->customlogContext('oidc.token.exchange', [
     *       'user'        => 'user@example.com',
     *       'provider'    => 'okta',
     *       'duration_ms' => 142,
     *   ]);
     *
     * @param string $event   Short dot-notation event name (e.g. "oidc.login.success")
     * @param array  $context Additional key-value context to include in the log entry
     */
    public function customlogContext(string $event, array $context = []): void
    {
        if (!$this->isLogEnable()) {
            return;
        }

        // Mask sensitive values before logging
        foreach (self::SENSITIVE_LOG_KEYS as $key) {
            if (isset($context[$key])) {
                $context[$key] = '***';
            }
        }

        $payload = array_merge(
            [
                'ts'    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'level' => 'info',
                'event' => $event,
            ],
            $context
        );

        $entry = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->_logger->debug($entry !== false ? $entry : $event);
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
     * @param mixed $value
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
     */
    public function isCurlInstalled(): int
    {
        return function_exists('curl_init') ? 1 : 0;
    }

    /**
     * This function is used to obfuscate and return the email in question.
     *
     * @param  string $email //refers to the email id to be obfuscated
     * @return string obfuscated email id.
     */
    public function getHiddenEmail($email): string
    {
        if (trim($email) === '') {
            return "";
        }

        $emailsize = strlen($email);
        $partialemail = substr($email, 0, 1);
        $temp = strrpos($email, "@");
        $endemail = substr($email, $temp - 1, $emailsize);
        for ($i = 1; $i < $temp; $i++) {
            $partialemail .= 'x';
        }

        return $partialemail . $endemail;
    }
    /**
     * Get admin session instance.
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
     */
    public function setSessionDataForCurrentUser($key, $value): void
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
     * @psalm-suppress PossiblyUnusedMethod – Called dynamically
     */
    public function putFileContents($file, $data): void
    {
        $this->fileSystem->filePutContents($file, $data);
    }

    /**
     * Get the Current User's logout url
     *
     * @psalm-suppress PossiblyUnusedMethod – Called from templates
     */
    public function getLogoutUrl(): string
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
     * @psalm-suppress PossiblyUnusedMethod – Called from templates
     */
    public function getCallBackUrl(): string
    {
        return $this->getBaseUrl() . OAuthConstants::CALLBACK_URL;
    }

    /**
     * Remove sign-in settings
     *
     * @psalm-suppress PossiblyUnusedMethod – Called dynamically
     */
    public function removeSignInSettings(): void
    {
        $this->setStoreConfig(OAuthConstants::SHOW_CUSTOMER_LINK, 0);
        $this->setStoreConfig(OAuthConstants::SHOW_ADMIN_LINK, 0);
    }

    /**
     * Reinitialize config
     *
     * @psalm-suppress PossiblyUnusedMethod – Called from admin actions
     */
    public function reinitConfig(): void
    {
        $this->reinitableConfig->reinit();
    }

    /**
     * Check if debug logging is enabled.
     */
    public function isLogEnable(): bool
    {
        return (bool) $this->getStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG);
    }

    /**
     * Common log method accessible from all classes.
     *
     * @param string|object $msg Debug message to log
     * @param mixed|null    $obj Optional object to dump
     * @psalm-suppress PossiblyUnusedMethod – Called dynamically
     */
    public function logDebug(string|object $msg = "", $obj = null): void
    {
        if (is_object($msg)) {
            $this->customlog(json_encode($msg, JSON_UNESCAPED_SLASHES) ?: '');
        } else {
            $this->customlog($msg);
        }

        if ($obj !== null) {
            $this->customlog(json_encode($obj, JSON_UNESCAPED_SLASHES) ?: '');
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
     */
    public function getEdition(): string
    {
        return $this->productMetadata->getEdition() == 'Community'
            ? 'Magento Open Source'
            : 'Adobe Commerce Enterprise/Cloud';
    }

    /**
     * Retrieve the admin base URL.
     */
    public function getCurrentDate(): string
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
     */
    public function decodeBase64(?string $input): string
    {
        if ($input === null || $input === '' || $input === '0') {
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
     */
    public function parseUrlComponents(string $url): array
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $parsed = parse_url($url);
        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Derive a first/last name pair from an email address local-part.
     *
     * Used as a fallback when the OIDC provider does not return given_name /
     * family_name claims. Centralised here to avoid the same logic being
     * duplicated across AdminUserCreator, CustomerUserCreator, ProcessUserAction,
     * and CheckAttributeMappingAction. (REF-02)
     *
     * Examples:
     *   "john.doe@example.com"  → ['first' => 'John', 'last' => 'Doe']
     *   "jsmith@example.com"    → ['first' => 'Jsmith', 'last' => '']
     *   "first_last@x.com"      → ['first' => 'First', 'last' => 'Last']
     *
     * @param  string $email A valid email address
     * @return array{first: string, last: string}
     */
    public function extractNameFromEmail(string $email): array
    {
        $local = (string) strstr($email, '@', true);
        if ($local === '') {
            // Email has no local part (starts with '@') or no '@' at all —
            // fall back to the domain portion, stripping the leading '@'.
            $domain = ltrim((string) strstr($email, '@'), '@');
            $local  = $domain !== '' ? $domain : $email;
        }
        $parts  = preg_split('/[\s._\-]+/', $local, 2) ?: [$local];

        return [
            'first' => ucfirst(strtolower($parts[0])),
            'last'  => ucfirst(strtolower($parts[1] ?? '')),
        ];
    }
}
