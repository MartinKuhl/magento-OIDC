<?php

namespace MiniOrange\OAuth\Model\Service;

use MiniOrange\OAuth\Helper\Exception\IncorrectUserInfoDataException;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthUtility;

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

    private $oauthUtility;

    /**
     * Initialize OIDC authentication service.
     *
     * @param \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility
     */
    public function __construct(OAuthUtility $oauthUtility)
    {
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * Validate user info data from OAuth provider.
     *
     * @param mixed $userInfoResponse
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
     * @param string $keyPrefix Current key prefix for recursion
     * @param array|object $arr The nested data structure
     * @param array $result Accumulator for flattened key-value pairs
     * @param int $depth Current recursion depth
     * @return array Flattened associative array
     */
    public function flattenAttributes(string $keyPrefix, $arr, array &$result, int $depth = 0): array
    {
        if ($depth > self::MAX_RECURSION_DEPTH) {
            return $result;
        }

        foreach ($arr as $key => $resource) {
            if (is_array($resource) || is_object($resource)) {
                $newPrefix = empty($keyPrefix) ? $key : $keyPrefix . "." . $key;
                $this->flattenAttributes($newPrefix, $resource, $result, $depth + 1);
            } else {
                $newKey = empty($keyPrefix) ? $key : $keyPrefix . "." . $key;
                $result[$newKey] = $resource;
            }
        }
        return $result;
    }

    /**
     * Extract email from OAuth response using configured attribute and recursive fallback.
     *
     * @param array $flattenedResponse Flattened attributes
     * @param array|object $rawResponse Raw OAuth response for recursive search
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
            $this->oauthUtility->customlog("OidcAuthenticationService: Email found via configured attribute '$emailAttribute': " . $flattenedResponse[$emailAttribute]);
            return $flattenedResponse[$emailAttribute];
        }

        // Fallback to recursive search
        $email = $this->findEmailRecursive($rawResponse);
        if (!empty($email)) {
            return $email;
        }

        return '';
    }

    /**
     * Extract login type from user info response.
     *
     * @param array|object $userInfoResponse
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
     * @param array|object $arr
     * @param int $depth
     * @return string
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
                $this->oauthUtility->customlog("OidcAuthenticationService: findEmailRecursive found: " . $value);
                return $value;
            }

            if (is_array($value) || is_object($value)) {
                $email = $this->findEmailRecursive($value, $depth + 1);
                if (!empty($email)) {
                    return $email;
                }
            }
        }
        return '';
    }
}
