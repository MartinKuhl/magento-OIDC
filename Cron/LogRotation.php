<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Cron;

use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Logger\OidcLogger;

/**
 * @deprecated since 3.0.8, use LogCleanup. Will be removed in v4.0.0.
 * @see \M2Oidc\OAuth\Cron\LogCleanup
 */
class LogRotation extends LogCleanup
{
    /**
     * @param OAuthUtility $oauthUtility
     * @param OidcLogger   $oidcLogger
     */
    // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
    public function __construct(OAuthUtility $oauthUtility, OidcLogger $oidcLogger)
    {
        parent::__construct($oauthUtility, $oidcLogger);
    }
}
