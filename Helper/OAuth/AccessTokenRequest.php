<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Helper\OAuth;

use M2Oidc\OAuth\Helper\OAuth\SAML2Utilities;
use M2Oidc\OAuth\Helper\OAuthConstants;

/**
 * Builds the query-string body for an OAuth2/OIDC access token exchange request
 * (used when the client credentials are sent in the Authorization header rather
 * than the request body — see AccessTokenRequestBody for the alternative shape).
 */
class AccessTokenRequest
{
    /**
     * @var string
     */
    private $clientID;

    /**
     * @var string
     */
    private $clientSecret;

    /**
     * @var string
     */
    private $redirectURL;

    /**
     * @var string
     */
    private $code;

    /**
     * @var string|null PKCE code_verifier (RFC 7636 §4.5) — null when PKCE is disabled (FEAT-01)
     */
    private readonly ?string $codeVerifier;

    /**
     * Initialize access token request.
     *
     * @param string      $clientID
     * @param string      $clientSecret
     * @param string      $redirectURL
     * @param string      $code
     * @param string|null $codeVerifier PKCE code_verifier; null when PKCE is not in use
     */
    public function __construct($clientID, $clientSecret, $redirectURL, $code, ?string $codeVerifier = null)
    {
        $this->clientID     = $clientID;
        $this->clientSecret = $clientSecret;
        $this->redirectURL  = $redirectURL;
        $this->code         = $code;
        $this->codeVerifier = $codeVerifier;
    }

    /**
     * Build the access token request as an associative array.
     *
     * @return array<string, string>
     */
    private function generateRequest(): array
    {
        $body = [
            'redirect_uri' => $this->redirectURL,
            'grant_type'   => OAuthConstants::GRANT_TYPE,
            'client_id'    => $this->clientID,
            'code'         => $this->code,
        ];

        // Omit client_secret for public clients (RFC 6749 §4.1.3).
        // Public clients identify themselves via client_id but do not authenticate.
        if ($this->clientSecret !== null && $this->clientSecret !== '') {
            $body['client_secret'] = $this->clientSecret;
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
