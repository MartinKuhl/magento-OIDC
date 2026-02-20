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
     * Initialize access token request.
     *
     * @param string $clientID
     * @param string $clientSecret
     * @param string $redirectURL
     * @param string $code
     */
    public function __construct($clientID, $clientSecret, $redirectURL, $code)
    {
        // all values required in the authn request are set here
        $this->clientID = $clientID;
        $this->clientSecret = $clientSecret;
        $this->redirectURL = $redirectURL;
        $this->code = $code;
    }

    /*
     *
     *
     */
    /**
     * Build the access token request as an associative array.
     */
    private function generateRequest(): array
    {

        return [
            'redirect_uri' => $this->redirectURL,
            'grant_type' => OAuthConstants::GRANT_TYPE,
            'client_id' => $this->clientID,
            'client_secret' => $this->clientSecret,
            'code' => $this->code
        ];
    }

    /**
     * This function is used to build our AccessToken request
     */
    public function build(): array
    {
        return $this->generateRequest();
    }
}
