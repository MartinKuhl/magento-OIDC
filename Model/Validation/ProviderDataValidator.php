<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Validation;

use M2Oidc\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

/**
 * Shared provider-data validation (C-03).
 *
 * Single source of truth for the whitelist, SSRF, and lockout-prevention rules
 * that were previously duplicated (or missing) across the admin Save controller,
 * the CLI import command, and the admin import handler:
 *
 *  - enum fields (login_type, claim_encoding, pkce_flow) are auto-normalized
 *    to their safe default with a warning, matching Provider/Save.php;
 *  - endpoint URL fields must be public HTTPS URLs (SsrfUrlValidator);
 *    blocked URLs are removed from the data with a warning;
 *  - OIDC-only login flags are reverted when no OIDC user of the matching
 *    type exists yet for the provider (lockout prevention).
 *
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 */
class ProviderDataValidator
{
    /**
     * Provider columns that hold outbound URLs and are subject to SSRF validation.
     *
     * @var string[]
     */
    private const ENDPOINT_URL_FIELDS = [
        'authorize_endpoint',
        'access_token_endpoint',
        'user_info_endpoint',
        'jwks_endpoint',
        'endsession_endpoint',
        'revocation_endpoint',
        'well_known_config_url',
    ];

    /** @var SsrfUrlValidator */
    private readonly SsrfUrlValidator $ssrfUrlValidator;

    /** @var UserProviderResource */
    private readonly UserProviderResource $userProviderResource;

    /**
     * Initialize provider data validator.
     *
     * @param SsrfUrlValidator     $ssrfUrlValidator
     * @param UserProviderResource $userProviderResource
     */
    public function __construct(
        SsrfUrlValidator $ssrfUrlValidator,
        UserProviderResource $userProviderResource
    ) {
        $this->ssrfUrlValidator     = $ssrfUrlValidator;
        $this->userProviderResource = $userProviderResource;
    }

    /**
     * Validate and normalize provider data before it is persisted.
     *
     * @param  mixed[] $data       Provider data (form POST or import row)
     * @param  int     $providerId Existing provider ID, or 0 when creating
     * @return ProviderValidationResult Normalized data plus warnings/errors
     */
    public function validate(array $data, int $providerId): ProviderValidationResult
    {
        $warnings = [];

        $data = $this->normalizeEnumFields($data, $warnings);
        $data = $this->stripUnsafeEndpointUrls($data, $warnings);
        $data = $this->applyLockoutGuards($data, $providerId, $warnings);

        return new ProviderValidationResult($data, $warnings);
    }

    /**
     * Whitelist-validate select fields; invalid values fall back to a safe default.
     *
     * @param  mixed[]  $data     Provider data
     * @param  string[] $warnings Warning collector (by reference)
     * @return mixed[] Normalized data
     */
    private function normalizeEnumFields(array $data, array &$warnings): array
    {
        $enumFields = [
            'login_type'     => [['customer', 'admin', 'both'], 'customer'],
            'claim_encoding' => [['none', 'base64'], 'none'],
            'pkce_flow'      => [['S256', 'plain', ''], ''],
        ];

        foreach ($enumFields as $field => [$allowed, $default]) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = trim((string) $data[$field]);
            if (!in_array($value, $allowed, true)) {
                $data[$field] = $default;
                $warnings[]   = (string) __(
                    'Invalid value "%1" for %2 was reset to "%3".',
                    $value,
                    $field,
                    $default
                );
            }
        }

        return $data;
    }

    /**
     * Remove endpoint URLs that are not public HTTPS URLs (SSRF protection).
     *
     * @param  mixed[]  $data     Provider data
     * @param  string[] $warnings Warning collector (by reference)
     * @return mixed[] Normalized data
     */
    private function stripUnsafeEndpointUrls(array $data, array &$warnings): array
    {
        foreach (self::ENDPOINT_URL_FIELDS as $field) {
            $url = trim((string) ($data[$field] ?? ''));
            if ($url === '') {
                continue;
            }
            if (!$this->ssrfUrlValidator->isAllowedExternalHttpsUrl($url)) {
                unset($data[$field]);
                $warnings[] = (string) __(
                    'The %1 URL was removed because it must be a public HTTPS URL '
                    . '(private and internal network addresses are not allowed).',
                    $field
                );
            }
        }

        return $data;
    }

    /**
     * Revert OIDC-only login flags that would lock out all users of a type.
     *
     * Matches Provider/Save.php: the guard only applies to existing providers
     * ($providerId > 0), because user links can only exist for saved providers.
     *
     * @param  mixed[]  $data       Provider data
     * @param  int      $providerId Existing provider ID, or 0 when creating
     * @param  string[] $warnings   Warning collector (by reference)
     * @return mixed[] Normalized data
     */
    private function applyLockoutGuards(array $data, int $providerId, array &$warnings): array
    {
        $lockoutFlags = [
            'm2oidc_disable_non_oidc_admin_login' => [
                'admin',
                (string) __(
                    'Admin OIDC-only login was automatically disabled because no admin users '
                    . 'have logged in via this provider yet.'
                ),
            ],
            'm2oidc_disable_non_oidc_customer_login' => [
                'customer',
                (string) __(
                    'Customer OIDC-only login was automatically disabled because no customers '
                    . 'have logged in via this provider yet.'
                ),
            ],
        ];

        foreach ($lockoutFlags as $flagField => [$userType, $warning]) {
            if ((int) ($data[$flagField] ?? 0) === 1
                && $providerId > 0
                && $this->userProviderResource->countByTypeAndProvider($userType, $providerId) === 0
            ) {
                $data[$flagField] = 0;
                $warnings[]       = $warning;
            }
        }

        return $data;
    }
}
