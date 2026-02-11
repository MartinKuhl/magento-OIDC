<?php

namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Helper\OAuth\AuthorizationRequest;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthSecurityHelper;
use MiniOrange\OAuth\Helper\SessionHelper;

/**
 * Handles generation and sending of AuthnRequest to the IDP
 * for authentication. AuthnRequest is generated and user is
 * redirected to the IDP for authentication.
 */
class SendAuthorizationRequest extends BaseAction
{
    private $sessionHelper;
    private $securityHelper;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        SessionHelper $sessionHelper,
        OAuthSecurityHelper $securityHelper
    ) {
        $this->sessionHelper = $sessionHelper;
        $this->securityHelper = $securityHelper;
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

        // Set relayState based on popup context, with redirect validation
        $rawRelayState = $isFromPopup
            ? $this->oauthUtility->getBaseUrl() . "checkout"
            : (isset($params['relayState']) ? $params['relayState'] : '/');
        $relayState = $this->securityHelper->validateRedirectUrl($rawRelayState, '/');

        // Speichere die aktuelle PHP-Session-ID
        $currentSessionId = session_id();
        $this->oauthUtility->customlog("SendAuthorizationRequest: Current session ID: " . $currentSessionId);

        // App-Name für die Authentifizierung ermitteln
        $app_name = isset($params['app_name']) ? $params['app_name'] : $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
        $this->oauthUtility->customlog("SendAuthorizationRequest: Using app_name: " . $app_name);

        // Combine relayState with session ID, app name, login type, and CSRF state token
        // Format: encodedRelayState|sessionId|encodedAppName|loginType|stateToken
        $stateToken = $this->securityHelper->createStateToken($currentSessionId);
        $relayState = urlencode($relayState) . '|' . $currentSessionId . '|' . urlencode($app_name) . '|' . OAuthConstants::LOGIN_TYPE_CUSTOMER . '|' . $stateToken;
        $this->oauthUtility->customlog("SendAuthorizationRequest: Combined relayState: " . $relayState);

        if (strpos($relayState, OAuthConstants::TEST_RELAYSTATE) !== false) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::IS_TEST, true);
            //$this->oauthUtility->flushCache(); // REMOVED for performance
        }

        $clientDetails = null;
        // App-Name wurde bereits oben bestimmt
        if (!$app_name) {
            $errorRedirect = $this->oauthUtility->getBaseUrl() . 'customer/account/login';
            $this->messageManager->addErrorMessage('App name not found. Please contact the administrator for assistance.');
            return $this->resultRedirectFactory->create()->setUrl($errorRedirect);
        }
        $this->oauthUtility->setSessionData(OAuthConstants::APP_NAME, $app_name);

        $clientDetails = $this->oauthUtility->getClientDetailsByAppName($app_name);
        if (empty($clientDetails)) {
            $errorRedirect = $this->oauthUtility->getBaseUrl() . 'customer/account/login';
            $this->messageManager->addErrorMessage('Provided App name is not configured. Please contact the administrator for assistance.');
            return $this->resultRedirectFactory->create()->setUrl($errorRedirect);
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
