<?php

namespace ExMomentAuthor\Modules\Jobs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;
use WP_Error;
use WP_Post;
use wpdb;

use ExMomentAuthor\Modules\Log\LogService;
use ExMomentAuthor\Modules\Jobs\JobsTimeHelper;

/**
 * Handles persistence for job schedules using UTC-normalized timestamps.
 */
class JobsSchedulingController {

    private const POST_TYPE = 'exmoau_job';
    private const TABLE_NAME = 'exmoau_job_schedules';
    private const OPTION_SCHEMA_VERSION = 'exmoau_job_schedule_schema_version';
    private const SCHEMA_VERSION = '1.1.0';

    private const META_JOB_TYPE = 'exmoau_job_type';
    private const META_SINGLE_SCHEDULED = 'exmoau_job_single_scheduled_datetime';
    private const META_REPEATING_DAYS = 'exmoau_job_repeating_days';
    private const META_REPEATING_HOURS = 'exmoau_job_repeating_hours_by_day';

    public const TYPE_SINGLE = 1;
    public const TYPE_REPEATING = 2;

    public const STATUS_PENDING = 0;
    public const STATUS_EXECUTED = 1;

    private const MAX_REPEATING_ROWS = 50;
    private const PAST_TOLERANCE_SECONDS = 60;

    /**
     * Map weekday identifiers to ISO-8601 numeric day-of-week values.
     *
     * @var array<string, int>
     */
    private const WEEKDAY_NUMBERS = [
        'mon' => 1,
        'tue' => 2,
        'wed' => 3,
        'thu' => 4,
        'fri' => 5,
        'sat' => 6,
        'sun' => 7,
    ];

    /**
     * Hook WordPress actions.
     *
     * @param array<string, mixed> $config Optional module configuration (unused).
     * @return void
     */
    public function __construct(array $config = []) {
        unset($config);

        add_action('save_post_' . self::POST_TYPE, [$this, 'syncJobSchedule'], 20, 3);
    }

    /**
     * Handle plugin activation by creating or upgrading the schedule table.
     *
     * @return void
     */
    public static function activate() {
        self::maybeUpgradeSchema();
    }

    /**
     * Synchronize persisted schedule rows when a job is saved.
     *
     * Performs capability and auto-draft guards before attempting to rebuild rows
     * so only authorized, publishable jobs update the dataset stored in the
     * `{prefix}` copy of {@see self::TABLE_NAME}. When schedule building yields a
     * {@see WP_Error} the repeater notice path is triggered via
     * {@see self::handleScheduleBuildError()}, ensuring admins receive feedback
     * about invalid weekday/hour selections.
     *
     * @since 1.1.0
     *
     * @param int     $postId   Post identifier.
     * @param WP_Post $post     Post object.
     * @param bool    $isUpdate Whether this is an update operation.
     * @return void
     */
    public function syncJobSchedule($postId, $post, $isUpdate) {
        unset($isUpdate);

        if (!($post instanceof WP_Post)) {
            return;
        }

        $postId = absint($postId);
        if ($postId <= 0) {
            return;
        }

        if ($post->post_type !== self::POST_TYPE) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($postId)) {
            return;
        }

        if (function_exists('wp_is_post_revision') && wp_is_post_revision($postId)) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        if ($post->post_status === 'auto-draft') {
            return;
        }

        self::maybeUpgradeSchema();

        if (!$this->isTableReady()) {
            $this->logDebug('Schedule table is unavailable; skipping sync for job %d.', $postId);
            return;
        }

        $rows = $this->buildScheduleRows($postId);
        if (is_wp_error($rows)) {
            $this->handleScheduleBuildError($postId, $rows);

            return;
        }

