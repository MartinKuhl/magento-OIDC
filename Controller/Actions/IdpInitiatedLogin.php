<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Actions;

use Magento\Framework\App\Action\HttpGetActionInterface;
use M2Oidc\OAuth\Helper\OAuth\AuthorizationRequest;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthSecurityHelper;
use M2Oidc\OAuth\Model\Security\OidcRateLimiter;

/**
 * IdP-Initiated SSO entry point (OIDC Third-Party Initiated Login §4).
 *
 * The IdP redirects users to this endpoint after they click an application tile
 * in the IdP dashboard. The controller generates a fresh PKCE verifier, OIDC
 * nonce, and CSRF state token — identical to SP-initiated flow — and immediately
 * redirects to the IdP authorization endpoint. The normal callback
 * (ReadAuthorizationResponse) then handles the response without any changes.
 *
 * Register this URL as the "Initiate Login URI" in your IdP application settings:
 *   https://<store>/m2oidc/actions/idpInitiatedLogin?provider_id=<id>
 *
 * Optional query parameters:
 *   relay_state  — Post-login destination URL (same-origin only, defaults to '/')
 *   login_hint   — Email or username pre-fill passed to the IdP
 *
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 */
class IdpInitiatedLogin extends BaseAction implements HttpGetActionInterface
{
    /** @var OAuthSecurityHelper */
    private readonly OAuthSecurityHelper $securityHelper;

    /** @var \Magento\Framework\Session\SessionManagerInterface */
    private readonly \Magento\Framework\Session\SessionManagerInterface $sessionManager;

    /** @var OidcRateLimiter */
    private readonly OidcRateLimiter $rateLimiter;

    /** @var \Magento\Framework\App\Request\Http */
    private readonly \Magento\Framework\App\Request\Http $httpRequest;

    /** @var \Magento\Framework\Stdlib\CookieManagerInterface */
    private readonly \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager;

    /** @var \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory */
    private readonly \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory;

