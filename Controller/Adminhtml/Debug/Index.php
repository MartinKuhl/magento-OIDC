<?php
namespace MiniOrange\OAuth\Controller\Adminhtml\Debug;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use MiniOrange\OAuth\Helper\OAuthUtility;

/**
 * Debug Controller für Authelia OIDC Response
 */
class Index extends Action
{
    /**
     * @var PageFactory
     */
    protected $resultPageFactory;

    /**
     * @var OAuthUtility
     */
    protected $oauthUtility;

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param OAuthUtility $oauthUtility
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        OAuthUtility $oauthUtility
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * Check ACL permissions
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('MiniOrange_OAuth::oauth_settings');
    }

    /**
     * Execute debug page
     * 
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        $this->oauthUtility->customlog("Debug: Accessing Authelia debug page");

        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend(__('Authelia OIDC Debug'));

        return $resultPage;
    }
}
