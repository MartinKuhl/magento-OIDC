<?php
namespace MiniOrange\OAuth\Helper;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\App\State;

/**
 * Session-Handler-Plugin für miniOrange OAuth SSO
 * Diese Klasse konfiguriert die PHP-Session für Cross-Origin-Nutzung
 */
class SessionHelper
{
    /**
     * Konfiguriert die PHP-Session für SSO
     */
    public static function configureSSOSession()
    {
        // Prüfen, ob die Session bereits aktiv ist
        $isSessionActive = (session_status() == PHP_SESSION_ACTIVE);
        
        if ($isSessionActive) {
            // Session schließen, um Einstellungen ändern zu können
            session_write_close();
        }
        
        // Versuche die Session-Einstellungen zu konfigurieren
        try {
            // SameSite auf None setzen für neue Sessions
            if (PHP_VERSION_ID >= 70300) {
                // In PHP 7.3+ können wir das direkt angeben
                session_set_cookie_params([
                    'samesite' => 'None',
                    'secure' => true,
                    'httponly' => true
                ]);
            } else {
                // Für ältere PHP-Versionen
                session_set_cookie_params(
                    0, // Lebensdauer
                    '/', // Pfad
                    '', // Domain
                    true, // Secure
                    true // HTTPOnly
                );
            }
            
            // Versuche die ini-Einstellung zu ändern, nur wenn Session noch nicht aktiv ist
            if (!$isSessionActive && PHP_VERSION_ID >= 70300) {
                @ini_set('session.cookie_samesite', 'None');
            }
        } catch (\Exception $e) {
            self::logDebug("SessionHelper: Fehler beim Konfigurieren der Session: " . $e->getMessage());
        }
        
        // Session wieder starten, wenn sie vorher aktiv war
        if ($isSessionActive) {
            session_start();
        }
    }
    
    /**
     * Bestehende Session-Cookies neu setzen mit SameSite=None
     * Behandelt sowohl Frontend als auch Admin Cookies
     */
    public static function updateSessionCookies()
    {
        try {
            // Prüfen, ob die Session aktiv ist
            $isSessionActive = (session_status() == PHP_SESSION_ACTIVE);
            $sessionName = session_name();
            $sessionId = $isSessionActive ? session_id() : '';
            
            // Wir benutzen direkt die PHP-Methoden anstatt Magento-API zu verwenden, um Fehler zu vermeiden
            
            // Behandeln des Frontend-Cookies (/ Pfad)
            if (isset($_COOKIE[$sessionName])) {
                $cookieValue = $_COOKIE[$sessionName];
                
                // Kompatibilitätsweise für verschiedene PHP-Versionen
                if (PHP_VERSION_ID >= 70300) {
                    // PHP 7.3+ unterstützt SameSite direkt
                    setcookie(
                        $sessionName, 
                        $cookieValue, 
                        [
                            'expires' => 0, // Session-Cookie
                            'path' => '/',
                            'domain' => '',
                            'secure' => true,
                            'httponly' => true,
                            'samesite' => 'None'
                        ]
                    );
                } else {
                    // Ältere PHP-Versionen
                    setcookie(
                        $sessionName, 
                        $cookieValue, 
                        0, // Session-Cookie 
                        '/; SameSite=None', 
                        '',
                        true, // Secure
                        true // HttpOnly
                    );
                }
            }
            
            // Auch andere wichtige Cookies in $_COOKIE durchlaufen und aktualisieren
            foreach ($_COOKIE as $name => $value) {
                // Admin-Cookies oder andere Session-bezogene Cookies aktualisieren
                if ($name !== $sessionName && (strpos($name, 'admin') !== false || strpos($name, 'PHPSESSID') !== false)) {
                    // Path basierend auf Cookie-Namen bestimmen
                    $path = (strpos($name, 'admin') !== false) ? '/admin' : '/';
                    
                    if (PHP_VERSION_ID >= 70300) {
                        // PHP 7.3+ unterstützt SameSite direkt
                        setcookie(
                            $name, 
                            $value, 
                            [
                                'expires' => 0, // Session-Cookie
                                'path' => $path,
                                'domain' => '',
                                'secure' => true,
                                'httponly' => true,
                                'samesite' => 'None'
                            ]
                        );
                    } else {
                        // Ältere PHP-Versionen
                        setcookie(
                            $name, 
                            $value, 
                            0, // Session-Cookie
                            $path . '; SameSite=None',
                            '',
                            true, // Secure
                            true // HttpOnly
                        );
                    }
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
     */
    public static function forceSameSiteNone()
    {
        try {
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
            
            // Auch für das PHP-Session-Cookie - wir verwenden eine vorsichtigere Methode
            if (session_status() === PHP_SESSION_ACTIVE) {
                $sessionName = session_name();
                $sessionId = session_id();
                
                if (!empty($sessionId) && isset($_COOKIE[$sessionName])) {
                    // Bestehenden Cookie mit den gleichen Einstellungen neu setzen, aber mit SameSite=None
                    if (PHP_VERSION_ID >= 70300) {
                        // Für PHP 7.3+
                        setcookie($sessionName, $sessionId, [
                            'expires' => 0, // Session-Cookie
                            'path' => '/',
                            'domain' => '',
                            'secure' => true,
                            'httponly' => true,
                            'samesite' => 'None'
                        ]);
                    } else {
                        // Für ältere PHP-Versionen
                        setcookie(
                            $sessionName,
                            $sessionId,
                            0, // Session-Cookie
                            '/; SameSite=None', // Path mit SameSite
                            '',
                            true, // Secure
                            true // HTTPOnly
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            self::logDebug("SessionHelper: Fehler in forceSameSiteNone: " . $e->getMessage());
        }
    }
}