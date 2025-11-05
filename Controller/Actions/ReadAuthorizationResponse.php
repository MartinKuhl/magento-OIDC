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

/**
 * Handles reading of Responses from the IDP. Read the SAML Response
 * from the IDP and process it to detect if it's a valid response from the IDP.
 * Generate a SAML Response Object and log the user in. Update existing user
 * attributes and groups if necessary.
 */
class ReadAuthorizationResponse extends BaseAction
{
    private $REQUEST;
    private $POST;
    private $processResponseAction;

    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        ProcessResponseAction $processResponseAction
    ) {
        //You can use dependency injection to get any class this observer may need.
        $this->processResponseAction = $processResponseAction;
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Execute function to execute the classes function.
     * @throws Exception
     */
    public function execute()
    {
        // Session für SSO konfigurieren
        SessionHelper::configureSSOSession();
        SessionHelper::updateSessionCookies();
         
        // read the response
        $params = $this->getRequest()->getParams();
        $Log_file_time = $this->oauthUtility->getStoreConfig(OAuthConstants::LOG_FILE_TIME);
        $current_time = time();
        $chk_enable_log = 1;
        $islogEnable = $this->oauthUtility->getStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG);
        
        if (($Log_file_time != NULL && ($current_time - $Log_file_time) >= 60*60*24*7) && $islogEnable) { //7days
            $this->oauthUtility->setStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG, 0);
            $chk_enable_log = 0;
            $this->oauthUtility->setStoreConfig(OAuthConstants::LOG_FILE_TIME, NULL);
            $this->oauthUtility->deleteCustomLogFile();
            $this->oauthUtility->flushCache();
        }
        
        $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: execute") : NULL;
        $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: params: " . implode(" ", $params)) : NULL;
        
        if (!isset($params['code'])) {
            $relayState = isset($params['relayState']) ? $params['relayState'] : '';
            $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: params['code'] not set") : NULL;
            
            if (isset($params['error'])) {
                return $this->sendHTTPRedirectRequest('?error=' . urlencode($params['error']), $this->oauthUtility->getBaseUrl());
            }
            return $this->sendHTTPRedirectRequest('?error=code+not+received', $this->oauthUtility->getBaseUrl(), $relayState, $params);
        }

        $authorizationCode = $params['code'];
        $combinedRelayState = $params['state']; // TODO: Security issue to be fixed
        $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: authorizationCode: " . $authorizationCode) : NULL;
        $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: combinedRelayState: " . $combinedRelayState) : NULL;
        
        // Extrahieren der ursprünglichen Relay-State, Session-ID und App-Name
        $parts = explode('|', $combinedRelayState);
        $relayState = $parts[0];
        $this->oauthUtility->customlog("RelayState after return from OIDC: " . $relayState);
        $originalSessionId = isset($parts[1]) ? $parts[1] : '';
        $app_name = isset($parts[2]) ? $parts[2] : '';
        
        $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: Original relayState: " . $relayState) : NULL;
        $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: Original sessionId: " . $originalSessionId) : NULL;
        $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: App name: " . $app_name) : NULL;
        
        // Aktuelle Session-ID speichern und dann zur ursprünglichen Session wechseln
        $currentSessionId = session_id();
        $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: Current sessionId: " . $currentSessionId) : NULL;
        
        if (!empty($originalSessionId) && $originalSessionId !== $currentSessionId) {
            // Speichern der aktuellen Session-Daten
            $tempData = $_SESSION;
            $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: Saving current session data and switching to original session") : NULL;
            
            // Aktuelle Session schließen
            session_write_close();
            
            // Zur ursprünglichen Session-ID wechseln
            session_id($originalSessionId);
            session_start();
            
            // Die wichtigen OAuth-Daten aus der temporären Session übertragen
            if (isset($tempData['oauth_access_token'])) {
                $_SESSION['oauth_access_token'] = $tempData['oauth_access_token'];
            }
            if (isset($tempData['oauth_id_token'])) {
                $_SESSION['oauth_id_token'] = $tempData['oauth_id_token'];
            }
        }
        
        // App-Name explizit in die Session schreiben und für die weitere Verarbeitung verwenden
        if (!empty($app_name)) {
            $this->oauthUtility->setSessionData(OAuthConstants::APP_NAME, $app_name);
            $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: App name set in session: " . $app_name) : NULL;
        } else {
            // Versuchen, den App-Namen aus der Session zu holen, falls nicht im State-Parameter
            $app_name = $this->oauthUtility->getSessionData(OAuthConstants::APP_NAME);
            $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: App name from session: " . $app_name) : NULL;
        }
        
        // Wenn der App-Name immer noch leer ist, versuchen den Standard-App-Namen aus der Konfiguration zu holen
        if (empty($app_name)) {
            $app_name = $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
            $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: Trying default app name from config: " . $app_name) : NULL;
        }

        //storing values from custom table in $clientDetails array
        $collection = $this->oauthUtility->getOAuthClientApps();
        $clientDetails = null;
        foreach ($collection as $item) {
            $itemData = $item->getData();
            $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: Found app: " . $itemData["app_name"]) : NULL;
            if ($itemData["app_name"] === $app_name) {
                $clientDetails = $itemData;
                $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: Using client details for app: " . $app_name) : NULL;
            }
        }
           
        // Überprüfen, ob $clientDetails nicht null ist
        if (!$clientDetails) {
            $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: Keine Client-Details für App '$app_name' gefunden!") : NULL;
            
            // Versuche, die erste verfügbare App zu verwenden als Notfalllösung
            $collection = $this->oauthUtility->getOAuthClientApps();
            if ($collection && count($collection) > 0) {
                foreach ($collection as $item) {
                    $clientDetails = $item->getData();
                    $app_name = $clientDetails["app_name"];
                    $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: Verwende erste verfügbare App: $app_name") : NULL;
                    // Speichere App-Namen für zukünftige Verwendung
                    $this->oauthUtility->setSessionData(OAuthConstants::APP_NAME, $app_name);
                    break;
                }
            }
            
            // Wenn immer noch keine App gefunden wurde
            if (!$clientDetails) {
                $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: Keine OAuth-Apps konfiguriert!") : NULL;
                return $this->getResponse()->setBody("Ungültige OAuth-App-Konfiguration. Bitte kontaktieren Sie den Administrator.");
            }
        }

        //get required values from the database
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
            //generate the accessToken request
            $accessTokenRequest = (new AccessTokenRequest($clientID, $clientSecret, $grantType, $redirectURL, $authorizationCode))->build();
        }

        //send the accessToken request
        $accessTokenResponse = Curl::mo_send_access_token_request($accessTokenRequest, $accessTokenURL, $clientID, $clientSecret, $header, $body);

        // todo: if access token response has an error
        // if access token endpoint returned a success response
        $accessTokenResponseData = json_decode($accessTokenResponse, 'true');
        
        if (isset($accessTokenResponseData['id_token'])) {
            $idToken = $accessTokenResponseData['id_token'];
            $this->oauthUtility->log_debug("ReadAuthorizationResponse: idToken: " . $idToken);
            $this->oauthUtility->setSessionData(OAuthConstants::ID_TOKEN, $idToken);
            $this->oauthUtility->setAdminSessionData(OAuthConstants::ID_TOKEN, $idToken);
            $this->oauthUtility->log_debug("ReadAuthorizationResponse: idToken stored: " . $idToken);
        }
        
        $userInfoURL = $clientDetails['user_info_endpoint'];

        if (!($userInfoURL == NULL || $userInfoURL == '') && isset($accessTokenResponseData['access_token'])) {
            $this->oauthUtility->log_debug("ReadAuthorizationResponse: accessTokenResponseData['access_token'] is set");
            $accessToken = $accessTokenResponseData['access_token'];
            $this->oauthUtility->log_debug("ReadAuthorizationResponse: accessToken: " . ($accessToken));
            $this->oauthUtility->log_debug("ReadAuthorizationResponse: userInfoURL: " . ($userInfoURL));
            
            if ($userInfoURL == NULL || $userInfoURL == '') {
                return $this->getResponse()->setBody("Invalid response. Please enter User Info URL.");
            }
            
            $header = "Bearer " . $accessToken;
            $authHeader = [
                "Authorization: $header"
            ];
            $userInfoResponse = Curl::mo_send_user_info_request($userInfoURL, $authHeader);
            $userInfoResponseData = json_decode($userInfoResponse, 'true');
            $this->oauthUtility->log_debug("ReadAuthorizationResponse: userInfoResponse" . json_encode($userInfoResponse));
        } elseif (isset($accessTokenResponseData['id_token'])) {
            $idToken = $accessTokenResponseData['id_token'];
            if (!empty($idToken)) {
                $idTokenArray = explode(".", $idToken);
                $userInfoResponseData = $idTokenArray[1];
                $userInfoResponseData = (array) json_decode((string)base64_decode($userInfoResponseData));
                $x509_cert = $this->oauthUtility->getStoreConfig(OAuthConstants::X509CERT);
            }
        } else {
            $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: access_token ->NULL and id_token ->NULL") : NULL;
            return $this->getResponse()->setBody("Invalid response. Please try again.");
        }

        if (empty($userInfoResponseData)) {
            $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: userinfoResponseData NULL") : NULL;
            return $this->getResponse()->setBody("Invalid response. Please try again.");
        }

        if (is_array($userInfoResponseData)) {
            $userInfoResponseData['relayState'] = $relayState;
        } else {
            $userInfoResponseData->relayState = $relayState;
        }

        $isTest = ($this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST) == true)
            || (isset($params['option']) && $params['option'] === OAuthConstants::TEST_CONFIG_OPT)
            || (strpos($relayState, 'showTestResults') !== false);

        if ($isTest) {
            // Es ist ein TEST-Flow: Redirect zu Test-Ergebnis-Controller!
            return $this->_redirect($relayState);
        }

        $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: Calling processResponseAction->execute()") : NULL;
        $result = $this->processResponseAction->setUserInfoResponse($userInfoResponseData)->execute();

        $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: processResponseAction returned: " . 
            ($result ? get_class($result) : 'NULL')) : NULL;

        $chk_enable_log ? $this->oauthUtility->customlog("ReadAuthorizationResponse: Returning result now...") : NULL;
        return $result;
    }

    /** Setter for the request Parameter */
    public function setRequestParam($request)
    {
        $this->REQUEST = $request;
        return $this;
    }

    /** Setter for the post Parameter */
    public function setPostParam($post)
    {
        $this->POST = $post;
        return $this;
    }

    public function get_base64_from_url($b64url)
    {
        return base64_decode(str_replace(['-','_'], ['+','/'], $b64url));
    }
}
