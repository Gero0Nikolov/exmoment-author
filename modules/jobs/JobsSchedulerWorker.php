<?php

namespace ExMomentAuthor\Modules\Jobs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use DateTimeImmutable;
use DateTimeZone;
use ExMomentAuthor\Core\ExMomentAuthorCoreSystem;
use ExMomentAuthor\Modules\Log\LogService;
use wpdb;
use WP_Post;
use ExMomentAuthor\Modules\Jobs\JobsTimeHelper;

/**
 * WordPress cron worker that executes scheduled ExMoment Author jobs.
 */
class JobsSchedulerWorker {

    public const HOOK = 'exmoau_minutely_worker';

    private const SCHEDULE_KEY = 'exmoau_minutely';
    private const LOOKAHEAD_SECONDS = 60;
    private const DUE_GRACE_SECONDS = 300;
    private const LOCK_TTL = 120;
    private const MAX_JOBS_PER_TICK = 10;
    private const LOCK_PREFIX = 'exmoau_job_claim_';
    private const LAST_RUN_TRANSIENT = 'exmoau_minutely_last_run';
    private const RUN_THROTTLE_SECONDS = 45;
    private const SPAWN_THROTTLE_TRANSIENT = 'exmoau_minutely_spawn';
    private const SPAWN_THROTTLE_SECONDS = 90;
    private const LOOPBACK_THROTTLE_TRANSIENT = 'exmoau_minutely_loopback';
    private const LOOPBACK_THROTTLE_SECONDS = 300;
    public const DISABLED_NOTICE_TRANSIENT = 'exmoau_cron_disabled_notice';
    private const DEBUG_TYPE = 'ExMomentAuthorSchedulerDebug';
    private const ERROR_TYPE = 'ExMomentAuthorScheduler';

    /**
     * Cached jobs execution controller instance.
     *
     * @var JobsExecutionController|null
     */
    private $executionController = null;

    /**
     * Cached jobs scheduling controller instance.
     *
     * @var JobsSchedulingController|null
     */
    private $schedulingController = null;

    /**
     * Hook WordPress actions and filters for the scheduler worker.
     *
     * Context: Request bootstrap when the plugin loads to ensure cron
     * registration and monitoring hooks are wired during normal requests.
     * This helper does not throw; update the contract if future refactors
     * introduce exceptions.
     *
     * @since 1.1.0
     *
     * @param array<string, mixed> $config Optional configuration payload (unused).
     *
     * @return void
     */
    public function __construct(array $config = []) {
        unset($config);

        $this->register();
    }

    /**
     * Register the required cron schedule, hooks, and monitors.
     *
     * Context: Request bootstrap during admin or frontend loads. Hooks the
     * worker into cron registration, scheduling enforcement, and health
     * monitoring without triggering cron execution directly.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function register() {
        add_filter('cron_schedules', [__CLASS__, 'registerCronSchedule']);
        add_action(self::HOOK, [$this, 'runScheduledJobs']);
        add_action('init', [__CLASS__, 'ensureEventScheduled']);
        add_action('admin_init', [__CLASS__, 'ensureEventScheduled']);
        add_action('wp_loaded', [__CLASS__, 'ensureEventScheduled']);
        add_action('init', [$this, 'monitorCronHealth'], 12);
    }

    /**
     * Register the one-minute cron schedule.
     *
     * Context: Runs within filter callbacks during request bootstrap while
     * WP-Cron schedules are being prepared. Does not interact with transients
     * or logging.
     *
     * @since 1.1.0
     *
     * @param array<string, array<string, mixed>> $schedules Existing schedules keyed by interval name.
     *
     * @return array<string, array<string, mixed>> Updated schedules collection including the ExMoment Author entry.
     */
    public static function registerCronSchedule($schedules) {
        if (!is_array($schedules)) {
            $schedules = [];
        }

        $schedules[self::SCHEDULE_KEY] = [
            'interval' => 60,
            'display' => esc_html__('Every minute (ExMoment Author)', 'exmoment-author'),
        ];

        return $schedules;
    }

    /**
     * Ensure the cron event is scheduled when the plugin loads.
     *
     * Context: Request bootstrap (front-end and admin). Touches the
     * `DISABLED_NOTICE_TRANSIENT` transient to clear disabled notices and logs
     * scheduler failures using structured debug/error channels. No exceptions
     * are thrown; revise if that changes.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public static function ensureEventScheduled() {
        if (self::isCronDisabled()) {
            self::flagCronDisabled();

            return;
        }

        $nextScheduled = wp_next_scheduled(self::HOOK);
        if ($nextScheduled !== false) {
            self::clearCronDisabledNotice();

            return;
        }

        $timestamp = JobsTimeHelper::asWpCronNow() + self::LOOKAHEAD_SECONDS;
        $scheduled = wp_schedule_event($timestamp, self::SCHEDULE_KEY, self::HOOK);

        if ($scheduled === false) {
            self::logStaticError('scheduler_schedule_failed', [
                'timestamp' => $timestamp,
            ]);

            return;
        }

        self::clearCronDisabledNotice();
    }

    /**
     * Schedule the worker on plugin activation.
     *
     * Context: Plugin activation hook executed from a request context. Binds
     * the cron schedule filter temporarily and relies on the same scheduling
     * flow as normal bootstrap. No transients are touched here and no
     * exceptions are thrown.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public static function activate() {
        add_filter('cron_schedules', [__CLASS__, 'registerCronSchedule']);
        self::ensureEventScheduled();
        remove_filter('cron_schedules', [__CLASS__, 'registerCronSchedule']);
    }

    /**
     * Unschedule the worker on plugin deactivation.
     *
     * Context: Plugin deactivation hook executed via request lifecycle. Clears
     * all scheduler-related transients including run throttles, loopback
     * throttles, and admin notice flags without producing debug output. This
     * helper does not throw.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public static function deactivate() {
        wp_clear_scheduled_hook(self::HOOK);
        self::deleteTransient(self::LAST_RUN_TRANSIENT);
        self::deleteTransient(self::SPAWN_THROTTLE_TRANSIENT);
        self::deleteTransient(self::LOOPBACK_THROTTLE_TRANSIENT);
        self::deleteTransient(self::DISABLED_NOTICE_TRANSIENT);
    }

    /**
     * Determine whether WP-Cron is disabled for the site.
     *
     * Context: Safe for request and cron execution. Reads global constants
     * only and never throws.
     *
     * @since 1.1.0
     *
     * @return bool
     */
    public static function isCronDisabled() {
        return defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    }

