<?php

namespace MiniOrange\OAuth\Helper\Exception;

use MiniOrange\OAuth\Helper\OAuthMessages;

/**
 * Exception denotes that user didnot provide a valid
 * password and confirm password.
 */
class RegistrationRequiredFieldsException extends \Exception
{
    public function __construct()
    {
        $message     = OAuthMessages::parse('REQUIRED_REGISTRATION_FIELDS');
        $code         = 111;
        parent::__construct($message, $code, null);
    }

    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
