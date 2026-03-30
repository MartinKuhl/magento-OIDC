<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Service;

use M2Oidc\OAuth\Helper\Exception\IncorrectUserInfoDataException;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthUtility;

/**
 * Service layer for OIDC authentication response processing.
 *
 * Encapsulates the core logic previously spread across controller-to-controller
 * chaining (ProcessResponseAction). Provides validation, attribute flattening,
 * email extraction, and login type detection as reusable service methods.
 */
class OidcAuthenticationService
{
    private const int MAX_RECURSION_DEPTH = 5;

    /** @var \M2Oidc\OAuth\Helper\OAuthUtility */
    private readonly \M2Oidc\OAuth\Helper\OAuthUtility $oauthUtility;

    /**
     * Initialize OIDC authentication service.
     *
     * @param OAuthUtility $oauthUtility
     */
    public function __construct(OAuthUtility $oauthUtility)
    {
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * Validate user info data from OAuth provider.
     *
     * @param  mixed $userInfoResponse
     * @throws IncorrectUserInfoDataException
     */
    public function validateUserInfo($userInfoResponse): void
    {
        $this->oauthUtility->customlog("OidcAuthenticationService: validateUserInfo");

        if (is_object($userInfoResponse) && isset($userInfoResponse->error)) {
            throw new IncorrectUserInfoDataException();
        }
        if (is_array($userInfoResponse) && isset($userInfoResponse['error'])) {
            throw new IncorrectUserInfoDataException();
        }
        if (empty($userInfoResponse)) {
            throw new IncorrectUserInfoDataException();
        }
    }

    /**
     * Flatten a nested OAuth response into dot-notation keyed array.
     *
     * When the provider is configured with claim_encoding=base64, each leaf key and
     * value is passed through tryBase64Decode(): valid Base64 strings that decode to
     * valid UTF-8 are replaced with their decoded form; everything else is kept as-is.
     * This transparently handles providers like Zitadel that Base64-encode all metadata
     * keys and values.
     *
     * @param string  $keyPrefix Current key prefix for recursion
     * @param mixed   $arr       The nested data structure
     * @param mixed[] $result    Accumulator for flattened key-value pairs
     * @param int     $depth     Current recursion depth
     * @return array<string, mixed> Flattened associative array
     */
    public function flattenAttributes(string $keyPrefix, $arr, array &$result, int $depth = 0): array
    {
        if ($depth > self::MAX_RECURSION_DEPTH) {
            return $result;
        }

        $decodeBase64 = $this->oauthUtility->getStoreConfig(OAuthConstants::CLAIM_ENCODING)
            === OAuthConstants::CLAIM_ENCODING_BASE64;

        foreach ($arr as $key => $resource) {
            $resolvedKey = ($decodeBase64 && $keyPrefix === '') ? $this->tryBase64Decode((string) $key) : (string) $key;
            if (is_array($resource) || is_object($resource)) {
                $newPrefix = $keyPrefix === '' || $keyPrefix === '0'
                    ? $resolvedKey
                    : $keyPrefix . '.' . $resolvedKey;
                $this->flattenAttributes($newPrefix, $resource, $result, $depth + 1);
            } else {
                $newKey = $keyPrefix === '' || $keyPrefix === '0'
                    ? $resolvedKey
                    : $keyPrefix . '.' . $resolvedKey;
                $result[$newKey] = $decodeBase64
                    ? $this->tryBase64Decode((string) $resource)
                    : $resource;
            }
        }
        return $result;
    }

    /**
     * Attempt to Base64-decode a string in strict mode.
     *
     * Returns the decoded value only when:
     * - base64_decode() succeeds in strict mode (no characters outside the Base64 alphabet)
     * - The decoded bytes form valid UTF-8
     *
     * Otherwise the original string is returned unchanged.
     *
     * @param  string $value Raw claim value or key
     * @return string Decoded value, or original if decoding is not applicable
     */
    private function tryBase64Decode(string $value): string
    {
        if ($value === '') {
            return $value;
        }
        $decoded = base64_decode($value, true); // phpcs:ignore Magento2.Functions.DiscouragedFunction
        if ($decoded === false) {
            return $value;
        }
        // Reject decoded output that is not valid UTF-8 (e.g. binary data).
        if (!mb_check_encoding($decoded, 'UTF-8')) {
            return $value;
        }
        // Reject decoded output containing C0/C1 control characters (null bytes, non-printable chars).
        // mb_check_encoding() validates UTF-8 structure but does not reject control characters.
        // A null byte in a group claim could cause strpos() comparisons to match unexpectedly.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $decoded)) {
            $this->oauthUtility->customlog(
                "OidcAuthenticationService: WARNING — decoded Base64 claim"
                . " contains control characters, rejecting decoded form"
            );
            return $value;
        }
        return $decoded;
    }

