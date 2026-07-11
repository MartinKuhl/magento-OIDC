<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Data;

/**
 * Immutable input for CheckAttributeMappingAction::handle().
 *
 * Replaces the setClientDetails()/setUserInfoResponse()/setFlattenedUserInfoResponse()/
 * setUserEmail()/setLoginType()/setHeadless() setter chain that ReadAuthorizationResponse
 * used to configure CheckAttributeMappingAction before calling execute(). Every field is
 * supplied at construction time, so there is no way to invoke handle() with partially
 * configured state.
 */
class OidcAttributeMappingContext
{
    /** @var mixed Raw userinfo response from the provider (array or object) */
    public readonly mixed $userInfoResponse;

    /** @var mixed[] Flattened userinfo attributes */
    public readonly array $flattenedUserInfoResponse;

    /** @var string|null User email extracted from attributes */
    public readonly ?string $userEmail;

    /** @var string|null Login type (admin|customer) */
    public readonly ?string $loginType;

    /** @var bool Headless PWA mode flag (FEAT-09) */
    public readonly bool $headless;

    /** @var mixed[] Provider row data array (MP-07) */
    public readonly array $clientDetails;

    /**
     * @param mixed       $userInfoResponse          Raw userinfo response from the provider (array or object)
     * @param mixed[]     $flattenedUserInfoResponse Flattened userinfo attributes
     * @param string|null $userEmail                 User email extracted from attributes
     * @param string|null $loginType                 Login type (admin|customer)
     * @param bool        $headless                  Headless PWA mode flag (FEAT-09)
     * @param mixed[]     $clientDetails             Provider row data array (MP-07)
     */
    public function __construct(
        mixed $userInfoResponse,
        array $flattenedUserInfoResponse,
        ?string $userEmail,
        ?string $loginType,
        bool $headless,
        array $clientDetails
    ) {
        $this->userInfoResponse = $userInfoResponse;
        $this->flattenedUserInfoResponse = $flattenedUserInfoResponse;
        $this->userEmail = $userEmail;
        $this->loginType = $loginType;
        $this->headless = $headless;
        $this->clientDetails = $clientDetails;
    }
}
