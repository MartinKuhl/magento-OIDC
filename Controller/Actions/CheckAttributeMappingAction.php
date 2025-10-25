<?php

namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Helper\Exception\MissingAttributesException;
use MiniOrange\OAuth\Helper\OAuthConstants;
use Magento\Framework\App\Action\HttpPostActionInterface;

class CheckAttributeMappingAction extends BaseAction implements HttpPostActionInterface
{
    const TEST_VALIDATE_RELAYSTATE = OAuthConstants::TEST_RELAYSTATE;

    private $userInfoResponse;
    private $flattenedUserInfoResponse;
    private $relayState;
    private $userEmail;

    private $emailAttribute;
    private $usernameAttribute;
    private $firstName;
    private $lastName;
    private $checkIfMatchBy;
    private $groupName;

    private $testAction;
    private $processUserAction;

    protected $userFactory;
    protected $adminSession;
    protected $cookieManager;
    protected $adminConfig;
    protected $cookieMetadataFactory;
    protected $backendUrl;

    private $adminFormKey = null;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        \MiniOrange\OAuth\Controller\Actions\ShowTestResultsAction $testAction,
        \MiniOrange\OAuth\Controller\Actions\ProcessUserAction $processUserAction,
        \Magento\User\Model\UserFactory $userFactory,
        \Magento\Backend\Model\Auth\Session $adminSession,
        \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager,
        \Magento\Backend\Model\Session\AdminConfig $adminConfig,
        \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory,
        \Magento\Backend\Model\UrlInterface $backendUrl
    ) {
        // Initialisiere Attribute-Mappings
        $this->emailAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_EMAIL);
        $this->emailAttribute = $oauthUtility->isBlank($this->emailAttribute) ? OAuthConstants::DEFAULT_MAP_EMAIL : $this->emailAttribute;
        
        $this->usernameAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_USERNAME);
        $this->usernameAttribute = $oauthUtility->isBlank($this->usernameAttribute) ? OAuthConstants::DEFAULT_MAP_USERN : $this->usernameAttribute;
        
        $this->firstName = $oauthUtility->getStoreConfig(OAuthConstants::MAP_FIRSTNAME);
        $this->firstName = $oauthUtility->isBlank($this->firstName) ? OAuthConstants::DEFAULT_MAP_FN : $this->firstName;
        
        $this->lastName = $oauthUtility->getStoreConfig(OAuthConstants::MAP_LASTNAME);
        $this->lastName = $oauthUtility->isBlank($this->lastName) ? OAuthConstants::DEFAULT_MAP_LN : $this->lastName;
        
        $this->checkIfMatchBy = $oauthUtility->getStoreConfig(OAuthConstants::MAP_MAP_BY);
        
        $this->testAction = $testAction;
        $this->processUserAction = $processUserAction;
        $this->userFactory = $userFactory;
        $this->adminSession = $adminSession;
        $this->cookieManager = $cookieManager;
        $this->adminConfig = $adminConfig;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->backendUrl = $backendUrl;
        
        parent::__construct($context, $oauthUtility);
    }

    public function execute()
    {
        error_log("=== CheckAttributeMappingAction execute START ===");
        
        $attrs = $this->userInfoResponse;
        $flattenedAttrs = $this->flattenedUserInfoResponse;
        $userEmail = $this->userEmail;
        
        $this->oauthUtility->customlog("Checking if admin user: " . $userEmail);
        $isAdminLogin = $this->isAdminUser($userEmail);
        $this->oauthUtility->customlog("Is admin user: " . ($isAdminLogin ? 'YES' : 'NO'));
        
        if ($isAdminLogin) {
            $this->oauthUtility->customlog("Attempting admin login...");
            
            if ($this->performAdminLogin($userEmail)) {
                $this->oauthUtility->customlog("Admin login successful!");
                
                // Admin-Pfad bestimmen
                $adminPath = $this->adminConfig->getValue('admin/url/use_custom_path')
                    ? $this->adminConfig->getValue('admin/url/custom_path')
                    : 'admin';
                
                $storeManager = $this->_objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);
                $baseUrl = $storeManager->getStore()->getBaseUrl();
                $adminUrl = rtrim($baseUrl, '/') . '/' . $adminPath;
                
                if (!empty($this->adminFormKey)) {
                    $adminUrl .= '?key=' . $this->adminFormKey;
                }
                
                error_log("Redirecting to: " . $adminUrl);
                
                // WICHTIG: Nicht sofort redirecten, sondern HTML mit Auto-Submit-Form ausgeben
                $formKey = $this->adminFormKey;
                
                $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Admin Login Erfolgreich</title>
        <style>
            body { font-family: Arial, sans-serif; text-align: center; padding-top: 50px; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
            .success { color: green; }
            .spinner { 
                width: 40px; height: 40px; 
                border: 5px solid rgba(0,0,0,0.1); 
                border-radius: 50%;
                border-top-color: #07d; 
                animation: spin 1s ease-in-out infinite;
                margin: 20px auto;
            }
            @keyframes spin { to { transform: rotate(360deg); } }
        </style>
    </head>
    <body>
        <div class="container">
            <h1 class="success">Admin Login erfolgreich!</h1>
            <p>Sie werden zum Magento Admin-Bereich weitergeleitet...</p>
            <div class="spinner"></div>
            <p>Falls die automatische Weiterleitung nicht funktioniert:</p>
            <form id="redirectForm" method="GET" action="' . htmlspecialchars($adminUrl) . '">
                <input type="hidden" name="form_key" value="' . htmlspecialchars($formKey) . '">
                <button type="submit">Zum Admin-Bereich</button>
            </form>
        </div>
        <script>
            try {
                localStorage.setItem("form_key", "' . $formKey . '");
                console.log("Form Key im localStorage gesetzt");
            } catch(e) {
                console.error("Fehler beim Setzen des Form Keys:", e);
            }
            
            // Verzögerung für Cookie-Verarbeitung
            setTimeout(function() {
                document.getElementById("redirectForm").submit();
            }, 1500);
        </script>
    </body>
    </html>';
                
                $this->getResponse()->setBody($html);
                return $this->getResponse();
                
            } else {
                error_log("performAdminLogin returned FALSE");
                $this->oauthUtility->customlog("Admin login failed - redirecting to admin login");
                
                $this->messageManager->addErrorMessage(
                    __('Admin login via OIDC failed. Please contact administrator.')
                );
                
                $loginUrl = $this->backendUrl->getUrl('admin');
                $this->getResponse()->setRedirect($loginUrl);
                return $this->getResponse();
            }
        }
        
        // Kein Admin-User, normaler Flow
        $this->oauthUtility->customlog("Not admin user, proceeding with normal flow");
        return $this->moOAuthCheckMapping($attrs, $flattenedAttrs, $userEmail);
    }
    
    private function isAdminUser($email)
    {
        $this->oauthUtility->customlog("isAdminUser: Checking email: " . $email);
        
        // Versuche Username
        $user = $this->userFactory->create()->loadByUsername($email);
        if ($user && $user->getId()) {
            $this->oauthUtility->customlog("Found admin by username, ID: " . $user->getId());
            return true;
        }
        
        // Versuche E-Mail
        $userCollection = $this->userFactory->create()->getCollection()
            ->addFieldToFilter('email', $email);
        
        $found = ($userCollection->getSize() > 0);
        $this->oauthUtility->customlog("Found admin by email: " . ($found ? 'YES' : 'NO'));
        
        return $found;
    }

    private function performAdminLogin($userEmail)
    {
        try {
            error_log("=== performAdminLogin START for: " . $userEmail);
            
            // WICHTIG: PHP Session starten
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
                error_log("PHP session started: " . session_id());
            }
            
            // Finde Admin-User per Email
            $userCollection = $this->userFactory->create()->getCollection()
                ->addFieldToFilter('email', $userEmail);
            
            if ($userCollection->getSize() === 0) {
                error_log("ERROR: User not found by email!");
                return false;
            }
            
            $user = $userCollection->getFirstItem();
            $userId = $user->getId();
            error_log("User found - ID: " . $userId);
            
            if (!$user->getIsActive()) {
                error_log("ERROR: User is inactive!");
                return false;
            }
            
            // Admin-Session im aktuellen Context setzen
            error_log("Setting admin session in current context");
            $this->adminSession->setUser($user);
            $this->adminSession->processLogin();
            
            // Form Key generieren
            $formKey = $this->adminSession->getFormKey();
            if (empty($formKey)) {
                $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                $formKeyGenerator = $objectManager->get(\Magento\Framework\Data\Form\FormKey::class);
                $formKey = $formKeyGenerator->getFormKey();
                if (empty($formKey)) {
                    $formKey = md5(uniqid(rand(), true));
                }
                $this->adminSession->setData('form_key', $formKey);
            }
            
            error_log("Final form key to use: " . $formKey);
            
            // ACL laden
            $this->adminSession->refreshAcl();
            
            // Session explizit speichern ABER NICHT schließen!
            $this->adminSession->writeClose();
            
            // WICHTIG: Hole die Session-ID NACH writeClose()
            $magentoSessionId = $this->adminSession->getSessionId();
            $phpSessionId = session_id();
            
            error_log("Magento Session ID: " . $magentoSessionId);
            error_log("PHP Session ID: " . $phpSessionId);
            
            // Verwende die PHP Session ID für Cookies
            $sessionIdToUse = !empty($phpSessionId) ? $phpSessionId : $magentoSessionId;
            
            // Session-Cookies setzen
            $this->setAdminCookies($sessionIdToUse, $formKey);
            
            // WICHTIG: Speichere Form Key für URL-Generierung
            $this->adminFormKey = $formKey;
            
            // ===== DEBUG-CODE HIER EINFÜGEN =====
            // Session-Daten in Datei prüfen
            $sessionPath = BP . '/var/session';
            if (!is_dir($sessionPath)) {
                $sessionPath = session_save_path();
            }
            
            $sessionFile = $sessionPath . '/sess_' . $sessionIdToUse;
            error_log("Expected session file: " . $sessionFile);
            error_log("Session file exists: " . (file_exists($sessionFile) ? 'YES' : 'NO'));
            
            if (file_exists($sessionFile)) {
                $content = file_get_contents($sessionFile);
                error_log("Session file size: " . strlen($content) . " bytes");
                error_log("Session file content (first 200 chars): " . substr($content, 0, 200));
            }
            // ===== ENDE DEBUG-CODE =====
            
            error_log("=== performAdminLogin: SUCCESS ===");
            return true;
            
        } catch (\Exception $e) {
            error_log("performAdminLogin EXCEPTION: " . $e->getMessage());
            error_log("Stack: " . $e->getTraceAsString());
            return false;
        }
    }

    private function setAdminCookies($sessionId, $formKey)
    {
        try {
            error_log("Setting admin cookies...");
            error_log("  SessionId: " . $sessionId);
            error_log("  FormKey: " . $formKey);
            
            $domain = $_SERVER['HTTP_HOST'] ?? '';
            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            
            // Verwende setcookie() wie in direct-admin-login.php
            $cookieOptions = [
                'expires' => time() + 3600,
                'path' => '/',
                'domain' => $domain,
                'secure' => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax'
            ];
            
            // 1. PHPSESSID
            setcookie('PHPSESSID', $sessionId, $cookieOptions);
            error_log("Set cookie: PHPSESSID=" . $sessionId);
            
            // 2. Admin Cookie
            $adminPath = $this->adminConfig->getValue('admin/url/use_custom_path')
                ? $this->adminConfig->getValue('admin/url/custom_path')
                : 'admin';
                
            $adminCookieOptions = $cookieOptions;
            $adminCookieOptions['path'] = '/' . $adminPath;
            setcookie('admin', $sessionId, $adminCookieOptions);
            error_log("Set cookie: admin=" . $sessionId . " (path: /" . $adminPath . ")");
            
            // 3. Form Key Cookie
            $formKeyCookieOptions = $cookieOptions;
            $formKeyCookieOptions['httponly'] = false; // JavaScript braucht Zugriff
            setcookie('form_key', $formKey, $formKeyCookieOptions);
            error_log("Set cookie: form_key=" . $formKey);
            
            error_log("All admin cookies set successfully");
            
        } catch (\Exception $e) {
            error_log("setAdminCookies EXCEPTION: " . $e->getMessage());
        }
    }

    private function moOAuthCheckMapping($attrs, $flattenedAttrs, $userEmail)
    {
        $this->oauthUtility->customlog("moOAuthCheckMapping: START");
       
        if (empty($attrs)) {
            throw new MissingAttributesException;
        }

        $this->checkIfMatchBy = OAuthConstants::DEFAULT_MAP_BY;
        $this->processUserName($flattenedAttrs);
        $this->processEmail($flattenedAttrs);
        $this->processGroupName($flattenedAttrs);

        return $this->processResult($attrs, $flattenedAttrs, $userEmail);
    }

    private function processResult($attrs, $flattenedattrs, $email)
    {
        $this->oauthUtility->customlog("processResult: START");
     
        $isTest = $this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST);

        if ($isTest == true) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::IS_TEST, false);
            $this->oauthUtility->flushCache();
            return $this->testAction->setAttrs($flattenedattrs)->setUserEmail($email)->execute();
        } else {
            return $this->processUserAction->setFlattenedAttrs($flattenedattrs)->setAttrs($attrs)->setUserEmail($email)->execute();
        }
    }

    private function processFirstName(&$attrs)
    {
        if (!isset($attrs[$this->firstName])) {
            $parts = explode("@", $this->userEmail);
            $attrs[$this->firstName] = $parts[0];
        }
    }

    private function processLastName(&$attrs)
    {
        if (!isset($attrs[$this->lastName])) {
            $parts = explode("@", $this->userEmail);
            $attrs[$this->lastName] = isset($parts[1]) ? $parts[1] : '';
        }
    }

    private function processUserName(&$attrs)
    {
        if (!isset($attrs[$this->usernameAttribute])) {
            $attrs[$this->usernameAttribute] = $this->userEmail;
        }
    }

    private function processEmail(&$attrs)
    {
        if (!isset($attrs[$this->emailAttribute])) {
            $attrs[$this->emailAttribute] = $this->userEmail;
        }
    }

    private function processGroupName(&$attrs)
    {
        if (!isset($attrs[$this->groupName])) {
            $this->groupName = [];
        }
    }

    public function setUserInfoResponse($userInfoResponse)
    {
        $this->userInfoResponse = $userInfoResponse;
        return $this;
    }

    public function setFlattenedUserInfoResponse($flattenedUserInfoResponse)
    {
        $this->flattenedUserInfoResponse = $flattenedUserInfoResponse;
        return $this;
    }

    public function setUserEmail($userEmail)
    {
        $this->userEmail = $userEmail;
        return $this;
    }

    public function setRelayState($relayState)
    {
        $this->relayState = $relayState;
        return $this;
    }
}

