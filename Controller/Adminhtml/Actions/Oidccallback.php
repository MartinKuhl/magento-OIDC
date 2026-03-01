<?php
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
    /** @var \Magento\User\Model\UserFactory */
    protected \Magento\User\Model\UserFactory $userFactory;

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

    /** @var \Magento\User\Model\ResourceModel\User\CollectionFactory */
    protected \Magento\User\Model\ResourceModel\User\CollectionFactory $userCollectionFactory;

    /**
     * Initialize OIDC callback action.
     *
     * @param \Magento\User\Model\UserFactory                                 $userFactory
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
     * @param \Magento\User\Model\ResourceModel\User\CollectionFactory        $userCollectionFactory
     * @param \Magento\Framework\App\Config\ScopeConfigInterface              $scopeConfig
     */
    public function __construct(
        \Magento\User\Model\UserFactory $userFactory,
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
        \Magento\User\Model\ResourceModel\User\CollectionFactory $userCollectionFactory,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->userFactory = $userFactory;
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
        $this->userCollectionFactory = $userCollectionFactory;
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
            $this->oauthUtility->customlog("Searching for admin user with email: " . $email);

            // Find admin user by email using collection factory (avoid getCollection on model)
            $userCollection = $this->userCollectionFactory->create()
                ->addFieldToFilter('email', $email);

            if ($userCollection->getSize() === 0) {
                $this->oauthUtility->customlog("ERROR: Admin user not found for email: " . $email);

                return $this->redirectToLoginWithError(
                    __(
                        'OIDC authentication failed. Please try again or contact your administrator.'
                    )
                );
            }

            $user = $userCollection->getFirstItem();
            $this->oauthUtility->customlog(
                "Admin user found - ID: " . $user->getId() . ", Username: " . $user->getUsername()
            );

            // Verify user is active
            if (!$user->getIsActive()) {
                $this->oauthUtility->customlog("ERROR: Admin user is inactive (ID: " . $user->getId() . ")");

                return $this->redirectToLoginWithError(
                    __('OIDC authentication failed. Please try again or contact your administrator.')
                );
            }

            // Perform login via standard Auth orchestrator
            $this->oauthUtility->customlog("Performing admin login via Auth::login() for user ID: " . $user->getId());

            try {
                // Use standard Auth::login() with OIDC token marker
                // This triggers the OidcCredentialPlugin and fires all security events
                $this->auth->login($email, 'OIDC_VERIFIED_USER');

                $this->oauthUtility->customlog("SUCCESS: Auth::login() completed successfully");

                // Verify login success and set OIDC cookie
                if ($this->auth->isLoggedIn()) {
                    // Set OIDC authentication cookie (persists across session boundary)
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

                    // Welcome message – Auth::getUser() may return a Proxy/Interceptor
                    // that does not pass instanceof checks against the concrete User class.
                    // Use the $user we already loaded from the collection instead.
                    $this->messageManager->addSuccessMessage(
                        __('Welcome back, %1!', $user->getFirstname() ?: $user->getUsername())
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

        // Create admin login URL with error parameter (URL-encoded, not base64 — read-side uses getParam() directly)
        $loginUrl = $this->url->getUrl('admin', ['_query' => ['oidc_error' => (string) $message]]);

        $resultRedirect->setUrl($loginUrl);
        return $resultRedirect;
    }
}
