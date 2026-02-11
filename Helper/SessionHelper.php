<?php
namespace MiniOrange\OAuth\Helper;

use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;

/**
 * Session-Handler-Plugin für miniOrange OAuth SSO
 * Diese Klasse konfiguriert die PHP-Session für Cross-Origin-Nutzung
 *
 * Compatible with Magento 2.4.7 - 2.4.8-p3
 */
class SessionHelper
{
    /**
     * @var CookieManagerInterface
     */
    private $cookieManager;

    /**
     * @var CookieMetadataFactory
     */
    private $cookieMetadataFactory;

    /**
     * @var OAuthUtility
     */
    private $oauthUtility;

    /**
     * @var BackendUrlInterface
     */
    private $backendUrl;

    public function __construct(
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        OAuthUtility $oauthUtility,
        BackendUrlInterface $backendUrl
    ) {
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->oauthUtility = $oauthUtility;
        $this->backendUrl = $backendUrl;
    }

    /**
     * Konfiguriert die PHP-Session für SSO
     */
    public function configureSSOSession()
    {
        // Manual session management removed to comply with Magento standards.
        // We rely on CookieManager to set SameSite attributes via updateSessionCookies.
        $this->updateSessionCookies();
    }

    /**
     * Bestehende Session-Cookies neu setzen mit SameSite=None
     * Behandelt sowohl Frontend als auch Admin Cookies
     *
     * Uses Magento's CookieManager for 2.4.7+ compatibility
     */
    public function updateSessionCookies()
    {
        try {
            // Prüfen, ob die Session aktiv ist
            $isSessionActive = (session_status() == PHP_SESSION_ACTIVE);
            $sessionName = session_name();

            // Behandeln des Frontend-Cookies (/ Pfad)
            if (isset($_COOKIE[$sessionName])) {
                $cookieValue = $_COOKIE[$sessionName];

                /** @var PublicCookieMetadata $metadata */
                $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                    ->setPath('/')
                    ->setSecure(true)
                    ->setHttpOnly(true)
                    ->setSameSite('None');

                $this->cookieManager->setPublicCookie($sessionName, $cookieValue, $metadata);
            }

            // Also update admin session cookies with SameSite=None
            $adminFrontName = $this->backendUrl->getAreaFrontName();
            foreach ($_COOKIE as $name => $value) {
                if ($name !== $sessionName && (strpos($name, $adminFrontName) !== false || strpos($name, 'PHPSESSID') !== false)) {
                    $path = (strpos($name, $adminFrontName) !== false) ? '/' . $adminFrontName : '/';

                    /** @var PublicCookieMetadata $metadata */
                    $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                        ->setPath($path)
                        ->setSecure(true)
                        ->setHttpOnly(true)
                        ->setSameSite('None');

                    $this->cookieManager->setPublicCookie($name, $value, $metadata);
                }
            }
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("SessionHelper: Exception in updateSessionCookies: " . $e->getMessage());
        }
    }

    /**
     * Set SameSite=None on the PHP session cookie only.
     * Only updates the session cookie — does not modify other cookies.
     */
    public function forceSameSiteNone()
    {
        try {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                return;
            }

            $sessionName = session_name();
            $sessionId = session_id();

            if (empty($sessionId) || !isset($_COOKIE[$sessionName])) {
                return;
            }

            /** @var PublicCookieMetadata $metadata */
            $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                ->setPath('/')
                ->setSecure(true)
                ->setHttpOnly(true)
                ->setSameSite('None');

            $this->cookieManager->setPublicCookie($sessionName, $sessionId, $metadata);
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("SessionHelper: Error in forceSameSiteNone: " . $e->getMessage());
        }
    }
}
