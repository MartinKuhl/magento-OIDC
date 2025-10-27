<?php
/**
 * Copyright © MiniOrange. All rights reserved.
 */

namespace MiniOrange\OAuth\Controller\Adminhtml\Actions;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use MiniOrange\OAuth\Helper\OAuthUtility;

/**
 * Debug Controller für Authelia OIDC Response
 * 
 * Zeigt alle Rückgabewerte von Authelia übersichtlich an
 * für einfacheres Debugging und Troubleshooting
 */
class Debug extends Action
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
     * Constructor
     *
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
     * Execute action
     *
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        // Sicherheitsprüfung: Nur mit gültigem Key zugänglich
        $key = $this->getRequest()->getParam('key');
        $expectedKey = hash('sha256', 'debug_authelia_oidc_' . date('Y-m-d'));
        
        if ($key !== $expectedKey) {
            $this->messageManager->addErrorMessage(
                __('Ungültiger Debug-Schlüssel. Bitte verwenden Sie den aktuellen Tagesschlüssel.')
            );
            return $this->resultRedirectFactory->create()->setPath('adminhtml/dashboard');
        }

        // Log den Debug-Zugriff
        $this->oauthUtility->customlog('Debug-Seite wurde aufgerufen');

        // Erstelle und gebe die Seite zurück
        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend(__('OIDC Debug Information'));
        
        return $resultPage;
    }

    /**
     * Check ACL permissions
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('MiniOrange_OAuth::oauth_settings');
    }
}
