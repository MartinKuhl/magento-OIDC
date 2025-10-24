<?php

namespace MiniOrange\OAuth\Observer;

use Magento\Framework\Event\ObserverInterface;
use MiniOrange\OAuth\Helper\SessionHelper;

/**
 * Observer für Session-Cookie-Anpassung
 * 
 * Dieser Observer wird aufgerufen, bevor die Response gesendet wird
 */
class SessionCookieObserver implements ObserverInterface
{
    /**
     * @var \MiniOrange\OAuth\Helper\OAuthUtility
     */
    protected $oauthUtility;

    /**
     * @param \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility
     */
    public function __construct(
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility
    ) {
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * Observer-Methode, die vor dem Senden der Response aufgerufen wird
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $this->oauthUtility->customlog("SessionCookieObserver: Modifying session cookies for SameSite=None");
            SessionHelper::forceSameSiteNone();
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("SessionCookieObserver: Error - " . $e->getMessage());
        }
    }
}