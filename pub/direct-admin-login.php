<?php
/**
 * Direktes Admin-Login Script für Magento 2
 * 
 * Dieses Skript verwendet einen sehr direkten Ansatz zum Einloggen
 * in den Magento 2 Admin-Bereich unter Verwendung einer vorhandenen
 * Benutzer-ID und simpler Weiterleitungsmechanismen.
 */

// Fehlerbehandlung aktivieren
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Zeitzone setzen
date_default_timezone_set('Europe/Berlin');

// Einfache Logging-Funktion
function writeLog($message) {
    $logFile = dirname(__DIR__) . '/var/log/direct_admin_login.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    
    if (isset($_GET['debug']) && $_GET['debug'] === 'true') {
        echo "<div style='margin:5px;padding:10px;background:#f5f5f5;border:1px solid #ddd;font-family:monospace;'>";
        echo htmlspecialchars($message);
        echo "</div>";
    }
}

try {
    // E-Mail-Parameter prüfen
    $email = $_GET['email'] ?? null;
    if (empty($email)) {
        writeLog("Fehler: E-Mail-Parameter fehlt");
        die("Parameter 'email' ist erforderlich.");
    }
    
    // Magento Bootstrap laden
    writeLog("Magento Bootstrap laden...");
    require_once dirname(__DIR__) . '/app/bootstrap.php';
    $bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
    $objectManager = $bootstrap->getObjectManager();
    
    // Area Code setzen
    $state = $objectManager->get(\Magento\Framework\App\State::class);
    try {
        $state->setAreaCode('adminhtml');
        writeLog("Area Code auf 'adminhtml' gesetzt");
    } catch (\Exception $e) {
        writeLog("Area Code bereits gesetzt: " . $e->getMessage());
    }
    
    // Admin-Benutzer per E-Mail finden
    writeLog("Suche Admin-Benutzer mit E-Mail: $email");
    $userCollection = $objectManager->create(\Magento\User\Model\ResourceModel\User\Collection::class);
    $userCollection->addFieldToFilter('email', $email);
    
    if ($userCollection->getSize() === 0) {
        // Als Alternative Benutzernamen versuchen
        writeLog("Kein Benutzer mit E-Mail gefunden. Versuche als Benutzername...");
        $user = $objectManager->create(\Magento\User\Model\User::class);
        $user->loadByUsername($email);
        
        if (!$user->getId()) {
            writeLog("Fehler: Kein Admin-Benutzer gefunden");
            die("Es wurde kein Admin-Benutzer mit dieser E-Mail oder Benutzername gefunden.");
        }
    } else {
        $user = $userCollection->getFirstItem();
    }
    
    writeLog("Admin-Benutzer gefunden: ID {$user->getId()}, Username: {$user->getUsername()}");
    
    // Die Backend-URL bestimmen
    $backendConfig = $objectManager->get(\Magento\Backend\App\Config::class);
    $adminPath = $backendConfig->getValue('admin/url/use_custom_path') 
                 ? $backendConfig->getValue('admin/url/custom_path') 
                 : 'admin';
                 
    writeLog("Admin-Pfad: $adminPath");
    
    // Admin-Authentifizierung direkt durchführen
    $authSession = $objectManager->get(\Magento\Backend\Model\Auth\Session::class);
    $authSession->setUser($user);
    $authSession->processLogin();
    
    // Form Key generieren und setzen
    $formKey = $objectManager->get(\Magento\Framework\Data\Form\FormKey::class);
    $formKeyValue = $formKey->getFormKey();
    $authSession->setFormKey($formKeyValue);
    
    // Explizit ACL laden
    $aclBuilder = $objectManager->get(\Magento\Authorization\Model\UserContextInterface::class);
    $authSession->refreshAcl();
    
    writeLog("Login durchgeführt: Session aktiv = " . ($authSession->isLoggedIn() ? "Ja" : "Nein"));
    
    // Cookie-Einstellungen
    $domain = $_SERVER['HTTP_HOST'];
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    
    // Setze notwendige Cookies manuell für den Browser
    // 1. Session-Cookie
    setcookie('PHPSESSID', session_id(), [
        'expires' => time() + 3600,
        'path' => '/',
        'domain' => $domain,
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    // 2. Admin-Cookie
    setcookie('admin', $authSession->getSessionId(), [
        'expires' => time() + 3600,
        'path' => '/' . $adminPath,
        'domain' => $domain,
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    // 3. Form-Key-Cookie
    setcookie('form_key', $formKeyValue, [
        'expires' => time() + 3600,
        'path' => '/',
        'domain' => $domain,
        'secure' => $isSecure,
        'httponly' => false,
        'samesite' => 'Lax'
    ]);
    
    writeLog("Cookies gesetzt: PHPSESSID, admin, form_key");
    
    // Bestimme das Ziel-URL für die Weiterleitung
    $redirectUrl = $_GET['redirect'] ?? '/' . $adminPath;
    
    // Wenn es ein relativer Pfad ist, konvertiere ihn zur vollen URL
    if (strpos($redirectUrl, 'http') !== 0 && $redirectUrl[0] === '/') {
        $protocol = $isSecure ? 'https://' : 'http://';
        $redirectUrl = $protocol . $domain . $redirectUrl;
    }
    
    writeLog("Weiterleitung zu: $redirectUrl");
    
    // HTML-Seite mit Auto-Submit-Form erstellen für maximale Browser-Kompatibilität
    echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Admin Login Erfolgreich</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding-top: 50px; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
        .success { color: green; }
        .loading { margin: 20px 0; }
        .spinner { 
            width: 40px; height: 40px; 
            border: 5px solid rgba(0,0,0,0.1); 
            border-radius: 50%;
            border-top-color: #07d; 
            animation: spin 1s ease-in-out infinite;
            margin: 0 auto;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="success">Admin Login erfolgreich!</h1>
        <p>Sie werden zum Magento Admin-Bereich weitergeleitet...</p>
        
        <div class="loading">
            <div class="spinner"></div>
        </div>
        
        <p>Falls die automatische Weiterleitung nicht funktioniert:</p>
        <form id="redirectForm" method="GET" action="' . htmlspecialchars($redirectUrl) . '">
            <input type="hidden" name="form_key" value="' . htmlspecialchars($formKeyValue) . '">
            <input type="hidden" name="login_attempt" value="' . time() . '">
            <button type="submit">Zum Admin-Bereich</button>
        </form>
        
        <script>
            // Speichere form_key im localStorage
            try {
                localStorage.setItem("form_key", "' . $formKeyValue . '");
                console.log("Form Key im localStorage gesetzt");
            } catch(e) {
                console.error("Fehler beim Setzen des Form Keys im localStorage:", e);
            }
            
            // Kurze Verzögerung für die Weiterleitung
            setTimeout(function() {
                document.getElementById("redirectForm").submit();
            }, 1500);
        </script>
    </div>
</body>
</html>';

} catch (\Exception $e) {
    writeLog("Fehler: " . $e->getMessage());
    writeLog("Stack-Trace: " . $e->getTraceAsString());
    
    echo '<h1>Fehler beim Login</h1>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    
    if (isset($_GET['debug']) && $_GET['debug'] === 'true') {
        echo '<h2>Stack-Trace:</h2>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    }
}