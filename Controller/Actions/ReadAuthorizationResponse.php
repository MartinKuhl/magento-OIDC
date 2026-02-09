<?php

namespace MiniOrange\OAuth\Controller\Actions;

use Exception;
use Magento\Framework\App\Action\Context;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuth\AccessTokenRequest;
use MiniOrange\OAuth\Helper\OAuth\AccessTokenRequestBody;
use MiniOrange\OAuth\Helper\Curl;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Helper\SessionHelper;

class ReadAuthorizationResponse extends BaseAction
{
    private $REQUEST;
    private $POST;
    private $processResponseAction;
    protected $_url;

    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        ProcessResponseAction $processResponseAction,
        \Magento\Framework\UrlInterface $url
    ) {
        $this->processResponseAction = $processResponseAction;
        $this->_url = $url;
        parent::__construct($context, $oauthUtility);
    }

    public function execute()
    {
        SessionHelper::configureSSOSession();
        SessionHelper::updateSessionCookies();

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

        // ... Session zurückwechseln, app_name auslesen wie gehabt ...

        // OAuth-Client-Details laden (wie gehabt, gekürzt)
        $collection = $this->oauthUtility->getOAuthClientApps();
        $clientDetails = null;
        foreach ($collection as $item) {
            $itemData = $item->getData();
            if ($itemData["app_name"] === $app_name) {
                $clientDetails = $itemData;
            }
        }
        if (!$clientDetails) {
            $collection = $this->oauthUtility->getOAuthClientApps();
            if ($collection && count($collection) > 0) {
                foreach ($collection as $item) {
                    $clientDetails = $item->getData();
                    $app_name = $clientDetails["app_name"];
                    $this->oauthUtility->setSessionData(OAuthConstants::APP_NAME, $app_name);
                    break;
                }
            }
            if (!$clientDetails) {
                return $this->getResponse()->setBody("Ungültige OAuth-App-Konfiguration. Bitte kontaktieren Sie den Administrator.");
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
                $idTokenArray = explode(".", $idToken);
                $userInfoResponseData = $idTokenArray[1];
                $userInfoResponseData = (array) json_decode((string) base64_decode($userInfoResponseData));
            }
        } else {
            return $this->getResponse()->setBody("Invalid response. Please try again.");
        }

        if (empty($userInfoResponseData)) {
            return $this->getResponse()->setBody("Invalid response. Please try again.");
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
                $_SESSION['mooauth_test_results'][$testKey] = $userInfoResponseData;
            }
            return $this->_redirect($relayState);
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
