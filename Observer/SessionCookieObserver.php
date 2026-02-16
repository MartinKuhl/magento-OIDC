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
     * @var SessionHelper
     */
    private $sessionHelper;

    /**
     * @param \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility
     * @param SessionHelper                         $sessionHelper
     */
    public function __construct(
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        SessionHelper $sessionHelper
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->sessionHelper = $sessionHelper;
    }

    /**
     * Observer-Methode, die vor dem Senden der Response aufgerufen wird
     *
     * @param  \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $this->sessionHelper->forceSameSiteNone();
        } catch (\Exception $e) {
            // Nur kritische Fehler loggen
            $this->oauthUtility->customlog("SessionCookieObserver: Critical error - " . $e->getMessage());
        }
    }
}
