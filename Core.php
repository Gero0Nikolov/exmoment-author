<?php

namespace ExMomentAuthor\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use DirectoryIterator;
use ExMomentAuthor\Modules\Jobs\JobsSchedulerWorker;
use ExMomentAuthor\Modules\Jobs\JobsSchedulingController;
use ExMomentAuthor\Modules\Library\UsedArticlesRepository;
use ExMomentAuthor\Modules\Log\LogService;
use ZipArchive;

class ExMomentAuthorCoreSystem {

    /**
     * Hook identifier for the minutely worker; must align with JobsSchedulerWorker::HOOK.
     */
    protected const MINUTELY_WORKER_HOOK = 'exmoau_minutely_worker';

    /**
     * Singleton instance of the core loader.
     *
     * @var ExMomentAuthorCoreSystem|null
     */
    private static $instance = null;

    /**
     * Plugin configuration container.
     *
     * @var array<string, mixed>
     */
    public $config;

    /**
     * Loaded module controllers indexed by controller class name.
     *
     * @var array<string, object>
     */
    public $controllers;

    /**
     * Register the plugin activation hook for library seeding.
     *
     * @return void
     */
    public static function registerActivationHook() {
        $pluginFile = (
            defined('EXMOAU_PLUGIN_FILE') ?
            EXMOAU_PLUGIN_FILE :
            rtrim(plugin_dir_path(__FILE__), '/\\') . '/exmoment-author.php'
        );

        register_activation_hook($pluginFile, [__CLASS__, 'seedLibraryOnActivation']);
        register_deactivation_hook($pluginFile, [__CLASS__, 'cleanupOnDeactivation']);
    }

    /**
     * Autoload ExMoment Author module classes using PSR-4 style resolution.
     *
     * @param string $class Fully-qualified class name requested by PHP.
     * @return void
     */
    public static function autoloadClass($class) {
        $prefix = 'ExMomentAuthor\\Modules\\';

        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        if ($relativeClass === false || $relativeClass === '') {
            return;
        }

        $segments = explode('\\', $relativeClass);

        if (empty($segments)) {
            return;
        }

        $className = array_pop($segments);
        $directories = array_map(
            static function ($segment) {
                return strtolower($segment);
            },
            $segments
        );

        $pluginPath = rtrim(dirname(__FILE__), '/\\');
        $baseDirectory = $pluginPath . '/modules';

        if (!empty($directories)) {
            $baseDirectory .= '/' . implode('/', $directories);
        }

        $filePath = $baseDirectory . '/' . $className . '.php';

        if (is_readable($filePath)) {
            require_once $filePath;
        }
    }

    /**
     * Handle plugin activation by seeding bundled library archives synchronously.
     *
     * @return void
     */
    public static function seedLibraryOnActivation() {
        delete_option('exmoau_library_seed_scheduled');
        delete_option('exmoau_library_seed_processed');

        $pluginPath = rtrim(plugin_dir_path(__FILE__), '/\\');

        $core = self::getInstance();
        $core->autoload();

        $schedulingController = $core->getModule('JobsSchedulingController');
        if ($schedulingController instanceof JobsSchedulingController) {
            JobsSchedulingController::activate();
        }

        JobsSchedulerWorker::activate();

        if (class_exists(LogService::class)) {
            LogService::activate();
        }

        $usedArticlesRepository = $core->getModule('UsedArticlesRepository');
        if ($usedArticlesRepository instanceof UsedArticlesRepository) {
            $usedArticlesRepository->ensureRegistryTable();
        }

        if (!class_exists('ZipArchive')) {
            self::logDebug('Library seeding skipped because the ZipArchive extension is unavailable.');

            return;
        }

        $sourceDirectory = $pluginPath . '/install/exmoau-library';

        if (!is_dir($sourceDirectory) || !is_readable($sourceDirectory)) {
            self::logDebug('Library seeding skipped because install/exmoau-library is missing or unreadable.');

            return;
        }

        $uploads = wp_get_upload_dir();
        if (
            !is_array($uploads) ||
            !empty($uploads['error']) ||
            empty($uploads['basedir'])
        ) {
            self::logDebug('Library seeding skipped because the uploads directory is unavailable.');

            return;
        }

        $destinationRoot = rtrim($uploads['basedir'], '/\\') . '/exmoau-library';

        if (!wp_mkdir_p($destinationRoot)) {
            self::logDebug('Library seeding aborted because uploads/exmoau-library could not be created.');

            return;
        }

        if (!wp_is_writable($destinationRoot)) {
            self::logDebug('Library seeding aborted because uploads/exmoau-library is not writable.');

            return;
        }

        self::logDebug('Library seeding destination ready at uploads/exmoau-library.');

        self::ensureLibraryProtection($destinationRoot);

        try {
            $iterator = new DirectoryIterator($sourceDirectory);
        } catch (\UnexpectedValueException $exception) {
            self::logDebug('Library seeding skipped because the source directory could not be read.');

            return;
        }

        $archives = [];

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            if ('zip' !== strtolower($fileInfo->getExtension())) {
                continue;
            }

            $archives[] = $fileInfo->getPathname();
        }

