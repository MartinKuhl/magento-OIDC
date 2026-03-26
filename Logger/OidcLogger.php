<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Logger;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Psr\Log\LoggerInterface;

/**
 * Dedicated logger service for the M2Oidc OAuth module.
 *
 * Extracted from OAuthUtility to give a focused, injectable log API.
 * Writes structured JSON entries to var/log/M2Oidc.log via the module logger.
 *
 * Two output formats are supported, controlled by the `oidc/logging/json_lines`
 * store config flag (default 0 = legacy):
 *
 * Legacy format (json_lines = 0):
 *   Each entry is a JSON object emitted as the Monolog message payload.
 *   The Monolog handler wraps it in its own line format:
 *   [2026-01-01 12:00:00] m2oidc.DEBUG: {"ts":"...","level":"debug","message":"..."} [] []
 *
 * JSON Lines format (json_lines = 1):
 *   Each entry is a standalone newline-delimited JSON object written directly
 *   to the log file, bypassing Monolog's own envelope:
 *   {"ts":"2026-01-01T12:00:00+00:00","level":"debug","message":"...","context":{}}
 */
class OidcLogger
{
    /**
     * Identifies the legacy (Monolog-wrapped) log format.
     * Used as a documentary constant; the format is active when json_lines = 0.
     */
    public const LEGACY_FORMAT = 'monolog_wrapped';

    /**
     * Sensitive field names whose values must be masked before logging.
     */
    public const SENSITIVE_LOG_KEYS = [
        'client_secret', 'access_token', 'id_token', 'refresh_token', 'password', 'token',
    ];

    /**
     * @param LoggerInterface      $psrLogger     PSR logger (wired to Magento\Framework\Logger\Monolog)
     * @param Logger               $moduleLogger  Module-specific Monolog logger writing to M2Oidc.log
     * @param File                 $fileDriver    Filesystem driver for log-file checks
     * @param DirectoryList        $directoryList For resolving var/ path
     * @param ScopeConfigInterface $scopeConfig   For reading the enable_debug_log flag
     */
    public function __construct(
        private readonly LoggerInterface $psrLogger,
        private readonly Logger $moduleLogger,
        private readonly File $fileDriver,
        private readonly DirectoryList $directoryList,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Check if debug logging is enabled.
     */
    public function isLogEnable(): bool
    {
        return (bool) $this->scopeConfig->getValue('m2oidc/oauth/debug_log');
    }

    /**
     * Check whether JSON Lines output format is enabled.
     *
     * When true, log entries are emitted as raw newline-delimited JSON objects
     * directly to the log file (bypassing Monolog's envelope format).
     * When false (default), entries are wrapped by Monolog's own line format.
     */
    public function isJsonLinesEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue('oidc/logging/json_lines');
    }

    /**
     * Write a plain-text message to the custom OIDC log.
     *
     * Legacy format (json_lines = 0):
     *   {"ts":"...","level":"debug","message":"..."}
     *
     * JSON Lines format (json_lines = 1):
     *   {"ts":"...","level":"debug","message":"...","context":{}}
     *
     * @param string $txt Human-readable log message
     */
    public function customlog(string $txt): void
    {
        if (!$this->isLogEnable()) {
            return;
        }

        $payload = [
            'ts'      => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'level'   => 'debug',
            'message' => $txt,
        ];

        if ($this->isJsonLinesEnabled()) {
            $payload['context'] = new \stdClass();
        }

        $entry = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->moduleLogger->debug($entry !== false ? $entry : $txt);
    }

    /**
     * Write a structured JSON log entry with additional context fields.
     *
     * Sensitive context keys are automatically masked with "***".
     *
     * Legacy format (json_lines = 0):
     *   {"ts":"...","level":"info","event":"...","key":"value",...}
     *
     * JSON Lines format (json_lines = 1):
     *   {"ts":"...","level":"info","event":"...","context":{"key":"value",...}}
     *
     * @param string               $event   Short dot-notation event name (e.g. "oidc.login.success")
     * @param array<string, mixed> $context Additional key-value context to include in the log entry
     */
    public function customlogContext(string $event, array $context = []): void
    {
        if (!$this->isLogEnable()) {
            return;
        }

        foreach (self::SENSITIVE_LOG_KEYS as $key) {
            if (isset($context[$key])) {
                $context[$key] = '***';
            }
        }

        if ($this->isJsonLinesEnabled()) {
            $payload = [
                'ts'      => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'level'   => 'info',
                'event'   => $event,
                'context' => $context ?: new \stdClass(),
            ];
        } else {
            $payload = array_merge(
                [
                    'ts'    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'level' => 'info',
                    'event' => $event,
                ],
                $context
            );
        }

        $entry = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->moduleLogger->debug($entry !== false ? $entry : $event);
    }

    /**
     * Check whether the custom OIDC log file exists.
     *
     * @psalm-return 0|1
     */
    public function isCustomLogExist(): int
    {
        try {
            $logPath = $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/log/M2Oidc.log';
            if ($this->fileDriver->isExists($logPath)) {
                return 1;
            }
        } catch (\Exception $e) {
            $this->psrLogger->debug('Path error while checking log file: ' . $e->getMessage());
        }
        return 0;
    }

    /**
     * Delete the custom OAuth log file if it exists.
     */
    public function deleteCustomLogFile(): void
    {
        try {
            $logPath = $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/log/M2Oidc.log';
            if ($this->fileDriver->isExists($logPath)) {
                $this->fileDriver->deleteFile($logPath);
            }
        } catch (\Exception $e) {
            $this->psrLogger->debug('Path error while deleting log file: ' . $e->getMessage());
        }
    }

    /**
     * Common log method accessible from all classes.
     *
     * @param string|object $msg Debug message to log
     * @param mixed|null    $obj Optional object to dump
     */
    public function logDebug(string|object $msg = "", $obj = null): void
    {
        if (is_object($msg)) {
            $this->customlog(json_encode($msg, JSON_UNESCAPED_SLASHES) ?: '');
        } else {
            $this->customlog($msg);
        }

        if ($obj !== null) {
            $this->customlog(json_encode($obj, JSON_UNESCAPED_SLASHES) ?: '');
        }
    }
}
