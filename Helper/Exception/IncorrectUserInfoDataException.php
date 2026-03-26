<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Helper\Exception;

use M2Oidc\OAuth\Helper\OAuthMessages;

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