    /**
     * Flag WP-Cron as disabled and surface an admin notice.
     *
     * Context: Invoked during request and cron contexts whenever WP-Cron is
     * detected as disabled. Persists the `DISABLED_NOTICE_TRANSIENT` flag and
     * emits a structured error log without throwing.
     *
     * @since 1.1.0
     *
     * @return void
     */
    private static function flagCronDisabled() {
        self::setTransient(self::DISABLED_NOTICE_TRANSIENT, 1, 600);

        self::logStaticError('scheduler_wp_cron_disabled', [
            'timestamp' => JobsTimeHelper::getCurrentUtcTimestamp(),
        ]);
    }

    /**
     * Clear the disabled WP-Cron notice when a run succeeds.
     *
     * Context: Safe for request and cron execution. Deletes the
     * `DISABLED_NOTICE_TRANSIENT` marker and does not log or throw.
     *
     * @since 1.1.0
     *
     * @return void
     */
    private static function clearCronDisabledNotice() {
        self::deleteTransient(self::DISABLED_NOTICE_TRANSIENT);
    }

    /**
     * Check whether the disabled cron notice flag is active.
     *
     * Context: Request and cron safe. Reads the namespaced
     * `DISABLED_NOTICE_TRANSIENT` transient without mutating it and does not
     * throw.
     *
     * @since 1.1.0
     *
     * @return bool
     */
    public static function isCronDisabledNoticeActive() {
        return (bool) self::getTransient(self::DISABLED_NOTICE_TRANSIENT);
    }

    /**
     * Monitor cron readiness and attempt to spawn when due.
     *
     * Context: Runs during the request bootstrap (late `init`). May spawn
     * loopback requests to `wp-cron.php` when the native spawner fails and
     * leverages both `SPAWN_THROTTLE_TRANSIENT` and
     * `LOOPBACK_THROTTLE_TRANSIENT` to avoid repeated loopback attempts. Emits
     * debug logs for spawn decisions and does not throw.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function monitorCronHealth() {
        if (defined('WP_CLI') && WP_CLI) {
            return;
        }

        if (self::isCronDisabled()) {
            self::flagCronDisabled();

            return;
        }

        self::clearCronDisabledNotice();

        $nextScheduled = wp_next_scheduled(self::HOOK);
        if ($nextScheduled === false) {
            return;
        }

        $now = JobsTimeHelper::getCurrentUtcTimestamp();
        if ($nextScheduled > ($now + self::LOOKAHEAD_SECONDS)) {
            return;
        }

        if (wp_doing_cron()) {
            return;
        }

        $this->maybeSpawnCron($now);
    }

    /**
     * Attempt to spawn WP-Cron using the native spawner.
     *
     * Context: Called from cron health monitoring during request bootstrap to
     * kick off cron processing without blocking the request. Respects spawn
     * throttling to avoid repeated attempts when the spawner or loopback fail.
     *
     * @param int $now Current UTC timestamp.
     *
     * @return void
     */
    private function maybeSpawnCron($now) {
        if (self::getTransient(self::SPAWN_THROTTLE_TRANSIENT)) {
            return;
        }

        self::setTransient(self::SPAWN_THROTTLE_TRANSIENT, $now, self::SPAWN_THROTTLE_SECONDS);

        if (!function_exists('spawn_cron')) {
            $this->attemptLoopback($now);

            return;
        }

        $result = spawn_cron($now);
        if ($result instanceof \WP_Error) {
            $this->logError('scheduler_spawn_error', [
                'message' => $result->get_error_message(),
            ]);

            $this->attemptLoopback($now);

            return;
        }

        if ($result === false) {
            $this->logDebug('scheduler_spawn_fallback', [
                'now' => $now,
            ]);

            $this->attemptLoopback($now);
        }
    }

    /**
     * Trigger a loopback request to wp-cron.php when native spawning fails.
     *
     * Context: Request bootstrap fallback invoked after native spawn attempts
     * fail. Honors the loopback throttle transient and performs a non-blocking
     * request to the cron endpoint.
     *
     * @param int $now Current UTC timestamp.
     *
     * @return void
     */
    private function attemptLoopback($now) {
        if (self::getTransient(self::LOOPBACK_THROTTLE_TRANSIENT)) {
            return;
        }

        self::setTransient(self::LOOPBACK_THROTTLE_TRANSIENT, $now, self::LOOPBACK_THROTTLE_SECONDS);

        $cronUrl = site_url('wp-cron.php');
        if (!is_string($cronUrl) || $cronUrl === '') {
            return;
        }

        $doing = $now . '.' . wp_rand(100000000, 999999999);
        $requestUrl = add_query_arg('doing_wp_cron', $doing, $cronUrl);

        $args = [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ];

        $response = wp_remote_post($requestUrl, $args);
        if ($response instanceof \WP_Error) {
            $this->logError('scheduler_loopback_failed', [
                'message' => $response->get_error_message(),
            ]);

            return;
        }

        $this->logDebug('scheduler_loopback_triggered', [
            'url' => $requestUrl,
        ]);
    }

    /**
     * Determine whether the worker recently executed.
     *
     * Context: Cron execution guard to reduce re-entry. Reads the
     * `LAST_RUN_TRANSIENT` marker without mutating state.
     *
     * @return bool
     */
    private function isRunThrottled() {
        return (bool) self::getTransient(self::LAST_RUN_TRANSIENT);
    }

    /**
     * Mark the current run to prevent immediate re-entry.
     *
     * Context: Cron execution guard to limit run frequency by persisting the
     * `LAST_RUN_TRANSIENT` marker.
     *
     * @param int $now Current UTC timestamp.
     *
     * @return void
     */
    private function markRunThrottle($now) {
        self::setTransient(self::LAST_RUN_TRANSIENT, $now, self::RUN_THROTTLE_SECONDS);
    }

    /**
     * Retrieve a namespaced transient value.
     *
     * Context: Request and cron safe helper that scopes scheduler transients
     * per site. Does not trigger logging and never throws.
     *
     * @since 1.1.0
     *
     * @param string $base Transient base key.
     *
     * @return mixed
     */
    private static function getTransient($base) {
        $key = self::buildTransientKey($base);
        $value = get_transient($key);

        if (false === $value) {
            $legacyKey = self::buildLegacyTransientKey($base);
            if ($legacyKey !== '') {
                $legacyValue = get_transient($legacyKey);
                if (false !== $legacyValue) {
                    return $legacyValue;
                }
            }
        }

        return $value;
    }

    /**
     * Persist a namespaced transient value.
     *
     * Context: Request and cron safe helper that normalizes expiration
     * windows for throttle transients such as `SPAWN_THROTTLE_TRANSIENT` and
     * `LOOPBACK_THROTTLE_TRANSIENT`. Does not log and never throws.
     *
     * @since 1.1.0
     *
     * @param string $base       Transient base key.
     * @param mixed  $value      Value to store.
     * @param int    $expiration Expiration in seconds.
     *
     * @return void
     */
    private static function setTransient($base, $value, $expiration) {
        $key = self::buildTransientKey($base);
        $expiration = (int) $expiration;
        if ($expiration < 1) {
            $expiration = 1;
        }

        set_transient($key, $value, $expiration);
        $legacyKey = self::buildLegacyTransientKey($base);
        if ($legacyKey !== '') {
            delete_transient($legacyKey);
        }
    }

