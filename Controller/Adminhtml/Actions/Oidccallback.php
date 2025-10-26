<?php

namespace MiniOrange\OAuth\Controller\Adminhtml\Actions;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * OIDC Callback Controller for Admin Login
 * 
 * This controller handles admin user login after successful OIDC authentication.
 * It does NOT extend Backend\App\Action to avoid authentication middleware,
 * allowing unauthenticated access for the login process itself.
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

    /**
     * Execute admin login after OIDC authentication
     * 
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $email = $this->request->getParam('email');
        
        if (empty($email)) {
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('admin');
            return $resultRedirect;
        }

        try {
            // Find admin user by email
            $userCollection = $this->userFactory->create()->getCollection()
                ->addFieldToFilter('email', $email);

            if ($userCollection->getSize() === 0) {
                throw new \Exception('Admin user not found: ' . $email);
            }

            $user = $userCollection->getFirstItem();
            
            // Verify user is active
            if (!$user->getIsActive()) {
                throw new \Exception('Admin user is inactive');
            }

            // Perform login
            $this->authSession->setUser($user);
            $this->authSession->processLogin();
            $this->authSession->refreshAcl();

            // Redirect to admin dashboard
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('admin/dashboard');
            return $resultRedirect;

        } catch (\Exception $e) {
            // Redirect to admin login on any error
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            $resultRedirect->setPath('admin');
            return $resultRedirect;
        }
    }
}
