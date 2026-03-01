<?php

namespace MiniOrange\OAuth\Controller\Actions;

use Magento\Framework\App\Action\Context;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuth\AccessTokenRequest;
use MiniOrange\OAuth\Helper\OAuth\AccessTokenRequestBody;
use MiniOrange\OAuth\Helper\Curl;
use MiniOrange\OAuth\Helper\Exception\IncorrectUserInfoDataException;
use MiniOrange\OAuth\Helper\JwtVerifier;
use MiniOrange\OAuth\Helper\OAuthSecurityHelper;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Model\Service\OidcAuthenticationService;

/**
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 */
class ReadAuthorizationResponse extends BaseAction
{
    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $_url;

    /** @var \Magento\Customer\Model\Session */
    private readonly \Magento\Customer\Model\Session $customerSession;

    /** @var \MiniOrange\OAuth\Helper\OAuthSecurityHelper */
    private readonly \MiniOrange\OAuth\Helper\OAuthSecurityHelper $securityHelper;

    /** @var \MiniOrange\OAuth\Helper\JwtVerifier */
    private readonly \MiniOrange\OAuth\Helper\JwtVerifier $jwtVerifier;

    /** @var \MiniOrange\OAuth\Helper\Curl */
    private readonly \MiniOrange\OAuth\Helper\Curl $curl;

    /** @var \MiniOrange\OAuth\Model\Service\OidcAuthenticationService */
    private readonly \MiniOrange\OAuth\Model\Service\OidcAuthenticationService $oidcAuthService;

    /** @var \MiniOrange\OAuth\Controller\Actions\CheckAttributeMappingAction */
    private readonly \MiniOrange\OAuth\Controller\Actions\CheckAttributeMappingAction $attrMappingAction;

