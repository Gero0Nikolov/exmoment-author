<?php

namespace ExMomentAuthor\Modules\Jobs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use ExMomentAuthor\Core\ExMomentAuthorCoreSystem;
use ExMomentAuthor\Modules\Gpt\GptController;
use ExMomentAuthor\Modules\Library\LibraryController;
use ExMomentAuthor\Modules\Library\UsedArticlesRepository;
use ExMomentAuthor\Modules\Log\LogService;
use ExMomentAuthor\Modules\Seo\YoastSeoIntegration;
use ExMomentAuthor\Modules\Settings\SettingsController;
use Throwable;
use WP_Error;
use WP_Post;
use WP_User;

class JobsExecutionController {

    private const POST_TYPE = 'exmoau_job';
    private const META_JOB_TYPE = 'exmoau_job_type';
    private const META_MIXTURE_DIRECTORIES = 'exmoau_setup_mixture_directories';
    private const META_MIXTURE_UNIQUENESS = 'exmoau_setup_mixture_uniqueness';
    private const META_MIXTURE_PER_CATEGORY = 'exmoau_setup_mixture_per_category';
    private const META_DIRECTIVE_POST_TYPE = 'exmoau_setup_directive_post_type';
    private const META_DIRECTIVE_POST_STATUS = 'exmoau_setup_directive_post_status';
    private const META_DIRECTIVE_POST_AUTHOR = 'exmoau_setup_directive_post_author';
    private const META_DIRECTIVE_GENERATION_COUNT = 'exmoau_setup_directive_generation_count';
    private const META_RESULT_POST_ID = 'exmoau_job_last_result_post_id';
    private const META_EXECUTION_STATUS = 'exmoau_execution_status';

    private const NOTICE_TRANSIENT_PREFIX = 'exmoau_job_run_notice_';
    private const NOTICE_QUERY_ARG = 'exmoau_job_run_notice';
    private const NOTICE_TOKEN_RUN = 'job_run';
    private const NOTICE_TOKEN_MAX_LENGTH = 64;

    private const MIXTURE_PER_CATEGORY_MIN = 1;
    private const MIXTURE_PER_CATEGORY_MAX = 50;
    private const DEFAULT_MIXTURE_PER_CATEGORY = 5;
    private const DEFAULT_DIRECTIVE_GENERATION_COUNT = 1;
    private const MIN_DIRECTIVE_GENERATION_COUNT = 1;

    private const MAX_SOURCE_BYTES = 1048576;
    private const MAX_COMBINED_SOURCE_CHARACTERS = 16000;
    private const MIN_TRUNCATED_SOURCE_CHARACTERS = 600;
    private const REGISTRY_DEBUG_PROBE_LIMIT = 25;

    private const CLOSING_SYSTEM_MESSAGE = 'Produce a single, publication-ready article that synthesizes the provided sources. Do not copy text verbatim. Return exactly one top-level title and the article body only, with no commentary, diagnostics, or visible SEO labels. The response must begin with exactly one top-level title (Markdown: "# <title>" or HTML: "<h1>title</h1>") followed by a blank line, then the article body. Do not repeat the title text anywhere in the body. After the article body, append exactly one hidden metadata block delimited by "===SEO_META_START===" and "===SEO_META_END===" on their own lines. Inside the block include exactly these three single-line entries and nothing else: "SEO_TITLE: <plain title up to 60 characters>", "SEO_DESCRIPTION: <plain description up to 155 characters>", and "FOCUS_KEYPHRASE: <plain concise keyphrase of 2 to 6 words>". Do not use quotes, commentary, examples, extra labels, bullet points, markdown, or additional lines inside the metadata block.';

    private const SEO_META_START = '===SEO_META_START===';
    private const SEO_META_END = '===SEO_META_END===';
    private const MANUAL_RUN_ACTION = 'exmoau_run_job_now';
    private const MANUAL_TRIGGER_KEYS = [
        'exmoau_job_run_now',
    ];

    /**
     * Shared used-articles repository instance.
     *
     * @var UsedArticlesRepository|null
     */
    private $usedArticlesRepository;

    /**
     * Shared library controller instance.
     *
     * @var LibraryController|null
     */
    private $libraryController;

    /**
     * Shared jobs error logger instance.
     *
     * @var JobsErrorController|null
     */
    private $jobsErrorController;

    /**
     * Shared Yoast SEO integration instance.
     *
     * @var YoastSeoIntegration|null
     */
    private $yoastSeoIntegration;

    /**
     * Tracks jobs that already emitted a registry write failure log.
     *
     * @var array<int, bool>
     */
    private $registryWriteFailureLogged = [];

    /**
     * Tracks how many registry probes were logged for the current collection run.
     *
     * @var int
     */
    private $registryProbeCount = 0;

    /**
     * Counts registry matches skipped during directory scanning.
     *
     * @var int
     */
    private $registryScanSkipped = 0;

    /**
     * Cache of registry identity checks performed during directory scanning.
     *
     * @var array<string, array<string, mixed>>
     */
    private $registryScanCache = [];

    /**
     * Whether registry uniqueness enforcement is active for the current run.
     *
     * @var bool
     */
    private $enforceRegistryUniqueness = false;

    /**
     * Whether the used-articles registry table was verified for the current run.
     *
     * @var bool
     */
    private $registryTableVerified = false;

    /**
     * Register WordPress hooks that drive job execution flows.
     *
     * Sets up save-post, manual-run, and admin notice hooks for the job post type. The optional
     * configuration array is currently unused but allows future dependency injection without
     * breaking callers. Hooks are attached early in the request lifecycle so capability checks occur
     * inside each callback rather than here.
     *
     * @param array<string, mixed> $config Optional configuration for dependency overrides; ignored.
     * @return void
     * @since 1.1.0
     *
     * Example:
     * ```
     * $controller = new JobsExecutionController();
     * add_action('init', static function () use ($controller) {
     *     // Hooks are registered via the constructor; no further setup needed.
     * });
     * ```
     */
    public function __construct(array $config = []) {
        unset($config);

        add_action('save_post_' . self::POST_TYPE, [$this, 'maybeRunJobOnSave'], 40, 3);
        add_action(self::MANUAL_RUN_ACTION, [$this, 'handleManualRun'], 10, 2);
        add_action('admin_notices', [$this, 'renderRunNotices']);
    }

