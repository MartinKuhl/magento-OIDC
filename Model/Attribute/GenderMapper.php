<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Attribute;

/**
 * Maps an OIDC gender claim value to a Magento gender ID.
 *
 * Single source of truth for gender recognition, shared by
 * CustomerAttributeMapper (customer creation) and CustomerProfileSyncService
 * (profile re-sync) so both flows recognize the same vocabulary — including
 * the German words sent by some IdPs.
 *
 * Magento gender IDs: 1 = Male, 2 = Female, 3 = Not Specified.
 */
class GenderMapper
{
    /** @var string[] Values recognized as male (Magento gender ID 1) */
    private const MALE_VALUES = ['male', 'm', '1', 'mann', 'männlich'];

    /** @var string[] Values recognized as female (Magento gender ID 2) */
    private const FEMALE_VALUES = ['female', 'f', '2', 'frau', 'weiblich'];

    /**
     * Map a gender claim value to a Magento gender ID.
     *
     * Matching is case-insensitive (multibyte-aware, so "MÄNNLICH" matches)
     * and ignores surrounding whitespace. Any value outside the male/female
     * vocabulary (including "other"/"diverse") returns null; callers decide
     * the fallback: CustomerAttributeMapper applies `?? 3` (Not Specified) on
     * creation, CustomerProfileSyncService skips the sync.
     *
     * @param  string $value Gender value from the OIDC claim
     */
    public function map(string $value): ?int
    {
        $normalized = mb_strtolower(trim($value));

        return match (true) {
            in_array($normalized, self::MALE_VALUES, true)   => 1,
            in_array($normalized, self::FEMALE_VALUES, true) => 2,
            default                                          => null,
        };
    }
}
