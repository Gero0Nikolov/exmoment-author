<?php

namespace ExMomentAuthor\Modules\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use ExMomentAuthor\Core\ExMomentAuthorCoreSystem;
use ExMomentAuthor\Modules\Gpt\GptController;
use ExMomentAuthor\Modules\Log\LogService;

/**
 * Orchestrates Settings sub-controllers and shared configuration.
 */
class SettingsController {

    /**
     * Option prefix applied to all settings managed by this module.
     */
    private const OPTION_PREFIX = 'exmoau_';

    /**
     * Settings group identifier used with the WordPress Settings API.
     */
    public const SETTINGS_GROUP = 'exmoau_settings';

    /**
     * Admin page slug for the ExMoment Author settings screen.
     */
    public const PAGE_SLUG = 'exmoau-settings';

    /**
     * Transient key used to cache settings managed by this module.
     */
    private const SETTINGS_TRANSIENT = 'exmoau_settings_cache';

    /**
     * Transient expiration interval (seven days).
     */
    private const SETTINGS_TRANSIENT_EXPIRATION = 604800;

    /**
     * Object cache group used when normalizing option autoload flags.
     */
    private const AUTOLOAD_CACHE_GROUP = 'exmoau_settings_autoload';

    /**
     * Cache lifetime (five minutes) for autoload normalization markers.
     */
    private const AUTOLOAD_CACHE_TTL = 300;

    /**
     * Cache sentinel value used when an option autoload flag is unavailable.
     */
    private const AUTOLOAD_CACHE_MISS = 'exmoau_settings_autoload_cache_miss';

    /**
     * Default AI behaviour mode when no value has been stored.
     */
    private const DEFAULT_AI_BEHAVIOUR_MODE = 'autonomous';

    /**
     * Default AI model selection when no value has been stored.
     */
    private const DEFAULT_AI_MODEL = 'gpt-5';

    /**
     * Default weight key used for OpenAI requests when configuration is missing or invalid.
     */
    private const DEFAULT_OPENAI_WEIGHT_KEY = '2aq';

    /**
     * Default AI image model identifier when no value has been stored.
     */
    private const DEFAULT_AI_IMAGE_MODEL = 'dall-e-3';

    /**
     * Default state for AI image generation toggle.
     */
    private const DEFAULT_AI_IMAGE_GENERATION_ENABLED = '1';

    /**
     * Minimum pixel dimension allowed for AI-generated images.
     */
    private const MIN_AI_IMAGE_DIMENSION = 256;

    /**
     * Maximum pixel dimension allowed for AI-generated images.
     */
    private const MAX_AI_IMAGE_DIMENSION = 2048;

    /**
     * Default pixel dimension used for width and height when inputs are missing or invalid.
     */
    private const DEFAULT_AI_IMAGE_DIMENSION = 1024;

    /**
     * Default dimensions preset for AI-generated images.
     */
    private const DEFAULT_AI_IMAGE_DIMENSIONS = '1024x1024';

    /**
     * Allowed dimension presets for AI-generated images.
     *
     * @var string[]
     */
    private const ALLOWED_AI_IMAGE_DIMENSIONS = [
        '1024x1024',
        '1536x1024',
        '1024x1536',
    ];

    /**
     * Maximum length for the AI image style prompt.
     */
    private const MAX_AI_IMAGE_STYLE_PROMPT_LENGTH = 1000;

    /**
     * Maximum number of characters allowed for user-defined system prompts.
     */
    private const MAX_USER_SYSTEM_PROMPT_LENGTH = 10000;

    /**
     * Allowed AI behaviour mode values.
     *
     * @var string[]
     */
    private const AI_BEHAVIOUR_MODES = [
        'autonomous',
        'augmented',
        'manual',
    ];

    /**
     * Option key used to store the optimized augmented system prompt record.
     */
    private const AUGMENTED_OPTIMIZED_PROMPT_OPTION_KEY = 'augmented_optimized_system_prompt';

    /**
     * Transient key used to cache the optimized augmented system prompt record.
     */
    private const AUGMENTED_PROMPT_TRANSIENT_KEY = 'exmoau_augmented_system_prompt_cache';

    /**
     * Transient expiration interval for the optimized prompt cache (seven days).
     */
    private const AUGMENTED_PROMPT_TRANSIENT_TTL = 604800;

    /**
     * Transient key for the most recent augmentation failure diagnostics snapshot.
     */
    private const AUGMENTED_PROMPT_FAILURE_TRANSIENT_KEY = 'exmoau_augmented_prompt_failure_diag';

    /**
     * Lifetime for augmentation failure diagnostics cached via transient (five minutes).
     */
    private const AUGMENTED_PROMPT_FAILURE_TRANSIENT_TTL = 300;

    /**
     * System message provided to the augmentation model.
     */
    private const AUGMENTATION_SYSTEM_MESSAGE = 'Rewrite the provided system prompt for maximum clarity and explicit instructions. The prompt will control an AI that generates new content using separate source material. Respond with the improved system prompt text only.';

    /**
     * Module configuration array.
     *
     * @var array<string, mixed>
     */
    public static $config = [];

    /**
     * Loaded sub-controllers indexed by class name.
     *
     * @var array<string, object>
     */
    protected $controllers = [];

    /**
     * Cached AI model metadata for the duration of the current request.
     *
     * @var array<string, array<int, array{id: string, name: string}>>
     */
    private static $availableAiModelsCache = [];

    /**
     * Cached OpenAI weight key for the current request.
     *
     * @var string|null
     */
    private static $cachedOpenAiWeightKey = null;

    /**
     * Tracks sanitized values for the current settings submission.
     *
     * @var array<string, mixed>
     */
    private static $submissionState = [
        'initialised' => false,
        'behaviour' => null,
        'behaviour_previous' => null,
        'behaviour_changed' => false,
        'augmented_prompt' => null,
        'augmented_prompt_previous' => null,
        'prompt_changed' => false,
        'augmented_model' => null,
        'augmented_model_previous' => null,
        'model_changed' => false,
        'augmentation_attempted' => false,
        'augmentation_error_added' => false,
        'augmentation_missing_key_added' => false,
        'augmentation_diagnostics' => null,
    ];

    /**
     * Allowed image model identifiers.
     *
     * @var string[]
     */
    private const AI_IMAGE_MODELS = [
        'dall-e-3',
        'gpt-image-1-mini',
        'gpt-image-1',
    ];

    /**
     * Instantiate the controller and autoload Settings sub-controllers.
     *
     * @param array<string, mixed> $config Optional module configuration overriding defaults such as the controllers directory.
     * @return void
     */
    public function __construct($config = []) {
        self::$config = array_merge(
            (is_array($config) ? $config : []),
            [
                'controllersPath' => dirname(__FILE__) . '/controllers/',
                'restrictedControllers' => [
                    '.',
                    '..',
                    'index.php',
                ],
            ]
        );

        $this->autoload();
    }

    /**
     * Scan the controllers directory, instantiate each controller, and register hooks.
     *
     * @return bool True when at least one controller is loaded and registration callbacks are attempted.
     */
    protected function autoload() {
        $controllersPath = (self::$config['controllersPath'] ?? '');

        if (empty($controllersPath) || !is_dir($controllersPath)) {
            return false;
        }

        $controllersDir = scandir($controllersPath);

        if (empty($controllersDir) || count($controllersDir) <= 2) {
            return false;
        }

        foreach ($controllersDir as $controllerScript) {
            if (in_array($controllerScript, self::$config['restrictedControllers'], true)) {
                continue;
            }

            if (substr($controllerScript, -4) !== '.php') {
                continue;
            }

            $controllerPath = $controllersPath . $controllerScript;
            $controllerClassName = explode('.php', $controllerScript)[0];

            if (!file_exists($controllerPath) || !empty($this->controllers[$controllerClassName])) {
                continue;
            }

            require_once $controllerPath;

            $controllerClass = sprintf(
                '\\ExMomentAuthor\\Modules\\Settings\\Controllers\\%s',
                $controllerClassName
            );

            try {
                $controllerInstance = new $controllerClass();
            } catch (\Throwable $exception) {
                self::logDebugMessage(
                    sprintf(
                        'Settings controller %s failed to initialize: %s',
                        $controllerClass,
                        $exception->getMessage()
                    ),
                    [
                        'controller' => $controllerClass,
                        'script'     => $controllerScript,
                    ]
                );

                continue;
            }

            if (method_exists($controllerInstance, 'register')) {
                $controllerInstance->register();
            } else {
                self::logDebugMessage(
                    sprintf(
                        'Settings controller %s missing register() method.',
                        $controllerClass
                    ),
                    [
                        'controller' => $controllerClass,
                    ]
                );
            }

            $this->controllers[$controllerClassName] = $controllerInstance;
        }

        return true;
    }