    /**
     * Conditionally run a single instant job when the job post is saved.
     *
     * Validates autosave/revision guards, ensures the trigger is appropriate, and then executes the
     * job immediately. The method records contextual notices for success or failure, prevents
     * duplicate execution on repeated publishes, and may create directive posts or update registry
     * metadata through the downstream runner. WordPress enforces edit capabilities before invoking
     * the save hook, so no additional capability check is performed here.
     *
     * @param int     $postId Job post identifier provided by WordPress.
     * @param WP_Post $post   Raw post object; ignored if the type does not match the job post type.
     * @param bool    $update Whether the save is an update instead of a new publish.
     * @return void
     * @since 1.1.0
     * @see JobsExecutionController::runJobNow()
     *
     * Example:
     * ```
     * $post = get_post($job_id);
     * $controller = new JobsExecutionController();
     * $controller->maybeRunJobOnSave($job_id, $post, true);
     * ```
     */
    public function maybeRunJobOnSave($postId, $post, $update) {
        if (!($post instanceof WP_Post)) {
            return;
        }

        $postId = absint($postId);
        if ($postId < 1 || $post->post_type !== self::POST_TYPE) {
            return;
        }

        if (!isset($_POST['_wpnonce'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if (!check_admin_referer('update-post_' . $postId, '_wpnonce', false)) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
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

        if ($post->post_status !== 'publish') {
            return;
        }

        $jobType = $this->normalizeJobType(get_post_meta($postId, self::META_JOB_TYPE, true));
        if ($jobType !== 'single_instant') {
            return;
        }

        $manualRequest = $this->isManualRunRequested();
        $originalStatus = $this->getOriginalPostStatus();

        if (!$manualRequest && $update && $originalStatus === 'publish') {
            return;
        }

        $context = [
            'trigger' => ($manualRequest ? 'manual' : 'publish'),
        ];

        $result = $this->runJobNow($postId, $context);
        $this->handleRunOutcome($postId, $result, $context);
    }

    /**
     * Execute a job immediately when triggered via the manual action hook.
     *
     * Normalises the job identifier with `absint()`, ensures a context array is available, and
     * delegates the actual execution to {@see JobsExecutionController::runJobNow()}. Results are
     * routed through the standard outcome handler to surface admin notices, logging, and transient
     * feedback for the requesting user. The originating UI verifies nonces and capabilities before
     * dispatching the action, so this method assumes the caller is authorised.
     *
     * @param int               $jobId   Job identifier supplied by the action dispatcher.
     * @param array<string,mixed> $context Optional execution context (trigger, actor, etc.).
     * @return void
     * @since 1.1.0
     * @see JobsExecutionController::runJobNow()
     *
     * Example:
     * ```
     * $controller = new JobsExecutionController();
     * do_action('exmoau_run_job_now', $job_id, ['trigger' => 'manual']);
     * ```
     */
    public function handleManualRun($jobId, $context = []) {
        $jobId = absint($jobId);
        if ($jobId < 1) {
            return;
        }

        $context = is_array($context) ? $context : [];
        if (!isset($context['trigger'])) {
            $context['trigger'] = 'manual';
        }

        $result = $this->runJobNow($jobId, $context);
        $this->handleRunOutcome($jobId, $result, $context);
    }

    /**
     * Display contextual admin notices after a job run.
     *
     * Reads the transient referenced in the request query, validates the screen, and prints an
     * escaped WordPress notice. The method deletes the transient immediately after reading to avoid
     * replay attacks or stale messaging. All user input is sanitised via `sanitize_key()` and
     * escaped prior to rendering the notice container. Notice visibility follows the current admin
     * screen capabilities; no further checks are required in this renderer.
     *
     * @return void
     * @since 1.1.0
     *
     * Example:
     * ```
     * $controller = new JobsExecutionController();
     * add_action('admin_notices', [$controller, 'renderRunNotices']);
     * ```
     */
    public function renderRunNotices() {
        if (empty($_GET[self::NOTICE_QUERY_ARG])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $token = $this->sanitizeNoticeToken(
            wp_unslash($_GET[self::NOTICE_QUERY_ARG]), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            [self::NOTICE_TOKEN_RUN]
        );
        if ($token === '') {
            return;
        }

        $transientKey = $this->buildNoticeTransientKey($token);
        if ($transientKey === '') {
            return;
        }

        $notice = get_transient($transientKey);
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }

        delete_transient($transientKey);

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->post_type !== self::POST_TYPE) {
            return;
        }

        $type = isset($notice['type']) ? $notice['type'] : 'success';
        if (!in_array($type, ['success', 'error', 'warning'], true)) {
            $type = 'success';
        }

        $class = 'notice-success';
        if ('error' === $type) {
            $class = 'notice-error';
        } elseif ('warning' === $type) {
            $class = 'notice-warning';
        }
        ?>
        <div class="notice <?php echo esc_attr($class); ?> is-dismissible">
            <p><?php echo esc_html($notice['message']); ?></p>
        </div>
        <?php
    }

    /**
     * Execute a job immediately and return the detailed outcome payload.
     *
     * Validates the job identifier, prepares the execution context, and invokes the internal job
     * runner. Returns either a structured array with execution metadata or a WP_Error when
     * collection or AI preparation fails. The downstream runner may generate directive posts, update
     * job meta, log registry interactions, and write transients. No capabilities are checked here;
     * callers must ensure the current user is permitted to run jobs.
     *
     * @param int                $jobId   Job post identifier to execute.
     * @param array<string,mixed> $context Optional execution context (trigger, request origin, etc.).
     * @return array<string, mixed>|WP_Error Structured result payload or WP_Error on failure.
     * @since 1.1.0
     * @see JobsExecutionController::handleRunOutcome()
     *
     * Example:
     * ```
     * $controller = new JobsExecutionController();
     * $result = $controller->runJobNow($job_id, ['trigger' => 'manual']);
     * if (is_wp_error($result)) {
     *     error_log($result->get_error_message());
     * }
     * ```
     */
    public function runJobNow($jobId, array $context = []) {
        $jobId = absint($jobId);
        if ($jobId < 1) {
            return [
                'success' => false,
                'post_id' => null,
                'error' => esc_html__('Invalid job identifier.', 'exmoment-author'),
            ];
        }

        return $this->runJobGenerations($jobId, $context, ['single_instant']);
    }

    /**
     * Execute a scheduled job triggered via the cron worker.
     *
     * Ensures the trigger context is marked as "schedule" and suppresses admin notices while still
     * persisting execution status meta. Returns the normalized result payload describing the run
     * outcome. Errors are converted to the standard array structure for downstream consumers. This
     * path executes under the WordPress cron runner where capability checks are not applicable.
     *
     * @param int                 $jobId   Job identifier.
     * @param array<string,mixed> $context Optional execution context overrides.
     * @return array<string, mixed>
     * @since 1.1.0
     *
     * Example:
     * ```
     * $controller = new JobsExecutionController();
     * $result = $controller->runScheduledJob($job_id, ['schedule_id' => 15]);
     * ```
     */
    public function runScheduledJob($jobId, array $context = []) {
        $jobId = absint($jobId);
        if ($jobId < 1) {
            return [
                'success' => false,
                'post_id' => null,
                'error' => esc_html__('Invalid job identifier.', 'exmoment-author'),
            ];
        }

        $context = is_array($context) ? $context : [];
        if (!isset($context['trigger'])) {
            $context['trigger'] = 'schedule';
        }

        $context['suppress_notice'] = true;

        $triggerForLog = isset($context['trigger']) ? sanitize_key((string) $context['trigger']) : 'schedule';
        $scheduleTypeForLog = isset($context['schedule_type']) ? sanitize_key((string) $context['schedule_type']) : '';
        $primaryScheduleId = isset($context['schedule_id']) ? (int) $context['schedule_id'] : 0;
        $scheduleIdsForLog = '';

        if (!empty($context['schedule_ids']) && is_array($context['schedule_ids'])) {
            $ids = array_map('intval', $context['schedule_ids']);
            $ids = array_values(array_filter($ids));

            if (!empty($ids)) {
                $limited = array_slice($ids, 0, 10);
                $scheduleIdsForLog = implode(',', $limited);

                if (count($ids) > count($limited)) {
                    $scheduleIdsForLog .= ',…';
                }
            }
        }

        $this->logDebug(
            'Scheduled execution start: job=%d trigger=%s type=%s primary=%d ids=%s',
            $jobId,
            ($triggerForLog !== '' ? $triggerForLog : 'schedule'),
            ($scheduleTypeForLog !== '' ? $scheduleTypeForLog : '(unset)'),
            $primaryScheduleId,
            ($scheduleIdsForLog !== '' ? $scheduleIdsForLog : '(none)')
        );

        $result = $this->runJobGenerations($jobId, $context, ['single_scheduled', 'repeating_scheduled']);

        if ($result instanceof WP_Error) {
            $normalized = [
                'success' => false,
                'post_id' => null,
                'error' => $result->get_error_message(),
            ];

            $this->logDebug(
                'Scheduled execution result: job=%d status=error message=%s',
                $jobId,
                sanitize_text_field($normalized['error'])
            );

            $this->handleRunOutcome($jobId, $normalized, $context);

            return $normalized;
        }

        if (!is_array($result)) {
            $normalized = [
                'success' => false,
                'post_id' => null,
                'error' => esc_html__('The job could not be executed.', 'exmoment-author'),
            ];

            $this->logDebug(
                'Scheduled execution result: job=%d status=invalid message=%s',
                $jobId,
                sanitize_text_field($normalized['error'])
            );

            $this->handleRunOutcome($jobId, $normalized, $context);

            return $normalized;
        }

        if (!empty($result['success'])) {
            $sources = isset($result['used_sources']) && is_array($result['used_sources'])
                ? $result['used_sources']
                : [];
            $metrics = isset($result['used_registry_metrics']) && is_array($result['used_registry_metrics'])
                ? $result['used_registry_metrics']
                : [];

            $this->persistUsedArticles($jobId, $sources, $metrics, $context);

            unset($result['used_sources'], $result['used_registry_metrics']);
        }

        $this->handleRunOutcome($jobId, $result, $context);

        $this->logDebug(
            'Scheduled execution result: job=%d status=%s schedule_type=%s error=%s',
            $jobId,
            (!empty($result['success']) ? 'success' : 'failure'),
            ($scheduleTypeForLog !== '' ? $scheduleTypeForLog : '(unset)'),
            (!empty($result['error']) ? sanitize_text_field((string) $result['error']) : '')
        );

        return $result;
    }

    /**
     * Execute one or more generations for a job and aggregate the results.
     *
     * Iterates through the resolved generation count, executing the job once per iteration while
     * preserving timing, truncation, and source metrics. Terminates early on failure, returning the
     * failing payload with generation markers added. Successful runs accumulate used sources and
     * registry metrics for persistence and reporting. Caller responsibility: capability checks and
     * ensuring the job type is allowed for the provided `$allowedTypes` context.
     *
     * @param int                  $jobId        Job identifier.
     * @param array<string, mixed> $context      Execution context (trigger, request metadata, etc.).
     * @param string[]             $allowedTypes Permitted job types for this invocation.
     * @return array<string, mixed>|WP_Error Structured success/failure payload or WP_Error on fatal error.
     */
    private function runJobGenerations($jobId, array $context, array $allowedTypes) {
        [$generationCount, $generationSource] = $this->resolveDirectiveGenerationCount($jobId, $context);
        $generationCount = max(self::MIN_DIRECTIVE_GENERATION_COUNT, (int) $generationCount);
        $generationSource = ($generationSource === 'directive') ? 'directive' : 'default';

        $this->logGenerationResolution($jobId, $generationCount, $generationSource);

        $finalResult = null;
        $aggregatedSources = [];
        $aggregatedMetrics = [];
        $sawSourcesKey = false;
        $sawMetricsKey = false;
        $totalDuration = 0.0;
        $totalSources = 0;
        $totalSkippedLarge = 0;
        $totalSkippedInvalid = 0;
        $totalSkippedUsed = 0;
        $invalidSamples = [];
        $anyTruncated = false;
        $generationRuns = [];

        for ($index = 1; $index <= $generationCount; $index++) {
            $iterationContext = $context;
            $iterationContext['generation_iteration'] = $index;
            $iterationContext['generation_total'] = $generationCount;
            $iterationContext['generation_source'] = $generationSource;

            $result = $this->executeJob($jobId, $iterationContext, $allowedTypes);

            if ($result instanceof WP_Error) {
                return $result;
            }

            if (!is_array($result)) {
                return $result;
            }

            if (empty($result['success'])) {
                if (!isset($result['generation_iteration'])) {
                    $result['generation_iteration'] = $index;
                }
                $result['generation_total'] = $generationCount;
                if (!isset($result['generation_runs'])) {
                    $result['generation_runs'] = $generationRuns;
                }

                return $result;
            }

            $finalResult = $result;

            $totalDuration += isset($result['duration']) ? (float) $result['duration'] : 0.0;
            $totalSources += isset($result['sources']) ? (int) $result['sources'] : 0;
            $totalSkippedLarge += isset($result['skipped_large_files']) ? (int) $result['skipped_large_files'] : 0;
            $totalSkippedInvalid += isset($result['skipped_invalid_files']) ? (int) $result['skipped_invalid_files'] : 0;
            $totalSkippedUsed += isset($result['skipped_used_files']) ? (int) $result['skipped_used_files'] : 0;
            $anyTruncated = $anyTruncated || !empty($result['truncated']);

            if (!empty($result['skipped_invalid_sample']) && is_array($result['skipped_invalid_sample'])) {
                foreach ($result['skipped_invalid_sample'] as $sample) {
                    if (!is_string($sample) || $sample === '') {
                        continue;
                    }
                    $invalidSamples[] = $sample;
                }
                $invalidSamples = array_values(array_unique($invalidSamples));
                if (count($invalidSamples) > 3) {
                    $invalidSamples = array_slice($invalidSamples, 0, 3);
                }
            }

            if (array_key_exists('used_sources', $result) && is_array($result['used_sources'])) {
                $aggregatedSources = array_merge($aggregatedSources, $result['used_sources']);
                $sawSourcesKey = true;
            }

            if (array_key_exists('used_registry_metrics', $result) && is_array($result['used_registry_metrics'])) {
                foreach ($result['used_registry_metrics'] as $metricKey => $metricValue) {
                    if (!is_numeric($metricValue)) {
                        continue;
                    }
                    if (!isset($aggregatedMetrics[$metricKey])) {
                        $aggregatedMetrics[$metricKey] = 0;
                    }
                    $aggregatedMetrics[$metricKey] += (int) $metricValue;
                }
                $sawMetricsKey = true;
            }

            $generationRuns[] = [
                'iteration' => $index,
                'post_id' => isset($result['post_id']) ? (int) $result['post_id'] : 0,
                'duration' => isset($result['duration']) ? (float) $result['duration'] : 0.0,
                'sources' => isset($result['sources']) ? (int) $result['sources'] : 0,
            ];
        }

        if ($finalResult === null) {
            return [
                'success' => false,
                'post_id' => null,
                'error' => esc_html__('The job could not be executed.', 'exmoment-author'),
            ];
        }

        $finalResult['duration'] = $totalDuration;
        $finalResult['sources'] = $totalSources;
        $finalResult['truncated'] = $anyTruncated;
        $finalResult['skipped_large_files'] = $totalSkippedLarge;
        $finalResult['skipped_invalid_files'] = $totalSkippedInvalid;
        $finalResult['skipped_invalid_sample'] = $invalidSamples;
        $finalResult['skipped_used_files'] = $totalSkippedUsed;

        if ($sawSourcesKey) {
            $finalResult['used_sources'] = $aggregatedSources;
        }

        if ($sawMetricsKey) {
            $finalResult['used_registry_metrics'] = $aggregatedMetrics;
        }

        if ($generationCount > 1) {
            $finalResult['generation_runs'] = $generationRuns;
            $finalResult['generation_runs_total'] = $generationCount;
        }

        $finalResult['generation_source'] = $generationSource;
        $finalResult['generation_count'] = $generationCount;

        return $finalResult;
    }

    /**
     * Determine how many generations to run and which source (directive vs. default) provided it.
     *
     * Reads the directive-specific meta override when the request originates from a directive
     * generation and falls back to the global default. Returns a tuple containing the numeric count
     * and a label describing the origin. The method does not clamp values; callers must enforce
     * minimums.
     *
     * @param int                  $jobId   Job identifier.
     * @param array<string, mixed> $context Execution context passed into the runner.
     * @return array{0:int|float,1:string} Two-item list of [count, source label].
     */
    private function resolveDirectiveGenerationCount($jobId, array $context = []) {
        $jobId = absint($jobId);
        $count = self::DEFAULT_DIRECTIVE_GENERATION_COUNT;
        $source = 'default';

        if (isset($context['generation_count']) && is_numeric($context['generation_count'])) {
            $count = (int) $context['generation_count'];
            if ($count >= self::MIN_DIRECTIVE_GENERATION_COUNT) {
                $source = 'directive';
            }
        } elseif (method_exists(LibraryController::class, 'resolveGenerationCount')) {
            $resolvedSource = null;
            $resolved = LibraryController::resolveGenerationCount($jobId, $resolvedSource);
            if (is_array($resolved) && isset($resolved['count'])) {
                $count = (int) $resolved['count'];
                if (isset($resolved['source']) && is_string($resolved['source'])) {
                    $resolvedSource = $resolved['source'];
                }
            } else {
                $count = (int) $resolved;
            }

            if (is_string($resolvedSource)) {
                $source = $resolvedSource;
            } elseif ($count >= self::MIN_DIRECTIVE_GENERATION_COUNT) {
                $source = 'directive';
            }
        } else {
            $stored = get_post_meta($jobId, self::META_DIRECTIVE_GENERATION_COUNT, true);
            if (is_numeric($stored)) {
                $count = (int) $stored;
                if ($count >= self::MIN_DIRECTIVE_GENERATION_COUNT) {
                    $source = 'directive';
                }
            }
        }

        if ($count < self::MIN_DIRECTIVE_GENERATION_COUNT) {
            $count = self::MIN_DIRECTIVE_GENERATION_COUNT;
            if ($source === 'directive') {
                $source = 'default';
            }
        }

        if ($source !== 'directive') {
            $source = 'default';
        }

        return [$count, $source];
    }

    /**
     * Emit a debug entry summarising resolved generation count for a directive.
     *
     * @param int    $directiveId Directive/job identifier.
     * @param int    $count       Normalised generation count.
     * @param string $source      Source label describing which configuration supplied the count.
     * @return void
     */
    private function logGenerationResolution($directiveId, $count, $source) {
        $directiveId = absint($directiveId);
        $count = max(self::MIN_DIRECTIVE_GENERATION_COUNT, (int) $count);
        $label = ($source === 'directive') ? 'directive' : 'default 1';

        $this->logDebug(
            'Directive %d resolved generation count %d using %s value.',
            $directiveId,
            $count,
            $label
        );
    }

    /**
     * Run a single job generation and return the result payload.
     *
     * Validates the job type, prepares collection constraints, gathers library sources, sanitises
     * messages, invokes the GPT controller, and writes the generated article. Returns either a
     * structured array describing the execution or a WP_Error when preparation fails. Admin notices
     * are handled by upstream callers.
     *
     * @param int                  $jobId        Job identifier being executed.
     * @param array<string, mixed> $context      Execution context propagated through the run.
     * @param string[]             $allowedTypes Allowed job types for this run.
     * @return array<string, mixed>|WP_Error
     */
    private function executeJob($jobId, array $context, array $allowedTypes = ['single_instant']) {
        $startTime = microtime(true);
        $this->resetRegistryWriteFailureState($jobId);
        $this->enforceRegistryUniqueness = false;
        $this->registryTableVerified = false;
        $result = [
            'success' => false,
            'post_id' => null,
            'error' => '',
            'model' => '',
            'sources' => 0,
            'categories' => 0,
            'truncated' => false,
            'per_article_limit' => 0,
            'skipped_large_files' => 0,
            'skipped_invalid_files' => 0,
            'skipped_invalid_sample' => [],
            'skipped_used_files' => 0,
            'duration' => 0.0,
            'post_status' => '',
        ];

        $job = get_post($jobId);
        if (!($job instanceof WP_Post) || $job->post_type !== self::POST_TYPE) {
            $result['error'] = esc_html__('The requested job could not be found.', 'exmoment-author');
            return $result;
        }

        $jobType = $this->normalizeJobType(get_post_meta($jobId, self::META_JOB_TYPE, true));
        if (!in_array($jobType, $allowedTypes, true)) {
            $trigger = '';
            if (isset($context['trigger'])) {
                $trigger = sanitize_key((string) $context['trigger']);
            }

            $this->logDebug(
                'Job %d skipped due to type %s not allowed for trigger %s.',
                $jobId,
                $jobType,
                ($trigger !== '' ? $trigger : 'manual')
            );

            $result['error'] = esc_html__('This job cannot be executed in the current mode.', 'exmoment-author');
            return $result;
        }

        $isScheduledMode = in_array($jobType, ['single_scheduled', 'repeating_scheduled'], true);

        $configuration = $this->fetchJobConfiguration($jobId);
        if ($configuration['directories'] === []) {
            $result['error'] = esc_html__('Select at least one library category before running the job.', 'exmoment-author');
            return $result;
        }

        if ($configuration['post_type'] === '' || !post_type_exists($configuration['post_type']) || !post_type_supports($configuration['post_type'], 'editor')) {
            $result['error'] = esc_html__('The configured directive post type is invalid or does not support content.', 'exmoment-author');
            return $result;
        }

        $postStatus = $configuration['post_status'];
        if ($postStatus !== 'publish') {
            $postStatus = 'draft';
        }
        $configuration['post_status'] = $postStatus;

        $authorId = $configuration['post_author'];
        if ($authorId < 1) {
            $result['error'] = esc_html__('Select a valid post author before running the job.', 'exmoment-author');
            return $result;
        }

        $author = get_user_by('id', $authorId);
        if (!($author instanceof WP_User) || !$this->userMeetsAuthorThreshold($authorId)) {
            $result['error'] = esc_html__('The configured post author is not eligible to publish content.', 'exmoment-author');
            return $result;
        }

        $libraryRoot = $this->resolveLibraryRoot();
        if ($libraryRoot === '') {
            $result['error'] = esc_html__('The ExMoment Author Library directory is unavailable.', 'exmoment-author');
            return $result;
        }

        $this->enforceRegistryUniqueness = $configuration['unique_only'];

        $usedRepository = null;
        if ($this->enforceRegistryUniqueness) {
            $usedRepository = $this->getUsedArticlesRepository();

            if ($usedRepository instanceof UsedArticlesRepository) {
                $this->registryTableVerified = $usedRepository->ensureRegistryTable();

                if (!$this->registryTableVerified) {
                    $this->logDebug(
                        'Registry uniqueness requested for job %d, but the used articles table could not be verified. '
                        . 'Registry checks are disabled for this run.',
                        $jobId
                    );
                }
            } else {
                $this->logDebug(
                    'Registry uniqueness requested for job %d, but the used articles repository is unavailable. '
                    . 'Registry checks are disabled for this run.',
                    $jobId
                );
            }
        }

        if (!($usedRepository instanceof UsedArticlesRepository)) {
            $usedRepository = $this->getUsedArticlesRepository();
        }

        if (!$this->enforceRegistryUniqueness || !($usedRepository instanceof UsedArticlesRepository)) {
            $this->registryTableVerified = false;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $tableName = ($usedRepository instanceof UsedArticlesRepository)
                ? $usedRepository->getRegistryTableName()
                : '';

            $this->logDebug(
                'Registry flags: job=%d, enforce=%s, verified=%s, table=%s, scanner=%s.',
                $jobId,
                ($this->enforceRegistryUniqueness ? 'yes' : 'no'),
                ($this->registryTableVerified ? 'yes' : 'no'),
                ($tableName !== '' ? $tableName : '(unknown)'),
                ($this->enforceRegistryUniqueness && $this->registryTableVerified ? 'on' : 'off')
            );
        }

        $this->logDebug(
            'Job %d triggered with %d categor%s, %d articles per category, unique=%s, registry=%s.',
            $jobId,
            count($configuration['directories']),
            (count($configuration['directories']) === 1 ? 'y' : 'ies'),
            $configuration['per_category'],
            ($configuration['unique_only'] ? 'yes' : 'no'),
            ($this->enforceRegistryUniqueness ? 'yes' : 'no')
        );

        $collection = $this->collectSources(
            $libraryRoot,
            $configuration['directories'],
            $configuration['per_category'],
            $configuration['unique_only'],
            $jobId
        );

        if (is_wp_error($collection)) {
            return $collection;
        }

        if (empty($collection['articles'])) {
            if (!empty($collection['skipped_invalid_files'])) {
                $result['error'] = esc_html__('All selected sources were invalid after sanitization.', 'exmoment-author');
            } else {
                $result['error'] = esc_html__('No articles were found for the selected categories.', 'exmoment-author');
            }
            return $result;
        }

        $articles = $collection['articles'];
        $result['sources'] = count($articles);
        $result['categories'] = $collection['categories'];
        $result['truncated'] = (bool) $collection['truncated'];
        $result['per_article_limit'] = (int) $collection['per_article_limit'];
        $result['skipped_large_files'] = (int) $collection['skipped_large_files'];
        $result['skipped_invalid_files'] = (int) $collection['skipped_invalid_files'];
        $result['skipped_invalid_sample'] = isset($collection['skipped_invalid_sample']) && is_array($collection['skipped_invalid_sample'])
            ? $collection['skipped_invalid_sample']
            : [];
        $result['skipped_used_files'] = isset($collection['skipped_used_files'])
            ? (int) $collection['skipped_used_files']
            : 0;

        if ($result['skipped_invalid_files'] > 0) {
            $this->logDebug(
                'Job %d skipped %d invalid source file(s) during normalization.',
                $jobId,
                $result['skipped_invalid_files']
            );
        }

        if ($result['skipped_used_files'] > 0) {
            $this->logDebug(
                'Job %d skipped %d previously used source file(s).',
                $jobId,
                $result['skipped_used_files']
            );
        }

        $aiConfiguration = SettingsController::getEffectiveAiConfiguration();
        $systemPrompt = is_string($aiConfiguration['system_prompt'] ?? '') ? $aiConfiguration['system_prompt'] : '';
        $model = is_string($aiConfiguration['model'] ?? '') ? $aiConfiguration['model'] : SettingsController::getDefaultAiModel();

        if ($systemPrompt === '') {
            $systemPrompt = SettingsController::getAutonomousSystemPrompt();
        }

        if (!is_string($model) || $model === '') {
            $model = SettingsController::getDefaultAiModel();
        }

        $result['model'] = $model;

        $sanitizedSystemPrompt = $this->sanitizeMessageContent($systemPrompt);
        if ($sanitizedSystemPrompt === '') {
            $this->logDebug('Job %d aborted: the system prompt could not be normalized to UTF-8.', $jobId);
            $result['error'] = esc_html__('The AI prompt could not be prepared safely.', 'exmoment-author');
            return $result;
        }

        $messages = $this->buildMessages($sanitizedSystemPrompt, $articles);

        if (empty($messages)) {
            $this->logDebug('Job %d aborted: no valid messages were produced for the AI request.', $jobId);
            $result['error'] = esc_html__('No valid sources were available after sanitization.', 'exmoment-author');
            return $result;
        }

        $messages = $this->sanitizeMessages($messages);

        if (empty($messages)) {
            $this->logDebug('Job %d aborted: message sanitization removed all payload entries.', $jobId);
            $result['error'] = esc_html__('The AI prompt could not be prepared safely.', 'exmoment-author');
            return $result;
        }

        $userMessages = array_filter($messages, static function ($message) {
            return is_array($message) && isset($message['role']) && $message['role'] === 'user';
        });

        if (empty($userMessages)) {
            $this->logDebug('Job %d aborted: the payload did not contain any user messages after sanitization.', $jobId);
            $result['error'] = esc_html__('No valid sources were available after sanitization.', 'exmoment-author');
            return $result;
        }

        $encodedMessages = wp_json_encode($messages);
        if (!is_string($encodedMessages) || $encodedMessages === '') {
            $this->logDebug('Job %d aborted: the message payload could not be encoded as JSON.', $jobId);
            $result['error'] = esc_html__('The AI prompt could not be prepared safely.', 'exmoment-author');
            return $result;
        }
        unset($encodedMessages);

        $debugModeEnabled = SettingsController::isGptDebugModeEnabled();
        $apiKey = SettingsController::getOption('openai_api_key');
        $apiKey = is_string($apiKey) ? trim($apiKey) : '';
        if ($apiKey === '' && !$debugModeEnabled) {
            $result['error'] = esc_html__('Configure the OpenAI API key before running jobs.', 'exmoment-author');
            return $result;
        }

        $controller = $this->instantiateGptController($apiKey);
        if (!($controller instanceof GptController)) {
            $result['error'] = esc_html__('Unable to communicate with the AI model at this time.', 'exmoment-author');
            return $result;
        }

        $weightKey = SettingsController::getOpenAiWeightKey();

        try {
            $response = $controller->chatCompletionCreate($messages, $weightKey, [], $model);
        } catch (Throwable $exception) {
            $this->logDebug('GPT invocation threw an exception: %s', $exception->getMessage());
            $result['error'] = esc_html__('The AI request failed unexpectedly. Please try again.', 'exmoment-author');
            return $result;
        }

        $diagnostics = $controller->getLastChatCompletionDiagnostics();
        if (is_string($response)) {
            if (is_array($diagnostics)) {
                $this->logDebug(
                    'GPT API error for job %d (model %s): %s',
                    $jobId,
                    $model,
                    isset($diagnostics['error_message']) ? (string) $diagnostics['error_message'] : $response
                );
            }
            $result['error'] = esc_html__('The AI service rejected the request. Please review the configuration.', 'exmoment-author');
            return $result;
        }

        $content = $this->extractResponseContent($response);
        if ($content === '') {
            $result['error'] = esc_html__('The AI response was empty or invalid.', 'exmoment-author');
            return $result;
        }

        $parsed = $this->parseArticleResponse($content);
        $title = is_string($parsed['title'] ?? '') ? $parsed['title'] : '';
        $body = is_string($parsed['body'] ?? '') ? $parsed['body'] : '';
        $seoMeta = (isset($parsed['seo_meta']) && is_array($parsed['seo_meta'])) ? $parsed['seo_meta'] : array();

        $invalidSeoFields = isset($seoMeta['invalid_fields']) && is_array($seoMeta['invalid_fields'])
            ? $seoMeta['invalid_fields']
            : array();

        if (!empty($invalidSeoFields)) {
            $encodedInvalidFields = wp_json_encode($invalidSeoFields);
            if (is_string($encodedInvalidFields) && $encodedInvalidFields !== '') {
                $this->logDebug('Job %d skipped invalid SEO fields: %s', $jobId, $encodedInvalidFields);
            }
        }

        if ($body === '') {
            $result['error'] = esc_html__('The AI response did not contain article content.', 'exmoment-author');
            return $result;
        }

        $insertResult = $this->createPost(
            $title,
            $body,
            $configuration['post_type'],
            $configuration['post_status'],
            $authorId
        );

        if (is_wp_error($insertResult)) {
            $this->logDebug('Failed to create post for job %d: %s', $jobId, $insertResult->get_error_message());
            $result['error'] = esc_html__('The generated article could not be saved.', 'exmoment-author');
            return $result;
        }

        $postId = (int) $insertResult;
        $this->storeBackReference($jobId, $postId);
        $this->maybePopulateYoastSeoMeta($postId, $seoMeta);
        $this->maybeGenerateFeaturedImage($controller, $postId);

        $duration = microtime(true) - $startTime;
        $result['success'] = true;
        $result['post_id'] = $postId;
        $result['duration'] = $duration;
        $result['post_status'] = $configuration['post_status'];

        $sourcesForRegistry = isset($collection['used_sources']) && is_array($collection['used_sources'])
            ? $collection['used_sources']
            : [];
        $metrics = isset($collection['metrics']) && is_array($collection['metrics'])
            ? $collection['metrics']
            : [];

        $result['used_sources'] = $sourcesForRegistry;
        $result['used_registry_metrics'] = $metrics;

        if (!$isScheduledMode) {
            $this->persistUsedArticles($jobId, $sourcesForRegistry, $metrics, $context);
            unset($result['used_sources'], $result['used_registry_metrics']);
        }

        $this->logDebug(
            'Job %d created post %d (status=%s, model=%s, duration=%.2fs, sources=%d, truncated=%s).',
            $jobId,
            $postId,
            $configuration['post_status'],
            $model,
            $duration,
            $result['sources'],
            ($result['truncated'] ? 'yes' : 'no')
        );

        if ($result['skipped_large_files'] > 0) {
            $this->logDebug('Job %d skipped %d oversized source file(s).', $jobId, $result['skipped_large_files']);
        }

        return $result;
    }

    /**
     * Retrieve and sanitize configuration for a job post.
     *
     * @param int $jobId Job identifier.
     * @return array{directories:string[],unique_only:bool,per_category:int,post_type:string,post_status:string,post_author:int}
     */
    private function fetchJobConfiguration($jobId) {
        $directories = get_post_meta($jobId, self::META_MIXTURE_DIRECTORIES, true);
        $directories = is_array($directories) ? $directories : [];

        $sanitizedDirectories = [];
        foreach ($directories as $directory) {
            if (!$this->isSafeCategoryName($directory)) {
                continue;
            }
            $sanitizedDirectories[] = trim((string) $directory);
        }

        $uniqueOnly = get_post_meta($jobId, self::META_MIXTURE_UNIQUENESS, true);
        $perCategory = get_post_meta($jobId, self::META_MIXTURE_PER_CATEGORY, true);
        $perCategory = is_numeric($perCategory) ? (int) $perCategory : self::DEFAULT_MIXTURE_PER_CATEGORY;
        if ($perCategory < self::MIXTURE_PER_CATEGORY_MIN) {
            $perCategory = self::MIXTURE_PER_CATEGORY_MIN;
        } elseif ($perCategory > self::MIXTURE_PER_CATEGORY_MAX) {
            $perCategory = self::MIXTURE_PER_CATEGORY_MAX;
        }

        $postType = get_post_meta($jobId, self::META_DIRECTIVE_POST_TYPE, true);
        $postType = is_string($postType) ? sanitize_key($postType) : '';

        $postStatus = get_post_meta($jobId, self::META_DIRECTIVE_POST_STATUS, true);
        $postStatus = is_string($postStatus) ? sanitize_key($postStatus) : 'draft';
        if ($postStatus !== 'publish') {
            $postStatus = 'draft';
        }

        $authorValue = get_post_meta($jobId, self::META_DIRECTIVE_POST_AUTHOR, true);
        $postAuthor = is_numeric($authorValue) ? (int) $authorValue : 0;

        return [
            'directories' => array_values(array_unique($sanitizedDirectories)),
            'unique_only' => ($uniqueOnly === '1'),
            'per_category' => $perCategory,
            'post_type' => $postType,
            'post_status' => $postStatus,
            'post_author' => $postAuthor,
        ];
    }

    /**
     * Collect eligible source files for a job run.
     *
     * Scans the requested library categories, normalises paths, validates uniqueness against the
     * used-articles registry, enforces per-category and payload-size limits, and returns
     * sanitised article content. When no content remains (e.g., registry exhaustion), a WP_Error is
     * returned to stop execution.
     *
     * @param string   $libraryRoot  Absolute library root path.
     * @param string[] $directories  Selected category slugs.
     * @param int      $perCategory  File limit per category.
     * @param bool     $uniqueOnly   Whether duplicates within the run are disallowed.
     * @param int      $jobId        Current job identifier.
     * @return array{
     *     articles: array<int, array{category:string,filename:string,path:string,content:string}>,
     *     categories: int,
     *     truncated: bool,
     *     per_article_limit: int,
     *     skipped_large_files: int,
     *     skipped_invalid_files: int,
     *     skipped_invalid_sample: array<int, string>,
     *     skipped_used_files: int,
     *     used_sources: array<int, array{path:string,directory:string,filename:string}>,
     *     metrics: array<string, int>
     * }|WP_Error Structured collection payload or WP_Error when no content is available.
     */
    private function collectSources($libraryRoot, array $directories, $perCategory, $uniqueOnly, $jobId) {
        $this->registryProbeCount = 0;
        $this->registryScanSkipped = 0;
        $this->registryScanCache = [];

        $articles = [];
        $processedCategories = 0;
        $skippedLarge = 0;
        $seenHashes = [];
        $seenNormalizedPaths = [];
        $invalidSources = [];
        $skippedUsed = 0;
        $exhaustedDirectories = [];
        $candidatesBefore = 0;
        $excludedForUniqueness = 0;
        $registryPassed = 0;

        $enforceRegistryUniqueness = ($this->enforceRegistryUniqueness === true);
        $registryTableVerified = ($this->registryTableVerified === true);
        $usedRepository = ($this->usedArticlesRepository instanceof UsedArticlesRepository)
            ? $this->usedArticlesRepository
            : null;

        $registryDebugEnabled = $enforceRegistryUniqueness
            && $registryTableVerified
            && defined('WP_DEBUG')
            && WP_DEBUG;
        $registryTableName = ($registryDebugEnabled && $usedRepository instanceof UsedArticlesRepository)
            ? $usedRepository->getRegistryTableName()
            : '';
        $registryScanBaseline = $this->getRegistryScanSkippedCount();

        foreach ($directories as $directory) {
            $categoryPath = $this->resolveCategoryPath($libraryRoot, $directory);
            if ($categoryPath === '' || !is_dir($categoryPath) || !is_readable($categoryPath)) {
                $this->logDebug('Skipping missing or unreadable category %s for job library.', $directory);
                continue;
            }

            $processedCategories++;
            $addedFromDirectory = false;
            $usedInDirectory = 0;
            $files = $this->selectCategoryFiles(
                $categoryPath,
                $perCategory,
                $directory
            );
            $scanAfter = $this->getRegistryScanSkippedCount();
            $earlySkippedInDirectory = max(0, $scanAfter - $registryScanBaseline);
            if ($earlySkippedInDirectory > 0) {
                $skippedUsed += $earlySkippedInDirectory;
                $usedInDirectory += $earlySkippedInDirectory;
                $excludedForUniqueness += $earlySkippedInDirectory;
                $candidatesBefore += $earlySkippedInDirectory;
            }
            $registryScanBaseline = $scanAfter;

            foreach ($files as $file) {
                $candidatesBefore++;
                $path = $file['path'];
                $normalizedPath = '';
                $pathHash = '';
                $registryCheckedDuringScan = false;

                if (isset($this->registryScanCache[$path]) && is_array($this->registryScanCache[$path])) {
                    $cacheEntry = $this->registryScanCache[$path];
                    if (isset($cacheEntry['normalized_path']) && is_string($cacheEntry['normalized_path'])) {
                        $normalizedPath = $cacheEntry['normalized_path'];
                    }
                    if (isset($cacheEntry['hash']) && is_string($cacheEntry['hash'])) {
                        $pathHash = $cacheEntry['hash'];
                    }
                    $registryCheckedDuringScan = !empty($cacheEntry['checked']);
                }

                if ($usedRepository instanceof UsedArticlesRepository && ($pathHash === '' || $normalizedPath === '')) {
                    $identity = $usedRepository->resolveIdentity($path);
                    if (is_array($identity)) {
                        if ($normalizedPath === '' && isset($identity['path']) && is_string($identity['path'])) {
                            $normalizedPath = $identity['path'];
                        }
                        if ($pathHash === '' && isset($identity['hash']) && is_string($identity['hash'])) {
                            $pathHash = $identity['hash'];
                        }
                    }
                }

                if ($normalizedPath === '') {
                    $normalizedPath = $this->normalizePath($path);
                    if ($normalizedPath === '') {
                        $normalizedPath = $path;
                    }
                }

                if ($enforceRegistryUniqueness && $registryTableVerified && $usedRepository instanceof UsedArticlesRepository) {
                    if ($registryCheckedDuringScan) {
                        $registryPassed++;
                        if ($registryDebugEnabled) {
                            $this->logRegistryProbe(
                                $registryTableName,
                                $path,
                                $normalizedPath,
                                $pathHash,
                                false,
                                'scanner-pass'
                            );
                        }
                    } else {
                        $alreadyUsed = false;
                        $reason = 'hash-miss';

                        if ($pathHash !== '') {
                            $alreadyUsed = $usedRepository->isHashUsed($pathHash);
                            $reason = $alreadyUsed ? 'hash-match' : 'hash-miss';
                        } else {
                            $alreadyUsed = $usedRepository->isUsed($path);
                            $reason = $alreadyUsed ? 'path-match' : 'path-miss';
                        }

                        if ($registryDebugEnabled) {
                            $this->logRegistryProbe(
                                $registryTableName,
                                $path,
                                $normalizedPath,
                                $pathHash,
                                $alreadyUsed,
                                $reason
                            );
                        }

                        if ($alreadyUsed) {
                            $skippedUsed++;
                            $usedInDirectory++;
                            $excludedForUniqueness++;
                            continue;
                        }

                        $registryPassed++;
                    }
                }

                if ($uniqueOnly) {
                    if ($pathHash !== '') {
                        if (isset($seenHashes[$pathHash])) {
                            continue;
                        }

                        $seenHashes[$pathHash] = true;
                    } else {
                        $dedupeKey = $normalizedPath !== '' ? $normalizedPath : $path;
                        if (isset($seenNormalizedPaths[$dedupeKey])) {
                            continue;
                        }

                        $seenNormalizedPaths[$dedupeKey] = true;
                    }
                }

                $size = @filesize($path);
                if ($size !== false && $size > self::MAX_SOURCE_BYTES) {
                    $skippedLarge++;
                    continue;
                }

                $content = $this->readArticleContent($path);
                if ($content === '') {
                    continue;
                }

                $normalizedContent = $this->sanitizeMessageContent($content);
                if ($normalizedContent === '') {
                    $invalidSources[] = $this->formatSourceLabel($directory, $file['name']);
                    $this->logDebug('Skipping source %s/%s due to invalid encoding.', $directory, $file['name']);
                    continue;
                }

                $articles[] = [
                    'category' => $directory,
                    'filename' => $file['name'],
                    'path' => $path,
                    'content' => $normalizedContent,
                ];

                $addedFromDirectory = true;
            }

            if ($enforceRegistryUniqueness && !$addedFromDirectory && $usedInDirectory > 0) {
                $exhaustedDirectories[] = $directory;
            }
        }

        $budget = $this->enforcePayloadBudget($articles);

        if (!empty($budget['removed_invalid'])) {
            foreach ($budget['removed_invalid'] as $removed) {
                if (!is_string($removed) || $removed === '') {
                    continue;
                }
                $invalidSources[] = $removed;
            }
        }

        $articles = isset($budget['articles']) && is_array($budget['articles']) ? $budget['articles'] : [];
        $selectedForRegistry = [];

        foreach ($articles as $article) {
            if (!isset($article['path']) || !is_string($article['path']) || $article['path'] === '') {
                continue;
            }

            $selectedForRegistry[] = [
                'path' => $article['path'],
                'directory' => isset($article['category']) ? $article['category'] : '',
                'filename' => isset($article['filename']) ? $article['filename'] : '',
            ];
        }

        $invalidSources = array_values(array_unique(array_filter($invalidSources)));

        if ($enforceRegistryUniqueness && empty($articles) && $skippedUsed > 0) {
            $directoriesToLog = !empty($exhaustedDirectories) ? $exhaustedDirectories : $directories;
            $directoriesToLog = array_values(array_unique(array_filter($directoriesToLog, 'is_string')));

            $directoryForLog = '';
            if (!empty($directoriesToLog)) {
                $directoryForLog = (string) reset($directoriesToLog);
            }

            $this->logNoAvailableContent($jobId, $directoryForLog);

            return new WP_Error(
                'exmoau_no_available_content',
                esc_html__('No eligible content after uniqueness filtering.', 'exmoment-author')
            );
        }

        if ($registryDebugEnabled) {
            $this->logDebug(
                'Registry summary: table=%s, candidates=%d, skipped_used=%d, passed_new=%d.',
                ($registryTableName !== '' ? $registryTableName : '(unknown)'),
                $candidatesBefore,
                $skippedUsed,
                $registryPassed
            );
        }

        return [
            'articles' => $articles,
            'categories' => $processedCategories,
            'truncated' => $budget['truncated'],
            'per_article_limit' => $budget['per_article'],
            'skipped_large_files' => $skippedLarge,
            'skipped_invalid_files' => count($invalidSources),
            'skipped_invalid_sample' => array_slice($invalidSources, 0, 3),
            'skipped_used_files' => $skippedUsed,
            'used_sources' => $selectedForRegistry,
            'metrics' => [
                'candidates_before' => $candidatesBefore,
                'excluded_for_uniqueness' => $excludedForUniqueness,
                'selected' => count($selectedForRegistry),
            ],
        ];
    }

    /**
     * Retrieve the shared used-articles repository instance.
     *
     * @return UsedArticlesRepository|null
     */
    private function getUsedArticlesRepository() {
        if ($this->usedArticlesRepository instanceof UsedArticlesRepository) {
            return $this->usedArticlesRepository;
        }

        $core = ExMomentAuthorCoreSystem::getInstance();
        $repository = $core->getModule('UsedArticlesRepository');

        if (!($repository instanceof UsedArticlesRepository)) {
            $core->autoload();
            $repository = $core->getModule('UsedArticlesRepository');
        }

        if ($repository instanceof UsedArticlesRepository) {
            $this->usedArticlesRepository = $repository;
        } else {
            $this->usedArticlesRepository = null;
        }

        return $this->usedArticlesRepository;
    }

    /**
     * Retrieve the shared library controller instance.
     *
     * @return LibraryController|null
     */
    private function getLibraryController() {
        if ($this->libraryController instanceof LibraryController) {
            return $this->libraryController;
        }

        $core = ExMomentAuthorCoreSystem::getInstance();
        $controller = $core->getModule('LibraryController');

        if (!($controller instanceof LibraryController)) {
            $core->autoload();
            $controller = $core->getModule('LibraryController');
        }

        if ($controller instanceof LibraryController) {
            $this->libraryController = $controller;
        } else {
            $this->libraryController = null;
        }

        return $this->libraryController;
    }

    /**
     * Retrieve the shared jobs error controller instance.
     *
     * @return JobsErrorController|null
     */
    private function getJobsErrorController() {
        if ($this->jobsErrorController instanceof JobsErrorController) {
            return $this->jobsErrorController;
        }

        $core = ExMomentAuthorCoreSystem::getInstance();
        $controller = $core->getModule('JobsErrorController');

        if (!($controller instanceof JobsErrorController)) {
            $core->autoload();
            $controller = $core->getModule('JobsErrorController');
        }

        if ($controller instanceof JobsErrorController) {
            $this->jobsErrorController = $controller;
        } else {
            $this->jobsErrorController = null;
        }

        return $this->jobsErrorController;
    }

    /**
     * Retrieve the shared Yoast SEO integration instance.
     *
     * @return YoastSeoIntegration|null
     */
    private function getYoastSeoIntegration() {
        if ($this->yoastSeoIntegration instanceof YoastSeoIntegration) {
            return $this->yoastSeoIntegration;
        }

        $core = ExMomentAuthorCoreSystem::getInstance();
        $integration = $core->getModule('YoastSeoIntegration');

        if (!($integration instanceof YoastSeoIntegration)) {
            $core->autoload();
            $integration = $core->getModule('YoastSeoIntegration');
        }

        if ($integration instanceof YoastSeoIntegration) {
            $this->yoastSeoIntegration = $integration;
        } else {
            $this->yoastSeoIntegration = null;
        }

        return $this->yoastSeoIntegration;
    }

    /**
     * Log the no-available-content state via the shared logger.
     *
     * @param int    $jobId     Job identifier.
     * @param string $directory Directory slug or label.
     * @return void
     */
    private function logNoAvailableContent($jobId, $directory) {
        $logger = $this->getJobsErrorController();

        if ($logger instanceof JobsErrorController) {
            $logger->logNoAvailableContent($jobId, $directory);
        }
    }

    /**
     * Record a structured log entry for registry write failures once per job.
     *
     * @param int                    $jobId      Job identifier.
     * @param array<string, string>  $source     Selected source metadata.
     * @param UsedArticlesRepository $repository Registry repository instance.
     * @param WP_Error               $error      Failure details.
     * @return void
     */
    private function handleRegistryWriteFailure($jobId, array $source, UsedArticlesRepository $repository, WP_Error $error) {
        $jobKey = $this->normalizeRegistryJobKey($jobId);

        if (isset($this->registryWriteFailureLogged[$jobKey])) {
            return;
        }

        $this->registryWriteFailureLogged[$jobKey] = true;

        $path = isset($source['path']) ? (string) $source['path'] : '';
        $filename = isset($source['filename']) ? (string) $source['filename'] : '';

        $this->logDebug(
            'Unable to record used article %s for job %d: %s',
            $path,
            $jobId,
            $error->get_error_message()
        );

        $logger = $this->getJobsErrorController();
        if (!($logger instanceof JobsErrorController)) {
            return;
        }

        $pathHash = $repository->getPathHash($path);
        $logger->logUsedRegistryWriteFailure($jobId, $filename, $pathHash);
    }

    /**
     * Persist used article metadata after a successful job execution via the library layer.
     *
     * @param int                                $jobId   Job identifier.
     * @param array<int, array<string, mixed>>   $sources Selected source metadata.
     * @param array<string, int>                 $metrics Collection metrics for logging.
     * @param array<string, mixed>               $context Optional execution context forwarded to telemetry.
     * @return array<string, mixed>|null
     */
    private function persistUsedArticles($jobId, array $sources, array $metrics = [], array $context = []) {
        $library = $this->getLibraryController();

        if (!($library instanceof LibraryController)) {
            $this->logDebug(
                'Library controller unavailable; skipping used article persistence for job %d.',
                absint($jobId)
            );

            return null;
        }

        $summary = $library->storeUsedArticles($jobId, $sources, $metrics, $context);
        if (!is_array($summary)) {
            return null;
        }

        $jobId = absint($jobId);
        $candidates = isset($summary['candidates']) ? (int) $summary['candidates'] : 0;
        $excluded = isset($summary['excluded']) ? (int) $summary['excluded'] : 0;
        $selected = isset($summary['selected']) ? (int) $summary['selected'] : count($sources);
        $written = isset($summary['written']) ? (int) $summary['written'] : 0;
        $duplicates = isset($summary['duplicates']) ? (int) $summary['duplicates'] : 0;
        $skipped = isset($summary['skipped']) ? (int) $summary['skipped'] : 0;

        $this->logDebug(
            'Job %d registry stats: candidates=%d, excluded=%d, selected=%d, written=%d, idempotent=%d, skipped=%d.',
            $jobId,
            $candidates,
            $excluded,
            $selected,
            $written,
            $duplicates,
            $skipped
        );

        if (!empty($summary['errors']) && is_array($summary['errors'])) {
            $repository = $this->getUsedArticlesRepository();

            foreach ($summary['errors'] as $error) {
                $errorObject = isset($error['error']) && $error['error'] instanceof WP_Error
                    ? $error['error']
                    : null;
                $source = isset($error['source']) && is_array($error['source']) ? $error['source'] : [];

                if (!($errorObject instanceof WP_Error)) {
                    continue;
                }

                if ($repository instanceof UsedArticlesRepository) {
                    $this->handleRegistryWriteFailure($jobId, $source, $repository, $errorObject);
                } else {
                    $this->logDebug(
                        'Used article persistence error for job %d: %s',
                        $jobId,
                        $errorObject->get_error_message()
                    );
                }
            }
        }

        return $summary;
    }

    /**
     * Reset the per-job write failure flag when beginning execution.
     *
     * @param int $jobId Job identifier.
     * @return void
     */
    private function resetRegistryWriteFailureState($jobId) {
        $jobKey = $this->normalizeRegistryJobKey($jobId);

        if (isset($this->registryWriteFailureLogged[$jobKey])) {
            unset($this->registryWriteFailureLogged[$jobKey]);
        }
    }

    /**
     * Normalise job identifiers for use as registry failure keys.
     *
     * @param int $jobId Job identifier.
     * @return int
     */
    private function normalizeRegistryJobKey($jobId) {
        $jobId = absint($jobId);

        if ($jobId < 1) {
            return 0;
        }

        return $jobId;
    }

    /**
     * Choose a subset of files from a category directory respecting skip rules and limits.
     *
     * @param string $categoryPath Absolute filesystem path to the category directory.
     * @param int    $limit        Maximum number of files to return.
     * @param string $categorySlug Optional sanitized category slug for logging.
     * @return array<int, array{name:string,path:string,size:int}>
     */
    private function selectCategoryFiles($categoryPath, $limit, $categorySlug = '') {
        $limit = max(1, (int) $limit);
        $filenames = [];
        $pathMap = [];
        $categorySlug = is_string($categorySlug) ? $categorySlug : '';

        try {
            $iterator = new \DirectoryIterator($categoryPath);
        } catch (\UnexpectedValueException $exception) {
            $this->logDebug('Unable to iterate category directory %s: %s', $categoryPath, $exception->getMessage());
            return [];
        }

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $name = $fileInfo->getFilename();
            $path = $fileInfo->getPathname();

            if ($this->shouldSkipFile($name, $path, $categorySlug)) {
                continue;
            }

            $filenames[] = $name;
            $filenames = array_values(array_unique($filenames));
            natcasesort($filenames);
            $filenames = array_slice(array_values($filenames), 0, $limit);

            $pathMap[$name] = $path;
            foreach (array_keys($pathMap) as $storedName) {
                if (!in_array($storedName, $filenames, true)) {
                    unset($pathMap[$storedName]);
                }
            }
        }

        $selected = [];
        foreach ($filenames as $name) {
            $path = isset($pathMap[$name]) ? $pathMap[$name] : ($categoryPath . DIRECTORY_SEPARATOR . $name);
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $selected[] = [
                'name' => $name,
                'path' => $path,
            ];
        }

        usort($selected, static function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        return $selected;
    }

    /**
     * Read and normalize article contents from a path, returning UTF-8 text or an empty string.
     *
     * @param string $path Absolute file path.
     * @return string
     */
    private function readArticleContent($path) {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return '';
        }

        return $this->normalizeArticleContent($contents);
    }

    /**
     * Normalize raw article contents by trimming, collapsing whitespace, and enforcing length limits.
     *
     * @param string $contents Raw file contents.
     * @return string
     */
    private function normalizeArticleContent($contents) {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        if (!is_string($contents)) {
            $contents = '';
        }

        $contents = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $contents);
        if (!is_string($contents)) {
            $contents = '';
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $contents);
        $normalized = wp_strip_all_tags($normalized);
        $normalized = preg_replace('/\n{3,}/', "\n\n", $normalized);
        $normalized = preg_replace('/[ \t]{2,}/', ' ', $normalized);

        if (!is_string($normalized)) {
            $normalized = $contents;
        }

        return trim($normalized);
    }

