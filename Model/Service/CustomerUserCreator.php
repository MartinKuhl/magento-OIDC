<?php

namespace MiniOrange\OAuth\Model\Service;

use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthUtility;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Math\Random;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use Magento\Directory\Helper\Data as DirectoryData;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Customer\Api\Data\CustomerInterface;
use MiniOrange\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

/**
 * Service class for creating Customer Users via OAuth/OIDC
 */
class CustomerUserCreator
{
    /** @var \Magento\Customer\Model\CustomerFactory */
    private readonly \Magento\Customer\Model\CustomerFactory $customerFactory;

    /** @var \Magento\Customer\Api\Data\AddressInterfaceFactory */
    private readonly \Magento\Customer\Api\Data\AddressInterfaceFactory $addressFactory;

    /** @var \Magento\Customer\Api\AddressRepositoryInterface */
    private readonly \Magento\Customer\Api\AddressRepositoryInterface $addressRepository;

    /** @var \Magento\Store\Model\StoreManagerInterface */
    private readonly \Magento\Store\Model\StoreManagerInterface $storeManager;

    /** @var \Magento\Framework\Math\Random */
    private readonly \Magento\Framework\Math\Random $randomUtility;

    /** @var \MiniOrange\OAuth\Helper\OAuthUtility */
    private readonly \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility;

    /** @var CountryCollectionFactory */
    private readonly CountryCollectionFactory $countryCollectionFactory;

    /** @var \Magento\Framework\Stdlib\DateTime\DateTime */
    private readonly \Magento\Framework\Stdlib\DateTime\DateTime $dateTime;

    /** @var DirectoryData */
    private readonly DirectoryData $directoryData;

    // Attribute mapping keys
    /**
     * @var string|null OIDC claim name for date of birth
     */
    private $dobAttribute;
    /**
     * @var string|null OIDC claim name for gender
     */
    private $genderAttribute;
    /**
     * @var string|null OIDC claim name for phone number
     */
    private $phoneAttribute;
    /**
     * @var string|null OIDC claim name for street address
     */
    private $streetAttribute;
    /**
     * @var string|null OIDC claim name for postal/zip code
     */
    private $zipAttribute;
    /**
     * @var string|null OIDC claim name for city/locality
     */
    private $cityAttribute;
    /**
     * @var string|null OIDC claim name for country
     */
    private $countryAttribute;

    /** @var \Magento\Customer\Api\CustomerRepositoryInterface */
    private readonly \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository;

    /** @var \MiniOrange\OAuth\Model\ResourceModel\UserProvider */
    private readonly UserProviderResource $userProviderResource;

    /**
     * Initialize customer user creator service.
     *
     * @param CustomerFactory $customerFactory
     * @param AddressInterfaceFactory $addressFactory
     * @param AddressRepositoryInterface $addressRepository
     * @param StoreManagerInterface $storeManager
     * @param Random $randomUtility
     * @param OAuthUtility $oauthUtility
     * @param CountryCollectionFactory $countryCollectionFactory
     * @param DateTime $dateTime
     * @param DirectoryData $directoryData
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
     * @param UserProviderResource $userProviderResource
     */
    public function __construct(
        CustomerFactory $customerFactory,
        AddressInterfaceFactory $addressFactory,
        AddressRepositoryInterface $addressRepository,
        StoreManagerInterface $storeManager,
        Random $randomUtility,
        OAuthUtility $oauthUtility,
        CountryCollectionFactory $countryCollectionFactory,
        DateTime $dateTime,
        DirectoryData $directoryData,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        UserProviderResource $userProviderResource
    ) {
        $this->customerFactory = $customerFactory;
        $this->addressFactory = $addressFactory;
        $this->addressRepository = $addressRepository;
        $this->storeManager = $storeManager;
        $this->randomUtility = $randomUtility;
        $this->oauthUtility = $oauthUtility;
        $this->countryCollectionFactory = $countryCollectionFactory;
        $this->dateTime = $dateTime;
        $this->directoryData = $directoryData;
        $this->customerRepository = $customerRepository;
        $this->userProviderResource = $userProviderResource;

        $this->initializeAttributeMapping();
    }

