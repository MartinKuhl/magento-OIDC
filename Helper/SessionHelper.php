<?php
namespace MiniOrange\OAuth\Helper;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Session\SessionManagerInterface;

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
    private static $cookieManager;

    /**
     * @var CookieMetadataFactory
     */
    private static $cookieMetadataFactory;

    /**
     * Get cookie manager instance
     *
     * @return CookieManagerInterface
     */
    private static function getCookieManager(): CookieManagerInterface
    {
        if (self::$cookieManager === null) {
            self::$cookieManager = ObjectManager::getInstance()->get(CookieManagerInterface::class);
        }
        return self::$cookieManager;
    }

    /**
     * Get cookie metadata factory instance
     *
     * @return CookieMetadataFactory
     */
    private static function getCookieMetadataFactory(): CookieMetadataFactory
    {
        if (self::$cookieMetadataFactory === null) {
            self::$cookieMetadataFactory = ObjectManager::getInstance()->get(CookieMetadataFactory::class);
        }
        return self::$cookieMetadataFactory;
    }

    /**
     * Konfiguriert die PHP-Session für SSO
     */
    public static function configureSSOSession()
    {
        // Manual session management removed to comply with Magento standards.
        // We rely on CookieManager to set SameSite attributes via updateSessionCookies.
        self::updateSessionCookies();
    }

    /**
     * Bestehende Session-Cookies neu setzen mit SameSite=None
     * Behandelt sowohl Frontend als auch Admin Cookies
     *
     * Uses Magento's CookieManager for 2.4.7+ compatibility
     */
    public static function updateSessionCookies()
    {
        try {
            $cookieManager = self::getCookieManager();
            $metadataFactory = self::getCookieMetadataFactory();

            // Prüfen, ob die Session aktiv ist
            $isSessionActive = (session_status() == PHP_SESSION_ACTIVE);
            $sessionName = session_name();

            // Behandeln des Frontend-Cookies (/ Pfad)
            if (isset($_COOKIE[$sessionName])) {
                $cookieValue = $_COOKIE[$sessionName];

                /** @var PublicCookieMetadata $metadata */
                $metadata = $metadataFactory->createPublicCookieMetadata()
                    ->setPath('/')
                    ->setSecure(true)
                    ->setHttpOnly(true)
                    ->setSameSite('None');

                $cookieManager->setPublicCookie($sessionName, $cookieValue, $metadata);
            }

            // Auch andere wichtige Cookies in $_COOKIE durchlaufen und aktualisieren
            foreach ($_COOKIE as $name => $value) {
                // Admin-Cookies oder andere Session-bezogene Cookies aktualisieren
                if ($name !== $sessionName && (strpos($name, 'admin') !== false || strpos($name, 'PHPSESSID') !== false)) {
                    // Path basierend auf Cookie-Namen bestimmen
                    $path = (strpos($name, 'admin') !== false) ? '/admin' : '/';

                    /** @var PublicCookieMetadata $metadata */
                    $metadata = $metadataFactory->createPublicCookieMetadata()
                        ->setPath($path)
                        ->setSecure(true)
                        ->setHttpOnly(true)
                        ->setSameSite('None');

                    $cookieManager->setPublicCookie($name, $value, $metadata);
                }
            }
        } catch (\Exception $e) {
            self::logDebug("SessionHelper: Exception in updateSessionCookies: " . $e->getMessage());
        }
    }

    /**
     * Protokollierung für Debugging
     */
    private static function logDebug($message)
    {
        try {
            $objectManager = ObjectManager::getInstance();
            $oauthUtility = $objectManager->get(\MiniOrange\OAuth\Helper\OAuthUtility::class);
            $oauthUtility->customlog($message);
        } catch (\Exception $e) {
            // Stille Fehlerbehandlung, wenn Logging fehlschlägt
        }
    }

    /**
     * Setzt die Header für das PHP-Cookie, um SameSite=None zu erzwingen
     * Diese Methode wird vor jeder Response aufgerufen
     *
     * Uses Magento's CookieManager for 2.4.7+ compatibility
     */
    public static function forceSameSiteNone()
    {
        try {
            $cookieManager = self::getCookieManager();
            $metadataFactory = self::getCookieMetadataFactory();

            // Aktuelle Cookies erfassen
            $cookies = [];
            foreach (headers_list() as $header) {
                if (strpos($header, 'Set-Cookie:') === 0) {
                    // Cookie-Header extrahieren
                    $cookies[] = $header;
                }
            }

            // Alle Set-Cookie-Header entfernen
            header_remove('Set-Cookie');

            // Cookies neu hinzufügen mit SameSite=None
            foreach ($cookies as $cookie) {
                // Sicherstellen, dass der Cookie "Secure" hat, wenn wir SameSite=None setzen
                if (strpos($cookie, '; secure') === false) {
                    $cookie = str_replace('Set-Cookie: ', 'Set-Cookie: ', $cookie) . '; secure';
                }

                $cookie = preg_replace('/(; SameSite=)([^;]*)/', '$1None', $cookie, -1, $count);
                if ($count === 0) {
                    // Wenn kein SameSite-Attribut gefunden wurde, füge es hinzu
                    $cookie = $cookie . '; SameSite=None';
                }

                header($cookie, false);
            }

            // Auch für das PHP-Session-Cookie - use Magento's CookieManager
            if (session_status() === PHP_SESSION_ACTIVE) {
                $sessionName = session_name();
                $sessionId = session_id();

                if (!empty($sessionId) && isset($_COOKIE[$sessionName])) {
                    /** @var PublicCookieMetadata $metadata */
                    $metadata = $metadataFactory->createPublicCookieMetadata()
                        ->setPath('/')
                        ->setSecure(true)
                        ->setHttpOnly(true)
                        ->setSameSite('None');

                    $cookieManager->setPublicCookie($sessionName, $sessionId, $metadata);
                }
            }
        } catch (\Exception $e) {
            self::logDebug("SessionHelper: Fehler in forceSameSiteNone: " . $e->getMessage());
        }
    }
}