    /**
     * Enforce the aggregate payload budget across collected articles.
     *
     * @param array<int, array{category:string,filename:string,path:string,content:string}> $articles
     * @return array{articles: array<int, array{category:string,filename:string,path:string,content:string}>, per_article:int, t
runcated: bool, removed_invalid: array<int, string>}
     */
    private function enforcePayloadBudget(array $articles) {
        if (empty($articles)) {
            return [
                'articles' => [],
                'truncated' => false,
                'per_article' => 0,
                'removed_invalid' => [],
            ];
        }

        $budget = self::MAX_COMBINED_SOURCE_CHARACTERS;
        $total = 0;
        foreach ($articles as $article) {
            $total += strlen(isset($article['content']) ? (string) $article['content'] : '');
        }

        $count = count($articles);
        $perArticle = 0;
        $truncated = false;

        if ($budget > 0 && $total > $budget) {
            $perArticle = (int) floor($budget / max(1, $count));
            if ($perArticle < self::MIN_TRUNCATED_SOURCE_CHARACTERS) {
                $perArticle = self::MIN_TRUNCATED_SOURCE_CHARACTERS;
            }
        }

        $removedInvalid = [];
        foreach ($articles as &$article) {
            if (!isset($article['content'])) {
                continue;
            }

            if ($perArticle > 0) {
                $length = strlen($article['content']);
                if ($length > $perArticle) {
                    $article['content'] = $this->truncateContent($article['content'], $perArticle);
                    $truncated = true;
                }
            }

            $article['content'] = $this->sanitizeMessageContent($article['content']);
            if ($article['content'] === '') {
                $removedInvalid[] = $this->formatSourceLabel(
                    isset($article['category']) ? $article['category'] : '',
                    isset($article['filename']) ? $article['filename'] : ''
                );
            }
        }
        unset($article);

        if (!empty($removedInvalid)) {
            $articles = array_values(array_filter($articles, static function ($article) {
                return isset($article['content']) && $article['content'] !== '';
            }));
        }

        return [
            'articles' => $articles,
            'truncated' => $truncated,
            'per_article' => $perArticle,
            'removed_invalid' => array_values(array_unique(array_filter($removedInvalid))),
        ];
    }

