<?php

namespace MiniOrange\OAuth\Helper\Exception;

use MiniOrange\OAuth\Helper\OAuthMessages;

/**
 * Exception denotes that the user trying to log in
 * or register in the plugin already has an account
 * and that the credentials provided are incorrect
 */
class AccountAlreadyExistsException extends \Exception
{
    public function __construct()
    {
        $message     = OAuthMessages::parse('ACCOUNT_EXISTS');
        $code         = 108;
        parent::__construct($message, $code, null);
    }

    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
