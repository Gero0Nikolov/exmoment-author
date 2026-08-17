<?php

namespace ExMomentAuthor\Modules\Jobs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use DateTime;
use WP_Error;
use WP_Post;
use WP_Post_Type;
use WP_User;
use WP_User_Query;

use ExMomentAuthor\Modules\Jobs\JobsSchedulerWorker;
use ExMomentAuthor\Modules\Jobs\JobsTimeHelper;

/**
 * Manages administrative meta boxes for the ExMoment Author Job post type.
 */
class JobsMetaController {

    private const POST_TYPE = 'exmoau_job';
    private const NONCE_ACTION = 'exmoau_save_job_meta';
    private const NONCE_FIELD = 'exmoau_job_meta_nonce';
    private const ERROR_QUERY_ARG = 'exmoau_job_meta_error';
    private const ERROR_TRANSIENT_PREFIX = 'exmoau_job_meta_errors_';
    private const ERROR_NOTICE_TOKEN = 'meta_errors';
    private const NOTICE_TOKEN_MAX_LENGTH = 64;

    private const META_JOB_TYPE = 'exmoau_job_type';
    private const META_SINGLE_SCHEDULED = 'exmoau_job_single_scheduled_datetime';
    private const META_REPEATING_DAYS = 'exmoau_job_repeating_days';
    private const META_REPEATING_HOURS = 'exmoau_job_repeating_hours_by_day';
    private const META_MIXTURE_DIRECTORIES = 'exmoau_setup_mixture_directories';
    private const META_MIXTURE_UNIQUENESS = 'exmoau_setup_mixture_uniqueness';
    private const META_MIXTURE_PER_CATEGORY = 'exmoau_setup_mixture_per_category';
    private const META_DIRECTIVE_POST_TYPE = 'exmoau_setup_directive_post_type';
    private const META_DIRECTIVE_POST_STATUS = 'exmoau_setup_directive_post_status';
    private const META_DIRECTIVE_POST_AUTHOR = 'exmoau_setup_directive_post_author';
    private const META_DIRECTIVE_GENERATION_COUNT = 'exmoau_setup_directive_generation_count';
    private const META_EXECUTION_STATUS = 'exmoau_execution_status';

    private const DEFAULT_JOB_TYPE = 'single_instant';
    private const DEFAULT_DIRECTIVE_POST_STATUS = 'draft';
    private const DEFAULT_DIRECTIVE_GENERATION_COUNT = 1;
    private const DIRECTIVE_GENERATION_MIN = 1;
    private const DEFAULT_MIXTURE_PER_CATEGORY = 5;
    private const MIXTURE_PER_CATEGORY_MIN = 1;
    private const MIXTURE_PER_CATEGORY_MAX = 50;

    private const SETUP_TABS_NONCE = 'exmoau_job_setup_tabs';
    private const AJAX_ACTION_MIXTURE = 'exmoau_get_mixture_tab';
    private const AJAX_ACTION_DIRECTIVE = 'exmoau_get_directive_tab';

    private const MIXTURE_SELECT_TOOLBAR_THRESHOLD = 50;
    private const MIXTURE_PAGE_SIZE = 24;

    /**
     * Allowed job type identifiers.
     *
     * @var string[]
     */
    private const JOB_TYPES = [
        'single_instant',
        'single_scheduled',
        'repeating_scheduled',
    ];

    /**
     * Weekday map keyed by identifier.
     *
     * @var array<string, string>
     */
    private const WEEKDAYS = [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
    ];

    /**
     * Cached library structure.
     *
     * @var array<string, array<string, string>>|null
     */
    private $libraryStructure = null;

    /**
     * Cached directive author options for the current request.
     *
     * @var array<int, string>|null
     */
    private $cachedDirectiveAuthors = null;

