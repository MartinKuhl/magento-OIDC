<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Service;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Provider\MappingRepository;

/**
 * Syncs existing customer profile, address and group data from OIDC claims
 * on every SSO login when the corresponding per-provider flag is enabled.
 *
 * Per-attribute sync control: when a normalized attribute mapping row exists for the
 * provider and its `sync_on_sso` flag is 0, that attribute is skipped regardless of
 * the coarse global `sync_customer_profile_on_sso` switch.
 *
 * Designed to be injected into ProcessUserAction via DI.
 */
class CustomerProfileSyncService
{
    /**
     * Constructor.
     *
     * @param CustomerRepositoryInterface                              $customerRepository
     * @param AddressInterfaceFactory                                  $addressFactory
     * @param AddressRepositoryInterface                               $addressRepository
     * @param CountryCollectionFactory                                 $countryCollectionFactory
     * @param \Magento\Customer\Api\Data\RegionInterfaceFactory        $regionFactory
     * @param OAuthUtility                                             $oauthUtility
     * @param MappingRepository                                        $mappingRepository
     */
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly AddressInterfaceFactory $addressFactory,
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly CountryCollectionFactory $countryCollectionFactory,
        private readonly \Magento\Customer\Api\Data\RegionInterfaceFactory $regionFactory,
        private readonly OAuthUtility $oauthUtility,
        private readonly MappingRepository $mappingRepository
    ) {
    }

    // ──────────────────────────────────────────────
    //  Profile sync (firstname, lastname, DOB, gender, phone)
    // ──────────────────────────────────────────────

    /**
     * Update customer profile fields from OIDC claims.
     *
     * Only fields that actually changed are written; if nothing changed
     * no save() call is made (performance).
     *
     * When $providerId > 0 and a normalized attribute mapping row exists for a given
     * attribute type with `sync_on_sso = 0`, that attribute is skipped.  When no
     * normalized row exists (legacy mode) the attribute is always synced.
     *
     * @param CustomerInterface    $customer   Loaded customer entity
     * @param array<string, mixed> $flat       Flattened OIDC attributes
     * @param array<string, mixed> $raw        Raw (nested) OIDC attributes
     * @param array<string, mixed> $attrKeys   Provider-specific attribute mapping keys
     * @param int                  $providerId Provider ID for per-attribute sync flag (0 = legacy)
     */
    public function syncProfile(
        CustomerInterface $customer,
        array $flat,
        array $raw,
        array $attrKeys,
        int $providerId = 0
    ): void {
        $changed = false;
        $attrMap = $providerId > 0 ? $this->mappingRepository->getFullAttributeMap($providerId) : [];

        // Firstname
        if ($this->shouldSync($attrMap, 'firstname')) {
            $fn = $this->extract($attrKeys['firstname'] ?? null, $flat, $raw);
            if ($fn !== null && $customer->getFirstname() !== $fn) {
                $customer->setFirstname($fn);
                $changed = true;
            }
        }

        // Lastname
        if ($this->shouldSync($attrMap, 'lastname')) {
            $ln = $this->extract($attrKeys['lastname'] ?? null, $flat, $raw);
            if ($ln !== null && $customer->getLastname() !== $ln) {
                $customer->setLastname($ln);
                $changed = true;
            }
        }

        // Date of Birth
        if ($this->shouldSync($attrMap, 'dob')) {
            $dob = $this->extract($attrKeys['dob'] ?? null, $flat, $raw);
            if ($dob !== null) {
                $formatted = $this->formatDob($dob);
                if ($formatted !== null && $customer->getDob() !== $formatted) {
                    $customer->setDob($formatted);
                    $changed = true;
                }
            }
        }

        // Gender
        if ($this->shouldSync($attrMap, 'gender')) {
            $gender = $this->extract($attrKeys['gender'] ?? null, $flat, $raw);
            if ($gender !== null) {
                $genderId = $this->mapGender($gender);
                if ($genderId !== null && (int) $customer->getGender() !== $genderId) {
                    $customer->setGender($genderId);
                    $changed = true;
                }
            }
        }

        // Email
        if ($this->shouldSync($attrMap, 'email')) {
            $email = $this->extract($attrKeys['email'] ?? null, $flat, $raw);
            if ($email !== null && $customer->getEmail() !== $email) {
                $customer->setEmail($email);
                $changed = true;
            }
        }

        if ($changed) {
            $this->customerRepository->save($customer);
            $this->oauthUtility->customlog(
                'CustomerProfileSync: profile updated for ' . $customer->getEmail()
            );
        }
    }

    // ──────────────────────────────────────────────
    //  Address sync (billing)
    // ──────────────────────────────────────────────

    /**
     * Update or create the default billing address from OIDC claims.
     *
     * Strategy: find existing default address of the given type and update it.
     * If none exists, create a new one and mark it as default.
     *
     * @param CustomerInterface    $customer
     * @param array<string, mixed> $flat
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $addrKeys  ['phone','street','zip','city','state','country']
     * @param string               $type      'billing'
     */
    public function syncAddress(
        CustomerInterface $customer,
        array $flat,
        array $raw,
        array $addrKeys,
        string $type = 'billing'
    ): void {
        $street  = $this->extract($addrKeys['street'] ?? null, $flat, $raw);
        $city    = $this->extract($addrKeys['city'] ?? null, $flat, $raw);
        $zip     = $this->extract($addrKeys['zip'] ?? null, $flat, $raw);
        $country = $this->extract($addrKeys['country'] ?? null, $flat, $raw);
        $phone   = $this->extract($addrKeys['phone'] ?? null, $flat, $raw);
        $state   = $this->extract($addrKeys['state'] ?? null, $flat, $raw);

        // At minimum street + city + country must be present
        if ($street === null || $city === null || $country === null) {
            return;
        }

        $countryId = $this->resolveCountryId($country);
        if ($countryId === null) {
            $this->oauthUtility->customlog(
                'CustomerProfileSync: could not resolve country "' . $country . '"'
            );
            return;
        }

        // Find existing default address
        $existingAddress = null;
        $defaultId = $type === 'billing'
            ? $customer->getDefaultBilling()
            : $customer->getDefaultShipping();

        if ($defaultId) {
            try {
                $existingAddress = $this->addressRepository->getById((int) $defaultId);
            } catch (\Exception $e) {
                // Address deleted externally — will create a new one
                $this->oauthUtility->customlog('CustomerProfileSync: ' . $e->getMessage());
            }
        }

        if ($existingAddress !== null) {
            $changed = false;

            if ($existingAddress->getStreet() !== [$street]) {
                $existingAddress->setStreet([$street]);
                $changed = true;
            }
            if ($existingAddress->getCity() !== $city) {
                $existingAddress->setCity($city);
                $changed = true;
            }
            if ($existingAddress->getPostcode() !== ($zip ?? '')) {
                $existingAddress->setPostcode($zip ?? '');
                $changed = true;
            }
            if ($existingAddress->getCountryId() !== $countryId) {
                $existingAddress->setCountryId($countryId);
                $changed = true;
            }
            if ($phone !== null && $existingAddress->getTelephone() !== $phone) {
                $existingAddress->setTelephone($phone);
                $changed = true;
            }
            if ($state !== null) {
                $existingAddress->setRegion(
                    $this->regionFactory->create()
                        ->setRegion($state)
                );
                $changed = true;
            }

            if ($changed) {
                $this->addressRepository->save($existingAddress);
                $this->oauthUtility->customlog(
                    'CustomerProfileSync: ' . $type . ' address updated for '
                    . $customer->getEmail()
                );
            }
        } else {
            // Create new default address
            $address = $this->addressFactory->create();
            $address->setCustomerId($customer->getId())
                ->setFirstname($customer->getFirstname())
                ->setLastname($customer->getLastname())
                ->setStreet([$street])
                ->setCity($city)
                ->setPostcode($zip ?? '')
                ->setCountryId($countryId)
                ->setTelephone($phone ?? '0000');

            if ($type === 'billing') {
                $address->setIsDefaultBilling(true);
            }

            $this->addressRepository->save($address);
            $this->oauthUtility->customlog(
                'CustomerProfileSync: ' . $type . ' address created for '
                . $customer->getEmail()
            );
        }
    }

    // ──────────────────────────────────────────────
    //  Private helpers
    // ──────────────────────────────────────────────
    /**
     * Decide whether an attribute type should be synced.
     *
     * Rule: if a normalized mapping row exists for $attributeType and its
     * `sync_on_sso` flag is 0 → skip.  If no row exists → sync (legacy behaviour).
     *
     * @param  array<string, array{attribute_name: string, sync_on_sso: int}> $attrMap
     * @param  string $attributeType  e.g. 'firstname', 'dob', 'billing_city'
     */
    private function shouldSync(array $attrMap, string $attributeType): bool
    {
        if (isset($attrMap[$attributeType])) {
            return (bool) $attrMap[$attributeType]['sync_on_sso'];
        }
        return true;
    }

    /**
     * Extract a value from flattened or raw OIDC attributes.
     *
     * @param string|null          $key  Attribute key to look up
     * @param array<string, mixed> $flat Flattened OIDC attributes
     * @param array<string, mixed> $raw  Raw (nested) OIDC attributes
     */
    private function extract(?string $key, array $flat, array $raw): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }
        $value = $flat[$key] ?? $raw[$key] ?? null;
        if (is_array($value)) {
            $value = reset($value) ?: null;
        }
        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /**
     * Format various DOB string formats to Y-m-d.
     *
     * @param string $dob Date of birth string in any supported format
     */
    private function formatDob(string $dob): ?string
    {
        $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'Y/m/d'];
        foreach ($formats as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $dob);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }
        // Fallback: let PHP try
        $ts = strtotime($dob);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    /**
     * Map gender string to Magento gender ID (1=Male, 2=Female, 3=Not specified).
     *
     * @param string $gender Gender string from OIDC claim
     */
    private function mapGender(string $gender): ?int
    {
        $lower = strtolower(trim($gender));
        return match (true) {
            in_array($lower, ['male', 'm', '1'], true)   => 1,
            in_array($lower, ['female', 'f', '2'], true)  => 2,
            in_array($lower, ['other', 'diverse', '3'], true) => 3,
            default => null,
        };
    }

    /**
     * Resolve a country name or ISO code to a Magento country_id.
     *
     * @param string $country Country name or ISO code from OIDC claim
     */
    private function resolveCountryId(string $country): ?string
    {
        // Already a 2-letter ISO code
        if (preg_match('/^[A-Z]{2}$/', strtoupper($country))) {
            return strtoupper($country);
        }

        try {
            $collection = $this->countryCollectionFactory->create();
            $collection->addFieldToFilter(
                ['country_id', 'iso3_code'],
                [
                    ['eq' => strtoupper($country)],
                    ['eq' => strtoupper($country)],
                ]
            );

            $item = $collection->getFirstItem();
            if ($item->getCountryId()) {
                return $item->getCountryId();
            }
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                'CustomerProfileSync: country collection error: ' . $e->getMessage()
            );
        }

        // Fallback: use PHP intl extension for en_US country name → ISO code lookup.
        // OIDC providers (e.g. Authelia) always send English names ("Germany") regardless of
        // the Magento store locale. Using Magento's active country codes (not a full AA-ZZ
        // brute-force) avoids deprecated ISO codes like "DD" that ICU/CLDR still maps to "Germany".
        if (extension_loaded('intl')) {
            $normalizedInput = strtolower(trim($country));
            try {
                $codes = $this->countryCollectionFactory->create()->getColumnValues('country_id');
            } catch (\Exception $e) {
                $codes = [];
            }
            foreach ($codes as $code) {
                $displayName = \Locale::getDisplayRegion('-' . $code, 'en_US');
                if ($displayName && $displayName !== $code
                    && strtolower($displayName) === $normalizedInput
                ) {
                    return (string) $code;
                }
            }
        }

        $this->oauthUtility->customlog(
            'CustomerProfileSync: could not resolve country "' . $country . '"'
        );

        return null;
    }
}
