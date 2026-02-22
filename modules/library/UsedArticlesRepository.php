<?php

namespace ExMomentAuthor\Modules\Library;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use WP_Error;
use wpdb;

/**
 * Persistently tracks which library articles have been used in mixtures.
 */
class UsedArticlesRepository {

    private const TABLE_SUFFIX = 'exmoau_used_articles';
    private const MAX_PATH_LENGTH = 2048;
    private const MAX_DIRECTORY_LENGTH = 512;

    /**
     * Cached flag indicating the registry table has been verified this request.
     *
     * @var bool
     */
    private static $tableReady = false;

    /**
     * WordPress database driver instance.
     *
     * @var wpdb|null
     */
    private $wpdb;

    /**
     * Rows affected by the last write operation.
     *
     * @var int
     */
    private $lastAffectedRows = 0;

    /**
     * Flag indicating whether the last write was idempotent.
     *
     * @var bool
     */
    private $lastWriteIdempotent = false;

    /**
     * Initialise the repository.
     *
     * @since 1.1.0
     *
     * @param wpdb|null $wpdb Optional database driver (defaults to the global $wpdb instance). When
     *                        omitted or invalid, the instance falls back to `null`.
     */
    public function __construct($wpdb = null) {
        if ($wpdb instanceof wpdb) {
            $this->wpdb = $wpdb;

            return;
        }

        global $wpdb;

        $this->wpdb = ($wpdb instanceof wpdb) ? $wpdb : null;
    }

    /**
     * Determine whether a path has already been registered as used.
     *
     * The supplied path is normalised via {@see resolveIdentity()} which trims, resolves real paths,
     * and rejects non-absolute or over-length values before the lookup occurs.
     *
     * @since 1.1.0
     *
     * @param string $fullPath Absolute filesystem path.
     * @return bool True when the normalised path hash exists in the registry, or false when the path
     *              is invalid or the hash cannot be located.
     */
    public function isUsed($fullPath) {
        $identity = $this->resolveIdentity($fullPath);

        if ($identity['hash'] === '') {
            return false;
        }

        return $this->hashExists($identity['hash']);
    }

