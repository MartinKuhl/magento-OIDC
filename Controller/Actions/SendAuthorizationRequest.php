<?php

namespace M2Oidc\OAuth\Controller\Actions;

use M2Oidc\OAuth\Helper\OAuth\AuthorizationRequest;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthSecurityHelper;

/**
 * Handles generation and sending of AuthnRequest to the final IDP
 * for authentication. AuthnRequest is generated and user is
 * redirected to the IDP for authentication.
 *
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 */
class SendAuthorizationRequest extends BaseAction
{
    /** @var OAuthSecurityHelper */
    private readonly OAuthSecurityHelper $securityHelper;

    /** @var \Magento\Framework\Session\SessionManagerInterface */
    private readonly \Magento\Framework\Session\SessionManagerInterface $sessionManager;

    /** @var \Magento\Framework\Stdlib\CookieManagerInterface */
    private readonly \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager;

    /** @var \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory */
    private readonly \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory;

    /**
     * @param \Magento\Framework\App\Action\Context                    $context
     * @param \M2Oidc\OAuth\Helper\OAuthUtility                        $oauthUtility
     * @param OAuthSecurityHelper                                      $securityHelper
     * @param \Magento\Framework\Session\SessionManagerInterface       $sessionManager
     * @param \Magento\Framework\Stdlib\CookieManagerInterface         $cookieManager
     * @param \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory   $cookieMetadataFactory
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility,
        OAuthSecurityHelper $securityHelper,
        \Magento\Framework\Session\SessionManagerInterface $sessionManager,
        \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager,
        \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory
    ) {
        $this->securityHelper        = $securityHelper;
        $this->sessionManager        = $sessionManager;
        $this->cookieManager         = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
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

        $this->oauthUtility->customlog("SendAuthorizationRequest: execute");

        $params = $this->getRequest()->getParams();
        $this->oauthUtility->customlog(
            "SendAuthorizationRequest: Request prarms: " . implode(" ", $params)
        );
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

        // If provider_id was not given in params but app_name was, load provider details
        // early to get the correct numeric ID for embedding in the relay state.
        // Without this, encodeRelayState() emits no 'p' key and ReadAuthorizationResponse
        // cannot reconstruct the provider context, causing provider_id=0 throughout the chain.
        if ($providerId === 0 && !empty($app_name) && $earlyProviderDetails === null) {
            $earlyProviderDetails = $this->oauthUtility->getClientDetailsByAppName($app_name);
            if ($earlyProviderDetails !== null) {
                $providerId = (int) ($earlyProviderDetails['id'] ?? 0);
                if ($providerId > 0) {
                    $this->oauthUtility->setActiveProviderId($providerId);
                }
            }
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
        // H-02: Read login_type from params but clamp to known valid values.
        // The admin SSO button legitimately passes login_type=admin via this frontend route.
        // Any value other than LOGIN_TYPE_ADMIN is coerced to LOGIN_TYPE_CUSTOMER, preventing
        // injection of unexpected values while preserving the admin login flow.
        $loginType = (string) ($params['login_type'] ?? OAuthConstants::LOGIN_TYPE_CUSTOMER);
        if ($loginType !== OAuthConstants::LOGIN_TYPE_ADMIN) {
            $loginType = OAuthConstants::LOGIN_TYPE_CUSTOMER;
        }
        $relayState = $this->securityHelper->encodeRelayState(
            $relayState,
            $currentSessionId,
            $app_name,
            $loginType,
            $stateToken,
            $providerId > 0 ? $providerId : null
        );
        $this->oauthUtility->customlog("SendAuthorizationRequest: Combined relayState: " . $relayState);

        // H-01: Generate and store a per-flow nonce for id_token replay protection.
        // The nonce is added to the authorization request params so the IdP includes
        // it in the id_token. It is consumed in ReadAuthorizationResponse and passed
        // to JwtVerifier::verifyAndDecode() for validation.
        $oidcNonce = bin2hex(random_bytes(16));
        $this->securityHelper->storeOidcNonce($stateToken, $oidcNonce);
        $params['nonce'] = $oidcNonce;

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

        // PKCE (RFC 7636) — generate verifier when provider has PKCE configured (FEAT-01)
        $codeChallenge       = null;
        $codeChallengeMethod = null;
        $pkceFlow            = $clientDetails['pkce_flow'] ?? '';
        if ($pkceFlow === OAuthConstants::PKCE_METHOD_S256
            || $pkceFlow === OAuthConstants::PKCE_METHOD_PLAIN
        ) {
            $codeVerifier        = $this->securityHelper->generateCodeVerifier();
            $codeChallenge       = $this->securityHelper->computeCodeChallenge($codeVerifier, $pkceFlow);
            $codeChallengeMethod = $pkceFlow;

            // Store verifier in shared cache (not PHP session) + transport via httpOnly cookie.
            // PHP sessions are lost during the OAuth redirect cycle on multi-server deployments;
            // Magento's shared cache (Redis in production) and cookies survive the round-trip.
            $pkceNonce = $this->securityHelper->storePkceVerifier(
                $codeVerifier,
                (int) $clientDetails['id']
            );
            $this->cookieManager->setPublicCookie(
                'oidc_pkce_nonce',
                $pkceNonce,
                $this->cookieMetadataFactory->createPublicCookieMetadata()
                    ->setDuration(600)
                    ->setPath('/m2oidc/')
                    ->setHttpOnly(true)
                    ->setSecure(true)
                    ->setSameSite('Lax')
            );

            $this->oauthUtility->customlog(
                "SendAuthorizationRequest: PKCE {$pkceFlow} enabled — challenge generated, verifier stored in cache"
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
            $codeChallenge,
            $codeChallengeMethod
        ))->build();
        $this->oauthUtility->customlog(
            "SendAuthorizationRequest:  Authorization Request: " . $authorizationRequest
        );
        // send oauth request over
        return $this->sendHTTPRedirectRequest($authorizationRequest, $authorizeURL, $relayState, $params);
    }
}
