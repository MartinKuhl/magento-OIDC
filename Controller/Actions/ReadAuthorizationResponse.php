<?php

namespace MiniOrange\OAuth\Controller\Actions;

use Exception;
use Magento\Framework\App\Action\Context;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuth\AccessTokenRequest;
use MiniOrange\OAuth\Helper\OAuth\AccessTokenRequestBody;
use MiniOrange\OAuth\Helper\Curl;
use MiniOrange\OAuth\Helper\JwtVerifier;
use MiniOrange\OAuth\Helper\OAuthSecurityHelper;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Helper\SessionHelper;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;

// TODO(A2): This controller chains into ProcessResponseAction and CheckAttributeMappingAction
// via setter injection and direct ->execute() calls. Consider refactoring to use Magento's
// service layer (Service Contracts / Repositories) instead of controller-to-controller chaining.
class ReadAuthorizationResponse extends BaseAction
{
    private $REQUEST;
    private $POST;
    private $processResponseAction;
    protected $_url;
    private $customerSession;
    private $sessionHelper;
    private $securityHelper;
    private $jwtVerifier;

    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        ProcessResponseAction $processResponseAction,
        \Magento\Framework\UrlInterface $url,
        \Magento\Customer\Model\Session $customerSession,
        SessionHelper $sessionHelper,
        OAuthSecurityHelper $securityHelper,
        JwtVerifier $jwtVerifier
    ) {
        $this->processResponseAction = $processResponseAction;
        $this->_url = $url;
        $this->customerSession = $customerSession;
        $this->sessionHelper = $sessionHelper;
        $this->securityHelper = $securityHelper;
        $this->jwtVerifier = $jwtVerifier;
        parent::__construct($context, $oauthUtility);
    }


    public function execute()
    {
        // configureSSOSession() removed from callback handler.
        // SameSite=None is only needed in SendAuthorizationRequest (outbound redirect to IdP).
        // Calling it here sets a stale Secure cookie that conflicts with session_regenerate_id()
        // inside setCustomerAsLoggedIn(), causing the browser to keep the old destroyed session ID.

        $params = $this->getRequest()->getParams();

        // ... Vorbereitende Logik & Logging wie gehabt ...

        if (!isset($params['code'])) {
            // Parse loginType from state even on error (state is still passed by OAuth provider)
            $loginType = OAuthConstants::LOGIN_TYPE_CUSTOMER; // default to customer
            if (isset($params['state'])) {
                $parts = explode('|', $params['state']);
                $loginType = isset($parts[3]) ? $parts[3] : OAuthConstants::LOGIN_TYPE_CUSTOMER;
            }

            if (isset($params['error'])) {
                $errorMsg = $params['error_description'] ?? $params['error'];
                $encodedError = base64_encode($errorMsg);

                // Check if this is a test flow by examining the relayState
                $relayState = '';
                if (isset($params['state'])) {
                    $parts = explode('|', $params['state']);
                    // Decode relayState as it was encoded to avoid delimiter collision
                    $relayState = isset($parts[0]) ? urldecode($parts[0]) : '';
                }

                $isTest = (
                    ($this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST) == true)
                    || (strpos($relayState, 'showTestResults') !== false)
                );

                if ($isTest && strpos($relayState, 'showTestResults') !== false) {
                    // Test mode: redirect to showTestResults with error
                    $errorUrl = $relayState . (strpos($relayState, '?') !== false ? '&' : '?') . 'oidc_error=' . $encodedError;
                    return $this->_redirect($errorUrl);
                }

                if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) {
                    // Admin: redirect to admin login page
                    $loginUrl = $this->_url->getUrl('admin', ['_query' => ['oidc_error' => $encodedError]]);
                } else {
                    // Customer: redirect to customer login page
                    $loginUrl = $this->_url->getUrl('customer/account/login', ['_query' => ['oidc_error' => $encodedError]]);
                }
                return $this->_redirect($loginUrl);
            }
        }

        $authorizationCode = $params['code'];
        $combinedRelayState = $params['state'];
        $parts = explode('|', $combinedRelayState);
        // Decode relayState and app_name as they were encoded to avoid delimiter collision
        $relayState = urldecode($parts[0]);

        $originalSessionId = isset($parts[1]) ? $parts[1] : '';
        $app_name = isset($parts[2]) ? urldecode($parts[2]) : '';
        // Parse loginType from relayState (defaults to customer for backward compatibility)
        $loginType = isset($parts[3]) ? $parts[3] : OAuthConstants::LOGIN_TYPE_CUSTOMER;

        // Validate CSRF state token (5th segment)
        $stateToken = isset($parts[4]) ? $parts[4] : '';
        if (empty($stateToken) || !$this->securityHelper->validateStateToken($originalSessionId, $stateToken)) {
            $this->oauthUtility->customlog("ERROR: State token validation failed (CSRF protection)");
            $encodedError = base64_encode('Security validation failed. Please try logging in again.');
            if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) {
                $loginUrl = $this->_url->getUrl('admin', ['_query' => ['oidc_error' => $encodedError]]);
            } else {
                $loginUrl = $this->_url->getUrl('customer/account/login', ['_query' => ['oidc_error' => $encodedError]]);
            }
            return $this->_redirect($loginUrl);
        }

        // Look up OAuth client details by app name
        $clientDetails = $this->oauthUtility->getClientDetailsByAppName($app_name);
        if (!$clientDetails) {
            // Fallback: use the first configured app
            $collection = $this->oauthUtility->getOAuthClientApps();
            if ($collection && count($collection) > 0) {
                $clientDetails = $collection->getFirstItem()->getData();
                $app_name = $clientDetails["app_name"];
                $this->oauthUtility->setSessionData(OAuthConstants::APP_NAME, $app_name);
            }
            if (!$clientDetails) {
                $this->oauthUtility->customlog("ERROR: Invalid OAuth app configuration");
                $encodedError = base64_encode('Invalid OAuth app configuration. Please contact the administrator.');
                if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) {
                    $loginUrl = $this->_url->getUrl('admin', ['_query' => ['oidc_error' => $encodedError]]);
                } else {
                    $loginUrl = $this->_url->getUrl('customer/account/login', ['_query' => ['oidc_error' => $encodedError]]);
                }
                return $this->_redirect($loginUrl);
            }
        }

        // Token-Request bauen
        $clientID = $clientDetails["clientID"];
        $clientSecret = $clientDetails["client_secret"];
        $accessTokenURL = $clientDetails["access_token_endpoint"];
        $header = $clientDetails["values_in_header"];
        $body = $clientDetails["values_in_body"];
        $redirectURL = $this->oauthUtility->getCallBackUrl();
        $grantType = isset($clientDetails['grant_type']) ? $clientDetails['grant_type'] : 'authorization_code';

        if ($header == 1 && $body == 0) {
            $accessTokenRequest = (new AccessTokenRequestBody($grantType, $redirectURL, $authorizationCode))->build();
        } else {
            $accessTokenRequest = (new AccessTokenRequest($clientID, $clientSecret, $grantType, $redirectURL, $authorizationCode))->build();
        }

        $accessTokenResponse = Curl::mo_send_access_token_request($accessTokenRequest, $accessTokenURL, $clientID, $clientSecret, $header, $body);

        $accessTokenResponseData = json_decode($accessTokenResponse, true);

        // Userinfo holen
        $userInfoURL = $clientDetails['user_info_endpoint'];
        if (!($userInfoURL == NULL || $userInfoURL == '') && isset($accessTokenResponseData['access_token'])) {
            $accessToken = $accessTokenResponseData['access_token'];
            $headerAuth = "Bearer " . $accessToken;
            $authHeader = ["Authorization: $headerAuth"];
            $userInfoResponse = Curl::mo_send_user_info_request($userInfoURL, $authHeader);
            $userInfoResponseData = json_decode($userInfoResponse, true);
        } elseif (isset($accessTokenResponseData['id_token'])) {
            $idToken = $accessTokenResponseData['id_token'];
            if (!empty($idToken)) {
                $jwksEndpoint = $clientDetails['jwks_endpoint'] ?? '';
                if (!empty($jwksEndpoint)) {
                    $userInfoResponseData = $this->jwtVerifier->verifyAndDecode($idToken, $jwksEndpoint, null, $clientID);
                    if ($userInfoResponseData === null) {
                        $this->oauthUtility->customlog("ERROR: JWT signature verification failed");
                        $encodedError = base64_encode('ID token verification failed. Please contact the administrator.');
                        if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) {
                            $loginUrl = $this->_url->getUrl('admin', ['_query' => ['oidc_error' => $encodedError]]);
                        } else {
                            $loginUrl = $this->_url->getUrl('customer/account/login', ['_query' => ['oidc_error' => $encodedError]]);
                        }
                        return $this->_redirect($loginUrl);
                    }
                } else {
                    // Fallback: no JWKS configured — decode without verification (backward compatible)
                    $this->oauthUtility->customlog("WARNING: No JWKS endpoint configured, decoding id_token without signature verification");
                    $userInfoResponseData = $this->jwtVerifier->decodeWithoutVerification($idToken);
                }
            }
        } else {
            $this->oauthUtility->customlog("ERROR: Invalid token response - no access_token or id_token");
            $encodedError = base64_encode('Invalid response from OAuth provider. Please try again.');
            if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) {
                $loginUrl = $this->_url->getUrl('admin', ['_query' => ['oidc_error' => $encodedError]]);
            } else {
                $loginUrl = $this->_url->getUrl('customer/account/login', ['_query' => ['oidc_error' => $encodedError]]);
            }
            return $this->_redirect($loginUrl);
        }

        if (empty($userInfoResponseData)) {
            $this->oauthUtility->customlog("ERROR: Empty user info response data");
            $encodedError = base64_encode('Invalid response from OAuth provider. Please try again.');
            if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) {
                $loginUrl = $this->_url->getUrl('admin', ['_query' => ['oidc_error' => $encodedError]]);
            } else {
                $loginUrl = $this->_url->getUrl('customer/account/login', ['_query' => ['oidc_error' => $encodedError]]);
            }
            return $this->_redirect($loginUrl);
        }

        // ==== TEST-REDIRECT-LOGIK ====
        $isTest = (
            ($this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST) == true)
            || (isset($params['option']) && $params['option'] === OAuthConstants::TEST_CONFIG_OPT)
            || (strpos($relayState, 'showTestResults') !== false)
        );

        if ($isTest) {
            // Test-Key aus relayState extrahieren (z.B. /key/abc123...)
            preg_match('/key\/([a-f0-9]{32,})/', $relayState, $matches);
            $testKey = $matches[1] ?? '';
            if ($testKey) {
                $testResults = $this->customerSession->getData('mooauth_test_results') ?: [];
                // Filter out large token data to prevent session bloat
                $filteredData = $userInfoResponseData;
                if (is_array($filteredData)) {
                    $excludeKeys = ['access_token', 'refresh_token', 'id_token', 'token'];
                    foreach ($excludeKeys as $exKey) {
                        unset($filteredData[$exKey]);
                    }
                }
                $testResults[$testKey] = $filteredData;
                // Only keep the latest 3 test results
                if (count($testResults) > 3) {
                    $testResults = array_slice($testResults, -3, 3, true);
                }
                $this->customerSession->setData('mooauth_test_results', $testResults);
            }
            $safeRelayState = $this->securityHelper->validateRedirectUrl($relayState, '/');
            return $this->_redirect($safeRelayState);
        }
        // ==== ENDE TEST-REDIRECT-LOGIK ====

        // Normale Response-Action
        if (is_array($userInfoResponseData)) {
            $userInfoResponseData['relayState'] = $relayState;
            $userInfoResponseData['loginType'] = $loginType;
        } else {
            $userInfoResponseData->relayState = $relayState;
            $userInfoResponseData->loginType = $loginType;
        }
        $result = $this->processResponseAction->setUserInfoResponse($userInfoResponseData)->execute();
        return $result;
    }
}
