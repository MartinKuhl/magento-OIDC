<?php

namespace MiniOrange\OAuth\Helper\OAuth;

use MiniOrange\OAuth\Helper\OAuth\SAML2Utilities;
use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * This class is used to generate our AuthnRequest object.
 * The generate function is called to generate an XML
 * document that can then be passed to the IDP for
 * validation.
 *
 * @todo - the generateXML function uses string. Need to convert it so that request
 *        - is generated using \Dom functions
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
     * @var string|null PKCE code_verifier to include in the token request (FEAT-01)
     */
    private readonly ?string $codeVerifier;

    /**
     * Initialize access token request body.
     *
     * @param string      $redirectURL
     * @param string      $code
     * @param string|null $codeVerifier PKCE code_verifier (RFC 7636 §4.5); null when PKCE is disabled
     */
    public function __construct($redirectURL, $code, ?string $codeVerifier = null)
    {
        $this->redirectURL  = $redirectURL;
        $this->code         = $code;
        $this->codeVerifier = $codeVerifier;
    }

    /**
     * Build the request body as an associative array.
     */
    private function generateRequest(): array
    {
        $body = [
            'redirect_uri' => $this->redirectURL,
            'grant_type'   => OAuthConstants::GRANT_TYPE,
            'code'         => $this->code,
        ];

        // PKCE (RFC 7636 §4.5): include code_verifier when PKCE is enabled (FEAT-01)
        if ($this->codeVerifier !== null && $this->codeVerifier !== '') {
            $body['code_verifier'] = $this->codeVerifier;
        }

        return $body;
    }

    /**
     * This function is used to build our AccessToken request
     */
    public function build(): array
    {
        return $this->generateRequest();
    }
}
