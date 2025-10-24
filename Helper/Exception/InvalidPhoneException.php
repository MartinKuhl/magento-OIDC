<?php

namespace MiniOrange\OAuth\Helper\Exception;

use MiniOrange\OAuth\Helper\OAuthMessages;

/**
 * Exception denotes that user didnot provide a valid
 * Certificate for encrypted assertion from the SP.
 */
class InvalidPhoneException extends \Exception
{
    public function __construct($phone)
    {
        $message     = OAuthMessages::parse('ERROR_PHONE_FORMAT', ['phone'=>$phone]);
        $code         = 112;
        parent::__construct($message, $code, null);
    }

    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
