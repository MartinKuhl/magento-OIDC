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
    private $clientID;

    /**
     * @var string
     */
    private $clientSecret;

    /**
     * @var string
     */
    private $grantType;

    /**
     * @var string
     */
    private $redirectURL;

    /**
     * @var string
     */
    private $code;

    /**
     * Initialize access token request body.
     *
     * @param string $grantType
     * @param string $redirectURL
     * @param string $code
     */
    public function __construct($grantType, $redirectURL, $code)
    {
        // all values required in the authn request are set here
        // $this->clientID = $clientID;
        // $this->clientSecret = $clientSecret;
        $this->grantType = $grantType;
        $this->redirectURL = $redirectURL;
        $this->code = $code;
    }

    /*
     *
     *
     */
    /**
     * Build the request body as an associative array.
     *
     * @return array
     */
    private function generateRequest()
    {

        $accessTokenRequestPostData = [
            'redirect_uri' => $this->redirectURL,
            'grant_type' => OAuthConstants::GRANT_TYPE,
            'code' => $this->code
        ];

        return $accessTokenRequestPostData;
    }

    /**
     * This function is used to build our AccessToken request
     */
    public function build(): array
    {
        $accessTokenRequestPostData = $this->generateRequest();
        return $accessTokenRequestPostData;
    }
}
