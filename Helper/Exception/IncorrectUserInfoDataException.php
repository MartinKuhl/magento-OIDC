<?php

namespace MiniOrange\OAuth\Helper\Exception;

use MiniOrange\OAuth\Helper\OAuthMessages;

/**
 * Exception denotes that there was an Invalid Operation
 */
class IncorrectUserInfoDataException extends \Exception
{
    /**
     * Exception thrown when user info data from the OIDC provider is incorrect.
     */
    public function __construct()
    {
        $message     = OAuthMessages::parse('INVALID_USER_INFO');
        $code         = 119;
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
