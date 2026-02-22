<?php

namespace ExMomentAuthor\Modules\Log;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use DateTimeInterface;
use ExMomentAuthor\Core\ExMomentAuthorCoreSystem;
use Throwable;
use wpdb;

/**
 * Persist sanitized diagnostic logs to the dedicated database table.
 */
class LogService {

    private const TABLE_NAME = 'exmoau_logs';
    private const OPTION_SCHEMA_VERSION = 'exmoau_logs_schema_version';
    private const SCHEMA_VERSION = '1.0.0';
    private const MAX_MESSAGE_LENGTH = 1000;
    private const MAX_CONTEXT_LENGTH = 4000;
    private const MAX_CONTEXT_VALUE_LENGTH = 512;
    private const ALLOWED_LEVELS = ['debug', 'info', 'warning', 'error', 'critical'];
    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE = 100;

    /**
     * Cached singleton instance for reuse across modules.
     *
     * @var LogService|null
     */
    private static $instance = null;

    /**
     * Tracks whether the log schema has been prepared for the current request.
     *
     * @var bool
     */
    private static $schemaReady = false;

    /**
     * WordPress database driver.
     *
     * @var wpdb|null
     */
    private $wpdb;

    /**
     * Initialise the service.
     *
     * @param array<string, mixed> $config Optional configuration (unused).
     * @return void
     */
    public function __construct(array $config = []) {
        unset($config);

        global $wpdb;

        $this->wpdb = ($wpdb instanceof wpdb) ? $wpdb : null;

        self::$instance = $this;
    }

    /**
     * Retrieve the shared service instance.
     *
     * @return LogService
     */
    public static function getInstance() {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        $core = ExMomentAuthorCoreSystem::getInstance();
        if ($core instanceof ExMomentAuthorCoreSystem) {
            $controller = $core->getModule('LogService');
            if ($controller instanceof self) {
                self::$instance = $controller;

                return self::$instance;
            }
        }

        self::$instance = new self();

        return self::$instance;
    }

    /**
     * Provision or upgrade the log schema during activation.
     *
     * Writes or updates the database table and bumps the stored schema
     * version option when needed.
     *
     * @return void
     */
    public static function activate() {
        self::maybeUpgradeSchema();
    }

