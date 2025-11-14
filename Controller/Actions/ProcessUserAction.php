<?php
namespace MiniOrange\OAuth\Controller\Actions;

use Magento\Authorization\Model\ResourceModel\Role\Collection;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseFactory;
use Magento\Framework\Math\Random;
use Magento\Store\Model\StoreManagerInterface;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;
use MiniOrange\OAuth\Helper\Exception\MissingAttributesException;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Helper\OAuthMessages;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Action\Action;
use MiniOrange\OAuth\Helper\AdminAuthHelper;
use Magento\Framework\Stdlib\DateTime\dateTime;

class ProcessUserAction extends Action
{
    private $attrs;
    private $flattenedattrs;
    private $userEmail;
    private $checkIfMatchBy;
    private $defaultRole;
    private $emailAttribute;
    private $usernameAttribute;
    private $firstNameKey;
    private $lastNameKey;
    private $userGroupModel;
    private $adminRoleModel;
    private $adminUserModel;
    private $customerModel;
    private $customerLoginAction;
    private $responseFactory;
    private $customerFactory;
    private $userFactory;
    private $randomUtility;
    private $storeManager;
    protected $scopeConfig;
    protected $dateTime;
    protected $adminAuthHelper;
    protected $oauthUtility;


    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        Collection $userGroupModel,
        Collection $adminRoleModel,
        User $adminUserModel,
        Customer $customerModel,
        StoreManagerInterface $storeManager,
        ResponseFactory $responseFactory,
        CustomerLoginAction $customerLoginAction,
        CustomerFactory $customerFactory,
        UserFactory $userFactory,
        Random $randomUtility,
        dateTime $dateTime,
        ScopeConfigInterface $scopeConfig,
        AdminAuthHelper $adminAuthHelper
    ) {
        $this->emailAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_EMAIL);
        $this->emailAttribute = $oauthUtility->isBlank($this->emailAttribute) ? OAuthConstants::DEFAULT_MAP_EMAIL : $this->emailAttribute;
        $this->usernameAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_USERNAME);
        $this->usernameAttribute = $oauthUtility->isBlank($this->usernameAttribute) ? OAuthConstants::DEFAULT_MAP_USERN : $this->usernameAttribute;
        $this->firstNameKey = $oauthUtility->getStoreConfig(OAuthConstants::MAP_FIRSTNAME);
        $this->firstNameKey = $oauthUtility->isBlank($this->firstNameKey) ? OAuthConstants::DEFAULT_MAP_FN : $this->firstNameKey;
        $this->lastNameKey = $oauthUtility->getStoreConfig(OAuthConstants::MAP_LASTNAME);
        $this->lastNameKey = $oauthUtility->isBlank($this->lastNameKey) ? OAuthConstants::DEFAULT_MAP_LN : $this->lastNameKey;
        $this->defaultRole = $oauthUtility->getStoreConfig(OAuthConstants::MAP_DEFAULT_ROLE);
        $this->checkIfMatchBy = $oauthUtility->getStoreConfig(OAuthConstants::MAP_MAP_BY);
        $this->userGroupModel = $userGroupModel;
        $this->adminRoleModel = $adminRoleModel;
        $this->adminUserModel = $adminUserModel;
        $this->customerModel = $customerModel;
        $this->storeManager = $storeManager;
        $this->checkIfMatchBy = $oauthUtility->getStoreConfig(OAuthConstants::MAP_MAP_BY);
        $this->responseFactory = $responseFactory;
        $this->customerLoginAction = $customerLoginAction;
        $this->customerFactory = $customerFactory;
        $this->userFactory = $userFactory;
        $this->randomUtility = $randomUtility;
        $this->scopeConfig = $scopeConfig;
        $this->dateTime = $dateTime;
        $this->adminAuthHelper = $adminAuthHelper;
        $this->oauthUtility = $oauthUtility;
        parent::__construct($context, $oauthUtility);
    }

    public function execute()
    {
        try {
            $this->oauthUtility->customlog("ProcessUserAction: execute");
            if (empty($this->attrs)) {
                $this->oauthUtility->customlog("No Attributes Received");
                throw new MissingAttributesException;
            }

            $firstName = $this->flattenedattrs[$this->firstNameKey] ?? null;
            $lastName = $this->flattenedattrs[$this->lastNameKey] ?? null;
            $userName = $this->flattenedattrs[$this->usernameAttribute] ?? null;

            $this->oauthUtility->customlog("ProcessUserAction: first name: " . $firstName);
            $this->oauthUtility->customlog("ProcessUserAction: last name: " . $lastName);
            $this->oauthUtility->customlog("ProcessUserAction: username: " . $userName);

            if ($this->oauthUtility->isBlank($this->defaultRole)) {
                $this->defaultRole = OAuthConstants::DEFAULT_ROLE;
            }

            $this->processUserAction($this->userEmail, $firstName, $lastName, $userName, $this->defaultRole);

        } catch (\Exception $e) {
            $this->oauthUtility->customlog("CRITICAL ERROR in execute: " . $e->getMessage());
            throw $e;
        }
    }

    private function processUserAction($user_email, $firstName, $lastName, $userName, $defaultRole)
    {
        $admin = false;
        $user = $this->getCustomerFromAttributes($user_email);

        //Todo_MK: Umbau von Counter auf Auto Create Config
        if (!$user) {
            $this->oauthUtility->customlog("User Not found. Inside autocreate user tab");
            $donotCreateUsers = $this->oauthUtility->getStoreConfig(OAuthConstants::MAGENTO_COUNTER);

            if (is_null($donotCreateUsers)) {
                $this->oauthUtility->setStoreConfig(OAuthConstants::MAGENTO_COUNTER, 10);
                $this->oauthUtility->reinitConfig();
                $donotCreateUsers = $this->oauthUtility->getStoreConfig(OAuthConstants::MAGENTO_COUNTER);
            }

            if ($donotCreateUsers < 1) {
                $this->oauthUtility->customlog("Auto Create User Limit exceeded");
                // [Auto-create limit logic bleibt gleich - gekürzt für Übersicht]
                $this->messageManager->addErrorMessage(OAuthMessages::AUTO_CREATE_USER_LIMIT);
                $url = $this->oauthUtility->getCustomerLoginUrl();
                return $this->getResponse()->setRedirect($url)->sendResponse();
            } else {
                $count = $this->oauthUtility->getStoreConfig(OAuthConstants::MAGENTO_COUNTER);
                $this->oauthUtility->setStoreConfig(OAuthConstants::MAGENTO_COUNTER, $count - 1);
                $this->oauthUtility->reinitConfig();
                $this->oauthUtility->customlog("Creating new customer");
                $user = $this->createNewUser($user_email, $firstName, $lastName, $userName, $user, $admin);
                $this->oauthUtility->customlog("processUserAction: user created");
            }
        } else {
            $this->oauthUtility->customlog("processUserAction: User Found");
        }

        $this->oauthUtility->customlog("processUserAction: redirecting user");

        $store_url = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
        $store_url = rtrim($store_url, '/\\');

        if (isset($this->attrs['relayState']) && !str_contains($this->attrs['relayState'], $store_url) && $this->attrs['relayState'] != '/') {
            $this->attrs['relayState'] = $store_url;
            $this->oauthUtility->customlog("processUserAction: changing relayState with store url " . $store_url);
        }

        $isAdminLogin = false;
        $relayState = '';

        if (is_array($this->attrs) && isset($this->attrs['relayState'])) {
            $relayState = $this->attrs['relayState'];
        } elseif (isset($this->attrs->relayState)) {
            $relayState = $this->attrs->relayState;
        }

        if (!empty($relayState)) {
            $isAdminLogin = (strpos($relayState, '/admin') !== false) ||
                (strpos($relayState, 'admin/') !== false) ||
                (strpos($relayState, 'admin') === 0);
            $this->oauthUtility->customlog("processUserAction: Relay state: " . $relayState . ", isAdmin: " . ($isAdminLogin ? "true" : "false"));
        }

        if ($isAdminLogin) {
            $this->oauthUtility->customlog("processUserAction: Processing as admin login");

            try {

                $adminUser = $this->getAdminUserByEmail($user_email);
                if ($adminUser && $adminUser->getIsActive() == 1) {
                    $this->oauthUtility->customlog("processUserAction: Admin user found and is active");

                    $redirectUrl = $this->adminAuthHelper->getStandaloneLoginUrl($user_email, $relayState);

                    $this->oauthUtility->customlog("processUserAction: Redirecting to: " . $redirectUrl);
                    $this->getResponse()->setRedirect($redirectUrl)->sendResponse();
                    return;
                } else {
                    $this->oauthUtility->customlog("processUserAction: Admin user not found for email: " . $user_email);

                    //Todo_MK: Umbau wenn Auto Create Admin aktiviert ist, dann Admin User erstellen
                    $errorMessage = sprintf(
                        'Admin-Zugang verweigert: Für die E-Mail-Adresse "%s" ist kein Administrator-Konto in Magento hinterlegt. Bitte wenden Sie sich an Ihren Systemadministrator.',
                        $user_email
                    );

                    $loginUrl = $this->oauthUtility->getBaseUrl() . 'admin?oidc_error=' . base64_encode($errorMessage);

                    $this->oauthUtility->customlog("processUserAction: Redirecting to admin login with error");
                    return $this->getResponse()->setRedirect($loginUrl)->sendResponse();
                }
            } catch (\Exception $e) {
                $this->oauthUtility->customlog("processUserAction: Exception during admin login: " . $e->getMessage());

                $errorMessage = 'Die Anmeldung über Authelia ist fehlgeschlagen. Bitte versuchen Sie es erneut oder wenden Sie sich an Ihren Administrator.';
                $loginUrl = $this->oauthUtility->getBaseUrl() . 'admin?oidc_error=' . base64_encode($errorMessage);

                return $this->getResponse()->setRedirect($loginUrl)->sendResponse();
            }
        } else {
            // Standard customer login flow
            $this->oauthUtility->customlog("processUserAction: Processing as customer login");
            if ($this->oauthUtility->getSessionData('guest_checkout')) {
                $this->oauthUtility->setSessionData('guest_checkout', NULL);
                $this->customerLoginAction->setUser($user)->setRelayState($this->oauthUtility->getBaseUrl() . 'checkout')->execute();
            } else if (is_array($this->attrs)) {
                $this->customerLoginAction->setUser($user)->setRelayState($this->attrs['relayState'])->execute();
            } else {
                $this->customerLoginAction->setUser($user)->setRelayState($this->attrs->relayState)->execute();
            }
        }
    }

    private function generateEmail($userName)
    {
        $this->oauthUtility->customlog("processUserAction: generateEmail");
        $siteurl = $this->oauthUtility->getBaseUrl();
        $siteurl = substr($siteurl, strpos($siteurl, '//'), strlen($siteurl) - 1);
        return $userName . '@' . $siteurl;
    }

    //to do extend with additional user creation logic and fields
    //additionally handle admin user creation if required
    private function createNewUser($user_email, $firstName, $lastName, $userName, $user, &$admin)
    {
        $this->oauthUtility->customlog("processUserAction: createNewUser");

        if (empty($firstName)) {
            $parts = explode("@", $user_email);
            $firstName = $parts[0];
        }

        if (empty($lastName)) {
            $parts = explode("@", $user_email);
            $lastName = $parts[1];
        }

        $random_password = $this->randomUtility->getRandomString(8);
        $userName = !$this->oauthUtility->isBlank($userName) ? $userName : $user_email;
        $firstName = !$this->oauthUtility->isBlank($firstName) ? $firstName : $userName;
        $lastName = !$this->oauthUtility->isBlank($lastName) ? $lastName : $userName;

        $user = $this->createCustomer($userName, $user_email, $firstName, $lastName, $random_password);
        return $user;
    }

    private function createCustomer($userName, $email, $firstName, $lastName, $random_password)
    {
        $this->oauthUtility->customlog("processUserAction: createCustomer");
        $websiteId = $this->storeManager->getWebsite()->getWebsiteId();
        $store = $this->storeManager->getStore();
        $storeId = $store->getStoreId();

        $this->oauthUtility->customlog("processUserAction: websiteID: " . $websiteId . " email: " . $email . " firstName: " . $firstName . " lastName: " . $lastName);

        return $this->customerFactory->create()
            ->setWebsiteId($websiteId)
            ->setEmail($email)
            ->setFirstname($firstName)
            ->setLastname($lastName)
            ->setPassword($random_password)
            ->save();
    }

    private function getCustomerFromAttributes($user_email)
    {
        $this->oauthUtility->customlog("processUserAction: getCustomerFromAttributes");
        $this->customerModel->setWebsiteId($this->storeManager->getStore()->getWebsiteId());
        $customer = $this->customerModel->loadByEmail($user_email);
        return !is_null($customer->getId()) ? $customer : false;
    }

    public function setAttrs($attrs)
    {
        $this->attrs = $attrs;
        return $this;
    }

    public function setFlattenedAttrs($flattenedattrs)
    {
        $this->flattenedattrs = $flattenedattrs;
        return $this;
    }

    public function setUserEmail($userEmail)
    {
        $this->userEmail = $userEmail;
        return $this;
    }

    private function getAdminUserByEmail($email)
    {
        $this->oauthUtility->customlog("processUserAction: getAdminUserByEmail for " . $email);

        try {
            // Zuerst nach Benutzername suchen
            $user = $this->userFactory->create()->loadByUsername($email);
            if ($user->getId()) {
                $this->oauthUtility->customlog("processUserAction: Found admin user with ID " . $user->getId() . ", Username: " . $user->getUsername() . ", Is Active: " . $user->getIsActive());
                return $user;
            }

            // Wenn nicht gefunden, nach E-Mail-Adresse suchen
            $collection = $this->userFactory->create()->getCollection()
                ->addFieldToFilter('email', $email);

            if ($collection->getSize() > 0) {
                $user = $collection->getFirstItem();
                $this->oauthUtility->customlog("processUserAction: Found admin user by email with ID " . $user->getId() . ", Username: " . $user->getUsername() . ", Is Active: " . $user->getIsActive());

                // Prüfen, ob Benutzer aktiv ist
                if ($user->getIsActive() != 1) {
                    $this->oauthUtility->customlog("processUserAction: Admin user found but is not active");
                }

                return $user;
            }

            $this->oauthUtility->customlog("processUserAction: No admin user found for email " . $email);
            return false;

        } catch (\Exception $e) {
            $this->oauthUtility->customlog("processUserAction: Error finding admin user: " . $e->getMessage());
            return false;
        }
    }

    // die Funktion muss ggf. erweitert werden, um die Rollenzuweisung zu unterstützen
    // was ist, wenn die Rolle nicht existiert? // welche Rolle
    private function createAdminUser($userName, $email, $firstName, $lastName, $random_password, $role_assigned)
    {
        $this->oauthUtility->customlog("processUserAction: Creating Admin user");

        $user = $this->userFactory->create();
        $user->setUsername($userName)
            ->setFirstname($firstName)
            ->setLastname($lastName)
            ->setEmail($email)
            ->setPassword($random_password)
            ->setIsActive(1);
        $user->save();

        $this->setAdminUserRole($role_assigned, $user);

        return $user;
    }

    private function setAdminUserRole($role_assigned, $user)
    {
        $this->oauthUtility->customlog("processUserAction: Set Admin User Role");
        $this->oauthUtility->customlog("processUserAction: role ID: " . $role_assigned);

        $user->setRoleId($role_assigned);
        $user->save();

        return $user;
    }
}
