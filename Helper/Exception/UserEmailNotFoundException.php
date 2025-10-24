<?php

namespace MiniOrange\OAuth\Helper\Exception;

use MiniOrange\OAuth\Helper\OAuthMessages;

/**
 * Exception denotes that there was an Invalid Operation
 */
class UserEmailNotFoundException extends \Exception
{
    public function __construct()
    {
        $message     = OAuthMessages::parse('EMAIL_ATTRIBUTE_NOT_RETURNED');
        $code         = 120;
        parent::__construct($message, $code, null);
    }

    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