    /**
     * Delete a namespaced transient.
     *
     * Context: Request and cron safe helper used to clear throttle and notice
     * flags. No logging occurs and no exceptions are thrown.
     *
     * @since 1.1.0
     *
     * @param string $base Transient base key.
     *
     * @return void
     */
    private static function deleteTransient($base) {
        $key = self::buildTransientKey($base);
        delete_transient($key);
        $legacyKey = self::buildLegacyTransientKey($base);
        if ($legacyKey !== '') {
            delete_transient($legacyKey);
        }
    }

    /**
     * Build a transient key scoped to the current site.
     *
     * Context: Internal utility for throttle and notice transients. Sanitizes
     * keys and appends the blog identifier. Never throws.
     *
     * @since 1.1.0
     *
     * @param string $base Base key string.
     *
     * @return string Namespaced transient key.
     */
    private static function buildTransientKey($base) {
        $normalized = self::normalizeTransientBase($base);

        $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;

        return sprintf('exmoau_%s_%d', $normalized, $blogId);
    }

    /**
     * Build a legacy transient key scoped to the current site.
     *
     * @param string $base Base key string.
     * @return string Legacy transient key.
     */
    private static function buildLegacyTransientKey($base) {
        $normalized = self::normalizeTransientBase($base);

        $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;

        return sprintf('exmoau_%s_%d', $normalized, $blogId);
    }

    /**
     * Normalize a transient base key by stripping known prefixes.
     *
     * @param string $base Base key string.
     * @return string Normalized base key.
     */
    private static function normalizeTransientBase($base) {
        $normalized = sanitize_key($base);
        if (strpos($normalized, 'exmoau_') === 0) {
            $normalized = substr($normalized, 7);
        } elseif (strpos($normalized, 'exmoau_') === 0) {
            $normalized = substr($normalized, 5);
        }

        if ($normalized === '') {
            $normalized = 'temp';
        }

        return $normalized;
    }

    /**
     * Write a structured error log entry from static contexts.
     *
     * Context: Used by static helpers when scheduler state changes outside an
     * instantiated worker. Emits structured logs without throwing.
     *
     * @since 1.1.0
     *
     * @param string               $code    Error code identifier.
     * @param array<string, mixed> $context Contextual values.
     *
     * @return void
     */
    private static function logStaticError($code, array $context = []) {
        self::dispatchLog('error', $code, $context);
    }

