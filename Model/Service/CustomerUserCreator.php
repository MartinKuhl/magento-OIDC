<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Model\Service;

use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Model\Attribute\AttributeMapperInterface;
use MiniOrange\OAuth\Model\Provider\MappingRepository;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Math\Random;
use Magento\Directory\Helper\Data as DirectoryData;
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

    /** @var MappingRepository */
    private readonly MappingRepository $mappingRepository;

    /** @var AttributeMapperInterface */
    private readonly AttributeMapperInterface $attributeMapper;

    /**
     * Initialize customer user creator service.
     *
     * @param CustomerFactory                                   $customerFactory
     * @param AddressInterfaceFactory                           $addressFactory
     * @param AddressRepositoryInterface                        $addressRepository
     * @param StoreManagerInterface                             $storeManager
     * @param Random                                            $randomUtility
     * @param OAuthUtility                                      $oauthUtility
     * @param DirectoryData                                     $directoryData
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
     * @param UserProviderResource                              $userProviderResource
     * @param MappingRepository                                 $mappingRepository
     * @param AttributeMapperInterface                          $attributeMapper
     */
    public function __construct(
        CustomerFactory $customerFactory,
        AddressInterfaceFactory $addressFactory,
        AddressRepositoryInterface $addressRepository,
        StoreManagerInterface $storeManager,
        Random $randomUtility,
        OAuthUtility $oauthUtility,
        DirectoryData $directoryData,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        UserProviderResource $userProviderResource,
        MappingRepository $mappingRepository,
        AttributeMapperInterface $attributeMapper
    ) {
        $this->customerFactory = $customerFactory;
        $this->addressFactory = $addressFactory;
        $this->addressRepository = $addressRepository;
        $this->storeManager = $storeManager;
        $this->randomUtility = $randomUtility;
        $this->oauthUtility = $oauthUtility;
        $this->directoryData = $directoryData;
        $this->customerRepository = $customerRepository;
        $this->userProviderResource = $userProviderResource;
        $this->mappingRepository = $mappingRepository;
        $this->attributeMapper = $attributeMapper;

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

            // Map customer attributes via strategy (Phase 3.2)
            $mapped = $this->attributeMapper->map($flattenedAttrs, $this->buildMappingConfig($rawAttrs));

            if (isset($mapped['dob'])) {
                $customer->setDob($mapped['dob']);
            }
            if (isset($mapped['gender'])) {
                $customer->setGender($mapped['gender']);
            }

            // Resolve customer group from OIDC group claims
            $oidcGroups = $this->extractOidcGroups($flattenedAttrs, $rawAttrs);
            if ($oidcGroups !== []) {
                $resolvedGroupId = $this->getCustomerGroupFromOidcGroups($oidcGroups, $providerId);
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
            $this->createCustomerAddress($savedCustomer, $firstName, $lastName, $mapped);

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
     * Reads from the normalized miniorange_oauth_role_mappings table first (Phase 4).
     * Falls back to the legacy JSON column when the new table has no data for this provider.
     *
     * @param  string[] $userGroups OIDC group claim values
     * @param  int      $providerId OIDC provider ID (0 = unknown)
     * @return int|null  Magento group ID, or null to deny creation
     */
    private function getCustomerGroupFromOidcGroups(array $userGroups, int $providerId = 0): ?int
    {
        // --- Phase 4 path: read from normalized table ---
        $mappings = [];
        if ($providerId > 0) {
            $newRows = $this->mappingRepository->getCustomerGroupMappings($providerId);
            // Normalize to legacy key names so the loop below is unchanged
            foreach ($newRows as $row) {
                $mappings[] = [
                    'group'         => $row['oidc_group'],
                    'customerGroup' => $row['magento_role_id'],
                ];
            }
        }

        // --- Fallback path: legacy JSON column ---
        if ($mappings === []) {
            $mappingsJson = $this->oauthUtility->getStoreConfig(OAuthConstants::CUSTOMER_GROUP_MAPPING);
            if (!$this->oauthUtility->isBlank($mappingsJson)) {
                $decoded = json_decode((string) $mappingsJson, true);
                if (is_array($decoded)) {
                    $mappings = $decoded;
                }
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
     * @param  array $flattenedAttrs Flattened OIDC attributes
     * @param  array $rawAttrs       Raw OIDC attributes
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
     * @param  CustomerInterface $customer       Existing Magento customer
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
     * Create customer address from mapped OIDC attribute values.
     *
     * @param  CustomerInterface      $customer
     * @param  string                 $firstName
     * @param  string                 $lastName
     * @param  array<string,mixed>    $mapped   Output of AttributeMapperInterface::map()
     */
    private function createCustomerAddress(
        CustomerInterface $customer,
        string $firstName,
        string $lastName,
        array $mapped
    ): void {
        $street    = (string) ($mapped['billing_street'] ?? '');
        $city      = (string) ($mapped['billing_city'] ?? '');
        $zip       = (string) ($mapped['billing_postcode'] ?? '');
        $countryId = (string) ($mapped['billing_country_id'] ?? '');
        $phone     = (string) ($mapped['billing_telephone'] ?? '');

        // Only create address if at least one of street, city, or country is present
        if ($street === '' && $city === '' && $countryId === '') {
            return;
        }

        try {
            $address = $this->addressFactory->create();
            $address->setCustomerId($customer->getId())
                ->setFirstname($firstName)
                ->setLastname($lastName)
                ->setStreet([$street])
                ->setCity($city)
                ->setPostcode($zip)
                ->setCountryId($countryId !== '' ? $countryId : $this->directoryData->getDefaultCountry())
                ->setTelephone($phone)
                ->setIsDefaultBilling(true)
                ->setIsDefaultShipping(true);

            $this->addressRepository->save($address);
            $this->oauthUtility->customlog(
                "CustomerUserCreator: Address created for customer ID: " . (string) $customer->getId()
            );

        } catch (\Exception $e) {
            $this->oauthUtility->customlog("CustomerUserCreator: ERROR creating address: " . $e->getMessage());
        }
    }

    /**
     * Build the mapping config array for AttributeMapperInterface::map().
     *
     * Keys are attribute types; values are the OIDC claim names resolved from config.
     * The '_raw_attrs' key carries the original nested OIDC response for dot-path support.
     *
     * @param  array<mixed> $rawAttrs Original nested OIDC response
     * @return array<string,mixed>
     */
    private function buildMappingConfig(array $rawAttrs = []): array
    {
        return [
            'dob'             => $this->dobAttribute,
            'gender'          => $this->genderAttribute,
            'billing_address' => $this->streetAttribute,
            'billing_city'    => $this->cityAttribute,
            'billing_zip'     => $this->zipAttribute,
            'billing_country' => $this->countryAttribute,
            'billing_phone'   => $this->phoneAttribute,
            '_raw_attrs'      => $rawAttrs,
        ];
    }
}