        try {
            $this->persistSchedules($postId, $rows);
        } catch (Throwable $exception) {
            $this->logDebug(
                'Failed to persist schedule rows for job %d: %s',
                $postId,
                $exception->getMessage()
            );
        }
    }

    /**
     * Build the schedule rows for the provided job.
     *
     * Single schedules convert the stored local datetime to UTC and reject values
     * that fall before the tolerance window, while repeating schedules validate
     * weekday/hour pairs, deduplicate combinations, and enforce insertion limits.
     *
     * @since 1.1.0
     *
     * @param int $postId Job post identifier.
     * @return array<int, array{
     *     job_post_id:int,
     *     schedule_type:int,
     *     exmoau_execution_status:int,
     *     scheduled_timestamp?:int,
     *     weekday_key?:int,
     *     time_of_day?:string,
     *     created_at:string
     * }>|WP_Error Structured rows or error details when validation fails.
     */
    private function buildScheduleRows($postId) {
        $jobType = $this->getMetaString($postId, self::META_JOB_TYPE);
        if ($jobType === '') {
            return [];
        }

        $jobType = sanitize_key($jobType);

        $nowUtc = $this->getCurrentUtc();
        $createdAt = gmdate('Y-m-d H:i:s', $nowUtc->getTimestamp());

        if ($jobType === 'single_scheduled') {
            $localDatetime = $this->getMetaString($postId, self::META_SINGLE_SCHEDULED);
            if ($localDatetime === '') {
                return [];
            }

            $scheduledUtc = $this->convertLocalStringToUtc($localDatetime);
            if ($scheduledUtc === null) {
                return [];
            }

            $scheduledTimestamp = $scheduledUtc->getTimestamp();
            $minimumTimestamp = $nowUtc->getTimestamp() - self::PAST_TOLERANCE_SECONDS;
            if ($scheduledTimestamp < $minimumTimestamp) {
                $this->logDebug(
                    'Skipping single scheduled job %d because UTC %s falls before minimum %s after tolerance.',
                    $postId,
                    gmdate('Y-m-d H:i:s', $scheduledTimestamp),
                    gmdate('Y-m-d H:i:s', $minimumTimestamp)
                );

                return [];
            }

            return [[
                'job_post_id' => $postId,
                'schedule_type' => self::TYPE_SINGLE,
                'exmoau_execution_status' => self::STATUS_PENDING,
                'scheduled_timestamp' => $scheduledTimestamp,
                'created_at' => $createdAt,
            ]];
        }

        if ($jobType === 'repeating_scheduled') {
            $days = $this->getMetaArray($postId, self::META_REPEATING_DAYS);
            $hoursByDay = $this->getMetaArray($postId, self::META_REPEATING_HOURS);

            $normalizedDays = $this->normalizeRepeatingDays($days, $postId);
            if (!empty($days) && empty($normalizedDays)) {
                return new WP_Error('repeater_invalid_input', 'No valid weekdays were provided.');
            }

            if (empty($normalizedDays)) {
                return [];
            }

            $normalizedHours = $this->normalizeRepeatingHours($hoursByDay, $normalizedDays, $postId);
            if (!empty($hoursByDay) && empty($normalizedHours)) {
                return new WP_Error('repeater_invalid_input', 'No valid time selections were provided.');
            }

            if (empty($normalizedHours)) {
                return [];
            }

            $rows = [];
            $seenPairs = [];
            $seenTimestamps = [];
            $inserted = 0;
            $limitLogged = false;

            foreach ($normalizedDays as $dayKey) {
                if (!isset(self::WEEKDAY_NUMBERS[$dayKey])) {
                    continue;
                }

                if ($inserted >= self::MAX_REPEATING_ROWS) {
                    if (!$limitLogged) {
                        $this->logDebug(
                            'Reached repeating schedule insert limit (%d) for job %d.',
                            self::MAX_REPEATING_ROWS,
                            $postId
                        );
                        $limitLogged = true;
                    }

                    break;
                }

                if (empty($normalizedHours[$dayKey]) || !is_array($normalizedHours[$dayKey])) {
                    continue;
                }

                foreach ($normalizedHours[$dayKey] as $timeValue) {
                    if ($inserted >= self::MAX_REPEATING_ROWS) {
                        if (!$limitLogged) {
                            $this->logDebug(
                                'Reached repeating schedule insert limit (%d) for job %d.',
                                self::MAX_REPEATING_ROWS,
                                $postId
                            );
                            $limitLogged = true;
                        }

                        break 2;
                    }

                    $weekdayNumber = (int) self::WEEKDAY_NUMBERS[$dayKey];
                    $pairKey = $weekdayNumber . '|' . $timeValue;
                    if (isset($seenPairs[$pairKey])) {
                        $this->logDebug(
                            'Duplicate repeating slot %s %s detected for job %d; skipping.',
                            $dayKey,
                            $timeValue,
                            $postId
                        );

                        continue;
                    }

                    $calculation = $this->calculateNextOccurrence($dayKey, $timeValue, $nowUtc);
                    if (is_wp_error($calculation)) {
                        if ($calculation->get_error_code() === 'repeater_invalid_input') {
                            return $calculation;
                        }

                        $this->logDebug(
                            'Unable to calculate next occurrence for repeating slot %s %s on job %d: %s',
                            $dayKey,
                            $timeValue,
                            $postId,
                            sanitize_text_field($calculation->get_error_message())
                        );

                        continue;
                    }

                    if (empty($calculation) || !isset($calculation['timestamp'])) {
                        continue;
                    }

                    $timestamp = (int) $calculation['timestamp'];
                    if ($timestamp <= 0) {
                        continue;
                    }

                    if (isset($seenTimestamps[$timestamp])) {
                        $this->logDebug(
                            'Next occurrence timestamp %d already scheduled; skipping duplicate for job %d.',
                            $timestamp,
                            $postId
                        );

                        continue;
                    }

                    $rows[] = [
                        'job_post_id' => $postId,
                        'schedule_type' => self::TYPE_REPEATING,
                        'exmoau_execution_status' => self::STATUS_PENDING,
                        'scheduled_timestamp' => $timestamp,
                        'weekday_key' => $weekdayNumber,
                        'time_of_day' => $timeValue,
                        'created_at' => $createdAt,
                    ];

                    $seenPairs[$pairKey] = true;
                    $seenTimestamps[$timestamp] = true;
                    $inserted++;
                }
            }

            return $rows;
        }

        return [];
    }

    /**
     * Handle schedule build errors gracefully.
     *
     * @since 1.1.0
     *
     * @param int      $postId Job post identifier.
     * @param WP_Error $error  Encountered error instance.
     * @return void
     */
    private function handleScheduleBuildError($postId, WP_Error $error) {
        $code = $error->get_error_code();
        $message = $error->get_error_message();

        if ($code === 'repeater_invalid_input') {
            $this->enqueueInvalidRepeaterNotice($postId);
            $this->logRepeaterBuildSkipped($postId, 'invalid_input');
        }

        if ($message === '') {
            $message = 'Unknown error';
        }

        $this->logDebug(
            'Failed to build schedule rows for job %d: [%s] %s',
            $postId,
            sanitize_key($code),
            sanitize_text_field($message)
        );
    }

    /**
     * Persist schedule rows to the database defined by {@see self::TABLE_NAME}.
     *
     * Rows replace prior entries atomically so migrations tracking
     * {@see self::OPTION_SCHEMA_VERSION} can rely on consistent data.
     *
     * @since 1.1.0
     *
     * @param int   $postId Job post identifier.
     * @param array $rows   Row definitions in the format returned by {@see self::buildScheduleRows()}.
     * @return void
     */
    private function persistSchedules($postId, array $rows) {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            throw new RuntimeException('Database connection unavailable.');
        }

        $table = self::getTableName();
        $inTransaction = $this->beginTransaction($wpdb);

        try {
            $deleteSql = $wpdb->prepare('DELETE FROM ' . $table . ' WHERE job_post_id = %d', $postId);
            if ($deleteSql === false) {
                throw new RuntimeException('Failed to prepare delete statement.');
            }

            $deleteResult = $wpdb->query($deleteSql);
            if ($deleteResult === false) {
                throw new RuntimeException('Failed to remove existing schedule rows.');
            }

            if (!empty($rows)) {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $scheduleType = isset($row['schedule_type']) ? (int) $row['schedule_type'] : null;
                    $executionStatus = isset($row['exmoau_execution_status']) ? (int) $row['exmoau_execution_status'] : null;
                    $createdAt = isset($row['created_at']) ? (string) $row['created_at'] : '';
                    $scheduledTimestamp = isset($row['scheduled_timestamp']) ? (int) $row['scheduled_timestamp'] : null;
                    $weekdayKeyRaw = array_key_exists('weekday_key', $row) ? $row['weekday_key'] : null;
                    $timeOfDayRaw = array_key_exists('time_of_day', $row) ? $row['time_of_day'] : null;

                    $hasWeekday = ($weekdayKeyRaw !== null && $weekdayKeyRaw !== '');
                    $hasTime = ($timeOfDayRaw !== null && $timeOfDayRaw !== '');

                    if ($hasWeekday xor $hasTime) {
                        $this->logDebug(
                            'Skipping malformed schedule row for job %d because weekday/time pair was incomplete.',
                            $postId
                        );

                        continue;
                    }

                    $isRecurring = ($hasWeekday && $hasTime);

                    if (!$isRecurring) {
                        if ($scheduledTimestamp === null || $scheduledTimestamp <= 0) {
                            $this->logDebug(
                                'Skipping instant schedule row for job %d because the timestamp was missing or invalid.',
                                $postId
                            );

                            continue;
                        }
                    }

                    if ($scheduleType === null || $executionStatus === null || $createdAt === '') {
                        $this->logDebug(
                            'Skipping malformed schedule row for job %d because required fields were missing.',
                            $postId
                        );

                        continue;
                    }

                    $weekdayKey = null;
                    $timeOfDay = null;
                    $weekdayLabel = '';

                    if ($isRecurring) {
                        $weekdayKey = (int) $weekdayKeyRaw;
                        if ($weekdayKey < 1 || $weekdayKey > 7) {
                            $this->logDebug(
                                'Skipping repeating schedule row for job %d because weekday %s was out of range.',
                                $postId,
                                sanitize_text_field((string) $weekdayKeyRaw)
                            );

                            continue;
                        }

                        $weekdayLabel = array_search($weekdayKey, self::WEEKDAY_NUMBERS, true);
                        if ($weekdayLabel === false) {
                            $weekdayLabel = (string) $weekdayKey;
                        }

                        $timeOfDay = is_string($timeOfDayRaw) ? $this->normalizeTimeValue($timeOfDayRaw) : '';
                        if ($timeOfDay === '') {
                            if (is_scalar($timeOfDayRaw)) {
                                $this->logDebug(
                                    'Skipping repeating schedule row for job %d because time "%s" was invalid.',
                                    $postId,
                                    sanitize_text_field((string) $timeOfDayRaw)
                                );
                            }

                            continue;
                        }
                    }

                    $data = [
                        'job_post_id' => $postId,
                        'schedule_type' => $scheduleType,
                        'exmoau_execution_status' => $executionStatus,
                    ];
                    $formats = ['%d', '%d', '%d'];

                    if ($scheduledTimestamp !== null && $scheduledTimestamp > 0) {
                        $data['scheduled_timestamp'] = $scheduledTimestamp;
                        $formats[] = '%d';
                    }

                    $data['created_at'] = $createdAt;
                    $formats[] = '%s';

                    if ($isRecurring) {
                        $data['weekday_key'] = $weekdayKey;
                        $formats[] = '%d';
                        $data['time_of_day'] = $timeOfDay;
                        $formats[] = '%s';
                    }

                    $result = $wpdb->insert($table, $data, $formats);

                    if (false === $result) {
                        $error = (string) $wpdb->last_error;
                        if ($this->isDuplicateKeyError($error) && $isRecurring) {
                            $weekdayLabelForLog = sanitize_text_field((string) $weekdayLabel);
                            $timeForLog = sanitize_text_field($timeOfDay);
                            $this->logDebug(
                                'Duplicate weekday/time %s %s detected for job %d; unique constraint prevented insertion.',
                                $weekdayLabelForLog,
                                $timeForLog,
                                $postId
                            );

                            continue;
                        }

                        throw new RuntimeException('Failed to insert schedule row: ' . $error);
                    }
                }
            }

            if ($inTransaction) {
                $wpdb->query('COMMIT');
            }
        } catch (Throwable $exception) {
            if ($inTransaction) {
                $wpdb->query('ROLLBACK');
            }

            throw $exception;
        }
    }

    /**
     * Begin a transaction if supported by the database connection.
     *
     * @param wpdb $wpdb Database instance injected from the global {@see $wpdb} reference.
     * @return bool True when the statement executed; false otherwise.
     */
    private function beginTransaction(wpdb $wpdb) {
        $result = $wpdb->query('START TRANSACTION');
        return ($result !== false);
    }

    /**
     * Normalize repeating day selections.
     *
     * @param mixed $days   Raw meta value (string|int|string[]|int[]|null).
     * @param int   $postId Job post identifier for logging.
     * @return string[] Normalized weekday keys keyed sequentially (e.g. mon, tue).
     */
    private function normalizeRepeatingDays($days, $postId) {
        $normalized = [];

        if (!is_array($days)) {
            if ($days !== null) {
                $days = [$days];
            } else {
                $days = [];
            }
        }

        foreach ($days as $day) {
            $dayKey = $this->normalizeWeekdayInput($day);
            if ($dayKey === '') {
                if (is_scalar($day)) {
                    $this->logDebug(
                        'Dropped invalid repeating weekday value "%s" for job %d.',
                        sanitize_text_field((string) $day),
                        $postId
                    );
                }

                continue;
            }

            if (isset($normalized[$dayKey])) {
                $this->logDebug(
                    'Dropped duplicate repeating weekday "%s" for job %d.',
                    $dayKey,
                    $postId
                );

                continue;
            }

            $normalized[$dayKey] = $dayKey;
        }

        return array_values($normalized);
    }

    /**
     * Normalize repeating hour selections keyed by weekday.
     *
     * @param mixed    $hoursRaw Raw hours meta value; supports keyed arrays or arrays of slot arrays.
     * @param string[] $validDays Validated day keys.
     * @param int      $postId    Job post identifier for logging.
     * @return array<string, string[]> Map of weekday keys to unique HH:MM time strings.
     */
    private function normalizeRepeatingHours($hoursRaw, array $validDays, $postId) {
        $result = [];

        if (!is_array($hoursRaw)) {
            return $result;
        }

        if ($this->looksLikeStructuredHours($hoursRaw)) {
            foreach ($hoursRaw as $slot) {
                if (!is_array($slot)) {
                    continue;
                }

                $dayKeyRaw = $slot['weekday_key'] ?? '';
                $timeRaw = $slot['time_of_day'] ?? '';

                $dayKey = $this->normalizeWeekdayInput($dayKeyRaw);
                if ($dayKey === '' || !in_array($dayKey, $validDays, true)) {
                    if (is_scalar($dayKeyRaw)) {
                        $this->logDebug(
                            'Ignoring repeating time block for invalid weekday "%s" on job %d.',
                            sanitize_text_field((string) $dayKeyRaw),
                            $postId
                        );
                    }

                    continue;
                }

                $timeValue = $this->normalizeTimeValue($timeRaw);
                if ($timeValue === '') {
                    if (is_scalar($timeRaw)) {
                        $this->logDebug(
                            'Dropped invalid repeating time "%s" for weekday "%s" on job %d.',
                            sanitize_text_field((string) $timeRaw),
                            $dayKey,
                            $postId
                        );
                    }

                    continue;
                }

                if (!isset($result[$dayKey])) {
                    $result[$dayKey] = [];
                }

                if (in_array($timeValue, $result[$dayKey], true)) {
                    $this->logDebug(
                        'Dropped duplicate repeating time "%s" for weekday "%s" on job %d.',
                        $timeValue,
                        $dayKey,
                        $postId
                    );

                    continue;
                }

                $result[$dayKey][] = $timeValue;
            }

            return $result;
        }

        foreach ($hoursRaw as $day => $times) {
            $dayKey = $this->normalizeWeekdayInput($day);
            if ($dayKey === '' || !in_array($dayKey, $validDays, true)) {
                if (is_scalar($day)) {
                    $this->logDebug(
                        'Ignoring repeating time block for invalid weekday "%s" on job %d.',
                        sanitize_text_field((string) $day),
                        $postId
                    );
                }

                continue;
            }

            if (!is_array($times)) {
                $times = [$times];
            }

            foreach ($times as $time) {
                $timeValue = $this->normalizeTimeValue($time);
                if ($timeValue === '') {
                    if (is_scalar($time)) {
                        $this->logDebug(
                            'Dropped invalid repeating time "%s" for weekday "%s" on job %d.',
                            sanitize_text_field((string) $time),
                            $dayKey,
                            $postId
                        );
                    }

                    continue;
                }

                if (!isset($result[$dayKey])) {
                    $result[$dayKey] = [];
                }

                if (in_array($timeValue, $result[$dayKey], true)) {
                    $this->logDebug(
                        'Dropped duplicate repeating time "%s" for weekday "%s" on job %d.',
                        $timeValue,
                        $dayKey,
                        $postId
                    );

                    continue;
                }

                $result[$dayKey][] = $timeValue;
            }
        }

        return $result;
    }

    /**
     * Convert a stored local datetime into UTC.
     *
     * @param string $value Local datetime string in Y-m-d H:i format.
     * @return DateTimeImmutable|null UTC-normalized datetime or null when parsing fails.
     */
    private function convertLocalStringToUtc($value) {
        $timezone = $this->getSiteTimezone();
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i', $value, $timezone);

        if (!$date) {
            return null;
        }

        $normalized = $date->format('Y-m-d H:i');
        if ($normalized !== $value) {
            $this->logDebug(
                'Adjusted local datetime %s to %s due to timezone normalization.',
                $value,
                $normalized
            );
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Calculate the next scheduled occurrence for a repeating slot.
     *
     * @param string            $dayKey Weekday identifier (mon-sun or numeric 1-7).
     * @param string            $time   24h time string.
     * @param DateTimeImmutable $nowUtc Current UTC time.
     * @return array{timestamp:int,reason:string}|WP_Error Resolved UTC timestamp and selection reason or validation error.
     */
    private function calculateNextOccurrence($dayKey, $time, DateTimeImmutable $nowUtc) {
        $normalizedDayKey = $this->normalizeWeekdayInput($dayKey);
        if ($normalizedDayKey === '' || !isset(self::WEEKDAY_NUMBERS[$normalizedDayKey])) {
            return new WP_Error('repeater_invalid_input', 'Invalid weekday value.');
        }

        $timeValue = $this->normalizeTimeValue($time);
        if ($timeValue === '') {
            return new WP_Error('repeater_invalid_input', 'Invalid time value.');
        }

        $timezone = $this->getSiteTimezone();
        $currentLocal = $nowUtc->setTimezone($timezone);
        $dayNumber = self::WEEKDAY_NUMBERS[$normalizedDayKey];
        $currentDay = (int) $currentLocal->format('N');
        $deltaDays = ($dayNumber - $currentDay + 7) % 7;

        $hour = (int) substr($timeValue, 0, 2);
        $minute = (int) substr($timeValue, 3, 2);

        $dayStart = $currentLocal->setTime(0, 0, 0)->modify('+' . $deltaDays . ' days');
        $candidate = $dayStart->setTime($hour, $minute, 0);
        $reason = 'rule';

        if ($candidate <= $currentLocal) {
            $fallbackCandidate = $this->resolveFallbackOccurrence($dayStart, $hour, $minute, $nowUtc);
            if (is_wp_error($fallbackCandidate)) {
                return $fallbackCandidate;
            }

            if (!empty($fallbackCandidate) && isset($fallbackCandidate['candidate']) && $fallbackCandidate['candidate'] instanceof DateTimeImmutable) {
                $candidate = $fallbackCandidate['candidate'];
                $reason = 'fallback';
            } else {
                $candidate = $candidate->modify('+7 days')->setTime($hour, $minute, 0);
            }
        }

        if ($candidate <= $currentLocal) {
            $candidate = $candidate->modify('+7 days')->setTime($hour, $minute, 0);
            $reason = 'rule';
        }

        $candidateUtc = $candidate->setTimezone(new DateTimeZone('UTC'));
        if ($candidateUtc->getTimestamp() <= $nowUtc->getTimestamp()) {
            return new WP_Error('repeater_no_future_occurrence', 'Unable to determine a future occurrence for the provided slot.');
        }

        if ($candidate->format('H:i') !== $timeValue) {
            $this->logDebug(
                'Adjusted repeating slot %s %s to local time %s due to DST handling.',
                $normalizedDayKey,
                $timeValue,
                $candidate->format('H:i')
            );
        }

        return [
            'timestamp' => $candidateUtc->getTimestamp(),
            'reason'    => $reason,
        ];
    }

    /**
     * Calculate the next UTC timestamp for a stored repeating schedule row.
     *
     * @param int               $weekdayNumber ISO-8601 weekday number (1-7).
     * @param string            $timeOfDay     Stored time-of-day value (HH:MM).
     * @param DateTimeImmutable $nowUtc        Reference UTC timestamp.
     * @return DateTimeImmutable|null UTC datetime for the next occurrence or null when invalid.
     */
    public function calculateNextUtcForScheduleRow($weekdayNumber, $timeOfDay, DateTimeImmutable $nowUtc) {
        $weekdayNumber = (int) $weekdayNumber;
        if ($weekdayNumber < 1 || $weekdayNumber > 7) {
            return null;
        }

        $dayKey = array_search($weekdayNumber, self::WEEKDAY_NUMBERS, true);
        if (!is_string($dayKey) || $dayKey === '') {
            return null;
        }

        $timeValue = is_string($timeOfDay) ? $this->normalizeTimeValue($timeOfDay) : '';
        if ($timeValue === '') {
            return null;
        }

        $calculation = $this->calculateNextOccurrence($dayKey, $timeValue, $nowUtc);
        if (is_wp_error($calculation) || empty($calculation) || !isset($calculation['timestamp'])) {
            return null;
        }

        $timestamp = (int) $calculation['timestamp'];
        if ($timestamp <= 0) {
            return null;
        }

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Handle fall-back overlaps by selecting the first occurrence after now.
     *
     * @param DateTimeImmutable $dayStart Local midnight for the target day.
     * @param int               $hour     Scheduled hour.
     * @param int               $minute   Scheduled minute.
     * @param DateTimeImmutable $nowUtc   Current UTC timestamp.
     * @return array{candidate:DateTimeImmutable}|array{}|WP_Error
     */
    private function resolveFallbackOccurrence(
        DateTimeImmutable $dayStart,
        $hour,
        $minute,
        DateTimeImmutable $nowUtc
    ) {
        $timezone = $this->getSiteTimezone();
        $dayEnd = $dayStart->modify('+1 day');
        $transitions = $timezone->getTransitions($dayStart->getTimestamp(), $dayEnd->getTimestamp());

        if ($transitions === false || !is_array($transitions)) {
            return [];
        }

        $transitionCount = count($transitions);
        if ($transitionCount < 2) {
            return [];
        }

        for ($index = 1; $index < $transitionCount; $index++) {
            $previous = $transitions[$index - 1];
            $current = $transitions[$index];

            if (!is_array($previous) || !is_array($current)) {
                continue;
            }

            if (!isset($previous['offset'], $current['offset'], $current['ts'])) {
                continue;
            }

            if ($current['offset'] >= $previous['offset']) {
                continue;
            }

            $transitionTimestamp = (int) $current['ts'];
            if ($transitionTimestamp <= $nowUtc->getTimestamp()) {
                continue;
            }

            $transitionLocal = (new DateTimeImmutable('@' . $transitionTimestamp))->setTimezone($timezone);
            $transitionMinutes = $this->minutesOfDay($transitionLocal);
            $targetMinutes = ($hour * 60) + $minute;

            if ($targetMinutes < $transitionMinutes) {
                return [];
            }

            $deltaMinutes = $targetMinutes - $transitionMinutes;
            $candidateTimestamp = $transitionTimestamp + ($deltaMinutes * 60);
            $candidateLocal = (new DateTimeImmutable('@' . $candidateTimestamp))->setTimezone($timezone);

            if ((int) $candidateLocal->format('N') !== (int) $dayStart->format('N')) {
                return [];
            }

            return [
                'candidate' => $candidateLocal,
            ];
        }

        return [];
    }

    /**
     * Retrieve the current UTC time.
     *
     * @return DateTimeImmutable
     */
    private function getCurrentUtc() {
        return JobsTimeHelper::getUtcNow();
    }

    /**
     * Retrieve the site timezone.
     *
     * @return DateTimeZone
     */
    private function getSiteTimezone() {
        return JobsTimeHelper::getSiteTimezone();
    }

    /**
     * Get a post meta value as a string.
     *
     * @param int    $postId Post identifier.
     * @param string $metaKey Meta key.
     * @return string
     */
    private function getMetaString($postId, $metaKey) {
        $value = get_post_meta($postId, $metaKey, true);

        if (is_string($value)) {
            return trim($value);
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Get a post meta value as an array.
     *
     * @param int    $postId Post identifier.
     * @param string $metaKey Meta key.
     * @return array
     */
    private function getMetaArray($postId, $metaKey) {
        $value = get_post_meta($postId, $metaKey, true);

        return (is_array($value) ? $value : []);
    }

    /**
     * Normalize weekday identifiers.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    private function normalizeDayKey($value) {
        if (!is_string($value)) {
            return '';
        }

        $key = sanitize_key($value);

        return isset(self::WEEKDAY_NUMBERS[$key]) ? $key : '';
    }

    /**
     * Normalize a weekday input that may be a string key or numeric index.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    private function normalizeWeekdayInput($value) {
        if (is_numeric($value)) {
            $number = (int) $value;
            if ($number >= 1 && $number <= 7) {
                $dayKey = array_search($number, self::WEEKDAY_NUMBERS, true);
                if (is_string($dayKey)) {
                    return $dayKey;
                }
            }

            return '';
        }

        if (is_string($value)) {
            return $this->normalizeDayKey($value);
        }

        return '';
    }

    /**
     * Normalize time strings.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    private function normalizeTimeValue($value) {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^(?:[01]?\d|2[0-3])$/', $value)) {
            $hour = (int) $value;
            return sprintf('%02d:00', $hour);
        }

        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];
            return sprintf('%02d:%02d', $hour, $minute);
        }

        return '';
    }

    /**
     * Determine whether the repeating hours payload is structured as slot arrays.
     *
     * @param mixed $hoursRaw Raw value from post meta; expected either structured slot arrays or keyed day/time arrays.
     * @return bool True when the shape resembles structured slot arrays.
     */
    private function looksLikeStructuredHours($hoursRaw) {
        if (!is_array($hoursRaw) || $hoursRaw === []) {
            return false;
        }

        $first = reset($hoursRaw);
        if (!is_array($first)) {
            return false;
        }

        if (!array_key_exists('weekday_key', $first) && !array_key_exists('time_of_day', $first)) {
            return false;
        }

        foreach ($hoursRaw as $entry) {
            if (!is_array($entry)) {
                return false;
            }

            if (!array_key_exists('weekday_key', $entry) && !array_key_exists('time_of_day', $entry)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if the schedule table exists.
     *
     * @return bool True when the schedule table can be queried.
     */
    private function isTableReady() {
        return self::tableExists();
    }

    /**
     * Determine whether the schedule table exists.
     *
     * @return bool True if the table is present in the {@see wpdb::$prefix} database.
     */
    private static function tableExists() {
        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return false;
        }

        $table = self::getTableName();
        $like = $wpdb->esc_like($table);
        $sql = $wpdb->prepare('SHOW TABLES LIKE %s', $like);
        if ($sql === false) {
            return false;
        }

        $result = $wpdb->get_var($sql);

        return ($result === $table);
    }

    /**
     * Create or update the schedule table schema when required.
     *
     * The routine provisions {@see self::TABLE_NAME} and records
     * {@see self::OPTION_SCHEMA_VERSION} so future migrations can detect
     * structural changes reliably.
     *
     * @since 1.1.0
     *
     * @return void
     */
    private static function maybeUpgradeSchema() {
        if (!self::schemaNeedsUpgrade()) {
            return;
        }

        global $wpdb;

        if (!($wpdb instanceof wpdb)) {
            return;
        }

        $table = self::getTableName();
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = sprintf(
            'CREATE TABLE %1$s (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                job_post_id bigint(20) unsigned NOT NULL,
                schedule_type tinyint(1) unsigned NOT NULL,
                exmoau_execution_status tinyint(1) unsigned NOT NULL DEFAULT 0,
                scheduled_timestamp bigint(20) unsigned DEFAULT NULL,
                weekday_key tinyint(1) unsigned DEFAULT NULL,
                time_of_day char(5) DEFAULT NULL,
                created_at datetime NOT NULL,
                last_executed_at datetime NULL DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY job_post_id (job_post_id),
                KEY scheduled_timestamp (scheduled_timestamp),
                KEY status_scheduled (exmoau_execution_status, scheduled_timestamp),
                KEY idx_weekday (weekday_key),
                KEY idx_time (time_of_day),
                KEY idx_weekday_time (weekday_key, time_of_day),
                UNIQUE KEY uq_post_weekday_time (job_post_id, weekday_key, time_of_day)
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
            $thisInstanceError = sanitize_text_field($exception->getMessage());
            $wpdb->last_error = $previousError;
            self::logSchemaIssue('error', 'Schema upgrade failed during dbDelta execution.', [
                'error'   => $thisInstanceError,
                'table'   => $table,
                'version' => self::SCHEMA_VERSION,
            ]);

            return;
        }

        if ($wpdb->last_error !== '') {
            $sanitizedError = sanitize_text_field($wpdb->last_error);
            $wpdb->last_error = $previousError;

            self::logSchemaIssue('error', 'Schema upgrade encountered a database error.', [
                'error'   => $sanitizedError,
                'table'   => $table,
                'version' => self::SCHEMA_VERSION,
            ]);

            return;
        }

        $wpdb->last_error = $previousError;

        update_option(self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION);
    }

    /**
     * Determine whether the schema requires an upgrade.
     *
     * @return bool True when schema creation or version mismatch requires upgrade.
     */
    private static function schemaNeedsUpgrade() {
        $storedVersion = get_option(self::OPTION_SCHEMA_VERSION, null);

        if ($storedVersion !== self::SCHEMA_VERSION) {
            return true;
        }

        return !self::tableExists();
    }

    /**
     * Log schema upgrade issues to the shared log service.
     *
     * @param string               $level   Log level identifier (debug|info|warning|critical|error).
     * @param string               $message Log message.
     * @param array<string, mixed> $context Contextual data, including schema version or table name when available.
     * @return void
     */
    private static function logSchemaIssue($level, $message, array $context = []) {
        $logger = LogService::getInstance();
        $source = 'jobs.scheduling.schema';

        $logged = false;
        if ($logger instanceof LogService) {
            switch ($level) {
                case 'debug':
                    $logged = $logger->debug($source, $message, $context);
                    break;
                case 'info':
                    $logged = $logger->info($source, $message, $context);
                    break;
                case 'warning':
                    $logged = $logger->warning($source, $message, $context);
                    break;
                case 'critical':
                    $logged = $logger->critical($source, $message, $context);
                    break;
                case 'error':
                default:
                    $logged = $logger->error($source, $message, $context);
                    break;
            }
        }

        if (!$logged && defined('WP_DEBUG') && WP_DEBUG) {
            $payload = [
                'level'   => $level,
                'message' => $message,
                'context' => $context,
            ];

            $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json) && $json !== '') {
                error_log($json); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }
    }

    /**
     * Retrieve the fully-qualified schedule table name.
     *
     * @return string Fully-qualified table name using {@see wpdb::$prefix} or `wp_` fallback.
     */
    public static function getTableName() {
        global $wpdb;

        $prefix = ($wpdb instanceof wpdb) ? $wpdb->prefix : 'wp_';

        return $prefix . self::TABLE_NAME;
    }

    /**
     * Calculate the number of minutes past midnight for a local time.
     *
     * @param DateTimeImmutable $time Date/time instance.
     * @return int
     */
    private function minutesOfDay(DateTimeImmutable $time) {
        return ((int) $time->format('H') * 60) + (int) $time->format('i');
    }

    /**
     * Determine whether an error string represents a duplicate-key violation.
     *
     * @param string $error Database error string.
     * @return bool
     */
    private function isDuplicateKeyError($error) {
        if ($error === '') {
            return false;
        }

        return (
            stripos($error, 'duplicate entry') !== false ||
            stripos($error, 'unique constraint') !== false
        );
    }

    /**
     * Schedule an admin notice describing invalid repeater inputs.
     *
     * @param int $postId Job post identifier.
     * @return void
     */
    private function enqueueInvalidRepeaterNotice($postId) {
        add_action(
            'admin_notices',
            static function () use ($postId) {
                if (!current_user_can('edit_post', $postId)) {
                    return;
                }

                $message = sprintf(
                    /* translators: %d: job post identifier. */
                    esc_html__('Unable to save repeating schedule for job #%d because the provided weekday or time selections were invalid.', 'exmoment-author'),
                    (int) $postId
                );

                echo "<div class='notice notice-error'><p>" . esc_html($message) . '</p></div>';
            }
        );
    }

    /**
     * Emit a concise JSON log when repeater rows are skipped.
     *
     * @param int    $postId Job post identifier.
     * @param string $reason Skip reason identifier.
     * @return void
     */
    private function logRepeaterBuildSkipped($postId, $reason) {
        $sanitizedReason = sanitize_key($reason);
        if ($sanitizedReason === '') {
            $sanitizedReason = 'unknown';
        }

        $jobId = (int) $postId;
        $context = [
            'type'   => 'ExMomentAuthorErrorLog',
            'code'   => 'repeater_build_skipped',
            'job_id' => $jobId,
            'reason' => $sanitizedReason,
            'ts'     => gmdate('c'),
        ];

        $logger = LogService::getInstance();
        if ($logger instanceof LogService) {
            $logger->warning('jobs.scheduling', 'Repeater build skipped during schedule sync.', $context, $jobId);

            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $encoded = wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($encoded) || $encoded === '') {
                $encoded = json_encode($context);
            }

            if (is_string($encoded) && $encoded !== '') {
                error_log($encoded); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }
    }

    /**
     * Log debug messages when WP_DEBUG is enabled.
     *
     * @param string $message Message template.
     * @param mixed  ...$args Message arguments.
     * @return void
     */
    private function logDebug($message, ...$args) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        if (!empty($args)) {
            $message = vsprintf($message, $args);
        }

        $logger = LogService::getInstance();
        if ($logger instanceof LogService) {
            $logger->debug('jobs.scheduling', $message);

            return;
        }

        error_log(sprintf('ExMoment Author Jobs Scheduling: %s', $message));
    }
}
