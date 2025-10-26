<?php
namespace MiniOrange\OAuth\Controller\Adminhtml\Actions;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;

class OidcCallback extends Action implements HttpGetActionInterface
{
    protected $userFactory;
    protected $authSession;
    protected $resultRedirectFactory;

    public function __construct(
        Context $context,
        \Magento\User\Model\UserFactory $userFactory,
        \Magento\Backend\Model\Auth\Session $authSession,
        RedirectFactory $resultRedirectFactory
    ) {
        parent::__construct($context);
        $this->userFactory = $userFactory;
        $this->authSession = $authSession;
        $this->resultRedirectFactory = $resultRedirectFactory;
    }

    public function execute()
    {
        // WICHTIG: Logging am Anfang
        error_log("=== OidcCallback::execute() START ===");
        
        $email = $this->getRequest()->getParam('email');
        error_log("Email parameter: " . ($email ?? 'NULL'));
        
        if (empty($email)) {
            error_log("ERROR: Email parameter is empty!");
            $this->messageManager->addErrorMessage(__('Email parameter missing'));
            
            $resultRedirect = $this->resultRedirectFactory->create();
            return $resultRedirect->setPath('admin');
        }

        try {
            error_log("Searching for user with email: " . $email);
            
            // User finden
            $userCollection = $this->userFactory->create()->getCollection()
                ->addFieldToFilter('email', $email);

            if ($userCollection->getSize() === 0) {
                error_log("ERROR: User not found!");
                throw new \Exception('User not found: ' . $email);
            }

            $user = $userCollection->getFirstItem();
            error_log("User found - ID: " . $user->getId());
            
            if (!$user->getIsActive()) {
                error_log("ERROR: User is inactive!");
                throw new \Exception('User is inactive');
            }

            // Login durchführen
            error_log("Performing login...");
            $this->authSession->setUser($user);
            $this->authSession->processLogin();
            $this->authSession->refreshAcl();
            
            error_log("Login successful - isLoggedIn: " . ($this->authSession->isLoggedIn() ? 'YES' : 'NO'));
            error_log("Session User ID: " . ($this->authSession->getUser() ? $this->authSession->getUser()->getId() : 'NULL'));

            // Zum Dashboard weiterleiten
            error_log("Redirecting to admin/dashboard");
            
            $resultRedirect = $this->resultRedirectFactory->create();
            return $resultRedirect->setPath('admin/dashboard');

        } catch (\Exception $e) {
            error_log("EXCEPTION in OidcCallback: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            $this->messageManager->addErrorMessage(__('Login failed: %1', $e->getMessage()));
            
            $resultRedirect = $this->resultRedirectFactory->create();
            return $resultRedirect->setPath('admin');
        }
    }

    /**
     * Check if admin has permissions to access this controller
     * 
     * @return bool
     */
    protected function _isAllowed()
    {
        // WICHTIG: TRUE zurückgeben, da dies ein Pre-Login-Endpoint ist
        error_log("=== OidcCallback::_isAllowed() called ===");
        return true;
    }
}
