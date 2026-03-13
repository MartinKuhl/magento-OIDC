<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Controller\Adminhtml\Actions;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use MiniOrange\OAuth\Helper\OAuthSecurityHelper;

/**
 * OIDC Callback Controller for Admin Login
 *
 * Handles admin user login after successful OIDC authentication. This
 * controller intentionally avoids extending Backend\App\Action so the
 * login endpoint can be accessed without prior authentication.
 *
 * Logging goes through `OAuthUtility` and is written to
 * `var/log/mo_oauth.log` when enabled.
 *
 * Security: only authenticate users that exist in `admin_user` and are active.
 *
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 */
class Oidccallback implements ActionInterface, HttpGetActionInterface
{
    /** @var \Magento\Backend\Model\Auth */
    protected \Magento\Backend\Model\Auth $auth;

    /** @var \Magento\Framework\Controller\ResultFactory */
    protected \Magento\Framework\Controller\ResultFactory $resultFactory;

    /** @var \Magento\Framework\App\RequestInterface */
    protected \Magento\Framework\App\RequestInterface $request;

    /** @var \MiniOrange\OAuth\Helper\OAuthUtility */
    protected \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility;

    /** @var \Magento\Framework\Message\ManagerInterface */
    protected \Magento\Framework\Message\ManagerInterface $messageManager;

    /** @var \Magento\Framework\UrlInterface */
    protected \Magento\Framework\UrlInterface $url;

    /** @var \Magento\Framework\Stdlib\CookieManagerInterface */
    protected \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager;

    /** @var \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory */
    protected \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory;

    /** @var BackendUrlInterface */
    protected BackendUrlInterface $backendUrl;

    /** @var \MiniOrange\OAuth\Helper\OAuthSecurityHelper */
    private readonly \MiniOrange\OAuth\Helper\OAuthSecurityHelper $securityHelper;

    /** @var \Magento\Framework\App\Config\ScopeConfigInterface */
    private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig;