    /**
     * Truncate content to a UTF-8-safe boundary while preserving sentence-like breaks when possible.
     *
     * @param string $content Content to truncate.
     * @param int    $limit   Maximum character length.
     * @return string
     */
    private function truncateContent($content, $limit) {
        if ($limit <= 0) {
            return '';
        }

        if (function_exists('mb_strcut')) {
            $truncated = mb_strcut($content, 0, $limit, 'UTF-8');
        } else {
            $truncated = substr($content, 0, $limit);
        }

        $breakpoint = strrpos($truncated, "\n\n");
        if ($breakpoint === false) {
            $breakpoint = strrpos($truncated, "\n");
        }
        if ($breakpoint === false) {
            $breakpoint = strrpos($truncated, ' ');
        }

        if ($breakpoint !== false && $breakpoint >= (int) floor($limit * 0.6)) {
            $truncated = substr($truncated, 0, $breakpoint);
        }

        return rtrim($truncated);
    }

    /**
     * Build the chat-completion message payload using the system prompt and collected articles.
     *
     * @param string                                                     $systemPrompt Normalized system prompt.
     * @param array<int, array{category:string,filename:string,content:string}> $articles     Prepared articles.
     * @return array<int, array{role:string,content:string}>
     */
    private function buildMessages($systemPrompt, array $articles) {
        $messages = [];

        $opening = $this->sanitizeMessageContent($systemPrompt);
        if ($opening === '') {
            return [];
        }

        $messages[] = [
            'role' => 'system',
            'content' => $opening,
        ];

        foreach ($articles as $article) {
            $category = isset($article['category']) ? sanitize_text_field($article['category']) : '';
            $filename = isset($article['filename']) ? sanitize_text_field($article['filename']) : '';
            $content = isset($article['content']) ? (string) $article['content'] : '';

            if ($content === '') {
                continue;
            }

            $composed = sprintf(
                "Source: %s/%s\n\n%s",
                $category,
                $filename,
                $content
            );

            $sanitized = $this->sanitizeMessageContent($composed);
            if ($sanitized === '') {
                $this->logDebug('Dropping source %s/%s after final message sanitization.', $category, $filename);
                continue;
            }

            $messages[] = [
                'role' => 'user',
                'content' => $sanitized,
            ];
        }

        $closing = $this->sanitizeMessageContent(self::CLOSING_SYSTEM_MESSAGE);
        if ($closing === '') {
            return [];
        }

        $messages[] = [
            'role' => 'system',
            'content' => $closing,
        ];

        return $messages;
    }