    /**
     * Execute due schedules for single and repeating jobs.
     *
     * Context: Cron execution triggered by the `HOOK` schedule. Reads and
     * updates scheduler transients, dispatches debug logging for selection and
     * dispatch decisions, and never throws.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function runScheduledJobs() {

        if (self::isCronDisabled()) {
            self::flagCronDisabled();

            return;
        }

        $nowTimestamp = JobsTimeHelper::getCurrentUtcTimestamp();
        $nowInstant = (new DateTimeImmutable('@' . $nowTimestamp))->setTimezone(new DateTimeZone('UTC'));
        $tolerance = self::DUE_GRACE_SECONDS;

        // Disabled by: @Gero on 26.10.2025
        // if ($this->isRunThrottled()) {
        //     $this->logDebug('scheduler_run_throttled', [
        //         'now' => $nowTimestamp,
        //     ]);

        //     return;
        // }

        // $this->markRunThrottle($nowTimestamp);

        $jobBuckets = [];
        $counters = [
            'singles_due'            => 0,
            'repeaters_due_rows'     => 0,
            'jobs_claimed'           => 0,
            'jobs_executed'          => 0,
            'repeater_rows_advanced' => 0,
        ];

        $singleRows = $this->fetchDueSingleSchedules($nowTimestamp, $tolerance, self::MAX_JOBS_PER_TICK);

        foreach ($singleRows as $row) {
            $scheduleId = isset($row['id']) ? (int) $row['id'] : 0;
            $jobId = isset($row['job_post_id']) ? (int) $row['job_post_id'] : 0;
            $scheduledTimestamp = isset($row['scheduled_timestamp']) ? (int) $row['scheduled_timestamp'] : 0;

            if ($scheduleId < 1 || $jobId < 1 || $scheduledTimestamp <= 0) {
                continue;
            }

            if (!JobsTimeHelper::isDue($scheduledTimestamp, $nowTimestamp, $tolerance)) {
                continue;
            }

            $jobBuckets[$jobId]['single'][] = [
                'id'                  => $scheduleId,
                'scheduled_timestamp' => $scheduledTimestamp,
            ];
            $counters['singles_due']++;
        }

        $repeatingRows = $this->fetchDueRepeatingSchedules(
            $nowTimestamp,
            $tolerance,
            self::MAX_JOBS_PER_TICK * 6
        );

        $dueThreshold = $nowTimestamp + $tolerance;
        $repeatingCount = is_array($repeatingRows) ? count($repeatingRows) : 0;
        $snapshotLimit = 10;
        $snapshots = [];

        if ($repeatingCount > 0) {
            foreach ($repeatingRows as $row) {
                if (count($snapshots) >= $snapshotLimit) {
                    break;
                }

                $scheduleId = isset($row['id']) ? (int) $row['id'] : 0;
                $jobId = isset($row['job_post_id']) ? (int) $row['job_post_id'] : 0;
                $scheduledTimestamp = isset($row['scheduled_timestamp']) ? (int) $row['scheduled_timestamp'] : 0;
                $weekday = isset($row['weekday_key']) ? (int) $row['weekday_key'] : 0;
                $timeOfDay = isset($row['time_of_day']) ? (string) $row['time_of_day'] : '';

                $snapshots[] = sprintf(
                    '#%d@%d-w%d-%s-j%d',
                    $scheduleId,
                    $scheduledTimestamp,
                    $weekday,
                    sanitize_text_field($timeOfDay),
                    $jobId
                );
            }
        }

        $this->logDebug('scheduler_repeater_selection', [
            'now'       => $nowTimestamp,
            'threshold' => $dueThreshold,
            'fetched'   => $repeatingCount,
            'rows'      => empty($snapshots) ? '' : implode(';', $snapshots),
        ]);

        foreach ($repeatingRows as $row) {
            $scheduleId = isset($row['id']) ? (int) $row['id'] : 0;
            $jobId = isset($row['job_post_id']) ? (int) $row['job_post_id'] : 0;
            $weekday = isset($row['weekday_key']) ? (int) $row['weekday_key'] : 0;
            $timeOfDay = isset($row['time_of_day']) ? (string) $row['time_of_day'] : '';
            $scheduledTimestamp = isset($row['scheduled_timestamp']) ? (int) $row['scheduled_timestamp'] : 0;

            if ($scheduleId < 1 || $jobId < 1 || $weekday < 1 || $weekday > 7 || $timeOfDay === '') {
                continue;
            }

            if ($scheduledTimestamp <= 0) {
                $reference = max($nowTimestamp - $tolerance, 1);
                $nextTimestamp = $this->calculateNextRepeatingTimestamp($weekday, $timeOfDay, $reference);

                if ($nextTimestamp <= 0) {
                    continue;
                }

                $this->initializeRepeatingSchedule($scheduleId, $nextTimestamp);
                $this->logDebug('scheduler_repeater_init_timestamp', [
                    'schedule_id' => $scheduleId,
                    'job_id'      => $jobId,
                    'next_run'    => $nextTimestamp,
                ]);

                $scheduledTimestamp = $nextTimestamp;
            }

            if (!JobsTimeHelper::isDue($scheduledTimestamp, $nowTimestamp, $tolerance)) {
                continue;
            }

            $counters['repeaters_due_rows']++;
            $this->logDebug('scheduler_repeater_due', [
                'schedule_id' => $scheduleId,
                'job_id'      => $jobId,
                'scheduled'   => $scheduledTimestamp,
                'weekday'     => $weekday,
                'time'        => $timeOfDay,
            ]);

            $jobBuckets[$jobId]['repeating'][] = [
                'id'                  => $scheduleId,
                'weekday_key'         => $weekday,
                'time_of_day'         => $timeOfDay,
                'scheduled_timestamp' => $scheduledTimestamp,
            ];
        }

        if (empty($jobBuckets)) {
            $this->logDebug('scheduler_tick_counters', array_merge($counters, [
                'now' => $nowTimestamp,
            ]));

            self::clearCronDisabledNotice();

            return;
        }

        $queue = [];
        foreach ($jobBuckets as $jobId => $bucket) {
            $jobId = (int) $jobId;
            if ($jobId < 1) {
                continue;
            }

            $earliest = $this->resolveEarliestTimestamp($bucket);
            if ($earliest <= 0) {
                continue;
            }

            $queue[] = [
                'job_id'   => $jobId,
                'earliest' => $earliest,
            ];
        }

        if (empty($queue)) {
            $this->logDebug('scheduler_tick_counters', array_merge($counters, [
                'now' => $nowTimestamp,
            ]));

            self::clearCronDisabledNotice();

            return;
        }

        usort($queue, static function ($left, $right) {
            $leftTime = isset($left['earliest']) ? (int) $left['earliest'] : PHP_INT_MAX;
            $rightTime = isset($right['earliest']) ? (int) $right['earliest'] : PHP_INT_MAX;

            if ($leftTime === $rightTime) {
                return 0;
            }

            return ($leftTime < $rightTime) ? -1 : 1;
        });

        $processed = 0;

        foreach ($queue as $entry) {
            if ($processed >= self::MAX_JOBS_PER_TICK) {
                break;
            }

            $jobId = isset($entry['job_id']) ? (int) $entry['job_id'] : 0;
            if ($jobId < 1 || !isset($jobBuckets[$jobId])) {
                continue;
            }

            $bucket = $jobBuckets[$jobId];
            $result = $this->dispatchJobGroup(
                $jobId,
                isset($bucket['single']) ? $bucket['single'] : [],
                isset($bucket['repeating']) ? $bucket['repeating'] : [],
                $nowInstant,
                $counters
            );

            if (!empty($result['attempted'])) {
                $processed++;
            }
        }

        $this->logDebug('scheduler_tick_counters', array_merge($counters, [
            'processed' => $processed,
            'now'       => $nowTimestamp,
        ]));

        self::clearCronDisabledNotice();
    }

    /**
     * Retrieve due single schedules ready for execution.
     *
     * @param int $nowTimestamp Current UTC timestamp.
     * @param int $tolerance    Grace window in seconds.
     * @param int $limit        Maximum rows to retrieve.
     *
     * @return array<int, array<string, mixed>> Rows keyed numerically.
     */
    private function fetchDueSingleSchedules($nowTimestamp, $tolerance, $limit) {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return [];
        }

        $limit = (int) $limit;
        if ($limit <= 0) {
            return [];
        }

        $table = JobsSchedulingController::getTableName();
        $postsTable = $wpdb->posts;

        $nowTimestamp = (int) $nowTimestamp;
        if ($nowTimestamp <= 0) {
            $nowTimestamp = JobsTimeHelper::getCurrentUtcTimestamp();
        }

        $tolerance = (int) $tolerance;
        if ($tolerance < 0) {
            $tolerance = 0;
        }

        $threshold = $nowTimestamp + $tolerance;

        $sql = $wpdb->prepare(
            "SELECT schedule.id, schedule.job_post_id, schedule.scheduled_timestamp\n            FROM {$table} AS schedule\n            INNER JOIN {$postsTable} AS posts ON posts.ID = schedule.job_post_id\n            WHERE posts.post_status = %s\n              AND posts.post_type = %s\n              AND schedule.schedule_type = %d\n              AND schedule.exmoau_execution_status = %d\n              AND schedule.scheduled_timestamp IS NOT NULL\n              AND schedule.scheduled_timestamp <= %d\n            ORDER BY schedule.scheduled_timestamp ASC\n            LIMIT %d",
            'publish',
            'exmoau_job',
            JobsSchedulingController::TYPE_SINGLE,
            JobsSchedulingController::STATUS_PENDING,
            $threshold,
            $limit
        );

        if ($sql === false) {
            return [];
        }

        $results = $wpdb->get_results($sql, ARRAY_A);

