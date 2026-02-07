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
use Magento\Framework\Stdlib\DateTime\dateTime;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Directory\Model\CountryFactory;

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
    protected $oauthUtility;

    // Customer data mapping attributes
    private $dobAttribute;
    private $genderAttribute;
    private $phoneAttribute;
    private $streetAttribute;
    private $zipAttribute;
    private $cityAttribute;
    private $countryAttribute;
    protected $addressRepository;
    protected $addressFactory;
    protected $countryFactory;


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
        AddressRepositoryInterface $addressRepository,
        AddressInterfaceFactory $addressFactory,
        CountryFactory $countryFactory
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

        // Initialize customer data mapping attributes
        $this->dobAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_DOB);
        $this->dobAttribute = $oauthUtility->isBlank($this->dobAttribute) ? OAuthConstants::DEFAULT_MAP_DOB : $this->dobAttribute;
        $this->genderAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_GENDER);
        $this->genderAttribute = $oauthUtility->isBlank($this->genderAttribute) ? OAuthConstants::DEFAULT_MAP_GENDER : $this->genderAttribute;
        $this->phoneAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_PHONE);
        $this->phoneAttribute = $oauthUtility->isBlank($this->phoneAttribute) ? OAuthConstants::DEFAULT_MAP_PHONE : $this->phoneAttribute;
        $this->streetAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_STREET);
        $this->streetAttribute = $oauthUtility->isBlank($this->streetAttribute) ? OAuthConstants::DEFAULT_MAP_STREET : $this->streetAttribute;
        $this->zipAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_ZIP);
        $this->zipAttribute = $oauthUtility->isBlank($this->zipAttribute) ? OAuthConstants::DEFAULT_MAP_ZIP : $this->zipAttribute;
        $this->cityAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_CITY);
        $this->cityAttribute = $oauthUtility->isBlank($this->cityAttribute) ? OAuthConstants::DEFAULT_MAP_CITY : $this->cityAttribute;
        $this->countryAttribute = $oauthUtility->getStoreConfig(OAuthConstants::MAP_COUNTRY);
        $this->countryAttribute = $oauthUtility->isBlank($this->countryAttribute) ? OAuthConstants::DEFAULT_MAP_COUNTRY : $this->countryAttribute;

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
        $this->oauthUtility = $oauthUtility;
        $this->addressRepository = $addressRepository;
        $this->addressFactory = $addressFactory;
        $this->countryFactory = $countryFactory;
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

            if ($this->oauthUtility->isBlank($this->defaultRole)) {
                $this->defaultRole = OAuthConstants::DEFAULT_ROLE;
            }

            $this->processUserAction($this->userEmail, $firstName, $lastName, $userName, $this->defaultRole);

        } catch (MissingAttributesException $e) {
            $this->oauthUtility->customlog("ERROR: Missing required attributes from OAuth provider");
            $this->messageManager->addErrorMessage(__('Authentication failed: Required user information not received from identity provider.'));
            return $this->resultRedirectFactory->create()->setPath('customer/account/login');
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("CRITICAL ERROR in execute: " . $e->getMessage());
            throw $e;
        }
    }

    private function processUserAction($user_email, $firstName, $lastName, $userName, $defaultRole)
    {
        $admin = false;
        $user = $this->getCustomerFromAttributes($user_email);

        if (!$user) {
            $this->oauthUtility->customlog("User not found. Checking auto-create configuration");

            // Check if auto-create customer is enabled in configuration
            $autoCreateEnabled = $this->oauthUtility->getStoreConfig(OAuthConstants::AUTO_CREATE_CUSTOMER);

            if (!$autoCreateEnabled) {
                $this->oauthUtility->customlog("Auto Create Customer is disabled. Rejecting login.");
                // Use same error handling pattern as other OIDC errors (oidc_error query param)
                $encodedError = base64_encode(OAuthMessages::AUTO_CREATE_USER_DISABLED);
                $loginUrl = $this->oauthUtility->getCustomerLoginUrl();
                $loginUrl .= (strpos($loginUrl, '?') !== false ? '&' : '?') . 'oidc_error=' . $encodedError;
                return $this->getResponse()->setRedirect($loginUrl)->sendResponse();
            }

            $user = $this->createNewUser($user_email, $firstName, $lastName, $userName, $user, $admin);
        }

        $store_url = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
        $store_url = rtrim($store_url, '/\\');

        if (isset($this->attrs['relayState']) && !str_contains($this->attrs['relayState'], $store_url) && $this->attrs['relayState'] != '/') {
            $this->attrs['relayState'] = $store_url;
            $this->oauthUtility->customlog("processUserAction: changing relayState with store url " . $store_url);
        }

        // Customer login flow (admin routing is now handled by CheckAttributeMappingAction)
        $this->oauthUtility->customlog("processUserAction: Processing as customer login");

        $relayState = '';
        if (is_array($this->attrs) && isset($this->attrs['relayState'])) {
            $relayState = $this->attrs['relayState'];
        } elseif (isset($this->attrs->relayState)) {
            $relayState = $this->attrs->relayState;
        }

        if ($this->oauthUtility->getSessionData('guest_checkout')) {
            $this->oauthUtility->setSessionData('guest_checkout', NULL);
            $this->customerLoginAction->setUser($user)->setRelayState($this->oauthUtility->getBaseUrl() . 'checkout')->execute();
        } else if (!empty($relayState)) {
            $this->customerLoginAction->setUser($user)->setRelayState($relayState)->execute();
        } else {
            $this->customerLoginAction->setUser($user)->setRelayState('/')->execute();
        }
    }

    private function generateEmail($userName)
    {
        $this->oauthUtility->customlog("processUserAction: generateEmail");
        $siteurl = $this->oauthUtility->getBaseUrl();
        $siteurl = substr($siteurl, strpos($siteurl, '//'), strlen($siteurl) - 1);
        return $userName . '@' . $siteurl;
    }

    //additionally handle admin user creation if required
    private function createNewUser($user_email, $firstName, $lastName, $userName, $user, &$admin)
    {

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
        $websiteId = $this->storeManager->getWebsite()->getWebsiteId();

        // Create customer with basic data
        $customer = $this->customerFactory->create()
            ->setWebsiteId($websiteId)
            ->setEmail($email)
            ->setFirstname($firstName)
            ->setLastname($lastName)
            ->setPassword($random_password);

        // Set Date of Birth if mapped and available
        $dob = $this->extractAttributeValue($this->dobAttribute);
        if (!empty($dob)) {
            $formattedDob = $this->formatDateOfBirth($dob);
            if ($formattedDob) {
                $customer->setDob($formattedDob);
            }
        }

        // Set Gender if mapped and available
        $gender = $this->extractAttributeValue($this->genderAttribute);
        if (!empty($gender)) {
            $genderId = $this->mapGender($gender);
            if ($genderId !== null) {
                $customer->setGender($genderId);
            }
        }

        $customer->save();

        // Create customer address if address fields are configured and available
        $this->createCustomerAddress($customer, $firstName, $lastName);

        return $customer;
    }

    /**
     * Extract attribute value from flattened attributes with support for nested paths (e.g., "address.locality")
     */
    private function extractAttributeValue($key)
    {
        if (empty($key) || empty($this->flattenedattrs)) {
            return null;
        }

        // First check if it exists directly in flattened attributes
        if (isset($this->flattenedattrs[$key])) {
            return $this->flattenedattrs[$key];
        }

        // Support nested path notation (e.g., "address.locality")
        if (strpos($key, '.') !== false) {
            $parts = explode('.', $key);
            $value = $this->flattenedattrs;
            foreach ($parts as $part) {
                if (is_array($value) && isset($value[$part])) {
                    $value = $value[$part];
                } else {
                    return null;
                }
            }
            return is_string($value) ? $value : null;
        }

        // Also check in raw attrs for nested structure
        if (!empty($this->attrs) && strpos($key, '.') !== false) {
            $parts = explode('.', $key);
            $value = $this->attrs;
            foreach ($parts as $part) {
                if (is_array($value) && isset($value[$part])) {
                    $value = $value[$part];
                } elseif (is_object($value) && isset($value->$part)) {
                    $value = $value->$part;
                } else {
                    return null;
                }
            }
            return is_string($value) ? $value : null;
        }

        return null;
    }

    /**
     * Format date of birth to Y-m-d format
     */
    private function formatDateOfBirth($dob)
    {
        try {
            $date = date_create($dob);
            if ($date) {
                return $date->format('Y-m-d');
            }
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("DOB parsing exception: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Map OIDC gender value to Magento gender ID
     * Magento: 1 = Male, 2 = Female, 3 = Not Specified
     */
    private function mapGender($genderValue)
    {
        if (empty($genderValue)) {
            return null;
        }

        $genderLower = strtolower(trim($genderValue));

        if (in_array($genderLower, ['male', 'm', '1', 'mann', 'männlich'])) {
            return 1;
        }
        if (in_array($genderLower, ['female', 'f', '2', 'frau', 'weiblich'])) {
            return 2;
        }

        // Not Specified for any other value
        return 3;
    }

    /**
     * Create customer address with mapped OIDC attributes
     */
    private function createCustomerAddress($customer, $firstName, $lastName)
    {
        // Extract address fields
        $street = $this->extractAttributeValue($this->streetAttribute);
        $city = $this->extractAttributeValue($this->cityAttribute);
        $zip = $this->extractAttributeValue($this->zipAttribute);
        $country = $this->extractAttributeValue($this->countryAttribute);
        $phone = $this->extractAttributeValue($this->phoneAttribute);

        // Only create address if at least street and city are provided
        if (empty($street) && empty($city) && empty($country)) {
            return;
        }

        try {
            $countryId = $this->resolveCountryId($country);

            $address = $this->addressFactory->create();
            $address->setCustomerId($customer->getId())
                ->setFirstname($firstName)
                ->setLastname($lastName)
                ->setStreet([$street ?? ''])
                ->setCity($city ?? '')
                ->setPostcode($zip ?? '')
                ->setCountryId($countryId ?? 'US')
                ->setTelephone($phone ?? '')
                ->setIsDefaultBilling(true)
                ->setIsDefaultShipping(true);

            $this->addressRepository->save($address);
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("ERROR creating address: " . $e->getMessage());
        }
    }

    /**
     * Resolve country name or code to Magento country ID (ISO 3166-1 alpha-2)
     */
    private function resolveCountryId($country)
    {
        if (empty($country)) {
            return null;
        }

        // If already a 2-letter code, return as-is (uppercase)
        if (strlen($country) === 2) {
            return strtoupper($country);
        }

        // Try to find country by name
        try {
            $countryCollection = $this->countryFactory->create()->getCollection();
            foreach ($countryCollection as $countryItem) {
                $countryName = $countryItem->getName();
                if ($countryName !== null && strcasecmp($countryName, $country) === 0) {
                    return $countryItem->getId();
                }
            }
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("Error resolving country: " . $e->getMessage());
        }

        // Return the value as-is if it looks like a country code
        if (strlen($country) <= 3) {
            return strtoupper($country);
        }

        return null;
    }

    private function getCustomerFromAttributes($user_email)
    {
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