    /**
     * Register settings and related sanitization callbacks with WordPress.
     *
     * Declares each tracked option with the Settings API, wiring up sanitizers
     * and cache invalidation hooks so cached payloads automatically refresh
     * when values are added, updated, or removed.
     *
     * @return void
     */
    public static function register() {
        $openAiOptionName = self::getOptionName('openai_api_key');

        register_setting(
            self::SETTINGS_GROUP,
            $openAiOptionName,
            [
                'type'              => 'string',
                'sanitize_callback' => [self::class, 'sanitizeOpenAiApiKey'],
                'default'           => '',
                'capability'        => 'manage_options',
            ]
        );

        self::registerOptionCacheHooks($openAiOptionName);
        self::registerModelCacheInvalidationHooks($openAiOptionName);
        self::registerAugmentedPromptCacheInvalidationHooks($openAiOptionName);
        self::registerModelCacheInvalidationHooks('openai_api_key');
        self::registerAugmentedPromptCacheInvalidationHooks('openai_api_key');

        $openAiWeightKeyOptionName = self::getOptionName('openai_weight_key');

        register_setting(
            self::SETTINGS_GROUP,
            $openAiWeightKeyOptionName,
            [
                'type'              => 'string',
                'sanitize_callback' => [self::class, 'sanitizeOpenAiWeightKey'],
                'default'           => self::DEFAULT_OPENAI_WEIGHT_KEY,
                'capability'        => 'manage_options',
            ]
        );

        self::registerOptionCacheHooks($openAiWeightKeyOptionName);

        $gptDebugModeOptionName = self::getOptionName('gpt_debug_mode');

        register_setting(
            self::SETTINGS_GROUP,
            $gptDebugModeOptionName,
            [
                'type'              => 'string',
                'sanitize_callback' => [self::class, 'sanitizeGptDebugMode'],
                'default'           => '0',
                'capability'        => 'manage_options',
            ]
        );

        self::registerOptionCacheHooks($gptDebugModeOptionName);

        $aiBehaviourOptionName = self::getOptionName('ai_behaviour_mode');

        register_setting(
            self::SETTINGS_GROUP,
            $aiBehaviourOptionName,
            [
                'type'              => 'string',
                'sanitize_callback' => [self::class, 'sanitizeAiBehaviourMode'],
                'default'           => self::DEFAULT_AI_BEHAVIOUR_MODE,
                'capability'        => 'manage_options',
            ]
        );

        self::registerOptionCacheHooks($aiBehaviourOptionName);

        $augmentedUserPromptOptionName = self::getOptionName('augmented_user_system_prompt');

        register_setting(
            self::SETTINGS_GROUP,
            $augmentedUserPromptOptionName,
            [
                'type'              => 'string',
                'sanitize_callback' => [self::class, 'sanitizeAugmentedUserSystemPrompt'],
                'default'           => '',
                'capability'        => 'manage_options',
            ]
        );

        self::registerOptionCacheHooks($augmentedUserPromptOptionName);
        self::registerAugmentedPromptCacheInvalidationHooks($augmentedUserPromptOptionName);

        $augmentedAiModelOptionName = self::getOptionName('augmented_ai_model');

        register_setting(
            self::SETTINGS_GROUP,
            $augmentedAiModelOptionName,
            [
                'type'              => 'string',
                'sanitize_callback' => [self::class, 'sanitizeAugmentedAiModel'],
                'default'           => self::DEFAULT_AI_MODEL,
                'capability'        => 'manage_options',
            ]
        );

        self::registerOptionCacheHooks($augmentedAiModelOptionName);
        self::registerAugmentedPromptCacheInvalidationHooks($augmentedAiModelOptionName);

        $manualUserPromptOptionName = self::getOptionName('manual_user_system_prompt');

        register_setting(
            self::SETTINGS_GROUP,
            $manualUserPromptOptionName,
            [
                'type'              => 'string',
                'sanitize_callback' => [self::class, 'sanitizeManualUserSystemPrompt'],
                'default'           => '',
                'capability'        => 'manage_options',
            ]
        );

        self::registerOptionCacheHooks($manualUserPromptOptionName);

        $manualAiModelOptionName = self::getOptionName('manual_ai_model');

        register_setting(
            self::SETTINGS_GROUP,
            $manualAiModelOptionName,
            [
                'type'              => 'string',
                'sanitize_callback' => [self::class, 'sanitizeManualAiModel'],
                'default'           => self::DEFAULT_AI_MODEL,
                'capability'        => 'manage_options',
            ]
        );

        self::registerOptionCacheHooks($manualAiModelOptionName);

        $aiImageModelOptionName = self::getOptionName('ai_image_model');

        register_setting(
            self::SETTINGS_GROUP,
            $aiImageModelOptionName,
            [
                'type'              => 'string',
                'sanitize_callback' => [self::class, 'sanitizeAiImageModel'],
                'default'           => self::DEFAULT_AI_IMAGE_MODEL,
                'capability'        => 'manage_options',
            ]
        );

        self::registerOptionCacheHooks($aiImageModelOptionName);

        $aiImageGenerationEnabledOptionName = self::getOptionName('ai_image_generation_enabled');

        register_setting(
            self::SETTINGS_GROUP,
            $aiImageGenerationEnabledOptionName,
            [
                'type'              => 'string',
                'sanitize_callback' => [self::class, 'sanitizeAiImageGenerationEnabled'],
                'default'           => self::DEFAULT_AI_IMAGE_GENERATION_ENABLED,
                'capability'        => 'manage_options',
            ]
        );

        self::registerOptionCacheHooks($aiImageGenerationEnabledOptionName);

        $aiImageStylePromptOptionName = self::getOptionName('ai_image_style_prompt');

        register_setting(
            self::SETTINGS_GROUP,
            $aiImageStylePromptOptionName,
            [
                'type'              => 'string',
                'sanitize_callback' => [self::class, 'sanitizeAiImageStylePrompt'],
                'default'           => '',
                'capability'        => 'manage_options',
            ]
        );

        self::registerOptionCacheHooks($aiImageStylePromptOptionName);

        $aiImageDimensionsOptionName = self::getOptionName('ai_image_dimensions');

        register_setting(
            self::SETTINGS_GROUP,
            $aiImageDimensionsOptionName,
            [
                'type'              => 'string',
                'sanitize_callback' => [self::class, 'sanitizeAiImageDimensions'],
                'default'           => self::DEFAULT_AI_IMAGE_DIMENSIONS,
                'capability'        => 'manage_options',
            ]
        );

        self::registerOptionCacheHooks($aiImageDimensionsOptionName);

        $aiImageWidthOptionName = self::getOptionName('ai_image_width');

        register_setting(
            self::SETTINGS_GROUP,
            $aiImageWidthOptionName,
            [
                'type'              => 'string',
                'sanitize_callback' => [self::class, 'sanitizeAiImageWidth'],
                'default'           => (string) self::DEFAULT_AI_IMAGE_DIMENSION,
                'capability'        => 'manage_options',
            ]
        );

        self::registerOptionCacheHooks($aiImageWidthOptionName);

        $aiImageHeightOptionName = self::getOptionName('ai_image_height');

        register_setting(
            self::SETTINGS_GROUP,
            $aiImageHeightOptionName,
            [
                'type'              => 'string',
                'sanitize_callback' => [self::class, 'sanitizeAiImageHeight'],
                'default'           => (string) self::DEFAULT_AI_IMAGE_DIMENSION,
                'capability'        => 'manage_options',
            ]
        );

        self::registerOptionCacheHooks($aiImageHeightOptionName);

        $mixtureUniquenessOptionName = self::getOptionName('setup_mixture_uniqueness');

        register_setting(
            self::SETTINGS_GROUP,
            $mixtureUniquenessOptionName,
            [
                'type'              => 'string',
                'sanitize_callback' => [self::class, 'sanitizeMixtureUniquenessFlag'],
                'default'           => '0',
                'capability'        => 'manage_options',
            ]
        );

        self::registerOptionCacheHooks($mixtureUniquenessOptionName);

        add_settings_section(
            'exmoau_settings_section_openai',
            '',
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_section(
            'exmoau_settings_section_ai_setup',
            '',
            '__return_false',
            self::PAGE_SLUG
        );
    }

    /**
     * Retrieve a sanitized option value for the provided key from the cached payload.
     *
     * Falls back to rebuilding the settings cache when the expected entry is
     * missing and guarantees that a scalar default is coerced to a string when
     * the stored value is not usable.
     *
     * @param string $key     Option key without prefix.
     * @param string $default Optional default used when the option is absent or not a string.
     * @return string Normalized option value with defaults forced to a string when necessary.
     */
    public static function getOption($key, $default = '') {
        $optionName = self::getOptionName($key);
        $settings = self::getSettingsCache();
        $defaultValue = (is_string($default) ? $default : '');

        if (!is_array($settings) || !array_key_exists($optionName, $settings)) {
            $settings = self::rebuildSettingsCache();
        }

        if (is_array($settings) && array_key_exists($optionName, $settings)) {
            $value = $settings[$optionName];

            if (is_string($value)) {
                return $value;
            }

            if (is_scalar($value)) {
                return (string) $value;
            }

            return $defaultValue;
        }

        return $defaultValue;
    }

    /**
     * Retrieve the fully qualified option field name for the provided key.
     *
     * Applies the module option prefix so forms can submit directly to the
     * stored option name without additional concatenation logic.
     *
     * @param string $key Option key without prefix.
     * @return string Prefixed option name suitable for Settings API usage.
     */
    public static function getOptionFieldName($key) {
        return self::getOptionName($key);
    }

    /**
     * Determine whether mixture uniqueness enforcement is enabled.
     *
     * Reads from the cached option array, sanitizes the stored scalar value, and
     * coerces any non-truthy submission to the disabled state.
     *
     * @return bool True when the option ultimately resolves to "1" after sanitization.
     */
    public static function isMixtureUniquenessEnabled() {
        $optionName = self::getOptionName('setup_mixture_uniqueness');
        $settings = self::getSettingsCache();

        $rawValue = (is_array($settings) && array_key_exists($optionName, $settings))
            ? $settings[$optionName]
            : '0';

        if (is_array($rawValue)) {
            $rawValue = reset($rawValue);
        }

        if (is_string($rawValue) || is_numeric($rawValue) || is_bool($rawValue)) {
            $sanitized = self::sanitizeMixtureUniquenessFlag($rawValue);
        } else {
            $sanitized = '0';
        }

        return ('1' === $sanitized);
    }

    /**
     * Determine whether GPT debug mode is currently active.
     *
     * Reads the cached option value and forces a boolean interpretation while
     * emitting debug logging when WP_DEBUG is enabled.
     *
     * @return bool True when the stored option resolves to the enabled flag.
     */
    public static function isGptDebugModeEnabled() {
        $value = self::getOption('gpt_debug_mode', '0');

        if (!is_string($value)) {
            return false;
        }

        $enabled = ($value === '1');

        if ($enabled) {
            self::logDebugMessage('GPT debug mode option read as enabled.');
        }

        return $enabled;
    }

    /**
     * Retrieve the configured OpenAI weight key.
     *
     * Falls back to the default weight key when the stored value is empty or
     * no longer present in the GPT weights map. The result is cached for the
     * duration of the request to avoid repeated lookups.
     *
     * @return string
     */
    public static function getOpenAiWeightKey() {
        if (is_string(self::$cachedOpenAiWeightKey)) {
            return self::$cachedOpenAiWeightKey;
        }

        $weightsMap = GptController::getWeightsMap();
        $weightKey = self::getOption('openai_weight_key', self::DEFAULT_OPENAI_WEIGHT_KEY);

        if (!is_string($weightKey)) {
            if (is_scalar($weightKey)) {
                $weightKey = (string) $weightKey;
            } else {
                $weightKey = '';
            }
        }

        $rawWeightKey = sanitize_text_field($weightKey);
        $rawWeightKey = trim($rawWeightKey);
        $weightKey = $rawWeightKey;

        if ($weightKey === '' || !array_key_exists($weightKey, $weightsMap)) {
            $weightKey = self::DEFAULT_OPENAI_WEIGHT_KEY;

            if (
                function_exists('add_settings_error')
                && is_admin()
                && $rawWeightKey !== ''
                && !array_key_exists($rawWeightKey, $weightsMap)
            ) {
                add_settings_error(
                    self::SETTINGS_GROUP,
                    'exmoau_openai_weight_key_missing',
                    esc_html__('The stored OpenAI weight preset is no longer available. Defaulting to 2aq.', 'exmoment-author'),
                    'error'
                );
            }
        }

        self::$cachedOpenAiWeightKey = $weightKey;

        return $weightKey;
    }

    /**
     * Retrieve the default OpenAI weight key used as a fallback.
     *
     * @return string
     */
    public static function getDefaultOpenAiWeightKey() {
        return self::DEFAULT_OPENAI_WEIGHT_KEY;
    }

    /**
     * Retrieve the configured AI image model.
     *
     * Ensures the returned identifier is always one of the allowed image models,
     * falling back to the default when the stored value is missing or invalid.
     *
     * @return string
     */
    public static function getAiImageModel() {
        $model = self::getOption('ai_image_model', self::DEFAULT_AI_IMAGE_MODEL);

        if (!is_string($model)) {
            return self::DEFAULT_AI_IMAGE_MODEL;
        }

        $model = strtolower(trim($model));

        if (!in_array($model, self::AI_IMAGE_MODELS, true)) {
            return self::DEFAULT_AI_IMAGE_MODEL;
        }

        return $model;
    }

    /**
     * Determine whether AI image generation is enabled.
     *
     * Defaults to enabled when the setting is absent or invalid, ensuring
     * backwards compatibility for existing installations.
     *
     * @return bool
     */
    public static function isAiImageGenerationEnabled() {
        $enabled = self::getOption('ai_image_generation_enabled', self::DEFAULT_AI_IMAGE_GENERATION_ENABLED);

        if (is_array($enabled)) {
            $enabled = reset($enabled);
        }

        if (is_bool($enabled)) {
            return $enabled;
        }

        if (is_string($enabled)) {
            $enabled = trim($enabled);
        }

        $enabled = (int) $enabled;

        return ($enabled === 1);
    }

    /**
     * Retrieve the configured AI image style prompt.
     *
     * Returns a sanitized, trimmed string respecting the maximum length
     * constraint. Empty strings are preserved to disable the prepended style.
     *
     * @return string
     */
    public static function getAiImageStylePrompt() {
        $prompt = self::getOption('ai_image_style_prompt', '');

        if (!is_string($prompt)) {
            return '';
        }

        $prompt = str_replace(["\r\n", "\r"], "\n", $prompt);
        $prompt = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $prompt);
        $prompt = is_string($prompt) ? $prompt : '';
        $prompt = sanitize_textarea_field($prompt);
        $prompt = trim($prompt);

        if ($prompt === '') {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($prompt) : strlen($prompt);

        if ($length > self::MAX_AI_IMAGE_STYLE_PROMPT_LENGTH) {
            $prompt = function_exists('mb_substr')
                ? mb_substr($prompt, 0, self::MAX_AI_IMAGE_STYLE_PROMPT_LENGTH)
                : substr($prompt, 0, self::MAX_AI_IMAGE_STYLE_PROMPT_LENGTH);
        }

        return $prompt;
    }

    /**
     * Retrieve the configured AI image dimensions preset.
     *
     * Prefers the dedicated dimensions option, falls back to legacy width/height
     * when they map to an allowed preset, and otherwise returns the default
     * dimensions string.
     *
     * @return string
     */
    public static function getAiImageDimensions() {
        $dimensions = self::getOption('ai_image_dimensions', '');
        $normalized = self::normalizeAiImageDimensionsValue($dimensions);

        if ($normalized !== '') {
            return $normalized;
        }

        $legacyWidth = self::getOption('ai_image_width', (string) self::DEFAULT_AI_IMAGE_DIMENSION);
        $legacyHeight = self::getOption('ai_image_height', (string) self::DEFAULT_AI_IMAGE_DIMENSION);
        $legacyPreset = self::mapLegacyDimensionsToPreset($legacyWidth, $legacyHeight);

        if ($legacyPreset !== '') {
            return $legacyPreset;
        }

        return self::DEFAULT_AI_IMAGE_DIMENSIONS;
    }

    /**
     * Retrieve the configured AI image width.
     *
     * @return int
     */
    public static function getAiImageWidth() {
        $width = self::getOption('ai_image_width', (string) self::DEFAULT_AI_IMAGE_DIMENSION);

        return self::normalizeAiImageDimension($width);
    }

    /**
     * Retrieve the configured AI image height.
     *
     * @return int
     */
    public static function getAiImageHeight() {
        $height = self::getOption('ai_image_height', (string) self::DEFAULT_AI_IMAGE_DIMENSION);

        return self::normalizeAiImageDimension($height);
    }

    /**
     * Retrieve the default AI model identifier used when selections fail validation.
     *
     * @return string Canonical model slug configured for fallback behaviour.
     */
    public static function getDefaultAiModel() {
        return self::DEFAULT_AI_MODEL;
    }

    /**
     * Retrieve the default AI image model identifier.
     *
     * @return string
     */
    public static function getDefaultAiImageModel() {
        return self::DEFAULT_AI_IMAGE_MODEL;
    }

    /**
     * Retrieve the default pixel dimension applied to AI-generated images.
     *
     * @return int
     */
    public static function getDefaultAiImageDimension() {
        return self::DEFAULT_AI_IMAGE_DIMENSION;
    }

    /**
     * Retrieve the default dimensions preset for AI-generated images.
     *
     * @return string
     */
    public static function getDefaultAiImageDimensions() {
        return self::DEFAULT_AI_IMAGE_DIMENSIONS;
    }

    /**
     * Retrieve allowed AI image dimension presets.
     *
     * @return string[]
     */
    public static function getAllowedAiImageDimensions() {
        return self::ALLOWED_AI_IMAGE_DIMENSIONS;
    }

    /**
     * Retrieve the default autonomous system prompt template.
     *
     * Provides the baseline system prompt used whenever user-provided prompts
     * sanitize down to an empty string. A filter is exposed so integrators can
     * override or localize the prompt outside the translation system.
     *
     * @return string Default prompt text ready for display or API usage.
     */
    public static function getAutonomousSystemPrompt() {
        $lines = [
            'You are an expert editorial engine that **blends multiple source articles** into a **single, original piece** optimized for search and reader value.',
            '',
            '**Your objectives**',
            '1) Create a new article that **synthesizes** the ideas, facts, and narrative arcs from the provided sources.',
            '2) Ensure the result is **original** (no verbatim copying, no near-duplicate phrasing), **fact-checked** against the sources, and **SEO-optimized** for the target topic.',
            '3) Maintain a **cohesive voice** and logical flow; resolve contradictions by choosing the most recent, well-supported facts.',
            '',
            '**Non-negotiable rules**',
            '- Do **not** quote or copy more than short phrases; always **paraphrase**. Aim for <8 consecutive words from any source.',
            '- Do **not** invent facts. If a claim isn’t supported by the sources, either omit it or mark it as uncertain.',
            '- Eliminate redundancy and filler; remove source-specific fluff, CTAs, or tracking text.',
            '- Write in clear, human style for a general audience (no “as an AI” language).',
            '',
            '**SEO & structure**',
            '- Produce:',
            '  - A compelling **H1** (1 only).',
            '  - A short **intro paragraph** with the main promise.',
            '  - **H2/H3** sections that reflect intent clusters and cover entities/topics found across sources.',
            '  - A **summary/conclusion** with key takeaways.',
            '  - 3–5 **FAQs** that naturally extend the topic (unique, not duplicates of headings).',
            '- Use terms readers actually search for; include synonyms and related entities **only** if supported by sources.',
            '',
            '**Originality & cohesion techniques**',
            '- Merge overlapping points; reconcile differences; attribute only when essential (e.g., “A 2023 report indicates …”).',
            '- Where sources disagree, present the strongest consensus and briefly note the variance.',
            '- Prefer active voice, specific nouns/verbs, and short sentences; vary sentence openings.',
            '',
            '**Output format**',
            '- Return clean Markdown in this order:',
            '  1) H1',
            '  2) Intro paragraph',
            '  3) Body with H2/H3 sections',
            '  4) Conclusion',
            '  5) “FAQs:” list (Q + short A)',
            '',
            '**Safety & compliance**',
            '- No medical, legal, or financial advice beyond summarizing what sources state.',
            '- No private or sensitive personal data.',
            '- Do not include the original source texts in the output.',
            '',
            '**Assume input will include:** a topic/brief + multiple source excerpts/notes. Use only what is provided.',
        ];

        $prompt = implode("\n", $lines);

        /**
         * Filter the default autonomous system prompt.
         *
         * Allows themes, plugins, or site-specific integrations to override or localize the
         * baseline prompt text without modifying core plugin files.
         *
         * @param string $prompt Default autonomous system prompt text.
         */
        $prompt = apply_filters('exmoau/settings/default_autonomous_system_prompt', $prompt);
        return apply_filters('exoa/settings/default_autonomous_system_prompt', $prompt);
    }

    /**
     * Retrieve the effective AI configuration based on the active behaviour.
     *
     * Coalesces the stored options into a normalized structure, forcing default
     * values for unsupported behaviours, missing models, or empty prompts.
     *
     * @return array{behaviour: string, model: string, system_prompt: string} Tuple consumed by job runners and UI forms.
     */
    public static function getEffectiveAiConfiguration() {
        $behaviour = self::getOption('ai_behaviour_mode', self::DEFAULT_AI_BEHAVIOUR_MODE);

        if (!is_string($behaviour) || !in_array($behaviour, self::AI_BEHAVIOUR_MODES, true)) {
            $behaviour = self::DEFAULT_AI_BEHAVIOUR_MODE;
        }

        $model = self::DEFAULT_AI_MODEL;
        $systemPrompt = self::getAutonomousSystemPrompt();

        if ($behaviour === 'augmented') {
            $model = self::getOption('augmented_ai_model', self::DEFAULT_AI_MODEL);
            $model = self::normalizeModelId($model);
            if ($model === '') {
                $model = self::DEFAULT_AI_MODEL;
            }

            $optimized = self::getAugmentedOptimizedPrompt();
            if ($optimized !== '') {
                $systemPrompt = self::normalizeStoredSystemPrompt($optimized);
            } else {
                $rawPrompt = self::getOption('augmented_user_system_prompt', '');
                $systemPrompt = self::normalizeStoredSystemPrompt($rawPrompt);
            }
        } elseif ($behaviour === 'manual') {
            $model = self::getOption('manual_ai_model', self::DEFAULT_AI_MODEL);
            $model = self::normalizeModelId($model);
            if ($model === '') {
                $model = self::DEFAULT_AI_MODEL;
            }

            $rawPrompt = self::getOption('manual_user_system_prompt', '');
            $systemPrompt = self::normalizeStoredSystemPrompt($rawPrompt);
        }

        if ($systemPrompt === '') {
            $systemPrompt = self::getAutonomousSystemPrompt();
        }

        return [
            'behaviour' => $behaviour,
            'model' => $model,
            'system_prompt' => $systemPrompt,
        ];
    }

    /**
     * Retrieve the available AI models, guaranteeing a deterministic fallback list.
     *
     * Results are cached per unique combination of model identifiers that must
     * be present so repeated option renders within a request do not trigger
     * duplicate lookups. When remote discovery fails, the collection is
     * supplemented with local defaults and any ensured identifiers.
     *
     * @param array<int, string> $ensureModelIds Optional model identifiers that must be included in the result.
     * @return array<int, array{id: string, name: string}> Ordered list of models suitable for select controls.
     */
    public static function getAvailableAiModels(array $ensureModelIds = []) {
        $normalizedIds = self::normalizeModelIdListForCache($ensureModelIds);
        $cacheKey = md5(implode('|', $normalizedIds));

        if (isset(self::$availableAiModelsCache[$cacheKey])) {
            return self::$availableAiModelsCache[$cacheKey];
        }

        $models = self::fetchAvailableAiModels($normalizedIds);

        self::$availableAiModelsCache[$cacheKey] = $models;

        return $models;
    }

    /**
     * Retrieve the optimized augmented prompt that matches the current inputs.
     *
     * Returns the cached optimized prompt when the stored snapshot aligns with
     * the user-specified prompt and model; otherwise an empty string is
     * returned so callers can fall back to user defaults.
     *
     * @return string Optimized prompt text or an empty string when no valid cache exists.
     */
    public static function getAugmentedOptimizedPrompt() {
        $record = self::getStoredAugmentedPromptRecord();
        $prompt = self::getOption('augmented_user_system_prompt', '');
        $model = self::getOption('augmented_ai_model', self::DEFAULT_AI_MODEL);

        if (!self::doesAugmentedPromptRecordMatch($record, $prompt, $model)) {
            return '';
        }

        $optimized = $record['optimized_prompt'] ?? '';

        return (is_string($optimized) ? $optimized : '');
    }

    /**
     * Normalize a stored system prompt retrieved from options.
     *
     * @param mixed $value Raw option value.
     * @return string
     */
    private static function normalizeStoredSystemPrompt($value) {
        if (!is_string($value)) {
            return '';
        }

        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        if (!is_string($sanitized)) {
            $sanitized = $value;
        }

        $sanitized = str_replace(["\r\n", "\r"], "\n", $sanitized);

        return trim($sanitized);
    }

    /**
     * Sanitize the OpenAI API key before persisting it to the database.
     *
     * Denies updates from users without the manage_options capability,
     * returning the previously stored key. Otherwise trims whitespace,
     * normalizes scalar input, and strips only ASCII control characters.
     *
     * @param mixed $value Raw option value from the request.
     * @return string Sanitized API key suitable for storage.
     */
    public static function sanitizeOpenAiApiKey($value) {
        $previousValue = self::getOption('openai_api_key', '');

        if (!current_user_can('manage_options')) {
            add_settings_error(
                self::SETTINGS_GROUP,
                'exmoau_openai_api_key_capability',
                esc_html__('You are not allowed to update the Open AI API Key.', 'exmoment-author'),
                'error'
            );

            return $previousValue;
        }

        if (!is_scalar($value)) {
            add_settings_error(
                self::SETTINGS_GROUP,
                'exmoau_openai_api_key_invalid',
                esc_html__('Invalid API key value.', 'exmoment-author'),
                'error'
            );

            return $previousValue;
        }

        $value = trim((string) $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);

        if (!is_string($value)) {
            add_settings_error(
                self::SETTINGS_GROUP,
                'exmoau_openai_api_key_invalid',
                esc_html__('Invalid API key value.', 'exmoment-author'),
                'error'
            );

            return $previousValue;
        }

        return $value;
    }

    /**
     * Sanitize the OpenAI weight key before persisting it.
     *
     * Validates the submitted value against the GPT weights map, falls back to
     * the default when invalid or empty, and restores the previous value when
     * the current user lacks permission to update the setting.
     *
     * @param mixed $value Raw option value from the request.
     * @return string Sanitized weight key suitable for storage.
     */
    public static function sanitizeOpenAiWeightKey($value) {
        $previousValue = self::getOpenAiWeightKey();

        if (!current_user_can('manage_options')) {
            add_settings_error(
                self::SETTINGS_GROUP,
                'exmoau_openai_weight_key_capability',
                esc_html__('You are not allowed to update the OpenAI weight preset.', 'exmoment-author'),
                'error'
            );

            return $previousValue;
        }

        if (!is_string($value)) {
            if (is_scalar($value)) {
                $value = (string) $value;
            } else {
                $value = '';
            }
        }

        $value = sanitize_text_field($value);
        $value = trim($value);

        $weightsMap = GptController::getWeightsMap();
        $defaultWeightKey = self::DEFAULT_OPENAI_WEIGHT_KEY;
        $hasPreviousValue = (is_string($previousValue) && $previousValue !== '');
        $fallbackWeightKey = $hasPreviousValue ? $previousValue : $defaultWeightKey;
        $wasEmpty = ($value === '');
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        $tooLong = ($length > 64);

        if ($wasEmpty || $tooLong || !array_key_exists($value, $weightsMap)) {
            add_settings_error(
                self::SETTINGS_GROUP,
                'exmoau_openai_weight_key_invalid',
                esc_html__('Choose a valid OpenAI weight preset.', 'exmoment-author'),
                'error'
            );

            $value = $fallbackWeightKey;
        }

        self::$cachedOpenAiWeightKey = $value;

        return $value;
    }

    /**
     * Sanitize the AI behaviour mode selection.
     *
     * Validates against the allowed behaviour list, enforces the default value
     * for empty submissions, restores the previous value on capability
     * failures, and records state transitions so downstream lifecycle handlers
     * can react (e.g., clearing cached prompts).
     *
     * @param mixed $value Raw option value from the request.
     * @return string Normalized behaviour slug that was accepted for storage.
     */
    public static function sanitizeAiBehaviourMode($value) {
        $previousValue = self::getOption('ai_behaviour_mode', self::DEFAULT_AI_BEHAVIOUR_MODE);

        if (!current_user_can('manage_options')) {
            add_settings_error(
                self::SETTINGS_GROUP,
                'exmoau_ai_behaviour_mode_capability',
                esc_html__('You are not allowed to update the AI behaviour configuration.', 'exmoment-author'),
                'error'
            );

            self::resetSubmissionState();
            self::updateBehaviourState($previousValue, $previousValue);

            return $previousValue;
        }

        self::resetSubmissionState();

        if (!is_string($value)) {
            $value = '';
        }

        $value = strtolower(sanitize_text_field($value));
        $value = trim($value);

        if ($value === '') {
            $value = self::DEFAULT_AI_BEHAVIOUR_MODE;
        }

        if (!in_array($value, self::AI_BEHAVIOUR_MODES, true)) {
            add_settings_error(
                self::SETTINGS_GROUP,
                'exmoau_ai_behaviour_mode_invalid',
                esc_html__('Choose a valid AI behaviour configuration.', 'exmoment-author'),
                'error'
            );

            self::updateBehaviourState($previousValue, $previousValue);

            return $previousValue;
        }

        self::updateBehaviourState($previousValue, $value);

        return $value;
    }

    /**
     * Sanitize the augmented mode user system prompt.
     *
     * Normalizes newlines, strips control characters, enforces the 10,000
     * character limit, restores the previous value when the user lacks
     * permission to update it, and tracks whether the prompt changed so
     * transient cache invalidation can be triggered later in the request lifecycle.
     *
     * @param mixed $value Raw option value from the request.
     * @return string Trimmed prompt that passed validation or an empty string when cleared.
     */
    public static function sanitizeAugmentedUserSystemPrompt($value) {
        $previousValue = self::getOption('augmented_user_system_prompt');
        $sanitized = self::sanitizeUserSystemPrompt($value, 'augmented_user_system_prompt');

        self::ensureSubmissionStateInitialised();

        if (!is_string(self::$submissionState['behaviour'])) {
            $currentBehaviour = self::getOption('ai_behaviour_mode', self::DEFAULT_AI_BEHAVIOUR_MODE);
            self::updateBehaviourState($currentBehaviour, $currentBehaviour);
        }

        self::updateAugmentedPromptState($previousValue, $sanitized);

        return $sanitized;
    }

    /**
     * Sanitize the manual mode user system prompt.
     *
     * Shares the same normalization rules as the augmented prompt sanitizer,
     * ensuring manual mode obeys the newline, control character, and length
     * constraints while respecting capability checks that revert to the stored value.
     *
     * @param mixed $value Raw option value from the request.
     * @return string Trimmed prompt that passed validation or an empty string when cleared.
     */
    public static function sanitizeManualUserSystemPrompt($value) {
        return self::sanitizeUserSystemPrompt($value, 'manual_user_system_prompt');
    }

    /**
     * Sanitize the augmented mode AI model selection.
     *
     * Coerces the provided identifier to lowercase, guarantees a default model
     * when no value is provided, restores the previous value when the update is
     * not permitted, and records the before/after state so prompt caches can be
     * refreshed when the model changes.
     *
     * @param mixed $value Raw option value from the request.
     * @return string Model identifier persisted for augmented mode.
     */
    public static function sanitizeAugmentedAiModel($value) {
        $previousValue = self::getOption('augmented_ai_model', self::DEFAULT_AI_MODEL);
        $sanitized = self::sanitizeAiModelSelection($value, 'augmented_ai_model');

        self::ensureSubmissionStateInitialised();

        if (!is_string(self::$submissionState['behaviour'])) {
            $currentBehaviour = self::getOption('ai_behaviour_mode', self::DEFAULT_AI_BEHAVIOUR_MODE);
            self::updateBehaviourState($currentBehaviour, $currentBehaviour);
        }

        self::updateAugmentedModelState($previousValue, $sanitized);

        self::handleAugmentedPromptLifecycle();

        return $sanitized;
    }

    /**
     * Sanitize the manual mode AI model selection.
     *
     * Applies the shared AI model sanitizer while ensuring an appropriate
     * fallback to the default model when the submission fails validation and
     * reverting to the stored value for unauthorized updates.
     *
     * @param mixed $value Raw option value from the request.
     * @return string Model identifier persisted for manual mode.
     */
    public static function sanitizeManualAiModel($value) {
        return self::sanitizeAiModelSelection($value, 'manual_ai_model');
    }

    /**
     * Sanitize the AI image model selection.
     *
     * Applies an allowlist to the submitted model, enforces capability checks,
     * and falls back to the default model when validation fails.
     *
     * @param mixed $value Raw submitted value.
     * @return string
     */
    public static function sanitizeAiImageModel($value) {
        if (!current_user_can('manage_options')) {
            add_settings_error(
                self::SETTINGS_GROUP,
                'exmoau_ai_image_model_capability',
                esc_html__('You are not allowed to update the AI image model.', 'exmoment-author'),
                'error'
            );

            return self::getOption('ai_image_model', self::DEFAULT_AI_IMAGE_MODEL);
        }

        if (is_array($value)) {
            $value = reset($value);
        }

        $value = (is_string($value) ? strtolower(trim($value)) : '');

        if (!in_array($value, self::AI_IMAGE_MODELS, true)) {
            $value = self::DEFAULT_AI_IMAGE_MODEL;
        }

        return $value;
    }

    /**
     * Sanitize the AI image style prompt submission.
     *
     * Removes control characters, normalizes whitespace, applies the maximum
     * length constraint, and respects capability checks.
     *
     * @param mixed $value Raw submitted value.
     * @return string
     */
    public static function sanitizeAiImageStylePrompt($value) {
        if (!current_user_can('manage_options')) {
            add_settings_error(
                self::SETTINGS_GROUP,
                'exmoau_ai_image_style_prompt_capability',
                esc_html__('You are not allowed to update the AI image style prompt.', 'exmoment-author'),
                'error'
            );

            return self::getOption('ai_image_style_prompt', '');
        }

        if (!is_string($value)) {
            $value = '';
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        $value = is_string($value) ? $value : '';
        $value = sanitize_textarea_field($value);
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if ($length > self::MAX_AI_IMAGE_STYLE_PROMPT_LENGTH) {
            $value = function_exists('mb_substr')
                ? mb_substr($value, 0, self::MAX_AI_IMAGE_STYLE_PROMPT_LENGTH)
                : substr($value, 0, self::MAX_AI_IMAGE_STYLE_PROMPT_LENGTH);
        }

        return $value;
    }

    /**
     * Sanitize the AI image generation toggle.
     *
     * Coerces checkbox submissions to "1" or "0", restoring the stored value
     * when the current user lacks permission to update the setting.
     *
     * @param mixed $value Raw submitted value.
     * @return string
     */
    public static function sanitizeAiImageGenerationEnabled($value) {
        if (!current_user_can('manage_options')) {
            add_settings_error(
                self::SETTINGS_GROUP,
                'exmoau_ai_image_generation_enabled_capability',
                esc_html__('You are not allowed to update AI image generation settings.', 'exmoment-author'),
                'error'
            );

            return self::getOption('ai_image_generation_enabled', self::DEFAULT_AI_IMAGE_GENERATION_ENABLED);
        }

        if (is_array($value)) {
            $value = reset($value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
        }

        $truthy = ['1', 'true', 'yes', 'on'];
        $enabled = in_array($value, $truthy, true);

        return $enabled ? '1' : '0';
    }

    /**
     * Sanitize the AI image dimensions preset submission.
     *
     * Enforces capability checks, restricts values to the allowed presets, and
     * falls back to the default dimensions when validation fails.
     *
     * @param mixed $value Raw submitted value.
     * @return string
     */
    public static function sanitizeAiImageDimensions($value) {
        if (!current_user_can('manage_options')) {
            add_settings_error(
                self::SETTINGS_GROUP,
                'exmoau_ai_image_dimensions_capability',
                esc_html__('You are not allowed to update the AI image dimensions.', 'exmoment-author'),
                'error'
            );

            return self::getOption('ai_image_dimensions', self::DEFAULT_AI_IMAGE_DIMENSIONS);
        }

        $normalized = self::normalizeAiImageDimensionsValue($value);

        if ($normalized === '') {
            $normalized = self::DEFAULT_AI_IMAGE_DIMENSIONS;
        }

        return $normalized;
    }

    /**
     * Sanitize the AI image width submission.
     *
     * @param mixed $value Raw submitted value.
     * @return string
     */
    public static function sanitizeAiImageWidth($value) {
        return self::sanitizeAiImageDimension($value, 'ai_image_width');
    }

    /**
     * Sanitize the AI image height submission.
     *
     * @param mixed $value Raw submitted value.
     * @return string
     */
    public static function sanitizeAiImageHeight($value) {
        return self::sanitizeAiImageDimension($value, 'ai_image_height');
    }

    /**
     * Normalize a submitted AI image dimension to a valid integer.
     *
     * @param mixed $value Raw value from the option or request.
     * @return int
     */
    private static function normalizeAiImageDimension($value) {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        $dimension = (is_numeric($value) ? (int) $value : 0);

        if ($dimension < self::MIN_AI_IMAGE_DIMENSION || $dimension > self::MAX_AI_IMAGE_DIMENSION) {
            $dimension = self::DEFAULT_AI_IMAGE_DIMENSION;
        }

        return $dimension;
    }

    /**
     * Normalize the AI image dimensions preset against the allowed list.
     *
     * @param mixed $value Raw value from the option or request.
     * @return string
     */
    private static function normalizeAiImageDimensionsValue($value) {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (!is_string($value)) {
            return '';
        }

        $value = sanitize_text_field($value);
        $value = strtolower(trim($value));

        if (!in_array($value, self::ALLOWED_AI_IMAGE_DIMENSIONS, true)) {
            return '';
        }

        return $value;
    }

    /**
     * Map legacy width and height values to an allowed preset when possible.
     *
     * @param mixed $width  Raw width value.
     * @param mixed $height Raw height value.
     * @return string
     */
    private static function mapLegacyDimensionsToPreset($width, $height) {
        $normalizedWidth = self::normalizeAiImageDimension($width);
        $normalizedHeight = self::normalizeAiImageDimension($height);

        $candidate = sprintf('%dx%d', $normalizedWidth, $normalizedHeight);

        if (!in_array($candidate, self::ALLOWED_AI_IMAGE_DIMENSIONS, true)) {
            return '';
        }

        return $candidate;
    }

    /**
     * Sanitize a numeric AI image dimension and enforce capability checks.
     *
     * @param mixed  $value     Raw submitted value.
     * @param string $optionKey Option key without prefix.
     * @return string
     */
    private static function sanitizeAiImageDimension($value, $optionKey) {
        if (!current_user_can('manage_options')) {
            add_settings_error(
                self::SETTINGS_GROUP,
                sprintf('exmoau_%s_capability', $optionKey),
                esc_html__('You are not allowed to update the AI image size.', 'exmoment-author'),
                'error'
            );

            return self::getOption($optionKey, (string) self::DEFAULT_AI_IMAGE_DIMENSION);
        }

        $dimension = self::normalizeAiImageDimension($value);

        return (string) $dimension;
    }

    /**
     * Sanitize the mixture uniqueness toggle value.
     *
     * Accepts booleans, numeric strings, and common truthy strings (on, yes,
     * true) while forcing all other input to the disabled state. Multi-value
     * submissions are collapsed to the first value before evaluation.
     *
     * @param mixed $value Raw submitted value.
     * @return string "1" when enabled, otherwise "0".
     */
    public static function sanitizeMixtureUniquenessFlag($value) {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (is_string($value)) {
            $value = strtolower(trim($value));
        }

        if (is_bool($value)) {
            $enabled = $value;
        } else {
            $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if (null === $filtered) {
                if (is_numeric($value)) {
                    $filtered = ((int) $value === 1);
                } else {
                    $filtered = in_array($value, ['on', 'yes', 'true'], true);
                }
            }

            $enabled = (bool) $filtered;
        }

        return ($enabled ? '1' : '0');
    }

    /**
     * Sanitize the GPT debug mode checkbox value.
     *
     * Delegates to WordPress boolean sanitization helpers when available and
     * logs the resulting state in debug environments. Returns a canonical
     * string flag for storage.
     *
     * @param mixed $value Raw submission value.
     * @return string "1" when enabled, otherwise "0".
     */
    public static function sanitizeGptDebugMode($value) {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (function_exists('rest_sanitize_boolean')) {
            $sanitized = rest_sanitize_boolean($value);
        } else {
            $sanitized = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        $result = ($sanitized ? '1' : '0');

        $state = ($result === '1') ? 'enabled' : 'disabled';
        self::logDebugMessage(sprintf('GPT debug mode updated: %s.', $state));

        return $result;
    }

    /**
     * Bootstrap the GPT controller used to query OpenAI models when possible.
     *
     * @param string|null $apiKey API key to authenticate with OpenAI.
     * @return GptController|null
     */
    private static function bootstrapGptController($apiKey = null) {
        if (!is_string($apiKey) || $apiKey === '') {
            $apiKey = self::getOpenAiApiKey();
        }

        if ($apiKey === '') {
            return null;
        }

        $controller = self::getGptControllerInstance('bootstrap');

        if (!($controller instanceof GptController)) {
            return null;
        }

        if (method_exists($controller, 'setApiKey')) {
            $configured = $controller->setApiKey($apiKey);

            if (!$configured) {
                self::logGptModuleIssue('bootstrap', 'Unable to configure GPT controller with the provided API key.');

                return null;
            }
        }

        return $controller;
    }

    /**
     * Retrieve the stored OpenAI API key required for augmentation requests.
     *
     * @return string Sanitized API key or an empty string when not configured.
     */
    private static function getOpenAiApiKey() {
        $apiKey = self::getOption('openai_api_key');

        if (!is_string($apiKey)) {
            return '';
        }

        $apiKey = trim($apiKey);
        $apiKey = preg_replace('/[\x00-\x1F\x7F]/', '', $apiKey);

        if (!is_string($apiKey)) {
            return '';
        }

        return $apiKey;
    }

    /**
     * Fetch the available AI models, falling back to a local list when necessary.
     *
     * @param array<int, string> $ensureModelIds Normalised identifiers that must be present.
     * @return array<int, array{id: string, name: string}> Normalized model entries ordered for display.
     */
    private static function fetchAvailableAiModels(array $ensureModelIds) {
        $controller = self::bootstrapGptController();

        if ($controller instanceof GptController) {
            try {
                $models = $controller->getAllGptModels($ensureModelIds);

                if (is_array($models) && $models !== []) {
                    return self::normalizeModelList($models, $ensureModelIds);
                }
            } catch (\Throwable $exception) {
                // Fall through to the local fallback list when the API request fails.
            }
        }

        return self::buildLocalFallbackModels($ensureModelIds);
    }

    /**
     * Construct a deterministic fallback model list.
     *
     * @param array<int, string> $ensureModelIds Normalised identifiers to include.
     * @return array<int, array{id: string, name: string}> Locally generated models used when discovery fails.
     */
    private static function buildLocalFallbackModels(array $ensureModelIds) {
        $normalizedIds = $ensureModelIds;

        if (!in_array(self::DEFAULT_AI_MODEL, $normalizedIds, true)) {
            $normalizedIds[] = self::DEFAULT_AI_MODEL;
        }

        $models = [];

        foreach ($normalizedIds as $modelId) {
            $normalized = self::normalizeModelId($modelId);

            if ($normalized === '') {
                continue;
            }

            $models[$normalized] = [
                'id'   => $normalized,
                'name' => self::generateReadableModelName($normalized),
            ];
        }

        if ($models === []) {
            $models[self::DEFAULT_AI_MODEL] = [
                'id'   => self::DEFAULT_AI_MODEL,
                'name' => self::generateReadableModelName(self::DEFAULT_AI_MODEL),
            ];
        }

        return self::sortModelsByName($models);
    }

    /**
     * Normalize a mixed collection of model entries.
     *
     * @param array<int, mixed> $models Raw model entries.
     * @param array<int, string> $ensureModelIds Identifiers that must be present in the result.
     * @return array<int, array{id: string, name: string}> Sanitized model entries keyed by order.
     */
    private static function normalizeModelList(array $models, array $ensureModelIds) {
        $normalized = [];

        foreach ($models as $model) {
            if (is_array($model)) {
                $modelId = isset($model['id']) ? self::normalizeModelId($model['id']) : '';
                $modelName = isset($model['name']) ? self::normalizeModelName($model['name'], $modelId) : '';
            } elseif (is_object($model)) {
                $modelId = isset($model->id) ? self::normalizeModelId($model->id) : '';
                $modelName = isset($model->name) ? self::normalizeModelName($model->name, $modelId) : '';
            } else {
                continue;
            }

            if ($modelId === '') {
                continue;
            }

            if ($modelName === '') {
                $modelName = self::generateReadableModelName($modelId);
            }

            $normalized[$modelId] = [
                'id'   => $modelId,
                'name' => $modelName,
            ];
        }

        foreach ($ensureModelIds as $modelId) {
            $normalizedId = self::normalizeModelId($modelId);

            if ($normalizedId === '' || isset($normalized[$normalizedId])) {
                continue;
            }

            $normalized[$normalizedId] = [
                'id'   => $normalizedId,
                'name' => self::generateReadableModelName($normalizedId),
            ];
        }

        if ($normalized === []) {
            return self::buildLocalFallbackModels($ensureModelIds);
        }

        return self::sortModelsByName($normalized);
    }

    /**
     * Normalize and de-duplicate model identifiers used for caching keys.
     *
     * @param array<int, mixed> $modelIds Raw identifiers.
     * @return array<int, string>
     */
    private static function normalizeModelIdListForCache(array $modelIds) {
        $normalized = [];

        foreach ($modelIds as $modelId) {
            $normalizedId = self::normalizeModelId($modelId);

            if ($normalizedId === '') {
                continue;
            }

            $normalized[$normalizedId] = $normalizedId;
        }

        $normalized[self::DEFAULT_AI_MODEL] = self::DEFAULT_AI_MODEL;

        $list = array_values($normalized);
        sort($list, SORT_STRING);

        return $list;
    }

    /**
     * Normalize a raw model identifier.
     *
     * @param mixed $modelId Raw identifier value.
     * @return string
     */
    private static function normalizeModelId($modelId) {
        if (is_string($modelId) || is_numeric($modelId)) {
            $normalized = strtolower(trim((string) $modelId));

            return $normalized;
        }

        return '';
    }

    /**
     * Normalize a human-readable model name.
     *
     * @param mixed  $name    Raw name value.
     * @param string $modelId Normalized identifier.
     * @return string
     */
    private static function normalizeModelName($name, $modelId) {
        if (!is_string($name)) {
            return self::generateReadableModelName($modelId);
        }

        $normalized = trim($name);
        $normalized = preg_replace('/[\r\n\t]+/', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        if (function_exists('wp_strip_all_tags')) {
            $normalized = wp_strip_all_tags($normalized, false);
        } else {
            $normalized = preg_replace('/<[^>]*>/', '', $normalized);
        }

        if (!is_string($normalized)) {
            $normalized = '';
        }

        $normalized = trim($normalized);

        if ($normalized === '') {
            return self::generateReadableModelName($modelId);
        }

        return $normalized;
    }

    /**
     * Sort models alphabetically by their human-readable names.
     *
     * @param array<int|string, array{id: string, name: string}> $models Model entries keyed by identifier.
     * @return array<int, array{id: string, name: string}>
     */
    private static function sortModelsByName(array $models) {
        $list = array_values($models);

        usort(
            $list,
            static function ($a, $b) {
                $nameA = (is_array($a) && isset($a['name'])) ? strtolower($a['name']) : '';
                $nameB = (is_array($b) && isset($b['name'])) ? strtolower($b['name']) : '';

                if ($nameA === $nameB) {
                    $idA = (is_array($a) && isset($a['id'])) ? $a['id'] : '';
                    $idB = (is_array($b) && isset($b['id'])) ? $b['id'] : '';

                    return strcmp($idA, $idB);
                }

                return strcmp($nameA, $nameB);
            }
        );

        return $list;
    }

    /**
     * Generate a readable model label without relying on external responses.
     *
     * @param string $modelId Normalized identifier.
     * @return string
     */
    private static function generateReadableModelName($modelId) {
        $normalizedId = self::normalizeModelId($modelId);

        if ($normalizedId === '') {
            return '';
        }

        $controller = self::getGptControllerInstance('model_name_normalization');

        if (
            $controller instanceof GptController &&
            method_exists($controller, 'formatModelName')
        ) {
            $label = GptController::formatModelName($normalizedId);

            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        $readable = preg_replace('/[_-]+/', ' ', $normalizedId);
        $readable = (is_string($readable) ? $readable : $normalizedId);
        $readable = trim($readable);

        if ($readable === '') {
            return $normalizedId;
        }

        $readable = ucwords(strtolower($readable));
        $readable = preg_replace('/\s+/', ' ', $readable);

        if (!is_string($readable) || $readable === '') {
            return $normalizedId;
        }

        return $readable;
    }


    /**
     * Build the fully qualified option name using the module prefix.
     *
     * @param string $key Option key without prefix.
     * @return string
     */
    private static function getOptionName($key) {
        $normalizedKey = (is_string($key) ? trim($key) : '');

        return self::OPTION_PREFIX . $normalizedKey;
    }

    /**
     * Retrieve the cached settings array, rebuilding it when necessary.
     *
     * @return array<string, mixed>
     */
    private static function getSettingsCache() {
        $settings = get_transient(self::SETTINGS_TRANSIENT);

        if (!is_array($settings)) {
            $settings = self::rebuildSettingsCache();
        }

        return $settings;
    }

    /**
     * Rebuild the settings cache by querying persisted option values.
     *
     * @return array<string, mixed>
     */
    private static function rebuildSettingsCache() {
        $trackedOptions = [
            'openai_api_key'              => '',
            'openai_weight_key'           => self::DEFAULT_OPENAI_WEIGHT_KEY,
            'gpt_debug_mode'              => '0',
            'ai_behaviour_mode'           => self::DEFAULT_AI_BEHAVIOUR_MODE,
            'augmented_user_system_prompt' => '',
            'augmented_ai_model'          => self::DEFAULT_AI_MODEL,
            'manual_user_system_prompt'   => '',
            'manual_ai_model'             => self::DEFAULT_AI_MODEL,
            'ai_image_model'              => self::DEFAULT_AI_IMAGE_MODEL,
            'ai_image_generation_enabled' => self::DEFAULT_AI_IMAGE_GENERATION_ENABLED,
            'ai_image_style_prompt'       => '',
            'ai_image_dimensions'         => self::DEFAULT_AI_IMAGE_DIMENSIONS,
            'ai_image_width'              => (string) self::DEFAULT_AI_IMAGE_DIMENSION,
            'ai_image_height'             => (string) self::DEFAULT_AI_IMAGE_DIMENSION,
        ];

        $settings = [];

        foreach ($trackedOptions as $key => $default) {
            $optionName = self::getOptionName($key);
            $value = get_option($optionName, null);

            if ($value === null) {
                $value = (is_string($default) ? $default : '');
            }

            $settings[$optionName] = $value;
        }

        set_transient(
            self::SETTINGS_TRANSIENT,
            $settings,
            self::SETTINGS_TRANSIENT_EXPIRATION
        );

        return $settings;
    }

    /**
     * Refresh the settings cache when tracked options are added, updated, or deleted.
     *
     * Hooked to the `add_option_{name}`, `update_option_{name}`, and
     * `delete_option_{name}` actions. The variadic signature accepts the
     * differing argument lists supplied by these WordPress hooks while
     * intentionally ignoring them because the cache rebuild queries each
     * tracked option directly.
     *
     * @param mixed ...$unused Parameters forwarded by the WordPress option actions.
     * @return void
     */
    public static function refreshSettingsCache(...$unused) {
        delete_transient(self::SETTINGS_TRANSIENT);
        self::rebuildSettingsCache();
    }

    /**
     * Register cache refresh hooks for an option name.
     *
     * Hooks into the `add_option_{$option}`/`update_option_{$option}`/
     * `delete_option_{$option}` actions to keep the transient payload in sync
     * with persisted values.
     *
     * @param string $optionName Fully qualified option name.
     * @return void
     */
    private static function registerOptionCacheHooks($optionName) {
        if (!is_string($optionName) || $optionName === '') {
            return;
        }

        add_action(
            sprintf('add_option_%s', $optionName),
            [self::class, 'refreshSettingsCache'],
            10,
            2
        );

        add_action(
            sprintf('update_option_%s', $optionName),
            [self::class, 'refreshSettingsCache'],
            10,
            3
        );

        add_action(
            sprintf('delete_option_%s', $optionName),
            [self::class, 'refreshSettingsCache'],
            10,
            1
        );
    }

    /**
     * Invalidate the cached OpenAI model list when credentials change.
     *
     * Attaches the GPT controller's cache flush callback to option mutation
     * hooks so remote model lookups refresh whenever API credentials or
     * behaviour selections change.
     *
     * @param string $optionName Fully qualified option name.
     * @return void
     */
    private static function registerModelCacheInvalidationHooks($optionName) {
        if (!is_string($optionName) || $optionName === '') {
            return;
        }

        $controller = self::getGptControllerInstance('model_cache_invalidation');

        if (!($controller instanceof GptController) || !method_exists($controller, 'flushModelCache')) {
            return;
        }

        $hooks = [
            sprintf('add_option_%s', $optionName),
            sprintf('update_option_%s', $optionName),
            sprintf('delete_option_%s', $optionName),
        ];

        foreach ($hooks as $hook) {
            add_action($hook, [GptController::class, 'flushModelCache'], 10, 0);
        }
    }

    /**
     * Retrieve the GPT module controller instance via the core system.
     *
     * Attempts to autoload the module when it has not been instantiated yet and logs diagnostic
     * messages when unavailable.
     *
     * @param string $context Operational context for debugging output.
     * @return GptController|null Controller instance ready for API calls or null when unavailable.
     */
    private static function getGptControllerInstance($context) {
        $core = ExMomentAuthorCoreSystem::getInstance();

        if (!($core instanceof ExMomentAuthorCoreSystem)) {
            self::logGptModuleIssue($context, 'Core system unavailable when requesting GPT controller.');

            return null;
        }

        $controller = $core->getModule('GptController');

        if (!($controller instanceof GptController) && method_exists($core, 'autoload')) {
            $core->autoload();
            $controller = $core->getModule('GptController');
        }

        if (!($controller instanceof GptController)) {
            self::logGptModuleIssue($context, 'GPT module is offline or failed to load.');

            return null;
        }

        return $controller;
    }

    /**
     * Emit a contextual debug log when the GPT module cannot be used.
     *
     * @param string $context Short identifier for the current operation.
     * @param string $message Description of the issue encountered.
     * @return void
     */
    private static function logGptModuleIssue($context, $message) {
        $operation = (is_string($context) ? trim($context) : '');
        $normalizedMessage = (is_string($message) ? trim($message) : '');

        if ($normalizedMessage === '') {
            $normalizedMessage = 'GPT module unavailable.';
        }

        $logContext = [];

        if ($operation !== '') {
            $logContext['operation'] = $operation;
        }

        self::logDebugMessage($normalizedMessage, $logContext);
    }

    /**
     * Retrieve the shared log service instance when available.
     *
     * @return LogService|null Singleton logger for emitting contextual diagnostics, or null when unavailable.
     */
    private static function getLogServiceInstance() {
        static $logger = null;

        if ($logger instanceof LogService) {
            return $logger;
        }

        try {
            $instance = LogService::getInstance();
        } catch (\Throwable $exception) {
            return null;
        }

        if ($instance instanceof LogService) {
            $logger = $instance;

            return $logger;
        }

        return null;
    }

    /**
     * Emit a debug log entry scoped to the settings controller.
     *
     * @param string               $message  Message to record.
     * @param array<string, mixed> $context  Contextual payload for the entry.
     * @return void
     */
    private static function logDebugMessage($message, array $context = []) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $normalizedMessage = (is_string($message) ? trim($message) : '');

        if ($normalizedMessage === '') {
            return;
        }

        $logger = self::getLogServiceInstance();

        if ($logger instanceof LogService) {
            $logger->debug('settings.controller', $normalizedMessage, $context);

            return;
        }

        if (function_exists('do_action')) {
            /**
             * Fires when the settings controller emits a debug log message without a log service instance.
             *
             * @param string               $message Log message.
             * @param array<string, mixed> $context Contextual payload for the log message.
             */
            do_action('exoa/settings/log_debug', $normalizedMessage, $context);
        }
    }

    /**
     * Sanitize a user-provided system prompt value shared by multiple modes.
     *
     * @param mixed  $value     Raw option value from the request.
     * @param string $optionKey Option key without prefix.
     * @return string
     */
    private static function sanitizeUserSystemPrompt($value, $optionKey) {
        if (!current_user_can('manage_options')) {
            add_settings_error(
                self::SETTINGS_GROUP,
                sprintf('exmoau_%s_capability', $optionKey),
                esc_html__('You are not allowed to update the AI behaviour configuration.', 'exmoment-author'),
                'error'
            );

            return self::getOption($optionKey);
        }

        if (!is_string($value)) {
            $value = '';
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        if (!is_string($value)) {
            $value = '';
        }

        $value = sanitize_textarea_field($value);
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        if ($length > self::MAX_USER_SYSTEM_PROMPT_LENGTH) {
            $value = function_exists('mb_substr')
                ? mb_substr($value, 0, self::MAX_USER_SYSTEM_PROMPT_LENGTH)
                : substr($value, 0, self::MAX_USER_SYSTEM_PROMPT_LENGTH);

            add_settings_error(
                self::SETTINGS_GROUP,
                sprintf('exmoau_%s_length', $optionKey),
                esc_html__('System prompts must be 10,000 characters or fewer.', 'exmoment-author'),
                'error'
            );
        }

        return $value;
    }

    /**
     * Sanitize an AI model selection shared by multiple modes.
     *
     * @param mixed  $value     Raw option value from the request.
     * @param string $optionKey Option key without prefix.
     * @return string
     */
    private static function sanitizeAiModelSelection($value, $optionKey) {
        if (!current_user_can('manage_options')) {
            add_settings_error(
                self::SETTINGS_GROUP,
                sprintf('exmoau_%s_capability', $optionKey),
                esc_html__('You are not allowed to update the AI behaviour configuration.', 'exmoment-author'),
                'error'
            );

            return self::getOption($optionKey, self::DEFAULT_AI_MODEL);
        }

        $previousValue = self::getOption($optionKey, self::DEFAULT_AI_MODEL);

        if (!is_string($value)) {
            $value = '';
        }

        $value = sanitize_text_field($value);
        $value = strtolower(trim($value));

        if ($value === '') {
            $value = self::DEFAULT_AI_MODEL;
        }

        $availableModels = self::getAvailableAiModels([$previousValue, $value]);
        $availableIds = array_map(
            static function ($model) {
                return (is_array($model) && isset($model['id'])) ? $model['id'] : '';
            },
            $availableModels
        );

        if (!in_array($value, $availableIds, true)) {
            add_settings_error(
                self::SETTINGS_GROUP,
                sprintf('exmoau_%s_invalid', $optionKey),
                esc_html__('Choose a supported AI model from the available list.', 'exmoment-author'),
                'error'
            );

            $fallback = (is_string($previousValue) && $previousValue !== '')
                ? $previousValue
                : self::DEFAULT_AI_MODEL;

            return $fallback;
        }

        return $value;
    }

    /**
     * Ensure the submission state array is initialised for the current request.
     *
     * @return void
     */
    private static function ensureSubmissionStateInitialised() {
        if (true === (self::$submissionState['initialised'] ?? false)) {
            return;
        }

        self::resetSubmissionState();
    }

    /**
     * Reset submission tracking metadata at the beginning of a save cycle.
     *
     * @return void
     */
    private static function resetSubmissionState() {
        self::$submissionState = [
            'initialised' => true,
            'behaviour' => null,
            'behaviour_previous' => null,
            'behaviour_changed' => false,
            'augmented_prompt' => null,
            'augmented_prompt_previous' => null,
            'prompt_changed' => false,
            'augmented_model' => null,
            'augmented_model_previous' => null,
            'model_changed' => false,
            'augmentation_attempted' => false,
            'augmentation_error_added' => false,
            'augmentation_missing_key_added' => false,
            'augmentation_diagnostics' => null,
        ];
    }

    /**
     * Store the current and previous behaviour selection for later processing.
     *
     * @param string|null $previousValue Previously persisted behaviour.
     * @param string|null $currentValue  Behaviour value being saved.
     * @return void
     */
    private static function updateBehaviourState($previousValue, $currentValue) {
        self::ensureSubmissionStateInitialised();

        $previous = is_string($previousValue) ? $previousValue : null;
        $current = is_string($currentValue) ? $currentValue : null;

        self::$submissionState['behaviour_previous'] = $previous;
        self::$submissionState['behaviour'] = $current;
        self::$submissionState['behaviour_changed'] = (
            is_string($previous) &&
            is_string($current) &&
            $current !== $previous
        );
    }

    /**
     * Store the augmented prompt values for the current submission cycle.
     *
     * @param string|null $previousValue Previously persisted prompt.
     * @param string|null $currentValue  Prompt being saved.
     * @return void
     */
    private static function updateAugmentedPromptState($previousValue, $currentValue) {
        self::ensureSubmissionStateInitialised();

        $previous = is_string($previousValue) ? $previousValue : '';
        $current = is_string($currentValue) ? $currentValue : '';

        self::$submissionState['augmented_prompt_previous'] = $previous;
        self::$submissionState['augmented_prompt'] = $current;
        self::$submissionState['prompt_changed'] = ($current !== $previous);
    }

    /**
     * Store the augmented model selection for the current submission cycle.
     *
     * @param string|null $previousValue Previously persisted model identifier.
     * @param string|null $currentValue  Model identifier being saved.
     * @return void
     */
    private static function updateAugmentedModelState($previousValue, $currentValue) {
        self::ensureSubmissionStateInitialised();

        $previous = self::normalizeModelId($previousValue);
        $current = self::normalizeModelId($currentValue);

        if ($previous === '') {
            $previous = self::DEFAULT_AI_MODEL;
        }

        if ($current === '') {
            $current = self::DEFAULT_AI_MODEL;
        }

        self::$submissionState['augmented_model_previous'] = $previous;
        self::$submissionState['augmented_model'] = $current;
        self::$submissionState['model_changed'] = ($current !== $previous);
    }

    /**
     * Run the augmented prompt workflow when necessary during a settings save.
     *
     * @return void
     */
    private static function handleAugmentedPromptLifecycle() {
        self::ensureSubmissionStateInitialised();

        if (true === (self::$submissionState['augmentation_attempted'] ?? false)) {
            return;
        }

        self::$submissionState['augmentation_attempted'] = true;

        $behaviour = self::$submissionState['behaviour'];

        if (!is_string($behaviour) || $behaviour === '') {
            $behaviour = self::getOption('ai_behaviour_mode', self::DEFAULT_AI_BEHAVIOUR_MODE);
            self::$submissionState['behaviour'] = $behaviour;

            if (!is_string(self::$submissionState['behaviour_previous'])) {
                self::$submissionState['behaviour_previous'] = $behaviour;
            }
        }

        if ('augmented' !== $behaviour) {
            $promptChanged = (bool) (self::$submissionState['prompt_changed'] ?? false);
            $modelChanged = (bool) (self::$submissionState['model_changed'] ?? false);
            $behaviourChanged = (bool) (self::$submissionState['behaviour_changed'] ?? false);

            if ($promptChanged || $modelChanged || $behaviourChanged) {
                self::deleteAugmentedPromptTransient();
            }

            return;
        }

        $prompt = self::$submissionState['augmented_prompt'];

        if (!is_string($prompt)) {
            $prompt = self::getOption('augmented_user_system_prompt', '');
        }

        $model = self::$submissionState['augmented_model'];

        if (!is_string($model) || $model === '') {
            $model = self::getOption('augmented_ai_model', self::DEFAULT_AI_MODEL);
        }

        $promptChanged = (bool) (self::$submissionState['prompt_changed'] ?? false);
        $modelChanged = (bool) (self::$submissionState['model_changed'] ?? false);

        $existingRecord = self::getStoredAugmentedPromptRecord();

        if (!$promptChanged && !$modelChanged && self::doesAugmentedPromptRecordMatch($existingRecord, $prompt, $model)) {
            self::setAugmentedPromptCache($existingRecord);

            return;
        }

        self::deleteAugmentedPromptTransient();

        $apiKey = self::getOpenAiApiKey();

        if ($apiKey === '') {
            self::setAugmentationDiagnostics([], $model, 'missing_api_key', [
                'error_message' => 'OpenAI API key not configured.',
            ]);
            self::handleAugmentationFailureTelemetry($prompt, $model);
            self::addAugmentationMissingKeyNotice();
            self::persistAugmentedPrompt($prompt, $model, $prompt, 'fallback');

            return;
        }

        $responseJson = self::performPromptAugmentation($prompt, $model, $apiKey);
        $response = self::decodeAugmentationResponse($responseJson);

        if (!is_array($response)) {
            $noticeContext = self::buildAugmentationNoticeContext(
                self::getAugmentationDiagnostics(),
                'invalid_response'
            );

            self::addAugmentationErrorNotice($noticeContext['status'], $noticeContext['type']);
            self::persistAugmentedPrompt($prompt, $model, $prompt, 'fallback');
            self::handleAugmentationFailureTelemetry($prompt, $model);

            return;
        }

        $optimizedPrompt = (string) ($response['prompt'] ?? '');

        if (true !== ($response['success'] ?? false)) {
            $noticeContext = self::buildAugmentationNoticeContext(
                self::getAugmentationDiagnostics(),
                'api_error'
            );

            self::addAugmentationErrorNotice($noticeContext['status'], $noticeContext['type']);
            self::persistAugmentedPrompt($prompt, $model, $prompt, 'fallback');
            self::handleAugmentationFailureTelemetry($prompt, $model);

            return;
        }

        self::persistAugmentedPrompt($prompt, $model, $optimizedPrompt, 'success');
        self::clearAugmentationFailureDiagnosticsTransient();
        self::clearAugmentationDiagnostics();
    }

    /**
     * Request an improved prompt from the GPT controller.
     *
     * @param string $prompt Original user-provided prompt.
     * @param string $model  Validated model identifier.
     * @param string $apiKey OpenAI API key retrieved from configuration.
     * @return string JSON-encoded response payload.
     */
    private static function performPromptAugmentation($prompt, $model, $apiKey) {
        $prompt = (string) $prompt;
        $model = self::normalizeModelId($model);
        $apiKey = (is_string($apiKey) ? trim($apiKey) : '');

        if ($prompt === '') {
            self::clearAugmentationDiagnostics();

            return self::formatAugmentationResponse(true, '');
        }

        if ($apiKey === '') {
            self::setAugmentationDiagnostics([], $model, 'missing_api_key', [
                'error_message' => 'OpenAI API key not configured.',
            ]);

            return self::formatAugmentationResponse(false, $prompt);
        }

        $controller = self::bootstrapGptController($apiKey);

        if (!($controller instanceof GptController)) {
            self::setAugmentationDiagnostics([], $model, 'controller_bootstrap_failure', [
                'error_message' => 'Unable to bootstrap GPT controller.',
            ]);

            return self::formatAugmentationResponse(false, $prompt);
        }

        $messages = [
            [
                'role' => 'system',
                'content' => self::AUGMENTATION_SYSTEM_MESSAGE,
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ];

        $weightKey = self::getOpenAiWeightKey();

        try {
            $response = $controller->chatCompletionCreate(
                $messages,
                $weightKey,
                [],
                $model
            );
        } catch (\Throwable $exception) {
            $diagnostics = $controller->getLastChatCompletionDiagnostics();

            if (!is_array($diagnostics)) {
                $diagnostics = [
                    'error_type'    => 'controller_exception',
                    'error_message' => $exception->getMessage(),
                ];
            }

            self::setAugmentationDiagnostics($diagnostics, $model, 'controller_exception');

            return self::formatAugmentationResponse(false, $prompt);
        }

        $diagnostics = $controller->getLastChatCompletionDiagnostics();

        if (is_string($response)) {
            self::setAugmentationDiagnostics($diagnostics, $model, 'api_error', [
                'error_message' => $response,
            ]);

            return self::formatAugmentationResponse(false, $prompt);
        }

        $usage = self::extractUsageFromResponse($response);

        if (!is_object($response) || !isset($response->choices) || !is_array($response->choices)) {
            self::setAugmentationDiagnostics($diagnostics, $model, 'invalid_response_structure', [
                'usage' => $usage,
            ]);

            return self::formatAugmentationResponse(false, $prompt);
        }

        if ($response->choices === []) {
            self::setAugmentationDiagnostics($diagnostics, $model, 'empty_response', [
                'usage' => $usage,
            ]);

            return self::formatAugmentationResponse(false, $prompt);
        }

        $choice = $response->choices[0] ?? null;

        if (!is_object($choice)) {
            self::setAugmentationDiagnostics($diagnostics, $model, 'invalid_choice_object', [
                'usage' => $usage,
            ]);

            return self::formatAugmentationResponse(false, $prompt);
        }

        $message = $choice->message ?? null;

        if (!is_object($message) || !isset($message->content)) {
            self::setAugmentationDiagnostics($diagnostics, $model, 'invalid_message_payload', [
                'usage' => $usage,
            ]);

            return self::formatAugmentationResponse(false, $prompt);
        }

        $optimizedPrompt = is_string($message->content) ? trim($message->content) : '';

        if ($optimizedPrompt === '') {
            self::setAugmentationDiagnostics($diagnostics, $model, 'empty_optimized_prompt', [
                'usage' => $usage,
            ]);

            return self::formatAugmentationResponse(false, $prompt);
        }

        self::clearAugmentationDiagnostics();

        return self::formatAugmentationResponse(true, $optimizedPrompt);
    }

    /**
     * Persist diagnostics for the most recent augmentation attempt.
     *
     * @param array<string, mixed>|null $diagnostics Raw diagnostics provided by the GPT controller.
     * @param string                    $model        Normalised model identifier used for augmentation.
     * @param string                    $defaultType  Default error type to apply when none supplied.
     * @param array<string, mixed>      $overrides    Additional values that should override diagnostics.
     * @return void Stores a normalized payload on the submission state for later notice rendering or logging.
     */
    private static function setAugmentationDiagnostics($diagnostics, $model, $defaultType = 'unknown_error', array $overrides = []) {
        self::ensureSubmissionStateInitialised();

        if (!is_array($diagnostics) && $overrides === []) {
            self::$submissionState['augmentation_diagnostics'] = null;

            return;
        }

        $normalized = self::normalizeAugmentationDiagnostics(
            (is_array($diagnostics) ? $diagnostics : []),
            $model,
            $defaultType,
            $overrides
        );

        self::$submissionState['augmentation_diagnostics'] = $normalized;
    }

    /**
     * Remove any stored diagnostics after a successful augmentation.
     *
     * @return void
     */
    private static function clearAugmentationDiagnostics() {
        self::ensureSubmissionStateInitialised();
        self::$submissionState['augmentation_diagnostics'] = null;
    }

    /**
     * Retrieve diagnostics captured during the current augmentation attempt.
     *
     * @return array<string, mixed>|null Normalized diagnostics payload including type, model, and usage details.
     */
    private static function getAugmentationDiagnostics() {
        self::ensureSubmissionStateInitialised();

        $diagnostics = self::$submissionState['augmentation_diagnostics'] ?? null;

        return (is_array($diagnostics) ? $diagnostics : null);
    }

    /**
     * Normalise diagnostic payloads for downstream logging and notices.
     *
     * @param array<string, mixed> $diagnostics Raw diagnostics from the GPT controller.
     * @param string               $model       Model identifier attempted during augmentation.
     * @param string               $defaultType Default error type when none provided.
     * @param array<string, mixed> $overrides   Override values merged into the diagnostics payload.
     * @return array<string, mixed>
     */
    private static function normalizeAugmentationDiagnostics(array $diagnostics, $model, $defaultType, array $overrides) {
        $normalizedModel = self::normalizeModelId($model);
        $payload = [
            'http_status'      => null,
            'error_type'       => $defaultType,
            'error_code'       => null,
            'error_message'    => '',
            'request_id'       => '',
            'model_attempted'  => ($normalizedModel !== '' ? $normalizedModel : ''),
            'timing_ms'        => null,
            'usage'            => [
                'prompt_tokens'     => null,
                'completion_tokens' => null,
                'total_tokens'      => null,
            ],
        ];

        if (array_key_exists('http_status', $diagnostics) && $diagnostics['http_status'] !== null && $diagnostics['http_status'] !== '') {
            $payload['http_status'] = (int) $diagnostics['http_status'];
        }

        if (!empty($diagnostics['error_type'])) {
            $payload['error_type'] = self::sanitizeNoticeType($diagnostics['error_type']);
        }

        if (array_key_exists('error_code', $diagnostics) && $diagnostics['error_code'] !== null && $diagnostics['error_code'] !== '') {
            $payload['error_code'] = self::truncateDiagnosticsString($diagnostics['error_code'], 128);
        }

        if (!empty($diagnostics['error_message'])) {
            $payload['error_message'] = self::truncateDiagnosticsString($diagnostics['error_message']);
        }

        if (!empty($diagnostics['request_id'])) {
            $payload['request_id'] = self::sanitizeRequestId($diagnostics['request_id']);
        }

        if (array_key_exists('model_attempted', $diagnostics) && is_string($diagnostics['model_attempted']) && $diagnostics['model_attempted'] !== '') {
            $payload['model_attempted'] = self::normalizeModelId($diagnostics['model_attempted']);
        }

        if (array_key_exists('timing_ms', $diagnostics) && is_numeric($diagnostics['timing_ms'])) {
            $payload['timing_ms'] = max((int) $diagnostics['timing_ms'], 0);
        }

        if (isset($diagnostics['usage']) && is_array($diagnostics['usage'])) {
            $payload['usage'] = self::sanitizeDiagnosticsUsage($diagnostics['usage']);
        }

        foreach ($overrides as $key => $value) {
            switch ($key) {
                case 'http_status':
                    if ($value !== null && $value !== '') {
                        $payload['http_status'] = (int) $value;
                    }
                    break;
                case 'error_type':
                    if (!empty($value)) {
                        $payload['error_type'] = self::sanitizeNoticeType($value);
                    }
                    break;
                case 'error_code':
                    if ($value !== null && $value !== '') {
                        $payload['error_code'] = self::truncateDiagnosticsString($value, 128);
                    }
                    break;
                case 'error_message':
                    if (!empty($value)) {
                        $payload['error_message'] = self::truncateDiagnosticsString($value);
                    }
                    break;
                case 'request_id':
                    if (!empty($value)) {
                        $payload['request_id'] = self::sanitizeRequestId($value);
                    }
                    break;
                case 'model_attempted':
                    if (!empty($value)) {
                        $payload['model_attempted'] = self::normalizeModelId($value);
                    }
                    break;
                case 'timing_ms':
                    if (is_numeric($value)) {
                        $payload['timing_ms'] = max((int) $value, 0);
                    }
                    break;
                case 'usage':
                    if (is_array($value)) {
                        $payload['usage'] = self::sanitizeDiagnosticsUsage($value);
                    }
                    break;
            }
        }

        if ($payload['error_type'] === '') {
            $payload['error_type'] = self::sanitizeNoticeType($defaultType);
        }

        if ($payload['error_type'] === '') {
            $payload['error_type'] = 'unknown_error';
        }

        return $payload;
    }

    /**
     * Sanitize usage metrics within diagnostics payloads.
     *
     * @param array<string, mixed> $usage Raw usage metrics.
     * @return array<string, int|null>
     */
    private static function sanitizeDiagnosticsUsage(array $usage) {
        $normalized = [
            'prompt_tokens'     => null,
            'completion_tokens' => null,
            'total_tokens'      => null,
        ];

        foreach ($normalized as $key => $default) {
            if (!array_key_exists($key, $usage)) {
                continue;
            }

            if (is_numeric($usage[$key])) {
                $normalized[$key] = (int) $usage[$key];
            }
        }

        return $normalized;
    }

    /**
     * Truncate diagnostic strings while stripping control characters.
     *
     * @param mixed $value Raw diagnostic value.
     * @param int   $limit Maximum characters to retain.
     * @return string
     */
    private static function truncateDiagnosticsString($value, $limit = 300) {
        if (is_array($value)) {
            $value = implode(' ', $value);
        }

        if (!is_string($value)) {
            return '';
        }

        $normalized = trim($value);
        $normalized = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        if (!is_string($normalized)) {
            return '';
        }

        if (!function_exists('mb_substr')) {
            return substr($normalized, 0, $limit);
        }

        return mb_substr($normalized, 0, $limit);
    }

    /**
     * Sanitize OpenAI request identifiers extracted from responses.
     *
     * @param mixed $requestId Raw request identifier.
     * @return string
     */
    private static function sanitizeRequestId($requestId) {
        if (!is_string($requestId)) {
            return '';
        }

        $cleaned = trim($requestId);
        $cleaned = preg_replace('/[^a-zA-Z0-9\-_.]/', '', $cleaned);

        if (!is_string($cleaned)) {
            return '';
        }

        return self::truncateDiagnosticsString($cleaned, 64);
    }

    /**
     * Extract usage metrics from a chat completion response when available.
     *
     * @param mixed $response Response returned by the GPT controller.
     * @return array<string, int|null>
     */
    private static function extractUsageFromResponse($response) {
        if (!is_object($response) || !isset($response->usage) || !is_object($response->usage)) {
            return [
                'prompt_tokens'     => null,
                'completion_tokens' => null,
                'total_tokens'      => null,
            ];
        }

        $usage = [
            'prompt_tokens'     => null,
            'completion_tokens' => null,
            'total_tokens'      => null,
        ];

        foreach ($usage as $key => $default) {
            if (!isset($response->usage->{$key})) {
                continue;
            }

            $value = $response->usage->{$key};

            if (is_numeric($value)) {
                $usage[$key] = (int) $value;
            }
        }

        return $usage;
    }

    /**
     * Build a notice context array from the captured diagnostics payload.
     *
     * @param array<string, mixed>|null $diagnostics Captured diagnostics payload.
     * @param string                    $defaultType Default error type when diagnostics are absent.
     * @return array{status: string|null, type: string}
     */
    private static function buildAugmentationNoticeContext($diagnostics, $defaultType) {
        $status = null;
        $type = $defaultType;

        if (is_array($diagnostics)) {
            if (array_key_exists('http_status', $diagnostics) && $diagnostics['http_status'] !== null) {
                $status = (int) $diagnostics['http_status'];
            }

            if (!empty($diagnostics['error_type'])) {
                $type = self::sanitizeNoticeType($diagnostics['error_type']);
            }
        }

        if ($type === '') {
            $type = self::sanitizeNoticeType($defaultType);
        }

        if ($type === '') {
            $type = 'unknown_error';
        }

        return [
            'status' => $status,
            'type'   => $type,
        ];
    }

    /**
     * Determine whether diagnostics should be logged to the debug log.
     *
     * @return bool
     */
    private static function shouldLogAugmentationDiagnostics() {
        if (!defined('WP_DEBUG') || true !== WP_DEBUG) {
            return false;
        }

        if (defined('SCRIPT_DEBUG') && true !== SCRIPT_DEBUG) {
            return false;
        }

        return true;
    }

    /**
     * Emit a single JSON encoded diagnostics entry to the debug log.
     *
     * @param string                       $prompt       Raw user prompt used for augmentation.
     * @param string                       $model        Model identifier selected for augmentation.
     * @param array<string, mixed>|null    $diagnostics  Captured diagnostics payload.
     * @return void
     */
    private static function logAugmentationFailure($prompt, $model, $diagnostics) {
        if (!self::shouldLogAugmentationDiagnostics()) {
            return;
        }

        $payload = self::normalizeAugmentationDiagnostics(
            (is_array($diagnostics) ? $diagnostics : []),
            $model,
            (is_array($diagnostics) && !empty($diagnostics['error_type']))
                ? $diagnostics['error_type']
                : 'unknown_error',
            []
        );

        $record = [
            'event'       => 'augmentation_failed',
            'model'       => ($payload['model_attempted'] !== '' ? $payload['model_attempted'] : self::normalizeModelId($model)),
            'prompt_len'  => self::calculatePromptLength($prompt),
        ];

        if ($payload['http_status'] !== null) {
            $record['http_status'] = (int) $payload['http_status'];
        }

        if ($payload['error_type'] !== '') {
            $record['error_type'] = $payload['error_type'];
        }

        if ($payload['error_code'] !== null && $payload['error_code'] !== '') {
            $record['error_code'] = $payload['error_code'];
        }

        if ($payload['error_message'] !== '') {
            $record['error_message'] = self::truncateDiagnosticsString($payload['error_message']);
        }

        if ($payload['request_id'] !== '') {
            $record['request_id'] = $payload['request_id'];
        }

        if ($payload['timing_ms'] !== null) {
            $record['timing_ms'] = (int) $payload['timing_ms'];
        }

        if (is_array($payload['usage'])) {
            $usage = [];

            foreach ($payload['usage'] as $key => $value) {
                if (is_int($value)) {
                    $usage[$key] = $value;
                }
            }

            if ($usage !== []) {
                $record['usage'] = $usage;
            }
        }

        $json = wp_json_encode($record);

        if (is_string($json) && $json !== '') {
            self::logDebugMessage(
                'Augmentation failure diagnostics snapshot.',
                [
                    'payload'      => $record,
                    'payload_json' => $json,
                ]
            );
        }
    }

    /**
     * Cache the latest augmentation failure diagnostics in a short-lived transient.
     *
     * @param string                    $prompt      Raw user prompt submitted for augmentation.
     * @param string                    $model       Model identifier in use.
     * @param array<string, mixed>|null $diagnostics Diagnostics payload to persist.
     * @return void
     */
    private static function setAugmentationFailureDiagnosticsTransient($prompt, $model, $diagnostics) {
        if (!function_exists('set_transient')) {
            return;
        }

        if (!is_array($diagnostics)) {
            self::clearAugmentationFailureDiagnosticsTransient();

            return;
        }

        $payload = self::normalizeAugmentationDiagnostics(
            $diagnostics,
            $model,
            (!empty($diagnostics['error_type']) ? $diagnostics['error_type'] : 'unknown_error'),
            []
        );

        $transient = [
            'model'         => ($payload['model_attempted'] !== '' ? $payload['model_attempted'] : self::normalizeModelId($model)),
            'prompt_length' => self::calculatePromptLength($prompt),
            'http_status'   => $payload['http_status'],
            'error_type'    => $payload['error_type'],
            'error_code'    => $payload['error_code'],
            'request_id'    => $payload['request_id'],
            'timing_ms'     => $payload['timing_ms'],
            'recorded_at'   => time(),
        ];

        if ($payload['error_message'] !== '') {
            $transient['error_message'] = self::truncateDiagnosticsString($payload['error_message'], 200);
        }

        if (is_array($payload['usage'])) {
            $usage = [];

            foreach ($payload['usage'] as $key => $value) {
                if (is_int($value)) {
                    $usage[$key] = $value;
                }
            }

            if ($usage !== []) {
                $transient['usage'] = $usage;
            }
        }

        set_transient(
            self::AUGMENTED_PROMPT_FAILURE_TRANSIENT_KEY,
            $transient,
            self::AUGMENTED_PROMPT_FAILURE_TRANSIENT_TTL
        );
        delete_transient(self::AUGMENTED_PROMPT_FAILURE_TRANSIENT_KEY);
    }

    /**
     * Remove the cached augmentation failure diagnostics transient.
     *
     * @return void
     */
    private static function clearAugmentationFailureDiagnosticsTransient() {
        if (function_exists('delete_transient')) {
            delete_transient(self::AUGMENTED_PROMPT_FAILURE_TRANSIENT_KEY);
            delete_transient(self::AUGMENTED_PROMPT_FAILURE_TRANSIENT_KEY);
        }
    }

    /**
     * Compute the character length of the provided prompt without exposing its content.
     *
     * @param string $prompt Prompt submitted for augmentation.
     * @return int
     */
    private static function calculatePromptLength($prompt) {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($prompt);
        }

        return (int) strlen($prompt);
    }

    /**
     * Store telemetry for failed augmentation attempts (logging + transient).
     *
     * @param string $prompt Original prompt submitted by the user.
     * @param string $model  Model identifier associated with the request.
     * @return void
     */
    private static function handleAugmentationFailureTelemetry($prompt, $model) {
        $diagnostics = self::getAugmentationDiagnostics();

        self::logAugmentationFailure($prompt, $model, $diagnostics);
        self::setAugmentationFailureDiagnosticsTransient($prompt, $model, $diagnostics);
    }

    /**
     * Sanitize notice status codes for admin display.
     *
     * @param mixed $status Raw status value.
     * @return string
     */
    private static function sanitizeNoticeStatus($status) {
        if (is_numeric($status)) {
            return (string) (int) $status;
        }

        if (is_string($status) && $status !== '') {
            $cleaned = preg_replace('/[^0-9a-zA-Z\-_.]/', '', $status);

            if (!is_string($cleaned) || $cleaned === '') {
                return 'n/a';
            }

            if (!function_exists('mb_substr')) {
                return substr($cleaned, 0, 16);
            }

            return mb_substr($cleaned, 0, 16);
        }

        return 'n/a';
    }

    /**
     * Normalize diagnostic error types for display within admin notices.
     *
     * @param mixed  $value    Raw error type.
     * @param string $fallback Fallback value when sanitization results in an empty string.
     * @return string
     */
    private static function sanitizeNoticeType($value, $fallback = 'unknown_error') {
        if (!is_string($value)) {
            return $fallback;
        }

        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9_\.\-]+/', '_', $normalized);

        if (!is_string($normalized) || $normalized === '') {
            return $fallback;
        }

        if (!function_exists('mb_substr')) {
            return substr($normalized, 0, 64);
        }

        return mb_substr($normalized, 0, 64);
    }

    /**
     * Normalise augmentation responses to the `{ success, prompt }` JSON contract.
     *
     * @param bool   $success Indicates whether augmentation succeeded.
     * @param string $prompt  Prompt payload to return to the caller.
     * @return string JSON representation of the response payload.
     */
    private static function formatAugmentationResponse($success, $prompt) {
        $prompt = (string) $prompt;
        $prompt = str_replace(["\r\n", "\r"], "\n", $prompt);
        $prompt = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $prompt);

        if (!is_string($prompt)) {
            $prompt = '';
        }

        $payload = [
            'success' => (bool) $success,
            'prompt'  => $prompt,
        ];

        $encoded = wp_json_encode($payload);

        if (!is_string($encoded)) {
            $fallback = [
                'success' => false,
                'prompt'  => $prompt,
            ];

            $encoded = wp_json_encode($fallback);
        }

        if (!is_string($encoded)) {
            $encoded = '{"success":false,"prompt":""}';
        }

        return $encoded;
    }

    /**
     * Decode an augmentation response JSON string into an associative array.
     *
     * @param string $responseJson JSON payload returned by the augmentation workflow.
     * @return array{success: bool, prompt: string}|null
     */
    private static function decodeAugmentationResponse($responseJson) {
        if (!is_string($responseJson) || $responseJson === '') {
            return null;
        }

        $decoded = json_decode($responseJson, true);

        if (!is_array($decoded)) {
            return null;
        }

        if (!array_key_exists('success', $decoded)) {
            return null;
        }

        if (!array_key_exists('prompt', $decoded)) {
            return null;
        }

        return [
            'success' => (bool) $decoded['success'],
            'prompt'  => (is_string($decoded['prompt']) ? $decoded['prompt'] : ''),
        ];
    }

    /**
     * Persist the optimized prompt metadata and prime the transient cache.
     *
     * @param string $prompt         Original prompt content.
     * @param string $model          Model identifier used during augmentation.
     * @param string $optimizedPrompt Optimized prompt text.
     * @param string $status         Result status (success|fallback).
     * @return void
     */
    private static function persistAugmentedPrompt($prompt, $model, $optimizedPrompt, $status) {
        $record = [
            'optimized_prompt'   => (string) $optimizedPrompt,
            'source_prompt_hash' => self::hashAugmentedPrompt($prompt),
            'model_id'           => self::normalizeModelId($model),
            'generated_at'       => time(),
            'status'             => sanitize_key($status),
        ];

        $optionName = self::getOptionName(self::AUGMENTED_OPTIMIZED_PROMPT_OPTION_KEY);

        self::updateLargeOption($optionName, $record);
        self::setAugmentedPromptCache($record);
    }

    /**
     * Retrieve the stored augmented prompt record, falling back to defaults when absent.
     *
     * @return array<string, mixed>
     */
    private static function getStoredAugmentedPromptRecord() {
        $cache = get_transient(self::AUGMENTED_PROMPT_TRANSIENT_KEY);

        if (self::isValidAugmentedPromptRecord($cache)) {
            return self::normalizeAugmentedPromptRecord($cache);
        }

        $option = get_option(
            self::getOptionName(self::AUGMENTED_OPTIMIZED_PROMPT_OPTION_KEY),
            []
        );

        if (self::isValidAugmentedPromptRecord($option)) {
            $record = self::normalizeAugmentedPromptRecord($option);
            self::setAugmentedPromptCache($record);

            return $record;
        }

        return self::normalizeAugmentedPromptRecord([]);
    }

    /**
     * Normalize the augmented prompt record to a consistent structure.
     *
     * @param mixed $record Raw record value from storage.
     * @return array<string, mixed>
     */
    private static function normalizeAugmentedPromptRecord($record) {
        if (!is_array($record)) {
            $record = [];
        }

        $optimized = isset($record['optimized_prompt']) && is_string($record['optimized_prompt'])
            ? $record['optimized_prompt']
            : '';
        $hash = isset($record['source_prompt_hash']) && is_string($record['source_prompt_hash'])
            ? $record['source_prompt_hash']
            : '';
        $model = isset($record['model_id']) && is_string($record['model_id'])
            ? self::normalizeModelId($record['model_id'])
            : '';
        $generatedAt = isset($record['generated_at'])
            ? (int) $record['generated_at']
            : 0;
        $status = isset($record['status']) && is_string($record['status'])
            ? sanitize_key($record['status'])
            : '';

        return [
            'optimized_prompt'   => $optimized,
            'source_prompt_hash' => $hash,
            'model_id'           => $model,
            'generated_at'       => $generatedAt,
            'status'             => $status,
        ];
    }

    /**
     * Determine whether the stored record matches the supplied prompt and model values.
     *
     * @param array<string, mixed> $record Stored record data.
     * @param string               $prompt Current raw prompt.
     * @param string               $model  Current model identifier.
     * @return bool
     */
    private static function doesAugmentedPromptRecordMatch(array $record, $prompt, $model) {
        $hash = $record['source_prompt_hash'] ?? '';
        $storedModel = $record['model_id'] ?? '';

        if (!is_string($hash) || $hash === '') {
            return false;
        }

        $promptHash = self::hashAugmentedPrompt($prompt);

        if ($promptHash !== $hash) {
            return false;
        }

        $normalizedModel = self::normalizeModelId($model);

        if ($normalizedModel === '') {
            $normalizedModel = self::DEFAULT_AI_MODEL;
        }

        if (!is_string($storedModel) || $storedModel === '') {
            return false;
        }

        return $normalizedModel === $storedModel;
    }

    /**
     * Create a deterministic hash of the provided prompt for change detection.
     *
     * @param string $prompt Prompt text.
     * @return string
     */
    private static function hashAugmentedPrompt($prompt) {
        return hash('sha256', (string) $prompt);
    }

    /**
     * Prime the augmented prompt transient cache with the provided record.
     *
     * @param array<string, mixed> $record Normalised record data.
     * @return void
     */
    private static function setAugmentedPromptCache(array $record) {
        if (!self::isValidAugmentedPromptRecord($record)) {
            self::deleteAugmentedPromptTransient();

            return;
        }

        set_transient(
            self::AUGMENTED_PROMPT_TRANSIENT_KEY,
            $record,
            self::AUGMENTED_PROMPT_TRANSIENT_TTL
        );
    }

    /**
     * Determine whether the supplied record is structurally valid.
     *
     * @param mixed $record Record candidate.
     * @return bool
     */
    private static function isValidAugmentedPromptRecord($record) {
        if (!is_array($record)) {
            return false;
        }

        if (!array_key_exists('optimized_prompt', $record)) {
            return false;
        }

        if (!array_key_exists('source_prompt_hash', $record)) {
            return false;
        }

        if (!array_key_exists('model_id', $record)) {
            return false;
        }

        return true;
    }

    /**
     * Remove the cached augmented prompt transient.
     *
     * @return void
     */
    public static function deleteAugmentedPromptTransient() {
        delete_transient(self::AUGMENTED_PROMPT_TRANSIENT_KEY);
    }

    /**
     * Record an augmentation failure notice without duplicating entries.
     *
     * @return void
     */
    private static function addAugmentationErrorNotice($status = null, $type = 'unknown_error') {
        if (true === (self::$submissionState['augmentation_error_added'] ?? false)) {
            return;
        }

        $statusLabel = self::sanitizeNoticeStatus($status);
        $typeLabel = self::sanitizeNoticeType($type);

        /* translators: 1: HTTP status or transport error code, 2: augmentation failure type identifier. */
        $template = __('Augmentation failed (status: %1$s, type: %2$s). Check debug log for details.', 'exmoment-author');
        $message = sprintf($template, $statusLabel, $typeLabel);
        $message = esc_html($message);

        add_settings_error(
            self::SETTINGS_GROUP,
            'exmoau_augmented_prompt_augmentation_failed',
            $message,
            'error'
        );

        self::$submissionState['augmentation_error_added'] = true;
    }

    /**
     * Display an admin notice when the OpenAI API key has not been configured.
     *
     * @return void
     */
    private static function addAugmentationMissingKeyNotice() {
        if (true === (self::$submissionState['augmentation_missing_key_added'] ?? false)) {
            return;
        }

        add_settings_error(
            self::SETTINGS_GROUP,
            'exmoau_augmented_prompt_missing_api_key',
            esc_html__('OpenAI API key not configured. Augmentation skipped.', 'exmoment-author'),
            'error'
        );

        self::$submissionState['augmentation_missing_key_added'] = true;
    }

    /**
     * Register hooks that invalidate the augmented prompt cache when dependencies change.
     *
     * @param string $optionName Fully qualified option name.
     * @return void
     */
    private static function registerAugmentedPromptCacheInvalidationHooks($optionName) {
        if (!is_string($optionName) || $optionName === '') {
            return;
        }

        $hooks = [
            sprintf('add_option_%s', $optionName),
            sprintf('update_option_%s', $optionName),
            sprintf('delete_option_%s', $optionName),
        ];

        foreach ($hooks as $hook) {
            add_action($hook, [self::class, 'deleteAugmentedPromptTransient'], 10, 0);
        }
    }

    /**
     * Persist a large option value while ensuring it is not autoloaded.
     *
     * @param string               $optionName Option identifier.
     * @param array<string, mixed> $value      Value to store.
     * @return void
     */
    private static function updateLargeOption($optionName, $value) {
        if (!is_string($optionName) || $optionName === '') {
            return;
        }

        $existing = get_option($optionName, null);

        if (null === $existing) {
            add_option($optionName, $value, '', 'no');
            self::primeOptionAutoloadCache($optionName, 'no');

            return;
        }

        $autoloadFlag = self::getOptionAutoloadFlag($optionName);
        $autoloadArgument = ('no' === $autoloadFlag) ? null : 'no';

        update_option($optionName, $value, $autoloadArgument);

        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($optionName, 'options');
        }

        self::primeOptionAutoloadCache($optionName, 'no');
    }

    /**
     * Retrieve the current autoload flag for an option using a cached prepared query.
     *
     * @param string $optionName Option identifier.
     * @return string|null Autoload flag (yes|no) or null when unavailable.
     */
    private static function getOptionAutoloadFlag($optionName) {
        if (!is_string($optionName) || $optionName === '') {
            return null;
        }

        $cacheKey = self::getAutoloadCacheKey($optionName);

        if (function_exists('wp_cache_get')) {
            $cached = wp_cache_get($cacheKey, self::AUTOLOAD_CACHE_GROUP);

            if (false !== $cached) {
                if (!is_string($cached) || $cached === self::AUTOLOAD_CACHE_MISS) {
                    return null;
                }

                return $cached;
            }
        }

        global $wpdb;

        if (!isset($wpdb) || !($wpdb instanceof \wpdb)) {
            return null;
        }

        $table = $wpdb->options;

        $autoload = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT autoload FROM {$table} WHERE option_name = %s LIMIT 1",
                $optionName
            )
        );

        $cacheValue = self::AUTOLOAD_CACHE_MISS;

        if (is_string($autoload) && $autoload !== '') {
            $cacheValue = $autoload;
        }

        if (function_exists('wp_cache_set')) {
            wp_cache_set(
                $cacheKey,
                $cacheValue,
                self::AUTOLOAD_CACHE_GROUP,
                self::AUTOLOAD_CACHE_TTL
            );
        }

        return ($cacheValue === self::AUTOLOAD_CACHE_MISS) ? null : $cacheValue;
    }

    /**
     * Cache the autoload flag for the supplied option.
     *
     * @param string      $optionName Option identifier.
     * @param string|null $autoload   Autoload value to store.
     * @return void
     */
    private static function primeOptionAutoloadCache($optionName, $autoload) {
        if (!is_string($optionName) || $optionName === '') {
            return;
        }

        if (!function_exists('wp_cache_set')) {
            return;
        }

        $cacheKey = self::getAutoloadCacheKey($optionName);
        $cacheValue = self::AUTOLOAD_CACHE_MISS;

        if (is_string($autoload) && $autoload !== '') {
            $cacheValue = $autoload;
        }

        wp_cache_set(
            $cacheKey,
            $cacheValue,
            self::AUTOLOAD_CACHE_GROUP,
            self::AUTOLOAD_CACHE_TTL
        );
    }

    /**
     * Build the cache key used for storing option autoload flags.
     *
     * @param string $optionName Option identifier.
     * @return string Cache key for the autoload flag record.
     */
    private static function getAutoloadCacheKey($optionName) {
        return md5($optionName) . ':autoload';
    }
}
