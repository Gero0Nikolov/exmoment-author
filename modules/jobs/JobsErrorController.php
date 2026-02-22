<?php

namespace ExMomentAuthor\Modules\Jobs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use ExMomentAuthor\Core\ExMomentAuthorCoreSystem;
use ExMomentAuthor\Modules\Log\LogService;

/**
 * Centralised error logging for job execution diagnostics.
 */
class JobsErrorController {

    private const LOG_TYPE = 'ExMomentAuthorErrorLog';

    /**
     * Shared log writer instance.
     *
     * @var LogService|null
     */
    private $logService;

    /**
     * Instantiate the controller.
     *
     * @param array<string, mixed> $config Optional configuration payload.
     * @return void
     */
    public function __construct(array $config = []) {
        unset($config);

        $this->logService = $this->resolveLogService();
    }

    /**
     * Record a structured log entry when no eligible content was available.
     *
     * The payload is written as JSON with the keys `type`, `code`, `job_id`,
     * `directory`, `mode`, and `timestamp`. The directory label is sanitized
     * via {@see self::sanitizeDirectory()} which applies `sanitize_text_field()`
     * (when available) and truncates the value to 191 characters to preserve log
     * format constraints.
     *
     * @since 1.1.0
     *
     * @param int    $jobId     Job identifier; coerced to a non-negative integer via
     *                          {@see absint()} before logging.
     * @param string $directory Directory slug or label; sanitized and length-capped by
     *                          {@see self::sanitizeDirectory()}.
     * @return bool True when the entry is dispatched to a log handler.
     */
    public function logNoAvailableContent($jobId, $directory) {
        $jobId = absint($jobId);
        $directory = $this->sanitizeDirectory($directory);

        $context = [
            'type'      => self::LOG_TYPE,
            'code'      => 'no_available_content',
            'directory' => $directory,
            'mode'      => 'uniqueness_filter',
            'timestamp' => gmdate('c'),
        ];

        return $this->writeLogEntry('warning', 'Job run skipped: no available content.', $context, $jobId);
    }

    /**
     * Record a structured log entry when the used-articles registry write fails.
     *
     * The payload is written as JSON with the keys `type`, `code`, `job_id`,
     * `file`, `hash`, and `timestamp`. File basenames are sanitized via
     * {@see self::sanitizeBasename()} which applies `sanitize_file_name()` when
     * available (falling back to a character whitelist) and truncates values to
     * 191 characters. The registry hash is normalized via
     * {@see self::sanitizePathHash()} to digits only and capped at 20 characters
     * so log lines stay within the expected length.
     *
     * @since 1.1.0
     *
     * @param int    $jobId    Job identifier; coerced to a non-negative integer via
     *                         {@see absint()} before logging.
     * @param string $basename Selected file basename; sanitized and capped by
     *                         {@see self::sanitizeBasename()}.
     * @param string $pathHash Digits-only path hash; normalized and length-capped by
     *                         {@see self::sanitizePathHash()}.
     * @return bool True when the entry is dispatched to a log handler.
     */
    public function logUsedRegistryWriteFailure($jobId, $basename, $pathHash) {
        $jobId = absint($jobId);
        $basename = $this->sanitizeBasename($basename);
        $pathHash = $this->sanitizePathHash($pathHash);

        $context = [
            'type'      => self::LOG_TYPE,
            'code'      => 'used_registry_write_failed',
            'file'      => $basename,
            'hash'      => $pathHash,
            'timestamp' => gmdate('c'),
        ];

        return $this->writeLogEntry('error', 'Used registry write failed for generated article.', $context, $jobId);
    }

    /**
     * Persist the payload to the standard WordPress debug log.
     *
     * @param string                $level   Logging severity level; defaults to `info` for unrecognised values.
     * @param string                $message Human-readable log message.
     * @param array<string, mixed>  $context Structured log payload. Expected keys include
     *                                       `type` (string), `code` (string), `timestamp` (ISO 8601 string), and
     *                                       additional fields specific to the event (for example `job_id`,
     *                                       `directory`, `mode`, `file`, or `hash`).
     * @param int|string            $jobId   Job identifier forwarded to the log channel.
     * @return bool True when a handler accepts the entry; false when no logging backend is available.
     */
    private function writeLogEntry($level, $message, array $context, $jobId) {
        $service = $this->logService instanceof LogService ? $this->logService : $this->resolveLogService();

        if ($service instanceof LogService) {
            switch ($level) {
                case 'debug':
                    return $service->debug('jobs.error', $message, $context, $jobId);
                case 'info':
                    return $service->info('jobs.error', $message, $context, $jobId);
                case 'warning':
                    return $service->warning('jobs.error', $message, $context, $jobId);
                case 'error':
                    return $service->error('jobs.error', $message, $context, $jobId);
                case 'critical':
                    return $service->critical('jobs.error', $message, $context, $jobId);
                default:
                    return $service->info('jobs.error', $message, $context, $jobId);
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $payload = [
                'level'   => $level,
                'message' => $message,
                'context' => $context,
                'job_id'  => $jobId,
            ];

            $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json) && $json !== '') {
                error_log($json); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }

        return false;
    }

    /**
     * Sanitize the directory label for logging.
     *
     * @param string $directory Directory slug or label.
     * @return string Sanitized directory label capped at 191 characters.
     */
    private function sanitizeDirectory($directory) {
        if (!is_string($directory)) {
            return '';
        }

        $sanitized = function_exists('sanitize_text_field')
            ? sanitize_text_field($directory)
            : trim($directory);

        return substr($sanitized, 0, 191);
    }

    /**
     * Sanitize the basename for logging.
     *
     * @param string $basename File basename.
     * @return string Sanitized basename capped at 191 characters.
     */
    private function sanitizeBasename($basename) {
        if (!is_string($basename)) {
            return '';
        }

        if (function_exists('sanitize_file_name')) {
            $sanitized = sanitize_file_name($basename);
        } else {
            $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '', $basename);
            if (!is_string($sanitized)) {
                $sanitized = '';
            }
        }

        return substr($sanitized, 0, 191);
    }

    /**
     * Sanitize the path hash value.
     *
     * @param string $pathHash Digits-only path hash.
     * @return string Sanitized, digits-only hash capped at 20 characters.
     */
    private function sanitizePathHash($pathHash) {
        $hash = preg_replace('/[^0-9]/', '', (string) $pathHash);

        if (!is_string($hash)) {
            $hash = '';
        }

        return substr($hash, 0, 20);
    }

    /**
     * Resolve the log service instance.
     *
     * @return LogService|null Shared log service instance when available; null when resolution fails.
     */
    private function resolveLogService() {
        $core = ExMomentAuthorCoreSystem::getInstance();
        if ($core instanceof ExMomentAuthorCoreSystem) {
            $service = $core->getModule('LogService');
            if ($service instanceof LogService) {
                return $service;
            }
        }

        return LogService::getInstance();
    }
}