        return is_array($results) ? $results : [];
    }

    /**
     * Retrieve due repeating schedules ready for execution.
     *
     * @param int $nowTimestamp Current UTC timestamp.
     * @param int $tolerance    Grace window in seconds.
     * @param int $limit        Maximum rows to retrieve.
     *
     * @return array<int, array<string, mixed>> Rows keyed numerically including schedule metadata.
     */
    private function fetchDueRepeatingSchedules($nowTimestamp, $tolerance, $limit) {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return [];
        }

        $limit = (int) $limit;
        if ($limit <= 0) {
            return [];
        }

        $table = JobsSchedulingController::getTableName();
        $postsTable = $wpdb->posts;

        $nowTimestamp = (int) $nowTimestamp;
        if ($nowTimestamp <= 0) {
            $nowTimestamp = JobsTimeHelper::getCurrentUtcTimestamp();
        }

        $tolerance = (int) $tolerance;
        if ($tolerance < 0) {
            $tolerance = 0;
        }

        $threshold = $nowTimestamp + $tolerance;

        $sql = $wpdb->prepare(
            "SELECT schedule.id, schedule.job_post_id, schedule.scheduled_timestamp, schedule.weekday_key, schedule.time_of_day\n            FROM {$table} AS schedule\n            INNER JOIN {$postsTable} AS posts ON posts.ID = schedule.job_post_id\n            WHERE posts.post_status = %s\n              AND posts.post_type = %s\n              AND schedule.schedule_type = %d\n              AND schedule.exmoau_execution_status = %d\n              AND schedule.weekday_key IS NOT NULL\n              AND schedule.time_of_day IS NOT NULL\n              AND (schedule.scheduled_timestamp IS NULL OR schedule.scheduled_timestamp <= %d)\n            ORDER BY schedule.job_post_id ASC, schedule.id ASC\n            LIMIT %d",
            'publish',
            'exmoau_job',
            JobsSchedulingController::TYPE_REPEATING,
            JobsSchedulingController::STATUS_PENDING,
            $threshold,
            $limit
        );

        if ($sql === false) {
            return [];
        }

        $results = $wpdb->get_results($sql, ARRAY_A);

        return is_array($results) ? $results : [];
    }

    /**
     * Dispatch a grouped set of schedules for a single job.
     *
     * @param int                             $jobId          Job identifier.
     * @param array<int, array<string, int>>  $singleRows     Single schedule rows keyed numerically.
     * @param array<int, array<string, mixed>> $repeatingRows Repeating schedule rows keyed numerically.
     * @param DateTimeImmutable               $nowInstant     Current UTC instant.
     * @param array<string, int>             &$counters      Mutable per-tick counters.
     *
     * @return array{attempted: bool, executed: bool}
     */
    private function dispatchJobGroup($jobId, array $singleRows, array $repeatingRows, DateTimeImmutable $nowInstant, array &$counters) {
        $outcome = [
            'attempted' => false,
            'executed'  => false,
        ];

        $jobId = (int) $jobId;
        if ($jobId < 1) {
            return $outcome;
        }

        if (empty($singleRows) && empty($repeatingRows)) {
            return $outcome;
        }

        if (!$this->acquireLock($jobId)) {
            $this->logDebug('scheduler_lock_skip', [
                'job_id' => $jobId,
            ]);

            return $outcome;
        }

        $outcome['attempted'] = true;
        $counters['jobs_claimed']++;

        try {
            $post = get_post($jobId);
            if (!($post instanceof WP_Post) || $post->post_type !== 'exmoau_job' || $post->post_status !== 'publish') {
                $this->logDebug('scheduler_job_missing', [
                    'job_id' => $jobId,
                ]);
                $this->finalizeMissingJob($singleRows, $repeatingRows, $nowInstant);

                return $outcome;
            }

            $jobType = $this->getJobType($jobId);
            if ($jobType === '') {
                $jobType = 'single_scheduled';
            }

            if (!in_array($jobType, ['single_scheduled', 'repeating_scheduled'], true)) {
                $this->logDebug('scheduler_job_type_invalid', [
                    'job_id'   => $jobId,
                    'job_type' => $jobType,
                ]);
                $this->finalizeMissingJob($singleRows, $repeatingRows, $nowInstant);

                return $outcome;
            }

            $execution = $this->getExecutionController();
            if (!($execution instanceof JobsExecutionController)) {
                $this->logError('scheduler_execution_missing', [
                    'job_id' => $jobId,
                ]);

                return $outcome;
            }

            $scheduleIds = [];
            $scheduledFor = $this->resolveEarliestTimestamp([
                'single'    => $singleRows,
                'repeating' => $repeatingRows,
            ]);

            foreach ($singleRows as $singleRow) {
                if (isset($singleRow['id'])) {
                    $scheduleIds[] = (int) $singleRow['id'];
                }
            }

            foreach ($repeatingRows as $repeatingRow) {
                if (isset($repeatingRow['id'])) {
                    $scheduleIds[] = (int) $repeatingRow['id'];
                }
            }

            $scheduleType = !empty($repeatingRows) ? 'repeating' : 'single';
            $primaryScheduleId = 0;
            if (!empty($singleRows) && isset($singleRows[0]['id'])) {
                $primaryScheduleId = (int) $singleRows[0]['id'];
            } elseif (!empty($repeatingRows) && isset($repeatingRows[0]['id'])) {
                $primaryScheduleId = (int) $repeatingRows[0]['id'];
            }

            $context = [
                'trigger'        => 'schedule',
                'schedule_type'  => $scheduleType,
                'schedule_id'    => $primaryScheduleId,
                'scheduled_for'  => $scheduledFor,
            ];

            if (!empty($scheduleIds)) {
                $context['schedule_ids'] = $scheduleIds;
            }

            $this->logDebug('scheduler_job_context', [
                'job_id'        => $jobId,
                'job_type'      => $jobType,
                'schedule_type' => $scheduleType,
                'primary_id'    => $primaryScheduleId,
                'scheduled_for' => $scheduledFor,
            ]);

            $result = $execution->runScheduledJob($jobId, $context);

            if (empty($result['success'])) {
                $this->logError('scheduler_dispatch_error', [
                    'job_id'       => $jobId,
                    'schedule_type'=> $scheduleType,
                ]);

                return $outcome;
            }

            $outcome['executed'] = true;
            $counters['jobs_executed']++;

            foreach ($singleRows as $singleRow) {
                $scheduleId = isset($singleRow['id']) ? (int) $singleRow['id'] : 0;
                $scheduledTimestamp = isset($singleRow['scheduled_timestamp']) ? (int) $singleRow['scheduled_timestamp'] : 0;
                if ($scheduleId < 1 || $scheduledTimestamp <= 0) {
                    continue;
                }

                $this->completeSingleSchedule($scheduleId, $nowInstant, $scheduledTimestamp);
                $this->logDebug('scheduler_single_executed', [
                    'job_id'      => $jobId,
                    'schedule_id' => $scheduleId,
                ]);
            }

            if (!empty($repeatingRows)) {
                $this->advanceRepeatingRows($jobId, $repeatingRows, $nowInstant, $counters);
            }
        } finally {
            $this->releaseLock($jobId);
        }

        return $outcome;
    }

    /**
     * Determine the earliest scheduled timestamp within a set of rows.
     *
     * Context: Helper used during dispatch grouping to find the soonest
     * execution time across single and repeating buckets.
     *
     * @param array<string, array<int, array<string, mixed>>> $bucket Grouped schedule rows keyed by schedule type.
     *
     * @return int Earliest timestamp or zero when unavailable.
     */
    private function resolveEarliestTimestamp(array $bucket) {
        $earliest = PHP_INT_MAX;

        if (!empty($bucket['single'])) {
            foreach ($bucket['single'] as $single) {
                $timestamp = isset($single['scheduled_timestamp']) ? (int) $single['scheduled_timestamp'] : 0;
                if ($timestamp > 0 && $timestamp < $earliest) {
                    $earliest = $timestamp;
                }
            }
        }

        if (!empty($bucket['repeating'])) {
            foreach ($bucket['repeating'] as $row) {
                $timestamp = isset($row['scheduled_timestamp']) ? (int) $row['scheduled_timestamp'] : 0;
                if ($timestamp > 0 && $timestamp < $earliest) {
                    $earliest = $timestamp;
                }
            }
        }

        return ($earliest === PHP_INT_MAX) ? 0 : $earliest;
    }

    /**
     * Advance repeating rows to their next scheduled occurrence.
     *
     * Context: Cron execution flow after a repeating job succeeds. Computes the
     * next timestamps and persists them through the scheduling controller.
     *
     * @param int                             $jobId      Job identifier.
     * @param array<int, array<string, mixed>> $rows       Repeating rows to advance.
     * @param DateTimeImmutable               $executedAt Execution timestamp.
     * @param array<string, int>             &$counters  Counter tracker.
     *
     * @return void
     */
    private function advanceRepeatingRows($jobId, array $rows, DateTimeImmutable $executedAt, array &$counters) {
        $scheduling = $this->getSchedulingController();
        if (!($scheduling instanceof JobsSchedulingController)) {
            $this->logError('scheduler_scheduling_missing', [
                'job_id' => $jobId,
            ]);

            return;
        }

        foreach ($rows as $row) {
            $scheduleId = isset($row['id']) ? (int) $row['id'] : 0;
            $weekday = isset($row['weekday_key']) ? (int) $row['weekday_key'] : 0;
            $timeOfDay = isset($row['time_of_day']) ? (string) $row['time_of_day'] : '';
            $scheduledTimestamp = isset($row['scheduled_timestamp']) ? (int) $row['scheduled_timestamp'] : 0;

            if ($scheduleId < 1 || $weekday < 1 || $weekday > 7 || $timeOfDay === '' || $scheduledTimestamp <= 0) {
                $this->logError('scheduler_repeating_row_invalid', [
                    'schedule_id' => $scheduleId,
                    'job_id'      => $jobId,
                    'weekday'     => $weekday,
                    'time'        => $timeOfDay,
                    'scheduled'   => $scheduledTimestamp,
                ]);

                continue;
            }

            $this->logDebug('scheduler_repeater_before_advance', [
                'job_id'      => $jobId,
                'schedule_id' => $scheduleId,
                'weekday'     => $weekday,
                'time'        => $timeOfDay,
                'scheduled'   => $scheduledTimestamp,
            ]);

            $nextTimestamp = $this->calculateNextRepeatingTimestamp($weekday, $timeOfDay, $scheduledTimestamp + 1, $scheduling);
            if ($nextTimestamp <= 0) {
                $this->logError('scheduler_next_run_unavailable', [
                    'schedule_id' => $scheduleId,
                    'job_id'      => $jobId,
                    'weekday'     => $weekday,
                    'time'        => $timeOfDay,
                    'previous'    => $scheduledTimestamp,
                ]);

                continue;
            }

            $this->updateRepeatingSchedule($scheduleId, $nextTimestamp, $executedAt, $scheduledTimestamp);
            $this->logDebug('scheduler_repeater_advanced', [
                'job_id'      => $jobId,
                'schedule_id' => $scheduleId,
                'next_run'    => $nextTimestamp,
                'previous'    => $scheduledTimestamp,
                'weekday'     => $weekday,
                'time'        => $timeOfDay,
            ]);

            $counters['repeater_rows_advanced']++;
        }
    }

    /**
     * Complete or clear schedule rows when the job cannot be executed.
     *
     * Context: Cron execution fallback for missing or invalid jobs. Marks
     * pending single rows complete and clears repeating rows to avoid
     * repeated processing attempts.
     *
     * @param array<int, array<string, mixed>> $singleRows    Single schedule rows.
     * @param array<int, array<string, mixed>> $repeatingRows Repeating schedule rows.
     * @param DateTimeImmutable                $nowInstant    Current UTC instant.
     *
     * @return void
     */
    private function finalizeMissingJob(array $singleRows, array $repeatingRows, DateTimeImmutable $nowInstant) {
        foreach ($singleRows as $singleRow) {
            $scheduleId = isset($singleRow['id']) ? (int) $singleRow['id'] : 0;
            $scheduledTimestamp = isset($singleRow['scheduled_timestamp']) ? (int) $singleRow['scheduled_timestamp'] : 0;
            if ($scheduleId < 1 || $scheduledTimestamp <= 0) {
                continue;
            }

            $this->completeSingleSchedule($scheduleId, $nowInstant, $scheduledTimestamp);
        }

        foreach ($repeatingRows as $repeatingRow) {
            $scheduleId = isset($repeatingRow['id']) ? (int) $repeatingRow['id'] : 0;
            $scheduledTimestamp = isset($repeatingRow['scheduled_timestamp']) ? (int) $repeatingRow['scheduled_timestamp'] : 0;
            if ($scheduleId < 1 || $scheduledTimestamp <= 0) {
                continue;
            }

            $this->clearRepeatingSchedule($scheduleId, $nowInstant, $scheduledTimestamp);
        }
    }

    /**
     * Calculate the next UTC timestamp for a repeating row.
     *
     * Context: Scheduling helper leveraged both during initialization and
     * advancement to compute the next run time for a repeating schedule.
     *
     * @param int                        $weekdayKey          ISO-8601 weekday number (1-7).
     * @param string                     $timeOfDay           Time of day string (HH:MM).
     * @param int                        $referenceTimestamp  Reference timestamp for calculation.
     * @param JobsSchedulingController|null $scheduling          Optional scheduling controller override.
     *
     * @return int Next run timestamp or zero when unavailable.
     */
    private function calculateNextRepeatingTimestamp($weekdayKey, $timeOfDay, $referenceTimestamp, ?JobsSchedulingController $scheduling = null) {
        $weekdayKey = (int) $weekdayKey;
        $timeOfDay = is_string($timeOfDay) ? trim($timeOfDay) : '';
        $referenceTimestamp = (int) $referenceTimestamp;

        if ($weekdayKey < 1 || $weekdayKey > 7 || $timeOfDay === '') {
            return 0;
        }

        if ($referenceTimestamp <= 0) {
            $referenceTimestamp = JobsTimeHelper::getCurrentUtcTimestamp();
        }

        $reference = (new DateTimeImmutable('@' . $referenceTimestamp))->setTimezone(new DateTimeZone('UTC'));

        if (!($scheduling instanceof JobsSchedulingController)) {
            $scheduling = $this->getSchedulingController();
        }
        if (!($scheduling instanceof JobsSchedulingController)) {
            return 0;
        }

        $next = $scheduling->calculateNextUtcForScheduleRow($weekdayKey, $timeOfDay, $reference);

        return ($next instanceof DateTimeImmutable) ? $next->getTimestamp() : 0;
    }

    /**
     * Persist an initialized repeating schedule timestamp.
     *
     * Context: Used when repeating rows are initialized without a computed
     * timestamp to seed their first scheduled execution.
     *
     * @param int $scheduleId   Schedule row identifier.
     * @param int $nextTimestamp Next UTC timestamp.
     *
     * @return void
     */
    private function initializeRepeatingSchedule($scheduleId, $nextTimestamp) {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return;
        }

        $scheduleId = (int) $scheduleId;
        $nextTimestamp = (int) $nextTimestamp;
        if ($scheduleId < 1 || $nextTimestamp <= 0) {
            return;
        }

        $table = JobsSchedulingController::getTableName();
        $sql = $wpdb->prepare(
            "UPDATE {$table} SET scheduled_timestamp = %d WHERE id = %d AND scheduled_timestamp IS NULL",
            $nextTimestamp,
            $scheduleId
        );

        if ($sql === false) {
            return;
        }

        $wpdb->query($sql);
    }

    /**
     * Attempt to acquire a lock for the provided job.
     *
     * Context: Cron execution guard to ensure a job is only processed once per
     * tick using a transient lock keyed to the job identifier.
     *
     * @param int $jobId Job identifier.
     *
     * @return bool True when the lock is acquired, false otherwise.
     */
    private function acquireLock($jobId) {
        $jobId = (int) $jobId;
        if ($jobId < 1) {
            return false;
        }

        $key = self::LOCK_PREFIX . $jobId;
        if (get_transient($key)) {
            return false;
        }

        return set_transient($key, time(), self::LOCK_TTL);
    }

    /**
     * Release a previously acquired job lock.
     *
     * Context: Cron execution cleanup to clear transient locks after
     * processing completes.
     *
     * @param int $jobId Job identifier.
     *
     * @return void
     */
    private function releaseLock($jobId) {
        $jobId = (int) $jobId;
        if ($jobId < 1) {
            return;
        }

        delete_transient(self::LOCK_PREFIX . $jobId);
    }

    /**
     * Retrieve the job type for a given post.
     *
     * Context: Cron dispatch helper that inspects job metadata to determine
     * whether schedules are single or repeating.
     *
     * @param int $jobId Job identifier.
     *
     * @return string Sanitized job type or empty string when unavailable.
     */
    private function getJobType($jobId) {
        $value = get_post_meta($jobId, 'exmoau_job_type', true);

        return is_string($value) ? sanitize_key($value) : '';
    }

    /**
     * Retrieve the shared jobs execution controller instance.
     *
     * Context: Lazily resolves the execution controller from the core module
     * registry and caches it for subsequent scheduler operations.
     *
     * @return JobsExecutionController|null
     */
    private function getExecutionController() {
        if ($this->executionController instanceof JobsExecutionController) {
            return $this->executionController;
        }

        $core = ExMomentAuthorCoreSystem::getInstance();
        $controller = $core->getModule('JobsExecutionController');

        if (!($controller instanceof JobsExecutionController)) {
            $core->autoload();
            $controller = $core->getModule('JobsExecutionController');
        }

        $this->executionController = ($controller instanceof JobsExecutionController) ? $controller : null;

        return $this->executionController;
    }

    /**
     * Retrieve the shared jobs scheduling controller instance.
     *
     * Context: Lazily resolves the scheduling controller from the core module
     * registry and caches it for schedule advancement and initialization.
     *
     * @return JobsSchedulingController|null
     */
    private function getSchedulingController() {
        if ($this->schedulingController instanceof JobsSchedulingController) {
            return $this->schedulingController;
        }

        $core = ExMomentAuthorCoreSystem::getInstance();
        $controller = $core->getModule('JobsSchedulingController');

        if (!($controller instanceof JobsSchedulingController)) {
            $core->autoload();
            $controller = $core->getModule('JobsSchedulingController');
        }

        $this->schedulingController = ($controller instanceof JobsSchedulingController) ? $controller : null;

        return $this->schedulingController;
    }

    /**
     * Mark a single schedule as executed.
     *
     * Context: Cron execution flow after a single job succeeds. Marks the
     * schedule row executed when the expected timestamp matches.
     *
     * @param int               $scheduleId         Schedule row identifier.
     * @param DateTimeImmutable $executedAt         Execution timestamp.
     * @param int               $expectedTimestamp  Expected scheduled timestamp.
     *
     * @return void
     */
    private function completeSingleSchedule($scheduleId, DateTimeImmutable $executedAt, $expectedTimestamp) {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return;
        }

        $scheduleId = (int) $scheduleId;
        $expectedTimestamp = (int) $expectedTimestamp;
        if ($scheduleId < 1) {
            return;
        }

        if ($expectedTimestamp <= 0) {
            return;
        }

        $table = JobsSchedulingController::getTableName();
        $sql = $wpdb->prepare(
            "UPDATE {$table} SET exmoau_execution_status = %d, last_executed_at = %s WHERE id = %d AND exmoau_execution_status = %d AND scheduled_timestamp <= %d",
            JobsSchedulingController::STATUS_EXECUTED,
            gmdate('Y-m-d H:i:s', $executedAt->getTimestamp()),
            $scheduleId,
            JobsSchedulingController::STATUS_PENDING,
            $expectedTimestamp
        );

        if ($sql === false) {
            return;
        }

        $updated = $wpdb->query($sql);
        if ($updated === 0) {
            $this->logDebug('scheduler_single_update_skipped', [
                'schedule_id' => $scheduleId,
            ]);
        }
    }

    /**
     * Update a repeating schedule with its next run timestamp.
     *
     * Context: Cron execution flow for repeating jobs after successful
     * execution. Persists the next scheduled timestamp while tracking the
     * execution time.
     *
     * @param int               $scheduleId        Schedule row identifier.
     * @param int               $nextTimestamp     Next UTC run timestamp.
     * @param DateTimeImmutable $executedAt        Execution timestamp.
     * @param int               $previousTimestamp Previously scheduled UTC timestamp.
     *
     * @return void
     */
    private function updateRepeatingSchedule($scheduleId, $nextTimestamp, DateTimeImmutable $executedAt, $previousTimestamp) {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return;
        }

        $scheduleId = (int) $scheduleId;
        $nextTimestamp = (int) $nextTimestamp;
        $previousTimestamp = (int) $previousTimestamp;
        if ($scheduleId < 1 || $nextTimestamp <= 0 || $previousTimestamp <= 0) {
            return;
        }

        $table = JobsSchedulingController::getTableName();
        $sql = $wpdb->prepare(
            "UPDATE {$table} SET scheduled_timestamp = %d, last_executed_at = %s WHERE id = %d AND scheduled_timestamp = %d",
            $nextTimestamp,
            gmdate('Y-m-d H:i:s', $executedAt->getTimestamp()),
            $scheduleId,
            $previousTimestamp
        );

        if ($sql === false) {
            return;
        }

        $updated = $wpdb->query($sql);
        if ($updated === 0) {
            $this->logDebug('scheduler_repeating_update_skipped', [
                'schedule_id' => $scheduleId,
            ]);
        }
    }

    /**
     * Clear a repeating schedule that is no longer valid.
     *
     * Context: Cron execution fallback when a repeating row cannot calculate a
     * next run. Clears the timestamp to pause further execution until
     * recalculated.
     *
     * @param int               $scheduleId        Schedule row identifier.
     * @param DateTimeImmutable $executedAt        Execution timestamp.
     * @param int               $expectedTimestamp Expected UTC timestamp.
     *
     * @return void
     */
    private function clearRepeatingSchedule($scheduleId, DateTimeImmutable $executedAt, $expectedTimestamp) {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return;
        }

        $scheduleId = (int) $scheduleId;
        $expectedTimestamp = (int) $expectedTimestamp;
        if ($scheduleId < 1) {
            return;
        }

        if ($expectedTimestamp <= 0) {
            return;
        }

        $table = JobsSchedulingController::getTableName();
        $sql = $wpdb->prepare(
            "UPDATE {$table} SET scheduled_timestamp = NULL, last_executed_at = %s WHERE id = %d AND scheduled_timestamp = %d",
            gmdate('Y-m-d H:i:s', $executedAt->getTimestamp()),
            $scheduleId,
            $expectedTimestamp
        );

        if ($sql === false) {
            return;
        }

        $updated = $wpdb->query($sql);
        if ($updated === 0) {
            $this->logDebug('scheduler_repeating_clear_skipped', [
                'schedule_id' => $scheduleId,
            ]);
        }
    }

    /**
     * Write a structured debug log entry when WP_DEBUG is enabled.
     *
     * Context: Cron execution and bootstrap diagnostics. Delegates to the
     * shared dispatcher while honoring the `WP_DEBUG` flag to avoid noisy logs
     * in production environments.
     *
     * @param string               $code    Debug code identifier.
     * @param array<string, mixed> $context Contextual values.
     *
     * @return void
     */
    private function logDebug($code, array $context = []) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        self::dispatchLog('debug', $code, $context);
    }

    /**
     * Write a structured error log entry.
     *
     * Context: Cron execution and bootstrap error reporting helper routed
     * through the shared logging pipeline.
     *
     * @param string               $code    Error code identifier.
     * @param array<string, mixed> $context Contextual values.
     *
     * @return void
     */
    private function logError($code, array $context = []) {
        self::dispatchLog('error', $code, $context);
    }

    /**
     * Sanitize context values for structured logging.
     *
     * Context: Utility shared by static and instance logging helpers to ensure
     * error/debug payloads remain serializable. Never throws.
     *
     * @since 1.1.0
     *
     * @param array<string, mixed> $context Context values.
     *
     * @return array<string, mixed>
     */
    private static function sanitizeContext(array $context) {
        $sanitized = [];

        foreach ($context as $key => $value) {
            $key = is_string($key) ? sanitize_key($key) : '';
            if ($key === '') {
                continue;
            }

            if (is_int($value) || is_float($value)) {
                $sanitized[$key] = $value + 0;
                continue;
            }

            if ($value instanceof DateTimeImmutable) {
                $sanitized[$key] = $value->getTimestamp();
                continue;
            }

            if (is_bool($value)) {
                $sanitized[$key] = $value ? 1 : 0;
                continue;
            }

            if (is_scalar($value)) {
                $sanitized[$key] = sanitize_text_field((string) $value);
                continue;
            }

            $sanitized[$key] = '';
        }

        return $sanitized;
    }

    /**
     * Dispatch a structured log event to the central log service.
     *
     * @param string               $level   Log level identifier.
     * @param string               $code    Event or error code.
     * @param array<string, mixed> $context Additional context values.
     * @return void
     */
    private static function dispatchLog($level, $code, array $context = []) {
        $normalizedCode = sanitize_key($code);
        if ($normalizedCode === '') {
            $normalizedCode = 'unspecified';
        }

        $payload = self::sanitizeContext($context);
        $payload['type'] = ($level === 'debug') ? self::DEBUG_TYPE : self::ERROR_TYPE;
        $payload['code'] = $normalizedCode;

        $jobId = self::extractJobIdFromContext($payload);

        $logger = LogService::getInstance();
        $message = sprintf('Scheduler %s: %s', $level, $normalizedCode);

        $logged = false;
        if ($logger instanceof LogService) {
            switch ($level) {
                case 'debug':
                    $logged = $logger->debug('jobs.scheduler', $message, $payload, $jobId);
                    break;
                case 'info':
                    $logged = $logger->info('jobs.scheduler', $message, $payload, $jobId);
                    break;
                case 'warning':
                    $logged = $logger->warning('jobs.scheduler', $message, $payload, $jobId);
                    break;
                case 'error':
                    $logged = $logger->error('jobs.scheduler', $message, $payload, $jobId);
                    break;
                case 'critical':
                    $logged = $logger->critical('jobs.scheduler', $message, $payload, $jobId);
                    break;
                default:
                    $logged = $logger->info('jobs.scheduler', $message, $payload, $jobId);
                    break;
            }
        }

        if (!$logged && defined('WP_DEBUG') && WP_DEBUG) {
            $fallback = [
                'level'   => $level,
                'message' => $message,
                'context' => $payload,
                'job_id'  => $jobId,
            ];

            $json = wp_json_encode($fallback, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json) && $json !== '') {
                error_log($json); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }
    }

    /**
     * Extract a job identifier from the logging context when available.
     *
     * @param array<string, mixed> $context Sanitized logging context.
     * @return int|null
     */
    private static function extractJobIdFromContext(array $context) {
        $candidates = ['job_id', 'jobid'];

        foreach ($candidates as $key) {
            if (!isset($context[$key])) {
                continue;
            }

            $value = $context[$key];
            if (is_int($value)) {
                return ($value > 0) ? $value : null;
            }

            if (is_numeric($value)) {
                $intValue = (int) $value;

                return ($intValue > 0) ? $intValue : null;
            }
        }

        return null;
    }
}
