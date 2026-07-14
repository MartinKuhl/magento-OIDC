<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Service;

use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthMessages;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Attribute\AttributeMapperInterface;
use M2Oidc\OAuth\Model\Attribute\MapperPool;
use M2Oidc\OAuth\Model\Provider\MappingRepository;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

/**
 * Service class for creating Customer Users via OAuth/OIDC
 */
class CustomerUserCreator
{
    /**
     * Attribute-mapping config lookups: attribute type => [config key, default claim name].
     */
    private const ATTRIBUTE_CONFIG = [
        'dob'     => [OAuthConstants::MAP_DOB,     OAuthConstants::DEFAULT_MAP_DOB],
        'gender'  => [OAuthConstants::MAP_GENDER,  OAuthConstants::DEFAULT_MAP_GENDER],
        'phone'   => [OAuthConstants::MAP_PHONE,   OAuthConstants::DEFAULT_MAP_PHONE],
        'street'  => [OAuthConstants::MAP_STREET,  OAuthConstants::DEFAULT_MAP_STREET],
        'zip'     => [OAuthConstants::MAP_ZIP,     OAuthConstants::DEFAULT_MAP_ZIP],
        'city'    => [OAuthConstants::MAP_CITY,    OAuthConstants::DEFAULT_MAP_CITY],
        'country' => [OAuthConstants::MAP_COUNTRY, OAuthConstants::DEFAULT_MAP_COUNTRY],
    ];

    /** @var \Magento\Customer\Model\CustomerFactory */
    private readonly \Magento\Customer\Model\CustomerFactory $customerFactory;

    /** @var \Magento\Customer\Api\Data\AddressInterfaceFactory */
    private readonly \Magento\Customer\Api\Data\AddressInterfaceFactory $addressFactory;

    /** @var \Magento\Customer\Api\AddressRepositoryInterface */
    private readonly \Magento\Customer\Api\AddressRepositoryInterface $addressRepository;

    /** @var \Magento\Store\Model\StoreManagerInterface */
    private readonly \Magento\Store\Model\StoreManagerInterface $storeManager;

    /** @var RandomPasswordGenerator */
    private readonly RandomPasswordGenerator $passwordGenerator;

    /** @var \M2Oidc\OAuth\Helper\OAuthUtility */
    private readonly \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility;

    /** @var array<string, string> Resolved OIDC claim name per attribute type (see ATTRIBUTE_CONFIG) */
    private array $attributeMapping = [];

    /** @var \Magento\Customer\Api\CustomerRepositoryInterface */
    private readonly \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository;

    /** @var \M2Oidc\OAuth\Model\ResourceModel\UserProvider */
    private readonly UserProviderResource $userProviderResource;

    /** @var MappingRepository */
    private readonly MappingRepository $mappingRepository;

    /** @var AttributeMapperInterface Default customer attribute mapper (fallback) */
    private readonly AttributeMapperInterface $attributeMapper;

    /** @var OidcAuthenticationService */
    private readonly OidcAuthenticationService $oidcAuthenticationService;

    /** @var GroupMappingResolver */
    private readonly GroupMappingResolver $groupMappingResolver;

    /** @var MapperPool|null Per-provider mapper registry (null in unit-test context without DI) */
    private readonly ?MapperPool $mapperPool;

    /**
     * Initialize customer user creator service.
     *
     * @param CustomerFactory                                   $customerFactory
     * @param AddressInterfaceFactory                           $addressFactory
     * @param AddressRepositoryInterface                        $addressRepository
     * @param StoreManagerInterface                             $storeManager
     * @param RandomPasswordGenerator                           $passwordGenerator
     * @param OAuthUtility                                      $oauthUtility
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
     * @param UserProviderResource                              $userProviderResource
     * @param MappingRepository                                 $mappingRepository
     * @param AttributeMapperInterface                          $attributeMapper
     * @param OidcAuthenticationService                         $oidcAuthenticationService
     * @param GroupMappingResolver                              $groupMappingResolver
     * @param MapperPool|null                                   $mapperPool
     */
    public function __construct(
        CustomerFactory $customerFactory,
        AddressInterfaceFactory $addressFactory,
        AddressRepositoryInterface $addressRepository,
        StoreManagerInterface $storeManager,
        RandomPasswordGenerator $passwordGenerator,
        OAuthUtility $oauthUtility,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        UserProviderResource $userProviderResource,
        MappingRepository $mappingRepository,
        AttributeMapperInterface $attributeMapper,
        OidcAuthenticationService $oidcAuthenticationService,
        GroupMappingResolver $groupMappingResolver,
        ?MapperPool $mapperPool = null
    ) {
        $this->customerFactory = $customerFactory;
        $this->addressFactory = $addressFactory;
        $this->addressRepository = $addressRepository;
        $this->storeManager = $storeManager;
        $this->passwordGenerator = $passwordGenerator;
        $this->oauthUtility = $oauthUtility;
        $this->customerRepository = $customerRepository;
        $this->userProviderResource = $userProviderResource;
        $this->mappingRepository = $mappingRepository;
        $this->attributeMapper = $attributeMapper;
        $this->oidcAuthenticationService = $oidcAuthenticationService;
        $this->groupMappingResolver = $groupMappingResolver;
        $this->mapperPool = $mapperPool;
    }