    /**
     * Initialize OIDC callback action.
     *
     * @param \Magento\Backend\Model\Auth                                     $auth
     * @param ResultFactory                                                   $resultFactory
     * @param RequestInterface                                                $request
     * @param \MiniOrange\OAuth\Helper\OAuthUtility                           $oauthUtility
     * @param ManagerInterface                                                $messageManager
     * @param UrlInterface                                                    $url
     * @param CookieManagerInterface                                          $cookieManager
     * @param CookieMetadataFactory                                           $cookieMetadataFactory
     * @param BackendUrlInterface                                             $backendUrl
     * @param OAuthSecurityHelper                                             $securityHelper
     * @param \Magento\Framework\App\Config\ScopeConfigInterface              $scopeConfig
     */
    public function __construct(
        \Magento\Backend\Model\Auth $auth,
        ResultFactory $resultFactory,
        RequestInterface $request,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        ManagerInterface $messageManager,
        UrlInterface $url,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        BackendUrlInterface $backendUrl,
        OAuthSecurityHelper $securityHelper,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->auth = $auth;
        $this->resultFactory = $resultFactory;
        $this->request = $request;
        $this->oauthUtility = $oauthUtility;
        $this->messageManager = $messageManager;
        $this->url = $url;
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->backendUrl = $backendUrl;
        $this->securityHelper = $securityHelper;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Execute admin login after OIDC authentication
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    #[\Override]
    public function execute()
    {
        $nonce = $this->cookieManager->getCookie('oidc_admin_nonce');

        // Delete the cookie immediately (one-time use)
        if ($nonce !== null) {
            $adminPath = '/' . $this->backendUrl->getAreaFrontName();
            $cookieMeta = $this->cookieMetadataFactory->createCookieMetadata()->setPath($adminPath);
            $this->cookieManager->deleteCookie('oidc_admin_nonce', $cookieMeta);
        }

        $this->oauthUtility->customlog("OIDC Admin Callback: Starting authentication");

        if (empty($nonce)) {
            $this->oauthUtility->customlog("ERROR: Nonce cookie is empty or missing");
            return $this->redirectToLoginWithError(
                __('Authentication failed: Invalid or missing authentication token.')
            );
        }

        $email = $this->securityHelper->redeemAdminLoginNonce($nonce);
        if ($email === null) {
            $this->oauthUtility->customlog("ERROR: Nonce is invalid or expired");
            return $this->redirectToLoginWithError(
                __('Authentication failed: Authentication token is invalid or has expired. Please try again.')
            );
        }

        $this->oauthUtility->customlog("Email resolved from nonce: " . $email);

        try {
            // C-01: Generate a single-use ephemeral auth token for this login attempt.
            // The token is stored in cache (TTL 120s) keyed by hash(token) → email.
            // OidcCredentialAdapter::authenticate() will validate and consume it.
            $oidcAuthToken = $this->securityHelper->createOidcAuthToken($email);

            // Perform login via standard Auth orchestrator.
            // OidcCredentialPlugin detects the ephemeral token format and injects
            // OidcCredentialAdapter, which validates the token, checks the user is
            // active, has a role, and fires all standard authentication events.
            $this->oauthUtility->customlog("Performing admin login via Auth::login() for: " . $email);

            try {
                $this->auth->login($email, $oidcAuthToken);

                $this->oauthUtility->customlog("SUCCESS: Auth::login() completed successfully");

                // Verify login success and set OIDC session/cookie data
                if ($this->auth->isLoggedIn()) {

                    // H-04: Mark session as OIDC-authenticated so OidcPasswordExpirationPlugin
                    // (and any other plugin checking this flag) can skip inapplicable checks.
                    /** @psalm-suppress UndefinedInterfaceMethod */
                    // @phpstan-ignore-next-line
                    $this->auth->getAuthStorage()->setData('is_oidc_authenticated', true);

                    // ── Persist id_token in post-login admin session ──
                    $encryptedIdToken = $this->cookieManager->getCookie('oidc_id_token_transport');
                    $providerId = (int) $this->cookieManager->getCookie('oidc_provider_id_transport');

                    if ($encryptedIdToken) {
                        try {
                            $idToken = $this->oauthUtility->getEncryptor()->decrypt($encryptedIdToken);
                            /** @psalm-suppress UndefinedInterfaceMethod */
                            // @phpstan-ignore-next-line
                            $this->auth->getAuthStorage()->setData('oidc_id_token', $idToken);
                            /** @psalm-suppress UndefinedInterfaceMethod */
                            // @phpstan-ignore-next-line
                            $this->auth->getAuthStorage()->setData('oidc_provider_id', $providerId);
                            $this->oauthUtility->customlog(
                                'Oidccallback: id_token persisted in admin session, provider_id=' . $providerId
                            );
                        } catch (\Exception $e) {
                            $this->oauthUtility->customlog(
                                'Oidccallback: Failed to decrypt id_token transport cookie: ' . $e->getMessage()
                            );
                        }

                        // Delete transport cookie immediately
                        $deleteMeta = $this->cookieMetadataFactory
                            ->createPublicCookieMetadata()
                            ->setPath('/');
                        $this->cookieManager->deleteCookie('oidc_id_token_transport', $deleteMeta);
                        $this->cookieManager->deleteCookie('oidc_provider_id_transport', $deleteMeta);
                    }

                    // Set OIDC authentication cookie (persists across session boundary).
                    // Path MUST be '/' so the cookie is readable on all admin sub-paths.
                    // Using $adminPath (e.g. '/admin') caused the cookie to be invisible
                    // on sub-routes where performIdentityCheck() is triggered.
                    $adminSessionLifetime = (int) $this->scopeConfig->getValue(
                        'admin/security/session_lifetime'
                    ) ?: 3600;
                    $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata();
                    $metadata->setDuration($adminSessionLifetime);
                    $metadata->setPath('/');
                    $metadata->setHttpOnly(true);
                    $metadata->setSameSite('Lax');
                    $metadata->setSecure($this->request->isSecure());
                    $this->cookieManager->setPublicCookie('oidc_authenticated', '1', $metadata);

                    $this->oauthUtility->customlog(
                        "OIDC cookie set with path '/' and duration " . $adminSessionLifetime . "s"
                    );

                    // H-03: Use post-login auth storage to get user for the welcome message.
                    // The user was loaded and validated inside OidcCredentialAdapter::authenticate().
                    /** @psalm-suppress UndefinedInterfaceMethod */
                    // @phpstan-ignore-next-line
                    $loggedInUser = $this->auth->getAuthStorage()->getUser();
                    $displayName = ($loggedInUser !== null)
                        ? ($loggedInUser->getFirstname() ?: $loggedInUser->getUsername())
                        : $email;

                    $this->messageManager->addSuccessMessage(
                        (string) __('Welcome back, %1!', $displayName)
                    );
                } else {
                    $this->oauthUtility->customlog("WARNING: Login processed but isLoggedIn() returned false");
                }

            } catch (\Magento\Framework\Exception\AuthenticationException $e) {
                $this->oauthUtility->customlog(
                    "ERROR: Authentication failed: " . $e->getMessage()
                );
                return $this->redirectToLoginWithError(__($e->getMessage()));
            }

            // Redirect to admin dashboard
            /**
 * @var \Magento\Framework\Controller\Result\Redirect $resultRedirect
*/
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('admin/dashboard');
            return $resultRedirect;

        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                "EXCEPTION in OIDC admin callback: " . $e->getMessage()
            );
            $trace = $e->getTraceAsString();
            $this->oauthUtility->customlog("Stack trace: " . $trace);

            return $this->redirectToLoginWithError(
                __(
                    'OIDC authentication failed. Please try again or contact your administrator.'
                )
            );
        }
    }

    /**
     * Redirect to admin login page with error message in URL
     *
     * Messages in URL are displayed on the login page via JavaScript or template
     *
     * @param  \Magento\Framework\Phrase $message
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    private function redirectToLoginWithError($message)
    {
        /**
 * @var \Magento\Framework\Controller\Result\Redirect $resultRedirect
*/
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        $loginUrl = $this->url->getUrl(
            'admin',
            ['_query' => ['oidc_error' => urlencode(base64_encode((string) $message))]]
        );

        $resultRedirect->setUrl($loginUrl);
        return $resultRedirect;
    }
}
