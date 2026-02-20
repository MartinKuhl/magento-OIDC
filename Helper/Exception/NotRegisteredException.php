<?php

namespace MiniOrange\OAuth\Helper\Exception;

use MiniOrange\OAuth\Helper\OAuthMessages;

/**
 * Exception denotes that user has not completed his registration.
 */
class NotRegisteredException extends \Exception
{
    /**
     * Exception thrown when a user is not registered.
     */
    public function __construct()
    {
        $message     = OAuthMessages::parse('NOT_REG_ERROR');
        $code         = 102;
        parent::__construct($message, $code, null);
    }

    /**
     * String representation of the exception.
     */
    #[\Override]
    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
