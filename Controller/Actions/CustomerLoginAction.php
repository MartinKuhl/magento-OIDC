<?php

namespace MiniOrange\OAuth\Controller\Actions;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResponseFactory;
use MiniOrange\OAuth\Helper\OAuthUtility;

/**
 * This class is called to log the customer user in. RelayState and
 * user are set separately. This is a simple class.
 */
class CustomerLoginAction extends BaseAction implements HttpPostActionInterface
{
    private $user;
    private $customerSession;
    private $responseFactory;
    private $relayState;


    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        Session $customerSession,
        ResponseFactory $responseFactory
    ) {
        //You can use dependency injection to get any class this observer may need.
        $this->customerSession = $customerSession;
        $this->responseFactory = $responseFactory;
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

        $this->customerSession->setCustomerAsLoggedIn($this->user);
        return $this->resultRedirectFactory->create()->setUrl($this->oauthUtility->getUrl($this->relayState));
    }


    /** Setter for the user Parameter
     * @param $user
     * @return CustomerLoginAction
     */
    public function setUser($user)
    {
        $this->oauthUtility->customlog("CustomerLoginAction: setUser");

        $this->user = $user;
        return $this;
    }

    /** 
     * Setter for the relayState parameter
     * @param $relayState
     * @return CustomerLoginAction
     */
    public function setRelayState($relayState)
    {
        $this->oauthUtility->log_debug("CustomerLoginAction: setRelayState");
        $this->relayState = $relayState;
        return $this;
    }
}
