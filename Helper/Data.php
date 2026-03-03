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
    /** @var UserFactory */
    private readonly UserFactory $adminFactory;

    /** @var CustomerFactory */
    private readonly CustomerFactory $customerFactory;

    /** @var UrlInterface */
    private readonly UrlInterface $urlInterface;

    /** @var WriterInterface */
    private readonly WriterInterface $configWriter;

    /** @var AssetRepository */
    private readonly AssetRepository $assetRepo;

    /** @var BackendHelper */
    private readonly BackendHelper $helperBackend;

    /** @var Url */
    private readonly Url $frontendUrl;

    /** @var MiniorangeOauthClientAppsFactory */
    private readonly MiniorangeOauthClientAppsFactory $clientAppsFactory;

    /** @var ClientCollectionFactory */
    private readonly ClientCollectionFactory $clientCollectionFactory;

    /** @var AppResource */
    private readonly AppResource $appResource;

    /** @var UserResource */
    private readonly UserResource $userResource;

    /** @var CustomerResource */
    private readonly CustomerResource $customerResource;

    /** @var EncryptorInterface */
    private readonly EncryptorInterface $encryptor;

    /** @var Escaper */
    private readonly Escaper $escaper;

    /** @var LoggerInterface */
    private readonly LoggerInterface $logger;

    /**
     * Initialize Data helper.
     *
     * @param Context $context
     * @param UserFactory $adminFactory
     * @param CustomerFactory $customerFactory
     * @param UrlInterface $urlInterface
     * @param WriterInterface $configWriter
     * @param AssetRepository $assetRepo
     * @param BackendHelper $helperBackend
     * @param Url $frontendUrl
     * @param MiniorangeOauthClientAppsFactory $clientAppsFactory
     * @param ClientCollectionFactory $clientCollectionFactory
     * @param AppResource $appResource
     * @param UserResource $userResource
     * @param CustomerResource $customerResource
     * @param EncryptorInterface $encryptor
     * @param Escaper $escaper
     * @param LoggerInterface $logger
     */
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
        $this->adminFactory             = $adminFactory;
        $this->customerFactory          = $customerFactory;
        $this->urlInterface             = $urlInterface;
        $this->configWriter             = $configWriter;
        $this->assetRepo                = $assetRepo;
        $this->helperBackend            = $helperBackend;
        $this->frontendUrl              = $frontendUrl;
        $this->clientAppsFactory        = $clientAppsFactory;
        $this->clientCollectionFactory  = $clientCollectionFactory;
        $this->appResource              = $appResource;
        $this->userResource             = $userResource;
        $this->customerResource         = $customerResource;
        $this->encryptor                = $encryptor;
        $this->escaper                  = $escaper;
        $this->logger                   = $logger;
    }

    /**
     * Set the entry in the OAuthClientApp table.
     *
     * @param string $appName
     * @param string $clientId
     * @param string $clientSecret
     * @param string $scope
     * @param string $authorizeUrl
     * @param string $accessTokenUrl
     * @param string $userInfoUrl
     * @param string $wellKnownConfigUrl
     * @param string $grantType
     * @param bool   $sendHeader
     * @param bool   $sendBody
     * @param string $issuer
     * @param string $endSessionUrl
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
        string $issuer = '',
        string $endSessionUrl = ''
    ): void {
        $model = $this->clientAppsFactory->create();
        $model->addData(
            [
                'app_name'              => $this->sanitize($appName),
                'callback_uri'          => '',
                'clientID'              => $this->sanitize($clientId),
                'client_secret'         => $this->encryptor->encrypt($this->sanitize($clientSecret)),
                'scope'                 => $this->sanitize($scope),
                'authorize_endpoint'    => $this->sanitize($authorizeUrl),
                'access_token_endpoint' => $this->sanitize($accessTokenUrl),
                'user_info_endpoint'    => $this->sanitize($userInfoUrl),
                'well_known_config_url' => $this->sanitize($wellKnownConfigUrl),
                'issuer'                => $this->sanitize($issuer),
                'endsession_endpoint'   => $this->sanitize($endSessionUrl),
                'grant_type'            => $this->sanitize($grantType),
                'values_in_header'      => $sendHeader,
                'values_in_body'        => $sendBody,
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
     * @param  string $appName
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
            if (preg_match('/^\d+:\d+:/', (string) $data['client_secret'])) {
                $data['client_secret'] = $this->encryptor->decrypt($data['client_secret']);
            }
            // Otherwise keep as-is (plaintext will be encrypted on next admin save)
        }

        return $data;
    }

    /**
     * Get client details by numeric provider ID (row `id`).
     *
     * MP-03: Direct lookup by primary key. Used when `providerId` is available
     * from the decoded OAuth state parameter (Sprint 5).
     *
     * @param  int $providerId Row `id` of the provider record (must be > 0)
     * @return array|null Client details array or null if not found
     */
    public function getClientDetailsById(int $providerId): ?array
    {
        if ($providerId <= 0) {
            return null;
        }
        $collection = $this->getOAuthClientApps();
        $collection->addFieldToFilter('id', ['eq' => $providerId]);
        $data = $collection->getSize() > 0 ? $collection->getFirstItem()->getData() : null;

        if ($data !== null && isset($data['client_secret']) && !empty($data['client_secret'])
            && preg_match('/^\d+:\d+:/', (string) $data['client_secret'])) {
            $data['client_secret'] = $this->encryptor->decrypt($data['client_secret']);
        }

        return $data;
    }

    /**
     * Persist the last test run result for a provider – lookup by app_name.
     *
     * Kept for backward compatibility. Prefer saveTestStatusById() when the
     * numeric provider ID is available (multi-provider safe).
     *
     * @param string $appName Provider app_name
     * @param string $status  'success', 'failed', or 'unsuccessful'
     */
    public function saveTestStatus(string $appName, string $status): void
    {
        $allowed = ['success', 'failed', 'unsuccessful'];
        if (!in_array($status, $allowed, true)) {
            $this->logger->warning('saveTestStatus: invalid status value "' . $status . '"');
            return;
        }

        $collection = $this->clientCollectionFactory->create();
        $collection->addFieldToFilter('app_name', $appName);
        $model = $collection->getFirstItem();

        if (!$model->getId()) {
            $this->logger->warning('saveTestStatus: no provider found for app_name "' . $appName . '"');
            return;
        }

        try {
            $model->setData('last_test_status', $status);
            $model->setData('last_test_at', date('Y-m-d H:i:s'));
            $this->appResource->save($model);
        } catch (\Exception $e) {
            $this->logger->error('saveTestStatus failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Persist the last test run result for a provider – lookup by primary key.
     *
     * Preferred over saveTestStatus() in multi-provider setups because it uses
     * the numeric ID from the OAuth state parameter instead of app_name from
     * the customer session (which may be empty after a redirect).
     *
     * @param int    $providerId Row `id` of the provider record (must be > 0)
     * @param string $status     'success', 'failed', or 'unsuccessful'
     */
    public function saveTestStatusById(int $providerId, string $status): void
    {
        $allowed = ['success', 'failed', 'unsuccessful'];
        if ($providerId <= 0 || !in_array($status, $allowed, true)) {
            $this->logger->warning(
                'saveTestStatusById: invalid arguments – providerId=' . $providerId . ', status=' . $status
            );
            return;
        }

        $model = $this->clientAppsFactory->create();
        $this->appResource->load($model, $providerId);

        if (!$model->getId()) {
            $this->logger->warning('saveTestStatusById: no provider found for id=' . $providerId);
            return;
        }

        try {
            $model->setData('last_test_status', $status);
            $model->setData('last_test_at', date('Y-m-d H:i:s'));
            $this->appResource->save($model);
        } catch (\Exception $e) {
            $this->logger->error('saveTestStatusById failed: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Save received OIDC claims to the provider record.
     *
     * Persists the extracted claim keys as JSON to the
     * received_oidc_claims column in miniorange_oauth_client_apps.
     *
     * @param int      $providerId Provider row ID
     * @param string[] $claimKeys  Array of claim key names
     */
    public function saveReceivedOidcClaims(int $providerId, array $claimKeys): void
    {
        if ($providerId <= 0) {
            return;
        }

        $model = $this->clientAppsFactory->create();
        $this->appResource->load($model, $providerId);

        if (!$model->getId()) {
            $this->logger->warning(
                'saveReceivedOidcClaims: no provider found for id=' . $providerId
            );
            return;
        }

        try {
            $model->setData('received_oidc_claims', json_encode($claimKeys));
            $this->appResource->save($model);
        } catch (\Exception $e) {
            $this->logger->error(
                'saveReceivedOidcClaims failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }


    /**
     * Return all active provider records for a given login type, ordered by sort_order.
     *
     * MP-03: Powers multi-provider SSO button rendering and provider selection UI.
     *
     * @param  string $loginType 'customer', 'admin', or 'both'
     * @return array  Array of provider data arrays (may be empty)
     */
    public function getAllActiveProviders(string $loginType = 'customer'): array
    {
        $collection = $this->getOAuthClientApps();
        $collection->addFieldToFilter('is_active', ['eq' => 1]);
        $collection->addFieldToFilter(
            'login_type',
            ['in' => [$loginType, 'both']]
        );
        $collection->setOrder('sort_order', 'ASC');

        $results = [];
        foreach ($collection as $item) {
            $data = $item->getData();
            if (isset($data['client_secret']) && !empty($data['client_secret'])
                && preg_match('/^\d+:\d+:/', (string) $data['client_secret'])) {
                $data['client_secret'] = $this->encryptor->decrypt($data['client_secret']);
            }
            $results[] = $data;
        }

        return $results;
    }

    /**
     * Extract data stored in the store config table.
     *
     * @param  string $config
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
     * @param string $config
     * @param mixed  $value
     * @param bool   $skipSanitize
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
                if (count($collection) > 0) {
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
     * @param string     $url
     * @param mixed      $value
     * @param int|string $id
     * @param bool       $admin
     * @throws \Exception
     */
    public function saveConfig(string $url, $value, $id, bool $admin): void
    {
        $admin ? $this->saveAdminStoreConfig($url, $value, $id) : $this->saveCustomerStoreConfig($url, $value, $id);
    }

    /**
     * Extract information stored in the admin user table.
     *
     * @param  string     $config
     * @param  int|string $id
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
     * @param string     $url
     * @param mixed      $value
     * @param int|string $id
     * @throws \Exception
     */
    private function saveAdminStoreConfig(string $url, $value, $id): void
    {
        $data  = [$url => $value];
        $model = $this->adminFactory->create();
        $this->userResource->load($model, $id);
        $model->addData($data);
        $model->setId($id);
        $this->userResource->save($model);
    }

    /**
     * Extract information stored in the customer user table.
     *
     * @param  string     $config
     * @param  int|string $id
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
     * @param string     $url
     * @param mixed      $value
     * @param int|string $id
     * @throws \Exception
     */
    private function saveCustomerStoreConfig(string $url, $value, $id): void
    {
        $data  = [$url => $value];
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
     *
     * @param string $url
     * @param array  $params
     */
    public function getUrl(string $url, array $params = []): string
    {
        return $this->urlInterface->getUrl($url, ['_query' => $params]);
    }

    /**
     * Get a frontend URL for the given path and parameters.
     *
     * @param string $url
     * @param array  $params
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
     *
     * @param string $image
     */
    public function getImageUrl(string $image): string
    {
        return $this->assetRepo->getUrl(
            OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_IMAGES . $image
        );
    }

    /**
     * Get admin CSS URL.
     *
     * @param string $css
     */
    public function getAdminCssUrl(string $css): string
    {
        // @phpstan-ignore-next-line
        return $this->assetRepo->getUrl(
            OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_CSS . $css,
            ['area' => 'adminhtml']
        );
    }

    /**
     * Get admin JS URL.
     *
     * @param string $js
     */
    public function getAdminJSUrl(string $js): string
    {
        // @phpstan-ignore-next-line
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
        // @phpstan-ignore-next-line
        return $this->assetRepo->getUrl(
            OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_METADATA,
            ['area' => 'adminhtml']
        );
    }

    /**
     * Build the SP-initiated URL for a specific provider by its numeric ID.
     *
     * MP-05: Used by the multi-provider SSO button loop. The URL includes
     * `provider_id` so `SendAuthorizationRequest` can load the correct row
     * without needing `app_name`.
     *
     * @param  int         $providerId  Row `id` from miniorange_oauth_client_apps
     * @param  string|null $relayState  Optional post-login redirect URL
     * @return string Frontend SSO URL with provider_id query parameter
     */
    public function getSPInitiatedUrlForProvider(int $providerId, ?string $relayState = null): string
    {
        $relayState = $relayState ?? $this->getCurrentUrl();
        return $this->getFrontendUrl(
            OAuthConstants::OAUTH_LOGIN_URL,
            ['relayState' => $relayState]
        ) . '&provider_id=' . $providerId;
    }

    /**
     * Get the admin SP-initiated URL for admin backend OIDC login.
     *
     * Uses the admin controller which sets loginType=admin.
     *
     * @param string|null $relayState
     * @param string|null $appName
     */
    public function getAdminSPInitiatedUrl(?string $relayState = null, ?string $appName = null): string
    {
        $relayState = $relayState ?? $this->getCurrentUrl();

        if ($appName === null || $appName === '' || $appName === '0') {
            $appName = $this->getStoreConfig(OAuthConstants::APP_NAME);
        }

        return $this->getAdminUrl(
            OAuthConstants::OAUTH_LOGIN_URL,
            ['relayState' => $relayState, 'app_name' => $appName]
        );
    }

    /**
     * Get the admin URL for the site based on the path passed.
     *
     * @param  string $url
     * @param  array  $params
     */
    public function getAdminUrl(string $url, array $params = []): string
    {
        return $this->helperBackend->getUrl($url, ['_query' => $params]);
    }

    /**
     * Get the admin secure URL for the site based on the path passed.
     *
     * @param  string $url
     * @param  array  $params
     */
    public function getAdminSecureUrl(string $url, array $params = []): string
    {
        return $this->helperBackend->getUrl($url, ['_secure' => true, '_query' => $params]);
    }
    
    /**
     * Expose encryptor for use in controllers (e.g. config import/export).
     */
    public function getEncryptor(): EncryptorInterface
    {
        return $this->encryptor;
    }

    /**
     * Expose client apps factory for use in controllers (e.g. config import).
     */
    public function getClientAppsFactory(): MiniorangeOauthClientAppsFactory
    {
        return $this->clientAppsFactory;
    }

    /**
     * Expose app resource model for use in controllers (e.g. config import).
     */
    public function getAppResource(): AppResource
    {
        return $this->appResource;
    }

    /**
     * Sanitize a string value.
     *
     * @param  mixed $value
     * @return string
     */
    protected function sanitize($value): string
    {
        return $this->escaper->escapeHtml((string) $value);
    }
}