    /**
     * Initialize read authorization response action.
     *
     * @param Context                                           $context
     * @param OAuthUtility                                      $oauthUtility
     * @param \Magento\Framework\UrlInterface                   $url
     * @param \Magento\Customer\Model\Session                   $customerSession
     * @param OAuthSecurityHelper                               $securityHelper
     * @param JwtVerifier                                       $jwtVerifier
     * @param Curl                                              $curl
     * @param OidcAuthenticationService                         $oidcAuthService
     * @param CheckAttributeMappingAction                       $attrMappingAction
     */
    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        \Magento\Framework\UrlInterface $url,
        \Magento\Customer\Model\Session $customerSession,
        OAuthSecurityHelper $securityHelper,
        JwtVerifier $jwtVerifier,
        Curl $curl,
        OidcAuthenticationService $oidcAuthService,
        CheckAttributeMappingAction $attrMappingAction
    ) {
        $this->_url = $url;
        $this->customerSession = $customerSession;
        $this->securityHelper = $securityHelper;
        $this->jwtVerifier = $jwtVerifier;
        $this->curl = $curl;
        $this->oidcAuthService = $oidcAuthService;
        $this->attrMappingAction = $attrMappingAction;
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Process the OAuth/OIDC authorization callback.
     *
     * Validates state token, exchanges authorization code for access/ID tokens,
     * verifies JWT signatures, and redirects to the appropriate destination.
     *
     * @return \Magento\Framework\Controller\ResultInterface|\Magento\Framework\App\ResponseInterface
     */
    #[\Override]
    public function execute()
    {
        // configureSSOSession() removed from callback handler.
        // SameSite=None is only needed in SendAuthorizationRequest (outbound redirect to IdP).
        // Calling it here sets a stale Secure cookie that conflicts with session_regenerate_id()
        // inside setCustomerAsLoggedIn(), causing the browser to keep the old destroyed session ID.

        $params = $this->getRequest()->getParams();

        // Preparatory logic and logging

        if (!isset($params['code'])) {
            // Parse loginType from state even on error (state is still passed by OAuth provider)
            $loginType = OAuthConstants::LOGIN_TYPE_CUSTOMER; // default to customer
            $relayState = '';
            if (isset($params['state'])) {
                $stateData = $this->securityHelper->decodeRelayState($params['state']);
                if ($stateData !== null) {
                    $loginType = $stateData['loginType'];
                    $relayState = $stateData['relayState'];
                } else {
                    // Legacy pipe-delimited format (backward compatibility)
                    $parts = explode('|', (string) $params['state']);
                    $loginType = isset($parts[3]) ? $parts[3] : OAuthConstants::LOGIN_TYPE_CUSTOMER;
                    $relayState = urldecode($parts[0]);
                }
            }

            if (isset($params['error'])) {
                $errorMsg = $params['error_description'] ?? $params['error'];
                $encodedError = base64_encode((string) $errorMsg);

                $isTest = (
                    ($this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST) == true)
                    || (strpos((string) $relayState, 'showTestResults') !== false)
                );

                if ($isTest && strpos((string) $relayState, 'showTestResults') !== false) {
                    // Test mode: redirect to showTestResults with error
                    $errorUrl = $relayState . (strpos((string) $relayState, '?') !== false ? '&' : '?')
                        . 'oidc_error=' . $encodedError;
                    return $this->_redirect($errorUrl);
                }

                $query = ['_query' => ['oidc_error' => $encodedError]];
                if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) {
                    // Admin: redirect to admin login page
                    $loginUrl = $this->_url->getUrl('admin', $query);
                } else {
                    // Customer: redirect to customer login page
                    $loginUrl = $this->_url->getUrl('customer/account/login', $query);
                }
                return $this->_redirect($loginUrl);
            }
        }

        $authorizationCode = $params['code'];
        $combinedRelayState = $params['state'];

        // Try JSON+Base64 decoding first; fall back to legacy pipe format for backward compatibility
        $stateData = $this->securityHelper->decodeRelayState($combinedRelayState);
        if ($stateData !== null) {
            $relayState = $stateData['relayState'];
            $originalSessionId = $stateData['sessionId'];
            $app_name = $stateData['appName'];
            $loginType = $stateData['loginType'];
            $stateToken = $stateData['stateToken'];
            // MP-04: provider_id embedded in state (0 = legacy/unknown — use app_name fallback below)
            $providerId = $stateData['providerId'] ?? 0;
        } else {
            // Legacy pipe-delimited format (backward compatibility during rollout)
            /** @psalm-suppress RedundantCast */
            $parts = explode('|', (string) $combinedRelayState);
            $relayState = urldecode($parts[0]);
            $originalSessionId = isset($parts[1]) ? $parts[1] : '';
            $app_name = isset($parts[2]) ? urldecode($parts[2]) : '';
            $loginType = isset($parts[3]) ? $parts[3] : OAuthConstants::LOGIN_TYPE_CUSTOMER;
            $stateToken = isset($parts[4]) ? $parts[4] : '';
            $providerId = 0; // legacy state — no provider_id available
        }

        // Validate CSRF state token
        $this->oauthUtility->customlog(
            "ReadAuthResponse: Validating state token. "
            . "Original Session ID: " . $originalSessionId
            . ", State Token: " . substr((string) $stateToken, 0, 8) . "..."
        );

        if (empty($stateToken)
            || !$this->securityHelper->validateStateToken($originalSessionId, $stateToken)
        ) {
            $this->oauthUtility->customlog("ERROR: State token validation failed (CSRF protection)");
            $encodedError = base64_encode('Security validation failed. Please try logging in again.');
            $query = ['_query' => ['oidc_error' => $encodedError]];
            if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) {
                $loginUrl = $this->_url->getUrl('admin', $query);
            } else {
                $loginUrl = $this->_url->getUrl('customer/account/login', $query);
            }
            return $this->_redirect($loginUrl);
        }

        $this->oauthUtility->customlog(
            "ReadAuthResponse: State token validation PASSED"
        );

        // MP-04: prefer direct-by-ID lookup when provider_id is known from state
        $clientDetails = ($providerId > 0)
            ? $this->oauthUtility->getClientDetailsById($providerId)
            : $this->oauthUtility->getClientDetailsByAppName($app_name);
        if (!$clientDetails) {
            // Fallback: use the first configured app
            $collection = $this->oauthUtility->getOAuthClientApps();
            if (count($collection) > 0) {
                $clientDetails = $collection->getFirstItem()->getData();
                $app_name = $clientDetails["app_name"];
                $this->oauthUtility->setSessionData(OAuthConstants::APP_NAME, $app_name);
            } else {
                $this->oauthUtility->customlog("ERROR: Invalid OAuth app configuration");
                $encodedError = base64_encode(
                    'Invalid OAuth app configuration. Please contact the administrator.'
                );
                $query = ['_query' => ['oidc_error' => $encodedError]];
                if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) {
                    $loginUrl = $this->_url->getUrl('admin', $query);
                } else {
                    $loginUrl = $this->_url->getUrl('customer/account/login', $query);
                }
                return $this->_redirect($loginUrl);
            }
        }

        // Build token request
        $clientID = $clientDetails["clientID"];
        $clientSecret = $clientDetails["client_secret"];
        $accessTokenURL = $clientDetails["access_token_endpoint"];
        $header = $clientDetails["values_in_header"];
        $body = $clientDetails["values_in_body"];
        $redirectURL = $this->oauthUtility->getCallBackUrl();

        // PKCE (RFC 7636 §4.5): retrieve verifier stored during authorization (FEAT-01)
        $codeVerifier = (string) $this->oauthUtility->getSessionData(
            OAuthConstants::PKCE_VERIFIER_SESSION_KEY,
            true // one-time: remove from session immediately after reading
        );
        $codeVerifier = $codeVerifier !== '' ? $codeVerifier : null;

        if ($codeVerifier !== null) {
            $this->oauthUtility->customlog("ReadAuthResponse: PKCE code_verifier found — including in token request");
        }

        if ($header == 1 && $body == 0) {
            $accessTokenRequest = (new AccessTokenRequestBody($redirectURL, $authorizationCode, $codeVerifier))
                ->build();
        } else {
            $accessTokenRequest = (new AccessTokenRequest(
                $clientID,
                $clientSecret,
                $redirectURL,
                $authorizationCode,
                $codeVerifier
            ))->build();
        }

        $accessTokenResponse = $this->curl->sendAccessTokenRequest(
            $accessTokenRequest,
            $accessTokenURL,
            $clientID,
            $clientSecret,
            $header,
            $body
        );

        $accessTokenResponseData = json_decode($accessTokenResponse, true);

        // Fetch user info
        $userInfoURL = $clientDetails['user_info_endpoint'];
        if ($userInfoURL != null && $userInfoURL != '' && isset($accessTokenResponseData['access_token'])) {
            $accessToken = $accessTokenResponseData['access_token'];
            $headerAuth = "Bearer " . $accessToken;
            $authHeader = ["Authorization: $headerAuth"];
            $userInfoResponse = $this->curl->sendUserInfoRequest($userInfoURL, $authHeader);
            $userInfoResponseData = json_decode($userInfoResponse, true);
        } elseif (isset($accessTokenResponseData['id_token'])) {
            $idToken = $accessTokenResponseData['id_token'];
            if (!empty($idToken)) {
                $jwksEndpoint = $clientDetails['jwks_endpoint'] ?? '';
                if (!empty($jwksEndpoint)) {
                    // Resolve expected issuer from stored discovery document data
                    $expectedIssuer = $clientDetails['issuer'] ?? null;
                    if (empty($expectedIssuer)) {
                        // Fallback: derive issuer from well-known URL
                        $wellKnownUrl = $clientDetails['well_known_config_url'] ?? '';
                        if (!empty($wellKnownUrl)) {
                            $expectedIssuer = preg_replace(
                                '#/\.well-known/openid-configuration$#i',
                                '',
                                (string) $wellKnownUrl
                            );
                        }
                    }
                    $userInfoResponseData = $this->jwtVerifier->verifyAndDecode(
                        $idToken,
                        $jwksEndpoint,
                        $expectedIssuer,
                        $clientID
                    );
                    if ($userInfoResponseData === null) {
                        $this->oauthUtility->customlog("ERROR: JWT signature verification failed");
                        $encodedError = base64_encode(
                            'ID token verification failed. Please contact the administrator.'
                        );
                        $query = ['_query' => ['oidc_error' => $encodedError]];
                        if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) {
                            $loginUrl = $this->_url->getUrl('admin', $query);
                        } else {
                            $loginUrl = $this->_url->getUrl('customer/account/login', $query);
                        }
                        return $this->_redirect($loginUrl);
                    }
                } else {
                    // SECURITY: Refuse unverified JWTs — JWKS endpoint is required
                    $this->oauthUtility->customlog("ERROR: Cannot verify id_token - no JWKS endpoint configured.");
                    $encodedError = base64_encode(
                        'OIDC configuration error: JWKS endpoint is required for id_token verification.'
                        . ' Please configure it in the OAuth settings.'
                    );
                    $query = ['_query' => ['oidc_error' => $encodedError]];
                    if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) {
                        $loginUrl = $this->_url->getUrl('admin', $query);
                    } else {
                        $loginUrl = $this->_url->getUrl('customer/account/login', $query);
                    }
                    return $this->_redirect($loginUrl);
                }
            }
        } else {
            $this->oauthUtility->customlog("ERROR: Invalid token response - no access_token or id_token");
            $encodedError = base64_encode('Invalid response from OAuth provider. Please try again.');
            $query = ['_query' => ['oidc_error' => $encodedError]];
            if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) {
                $loginUrl = $this->_url->getUrl('admin', $query);
            } else {
                $loginUrl = $this->_url->getUrl('customer/account/login', $query);
            }
            return $this->_redirect($loginUrl);
        }

        if (empty($userInfoResponseData)) {
            $this->oauthUtility->customlog("ERROR: Empty user info response data");
            $encodedError = base64_encode('Invalid response from OAuth provider. Please try again.');
            $query = ['_query' => ['oidc_error' => $encodedError]];
            if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) {
                $loginUrl = $this->_url->getUrl('admin', $query);
            } else {
                $loginUrl = $this->_url->getUrl('customer/account/login', $query);
            }
            return $this->_redirect($loginUrl);
        }

        // ==== TEST REDIRECT LOGIC ====
        $isTest = (
            ($this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST) == true)
            || (isset($params['option']) && $params['option'] === OAuthConstants::TEST_CONFIG_OPT)
            || (strpos((string) $relayState, 'showTestResults') !== false)
        );

        if ($isTest) {
            // Extract test key from relayState (e.g. /key/abc123...)
            preg_match('/key\/([a-f0-9]{32,})/', (string) $relayState, $matches);
            $testKey = $matches[1] ?? '';
            if ($testKey !== '' && $testKey !== '0') {
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
        // ==== END TEST REDIRECT LOGIC ====

        // Process authentication via service layer (replaces controller chaining)
        if (is_array($userInfoResponseData)) {
            $userInfoResponseData['relayState'] = $relayState;
            $userInfoResponseData['loginType'] = $loginType;
        } else {
            $userInfoResponseData->relayState = $relayState;
            $userInfoResponseData->loginType = $loginType;
        }

        try {
            $this->oidcAuthService->validateUserInfo($userInfoResponseData);
        } catch (IncorrectUserInfoDataException $e) {
            $this->oauthUtility->customlog(
                "ERROR: Invalid user info data from OAuth provider - " . $e->getMessage()
            );
            $this->messageManager->addErrorMessage(
                __('Authentication failed: Invalid user information received from identity provider.')
            );
            $errorPath = ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) ? 'admin' : 'customer/account/login';
            return $this->resultRedirectFactory->create()->setPath($errorPath);
        }

        $flattenedResponse = [];
        $this->oidcAuthService->flattenAttributes('', $userInfoResponseData, $flattenedResponse);

        $userEmail = $this->oidcAuthService->extractEmail($flattenedResponse, $userInfoResponseData);
        if ($userEmail === '' || $userEmail === '0') {
            $this->messageManager->addErrorMessage(
                __('Email address not received. Please check attribute mapping.')
            );
            return $this->resultRedirectFactory->create()->setPath('customer/account/login');
        }

        $detectedLoginType = $this->oidcAuthService->extractLoginType($userInfoResponseData);
        $this->oauthUtility->customlog("ReadAuthorizationResponse: loginType = " . $detectedLoginType);

        // MP-07: pass per-provider attribute mappings so the correct row columns are used
        if (!empty($clientDetails)) {
            $this->attrMappingAction->setClientDetails($clientDetails);
        }

        return $this->attrMappingAction
            ->setUserInfoResponse($userInfoResponseData)
            ->setFlattenedUserInfoResponse($flattenedResponse)
            ->setUserEmail($userEmail)
            ->setLoginType($detectedLoginType)
            ->execute();
    }
}