    /**
     * Resolve the attribute mapper for the given provider.
     *
     * Uses the MapperPool when available (prefers provider-specific override),
     * then falls back to the directly-injected default mapper.
     *
     * @param int $providerId OIDC provider ID
     */
    private function resolveMapper(int $providerId): AttributeMapperInterface
    {
        if ($this->mapperPool instanceof \M2Oidc\OAuth\Model\Attribute\MapperPool && $providerId > 0) {
            try {
                return $this->mapperPool->getMapper($providerId, 'customer');
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
            } catch (\InvalidArgumentException $e) {
                // No mapper registered for this provider — fall through to default
            }
        }
        return $this->attributeMapper;
    }

    /**
     * Initialize attribute mapping from configuration.
     *
     * For each attribute type in ATTRIBUTE_CONFIG the configured claim name is
     * read from store config; blank values fall back to the module default.
     */
    private function initializeAttributeMapping(): void
    {
        foreach (self::ATTRIBUTE_CONFIG as $type => [$configKey, $defaultClaim]) {
            $configured = $this->oauthUtility->getStoreConfig($configKey);
            $this->attributeMapping[$type] = $this->oauthUtility->isBlank($configured)
                ? $defaultClaim
                : (string) $configured;
        }
    }

    /**
     * Create a customer from OIDC attributes.
     *
     * @param  string  $email
     * @param  string  $userName
     * @param  string  $firstName
     * @param  string  $lastName
     * @param  mixed[] $flattenedAttrs
     * @param  mixed[] $rawAttrs
     * @param  int     $providerId OIDC provider ID (0 = unknown / not tracked)
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
        $this->initializeAttributeMapping();
        $this->oauthUtility->customlog("CustomerUserCreator: Starting creation for " . $email);

        try {
            // Name fallbacks — delegate to shared helper
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

            // Generate a 32-char password with guaranteed special/digit characters.
            $randomPassword = $this->passwordGenerator->generate();

            $websiteId = $this->storeManager->getWebsite()->getId();

            // Create customer with basic data
            $customer = $this->customerFactory->create();
            $customer->setWebsiteId($websiteId)
                ->setEmail($email)
                ->setFirstname($firstName)
                ->setLastname($lastName);

            // Map customer attributes via strategy (Phase 3.2); use per-provider mapper if registered
            $mapped = $this->resolveMapper($providerId)->map(
                $flattenedAttrs,
                $this->buildMappingConfig($rawAttrs, $providerId)
            );

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
                    $msg = OAuthMessages::parse(
                        'CUSTOMER_GROUP_MAPPING_NO_MATCH',
                        ['groups' => implode(', ', $oidcGroups)]
                    );
                    $this->oauthUtility->customlog($msg);
                    throw new \Magento\Framework\Exception\LocalizedException(__($msg));
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
     * Delegates to GroupMappingResolver: normalized m2oidc_oauth_role_mappings
     * table first, legacy JSON column fallback, case-insensitive group match,
     * configured default group. When the deny policy
     * (m2oidc_dont_create_customer_if_group_not_mapped) is active, an unmatched
     * user is denied (null) instead of falling back to a default group.
     *
     * @param  string[] $userGroups OIDC group claim values
     * @param  int      $providerId OIDC provider ID (0 = unknown)
     * @return int|null  Magento group ID, or null when no mapping matches.
     *                   Callers must decide whether to throw or use a default.
     */
    private function getCustomerGroupFromOidcGroups(array $userGroups, int $providerId = 0): ?int
    {
        // Deny policy: m2oidc_dont_create_customer_if_group_not_mapped suppresses all fallbacks
        $dontCreate = $this->oauthUtility->getStoreConfig(OAuthConstants::CREATEIFNOTMAP_CUSTOMER);
        $denyIfUnmapped = $dontCreate === 'checked';

        $defaultGroup = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_DEFAULT_CUSTOMER_GROUP);
        $resolved = $this->groupMappingResolver->resolve(
            GroupMappingResolver::TYPE_CUSTOMER_GROUP,
            $providerId,
            $userGroups,
            ($denyIfUnmapped || $defaultGroup === null) ? null : (string) $defaultGroup
        );