    /**
     * Record that a file path has been used in a job mixture.
     *
     * The path is normalised and validated via {@see resolveIdentity()}, the optional directory
     * value is sanitised with `sanitize_text_field()` (or trimmed when unavailable), and the job ID is
     * coerced to a non-negative integer.
     *
     * @since 1.1.0
     *
     * @param string                                 $fullPath Absolute filesystem path.
     * @param array{directory?: string|array, job_id?: mixed} $meta Optional metadata (directory, job_id).
     * @return bool|WP_Error True when the insert/update succeeds. Returns a WP_Error when the path is
     *                       invalid, the database connection is missing, the table could not be
     *                       verified, or the write query fails.
     */
    public function markUsed($fullPath, array $meta = []) {
        $this->lastAffectedRows = 0;
        $this->lastWriteIdempotent = false;

        $identity = $this->resolveIdentity($fullPath);
        if ($identity['path'] === '' || $identity['hash'] === '') {
            return new WP_Error('exmoau_used_articles_invalid_path', 'Unable to record an empty or invalid path.');
        }
        if (!($this->wpdb instanceof wpdb)) {
            return new WP_Error('exmoau_used_articles_database_unavailable', 'The database connection is unavailable.');
        }

        if (!$this->ensureTable()) {
            return new WP_Error('exmoau_used_articles_table_missing', 'The used articles registry table could not be verified.');
        }

        $directory = $this->extractDirectoryMeta($meta);
        $jobId = $this->extractJobIdMeta($meta);
        $table = self::getTableName($this->wpdb);
        $usedAt = $this->getCurrentUtcTimestamp();

        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "
                    INSERT INTO {$table} (path_hash, full_path, directory, job_id, used_at)
                    VALUES (%s, %s, %s, %d, %s)
                    ON DUPLICATE KEY UPDATE
                        full_path = VALUES(full_path),
                        directory = VALUES(directory),
                        job_id = VALUES(job_id),
                        used_at = VALUES(used_at)
                ",
                $identity['hash'],
                $identity['path'],
                $directory,
                $jobId,
                $usedAt
            )
        );

        if (false === $result) {
            return new WP_Error('exmoau_used_articles_write_failed', 'Failed to write to the used articles registry.');
        }

        $this->lastAffectedRows = (int) $result;
        $this->lastWriteIdempotent = ($this->lastAffectedRows > 1 || $this->lastAffectedRows === 0);

        return true;
    }

    /**
     * Retrieve the digits-only hash for a filesystem path.
     *
     * The path is normalised using the same sanitisation rules as {@see resolveIdentity()} to ensure
     * consistent hashing.
     *
     * @since 1.1.0
     *
     * @param string $fullPath Absolute filesystem path.
     * @return string Unsigned CRC32 hash string for the normalised path, or an empty string when the
     *                path cannot be normalised.
     */
    public function getPathHash($fullPath) {
        $identity = $this->resolveIdentity($fullPath);

        return $identity['hash'];
    }

    /**
     * Determine whether a precomputed path hash already exists in the registry.
     *
     * The supplied hash is sanitised via {@see normalizeHashKey()} to ensure it is a digits-only
     * representation prior to querying the database.
     *
     * @since 1.1.0
     *
     * @param string|int $hash Unsigned CRC32 hash string or integer.
     * @return bool True when the sanitised hash is found, false otherwise.
     */
    public function isHashUsed($hash) {
        $normalized = $this->normalizeHashKey($hash);

        if ('' === $normalized) {
            return false;
        }

        return $this->hashExists($normalized);
    }

    /**
     * Resolve the normalized path and deterministic hash for a filesystem path.
     *
     * Sanitisation trims whitespace, applies `realpath()` when available, normalises directory
     * separators, ensures the value is absolute, and enforces path length limits before hashing.
     *
     * @since 1.1.0
     *
     * @param string $fullPath Absolute filesystem path.
     * @return array{path: string, hash: string} Normalised path and hash pair, or empty strings when
     *                                           validation fails.
     */
    public function resolveIdentity($fullPath) {
        $normalized = $this->normalizeFullPath($fullPath);
        if ('' === $normalized) {
            return [
                'path' => '',
                'hash' => '',
            ];
        }

        $hash = $this->generateHash($normalized);
        if ('' === $hash) {
            return [
                'path' => '',
                'hash' => '',
            ];
        }

        return [
            'path' => $normalized,
            'hash' => $hash,
        ];
    }

    /**
     * Retrieve the fully qualified registry table name for diagnostics.
     *
     * @since 1.1.0
     *
     * @return string Table name including the WordPress prefix, or an empty string when no database
     *                connection is available.
     */
    public function getRegistryTableName() {
        if (!($this->wpdb instanceof wpdb)) {
            return '';
        }

        return self::getTableName($this->wpdb);
    }

    /**
     * Create the registry table when it is missing.
     *
     * The schema defines an auto-incrementing `id` primary key, a unique `path_hash`, the recorded
     * `full_path`, optional `directory` (indexed by prefix) and `job_id` columns, and a `used_at`
     * timestamp defaulting to the current time. Successful creation primes the static cache flag used
     * by {@see ensureRegistryTable()}.
     *
     * @since 1.1.0
     *
     * @param wpdb|null $database Optional database driver.
     * @return bool True when a valid database connection is available (table creation is attempted and
     *              the ready flag is set), or false when no database driver is present.
     */
    public static function createTable($database = null) {
        if (!($database instanceof wpdb)) {
            global $wpdb;

            $database = ($wpdb instanceof wpdb) ? $wpdb : null;
        }

        if (!($database instanceof wpdb)) {
            return false;
        }

        $table = self::getTableName($database);
        $charset = $database->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "
CREATE TABLE {$table} (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    path_hash BIGINT(20) UNSIGNED NOT NULL,
    full_path VARCHAR(2048) NOT NULL,
    directory VARCHAR(512) DEFAULT NULL,
    job_id BIGINT(20) UNSIGNED DEFAULT NULL,
    used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY  (id),
    UNIQUE KEY path_hash (path_hash),
    KEY directory_idx (directory(191)),
    KEY job_id_idx (job_id)
) {$charset};
";

        dbDelta($sql); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

        self::$tableReady = true;

        return true;
    }

    /**
     * Ensure the registry table exists for the current installation.
     *
     * This method uses the static {@see self::$tableReady} flag to avoid redundant lookups once the table
     * is verified during the current request, creating the table via {@see createTable()} when
     * required.
     *
     * @since 1.1.0
     *
     * @return bool True when the table already exists or creation succeeds, false when the database is
     *              unavailable or the setup routine fails.
     */
    public function ensureRegistryTable() {
        if (!($this->wpdb instanceof wpdb)) {
            return false;
        }

        return $this->ensureTable();
    }

    /**
     * Ensure the registry table exists for the current request, using the cached readiness flag to
     * avoid redundant lookups.
     *
     * @return bool True when the table is ready for queries, false when no database is available or
     *              verification/creation fails.
     */
    private function ensureTable() {
        if (self::$tableReady) {
            return true;
        }

        if (!($this->wpdb instanceof wpdb)) {
            return false;
        }

        $table = self::getTableName($this->wpdb);

        $exists = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $this->wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $table
            )
        );

        if ($exists === $table) {
            self::$tableReady = true;

            return true;
        }

        return self::createTable($this->wpdb);
    }

    /**
     * Retrieve the fully qualified table name.
     *
     * @param wpdb $wpdb Database driver instance.
     * @return string Fully qualified table name including the WordPress prefix.
     */
    private static function getTableName(wpdb $wpdb) {
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Normalise and validate an absolute filesystem path.
     *
     * Trims whitespace, resolves real paths when available, normalises directory separators, ensures
     * the path is absolute, and enforces maximum length limits.
     *
     * @param string $path Raw filesystem path.
     * @return string Normalised absolute path, or an empty string when validation fails.
     */
    private function normalizeFullPath($path) {
        if (!is_string($path)) {
            return '';
        }

        $trimmed = trim($path);
        if ('' === $trimmed) {
            return '';
        }

        $resolved = realpath($trimmed);
        if (false !== $resolved) {
            $trimmed = $resolved;
        }

        if (function_exists('wp_normalize_path')) {
            $normalized = wp_normalize_path($trimmed);
        } else {
            $normalized = str_replace('\\', '/', $trimmed);
            $normalized = preg_replace('#/+#', '/', $normalized);
        }

        $normalized = rtrim((string) $normalized, '/');
        if ('' === $normalized) {
            return '';
        }

        if (!$this->isAbsolutePath($normalized)) {
            return '';
        }

        if (strlen($normalized) > self::MAX_PATH_LENGTH) {
            return '';
        }

        return $normalized;
    }

    /**
     * Determine if the provided path is absolute on the current platform.
     *
     * @param string $path Normalised filesystem path.
     * @return bool True when the path is absolute, false otherwise.
     */
    private function isAbsolutePath($path) {
        if ('' === $path) {
            return false;
        }

        if ('/' === $path[0]) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:\//', $path);
    }

    /**
     * Generate the unsigned CRC32 hash for a normalised path.
     *
     * @param string $normalizedPath Normalised filesystem path.
     * @return string Unsigned CRC32 hash string, or an empty string when the input is blank after
     *                normalisation.
     */
    private function generateHash($normalizedPath) {
        $normalizedPath = $this->normalizeHashInput($normalizedPath);

        if ('' === $normalizedPath) {
            return '';
        }

        return sprintf('%u', crc32($normalizedPath));
    }

    /**
     * Determine whether the provided hash exists in the registry table.
     *
     * @param string $hash Unsigned CRC32 hash string.
     * @return bool True when the hash exists in the registry, false when the database is unavailable,
     *              the table cannot be verified, or the hash is missing.
     */
    private function hashExists($hash) {
        if (!($this->wpdb instanceof wpdb)) {
            return false;
        }

        if (!$this->ensureTable()) {
            return false;
        }

        $table = self::getTableName($this->wpdb);

        $sql = $this->wpdb->prepare(
            "SELECT id FROM {$table} WHERE path_hash = %s LIMIT 1",
            $hash
        );

        $result = $this->wpdb->get_var($sql); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        return !empty($result);
    }

    /**
     * Retrieve metrics about the most recent write.
     *
     * @since 1.1.0
     *
     * @return array{affected_rows: int, idempotent: bool} Number of rows affected and whether the
     *                                                     operation was idempotent.
     */
    public function getLastWriteMetrics() {
        return [
            'affected_rows' => $this->lastAffectedRows,
            'idempotent'    => $this->lastWriteIdempotent,
        ];
    }

    /**
     * Normalise the path for hashing purposes.
     *
     * @param string $normalizedPath Validated filesystem path.
     * @return string Lowercased, trimmed path for hashing, or an empty string when blank.
     */
    private function normalizeHashInput($normalizedPath) {
        $trimmed = trim($normalizedPath);
        if ('' === $trimmed) {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($trimmed, 'UTF-8');
        }

        return strtolower($trimmed);
    }

    /**
     * Normalise a hash value prior to lookups.
     *
     * @param mixed $hash Candidate hash value.
     * @return string Digits-only hash string, or an empty string when normalisation fails.
     */
    private function normalizeHashKey($hash) {
        if (is_int($hash) || is_float($hash)) {
            $hash = (string) (int) $hash;
        } elseif (is_string($hash)) {
            $hash = trim($hash);
        } else {
            return '';
        }

        if ($hash === '') {
            return '';
        }

        if (!ctype_digit($hash)) {
            return '';
        }

        return $hash;
    }

    /**
     * Extract and sanitise the optional directory metadata.
     *
     * @param array<string, mixed> $meta Metadata payload.
     * @return string Sanitised directory value truncated to the maximum length, or an empty string when
     *                unavailable or invalid.
     */
    private function extractDirectoryMeta(array $meta) {
        if (!isset($meta['directory'])) {
            return '';
        }

        $directory = $meta['directory'];
        if (is_array($directory)) {
            $directory = reset($directory);
        }

        if (!is_string($directory)) {
            return '';
        }

        $sanitized = function_exists('sanitize_text_field')
            ? sanitize_text_field($directory)
            : trim((string) $directory);

        $sanitized = substr($sanitized, 0, self::MAX_DIRECTORY_LENGTH);

        return $sanitized;
    }

    /**
     * Extract and normalise the optional job identifier metadata.
     *
     * @param array<string, mixed> $meta Metadata payload.
     * @return int Non-negative job identifier extracted from the metadata payload.
     */
    private function extractJobIdMeta(array $meta) {
        if (!isset($meta['job_id'])) {
            return 0;
        }

        $value = $meta['job_id'];
        if (is_numeric($value)) {
            $value = (int) $value;
        } else {
            $value = 0;
        }

        if ($value < 0) {
            $value = 0;
        }

        return $value;
    }

    /**
     * Retrieve the current timestamp in UTC for database writes.
     *
     * @return string UTC timestamp formatted for MySQL insertion.
     */
    private function getCurrentUtcTimestamp() {
        if (function_exists('current_time')) {
            $timestamp = current_time('mysql', true);

            if (is_string($timestamp) && '' !== $timestamp) {
                return $timestamp;
            }
        }

        return gmdate('Y-m-d H:i:s');
    }
}

