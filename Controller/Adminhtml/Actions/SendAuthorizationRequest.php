<?php

namespace MiniOrange\OAuth\Controller\Adminhtml\Actions;

use MiniOrange\OAuth\Helper\OAuth\AuthorizationRequest;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthSecurityHelper;
use MiniOrange\OAuth\Helper\SessionHelper;
use Magento\Backend\App\Action;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Controller\Actions\BaseAction;

/**
 * Admin: Send authorization request to the configured OIDC provider.
 *
 * Builds an authorization URL and redirects the admin user to the
 * provider's authorization endpoint. Uses `AuthorizationRequest` helper
 * to construct the final URL.
 */
class SendAuthorizationRequest extends BaseAction
{
    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $urlBuilder;

    private readonly \MiniOrange\OAuth\Helper\OAuthSecurityHelper $securityHelper;

    private readonly \Magento\Framework\Session\SessionManagerInterface $sessionManager;

    /**
     * Initialize admin send authorization request action.
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        OAuthSecurityHelper $securityHelper,
        \Magento\Framework\Session\SessionManagerInterface $sessionManager
    ) {
        parent::__construct($context, $oauthUtility);
        $this->urlBuilder = $context->getUrl();
        $this->securityHelper = $securityHelper;
        $this->sessionManager = $sessionManager;
    }

    /**
     * Execute the admin authorization request.
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    #[\Override]
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

        if ((($Log_file_time != null && ($current_time - $Log_file_time) >= 60 * 60 * 24 * 7) && $islogEnable)
            || ($islogEnable == 0 && $log_file_exist)
        ) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG, 0);
            $chk_enable_log = 0;
            $this->oauthUtility->setStoreConfig(OAuthConstants::LOG_FILE_TIME, null);
            $this->oauthUtility->deleteCustomLogFile();
            //$this->oauthUtility->flushCache(); // REMOVED for performance
        }
        if ($chk_enable_log !== 0) {
            $this->oauthUtility->customlog("SendAuthorizationRequest: execute");
        }

        $params = $this->getRequest()->getParams();
        if ($chk_enable_log !== 0) {
            $this->oauthUtility->customlog(
                "SendAuthorizationRequest: Full params: " . var_export($params, true)
            );
        }

        $isFromPopup = isset($params['from_popup']) && $params['from_popup'] == '1';

        $app_name = isset($params['app_name'])
            ? $params['app_name']
            : $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
        $this->oauthUtility->customlog("SendAuthorizationRequest: Using app_name: " . $app_name);

        $currentSessionId = $this->sessionManager->getSessionId();
        $clientDetails = null;

        if (!$app_name) {
            $backendLoginUrl = $this->urlBuilder->getUrl('adminhtml/auth/login');
            $this->messageManager->addErrorMessage(
                'App name not found. Please contact the administrator for assistance.'
            );
            return $this->resultRedirectFactory->create()->setUrl($backendLoginUrl);
        }
        $this->oauthUtility->setSessionData(OAuthConstants::APP_NAME, $app_name);

        $clientDetails = $this->oauthUtility->getClientDetailsByAppName($app_name);
        if ($clientDetails === null || $clientDetails === []) {
            $backendLoginUrl = $this->urlBuilder->getUrl('adminhtml/auth/login');
            $msg = 'Provided App name is not configured. Please contact the administrator for assistance.';
            $this->messageManager->addErrorMessage($msg);
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
        $stateToken = $this->securityHelper->createStateToken($currentSessionId);
        $relayState = $this->securityHelper->encodeRelayState(
            $relayState,
            $currentSessionId,
            $app_name,
            OAuthConstants::LOGIN_TYPE_ADMIN,
            $stateToken
        );

        $isTest = (
            ($this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST) == true)
            || (isset($params['option']) && $params['option'] === OAuthConstants::TEST_CONFIG_OPT)
        );

        // Test flow: rebuild combined state with test results URL as the relayState segment
        if ($isTest) {
            $testKey = bin2hex(random_bytes(16));
            $baseUrl = $this->oauthUtility->getBaseUrl();
            $testRelayState = $baseUrl . 'mooauth/actions/showTestResults/key/' . $testKey . '/';
            $this->oauthUtility->customlog(
                "SendAuthorizationRequest: Test-Flow, setting relayState to: " . $testRelayState
            );
            // Rebuild combined state preserving sessionId, appName, loginType, stateToken
            $relayState = $this->securityHelper->encodeRelayState(
                $testRelayState,
                $currentSessionId,
                $app_name,
                OAuthConstants::LOGIN_TYPE_ADMIN,
                $stateToken
            );
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

        if ($chk_enable_log !== 0) {
            $this->oauthUtility->customlog(
                "SendAuthorizationRequest:  Authorization Request: " . $authorizationRequest
            );
        }

        return $this->sendHTTPRedirectRequest($authorizationRequest, $authorizeURL, $relayState, $params);
    }
}
