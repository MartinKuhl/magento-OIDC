<?php

namespace MiniOrange\OAuth\Helper\OAuth;

use MiniOrange\OAuth\Helper\OAuth\SAML2Utilities;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\Exception\InvalidRequestInstantException;
use MiniOrange\OAuth\Helper\Exception\InvalidRequestVersionException;
use MiniOrange\OAuth\Helper\Exception\MissingIssuerValueException;

/**
 * This class is used to generate our AuthnRequest string.
 *
 */
class AuthorizationRequest
{

    private $clientID;
    private $scope;
    private $authorizeURL;
    private $responseType;
    private $redirectURL;
    private $params;
    private $state;

    public function __construct($clientID, $scope, $authorizeURL, $responseType, $redirectURL, $relayState,$params)
    {
        $this->clientID = $clientID;
        $this->scope = $scope;
        $this->state = $relayState; // TODO: Security issue will need to handle this better later on
        $this->authorizeURL = $authorizeURL;
        $this->responseType = $responseType;
        $this->redirectURL = $redirectURL;
        $this->params = $params;

    }

    /**
     * This function is called to generate our authnRequest. This is an internal
     * function and shouldn't be called directly. Call the @build function instead.
     * It returns the string format of the XML and encode it based on the sso
     * binding type.
     *
     * @todo - Have to convert this so that it's not a string value but an XML document
     */
    private function generateRequest()
    {
        $requestStr = "";

        if (!(strpos($this->authorizeURL, '?') !== false)) {
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
                $requestStr .= '&' . urlencode($key) . '=' . urlencode($value);
            }
        }

        return $requestStr;
    }


    /**
     * This function is used to build our AuthnRequest. Deflate
     * and encode the AuthnRequest string if the sso binding
     * type is empty or is of type HTTPREDIRECT.
     */
    public function build()
    {
        return $this->generateRequest();
    }
}
