<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Model\Attribute;

use MiniOrange\OAuth\Helper\OAuthUtility;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;

/**
 * Maps flattened OIDC claims to Magento customer attribute values.
 *
 * Extracts and transforms: date of birth, gender, and billing address fields.
 * Pure value transformation — no persistence side effects.
 *
 * Expected $mappingConfig keys (all optional):
 *   'dob'             => OIDC claim name for date of birth
 *   'gender'          => OIDC claim name for gender
 *   'billing_address' => OIDC claim name for street address
 *   'billing_city'    => OIDC claim name for city
 *   'billing_zip'     => OIDC claim name for postal code
 *   'billing_country' => OIDC claim name for country (name or ISO code)
 *   'billing_phone'   => OIDC claim name for telephone
 *   '_raw_attrs'      => (array) original nested OIDC response for dot-path lookups
 *
 * Returned keys (only present when resolved value is non-empty):
 *   'dob', 'gender', 'billing_street', 'billing_city',
 *   'billing_postcode', 'billing_country_id', 'billing_telephone'
 */
class CustomerAttributeMapper implements AttributeMapperInterface
{
    /**
     * @param OAuthUtility             $oauthUtility
     * @param CountryCollectionFactory $countryCollectionFactory
     */
    public function __construct(
        private readonly OAuthUtility $oauthUtility,
        private readonly CountryCollectionFactory $countryCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function map(array $flattenedAttrs, array $mappingConfig): array
    {
        $rawAttrs = (array) ($mappingConfig['_raw_attrs'] ?? []);
        $result   = [];

        // --- Date of Birth ---
        $dob = $this->extractClaimValue((string) ($mappingConfig['dob'] ?? ''), $flattenedAttrs, $rawAttrs);
        if ($dob !== null && $dob !== '' && $dob !== '0') {
            $formatted = $this->formatDateOfBirth($dob);
            if ($formatted !== null) {
                $result['dob'] = $formatted;
            }
        }

        // --- Gender ---
        $genderRaw = $this->extractClaimValue(
            (string) ($mappingConfig['gender'] ?? ''),
            $flattenedAttrs,
            $rawAttrs
        );
        if ($genderRaw !== null && $genderRaw !== '' && $genderRaw !== '0') {
            $genderId = $this->mapGender($genderRaw);
            if ($genderId !== null) {
                $result['gender'] = $genderId;
            }
        }

        // --- Billing street ---
        $street = $this->extractClaimValue(
            (string) ($mappingConfig['billing_address'] ?? ''),
            $flattenedAttrs,
            $rawAttrs
        );
        if ($street !== null && $street !== '' && $street !== '0') {
            $result['billing_street'] = $street;
        }

        // --- Billing city ---
        $city = $this->extractClaimValue(
            (string) ($mappingConfig['billing_city'] ?? ''),
            $flattenedAttrs,
            $rawAttrs
        );
        if ($city !== null && $city !== '' && $city !== '0') {
            $result['billing_city'] = $city;
        }

        // --- Billing postcode ---
        $zip = $this->extractClaimValue(
            (string) ($mappingConfig['billing_zip'] ?? ''),
            $flattenedAttrs,
            $rawAttrs
        );
        if ($zip !== null && $zip !== '' && $zip !== '0') {
            $result['billing_postcode'] = $zip;
        }

        // --- Billing country (resolved to Magento country_id) ---
        $country = $this->extractClaimValue(
            (string) ($mappingConfig['billing_country'] ?? ''),
            $flattenedAttrs,
            $rawAttrs
        );
        if ($country !== null && $country !== '' && $country !== '0') {
            $countryId = $this->resolveCountryId($country);
            if ($countryId !== null) {
                $result['billing_country_id'] = $countryId;
            }
        }

        // --- Billing telephone ---
        $phone = $this->extractClaimValue(
            (string) ($mappingConfig['billing_phone'] ?? ''),
            $flattenedAttrs,
            $rawAttrs
        );
        if ($phone !== null && $phone !== '' && $phone !== '0') {
            $result['billing_telephone'] = $phone;
        }

        return $result;
    }

    /**
     * Look up a claim value from flattened attributes, supporting dot-notation paths.
     *
     * @param  string              $claimName      Claim name or dot-separated path (e.g. "address.locality")
     * @param  array<string,mixed> $flattenedAttrs Flattened OIDC claims
     * @param  array<mixed>        $rawAttrs       Original nested OIDC response
     */
    private function extractClaimValue(string $claimName, array $flattenedAttrs, array $rawAttrs): ?string
    {
        if ($claimName === '') {
            return null;
        }

        // Direct key lookup
        if (isset($flattenedAttrs[$claimName])) {
            return (string) $flattenedAttrs[$claimName];
        }

        // Dot-path traversal
        if (str_contains($claimName, '.')) {
            $parts = explode('.', $claimName);

            // Try flattened first
            $value = $flattenedAttrs;
            foreach ($parts as $part) {
                if (is_array($value) && isset($value[$part])) {
                    $value = $value[$part];
                } else {
                    $value = null;
                    break;
                }
            }
            if (is_string($value)) {
                return $value;
            }

            // Try raw attrs (may have nested objects)
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

        return null;
    }

    /**
     * Format a date of birth string to Y-m-d.
     *
     * @param  string $dob
     */
    private function formatDateOfBirth(string $dob): ?string
    {
        try {
            $date = date_create($dob);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        } catch (\Exception $e) {
            $this->oauthUtility->customlog('CustomerAttributeMapper: DOB parse error: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Map an OIDC gender string to a Magento gender ID (1=Male, 2=Female, 3=Not Specified).
     *
     * @param  string $genderValue
     */
    private function mapGender(string $genderValue): ?int
    {
        if ($genderValue === '' || $genderValue === '0') {
            return null;
        }

        $lower = strtolower(trim($genderValue));

        if (in_array($lower, ['male', 'm', '1', 'mann', 'männlich'], true)) {
            return 1;
        }
        if (in_array($lower, ['female', 'f', '2', 'frau', 'weiblich'], true)) {
            return 2;
        }

        return 3; // Not Specified
    }

    /**
     * Resolve a country name or ISO code to a Magento country_id.
     *
     * @param  string $country
     */
    private function resolveCountryId(string $country): ?string
    {
        if ($country === '' || $country === '0') {
            return null;
        }

        // Already a 2-letter ISO code
        if (strlen($country) === 2) {
            return strtoupper($country);
        }

        try {
            $collection = $this->countryCollectionFactory->create();
            foreach ($collection as $countryItem) {
                $countryName = $countryItem->getName();
                if ($countryName !== null && strcasecmp((string) $countryName, $country) === 0) {
                    return (string) $countryItem->getId();
                }
            }
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                'CustomerAttributeMapper: country resolve error: ' . $e->getMessage()
            );
        }

        // Short value that looks like a code (e.g. "USA")
        if (strlen($country) <= 3) {
            return strtoupper($country);
        }

        return null;
    }
}