    /**
     * Sanitize chat-completion messages ensuring UTF-8 content and valid roles.
     *
     * @param array<int, array<string, mixed>> $messages Message payload prior to sanitization.
     * @return array<int, array{role:string,content:string}>
     */
    private function sanitizeMessages(array $messages) {
        $sanitized = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = isset($message['role']) ? sanitize_key($message['role']) : '';
            $content = isset($message['content']) ? $this->sanitizeMessageContent($message['content']) : '';

            if ($role === '' || $content === '') {
                continue;
            }

            $sanitized[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $sanitized;
    }

    /**
     * Normalize message content to UTF-8 and strip control characters.
     *
     * @param string|scalar|mixed $value Potentially non-string input.
     * @return string
     */
    private function sanitizeMessageContent($value) {
        if (!is_string($value)) {
            if (is_scalar($value)) {
                $value = (string) $value;
            } else {
                return '';
            }
        }

        if ($value === '') {
            return '';
        }

        $detected = false;
        if (function_exists('mb_detect_encoding')) {
            $detected = @mb_detect_encoding($value, ['UTF-8', 'ISO-8859-1', 'ISO-8859-15', 'Windows-1252'], true);
        }

        if ($detected && is_string($detected) && strtoupper($detected) !== 'UTF-8') {
            if (function_exists('iconv')) {
                $converted = @iconv($detected, 'UTF-8//IGNORE', $value);
                if (is_string($converted) && $converted !== '') {
                    $value = $converted;
                }
            } elseif (in_array($detected, ['ISO-8859-1', 'ISO-8859-15'], true)) {
                if (function_exists('mb_convert_encoding')) {
                    $converted = @mb_convert_encoding($value, 'UTF-8', $detected);
                    if (is_string($converted) && $converted !== '') {
                        $value = $converted;
                    } else {
                        // Replace deprecated utf8_encode() with WordPress-safe invalid UTF-8 cleanup.
                        $value = wp_check_invalid_utf8($value, true);
                    }
                } else {
                    // Replace deprecated utf8_encode() with WordPress-safe invalid UTF-8 cleanup.
                    $value = wp_check_invalid_utf8($value, true);
                }
            }
        } elseif (!$detected && function_exists('iconv')) {
            $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        }

        if (!is_string($value) || $value === '') {
            return '';
        }

        $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value);
        if (!is_string($value)) {
            $value = '';
        }

        if ($value === '') {
            return '';
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        if (!is_string($value)) {
            $value = '';
        }

        if ($value === '') {
            return '';
        }

        if (!$this->isValidUtf8($value) && function_exists('iconv')) {
            $value = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        }

        if (!is_string($value) || $value === '' || !$this->isValidUtf8($value)) {
            return '';
        }

        return $value;
    }

    /**
     * Determine whether a value is valid UTF-8 text.
     *
     * @param mixed $value Value to check.
     * @return bool
     */
    private function isValidUtf8($value) {
        if (!is_string($value)) {
            return false;
        }

        if ($value === '') {
            return true;
        }

        if (function_exists('mb_check_encoding')) {
            return @mb_check_encoding($value, 'UTF-8');
        }

        return (bool) preg_match('//u', $value);
    }

    /**
     * Compose a human-readable label for a source file.
     *
     * @param string $category Category slug.
     * @param string $filename File name.
     * @return string
     */
    private function formatSourceLabel($category, $filename) {
        $category = is_string($category) ? sanitize_text_field($category) : '';
        $filename = is_string($filename) ? sanitize_text_field($filename) : '';

        if ($category !== '' && $filename !== '') {
            return $category . '/' . $filename;
        }

        if ($filename !== '') {
            return $filename;
        }

        if ($category !== '') {
            return $category;
        }

        return '';
    }

    /**
     * Initialise the GPT controller with the provided API key if available.
     *
     * @param string|null $apiKey OpenAI API key from settings; may be empty when debug mode is active.
     * @return GptController|null
     */
    private function instantiateGptController($apiKey) {
        $apiKey = (is_string($apiKey) ? trim($apiKey) : '');

        if ($apiKey === '') {
            return null;
        }

        $core = ExMomentAuthorCoreSystem::getInstance();

        $controller = $core->getModule('GptController');

        if (!($controller instanceof GptController) && method_exists($core, 'autoload')) {
            $core->autoload();
            $controller = $core->getModule('GptController');
        }

        if (!($controller instanceof GptController)) {
            $this->logDebug('GPT module is offline; unable to initialise job execution.');

            return null;
        }

        if (method_exists($controller, 'setApiKey')) {
            $configured = $controller->setApiKey($apiKey);

            if (!$configured) {
                $this->logDebug('GPT module rejected the supplied API key during job execution.');

                return null;
            }
        }

        return $controller;
    }

    /**
     * Extract the assistant content string from a chat completion response array.
     *
     * @param array<string, mixed>|null $response Raw response from GptController.
     * @return string
     */
    private function extractResponseContent($response) {
        if (!is_object($response) || !isset($response->choices) || !is_array($response->choices) || $response->choices === []) {
            return '';
        }

        $choice = $response->choices[0] ?? null;
        if (!is_object($choice)) {
            return '';
        }

        $message = $choice->message ?? null;
        if (!is_object($message) || !isset($message->content) || !is_string($message->content)) {
            return '';
        }

        return trim($message->content);
    }

    /**
     * Parse the AI response into title, body, and SEO metadata components.
     *
     * @param string $content Raw AI response text.
     * @return array{
     *     title:string,
     *     body:string,
     *     seo_meta:array{
     *         seo_title:string,
     *         seo_description:string,
     *         focus_keyphrase:string,
     *         invalid_fields:array<string, string>
     *     }
     * }
     */
    private function parseArticleResponse($content) {
        $content = is_string($content) ? $content : '';

        if ($content === '') {
            return array(
                'title' => '',
                'body' => '',
                'seo_meta' => array(
                    'seo_title' => '',
                    'seo_description' => '',
                    'focus_keyphrase' => '',
                    'invalid_fields' => array(),
                ),
            );
        }

        $seoExtraction = $this->extractSeoMetadata($content);
        $titleAndBody = $this->extractTitleAndBody($seoExtraction['content']);

        return array(
            'title' => $titleAndBody['title'],
            'body' => $titleAndBody['body'],
            'seo_meta' => array(
                'seo_title' => $seoExtraction['seo_title'],
                'seo_description' => $seoExtraction['seo_description'],
                'focus_keyphrase' => $seoExtraction['focus_keyphrase'],
                'invalid_fields' => $seoExtraction['invalid_fields'],
            ),
        );
    }

    /**
     * Extract SEO metadata block from the AI response body.
     *
     * @param string $content AI response body.
     * @return array{
     *     content:string,
     *     seo_title:string,
     *     seo_description:string,
     *     focus_keyphrase:string,
     *     invalid_fields:array<string, string>
     * }
     */
    private function extractSeoMetadata($content) {
        $content = is_string($content) ? $content : '';

        if ($content === '') {
            return array(
                'content' => '',
                'seo_title' => '',
                'seo_description' => '',
                'focus_keyphrase' => '',
                'invalid_fields' => array(),
            );
        }

        $metaStart = strpos($content, self::SEO_META_START);
        $metaEnd = strpos($content, self::SEO_META_END);

        if ($metaStart === false || $metaEnd === false || $metaEnd <= $metaStart) {
            return array(
                'content' => trim($content),
                'seo_title' => '',
                'seo_description' => '',
                'focus_keyphrase' => '',
                'invalid_fields' => array(),
            );
        }

        $metaBodyStart = $metaStart + strlen(self::SEO_META_START);
        $metaBodyLength = $metaEnd - $metaBodyStart;
        $metaBody = substr($content, $metaBodyStart, $metaBodyLength);

        $rawFields = array(
            'SEO_TITLE' => null,
            'SEO_DESCRIPTION' => null,
            'FOCUS_KEYPHRASE' => null,
        );
        $invalidFields = array();

        if (is_string($metaBody) && $metaBody !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $metaBody);

            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim($line);

                    if ($line === '') {
                        continue;
                    }

                    $segments = explode(':', $line, 2);

                    if (count($segments) !== 2) {
                        continue;
                    }

                    $label = strtoupper(trim($segments[0]));
                    if (!array_key_exists($label, $rawFields)) {
                        continue;
                    }

                    if ($rawFields[$label] !== null) {
                        $invalidFields[strtolower($label)] = 'Duplicate metadata field.';
                        continue;
                    }

                    $rawFields[$label] = $segments[1];
                }
            }
        }

        $titleValidation = $this->validateSeoTitle($rawFields['SEO_TITLE']);
        if (!empty($titleValidation['reason'])) {
            $invalidFields['seo_title'] = $titleValidation['reason'];
        }

        $descriptionValidation = $this->validateSeoDescription($rawFields['SEO_DESCRIPTION']);
        if (!empty($descriptionValidation['reason'])) {
            $invalidFields['seo_description'] = $descriptionValidation['reason'];
        }

        $focusValidation = $this->validateFocusKeyphrase($rawFields['FOCUS_KEYPHRASE']);
        if (!empty($focusValidation['reason'])) {
            $invalidFields['focus_keyphrase'] = $focusValidation['reason'];
        }

        $cleanContentPrefix = substr($content, 0, $metaStart);
        $cleanContentSuffix = substr($content, $metaEnd + strlen(self::SEO_META_END));
        $cleanContent = (string) ($cleanContentPrefix . $cleanContentSuffix);

        return array(
            'content' => trim($cleanContent),
            'seo_title' => $titleValidation['valid'] ? $titleValidation['value'] : '',
            'seo_description' => $descriptionValidation['valid'] ? $descriptionValidation['value'] : '',
            'focus_keyphrase' => $focusValidation['valid'] ? $focusValidation['value'] : '',
            'invalid_fields' => $invalidFields,
        );
    }

    /**
     * Split the AI response into a title and article body.
     *
     * @param string $content Full AI response content.
     * @return array{title:string,body:string}
     */
    private function extractTitleAndBody($content) {
        $trimmed = ltrim($content);
        $lines = preg_split('/\r\n|\r|\n/', $trimmed);
        $title = '';
        $body = $trimmed;

        if (is_array($lines)) {
            foreach ($lines as $index => $line) {
                $lineTrimmed = trim($line);
                if ($lineTrimmed === '') {
                    continue;
                }

                // Handle Markdown title (# Title)
                if (strpos($lineTrimmed, '#') === 0 && preg_match('/^#\s*(.+)$/', $lineTrimmed, $matches)) {
                    $title = trim($matches[1]);
                    $bodyLines = array_slice($lines, $index + 1);
                    $body = ltrim(implode("\n", $bodyLines));
                } 
                // Handle HTML title (<h1>Title</h1>)
                elseif (preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $lineTrimmed, $matches)) {
                    $title = trim(wp_strip_all_tags($matches[1]));
                    $bodyLines = array_slice($lines, $index + 1);
                    $body = ltrim(implode("\n", $bodyLines));
                } 
                else {
                    $title = $this->generateTitleFromContent($trimmed);
                }

                break;
            }
        }

        if ($title === '') {
            $title = $this->generateTitleFromContent($trimmed);
        }

        // Remove any residual <h1> tags (opening or closing) from the body
        $body = preg_replace('/<h1[^>]*>.*?<\/h1>/is', '', $body);
        $body = preg_replace('/^\s+|\s+$/u', '', $body); // Trim whitespace

        return [
            'title' => $title,
            'body'  => $body,
        ];
    }

    /**
     * Normalize a SEO field by trimming and collapsing whitespace.
     *
     * @param string $value Raw metadata value.
     * @return string
     */
    private function normalizeSeoField($value) {
        return $this->normalizeSeoFieldValue($value);
    }

    /**
     * Normalize SEO metadata before validation.
     *
     * @param mixed $value Raw metadata value.
     * @return string
     */
    private function normalizeSeoFieldValue($value) {
        return YoastSeoIntegration::normalizeSeoValue($value);
    }

    /**
     * Validate a parsed SEO title.
     *
     * @param mixed $value Parsed title candidate.
     * @return array{valid:bool,value:string,reason:string}
     */
    private function validateSeoTitle($value) {
        return YoastSeoIntegration::validateSeoTitleValue($value);
    }

    /**
     * Validate a parsed SEO description.
     *
     * @param mixed $value Parsed description candidate.
     * @return array{valid:bool,value:string,reason:string}
     */
    private function validateSeoDescription($value) {
        return YoastSeoIntegration::validateSeoDescriptionValue($value);
    }

    /**
     * Validate a parsed focus keyphrase.
     *
     * @param mixed $value Parsed focus keyphrase candidate.
     * @return array{valid:bool,value:string,reason:string}
     */
    private function validateFocusKeyphrase($value) {
        return YoastSeoIntegration::validateFocusKeyphraseValue($value);
    }

    /**
     * Generate a fallback title from the first heading or first line of content.
     *
     * @param string $content Article body.
     * @return string
     */
    private function generateTitleFromContent($content) {
        $normalized = preg_replace('/\s+/', ' ', $content);
        if (!is_string($normalized)) {
            $normalized = $content;
        }

        $normalized = trim($normalized);
        if ($normalized === '') {
            return esc_html__('Generated Article', 'exmoment-author');
        }

        $max = 80;
        $min = 60;
        $length = function_exists('mb_strlen') ? mb_strlen($normalized, 'UTF-8') : strlen($normalized);

        if ($length <= $max) {
            return $normalized;
        }

        $slice = function_exists('mb_substr') ? mb_substr($normalized, 0, $max + 1, 'UTF-8') : substr($normalized, 0, $max + 1);
        if (function_exists('mb_strrpos')) {
            $break = mb_strrpos($slice, ' ', 0, 'UTF-8');
        } else {
            $break = strrpos($slice, ' ');
        }

        if ($break !== false && $break >= $min) {
            $slice = function_exists('mb_substr') ? mb_substr($slice, 0, $break, 'UTF-8') : substr($slice, 0, $break);
        } else {
            $slice = function_exists('mb_substr') ? mb_substr($normalized, 0, $max, 'UTF-8') : substr($normalized, 0, $max);
        }

        return rtrim($slice, " \t\n\r\0\x0B,.;:-");
    }

    /**
     * Create a WordPress post for the generated article.
     *
     * @param string $title       Proposed post title.
     * @param string $body        Generated post content.
     * @param string $postType    Target post type.
     * @param string $postStatus  Target post status (publish|draft).
     * @param int    $postAuthor  Author user ID.
     * @return int|WP_Error Post ID on success or WP_Error on failure.
     */
    private function createPost($title, $body, $postType, $postStatus, $postAuthor) {
        $sanitizedTitle = wp_strip_all_tags($title);
        $sanitizedTitle = sanitize_text_field($sanitizedTitle);
        if ($sanitizedTitle === '') {
            $sanitizedTitle = esc_html__('Generated Article', 'exmoment-author');
        }

        $sanitizedContent = wp_kses_post($body);

        $postData = [
            'post_title' => $sanitizedTitle,
            'post_content' => $sanitizedContent,
            'post_type' => $postType,
            'post_status' => $postStatus,
            'post_author' => (int) $postAuthor,
        ];

        return wp_insert_post($postData, true, false);
    }

    /**
     * Populate Yoast SEO meta for generated posts when possible.
     *
     * @param int    $postId          Generated post identifier.
     * @param array<string, mixed> $seoMeta Validated SEO metadata payload.
     * @return void
     */
    private function maybePopulateYoastSeoMeta($postId, array $seoMeta) {
        $postId = absint($postId);

        if ($postId < 1) {
            return;
        }

        $seoTitle = isset($seoMeta['seo_title']) ? $this->normalizeSeoFieldValue($seoMeta['seo_title']) : '';
        $seoDescription = isset($seoMeta['seo_description']) ? $this->normalizeSeoFieldValue($seoMeta['seo_description']) : '';
        $focusKeyphrase = isset($seoMeta['focus_keyphrase']) ? $this->normalizeSeoFieldValue($seoMeta['focus_keyphrase']) : '';

        if ($seoTitle === '' && $seoDescription === '' && $focusKeyphrase === '') {
            return;
        }

        $integration = $this->getYoastSeoIntegration();

        if (!($integration instanceof YoastSeoIntegration) || !$integration->isActive()) {
            return;
        }

        try {
            $integration->maybeUpdatePostSeo($postId, $seoTitle, $seoDescription, $focusKeyphrase);
        } catch (Throwable $exception) {
            $this->logDebug('Yoast SEO integration failed for post %d: %s', $postId, $exception->getMessage());
        }
    }

    /**
     * Generate and attach an AI featured image for the post when appropriate.
     *
     * @param GptController $controller GPT controller instance.
     * @param int           $postId     Target post identifier.
     * @return void
     */
    private function maybeGenerateFeaturedImage($controller, $postId) {
        $postId = absint($postId);

        if (!($controller instanceof GptController)) {
            return;
        }

        if ($postId < 1 || has_post_thumbnail($postId)) {
            return;
        }

        $enabled = apply_filters('exmoau_enable_ai_featured_images', true, $postId);
        $enabled = apply_filters('exmoau_enable_ai_featured_images', $enabled, $postId);
        if ($enabled !== true) {
            return;
        }

        try {
            $generation = $controller->generateFeaturedImageForPost($postId);

            if (is_array($generation) && empty($generation['success'])) {
                $this->logDebug(
                    'AI featured image generation skipped for post %d: %s',
                    $postId,
                    isset($generation['error']) ? (string) $generation['error'] : 'unknown'
                );
            }
        } catch (Throwable $exception) {
            $this->logDebug('AI featured image generation failed for post %d: %s', $postId, $exception->getMessage());
        }
    }

    /**
     * Store a meta back-reference from the job to the generated post.
     *
     * @param int $jobId  Job identifier.
     * @param int $postId Generated post identifier.
     * @return void
     */
    private function storeBackReference($jobId, $postId) {
        update_post_meta($jobId, self::META_RESULT_POST_ID, (int) $postId);
    }

    /**
     * Persist the last execution status for eligible job types.
     *
     * @param int $jobId  Job identifier.
     * @param int $status Normalized execution status (1=success, 0=failure).
     * @return void
     */
    private function persistExecutionStatus($jobId, $status) {
        $jobId = absint($jobId);
        if ($jobId < 1) {
            return;
        }

        $jobType = $this->normalizeJobType(get_post_meta($jobId, self::META_JOB_TYPE, true));
        if ($jobType === 'repeating_scheduled') {
            return;
        }

        $normalizedStatus = ($status === 1) ? 1 : 0;
        $current = get_post_meta($jobId, self::META_EXECUTION_STATUS, true);
        $current = is_numeric($current) ? (int) $current : 0;

        if ($current === $normalizedStatus) {
            return;
        }

        $updated = update_post_meta($jobId, self::META_EXECUTION_STATUS, $normalizedStatus);
        if (!$updated) {
            $this->logDebug(
                'Failed to persist execution status %d for job %d.',
                $normalizedStatus,
                $jobId
            );
        }
    }

    /**
     * Persist the outcome of a job run and record any user-facing notices.
     *
     * Accepts either a structured result array or a WP_Error when execution failed
     * upstream. The method normalizes the execution status, prevents duplicate
     * notices when suppression is requested, and stores contextual success or error
     * messages for later display in the admin area.
     *
     * @param int             $jobId   Identifier of the job that just executed.
     * @param array|WP_Error  $result  Result payload or WP_Error describing the failure.
     * @param array<string,mixed> $context Contextual flags that influence notice handling.
     * @return void
     */
    private function handleRunOutcome($jobId, $result, array $context) {
        $success = false;

        if (is_array($result)) {
            $success = !empty($result['success']);
        }

        $this->persistExecutionStatus($jobId, $success ? 1 : 0);

        if (!empty($context['suppress_notice'])) {
            return;
        }

        if ($result instanceof WP_Error) {
            $message = $result->get_error_message();
            if ($message === '') {
                $message = esc_html__('The job could not be executed.', 'exmoment-author');
            }

            $this->storeRunNotice($jobId, 'error', $message);

            return;
        }

        if (!is_array($result)) {
            $this->storeRunNotice(
                $jobId,
                'error',
                esc_html__('The job could not be executed.', 'exmoment-author')
            );

            return;
        }

        if (empty($result['success'])) {
            $message = isset($result['error']) ? $result['error'] : esc_html__('The job could not be executed.', 'exmoment-author');
            $this->storeRunNotice($jobId, 'error', $message);

            return;
        }

        $message = $this->buildSuccessMessage($result);
        $this->storeRunNotice($jobId, 'success', $message);
    }

    /**
     * Compose a human-readable success notice from a run result payload.
     *
     * @param array<string, mixed> $result Normalized run result payload.
     * @return string
     */
    private function buildSuccessMessage(array $result) {
        $postId = isset($result['post_id']) ? (int) $result['post_id'] : 0;
        $postStatus = isset($result['post_status']) ? sanitize_key($result['post_status']) : 'draft';
        $sources = isset($result['sources']) ? (int) $result['sources'] : 0;
        $categories = isset($result['categories']) ? (int) $result['categories'] : 0;
        $model = isset($result['model']) ? sanitize_text_field($result['model']) : '';
        $duration = isset($result['duration']) ? (float) $result['duration'] : 0.0;
        $generations = isset($result['generation_runs_total']) ? (int) $result['generation_runs_total'] : 0;
        $generationRuns = isset($result['generation_runs']) && is_array($result['generation_runs'])
            ? $result['generation_runs']
            : [];
        $parts = [];

        if ($generations > 1 && !empty($generationRuns)) {
            $postLabels = [];
            foreach ($generationRuns as $run) {
                if (!is_array($run)) {
                    continue;
                }

                $runPostId = isset($run['post_id']) ? (int) $run['post_id'] : 0;
                if ($runPostId > 0) {
                    $postLabels[] = '#' . $runPostId;
                }
            }

            if (!empty($postLabels)) {
                $parts[] = sprintf(
                    /* translators: 1: Number of generations, 2: List of post IDs. */
                    esc_html__('Created %1$d posts (%2$s).', 'exmoment-author'),
                    $generations,
                    implode(', ', $postLabels)
                );
            } else {
                $parts[] = sprintf(
                    /* translators: %d: Number of generations. */
                    esc_html__('Completed %d generations.', 'exmoment-author'),
                    $generations
                );
            }

            $parts[] = sprintf(
                /* translators: %s: Post status. */
                esc_html__('Final post status: %s.', 'exmoment-author'),
                $postStatus
            );

            if ($sources > 0) {
                $parts[] = sprintf(
                    /* translators: %d: Number of sources. */
                    esc_html__('Total sources: %d.', 'exmoment-author'),
                    $sources
                );
            }

            if ($model !== '') {
                /* translators: %s: Model name. */
                $parts[] = sprintf(esc_html__('Model: %s.', 'exmoment-author'), $model);
            }

            if ($duration > 0) {
                /* translators: %s: Duration in seconds. */
                $parts[] = sprintf(esc_html__('Total duration: %.2f seconds.', 'exmoment-author'), $duration);
            }

            if (!empty($result['truncated'])) {
                /* translators: %s: Truncated sources notice. */
                $parts[] = esc_html__('Some sources were truncated to fit the token budget.', 'exmoment-author');
            }

            if (!empty($result['skipped_large_files'])) {
                $parts[] = sprintf(
                    /* translators: %d: Number of oversized source files. */
                    esc_html__('Skipped %d oversized source file(s).', 'exmoment-author'),
                    (int) $result['skipped_large_files']
                );
            }

            if (!empty($result['skipped_invalid_files'])) {
                $parts[] = sprintf(
                    /* translators: %d: Number of invalid source files. */
                    esc_html__('Skipped %d invalid source file(s).', 'exmoment-author'),
                    (int) $result['skipped_invalid_files']
                );
            }

            if (!empty($result['skipped_used_files'])) {
                $parts[] = sprintf(
                    /* translators: %d: Number of previously used source files. */
                    esc_html__('Skipped %d previously used source file(s).', 'exmoment-author'),
                    (int) $result['skipped_used_files']
                );
            }

            return implode(' ', $parts);
        }

        if ($postId > 0) {
            $parts[] = sprintf(
                /* translators: 1: Post ID, 2: Post status. */
                esc_html__('Created post #%1$d (%2$s).', 'exmoment-author'),
                $postId,
                $postStatus
            );
        }

        if ($sources > 0) {
            $articleText = sprintf(
                /* translators: %d: Number of articles. */
                esc_html__('%d article', 'exmoment-author'),
                $sources
            );
            if ($sources !== 1) {
                $articleText = sprintf(
                    /* translators: %d: Number of articles. */
                    esc_html__('%d articles', 'exmoment-author'),
                    $sources
                );
            }

            $categoryText = sprintf(
                /* translators: %d: Number of categories. */
                esc_html__('%d category', 'exmoment-author'),
                $categories
            );
            if ($categories !== 1) {
                $categoryText = sprintf(
                    /* translators: %d: Number of categories. */
                    esc_html__('%d categories', 'exmoment-author'),
                    $categories
                );
            }

            $parts[] = sprintf(
                /* translators: 1: Article count, 2: Category count. */
                esc_html__('Used %1$s across %2$s.', 'exmoment-author'),
                $articleText,
                $categoryText
            );
        }

        if ($model !== '') {
            /* translators: %s: Model name. */
            $parts[] = sprintf(esc_html__('Model: %s.', 'exmoment-author'), $model);
        }

        if (!empty($result['truncated'])) {
            /* translators: %s: Truncated sources notice. */
            $parts[] = esc_html__('Sources were truncated to respect the token budget.', 'exmoment-author');
        }

        if (!empty($result['skipped_large_files'])) {
            $parts[] = sprintf(
                /* translators: %d: Number of oversized source files. */
                esc_html__('Skipped %d oversized source file(s).', 'exmoment-author'),
                (int) $result['skipped_large_files']
            );
        }

        if (!empty($result['skipped_invalid_files'])) {
            $sample = [];
            if (!empty($result['skipped_invalid_sample']) && is_array($result['skipped_invalid_sample'])) {
                foreach ($result['skipped_invalid_sample'] as $label) {
                    if (!is_string($label) || $label === '') {
                        continue;
                    }
                    $sample[] = sanitize_text_field($label);
                    if (count($sample) >= 3) {
                        break;
                    }
                }
            }

            if (!empty($sample)) {
                $parts[] = sprintf(
                    /* translators: 1: Number of invalid source files, 2: Comma-separated sample list. */
                    esc_html__('Skipped %1$d invalid source file(s): %2$s.', 'exmoment-author'),
                    (int) $result['skipped_invalid_files'],
                    implode(', ', $sample)
                );
            } else {
                $parts[] = sprintf(
                    /* translators: %d: Number of invalid source files. */
                    esc_html__('Skipped %d invalid source file(s).', 'exmoment-author'),
                    (int) $result['skipped_invalid_files']
                );
            }
        }

        if ($duration > 0) {
            /* translators: %s: Duration in seconds. */
            $parts[] = sprintf(esc_html__('Duration: %.2f seconds.', 'exmoment-author'), $duration);
        }

        return implode(' ', $parts);
    }

    /**
     * Persist a transient-backed admin notice for a job run.
     *
     * @param int    $jobId   Job identifier.
     * @param string $type    Notice type (success|error|warning).
     * @param string $message Escaped notice message.
     * @return void
     */
    private function storeRunNotice($jobId, $type, $message) {
        $message = trim((string) $message);
        if ($message === '') {
            return;
        }

        $jobId = absint($jobId);
        if ($jobId < 1) {
            return;
        }

        if (!in_array($type, ['success', 'error', 'warning'], true)) {
            $type = 'success';
        }

        $transientKey = $this->buildNoticeTransientKey(self::NOTICE_TOKEN_RUN);
        if ($transientKey === '') {
            return;
        }

        set_transient($transientKey, [
            'type' => $type,
            'message' => $message,
        ], MINUTE_IN_SECONDS);

        if (!headers_sent()) {
            add_filter(
                'redirect_post_location',
                static function ($location) {
                    return add_query_arg(self::NOTICE_QUERY_ARG, self::NOTICE_TOKEN_RUN, $location);
                }
            );
        }
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
     * Determine whether the current request explicitly requested a manual run.
     *
     * Reads known trigger keys from $_POST; caller must perform nonce/capability checks.
     *
     * @return bool
     */
    private function isManualRunRequested() {
        foreach (self::MANUAL_TRIGGER_KEYS as $key) {
            if (!isset($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                continue;
            }

            $value = $this->getScalarPostValue($key);
            if ($value === null) {
                continue;
            }

            $value = sanitize_text_field($value);
            $value = strtolower(trim($value));
            if (in_array($value, array('1', 'true', 'yes', 'on'), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retrieve the original post status from the save-post request, if provided.
     *
     * @return string
     */
    private function getOriginalPostStatus() {
        $value = $this->getScalarPostValue('original_post_status');
        if ($value === null) {
            return '';
        }

        $value = sanitize_key($value);
        if (!in_array($value, array('publish', 'draft', 'pending', 'private', 'future', 'trash'), true)) {
            return '';
        }

        return $value;
    }

    /**
     * Retrieve a scalar value from $_POST or null when invalid.
     *
     * @param string $key POST key to read.
     * @return string|null
     */
    private function getScalarPostValue($key) {
        if (!isset($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return null;
        }

        $value = wp_unslash($_POST[$key]); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!is_scalar($value)) {
            return null;
        }

        return (string) $value;
    }

    /**
     * Normalise the stored job type meta value.
     *
     * @param mixed $value Raw meta value.
     * @return string
     */
    private function normalizeJobType($value) {
        if (!is_string($value)) {
            return '';
        }

        $value = sanitize_key($value);
        return $value;
    }

    /**
     * Resolve the absolute library root directory.
     *
     * @return string
     */
    private function resolveLibraryRoot() {
        $uploads = wp_get_upload_dir();
        if (!is_array($uploads) || !empty($uploads['error']) || empty($uploads['basedir'])) {
            return '';
        }

        return rtrim($uploads['basedir'], '/\\') . '/exmoau-library';
    }

    /**
     * Build a sanitized absolute path for a library category.
     *
     * @param string $root     Library root path.
     * @param string $category Category slug.
     * @return string
     */
    private function resolveCategoryPath($root, $category) {
        $normalizedRoot = $this->normalizePath($root);
        if ($normalizedRoot === '') {
            return '';
        }

        $candidate = $normalizedRoot . '/' . $category;
        $realRoot = realpath($normalizedRoot);
        if (false === $realRoot || !is_dir($realRoot)) {
            return '';
        }
        $realCandidate = realpath($candidate);

        if (false === $realCandidate || !is_dir($realCandidate)) {
            return '';
        }

        $normalizedCandidate = $this->normalizePath($realCandidate);
        if (strpos($normalizedCandidate . '/', $this->normalizePath($realRoot) . '/') !== 0) {
            return '';
        }

        return $realCandidate;
    }

    /**
     * Validate that a category name is safe for filesystem usage.
     *
     * @param mixed $value Candidate category value.
     * @return bool
     */
    private function isSafeCategoryName($value) {
        if (!is_string($value)) {
            return false;
        }

        $value = trim($value);
        if ($value === '' || strpos($value, '..') !== false || strpos($value, '/') !== false || strpos($value, '\\') !== false) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9_.\- ]+$/', $value);
    }

    /**
     * Determine whether a file should be skipped when scanning the library.
     *
     * @param string $name         Base filename.
     * @param string $path         Absolute file path.
     * @param string $categorySlug Optional category slug for logging.
     * @return bool
     */
    private function shouldSkipFile($name, $path, $categorySlug = '') {
        if ($name === '' || $name[0] === '.') {
            return true;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension !== 'txt') {
            return true;
        }

        if (is_link($path)) {
            return true;
        }

        $enforceRegistryUniqueness = ($this->enforceRegistryUniqueness === true);
        $registryTableVerified = ($this->registryTableVerified === true);
        $usedRepository = ($this->usedArticlesRepository instanceof UsedArticlesRepository)
            ? $this->usedArticlesRepository
            : null;

        if (!$enforceRegistryUniqueness || !($usedRepository instanceof UsedArticlesRepository) || !$registryTableVerified) {
            return false;
        }

        $identity = $usedRepository->resolveIdentity($path);
        $normalizedPath = '';
        $pathHash = '';

        if (is_array($identity)) {
            $normalizedPath = isset($identity['path']) && is_string($identity['path'])
                ? $identity['path']
                : '';
            $pathHash = isset($identity['hash']) && is_string($identity['hash'])
                ? $identity['hash']
                : '';
        }

        $alreadyUsed = false;
        $reason = 'hash-miss';

        if ($pathHash !== '') {
            $alreadyUsed = $usedRepository->isHashUsed($pathHash);
            $reason = $alreadyUsed ? 'hash-match' : 'hash-miss';
        } else {
            $alreadyUsed = $usedRepository->isUsed($path);
            $reason = $alreadyUsed ? 'path-match' : 'path-miss';
        }

        if ($alreadyUsed) {
            if (!is_int($this->registryScanSkipped)) {
                $this->registryScanSkipped = 0;
            }

            $this->registryScanSkipped++;

            $categoryForLog = ($categorySlug !== '' ? $categorySlug : '(unknown)');
            $hashForLog = ($pathHash !== '' ? $pathHash : '(empty)');
            $normalizedForLog = ($normalizedPath !== '' ? $normalizedPath : '(empty)');

            $this->logDebug(
                'Registry skip=used=true: category=%s, file=%s, hash=%s, normalized=%s, reason=%s.',
                $categoryForLog,
                (string) $name,
                $hashForLog,
                $normalizedForLog,
                $reason
            );

            return true;
        }

        if ($normalizedPath === '') {
            $normalizedPath = $this->normalizePath($path);
        }

        if ($normalizedPath === '') {
            $normalizedPath = (string) $path;
        }

        $this->registryScanCache[$path] = [
            'normalized_path' => $normalizedPath,
            'hash' => $pathHash,
            'checked' => true,
        ];

        return false;
    }

    /**
     * Normalize a filesystem path for registry comparisons.
     *
     * @param string $path Absolute file path.
     * @return string
     */
    private function normalizePath($path) {
        if (!is_string($path) || $path === '') {
            return '';
        }

        $normalized = str_replace('\\', '/', $path);
        $normalized = preg_replace('#/+#', '/', $normalized);

        return rtrim((string) $normalized, '/');
    }

    /**
     * Retrieve the number of registry identity checks skipped during scanning.
     *
     * @return int
     */
    private function getRegistryScanSkippedCount() {
        return is_int($this->registryScanSkipped) ? $this->registryScanSkipped : 0;
    }

    /**
     * Log details about registry lookups during source collection when debug is enabled.
     *
     * @param string $tableName      Registry table name.
     * @param string $originalPath   Original file path.
     * @param string $normalizedPath Normalized file path used for deduping.
     * @param string $hash           Calculated hash for the file path.
     * @param bool   $skipped        Whether the probe was skipped.
     * @param string $reason         Reason for skip or match context.
     * @return void
     */
    private function logRegistryProbe($tableName, $originalPath, $normalizedPath, $hash, $skipped, $reason) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        if (!is_int($this->registryProbeCount)) {
            $this->registryProbeCount = 0;
        }

        if ($this->registryProbeCount >= self::REGISTRY_DEBUG_PROBE_LIMIT) {
            return;
        }

        $this->registryProbeCount++;

        $tableForLog = ($tableName !== '' ? $tableName : '(unknown)');
        $lookupTemplate = sprintf('SELECT id FROM %s WHERE path_hash = %%s LIMIT 1', $tableForLog);

        $this->logDebug(
            'Registry probe #%d: table=%s, lookup="%s", original="%s", normalized="%s", hash=%s, skip=%s (%s).',
            $this->registryProbeCount,
            $tableForLog,
            $lookupTemplate,
            (string) $originalPath,
            (string) $normalizedPath,
            ($hash !== '' ? $hash : '(empty)'),
            ($skipped ? 'yes' : 'no'),
            $reason
        );
    }

    /**
     * Proxy debug logging through the shared log service when available.
     *
     * @param string $message Message format string.
     * @param mixed  ...$args Message arguments.
     * @return void
     */
    private function logDebug($message, ...$args) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        if (!is_string($message) || $message === '') {
            return;
        }

        if (!empty($args)) {
            $message = vsprintf($message, $args);
        }

        $logger = LogService::getInstance();
        if ($logger instanceof LogService) {
            $logger->debug('jobs.execution', $message);

            return;
        }

        error_log('ExMoment Author: ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }

    /**
     * Ensure a user has at least author capabilities for content creation.
     *
     * @param int|WP_User|null $userId User identifier or object; null defaults to current user.
     * @return bool
     */
    private function userMeetsAuthorThreshold($userId) {
        $userId = absint($userId);
        if ($userId < 1) {
            return false;
        }

        if (is_multisite() && is_super_admin($userId)) {
            return true;
        }

        $user = get_user_by('id', $userId);
        if (!($user instanceof WP_User)) {
            return false;
        }

        $roles = isset($user->roles) && is_array($user->roles) ? $user->roles : [];
        foreach ($roles as $role) {
            if (!is_string($role) || $role === '') {
                continue;
            }

            if (strtolower($role) !== 'subscriber') {
                return true;
            }
        }

        return false;
    }
}
