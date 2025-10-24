<?php
/**
 * Copyright © MiniOrange Inc. All rights reserved.
 */
namespace MiniOrange\OAuth\Helper;

use Magento\Framework\App\ObjectManager;
use Magento\Backend\Model\Auth\Session as BackendSession;

/**
 * Spezialisierter Helfer für Admin-Session-Handling
 */
class AdminSessionHelper
{
    /**
     * Logger für Debug-Nachrichten
     * 
     * @param string $message
     * @return void
     */
    private static function logDebug($message)
    {
        try {
            $objectManager = ObjectManager::getInstance();
            $oauthUtility = $objectManager->get(\MiniOrange\OAuth\Helper\OAuthUtility::class);
            $oauthUtility->customlog("AdminSessionHelper: " . $message);
        } catch (\Exception $e) {
            // Stille Fehlerbehandlung, wenn Logging fehlschlägt
        }
    }
    
    /**
     * Initialisiert eine Admin-Session mit einem Benutzer
     * 
     * @param \Magento\User\Model\User $adminUser
     * @return string|bool Session-ID oder false bei Fehler
     */
    public static function initAdminSession($adminUser)
    {
        try {
            if (!$adminUser || !$adminUser->getId()) {
                self::logDebug("Invalid admin user provided");
                return false;
            }
            
            $objectManager = ObjectManager::getInstance();
            
            // Admin-Session-Objekte - explizit neu erstellen, um Cache-Probleme zu vermeiden
            $authService = $objectManager->create(\Magento\Backend\Model\Auth::class);
            $adminSession = $objectManager->create(\Magento\Backend\Model\Auth\Session::class);
            $sessionManager = $objectManager->create(\Magento\Framework\Session\SessionManagerInterface::class);
            
            // Überprüfen, ob die processLogin-Methode existiert
            if (!method_exists($adminSession, 'processLogin')) {
                self::logDebug("ERROR: processLogin method does not exist in admin session class: " . get_class($adminSession));
                self::logDebug("Available methods: " . implode(', ', get_class_methods($adminSession)));
                throw new \Exception("processLogin method not found in admin session");
            }
            
            // Bestehendes Session-Handling
            self::logDebug("Creating fresh admin session for user ID: " . $adminUser->getId());
            
            // Sauberen Session-Status sicherstellen
            $sessionManager->start();
            $sessionId = $sessionManager->getSessionId();
            self::logDebug("Created admin session with ID: " . $sessionId);
            
            // Benutzer in Auth-Service und Session setzen
            $authService->setAuthStorage($adminSession);
            $adminSession->setUser($adminUser);
            self::logDebug("Calling processLogin on " . get_class($adminSession));
            $adminSession->processLogin();
            
            // Wichtig: Admin-Benutzer als eingeloggt markieren
            $adminUser->getResource()->recordLogin($adminUser);
            $adminSession->setIsFirstPageAfterLogin(true);
            
            // Session explizit speichern
            $sessionManager->writeClose();
            
            // Cookie setzen für Admin-Session
            self::setAdminSessionCookie($sessionId, $adminSession->getName());
            
            return $sessionId;
        } catch (\Exception $e) {
            self::logDebug("Error in initAdminSession: " . $e->getMessage() . " - " . $e->getTraceAsString());
            return false;
        }
    }
    
    /**
     * Setzt den Admin-Session-Cookie mit korrekten Attributen
     * 
     * @param string $sessionId
     * @param string $cookieName
     * @return bool
     */
    public static function setAdminSessionCookie($sessionId, $cookieName = 'admin')
    {
        try {
            // Direkte Cookie-Setzung für maximale Kontrolle
            $expires = time() + 86400; // 24 Stunden
            $path = '/admin';
            
            // Cookie setzen mit SameSite=None für Cross-Domain-Funktionalität
            if (PHP_VERSION_ID >= 70300) {
                // PHP 7.3+
                setcookie($cookieName, $sessionId, [
                    'expires' => $expires,
                    'path' => $path,
                    'domain' => '',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'None'
                ]);
            } else {
                // Ältere PHP-Versionen
                setcookie(
                    $cookieName,
                    $sessionId,
                    $expires,
                    $path . '; SameSite=None', // Path mit SameSite
                    '',
                    true,
                    true
                );
            }
            
            self::logDebug("Set admin session cookie: $cookieName=$sessionId (expires in 24h)");
            
            // Optional: Auch über Magento Cookie Manager
            try {
                $objectManager = ObjectManager::getInstance();
                $cookieManager = $objectManager->get(\Magento\Framework\Stdlib\CookieManagerInterface::class);
                $cookieMetadataFactory = $objectManager->get(\Magento\Framework\Stdlib\Cookie\CookieMetadataFactory::class);
                
                if ($cookieManager && $cookieMetadataFactory) {
                    $metadata = $cookieMetadataFactory->createPublicCookieMetadata()
                        ->setDuration(86400)
                        ->setPath($path)
                        ->setSecure(true)
                        ->setHttpOnly(true);
                        
                    // SameSite=None für Cross-Domain-Authentifizierung
                    if (method_exists($metadata, 'setSameSite')) {
                        $metadata->setSameSite('None');
                    }
                    
                    $cookieManager->setPublicCookie($cookieName, $sessionId, $metadata);
                    self::logDebug("Set admin cookie via manager");
                }
            } catch (\Exception $e) {
                self::logDebug("Note: Cookie manager error (non-critical): " . $e->getMessage());
                // Fehler hier ignorieren, da wir den Cookie bereits direkt gesetzt haben
            }
            
            return true;
        } catch (\Exception $e) {
            self::logDebug("Error setting admin cookie: " . $e->getMessage());
            return false;
        }
    }
    
