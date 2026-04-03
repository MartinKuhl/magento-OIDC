<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Attribute;

use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Attribute\Transformer;

/**
 * Maps pre-extracted OIDC name values to admin user attributes with email-based fallbacks.
 *
 * Role resolution is intentionally excluded — it stays in AdminUserCreator because
 * it requires MappingRepository and is already covered by unit tests there.
 *
 * Expected $flattenedAttrs keys (all optional, may be empty strings):
 *   'firstname' => pre-extracted first name from OIDC claims
 *   'lastname'  => pre-extracted last name from OIDC claims
 *
 * Expected $mappingConfig keys (all optional):
 *   '_email'      => admin user's email address (used for name fallback derivation)
 *   '_raw_claims' => (array) full flattened OIDC claim set (needed by concat transform)
 *   '_transforms' => (array) [attribute_type => ['function' => string|null, 'params' => string|null]]
 *
 * Returned keys (always present, but may be empty string if email too is empty):
 *   'firstname', 'lastname'
 */
class AdminAttributeMapper implements AttributeMapperInterface
{
    /**
     * @param OAuthUtility $oauthUtility
     * @param Transformer  $transformer
     */
    public function __construct(
        private readonly OAuthUtility $oauthUtility,
        private readonly Transformer $transformer
    ) {
    }

    /**
     * @inheritDoc
     *
     * Applies the email-based name fallback chain:
     * 1. Apply any configured claim transforms for firstname / lastname.
     * 2. If both firstname and lastname are non-empty after transforms, return them.
     * 3. Derive first/last name from the email address prefix using
     *    OAuthUtility::extractNameFromEmail() as the single source of truth (REF-02).
     * 4. If lastname is still empty after derivation, reuse firstname.
     */
    #[\Override]
    public function map(array $flattenedAttrs, array $mappingConfig): array
    {
        $firstName  = (string) ($flattenedAttrs['firstname'] ?? '');
        $lastName   = (string) ($flattenedAttrs['lastname']  ?? '');
        $email      = (string) ($mappingConfig['_email'] ?? '');
        $rawClaims  = (array)  ($mappingConfig['_raw_claims'] ?? $flattenedAttrs);
        $transforms = (array)  ($mappingConfig['_transforms'] ?? []);

        // Apply transforms (e.g. concat from multiple claims, split, prefix)
        if (isset($transforms['firstname'])) {
            $t         = $transforms['firstname'];
            $firstName = $this->transformer->apply(
                $firstName !== '' ? $firstName : null,
                $rawClaims,
                $t['function'] ?? null,
                $t['params'] ?? null
            ) ?? '';
        }
        if (isset($transforms['lastname'])) {
            $t        = $transforms['lastname'];
            $lastName = $this->transformer->apply(
                $lastName !== '' ? $lastName : null,
                $rawClaims,
                $t['function'] ?? null,
                $t['params'] ?? null
            ) ?? '';
        }

        if ($firstName !== '' && $lastName !== '') {
            return ['firstname' => $firstName, 'lastname' => $lastName];
        }

        if ($email !== '') {
            $derived = $this->oauthUtility->extractNameFromEmail($email);

            if ($firstName === '') {
                $firstName = $derived['first'];
                $this->oauthUtility->customlog('AdminAttributeMapper: firstName fallback: ' . $firstName);
            }
            if ($lastName === '') {
                $lastName = $derived['last'] !== '' ? $derived['last'] : $firstName;
                $this->oauthUtility->customlog('AdminAttributeMapper: lastName fallback: ' . $lastName);
            }
        }

        return ['firstname' => $firstName, 'lastname' => $lastName];
    }
}
