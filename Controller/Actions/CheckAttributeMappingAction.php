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
                
                // Nutze den Form Key aus performAdminLogin
                if (!empty($this->adminFormKey)) {
                    $adminUrl = $this->backendUrl->getUrl('admin/index/index', [
                        'key' => $this->adminFormKey
                    ]);
                    error_log("Using saved form key for URL: " . $this->adminFormKey);
                } else {
                    // Fallback ohne key
                $adminUrl = $this->backendUrl->getUrl('admin/index/index');                    
                error_log("No form key available, using URL without key");
                }
                
                error_log("Redirecting to: " . $adminUrl);
                
                // Direkter Redirect
                $this->getResponse()->setRedirect($adminUrl);
                return $this->getResponse();
                
            } else {
                // Admin login fehlgeschlagen
                error_log("performAdminLogin returned FALSE");
                $this->oauthUtility->customlog("Admin login failed - redirecting to admin login");
                
                $this->messageManager->addErrorMessage(
                    __('Admin login via OIDC failed. Please contact administrator.')
                );
                
                // Redirect zur Admin-Login-Seite
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
            
            // Session ID holen
            $sessionId = $this->adminSession->getSessionId();
            error_log("Session ID: " . $sessionId);
            
            if (empty($sessionId)) {
                error_log("ERROR: No session ID generated!");
                return false;
            }
            
            // WICHTIG: Versuche zuerst Form Key aus Admin Session
            $formKey = $this->adminSession->getFormKey();
            error_log("Admin session form key: '" . $formKey . "'");
            
            // Falls leer, generiere manuell
            if (empty($formKey)) {
                error_log("Admin session form key empty, generating manual key...");
                $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                $formKeyGenerator = $objectManager->get(\Magento\Framework\Data\Form\FormKey::class);
                $formKey = $formKeyGenerator->getFormKey();
                
                // Falls immer noch leer, erzeuge eigenen
                if (empty($formKey)) {
                    $formKey = md5(uniqid(rand(), true));
                    error_log("Generated random form key: " . $formKey);
                }
                
                // Setze in Admin Session zurück
                $this->adminSession->setData('form_key', $formKey);
            }
            
            error_log("Final form key to use: " . $formKey);
            
            // ACL laden
            $this->adminSession->refreshAcl();
            
            // Session explizit speichern
            $this->adminSession->writeClose();
            error_log("Session written and closed");
            
            // Session-Cookies setzen
            $this->setAdminCookies($sessionId, $formKey);
            
            // WICHTIG: Speichere Form Key für URL-Generierung
            $this->adminFormKey = $formKey;
            
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
            
            // 1. Admin-Session-Cookie (PHPSESSID)
            $adminCookieName = $this->adminSession->getName();
            error_log("Admin cookie name: " . $adminCookieName);
            
            $cookieMetadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                ->setDuration(3600)
                ->setPath($this->adminConfig->getCookiePath())
                ->setDomain($this->adminConfig->getCookieDomain())
                ->setSecure($this->adminConfig->getCookieSecure())
                ->setHttpOnly($this->adminConfig->getCookieHttpOnly());
            
            if (method_exists($cookieMetadata, 'setSameSite')) {
                $cookieMetadata->setSameSite('Lax');
            }
            
            $this->cookieManager->setPublicCookie($adminCookieName, $sessionId, $cookieMetadata);
            error_log("Set cookie: " . $adminCookieName . "=" . $sessionId);
            
            // 2. Admin-spezifisches Cookie (falls anders als PHPSESSID)
            // Admin-Backend nutzt oft ein separates "admin" Cookie
            $adminPath = $this->adminConfig->getCookiePath();
            if ($adminCookieName === 'PHPSESSID' && !empty($adminPath)) {
                $adminCookieMetadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                    ->setDuration(3600)
                    ->setPath($adminPath)
                    ->setDomain($this->adminConfig->getCookieDomain())
                    ->setSecure($this->adminConfig->getCookieSecure())
                    ->setHttpOnly(true);
                
                if (method_exists($adminCookieMetadata, 'setSameSite')) {
                    $adminCookieMetadata->setSameSite('Lax');
                }
                
                $this->cookieManager->setPublicCookie('admin', $sessionId, $adminCookieMetadata);
                error_log("Set cookie: admin=" . $sessionId . " (path: " . $adminPath . ")");
            }
            
            // 3. Form-Key-Cookie (NUR wenn nicht leer!)
            if (!empty($formKey)) {
                $formKeyCookieMetadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                    ->setDuration(3600)
                    ->setPath('/')
                    ->setDomain($domain)
                    ->setSecure($isSecure)
                    ->setHttpOnly(false); // JavaScript-Zugriff erforderlich
                
                if (method_exists($formKeyCookieMetadata, 'setSameSite')) {
                    $formKeyCookieMetadata->setSameSite('Lax');
                }
                
                $this->cookieManager->setPublicCookie('form_key', $formKey, $formKeyCookieMetadata);
                error_log("Set cookie: form_key=" . $formKey);
            } else {
                error_log("WARNING: form_key is empty, cookie not set!");
            }
            
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

