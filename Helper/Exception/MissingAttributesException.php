<?php

namespace MiniOrange\OAuth\Helper\Exception;

use MiniOrange\OAuth\Helper\OAuthMessages;

/**
 * Exception denotes that the SAML resquest or response has missing
 * ID attribute.
 */
class MissingAttributesException extends \Exception
{
    /**
     * Exception thrown when required OIDC attributes are missing.
     */
    public function __construct()
    {
        $message     = OAuthMessages::parse('MISSING_ATTRIBUTES_EXCEPTION');
        $code         = 125;
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
