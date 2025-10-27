<?php
namespace MiniOrange\OAuth\Controller\Adminhtml\Actions;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;

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
    protected $authSession;
    protected $resultFactory;
    protected $request;
    protected $oauthUtility;
    protected $messageManager;
    protected $url;

    public function __construct(
        \Magento\User\Model\UserFactory $userFactory,
        \Magento\Backend\Model\Auth\Session $authSession,
        ResultFactory $resultFactory,
        RequestInterface $request,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        ManagerInterface $messageManager,
        UrlInterface $url
    ) {
        $this->userFactory = $userFactory;
        $this->authSession = $authSession;
        $this->resultFactory = $resultFactory;
        $this->request = $request;
        $this->oauthUtility = $oauthUtility;
        $this->messageManager = $messageManager;
        $this->url = $url;
    }

    /**
     * Execute admin login after OIDC authentication
     * 
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $email = $this->request->getParam('email');
        
        $this->oauthUtility->customlog("OIDC Admin Callback: Starting authentication");
        $this->oauthUtility->customlog("Email parameter: " . ($email ?? 'NULL'));
        
        if (empty($email)) {
            $this->oauthUtility->customlog("ERROR: Email parameter is empty");
            return $this->redirectToLoginWithError(
                __('Authentifizierung fehlgeschlagen: Keine E-Mail-Adresse vom OIDC-Provider erhalten.')
            );
        }

        try {
            $this->oauthUtility->customlog("Searching for admin user with email: " . $email);
            
            // Find admin user by email
            $userCollection = $this->userFactory->create()->getCollection()
                ->addFieldToFilter('email', $email);

            if ($userCollection->getSize() === 0) {
                $this->oauthUtility->customlog("ERROR: Admin user not found for email: " . $email);
                
                return $this->redirectToLoginWithError(
                    __(
                        'Admin-Zugang verweigert: Für die E-Mail-Adresse "%1" ist kein Administrator-Konto in Magento hinterlegt. Bitte wenden Sie sich an Ihren Systemadministrator.',
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
                        'Admin-Zugang verweigert: Das Administrator-Konto für "%1" ist deaktiviert. Bitte kontaktieren Sie Ihren Systemadministrator zur Aktivierung.',
                        $email
                    )
                );
            }

            // Perform login
            $this->oauthUtility->customlog("Performing admin login for user ID: " . $user->getId());
            $this->authSession->setUser($user);
            $this->authSession->processLogin();
            $this->authSession->refreshAcl();

            // Verify login success
            $isLoggedIn = $this->authSession->isLoggedIn();
            $sessionUserId = $this->authSession->getUser() ? $this->authSession->getUser()->getId() : 'NULL';
            
            $this->oauthUtility->customlog("Login result - isLoggedIn: " . ($isLoggedIn ? 'YES' : 'NO') . ", Session User ID: " . $sessionUserId);

            if ($isLoggedIn) {
                $this->oauthUtility->customlog("SUCCESS: Admin login successful, redirecting to dashboard");
                
                $this->messageManager->addSuccessMessage(
                    __('Willkommen zurück, %1!', $user->getFirstname() ?: $user->getUsername())
                );
            } else {
                $this->oauthUtility->customlog("WARNING: Login processed but isLoggedIn() returned false");
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
                    'Die Anmeldung über Authelia ist fehlgeschlagen. Bitte versuchen Sie es erneut oder wenden Sie sich an Ihren Administrator.'
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
                'oidc_error' => base64_encode((string)$message)
            ]
        ]);
        
        $resultRedirect->setUrl($loginUrl);
        return $resultRedirect;
    }
}
