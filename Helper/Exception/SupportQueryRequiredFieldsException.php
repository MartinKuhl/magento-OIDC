<?php

namespace M2Oidc\OAuth\Helper\Exception;

use M2Oidc\OAuth\Helper\OAuthMessages;

/**
final  * Exception denotes that admin didnot fill the required
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
        parent::__construct($message, $code);
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
