<?php

namespace ExMomentAuthor\Modules\Jobs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use ExMomentAuthor\Modules\Log\LogService;

/**
 * Ensures ExMoment Author jobs cannot be published without mixture categories.
 */
class JobsPublicationValidator {

    private const POST_TYPE = 'exmoau_job';
    private const META_MIXTURE_DIRECTORIES = 'exmoau_setup_mixture_directories';
    private const NOTICE_QUERY_ARG = 'exmoau_job_publish_notice';
    private const NOTICE_TRANSIENT_PREFIX = 'exmoau_job_publish_notice_';
    private const NOTICE_TOKEN_PUBLISH_BLOCKED = 'publish_blocked';
    private const NOTICE_TOKEN_MAX_LENGTH = 64;

    /**
     * Tracks whether a notice has already been queued for the current request.
     *
     * @var bool
     */
    private $noticeQueued = false;

    /**
     * Hook WordPress actions that enforce sanitized mixture selections.
     *
     * Registers publish validation callbacks so directory selections and notice
     * transients are sanitized before they affect request state.
     *
     * @since 1.1.0
     *
     * @param array<string, mixed> $config Optional configuration (unused).
     *
     * @hook wp_insert_post_data 20 Accepts the filtered post data and raw submission.
     * @hook admin_notices        Displays validation feedback in the editor.
     */
    public function __construct(array $config = []) {
        unset($config);

        add_filter('wp_insert_post_data', [$this, 'enforceMixtureSelection'], 20, 2);
        add_action('admin_notices', [$this, 'renderPublishNotice']);
    }

    /**
     * Prevent publishing jobs without at least one mixture category selected.
     *
     * Mixture directory selections are normalized and sanitized before the post
     * is persisted. If validation fails, the post status is forced back to
     * `draft` and an admin notice transient is queued for display.
     *
     * @since 1.1.0
     *
     * @param array<string, mixed> $data    Sanitized post data for insertion.
     * @param array<string, mixed> $postarr Raw submitted post data.
     * @return array<string, mixed> Filtered post data returned to the
     *                              `wp_insert_post_data` filter.
     *
     * @hook wp_insert_post_data Runs before post data is persisted; receives
     *                            filtered data and the raw submission.
     */
    public function enforceMixtureSelection($data, $postarr) {
        if (!is_array($data) || !is_array($postarr)) {
            return $data;
        }

        $postType = $this->resolvePostType($data, $postarr);
        if ($postType !== self::POST_TYPE) {
            return $data;
        }

        $targetStatus = isset($data['post_status']) ? sanitize_key($data['post_status']) : '';
        if ($targetStatus !== 'publish' && $targetStatus !== 'future') {
            return $data;
        }

        if ($this->hasSelectedMixtureDirectories($postarr)) {
            return $data;
        }

        $data['post_status'] = 'draft';

        $postId = isset($postarr['ID']) ? absint($postarr['ID']) : 0;
        $postTitle = isset($data['post_title']) ? (string) $data['post_title'] : '';

        $this->queuePublishNotice($postId);
        $this->logBlockedPublish($postId, $postTitle, $targetStatus);

        return $data;
    }

    /**
     * Display a publish validation notice when present in the request.
     *
     * Transient keys pulled from the request are sanitized prior to loading and
     * deleting the stored message.
     *
     * @since 1.1.0
     *
     * @return void
     *
     * @hook admin_notices Executes in the WordPress admin area before notices
     *                      render on the job editor screen.
     */
    public function renderPublishNotice() {
        if (empty($_GET[self::NOTICE_QUERY_ARG])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->post_type !== self::POST_TYPE) {
            return;
        }

        $token = $this->sanitizeNoticeToken(
            wp_unslash($_GET[self::NOTICE_QUERY_ARG]), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            [self::NOTICE_TOKEN_PUBLISH_BLOCKED]
        );
        if ($token === '') {
            return;
        }

        $transientKey = $this->buildNoticeTransientKey($token);
        if ($transientKey === '') {
            return;
        }

        $message = get_transient($transientKey);
        delete_transient($transientKey);

        if (!is_string($message) || $message === '') {
            return;
        }
        ?>
        <div class="notice notice-error">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }

    /**
     * Determine the post type for the current request.
     *
     * @param array<string, mixed> $data    Filtered post data.
     * @param array<string, mixed> $postarr Raw post data.
     * @return string Post type slug or empty string when unavailable.
     */
    private function resolvePostType(array $data, array $postarr) {
        if (isset($postarr['post_type']) && is_string($postarr['post_type'])) {
            return $postarr['post_type'];
        }

        if (isset($data['post_type']) && is_string($data['post_type'])) {
            return $data['post_type'];
        }

        return '';
    }

