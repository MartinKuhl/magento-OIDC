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

/**
 * Service class for creating Customer Users via OAuth/OIDC
 */
class CustomerUserCreator
{
    private \Magento\Customer\Model\CustomerFactory $customerFactory;

    private \Magento\Customer\Api\Data\AddressInterfaceFactory $addressFactory;

    private \Magento\Customer\Api\AddressRepositoryInterface $addressRepository;

    private \Magento\Store\Model\StoreManagerInterface $storeManager;

    private \Magento\Framework\Math\Random $randomUtility;

    private \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility;

    /**
     * @var CountryCollectionFactory
     */
    private CountryCollectionFactory $countryCollectionFactory;

    private \Magento\Framework\Stdlib\DateTime\DateTime $dateTime;

    /**
     * @var DirectoryData
     */
    private DirectoryData $directoryData;

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

    private \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository;

    /**
     * Initialize customer user creator service.
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
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
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

        $this->initializeAttributeMapping();
    }

    /**
     * Initialize attribute mapping from configuration.
     *
     * @return void
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
     */
    public function createCustomer(
        string $email,
        string $userName,
        string $firstName,
        string $lastName,
        array $flattenedAttrs,
        array $rawAttrs
    ): ?CustomerInterface {
        $this->oauthUtility->customlog("CustomerUserCreator: Starting creation for " . $email);

        try {
            // Name fallbacks if empty
            if ($firstName === '' || $firstName === '0') {
                $parts = explode("@", $email);
                $firstName = $parts[0] ?? 'Customer';
            }
            if ($lastName === '' || $lastName === '0') {
                $parts = explode("@", $email);
                $lastName = $parts[1] ?? 'User';
            }

            $userName = $this->oauthUtility->isBlank($userName) ? $email : $userName;

            // Generate secure random password (32 chars)
            $randomPassword = $this->randomUtility->getRandomString(28)
                . $this->randomUtility->getRandomString(2, '!@#$%^&*')
                . $this->randomUtility->getRandomString(2, '0123456789');

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

            // Convert model to data interface for repository save, pass password as second arg
            $customerDataModel = $customer->getDataModel();
            $savedCustomer = $this->customerRepository->save($customerDataModel, $randomPassword);

            $this->oauthUtility->customlog(
                "CustomerUserCreator: Customer created with ID: " . $savedCustomer->getId()
            );

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
     * Create customer address with mapped OIDC attributes
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
                "CustomerUserCreator: Address created for customer ID: " . $customer->getId()
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
        if (strpos($key, '.') !== false) {
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
                if ($countryName !== null && strcasecmp($countryName, $country) === 0) {
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
