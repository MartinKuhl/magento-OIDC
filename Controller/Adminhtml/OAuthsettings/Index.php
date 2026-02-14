<?php

namespace MiniOrange\OAuth\Controller\Adminhtml\OAuthsettings;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthMessages;

use MiniOrange\OAuth\Controller\Actions\BaseAdminAction;
use MiniOrange\OAuth\Helper\Curl;

/**
 * OAuth Settings admin controller.
 *
 * Handles discovery by URL or manual endpoint entry and saves OAuth/OIDC
 * client configuration provided by the administrator.
 */
class Index extends BaseAdminAction implements HttpPostActionInterface, HttpGetActionInterface
{
    /** @var Curl */
    private Curl $curl;

    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Psr\Log\LoggerInterface $logger,
        Curl $curl
    ) {
        $this->curl = $curl;
        parent::__construct($context, $resultPageFactory, $oauthUtility, $messageManager, $logger);
    }

    /**
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        try {
            $params = $this->getRequest()->getParams(); //get params

            // check if form options are being saved
            if ($this->isFormOptionBeingSaved($params) && isset($params['endpoint_radio_button'])) {
                //Store radio button value in $radiostate parameter.
                $radiostate = $params['endpoint_radio_button'];
                //check whether URL radio button is checked or manual radio button is checked.

                if ($radiostate == 'byurl') {


                    $url = $params['endpoint_url'];

                    if ($url != null) {

                        //get URL content
                        $url = filter_var($url, FILTER_SANITIZE_URL);

                        // Use injected Curl helper to fetch discovery document
                        $file = $this->curl->sendUserInfoRequest($url, []);

                        $obj = json_decode($file);
                        $this->checkIfRequiredFieldsEmpty([
                            'mo_oauth_app_name' => $params,
                            'mo_oauth_client_id' => $params,
                            'mo_oauth_client_secret' => $params,
                            'mo_oauth_scope' => $params
                        ]);
                        //check if url has any information or not.
                        if ($obj != null) { /**
                              * Fetch endpoints from data obtained from URL
                              */

                            $mo_oauth_authorize_url = $obj->authorization_endpoint; //authorization_endpoint

                            $mo_oauth_accesstoken_url = $obj->token_endpoint;  //token_endpoint

                            $mo_oauth_getuserinfo_url = isset($obj->userinfo_endpoint) ? $obj->userinfo_endpoint : '';
                            $mo_oauth_issuer = isset($obj->issuer) ? $obj->issuer : '';

                            // Store endpoint parameters for saving to database
                            $params['mo_oauth_authorize_url'] = trim($mo_oauth_authorize_url);
                            $params['mo_oauth_accesstoken_url'] = trim($mo_oauth_accesstoken_url);
                            $params['mo_oauth_getuserinfo_url'] = trim($mo_oauth_getuserinfo_url);
                            $params['mo_oauth_issuer'] = trim($mo_oauth_issuer);

                            $this->checkIfRequiredFieldsEmpty([
                                'mo_oauth_app_name' => $params,
                                'mo_oauth_client_id' => $params,
                                'mo_oauth_client_secret' => $params,
                                'mo_oauth_scope' => $params,
                                'mo_oauth_authorize_url' => $params,
                                'mo_oauth_accesstoken_url' => $params,
                            ]);
                            $this->processValuesAndSaveData($params);
                            $this->oauthUtility->flushCache();
                            $this->messageManager->addSuccessMessage(OAuthMessages::SETTINGS_SAVED);
                            $this->oauthUtility->reinitConfig();

                        } else {
                            $this->messageManager->addErrorMessage('Please Enter Valid URL');
                            $this->oauthUtility->customlog('URL do not have any information.Please enter valid url');

                        }


                    } else {

                        $this->messageManager->addErrorMessage('Please Enter URL');
                        $this->oauthUtility->customlog('URL is empty.Please enter valid  url');
                    }



                } else {


                    if ($radiostate == 'bymanual') {

                        $this->checkIfRequiredFieldsEmpty([
                            'mo_oauth_app_name' => $params,
                            'mo_oauth_client_id' => $params,
                            'mo_oauth_client_secret' => $params,
                            'mo_oauth_scope' => $params,
                            'mo_oauth_authorize_url' => $params,
                            'mo_oauth_accesstoken_url' => $params,
                        ]);
                        $this->processValuesAndSaveData($params);
                        $this->oauthUtility->flushCache();
                        $this->messageManager->addSuccessMessage(OAuthMessages::SETTINGS_SAVED);
                        $this->oauthUtility->reinitConfig();
                    } else {
                        $this->messageManager->addErrorMessage('Please Select Required OAuth Endpoints option');
                        $this->oauthUtility->customlog('Error in Controller->Adminhtml->OAuthsettings->index file...Please Select Required OAuth Endpoints option');
                    }


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
     * @param array $params
     * @return void
     */
    private function processValuesAndSaveData(array $params)
    {
        $mo_oauth_app_name = trim($params['mo_oauth_app_name']);
        $mo_oauth_client_id = trim($params['mo_oauth_client_id']);
        $mo_oauth_client_secret = trim($params['mo_oauth_client_secret']);
        $mo_oauth_scope = trim($params['mo_oauth_scope']);
        $mo_oauth_authorize_url = trim($params['mo_oauth_authorize_url']);
        $mo_oauth_accesstoken_url = trim($params['mo_oauth_accesstoken_url']);
        $mo_oauth_getuserinfo_url = trim($params['mo_oauth_getuserinfo_url']);
        $mo_oauth_well_known_config_url = isset($params['endpoint_url']) ? trim($params['endpoint_url']) : '';
        $mo_oauth_issuer = isset($params['mo_oauth_issuer']) ? trim($params['mo_oauth_issuer']) : '';
        $mo_oauth_grant_type = OAuthConstants::GRANT_TYPE;
        $send_header = isset($params['send_header']) ? 1 : 0;
        $send_body = isset($params['send_body']) ? 1 : 0;

        $clientDetails = $this->oauthUtility->getClientDetailsByAppName($mo_oauth_app_name);

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
            $mo_oauth_issuer
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
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_OAUTHSETTINGS);
    }
}