        /**
     * Generiert einen neuen Form-Key für CSRF-Schutz
     * 
     * @return string|bool Form-Key oder false bei Fehler
     */
    public static function generateFormKey()
    {
        try {
            $objectManager = ObjectManager::getInstance();
            
            // FormKey-Objekt direkt verwenden
            $formKey = $objectManager->get(\Magento\Framework\Data\Form\FormKey::class);
            $newKey = $formKey->getFormKey();
            
            // Stellen wir sicher, dass der Form-Key auch in der Session verfügbar ist
            $adminSession = $objectManager->get(\Magento\Backend\Model\Auth\Session::class);
            $adminSession->setData('form_key', $newKey);
            
            // Auch in der globalen Session speichern
            $sessionManager = $objectManager->get(\Magento\Framework\Session\SessionManagerInterface::class);
            $sessionManager->setData('form_key', $newKey);
            
            // Form-Key-Cookie setzen
            $cookieManager = $objectManager->get(\Magento\Framework\Stdlib\CookieManagerInterface::class);
            $cookieMetadata = $objectManager->get(\Magento\Framework\Stdlib\Cookie\PublicCookieMetadataFactory::class)
                ->create()
                ->setDuration(86400)
                ->setPath('/')
                ->setHttpOnly(false)
                ->setSameSite('None')
                ->setSecure(true);
                
            $cookieManager->setPublicCookie(
                'form_key', 
                $newKey, 
                $cookieMetadata
            );
            
            // Gleichen Cookie auch speziell für den Admin-Bereich setzen
            $adminCookieMetadata = $objectManager->get(\Magento\Framework\Stdlib\Cookie\PublicCookieMetadataFactory::class)
                ->create()
                ->setDuration(86400)
                ->setPath('/admin')
                ->setHttpOnly(false)
                ->setSameSite('None')
                ->setSecure(true);
                
            $cookieManager->setPublicCookie(
                'form_key', 
                $newKey, 
                $adminCookieMetadata
            );
            
            self::logDebug("Generated and stored new form key: " . $newKey);
            return $newKey;
        } catch (\Exception $e) {
            self::logDebug("Error generating form key: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Direkter JavaScript-Snippet für die Form-Key-Einrichtung
     * 
     * @param string $formKey Der zu setzende Form-Key
     * @return string JavaScript-Snippet
     */
    public static function getFormKeyJsSnippet($formKey)
    {
        return "<script>
            document.addEventListener('DOMContentLoaded', function() {
                try {
                    // Form-Key im localStorage setzen
                    localStorage.setItem('form_key', '$formKey');
                    console.log('Form key set in localStorage: $formKey');
                    
                    // Prüfen, ob Admin-Dashboard bereits geladen ist
                    if (document.querySelector('form[action*=\"/admin/\"]')) {
                        // Form-Keys in allen Formularen setzen
                        var forms = document.querySelectorAll('form');
                        forms.forEach(function(form) {
                            var input = form.querySelector('input[name=\"form_key\"]');
                            if (input) {
                                input.value = '$formKey';
                            } else {
                                var newInput = document.createElement('input');
                                newInput.type = 'hidden';
                                newInput.name = 'form_key';
                                newInput.value = '$formKey';
                                form.appendChild(newInput);
                            }
                        });
                        console.log('Set form key in ' + forms.length + ' forms');
                    }
                } catch(e) {
                    console.error('Error setting form key:', e);
                }
            });
        </script>";
    }
    
    /**
     * Gibt den aktuellen Form Key zurück
     *
     * @return string|null
     */
    public static function getFormKey()
    {
        try {
            $objectManager = ObjectManager::getInstance();
            
            // Zuerst aus der Session holen
            $authSession = $objectManager->create(\Magento\Backend\Model\Auth\Session::class);
            self::logDebug("Auth Session class: " . get_class($authSession));
            
            if ($authSession && method_exists($authSession, 'getFormKey')) {
                $key = $authSession->getFormKey();
                if ($key) {
                    self::logDebug("Retrieved form key from session: $key");
                    return $key;
                }
            } else {
                self::logDebug("getFormKey method not available on session object: " . get_class($authSession));
            }
            
            // Wenn nicht in der Session, dann neu generieren
            $formKey = $objectManager->create(\Magento\Framework\Data\Form\FormKey::class);
            $key = $formKey->getFormKey();
            self::logDebug("Generated new form key: $key");
            
            // Versuchen, den Form Key in der Session zu speichern
            if ($authSession && method_exists($authSession, 'setFormKey')) {
                $authSession->setFormKey($key);
                self::logDebug("Stored form key in session");
            }
            
            return $key;
        } catch (\Exception $e) {
            self::logDebug("Error getting form key: " . $e->getMessage());
            return null;
        }
    }
}