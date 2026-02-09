<?php

namespace MiniOrange\OAuth\Model\Service;

use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthUtility;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Math\Random;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Helper\Data as DirectoryData;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Service class for creating Customer Users via OAuth/OIDC
 */
class CustomerUserCreator
{
    /**
     * @var CustomerFactory
     */
    private $customerFactory;

    /**
     * @var AddressInterfaceFactory
     */
    private $addressFactory;

    /**
     * @var AddressRepositoryInterface
     */
    private $addressRepository;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Random
     */
    private $randomUtility;

    /**
     * @var OAuthUtility
     */
    private $oauthUtility;

    /**
     * @var CountryFactory
     */
    private $countryFactory;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @var DirectoryData
     */
    private $directoryData;

    // Attribute mapping keys
    private $dobAttribute;
    private $genderAttribute;
    private $phoneAttribute;
    private $streetAttribute;
    private $zipAttribute;
    private $cityAttribute;
    private $countryAttribute;

    /**
     * @var \Magento\Customer\Api\CustomerRepositoryInterface
     */
    private $customerRepository;

    public function __construct(
        CustomerFactory $customerFactory,
        AddressInterfaceFactory $addressFactory,
        AddressRepositoryInterface $addressRepository,
        StoreManagerInterface $storeManager,
        Random $randomUtility,
        OAuthUtility $oauthUtility,
        CountryFactory $countryFactory,
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
        $this->countryFactory = $countryFactory;
        $this->dateTime = $dateTime;
        $this->directoryData = $directoryData;
        $this->customerRepository = $customerRepository;

        $this->initializeAttributeMapping();
    }

    private function initializeAttributeMapping()
    {
        $this->dobAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_DOB);
        $this->dobAttribute = $this->oauthUtility->isBlank($this->dobAttribute) ? OAuthConstants::DEFAULT_MAP_DOB : $this->dobAttribute;

        $this->genderAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_GENDER);
        $this->genderAttribute = $this->oauthUtility->isBlank($this->genderAttribute) ? OAuthConstants::DEFAULT_MAP_GENDER : $this->genderAttribute;

        $this->phoneAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_PHONE);
        $this->phoneAttribute = $this->oauthUtility->isBlank($this->phoneAttribute) ? OAuthConstants::DEFAULT_MAP_PHONE : $this->phoneAttribute;

        $this->streetAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_STREET);
        $this->streetAttribute = $this->oauthUtility->isBlank($this->streetAttribute) ? OAuthConstants::DEFAULT_MAP_STREET : $this->streetAttribute;

        $this->zipAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_ZIP);
        $this->zipAttribute = $this->oauthUtility->isBlank($this->zipAttribute) ? OAuthConstants::DEFAULT_MAP_ZIP : $this->zipAttribute;

        $this->cityAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_CITY);
        $this->cityAttribute = $this->oauthUtility->isBlank($this->cityAttribute) ? OAuthConstants::DEFAULT_MAP_CITY : $this->cityAttribute;

        $this->countryAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_COUNTRY);
        $this->countryAttribute = $this->oauthUtility->isBlank($this->countryAttribute) ? OAuthConstants::DEFAULT_MAP_COUNTRY : $this->countryAttribute;
    }

    /**
     * Create a new customer
     *
     * @param string $email
     * @param string $userName
     * @param string $firstName
     * @param string $lastName
     * @param array $flattenedAttrs
     * @param array $rawAttrs
     * @return \Magento\Customer\Model\Customer|null
     */
    public function createCustomer($email, $userName, $firstName, $lastName, $flattenedAttrs, $rawAttrs)
    {
        $this->oauthUtility->customlog("CustomerUserCreator: Starting creation for " . $email);

        try {
            // Name fallbacks if empty
            if (empty($firstName)) {
                $parts = explode("@", $email);
                $firstName = $parts[0] ?? 'Customer';
            }
            if (empty($lastName)) {
                $parts = explode("@", $email);
                $lastName = $parts[1] ?? 'User';
            }

            $userName = !$this->oauthUtility->isBlank($userName) ? $userName : $email;

            // Generate secure random password (32 chars)
            $randomPassword = $this->randomUtility->getRandomString(28)
                . $this->randomUtility->getRandomString(2, '!@#$%^&*')
                . $this->randomUtility->getRandomString(2, '0123456789');

            $websiteId = $this->storeManager->getWebsite()->getWebsiteId();

            // Create customer with basic data
            $customer = $this->customerFactory->create();
            $customer->setWebsiteId($websiteId)
                ->setEmail($email)
                ->setFirstname($firstName)
                ->setLastname($lastName)
                ->setPassword($randomPassword);

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

            $this->customerRepository->save($customer);
            $this->oauthUtility->customlog("CustomerUserCreator: Customer created with ID: " . $customer->getId());

            // Create customer address
            $this->createCustomerAddress($customer, $firstName, $lastName, $flattenedAttrs, $rawAttrs);

            return $customer;

        } catch (\Exception $e) {
            $this->oauthUtility->customlog("CustomerUserCreator: Error creating customer: " . $e->getMessage());
            $this->oauthUtility->customlog("Stack trace: " . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Create customer address with mapped OIDC attributes
     */
    private function createCustomerAddress($customer, $firstName, $lastName, $flattenedAttrs, $rawAttrs)
    {
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
            $this->oauthUtility->customlog("CustomerUserCreator: Address created for customer ID: " . $customer->getId());

        } catch (\Exception $e) {
            $this->oauthUtility->customlog("CustomerUserCreator: ERROR creating address: " . $e->getMessage());
        }
    }

    /**
     * Extract attribute value from flattened attributes with support for nested paths
     */
    private function extractAttributeValue($key, $flattenedAttrs, $rawAttrs)
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
            if (!empty($flattenedAttrs)) {
                $parts = explode('.', $key);
                $value = $flattenedAttrs;
                foreach ($parts as $part) {
                    if (is_array($value) && isset($value[$part])) {
                        $value = $value[$part];
                    } else {
                        return null;
                    }
                }
                if (is_string($value))
                    return $value;
            }

            // Check in raw attrs
            if (!empty($rawAttrs)) {
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
     */
    private function formatDateOfBirth($dob)
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

        return 3; // Not Specified
    }

    /**
     * Resolve country name or code to Magento country ID
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
            $this->oauthUtility->customlog("CustomerUserCreator: Error resolving country: " . $e->getMessage());
        }

        // Return the value as-is if it looks like a country code
        if (strlen($country) <= 3) {
            return strtoupper($country);
        }

        return null;
    }
}
