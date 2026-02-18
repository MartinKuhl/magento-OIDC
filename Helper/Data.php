<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Helper;

use Magento\Backend\Helper\Data as BackendHelper;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Url;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Store\Model\ScopeInterface;
use Magento\User\Model\ResourceModel\User as UserResource;
use Magento\User\Model\UserFactory;
use MiniOrange\OAuth\Model\MiniorangeOauthClientAppsFactory;
use MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps as AppResource;
use MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps\CollectionFactory as ClientCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Helper class for reading/writing OAuth configuration data,
 * managing OAuth client app records, and providing URL utilities.
 */
class Data extends AbstractHelper
{
    private UserFactory $adminFactory;

    private CustomerFactory $customerFactory;

    private UrlInterface $urlInterface;

    private WriterInterface $configWriter;

    private AssetRepository $assetRepo;

    private BackendHelper $helperBackend;

    private Url $frontendUrl;

    private MiniorangeOauthClientAppsFactory $clientAppsFactory;

    private ClientCollectionFactory $clientCollectionFactory;

    private AppResource $appResource;

    private UserResource $userResource;

    private CustomerResource $customerResource;

    private EncryptorInterface $encryptor;

    private Escaper $escaper;

    private LoggerInterface $logger;

    public function __construct(
        Context $context,
        UserFactory $adminFactory,
        CustomerFactory $customerFactory,
        UrlInterface $urlInterface,
        WriterInterface $configWriter,
        AssetRepository $assetRepo,
        BackendHelper $helperBackend,
        Url $frontendUrl,
        MiniorangeOauthClientAppsFactory $clientAppsFactory,
        ClientCollectionFactory $clientCollectionFactory,
        AppResource $appResource,
        UserResource $userResource,
        CustomerResource $customerResource,
        EncryptorInterface $encryptor,
        Escaper $escaper,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->adminFactory = $adminFactory;
        $this->customerFactory = $customerFactory;
        $this->urlInterface = $urlInterface;
        $this->configWriter = $configWriter;
        $this->assetRepo = $assetRepo;
        $this->helperBackend = $helperBackend;
        $this->frontendUrl = $frontendUrl;
        $this->clientAppsFactory = $clientAppsFactory;
        $this->clientCollectionFactory = $clientCollectionFactory;
        $this->appResource = $appResource;
        $this->userResource = $userResource;
        $this->customerResource = $customerResource;
        $this->encryptor = $encryptor;
        $this->escaper = $escaper;
        $this->logger = $logger;
    }

    /**
     * Set the entry in the OAuthClientApp table.
     *
     * @throws \Exception
     */
    public function setOAuthClientApps(
        string $appName,
        string $clientId,
        string $clientSecret,
        string $scope,
        string $authorizeUrl,
        string $accessTokenUrl,
        string $userInfoUrl,
        string $wellKnownConfigUrl,
        string $grantType,
        bool $sendHeader,
        bool $sendBody,
        string $issuer = ''
    ): void {
        $model = $this->clientAppsFactory->create();
        $model->addData(
            [
                "app_name" => $this->sanitize($appName),
                "callback_uri" => '',
                "clientID" => $this->sanitize($clientId),
                "client_secret" => $this->encryptor->encrypt(
                    $this->sanitize($clientSecret)
                ),
                "scope" => $this->sanitize($scope),
                "authorize_endpoint" => $this->sanitize($authorizeUrl),
                "access_token_endpoint" => $this->sanitize($accessTokenUrl),
                "user_info_endpoint" => $this->sanitize($userInfoUrl),
                "well_known_config_url" => $this->sanitize($wellKnownConfigUrl),
                "issuer" => $this->sanitize($issuer),
                "grant_type" => $this->sanitize($grantType),
                "values_in_header" => $sendHeader,
                "values_in_body" => $sendBody
            ]
        );
        $this->appResource->save($model);
    }

    /**
     * Delete all OAuth client app records.
     */
    public function deleteAllRecords(): void
    {
        $collection = $this->clientCollectionFactory->create();
        foreach ($collection as $item) {
            if (is_object($item) && method_exists($item, 'delete')) {
                $item->delete();
            }
        }
    }

    /**
     * Get all entries from the OAuthClientApp table.
     *
     * @return \MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps\Collection
     */
    public function getOAuthClientApps()
    {
        return $this->clientCollectionFactory->create();
    }