        if ($resolved !== null) {
            return $resolved;
        }

        if ($denyIfUnmapped) {
            $this->oauthUtility->customlog(
                'CustomerGroupMapping: no match, creation denied (dont_create_customer_if_group_not_mapped)'
            );
            return null;
        }

        // Ultimate fallback: Magento "General" group (ID 1)
        return 1;
    }

    /**
     * Extract OIDC group claims from user attributes.
     *
     * @param  mixed[] $flattenedAttrs Flattened OIDC attributes
     * @param  mixed[] $rawAttrs       Raw OIDC attributes
     * @return string[]
     */
    private function extractOidcGroups(array $flattenedAttrs, array $rawAttrs): array
    {
        $groupAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_GROUP);
        if ($this->oauthUtility->isBlank($groupAttribute)) {
            return [];
        }

        $rawGroups = $flattenedAttrs[$groupAttribute] ?? ($rawAttrs[$groupAttribute] ?? null);
        return $this->oidcAuthenticationService->normalizeGroups($rawGroups);
    }

    /**
     * Update an existing customer's group based on OIDC group claims.
     *
     * Called by ProcessUserAction when update_frontend_groups_on_sso is enabled
     * and the customer already exists.
     *
     * @param  CustomerInterface $customer       Existing Magento customer
     * @param  mixed[]           $flattenedAttrs Flattened OIDC attributes
     * @param  mixed[]           $rawAttrs       Raw OIDC attributes
     * @param  int               $providerId     OIDC provider ID (0 = unknown)
     * @return bool true if group was changed and saved
     */
    public function updateCustomerGroupFromOidc(
        CustomerInterface $customer,
        array $flattenedAttrs,
        array $rawAttrs,
        int $providerId = 0
    ): bool {
        $oidcGroups = $this->extractOidcGroups($flattenedAttrs, $rawAttrs);
        if ($oidcGroups === []) {
            $this->oauthUtility->customlog(
                'updateCustomerGroupFromOidc: no OIDC groups in token, skipping'
            );
            return false;
        }

        $resolvedGroupId = $this->getCustomerGroupFromOidcGroups($oidcGroups, $providerId);
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

        // Only create address if all required fields (street, zip, city, country) are present
        if ($street === '' || $zip === '' || $city === '' || $countryId === '') {
            $missing = array_keys(array_filter(
                ['street' => $street, 'zip' => $zip, 'city' => $city, 'country' => $countryId],
                fn(string $v): bool => $v === ''
            ));
            $this->oauthUtility->customlog(
                'CustomerUserCreator: Address skipped — missing required field(s): ' . implode(', ', $missing)
            );
            return;
        }

        try {
            $address = $this->addressFactory->create();
            $address->setCustomerId((int)$customer->getId())
                ->setFirstname($firstName)
                ->setLastname($lastName)
                ->setStreet([$street])
                ->setCity($city)
                ->setPostcode($zip)
                ->setCountryId($countryId)
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
     * The '_transforms' key carries per-attribute-type transform config from MappingRepository.
     *
     * @param  array<mixed> $rawAttrs   Original nested OIDC response
     * @param  int          $providerId Provider ID for transform lookup (0 = no transforms)
     * @return array<string,mixed>
     */
    private function buildMappingConfig(array $rawAttrs = [], int $providerId = 0): array
    {
        $transforms = [];
        if ($providerId > 0) {
            $attrMap = $this->mappingRepository->getFullAttributeMap($providerId);
            foreach ($attrMap as $type => $row) {
                if (!empty($row['transform_function'])) {
                    $transforms[$type] = [
                        'function' => $row['transform_function'],
                        'params'   => $row['transform_params'] ?? null,
                    ];
                }
            }
        }

        return [
            'dob'             => $this->attributeMapping['dob'] ?? null,
            'gender'          => $this->attributeMapping['gender'] ?? null,
            'billing_address' => $this->attributeMapping['street'] ?? null,
            'billing_city'    => $this->attributeMapping['city'] ?? null,
            'billing_zip'     => $this->attributeMapping['zip'] ?? null,
            'billing_country' => $this->attributeMapping['country'] ?? null,
            'billing_phone'   => $this->attributeMapping['phone'] ?? null,
            '_raw_attrs'      => $rawAttrs,
            '_transforms'     => $transforms,
        ];
    }
}
