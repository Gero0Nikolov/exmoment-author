<?php

namespace ExMomentAuthor\Modules\Log;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Render the ExMoment Author Log admin page.
 */
class LogAdminController {

    private const MENU_PARENT = 'tools.php';
    private const MENU_SLUG = 'exmoau-log';
    private const CAPABILITY = 'manage_options';
    private const VIEW_FILE = __DIR__ . '/views/admin-log.php';
    private const PER_PAGE = 20;

    /**
     * Hook into the admin menu registration.
     *
     * @return void
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'registerPage']);
    }

    /**
     * Register the Tools → ExMoment Author Log submenu.
     *
     * @return void
     */
    public function registerPage() {
        add_submenu_page(
            self::MENU_PARENT,
            esc_html_x('ExMoment Author Log', 'admin page title', 'exmoment-author'),
            esc_html_x('ExMoment Author Log', 'admin menu title', 'exmoment-author'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderPage']
        );
    }

    /**
     * Render the log listing page.
     *
     * Outputs the admin view template and triggers database reads for the
     * listing and optional detail view.
     *
     * @return void
     */
    public function renderPage() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'exmoment-author'));
        }

        $service = LogService::getInstance();

        $request = $this->parseRequest($service);
        $listing = $service->listEntries([
            'filters' => $request['service_filters'],
            'page' => $request['page'],
            'per_page' => $request['per_page'],
        ]);

        $entries = $this->formatEntries($listing['entries']);
        $detail = $this->loadDetail($service, $request['detail_id']);

        $viewData = [
            'page_title' => __('ExMoment Author Log', 'exmoment-author'),
            'page_url' => menu_page_url(self::MENU_SLUG, false),
            'filters' => $request['filters'],
            'query_args' => $request['query_args'],
            'levels' => $service->getAllowedLevels(),
            'entries' => $entries,
            'pagination' => [
                'page' => $listing['page'],
                'per_page' => $listing['per_page'],
                'total' => $listing['total'],
                'total_pages' => $listing['total_pages'],
            ],
            'detail' => $detail,
        ];

        if (!file_exists(self::VIEW_FILE)) {
            wp_die(esc_html__('Log view missing. Please reinstall the plugin.', 'exmoment-author'));
        }

        /** @var array<string, mixed> $viewData */
        $data = $viewData;

        include self::VIEW_FILE;
    }

    /**
     * Parse and validate the incoming request parameters.
     *
     * Reads query parameters from $_GET, sanitizes them, and prepares values
     * for database queries.
     *
     * @param LogService $service Log service instance.
     * @return array<string, mixed> Sanitized request parameters and derived values.
     */
    private function parseRequest(LogService $service) {
        $query = [];
        if (!empty($_GET)) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $query = wp_unslash($_GET); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        $allowedLevels = $service->getAllowedLevels();

        $filters = [
            'level' => '',
            'source' => '',
            'job_id' => '',
            'date_from' => '',
            'date_to' => '',
            'search' => '',
        ];

        $serviceFilters = [];

        if (isset($query['level']) && !is_array($query['level'])) {
            $level = strtolower(sanitize_text_field($query['level']));
            if (in_array($level, $allowedLevels, true)) {
                $filters['level'] = $level;
                $serviceFilters['level'] = $level;
            }
        }

        if (isset($query['source']) && !is_array($query['source'])) {
            $source = $this->normalizeSourceFilter($query['source']);
            if ($source !== '') {
                $filters['source'] = $source;
                $serviceFilters['source'] = $source;
            }
        }

        if (isset($query['job_id']) && !is_array($query['job_id'])) {
            $jobId = absint($query['job_id']);
            if ($jobId > 0) {
                $filters['job_id'] = (string) $jobId;
                $serviceFilters['job_id'] = $jobId;
            }
        }

        if (isset($query['date_from']) && !is_array($query['date_from'])) {
            $from = $this->sanitizeDateInput($query['date_from'], false);
            if ($from['value'] !== '') {
                $filters['date_from'] = $from['display'];
                $serviceFilters['date_from'] = $from['value'];
            }
        }

        if (isset($query['date_to']) && !is_array($query['date_to'])) {
            $to = $this->sanitizeDateInput($query['date_to'], true);
            if ($to['value'] !== '') {
                $filters['date_to'] = $to['display'];
                $serviceFilters['date_to'] = $to['value'];
            }
        }

        if (isset($query['search']) && !is_array($query['search'])) {
            $search = $this->sanitizeSearchInput($query['search']);
            if ($search !== '') {
                $filters['search'] = $search;
                $serviceFilters['search'] = $search;
            }
        }

        $page = isset($query['paged']) ? max(1, (int) $query['paged']) : 1;
        $detailId = isset($query['log_id']) ? absint($query['log_id']) : 0;

        $queryArgs = ['page' => self::MENU_SLUG];
        foreach (['level', 'source', 'job_id', 'date_from', 'date_to', 'search'] as $key) {
            if ($filters[$key] !== '') {
                $queryArgs[$key] = $filters[$key];
            }
        }

        return [
            'filters' => $filters,
            'service_filters' => $serviceFilters,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'detail_id' => ($detailId > 0) ? $detailId : null,
            'query_args' => $queryArgs,
        ];
    }

    /**
     * Prepare entries for display.
     *
     * @param array<int, array<string, mixed>> $entries Raw entries.
     * @return array<int, array<string, mixed>>
     */
    private function formatEntries(array $entries) {
        $formatted = [];

        foreach ($entries as $entry) {
            $message = isset($entry['message']) ? (string) $entry['message'] : '';
            $preview = $this->truncate($message, 240);
            if ($preview !== $message) {
                $preview .= '…';
            }

            $formatted[] = [
                'id' => isset($entry['id']) ? (int) $entry['id'] : 0,
                'level' => isset($entry['level']) ? (string) $entry['level'] : '',
                'source' => isset($entry['source']) ? (string) $entry['source'] : '',
                'job_id' => isset($entry['job_id']) && $entry['job_id'] !== null ? (int) $entry['job_id'] : null,
                'message' => $message,
                'preview' => $preview,
                'created_at' => isset($entry['created_at']) ? (string) $entry['created_at'] : '',
            ];
        }

        return $formatted;
    }

    /**
     * Load details for a selected log entry.
     *
     * @param LogService $service  Log service instance.
     * @param int|null   $detailId Requested log identifier.
     * @return array<string, mixed> Entry and formatted context data loaded from the database when found.
     */
    private function loadDetail(LogService $service, $detailId) {
        $detail = [
            'requested' => ($detailId !== null),
            'entry' => null,
            'context' => [
                'type' => 'empty',
                'content' => '',
            ],
        ];

        if ($detailId === null) {
            return $detail;
        }

        $entry = $service->getEntry($detailId);
        if (!is_array($entry)) {
            return $detail;
        }

        $detail['entry'] = $entry;
        $detail['context'] = $this->formatContext($entry['context'] ?? '');

        return $detail;
    }

    /**
     * Normalise the source filter to the stored format.
     *
     * @param mixed $source Raw source filter.
     * @return string
     */
    private function normalizeSourceFilter($source) {
        if (!is_string($source)) {
            $source = (string) $source;
        }

        $source = sanitize_text_field($source);
        $source = strtolower($source);
        $source = preg_replace('/[^a-z0-9._-]/', '_', $source);
        if (!is_string($source)) {
            $source = '';
        }

        return $this->truncate($source, 191);
    }

    /**
     * Sanitize and prepare the search input.
     *
     * @param mixed $search Raw search filter.
     * @return string
     */
    private function sanitizeSearchInput($search) {
        if (!is_string($search)) {
            $search = (string) $search;
        }

        $search = sanitize_text_field($search);

        return $this->truncate($search, 128);
    }

    /**
     * Sanitize a date string and convert it to a MySQL-compatible timestamp.
     *
     * @param mixed $value     Raw date value.
     * @param bool  $endOfDay  Whether to normalise to the end of the day.
     * @return array{display: string, value: string}
     */
    private function sanitizeDateInput($value, $endOfDay) {
        if (!is_string($value)) {
            $value = (string) $value;
        }

        $value = trim($value);

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (!($date instanceof \DateTimeImmutable)) {
            return [
                'display' => '',
                'value' => '',
            ];
        }

        if ($endOfDay) {
            $date = $date->setTime(23, 59, 59);
        } else {
            $date = $date->setTime(0, 0, 0);
        }

        return [
            'display' => $date->format('Y-m-d'),
            'value' => $date->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Prepare the context payload for display.
     *
     * @param string $context Raw context string.
     * @return array{type: string, content: string}
     */
    private function formatContext($context) {
        if ($context === null || $context === '') {
            return [
                'type' => 'empty',
                'content' => '',
            ];
        }

        $decoded = json_decode($context, true);
        if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
            $pretty = wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($pretty) && $pretty !== '') {
                return [
                    'type' => 'json',
                    'content' => $pretty,
                ];
            }
        }

        return [
            'type' => 'text',
            'content' => (string) $context,
        ];
    }

    /**
     * Truncate a string with multibyte safety.
     *
     * @param string $value  Raw string value.
     * @param int    $limit  Maximum length.
     * @return string
     */
    private function truncate($value, $limit) {
        if (!is_string($value)) {
            $value = (string) $value;
        }

        if ($limit <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
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
}
