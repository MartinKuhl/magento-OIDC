<?php

namespace M2Oidc\OAuth\Controller\Adminhtml\OAuthsettings;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthMessages;

use M2Oidc\OAuth\Controller\Actions\BaseAdminAction;
use M2Oidc\OAuth\Helper\Curl;
use M2Oidc\OAuth\Model\M2oidcOauthClientAppsFactory;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps as AppResource;

/**
 * OAuth Settings admin controller.
 *
 * Handles discovery by URL or manual endpoint entry and saves OAuth/OIDC
 * client configuration provided by the administrator.
 *
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 * @psalm-suppress DeprecatedInterface
 */
class Index extends BaseAdminAction implements HttpPostActionInterface, HttpGetActionInterface
{
    /** @var Curl */
    private readonly Curl $curl;

    /** @var M2oidcOauthClientAppsFactory */
    private readonly M2oidcOauthClientAppsFactory $clientAppsFactory;

    /** @var AppResource */
    private readonly AppResource $appResource;

    /**
     * Initialize OAuth settings controller.
     *
     * @param \Magento\Backend\App\Action\Context            $context
     * @param \Magento\Framework\View\Result\PageFactory     $resultPageFactory
     * @param \M2Oidc\OAuth\Helper\OAuthUtility              $oauthUtility
     * @param \Magento\Framework\Message\ManagerInterface    $messageManager
     * @param \Psr\Log\LoggerInterface                       $logger
     * @param Curl                                           $curl
     * @param M2oidcOauthClientAppsFactory                   $clientAppsFactory
     * @param AppResource                                    $appResource
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Psr\Log\LoggerInterface $logger,
        Curl $curl,
        M2oidcOauthClientAppsFactory $clientAppsFactory,
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
                $requiredBase = ['m2oidc_app_name' => $params, 'm2oidc_client_id' => $params,
                                 'm2oidc_scope' => $params];
                if (!$isProviderContext) {
                    $requiredBase['m2oidc_client_secret'] = $params;
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
                                    $m2oidc_authorize_url   = (string) $obj->authorization_endpoint;
                                    $m2oidc_accesstoken_url = (string) $obj->token_endpoint;
                                    $m2oidc_getuserinfo_url = isset($obj->userinfo_endpoint)
                                        ? (string) $obj->userinfo_endpoint : '';
                                    $m2oidc_issuer          = isset($obj->issuer)
                                        ? (string) $obj->issuer : '';

                                    // Store endpoint parameters for saving to database
                                    $params['m2oidc_authorize_url']    = trim($m2oidc_authorize_url);
                                    $params['m2oidc_accesstoken_url']  = trim($m2oidc_accesstoken_url);
                                    $params['m2oidc_getuserinfo_url']  = trim($m2oidc_getuserinfo_url);
                                    $params['m2oidc_issuer']           = trim($m2oidc_issuer);
                                    $params['m2oidc_endsession_url']      = isset($obj->end_session_endpoint)
                                        ? trim((string) $obj->end_session_endpoint) : '';
                                    $params['m2oidc_revocation_endpoint'] = isset($obj->revocation_endpoint)
                                        ? trim((string) $obj->revocation_endpoint) : '';

                                    $endpointRequired = array_merge(
                                        $requiredBase,
                                        ['m2oidc_authorize_url' => $params,
                                         'm2oidc_accesstoken_url' => $params]
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
                            ['m2oidc_authorize_url' => $params,
                             'm2oidc_accesstoken_url' => $params]
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
        $resultPage->getConfig()->getTitle()->prepend(__('M2Oidc OAuth'));
        return $resultPage;
    }

    /**
     * Process Values being submitted and save data in the database.
     *
     * Saves directly to the specific provider's row in m2oidc_oauth_client_apps
     * identified by provider_id in $params.
     *
     * @param array<string, mixed> $params
     */
    private function processValuesAndSaveData(array $params): void
    {
        $providerId = (int) ($params['provider_id'] ?? 0);

        $m2oidc_app_name            = trim((string) ($params['m2oidc_app_name'] ?? ''));
        $m2oidc_client_id           = trim((string) ($params['m2oidc_client_id'] ?? ''));
        $m2oidc_client_secret       = trim((string) ($params['m2oidc_client_secret'] ?? ''));
        $m2oidc_scope               = trim((string) ($params['m2oidc_scope'] ?? ''));
        $m2oidc_authorize_url       = trim((string) ($params['m2oidc_authorize_url'] ?? ''));
        $m2oidc_accesstoken_url     = trim((string) ($params['m2oidc_accesstoken_url'] ?? ''));
        $m2oidc_getuserinfo_url     = trim((string) ($params['m2oidc_getuserinfo_url'] ?? ''));
        $m2oidc_well_known_config_url = trim((string) ($params['endpoint_url'] ?? ''));
        $m2oidc_issuer              = trim((string) ($params['m2oidc_issuer'] ?? ''));
        $m2oidc_endsession_url      = trim((string) ($params['m2oidc_endsession_url'] ?? ''));
        $m2oidc_revocation_endpoint = trim((string) ($params['m2oidc_revocation_endpoint'] ?? ''));
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
            $model->setData('app_name', $m2oidc_app_name);
            $model->setData('clientID', $m2oidc_client_id);
            $model->setData('scope', $m2oidc_scope);
            $model->setData('authorize_endpoint', $m2oidc_authorize_url);
            $model->setData('access_token_endpoint', $m2oidc_accesstoken_url);
            $model->setData('user_info_endpoint', $m2oidc_getuserinfo_url);
            $model->setData('well_known_config_url', $m2oidc_well_known_config_url);
            $model->setData('issuer', $m2oidc_issuer);
            $model->setData('endsession_endpoint', $m2oidc_endsession_url);
            if ($m2oidc_revocation_endpoint !== '') {
                $model->setData('revocation_endpoint', $m2oidc_revocation_endpoint);
            }
            $model->setData('values_in_header', (int) $send_header);
            $model->setData('values_in_body', (int) $send_body);
            if ($m2oidc_client_secret !== '') {
                $model->setData('client_secret', $m2oidc_client_secret);
            }
            $this->appResource->save($model);
        }
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