    /**
     * Initialize attribute mapping from configuration.
     */
    private function initializeAttributeMapping(): void
    {
        $this->dobAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_DOB);
        $this->dobAttribute = $this->oauthUtility->isBlank($this->dobAttribute)
            ? OAuthConstants::DEFAULT_MAP_DOB : $this->dobAttribute;

        $this->genderAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_GENDER);
        $this->genderAttribute = $this->oauthUtility->isBlank($this->genderAttribute)
            ? OAuthConstants::DEFAULT_MAP_GENDER : $this->genderAttribute;

        $this->phoneAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_PHONE);
        $this->phoneAttribute = $this->oauthUtility->isBlank($this->phoneAttribute)
            ? OAuthConstants::DEFAULT_MAP_PHONE : $this->phoneAttribute;

        $this->streetAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_STREET);
        $this->streetAttribute = $this->oauthUtility->isBlank($this->streetAttribute)
            ? OAuthConstants::DEFAULT_MAP_STREET : $this->streetAttribute;

        $this->zipAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_ZIP);
        $this->zipAttribute = $this->oauthUtility->isBlank($this->zipAttribute)
            ? OAuthConstants::DEFAULT_MAP_ZIP : $this->zipAttribute;

        $this->cityAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_CITY);
        $this->cityAttribute = $this->oauthUtility->isBlank($this->cityAttribute)
            ? OAuthConstants::DEFAULT_MAP_CITY : $this->cityAttribute;

        $this->countryAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_COUNTRY);
        $this->countryAttribute = $this->oauthUtility->isBlank($this->countryAttribute)
            ? OAuthConstants::DEFAULT_MAP_COUNTRY : $this->countryAttribute;
    }

    /**
     * Create a customer from OIDC attributes.
     *
     * @param  string $email
     * @param  string $userName
     * @param  string $firstName
     * @param  string $lastName
     * @param  array  $flattenedAttrs
     * @param  array  $rawAttrs
     * @param  int    $providerId OIDC provider ID (0 = unknown / not tracked)
     */
    public function createCustomer(
        string $email,
        string $userName,
        string $firstName,
        string $lastName,
        array $flattenedAttrs,
        array $rawAttrs,
        int $providerId = 0
    ): ?CustomerInterface {
        $this->oauthUtility->customlog("CustomerUserCreator: Starting creation for " . $email);

        try {
            // Name fallbacks — delegate to shared helper (REF-02)
            if ($firstName === '' || $firstName === '0' || $lastName === '' || $lastName === '0') {
                $derived = $this->oauthUtility->extractNameFromEmail($email);
                if ($firstName === '' || $firstName === '0') {
                    $firstName = $derived['first'];
                }
                if ($lastName === '' || $lastName === '0') {
                    $lastName = $derived['last'] !== '' ? $derived['last'] : $derived['first'];
                }
            }

            $userName = $this->oauthUtility->isBlank($userName) ? $email : $userName;

            // Generate a 32-char password and shuffle to avoid predictable character-class ordering (SEC-12).
            $randomPassword = str_shuffle(
                $this->randomUtility->getRandomString(28)
                . $this->randomUtility->getRandomString(2, '!@#$%^&*')
                . $this->randomUtility->getRandomString(2, '0123456789')
            );

            $websiteId = $this->storeManager->getWebsite()->getId();

            // Create customer with basic data
            $customer = $this->customerFactory->create();
            $customer->setWebsiteId($websiteId)
                ->setEmail($email)
                ->setFirstname($firstName)
                ->setLastname($lastName);

            // Set Date of Birth
            $dob = $this->extractAttributeValue($this->dobAttribute, $flattenedAttrs, $rawAttrs);
            if (!empty($dob)) {
                $formattedDob = $this->formatDateOfBirth($dob);
                if ($formattedDob) {
                    $customer->setDob($formattedDob);
                }
            }

            // Set Gender
            $gender = $this->extractAttributeValue($this->genderAttribute, $flattenedAttrs, $rawAttrs);
            if (!empty($gender)) {
                $genderId = $this->mapGender($gender);
                if ($genderId !== null) {
                    $customer->setGender($genderId);
                }
            }

            // Resolve customer group from OIDC group claims
            $oidcGroups = $this->extractOidcGroups($flattenedAttrs, $rawAttrs);
            if ($oidcGroups !== []) {
                $resolvedGroupId = $this->getCustomerGroupFromOidcGroups($oidcGroups);
                if ($resolvedGroupId === null) {
                    $this->oauthUtility->customlog(
                        'CustomerUserCreator: creation denied – OIDC group not mapped'
                    );
                    throw new \Magento\Framework\Exception\LocalizedException(
                        __('Customer creation denied: OIDC group not mapped.')
                    );
                }
                $customer->setGroupId($resolvedGroupId);
                $this->oauthUtility->customlog(
                    "CustomerUserCreator: assigned group_id {$resolvedGroupId}"
                );
            }

            // Convert model to data interface for repository save, pass password as second arg
            $customerDataModel = $customer->getDataModel();
            $savedCustomer = $this->customerRepository->save($customerDataModel, $randomPassword);

            $this->oauthUtility->customlog(
                "CustomerUserCreator: Customer created with ID: " . (string)$savedCustomer->getId()
            );

            // Track which OIDC provider created this customer
            if ($providerId > 0 && $savedCustomer->getId()) {
                $this->userProviderResource->saveMapping('customer', (int) $savedCustomer->getId(), $providerId);
                $this->oauthUtility->customlog(
                    "CustomerUserCreator: Provider mapping saved (customer ID "
                    . (string) $savedCustomer->getId() . " → provider ID " . $providerId . ")"
                );
            }

            // Create customer address
            $this->createCustomerAddress($savedCustomer, $firstName, $lastName, $flattenedAttrs, $rawAttrs);

            return $savedCustomer;

        } catch (\Exception $e) {
            $this->oauthUtility->customlog("CustomerUserCreator: Error creating customer: " . $e->getMessage());
            $this->oauthUtility->customlog("Stack trace: " . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Resolve Magento customer group ID from OIDC group claims.
     *
     * @param  string[] $userGroups OIDC group claim values
     * @return int|null  Magento group ID, or null to deny creation
     */
    private function getCustomerGroupFromOidcGroups(array $userGroups): ?int
    {
        $mappingsJson = $this->oauthUtility->getStoreConfig(OAuthConstants::CUSTOMER_GROUP_MAPPING);
        $mappings = [];
        if (!$this->oauthUtility->isBlank($mappingsJson)) {
            $decoded = json_decode((string) $mappingsJson, true);
            if (is_array($decoded)) {
                $mappings = $decoded;
            }
        }

        // Match OIDC groups against configured mappings (case-insensitive)
        if ($userGroups !== [] && $mappings !== []) {
            foreach ($mappings as $mapping) {
                $oidcGroup = (string) ($mapping['group'] ?? '');
                $magentoGroupId = (string) ($mapping['customerGroup'] ?? '');
                if ($oidcGroup === '' || $magentoGroupId === '') {
                    continue;
                }
                foreach ($userGroups as $userGroup) {
                    if (strcasecmp((string) $userGroup, $oidcGroup) === 0) {
                        $this->oauthUtility->customlog(
                            "CustomerGroupMapping: matched '{$userGroup}' -> group ID {$magentoGroupId}"
                        );
                        return (int) $magentoGroupId;
                    }
                }
            }
        }

        // No match – check deny policy via mo_oauth_dont_create_customer_if_group_not_mapped
        $dontCreate = $this->oauthUtility->getStoreConfig(OAuthConstants::CREATEIFNOTMAP_CUSTOMER);
        if (!$this->oauthUtility->isBlank($dontCreate) && $dontCreate === 'checked') {
            $this->oauthUtility->customlog(
                'CustomerGroupMapping: no match, creation denied (dont_create_customer_if_group_not_mapped)'
            );
            return null;
        }

        // Fallback: configured default customer group
        $defaultGroup = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_DEFAULT_CUSTOMER_GROUP);
        if (!$this->oauthUtility->isBlank($defaultGroup) && is_numeric($defaultGroup)) {
            $this->oauthUtility->customlog(
                "CustomerGroupMapping: fallback to default group ID {$defaultGroup}"
            );
            return (int) $defaultGroup;
        }

        // Ultimate fallback: Magento "General" group (ID 1)
        return 1;
    }

    /**
     * Extract OIDC group claims from user attributes.
     *
     * @param  array<string, mixed> $flattenedAttrs
     * @param  array<string, mixed> $rawAttrs
     * @return string[]
     */
    private function extractOidcGroups(array $flattenedAttrs, array $rawAttrs): array
    {
        $groupAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_GROUP);
        if ($this->oauthUtility->isBlank($groupAttribute)) {
            return [];
        }

        $rawGroups = $flattenedAttrs[$groupAttribute] ?? ($rawAttrs[$groupAttribute] ?? null);
        if (is_string($rawGroups)) {
            return [$rawGroups];
        }
        if (is_array($rawGroups)) {
            return $rawGroups;
        }
        return [];
    }

    /**
     * Update an existing customer's group based on OIDC group claims.
     *
     * Called by ProcessUserAction when update_frontend_groups_on_sso is enabled
     * and the customer already exists.
     *
     * @param  CustomerInterface $customer      Existing Magento customer
     * @param  array             $flattenedAttrs Flattened OIDC attributes
     * @param  array             $rawAttrs       Raw OIDC attributes
     * @return bool true if group was changed and saved
     */
    public function updateCustomerGroupFromOidc(
        CustomerInterface $customer,
        array $flattenedAttrs,
        array $rawAttrs
    ): bool {
        $oidcGroups = $this->extractOidcGroups($flattenedAttrs, $rawAttrs);
        if ($oidcGroups === []) {
            $this->oauthUtility->customlog(
                'updateCustomerGroupFromOidc: no OIDC groups in token, skipping'
            );
            return false;
        }

        $resolvedGroupId = $this->getCustomerGroupFromOidcGroups($oidcGroups);
        if ($resolvedGroupId === null) {
            // Deny-policy active but no match — don't change existing customer
            $this->oauthUtility->customlog(
                'updateCustomerGroupFromOidc: no mapping match, keeping current group'
            );
            return false;
        }

        $currentGroupId = (int) $customer->getGroupId();
        if ($currentGroupId === $resolvedGroupId) {
            $this->oauthUtility->customlog(
                "updateCustomerGroupFromOidc: group unchanged (ID {$currentGroupId})"
            );
            return false;
        }

        $customer->setGroupId($resolvedGroupId);
        $this->customerRepository->save($customer);

        $this->oauthUtility->customlog(
            "updateCustomerGroupFromOidc: group changed {$currentGroupId} → {$resolvedGroupId}"
        );
        return true;
    }


    /**
     * Create customer address with mapped OIDC attributes
     *
     * @param  CustomerInterface $customer
     * @param  string            $firstName
     * @param  string            $lastName
     * @param  array             $flattenedAttrs
     * @param  array             $rawAttrs
     */
    private function createCustomerAddress(
        CustomerInterface $customer,
        string $firstName,
        string $lastName,
        array $flattenedAttrs,
        array $rawAttrs
    ): void {
        // Extract address fields
        $street = $this->extractAttributeValue($this->streetAttribute, $flattenedAttrs, $rawAttrs);
        $city = $this->extractAttributeValue($this->cityAttribute, $flattenedAttrs, $rawAttrs);
        $zip = $this->extractAttributeValue($this->zipAttribute, $flattenedAttrs, $rawAttrs);
        $country = $this->extractAttributeValue($this->countryAttribute, $flattenedAttrs, $rawAttrs);
        $phone = $this->extractAttributeValue($this->phoneAttribute, $flattenedAttrs, $rawAttrs);

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
                ->setCountryId($countryId ?? $this->directoryData->getDefaultCountry())
                ->setTelephone($phone ?? '')
                ->setIsDefaultBilling(true)
                ->setIsDefaultShipping(true);

            $this->addressRepository->save($address);
            $this->oauthUtility->customlog(
                "CustomerUserCreator: Address created for customer ID: " . (string)$customer->getId()
            );

        } catch (\Exception $e) {
            $this->oauthUtility->customlog("CustomerUserCreator: ERROR creating address: " . $e->getMessage());
        }
    }

    /**
     * Extract attribute value from flattened attributes with support for nested paths
     *
     * @param  string            $key            Attribute key or dotted path (e.g., "address.locality")
     * @param  array             $flattenedAttrs Flattened key/value map
     * @param  array             $rawAttrs       Original attributes structure
     * @return string|null
     */
    private function extractAttributeValue($key, array $flattenedAttrs, array $rawAttrs)
    {
        if (empty($key)) {
            return null;
        }

        // First check if it exists directly in flattened attributes
        if (isset($flattenedAttrs[$key])) {
            return $flattenedAttrs[$key];
        }

        // Support nested path notation (e.g., "address.locality")
        if (str_contains($key, '.')) {
            // Check in flattened attrs
            if ($flattenedAttrs !== []) {
                $parts = explode('.', $key);
                $value = $flattenedAttrs;
                foreach ($parts as $part) {
                    if (is_array($value) && isset($value[$part])) {
                        $value = $value[$part];
                    } else {
                        return null;
                    }
                }
                if (is_string($value)) {
                    return $value;
                }
            }

            // Check in raw attrs
            if ($rawAttrs !== []) {
                $parts = explode('.', $key);
                $value = $rawAttrs;
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
        }

        return null;
    }

    /**
     * Format date of birth to Y-m-d format
     *
     * @param  string $dob Raw date string
     * @return string|null Formatted date `Y-m-d` or null on parse failure
     */
    private function formatDateOfBirth($dob): ?string
    {
        try {
            $date = date_create($dob);
            if ($date) {
                return $date->format('Y-m-d');
            }
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("CustomerUserCreator: DOB parsing exception: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Map OIDC gender value to Magento gender ID
     *
     * @param string $genderValue
     * @psalm-return 1|2|3|null
     */
    private function mapGender(string $genderValue): int|null
    {
        if ($genderValue === '' || $genderValue === '0') {
            return null;
        }

        $genderLower = strtolower(trim($genderValue));

        if (in_array($genderLower, ['male', 'm', '1', 'mann', 'männlich'])) {
            return 1;
        }
        if (in_array($genderLower, ['female', 'f', '2', 'frau', 'weiblich'])) {
            return 2;
        }

        return 3; // Not Specified
    }

    /**
     * Resolve country name or code to Magento country ID
     *
     * @param  string|null $country
     */
    private function resolveCountryId(string|null $country)
    {
        if ($country === null || $country === '' || $country === '0') {
            return null;
        }

        // If already a 2-letter code, return as-is (uppercase)
        if (strlen($country) === 2) {
            return strtoupper($country);
        }

        try {
            $countryCollection = $this->countryCollectionFactory->create();
            foreach ($countryCollection as $countryItem) {
                $countryName = $countryItem->getName();
                if ($countryName !== null && strcasecmp((string) $countryName, $country) === 0) {
                    return $countryItem->getId();
                }
            }
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("CustomerUserCreator: Error resolving country: " . $e->getMessage());
        }

        // Return the value as-is if it looks like a country code
        if (strlen($country) <= 3) {
            return strtoupper($country);
        }

        return null;
    }
}
