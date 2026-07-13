<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Cron;

use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Logger\OidcLogger;

/**
 * Daily cron job that cleans up the OIDC debug log file.
 *
 * Runs once per day (03:00 server time). Disables debug logging and **deletes**
 * (not rotates) the log file when either:
 *  - The file is older than 7 days (LOG_FILE_TIME + 604800 s elapsed), or
 *  - Debug logging was disabled in the admin UI while the log file still exists.
 *
 * Note: this class intentionally deletes the log rather than rotating it.
 * A future improvement would rename the file to M2Oidc.log.1 and keep up to
 * 3 historical copies before deleting.
 */
class LogCleanup
{
    /**
     * @param OAuthUtility $oauthUtility
     * @param OidcLogger   $oidcLogger
     */
    public function __construct(
        private readonly OAuthUtility $oauthUtility,
        private readonly OidcLogger $oidcLogger
    ) {
    }

    /**
     * Execute log cleanup check.
     */
    public function execute(): void
    {
        $logFileTime  = $this->oauthUtility->getStoreConfig(OAuthConstants::LOG_FILE_TIME);
        $isLogEnabled = $this->oauthUtility->getStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG);
        $logFileExists = (bool) $this->oidcLogger->isCustomLogExist();

        $currentTime = time();

        $ageExceeded = ($logFileTime !== null)
            && (($currentTime - (int) $logFileTime) >= 60 * 60 * 24 * 7);

        $cleanupNeeded = ($ageExceeded && $isLogEnabled)
            || (!$isLogEnabled && $logFileExists);

        if ($cleanupNeeded) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG, 0);
            $this->oauthUtility->setStoreConfig(OAuthConstants::LOG_FILE_TIME, null);
            $this->oidcLogger->deleteCustomLogFile();
            $this->oidcLogger->deleteProviderLogFiles();
        }
    }
}
