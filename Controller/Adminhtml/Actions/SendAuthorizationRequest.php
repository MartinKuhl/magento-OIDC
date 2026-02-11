<?php

namespace MiniOrange\OAuth\Controller\Adminhtml\Actions;

use MiniOrange\OAuth\Helper\OAuth\AuthorizationRequest;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthSecurityHelper;
use MiniOrange\OAuth\Helper\SessionHelper;
use Magento\Backend\App\Action;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Controller\Actions\BaseAction;

class SendAuthorizationRequest extends BaseAction
{
    protected $oauthUtility;
    protected $urlBuilder;
    private $sessionHelper;
    private $securityHelper;

    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        SessionHelper $sessionHelper,
        OAuthSecurityHelper $securityHelper
    ) {
        parent::__construct($context, $oauthUtility);
        $this->urlBuilder = $context->getUrl();
        $this->sessionHelper = $sessionHelper;
        $this->securityHelper = $securityHelper;
    }

    public function execute()
    {
        $this->sessionHelper->configureSSOSession();

        $Log_file_time = $this->oauthUtility->getStoreConfig(OAuthConstants::LOG_FILE_TIME);
        $current_time = time();
        $chk_enable_log = 1;
        $islogEnable = $this->oauthUtility->getStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG);
        $log_file_exist = $this->oauthUtility->isCustomLogExist();

        if ((($Log_file_time != NULL && ($current_time - $Log_file_time) >= 60 * 60 * 24 * 7) && $islogEnable) || ($islogEnable == 0 && $log_file_exist)) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG, 0);
            $chk_enable_log = 0;
            $this->oauthUtility->setStoreConfig(OAuthConstants::LOG_FILE_TIME, NULL);
            $this->oauthUtility->deleteCustomLogFile();
            $this->oauthUtility->flushCache();
        }
        $chk_enable_log ? $this->oauthUtility->customlog("SendAuthorizationRequest: execute") : NULL;

        $params = $this->getRequest()->getParams();
        $chk_enable_log ? $this->oauthUtility->customlog("SendAuthorizationRequest: Full params: " . print_r($params, true)) : NULL;

        $isFromPopup = isset($params['from_popup']) && $params['from_popup'] == '1';

        $app_name = isset($params['app_name']) ? $params['app_name'] : $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
        $this->oauthUtility->customlog("SendAuthorizationRequest: Using app_name: " . $app_name);

        $currentSessionId = session_id();
        $clientDetails = null;

        if (!$app_name) {
            $backendLoginUrl = $this->urlBuilder->getUrl('adminhtml/auth/login');
            $this->messageManager->addErrorMessage('App name not found. Please contact the administrator for assistance.');
            return $this->resultRedirectFactory->create()->setUrl($backendLoginUrl);
        }
        $this->oauthUtility->setSessionData(OAuthConstants::APP_NAME, $app_name);

        $clientDetails = $this->oauthUtility->getClientDetailsByAppName($app_name);
        if (empty($clientDetails)) {
            $backendLoginUrl = $this->urlBuilder->getUrl('adminhtml/auth/login');
            $this->messageManager->addErrorMessage('Provided App name is not configured. Please contact the administrator for assistance.');
            return $this->resultRedirectFactory->create()->setUrl($backendLoginUrl);
        }
        if (!$clientDetails["authorize_endpoint"]) {
            $this->messageManager->addErrorMessage(
                __('Authorization endpoint is not configured. Please contact the administrator.')
            );
            $backendUrl = $this->urlBuilder->getUrl('adminhtml/auth/login');
            return $this->resultRedirectFactory->create()->setUrl($backendUrl);
        }

        $clientID = $clientDetails["clientID"];
        $scope = $clientDetails["scope"];
        $authorizeURL = $clientDetails["authorize_endpoint"];
        $responseType = OAuthConstants::CODE;
        $redirectURL = $this->oauthUtility->getCallBackUrl();

        // Build relayState with login type for admin, with redirect validation
        $rawRelayState = $isFromPopup
            ? $this->oauthUtility->getBaseUrl() . "checkout"
            : (isset($params['relayState']) ? $params['relayState'] : '/');
        $relayState = $this->securityHelper->validateRedirectUrl($rawRelayState, '/');
        // Format: encodedRelayState|sessionId|encodedAppName|loginType|stateToken
        $stateToken = $this->securityHelper->createStateToken($currentSessionId);
        $relayState = urlencode($relayState) . '|' . $currentSessionId . '|' . urlencode($app_name) . '|' . OAuthConstants::LOGIN_TYPE_ADMIN . '|' . $stateToken;

        $isTest = (
            ($this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST) == true)
            || (isset($params['option']) && $params['option'] === OAuthConstants::TEST_CONFIG_OPT)
        );

        // Testfall: relayState auf Testergebnis überschreiben
        if ($isTest) {
            $testKey = bin2hex(random_bytes(16));
            $baseUrl = $this->oauthUtility->getBaseUrl();
            $relayState = $baseUrl . 'mooauth/actions/showTestResults/key/' . $testKey . '/';
            $this->oauthUtility->customlog("SendAuthorizationRequest: Test-Flow, setting relayState to: " . $relayState);
        }

        $this->oauthUtility->customlog("Test relayState FINAL: " . $relayState);

        $authorizationRequest = (new AuthorizationRequest(
            $clientID,
            $scope,
            $authorizeURL,
            $responseType,
            $redirectURL,
            $relayState,
            $params
        ))->build();

        $chk_enable_log ? $this->oauthUtility->customlog(
            "SendAuthorizationRequest:  Authorization Request: " . $authorizationRequest
        ) : NULL;

        return $this->sendHTTPRedirectRequest($authorizationRequest, $authorizeURL, $relayState, $params);
    }
}