        $discovered = count($archives);
        $extracted = 0;
        $skipped = 0;

        foreach ($archives as $archivePath) {
            $result = self::processLibraryArchive($archivePath, $destinationRoot);

            if ('processed' === $result['status']) {
                $extracted++;
            } else {
                $skipped++;
            }

            if (!empty($result['message'])) {
                self::logDebug($result['message']);
            }
        }

        self::logDebug(
            'Library seeding summary: discovered %d bundle(s); extracted %d; skipped %d.',
            $discovered,
            $extracted,
            $skipped
        );
    }

    /**
     * Handle plugin deactivation cleanup.
     *
     * @return void
     */
    public static function cleanupOnDeactivation() {
        JobsSchedulerWorker::deactivate();
    }

    /**
     * Guarantee that the minutely scheduler remains registered.
     *
     * @return void
     */
    public static function ensureSchedulerEvent() {
        $hook = self::getMinutelyWorkerHook();
        if (wp_next_scheduled($hook) !== false) {
            return;
        }

        JobsSchedulerWorker::ensureEventScheduled();
    }

    /**
     * Resolve the hook name for the minutely worker without requiring eager class loading.
     *
     * @return string
     */
    private static function getMinutelyWorkerHook() {
        if (class_exists(JobsSchedulerWorker::class)) {
            return JobsSchedulerWorker::HOOK;
        }

        return self::MINUTELY_WORKER_HOOK;
    }

    /**
     * Ensure the uploads library directory blocks direct web access.
     *
     * @param string $destinationRoot Absolute path to uploads/exmoau-library.
     * @return void
     */
    private static function ensureLibraryProtection($destinationRoot) {
        $normalizedRoot = rtrim($destinationRoot, '/\\');
        $files = [
            [
                'name'     => 'index.php',
                'contents' => "<?php // Silence is golden\n",
            ],
            [
                'name'     => '.htaccess',
                'contents' => "Options -Indexes\n<IfModule mod_access_compat>\n    Deny from all\n</IfModule>\n<IfModule !mod_access_compat>\n    Require all denied\n</IfModule>\n",
            ],
        ];

        foreach ($files as $fileSpec) {
            $filePath = $normalizedRoot . DIRECTORY_SEPARATOR . $fileSpec['name'];

            if (file_exists($filePath)) {
                self::logDebug(
                    '%s already exists in uploads/exmoau-library; skipping creation.',
                    $fileSpec['name']
                );

                continue;
            }


            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }
            $write_ok = false;
            if (is_object($wp_filesystem) && method_exists($wp_filesystem, 'put_contents')) {
                $write_ok = $wp_filesystem->put_contents($filePath, $fileSpec['contents'], FS_CHMOD_FILE);
            }
            if (!$write_ok) {
                self::logDebug(
                    'Failed to write contents to %s in uploads/exmoau-library.',
                    $fileSpec['name']
                );
                if (file_exists($filePath)) {
                    wp_delete_file($filePath);
                }
                continue;
            }

            self::logDebug('Created %s in uploads/exmoau-library.', $fileSpec['name']);
        }
    }

    /**
     * Initialize plugin configuration and register hooks.
     *
     * @return void
     */
    public function __construct() {

        self::$instance = $this;

        $pluginPath = rtrim(plugin_dir_path(__FILE__), '/\\');
        $pluginUrl = plugin_dir_url(__FILE__);
        $host = (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
        $isDevelopmentHost = (
            strpos($host, 'localhost') !== false ||
            strpos($host, 'loc.') !== false
        );

        // This needs to be set via WordPress Admin Page.
        $openAiApiKey = '';

        // Setup.
        $this->config = [
            'resourceVersion' => (
                $isDevelopmentHost ?
                gmdate('YmdHis') :
                '1.2.0'
            ),
            'base' => [
                'path' => $pluginPath,
                'url' => $pluginUrl,
            ],
            'paths' => [
                'modules' => $pluginPath .'/modules',
            ],
            'autoload' => [
                'ai' => array(
                    array(
                        'class'        => 'AiService',
                        'instantiate'  => true,
                    ),
                ),
                'log' => [
                    [
                        'class'        => 'LogService',
                        'instantiate'  => true,
                    ],
                    [
                        'class'        => 'LogAdminController',
                        'instantiate'  => true,
                    ],
                ],
                'jobs' => [
                    [
                        'class'        => 'JobsController',
                        'instantiate'  => true,
                    ],
                    [
                        'class'        => 'JobsMetaController',
                        'instantiate'  => true,
                    ],
                    [
                        'class'        => 'JobsAiContextResolver',
                        'instantiate'  => false,
                    ],
                    [
                        'class'        => 'JobsPublicationValidator',
                        'instantiate'  => true,
                    ],
                    [
                        'class'        => 'JobsSchedulingController',
                        'instantiate'  => true,
                    ],
                    [
                        'class'        => 'JobsSchedulerWorker',
                        'instantiate'  => true,
                    ],
                    [
                        'class'        => 'JobsErrorController',
                        'instantiate'  => true,
                    ],
                    [
                        'class'        => 'JobsExecutionController',
                        'instantiate'  => true,
                    ],
                ],
                'settings' => [
                    [
                        'class'        => 'SettingsController',
                        'instantiate'  => true,
                    ],
                ],
                'gpt' => [
                    [
                        'class'        => 'GptController',
                        'instantiate'  => true,
                    ],
                    [
                        'class'        => 'Controllers/ContextSearch',
                        'instantiate'  => false,
                    ],
                    [
                        'class'        => 'Controllers/CreateDirectory',
                        'instantiate'  => false,
                    ],
                    [
                        'class'        => 'Controllers/DeleteDirectory',
                        'instantiate'  => false,
                    ],
                    [
                        'class'        => 'Controllers/DeleteFile',
                        'instantiate'  => false,
                    ],
                    [
                        'class'        => 'Controllers/GetDirectory',
                        'instantiate'  => false,
                    ],
                    [
                        'class'        => 'Controllers/Memory',
                        'instantiate'  => false,
                    ],
                    [
                        'class'        => 'Controllers/ReadFile',
                        'instantiate'  => false,
                    ],
                    [
                        'class'        => 'Controllers/Weather',
                        'instantiate'  => false,
                    ],
                    [
                        'class'        => 'Controllers/WebCraw',
                        'instantiate'  => false,
                    ],
                    [
                        'class'        => 'Controllers/WriteFile',
                        'instantiate'  => false,
                    ],
                ],
                'help' => [
                    [
                        'class'        => 'HelpController',
                        'instantiate'  => true,
                    ],
                    [
                        'class'        => 'HelpAdminBar',
                        'instantiate'  => true,
                    ],
                ],
                'library' => [
                    [
                        'class'        => 'UsedArticlesRepository',
                        'instantiate'  => true,
                    ],
                    [
                        'class'        => 'LibraryController',
                        'instantiate'  => true,
                    ],
                ],
                'seo' => [
                    [
                        'class'        => 'YoastSeoIntegration',
                        'instantiate'  => true,
                    ],
                ],
                'cache' => [
                    [
                        'class'        => 'FlygRecacheService',
                        'instantiate'  => true,
                    ],
                    [
                        'class'        => 'SavePostFlygRecache',
                        'instantiate'  => true,
                    ],
                ],
            ],
            'moduleConfig' => [
                'settings' => [],
                'gpt' => [
                    // Third Key.
                    'key' => $openAiApiKey,
                    'user' => [
                        'logged' => false,
                        'id' => null,
                    ],
                    'temperature' => 0,
                    'hardDrive' => $pluginPath .'/modules/gpt/GptHardDrive/',
                ],
                'api' => [
                    'key' => '',
                    'defaultPrefix' => '',
                    'namespace' => '',
                ],
                'jobs' => [],
                'help' => [],
                'library' => [
                    'welcomeCtaUrl' => '',
                ],
                'seo' => [],
                'cache' => [],
            ],
            'resources' => [
                'scripts' => $pluginUrl .'resources/src/scripts/',
                'styles' => $pluginUrl .'resources/dist/styles/',
                'assets' => [
                    'img' => $pluginUrl .'resources/assets/img/',
                ],
            ],
        ];

        // Controllers Container.
        $this->controllers = [];

        spl_autoload_register([self::class, 'autoloadClass']);

        // Autoload.
        add_action('init', [$this, 'autoload'], 3);

        // Ensure scheduler hooks remain registered.
        add_action('init', [__CLASS__, 'ensureSchedulerEvent']);
        add_action('admin_init', [__CLASS__, 'ensureSchedulerEvent']);
        add_action('wp_loaded', [__CLASS__, 'ensureSchedulerEvent']);

        self::maybeEnableAlternateWpCron();

        add_filter('cron_request', [__CLASS__, 'filterCronRequestForLocalDocker'], 10, 2);
        add_filter('wp_ai_client_default_request_timeout', array(__CLASS__, 'filterAiClientRequestTimeout'));

        // Load Admin Resources.
        add_action('admin_enqueue_scripts', [$this, 'loadAdminResources']);
        add_action('login_enqueue_scripts', [$this, 'loadLoginResources']);

        // Init Pulse.
        add_action('wp_ajax_exmoau_pulse_vibe', [$this, 'pulseVibe']);
        add_action('wp_ajax_nopriv_exmoau_pulse_vibe', [$this, 'pulseVibe']);
    }

    /**
     * Allow long-running article and image generation requests to complete.
     *
     * @return float
     */
    public static function filterAiClientRequestTimeout() {
        return 120.0;
    }

    /**
     * Destructor placeholder.
     *
     * @return void
     */
    public function __destruct() {}

    /**
     * Retrieve the core loader instance.
     *
     * @return ExMomentAuthorCoreSystem
     */
    public static function getInstance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Autoload controllers defined in configuration.
     *
     * @return bool
     */
    public function autoload() {
        if (empty($this->config['autoload']) || !is_array($this->config['autoload'])) {
            return false;
        }

        foreach ($this->config['autoload'] as $moduleName => $moduleControllers) {
            if (empty($moduleControllers) || !is_array($moduleControllers)) {
                continue;
            }

            $namespace = [];
            $namespaceArr = explode('-', strtolower($moduleName));
            foreach ($namespaceArr as $namespacePart) {
                $namespace[] = ucfirst($namespacePart);
            }
            $namespaceName = implode('', $namespace);

            foreach ($moduleControllers as $controllerConfig) {
                if (is_string($controllerConfig)) {
                    $controllerConfig = [
                        'class'       => $controllerConfig,
                        'instantiate' => true,
                    ];
                }

                if (!is_array($controllerConfig)) {
                    continue;
                }

                $classSpec = (string) ($controllerConfig['class'] ?? '');
                $classSpec = trim(str_replace('\\', '/', $classSpec), '/');

                if ($classSpec === '') {
                    continue;
                }

                $instantiate = array_key_exists('instantiate', $controllerConfig) ?
                    (bool) $controllerConfig['instantiate'] :
                    true;

                $classParts = array_values(array_filter(
                    explode('/', $classSpec),
                    static function ($part) {
                        return $part !== '';
                    }
                ));

                if (empty($classParts)) {
                    continue;
                }

                $className = array_pop($classParts);
                $namespaceSuffixParts = array_merge($classParts, [$className]);

                $controller = sprintf(
                    '\\ExMomentAuthor\\Modules\\%s\\%s',
                    $namespaceName,
                    implode('\\', $namespaceSuffixParts)
                );

                if (!$instantiate) {
                    continue;
                }

                if (!empty($this->controllers[$className])) {
                    continue;
                }

                $moduleConfig = (
                    !empty($this->config['moduleConfig'][$moduleName]) ?
                    $this->config['moduleConfig'][$moduleName] :
                    []
                );

                if (
                    $moduleName === 'api' ||
                    $moduleName === 'gpt'
                ) {
                    $moduleConfig['user']['logged'] = \is_user_logged_in();
                    $moduleConfig['user']['id'] = \get_current_user_id();
                }

                try {
                    $this->controllers[$className] = new $controller($moduleConfig);
                } catch (\Throwable $exception) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log(
                            sprintf(
                                'ExMoment Author: Failed to initialise controller %s for module %s (%s).',
                                $controller,
                                $moduleName,
                                $exception->getMessage()
                            )
                        );
                    }

                    continue;
                }

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(
                        sprintf(
                            'ExMoment Author: Controller %s initialised for module %s.',
                            $controller,
                            $moduleName
                        )
                    );
                }
            }
        }

        return true;
    }

    /**
     * Determine whether core admin assets should load on the current screen.
     *
     * @param mixed $hookSuffix Current admin hook suffix.
     * @param mixed $screen Current admin screen object when available.
     * @return bool
     */
    private function shouldEnqueueAdminAssets($hookSuffix, $screen) {
        if (!is_string($hookSuffix) || $hookSuffix === '') {
            return false;
        }

        if (current_user_can('manage_options')) {
            return true;
        }

        if (strpos($hookSuffix, 'exmoau-') !== false || strpos($hookSuffix, 'exmoment-author') !== false) {
            return true;
        }

        $postType = '';
        if (is_object($screen) && isset($screen->post_type) && is_string($screen->post_type)) {
            $postType = $screen->post_type;
        }

        if ($postType !== 'exmoau_job') {
            return false;
        }

        if (in_array($hookSuffix, ['post.php', 'post-new.php', 'edit.php'], true)) {
            return true;
        }

        $screenBase = '';
        if (is_object($screen) && isset($screen->base) && is_string($screen->base)) {
            $screenBase = $screen->base;
        }

        return in_array($screenBase, ['post', 'edit'], true);
    }

    /**
     * Enqueue admin scripts and styles for the plugin.
     *
     * @param mixed $hookSuffix Current admin hook suffix.
     * @return void
     */
    public function loadAdminResources($hookSuffix = '') {
        $scriptHandle = 'exmoau-admin-core';
        $styleHandle = 'exmoau-admin-global';

        $jsBasePath = $this->config['resources']['scripts'];
        $jsResources = [
            'base' => $jsBasePath,
            'autoload' => $jsBasePath .'autoload/admin/',
            'dependencies' => $jsBasePath .'dependencies/admin/',
        ];

        $stylesPath = $this->config['resources']['styles'] .'admin/';

        wp_register_script(
            $scriptHandle,
            $jsResources['base'] .'core-admin.js',
            ['jquery'],
            $this->config['resourceVersion'],
            true
        );

        wp_register_style(
            $styleHandle,
            $stylesPath .'global.css',
            [],
            $this->config['resourceVersion'],
            'all'
        );

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$this->shouldEnqueueAdminAssets($hookSuffix, $screen)) {
            return;
        }

        $canManage = current_user_can('manage_options');
        $libraryHasContent = ($canManage ? $this->libraryHasContent() : true);
        $welcomeCtaUrl = $this->getLibraryWelcomeCtaUrl();
        $libraryAdminUrl = admin_url('tools.php?page=exmoau-library');

        wp_enqueue_script($scriptHandle);

        $localizationData = [
            'scripts' => [
                'dir' => $jsResources['autoload'],
                'dependenciesDir' => $jsResources['dependencies'],
                'version' => $this->config['resourceVersion'],
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'apiKey' => $this->config['moduleConfig']['api']['key'],
                'apiUrl' => (
                    get_site_url() .
                    $this->config['moduleConfig']['api']['defaultPrefix'] .
                    $this->config['moduleConfig']['api']['namespace']
                ),
            ],
            'library' => [
                'hasContent' => $libraryHasContent,
                'welcomeCtaUrl' => esc_url($welcomeCtaUrl),
                'libraryAdminUrl' => esc_url($libraryAdminUrl),
            ],
        ];

        wp_localize_script(
            $scriptHandle,
            'ExMomentAuthorAdminConfig',
            $localizationData
        );

        wp_enqueue_style($styleHandle);
    }

    /**
     * Enqueue minimal login scripts for session cleanup.
     *
     * @return void
     */
    public function loadLoginResources() {
        $handle = 'exmoau-login';

        wp_register_script(
            $handle,
            '',
            [],
            $this->config['resourceVersion'],
            true
        );

        wp_enqueue_script($handle);

        wp_add_inline_script(
            $handle,
            "try { sessionStorage.removeItem('exmoau_library_welcome_dismissed'); } catch (error) {}"
        );
        wp_add_inline_script(
            $handle,
            "try { sessionStorage.removeItem('exmoau_library_welcome_dismissed'); } catch (error) {}"
        );
    }

    /**
     * Determine whether the uploads library contains valid content.
     *
     * @return bool
     */
    private function libraryHasContent() {
        $uploadDir = wp_upload_dir();
        $baseDir = (isset($uploadDir['basedir']) ? $uploadDir['basedir'] : '');
        if ($baseDir === '') {
            return false;
        }

        $libraryRoot = trailingslashit($baseDir) . 'exmoau-library';
        if (!is_dir($libraryRoot) || !is_readable($libraryRoot)) {
            return false;
        }

        $items = scandir($libraryRoot);
        if (!is_array($items)) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            if (!$this->isSafeLibraryIdentifier($item, true)) {
                continue;
            }

            $directoryPath = $libraryRoot . DIRECTORY_SEPARATOR . $item;
            if (is_dir($directoryPath) && !is_link($directoryPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a library identifier for filesystem usage.
     *
     * @param mixed $value       Value to validate.
     * @param bool  $allowSpaces Whether spaces are permitted.
     * @return bool
     */
    private function isSafeLibraryIdentifier($value, $allowSpaces = false) {
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

        $pattern = ($allowSpaces ? '/^[A-Za-z0-9 _.-]+$/' : '/^[A-Za-z0-9_.-]+$/');
        return (bool) preg_match($pattern, $value);
    }

    /**
     * Sanitize the welcome CTA URL from config.
     *
     * @return string
     */
    private function getLibraryWelcomeCtaUrl() {
        $fallback = 'https://author.exmoment.com/get-free-content-pack/';
        $rawUrl = (
            isset($this->config['moduleConfig']['library']['welcomeCtaUrl']) ?
            $this->config['moduleConfig']['library']['welcomeCtaUrl'] :
            ''
        );

        if (!is_string($rawUrl)) {
            return esc_url_raw($fallback);
        }

        $rawUrl = trim($rawUrl);
        if ($rawUrl === '') {
            return esc_url_raw($fallback);
        }

        $validated = wp_http_validate_url($rawUrl);
        if (!$validated) {
            return esc_url_raw($fallback);
        }

        $scheme = wp_parse_url($validated, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return esc_url_raw($fallback);
        }

        return esc_url_raw($validated);
    }

    /**
     * Retrieve a loaded module controller.
     *
     * @param string $moduleName Controller key from autoload map.
     * @return mixed[]|object|null
     */
    public function getModule($moduleName) {
        return (
            !empty($this->controllers[$moduleName]) ?
            $this->controllers[$moduleName] :
            []
        );
    }

    /**
     * Simple heartbeat endpoint for AJAX checks.
     *
     * @return void
     */
    public function pulseVibe() {
        echo 'Vibe Check: Good;';
    }

    /**
     * Process an individual library bundle archive.
     *
     * @param string $archivePath     Absolute path to the zip archive.
     * @param string $destinationRoot Destination directory for extracted files.
     * @return array<string, mixed>
     */
    private static function processLibraryArchive($archivePath, $destinationRoot) {
        $bundleName = basename($archivePath);

        $zip = new ZipArchive();
        $openResult = $zip->open($archivePath);

        if (true !== $openResult) {
            return [
                'status'  => 'skipped',
                'message' => sprintf(
                    'Library seeding skipped %s because the archive could not be opened (error %d).',
                    $bundleName,
                    $openResult
                ),
            ];
        }

        $validation = self::validateArchive($zip);

        if (!$validation['valid']) {
            $zip->close();

            return [
                'status'  => 'skipped',
                'message' => sprintf(
                    'Library seeding skipped %s because %s.',
                    $bundleName,
                    $validation['reason']
                ),
            ];
        }

        $extraction = self::extractArchive($zip, $destinationRoot);
        $zip->close();

        if ($extraction['errors'] > 0) {
            return [
                'status'  => 'skipped',
                'message' => sprintf(
                    'Library seeding partially extracted %s before encountering %d error(s).',
                    $bundleName,
                    $extraction['errors']
                ),
            ];
        }

        return [
            'status'  => 'processed',
            'message' => sprintf(
                'Library seeding extracted %s with %d file(s) written and %d existing skipped.',
                $bundleName,
                $extraction['written'],
                $extraction['conflicts']
            ),
        ];
    }

    /**
     * Validate archive contents for safe extraction.
     *
     * @param ZipArchive $zip Archive instance to validate.
     * @return array{valid: bool, reason: string}
     */
    private static function validateArchive(ZipArchive $zip) {
        $hasDirectory = false;
        $fileCount = $zip->numFiles;

        for ($index = 0; $index < $fileCount; $index++) {
            $stat = $zip->statIndex($index);

            if (false === $stat || empty($stat['name'])) {
                continue;
            }

            $normalized = self::normalizeEntryName($stat['name']);

            if ('' === $normalized) {
                continue;
            }

            $unsafeReason = self::getUnsafePathReason($normalized);

            if (null !== $unsafeReason) {
                return [
                    'valid'  => false,
                    'reason' => $unsafeReason,
                ];
            }

            if (self::entryIsSymlink($stat)) {
                return [
                    'valid'  => false,
                    'reason' => 'it contains a symbolic link entry',
                ];
            }

            $trimmed = ltrim($normalized, '/');

            if ('' === $trimmed) {
                continue;
            }

            $segments = explode('/', $trimmed);
            $isDirectory = ('/' === substr($normalized, -1));

            if ($isDirectory || count($segments) > 1) {
                $hasDirectory = true;
            }
        }

        if (!$hasDirectory) {
            return [
                'valid'  => false,
                'reason' => 'its top level only contains files',
            ];
        }

        return [
            'valid'  => true,
            'reason' => '',
        ];
    }

    /**
     * Extract validated archive entries without overwriting existing files.
     *
     * @param ZipArchive $zip             Archive instance ready for extraction.
     * @param string     $destinationRoot Destination directory.
     * @return array{written: int, conflicts: int, errors: int}
     */
    private static function extractArchive(ZipArchive $zip, $destinationRoot) {
        $written = 0;
        $conflicts = 0;
        $errors = 0;
        $fileCount = $zip->numFiles;

        for ($index = 0; $index < $fileCount; $index++) {
            $stat = $zip->statIndex($index);

            if (false === $stat || empty($stat['name'])) {
                continue;
            }

            $normalized = self::normalizeEntryName($stat['name']);
            $trimmed = ltrim($normalized, '/');

            if ('' === $trimmed) {
                continue;
            }

            $isDirectory = ('/' === substr($normalized, -1));

            if ($isDirectory) {
                $directoryRelative = rtrim($trimmed, '/');

                if ('' === $directoryRelative) {
                    continue;
                }

                $targetDirectory = self::pathJoin($destinationRoot, $directoryRelative);

                if (!is_dir($targetDirectory) && !wp_mkdir_p($targetDirectory)) {
                    $errors++;
                    self::logDebug('Library seeding could not create directory %s.', $directoryRelative);

                    break;
                }

                continue;
            }

            $parentRelative = dirname($trimmed);

            if ('.' !== $parentRelative && '' !== $parentRelative) {
                $parentDirectory = self::pathJoin($destinationRoot, $parentRelative);

                if (!is_dir($parentDirectory) && !wp_mkdir_p($parentDirectory)) {
                    $errors++;
                    self::logDebug('Library seeding could not create parent directory for %s.', $trimmed);

                    break;
                }
            }

            $targetPath = self::pathJoin($destinationRoot, $trimmed);

            if (file_exists($targetPath)) {
                $conflicts++;

                continue;
            }

            $stream = $zip->getStream($stat['name']);

            if (false === $stream) {
                $errors++;
                self::logDebug('Library seeding could not read %s from archive.', $trimmed);

                break;
            }

            $contents = stream_get_contents($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (false === $contents) {
                $errors++;
                self::logDebug('Library seeding failed while reading %s from archive.', $trimmed);

                break;
            }

            if (false === file_put_contents($targetPath, $contents)) {
                $errors++;
                self::logDebug('Library seeding could not write file %s.', $trimmed);

                break;
            }

            $written++;
        }

        return [
            'written'   => $written,
            'conflicts' => $conflicts,
            'errors'    => $errors,
        ];
    }

    /**
     * Normalize archive entry names to use forward slashes.
     *
     * @param string $name Raw entry name from the archive.
     * @return string
     */
    private static function normalizeEntryName($name) {
        $cleanName = str_replace("\0", '', $name);

        if (function_exists('wp_normalize_path')) {
            return wp_normalize_path($cleanName);
        }

        return str_replace('\\', '/', $cleanName);
    }

    /**
     * Determine whether an archive entry references an unsafe path.
     *
     * @param string $path Normalized entry path.
     * @return string|null
     */
    private static function getUnsafePathReason($path) {
        $normalized = str_replace('\\', '/', $path);

        if (preg_match('#^[A-Za-z]:/#', $normalized)) {
            return sprintf('it references an absolute path entry (%s)', $normalized);
        }

        if (strpos($normalized, '../') !== false) {
            return sprintf('it contains traversal segments (%s)', $normalized);
        }

        if ('/' === substr($normalized, 0, 1)) {
            return sprintf('it references an absolute path entry (%s)', $normalized);
        }

        $segments = explode('/', $normalized);

        foreach ($segments as $segment) {
            if ('..' === $segment) {
                return sprintf('it contains traversal segments (%s)', $normalized);
            }
        }

        return null;
    }

    /**
     * Check whether an archive entry is a symbolic link.
     *
     * @param array<string, mixed> $stat Archive entry metadata.
     * @return bool
     */
    private static function entryIsSymlink($stat) {
        if (!isset($stat['external_attributes'])) {
            return false;
        }

        $attributes = $stat['external_attributes'];
        $fileType = ($attributes >> 16) & 0xF000;

        return 0120000 === $fileType;
    }

    /**
     * Join a base path with a relative archive path.
     *
     * @param string $base     Destination base directory.
     * @param string $relative Relative path from the archive.
     * @return string
     */
    private static function pathJoin($base, $relative) {
        $basePath = rtrim($base, '/\\');

        if ('' === $relative || '.' === $relative) {
            return $basePath;
        }

        $segments = explode('/', str_replace('\\', '/', $relative));
        $path = $basePath;

        foreach ($segments as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }

            $path .= DIRECTORY_SEPARATOR . $segment;
        }

        return $path;
    }

    /**
     * Determine whether the local Docker cron compatibility mode is enabled.
     *
     * @return bool
     */
    private static function isLocalDockerCronFixEnabled() {
        $explicitFlag = getenv('EXMOAU_LOCAL_DOCKER_CRON_FIX');
        $dockerFlag = getenv('DOCKER');

        return (
            $explicitFlag === '1' ||
            $dockerFlag === '1' ||
            file_exists('/.dockerenv')
        );
    }

    /**
     * Enable alternate cron spawning behavior in local Docker environments.
     *
     * @return void
     */
    private static function maybeEnableAlternateWpCron() {
        if (!self::isLocalDockerCronFixEnabled() || (defined('WP_CLI') && WP_CLI)) {
            return;
        }

        if (!defined('ALTERNATE_WP_CRON')) {
            define('ALTERNATE_WP_CRON', true);
        }
    }

    /**
     * Rewrite cron spawn loopback requests for local Docker compatibility.
     *
     * @param mixed $cronRequest Cron request payload from WordPress.
     * @param mixed $doingWpCron Unique cron lock token provided by WordPress.
     * @return mixed
     */
    public static function filterCronRequestForLocalDocker($cronRequest, $doingWpCron) {
        if (!self::isLocalDockerCronFixEnabled() || (defined('WP_CLI') && WP_CLI)) {
            return $cronRequest;
        }

        if (!is_array($cronRequest) || !array_key_exists('url', $cronRequest)) {
            return $cronRequest;
        }

        $url = $cronRequest['url'];
        if (!is_string($url) || $url === '') {
            return $cronRequest;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || !is_string($parts['host'])) {
            return $cronRequest;
        }

        $host = strtolower($parts['host']);
        $loopbackHosts = array('localhost', '127.0.0.1', '::1');
        if (!in_array($host, $loopbackHosts, true)) {
            return $cronRequest;
        }

        $parts['host'] = 'host.docker.internal';
        $rewrittenUrl = self::buildUrlFromParts($parts);

        if ($rewrittenUrl === '') {
            return $cronRequest;
        }

        $cronRequest['url'] = $rewrittenUrl;

        return $cronRequest;
    }

    /**
     * Rebuild a URL from wp_parse_url()-style parts.
     *
     * @param array<string, mixed> $parts URL parts.
     * @return string
     */
    private static function buildUrlFromParts($parts) {
        $scheme = (isset($parts['scheme']) && is_string($parts['scheme']) && $parts['scheme'] !== '') ?
            $parts['scheme'] . '://' :
            '';
        $user = (isset($parts['user']) && is_string($parts['user'])) ? $parts['user'] : '';
        $pass = (isset($parts['pass']) && is_string($parts['pass'])) ? ':' . $parts['pass']  : '';
        $auth = ($user !== '' || $pass !== '') ? $user . $pass . '@' : '';
        $host = (isset($parts['host']) && is_string($parts['host'])) ? $parts['host'] : '';
        $port = (isset($parts['port']) && is_int($parts['port'])) ? ':' . $parts['port'] : '';
        $path = (isset($parts['path']) && is_string($parts['path'])) ? $parts['path'] : '';
        $query = (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') ?
            '?' . $parts['query'] :
            '';
        $fragment = (isset($parts['fragment']) && is_string($parts['fragment']) && $parts['fragment'] !== '') ?
            '#' . $parts['fragment'] :
            '';

        if ($host === '') {
            return '';
        }

        return $scheme . $auth . $host . $port . $path . $query . $fragment;
    }

    /**
     * Write a debug log message when WP_DEBUG is enabled.
     *
     * @param string $message Log message with optional sprintf tokens.
     * @param mixed  ...$args Values for the formatted message.
     * @return void
     */
    private static function logDebug($message, ...$args) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        if (!empty($args)) {
            $message = vsprintf($message, $args);
        }

        error_log(sprintf('ExMoment Author: %s', $message));
    }

}

ExMomentAuthorCoreSystem::registerActivationHook();

$ExMomentAuthorCoreSystem = ExMomentAuthorCoreSystem::getInstance();
$ExMomentAuthorCoreSystem = $ExMomentAuthorCoreSystem;
$ExoCoreInternalSystem = $ExMomentAuthorCoreSystem;
