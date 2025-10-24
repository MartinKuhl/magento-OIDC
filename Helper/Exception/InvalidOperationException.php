<?php

namespace MiniOrange\OAuth\Helper\Exception;

use MiniOrange\OAuth\Helper\OAuthMessages;

/**
 * Exception denotes that there was an Invalid Operation
 */
class InvalidOperationException extends \Exception
{
    public function __construct()
    {
        $message     = OAuthMessages::parse('INVALID_OP');
        $code         = 105;
        parent::__construct($message, $code, null);
    }

    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