    /**
     * Determine whether mixture directories were selected for the submission.
     *
     * @param array<string, mixed> $postarr Raw submitted post data.
     * @return bool True when at least one directory has been provided or
     *              previously saved.
     */
    private function hasSelectedMixtureDirectories(array $postarr) {
        $wasSubmitted = false;
        $directories = [];

        if (array_key_exists(self::META_MIXTURE_DIRECTORIES, $postarr)) {
            $wasSubmitted = true;
            $directories = $this->normalizeDirectorySelection($postarr[self::META_MIXTURE_DIRECTORIES]);
        } elseif (isset($_POST[self::META_MIXTURE_DIRECTORIES])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $postId = isset($postarr['ID']) ? absint($postarr['ID']) : 0;
            if (
                $postId < 1 ||
                !isset($_POST['_wpnonce']) || // phpcs:ignore WordPress.Security.NonceVerification.Missing
                !is_string($_POST['_wpnonce']) || // phpcs:ignore WordPress.Security.NonceVerification.Missing
                !check_admin_referer('update-post_' . $postId, '_wpnonce', false) // phpcs:ignore WordPress.Security.NonceVerification.Missing
            ) {
                return false;
            }

            $wasSubmitted = true;
            $directories = $this->normalizeDirectorySelection(
                wp_unslash($_POST[self::META_MIXTURE_DIRECTORIES]) // phpcs:ignore WordPress.Security.NonceVerification.Missing
            );
        }

        if (!$wasSubmitted) {
            $postId = isset($postarr['ID']) ? absint($postarr['ID']) : 0;
            if ($postId > 0) {
                $stored = get_post_meta($postId, self::META_MIXTURE_DIRECTORIES, true);
                $directories = $this->normalizeDirectorySelection($stored);
            }
        }

        return !empty($directories);
    }

    /**
     * Normalize the incoming directory selection value to a string list.
     *
     * @param mixed $value Raw selection value.
     * @return string[] Array of sanitized directory identifiers.
     */
    private function normalizeDirectorySelection($value) {
        $directories = [];

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!is_string($item)) {
                    continue;
                }

                $item = trim($item);
                if ($item === '') {
                    continue;
                }

                $directories[] = sanitize_text_field($item);
            }
        } elseif (is_string($value)) {
            $value = trim($value);
            if ($value !== '') {
                $directories[] = sanitize_text_field($value);
            }
        }

        if (!empty($directories)) {
            $directories = array_values(array_unique($directories));
        }

        return $directories;
    }

    /**
     * Queue an admin notice informing the user that publishing was blocked.
     *
     * @param int $postId Post identifier, if available.
     * @return void
     */
    private function queuePublishNotice($postId) {
        if ($this->noticeQueued) {
            return;
        }

        $message = __('Please select at least one category under Setup → Mixture before publishing this job.', 'exmoment-author');

        $transientKey = $this->buildNoticeTransientKey(self::NOTICE_TOKEN_PUBLISH_BLOCKED);
        if ($transientKey === '') {
            return;
        }

        set_transient($transientKey, $message, MINUTE_IN_SECONDS);

        if (!headers_sent()) {
            add_filter(
                'redirect_post_location',
                static function ($location) {
                    return add_query_arg(self::NOTICE_QUERY_ARG, self::NOTICE_TOKEN_PUBLISH_BLOCKED, $location);
                }
            );
        }

        $this->noticeQueued = true;
    }

    /**
     * Sanitize and allowlist a notice token from request input.
     *
     * @param mixed $token Raw token input.
     * @param array<int, string> $allowlist Allowed token values.
     * @return string Sanitized token or empty string when invalid.
     */
    private function sanitizeNoticeToken($token, array $allowlist) {
        if (!is_string($token)) {
            return '';
        }

        $token = sanitize_key($token);
        if ($token === '' || strlen($token) > self::NOTICE_TOKEN_MAX_LENGTH) {
            return '';
        }

        return in_array($token, $allowlist, true) ? $token : '';
    }

    /**
     * Build the transient key for a notice token and current user.
     *
     * @param string $token Allowlisted notice token.
     * @return string Transient key or empty string when unavailable.
     */
    private function buildNoticeTransientKey($token) {
        $userId = get_current_user_id();
        if ($userId < 1) {
            return '';
        }

        return self::NOTICE_TRANSIENT_PREFIX . $token . '_' . $userId;
    }

    /**
     * Log the blocked publish attempt when debugging is enabled.
     *
     * @param int    $postId          Post identifier.
     * @param string $postTitle       Post title.
     * @param string $attemptedStatus Intended status before enforcement.
     * @return void
     */
    private function logBlockedPublish($postId, $postTitle, $attemptedStatus) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $postId = absint($postId);
        $postTitle = sanitize_text_field($postTitle);
        $attemptedStatus = sanitize_key($attemptedStatus);

        if ($postTitle === '') {
            $postTitle = '(no title)';
        }

        $message = sprintf(
            '[ExMoment Author] Blocked publish for job %1$d (%2$s) due to missing mixture categories. Target status: %3$s.',
            $postId,
            $postTitle,
            $attemptedStatus
        );

        $logger = LogService::getInstance();
        if ($logger instanceof LogService) {
            $logger->debug('jobs.publication', $message, [
                'job_id'        => $postId,
                'title'         => $postTitle,
                'target_status' => $attemptedStatus,
            ], $postId);

            return;
        }

        error_log($message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }
}
