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
 * Per-provider log isolation: pass the provider's `log_file_suffix` value as
 * the optional $logSuffix parameter on customlog() / customlogContext(). When
 * set, the entry is written to var/log/M2Oidc_<suffix>.log instead of the
 * shared log. The per-suffix Monolog loggers are created lazily and cached for
 * the lifetime of the request.
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
     * Cache of per-suffix Monolog logger instances, keyed by sanitised suffix.
     *
     * @var array<string, \Monolog\Logger>
     */
    private array $suffixLoggers = [];

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
        return (bool) $this->scopeConfig->getValue('m2oidc/logging/json_lines');
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
     * @param string $txt       Human-readable log message
     * @param string $logSuffix Optional per-provider log file suffix (from provider's log_file_suffix column).
     *                          When non-empty, writes to var/log/M2Oidc_<suffix>.log instead of the shared log.
     */
    public function customlog(string $txt, string $logSuffix = ''): void
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

        $entry  = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $logger = $this->resolveLogger($logSuffix);
        $logger->debug($entry !== false ? $entry : $txt);
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
     * @param string  $event     Short dot-notation event name (e.g. "oidc.login.success")
     * @param mixed[] $context   Additional key-value context to include in the log entry
     * @param string  $logSuffix Optional per-provider log file suffix (see customlog())
     */
    public function customlogContext(string $event, array $context = [], string $logSuffix = ''): void
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

        $entry  = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $logger = $this->resolveLogger($logSuffix);
        $logger->debug($entry !== false ? $entry : $event);
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
     * Delete all per-provider log files matching var/log/M2Oidc_*.log.
     *
     * Called by LogCleanup cron to rotate suffix-based log files with the same
     * age/disabled criteria applied to the shared M2Oidc.log.
     */
    public function deleteProviderLogFiles(): void
    {
        try {
            $logDir = $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/log/';
            $files = \Magento\Framework\Filesystem\Glob::glob($logDir . 'M2Oidc_*.log');
            foreach ($files as $file) {
                try {
                    if ($this->fileDriver->isExists($file)) {
                        $this->fileDriver->deleteFile($file);
                    }
                } catch (\Exception $inner) {
                    $this->psrLogger->debug('Could not delete provider log file: ' . $inner->getMessage());
                }
            }
        } catch (\Exception $e) {
            $this->psrLogger->debug('Path error while deleting provider log files: ' . $e->getMessage());
        }
    }

    /**
     * Common log method accessible from all classes.
     *
     * @param string|object $msg       Debug message to log
     * @param mixed|null    $obj       Optional object to dump
     * @param string        $logSuffix Optional per-provider log file suffix
     */
    public function logDebug(string|object $msg = "", $obj = null, string $logSuffix = ''): void
    {
        if (is_object($msg)) {
            $this->customlog(json_encode($msg, JSON_UNESCAPED_SLASHES) ?: '', $logSuffix);
        } else {
            $this->customlog($msg, $logSuffix);
        }

        if ($obj !== null) {
            $this->customlog(json_encode($obj, JSON_UNESCAPED_SLASHES) ?: '', $logSuffix);
        }
    }

    /**
     * Resolve the Monolog logger to write to.
     *
     * Returns the shared module logger when $logSuffix is empty.
     * For a non-empty suffix, lazily creates (and caches) a StreamHandler-backed
     * Monolog logger writing to var/log/M2Oidc_<suffix>.log.
     *
     * @param string $logSuffix Provider's log_file_suffix value (may be empty)
     * @return \Monolog\Logger
     */
    private function resolveLogger(string $logSuffix): \Monolog\Logger
    {
        // Sanitise: allow only letters, digits, underscores, hyphens
        $suffix = preg_replace('/[^a-zA-Z0-9_-]/', '', $logSuffix);

        if ($suffix === null || $suffix === '') {
            return $this->moduleLogger;
        }

        if (isset($this->suffixLoggers[$suffix])) {
            return $this->suffixLoggers[$suffix];
        }

        // M-12: Evict oldest entry when cache exceeds 20 loggers
        if (count($this->suffixLoggers) >= 20) {
            array_shift($this->suffixLoggers);
        }

        try {
            $logPath = $this->directoryList->getPath(DirectoryList::VAR_DIR)
                . '/log/M2Oidc_' . $suffix . '.log';
            $handler  = new \Monolog\Handler\StreamHandler($logPath, \Monolog\Level::Debug);
            $logger   = new \Monolog\Logger('m2oidc_' . $suffix, [$handler]);
            $this->suffixLoggers[$suffix] = $logger;
        } catch (\Exception $e) {
            // Fall back to the shared logger if the suffix file cannot be opened
            $this->psrLogger->debug('Could not open provider log file, falling back: ' . $e->getMessage());
            $this->suffixLoggers[$suffix] = $this->moduleLogger;
        }

        return $this->suffixLoggers[$suffix];
    }
}
