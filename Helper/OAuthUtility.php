<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Helper;

use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Url;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\User\Model\UserFactory;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Stdlib\DateTime\DateTime;

use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\Data;
use M2Oidc\OAuth\Logger\OidcLogger;
use M2Oidc\OAuth\Model\Config\OidcConfigReader;
use M2Oidc\OAuth\Model\Provider\ProviderResolver;
use M2Oidc\OAuth\Model\ResourceModel\OidcProviderRepository;

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

    /** @var \Magento\Framework\App\ProductMetadataInterface */
    protected \Magento\Framework\App\ProductMetadataInterface $productMetadata;

    /** @var \Magento\Framework\Stdlib\DateTime\DateTime */
    protected \Magento\Framework\Stdlib\DateTime\DateTime $dateTime;

    /** @var OidcLogger */
    public readonly OidcLogger $oidcLogger;

    /** @var ProviderResolver */
    public readonly ProviderResolver $providerResolver;

    /** @var OidcConfigReader */
    public readonly OidcConfigReader $configReader;

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
     * @param File $fileSystem
     * @param \Magento\Framework\App\ProductMetadataInterface $productMetadata
     * @param \M2Oidc\OAuth\Model\M2oidcOauthClientAppsFactory $m2oidcOauthClientAppsFactory
     * @param \M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps\CollectionFactory $clientCollectionFactory
     * @param \M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps $appResource
     * @param \Magento\User\Model\ResourceModel\User $userResource
     * @param \Magento\Customer\Model\ResourceModel\Customer $customerResource
     * @param DateTime $dateTime
     * @param \Magento\Framework\Encryption\EncryptorInterface $encryptor
     * @param \Magento\Framework\Escaper $escaper
     * @param OidcLogger $oidcLogger
     * @param ProviderResolver $providerResolver
     * @param OidcConfigReader $configReader
     * @param OidcProviderRepository $providerRepository
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
        File $fileSystem,
        \Magento\Framework\App\ProductMetadataInterface $productMetadata,
        \M2Oidc\OAuth\Model\M2oidcOauthClientAppsFactory $m2oidcOauthClientAppsFactory,
        \M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps\CollectionFactory $clientCollectionFactory,
        \M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps $appResource,
        \Magento\User\Model\ResourceModel\User $userResource,
        \Magento\Customer\Model\ResourceModel\Customer $customerResource,
        DateTime $dateTime,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        \Magento\Framework\Escaper $escaper,
        OidcLogger $oidcLogger,
        ProviderResolver $providerResolver,
        OidcConfigReader $configReader,
        OidcProviderRepository $providerRepository
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
            $m2oidcOauthClientAppsFactory,
            $clientCollectionFactory,
            $appResource,
            $userResource,
            $customerResource,
            $encryptor,
            $escaper,
            $providerRepository
        );

        $this->adminSession = $adminSession;
        $this->customerSession = $customerSession;
        $this->authSession = $authSession;
        $this->cacheTypeList = $cacheTypeList;
        $this->cacheFrontendPool = $cacheFrontendPool;
        $this->fileSystem = $fileSystem;
        $this->logger = $logger;
        $this->reinitableConfig = $reinitableConfig;
        $this->productMetadata = $productMetadata;
        $this->dateTime = $dateTime;
        $this->oidcLogger = $oidcLogger;
        $this->providerResolver = $providerResolver;
        $this->configReader = $configReader;
    }

    // =========================================================================
    // Delegation: Logging — delegates to OidcLogger
    // =========================================================================

    /**
     * Write a plain-text message to the custom OIDC log.
     *
     * Delegates to OidcLogger::customlog().
     *
     * @param string $txt Human-readable log message
     */
    public function customlog(string $txt): void
    {
        $this->oidcLogger->customlog($txt);
    }

    /**
     * Write a structured JSON log entry with context fields.
     *
     * Delegates to OidcLogger::customlogContext().
     *
     * @param string  $event   Short dot-notation event name
     * @param mixed[] $context Additional key-value context
     */
    public function customlogContext(string $event, array $context = []): void
    {
        $this->oidcLogger->customlogContext($event, $context);
    }

    /**
     * Log a debug message, optionally with an object/array.
     *
     * Delegates to OidcLogger::logDebug().
     *
     * @param string|object $msg Debug message
     * @param mixed|null    $obj Optional object to dump
     */
    public function logDebug(string|object $msg = "", $obj = null): void
    {
        $this->oidcLogger->logDebug($msg, $obj);
    }

    /**
     * Check if debug logging is enabled.
     *
     * Delegates to OidcLogger::isLogEnable().
     */
    public function isLogEnable(): bool
    {
        return $this->oidcLogger->isLogEnable();
    }

    /**
     * Check whether the custom OIDC log file exists.
     *
     * Delegates to OidcLogger::isCustomLogExist().
     *
     * @psalm-return 0|1
     */
    public function isCustomLogExist(): int
    {
        return $this->oidcLogger->isCustomLogExist();
    }

    /**
     * Delete the custom OAuth log file.
     *
     * Delegates to OidcLogger::deleteCustomLogFile().
     */
    public function deleteCustomLogFile(): void
    {
        $this->oidcLogger->deleteCustomLogFile();
    }

    // =========================================================================
    // Delegation: Provider resolution — delegates to ProviderResolver
    // =========================================================================

    /**
     * Set the active provider context for this request.
     *
     * Delegates to ProviderResolver::setActiveProviderId().
     *
     * @param int $providerId Row `id` from m2oidc_oauth_client_apps (> 0)
     */
    public function setActiveProviderId(int $providerId): void
    {
        $this->providerResolver->setActiveProviderId($providerId);
    }

    /**
     * Return the currently active provider ID (or null if not set).
     *
     * Delegates to ProviderResolver::getActiveProviderId().
     */
    public function getActiveProviderId(): ?int
    {
        return $this->providerResolver->getActiveProviderId();
    }

    /**
     * Lazy-load and return the active provider row.
     *
     * Delegates to ProviderResolver::resolveActiveProvider().
     *
     * @return array<string,mixed> Provider data array or empty array if none found
     */
    public function resolveActiveProvider(): array
    {
        return $this->providerResolver->resolveActiveProvider();
    }

    // =========================================================================
    // MP-05: Provider-aware config resolution — delegates to OidcConfigReader
    // =========================================================================
    /**
     * Read a config value — provider-specific keys from the app table, global keys from core_config_data.
     *
     * Delegates to OidcConfigReader. Provider-specific keys are read EXCLUSIVELY from the
     * m2oidc_oauth_client_apps table. No fallback to core_config_data.
     * Returns null if the column is empty or the provider is not found.
     *
     * @param string $config OAuthConstants key (e.g. OAuthConstants::MAP_EMAIL)
     */
    #[\Override]
    public function getStoreConfig(string $config): mixed
    {
        return $this->configReader->getStoreConfig($config);
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
     */
    public function isCurlInstalled(): int
    {
        return function_exists('curl_init') ? 1 : 0;
    }

    /**
     * This function is used to obfuscate and return the email in question.
     *
     * @param  string $email
     * @return string obfuscated email id.
     */
    public function getHiddenEmail($email): string
    {
        if (trim($email) === '') {
            return "";
        }

        $emailsize = strlen($email);
        $partialemail = substr($email, 0, 1);
        $temp = (int)strrpos($email, "@");
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
     * Return all active providers for the given login type.
     *
     * Delegates to ProviderResolver.
     *
     * @param  string $loginType 'customer' | 'admin' | 'both'
     * @return array<int, array<string, mixed>>
     */
    #[\Override]
    public function getAllActiveProviders(string $loginType = 'customer'): array
    {
        return $this->providerResolver->getAllActiveProviders($loginType);
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
     * Get the Current Customer who is logged in
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
        return $this->getAdminUrl('');
    }

    /**
     * Get the customer login url
     */
    public function getCustomerLoginUrl(): string
    {
        return $this->getUrl('customer/account/login');
    }

    /**
     * Get SP-initiated SSO login URL (single-provider shortcut).
     *
     * @param string|null $relayState Optional post-login redirect target
     * @param string|null $appName    Optional provider app name to select
     */
    public function getSPInitiatedUrl(?string $relayState = null, ?string $appName = null): string
    {
        $providers = $this->getAllActiveProviders(OAuthConstants::LOGIN_TYPE_CUSTOMER);
        if ($providers === []) {
            return $this->getUrl(OAuthConstants::OAUTH_LOGIN_URL);
        }

        /** @psalm-suppress InvalidArrayOffset */
        $provider = (in_array($appName, [null, '', '0'], true))
            ? reset($providers)
            : ($providers[$appName] ?? reset($providers));

        return $this->getSPInitiatedUrlForProvider((int) ($provider['id'] ?? 0), $relayState);
    }

    /**
     * Get is Test Configuration clicked
     *
     * @return         bool
     * @psalm-suppress PossiblyUnusedMethod – Called from templates
     */
    public function getIsTestConfigurationClicked(): mixed
    {
        return $this->getStoreConfig(OAuthConstants::IS_TEST);
    }

    /**
     * Flush Magento Cache.
     *
     * @param string $from
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
     * @param  string $file
     * @return string
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
     */
    public function putFileContents($file, $data): void
    {
        $this->fileSystem->filePutContents($file, $data);
    }

    /**
     * Get the Current User's logout url
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
     */
    public function getCallBackUrl(): string
    {
        return $this->getBaseUrl() . OAuthConstants::CALLBACK_URL;
    }

    /**
     * Remove sign-in settings for all providers.
     *
     * Updates the provider table directly instead of core_config_data.
     */
    public function removeSignInSettings(): void
    {
        $collection = $this->getOAuthClientApps();
        foreach ($collection as $item) {
            $item->setData('show_customer_link', '0');
            $item->setData('show_admin_link', '0');
            $this->appResource->save($item);
        }
    }

    /**
     * Reinitialize config
     */
    public function reinitConfig(): void
    {
        $this->reinitableConfig->reinit();
    }

    /**
     * Get client details — reads all values from the active provider row.
     *
     * All values come from the provider table via resolveActiveProvider().
     *
     * @return array<int, mixed>
     */
    public function getClientDetails(): array
    {
        $provider = $this->providerResolver->resolveActiveProvider();

        return [
            $provider['app_name'] ?? null,
            $provider['scope'] ?? null,
            $provider['clientID'] ?? null,
            $provider['client_secret'] ?? null,
            $provider['authorize_endpoint'] ?? null,
            $provider['access_token_endpoint'] ?? null,
            $provider['user_info_endpoint'] ?? null,
            $provider['values_in_header'] ?? null,
            $provider['values_in_body'] ?? null,
            $provider['well_known_config_url'] ?? null,
            $provider['show_customer_link'] ?? null,
            $provider['email_attribute'] ?? OAuthConstants::DEFAULT_MAP_EMAIL,
            $provider['username_attribute'] ?? OAuthConstants::DEFAULT_MAP_USERN,
            $provider['email_attribute'] ?? OAuthConstants::DEFAULT_MAP_EMAIL,
        ];
    }

    /**
     * Retrieve the Magento product version.
     *
     * @return string
     */
    public function getProductVersion()
    {
        return $this->productMetadata->getVersion();
    }

    /**
     * Retrieve the Magento edition label.
     */
    public function getEdition(): string
    {
        return $this->productMetadata->getEdition() == 'Community'
            ? 'Magento Open Source'
            : 'Adobe Commerce Enterprise/Cloud';
    }

    /**
     * Get the current date/time in the store's configured timezone.
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
     * @param  string|null $input
     */
    public function decodeBase64(?string $input): string
    {
        if (in_array($input, [null, '', '0'], true)) {
            return '';
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $decoded = base64_decode($input, true);
        return $decoded === false ? '' : $decoded;
    }

    /**
     * Extract the path component from a URL in a safe manner.
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
     * @param  string $url
     * @return array<string, mixed>
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
     * @param  string $email A valid email address
     * @return array{first: string, last: string}
     */
    public function extractNameFromEmail(string $email): array
    {
        $local = (string) strstr($email, '@', true);
        if ($local === '') {
            $domain = ltrim((string) strstr($email, '@'), '@');
            $local  = $domain !== '' ? $domain : $email;
        }
        $parts  = preg_split('/[\s._\-]+/', $local, 2) ?: [$local];

        return [
            'first' => ucfirst(strtolower($parts[0])),
            'last'  => ucfirst(strtolower($parts[1] ?? '')),
        ];
    }

    /**
     * Persist the OIDC test result to the provider record by app_name (legacy).
     *
     * @param string $appName The app_name of the provider
     * @param string $status  'success', 'failed', or 'unsuccessful'
     */
    #[\Override]
    public function saveTestStatus(string $appName, string $status): void
    {
        if ($appName === '') {
            $this->oidcLogger->customlog('saveTestStatus: skipped — empty app_name');
            return;
        }

        $now = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $connection = $this->appResource->getConnection();
        $tableName  = $this->appResource->getMainTable();

        if ($connection === false) {
            $this->oidcLogger->customlog('saveTestStatus: failed — could not obtain database connection');
            return;
        }

        try {
            $affected = $connection->update(
                $tableName,
                ['last_test_status' => $status, 'last_test_at' => $now],
                ['app_name = ?' => $appName]
            );
            if ($affected === 0) {
                $this->oidcLogger->customlog('saveTestStatus: provider not found for app_name: ' . $appName);
            }
        } catch (\Exception $e) {
            $this->oidcLogger->customlog('saveTestStatus: failed — ' . $e->getMessage());
        }
    }

    /**
     * Persist the OIDC test result to the provider record by numeric ID (preferred).
     *
     * @param int    $providerId Row `id` from m2oidc_oauth_client_apps
     * @param string $status     'success', 'failed', or 'unsuccessful'
     */
    #[\Override]
    public function saveTestStatusById(int $providerId, string $status): void
    {
        if ($providerId <= 0) {
            $this->oidcLogger->customlog('saveTestStatusById: skipped — invalid provider ID');
            return;
        }

        $now = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $connection = $this->appResource->getConnection();
        $tableName  = $this->appResource->getMainTable();

        if ($connection === false) {
            $this->oidcLogger->customlog('saveTestStatusById: failed — could not obtain database connection');
            return;
        }

        try {
            $connection->update(
                $tableName,
                ['last_test_status' => $status, 'last_test_at' => $now],
                ['id = ?' => $providerId]
            );
        } catch (\Exception $e) {
            $this->oidcLogger->customlog('saveTestStatusById: failed — ' . $e->getMessage());
        }
    }

    /**
     * Persist received OIDC claim keys to the provider record.
     *
     * @param int      $providerId Row `id` from m2oidc_oauth_client_apps
     * @param string[] $claimKeys  Flat list of claim key names
     */
    #[\Override]
    public function saveReceivedOidcClaims(int $providerId, array $claimKeys): void
    {
        if ($providerId <= 0) {
            return;
        }

        $json = json_encode(array_values(array_unique($claimKeys)), JSON_UNESCAPED_SLASHES);

        $connection = $this->appResource->getConnection();
        $tableName  = $this->appResource->getMainTable();

        if ($connection === false) {
            $this->oidcLogger->customlog('saveReceivedOidcClaims: failed — could not obtain database connection');
            return;
        }

        try {
            $connection->update(
                $tableName,
                ['received_oidc_claims' => $json !== false ? $json : '[]'],
                ['id = ?' => $providerId]
            );
        } catch (\Exception $e) {
            $this->oidcLogger->customlog('saveReceivedOidcClaims: failed — ' . $e->getMessage());
        }
    }

    /**
     * Decrypt an encrypted secret value using Magento's encryptor.
     *
     * @param string $secret Encrypted secret string
     * @return string Decrypted secret
     */
    public function decryptSecret(string $secret): string
    {
        return $this->encryptor->decrypt($secret);
    }

    /**
     * Update specific fields on a provider row (e.g. PKCE code_verifier).
     *
     * @param int     $providerId Row `id` from m2oidc_oauth_client_apps
     * @param mixed[] $data       Column => value pairs to update
     */
    public function saveProviderData(int $providerId, array $data): void
    {
        if ($providerId <= 0 || $data === []) {
            return;
        }

        $connection = $this->appResource->getConnection();
        $tableName  = $this->appResource->getMainTable();

        if ($connection === false) {
            $this->oidcLogger->customlog('saveProviderData: failed — could not obtain database connection');
            return;
        }

        try {
            $connection->update($tableName, $data, ['id = ?' => $providerId]);
        } catch (\Exception $e) {
            $this->oidcLogger->customlog('saveProviderData: failed — ' . $e->getMessage());
        }
    }
}
