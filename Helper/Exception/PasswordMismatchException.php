<?php

namespace MiniOrange\OAuth\Helper\Exception;

use MiniOrange\OAuth\Helper\OAuthMessages;

/**
 * Exception denotes that there was a password mismatch
 */
class PasswordMismatchException extends \Exception
{
    public function __construct()
    {
        $message     = OAuthMessages::parse('PASS_MISMATCH');
        $code         = 122;
        parent::__construct($message, $code, null);
    }

    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
