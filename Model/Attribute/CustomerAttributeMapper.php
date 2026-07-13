<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Attribute;

use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Attribute\Transformer;

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
 *   '_transforms'     => (array) [attribute_type => ['function' => string|null, 'params' => string|null]]
 *
 * Returned keys (only present when resolved value is non-empty):
 *   'dob', 'gender', 'billing_street', 'billing_city',
 *   'billing_postcode', 'billing_country_id', 'billing_telephone'
 */
class CustomerAttributeMapper implements AttributeMapperInterface
{
    /**
     * @param OAuthUtility    $oauthUtility
     * @param Transformer     $transformer
     * @param GenderMapper    $genderMapper
     * @param CountryResolver $countryResolver
     */
    public function __construct(
        private readonly OAuthUtility $oauthUtility,
        private readonly Transformer $transformer,
        private readonly GenderMapper $genderMapper,
        private readonly CountryResolver $countryResolver
    ) {
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function map(array $flattenedAttrs, array $mappingConfig): array
    {
        $rawAttrs  = (array) ($mappingConfig['_raw_attrs'] ?? []);
        $transforms = (array) ($mappingConfig['_transforms'] ?? []);
        $result    = [];

        // --- Date of Birth ---
        $dob = $this->extractClaimValue((string) ($mappingConfig['dob'] ?? ''), $flattenedAttrs, $rawAttrs);
        $dob = $this->applyTransform($dob, $flattenedAttrs, $transforms, 'dob');
        if (!in_array($dob, [null, '', '0'], true)) {
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
        $genderRaw = $this->applyTransform($genderRaw, $flattenedAttrs, $transforms, 'gender');
        if (!in_array($genderRaw, [null, '', '0'], true)) {
            // Unrecognized values default to 3 (Not Specified) on customer creation
            $result['gender'] = $this->genderMapper->map($genderRaw) ?? 3;
        }

        // --- Billing street ---
        $street = $this->extractClaimValue(
            (string) ($mappingConfig['billing_address'] ?? ''),
            $flattenedAttrs,
            $rawAttrs
        );
        $street = $this->applyTransform($street, $flattenedAttrs, $transforms, 'billing_address');
        if (!in_array($street, [null, '', '0'], true)) {
            $result['billing_street'] = $street;
        }

        // --- Billing city ---
        $city = $this->extractClaimValue(
            (string) ($mappingConfig['billing_city'] ?? ''),
            $flattenedAttrs,
            $rawAttrs
        );
        $city = $this->applyTransform($city, $flattenedAttrs, $transforms, 'billing_city');
        if (!in_array($city, [null, '', '0'], true)) {
            $result['billing_city'] = $city;
        }

        // --- Billing postcode ---
        $zip = $this->extractClaimValue(
            (string) ($mappingConfig['billing_zip'] ?? ''),
            $flattenedAttrs,
            $rawAttrs
        );
        $zip = $this->applyTransform($zip, $flattenedAttrs, $transforms, 'billing_zip');
        if (!in_array($zip, [null, '', '0'], true)) {
            $result['billing_postcode'] = $zip;
        }

        // --- Billing country (resolved to Magento country_id) ---
        $country = $this->extractClaimValue(
            (string) ($mappingConfig['billing_country'] ?? ''),
            $flattenedAttrs,
            $rawAttrs
        );
        $country = $this->applyTransform($country, $flattenedAttrs, $transforms, 'billing_country');
        if (!in_array($country, [null, '', '0'], true)) {
            $countryId = $this->countryResolver->resolve($country);
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
        $phone = $this->applyTransform($phone, $flattenedAttrs, $transforms, 'billing_phone');
        if (!in_array($phone, [null, '', '0'], true)) {
            $result['billing_telephone'] = $phone;
        }

        return $result;
    }

    /**
     * Apply a configured transform (if any) for a given attribute type.
     *
     * @param  string|null           $rawValue
     * @param  array<string,mixed>   $flattenedAttrs  Full flattened claim set (for concat)
     * @param  array<string,mixed>   $transforms      [attribute_type => ['function'=>..., 'params'=>...]]
     * @param  string                $attributeType
     */
    private function applyTransform(
        ?string $rawValue,
        array $flattenedAttrs,
        array $transforms,
        string $attributeType
    ): ?string {
        if (!isset($transforms[$attributeType])) {
            return $rawValue;
        }
        $t = $transforms[$attributeType];
        return $this->transformer->apply(
            $rawValue,
            $flattenedAttrs,
            $t['function'] ?? null,
            $t['params'] ?? null
        );
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
}
