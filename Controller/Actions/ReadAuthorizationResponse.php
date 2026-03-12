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
use MiniOrange\OAuth\Model\Security\OidcRateLimiter;
use MiniOrange\OAuth\Model\Service\OidcAuthenticationService;
use MiniOrange\OAuth\Model\Service\TokenRefreshService;

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

    /** @var OidcRateLimiter */
    private readonly OidcRateLimiter $rateLimiter;

    /** @var TokenRefreshService */
    private readonly TokenRefreshService $tokenRefreshService;

    /** @var \MiniOrange\OAuth\Controller\Actions\CheckAttributeMappingAction */
    private readonly \MiniOrange\OAuth\Controller\Actions\CheckAttributeMappingAction $attrMappingAction;

    /** @var \Magento\Framework\Stdlib\CookieManagerInterface */
    private readonly \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager;

    /** @var \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory */
    private readonly \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory;

    /**
     * Initialize read authorization response action.
     *
     * @param Context $context
     * @param OAuthUtility $oauthUtility
     * @param \Magento\Framework\UrlInterface $url
     * @param \Magento\Customer\Model\Session $customerSession
     * @param OAuthSecurityHelper $securityHelper
     * @param JwtVerifier $jwtVerifier
     * @param Curl $curl
     * @param OidcAuthenticationService $oidcAuthService
     * @param CheckAttributeMappingAction $attrMappingAction
     * @param \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager
     * @param \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory
     * @param OidcRateLimiter $rateLimiter
     * @param TokenRefreshService $tokenRefreshService
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
        CheckAttributeMappingAction $attrMappingAction,
        \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager,
        \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory,
        OidcRateLimiter $rateLimiter,
        TokenRefreshService $tokenRefreshService,
    ) {
        $this->_url = $url;
        $this->customerSession = $customerSession;
        $this->securityHelper = $securityHelper;
        $this->jwtVerifier = $jwtVerifier;
        $this->curl = $curl;
        $this->oidcAuthService = $oidcAuthService;
        $this->attrMappingAction = $attrMappingAction;
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->rateLimiter = $rateLimiter;
        $this->tokenRefreshService = $tokenRefreshService;
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

        // Rate limiting: block IPs that exceed MAX_ATTEMPTS/WINDOW_SECONDS
        /** @var \Magento\Framework\App\Request\Http $request */
        $request = $this->getRequest();
        $clientIp = (string) $request->getClientIp();
        if (!$this->rateLimiter->isAllowed($clientIp)) {
            $this->oauthUtility->customlog(
                "ReadAuthResponse: Rate limit exceeded for IP: " . $clientIp
            );
            $this->messageManager->addErrorMessage(
                __('Too many authentication attempts. Please wait before trying again.')
            );
            return $this->resultRedirectFactory->create()->setPath('customer/account/login');
        }

        $params = $this->getRequest()->getParams();

        // Preparatory logic and logging

        if (!isset($params['code'])) {
            // Parse loginType from state even on error
            $loginType = OAuthConstants::LOGIN_TYPE_CUSTOMER;
            $relayState = '';
            if (isset($params['state'])) {
                $stateData = $this->securityHelper->decodeRelayState($params['state']);
                if ($stateData !== null) {
                    $loginType = $stateData['loginType'];
                    $relayState = $stateData['relayState'];
                } else {
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
        } else {
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
                $providerId = $stateData['providerId'] ?? 0;
                $this->oauthUtility->setActiveProviderId($providerId);
            } else {
                /** @psalm-suppress RedundantCast */
                $parts = explode('|', (string) $combinedRelayState);
                $relayState = urldecode($parts[0]);
                $originalSessionId = isset($parts[1]) ? $parts[1] : '';
                $app_name = isset($parts[2]) ? urldecode($parts[2]) : '';
                $loginType = isset($parts[3]) ? $parts[3] : OAuthConstants::LOGIN_TYPE_CUSTOMER;
                $stateToken = isset($parts[4]) ? $parts[4] : '';
                $providerId = 0;
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

            // MP-04: prefer direct-by-ID lookup
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

            // PKCE (RFC 7636 §4.5): retrieve verifier from session (one-time, auto-removed).
            // Keyed by provider ID so multiple providers can coexist per session.
            $sessionKey = 'pkce_code_verifier_' . (int) $clientDetails['id'];
            $codeVerifier = $this->oauthUtility->getSessionData($sessionKey, true) ?: null;

            if ($codeVerifier !== null) {
                $this->oauthUtility->customlog(
                    "ReadAuthResponse: PKCE code_verifier loaded from session — including in token request"
                );
            } elseif (!empty($clientDetails['pkce_code_verifier'])) {
                // Fallback: admin flow stores verifier in DB because the admin and frontend
                // PHP sessions are isolated. Read and immediately clear (one-time use).
                $codeVerifier = (string) $clientDetails['pkce_code_verifier'];
                $this->oauthUtility->saveProviderData(
                    (int) $clientDetails['id'],
                    ['pkce_code_verifier' => null]
                );
                $this->oauthUtility->customlog(
                    "ReadAuthResponse: PKCE code_verifier loaded from DB (admin flow) — including in token request"
                );
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

            // ── RP-Initiated Logout: persist id_token via cookie for Oidccallback ──
            if (!empty($accessTokenResponseData['id_token'])) {
                $rawIdToken = $accessTokenResponseData['id_token'];
                
                // Encrypt before storing in cookie
                $encryptedToken = $this->oauthUtility->getEncryptor()->encrypt($rawIdToken);
                
                $metadata = $this->cookieMetadataFactory
                    ->createPublicCookieMetadata()
                    ->setPath('/')
                    ->setHttpOnly(true)
                    ->setSecure(true)
                    ->setSameSite('Lax')
                    ->setDuration(120); // 2 min — just enough for the redirect chain

                $this->cookieManager->setPublicCookie(
                    'oidc_id_token_transport',
                    $encryptedToken,
                    $metadata
                );

                $this->cookieManager->setPublicCookie(
                    'oidc_provider_id_transport',
                    (string) $providerId,
                    $metadata
                );

                $this->oauthUtility->customlog(
                    'ReadAuthResponse: id_token stored in transport cookie for provider_id=' . $providerId
                );
            }
            // ── END id_token transport ──

            if (isset($accessTokenResponseData['access_token']) || isset($accessTokenResponseData['id_token'])) {
                // Fetch user info
                $userInfoURL = $clientDetails['user_info_endpoint'];
                if ($userInfoURL != null && $userInfoURL != '' && isset($accessTokenResponseData['access_token'])) {
                    $accessToken = $accessTokenResponseData['access_token'];

                    // Store tokens in session for subsequent refresh (Phase 2.3)
                    $this->tokenRefreshService->storeTokens(
                        (string) ($accessTokenResponseData['refresh_token'] ?? ''),
                        (int) ($accessTokenResponseData['expires_in'] ?? 0),
                        (string) $accessToken
                    );

                    $headerAuth = "Bearer " . $accessToken;
                    $authHeader = ["Authorization: $headerAuth"];
                    $userInfoResponse = $this->curl->sendUserInfoRequest($userInfoURL, $authHeader);
                    $userInfoResponseData = json_decode($userInfoResponse, true);
                } elseif (isset($accessTokenResponseData['id_token'])) {
                    $idToken = $accessTokenResponseData['id_token'];
                    if (!empty($idToken)) {
                        $idTokenResult = $this->resolveUserInfoFromIdToken(
                            $idToken,
                            $clientDetails,
                            $clientID,
                            $relayState,
                            $loginType
                        );
                        if ($idTokenResult instanceof \Magento\Framework\Controller\ResultInterface) {
                            return $idTokenResult;
                        }
                        $userInfoResponseData = $idTokenResult;
                    }
                }

                if (empty($userInfoResponseData)) {
                    $this->oauthUtility->customlog("ERROR: Empty user info response data");
                    $encodedError = base64_encode('Invalid response from OAuth provider. Please try again.');
                    // Test mode: show error on showTestResults instead of admin login
                    if (strpos((string) $relayState, 'showTestResults') !== false) {
                        $errorUrl = rtrim((string) $relayState, '/') . '?oidc_error=' . urlencode($encodedError);
                        return $this->_redirect($errorUrl);
                    }
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
                    $this->storeTestResultInSession($testKey, $userInfoResponseData);

                    // MP-04: Append provider_id as URL parameter to the relayState URL.
                    // Session-based approach removed — sessions are unreliable across
                    // cross-domain OIDC redirects (SameSite cookies, session regeneration).
                    if ($providerId > 0 && strpos((string) $relayState, 'showTestResults') !== false) {
                        $separator = (strpos((string) $relayState, '?') !== false) ? '&' : '?';
                        $relayState .= $separator . 'provider_id=' . $providerId;
                        $this->oauthUtility->customlog(
                            'ReadAuthResponse: appended provider_id=' . $providerId . ' to relayState URL'
                        );
                    }

                    $safeRelayState = $this->securityHelper->validateRedirectUrl($relayState, '/');
                    return $this->_redirect($safeRelayState);
                }
                // ==== END TEST REDIRECT LOGIC ====

                // Process authentication via service layer
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
                    $errorMsg = 'Authentication failed: Invalid user information received from identity provider.';
                    if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) {
                        $this->messageManager->addErrorMessage(__($errorMsg));
                        return $this->resultRedirectFactory->create()->setPath('admin');
                    }
                    $encodedError = base64_encode($errorMsg);
                    $loginUrl = $this->_url->getUrl(
                        'customer/account/login',
                        ['_query' => ['oidc_error' => $encodedError]]
                    );
                    return $this->_redirect($loginUrl);
                }

                $flattenedResponse = [];
                $this->oidcAuthService->flattenAttributes('', $userInfoResponseData, $flattenedResponse);

                $userEmail = $this->oidcAuthService->extractEmail($flattenedResponse, $userInfoResponseData);
                if ($userEmail === '' || $userEmail === '0') {
                    $encodedError = base64_encode(
                        'Email address not received. Please check attribute mapping.'
                    );
                    $loginUrl = $this->_url->getUrl(
                        'customer/account/login',
                        ['_query' => ['oidc_error' => $encodedError]]
                    );
                    return $this->_redirect($loginUrl);
                }

                $detectedLoginType = $this->oidcAuthService->extractLoginType($userInfoResponseData);
                $this->oauthUtility->customlog("ReadAuthorizationResponse: loginType = " . $detectedLoginType);

                // MP-07: pass per-provider attribute mappings
                if (!empty($clientDetails)) {
                    $this->attrMappingAction->setClientDetails($clientDetails);
                }

                return $this->attrMappingAction
                    ->setUserInfoResponse($userInfoResponseData)
                    ->setFlattenedUserInfoResponse($flattenedResponse)
                    ->setUserEmail($userEmail)
                    ->setLoginType($detectedLoginType)
                    ->execute();
            } else {
                $this->oauthUtility->customlog("ERROR: Invalid token response - no access_token or id_token");
                $encodedError = base64_encode('Invalid response from OAuth provider. Please try again.');
                // Test mode: show error on showTestResults instead of admin login
                if (strpos((string) $relayState, 'showTestResults') !== false) {
                    $errorUrl = rtrim((string) $relayState, '/') . '?oidc_error=' . urlencode($encodedError);
                    return $this->_redirect($errorUrl);
                }
                $query = ['_query' => ['oidc_error' => $encodedError]];
                if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) {
                    $loginUrl = $this->_url->getUrl('admin', $query);
                } else {
                    $loginUrl = $this->_url->getUrl('customer/account/login', $query);
                }
                return $this->_redirect($loginUrl);
            }
        }
        /** @var \Magento\Framework\Controller\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath($this->oauthUtility->getCallBackUrl());
        return $resultRedirect;
    }

    /**
     * Store OIDC test result data in the customer session under the given key.
     *
     * Filters out large token values to prevent session bloat.
     * Keeps only the 3 most recent test results.
     *
     * @param  string $testKey          Hex key extracted from relayState
     * @param  mixed  $userInfoResponse Raw user info response data
     */
    private function storeTestResultInSession(string $testKey, $userInfoResponse): void
    {
        if ($testKey === '' || $testKey === '0') {
            return;
        }
        $testResults = $this->customerSession->getData('mooauth_test_results') ?: [];
        // Filter out large token data to prevent session bloat
        $filteredData = $userInfoResponse;
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

    /**
     * Verify and decode an OIDC id_token using the configured JWKS endpoint.
     *
     * Returns the decoded claims array on success, a redirect ResultInterface on
     * verification failure, or null when no JWKS endpoint is configured.
     *
     * @param  string $idToken       Raw JWT id_token from the token endpoint
     * @param  array  $clientDetails Provider configuration row
     * @param  string $clientID      OAuth client identifier
     * @param  string $relayState    Relay state URL for test-mode error redirect
     * @param  string $loginType     Login type (admin|customer) for error routing
     * @return array|\Magento\Framework\App\ResponseInterface
     */
    private function resolveUserInfoFromIdToken(
        string $idToken,
        array $clientDetails,
        string $clientID,
        string $relayState,
        string $loginType
    ) {
        $jwksEndpoint = $clientDetails['jwks_uri'] ?? '';
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
            $decoded = $this->jwtVerifier->verifyAndDecode(
                $idToken,
                $jwksEndpoint,
                $expectedIssuer,
                $clientID
            );
            if ($decoded === null) {
                $this->oauthUtility->customlog("ERROR: JWT signature verification failed");
                $encodedError = base64_encode(
                    'ID token verification failed. Please contact the administrator.'
                );
                if (strpos($relayState, 'showTestResults') !== false) {
                    $errorUrl = rtrim($relayState, '/') . '?oidc_error='
                        . urlencode($encodedError);
                    return $this->_redirect($errorUrl);
                }
                $query = ['_query' => ['oidc_error' => $encodedError]];
                if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) {
                    $loginUrl = $this->_url->getUrl('admin', $query);
                } else {
                    $loginUrl = $this->_url->getUrl('customer/account/login', $query);
                }
                return $this->_redirect($loginUrl);
            }
            return $decoded;
        }

        // SECURITY: Refuse unverified JWTs — JWKS endpoint is required
        $this->oauthUtility->customlog(
            "ERROR: Cannot verify id_token - no JWKS endpoint configured."
        );
        $encodedError = base64_encode(
            'OIDC configuration error: JWKS endpoint is required for id_token verification.'
            . ' Please configure it in the OAuth settings.'
        );
        if (strpos($relayState, 'showTestResults') !== false) {
            $errorUrl = rtrim($relayState, '/') . '?oidc_error='
                . urlencode($encodedError);
            return $this->_redirect($errorUrl);
        }
        $query = ['_query' => ['oidc_error' => $encodedError]];
        if ($loginType === OAuthConstants::LOGIN_TYPE_ADMIN) {
            $loginUrl = $this->_url->getUrl('admin', $query);
        } else {
            $loginUrl = $this->_url->getUrl('customer/account/login', $query);
        }
        return $this->_redirect($loginUrl);
    }
}
