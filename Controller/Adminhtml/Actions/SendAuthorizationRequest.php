<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Adminhtml\Actions;

use M2Oidc\OAuth\Helper\OAuth\AuthorizationRequest;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthSecurityHelper;
use M2Oidc\OAuth\Controller\Actions\BaseAction;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * Admin: Send authorization request to the configured OIDC provider.
 *
 * Builds an authorization URL and redirects the admin user to the
 * provider's authorization endpoint. Uses `AuthorizationRequest` helper
 * to construct the final URL.
 *
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 */
class SendAuthorizationRequest extends BaseAction
{
    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $urlBuilder;

    /** @var \M2Oidc\OAuth\Helper\OAuthSecurityHelper */
    private readonly \M2Oidc\OAuth\Helper\OAuthSecurityHelper $securityHelper;

    /** @var \Magento\Framework\Session\SessionManagerInterface */
    private readonly \Magento\Framework\Session\SessionManagerInterface $sessionManager;

    /** @var CookieManagerInterface */
    private readonly CookieManagerInterface $cookieManager;

    /** @var CookieMetadataFactory */
    private readonly CookieMetadataFactory $cookieMetadataFactory;

    /**
     * Initialize admin send authorization request action.
     *
     * @param \Magento\Backend\App\Action\Context                $context
     * @param \M2Oidc\OAuth\Helper\OAuthUtility                  $oauthUtility
     * @param OAuthSecurityHelper                                $securityHelper
     * @param \Magento\Framework\Session\SessionManagerInterface $sessionManager
     * @param CookieManagerInterface                             $cookieManager
     * @param CookieMetadataFactory                              $cookieMetadataFactory
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility,
        OAuthSecurityHelper $securityHelper,
        \Magento\Framework\Session\SessionManagerInterface $sessionManager,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory
    ) {
        parent::__construct($context, $oauthUtility);
        $this->urlBuilder = $context->getUrl();
        $this->securityHelper = $securityHelper;
        $this->sessionManager = $sessionManager;
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
    }

    /**
     * Execute the admin authorization request.
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    #[\Override]
    public function execute()
    {

        $this->oauthUtility->customlog("SendAuthorizationRequest: execute");

        $params = $this->getRequest()->getParams();
        $this->oauthUtility->customlog(
            "SendAuthorizationRequest: params: " . (json_encode($params, JSON_UNESCAPED_SLASHES) ?: '{}')
        );

        $isFromPopup = isset($params['from_popup']) && $params['from_popup'] == '1';

        $currentSessionId = $this->sessionManager->getSessionId();

        // Prefer numeric provider_id (set by multi-provider SSO buttons), fall back to app_name
        $providerId = isset($params['provider_id']) ? (int) $params['provider_id'] : 0;
        if ($providerId > 0) {
            $this->oauthUtility->setActiveProviderId($providerId);
        }

        $app_name = isset($params['app_name'])
            ? $params['app_name']
            : $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);

        // Resolve app_name from provider row when only provider_id was given
        $earlyProviderDetails = null;
        if (empty($app_name) && $providerId > 0) {
            $earlyProviderDetails = $this->oauthUtility->getClientDetailsById($providerId);
            $app_name = $earlyProviderDetails['app_name'] ?? '';
        }

        $this->oauthUtility->customlog(
            "SendAuthorizationRequest: Using app_name: " . $app_name . " provider_id: " . $providerId
        );

        $clientDetails = null;

        if (!$app_name) {
            $backendLoginUrl = $this->urlBuilder->getUrl('adminhtml/auth/login');
            $this->messageManager->addErrorMessage(
                'App name not found. Please contact the administrator for assistance.'
            );
            return $this->resultRedirectFactory->create()->setUrl($backendLoginUrl);
        }
        $this->oauthUtility->setSessionData(OAuthConstants::APP_NAME, $app_name);

        // Load provider details: prefer by ID to avoid ambiguity when multiple providers share a name
        $clientDetails = $providerId > 0
            ? ($earlyProviderDetails ?? $this->oauthUtility->getClientDetailsById($providerId))
            : $this->oauthUtility->getClientDetailsByAppName($app_name);
        if ($clientDetails === null || $clientDetails === []) {
            $backendLoginUrl = $this->urlBuilder->getUrl('adminhtml/auth/login');
            $msg = 'Provided App name is not configured. Please contact the administrator for assistance.';
            $this->messageManager->addErrorMessage($msg);
            return $this->resultRedirectFactory->create()->setUrl($backendLoginUrl);
        }

        if (!$clientDetails["authorize_endpoint"]) {
            $this->messageManager->addErrorMessage(
                (string)__('Authorization endpoint is not configured. Please contact the administrator.')
            );
            $backendUrl = $this->urlBuilder->getUrl('adminhtml/auth/login');
            return $this->resultRedirectFactory->create()->setUrl($backendUrl);
        }

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

            // Store verifier in cache and pass nonce via cookie (mirrors customer flow — F2).
            // A cookie bridges the adminhtml→frontend area switch that happens when the IdP
            // redirects back to ReadAuthorizationResponse (frontend area).
            $pkceNonce = $this->securityHelper->storePkceVerifier($codeVerifier, (int) $clientDetails['id']);
            $metadata  = $this->cookieMetadataFactory
                ->createPublicCookieMetadata()
                ->setPath('/m2oidc/')
                ->setHttpOnly(true)
                ->setDuration(600);
            $this->cookieManager->setPublicCookie('oidc_admin_pkce_nonce', $pkceNonce, $metadata);
            $this->oauthUtility->customlog(
                "SendAuthorizationRequest (admin): PKCE {$pkceFlow} enabled"
                . " — challenge generated, verifier stored in cache (nonce cookie set)"
            );
        }

        // Build relayState with login type for admin, with redirect validation
        $rawRelayState = $isFromPopup
            ? $this->oauthUtility->getBaseUrl() . "checkout"
            : (isset($params['relayState']) ? $params['relayState'] : '/');
        $relayState = $this->securityHelper->validateRedirectUrl($rawRelayState, '/');
        $stateToken = $this->securityHelper->createStateToken($currentSessionId);

        $providerId = (int) ($clientDetails['id'] ?? 0);

        $relayState = $this->securityHelper->encodeRelayState(
            $relayState,
            $currentSessionId,
            $app_name,
            OAuthConstants::LOGIN_TYPE_ADMIN,
            $stateToken,
            $providerId
        );

        // Generate and store a per-flow nonce for id_token replay protection.
        // The nonce is added to the authorization request params so the IdP includes
        // it in the id_token. It is consumed in ReadAuthorizationResponse and passed
        // to JwtVerifier::verifyAndDecode() for validation.
        $oidcNonce = bin2hex(random_bytes(16));
        $this->securityHelper->storeOidcNonce($stateToken, $oidcNonce);
        $params['nonce'] = $oidcNonce;

        $isTest = (
            ($this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST) == true)
            || (isset($params['option']) && $params['option'] === OAuthConstants::TEST_CONFIG_OPT)
        );

        // Test flow: rebuild combined state with test results URL as the relayState segment
        if ($isTest) {
            $testKey = bin2hex(random_bytes(16));
            $baseUrl = $this->oauthUtility->getBaseUrl();
            $testRelayState = $baseUrl . 'm2oidc/actions/showTestResults/key/' . $testKey . '/';
            $this->oauthUtility->customlog(
                "SendAuthorizationRequest: Test-Flow, setting relayState to: " . $testRelayState
            );
            $relayState = $this->securityHelper->encodeRelayState(
                $testRelayState,
                $currentSessionId,
                $app_name,
                OAuthConstants::LOGIN_TYPE_ADMIN,
                $stateToken,
                $providerId
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
            $params,
            $codeChallenge,
            $codeChallengeMethod
        ))->build();

        $this->oauthUtility->customlog(
            "SendAuthorizationRequest:  Authorization Request: " . $authorizationRequest
        );

        return $this->sendHTTPRedirectRequest($authorizationRequest, $authorizeURL, $relayState, $params);
    }
}
