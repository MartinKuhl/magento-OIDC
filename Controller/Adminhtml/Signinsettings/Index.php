<?php

namespace MiniOrange\OAuth\Controller\Adminhtml\Signinsettings;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthMessages;

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
    protected \Magento\Framework\App\Response\Http\FileFactory $fileFactory;

    protected \Magento\Store\Model\StoreManagerInterface $_storeManager;
    private readonly \Magento\Framework\App\ProductMetadataInterface $productMetadata;

    /**
     * Initialize sign-in settings controller.
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        OAuthUtility $oauthUtility,
        ManagerInterface $messageManager,
        LoggerInterface $logger,
        \Magento\Framework\App\Response\Http\FileFactory $fileFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\ProductMetadataInterface $productMetadata
    ) {
        //You can use dependency injection to get any class this observer may need.
        parent::__construct($context, $resultPageFactory, $oauthUtility, $messageManager, $logger);
        $this->_storeManager = $storeManager;
        $this->fileFactory = $fileFactory;
        $this->productMetadata = $productMetadata;
    }

    /**
     * Main controller entry-point for Sign-in settings page.
     *
     * Handles saving, debug log toggling, clearing and downloading logs.
     *
     * @return \Magento\Framework\View\Result\Page|\Magento\Framework\App\ResponseInterface
     */
    #[\Override]
    public function execute()
    {
        try {
            $params = $this->getRequest()->getParams(); //get params

            // check if form options are being saved
            if ($this->isFormOptionBeingSaved($params)) {
                if ($params['option'] == 'saveSignInSettings') {
                    $this->processValuesAndSaveData($params);
                    $this->oauthUtility->flushCache();
                    $this->messageManager->addSuccessMessage(OAuthMessages::SETTINGS_SAVED);
                    $this->oauthUtility->reinitConfig();
                } elseif ($params['option'] == 'enable_debug_log') {
                    $debug_log_on = isset($params['debug_log_on']) ? 1 : 0;
                    $log_file_time = time();
                    $this->oauthUtility->setStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG, $debug_log_on);
                    $this->oauthUtility->flushCache();
                    $this->messageManager->addSuccessMessage(OAuthMessages::SETTINGS_SAVED);
                    $this->oauthUtility->reinitConfig();
                    if ($debug_log_on == '1') {
                        $this->oauthUtility->setStoreConfig(OAuthConstants::LOG_FILE_TIME, $log_file_time);
                    } elseif ($debug_log_on == '0' && $this->oauthUtility->isCustomLogExist()) {
                        $this->oauthUtility->setStoreConfig(OAuthConstants::LOG_FILE_TIME, null);
                        $this->oauthUtility->deleteCustomLogFile();
                    }
                } elseif ($params['option'] == 'clear_download_logs') {
                    if (isset($params['download_logs'])) {
                        $result = $this->handleDownloadLogs();
                        if ($result !== null) {
                            return $result;
                        }
                    } elseif (isset($params['clear_logs'])) {
                        $this->handleClearLogs();
                    }
                }

            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->oauthUtility->customlog($e->getMessage());
        }
        // generate page
        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend(__('MiniOrange OAuth'));
        return $resultPage;
    }

    /**
     * Handle downloading of debug log files.
     *
     * @return \Magento\Framework\App\ResponseInterface|null
     */
    private function handleDownloadLogs()
    {
        $fileName = "mo_oauth.log";
        $filePath = '../var/log/' . $fileName;
        $content = [
            'type' => 'filename',
            'value' => $filePath,
            'rm' => 0,
        ];

        if ($this->oauthUtility->isLogEnable()) {
            $this->logClientConfigurationIfAvailable();
        }

        if ($this->oauthUtility->isCustomLogExist() && $this->oauthUtility->isLogEnable()) {
            return $this->fileFactory->create($fileName, $content, DirectoryList::VAR_DIR);
        }

        $this->messageManager->addErrorMessage('Please Enable Debug Log Setting First');
        return null;
    }

    /**
     * Log client configuration settings for diagnostics.
     */
    private function logClientConfigurationIfAvailable(): void
    {
        $appName = $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
        $clientDetails = $this->oauthUtility->getClientDetailsByAppName($appName);

        if (!$clientDetails) {
            return;
        }

        $showCustomerLink = $this->oauthUtility->getStoreConfig(
            OAuthConstants::SHOW_CUSTOMER_LINK
        );
        $attributeEmail = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_EMAIL);
        $attributeUsername = $this->oauthUtility->getStoreConfig(
            OAuthConstants::MAP_USERNAME
        );
        $customerEmail = $this->oauthUtility->getStoreConfig(
            OAuthConstants::DEFAULT_MAP_EMAIL
        );

        $values = [
            $appName,
            $clientDetails["scope"],
            $clientDetails['authorize_endpoint'],
            $clientDetails['access_token_endpoint'],
            $clientDetails['user_info_endpoint'],
            $clientDetails["values_in_header"],
            $clientDetails["values_in_body"],
            $clientDetails['well_known_config_url'],
            $showCustomerLink,
            $attributeEmail,
            $attributeUsername,
            $customerEmail,
            OAuthConstants::VERSION,
            $this->productMetadata->getVersion(),
            phpversion()
        ];

        $this->customerConfigurationSettings($values);
    }

    /**
     * Handle clearing of debug log files.
     */
    private function handleClearLogs(): void
    {
        if ($this->oauthUtility->isCustomLogExist() !== 0) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::LOG_FILE_TIME, null);
            $this->oauthUtility->deleteCustomLogFile();
            $this->messageManager->addSuccessMessage('Logs Cleared Successfully');
        } else {
            $this->messageManager->addSuccessMessage('Logs Have Already Been Removed');
        }
    }

    /**
     * Process Values being submitted and save data in the database.
     */
    private function processValuesAndSaveData(array $params): void
    {
        $mo_oauth_show_customer_link = isset($params['mo_oauth_show_customer_link']) ? 1 : 0;
        $mo_oauth_show_admin_link = isset($params['mo_oauth_show_admin_link']) ? 1 : 0;
        $mo_sso_auto_create_admin = isset($params['mo_sso_auto_create_admin']) ? 1 : 0;
        $mo_sso_auto_create_customer = isset($params['mo_sso_auto_create_customer']) ? 1 : 0;
        $mo_oauth_enable_login_redirect = isset($params['mo_oauth_enable_login_redirect']) ? 1 : 0;
        $mo_oauth_logout_redirect_url = isset($params['mo_oauth_logout_redirect_url'])
            ? $params['mo_oauth_logout_redirect_url'] : '';
        $mo_disable_non_oidc_admin_login = isset($params['mo_disable_non_oidc_admin_login']) ? 1 : 0;
        $mo_disable_non_oidc_customer_login = isset(
            $params['mo_disable_non_oidc_customer_login']
        ) ? 1 : 0;

        $this->oauthUtility->customlog(
            "SignInSettings: Saving customer link setting: " . $mo_oauth_show_customer_link
        );
        $this->oauthUtility->customlog(
            "SignInSettings: Saving admin link setting: " . $mo_oauth_show_admin_link
        );
        $this->oauthUtility->customlog(
            "SignInSettings: Saving auto create admin setting: " . $mo_sso_auto_create_admin
        );
        $this->oauthUtility->customlog(
            "SignInSettings: Saving auto create customer setting: " . $mo_sso_auto_create_customer
        );
        $this->oauthUtility->customlog(
            "SignInSettings: Saving login redirect setting: " . $mo_oauth_enable_login_redirect
        );
        $this->oauthUtility->customlog("SignInSettings: Saving logout redirect url: " . $mo_oauth_logout_redirect_url);
        $this->oauthUtility->customlog(
            "SignInSettings: Saving disable non-OIDC admin login: " . $mo_disable_non_oidc_admin_login
        );
        $this->oauthUtility->customlog(
            "SignInSettings: Saving disable non-OIDC customer login: "
            . $mo_disable_non_oidc_customer_login
        );

        // ── Lockout-Prevention: OIDC-only requires Show-OIDC ──
        if ($mo_oauth_show_admin_link === 0 && $mo_disable_non_oidc_admin_login === 1) {
            $mo_disable_non_oidc_admin_login = 0;
            $this->messageManager->addWarningMessage(
                __(
                    'Admin OIDC-only login was automatically disabled because the OIDC login button '
                    . 'is not shown on the admin login page.'
                )
            );
        }

        if ($mo_oauth_show_customer_link === 0 && $mo_disable_non_oidc_customer_login === 1) {
            $mo_disable_non_oidc_customer_login = 0;
            $this->messageManager->addWarningMessage(
                __(
                    'Customer OIDC-only login was automatically disabled because the OIDC login button '
                    . 'is not shown on the customer login page.'
                )
            );
        }

        $this->oauthUtility->setStoreConfig(OAuthConstants::SHOW_CUSTOMER_LINK, $mo_oauth_show_customer_link);
        $this->oauthUtility->setStoreConfig(OAuthConstants::SHOW_ADMIN_LINK, $mo_oauth_show_admin_link);
        $this->oauthUtility->setStoreConfig(OAuthConstants::AUTO_CREATE_ADMIN, $mo_sso_auto_create_admin);
        $this->oauthUtility->setStoreConfig(OAuthConstants::AUTO_CREATE_CUSTOMER, $mo_sso_auto_create_customer);
        $this->oauthUtility->setStoreConfig(OAuthConstants::ENABLE_LOGIN_REDIRECT, $mo_oauth_enable_login_redirect);
        $this->oauthUtility->setStoreConfig(OAuthConstants::OAUTH_LOGOUT_URL, $mo_oauth_logout_redirect_url);
        $this->oauthUtility->setStoreConfig(
            OAuthConstants::DISABLE_NON_OIDC_ADMIN_LOGIN,
            $mo_disable_non_oidc_admin_login
        );
        $this->oauthUtility->setStoreConfig(
            OAuthConstants::DISABLE_NON_OIDC_CUSTOMER_LOGIN,
            $mo_disable_non_oidc_customer_login
        );
    }

    /**
     * Save the sign-in settings configuration.
     *
     *
     * @param (false|mixed|string)[] $values
     * @psalm-param list{string, mixed, mixed, mixed, mixed, mixed, mixed, mixed, mixed, mixed, mixed, mixed,
     *                    'v4.2.0', string, false|string} $values
     */
    private function customerConfigurationSettings(array $values): void
    {
        $this->oauthUtility->customlog("......................................................................");
        $this->oauthUtility->customlog("Plugin: OAuth Free : " . $values[12]);
        $this->oauthUtility->customlog("Plugin: Magento version : " . $values[13] . " ; Php version: " . $values[14]);
        $this->oauthUtility->customlog("Appname: " . $values[0]);
        $this->oauthUtility->customlog("Scope: " . $values[1]);
        $this->oauthUtility->customlog("Authorize_url: " . $values[2]);
        $this->oauthUtility->customlog("Accesstoken_url: " . $values[3]);
        $this->oauthUtility->customlog("Getuserinfo_url: " . $values[4]);
        $this->oauthUtility->customlog("Header: " . $values[5]);
        $this->oauthUtility->customlog("Body: " . $values[6]);
        $this->oauthUtility->customlog("Well known config url: " . $values[7]);
        $this->oauthUtility->customlog("Show_customer_link: " . $values[8]);
        $this->oauthUtility->customlog("Attribute_email: " . $values[9]);
        $this->oauthUtility->customlog("Attribute_username: " . $values[10]);
        $this->oauthUtility->customlog("Customer_email: " . $values[11]);
        $this->oauthUtility->customlog("Enable login redirect: " . $values[12]);
        $this->oauthUtility->customlog("......................................................................");
    }

    /**
     * Is the user allowed to view the Sign in Settings.
     * This is based on the ACL set by the admin in the backend.
     * Works in conjugation with acl.xml
     *
     * @return bool
     */
    #[\Override]
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_SIGNIN);
    }
}