    /**
     * Get client details by app name using collection filtering.
     *
     * @return array|null Client details array or null if not found
     */
    public function getClientDetailsByAppName(string $appName): ?array
    {
        $collection = $this->getOAuthClientApps();
        $collection->addFieldToFilter('app_name', $appName);
        $data = $collection->getSize() > 0 ? $collection->getFirstItem()->getData() : null;

        if ($data !== null && isset($data['client_secret']) && !empty($data['client_secret'])) {
            // Magento encrypted values use format "version:key_num:base64data" (e.g. "0:2:abc...")
            // Only attempt decryption if the value matches this pattern
            if (preg_match('/^\d+:\d+:/', $data['client_secret'])) {
                $data['client_secret'] = $this->encryptor->decrypt($data['client_secret']);
            }
            // Otherwise keep as-is (plaintext — will be encrypted on next admin save)
        }

        return $data;
    }

    /**
     * Extract data stored in the store config table.
     *
     * @return mixed
     */
    public function getStoreConfig(string $config)
    {
        return $this->scopeConfig->getValue(
            'miniorange/oauth/' . $config,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Store data in the store config table.
     *
     * @param mixed  $value
     */
    public function setStoreConfig(string $config, $value, bool $skipSanitize = false): void
    {
        $finalValue = $skipSanitize ? $value : $this->sanitize($value);
        $this->configWriter->save('miniorange/oauth/' . $config, $finalValue);

        // If this is an admin or customer link setting, also update the OAuth client app table
        if ($config === OAuthConstants::SHOW_ADMIN_LINK
            || $config === OAuthConstants::SHOW_CUSTOMER_LINK
        ) {
            try {
                $collection = $this->getOAuthClientApps();
                if ($collection && count($collection) > 0) {
                    foreach ($collection as $item) {
                        $model = $this->clientAppsFactory->create();
                        $this->appResource->load($model, $item->getId());
                        $model->setData($config, $finalValue);
                        $this->appResource->save($model);
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error(
                    'Failed to update OAuth client app table: ' . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        }
    }

    /**
     * Save user attributes to the database.
     *
     * Decides which user type (admin or customer) to update.
     *
     * @param mixed      $value
     * @param int|string $id
     * @throws \Exception
     */
    public function saveConfig(string $url, $value, $id, bool $admin): void
    {
        $admin ? $this->saveAdminStoreConfig($url, $value, $id) : $this->saveCustomerStoreConfig($url, $value, $id);
    }

    /**
     * Extract information stored in the admin user table.
     *
     * @param int|string $id
     * @return mixed
     */
    public function getAdminStoreConfig(string $config, $id)
    {
        $model = $this->adminFactory->create();
        $this->userResource->load($model, $id);
        return $model->getData($config);
    }

    /**
     * Save admin attributes to the database.
     *
     * @param mixed      $value
     * @param int|string $id
     * @throws \Exception
     */
    private function saveAdminStoreConfig(string $url, $value, $id): void
    {
        $data = [$url => $value];
        $model = $this->adminFactory->create();
        $this->userResource->load($model, $id);
        $model->addData($data);
        $model->setId($id);
        $this->userResource->save($model);
    }

    /**
     * Extract information stored in the customer user table.
     *
     * @param int|string $id
     * @return mixed
     */
    public function getCustomerStoreConfig(string $config, $id)
    {
        $model = $this->customerFactory->create();
        $this->customerResource->load($model, $id);
        return $model->getData($config);
    }

    /**
     * Save customer attributes to the database.
     *
     * @param mixed      $value
     * @param int|string $id
     * @throws \Exception
     */
    private function saveCustomerStoreConfig(string $url, $value, $id): void
    {
        $data = [$url => $value];
        $model = $this->customerFactory->create();
        $this->customerResource->load($model, $id);
        $model->addData($data);
        $model->setId($id);
        $this->customerResource->save($model);
    }

    /**
     * Get the site's base URL.
     */
    public function getBaseUrl(): string
    {
        return $this->urlInterface->getBaseUrl();
    }

    /**
     * Get the current URL the user is on.
     */
    public function getCurrentUrl(): string
    {
        return $this->urlInterface->getCurrentUrl();
    }

    /**
     * Get a URL based on the given path and parameters.
     */
    public function getUrl(string $url, array $params = []): string
    {
        return $this->urlInterface->getUrl($url, ['_query' => $params]);
    }

    /**
     * Get a frontend URL for the given path and parameters.
     */
    public function getFrontendUrl(string $url, array $params = []): string
    {
        return $this->frontendUrl->getUrl($url, ['_query' => $params]);
    }

    /**
     * Get the site's issuer URL.
     */
    public function getIssuerUrl(): string
    {
        return $this->getBaseUrl() . OAuthConstants::ISSUER_URL_PATH;
    }

    /**
     * Get the image URL for a module asset.
     */
    public function getImageUrl(string $image): string
    {
        return $this->assetRepo->getUrl(
            OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_IMAGES . $image
        );
    }

    /**
     * Get admin CSS URL.
     */
    public function getAdminCssUrl(string $css): string
    {
        return $this->assetRepo->getUrl(
            OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_CSS . $css,
            ['area' => 'adminhtml']
        );
    }

    /**
     * Get admin JS URL.
     */
    public function getAdminJSUrl(string $js): string
    {
        return $this->assetRepo->getUrl(
            OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_JS . $js,
            ['area' => 'adminhtml']
        );
    }

    /**
     * Get admin metadata download URL.
     */
    public function getMetadataUrl(): string
    {
        return $this->assetRepo->getUrl(
            OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_METADATA,
            ['area' => 'adminhtml']
        );
    }

    /**
     * Get admin metadata file path.
     */
    public function getMetadataFilePath(): string
    {
        return $this->assetRepo->createAsset(
            OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_METADATA,
            ['area' => 'adminhtml']
        )->getSourceFile();
    }

    /**
     * Get the resource as a file path instead of a URL.
     */
    public function getResourcePath(string $key): string
    {
        return $this->assetRepo
            ->createAsset(
                OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_CERTS . $key,
                ['area' => 'adminhtml']
            )
            ->getSourceFile();
    }

    /**
     * Get the admin base/home page URL.
     */
    public function getAdminBaseUrl(): string
    {
        return $this->helperBackend->getHomePageUrl();
    }

    /**
     * Sanitize input data to prevent XSS and other injection attacks.
     *
     * @param mixed $value
     * @return mixed
     */
    public function sanitize($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $val) {
                $value[$key] = $this->sanitize($val);
            }
            return $value;
        }

        if (is_string($value)) {
            $clean = strip_tags(trim($value));
            return $this->escaper->escapeHtml($clean);
        }

        return $value;
    }

    /**
     * Get the admin URL for the site based on the path passed.
     */
    public function getAdminUrl(string $url, array $params = []): string
    {
        return $this->helperBackend->getUrl($url, ['_query' => $params]);
    }

    /**
     * Get the admin secure URL for the site based on the path passed.
     */
    public function getAdminSecureUrl(string $url, array $params = []): string
    {
        return $this->helperBackend->getUrl($url, ['_secure' => true, '_query' => $params]);
    }

    /**
     * Get the SP-initiated URL for frontend/customer OIDC login.
     */
    public function getSPInitiatedUrl(?string $relayState = null, ?string $appName = null): string
    {
        $relayState = $relayState ?? $this->getCurrentUrl();

        // If app_name is not set, try to retrieve it from the configuration
        if ($appName === null || $appName === '' || $appName === '0') {
            $appName = $this->getStoreConfig(OAuthConstants::APP_NAME);
        }

        return $this->getFrontendUrl(
            OAuthConstants::OAUTH_LOGIN_URL,
            ["relayState" => $relayState]
        ) . "&app_name=" . $appName;
    }

    /**
     * Get the admin SP-initiated URL for admin backend OIDC login.
     *
     * Uses the admin controller which sets loginType=admin.
     */
    public function getAdminSPInitiatedUrl(?string $relayState = null, ?string $appName = null): string
    {
        $relayState = $relayState ?? $this->getCurrentUrl();

        if ($appName === null || $appName === '' || $appName === '0') {
            $appName = $this->getStoreConfig(OAuthConstants::APP_NAME);
        }

        // Use admin URL to route to admin SendAuthorizationRequest controller
        return $this->getAdminUrl(
            OAuthConstants::OAUTH_LOGIN_URL,
            ["relayState" => $relayState, "app_name" => $appName]
        );
    }
}