    /**
     * @param \Magento\Framework\App\Action\Context                    $context
     * @param \M2Oidc\OAuth\Helper\OAuthUtility                        $oauthUtility
     * @param OAuthSecurityHelper                                      $securityHelper
     * @param \Magento\Framework\Session\SessionManagerInterface       $sessionManager
     * @param OidcRateLimiter                                          $rateLimiter
     * @param \Magento\Framework\App\Request\Http                      $httpRequest
     * @param \Magento\Framework\Stdlib\CookieManagerInterface         $cookieManager
     * @param \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory   $cookieMetadataFactory
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility,
        OAuthSecurityHelper $securityHelper,
        \Magento\Framework\Session\SessionManagerInterface $sessionManager,
        OidcRateLimiter $rateLimiter,
        \Magento\Framework\App\Request\Http $httpRequest,
        \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager,
        \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory
    ) {
        $this->securityHelper        = $securityHelper;
        $this->sessionManager        = $sessionManager;
        $this->rateLimiter           = $rateLimiter;
        $this->httpRequest           = $httpRequest;
        $this->cookieManager         = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Handle an IdP-initiated login request.
     *
     * @throws \Exception
     */
    #[\Override]
    public function execute()
    {
        $this->oauthUtility->customlog("IdpInitiatedLogin: execute");

        // 1. Rate limiting — reuses the shared IP counter with the callback endpoint.
        $clientIp = (string) $this->httpRequest->getClientIp();
        if (!$this->rateLimiter->isAllowed($clientIp)) {
            $this->messageManager->addErrorMessage(
                (string)__('Too many requests. Please wait before trying again.')
            );
            return $this->resultRedirectFactory->create()->setPath('customer/account/login');
        }

        $params = $this->getRequest()->getParams();

        // 2. provider_id is required.
        $providerId = isset($params['provider_id']) ? (int) $params['provider_id'] : 0;
        if ($providerId <= 0) {
            $this->messageManager->addErrorMessage(
                (string)__('Invalid provider. Please contact the administrator.')
            );
            return $this->resultRedirectFactory->create()->setPath('customer/account/login');
        }

        // 3. Set provider context so all getStoreConfig() calls resolve correctly.
        $this->oauthUtility->setActiveProviderId($providerId);

        // 4. Load provider row and validate it is configured, active, and has IdP-initiated enabled.
        $clientDetails = $this->oauthUtility->getClientDetailsById($providerId);
        if ($clientDetails === null || $clientDetails === []) {
            $this->messageManager->addErrorMessage(
                (string)__('Provider not found. Please contact the administrator.')
            );
            return $this->resultRedirectFactory->create()->setPath('customer/account/login');
        }

        // getClientDetailsById() does NOT filter on is_active — check explicitly.
        if (empty($clientDetails['is_active'])) {
            $this->messageManager->addErrorMessage(
                (string)__('This SSO provider is not active.')
            );
            return $this->resultRedirectFactory->create()->setPath('customer/account/login');
        }

        if (empty($clientDetails['idp_initiated_enabled'])) {
            $this->messageManager->addErrorMessage(
                (string)__('IdP-initiated login is not enabled for this provider.')
            );
            return $this->resultRedirectFactory->create()->setPath('customer/account/login');
        }

        if (empty($clientDetails['authorize_endpoint'])) {
            $this->messageManager->addErrorMessage(
                (string)__('Authorization endpoint is not configured. Please contact the administrator.')
            );
            return $this->resultRedirectFactory->create()->setPath('customer/account/login');
        }

        // 5. Validate the optional relay_state (same-origin; defaults to '/').
        $rawRelayState = isset($params['relay_state']) ? (string) $params['relay_state'] : '/';
        $relayState    = $this->securityHelper->validateRedirectUrl($rawRelayState, '/');

        // 6. Determine login type (H-02 clamping, two-level fallback).
        // URL param takes precedence — allows separate IdP tiles for admin vs customer
        // on login_type=both providers (?login_type=admin / ?login_type=customer).
        // Falls back to provider row when not supplied. Any value other than 'admin'
        // is coerced to 'customer' to prevent injection of arbitrary values.
        $loginTypeParam    = isset($params['login_type']) ? (string) $params['login_type'] : null;
        $providerLoginType = (string) ($clientDetails['login_type'] ?? OAuthConstants::LOGIN_TYPE_CUSTOMER);
        if ($loginTypeParam !== null) {
            $loginType = ($loginTypeParam === OAuthConstants::LOGIN_TYPE_ADMIN)
                ? OAuthConstants::LOGIN_TYPE_ADMIN
                : OAuthConstants::LOGIN_TYPE_CUSTOMER;
        } else {
            $loginType = ($providerLoginType === OAuthConstants::LOGIN_TYPE_ADMIN)
                ? OAuthConstants::LOGIN_TYPE_ADMIN
                : OAuthConstants::LOGIN_TYPE_CUSTOMER;
        }

        // 7. Generate CSRF state token and OIDC nonce (identical to SP-initiated flow).
        $currentSessionId = $this->sessionManager->getSessionId();
        $stateToken       = $this->securityHelper->createStateToken($currentSessionId);
        $oidcNonce        = bin2hex(random_bytes(16));
        $this->securityHelper->storeOidcNonce($stateToken, $oidcNonce);

        // 8. Encode relay state envelope (same format as SendAuthorizationRequest).
        $appName      = (string) ($clientDetails['app_name'] ?? '');
        $relayState   = $this->securityHelper->encodeRelayState(
            $relayState,
            $currentSessionId,
            $appName,
            $loginType,
            $stateToken,
            $providerId
        );
        $this->oauthUtility->customlog("IdpInitiatedLogin: encodedRelayState: " . $relayState);

        // 9. PKCE (RFC 7636) — generate verifier when provider has PKCE configured.
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
            // PHP sessions are lost during the OAuth redirect cycle on multi-server deployments.
            $pkceNonce = $this->securityHelper->storePkceVerifier($codeVerifier, $providerId);
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
                "IdpInitiatedLogin: PKCE {$pkceFlow} enabled — challenge generated, verifier stored in cache"
            );
        }

        // 10. Build extra params: nonce is required; login_hint is optional passthrough.
        $extraParams = ['nonce' => $oidcNonce];
        if (!empty($params['login_hint'])) {
            $extraParams['login_hint'] = (string) $params['login_hint'];
        }

        // 11. Build authorization request and redirect to IdP.
        $authorizationRequest = (new AuthorizationRequest(
            $clientDetails['clientID'],
            $clientDetails['scope'],
            $clientDetails['authorize_endpoint'],
            OAuthConstants::CODE,
            $this->oauthUtility->getCallBackUrl(),
            $relayState,
            $extraParams,
            $codeChallenge,
            $codeChallengeMethod
        ))->build();

        $this->oauthUtility->customlog(
            "IdpInitiatedLogin: Authorization Request: " . $authorizationRequest
        );

        return $this->sendHTTPRedirectRequest(
            $authorizationRequest,
            $clientDetails['authorize_endpoint'],
            $relayState,
            $extraParams
        );
    }
}
