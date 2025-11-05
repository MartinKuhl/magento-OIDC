<?php

namespace MiniOrange\OAuth\Controller\Adminhtml\Actions;

use MiniOrange\OAuth\Helper\OAuth\AuthorizationRequest;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\SessionHelper;
use Magento\Backend\App\Action;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Controller\Actions\BaseAction;

/**
 * Handles generation and sending of AuthnRequest to the IDP
 * for authentication. AuthnRequest is generated and user is
 * redirected to the IDP for authentication.
 */
class SendAuthorizationRequest extends BaseAction
{
    protected $oauthUtility;

    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility
        // ggf. weitere DI-Objekte
    ) {
        parent::__construct($context, $oauthUtility /* , ...weitere Argumente */);
    }

    /**
     * Execute function to execute the classes function.
     * @throws \Exception
     */
    public function execute()
    {
        // Konfigurieren der Session für SSO mit SameSite=None
        SessionHelper::configureSSOSession();
        SessionHelper::updateSessionCookies();

        $Log_file_time = $this->oauthUtility->getStoreConfig(OAuthConstants::LOG_FILE_TIME);
        $current_time = time();
        $chk_enable_log = 1;
        $islogEnable = $this->oauthUtility->getStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG);
        $log_file_exist = $this->oauthUtility->isCustomLogExist();

        if ((($Log_file_time != NULL && ($current_time - $Log_file_time) >= 60*60*24*7) && $islogEnable) || ($islogEnable == 0 && $log_file_exist)) { //7days
            $this->oauthUtility->setStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG, 0);
            $chk_enable_log = 0;
            $this->oauthUtility->setStoreConfig(OAuthConstants::LOG_FILE_TIME, NULL);
            $this->oauthUtility->deleteCustomLogFile();
            $this->oauthUtility->flushCache();
        }
        $chk_enable_log ? $this->oauthUtility->customlog("SendAuthorizationRequest: execute") : NULL;

        $params = $this->getRequest()->getParams();  //get params
        $chk_enable_log ? $this->oauthUtility->customlog("SendAuthorizationRequest: Request prarms: ".implode(" ", $params)) : NULL;
        $isFromPopup = isset($params['from_popup']) && $params['from_popup'] == '1';

        // App-Name für die Authentifizierung ermitteln
        $app_name = isset($params['app_name']) ? $params['app_name'] : $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
        $this->oauthUtility->customlog("SendAuthorizationRequest: Using app_name: " . $app_name);

        $currentSessionId = session_id();
        $clientDetails = null;

        // App-Name wurde bereits oben bestimmt
        if (!$app_name) {
            $relayState = isset($params['relayState']) ? $params['relayState'] : $this->oauthUtility->getBaseUrl() . 'customer/account/login';
            $this->messageManager->addErrorMessage('App name not found. Please contact the administrator for assistance.');
            return $this->getResponse()->setRedirect($relayState)->sendResponse();
        }
        $this->oauthUtility->setSessionData(OAuthConstants::APP_NAME, $app_name);

        $collection = $this->oauthUtility->getOAuthClientApps();
        $this->oauthUtility->log_debug("SendAuthorizationRequest: collection :", count($collection));
        foreach ($collection as $item) {
            if ($item->getData()["app_name"] === $app_name) {
                $clientDetails = $item->getData();
            }
        }
        if (empty($clientDetails)) {
            $relayState = isset($params['relayState']) ? $params['relayState'] : $this->oauthUtility->getBaseUrl() . 'customer/account/login';
            $this->messageManager->addErrorMessage('Provided App name is not configured. Please contact the administrator for assistance.');
            return $this->getResponse()->setRedirect($relayState)->sendResponse();
        }
        if (!$clientDetails["authorize_endpoint"]) {
            return;
        }

        //get required values from the database
        $clientID = $clientDetails["clientID"];
        $scope = $clientDetails["scope"];
        $authorizeURL = $clientDetails["authorize_endpoint"];
        $responseType = OAuthConstants::CODE;
        $redirectURL = $this->oauthUtility->getCallBackUrl();

        // *** EINZIGE relayState-Zuweisung außerhalb des Testfalls ***
        $relayState = $isFromPopup
            ? $this->oauthUtility->getBaseUrl() . "checkout"
            : (isset($params['relayState']) ? $params['relayState'] : '/');
        $relayState = $relayState . '|' . $currentSessionId . '|' . $app_name;

        $isTest = (
            ($this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST) == true)
            || (isset($params['option']) && $params['option'] === OAuthConstants::TEST_CONFIG_OPT)
        );

        // *** TESTFALL überschreibt relayState exklusiv ***
        if ($isTest) {
            $testKey = bin2hex(random_bytes(16));
            $relayState = $this->getUrl(
                'mooauth/actions/showTestResults',
                ['key' => $testKey]
            );
            $this->oauthUtility->customlog("SendAuthorizationRequest: Test-Flow, setting relayState to: " . $relayState);
        }

        $this->oauthUtility->customlog("Test relayState FINAL: " . $relayState);

        //generate the authorization request
        $authorizationRequest = (new AuthorizationRequest($clientID, $scope, $authorizeURL, $responseType, $redirectURL, $relayState, $params))->build();
        $chk_enable_log ? $this->oauthUtility->customlog("SendAuthorizationRequest:  Authorization Request: ".$authorizationRequest) : NULL;

        // send oauth request over
        return $this->sendHTTPRedirectRequest($authorizationRequest, $authorizeURL, $relayState, $params);
    }
}
