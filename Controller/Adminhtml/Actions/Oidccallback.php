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
 * This controller handles admin user login after successful OIDC authentication.
 * It does NOT extend Backend\App\Action to avoid authentication middleware,
 * allowing unauthenticated access for the login process itself.
 *
 * All logging is done via OAuthUtility to respect the plugin's logging configuration.
 * Logs are written to var/log/mo_oauth.log when logging is enabled.
 *
 * Security: Only authenticates users that exist in the admin_user table
 * and are marked as active.
 *
 * @package MiniOrange\OAuth\Controller\Adminhtml\Actions
 */
class Oidccallback implements ActionInterface, HttpGetActionInterface
{
    protected $userFactory;
    protected $auth;
    protected $resultFactory;
    protected $request;
    protected $oauthUtility;
    protected $messageManager;
    protected $url;
    protected $cookieManager;
    protected $cookieMetadataFactory;
    protected $backendUrl;
    private $securityHelper;

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
        OAuthSecurityHelper $securityHelper
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
    }

    /**
     * Execute admin login after OIDC authentication
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $nonce = $this->request->getParam('nonce');

        $this->oauthUtility->customlog("OIDC Admin Callback: Starting authentication");

        if (empty($nonce)) {
            $this->oauthUtility->customlog("ERROR: Nonce parameter is empty");
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

            // Find admin user by email
            $userCollection = $this->userFactory->create()->getCollection()
                ->addFieldToFilter('email', $email);

            if ($userCollection->getSize() === 0) {
                $this->oauthUtility->customlog("ERROR: Admin user not found for email: " . $email);

                return $this->redirectToLoginWithError(
                    __(
                        'Admin access denied: No administrator account found for email "%1". Please contact your system administrator.',
                        $email
                    )
                );
            }

            $user = $userCollection->getFirstItem();
            $this->oauthUtility->customlog("Admin user found - ID: " . $user->getId() . ", Username: " . $user->getUsername());

            // Verify user is active
            if (!$user->getIsActive()) {
                $this->oauthUtility->customlog("ERROR: Admin user is inactive (ID: " . $user->getId() . ")");

                return $this->redirectToLoginWithError(
                    __(
                        'Admin access denied: The administrator account for "%1" is disabled. Please contact your system administrator.',
                        $email
                    )
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
                    $loggedInUser = $this->auth->getUser();
                    $this->oauthUtility->customlog("Authenticated user ID: " . $loggedInUser->getId());

                    // Set OIDC authentication cookie (persists across session boundary)
                    $adminPath = '/' . $this->backendUrl->getAreaFrontName();
                    $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                        ->setDuration(86400) // 24 hours
                        ->setPath($adminPath)
                        ->setHttpOnly(true)
                        ->setSecure(true);
                    $this->cookieManager->setPublicCookie('oidc_authenticated', '1', $metadata);

                    $this->oauthUtility->customlog("OIDC cookie set for path: " . $adminPath);

                    $this->messageManager->addSuccessMessage(
                        __('Welcome back, %1!', $loggedInUser->getFirstname() ?: $loggedInUser->getUsername())
                    );
                } else {
                    $this->oauthUtility->customlog("WARNING: Login processed but isLoggedIn() returned false");
                }

            } catch (\Magento\Framework\Exception\AuthenticationException $e) {
                $this->oauthUtility->customlog("ERROR: Authentication failed: " . $e->getMessage());
                return $this->redirectToLoginWithError(__($e->getMessage()));
            }

            // Redirect to admin dashboard
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('admin/dashboard');
            return $resultRedirect;

        } catch (\Exception $e) {
            $this->oauthUtility->customlog("EXCEPTION in OIDC admin callback: " . $e->getMessage());
            $this->oauthUtility->customlog("Stack trace: " . $e->getTraceAsString());

            return $this->redirectToLoginWithError(
                __(
                    'OIDC authentication failed. Please try again or contact your administrator.'
                )
            );
        }
    }

    /**
     * Redirect to admin login page with error message in URL
     * Messages in URL are displayed on the login page via JavaScript or template
     *
     * @param \Magento\Framework\Phrase $message
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    private function redirectToLoginWithError($message)
    {
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        // Erstelle Admin-Login-URL mit Fehlerparameter
        $loginUrl = $this->url->getUrl('admin', [
            '_query' => [
                'oidc_error' => base64_encode((string) $message)
            ]
        ]);

        $resultRedirect->setUrl($loginUrl);
        return $resultRedirect;
    }
}
