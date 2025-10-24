<?php

namespace MiniOrange\OAuth\Helper\Exception;

use MiniOrange\OAuth\Helper\OAuthMessages;

/**
 * Exception denotes that the user trying to log in
 * or register in the plugin already has an account
 * and that the credentials provided are incorrect
 */
class InvalidEmailException extends \Exception
{
    public function __construct()
    {
        $message     = OAuthMessages::parse('INVALID_EMAIL_FORMAT');
        $code         = 121;
        parent::__construct($message, $code, null);
    }

    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
