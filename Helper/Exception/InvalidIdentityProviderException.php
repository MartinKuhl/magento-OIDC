<?php

namespace MiniOrange\OAuth\Helper\Exception;

use MiniOrange\OAuth\Helper\OAuthMessages;

/**
 * Exception denotes that IDP is not valid as it maynot
 * have all the necessary information about a IDP
 */
class InvalidIdentityProviderException extends \Exception
{
    public function __construct()
    {
        $message     = OAuthMessages::parse('INVALID_IDP');
        $code         = 119;
        parent::__construct($message, $code, null);
    }

    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
