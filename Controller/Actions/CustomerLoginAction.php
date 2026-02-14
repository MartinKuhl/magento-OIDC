<?php

namespace MiniOrange\OAuth\Controller\Actions;

use Magento\Customer\Model\Session;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResponseFactory;
use MiniOrange\OAuth\Helper\OAuthSecurityHelper;
use MiniOrange\OAuth\Helper\OAuthUtility;

class CustomerLoginAction extends BaseAction implements HttpPostActionInterface
{
    /**
     * @var \Magento\Customer\Model\Data\Customer|null
     */
    private $user;

    /**
     * @var Session
     */
    private $customerSession;

    /**
     * @var ResponseFactory
     */
    private $responseFactory;

    /**
     * @var string|null
     */
    private $relayState;

    /**
     * @var OAuthSecurityHelper
     */
    private $securityHelper;

    /**
     * @var CustomerFactory
     */
    private $customerFactory;

    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        Session $customerSession,
        ResponseFactory $responseFactory,
        OAuthSecurityHelper $securityHelper,
        CustomerFactory $customerFactory
    ) {
        $this->customerSession = $customerSession;
        $this->responseFactory = $responseFactory;
        $this->securityHelper = $securityHelper;
        $this->customerFactory = $customerFactory;
        parent::__construct($context, $oauthUtility);
    }


    /**
     * Execute function to execute the classes function.
     */
    public function execute()
    {
        if (!isset($this->relayState)) {
            $this->relayState = $this->oauthUtility->getBaseUrl() . "customer/account";
        }
        $this->oauthUtility->customlog("CustomerLoginAction: execute");

        if ($this->user === null) {
            $this->oauthUtility->customlog("CustomerLoginAction: ERROR - user is null, cannot log in");
            $this->messageManager->addErrorMessage(__('Authentication failed. Please try again.'));
            return $this->resultRedirectFactory->create()->setUrl(
                $this->oauthUtility->getBaseUrl() . 'customer/account/login'
            );
        }

        // If relayState points to the login page, redirect to account dashboard instead.
        // This happens when SSO is initiated from the login page itself.
        $relayPath = $this->oauthUtility->extractPathFromUrl($this->relayState) ?? '';
        if (str_starts_with(rtrim($relayPath, '/'), '/customer/account/login')) {
            $this->relayState = $this->oauthUtility->getBaseUrl() . 'customer/account';
        }

        $customerModel = $this->customerFactory->create()->load($this->user->getId());
        $this->customerSession->setCustomerAsLoggedIn($customerModel);
        $safeRelayState = $this->securityHelper->validateRedirectUrl(
            $this->relayState,
            $this->oauthUtility->getBaseUrl() . 'customer/account'
        );
        // Convert relative paths to full URLs
        if (str_starts_with($safeRelayState, '/')) {
            $safeRelayState = rtrim($this->oauthUtility->getBaseUrl(), '/') . $safeRelayState;
        }
        $this->oauthUtility->customlog("CustomerLoginAction: Redirecting to: " . $safeRelayState);
        return $this->resultRedirectFactory->create()->setUrl($safeRelayState);
    }


    /**
     * Setter for the user parameter.
     *
     * @param \Magento\Customer\Model\Data\Customer|null $user
     * @return CustomerLoginAction
     */
    public function setUser($user)
    {
        $this->oauthUtility->customlog("CustomerLoginAction: setUser");

        $this->user = $user;
        return $this;
    }

    /**
     * Setter for the relayState parameter.
     *
     * @param string|null $relayState
     * @return CustomerLoginAction
     */
    public function setRelayState($relayState)
    {
        $this->oauthUtility->customlog("CustomerLoginAction: setRelayState");
        $this->relayState = $relayState;
        return $this;
    }
}
