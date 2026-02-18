<?php

namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Helper\OAuth\AuthorizationRequest;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthSecurityHelper;

/**
 * Handles generation and sending of AuthnRequest to the IDP
 * for authentication. AuthnRequest is generated and user is
 * redirected to the IDP for authentication.
 */
class SendAuthorizationRequest extends BaseAction
{
    private \MiniOrange\OAuth\Helper\OAuthSecurityHelper $securityHelper;

    private \Magento\Framework\Session\SessionManagerInterface $sessionManager;

    /**
     * Initialize send authorization request action.
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        OAuthSecurityHelper $securityHelper,
        \Magento\Framework\Session\SessionManagerInterface $sessionManager
    ) {
        $this->securityHelper = $securityHelper;
        $this->sessionManager = $sessionManager;
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Execute function to execute the classes function.
     *
     * @throws \Exception
     */
    public function execute()
    {
        // configureSSOSession() removed: it creates a host-only PHPSESSID cookie (no domain)
        // that conflicts with PHP's session cookie (domain=...). The duplicate cookie prevents
        // session_regenerate_id() from updating the browser's session ID after login.
        // SameSite=None is unnecessary — OAuth uses top-level navigation (SameSite=Lax suffices).

        $Log_file_time = $this->oauthUtility->getStoreConfig(OAuthConstants::LOG_FILE_TIME);
        $current_time = time();
        $chk_enable_log = 1;
        $islogEnable = $this->oauthUtility->getStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG);
        $log_file_exist = $this->oauthUtility->isCustomLogExist();

        if ((($Log_file_time != null && ($current_time - $Log_file_time) >= 60 * 60 * 24 * 7)
            && $islogEnable) || ($islogEnable == 0 && $log_file_exist)
        ) { //7days
            $this->oauthUtility->setStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG, 0);
            $chk_enable_log = 0;
            $this->oauthUtility->setStoreConfig(OAuthConstants::LOG_FILE_TIME, null);
            $this->oauthUtility->deleteCustomLogFile();
            //$this->oauthUtility->flushCache(); // REMOVED for performance
        }
        if ($chk_enable_log) {
            $this->oauthUtility->customlog("SendAuthorizationRequest: execute");
        }

        $params = $this->getRequest()->getParams();
        if ($chk_enable_log) {
            $this->oauthUtility->customlog(
                "SendAuthorizationRequest: Request prarms: " . implode(" ", $params)
            );
        }
        $isFromPopup = isset($params['from_popup']) && $params['from_popup'] == '1';

        // Set relayState based on popup context, with redirect validation
        $rawRelayState = $isFromPopup
            ? $this->oauthUtility->getBaseUrl() . "checkout"
            : (isset($params['relayState']) ? $params['relayState'] : '/');
        $relayState = $this->securityHelper->validateRedirectUrl($rawRelayState, '/');

        // Store the current PHP session ID
        $currentSessionId = $this->sessionManager->getSessionId();
        $this->oauthUtility->customlog("SendAuthorizationRequest: Current session ID: " . $currentSessionId);

        // Determine app name for authentication
        $app_name = isset($params['app_name'])
            ? $params['app_name']
            : $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
        $this->oauthUtility->customlog("SendAuthorizationRequest: Using app_name: " . $app_name);

        // Combine relayState with session ID, app name, login type, and CSRF state token
        $stateToken = $this->securityHelper->createStateToken($currentSessionId);
        $relayState = $this->securityHelper->encodeRelayState(
            $relayState,
            $currentSessionId,
            $app_name,
            OAuthConstants::LOGIN_TYPE_CUSTOMER,
            $stateToken
        );
        $this->oauthUtility->customlog("SendAuthorizationRequest: Combined relayState: " . $relayState);

        if (strpos($relayState, OAuthConstants::TEST_RELAYSTATE) !== false) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::IS_TEST, true);
            //$this->oauthUtility->flushCache(); // REMOVED for performance
        }

        $clientDetails = null;
        // App name was already determined above
        if (!$app_name) {
            $errorRedirect = $this->oauthUtility->getBaseUrl() . 'customer/account/login';
            $this->messageManager->addErrorMessage(
                'App name not found. Please contact the administrator for assistance.'
            );
            return $this->resultRedirectFactory->create()->setUrl($errorRedirect);
        }
        $this->oauthUtility->setSessionData(OAuthConstants::APP_NAME, $app_name);

        $clientDetails = $this->oauthUtility->getClientDetailsByAppName($app_name);
        if (empty($clientDetails)) {
            $errorRedirect = $this->oauthUtility->getBaseUrl() . 'customer/account/login';
            $this->messageManager->addErrorMessage(
                'Provided App name is not configured. Please contact the administrator for assistance.'
            );
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
        $authorizationRequest = (new AuthorizationRequest(
            $clientID,
            $scope,
            $authorizeURL,
            $responseType,
            $redirectURL,
            $relayState,
            $params
        ))->build();
        if ($chk_enable_log) {
            $this->oauthUtility->customlog(
                "SendAuthorizationRequest:  Authorization Request: " . $authorizationRequest
            );
        }
        // send oauth request over
        return $this->sendHTTPRedirectRequest($authorizationRequest, $authorizeURL, $relayState, $params);
    }
}
