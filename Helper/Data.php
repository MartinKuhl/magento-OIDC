<?php

namespace MiniOrange\OAuth\Helper;

use \Magento\Framework\App\Helper\AbstractHelper;
use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * This class contains functions to get and set the required data
 * from Magento database or session table/file or generate some
 * necessary values to be used in our module.
 */
class Data extends AbstractHelper
{

    protected $scopeConfig;
    protected $adminFactory;
    protected $customerFactory;
    protected $urlInterface;
    protected $configWriter;
    protected $assetRepo;
    protected $helperBackend;
    protected $frontendUrl;
    protected $_miniorangeOauthClientAppsFactory;
    protected $appResource;
    protected $userResource;
    protected $customerResource;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\User\Model\UserFactory $adminFactory,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        \Magento\Framework\UrlInterface $urlInterface,
        \Magento\Framework\App\Config\Storage\WriterInterface $configWriter,
        \Magento\Framework\View\Asset\Repository $assetRepo,
        \Magento\Backend\Helper\Data $helperBackend,
        \Magento\Framework\Url $frontendUrl,
        \MiniOrange\OAuth\Model\MiniorangeOauthClientAppsFactory $miniorangeOauthClientAppsFactory,
        \MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps $appResource,
        \Magento\User\Model\ResourceModel\User $userResource,
        \Magento\Customer\Model\ResourceModel\Customer $customerResource
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->adminFactory = $adminFactory;
        $this->customerFactory = $customerFactory;
        $this->urlInterface = $urlInterface;
        $this->configWriter = $configWriter;
        $this->assetRepo = $assetRepo;
        $this->helperBackend = $helperBackend;
        $this->frontendUrl = $frontendUrl;
        $this->_miniorangeOauthClientAppsFactory = $miniorangeOauthClientAppsFactory;
        $this->appResource = $appResource;
        $this->userResource = $userResource;
        $this->customerResource = $customerResource;
    }


    /**
     * Set the entry in the OAuthClientApp Table
     *
     * @param $mo_oauth_app_name
     * @param $mo_oauth_client_id
     * @param  $mo_oauth_client_secret
     * @param  $mo_oauth_scope
     * @param  $mo_oauth_endsession_url
     * @param  $mo_oauth_authorize_url
     * @param  $mo_oauth_accesstoken_url
     * @param  $mo_oauth_grant_type
     * @param  $mo_oauth_getuserinfo_url
     * @param  $send_header
     * @param  $send_body
     * @param  $jwksURL
     */

    public function setOAuthClientApps(
        $mo_oauth_app_name,
        $mo_oauth_client_id,
        $mo_oauth_client_secret,
        $mo_oauth_scope,
        $mo_oauth_authorize_url,
        $mo_oauth_accesstoken_url,
        $mo_oauth_getuserinfo_url,
        $mo_oauth_well_known_config_url,
        $mo_oauth_grant_type,
        $send_header,
        $send_body,
    ) {
        $model = $this->_miniorangeOauthClientAppsFactory->create();
        $model->addData([
            "app_name" => $this->sanitize($mo_oauth_app_name),
            "callback_uri" => '',
            "clientID" => $this->sanitize($mo_oauth_client_id),
            "client_secret" => $this->sanitize($mo_oauth_client_secret),
            "scope" => $this->sanitize($mo_oauth_scope),
            "authorize_endpoint" => $this->sanitize($mo_oauth_authorize_url),
            "access_token_endpoint" => $this->sanitize($mo_oauth_accesstoken_url),
            "user_info_endpoint" => $this->sanitize($mo_oauth_getuserinfo_url),
            "well_known_config_url" => $this->sanitize($mo_oauth_well_known_config_url),
            "grant_type" => $this->sanitize($mo_oauth_grant_type),
            "values_in_header" => $send_header,
            "values_in_body" => $send_body
        ]);
        $this->appResource->save($model);
    }

    /**
     * Delete all the records
     */
    public function deleteAllRecords()
    {
        $this->_miniorangeOauthClientAppsFactory
            ->create()
            ->getCollection()
            ->walk('delete');
    }
    /**
     * Get All the entry from the OAuthClientApp Table
     */
    public function getOAuthClientApps()
    {
        $model = $this->_miniorangeOauthClientAppsFactory->create();
        $collection = $model->getCollection();
        return $collection;
    }

    /**
     * Get the app ID from the OAuthClientApp Table
     */
    public function getIDPApps()
    {
        return $this->getOAuthClientApps();
    }

    /**
     * Function to extract data stored in the store config table.
     *
     * @param $config
     */
    public function getStoreConfig($config)
    {
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        return $this->scopeConfig->getValue('miniorange/oauth/' . $config, $storeScope);
    }


    /**
     * Function to store data stored in the store config table.
     *
     * @param string $config
     * @param mixed $value
     * @param bool $skipSanitize
     */
    public function setStoreConfig($config, $value, $skipSanitize = false)
    {
        $finalValue = $skipSanitize ? $value : $this->sanitize($value);
        $this->configWriter->save('miniorange/oauth/' . $config, $finalValue);

        // Wenn es sich um Admin- oder Kunden-Link-Einstellungen handelt, aktualisieren Sie auch die OAuth-Client-App-Tabelle
        if ($config === OAuthConstants::SHOW_ADMIN_LINK || $config === OAuthConstants::SHOW_CUSTOMER_LINK) {
            try {
                $collection = $this->getOAuthClientApps();
                if ($collection && count($collection) > 0) {
                    foreach ($collection as $item) {
                        $model = $this->_miniorangeOauthClientAppsFactory->create()->load($item->getId());
                        $model->setData($config, $finalValue);
                        $this->appResource->save($model);
                    }
                }
            } catch (\Exception $e) {
                // Fehler beim Aktualisieren der Client-App-Tabelle protokollieren
            }
        }
    }


    /**
     * This function is used to save user attributes to the
     * database and save it. Mostly used in the SSO flow to
     * update user attributes. Decides which user to update.
     *
     * @param $url
     * @param $value
     * @param $id
     * @param $admin
     * @throws \Exception
     */
    public function saveConfig($url, $value, $id, $admin)
    {
        $admin ? $this->saveAdminStoreConfig($url, $value, $id) : $this->saveCustomerStoreConfig($url, $value, $id);
    }


    /**
     * Function to extract information stored in the admin user table.
     *
     * @param $config
     * @param $id
     */
    public function getAdminStoreConfig($config, $id)
    {
        return $this->adminFactory->create()->load($id)->getData($config);
    }


    /**
     * This function is used to save admin attributes to the
     * database and save it. Mostly used in the SSO flow to
     * update user attributes.
     *
     * @param $url
     * @param $value
     * @param $id
     * @throws \Exception
     */
    private function saveAdminStoreConfig($url, $value, $id)
    {
        $data = [$url => $value];
        $model = $this->adminFactory->create()->load($id)->addData($data);
        $model->setId($id);
        $this->userResource->save($model);
    }


    /**
     * Function to extract information stored in the customer user table.
     *
     * @param $config
     * @param $id
     */
    public function getCustomerStoreConfig($config, $id)
    {
        return $this->customerFactory->create()->load($id)->getData($config);
    }


    /**
     * This function is used to save customer attributes to the
     * database and save it. Mostly used in the SSO flow to
     * update user attributes.
     *
     * @param $url
     * @param $value
     * @param $id
     * @throws \Exception
     */
    private function saveCustomerStoreConfig($url, $value, $id)
    {
        $data = [$url => $value];
        $model = $this->customerFactory->create()->load($id)->addData($data);
        $model->setId($id);
        $this->customerResource->save($model);
    }


    /**
     * Function to get the sites Base URL.
     */
    public function getBaseUrl()
    {
        return $this->urlInterface->getBaseUrl();
    }


    /**
     * Function get the current url the user is on.
     */
    public function getCurrentUrl()
    {
        return $this->urlInterface->getCurrentUrl();
    }


    /**
     * Function to get the url based on where the user is.
     *
     * @param $url
     */
    public function getUrl($url, $params = [])
    {
        return $this->urlInterface->getUrl($url, ['_query' => $params]);
    }


    /**
     * Function to get the sites frontend url.
     *
     * @param $url
     */
    public function getFrontendUrl($url, $params = [])
    {
        return $this->frontendUrl->getUrl($url, ['_query' => $params]);
    }


    /**
     * Function to get the sites Issuer URL.
     */
    public function getIssuerUrl()
    {
        return $this->getBaseUrl() . OAuthConstants::ISSUER_URL_PATH;
    }


    /**
     * Function to get the Image URL of our module.
     *
     * @param $image
     */
    public function getImageUrl($image)
    {
        return $this->assetRepo->getUrl(OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_IMAGES . $image);
    }


    /**
     * Get Admin CSS URL
     */
    public function getAdminCssUrl($css)
    {
        return $this->assetRepo->getUrl(OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_CSS . $css, ['area' => 'adminhtml']);
    }


    /**
     * Get Admin JS URL
     */
    public function getAdminJSUrl($js)
    {
        return $this->assetRepo->getUrl(OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_JS . $js, ['area' => 'adminhtml']);
    }


    /**
     * Get Admin Metadata Download URL
     */
    public function getMetadataUrl()
    {
        return $this->assetRepo->getUrl(OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_METADATA, ['area' => 'adminhtml']);
    }


    /**
     * Get Admin Metadata File Path
     */
    public function getMetadataFilePath()
    {
        return $this->assetRepo->createAsset(OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_METADATA, ['area' => 'adminhtml'])
            ->getSourceFile();
    }


    /**
     * Function to get the resource as a path instead of the URL.
     *
     * @param $key
     */
    public function getResourcePath($key)
    {
        return $this->assetRepo
            ->createAsset(OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_CERTS . $key, ['area' => 'adminhtml'])
            ->getSourceFile();
    }


    public function getAdminBaseUrl()
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
            return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
        }
        return $value;
    }


    /**
     * Get the Admin url for the site based on the path passed,
     * Append the query parameters to the URL if necessary.
     *
     * @param $url
     * @param $params
     */
    public function getAdminUrl($url, $params = [])
    {
        return $this->helperBackend->getUrl($url, ['_query' => $params]);
    }


    /**
     * Get the Admin secure url for the site based on the path passed,
     * Append the query parameters to the URL if necessary.
     *
     * @param $url
     * @param $params
     */
    public function getAdminSecureUrl($url, $params = [])
    {
        return $this->helperBackend->getUrl($url, ['_secure' => true, '_query' => $params]);
    }


    /**
     * Get the SP InitiatedURL (for frontend/customer OIDC login)
     *
     * @param $relayState
     * @param $app_name
     */
    public function getSPInitiatedUrl($relayState = null, $app_name = NULL)
    {
        $relayState = is_null($relayState) ? $this->getCurrentUrl() : $relayState;

        // Wenn app_name nicht gesetzt ist, versuchen Sie es aus der Konfiguration zu holen
        if (empty($app_name)) {
            $app_name = $this->getStoreConfig(OAuthConstants::APP_NAME);
        }

        return $this->getFrontendUrl(
            OAuthConstants::OAUTH_LOGIN_URL,
            ["relayState" => $relayState]
        ) . "&app_name=" . $app_name;
    }

    /**
     * Get the Admin SP InitiatedURL (for admin backend OIDC login)
     * Uses the admin controller which sets loginType=admin
     *
     * @param $relayState
     * @param $app_name
     */
    public function getAdminSPInitiatedUrl($relayState = null, $app_name = NULL)
    {
        $relayState = is_null($relayState) ? $this->getCurrentUrl() : $relayState;

        if (empty($app_name)) {
            $app_name = $this->getStoreConfig(OAuthConstants::APP_NAME);
        }

        // Use admin URL to route to admin SendAuthorizationRequest controller
        return $this->getAdminUrl(
            OAuthConstants::OAUTH_LOGIN_URL,
            ["relayState" => $relayState, "app_name" => $app_name]
        );
    }
}
