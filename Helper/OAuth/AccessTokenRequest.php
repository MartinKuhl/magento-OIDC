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
    private $clientID;
    private $clientSecret;
    private $grantType;
    private $redirectURL;
    private $code;

    public function __construct($clientID, $clientSecret, $grantType, $redirectURL, $code)
    {
        // all values required in the authn request are set here
        $this->clientID = $clientID;
        $this->clientSecret = $clientSecret;
        $this->grantType = $grantType;
        $this->redirectURL = $redirectURL;
        $this->code = $code;
    }

    /*
     *
     *
     */
    private function generateRequest()
    {
        
        $accessTokenRequestPostData =  [
            'redirect_uri'     => $this->redirectURL,
            'grant_type'  => OAuthConstants::GRANT_TYPE,
            'client_id'  => $this->clientID,
            'client_secret'  => $this->clientSecret,
            'code'  => $this->code
        ];
        return $accessTokenRequestPostData;
    }


    /**
     * This function is used to build our AccessToken request
     */
    public function build()
    {
        $accessTokenRequestPostData = $this->generateRequest();
        return $accessTokenRequestPostData;
    }
}
