<?php

namespace MiniOrange\OAuth\Helper\OAuth;

use MiniOrange\OAuth\Helper\OAuth\SAML2Utilities;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\Exception\InvalidRequestInstantException;
use MiniOrange\OAuth\Helper\Exception\InvalidRequestVersionException;
use MiniOrange\OAuth\Helper\Exception\MissingIssuerValueException;

/**
 * This class is used to generate our AuthnRequest string.
 */
class AuthorizationRequest
{

    /**
     * @var string
     */
    private $clientID;

    /**
     * @var string
     */
    private $scope;

    /**
     * @var string
     */
    private $authorizeURL;

    /**
     * @var string
     */
    private $responseType;

    /**
     * @var string
     */
    private $redirectURL;

    /**
     * @var array
     */
    private $params;

    /**
     * @var string
     */
    private $state;

    /**
     * @var string|null PKCE code_challenge (RFC 7636 §4.2) — null when PKCE is disabled (FEAT-01)
     */
    private ?string $codeChallenge = null;

    /**
     * Initialize authorization request parameters.
     *
     * @param string      $clientID
     * @param string      $scope
     * @param string      $authorizeURL
     * @param string      $responseType
     * @param string      $redirectURL
     * @param string      $relayState    The pre-validated, encoded state string (SEC-09)
     * @param array       $params        Extra OAuth parameters (nonce, prompt, …)
     * @param string|null $codeChallenge PKCE code_challenge (FEAT-01); null disables PKCE
     */
    public function __construct(
        $clientID,
        $scope,
        $authorizeURL,
        $responseType,
        $redirectURL,
        $relayState,
        $params,
        ?string $codeChallenge = null
    ) {
        $this->clientID      = $clientID;
        $this->scope         = $scope;
        $this->state         = $relayState; // SEC-09: relay state is pre-validated by OAuthSecurityHelper
        $this->authorizeURL  = $authorizeURL;
        $this->responseType  = $responseType;
        $this->redirectURL   = $redirectURL;
        $this->params        = $params;
        $this->codeChallenge = $codeChallenge;
    }

    /**
     * This function is called to generate our authnRequest. This is an internal
     * function and shouldn't be called directly. Call the @build function instead.
     * It returns the string format of the XML and encode it based on the sso
     * binding type.
     *
     * @todo - Have to convert this so that it's not a string value but an XML document
     */
    private function generateRequest(): string
    {
        $requestStr = "";

        if (strpos($this->authorizeURL, '?') === false) {
            $requestStr .= '?';
        }

        $requestStr .=
            'client_id=' . urlencode($this->clientID) .
            '&scope=' . urlencode($this->scope) .
            '&state=' . urlencode($this->state) .
            '&redirect_uri=' . urlencode($this->redirectURL) .
            '&response_type=' . urlencode($this->responseType);

        // Only forward recognized OAuth parameters to the IdP, URL-encoded
        $allowedExtraParams = ['nonce', 'prompt', 'login_hint', 'acr_values'];
        foreach ($this->params as $key => $value) {
            if (in_array($key, $allowedExtraParams, true)) {
                $requestStr .= '&' . urlencode($key) . '=' . urlencode((string) $value);
            }
        }

        // PKCE (RFC 7636 §4.3) — include code_challenge when verifier was generated (FEAT-01)
        if ($this->codeChallenge !== null && $this->codeChallenge !== '') {
            $requestStr .= '&code_challenge=' . urlencode($this->codeChallenge)
                . '&code_challenge_method=S256';
        }

        return $requestStr;
    }

    /**
     * This function is used to build our AuthnRequest. Deflate
     * and encode the AuthnRequest string if the sso binding
     * type is empty or is of type HTTPREDIRECT.
     */
    public function build(): string
    {
        return $this->generateRequest();
    }
}
