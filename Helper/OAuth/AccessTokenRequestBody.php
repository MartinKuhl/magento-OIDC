<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Helper\OAuth;

use M2Oidc\OAuth\Helper\OAuth\SAML2Utilities;
use M2Oidc\OAuth\Helper\OAuthConstants;

/**
 * Builds the associative-array body for an OAuth2/OIDC access token exchange request
 * when client credentials are sent in the body rather than an Authorization header
 * (see AccessTokenRequest for the header-based alternative).
 */
class AccessTokenRequestBody
{
    /**
     * @var string
     */
    private $redirectURL;

    /**
     * @var string
     */
    private $code;

    /**
     * @var string|null PKCE code_verifier to include in the token request
     */
    private readonly ?string $codeVerifier;

    /**
     * @var string|null OAuth client ID to include in the body for public clients;
     *                  null when the client authenticates via HTTP Basic (RFC 6749 §2.3.1)
     */
    private readonly ?string $clientID;

    /**
     * Initialize access token request body.
     *
     * @param string      $redirectURL
     * @param string      $code
     * @param string|null $codeVerifier PKCE code_verifier (RFC 7636 §4.5); null when PKCE is disabled
     * @param string|null $clientID     Client ID for public clients; null when HTTP Basic auth is used
     */
    public function __construct($redirectURL, $code, ?string $codeVerifier = null, ?string $clientID = null)
    {
        $this->redirectURL  = $redirectURL;
        $this->code         = $code;
        $this->codeVerifier = $codeVerifier;
        $this->clientID     = $clientID;
    }

    /**
     * Build the request body as an associative array.
     *
     * @return array<string, string>
     */
    private function generateRequest(): array
    {
        $body = [
            'redirect_uri' => $this->redirectURL,
            'grant_type'   => OAuthConstants::GRANT_TYPE,
            'code'         => $this->code,
        ];

        // Public clients send no Authorization header, so the token endpoint
        // can only identify them via a client_id body parameter (RFC 6749 §3.2.1).
        // Confidential clients authenticate via HTTP Basic and must not duplicate
        // client_id in the body (RFC 6749 §2.3.1) — they pass null here.
        if ($this->clientID !== null && $this->clientID !== '') {
            $body['client_id'] = $this->clientID;
        }

        // PKCE (RFC 7636 §4.5): include code_verifier when PKCE is enabled (FEAT-01)
        if ($this->codeVerifier !== null && $this->codeVerifier !== '') {
            $body['code_verifier'] = $this->codeVerifier;
        }

        return $body;
    }

    /**
     * This function is used to build our AccessToken request
     *
     * @return array<string, string>
     */
    public function build(): array
    {
        return $this->generateRequest();
    }
}
