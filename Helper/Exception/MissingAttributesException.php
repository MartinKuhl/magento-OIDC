<?php

namespace MiniOrange\OAuth\Helper\Exception;

use MiniOrange\OAuth\Helper\OAuthMessages;

/**
 * Exception denotes that the SAML resquest or response has missing
 * ID attribute.
 */
class MissingAttributesException extends \Exception
{
    public function __construct()
    {
        $message     = OAuthMessages::parse('MISSING_ATTRIBUTES_EXCEPTION');
        $code         = 125;
        parent::__construct($message, $code, null);
    }

    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
