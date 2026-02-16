<?php

namespace MiniOrange\OAuth\Helper\Exception;

use MiniOrange\OAuth\Helper\OAuthMessages;

/**
 * Exception denotes that admin didnot fill the required
 * support query form field values.
 */
class SupportQueryRequiredFieldsException extends \Exception
{
    /**
     * Exception thrown when required fields for a support query are missing.
     */
    public function __construct()
    {
        $message     = OAuthMessages::parse('REQUIRED_QUERY_FIELDS');
        $code         = 109;
        parent::__construct($message, $code, null);
    }

    /**
     * Initialize exception with a descriptive message.
     *
     * @param string          $message
     * @param int             $code
     * @param \Throwable|null $previous
     */
    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
