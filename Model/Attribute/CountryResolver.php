<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Attribute;

use M2Oidc\OAuth\Helper\OAuthUtility;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;

/**
 * Resolves an OIDC country claim to a Magento country_id (L43).
 *
 * Single source of truth for country resolution, shared by
 * CustomerAttributeMapper (customer creation) and CustomerProfileSyncService
 * (address re-sync). Resolution chain:
 *   1. 2-letter ISO 3166-1 alpha-2 code → validated uppercase passthrough
 *   2. filtered collection query on country_id / iso3_code (e.g. "DEU", "USA")
 *   3. store-locale country-name scan (name is locale-resolved, not a DB column)
 *   4. intl-based en_US country-name lookup (see resolveViaIntl())
 *
 * Stateless except for documented per-request memoization: resolved inputs
 * and the active-country-code list are cached in private arrays so repeated
 * lookups within one request hit the database at most once per step.
 */
class CountryResolver
{
    /** @var array<string, string|null> Per-request memo of resolved inputs (keyed lowercase) */
    private array $resolved = [];

    /** @var array<int, string>|null Per-request cache of active Magento country codes for the intl lookup */
    private ?array $countryCodes = null;

    /**
     * @param CountryCollectionFactory $countryCollectionFactory
     * @param OAuthUtility             $oauthUtility
     */
    public function __construct(
        private readonly CountryCollectionFactory $countryCollectionFactory,
        private readonly OAuthUtility $oauthUtility
    ) {
    }

    /**
     * Resolve a country name or ISO code to a Magento country_id.
     *
     * @param  string $country Country name or ISO code from the OIDC claim
     * @return string|null Two-letter Magento country_id, or null when unresolvable
     */
    public function resolve(string $country): ?string
    {
        $country = trim($country);
        if ($country === '' || $country === '0') {
            return null;
        }

        $memoKey = mb_strtolower($country);
        if (array_key_exists($memoKey, $this->resolved)) {
            return $this->resolved[$memoKey];
        }

        return $this->resolved[$memoKey] = $this->doResolve($country);
    }

    /**
     * Run the resolution chain for a trimmed, non-empty input.
     *
     * @param  string $country Trimmed country name or ISO code
     */
    private function doResolve(string $country): ?string
    {
        // 1) Already a 2-letter ISO code — validate (alphabetic) and pass through uppercased
        if (preg_match('/^[A-Za-z]{2}$/', $country) === 1) {
            return strtoupper($country);
        }

        // 2) Filtered query on country_id / ISO-3 code (handles "DEU", "USA", odd casing)
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
                return (string) $item->getCountryId();
            }
        } catch (\Throwable $e) {
            // Broad catch: collection lookups depend on live DB/framework state
            // (and, in unit tests, on partially-stubbed collection doubles) that
            // can raise \Error as well as \Exception — never let this abort login.
            $this->oauthUtility->customlog('CountryResolver: code lookup error: ' . $e->getMessage());
        }

        // 3) Store-locale country-name scan (the localized name is not a DB column,
        //    so it cannot be part of the filtered query above)
        try {
            foreach ($this->countryCollectionFactory->create() as $countryItem) {
                $countryName = $countryItem->getName();
                if ($countryName !== null && strcasecmp((string) $countryName, $country) === 0) {
                    return (string) $countryItem->getId();
                }
            }
        } catch (\Throwable $e) {
            $this->oauthUtility->customlog('CountryResolver: name scan error: ' . $e->getMessage());
        }

        // 4) intl-based English-name lookup
        $intlMatch = $this->resolveViaIntl($country);
        if ($intlMatch !== null) {
            return $intlMatch;
        }

        $this->oauthUtility->customlog('CountryResolver: could not resolve country "' . $country . '"');
        return null;
    }

    /**
     * Resolve via the PHP intl extension: en_US country name → ISO code.
     *
     * OIDC providers (e.g. Authelia) always send English names ("Germany") regardless
     * of the Magento store locale, so the locale-aware scan above misses them.
     * Using Magento's active country codes (not a full AA-ZZ brute-force) avoids
     * deprecated ISO codes like "DD" (East Germany) that ICU/CLDR still maps to "Germany".
     *
     * @param  string $country Trimmed country name from the OIDC claim
     */
    private function resolveViaIntl(string $country): ?string
    {
        if (!extension_loaded('intl')) {
            return null;
        }

        $normalizedInput = mb_strtolower($country);
        foreach ($this->getActiveCountryCodes() as $code) {
            $displayName = \Locale::getDisplayRegion('-' . $code, 'en_US');
            if ($displayName && $displayName !== $code
                && mb_strtolower($displayName) === $normalizedInput
            ) {
                return $code;
            }
        }
        return null;
    }

    /**
     * Return (and memoize) the active Magento country codes.
     *
     * @return array<int, string>
     */
    private function getActiveCountryCodes(): array
    {
        if ($this->countryCodes === null) {
            try {
                $values = $this->countryCollectionFactory->create()->getColumnValues('country_id');
                $this->countryCodes = array_map(
                    /**
                     * getColumnValues() is typed as returning mixed per-row; country_id is a
                     * varchar column so this is always a scalar string in practice.
                     * @psalm-suppress InvalidCast
                     */
                    static fn(mixed $code): string => (string) $code,
                    array_values($values)
                );
            } catch (\Throwable $e) {
                $this->oauthUtility->customlog('CountryResolver: code list error: ' . $e->getMessage());
                $this->countryCodes = [];
            }
        }
        return $this->countryCodes;
    }
}
