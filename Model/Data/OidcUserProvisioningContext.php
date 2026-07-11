<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Data;

/**
 * Immutable input for ProcessUserAction::handle().
 *
 * Replaces the setAttrs()/setFlattenedAttrs()/setUserEmail()/setAutoCreateCustomer()/
 * setProviderId()/setHeadless() setter chain that CheckAttributeMappingAction used to
 * configure ProcessUserAction before calling execute(). Every field is supplied at
 * construction time, so there is no way to invoke handle() with partially configured state.
 */
class OidcUserProvisioningContext
{
    /** @var mixed Raw OAuth response attributes */
    public readonly mixed $attrs;

    /** @var mixed[] Flattened attribute map (simple key => value mapping) */
    public readonly array $flattenedAttrs;

    /** @var string User's email address resolved from attributes */
    public readonly string $userEmail;

    /** @var int|null Per-provider auto-create customer flag (null = fall back to global config) */
    public readonly ?int $autoCreateCustomer;

    /** @var int OIDC provider ID to record when a new customer is created (0 = not tracked) */
    public readonly int $providerId;

    /** @var bool Headless PWA mode flag (FEAT-09) */
    public readonly bool $headless;

    /**
     * @param mixed    $attrs              Raw OAuth response attributes
     * @param mixed[]  $flattenedAttrs     Flattened attribute map (simple key => value mapping)
     * @param string   $userEmail          User's email address resolved from attributes
     * @param int|null $autoCreateCustomer Per-provider auto-create customer flag (null = use global config)
     * @param int      $providerId         OIDC provider ID to record when a new customer is created
     * @param bool     $headless           Headless PWA mode flag (FEAT-09)
     */
    public function __construct(
        mixed $attrs,
        array $flattenedAttrs,
        string $userEmail,
        ?int $autoCreateCustomer,
        int $providerId,
        bool $headless
    ) {
        $this->attrs = $attrs;
        $this->flattenedAttrs = $flattenedAttrs;
        $this->userEmail = $userEmail;
        $this->autoCreateCustomer = $autoCreateCustomer;
        $this->providerId = $providerId;
        $this->headless = $headless;
    }
}
