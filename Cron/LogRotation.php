<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Cron;

use M2Oidc\OAuth\Helper\OAuthUtility;

/**
 * @deprecated 3.0.8 Use LogCleanup instead. This class is kept only for backward compatibility
 *             with third-party code that may reference M2Oidc\OAuth\Cron\LogRotation.
 *             Will be removed in v4.0.0.
 */
class LogRotation extends LogCleanup
{
    /**
     * @param OAuthUtility $oauthUtility
     */
    public function __construct(OAuthUtility $oauthUtility)
    {
        parent::__construct($oauthUtility);
    }
}
