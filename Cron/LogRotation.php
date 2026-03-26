<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Cron;

use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Logger\OidcLogger;

/**
 * @deprecated 3.0.8 Use LogCleanup instead. This class is kept only for backward compatibility
 *             with third-party code that may reference M2Oidc\OAuth\Cron\LogRotation.
 *             Will be removed in v4.0.0.
 */
class LogRotation extends LogCleanup
{
    /**
     * @param OAuthUtility $oauthUtility
     * @param OidcLogger   $oidcLogger
     */
    public function __construct(OAuthUtility $oauthUtility, OidcLogger $oidcLogger)
    {
        parent::__construct($oauthUtility, $oidcLogger);
    }
}
