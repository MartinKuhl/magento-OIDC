<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Cron;

use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthUtility;

/**
 * Daily cron job that rotates the OIDC debug log file.
 *
 * Runs once per day (03:00 server time). Disables debug logging and deletes
 * the log file when either:
 *  - The file is older than 7 days (LOG_FILE_TIME + 604800 s elapsed), or
 *  - Debug logging was disabled in the admin UI while the log file still exists.
 */
class LogRotation
{
    /**
     * @param OAuthUtility $oauthUtility
     */
    public function __construct(private readonly OAuthUtility $oauthUtility)
    {
    }

    /**
     * Execute log rotation check.
     */
    public function execute(): void
    {
        $logFileTime  = $this->oauthUtility->getStoreConfig(OAuthConstants::LOG_FILE_TIME);
        $isLogEnabled = $this->oauthUtility->getStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG);
        $logFileExists = (bool) $this->oauthUtility->isCustomLogExist();

        $currentTime = time();

        $ageExceeded = ($logFileTime !== null)
            && (($currentTime - (int) $logFileTime) >= 60 * 60 * 24 * 7);

        $rotationNeeded = ($ageExceeded && $isLogEnabled)
            || (!$isLogEnabled && $logFileExists);

        if ($rotationNeeded) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG, 0);
            $this->oauthUtility->setStoreConfig(OAuthConstants::LOG_FILE_TIME, null);
            $this->oauthUtility->deleteCustomLogFile();
        }
    }
}