    /**
     * Extract email from OAuth response using configured attribute and recursive fallback.
     *
     * @param  mixed[] $flattenedResponse Flattened attributes
     * @param  mixed   $rawResponse       Raw OAuth response for recursive search
     * @return string Email address or empty string if not found
     */
    public function extractEmail(array $flattenedResponse, $rawResponse): string
    {
        // First try the configured email attribute
        $emailAttribute = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_EMAIL);
        if ($this->oauthUtility->isBlank($emailAttribute)) {
            $emailAttribute = OAuthConstants::DEFAULT_MAP_EMAIL;
        }

        if (isset($flattenedResponse[$emailAttribute])
            && filter_var($flattenedResponse[$emailAttribute], FILTER_VALIDATE_EMAIL)
        ) {
            $this->oauthUtility->customlog(
                "OidcAuthenticationService: Email found via configured attribute '$emailAttribute': "
                . $flattenedResponse[$emailAttribute]
            );
            return $flattenedResponse[$emailAttribute];
        }

        // Fallback to recursive search — H-08: log a warning so operators know the mapping is misconfigured
        $email = $this->findEmailRecursive($rawResponse);
        if ($email !== '' && $email !== '0') {
            $this->oauthUtility->customlog(
                'OidcAuthenticationService: WARNING — email not found in configured attribute "'
                . $emailAttribute . '", using recursive fallback value: ' . $email
            );
            return $email;
        }

