<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Actions;

use Magento\Framework\App\Action\Context;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Integration\Model\Oauth\TokenFactory;
use M2Oidc\OAuth\Helper\OAuthSecurityHelper;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Security\OidcRateLimiter;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Model\CustomerFactory;

/**
 * Headless / PWA OIDC Callback Controller (FEAT-09)
 *
 * Called after CustomerLoginAction when headless_mode is enabled.
 * Validates the one-time nonce from the oidc_headless_nonce cookie,
 * issues a Magento customer token, and returns an HTML page that
 * posts the token to the opener window via window.postMessage then
 * closes the popup.
 *
 * Security:
 * - Nonce is single-use (atomic cache read-and-delete).
 * - postMessage target is restricted to the store's base URL origin.
 * - No session cookie is created; the token is the sole credential.
 *
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 */
class HeadlessOidcCallback extends BaseAction
{
    /** @var CookieManagerInterface */
    private readonly CookieManagerInterface $cookieManager;

    /** @var CookieMetadataFactory */
    private readonly CookieMetadataFactory $cookieMetadataFactory;

    /** @var OAuthSecurityHelper */
    private readonly OAuthSecurityHelper $securityHelper;

    /** @var CustomerRepositoryInterface */
    private readonly CustomerRepositoryInterface $customerRepository;

    /** @var CustomerFactory */
    private readonly CustomerFactory $customerFactory;

    /** @var StoreManagerInterface */
    private readonly StoreManagerInterface $storeManager;

    /** @var RawFactory */
    private readonly RawFactory $rawResultFactory;

    /** @var TokenFactory */
    private readonly TokenFactory $tokenFactory;

    /** @var \Magento\Framework\Escaper */
    private readonly \Magento\Framework\Escaper $escaper;

    /** @var OidcRateLimiter */
    private readonly OidcRateLimiter $rateLimiter;

    /**
     * @param Context                     $context
     * @param OAuthUtility                $oauthUtility
     * @param CookieManagerInterface      $cookieManager
     * @param CookieMetadataFactory       $cookieMetadataFactory
     * @param OAuthSecurityHelper         $securityHelper
     * @param CustomerRepositoryInterface $customerRepository
     * @param CustomerFactory             $customerFactory
     * @param StoreManagerInterface       $storeManager
     * @param RawFactory                  $rawResultFactory
     * @param TokenFactory                $tokenFactory
     * @param \Magento\Framework\Escaper  $escaper
     * @param OidcRateLimiter             $rateLimiter
     */
    public function __construct(
        Context                       $context,
        OAuthUtility                  $oauthUtility,
        CookieManagerInterface        $cookieManager,
        CookieMetadataFactory         $cookieMetadataFactory,
        OAuthSecurityHelper           $securityHelper,
        CustomerRepositoryInterface   $customerRepository,
        CustomerFactory               $customerFactory,
        StoreManagerInterface         $storeManager,
        RawFactory                    $rawResultFactory,
        TokenFactory                  $tokenFactory,
        \Magento\Framework\Escaper    $escaper,
        OidcRateLimiter               $rateLimiter
    ) {
        $this->cookieManager         = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->securityHelper        = $securityHelper;
        $this->customerRepository    = $customerRepository;
        $this->customerFactory       = $customerFactory;
        $this->storeManager          = $storeManager;
        $this->rawResultFactory      = $rawResultFactory;
        $this->tokenFactory          = $tokenFactory;
        $this->escaper               = $escaper;
        $this->rateLimiter           = $rateLimiter;
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Execute headless callback: validate nonce, issue token, return postMessage page.
     */
    #[\Override]
    public function execute(): \Magento\Framework\Controller\ResultInterface
    {
        $this->oauthUtility->customlog("HeadlessOidcCallback: Starting headless token flow");

        // Rate limiting — prevent nonce brute-force
        $request = $this->getRequest();
        $clientIp = ($request instanceof \Magento\Framework\App\Request\Http)
            ? (string) $request->getClientIp()
            : '';
        if (!$this->rateLimiter->isAllowed($clientIp)) {
            $this->oauthUtility->customlog("HeadlessOidcCallback: Rate limit exceeded for IP: " . $clientIp);
            return $this->buildErrorPage('Too many requests. Please try again later.');
        }

        // Read and delete nonce cookie
        $nonce = $this->cookieManager->getCookie('oidc_headless_nonce');
        if ($nonce !== null) {
            try {
                $meta = $this->cookieMetadataFactory->createCookieMetadata()->setPath('/');
                $this->cookieManager->deleteCookie('oidc_headless_nonce', $meta);
            } catch (\Exception $e) {
                $this->oauthUtility->customlog(
                    "HeadlessOidcCallback: Error deleting nonce cookie: " . $e->getMessage()
                );
            }
        }

        if (empty($nonce)) {
            $this->oauthUtility->customlog("HeadlessOidcCallback: ERROR - missing nonce cookie");
            return $this->buildErrorPage('Authentication failed. Please try again.');
        }

        // Redeem nonce (atomic read-and-delete via cache)
        $nonceData = $this->securityHelper->redeemCustomerLoginNonce($nonce);
        if ($nonceData === null) {
            $this->oauthUtility->customlog("HeadlessOidcCallback: ERROR - invalid or expired nonce");
            return $this->buildErrorPage('Authentication session expired. Please try again.');
        }

        // Guard: nonce must have been created for headless flow
        if (empty($nonceData['headless'])) {
            $this->oauthUtility->customlog("HeadlessOidcCallback: ERROR - nonce was not issued for headless flow");
            return $this->buildErrorPage('Invalid authentication flow. Please try again.');
        }

        $email      = $nonceData['email'];
        $relayState = $nonceData['relayState'];
        $this->oauthUtility->customlog("HeadlessOidcCallback: Email from nonce: " . $email);

        // Load customer
        try {
            $customerData = $this->customerRepository->get($email);
            $customerId   = (int) $customerData->getId();
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->oauthUtility->customlog(
                "HeadlessOidcCallback: ERROR - customer not found for email: " . $email
            );
            return $this->buildErrorPage('Authentication failed. Please try again.');
        }

        // Enforce website context
        /** @phpstan-ignore-next-line */
        $customerModel = $this->customerFactory->create()->load($customerId);
        $websiteId     = $this->storeManager->getStore()->getWebsiteId();
        if ((int) $customerModel->getWebsiteId() !== (int) $websiteId) {
            $this->oauthUtility->customlog(
                "HeadlessOidcCallback: cross-website login blocked for customer " . $customerId
            );
            return $this->buildErrorPage('Authentication failed: This account is not registered on this website.');
        }

        // Issue Magento customer token (no password needed — auth done at IdP)
        try {
            $token = $this->tokenFactory->create()->createCustomerToken($customerId);
            $tokenValue = $token->getToken();
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                "HeadlessOidcCallback: ERROR - token issuance failed: " . $e->getMessage()
            );
            return $this->buildErrorPage('Authentication failed. Unable to issue token.');
        }

        $this->oauthUtility->customlog(
            "HeadlessOidcCallback: Token issued for customer ID " . $customerId
        );

        // Determine allowed postMessage origin from store base URL
        // Warn if store base URL is not HTTPS (postMessage will fail cross-origin)
        $baseUrl = rtrim($this->oauthUtility->getBaseUrl(), '/');
        if (!str_starts_with($baseUrl, 'https://')) {
            $this->oauthUtility->customlog(
                'HeadlessOidcCallback: WARNING — store base URL is not HTTPS: ' . $baseUrl
            );
        }
        $origin = $this->resolveStoreOrigin();

        // Determine the relay state URL to pass to the opener
        $safeRelayState = (in_array($relayState, ['', '0', '/'], true))
            ? $baseUrl . '/customer/account'
            : $this->securityHelper->validateRedirectUrl($relayState, $baseUrl . '/customer/account');

        return $this->buildTokenPage($tokenValue, $origin, $safeRelayState);
    }

