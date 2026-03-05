<?php

namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Helper\OAuth\AuthorizationRequest;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthSecurityHelper;

/**
 * Handles generation and sending of AuthnRequest to the IDP
 * for authentication. AuthnRequest is generated and user is
 * redirected to the IDP for authentication.
 *
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 */
class SendAuthorizationRequest extends BaseAction
{
    /** @var \MiniOrange\OAuth\Helper\OAuthSecurityHelper */
    private readonly \MiniOrange\OAuth\Helper\OAuthSecurityHelper $securityHelper;

    /** @var \Magento\Framework\Session\SessionManagerInterface */
    private readonly \Magento\Framework\Session\SessionManagerInterface $sessionManager;

    /**
     * Initialize send authorization request action.
     *
     * @param \Magento\Framework\App\Action\Context              $context
     * @param \MiniOrange\OAuth\Helper\OAuthUtility              $oauthUtility
     * @param OAuthSecurityHelper                                $securityHelper
     * @param \Magento\Framework\Session\SessionManagerInterface $sessionManager
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

        if ((($Log_file_time != null && ($current_time - $Log_file_time) >= 60 * 60 * 24 * 7)
            && $islogEnable) || ($islogEnable == 0 && $log_file_exist)
        ) { //7days
            $this->oauthUtility->setStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG, 0);
            $chk_enable_log = 0;
            $this->oauthUtility->setStoreConfig(OAuthConstants::LOG_FILE_TIME, null);
            $this->oauthUtility->deleteCustomLogFile();
        }
        if ($chk_enable_log !== 0) {
            $this->oauthUtility->customlog("SendAuthorizationRequest: execute");
        }

        $params = $this->getRequest()->getParams();
        if ($chk_enable_log !== 0) {
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

        // Determine provider: prefer numeric provider_id, fall back to app_name lookup (MP-04)
        $providerId = isset($params['provider_id']) ? (int) $params['provider_id'] : 0;
        // MP-05: Set provider context — all getStoreConfig() calls resolve from correct provider row
        $this->oauthUtility->setActiveProviderId($providerId);

        // Determine app name for authentication
        $app_name = isset($params['app_name'])
            ? $params['app_name']
            : $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);

        // FIX: Resolve app_name from provider table when only provider_id is given
        // This prevents null being passed to encodeRelayState() which requires string
        $earlyProviderDetails = null;
        if (empty($app_name) && $providerId > 0) {
            $earlyProviderDetails = $this->oauthUtility->getClientDetailsById($providerId);
            $app_name = $earlyProviderDetails['app_name'] ?? '';
        }

        // Fail-safe: abort with user-friendly error if app_name is still unresolvable
        if (empty($app_name)) {
            $errorRedirect = $this->oauthUtility->getBaseUrl() . 'customer/account/login';
            $this->messageManager->addErrorMessage(
                __('App name not found. Please contact the administrator for assistance.')
            );
            return $this->resultRedirectFactory->create()->setUrl($errorRedirect);
        }

        $this->oauthUtility->customlog(
            "SendAuthorizationRequest: Using app_name: " . $app_name . " provider_id: " . $providerId
        );

        // Combine relayState with session ID, app name, login type, CSRF token, and provider ID
        $stateToken = $this->securityHelper->createStateToken($currentSessionId);
        $relayState = $this->securityHelper->encodeRelayState(
            $relayState,
            $currentSessionId,
            $app_name,
            isset($params['login_type']) ? $params['login_type'] : OAuthConstants::LOGIN_TYPE_CUSTOMER,
            $stateToken,
            $providerId > 0 ? $providerId : null
        );
        $this->oauthUtility->customlog("SendAuthorizationRequest: Combined relayState: " . $relayState);

        if (strpos($relayState, OAuthConstants::TEST_RELAYSTATE) !== false) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::IS_TEST, true);
        }

        $clientDetails = null;
        $this->oauthUtility->setSessionData(OAuthConstants::APP_NAME, $app_name);

        // MP-04: reuse early-loaded provider details to avoid duplicate DB call
        $clientDetails = $providerId > 0
            ? ($earlyProviderDetails ?? $this->oauthUtility->getClientDetailsById($providerId))
            : $this->oauthUtility->getClientDetailsByAppName($app_name);
        if ($clientDetails === null || $clientDetails === []) {
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
        $clientID     = $clientDetails["clientID"];
        $scope        = $clientDetails["scope"];
        $authorizeURL = $clientDetails["authorize_endpoint"];
        $responseType = OAuthConstants::CODE;
        $redirectURL  = $this->oauthUtility->getCallBackUrl();

        // PKCE (RFC 7636) — generate verifier when provider has S256 configured (FEAT-01)
        $codeChallenge = null;
        $pkceFlow      = $clientDetails['pkce_flow'] ?? '';
        if ($pkceFlow === OAuthConstants::PKCE_METHOD_S256) {
            $codeVerifier  = $this->securityHelper->generateCodeVerifier();
            $codeChallenge = $this->securityHelper->computeCodeChallenge($codeVerifier);
            // Persist verifier in provider row (survives admin→frontend session switch)
            $this->oauthUtility->saveProviderData(
                (int) $clientDetails['id'],
                ['pkce_code_verifier' => $codeVerifier]
            );

            $this->oauthUtility->customlog(
                "SendAuthorizationRequest: PKCE S256 enabled — challenge generated, verifier persisted to DB"
            );
        }

        //generate the authorization request
        $authorizationRequest = (new AuthorizationRequest(
            $clientID,
            $scope,
            $authorizeURL,
            $responseType,
            $redirectURL,
            $relayState,
            $params,
            $codeChallenge
        ))->build();
        if ($chk_enable_log !== 0) {
            $this->oauthUtility->customlog(
                "SendAuthorizationRequest:  Authorization Request: " . $authorizationRequest
            );
        }
        // send oauth request over
        return $this->sendHTTPRedirectRequest($authorizationRequest, $authorizeURL, $relayState, $params);
    }
}