    /**
     * Hook WordPress actions that power the job meta interface.
     *
     * Registers callbacks for meta box rendering, asset loading, AJAX tabs, and
     * admin notices. Capability and nonce checks occur inside the individual
     * handlers so privileged actions (saving job meta or serving AJAX payloads)
     * only run after WordPress validates the current user. The optional
     * configuration parameter is reserved for future dependency injection and
     * is not used at the moment.
     *
     * @param array<string, mixed> $config Optional module configuration (unused).
     * @return void
     * @since 1.1.0
     */
    public function __construct(array $config = []) {
        unset($config);

        add_action('add_meta_boxes', [$this, 'registerMetaBoxes']);
        add_action('admin_notices', [$this, 'renderCronDisabledNotice'], 5);
        add_action('save_post_' . self::POST_TYPE, [$this, 'saveJobMeta'], 10, 2);
        add_action('admin_notices', [$this, 'renderAdminNotices']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueJobMetaAssets'], 20);
        add_action('wp_ajax_' . self::AJAX_ACTION_MIXTURE, [$this, 'handleMixtureTabAjax']);
        add_action('wp_ajax_' . self::AJAX_ACTION_DIRECTIVE, [$this, 'handleDirectiveTabAjax']);
        add_action('post_submitbox_misc_actions', [$this, 'renderExecutionStatusSubmitBox'], 100);
    }

    /**
     * Register meta boxes for the job post type.
     *
     * Runs on the `add_meta_boxes` hook and bails unless WordPress identifies
     * the current screen as the job post type. WordPress core enforces the
     * capability checks for editing the post before our render callbacks run,
     * and further nonce validation occurs inside {@see renderJobTypeMetaBox()}
     * and {@see renderSetupMetaBox()}.
     *
     * @param string $postType Current post type slug supplied by WordPress.
     * @return void
     * @since 1.1.0
     */
    public function registerMetaBoxes($postType) {
        if ($postType !== self::POST_TYPE) {
            return;
        }

        add_meta_box(
            'exmoau_job_type',
            __('Job Type', 'exmoment-author'),
            [$this, 'renderJobTypeMetaBox'],
            self::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'exmoau_job_setup',
            __('Setup', 'exmoment-author'),
            [$this, 'renderSetupMetaBox'],
            self::POST_TYPE,
            'normal',
            'default'
        );

        add_meta_box(
            'exmoau_job_custom_system_prompt_box',
            __('Custom System Prompt', 'exmoment-author'),
            array($this, 'renderCustomSystemPromptMetaBox'),
            self::POST_TYPE,
            'normal',
            'default'
        );
    }

    /**
     * Render the optional per-job editorial system prompt override.
     *
     * @param WP_Post $post Current job post.
     * @return void
     */
    public function renderCustomSystemPromptMetaBox($post) {
        if (!($post instanceof WP_Post) || !current_user_can('edit_post', $post->ID)) {
            return;
        }

        $storedValue = get_post_meta(
            $post->ID,
            JobsAiContextResolver::META_CUSTOM_SYSTEM_PROMPT,
            true
        );
        $normalizedValue = JobsAiContextResolver::sanitizeCustomSystemPrompt($storedValue);
        $prompt = is_wp_error($normalizedValue) ? '' : $normalizedValue;
        ?>
        <div class="exmoau-job-prompt">
            <p class="description" id="exmoau_job_custom_system_prompt_description">
                <?php esc_html_e('Optional. When provided, this editorial system prompt replaces the general AI Setup system prompt for this job. Required article and SEO output instructions remain active.', 'exmoment-author'); ?>
            </p>
            <label class="screen-reader-text" for="exmoau_job_custom_system_prompt">
                <?php esc_html_e('Custom System Prompt', 'exmoment-author'); ?>
            </label>
            <textarea
                id="exmoau_job_custom_system_prompt"
                name="exmoau_job_custom_system_prompt"
                class="large-text code"
                rows="10"
                maxlength="<?php echo esc_attr(JobsAiContextResolver::MAX_CUSTOM_SYSTEM_PROMPT_LENGTH); ?>"
                aria-describedby="exmoau_job_custom_system_prompt_description"
            ><?php echo esc_textarea($prompt); ?></textarea>
            <p class="description">
                <?php esc_html_e('Maximum 10,000 characters. Line breaks are preserved.', 'exmoment-author'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Localize configuration for the job meta interface.
     *
     * Runs on `admin_enqueue_scripts`, checks the requested admin screen, and
     * then exposes sanitized configuration to JavaScript. No user-supplied
     * input is consumed here; the method only surfaces the cron-disabled flag
     * after WordPress confirms the screen corresponds to the job post editor.
     *
     * @param string $hookSuffix Current admin page hook provided by WordPress.
     * @return void
     * @since 1.1.0
     */
    public function enqueueJobMetaAssets($hookSuffix) {
        if (!is_string($hookSuffix)) {
            return;
        }

        if ($hookSuffix !== 'post.php' && $hookSuffix !== 'post-new.php') {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== self::POST_TYPE) {
            return;
        }

        wp_localize_script(
            'exmoau-admin-core',
            'ExMomentAuthorJobsMetaConfig',
            [
                'cronDisabled' => JobsSchedulerWorker::isCronDisabledNoticeActive() ? 1 : 0,
            ]
        );
    }

    /**
     * Display a warning when WP-Cron is disabled.
     *
     * Verifies the current user can manage options and that the notice is
     * relevant to the job post type before rendering a translated, escaped
     * message. No external input is read while building the markup.
     *
     * @return void
     * @since 1.1.0
     */
    public function renderCronDisabledNotice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!JobsSchedulerWorker::isCronDisabledNoticeActive()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->post_type !== self::POST_TYPE) {
            return;
        }
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <?php esc_html_e('WP-Cron appears disabled. Scheduled jobs will not run until cron is enabled or a server task triggers wp-cron.php.', 'exmoment-author'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render the execution status indicator within the submit box misc actions area.
     *
     * Ensures the current screen is editing a job, validates the global post
     * context, and checks the `edit_post` capability for the current user
     * before outputting sanitized status markup.
     *
     * @return void
     * @since 1.1.0
     */
    public function renderExecutionStatusSubmitBox() {
        if (!is_admin()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'post' || $screen->post_type !== self::POST_TYPE) {
            return;
        }

        global $post;

        if (!($post instanceof WP_Post)) {
            return;
        }

        if (!current_user_can('edit_post', $post->ID)) {
            return;
        }

        $indicatorMarkup = $this->getExecutionStatusMarkup($post->ID);
        if ($indicatorMarkup === '') {
            return;
        }

        echo wp_kses_post($indicatorMarkup);
    }

    /**
     * Generate the execution status markup for the submit box indicator.
     *
     * @param int $postId Job post identifier.
     * @return string Escaped HTML string for the status badge or empty string when unavailable.
     * @since 1.1.0
     */
    private function getExecutionStatusMarkup($postId) {
        $postId = (int) $postId;
        if ($postId <= 0) {
            return '';
        }

        $rawStatus = get_post_meta($postId, self::META_EXECUTION_STATUS, true);
        $statusValue = is_numeric($rawStatus) ? (int) $rawStatus : 0;
        $statusValue = ($statusValue === 1) ? 1 : 0;
        $isExecuted = ($statusValue === 1);

        $wrapperClasses = [
            'exmoau-job-status',
            ($isExecuted ? 'exmoau-job-status--executed' : 'exmoau-job-status--pending'),
        ];

        $label = $isExecuted
            ? esc_html__('Executed', 'exmoment-author')
            : esc_html__('Pending execution', 'exmoment-author');

        $classes = esc_attr(implode(' ', $wrapperClasses));
        $text = esc_html($label);

        return sprintf(
            '<div class="%1$s" role="status" aria-live="polite">'
            . '<span class="exmoau-job-status__dot" aria-hidden="true"></span>'
            . '<span class="exmoau-job-status__text">%2$s</span>'
            . '</div>',
            $classes,
            $text
        );
    }

    /**
     * Render the Job Type meta box.
     *
     * Outputs nonce-protected controls for selecting the job execution
     * pattern. Stored values are sanitized via helper methods before being
     * escaped into the markup. The nonce generated here (`exmoau_job_meta_nonce`)
     * is required later by {@see saveJobMeta()} to authorize persistence.
     *
     * @param WP_Post $post Current post object supplied by WordPress.
     * @return void
     * @since 1.1.0
     */
    public function renderJobTypeMetaBox($post) {
        if (!($post instanceof WP_Post)) {
            return;
        }

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $jobType = $this->getJobTypeValue($post->ID);
        $scheduledDatetime = $this->getScheduledDatetimeValue($post->ID);
        $repeatingDays = $this->getRepeatingDaysValue($post->ID);
        $repeatingHours = $this->getRepeatingHoursValue($post->ID);

        $datetimeInputValue = '';
        if ($scheduledDatetime !== '') {
            $datetimeInputValue = $this->formatDatetimeForInput($scheduledDatetime);
        }

        $weekdayLabels = $this->getWeekdayLabels();
        $serverTimeMarkup = $this->buildServerTimeMarkup(JobsTimeHelper::getDisplayContext());
        ?>
        <div class="exmoau-job-type" data-exmoau-job-meta="type">
            <p class="description">
                <?php esc_html_e('Select the execution pattern for this job. Additional fields will appear based on your selection.', 'exmoment-author'); ?>
            </p>
            <fieldset class="exmoau-job-type__options">
                <legend class="screen-reader-text">
                    <?php esc_html_e('Job type selection', 'exmoment-author'); ?>
                </legend>
                <?php foreach (self::JOB_TYPES as $type) :
                    $label = $this->getJobTypeLabel($type);
                    ?>
                    <label class="exmoau-job-type__option">
                        <input
                            type="radio"
                            name="exmoau_job_type"
                            value="<?php echo esc_attr($type); ?>"
                            <?php checked($jobType, $type); ?>
                        />
                        <span><?php echo esc_html($label); ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <div
                class="exmoau-job-type__pane<?php echo ($jobType === 'single_scheduled' ? '' : ' exmoau-is-hidden'); ?>"
                data-exmoau-job-type-pane="single_scheduled"
                aria-hidden="<?php echo esc_attr($jobType === 'single_scheduled' ? 'false' : 'true'); ?>"
            >
                <?php if ($serverTimeMarkup !== '') : ?>
                    <?php echo wp_kses_post($serverTimeMarkup); ?>
                <?php endif; ?>
                <label class="exmoau-job-type__field" for="exmoau_job_single_scheduled_datetime">
                    <span class="exmoau-job-type__label">
                        <?php esc_html_e('Schedule date & time', 'exmoment-author'); ?>
                    </span>
                    <input
                        type="datetime-local"
                        id="exmoau_job_single_scheduled_datetime"
                        name="exmoau_job_single_scheduled_datetime"
                        value="<?php echo esc_attr($datetimeInputValue); ?>"
                        class="exmoau-job-type__input"
                    />
                </label>
                <p class="description">
                    <?php esc_html_e('Set the local date and time when this job should run.', 'exmoment-author'); ?>
                </p>
            </div>

            <div
                class="exmoau-job-type__pane<?php echo ($jobType === 'repeating_scheduled' ? '' : ' exmoau-is-hidden'); ?>"
                data-exmoau-job-type-pane="repeating_scheduled"
                aria-hidden="<?php echo esc_attr($jobType === 'repeating_scheduled' ? 'false' : 'true'); ?>"
            >
                <?php if ($serverTimeMarkup !== '') : ?>
                    <?php echo wp_kses_post($serverTimeMarkup); ?>
                <?php endif; ?>
                <p class="description">
                    <?php esc_html_e('Choose one or more weekdays and specify the times the job should run on those days.', 'exmoment-author'); ?>
                </p>
                <div class="exmoau-job-type__repeat">
                    <?php foreach ($weekdayLabels as $dayKey => $label) :
                        $isSelected = in_array($dayKey, $repeatingDays, true);
                        $dayTimes = isset($repeatingHours[$dayKey]) ? $repeatingHours[$dayKey] : [];
                        if (empty($dayTimes)) {
                            $dayTimes = [''];
                        }
                        ?>
                        <div class="exmoau-job-type__repeat-day">
                            <label class="exmoau-job-type__repeat-label">
                                <input
                                    type="checkbox"
                                    name="exmoau_job_repeating_days[]"
                                    value="<?php echo esc_attr($dayKey); ?>"
                                    <?php checked($isSelected); ?>
                                    data-exmoau-job-repeat-day="<?php echo esc_attr($dayKey); ?>"
                                />
                                <span><?php echo esc_html($label); ?></span>
                            </label>
                            <div
                                class="exmoau-job-type__repeat-times<?php echo ($isSelected ? '' : ' exmoau-is-hidden'); ?>"
                                data-exmoau-job-day-pane="<?php echo esc_attr($dayKey); ?>"
                                aria-hidden="<?php echo esc_attr($isSelected ? 'false' : 'true'); ?>"
                            >
                                <div class="exmoau-job-type__repeat-time-list" data-exmoau-job-time-list="<?php echo esc_attr($dayKey); ?>">
                                    <?php
                                    $timeFieldIndex = 0;
                                    foreach ($dayTimes as $timeValue) :
                                        $timeFieldId = sprintf('exmoau_job_repeating_hours_%s_%d', $dayKey, $timeFieldIndex);
                                        $timeFieldIndex++;
                                        ?>
                                        <div class="exmoau-job-type__repeat-time">
                                            <label class="screen-reader-text" for="<?php echo esc_attr($timeFieldId); ?>">
                                                <?php
                                                    printf(
                                                        /* translators: %s: weekday label. */
                                                        esc_html__('Run time for %s', 'exmoment-author'),
                                                        esc_html($label)
                                                    );
                                                ?>
                                            </label>
                                            <input
                                                type="time"
                                                id="<?php echo esc_attr($timeFieldId); ?>"
                                                name="exmoau_job_repeating_hours_by_day[<?php echo esc_attr($dayKey); ?>][]"
                                                value="<?php echo esc_attr($timeValue); ?>"
                                                class="exmoau-job-type__input"
                                                step="60"
                                            />
                                            <button
                                                type="button"
                                                class="button-link exmoau-job-type__remove-time"
                                                data-exmoau-job-remove-time="<?php echo esc_attr($dayKey); ?>"
                                            >
                                                <?php esc_html_e('Remove', 'exmoment-author'); ?>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button
                                    type="button"
                                    class="button button-secondary exmoau-job-type__add-time"
                                    data-exmoau-job-add-time="<?php echo esc_attr($dayKey); ?>"
                                >
                                    <?php esc_html_e('Add time', 'exmoment-author'); ?>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Setup meta box.
     *
     * Displays sanitized directory selections, directive options, and other
     * setup controls. Values retrieved from post meta are normalized via helper
     * methods and escaped before reaching the browser. This UI pairs with the
     * AJAX handlers documented below, which enforce the same nonce and
     * capability requirements.
     *
     * @param WP_Post $post Current post object supplied by WordPress.
     * @return void
     * @since 1.1.0
     */
    public function renderSetupMetaBox($post) {
        if (!($post instanceof WP_Post)) {
            return;
        }

        $library = $this->getLibraryStructure();
        $directories = array_values(
            array_intersect(
                $this->getMixtureDirectoriesValue($post->ID),
                array_keys($library)
            )
        );
        $uniqueness = $this->getMixtureUniquenessValue($post->ID);
        $perCategory = $this->getMixturePerCategoryValue($post->ID);
        $directiveSettings = $this->getDirectiveSettings($post->ID);
        $directivePostType = $directiveSettings['post_type'];
        $directivePostStatus = $directiveSettings['post_status'];
        $directivePostAuthor = $directiveSettings['post_author'];
        $directiveGenerationCount = $directiveSettings['generation_count'];
        $invalidDirectivePostType = $directiveSettings['invalid_post_type'];
        $invalidDirectivePostAuthor = $directiveSettings['invalid_post_author'];

        $messages = [
            /* translators: %s: Directory label. */
            'selected' => esc_html__('Selected directory: %s', 'exmoment-author'),
            /* translators: %s: Directory label. */
            'deselected' => esc_html__('Removed directory: %s', 'exmoment-author'),
            'selectAll' => esc_html__('All directories selected.', 'exmoment-author'),
            'clearAll' => esc_html__('All directory selections cleared.', 'exmoment-author'),
            /* translators: %d: Directory page number. */
            'pagination' => esc_html__('Loading directory page %d.', 'exmoment-author'),
            /* translators: %s: Directive label. */
            'directiveSet' => esc_html__('Directive updated to %s.', 'exmoment-author'),
            'directiveCleared' => esc_html__('Directive selection cleared.', 'exmoment-author'),
            /* translators: %s: Post status label. */
            'statusSet' => esc_html__('Post status set to %s.', 'exmoment-author'),
            /* translators: %s: Post author name. */
            'authorSet' => esc_html__('Post author set to %s.', 'exmoment-author'),
            'authorCleared' => esc_html__('Post author selection cleared.', 'exmoment-author'),
            'loadError' => esc_html__('Unable to load the requested tab. Please try again.', 'exmoment-author'),
        ];

        $state = [
            'postId' => $post->ID,
            'nonce' => wp_create_nonce(self::SETUP_TABS_NONCE),
            'directories' => $directories,
            'uniqueness' => $uniqueness ? '1' : '0',
            'perCategory' => (string) $perCategory,
            'directive' => $directivePostType,
            'postStatus' => $directivePostStatus,
            'postAuthor' => $directivePostAuthor > 0 ? (string) $directivePostAuthor : '',
            'generationCount' => (string) $directiveGenerationCount,
            'invalidDirectivePostType' => $invalidDirectivePostType ? '1' : '',
            'invalidDirectivePostAuthor' => $invalidDirectivePostAuthor ? '1' : '',
            'activeTab' => 'mixture',
            'messages' => $messages,
            'ajaxActions' => [
                'mixture' => self::AJAX_ACTION_MIXTURE,
                'directive' => self::AJAX_ACTION_DIRECTIVE,
            ],
        ];

        $stateJson = wp_json_encode($state);
        if (!is_string($stateJson)) {
            $stateJson = '{}';
        }

        $tabs = [
            'mixture' => [
                'label' => esc_html__('Mixture', 'exmoment-author'),
                'tab_id' => 'exmoau-job-setup-tab-mixture',
                'panel_id' => 'exmoau-job-setup-pane-mixture',
            ],
            'directive' => [
                'label' => esc_html__('Directive', 'exmoment-author'),
                'tab_id' => 'exmoau-job-setup-tab-directive',
                'panel_id' => 'exmoau-job-setup-pane-directive',
            ],
        ];

        $tablistId = 'exmoau-job-setup-tabs';
        ?>
        <div
            class="exmoau-job-setup"
            data-exmoau-job-meta="setup"
            data-exmoau-job-setup="<?php echo esc_attr($stateJson); ?>"
            data-exmoau-job-library-count="<?php echo esc_attr(count($library)); ?>"
        >
            <p class="description">
                <?php esc_html_e('Configure the content sources and publishing directive for this job.', 'exmoment-author'); ?>
            </p>
            <nav
                class="nav-tab-wrapper exmoau-job-setup__tabs"
                role="tablist"
                aria-labelledby="<?php echo esc_attr($tablistId); ?>"
                data-exmoau-job-setup-tabs
            >
                <span id="<?php echo esc_attr($tablistId); ?>" class="screen-reader-text">
                    <?php esc_html_e('Job setup tabs', 'exmoment-author'); ?>
                </span>
                <?php foreach ($tabs as $tabKey => $tabConfig) :
                    $isActive = ($tabKey === 'mixture');
                    $tabClasses = 'nav-tab';
                    if ($isActive) {
                        $tabClasses .= ' nav-tab-active';
                    }
                    ?>
                    <button
                        type="button"
                        class="<?php echo esc_attr($tabClasses); ?>"
                        role="tab"
                        id="<?php echo esc_attr($tabConfig['tab_id']); ?>"
                        data-exmoau-job-setup-tab="<?php echo esc_attr($tabKey); ?>"
                        aria-controls="<?php echo esc_attr($tabConfig['panel_id']); ?>"
                        aria-selected="<?php echo esc_attr($isActive ? 'true' : 'false'); ?>"
                        tabindex="<?php echo esc_attr($isActive ? '0' : '-1'); ?>"
                    >
                        <?php echo esc_html($tabConfig['label']); ?>
                    </button>
                <?php endforeach; ?>
            </nav>
            <div class="exmoau-job-setup__panels">
                <?php foreach ($tabs as $tabKey => $tabConfig) :
                    $isActive = ($tabKey === 'mixture');
                    $panelClasses = 'exmoau-job-setup__panel';
                    $panelClasses .= $isActive ? ' is-active' : ' exmoau-is-hidden';
                    ?>
                    <div
                        id="<?php echo esc_attr($tabConfig['panel_id']); ?>"
                        class="<?php echo esc_attr($panelClasses); ?>"
                        role="tabpanel"
                        aria-labelledby="<?php echo esc_attr($tabConfig['tab_id']); ?>"
                        aria-hidden="<?php echo esc_attr($isActive ? 'false' : 'true'); ?>"
                        data-exmoau-job-setup-pane="<?php echo esc_attr($tabKey); ?>"
                    >
                        <div data-exmoau-job-setup-panel-inner="<?php echo esc_attr($tabKey); ?>"></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="exmoau-job-setup__loading" data-exmoau-job-setup-loading aria-hidden="true">
                <span class="spinner"></span>
                <span class="exmoau-job-setup__loading-text"><?php esc_html_e('Loading setup options…', 'exmoment-author'); ?></span>
            </div>
            <div class="exmoau-job-setup__status screen-reader-text" aria-live="polite" data-exmoau-job-setup-status></div>
            <div class="exmoau-job-setup__fields" aria-hidden="true">
                <div data-exmoau-job-setup-directories-field>
                    <?php foreach ($directories as $directory) : ?>
                        <input type="hidden" name="exmoau_setup_mixture_directories[]" value="<?php echo esc_attr($directory); ?>" />
                    <?php endforeach; ?>
                </div>
                <input
                    type="hidden"
                    name="exmoau_setup_directive_post_type"
                    value="<?php echo esc_attr($directivePostType); ?>"
                    data-exmoau-job-setup-directive-field
                />
                <input
                    type="hidden"
                    name="exmoau_setup_directive_post_status"
                    value="<?php echo esc_attr($directivePostStatus); ?>"
                    data-exmoau-job-setup-directive-status-field
                />
                <input
                    type="hidden"
                    name="exmoau_setup_directive_post_author"
                    value="<?php echo esc_attr($directivePostAuthor > 0 ? (string) $directivePostAuthor : ''); ?>"
                    data-exmoau-job-setup-directive-author-field
                />
                <input
                    type="hidden"
                    name="exmoau_setup_directive_generation_count"
                    value="<?php echo esc_attr($directiveGenerationCount); ?>"
                    data-exmoau-job-setup-directive-generation-field
                />
                <input
                    type="hidden"
                    name="exmoau_job_setup_active_tab"
                    value="mixture"
                    data-exmoau-job-setup-active-tab
                />
            </div>
        </div>
        <?php
    }

    /**
     * Prepare and validate AJAX request context for setup tabs.
     *
     * Validates the AJAX nonce, referrer host, and current user capabilities
     * before extracting sanitized request variables. Callers must inspect the
     * returned value: a `WP_Error` is sent back to the browser, while a
     * successful result contains the normalized context used by tab rendering.
     *
     * @return array{
     *     post_id:int,
     *     selected_directories:string[],
     *     directive:string,
     *     post_status:string,
     *     post_author:string|int,
     *     generation_count:string|int,
     *     per_category:string,
     *     page:int,
     *     uniqueness:bool,
     *     invalid_post_type:bool,
     *     invalid_post_author:bool
     * }|WP_Error Normalized context array or error when validation fails.
     * @since 1.1.0
     */
    private function prepareSetupAjaxRequest() {
        if (!$this->refererMatchesCurrentHost()) {
            return new WP_Error('invalid_referer', esc_html__('Invalid request source.', 'exmoment-author'));
        }

        $nonce = '';
        $nonceValue = $this->getScalarPostValue('nonce');
        if ($nonceValue === null && isset($_POST['nonce'])) {
            return new WP_Error('invalid_nonce', esc_html__('Security validation failed. Please refresh and try again.', 'exmoment-author'));
        }
        if ($nonceValue !== null) {
            $nonce = sanitize_text_field($nonceValue);
        }
        if (
            $nonce === '' ||
            !wp_verify_nonce($nonce, self::SETUP_TABS_NONCE)
        ) {
            return new WP_Error('invalid_nonce', esc_html__('Security validation failed. Please refresh and try again.', 'exmoment-author'));
        }

        $postId = 0;
        $postIdValue = $this->getScalarPostValue('post_id');
        if ($postIdValue === null && isset($_POST['post_id'])) {
            return new WP_Error('invalid_post', esc_html__('The requested job could not be found.', 'exmoment-author'));
        }
        if ($postIdValue !== null) {
            $postId = absint($postIdValue);
            if ($postId < 1) {
                return new WP_Error('invalid_post', esc_html__('The requested job could not be found.', 'exmoment-author'));
            }
        }

        if ($postId > 0) {
            $post = get_post($postId);
            if (!($post instanceof WP_Post) || $post->post_type !== self::POST_TYPE) {
                return new WP_Error('invalid_post', esc_html__('The requested job could not be found.', 'exmoment-author'));
            }

            if (!current_user_can('edit_post', $postId)) {
                return new WP_Error('forbidden', esc_html__('You are not allowed to edit this job.', 'exmoment-author'));
            }
        } else {
            $postTypeObject = get_post_type_object(self::POST_TYPE);
            $capability = 'edit_posts';
            if ($postTypeObject && isset($postTypeObject->cap->create_posts)) {
                $capability = $postTypeObject->cap->create_posts;
            }

            if (!current_user_can($capability)) {
                return new WP_Error('forbidden', esc_html__('You are not allowed to create jobs.', 'exmoment-author'));
            }
        }

        $library = $this->getLibraryStructure();
        $availableDirectories = array_keys($library);

        $selectedDirectories = array();
        if (isset($_POST['selected_directories'])) {
            $selectedDirectoriesInput = wp_unslash($_POST['selected_directories']);
            if (is_array($selectedDirectoriesInput)) {
                $selectedDirectoriesInput = $selectedDirectoriesInput;
            } elseif (is_scalar($selectedDirectoriesInput)) {
                $selectedDirectoriesInput = array($selectedDirectoriesInput);
            } else {
                return new WP_Error('invalid_directories', esc_html__('Invalid directory selection.', 'exmoment-author'));
            }

            $sanitizedDirectories = array();
            foreach ($selectedDirectoriesInput as $directoryValue) {
                if (!is_scalar($directoryValue)) {
                    return new WP_Error('invalid_directories', esc_html__('Invalid directory selection.', 'exmoment-author'));
                }

                $directoryValue = sanitize_text_field((string) $directoryValue);
                if ($directoryValue === '') {
                    continue;
                }

                $sanitizedDirectories[] = $directoryValue;
            }

            if (!empty($sanitizedDirectories)) {
                $selectionErrors = new WP_Error();
                $selectedDirectories = $this->sanitizeDirectorySelection(
                    $sanitizedDirectories,
                    $availableDirectories,
                    $selectionErrors
                );
                if ($selectionErrors->has_errors()) {
                    return new WP_Error('invalid_directories', esc_html__('Invalid directory selection.', 'exmoment-author'));
                }
            }
        }

        $directive = '';
        $directiveValue = $this->getScalarPostValue('directive');
        if ($directiveValue === null && isset($_POST['directive'])) {
            return new WP_Error('invalid_directive_post_type', esc_html__('Select a valid post type that supports post content.', 'exmoment-author'));
        }
        if ($directiveValue !== null) {
            $directiveCandidate = sanitize_key($directiveValue);
            $directive = $this->sanitizeDirectivePostType($directiveCandidate);
            if ($directive === null) {
                return new WP_Error('invalid_directive_post_type', esc_html__('Select a valid post type that supports post content.', 'exmoment-author'));
            }
        }

        $postStatus = '';
        $postStatusValue = $this->getScalarPostValue('post_status');
        if ($postStatusValue === null && isset($_POST['post_status'])) {
            return new WP_Error('invalid_post_status', esc_html__('Select a valid post status.', 'exmoment-author'));
        }
        if ($postStatusValue !== null) {
            $postStatus = $this->sanitizeDirectivePostStatus(sanitize_key($postStatusValue));
        }

        $postAuthor = '';
        $postAuthorValue = $this->getScalarPostValue('post_author');
        if ($postAuthorValue === null && isset($_POST['post_author'])) {
            return new WP_Error('invalid_post_author', esc_html__('Select a valid post author.', 'exmoment-author'));
        }
        if ($postAuthorValue !== null) {
            $postAuthorCandidate = sanitize_text_field($postAuthorValue);
            $postAuthorId = $this->sanitizeDirectivePostAuthor($postAuthorCandidate);
            if ($postAuthorId === null) {
                return new WP_Error('invalid_post_author', esc_html__('Select a valid post author.', 'exmoment-author'));
            }
            $postAuthor = $postAuthorId;
        }

        $generationCount = '';
        $generationValue = $this->getScalarPostValue('generation_count');
        if ($generationValue === null && isset($_POST['generation_count'])) {
            return new WP_Error('invalid_generation_count', esc_html__('Generation count must be a whole number.', 'exmoment-author'));
        }
        if ($generationValue !== null) {
            $generationErrors = new WP_Error();
            $generationCount = $this->sanitizeDirectiveGenerationCount($generationValue, $generationErrors);
            if ($generationErrors->has_errors()) {
                return new WP_Error('invalid_generation_count', esc_html__('Generation count must be a whole number.', 'exmoment-author'));
            }
        }

        $perCategory = '';
        $perCategoryValue = $this->getScalarPostValue('per_category');
        if ($perCategoryValue === null && isset($_POST['per_category'])) {
            return new WP_Error('invalid_per_category', esc_html__('Articles per category must be provided as a whole number.', 'exmoment-author'));
        }
        if ($perCategoryValue !== null) {
            $perCategoryErrors = new WP_Error();
            $perCategoryAdjusted = false;
            $perCategory = $this->sanitizeMixtureArticlesPerCategory(
                $perCategoryValue,
                self::DEFAULT_MIXTURE_PER_CATEGORY,
                $perCategoryAdjusted,
                $perCategoryErrors
            );
            if ($perCategoryErrors->has_errors()) {
                return new WP_Error('invalid_per_category', esc_html__('Articles per category must be provided as a whole number.', 'exmoment-author'));
            }
        }
        $page = 1;
        $pageValue = $this->getScalarPostValue('page');
        if ($pageValue === null && isset($_POST['page'])) {
            return new WP_Error('invalid_page', esc_html__('Invalid page selection.', 'exmoment-author'));
        }
        if ($pageValue !== null) {
            $page = absint($pageValue);
            if ($page < 1) {
                return new WP_Error('invalid_page', esc_html__('Invalid page selection.', 'exmoment-author'));
            }
        }

        $uniqueness = false;
        $rawUniqueness = $this->getScalarPostValue('uniqueness');
        if ($rawUniqueness === null && isset($_POST['uniqueness'])) {
            return new WP_Error('invalid_uniqueness', esc_html__('Invalid uniqueness selection.', 'exmoment-author'));
        }
        if ($rawUniqueness !== null) {
            $uniqueness = wp_validate_boolean(sanitize_text_field($rawUniqueness));
        }

        $invalidPostType = false;
        $rawInvalidPostType = $this->getScalarPostValue('invalid_post_type');
        if ($rawInvalidPostType === null && isset($_POST['invalid_post_type'])) {
            return new WP_Error('invalid_post_type_flag', esc_html__('Invalid post type flag.', 'exmoment-author'));
        }
        if ($rawInvalidPostType !== null) {
            $invalidPostType = wp_validate_boolean(sanitize_text_field($rawInvalidPostType));
        }

        $invalidPostAuthor = false;
        $rawInvalidPostAuthor = $this->getScalarPostValue('invalid_post_author');
        if ($rawInvalidPostAuthor === null && isset($_POST['invalid_post_author'])) {
            return new WP_Error('invalid_post_author_flag', esc_html__('Invalid post author flag.', 'exmoment-author'));
        }
        if ($rawInvalidPostAuthor !== null) {
            $invalidPostAuthor = wp_validate_boolean(sanitize_text_field($rawInvalidPostAuthor));
        }

        return array(
            'post_id' => $postId,
            'selected_directories' => $selectedDirectories,
            'directive' => $directive,
            'post_status' => $postStatus,
            'post_author' => $postAuthor,
            'generation_count' => $generationCount,
            'per_category' => $perCategory,
            'page' => $page,
            'uniqueness' => $uniqueness,
            'invalid_post_type' => $invalidPostType,
            'invalid_post_author' => $invalidPostAuthor,
        );
    }

    /**
     * Send a JSON error response and halt execution.
     *
     * @param WP_Error $error  Error instance.
     * @param int      $status HTTP status code.
     * @return void
     * @since 1.1.0
     */
    private function sendAjaxError(WP_Error $error, $status = 400) {
        $message = $error->get_error_message();
        if ($message === '') {
            $message = esc_html__('An unexpected error occurred.', 'exmoment-author');
        }

        wp_send_json_error(array('message' => $message), $status);
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
     * Determine if the HTTP referrer matches the current host.
     *
     * Used by AJAX callbacks to guard against cross-origin requests before
     * evaluating nonces. Relies on `home_url()` as the canonical host.
     *
     * @return bool True when the sanitized referrer host matches the site host.
     * @since 1.1.0
     */
    private function refererMatchesCurrentHost() {
        if (empty($_SERVER['HTTP_REFERER'])) {
            return false;
        }

        $referer = esc_url_raw(wp_unslash((string) $_SERVER['HTTP_REFERER']));
        if ($referer === '') {
            return false;
        }

        $refererHost = wp_parse_url($referer, PHP_URL_HOST);
        if (!$refererHost) {
            return false;
        }

        $siteHost = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!$siteHost) {
            return false;
        }

        return strcasecmp($refererHost, $siteHost) === 0;
    }

    /**
     * Retrieve the preferred page size for directory tiles.
     *
     * @param int $totalDirectories Total number of directories.
     * @return int Page size constrained to available directory count.
     * @since 1.1.0
     */
    private function getMixturePageSize($totalDirectories) {
        $pageSize = max(1, (int) self::MIXTURE_PAGE_SIZE);

        if ($totalDirectories > 0 && $pageSize > $totalDirectories) {
            return $totalDirectories;
        }

        return $pageSize;
    }

    /**
     * Clamp the requested page number to the valid range.
     *
     * @param int $page       Requested page number.
     * @param int $totalPages Total available pages.
     * @return int Validated page number within range.
     * @since 1.1.0
     */
    private function sanitizePageNumber($page, $totalPages) {
        $page = max(1, (int) $page);
        $totalPages = max(1, (int) $totalPages);

        if ($page > $totalPages) {
            $page = $totalPages;
        }

        return $page;
    }

    /**
     * Build the Mixture tab markup fragment.
     *
     * @param string[]                            $pageDirectories  Directories for the current page.
     * @param string[]                            $selected         Selected directories.
     * @param bool                                $uniqueOnly       Uniqueness flag.
     * @param int                                 $perCategory      Articles per category limit.
     * @param array<string, array<string, string>> $library          Library structure.
     * @param int                                 $page             Current page number.
     * @param int                                 $totalPages       Total number of pages.
     * @param int                                 $totalDirectories Total directory count.
     * @param int                                 $pageSize         Page size.
     * @param array<string, bool>                 $options          Optional flags (e.g. `per_category_adjusted`).
     * @return string Rendered HTML fragment for the Mixture tab.
     * @since 1.1.0
     */
    private function buildMixturePanelMarkup($pageDirectories, $selected, $uniqueOnly, $perCategory, $library, $page, $totalPages, $totalDirectories, $pageSize, $options = []) {
        $perCategory = (int) $perCategory;
        if ($perCategory < self::MIXTURE_PER_CATEGORY_MIN) {
            $perCategory = self::MIXTURE_PER_CATEGORY_MIN;
        } elseif ($perCategory > self::MIXTURE_PER_CATEGORY_MAX) {
            $perCategory = self::MIXTURE_PER_CATEGORY_MAX;
        }

        $perCategoryAdjusted = !empty($options['per_category_adjusted']);

        ob_start();
        ?>
        <div
            class="exmoau-job-setup__mixture"
            data-exmoau-job-mixture
            data-current-page="<?php echo esc_attr($page); ?>"
            data-total-pages="<?php echo esc_attr($totalPages); ?>"
            data-total-directories="<?php echo esc_attr($totalDirectories); ?>"
            data-page-size="<?php echo esc_attr($pageSize); ?>"
        >
            <?php if ($perCategoryAdjusted) : ?>
                <div class="notice notice-warning">
                    <p>
                        <?php
                        printf(
                            /* translators: 1: Minimum articles per category, 2: Maximum articles per category. */
                            esc_html__('Articles per category must stay between %1$d and %2$d. The value shown has been adjusted to fit this range.', 'exmoment-author'),
                            absint(self::MIXTURE_PER_CATEGORY_MIN),
                            absint(self::MIXTURE_PER_CATEGORY_MAX)
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>
            <div class="exmoau-job-setup__group">
                <label class="exmoau-job-setup__checkbox">
                    <input
                        type="checkbox"
                        name="exmoau_setup_mixture_uniqueness"
                        value="1"
                        <?php checked($uniqueOnly); ?>
                    />
                    <span><?php esc_html_e('Use unique articles only', 'exmoment-author'); ?></span>
                </label>
            </div>
            <div class="exmoau-job-setup__group">
                <label class="exmoau-job-setup__field" for="exmoau_setup_mixture_per_category">
                    <span class="exmoau-job-setup__label">
                        <?php esc_html_e('Articles per category', 'exmoment-author'); ?>
                    </span>
                    <input
                        type="number"
                        id="exmoau_setup_mixture_per_category"
                        name="exmoau_setup_mixture_per_category"
                        min="<?php echo esc_attr(self::MIXTURE_PER_CATEGORY_MIN); ?>"
                        max="<?php echo esc_attr(self::MIXTURE_PER_CATEGORY_MAX); ?>"
                        value="<?php echo esc_attr($perCategory); ?>"
                        required
                        data-exmoau-job-mixture-per-category
                    />
                </label>
                <p class="description exmoau-job-setup__description">
                    <?php esc_html_e('Collect up to this many articles from each selected category and send them as separate messages to the AI.', 'exmoment-author'); ?>
                </p>
            </div>
            <?php if ($totalDirectories === 0) : ?>
                <div class="notice notice-warning">
                    <p>
                        <?php esc_html_e('No directories were found inside uploads/exmoau-library.', 'exmoment-author'); ?>
                    </p>
                </div>
            <?php else : ?>
                <?php if ($totalDirectories > self::MIXTURE_SELECT_TOOLBAR_THRESHOLD) : ?>
                    <div class="exmoau-job-setup__mixture-toolbar" data-exmoau-job-mixture-toolbar>
                        <button type="button" class="button-link exmoau-job-setup__mixture-action" data-exmoau-job-mixture-select="all">
                            <?php esc_html_e('Select All', 'exmoment-author'); ?>
                        </button>
                        <button type="button" class="button-link exmoau-job-setup__mixture-action" data-exmoau-job-mixture-select="none">
                            <?php esc_html_e('Clear All', 'exmoment-author'); ?>
                        </button>
                    </div>
                <?php endif; ?>
                <div
                    class="exmoau-job-setup__tile-grid"
                    role="group"
                    aria-label="<?php esc_attr_e('Directory choices', 'exmoment-author'); ?>"
                    data-exmoau-job-mixture-tiles
                >
                    <?php foreach ($pageDirectories as $directory) :
                        $label = isset($library[$directory]['label']) ? $library[$directory]['label'] : $directory;
                        $isSelected = in_array($directory, $selected, true);
                        $buttonClasses = 'button exmoau-job-setup__tile';
                        $buttonClasses .= $isSelected ? ' button-primary is-selected' : ' button-secondary';
                        ?>
                        <button
                            type="button"
                            class="<?php echo esc_attr($buttonClasses); ?>"
                            title="<?php echo esc_attr($label); ?>"
                            data-exmoau-job-mixture-tile="<?php echo esc_attr($directory); ?>"
                            aria-pressed="<?php echo esc_attr($isSelected ? 'true' : 'false'); ?>"
                        >
                            <span class="exmoau-job-setup__tile-label"><?php echo esc_html($label); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php if ($totalPages > 1) : ?>
                    <nav class="exmoau-job-setup__pagination" role="navigation" aria-label="<?php esc_attr_e('Directory pages', 'exmoment-author'); ?>">
                        <button
                            type="button"
                            class="button button-secondary exmoau-job-setup__pagination-prev"
                            data-exmoau-job-mixture-page="<?php echo esc_attr(max(1, $page - 1)); ?>"
                            <?php disabled($page <= 1); ?>
                        >
                            <?php esc_html_e('Previous', 'exmoment-author'); ?>
                        </button>
                        <span class="exmoau-job-setup__pagination-status">
                            <?php
                            /* translators: 1: Current page number, 2: Total number of pages. */
                            printf(esc_html__('Page %1$d of %2$d', 'exmoment-author'), (int) $page, (int) $totalPages);
                            ?>
                        </span>
                        <button
                            type="button"
                            class="button button-secondary exmoau-job-setup__pagination-next"
                            data-exmoau-job-mixture-page="<?php echo esc_attr(min($totalPages, $page + 1)); ?>"
                            <?php disabled($page >= $totalPages); ?>
                        >
                            <?php esc_html_e('Next', 'exmoment-author'); ?>
                        </button>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
            <div class="screen-reader-text" aria-live="polite" data-exmoau-job-mixture-status></div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Build the Directive tab markup fragment.
     *
     * @param array<string, string> $postTypes        Available post types.
     * @param string                $selectedPostType Selected post type.
     * @param string                $selectedStatus   Selected post status.
     * @param int                   $selectedAuthor   Selected author ID.
     * @param array<int, string>    $authorOptions    Available author options.
     * @param array<string, mixed>  $options          Additional rendering options (e.g. invalid flags, generation count).
     * @return string Rendered HTML fragment for the Directive tab.
     * @since 1.1.0
     */
    private function buildDirectivePanelMarkup($postTypes, $selectedPostType, $selectedStatus, $selectedAuthor, array $authorOptions, $options = []) {
        $selectedAuthor = (int) $selectedAuthor;
        $invalidPostType = !empty($options['invalid_post_type']);
        $invalidPostAuthor = !empty($options['invalid_post_author']);
        $statusValue = ($selectedStatus === 'publish') ? 'publish' : self::DEFAULT_DIRECTIVE_POST_STATUS;
        $hasAuthorOptions = array_key_exists('has_author_options', $options)
            ? (bool) $options['has_author_options']
            : !empty($authorOptions);
        $generationCount = isset($options['generation_count']) ? (int) $options['generation_count'] : self::DEFAULT_DIRECTIVE_GENERATION_COUNT;
        if ($generationCount < self::DIRECTIVE_GENERATION_MIN) {
            $generationCount = self::DIRECTIVE_GENERATION_MIN;
        }

        if ($selectedAuthor > 0 && !isset($authorOptions[$selectedAuthor])) {
            $selectedAuthor = 0;
        }

        if (!$hasAuthorOptions) {
            $selectedAuthor = 0;
        }

        asort($authorOptions, SORT_NATURAL | SORT_FLAG_CASE);
        $authorFieldDisabled = !$hasAuthorOptions;

        ob_start();
        ?>
        <div class="exmoau-job-setup__directive" data-exmoau-job-directive>
            <?php if ($invalidPostType) : ?>
                <div class="notice notice-warning">
                    <p>
                        <?php esc_html_e('The previously selected post type is no longer available. Please choose another option.', 'exmoment-author'); ?>
                    </p>
                </div>
            <?php endif; ?>
            <div class="exmoau-job-setup__group">
                <label class="exmoau-job-setup__field" for="exmoau_setup_directive_post_type_display">
                    <span class="exmoau-job-setup__label">
                        <?php esc_html_e('Post type', 'exmoment-author'); ?>
                    </span>
                    <select
                        id="exmoau_setup_directive_post_type_display"
                        name="exmoau_setup_directive_post_type_display"
                        data-exmoau-job-directive-select
                    >
                        <option value="">
                            <?php esc_html_e('Select a post type', 'exmoment-author'); ?>
                        </option>
                        <?php foreach ($postTypes as $postType => $label) : ?>
                            <option value="<?php echo esc_attr($postType); ?>" <?php selected($selectedPostType, $postType); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <p class="description exmoau-job-setup__description">
                    <?php esc_html_e('Choose which post type should receive generated content. Only post types that support the content editor and that you can create are available.', 'exmoment-author'); ?>
                </p>
            </div>
            <div class="exmoau-job-setup__group">
                <label class="exmoau-job-setup__field" for="exmoau_setup_directive_post_status_display">
                    <span class="exmoau-job-setup__label">
                        <?php esc_html_e('Post status', 'exmoment-author'); ?>
                    </span>
                    <select
                        id="exmoau_setup_directive_post_status_display"
                        name="exmoau_setup_directive_post_status_display"
                        data-exmoau-job-directive-status
                    >
                        <option value="draft" <?php selected($statusValue, 'draft'); ?>>
                            <?php esc_html_e('Draft', 'exmoment-author'); ?>
                        </option>
                        <option value="publish" <?php selected($statusValue, 'publish'); ?>>
                            <?php esc_html_e('Published', 'exmoment-author'); ?>
                        </option>
                    </select>
                </label>
                <p class="description exmoau-job-setup__description">
                    <?php esc_html_e('Decide whether generated posts should publish immediately or remain in draft for review.', 'exmoment-author'); ?>
                </p>
            </div>
            <div class="exmoau-job-setup__group">
                <?php if ($invalidPostAuthor) : ?>
                    <div class="notice notice-warning">
                        <p>
                            <?php esc_html_e('The previously selected author no longer meets the requirements. Please choose another user.', 'exmoment-author'); ?>
                        </p>
                    </div>
                <?php endif; ?>
                <?php if (empty($authorOptions)) : ?>
                    <div class="notice notice-info">
                        <p>
                            <?php esc_html_e('No eligible authors are available. Create a user with a role above Subscriber to assign ownership.', 'exmoment-author'); ?>
                        </p>
                    </div>
                <?php endif; ?>
                <label class="exmoau-job-setup__field" for="exmoau_setup_directive_post_author_select">
                    <span class="exmoau-job-setup__label">
                        <?php esc_html_e('Post author', 'exmoment-author'); ?>
                    </span>
                    <select
                        id="exmoau_setup_directive_post_author_select"
                        class="exmoau-job-setup__author-select"
                        data-exmoau-job-directive-author-select
                        <?php disabled($authorFieldDisabled); ?>
                        aria-disabled="<?php echo esc_attr($authorFieldDisabled ? 'true' : 'false'); ?>"
                    >
                        <option value="">
                            <?php esc_html_e('Select an author', 'exmoment-author'); ?>
                        </option>
                        <?php foreach ($authorOptions as $authorId => $label) : ?>
                            <option value="<?php echo esc_attr($authorId); ?>" <?php selected($selectedAuthor, (int) $authorId); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <p class="description exmoau-job-setup__description">
                    <?php esc_html_e('Assign ownership for generated posts. Only users with roles above Subscriber appear here.', 'exmoment-author'); ?>
                </p>
                <label class="exmoau-job-setup__field" for="exmoau_setup_directive_generation_count">
                    <span class="exmoau-job-setup__label">
                        <?php esc_html_e('Number of items to generate', 'exmoment-author'); ?>
                    </span>
                    <input
                        id="exmoau_setup_directive_generation_count"
                        type="number"
                        class="exmoau-job-setup__input"
                        name="exmoau_setup_directive_generation_count"
                        value="<?php echo esc_attr($generationCount); ?>"
                        min="<?php echo esc_attr(self::DIRECTIVE_GENERATION_MIN); ?>"
                        data-exmoau-job-directive-generation
                    />
                </label>
                <p class="description exmoau-job-setup__description">
                    <?php esc_html_e('Default is 1. This controls how many articles the directive produces per run.', 'exmoment-author'); ?>
                </p>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Persist job meta after validation.
     *
     * Expects the `exmoau_job_meta_nonce` created in {@see renderJobTypeMetaBox()}
     * and requires the current user to have the `edit_post` capability for the
     * job. All incoming values are unslashed and sanitized via dedicated helper
     * methods before being saved to post meta. When validation fails, the
     * method records translated error messages for {@see renderAdminNotices()}.
     *
     * @param int     $postId Job post identifier provided by WordPress.
     * @param WP_Post $post   Post object supplied by WordPress.
     * @return void
     * @since 1.1.0
     */
    public function saveJobMeta($postId, $post) {
        if (!($post instanceof WP_Post)) {
            return;
        }

        if ($post->post_type !== self::POST_TYPE) {
            return;
        }

        if (!isset($_POST[self::NONCE_FIELD])) {
            return;
        }

        $nonce = '';
        $rawNonce = $this->getScalarPostValue(self::NONCE_FIELD);
        if ($rawNonce === null) {
            return;
        }
        $nonce = sanitize_text_field($rawNonce);
        if (
            $nonce === '' ||
            !wp_verify_nonce($nonce, self::NONCE_ACTION)
        ) {
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

        $library = $this->getLibraryStructure();
        $availableDirectories = array_keys($library);

        $errors = new WP_Error();

        $jobTypeValue = '';
        $jobTypeRaw = $this->getScalarPostValue('exmoau_job_type');
        if ($jobTypeRaw === null && isset($_POST['exmoau_job_type'])) {
            $errors->add('invalid_job_type_type', esc_html__('Invalid job type selection.', 'exmoment-author'));
        } elseif ($jobTypeRaw !== null) {
            $jobTypeValue = sanitize_text_field($jobTypeRaw);
        }
        $jobType = $this->sanitizeJobType($jobTypeValue);
        $scheduledDatetime = '';
        $repeatingDays = [];
        $repeatingHours = [];

        if ($jobType === '') {
            $jobType = self::DEFAULT_JOB_TYPE;
        }

        if (!in_array($jobType, self::JOB_TYPES, true)) {
            $errors->add('invalid_job_type', esc_html__('Invalid job type selected.', 'exmoment-author'));
            $jobType = self::DEFAULT_JOB_TYPE;
        }

        if ($jobType === 'single_scheduled') {
            $rawDatetime = '';
            $datetimeValue = $this->getScalarPostValue('exmoau_job_single_scheduled_datetime');
            if ($datetimeValue === null && isset($_POST['exmoau_job_single_scheduled_datetime'])) {
                $errors->add('invalid_datetime_type', esc_html__('Please select a valid schedule date and time.', 'exmoment-author'));
            } elseif ($datetimeValue !== null) {
                $rawDatetime = sanitize_text_field($datetimeValue);
            }
            $parsedDatetime = $this->parseLocalDatetime($rawDatetime);
            if ($parsedDatetime === null) {
                $errors->add('invalid_datetime', esc_html__('Please select a valid schedule date and time.', 'exmoment-author'));
            } else {
                $scheduledDatetime = $parsedDatetime;
            }
        }

        if ($jobType === 'repeating_scheduled') {
            $rawDays = [];
            if (isset($_POST['exmoau_job_repeating_days'])) {
                $rawDaysInput = wp_unslash($_POST['exmoau_job_repeating_days']);
                if (!is_array($rawDaysInput)) {
                    $rawDaysInput = [$rawDaysInput];
                }

                foreach ($rawDaysInput as $dayValue) {
                    if (!is_scalar($dayValue)) {
                        $errors->add(
                            'invalid_repeating_day_type',
                            esc_html__('Repeating days must be provided as strings.', 'exmoment-author')
                        );
                        continue;
                    }

                    $sanitizedDay = sanitize_text_field((string) $dayValue);
                    if ($sanitizedDay === '') {
                        continue;
                    }

                    $rawDays[] = $sanitizedDay;
                }

                if (!empty($rawDays)) {
                    $rawDays = array_values(array_unique($rawDays));
                }
            }

            $rawHoursInput = isset($_POST['exmoau_job_repeating_hours_by_day'])
                ? wp_unslash($_POST['exmoau_job_repeating_hours_by_day'])
                : [];
            $rawHours = [];
            $hasInvalidRepeatingHourValue = false;
            if (isset($_POST['exmoau_job_repeating_hours_by_day']) && !is_array($rawHoursInput)) {
                $errors->add(
                    'invalid_repeating_hours_type',
                    esc_html__('Repeating hours must be provided as a list.', 'exmoment-author')
                );
            } elseif (is_array($rawHoursInput)) {
                foreach ($rawHoursInput as $day => $times) {
                    if (!is_scalar($day)) {
                        $errors->add(
                            'invalid_repeating_hours_day',
                            esc_html__('Repeating hours contain invalid day values.', 'exmoment-author')
                        );
                        continue;
                    }

                    $dayKey = sanitize_key((string) $day);
                    if ($dayKey === '') {
                        $errors->add(
                            'invalid_repeating_hours_day',
                            esc_html__('Repeating hours contain invalid day values.', 'exmoment-author')
                        );
                        continue;
                    }

                    if (!is_array($times)) {
                        $times = [$times];
                    }

                    foreach ($times as $time) {
                        if (!is_scalar($time)) {
                            $hasInvalidRepeatingHourValue = true;
                            continue;
                        }

                        $timeValue = sanitize_text_field((string) $time);
                        if ($timeValue === '') {
                            continue;
                        }

                        $rawHours[$dayKey][] = $timeValue;
                    }
                }
            }

            if ($hasInvalidRepeatingHourValue) {
                $errors->add(
                    'invalid_repeating_hours_time',
                    esc_html__('Repeating hours contain invalid time values.', 'exmoment-author')
                );
            }

            $repeatingDays = $this->sanitizeRepeatingDays($rawDays);
            $repeatingHours = $this->sanitizeRepeatingHours($rawHours, $repeatingDays, $errors);

            if (empty($repeatingDays)) {
                $errors->add('missing_repeating_days', esc_html__('Please select at least one day and time for the repeating schedule.', 'exmoment-author'));
            }
        }

        $selectedDirectoriesInput = [];
        if (isset($_POST['exmoau_setup_mixture_directories'])) {
            $mixtureDirectories = wp_unslash($_POST['exmoau_setup_mixture_directories']);
            if (!is_array($mixtureDirectories)) {
                $mixtureDirectories = [$mixtureDirectories];
            }

            foreach ($mixtureDirectories as $directoryValue) {
                if (!is_scalar($directoryValue)) {
                    $errors->add(
                        'invalid_mixture_directory_type',
                        esc_html__('Mixture directories must be provided as strings.', 'exmoment-author')
                    );
                    continue;
                }

                $sanitizedDirectory = sanitize_text_field((string) $directoryValue);
                if ($sanitizedDirectory === '') {
                    continue;
                }

                $selectedDirectoriesInput[] = $sanitizedDirectory;
            }

            if (!empty($selectedDirectoriesInput)) {
                $selectedDirectoriesInput = array_values(array_unique($selectedDirectoriesInput));
            }
        }

        $selectedDirectories = $this->sanitizeDirectorySelection(
            $selectedDirectoriesInput,
            $availableDirectories,
            $errors
        );

        $uniqueOnly = false;
        $rawUniqueness = $this->getScalarPostValue('exmoau_setup_mixture_uniqueness');
        if ($rawUniqueness === null && isset($_POST['exmoau_setup_mixture_uniqueness'])) {
            $errors->add(
                'invalid_mixture_uniqueness',
                esc_html__('Invalid uniqueness selection.', 'exmoment-author')
            );
        } elseif ($rawUniqueness !== null) {
            $uniqueOnly = wp_validate_boolean(sanitize_text_field($rawUniqueness));
        }
        $previousPerCategory = $this->getMixturePerCategoryValue($postId);
        $perCategoryAdjusted = false;
        $perCategoryInput = '';
        $perCategoryRaw = $this->getScalarPostValue('exmoau_setup_mixture_per_category');
        if ($perCategoryRaw === null && isset($_POST['exmoau_setup_mixture_per_category'])) {
            $errors->add(
                'invalid_mixture_per_category_type',
                esc_html__('Articles per category must be provided as a whole number.', 'exmoment-author')
            );
        } elseif ($perCategoryRaw !== null) {
            $perCategoryInput = sanitize_text_field($perCategoryRaw);
        }
        $perCategoryValue = $this->sanitizeMixtureArticlesPerCategory(
            $perCategoryInput,
            $previousPerCategory,
            $perCategoryAdjusted,
            $errors
        );

        $directivePostTypeInput = '';
        $directivePostTypeRaw = $this->getScalarPostValue('exmoau_setup_directive_post_type');
        if ($directivePostTypeRaw === null && isset($_POST['exmoau_setup_directive_post_type'])) {
            $errors->add(
                'invalid_directive_post_type',
                esc_html__('Select a valid post type that supports post content.', 'exmoment-author')
            );
        } elseif ($directivePostTypeRaw !== null) {
            $directivePostTypeInput = sanitize_key($directivePostTypeRaw);
        }
        $directivePostType = $this->sanitizeDirectivePostType($directivePostTypeInput);
        if ($directivePostType === null) {
            $errors->add('invalid_directive_post_type', esc_html__('Select a valid post type that supports post content.', 'exmoment-author'));
        }

        $availableDirectiveAuthors = $this->getEligibleDirectiveAuthorOptions();
        $allowEmptyAuthorSelection = empty($availableDirectiveAuthors);

        $directivePostStatusInput = '';
        $directivePostStatusRaw = $this->getScalarPostValue('exmoau_setup_directive_post_status');
        if ($directivePostStatusRaw === null && isset($_POST['exmoau_setup_directive_post_status'])) {
            $errors->add(
                'invalid_directive_post_status',
                esc_html__('Select a valid post status.', 'exmoment-author')
            );
        } elseif ($directivePostStatusRaw !== null) {
            $directivePostStatusInput = sanitize_key($directivePostStatusRaw);
        }
        $directivePostStatus = $this->sanitizeDirectivePostStatus($directivePostStatusInput);
        if ($directivePostStatus === '') {
            $directivePostStatus = self::DEFAULT_DIRECTIVE_POST_STATUS;
        }

        $directivePostAuthorInput = '';
        $directivePostAuthorRaw = $this->getScalarPostValue('exmoau_setup_directive_post_author');
        if ($directivePostAuthorRaw === null && isset($_POST['exmoau_setup_directive_post_author'])) {
            $errors->add(
                'invalid_directive_post_author',
                esc_html__('Select a valid post author with editorial access.', 'exmoment-author')
            );
        } elseif ($directivePostAuthorRaw !== null) {
            $directivePostAuthorInput = sanitize_text_field($directivePostAuthorRaw);
        }
        $directivePostAuthor = $this->sanitizeDirectivePostAuthor(
            $directivePostAuthorInput,
            $allowEmptyAuthorSelection
        );
        if ($directivePostAuthor === null) {
            if ($allowEmptyAuthorSelection) {
                $directivePostAuthor = 0;
            } else {
                $errors->add(
                    'invalid_directive_post_author',
                    esc_html__('Select a valid post author with editorial access.', 'exmoment-author')
                );
            }
        }

        $directiveGenerationCountInput = '';
        $directiveGenerationCountRaw = $this->getScalarPostValue('exmoau_setup_directive_generation_count');
        if ($directiveGenerationCountRaw === null && isset($_POST['exmoau_setup_directive_generation_count'])) {
            $errors->add(
                'invalid_directive_generation_count_type',
                esc_html__('Generation count must be provided as a whole number.', 'exmoment-author')
            );
        } elseif ($directiveGenerationCountRaw !== null) {
            $directiveGenerationCountInput = sanitize_text_field($directiveGenerationCountRaw);
        }
        $directiveGenerationCount = $this->sanitizeDirectiveGenerationCount(
            $directiveGenerationCountInput,
            $errors
        );

        $storedCustomPrompt = get_post_meta(
            $postId,
            JobsAiContextResolver::META_CUSTOM_SYSTEM_PROMPT,
            true
        );
        $storedCustomPrompt = JobsAiContextResolver::sanitizeCustomSystemPrompt($storedCustomPrompt);
        $customSystemPrompt = is_wp_error($storedCustomPrompt) ? '' : $storedCustomPrompt;

        if (isset($_POST['exmoau_job_custom_system_prompt'])) {
            $customPromptInput = wp_unslash($_POST['exmoau_job_custom_system_prompt']);
            $sanitizedCustomPrompt = JobsAiContextResolver::sanitizeCustomSystemPrompt($customPromptInput);

            if (is_wp_error($sanitizedCustomPrompt)) {
                $errors->add(
                    $sanitizedCustomPrompt->get_error_code(),
                    esc_html($sanitizedCustomPrompt->get_error_message())
                );
            } else {
                $customSystemPrompt = $sanitizedCustomPrompt;
            }
        }

        $repeatingDays = array_values($repeatingDays);
        $selectedDirectories = array_values($selectedDirectories);

        if ($errors->has_errors()) {
            $this->storeValidationErrors($postId, $errors);
            return;
        }

        $this->updateMetaValue($postId, self::META_JOB_TYPE, $jobType);

        if ($jobType === 'single_scheduled') {
            $this->updateMetaValue($postId, self::META_SINGLE_SCHEDULED, $scheduledDatetime);
        } else {
            $this->updateMetaValue($postId, self::META_SINGLE_SCHEDULED, '');
        }

        if ($jobType === 'repeating_scheduled') {
            $this->updateMetaValue($postId, self::META_REPEATING_DAYS, $repeatingDays);
            $this->updateMetaValue($postId, self::META_REPEATING_HOURS, $repeatingHours);
        } else {
            $this->updateMetaValue($postId, self::META_REPEATING_DAYS, []);
            $this->updateMetaValue($postId, self::META_REPEATING_HOURS, []);
        }

        $this->updateMetaValue($postId, self::META_MIXTURE_DIRECTORIES, $selectedDirectories);
        $this->updateMetaValue($postId, self::META_MIXTURE_UNIQUENESS, $uniqueOnly ? '1' : '0');
        $this->updateMetaValue($postId, self::META_MIXTURE_PER_CATEGORY, $perCategoryValue);
        $this->updateMetaValue($postId, self::META_DIRECTIVE_POST_TYPE, $directivePostType);
        $this->updateMetaValue($postId, self::META_DIRECTIVE_POST_STATUS, $directivePostStatus);
        if (is_int($directivePostAuthor) && $directivePostAuthor > 0) {
            $this->updateMetaValue($postId, self::META_DIRECTIVE_POST_AUTHOR, $directivePostAuthor);
        } else {
            $this->updateMetaValue($postId, self::META_DIRECTIVE_POST_AUTHOR, '');
        }
        $this->updateMetaValue($postId, self::META_DIRECTIVE_GENERATION_COUNT, $directiveGenerationCount);
        $this->updateMetaValue(
            $postId,
            JobsAiContextResolver::META_CUSTOM_SYSTEM_PROMPT,
            $customSystemPrompt
        );
    }

    /**
     * Handle AJAX requests for the Mixture tab.
     *
     * Relies on {@see prepareSetupAjaxRequest()} to validate the
     * `exmoau_job_setup_tabs` nonce, enforce the HTTP referer check implemented
     * in {@see refererMatchesCurrentHost()}, and confirm the current user can
     * manage the job post. Directory selections and pagination parameters are
     * sanitized before rendering new markup. Execution terminates via
     * `wp_send_json_success()` or `wp_send_json_error()`.
     *
     * Example:
     * ```php
     * $rawSelection = array_map('sanitize_text_field', (array) $_POST['selected_directories']);
     * $safeSelection = $controller->sanitizeDirectorySelection($rawSelection, $allowed, new WP_Error());
     * ```
     *
     * @return void
     * @since 1.1.0
     */
    public function handleMixtureTabAjax() {
        $context = $this->prepareSetupAjaxRequest();
        if ($context instanceof WP_Error) {
            $this->sendAjaxError($context, 400);
        }

        $library = $this->getLibraryStructure();
        $availableDirectories = array_keys($library);

        $selectionErrors = new WP_Error();
        $perCategoryAdjusted = false;
        $storedPerCategory = 0;
        if (!empty($context['post_id'])) {
            $storedPerCategory = $this->getMixturePerCategoryValue((int) $context['post_id']);
        }

        if ($storedPerCategory < self::MIXTURE_PER_CATEGORY_MIN) {
            $storedPerCategory = self::MIXTURE_PER_CATEGORY_MIN;
        }

        $perCategory = $this->sanitizeMixtureArticlesPerCategory(
            $context['per_category'],
            $storedPerCategory > 0 ? $storedPerCategory : self::DEFAULT_MIXTURE_PER_CATEGORY,
            $perCategoryAdjusted
        );

        $selectedDirectories = $this->sanitizeDirectorySelection(
            $context['selected_directories'],
            $availableDirectories,
            $selectionErrors
        );
        $selectedDirectories = array_values($selectedDirectories);

        $totalDirectories = count($availableDirectories);
        $pageSize = $this->getMixturePageSize($totalDirectories);
        $totalPages = ($totalDirectories > 0) ? (int) ceil($totalDirectories / $pageSize) : 1;
        $page = $this->sanitizePageNumber($context['page'], $totalPages);

        $offset = ($page - 1) * $pageSize;
        $pageDirectories = ($totalDirectories === 0)
            ? []
            : array_slice($availableDirectories, $offset, $pageSize);

        $html = $this->buildMixturePanelMarkup(
            $pageDirectories,
            $selectedDirectories,
            $context['uniqueness'],
            $perCategory,
            $library,
            $page,
            $totalPages,
            $totalDirectories,
            $pageSize,
            [
                'per_category_adjusted' => $perCategoryAdjusted,
            ]
        );

        wp_send_json_success(
            [
                'html' => $html,
                'directories' => $availableDirectories,
                'page' => $page,
                'totalPages' => $totalPages,
                'totalDirectories' => $totalDirectories,
                'pageSize' => $pageSize,
                'selected' => $selectedDirectories,
                'uniqueness' => $context['uniqueness'],
                'perCategory' => $perCategory,
                'perCategoryAdjusted' => $perCategoryAdjusted,
            ]
        );
    }

    /**
     * Handle AJAX requests for the Directive tab.
     *
     * Shares the same nonce, referer, and capability enforcement provided by
     * {@see prepareSetupAjaxRequest()} and {@see refererMatchesCurrentHost()}.
     * All directive settings, including post types, authors, and generation
     * counts, are sanitized before returning the refreshed markup. Execution
     * stops through `wp_send_json_success()` or `wp_send_json_error()`.
     *
     * Example:
     * ```php
     * $safeCount = $controller->sanitizeDirectiveGenerationCount(wp_unslash($_POST['generation_count'] ?? ''));
     * ```
     *
     * @return void
     * @since 1.1.0
     */
    public function handleDirectiveTabAjax() {
        $context = $this->prepareSetupAjaxRequest();
        if ($context instanceof WP_Error) {
            $this->sendAjaxError($context, 400);
        }

        $storedSettings = null;
        if (!empty($context['post_id'])) {
            $storedSettings = $this->getDirectiveSettings((int) $context['post_id']);
        }

        $directiveValue = $this->sanitizeDirectivePostType($context['directive']);
        $invalidPostType = !empty($context['invalid_post_type']);
        if ($directiveValue === null) {
            $invalidPostType = true;
            $directiveValue = ($storedSettings && $storedSettings['post_type'] !== '')
                ? $storedSettings['post_type']
                : '';
        } elseif ($directiveValue === '') {
            if ($storedSettings && $storedSettings['post_type'] !== '') {
                $directiveValue = $storedSettings['post_type'];
            }
            if ($storedSettings) {
                $invalidPostType = $invalidPostType || $storedSettings['invalid_post_type'];
            }
        }

        $generationCount = $storedSettings
            ? (int) $storedSettings['generation_count']
            : self::DEFAULT_DIRECTIVE_GENERATION_COUNT;
        if (isset($context['generation_count'])) {
            $generationCount = $this->sanitizeDirectiveGenerationCount($context['generation_count']);
        }

        $postStatus = $this->sanitizeDirectivePostStatus($context['post_status']);
        if ($postStatus === '') {
            $postStatus = self::DEFAULT_DIRECTIVE_POST_STATUS;
        }

        $authorId = $this->sanitizeDirectivePostAuthor($context['post_author']);
        $invalidPostAuthor = !empty($context['invalid_post_author']);
        $submittedAuthorRaw = '';
        if (is_string($context['post_author']) || is_int($context['post_author'])) {
            $submittedAuthorRaw = trim((string) $context['post_author']);
        }
        $submittedAuthorProvided = ($submittedAuthorRaw !== '');

        $storedHadAuthor = $storedSettings ? !empty($storedSettings['had_post_author']) : false;
        $storedInvalidAuthor = $storedSettings ? !empty($storedSettings['invalid_post_author']) : false;
        $storedAuthorValue = $storedSettings ? (int) $storedSettings['post_author'] : 0;

        if ($authorId === null) {
            $invalidPostAuthor = true;
            $authorId = $storedAuthorValue;
        } elseif ($authorId === 0 && $storedAuthorValue > 0) {
            $authorId = $storedAuthorValue;
        }

        $authorOptions = $this->getEligibleDirectiveAuthorOptions();
        $hasAuthorOptions = !empty($authorOptions);

        if ($authorId > 0 && !isset($authorOptions[$authorId])) {
            $invalidPostAuthor = true;
            $authorId = 0;
        }

        if (!$hasAuthorOptions) {
            $authorId = 0;
        } elseif ($authorId === 0 && !$submittedAuthorProvided && !$storedInvalidAuthor) {
            $defaultAuthor = $this->findDefaultDirectiveAuthor();
            if ($defaultAuthor > 0) {
                $authorId = $defaultAuthor;
            }
        }

        if ($storedInvalidAuthor) {
            $invalidPostAuthor = true;
        }

        if (!$hasAuthorOptions && !$submittedAuthorProvided && !$storedHadAuthor) {
            $invalidPostAuthor = false;
        } else {
            $invalidPostAuthor = $invalidPostAuthor && $hasAuthorOptions && ($storedHadAuthor || $submittedAuthorProvided || $storedInvalidAuthor);
        }

        $postTypes = $this->getAvailableDirectivePostTypes();
        if ($directiveValue !== '' && !array_key_exists($directiveValue, $postTypes)) {
            $invalidPostType = true;
            $directiveValue = '';
        }

        if ($storedSettings) {
            $invalidPostType = $invalidPostType || $storedSettings['invalid_post_type'];
        }

        $html = $this->buildDirectivePanelMarkup(
            $postTypes,
            $directiveValue,
            $postStatus,
            $authorId,
            $authorOptions,
            [
                'invalid_post_type' => $invalidPostType,
                'invalid_post_author' => $invalidPostAuthor,
                'has_author_options' => $hasAuthorOptions,
                'generation_count' => $generationCount,
            ]
        );

        wp_send_json_success(
            [
                'html' => $html,
                'directive' => $directiveValue,
                'postStatus' => $postStatus,
                'postAuthor' => $authorId > 0 ? (string) $authorId : '',
                'invalidPostType' => $invalidPostType,
                'invalidPostAuthor' => $invalidPostAuthor,
                'authorCount' => count($authorOptions),
                'hasAuthorOptions' => $hasAuthorOptions,
                'generationCount' => $generationCount,
            ]
        );
    }

    /**
     * Display validation errors stored during save_post.
     *
     * Reads the transient key from the query string, sanitizes it, and renders
     * localized error messages only on the job post editor. No nonce check is
     * performed because the notice payload is generated server-side during the
     * previous `save_post` attempt.
     *
     * @return void
     * @since 1.1.0
     */
    public function renderAdminNotices() {
        $noticeArg = '';
        if (!empty($_GET[self::ERROR_QUERY_ARG])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $noticeArg = wp_unslash($_GET[self::ERROR_QUERY_ARG]); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        if ($noticeArg === '') {
            return;
        }

        $token = $this->sanitizeNoticeToken(
            $noticeArg,
            [self::ERROR_NOTICE_TOKEN]
        );
        if ($token === '') {
            return;
        }

        $transientKey = $this->buildNoticeTransientKey($token);
        if ($transientKey === '') {
            return;
        }

        $messages = get_transient($transientKey);
        if (empty($messages) || !is_array($messages)) {
            return;
        }

        delete_transient($transientKey);

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->post_type !== self::POST_TYPE) {
            return;
        }
        ?>
        <div class="notice notice-error">
            <p>
                <?php esc_html_e('The job could not be saved. Please correct the following issues:', 'exmoment-author'); ?>
            </p>
            <ul class="exmoau-job-meta-errors">
                <?php foreach ($messages as $message) :
                    ?>
                    <li><?php echo esc_html($message); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Retrieve the stored job type.
     *
     * @param int $postId Post identifier.
     * @return string Normalized job type identifier.
     * @since 1.1.0
     */
    private function getJobTypeValue($postId) {
        $stored = get_post_meta($postId, self::META_JOB_TYPE, true);
        $stored = is_string($stored) ? $stored : '';
        $stored = $this->sanitizeJobType($stored);

        if ($stored === '' || !in_array($stored, self::JOB_TYPES, true)) {
            return self::DEFAULT_JOB_TYPE;
        }

        return $stored;
    }

    /**
     * Retrieve the stored scheduled datetime.
     *
     * @param int $postId Post identifier.
     * @return string ISO8601 datetime string in site timezone or empty string.
     * @since 1.1.0
     */
    private function getScheduledDatetimeValue($postId) {
        $stored = get_post_meta($postId, self::META_SINGLE_SCHEDULED, true);
        if (!is_string($stored)) {
            return '';
        }

        $parsed = $this->parseLocalDatetime($stored);
        return ($parsed !== null) ? $parsed : '';
    }

    /**
     * Retrieve stored repeating days.
     *
     * @param int $postId Post identifier.
     * @return string[] Valid weekday identifiers.
     * @since 1.1.0
     */
    private function getRepeatingDaysValue($postId) {
        $stored = get_post_meta($postId, self::META_REPEATING_DAYS, true);
        if (!is_array($stored)) {
            return [];
        }

        $days = [];
        foreach ($stored as $value) {
            $dayKey = $this->sanitizeWeekday($value);
            if ($dayKey !== '' && !in_array($dayKey, $days, true)) {
                $days[] = $dayKey;
            }
        }

        return $days;
    }

    /**
     * Retrieve stored repeating hours grouped by day.
     *
     * @param int $postId Post identifier.
     * @return array<string, string[]> Hour values keyed by weekday.
     * @since 1.1.0
     */
    private function getRepeatingHoursValue($postId) {
        $stored = get_post_meta($postId, self::META_REPEATING_HOURS, true);
        if (!is_array($stored)) {
            return [];
        }

        $hours = [];
        foreach ($stored as $day => $times) {
            $dayKey = $this->sanitizeWeekday($day);
            if ($dayKey === '') {
                continue;
            }
            if (!is_array($times)) {
                $times = [$times];
            }
            foreach ($times as $time) {
                $timeValue = $this->sanitizeTimeValue($time);
                if ($timeValue === '') {
                    continue;
                }
                $hours[$dayKey][] = $timeValue;
            }
            if (!empty($hours[$dayKey])) {
                $hours[$dayKey] = array_values(array_unique($hours[$dayKey]));
            }
        }

        return $hours;
    }

    /**
     * Retrieve stored directory selection.
     *
     * @param int $postId Post identifier.
     * @return string[] Selected directory identifiers.
     * @since 1.1.0
     */
    private function getMixtureDirectoriesValue($postId) {
        $stored = get_post_meta($postId, self::META_MIXTURE_DIRECTORIES, true);
        if (!is_array($stored)) {
            return [];
        }

        $directories = [];
        foreach ($stored as $value) {
            $value = is_string($value) ? trim($value) : '';
            if ($value === '' || in_array($value, $directories, true)) {
                continue;
            }
            $directories[] = $value;
        }

        return $directories;
    }

    /**
     * Retrieve stored uniqueness flag.
     *
     * @param int $postId Post identifier.
     * @return bool
     * @since 1.1.0
     */
    private function getMixtureUniquenessValue($postId) {
        $stored = get_post_meta($postId, self::META_MIXTURE_UNIQUENESS, true);
        return ($stored === '1' || $stored === 1 || $stored === true);
    }

    /**
     * Retrieve stored per-category article limit.
     *
     * @param int $postId Post identifier.
     * @return int Articles-per-category value within allowed bounds.
     * @since 1.1.0
     */
    private function getMixturePerCategoryValue($postId) {
        $stored = get_post_meta($postId, self::META_MIXTURE_PER_CATEGORY, true);
        if (!is_numeric($stored)) {
            return self::DEFAULT_MIXTURE_PER_CATEGORY;
        }

        $value = (int) $stored;
        if ($value < self::MIXTURE_PER_CATEGORY_MIN) {
            return self::MIXTURE_PER_CATEGORY_MIN;
        }

        if ($value > self::MIXTURE_PER_CATEGORY_MAX) {
            return self::MIXTURE_PER_CATEGORY_MAX;
        }

        return $value;
    }

    /**
     * Retrieve directive settings for the job.
     *
     * @param int $postId Post identifier.
     * @return array{
     *     post_type:string,
     *     post_status:string,
     *     post_author:int,
     *     invalid_post_type:bool,
     *     invalid_post_author:bool,
     *     had_post_author:bool,
     *     has_author_options:bool,
     *     generation_count:int
     * }
     * @since 1.1.0
     */
    private function getDirectiveSettings($postId) {
        $postTypeValue = get_post_meta($postId, self::META_DIRECTIVE_POST_TYPE, true);
        $postTypeValue = is_string($postTypeValue) ? sanitize_key($postTypeValue) : '';

        $postStatusValue = get_post_meta($postId, self::META_DIRECTIVE_POST_STATUS, true);
        $postStatusValue = is_string($postStatusValue)
            ? $this->sanitizeDirectivePostStatus($postStatusValue)
            : self::DEFAULT_DIRECTIVE_POST_STATUS;

        $storedAuthor = get_post_meta($postId, self::META_DIRECTIVE_POST_AUTHOR, true);
        $storedAuthorId = is_numeric($storedAuthor) ? (int) $storedAuthor : 0;
        $authorId = $storedAuthorId;

        $generationStored = get_post_meta($postId, self::META_DIRECTIVE_GENERATION_COUNT, true);
        $generationCount = $this->sanitizeDirectiveGenerationCount($generationStored);

        $invalidPostType = false;
        if ($postTypeValue !== '' && !$this->isDirectivePostTypeSelectable($postTypeValue)) {
            $invalidPostType = true;
            $postTypeValue = '';
        }

        $availableAuthors = $this->getEligibleDirectiveAuthorOptions();
        $hasAuthorOptions = !empty($availableAuthors);

        $invalidPostAuthor = false;
        if ($authorId > 0) {
            if (!$this->userMeetsAuthorThreshold($authorId) || ($hasAuthorOptions && !isset($availableAuthors[$authorId]))) {
                $invalidPostAuthor = $hasAuthorOptions;
                $authorId = 0;
            }
        }

        if ($authorId === 0 && $hasAuthorOptions) {
            $defaultAuthor = $this->findDefaultDirectiveAuthor();
            if ($defaultAuthor > 0) {
                $authorId = $defaultAuthor;
            }
        }

        return [
            'post_type' => $postTypeValue,
            'post_status' => ($postStatusValue !== '') ? $postStatusValue : self::DEFAULT_DIRECTIVE_POST_STATUS,
            'post_author' => $authorId,
            'invalid_post_type' => $invalidPostType,
            'invalid_post_author' => $invalidPostAuthor,
            'had_post_author' => ($storedAuthorId > 0),
            'has_author_options' => $hasAuthorOptions,
            'generation_count' => $generationCount,
        ];
    }

    /**
     * Retrieve stored directive post type.
     *
     * @param int $postId Post identifier.
     * @return string Sanitized directive post type identifier.
     * @since 1.1.0
     */
    private function getDirectivePostTypeValue($postId) {
        $settings = $this->getDirectiveSettings($postId);
        return $settings['post_type'];
    }

    /**
     * Fetch the available library structure from uploads.
     *
     * @return array<string, array<string, mixed>> Directory map keyed by identifier.
     * @since 1.1.0
     */
    private function getLibraryStructure() {
        if (is_array($this->libraryStructure)) {
            return $this->libraryStructure;
        }

        $structure = [];
        $uploadDir = wp_upload_dir();
        $baseDir = isset($uploadDir['basedir']) ? $uploadDir['basedir'] : '';
        if ($baseDir === '') {
            $this->libraryStructure = $structure;
            return $structure;
        }

        $libraryRoot = trailingslashit($baseDir) . 'exmoau-library';
        if (!is_dir($libraryRoot)) {
            $this->libraryStructure = $structure;
            return $structure;
        }

        $items = scandir($libraryRoot);
        if (!is_array($items)) {
            $this->libraryStructure = $structure;
            return $structure;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $directoryPath = $libraryRoot . DIRECTORY_SEPARATOR . $item;
            if (!is_dir($directoryPath) || !$this->isSafeIdentifier($item, true)) {
                continue;
            }

            $structure[$item] = [
                'label' => $item,
            ];
        }

        ksort($structure, SORT_NATURAL | SORT_FLAG_CASE);

        $this->libraryStructure = $structure;
        return $structure;
    }

    /**
     * Retrieve available directive post types.
     *
     * @return array<string, string> Post type slugs keyed to their labels.
     * @since 1.1.0
     */
    private function getAvailableDirectivePostTypes() {
        $postTypes = get_post_types([], 'objects');
        $available = [];

        foreach ($postTypes as $postType => $object) {
            if (!$this->isDirectivePostTypeSelectable($postType, $object)) {
                continue;
            }

            $label = isset($object->labels->singular_name) ? $object->labels->singular_name : $postType;
            $available[$postType] = $label;
        }

        asort($available, SORT_NATURAL | SORT_FLAG_CASE);

        return $available;
    }

    /**
     * Sanitize job type input.
     *
     * @param mixed $value Raw value.
     * @return string Sanitized job type identifier or empty string when invalid.
     * @since 1.1.0
     */
    private function sanitizeJobType($value) {
        if (!is_string($value)) {
            return '';
        }

        $value = sanitize_key($value);
        return $value;
    }

    /**
     * Convert stored datetime to datetime-local input value.
     *
     * @param string $value Stored datetime string.
     * @return string Datetime formatted for `datetime-local` inputs.
     * @since 1.1.0
     */
    private function formatDatetimeForInput($value) {
        $date = DateTime::createFromFormat('Y-m-d H:i', $value);
        if (!$date) {
            return '';
        }

        return $date->format('Y-m-d\TH:i');
    }

    /**
     * Build the server time display markup for the scheduling panes.
     *
     * @param array<string, mixed> $context Server time context values.
     * @return string Escaped HTML snippet describing server time.
     * @since 1.1.0
     */
    private function buildServerTimeMarkup(array $context) {
        $timestamp = isset($context['timestamp_utc']) ? (int) $context['timestamp_utc'] : 0;
        if ($timestamp <= 0) {
            return '';
        }

        $offsetSeconds = isset($context['offset_seconds']) ? (int) $context['offset_seconds'] : 0;
        $localDisplay = isset($context['local_display']) ? (string) $context['local_display'] : '';
        $localIso = isset($context['local_iso']) ? (string) $context['local_iso'] : '';
        $timezoneDisplay = isset($context['timezone_display']) ? (string) $context['timezone_display'] : '';
        $offsetLabel = isset($context['offset_label']) ? (string) $context['offset_label'] : '';
        $lastRefresh = isset($context['last_refresh_display']) ? (string) $context['last_refresh_display'] : $localDisplay;

        if ($localDisplay === '' || $localIso === '') {
            return '';
        }

        if ($timezoneDisplay === '') {
            $timezoneDisplay = $offsetLabel;
        }

        ob_start();
        ?>
        <div
            class="exmoau-job-time"
            data-exmoau-job-server-time="1"
            data-exmoau-server-timestamp="<?php echo esc_attr((string) $timestamp); ?>"
            data-exmoau-server-offset="<?php echo esc_attr((string) $offsetSeconds); ?>"
            data-exmoau-server-offset-label="<?php echo esc_attr($offsetLabel); ?>"
        >
            <span class="exmoau-job-time__label"><?php esc_html_e('Server time:', 'exmoment-author'); ?></span>
            <span class="exmoau-job-time__current">
                <time class="exmoau-job-time__clock" datetime="<?php echo esc_attr($localIso); ?>">
                    <?php echo esc_html($localDisplay); ?>
                </time>
                <span class="exmoau-job-time__zone">
                    (<?php echo esc_html($timezoneDisplay); ?>)
                </span>
            </span>
            <span class="exmoau-job-time__snapshot">
                <?php
                printf(
                    /* translators: %s: Time the data was last refreshed. */
                    esc_html__('Last refreshed at %s', 'exmoment-author'),
                    esc_html($lastRefresh)
                );
                ?>
            </span>
        </div>
        <?php

        return trim((string) ob_get_clean());
    }

    /**
     * Parse a local datetime string.
     *
     * @param mixed $value Raw input value.
     * @return string|null ISO8601-like datetime string or null when invalid.
     * @since 1.1.0
     */
    private function parseLocalDatetime($value) {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $value = str_replace('T', ' ', $value);
        $date = DateTime::createFromFormat('Y-m-d H:i', $value);
        $errors = DateTime::getLastErrors();

        if (!$date || !empty($errors['warning_count']) || !empty($errors['error_count'])) {
            return null;
        }

        return $date->format('Y-m-d H:i');
    }

    /**
     * Sanitize repeating day selections.
     *
     * @param mixed $days Raw day values.
     * @return string[] Valid weekday identifiers.
     * @since 1.1.0
     */
    private function sanitizeRepeatingDays($days) {
        if (!is_array($days)) {
            $days = [$days];
        }

        $sanitized = [];
        foreach ($days as $day) {
            $dayKey = $this->sanitizeWeekday($day);
            if ($dayKey === '' || in_array($dayKey, $sanitized, true)) {
                continue;
            }

            $sanitized[] = $dayKey;
        }

        return $sanitized;
    }

    /**
     * Sanitize repeating hours selection.
     *
     * @param mixed    $hoursRaw      Raw hours array.
     * @param string[] $selectedDays  Validated day keys.
     * @param WP_Error $errors        Error collector.
     * @return array<string, string[]> Sanitized hours grouped by weekday.
     * @since 1.1.0
     */
    private function sanitizeRepeatingHours($hoursRaw, $selectedDays, WP_Error $errors) {
        $result = [];
        $hasInvalidTimeValue = false;
        if (!is_array($hoursRaw)) {
            return $result;
        }

        foreach ($hoursRaw as $day => $times) {
            $dayKey = $this->sanitizeWeekday($day);
            if ($dayKey === '' || !in_array($dayKey, $selectedDays, true)) {
                continue;
            }

            if (!is_array($times)) {
                $times = [$times];
            }

            $hasAnyValueForDay = false;
            $hasInvalidValueForDay = false;
            foreach ($times as $time) {
                if (!is_string($time)) {
                    continue;
                }

                $rawTime = trim($time);
                if ($rawTime === '') {
                    continue;
                }

                $hasAnyValueForDay = true;
                $timeValue = $this->sanitizeTimeValue($time);
                if ($timeValue === '') {
                    $hasInvalidValueForDay = true;
                    continue;
                }

                $result[$dayKey][] = $timeValue;
            }

            if (empty($result[$dayKey])) {
                if ($hasInvalidValueForDay) {
                    $hasInvalidTimeValue = true;
                } elseif ($hasAnyValueForDay) {
                    $hasInvalidTimeValue = true;
                } else {
                    $errors->add(
                        'missing_repeating_time',
                        sprintf(
                            /* translators: %s: weekday name. */
                            esc_html__('Provide at least one valid time for %s.', 'exmoment-author'),
                            esc_html($this->getWeekdayLabels()[$dayKey])
                        )
                    );
                }
            } else {
                $result[$dayKey] = array_values(array_unique($result[$dayKey]));
                if ($hasInvalidValueForDay) {
                    $hasInvalidTimeValue = true;
                }
            }
        }

        if ($hasInvalidTimeValue) {
            $errors->add(
                'invalid_repeating_hours_time',
                esc_html__('Repeating hours contain invalid time values.', 'exmoment-author')
            );
        }

        return $result;
    }

    /**
     * Sanitize directory selection values.
     *
     * @param mixed    $selection           Raw directory values.
     * @param string[] $availableDirectories Available directories.
     * @param WP_Error $errors              Error collector.
     * @return string[] Sanitized directory identifiers.
     * @since 1.1.0
     */
    private function sanitizeDirectorySelection($selection, $availableDirectories, WP_Error $errors) {
        if (!is_array($selection)) {
            $selection = [$selection];
        }

        $sanitized = [];
        foreach ($selection as $value) {
            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value === '' || in_array($value, $sanitized, true)) {
                continue;
            }

            if (!in_array($value, $availableDirectories, true)) {
                $errors->add('invalid_directory', esc_html__('One or more selected directories are invalid.', 'exmoment-author'));
                continue;
            }

            $sanitized[] = $value;
        }

        return $sanitized;
    }

    /**
     * Sanitize article selection ensuring they belong to selected directories.
     *
     * @param mixed                              $selection         Raw article values.
     * @param array<string, array<string, mixed>> $libraryStructure Library data.
     * @param string[]                            $directories       Selected directories.
     * @param WP_Error                            $errors            Error collector.
     * @return string[]
     */
    /**
     * Sanitize directive post type selection.
     *
     * @param mixed $value Raw value.
     * @return string|null Null when invalid.
     * @since 1.1.0
     */
    private function sanitizeDirectivePostType($value) {
        if (!is_string($value)) {
            return '';
        }

        $value = sanitize_key($value);
        if ($value === '') {
            return '';
        }

        if (!$this->isDirectivePostTypeSelectable($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Sanitize directive post status selection.
     *
     * @param mixed $value Raw value.
     * @return string Validated post status.
     * @since 1.1.0
     */
    private function sanitizeDirectivePostStatus($value) {
        if (!is_string($value)) {
            return self::DEFAULT_DIRECTIVE_POST_STATUS;
        }

        $value = sanitize_key($value);
        return ($value === 'publish') ? 'publish' : self::DEFAULT_DIRECTIVE_POST_STATUS;
    }

    /**
     * Sanitize the articles-per-category input.
     *
     * @param mixed    $value          Raw value.
     * @param int      $fallback       Fallback value when invalid.
     * @param bool     $wasAdjusted    Reference flag set when adjusted.
     * @param WP_Error $errors         Optional error collector.
     * @return int Sanitized articles-per-category value.
     * @since 1.1.0
     */
    private function sanitizeMixtureArticlesPerCategory($value, $fallback, &$wasAdjusted = false, ?WP_Error $errors = null) {
        $wasAdjusted = false;
        $fallbackValue = (int) $fallback;
        if ($fallbackValue < self::MIXTURE_PER_CATEGORY_MIN) {
            $fallbackValue = self::MIXTURE_PER_CATEGORY_MIN;
        } elseif ($fallbackValue > self::MIXTURE_PER_CATEGORY_MAX) {
            $fallbackValue = self::MIXTURE_PER_CATEGORY_MAX;
        }

        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null || $value === '') {
            $wasAdjusted = true;
            if ($errors instanceof WP_Error) {
                $errors->add(
                    'missing_mixture_per_category',
                    esc_html__('Enter how many articles to collect per category.', 'exmoment-author')
                );
            }

            return $fallbackValue;
        }

        if (!is_scalar($value)) {
            $wasAdjusted = true;
            if ($errors instanceof WP_Error) {
                $errors->add(
                    'invalid_mixture_per_category_type',
                    esc_html__('Articles per category must be provided as a whole number.', 'exmoment-author')
                );
            }

            return $fallbackValue;
        }

        $rawString = trim((string) $value);
        if ($rawString === '' || !preg_match('/^\d+$/', $rawString)) {
            $wasAdjusted = true;
            if ($errors instanceof WP_Error) {
                $errors->add(
                    'invalid_mixture_per_category_format',
                    esc_html__('Articles per category must be a whole number.', 'exmoment-author')
                );
            }

            return $fallbackValue;
        }

        $intValue = (int) $rawString;

        if ($intValue < self::MIXTURE_PER_CATEGORY_MIN) {
            $wasAdjusted = true;
            if ($errors instanceof WP_Error) {
                $errors->add(
                    'invalid_mixture_per_category_min',
                    sprintf(
                        /* translators: %d: Minimum articles per category. */
                        esc_html__('Articles per category must be at least %d.', 'exmoment-author'),
                        self::MIXTURE_PER_CATEGORY_MIN
                    )
                );
            }

            return self::MIXTURE_PER_CATEGORY_MIN;
        }

        if ($intValue > self::MIXTURE_PER_CATEGORY_MAX) {
            $wasAdjusted = true;
            if ($errors instanceof WP_Error) {
                $errors->add(
                    'invalid_mixture_per_category_max',
                    sprintf(
                        /* translators: %d: Maximum articles per category. */
                        esc_html__('Articles per category cannot exceed %d.', 'exmoment-author'),
                        self::MIXTURE_PER_CATEGORY_MAX
                    )
                );
            }

            return self::MIXTURE_PER_CATEGORY_MAX;
        }

        return $intValue;
    }

    /**
     * Sanitize directive post author selection.
     *
     * @param mixed $value      Raw value.
     * @param bool  $allowEmpty Whether to allow empty selections.
     * @return int|null Returns user ID, zero when empty allowed, or null when invalid.
     * @since 1.1.0
     */
    private function sanitizeDirectivePostAuthor($value, $allowEmpty = true) {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (!is_string($value) && !is_int($value)) {
            return $allowEmpty ? 0 : null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return $allowEmpty ? 0 : null;
        }

        $userId = absint($value);
        if ($userId < 1) {
            return $allowEmpty ? 0 : null;
        }

        if (!$this->userMeetsAuthorThreshold($userId)) {
            return $allowEmpty ? 0 : null;
        }

        return $userId;
    }

    /**
     * Sanitize how many items should be generated per directive run.
     *
     * @param mixed         $value  Raw value.
     * @param WP_Error|null $errors Optional error collector.
     * @return int Sanitized generation count respecting the minimum threshold.
     * @since 1.1.0
     */
    private function sanitizeDirectiveGenerationCount($value, WP_Error $errors = null) {
        if ($value === null || $value === '') {
            return self::DEFAULT_DIRECTIVE_GENERATION_COUNT;
        }

        if (is_array($value)) {
            if ($errors instanceof WP_Error) {
                $errors->add(
                    'invalid_directive_generation_count_type',
                    esc_html__('Generation count must be provided as a whole number.', 'exmoment-author')
                );
            }

            return self::DEFAULT_DIRECTIVE_GENERATION_COUNT;
        }

        $value = is_string($value) ? trim($value) : $value;
        if ($value === '' || (!is_numeric($value) && !ctype_digit((string) $value))) {
            if ($errors instanceof WP_Error) {
                $errors->add(
                    'invalid_directive_generation_count_format',
                    esc_html__('Generation count must be a whole number.', 'exmoment-author')
                );
            }

            return self::DEFAULT_DIRECTIVE_GENERATION_COUNT;
        }

        $intValue = (int) $value;
        if ($intValue < self::DIRECTIVE_GENERATION_MIN) {
            if ($errors instanceof WP_Error) {
                $errors->add(
                    'invalid_directive_generation_count_min',
                    sprintf(
                        /* translators: %d: Minimum directive generation count. */
                        esc_html__('Generation count must be at least %d.', 'exmoment-author'),
                        self::DIRECTIVE_GENERATION_MIN
                    )
                );
            }

            return self::DIRECTIVE_GENERATION_MIN;
        }

        return $intValue;
    }

    /**
     * Retrieve eligible directive authors for selection.
     *
     * @return array<int, string> Author IDs keyed to display labels.
     * @since 1.1.0
     */
    private function getEligibleDirectiveAuthorOptions() {
        if (is_array($this->cachedDirectiveAuthors)) {
            return $this->cachedDirectiveAuthors;
        }

        $args = [
            'number' => -1,
            'orderby' => 'display_name',
            'order' => 'ASC',
            'fields' => 'all_with_meta',
            'blog_id' => get_current_blog_id(),
        ];

        $query = new WP_User_Query($args);
        $options = [];
        $seen = [];

        foreach ($query->get_results() as $result) {
            $user = ($result instanceof WP_User)
                ? $result
                : (is_object($result) && isset($result->ID) ? get_user_by('id', (int) $result->ID) : null);

            if (!($user instanceof WP_User)) {
                continue;
            }

            if (!$this->userMeetsAuthorThreshold($user->ID)) {
                continue;
            }

            $options[$user->ID] = $this->formatAuthorLabel($user);
            $seen[$user->ID] = true;
        }

        if (is_multisite()) {
            $superAdmins = get_super_admins();
            if (is_array($superAdmins)) {
                foreach ($superAdmins as $login) {
                    if (!is_string($login) || $login === '') {
                        continue;
                    }

                    $user = get_user_by('login', $login);
                    if (!($user instanceof WP_User)) {
                        continue;
                    }

                    if (isset($seen[$user->ID])) {
                        continue;
                    }

                    if (!$this->userMeetsAuthorThreshold($user->ID)) {
                        continue;
                    }

                    $options[$user->ID] = $this->formatAuthorLabel($user);
                    $seen[$user->ID] = true;
                }
            }
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        $this->cachedDirectiveAuthors = $options;

        return $options;
    }

    /**
     * Format display label for an author option.
     *
     * @param WP_User $user User object.
     * @return string Readable author label for select options.
     * @since 1.1.0
     */
    private function formatAuthorLabel(WP_User $user) {
        $displayName = $user->display_name !== '' ? $user->display_name : $user->user_login;
        $username = $user->user_login;

        if ($displayName === $username) {
            return $displayName;
        }

        return sprintf('%1$s (%2$s)', $displayName, $username);
    }

    /**
     * Determine if the post type can be targeted by the directive.
     *
     * @param string            $postType Post type slug.
     * @param WP_Post_Type|null $object   Post type object.
     * @return bool True when the post type supports editor content and the user can create it.
     * @since 1.1.0
     */
    private function isDirectivePostTypeSelectable($postType, $object = null) {
        if (!is_string($postType) || $postType === '') {
            return false;
        }

        if (!post_type_exists($postType)) {
            return false;
        }

        if (!($object instanceof WP_Post_Type)) {
            $object = get_post_type_object($postType);
        }

        if (!($object instanceof WP_Post_Type)) {
            return false;
        }

        if (!post_type_supports($postType, 'editor')) {
            return false;
        }

        if (!$this->isAdminManageablePostType($object)) {
            return false;
        }

        $capability = $this->getCreateCapabilityForPostType($object);
        if ($capability === '') {
            return false;
        }

        return current_user_can($capability);
    }

    /**
     * Determine if the post type is manageable in the admin UI.
     *
     * @param WP_Post_Type $object Post type object.
     * @return bool True when the type is exposed in the admin and not excluded.
     * @since 1.1.0
     */
    private function isAdminManageablePostType(WP_Post_Type $object) {
        if (!$object->show_ui) {
            return false;
        }

        $excluded = [
            'attachment',
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_global_styles',
            'wp_navigation',
        ];

        if (in_array($object->name, $excluded, true)) {
            return false;
        }

        if (property_exists($object, 'internal') && $object->internal) {
            return false;
        }

        return true;
    }

    /**
     * Retrieve the capability used to create posts for a type.
     *
     * @param WP_Post_Type $object Post type object.
     * @return string Capability string or empty string when unavailable.
     * @since 1.1.0
     */
    private function getCreateCapabilityForPostType(WP_Post_Type $object) {
        if (isset($object->cap->create_posts) && is_string($object->cap->create_posts) && $object->cap->create_posts !== '') {
            return $object->cap->create_posts;
        }

        if (isset($object->cap->edit_posts) && is_string($object->cap->edit_posts) && $object->cap->edit_posts !== '') {
            return $object->cap->edit_posts;
        }

        return '';
    }

    /**
     * Determine a sensible default author for new jobs.
     *
     * @return int User ID for default author or zero when unavailable.
     * @since 1.1.0
     */
    private function findDefaultDirectiveAuthor() {
        $currentUserId = get_current_user_id();
        if ($currentUserId > 0 && $this->userMeetsAuthorThreshold($currentUserId)) {
            return $currentUserId;
        }

        $authors = $this->getEligibleDirectiveAuthorOptions();
        if (!empty($authors)) {
            $firstKey = array_key_first($authors);
            if ($firstKey !== null) {
                return (int) $firstKey;
            }
        }

        return 0;
    }

    /**
     * Check whether a user meets the editorial capability threshold.
     *
     * @param int $userId User identifier.
     * @return bool True when the user can edit or publish target post types.
     * @since 1.1.0
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

    /**
     * Store validation errors for display after redirect.
     *
     * @param int      $postId Post identifier.
     * @param WP_Error $errors Validation errors.
     * @return void
     * @since 1.1.0
     */
    private function storeValidationErrors($postId, WP_Error $errors) {
        $messages = $errors->get_error_messages();
        if (empty($messages)) {
            return;
        }

        $transientKey = $this->buildNoticeTransientKey(self::ERROR_NOTICE_TOKEN);
        if ($transientKey === '') {
            return;
        }

        set_transient($transientKey, $messages, MINUTE_IN_SECONDS);

        add_filter(
            'redirect_post_location',
            function ($location) {
                return add_query_arg(self::ERROR_QUERY_ARG, self::ERROR_NOTICE_TOKEN, $location);
            }
        );
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

        return self::ERROR_TRANSIENT_PREFIX . $token . '_' . $userId;
    }

    /**
     * Update or delete post meta based on value.
     *
     * @param int            $postId Post identifier.
     * @param string         $metaKey Meta key.
     * @param int|string|array $value  Value to store.
     * @return void
     * @since 1.1.0
     */
    private function updateMetaValue($postId, $metaKey, $value) {
        if ($value === '' || $value === null || (is_array($value) && empty($value))) {
            delete_post_meta($postId, $metaKey);
            return;
        }

        update_post_meta($postId, $metaKey, $value);
    }

    /**
     * Ensure identifier is safe for file system use.
     *
     * @param mixed $value       Value to validate.
     * @param bool  $allowSpaces Whether spaces are permitted.
     * @return bool True when the identifier contains only allowed characters.
     * @since 1.1.0
     */
    private function isSafeIdentifier($value, $allowSpaces = false) {
        if (!is_string($value)) {
            return false;
        }

        $value = trim($value);
        if ($value === '' || strpos($value, '..') !== false || strpos($value, '/') !== false || strpos($value, '\\') !== false) {
            return false;
        }

        if (substr($value, 0, 1) === '.') {
            return false;
        }

        $pattern = $allowSpaces ? '/^[A-Za-z0-9 _.-]+$/' : '/^[A-Za-z0-9_.-]+$/';
        return (bool) preg_match($pattern, $value);
    }

    /**
     * Sanitize weekday identifiers.
     *
     * @param mixed $value Raw value.
     * @return string Valid weekday key or empty string when invalid.
     * @since 1.1.0
     */
    private function sanitizeWeekday($value) {
        if (!is_string($value)) {
            return '';
        }

        $value = sanitize_key($value);
        return isset(self::WEEKDAYS[$value]) ? $value : '';
    }

    /**
     * Sanitize time values.
     *
     * @param mixed $value Raw value.
     * @return string 24-hour `HH:MM` time string or empty string when invalid.
     * @since 1.1.0
     */
    private function sanitizeTimeValue($value) {
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
     * Retrieve weekday labels.
     *
     * @return array<string, string> Weekday identifiers mapped to translated labels.
     * @since 1.1.0
     */
    private function getWeekdayLabels() {
        return [
            'mon' => __('Monday', 'exmoment-author'),
            'tue' => __('Tuesday', 'exmoment-author'),
            'wed' => __('Wednesday', 'exmoment-author'),
            'thu' => __('Thursday', 'exmoment-author'),
            'fri' => __('Friday', 'exmoment-author'),
            'sat' => __('Saturday', 'exmoment-author'),
            'sun' => __('Sunday', 'exmoment-author'),
        ];
    }

    /**
     * Get a translated label for a job type.
     *
     * @param string $jobType Job type identifier.
     * @return string Translated label for the provided job type.
     * @since 1.1.0
     */
    private function getJobTypeLabel($jobType) {
        switch ($jobType) {
            case 'single_scheduled':
                return __('Single › Scheduled', 'exmoment-author');
            case 'repeating_scheduled':
                return __('Repeating › Scheduled', 'exmoment-author');
            case 'single_instant':
            default:
                return __('Single › Instant', 'exmoment-author');
        }
    }
}