    /**
     * Build an HTML page that posts the token to the opener window and closes.
     *
     * @param string $token        Magento customer bearer token
     * @param string $targetOrigin Allowed postMessage origin (same-origin)
     * @param string $relayState   URL the opener should navigate to after login
     */
    private function buildTokenPage(
        string $token,
        string $targetOrigin,
        string $relayState
    ): \Magento\Framework\Controller\ResultInterface {
        $payload = json_encode([
            'status'    => 'ok',
            'token'     => $token,
            'relayState' => $relayState,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Escape origin for JS string literal (origin is constructed from parse_url — safe,
        // but we sanitize further to avoid any injection from unexpected URL formats).
        $safeOrigin  = $this->escaper->escapeHtmlAttr($targetOrigin);

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Completing login…</title></head>
<body>
<script>
(function () {
    var payload  = {$payload};
    var target   = '{$safeOrigin}';
    if (window.opener && typeof window.opener.postMessage === 'function') {
        window.opener.postMessage(payload, target);
    }
    window.close();
})();
</script>
<p>Login complete. You may close this window.</p>
</body>
</html>
HTML;

        /** @var \Magento\Framework\Controller\Result\Raw $result */
        $result = $this->rawResultFactory->create();
        $result->setHeader('Content-Type', 'text/html; charset=utf-8');
        $result->setContents($html);
        return $result;
    }

    /**
     * Build an error HTML page that posts an error status to the opener window.
     *
     * The error postMessage target is restricted to the store origin —
     * the same origin computation used by the success page — instead of the
     * '*' wildcard, so error details never leak to a foreign opener.
     *
     * @param string $message Human-readable error message
     */
    private function buildErrorPage(string $message): \Magento\Framework\Controller\ResultInterface
    {
        $payload = json_encode([
            'status' => 'error',
            'error'  => $message,
        ]);

        // Escape origin for JS string literal (same sanitation as buildTokenPage()).
        $safeOrigin = $this->escaper->escapeHtmlAttr($this->resolveStoreOrigin());

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Login error</title></head>
<body>
<script>
(function () {
    var payload = {$payload};
    var target  = '{$safeOrigin}';
    if (window.opener && typeof window.opener.postMessage === 'function') {
        window.opener.postMessage(payload, target);
    }
    window.close();
})();
</script>
<p>Login failed. You may close this window.</p>
</body>
</html>
HTML;

        /** @var \Magento\Framework\Controller\Result\Raw $result */
        $result = $this->rawResultFactory->create();
        $result->setHeader('Content-Type', 'text/html; charset=utf-8');
        $result->setContents($html);
        return $result;
    }

    /**
     * Compute the allowed postMessage target origin from the store base URL.
     *
     * Shared by the success (buildTokenPage) and error (buildErrorPage) pages
     * so both restrict postMessage to the store's own origin.
     *
     * @return string Origin in scheme://host[:port] form
     */
    private function resolveStoreOrigin(): string
    {
        $baseUrl = rtrim($this->oauthUtility->getBaseUrl(), '/');
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $parsedOrigin = parse_url($baseUrl);
        if (!is_array($parsedOrigin)) {
            $parsedOrigin = [];
        }
        $origin = ($parsedOrigin['scheme'] ?? 'https')
            . '://'
            . ($parsedOrigin['host'] ?? '');
        if (isset($parsedOrigin['port']) && $parsedOrigin['port'] !== 0) {
            $origin .= ':' . $parsedOrigin['port'];
        }
        return $origin;
    }
}
