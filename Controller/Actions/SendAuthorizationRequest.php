<?php

namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Helper\OAuth\AuthorizationRequest;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\SessionHelper;

/**
 * Handles generation and sending of AuthnRequest to the IDP
 * for authentication. AuthnRequest is generated and user is
 * redirected to the IDP for authentication.
 */
class SendAuthorizationRequest extends BaseAction
{
    private $sessionHelper;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        SessionHelper $sessionHelper
    ) {
        $this->sessionHelper = $sessionHelper;
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Execute function to execute the classes function.
     * @throws \Exception
     */
    public function execute()
    {
        // Konfigurieren der Session für SSO mit SameSite=None
        $this->sessionHelper->configureSSOSession();

        $Log_file_time = $this->oauthUtility->getStoreConfig(OAuthConstants::LOG_FILE_TIME);
        $current_time = time();
        $chk_enable_log = 1;
        $islogEnable = $this->oauthUtility->getStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG);
        $log_file_exist = $this->oauthUtility->isCustomLogExist();

        if ((($Log_file_time != NULL && ($current_time - $Log_file_time) >= 60 * 60 * 24 * 7) && $islogEnable) || ($islogEnable == 0 && $log_file_exist)) //7days
        {
            $this->oauthUtility->setStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG, 0);
            $chk_enable_log = 0;
            $this->oauthUtility->setStoreConfig(OAuthConstants::LOG_FILE_TIME, NULL);
            $this->oauthUtility->deleteCustomLogFile();
            //$this->oauthUtility->flushCache(); // REMOVED for performance
        }
        $chk_enable_log ? $this->oauthUtility->customlog("SendAuthorizationRequest: execute") : NULL;

        $params = $this->getRequest()->getParams();  //get params
        $chk_enable_log ? $this->oauthUtility->customlog("SendAuthorizationRequest: Request prarms: " . implode(" ", $params)) : NULL;
        $isFromPopup = isset($params['from_popup']) && $params['from_popup'] == '1';

        // Set relayState based on popup context
        $relayState = $isFromPopup
            ? $this->oauthUtility->getBaseUrl() . "checkout"  // Checkout page for popup
            : (isset($params['relayState']) ? $params['relayState'] : '/');  // Original relayState or default

        // Speichere die aktuelle PHP-Session-ID
        $currentSessionId = session_id();
        $this->oauthUtility->customlog("SendAuthorizationRequest: Current session ID: " . $currentSessionId);

        // App-Name für die Authentifizierung ermitteln
        $app_name = isset($params['app_name']) ? $params['app_name'] : $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
        $this->oauthUtility->customlog("SendAuthorizationRequest: Using app_name: " . $app_name);

        // Kombiniere den relayState mit der Session-ID, App-Namen und Login-Typ in einem kodierten Format
        // Format: encodedRelayState|sessionId|encodedAppName|loginType
        // URL-Encode components to prevent collisions with the pipe delimiter
        $relayState = urlencode($relayState) . '|' . $currentSessionId . '|' . urlencode($app_name) . '|' . OAuthConstants::LOGIN_TYPE_CUSTOMER;
        $this->oauthUtility->customlog("SendAuthorizationRequest: Combined relayState: " . $relayState);

        if (strpos($relayState, OAuthConstants::TEST_RELAYSTATE) !== false) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::IS_TEST, true);
            //$this->oauthUtility->flushCache(); // REMOVED for performance
        }

        $clientDetails = null;
        // App-Name wurde bereits oben bestimmt
        if (!$app_name) {

            if (isset($params['relayState'])) {
                $relayState = $params['relayState'];
            } else {
                $relayState = $this->oauthUtility->getBaseUrl() . 'customer/account/login';
            }
            $this->messageManager->addErrorMessage('App name not found. Please contact the administrator for assistance.');
            return $this->resultRedirectFactory->create()->setUrl($relayState);

        }
        $this->oauthUtility->setSessionData(OAuthConstants::APP_NAME, $app_name);

        $collection = $this->oauthUtility->getOAuthClientApps();
        //$this->oauthUtility->log_debug("SendAuthorizationRequest: collection :", count($collection));
        foreach ($collection as $item) {
            if ($item->getData()["app_name"] === $app_name) {
                $clientDetails = $item->getData();
            }
        }
        if (empty($clientDetails)) {
            if (isset($params['relayState'])) {
                $relayState = $params['relayState'];
            } else {
                $relayState = $this->oauthUtility->getBaseUrl() . 'customer/account/login';
            }
            $this->messageManager->addErrorMessage('Provided App name is not configured. Please contact the administrator for assistance.');

            return $this->resultRedirectFactory->create()->setUrl($relayState);
        }
        if (!$clientDetails["authorize_endpoint"]) {
            $this->messageManager->addErrorMessage(
                __('Authorization endpoint is not configured. Please contact the administrator.')
            );
            return $this->resultRedirectFactory->create()->setUrl(
                $this->oauthUtility->getBaseUrl() . 'customer/account/login'
            );
        }


        //get required values from the database
        $clientID = $clientDetails["clientID"];
        $scope = $clientDetails["scope"];
        $authorizeURL = $clientDetails["authorize_endpoint"];
        $responseType = OAuthConstants::CODE;
        $redirectURL = $this->oauthUtility->getCallBackUrl();

        //generate the authorization request
        $authorizationRequest = (new AuthorizationRequest($clientID, $scope, $authorizeURL, $responseType, $redirectURL, $relayState, $params))->build();
        $chk_enable_log ? $this->oauthUtility->customlog("SendAuthorizationRequest:  Authorization Request: " . $authorizationRequest) : NULL;
        // send oauth request over
        return $this->sendHTTPRedirectRequest($authorizationRequest, $authorizeURL, $relayState, $params);
    }
}
