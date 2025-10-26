<?php
namespace MiniOrange\OAuth\Controller\Adminhtml\Actions;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * WICHTIG: Nicht von Backend\App\Action erben!
 * Das würde Admin-Auth erzwingen.
 */
class Oidccallback implements ActionInterface, HttpGetActionInterface
{
    protected $userFactory;
    protected $authSession;
    protected $resultFactory;
    protected $request;

    public function __construct(
        \Magento\User\Model\UserFactory $userFactory,
        \Magento\Backend\Model\Auth\Session $authSession,
        ResultFactory $resultFactory,
        RequestInterface $request
    ) {
        $this->userFactory = $userFactory;
        $this->authSession = $authSession;
        $this->resultFactory = $resultFactory;
        $this->request = $request;
    }

    public function execute()
    {
        error_log("=== OidcCallback::execute() START ===");
        
        $email = $this->request->getParam('email');
        error_log("Email parameter: " . ($email ?? 'NULL'));
        
        if (empty($email)) {
            error_log("ERROR: Email parameter is empty!");
            
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('admin');
            return $resultRedirect;
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
            
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('admin/dashboard');
            return $resultRedirect;

        } catch (\Exception $e) {
            error_log("EXCEPTION in OidcCallback: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('admin');
            return $resultRedirect;
        }
    }
}
