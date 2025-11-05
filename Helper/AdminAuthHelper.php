<?php
/**
 * Admin Authentication Helper für Magento
 * 
 * Diese Klasse erleichtert die Authentifizierung von Admin-Benutzern
 * innerhalb des miniOrange OAuth SSO-Plugins, indem sie einen einfachen
 * Zugang zum standaloneAdmin-Login bietet.
 */
namespace MiniOrange\OAuth\Helper;

use Magento\Framework\App\ObjectManager;

class AdminAuthHelper
{
    /**
     * Bestimmt die Base-URL ohne ObjectManager zu verwenden
     * 
     * @return string Base-URL der Magento-Installation
     */
    private static function getBaseUrl()
    {
        // Wenn wir innerhalb von Magento sind und ObjectManager verfügbar ist
        if (class_exists('\Magento\Framework\App\ObjectManager') && \Magento\Framework\App\ObjectManager::getInstance()) {
            try {
                $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                $storeManager = $objectManager->get('\Magento\Store\Model\StoreManagerInterface');
                return $storeManager->getStore()->getBaseUrl();
            } catch (\Exception $e) {
                // Fallback-Methode bei Fehlern
            }
        }
        
        // Fallback: URL aus $_SERVER ermitteln
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $domainName = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
        
        // Basis-Pfad der Magento-Installation ermitteln
        $basePath = '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($scriptName) {
            $basePath = dirname(dirname($scriptName));
            if ($basePath == '/' || $basePath == '\\') {
                $basePath = '';
            }
        }
        
        return $protocol . $domainName . $basePath . '/';
    }
    /**
     * Führt eine Admin-Authentifizierung durch, indem zum Standalone-Skript umgeleitet wird
     *
     * @param string $email E-Mail-Adresse des Admin-Benutzers
     * @param string $redirectUrl Optional: URL zum Weiterleiten nach der Authentifizierung
     * @return string URL zur Weiterleitung
     */
    public static function getStandaloneLoginUrl($email, $redirectUrl = '')
    {
        // Einfache Methode zur Bestimmung der Base-URL ohne ObjectManager
        $baseUrl = self::getBaseUrl();
        
        // URL für das Standalone-Direct-Login-Skript erstellen (neue robuste Lösung)
        $url = $baseUrl . 'direct-admin-login.php';
        $url .= '?email=' . urlencode($email);
        $url .= '&debug=true'; // Debug-Ausgaben aktivieren
        
        if (!empty($redirectUrl)) {
            $url .= '&redirect=' . urlencode($redirectUrl);
        }
        
        $url .= '&ts=' . time(); // Timestamp zur Cache-Vermeidung
        
        return $url;
    }
    
    /**
     * Einfache Log-Funktion
     * 
     * @param string $message Die zu protokollierende Nachricht
     */
    public static function logDebug($message)
    {
        try {
            $objectManager = ObjectManager::getInstance();
            $oauthUtility = $objectManager->get(OAuthUtility::class);
            $oauthUtility->customlog("AdminAuthHelper: " . $message);
        } catch (\Exception $e) {
            // Stille Fehlerbehandlung, wenn Logging fehlschlägt
        }
    }
}