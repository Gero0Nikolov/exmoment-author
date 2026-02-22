<?php

namespace ExMomentAuthor\Modules\Jobs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use DateTimeImmutable;
use DateTimeZone;

/**
 * Shared helpers for server time and timezone calculations.
 */
class JobsTimeHelper {

    /**
     * Retrieve the current UTC time.
     *
     * @return DateTimeImmutable
     */
    public static function getUtcNow() {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Retrieve the current UTC timestamp.
     *
     * @return int
     */
    public static function getCurrentUtcTimestamp() {
        return self::getUtcNow()->getTimestamp();
    }

    /**
     * Retrieve the canonical timestamp used for WP-Cron calculations.
     *
     * @return int
     */
    public static function asWpCronNow() {
        return self::getCurrentUtcTimestamp();
    }

    /**
     * Retrieve the site timezone configured in WordPress.
     *
     * @return DateTimeZone
     */
    public static function getSiteTimezone() {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        $timezoneString = get_option('timezone_string');
        if (is_string($timezoneString) && $timezoneString !== '') {
            return new DateTimeZone($timezoneString);
        }

        $offset = (float) get_option('gmt_offset', 0);
        $hours = (int) $offset;
        $minutes = (int) round(($offset - $hours) * 60);
        $sign = ($offset >= 0) ? '+' : '-';

        $timezoneId = sprintf('%s%02d:%02d', $sign, abs($hours), abs($minutes));

        return new DateTimeZone($timezoneId);
    }

    /**
     * Retrieve the current time in the site timezone.
     *
     * @return DateTimeImmutable
     */
    public static function getSiteNow() {
        $timezone = self::getSiteTimezone();

        return self::getUtcNow()->setTimezone($timezone);
    }

    /**
     * Retrieve the configured timezone identifier, if available.
     *
     * @return string
     */
    public static function getTimezoneIdentifier() {
        $timezoneString = get_option('timezone_string');
        if (is_string($timezoneString) && $timezoneString !== '') {
            return $timezoneString;
        }

        if (function_exists('wp_timezone_string')) {
            $fallback = wp_timezone_string();
            if (is_string($fallback) && $fallback !== '') {
                return $fallback;
            }
        }

        return '';
    }

    /**
     * Format an offset in seconds as a canonical UTC offset label.
     *
     * @param int $offsetSeconds Offset from UTC in seconds.
     * @return string
     */
    public static function formatOffsetLabel($offsetSeconds) {
        $offsetSeconds = (int) $offsetSeconds;
        $sign = ($offsetSeconds >= 0) ? '+' : '-';
        $absolute = abs($offsetSeconds);
        $hours = (int) floor($absolute / 3600);
        $minutes = (int) floor(($absolute % 3600) / 60);

        return sprintf('UTC%s%02d:%02d', $sign, $hours, $minutes);
    }

    /**
     * Build a payload describing the current server time for display.
     *
     * @return array{
     *     timestamp_utc: int,
     *     local_iso: string,
     *     local_display: string,
     *     offset_seconds: int,
     *     offset_label: string,
     *     timezone_identifier: string,
     *     timezone_display: string,
     *     last_refresh_display: string,
     *     last_refresh_iso: string
     * }
     */
    public static function getDisplayContext() {
        $nowUtc = self::getUtcNow();
        $timezone = self::getSiteTimezone();
        $nowLocal = $nowUtc->setTimezone($timezone);
        $identifier = self::getTimezoneIdentifier();
        $offsetSeconds = (int) $timezone->getOffset($nowUtc);
        $offsetLabel = self::formatOffsetLabel($offsetSeconds);

        $timezoneDisplay = $offsetLabel;
        if ($identifier !== '') {
            $timezoneDisplay = sprintf('%s, %s', $identifier, $offsetLabel);
        }

        return [
            'timestamp_utc'        => $nowUtc->getTimestamp(),
            'local_iso'            => $nowLocal->format('Y-m-d\TH:i:sP'),
            'local_display'        => $nowLocal->format('Y-m-d H:i:s'),
            'offset_seconds'       => $offsetSeconds,
            'offset_label'         => $offsetLabel,
            'timezone_identifier'  => $identifier,
            'timezone_display'     => $timezoneDisplay,
            'last_refresh_display' => $nowLocal->format('Y-m-d H:i:s'),
            'last_refresh_iso'     => $nowLocal->format('Y-m-d\TH:i:sP'),
        ];
    }

    /**
     * Retrieve the ISO-8601 weekday number for a UTC timestamp.
     *
     * @param int|null $timestamp UTC timestamp; defaults to current time.
     * @return int
     */
    public static function getWeekdayUtc($timestamp = null) {
        if ($timestamp === null) {
            $timestamp = self::getCurrentUtcTimestamp();
        }

        $timestamp = (int) $timestamp;
        if ($timestamp <= 0) {
            $timestamp = self::getCurrentUtcTimestamp();
        }

        return (int) gmdate('N', $timestamp);
    }

    /**
     * Format a UTC timestamp into an HH:MM string.
     *
     * @param int $timestamp UTC timestamp reference.
     * @return string
     */
    public static function formatTimeOfDay($timestamp) {
        $timestamp = (int) $timestamp;
        if ($timestamp <= 0) {
            $timestamp = self::getCurrentUtcTimestamp();
        }

        return gmdate('H:i', $timestamp);
    }

    /**
     * Parse an HH:MM string into discrete hour and minute values.
     *
     * @param string $value Input time string.
     * @return array{hour: int, minute: int}|null
     */
    public static function parseTimeOfDay($value) {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return null;
        }

        if (!preg_match('/^(\d{2}):(\d{2})$/', $value, $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return [
            'hour' => $hour,
            'minute' => $minute,
        ];
    }

    /**
     * Determine whether a scheduled UTC timestamp is due relative to the current time.
     *
     * @param int $scheduledUtc Scheduled UTC timestamp.
     * @param int $nowUtc       Current UTC timestamp reference.
     * @param int $grace        Grace window in seconds.
     * @return bool
     */
    public static function isDue($scheduledUtc, $nowUtc, $grace = 60) {
        $scheduledUtc = (int) $scheduledUtc;
        $nowUtc = (int) $nowUtc;
        $grace = (int) $grace;

        if ($scheduledUtc <= 0) {
            return false;
        }

        if ($nowUtc <= 0) {
            $nowUtc = self::getCurrentUtcTimestamp();
        }

        if ($grace < 0) {
            $grace = 0;
        }

        return $scheduledUtc <= ($nowUtc + $grace);
    }
}