        return '';
    }

    /**
     * Extract login type from user info response.
     *
     * @param  mixed $userInfoResponse Array or object with loginType
     * @return string Login type constant
     */
    public function extractLoginType($userInfoResponse): string
    {
        if (is_array($userInfoResponse) && isset($userInfoResponse['loginType'])) {
            return $userInfoResponse['loginType'];
        }
        if (is_object($userInfoResponse) && isset($userInfoResponse->loginType)) {
            return $userInfoResponse->loginType;
        }
        return OAuthConstants::LOGIN_TYPE_CUSTOMER;
    }

    /**
     * Normalize a raw group/role claim value into a flat string array.
     *
     * Handles three formats transparently:
     *   "admin"                         → ['admin']           (string)
     *   ["admin", "member"]             → ['admin', 'member']  (flat scalar array — standard IdPs)
     *   {"admin": {orgId: "domain"}, …} → ['admin', 'member']  (Zitadel nested — keys are role names)
     *
     * Used by all group-extraction call sites (admin creation, admin role sync,
     * customer creation/sync) to guarantee consistent behaviour regardless of IdP.
     *
     * @param  mixed $rawValue Raw claim value from the OIDC provider
     * @return string[]
     */
    public function normalizeGroups(mixed $rawValue): array
    {
        if ($rawValue === null || $rawValue === '') {
            return [];
        }
        if (is_string($rawValue)) {
            return [$rawValue];
        }
        $arr = is_object($rawValue) ? (array) $rawValue : $rawValue;
        if (!is_array($arr) || $arr === []) {
            return [];
        }
        // Detect Zitadel-style nested structure: {"roleName": {"orgId": "domain"}}
        // When values are themselves arrays/objects, the KEYS are the role names.
        $firstVal = reset($arr);
        if (is_array($firstVal) || is_object($firstVal)) {
            return array_keys($arr);
        }
        // Flat scalar array — filter out empties and re-index
        return array_values(array_filter(
            array_map(fn($v): string => (string) $v, $arr),
            fn(string $v): bool => $v !== ''
        ));
    }

    /**
     * Normalize all Zitadel-style role claims in an attribute array for display.
     *
     * Handles two forms transparently:
     *
     * 1. Raw nested (attributes straight from session/userinfo endpoint):
     *      "urn:zitadel:...:roles" => {"admin": {"orgId": "domain"}, "non-admin": {...}}
     *    → replaces the value with a flat string array: ["admin", "non-admin"]
     *
     * 2. Flat dot-notation (after flattenAttributes() / Base64 decode):
     *      "urn:zitadel:...:roles.admin.365482058136485891"    => "domain"
     *      "urn:zitadel:...:roles.non-admin.365482058136485891" => "domain"
     *    → adds a synthetic parent key: "urn:zitadel:...:roles" => ["admin", "non-admin"]
     *    Detection heuristic: parent.roleName.orgId where roleName is non-numeric
     *    and orgId is a long all-digit string (≥ 15 chars, matching Zitadel org IDs).
     *
     * Called from ShowTestResults so users see extracted role names regardless of
     * whether the group attribute mapping has been configured yet.
     *
     * @param mixed[] $attrs Attribute array (modified in-place)
     */
    public function normalizeZitadelRoleClaimsForDisplay(array &$attrs): void
    {
        // Step 1 — Raw nested: replace Zitadel nested objects with flat role name arrays.
        // Only touches keys whose values are associative arrays where the first element
        // is itself an array/object (the Zitadel pattern; plain address/name objects are safe).
        foreach ($attrs as $key => $value) {
            if (!is_array($value) && !is_object($value)) {
                continue;
            }
            $arr = is_object($value) ? (array) $value : $value;
            if ($arr === []) {
                continue;
            }
            $firstVal = reset($arr);
            if (is_array($firstVal) || is_object($firstVal)) {
                $attrs[$key] = array_keys($arr);
            }
        }

        // Step 2 — Flat dot-notation: reconstruct parent keys from Zitadel subkey patterns.
        // Pattern: parentKey.roleName.numericOrgId
        //   roleName  — non-numeric (e.g. "admin")
        //   numericOrgId — all digits, length ≥ 15 (Zitadel org IDs are 18-digit numbers)
        $parents = [];
        foreach (array_keys($attrs) as $key) {
            $key      = (string) $key;
            $firstDot = strpos($key, '.');
            if ($firstDot === false) {
                continue;
            }
            $parentKey = substr($key, 0, $firstDot);
            $remainder = substr($key, $firstDot + 1);
            $secondDot = strpos($remainder, '.');
            if ($secondDot === false) {
                continue; // Need at least parent.role.orgId
            }
            $roleName = substr($remainder, 0, $secondDot);
            $orgPart  = substr($remainder, $secondDot + 1);
            if ($roleName === '' || is_numeric($roleName)) {
                continue; // Role names are non-numeric strings
            }
            if (!ctype_digit($orgPart) || strlen($orgPart) < 15) {
                continue; // Zitadel org IDs are long all-digit strings
            }
            $parents[$parentKey][$roleName] = true;
        }
        foreach ($parents as $parentKey => $roles) {
            if (!array_key_exists($parentKey, $attrs)) {
                $attrs[$parentKey] = array_keys($roles);
            }
        }
    }

    /**
     * Reconstruct a parent group claim key from Zitadel-style flattened subkeys.
     *
     * The flattenAttributes() method only stores leaf keys, so Zitadel's nested object:
     *   {"admin": {"orgId": "domain"}, "non-admin": {"orgId": "domain"}}
     * becomes flat keys like:
     *   "groupAttr.admin.orgId" => "domain"
     *   "groupAttr.non-admin.orgId" => "domain"
     *
     * This method scans for those non-numeric first segments and adds the
     * synthetic parent key: "groupAttr" => ["admin", "non-admin"]
     *
     * No-op when the key already exists or no matching subkeys are found.
     *
     * @param  mixed[] $flattenedAttrs  Flattened OIDC attributes (modified in-place)
     * @param  string  $groupAttribute  Configured group attribute key
     */
    public function reconstructNestedGroupClaim(array &$flattenedAttrs, string $groupAttribute): void
    {
        if ($groupAttribute === '' || array_key_exists($groupAttribute, $flattenedAttrs)) {
            return;
        }
        $prefix = $groupAttribute . '.';
        $roles  = [];
        foreach (array_keys($flattenedAttrs) as $key) {
            /** @psalm-suppress InvalidCast */
            if (str_starts_with((string) $key, $prefix)) {
                /** @psalm-suppress InvalidCast */
                $firstSegment = explode('.', substr((string) $key, strlen($prefix)), 2)[0];
                if ($firstSegment !== '' && !is_numeric($firstSegment)) {
                    $roles[$firstSegment] = true;
                }
            }
        }
        if ($roles !== []) {
            $flattenedAttrs[$groupAttribute] = array_keys($roles);
        }
    }

    /**
     * Recursively search for an email address in the user info data.
     *
     * @param  mixed $arr
     * @param  int   $depth
     */
    private function findEmailRecursive($arr, int $depth = 0): string
    {
        if ($depth > self::MAX_RECURSION_DEPTH) {
            return '';
        }

        if (is_object($arr)) {
            $arr = (array) $arr;
        }

        if (!is_array($arr)) {
            return '';
        }

        foreach ($arr as $value) {
            if (is_scalar($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $this->oauthUtility->customlog(
                    "OidcAuthenticationService: findEmailRecursive found: " . (string)$value
                );
                return (string) $value;
            }

            if (is_array($value) || is_object($value)) {
                $email = $this->findEmailRecursive($value, $depth + 1);
                if ($email !== '' && $email !== '0') {
                    return $email;
                }
            }
        }
        return '';
    }
}
