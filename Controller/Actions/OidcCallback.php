<?php
namespace MiniOrange\OAuth\Controller\Adminhtml\Actions;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;

class OidcCallback extends Action implements HttpGetActionInterface
{
    protected $userFactory;
    protected $authSession;
    protected $formKey;
    protected $backendUrl;

    public function __construct(
        Context $context,
        \Magento\User\Model\UserFactory $userFactory,
        \Magento\Backend\Model\Auth\Session $authSession,
        \Magento\Framework\Data\Form\FormKey $formKey,
        \Magento\Backend\Model\UrlInterface $backendUrl
    ) {
        parent::__construct($context);
        $this->userFactory = $userFactory;
        $this->authSession = $authSession;
        $this->formKey = $formKey;
        $this->backendUrl = $backendUrl;
    }

    public function execute()
    {
        $email = $this->getRequest()->getParam('email');
        
        if (empty($email)) {
            $this->messageManager->addErrorMessage(__('Email parameter missing'));
            return $this->_redirect('admin');
        }

        try {
            // User finden
            $userCollection = $this->userFactory->create()->getCollection()
                ->addFieldToFilter('email', $email);

            if ($userCollection->getSize() === 0) {
                throw new \Exception('User not found');
            }

            $user = $userCollection->getFirstItem();
            
            if (!$user->getIsActive()) {
                throw new \Exception('User is inactive');
            }

            // Login durchführen
            $this->authSession->setUser($user);
            $this->authSession->processLogin();
            $this->authSession->refreshAcl();

            // Zum Dashboard weiterleiten
            return $this->_redirect('admin/dashboard');

        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Login failed: %1', $e->getMessage()));
            return $this->_redirect('admin');
        }
    }

    protected function _isAllowed()
    {
        // Dieser Endpoint braucht keine Admin-Berechtigung (Pre-Login)
        return true;
    }
}
