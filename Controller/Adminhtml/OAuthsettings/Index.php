<?php

namespace MiniOrange\OAuth\Controller\Adminhtml\OAuthsettings;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthMessages;

use MiniOrange\OAuth\Controller\Actions\BaseAdminAction;
use MiniOrange\OAuth\Helper\Curl;
use MiniOrange\OAuth\Model\MiniorangeOauthClientAppsFactory;
use MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps as AppResource;

/**
 * OAuth Settings admin controller.
 *
 * Handles discovery by URL or manual endpoint entry and saves OAuth/OIDC
 * client configuration provided by the administrator.
 *
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 */
class Index extends BaseAdminAction implements HttpPostActionInterface, HttpGetActionInterface
{
    /** @var Curl */
    private readonly Curl $curl;

    /** @var MiniorangeOauthClientAppsFactory */
    private readonly MiniorangeOauthClientAppsFactory $clientAppsFactory;

    /** @var AppResource */
    private readonly AppResource $appResource;

    /**
     * Initialize OAuth settings controller.
     *
     * @param \Magento\Backend\App\Action\Context              $context
     * @param \Magento\Framework\View\Result\PageFactory       $resultPageFactory
     * @param \MiniOrange\OAuth\Helper\OAuthUtility            $oauthUtility
     * @param \Magento\Framework\Message\ManagerInterface      $messageManager
     * @param \Psr\Log\LoggerInterface                         $logger
     * @param Curl                                             $curl
     * @param MiniorangeOauthClientAppsFactory                 $clientAppsFactory
     * @param AppResource                                      $appResource
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Psr\Log\LoggerInterface $logger,
        Curl $curl,
        MiniorangeOauthClientAppsFactory $clientAppsFactory,
        AppResource $appResource
    ) {
        $this->curl              = $curl;
        $this->clientAppsFactory = $clientAppsFactory;
        $this->appResource       = $appResource;
        parent::__construct($context, $resultPageFactory, $oauthUtility, $messageManager, $logger);
    }

    /**
     * Execute OAuth settings save action.
     *
     * @return \Magento\Framework\View\Result\Page
     */
    #[\Override]
    public function execute() // phpcs:ignore Generic.Metrics.NestingLevel.TooHigh
    {
        try {
            $params = $this->getRequest()->getParams(); //get params

            // check if form options are being saved
            // phpcs:ignore Magento2.Metrics.NestingLevel
            if ($this->isFormOptionBeingSaved($params) && isset($params['endpoint_radio_button'])) {
                // When editing an existing provider, secret is optional (keep existing if blank)
                $isProviderContext = (int) ($params['provider_id'] ?? 0) > 0;

                // Required fields common to all modes; secret omitted in provider-context
                $requiredBase = ['mo_oauth_app_name' => $params, 'mo_oauth_client_id' => $params,
                                 'mo_oauth_scope' => $params];
                if (!$isProviderContext) {
                    $requiredBase['mo_oauth_client_secret'] = $params;
                }

                //Store radio button value in $radiostate parameter.
                $radiostate = $params['endpoint_radio_button'];
                //check whether URL radio button is checked or manual radio button is checked.

                if ($radiostate == 'byurl') {
                    $rawUrl = trim((string) ($params['endpoint_url'] ?? ''));
                    if ($rawUrl !== '') {

                        // SEC-04: Validate — must be a well-formed HTTPS URL
                        $url = filter_var($rawUrl, FILTER_VALIDATE_URL);
                        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
                        if ($url === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
                            $this->messageManager->addErrorMessage(
                                'Discovery URL must be a valid HTTPS URL '
                                . '(e.g. https://provider.example.com/.well-known/openid-configuration).'
                            );
                        } else {
                            // SEC-04: Block SSRF — reject loopback and RFC-1918 private ranges
                            // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
                            $host = (string) parse_url($url, PHP_URL_HOST);
                            $isPrivateHost = in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)
                                || (bool) preg_match(
                                    '/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/',
                                    $host
                                );

                            if ($isPrivateHost) {
                                $this->messageManager->addErrorMessage(
                                    'Discovery URL must not point to a private or internal network address.'
                                );
                                $this->oauthUtility->customlog(
                                    'SEC-04: Blocked SSRF attempt — private host in discovery URL: ' . $host
                                );
                            } else {
                                // Fetch the OIDC discovery document
                                $file = $this->curl->sendUserInfoRequest($url, []);

                                $obj = json_decode($file);
                                $this->checkIfRequiredFieldsEmpty($requiredBase);
                                // Validate discovery document has required OIDC endpoints
                                if ($obj !== null && isset($obj->authorization_endpoint, $obj->token_endpoint)) {
                                    $mo_oauth_authorize_url   = (string) $obj->authorization_endpoint;
                                    $mo_oauth_accesstoken_url = (string) $obj->token_endpoint;
                                    $mo_oauth_getuserinfo_url = isset($obj->userinfo_endpoint)
                                        ? (string) $obj->userinfo_endpoint : '';
                                    $mo_oauth_issuer          = isset($obj->issuer)
                                        ? (string) $obj->issuer : '';

                                    // Store endpoint parameters for saving to database
                                    $params['mo_oauth_authorize_url']    = trim($mo_oauth_authorize_url);
                                    $params['mo_oauth_accesstoken_url']  = trim($mo_oauth_accesstoken_url);
                                    $params['mo_oauth_getuserinfo_url']  = trim($mo_oauth_getuserinfo_url);
                                    $params['mo_oauth_issuer']           = trim($mo_oauth_issuer);
                                    $params['mo_oauth_endsession_url']   = isset($obj->end_session_endpoint)
                                        ? trim((string) $obj->end_session_endpoint) : '';

                                    $endpointRequired = array_merge(
                                        $requiredBase,
                                        ['mo_oauth_authorize_url' => $params,
                                         'mo_oauth_accesstoken_url' => $params]
                                    );
                                    $this->checkIfRequiredFieldsEmpty($endpointRequired);
                                    $this->processValuesAndSaveData($params);
                                    $this->oauthUtility->flushCache();
                                    $this->messageManager->addSuccessMessage(OAuthMessages::SETTINGS_SAVED);
                                    $this->oauthUtility->reinitConfig();

                                } else {
                                    $this->messageManager->addErrorMessage(
                                        'Please enter a valid OIDC discovery URL. '
                                        . 'The document is missing required endpoints '
                                        . '(authorization_endpoint, token_endpoint).'
                                    );
                                    $this->oauthUtility->customlog(
                                        'Discovery document missing authorization_endpoint or token_endpoint.'
                                    );
                                }
                            }
                        }
                    } else {

                        $this->messageManager->addErrorMessage('Please Enter URL');
                        $this->oauthUtility->customlog('URL is empty. Please enter a valid URL.');
                    }
                } elseif ($radiostate == 'bymanual') {
                    $this->checkIfRequiredFieldsEmpty(
                        array_merge(
                            $requiredBase,
                            ['mo_oauth_authorize_url' => $params,
                             'mo_oauth_accesstoken_url' => $params]
                        )
                    );
                    $this->processValuesAndSaveData($params);
                    $this->oauthUtility->flushCache();
                    $this->messageManager->addSuccessMessage(OAuthMessages::SETTINGS_SAVED);
                    $this->oauthUtility->reinitConfig();
                } else {
                    $this->messageManager->addErrorMessage('Please Select Required OAuth Endpoints option');
                    $this->oauthUtility->customlog(
                        'Error in Controller->Adminhtml->OAuthsettings->index file...'
                        . 'Please Select Required OAuth Endpoints option'
                    );
                }
                // check if required values have been submitted

                $this->oauthUtility->reinitConfig();

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
     * Process Values being submitted and save data in the database.
     *
     * When provider_id is present in $params, saves directly to that provider's
     * row in miniorange_oauth_client_apps (provider-context mode).
     * Otherwise falls back to the legacy single-provider behaviour.
     *
     * @param array $params
     */
    private function processValuesAndSaveData(array $params): void
    {
        $providerId = (int) ($params['provider_id'] ?? 0);

        $mo_oauth_app_name            = trim((string) ($params['mo_oauth_app_name'] ?? ''));
        $mo_oauth_client_id           = trim((string) ($params['mo_oauth_client_id'] ?? ''));
        $mo_oauth_client_secret       = trim((string) ($params['mo_oauth_client_secret'] ?? ''));
        $mo_oauth_scope               = trim((string) ($params['mo_oauth_scope'] ?? ''));
        $mo_oauth_authorize_url       = trim((string) ($params['mo_oauth_authorize_url'] ?? ''));
        $mo_oauth_accesstoken_url     = trim((string) ($params['mo_oauth_accesstoken_url'] ?? ''));
        $mo_oauth_getuserinfo_url     = trim((string) ($params['mo_oauth_getuserinfo_url'] ?? ''));
        $mo_oauth_well_known_config_url = trim((string) ($params['endpoint_url'] ?? ''));
        $mo_oauth_issuer              = trim((string) ($params['mo_oauth_issuer'] ?? ''));
        $mo_oauth_endsession_url      = trim((string) ($params['mo_oauth_endsession_url'] ?? ''));
        $send_header                  = isset($params['send_header']);
        $send_body                    = isset($params['send_body']);

        if ($providerId > 0) {
            // --- Provider-context mode: UPDATE the specific provider row ---
            $model = $this->clientAppsFactory->create();
            $this->appResource->load($model, $providerId);
            if (!$model->getId()) {
                $this->messageManager->addErrorMessage((string) __('Provider not found.'));
                return;
            }
            $model->setData('app_name', $mo_oauth_app_name);
            $model->setData('clientID', $mo_oauth_client_id);
            $model->setData('scope', $mo_oauth_scope);
            $model->setData('authorize_endpoint', $mo_oauth_authorize_url);
            $model->setData('access_token_endpoint', $mo_oauth_accesstoken_url);
            $model->setData('user_info_endpoint', $mo_oauth_getuserinfo_url);
            $model->setData('well_known_config_url', $mo_oauth_well_known_config_url);
            $model->setData('issuer', $mo_oauth_issuer);
            $model->setData('endsession_endpoint', $mo_oauth_endsession_url);
            $model->setData('values_in_header', (int) $send_header);
            $model->setData('values_in_body', (int) $send_body);
            if ($mo_oauth_client_secret !== '') {
                $model->setData('client_secret', $mo_oauth_client_secret);
            }
            $this->appResource->save($model);
            return;
        }

        // --- Legacy mode: single provider, delete all + recreate ---
        $mo_oauth_grant_type = OAuthConstants::GRANT_TYPE;

        $this->oauthUtility->getClientDetailsByAppName($mo_oauth_app_name);

        // Remove all previous records so at a time only 1 app_name is shown (free version)
        $this->oauthUtility->deleteAllRecords();

        // Store in custom table
        $this->oauthUtility->setOAuthClientApps(
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
            $mo_oauth_issuer,
            $mo_oauth_endsession_url
        );

        $this->oauthUtility->setStoreConfig(OAuthConstants::APP_NAME, $mo_oauth_app_name);
        $this->oauthUtility->setStoreConfig(OAuthConstants::SHOW_CUSTOMER_LINK, 1);

        $currentAdminUser = $this->oauthUtility->getCurrentAdminUser()->getData();
        $userEmail = $currentAdminUser['email'];

        $this->oauthUtility->setStoreConfig(OAuthConstants::ADMINEMAIL, $userEmail);
    }

    /**
     * Is the user allowed to view the Service Provider settings.
     * This is based on the ACL set by the admin in the backend.
     * Works in conjugation with acl.xml
     *
     * @return bool
     */
    #[\Override]
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_OAUTHSETTINGS);
    }
}