    /**
     * Record a debug log entry when WP_DEBUG is enabled.
     *
     * Persists a sanitized row to the logs table when debugging is active.
     *
     * @param string               $source  Component or subsystem identifier.
     * @param string               $message Log message.
     * @param array<string, mixed> $context Optional context payload.
     * @param int|null             $jobId   Associated job identifier.
     * @return bool True when the entry was queued for database insertion.
     */
    public function debug($source, $message, array $context = [], $jobId = null) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return false;
        }

        return $this->log('debug', $source, $message, $context, $jobId);
    }

    /**
     * Record an informational log entry.
     *
     * Persists the message to the logs database table.
     *
     * @param string               $source  Component or subsystem identifier.
     * @param string               $message Log message.
     * @param array<string, mixed> $context Optional context payload.
     * @param int|null             $jobId   Associated job identifier.
     * @return bool True when the entry was queued for database insertion.
     */
    public function info($source, $message, array $context = [], $jobId = null) {
        return $this->log('info', $source, $message, $context, $jobId);
    }

    /**
     * Record a warning log entry.
     *
     * Persists the message to the logs database table.
     *
     * @param string               $source  Component or subsystem identifier.
     * @param string               $message Log message.
     * @param array<string, mixed> $context Optional context payload.
     * @param int|null             $jobId   Associated job identifier.
     * @return bool True when the entry was queued for database insertion.
     */
    public function warning($source, $message, array $context = [], $jobId = null) {
        return $this->log('warning', $source, $message, $context, $jobId);
    }

    /**
     * Record an error log entry.
     *
     * Persists the message to the logs database table.
     *
     * @param string               $source  Component or subsystem identifier.
     * @param string               $message Log message.
     * @param array<string, mixed> $context Optional context payload.
     * @param int|null             $jobId   Associated job identifier.
     * @return bool True when the entry was queued for database insertion.
     */
    public function error($source, $message, array $context = [], $jobId = null) {
        return $this->log('error', $source, $message, $context, $jobId);
    }

    /**
     * Record a critical log entry.
     *
     * Persists the message to the logs database table.
     *
     * @param string               $source  Component or subsystem identifier.
     * @param string               $message Log message.
     * @param array<string, mixed> $context Optional context payload.
     * @param int|null             $jobId   Associated job identifier.
     * @return bool True when the entry was queued for database insertion.
     */
    public function critical($source, $message, array $context = [], $jobId = null) {
        return $this->log('critical', $source, $message, $context, $jobId);
    }

    /**
     * Create or upgrade the log schema when required.
     *
     * Creates or alters the logs database table and updates the schema version
     * option when schema changes are detected.
     *
     * @return void
     */
    private static function maybeUpgradeSchema() {
        if (!self::schemaNeedsUpgrade()) {
            self::$schemaReady = true;

            return;
        }

        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return;
        }

        $table = sprintf('`%s`', self::getTableName());
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %1$s (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `level` varchar(20) NOT NULL,
                `source` varchar(191) NOT NULL,
                `job_id` bigint(20) unsigned DEFAULT NULL,
                `message` text NOT NULL,
                `context` longtext DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `level_idx` (`level`),
                KEY `source_idx` (`source`),
                KEY `job_id_idx` (`job_id`),
                KEY `created_at_idx` (`created_at`)
            ) ENGINE=InnoDB %2$s;',
            $table,
            $charsetCollate
        );

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $previousError = $wpdb->last_error;
        $wpdb->last_error = '';

        try {
            dbDelta($sql);
        } catch (Throwable $exception) {
            $wpdb->last_error = $previousError;
            self::logFallback(
                sprintf('Log schema upgrade failed: %s', self::sanitizeText($exception->getMessage()))
            );

            return;
        }

        if ($wpdb->last_error !== '') {
            $sanitizedError = self::sanitizeText($wpdb->last_error);
            $wpdb->last_error = $previousError;
            self::logFallback(
                sprintf('Log schema upgrade encountered a database error: %s', $sanitizedError)
            );

            return;
        }

        $wpdb->last_error = $previousError;

        update_option(self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION);
        self::$schemaReady = true;
    }

    /**
     * Determine whether the log schema requires an upgrade.
     *
     * @return bool True when the option or table state indicates a migration is needed.
     */
    private static function schemaNeedsUpgrade() {
        $storedVersion = get_option(self::OPTION_SCHEMA_VERSION, null);

        if ($storedVersion !== self::SCHEMA_VERSION) {
            return true;
        }

        return !self::tableExists();
    }

    /**
     * Check if the log table exists.
     *
     * @return bool True when the logs table is present in the database.
     */
    private static function tableExists() {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return false;
        }

        $table = self::getTableName();

        $result = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $table
            )
        );

        return ($result === $table);
    }

    /**
     * Retrieve the fully qualified log table name.
     *
     * @return string Database table name including prefix.
     */
    public static function getTableName() {
        global $wpdb;

        $prefix = ($wpdb instanceof wpdb) ? $wpdb->prefix : 'wp_';

        return $prefix . self::TABLE_NAME;
    }

    /**
     * Persist a log entry to the database.
     *
     * Inserts the sanitized entry into the logs table and writes to error_log
     * when database access fails.
     *
     * @param string               $level   Log severity level.
     * @param string               $source  Component or subsystem identifier.
     * @param string               $message Log message.
     * @param array<string, mixed> $context Contextual payload.
     * @param int|null             $jobId   Associated job identifier.
     * @return bool True when the row was inserted successfully.
     */
    private function log($level, $source, $message, array $context, $jobId) {
        if (!($this->wpdb instanceof wpdb)) {
            return false;
        }

        $normalizedLevel = $this->normalizeLevel($level);
        $normalizedSource = $this->sanitizeSource($source);
        if ($normalizedSource === '') {
            $normalizedSource = 'general';
        }

        $sanitizedMessage = $this->sanitizeMessage($message);
        if ($sanitizedMessage === '') {
            return false;
        }

        $sanitizedContext = $this->encodeContext($context);
        $normalizedJobId = $this->sanitizeJobId($jobId);

        if (!$this->ensureSchemaReady()) {
            $this->logFallback(
                sprintf('Log table unavailable for %s message from %s.', $normalizedLevel, $normalizedSource)
            );

            return false;
        }

        $timestamp = $this->currentUtcTime();
        $data = [
            'level'      => $normalizedLevel,
            'source'     => $normalizedSource,
            'message'    => $sanitizedMessage,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
        $formats = ['%s', '%s', '%s', '%s', '%s'];

        if ($normalizedJobId !== null) {
            $data['job_id'] = $normalizedJobId;
            $formats[] = '%d';
        }

        if ($sanitizedContext !== '') {
            $data['context'] = $sanitizedContext;
            $formats[] = '%s';
        }

        $result = $this->wpdb->insert(self::getTableName(), $data, $formats); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

        if ($result === false && defined('WP_DEBUG') && WP_DEBUG) {
            $this->logFallback(
                sprintf('Failed to persist log entry for %s:%s.', $normalizedLevel, $normalizedSource),
                [
                    'message' => $sanitizedMessage,
                    'context' => $sanitizedContext,
                ]
            );
        }

        return ($result !== false);
    }

    /**
     * Ensure the log table is available for writes.
     *
     * May trigger schema creation and option updates when the table is absent.
     *
     * @return bool True when the schema is ready for database inserts.
     */
    private function ensureSchemaReady() {
        if (self::$schemaReady) {
            return true;
        }

        self::maybeUpgradeSchema();

        if (!self::$schemaReady) {
            self::$schemaReady = self::tableExists();
        }

        return self::$schemaReady;
    }

    /**
     * Normalise and truncate a log message.
     *
     * @param string $message Log message.
     * @return string Sanitized text trimmed to the configured length.
     */
    private function sanitizeMessage($message) {
        if (!is_string($message)) {
            $message = (string) $message;
        }

        $sanitized = function_exists('sanitize_textarea_field')
            ? sanitize_textarea_field($message)
            : self::sanitizeText($message);

        return $this->truncateString($sanitized, self::MAX_MESSAGE_LENGTH);
    }

    /**
     * Encode the context payload as a sanitised JSON string.
     *
     * @param array<string, mixed> $context Context payload.
     * @return string JSON string ready for storage or an empty string when no context is available.
     */
    private function encodeContext(array $context) {
        if (empty($context)) {
            return '';
        }

        $sanitized = $this->sanitizeContextArray($context);
        if (empty($sanitized)) {
            return '';
        }

        $encoded = wp_json_encode($sanitized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === '') {
            return '';
        }

        return $this->truncateString($encoded, self::MAX_CONTEXT_LENGTH);
    }

    /**
     * Sanitize each context entry recursively.
     *
     * @param array<string, mixed> $context Context payload.
     * @return array<string, mixed> Sanitized context array.
     */
    private function sanitizeContextArray(array $context) {
        $sanitized = [];

        foreach ($context as $key => $value) {
            $normalizedKey = $this->sanitizeContextKey($key);
            if ($normalizedKey === '') {
                continue;
            }

            $sanitized[$normalizedKey] = $this->sanitizeContextValue($value);
        }

        return $sanitized;
    }

    /**
     * Normalise a context key.
     *
     * @param mixed $key Context array key.
     * @return string Normalised array key suitable for JSON encoding.
     */
    private function sanitizeContextKey($key) {
        if (is_int($key)) {
            return (string) $key;
        }

        if (!is_string($key)) {
            return '';
        }

        $normalized = strtolower($key);
        $normalized = preg_replace('/[^a-z0-9._-]/', '_', $normalized);
        if (!is_string($normalized)) {
            $normalized = '';
        }

        return $this->truncateString($normalized, 191);
    }

    /**
     * Normalise a context value.
     *
     * @param mixed $value Context value.
     * @return mixed Sanitized value ready for JSON encoding.
     */
    private function sanitizeContextValue($value) {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) || is_float($value)) {
            return $value + 0;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_array($value)) {
            return $this->sanitizeContextArray($value);
        }

        if (is_object($value)) {
            return $this->sanitizeContextArray((array) $value);
        }

        $stringValue = function_exists('sanitize_textarea_field')
            ? sanitize_textarea_field((string) $value)
            : self::sanitizeText((string) $value);

        return $this->truncateString($stringValue, self::MAX_CONTEXT_VALUE_LENGTH);
    }

    /**
     * Truncate a string to the configured limit.
     *
     * @param string $value Raw string value.
     * @param int    $limit Maximum length.
     * @return string Truncated string with multibyte support when available.
     */
    private function truncateString($value, $limit) {
        if (!is_string($value)) {
            $value = (string) $value;
        }

        if ($limit <= 0) {
            return '';
        }

        if (function_exists('mb_strlen')) {
            if (mb_strlen($value, 'UTF-8') <= $limit) {
                return $value;
            }

            return mb_substr($value, 0, $limit, 'UTF-8');
        }

        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit);
    }

    /**
     * Normalise the severity level.
     *
     * @param string $level Requested level.
     * @return string Allowed severity value with a fallback of "info".
     */
    private function normalizeLevel($level) {
        $level = strtolower((string) $level);
        if (!in_array($level, self::ALLOWED_LEVELS, true)) {
            return 'info';
        }

        return $level;
    }

    /**
     * Sanitize the source identifier.
     *
     * @param string $source Raw source identifier.
     * @return string Normalized source string constrained to the column length.
     */
    private function sanitizeSource($source) {
        if (!is_string($source)) {
            $source = (string) $source;
        }

        $sanitized = strtolower($source);
        $sanitized = preg_replace('/[^a-z0-9._-]/', '_', $sanitized);
        if (!is_string($sanitized)) {
            $sanitized = '';
        }

        return $this->truncateString($sanitized, 191);
    }

    /**
     * Sanitize the job identifier.
     *
     * @param int|string|null $jobId Job identifier.
     * @return int|null Positive integer ID or null when invalid.
     */
    private function sanitizeJobId($jobId) {
        if ($jobId === null || $jobId === '') {
            return null;
        }

        if (function_exists('absint')) {
            $normalized = absint($jobId);
        } else {
            $normalized = (int) max(0, (int) $jobId);
        }

        return ($normalized > 0) ? $normalized : null;
    }

    /**
     * Retrieve the current UTC timestamp in MySQL datetime format.
     *
     * @return string UTC datetime string.
     */
    private function currentUtcTime() {
        if (function_exists('current_time')) {
            return current_time('mysql', true);
        }

        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Write a fallback message to error_log when database logging fails.
     *
     * @param string                     $message Summary message.
     * @param array<string, string>|null $context Optional context payload.
     * @return void Writes directly to the PHP error log.
     */
    private function logFallback($message, ?array $context = null) {
        $payload = [
            'source'  => 'exmoment-author.log-service',
            'message' => $message,
        ];

        if (!empty($context)) {
            $payload['context'] = $context;
        }

        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            $json = '[ExMoment Author] ' . $message;
        }

        error_log($json); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }

    /**
     * Retrieve the allowed log levels.
     *
     * @return array<int, string>
     */
    public function getAllowedLevels() {
        return self::ALLOWED_LEVELS;
    }

    /**
     * Retrieve paginated log entries with optional filters applied.
     *
     * Executes database read queries for the log listing and count using the
     * provided filters.
     *
     * @param array<string, mixed> $args {
     *     @type array<string, mixed> $filters  Optional filter constraints.
     *     @type int                  $page     1-indexed page number.
     *     @type int                  $per_page Number of rows per page.
     * }
     *
     * @return array<string, mixed> Paginated result set including entries and totals.
     */
    public function listEntries(array $args = []) {
        if (!($this->wpdb instanceof wpdb)) {
            return [
                'entries'     => [],
                'page'        => 1,
                'per_page'    => self::DEFAULT_PER_PAGE,
                'total'       => 0,
                'total_pages' => 0,
            ];
        }

        $page = (int) ($args['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }

        $perPage = (int) ($args['per_page'] ?? self::DEFAULT_PER_PAGE);
        if ($perPage < 1) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        if ($perPage > self::MAX_PER_PAGE) {
            $perPage = self::MAX_PER_PAGE;
        }

        $filters = [];
        if (!empty($args['filters']) && is_array($args['filters'])) {
            $filters = $args['filters'];
        }

        $conditions = [];
        $values = [];

        $level = strtolower((string) ($filters['level'] ?? ''));
        if (in_array($level, self::ALLOWED_LEVELS, true)) {
            $conditions[] = 'level = %s';
            $values[] = $level;
        }

        if (!empty($filters['source'])) {
            $source = $this->sanitizeSource($filters['source']);
            if ($source !== '') {
                $conditions[] = 'source = %s';
                $values[] = $source;
            }
        }

        if (array_key_exists('job_id', $filters)) {
            $jobId = $this->sanitizeJobId($filters['job_id']);
            if ($jobId !== null) {
                $conditions[] = 'job_id = %d';
                $values[] = $jobId;
            }
        }

        if (!empty($filters['date_from'])) {
            $dateFrom = $this->sanitizeDateFilter($filters['date_from']);
            if ($dateFrom !== '') {
                $conditions[] = 'created_at >= %s';
                $values[] = $dateFrom;
            }
        }

        if (!empty($filters['date_to'])) {
            $dateTo = $this->sanitizeDateFilter($filters['date_to']);
            if ($dateTo !== '') {
                $conditions[] = 'created_at <= %s';
                $values[] = $dateTo;
            }
        }

        if (!empty($filters['search'])) {
            $search = $this->sanitizeSearchTerm($filters['search']);
            if ($search !== '') {
                $conditions[] = 'message LIKE %s';
                $values[] = '%' . $this->wpdb->esc_like($search) . '%';
            }
        }

        $whereSql = '';
        if (!empty($conditions)) {
            $whereSql = ' WHERE ' . implode(' AND ', $conditions);
        }

        $offset = ($page - 1) * $perPage;
        $table = self::getTableName();
        $orderClause = ' ORDER BY created_at DESC, id DESC';

        $selectSql = 'SELECT id, level, source, job_id, message, created_at, updated_at FROM ' . $table . $whereSql . $orderClause . ' LIMIT %d OFFSET %d';
        $queryValues = array_merge($values, [$perPage, $offset]);
        $preparedSelect = $this->wpdb->prepare($selectSql, $queryValues);
        $rows = $this->wpdb->get_results($preparedSelect, ARRAY_A);

        if (!is_array($rows)) {
            $rows = [];
        }

        $entries = [];
        foreach ($rows as $row) {
            $jobId = isset($row['job_id']) ? (int) $row['job_id'] : 0;
            $entries[] = [
                'id'         => isset($row['id']) ? (int) $row['id'] : 0,
                'level'      => isset($row['level']) ? (string) $row['level'] : '',
                'source'     => isset($row['source']) ? (string) $row['source'] : '',
                'job_id'     => ($jobId > 0) ? $jobId : null,
                'message'    => isset($row['message']) ? (string) $row['message'] : '',
                'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : '',
                'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : '',
            ];
        }

        $countSql = 'SELECT COUNT(*) FROM ' . $table . $whereSql;
        $countQuery = !empty($values)
            ? $this->wpdb->prepare($countSql, $values)
            : $countSql;

        $total = (int) $this->wpdb->get_var($countQuery);
        $totalPages = ($perPage > 0) ? (int) ceil($total / $perPage) : 0;

        return [
            'entries'     => $entries,
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Retrieve a single log entry by identifier.
     *
     * Reads a single row from the logs database table.
     *
     * @param int|string $id Log identifier.
     * @return array<string, mixed>|null Database row or null when not found.
     */
    public function getEntry($id) {
        if (!($this->wpdb instanceof wpdb)) {
            return null;
        }

        $logId = $this->sanitizeLogId($id);
        if ($logId <= 0) {
            return null;
        }

        $table = self::getTableName();

        $selectSql = 'SELECT id, level, source, job_id, message, context, created_at, updated_at FROM ' . $table . ' WHERE id = %d LIMIT 1';
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare($selectSql, $logId),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }

        $jobId = isset($row['job_id']) ? (int) $row['job_id'] : 0;

        return [
            'id'         => isset($row['id']) ? (int) $row['id'] : 0,
            'level'      => isset($row['level']) ? (string) $row['level'] : '',
            'source'     => isset($row['source']) ? (string) $row['source'] : '',
            'job_id'     => ($jobId > 0) ? $jobId : null,
            'message'    => isset($row['message']) ? (string) $row['message'] : '',
            'context'    => isset($row['context']) ? (string) $row['context'] : '',
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : '',
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : '',
        ];
    }

    /**
     * Sanitize a search term for use in LIKE clauses.
     *
     * @param string $term Raw search term.
     * @return string Escaped term trimmed to the maximum search length.
     */
    private function sanitizeSearchTerm($term) {
        if (!is_string($term)) {
            $term = (string) $term;
        }

        $sanitized = function_exists('sanitize_text_field')
            ? sanitize_text_field($term)
            : self::sanitizeText($term);

        return $this->truncateString($sanitized, 128);
    }

    /**
     * Validate a date filter string.
     *
     * @param string $value Potential datetime string.
     * @return string Normalized datetime string or empty string when invalid.
     */
    private function sanitizeDateFilter($value) {
        if (!is_string($value) || $value === '') {
            return '';
        }

        $value = trim($value);

        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        if (!($date instanceof \DateTimeImmutable)) {
            return '';
        }

        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Normalise a log identifier.
     *
     * @param int|string $id Raw identifier.
     * @return int Positive integer identifier suitable for queries.
     */
    private function sanitizeLogId($id) {
        if (function_exists('absint')) {
            $value = absint($id);
        } else {
            $value = (int) max(0, (int) $id);
        }

        return ($value > 0) ? $value : 0;
    }

    /**
     * Sanitize an arbitrary text fragment.
     *
     * @param string $text Raw text input.
     * @return string Plain text string stripped of HTML tags and whitespace.
     */
    private static function sanitizeText($text) {
        if (!is_string($text)) {
            $text = (string) $text;
        }

        $text = wp_strip_all_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        if (!is_string($text)) {
            $text = '';
        }

        return trim($text);
    }
}
