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
    private const MAX_RECURSION_DEPTH = 5;

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
     * @param  string       $keyPrefix Current key prefix for recursion
     * @param  array|object $arr       The nested data structure
     * @param  array        $result    Accumulator for flattened key-value pairs
     * @param  int          $depth     Current recursion depth
     * @return array Flattened associative array
     */
    public function flattenAttributes(string $keyPrefix, $arr, array &$result, int $depth = 0): array
    {
        if ($depth > self::MAX_RECURSION_DEPTH) {
            return $result;
        }

        $decodeBase64 = $this->oauthUtility->getStoreConfig(OAuthConstants::CLAIM_ENCODING)
            === OAuthConstants::CLAIM_ENCODING_BASE64;

        foreach ($arr as $key => $resource) {
            $resolvedKey = $decodeBase64 ? $this->tryBase64Decode((string) $key) : (string) $key;
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
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return $value;
        }
        // Reject decoded output that is not valid UTF-8 (e.g. binary data).
        if (!mb_check_encoding($decoded, 'UTF-8')) {
            return $value;
        }
        return $decoded;
    }

    /**
     * Extract email from OAuth response using configured attribute and recursive fallback.
     *
     * @param  array        $flattenedResponse Flattened attributes
     * @param  array|object $rawResponse       Raw OAuth response for recursive search
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
     * @param  array|object $userInfoResponse
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
