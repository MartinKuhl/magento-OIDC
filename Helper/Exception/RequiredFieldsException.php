<?php

namespace MiniOrange\OAuth\Helper\Exception;

use MiniOrange\OAuth\Helper\OAuthMessages;

/**
 * Exception denotes that user has not entered all the requried fields.
 */
class RequiredFieldsException extends \Exception
{
    /**
     * Exception thrown when required fields are missing in the OAuth configuration.
     */
    public function __construct()
    {
        $message     = OAuthMessages::parse('REQUIRED_FIELDS');
        $code         = 104;
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
