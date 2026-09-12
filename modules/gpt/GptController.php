<?php

namespace ExMomentAuthor\Modules\Gpt;

use ExMomentAuthor\Modules\Ai\AiService;
use ExMomentAuthor\Core\ExMomentAuthorCoreSystem;
use ExMomentAuthor\Modules\Jobs\JobsAiContextResolver;
use ExMomentAuthor\Modules\Settings\SettingsController;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WP_Post;

class GptController {

    public static $config;
    public static $roles;

    public $user;
    public $controllers;

    private static $weightsMap;
    private $aiService;

    /**
     * Captures diagnostics for the most recent chat completion request.
     *
     * @var array<string, mixed>|null
     */
    private $lastChatCompletionDiagnostics;

    /**
     * Cached AI model metadata for the current process.
     *
     * @var array<int, array{id: string, name: string}>
     */
    private static $modelListCache = [];

    /**
     * Flag indicating whether the model cache has been initialised for this request.
     *
     * @var bool
     */
    private static $modelListCacheInitialised = false;

    /**
     * Default model identifier used as a safe fallback when the API is unavailable.
     */
    private const DEFAULT_MODEL_ID = '';

    /**
     * Transient key retained for clearing model metadata from pre-migration installs.
     */
    private const MODEL_LIST_TRANSIENT = 'exmoau_gpt_model_list';

    /**
     * Transient lifetime in seconds (five minutes).
     */
    private const MODEL_LIST_CACHE_TTL = 300;

    /**
     * Debug log message emitted when GPT API calls are bypassed.
     */
    private const DEBUG_BYPASS_MESSAGE = '[ExMoment Author] GPT debug mode active — API bypassed.';

    /**
     * Post meta flag indicating that an AI featured image has already been generated.
     */
    private const META_AI_FEATURED_IMAGE = 'exmoau_ai_featured_image_generated';

    /**
     * Maximum accepted generated image payload size (25 MiB).
     */
    private const MAX_AI_IMAGE_FILE_SIZE = 26214400;

    /**
     * Keep featured images specific to the article and prevent repetitive subject casting.
     */
    private const FEATURED_IMAGE_COMPOSITION_INSTRUCTION = 'Editorial composition requirements: Represent the article\'s specific subject rather than a generic lifestyle scene. Include people only when they help communicate the subject. When people are included, vary their gender presentation instead of defaulting to women.';

    /**
     * Retrieve sanitized image generation settings from persisted configuration.
     *
     * @return array{model: string, style_prompt: string, dimensions: string, format: string, enabled: bool}
     */
    private function getImageGenerationSettings() {
        $model = SettingsController::getAiImageModel();
        $stylePrompt = SettingsController::getAiImageStylePrompt();
        $dimensions = SettingsController::getAiImageDimensions();
        $format = SettingsController::getAiImageFormat();
        $enabled = SettingsController::isAiImageGenerationEnabled();

        $model = ($model !== '' ? $model : SettingsController::getDefaultAiImageModel());
        $dimensions = ($dimensions !== '' ? $dimensions : SettingsController::getDefaultAiImageDimensions());
        $format = ($format !== '' ? $format : SettingsController::getDefaultAiImageFormat());

        return array(
            'model'        => $model,
            'style_prompt' => $stylePrompt,
            'dimensions'   => $dimensions,
            'format'       => $format,
            'enabled'      => $enabled,
        );
    }

    /**
     * Bootstrap the GPT compatibility module and load function controllers.
     *
     * The constructor merges the provided configuration with module defaults, prepares the
     * controller registry, and resolves the internal WordPress AI Client service. Provider
     * credentials remain under WordPress ownership. Controller classes are autoloaded from the
     * configured directory while ensuring restricted files are ignored.
     *
     * @param array<string, mixed> $config Module configuration such as temperature defaults and
     *                                     filesystem paths. Unexpected keys are ignored.
     * @return void
     * @since 1.1.0
     *
     * Example:
     * ```
     * $gpt = new GptController([
     *     'temperature' => 0.2,
     * ]);
     * ```
     */
    public function __construct($config) {

        // Set Base GPT config
        self::$config = array_merge(
            $config,
            [
                'controllersPath' => dirname(__FILE__) .'/controllers/',
                'restrictedControllers' => [
                    '.',
                    '..',
                    // 'WriteFile.php',
                ],
            ]
        );

        self::$roles = [
            'system' => 'system',
            'user' => 'user',
            'assistant' => 'assistant',
            'function' => 'function',
        ];

        $this->user = 'ExMomentAuthorSystem';

        $this->controllers = [];

        self::initialiseWeightsMap();

        // Init APIs
        $this->init();
    }

    /**
     * Initialise module services and autoload function controllers.
     *
     * This method loads controller classes from disk and resolves the internal AI service. It does
     * not dispatch a generation request. Failures to load controllers are logged only when WP_DEBUG
     * is enabled.
     *
     * @return void
     * @since 1.1.0
     *
     * Example:
     * ```
     * $this->init();
     * ```
     */
    protected function init() {

        // Autoload
        $this->autoload();

        if ($this->isDebugModeEnabled()) {
            $this->logDebugBypass('initialisation');

            return;
        }

        $this->aiService = $this->resolveAiService();
    }

    /**
     * Return the configured weights map, initialising it when necessary.
     *
     * @return array<int|string, int>
     */
    public static function getWeightsMap() {
        self::initialiseWeightsMap();

        return self::$weightsMap;
    }

    /**
     * Ensure the weights map is populated for the current request.
     *
     * @return void
     */
    private static function initialiseWeightsMap() {
        if (is_array(self::$weightsMap) && !empty(self::$weightsMap)) {
            return;
        }

        self::$weightsMap = self::getDefaultWeightsMap();
    }

    /**
     * Provide the default weights map used by GPT operations.
     *
     * @return array<int|string, int>
     */
    private static function getDefaultWeightsMap() {
        return [
            'single' => 2,
            0 => 60,
            1 => 120,
            2 => 500,
            '2aq' => 16000,
            '4aq' => 32000,
        ];
    }

    /**
     * Resolve the maximum tokens allowed for the provided weight key.
     *
     * Falls back to the default weight key when the supplied key is missing or
     * no longer present in the weights map.
     *
     * @param int|string $weight Weight key requested by the caller.
     * @return int|null Maximum tokens for the resolved weight key or null when unavailable.
     */
    private function resolveMaxTokens($weight) {
        $weightsMap = self::getWeightsMap();
        $defaultWeightKey = SettingsController::getDefaultAiTokenBudgetKey();

        $normalizedWeightKey = '';

        if (is_string($weight) || is_numeric($weight)) {
            $normalizedWeightKey = (string) $weight;
        }

        if ($normalizedWeightKey === '') {
            $normalizedWeightKey = $defaultWeightKey;
        }

        if (!array_key_exists($normalizedWeightKey, $weightsMap)) {
            $normalizedWeightKey = $defaultWeightKey;
        }

        if (!array_key_exists($normalizedWeightKey, $weightsMap)) {
            return null;
        }

        return $weightsMap[$normalizedWeightKey];
    }

    /**
     * Determine whether GPT debug mode is currently enabled.
     *
     * @return bool
     */
    public function isDebugModeEnabled() {
        return SettingsController::isGptDebugModeEnabled();
    }

    /**
     * Emit a debug log entry when GPT operations are bypassed.
     *
     * @param string $context Operational context for diagnostics.
     * @return void
     */
    private function logDebugBypass($context) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $message = self::DEBUG_BYPASS_MESSAGE;

        if (is_string($context) && $context !== '') {
            $message = sprintf('%s Context: %s.', $message, $context);
        }

        $logger = \ExMomentAuthor\Modules\Log\LogService::getInstance();
        if ($logger instanceof \ExMomentAuthor\Modules\Log\LogService) {
            $logger->debug('gpt.debug_bypass', $message, ['context' => $context], 0);
        }
    }

    /**
     * Build a deterministic chat completion response for debug mode.
     *
     * @return object Generic response object matching the plugin's legacy chat response shape.
     */
    private function buildDebugChatCompletionResponse() {
        $message = (object) [
            'role' => 'assistant',
            'content' => "# TEST\n\nTEST",
        ];

        $choice = (object) [
            'index' => 0,
            'message' => $message,
            'finish_reason' => 'stop',
        ];

        $usage = (object) [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
        ];

        return (object) [
            'id' => 'debug-chat-completion',
            'object' => 'chat.completion',
            'created' => time(),
            'model' => self::DEFAULT_MODEL_ID,
            'choices' => [$choice],
            'usage' => $usage,
        ];
    }

    /**
     * Build a deterministic legacy completion response for debug mode.
     *
     * @return array<string, mixed> Predictable payload used when remote calls are bypassed.
     */
    private function buildDebugCompletionPayload() {
        return [
            'title' => 'TEST',
            'content' => 'TEST',
            'tokens_used' => 0,
            'generated_text' => 'TEST',
        ];
    }

    /**
     * Discover and instantiate GPT function controllers from the module directory.
     *
     * Iterates the configured controllers directory, skipping restricted entries, and requires each
     * PHP file before instantiating its namespaced class. Errors are logged to error_log only when
     * WP_DEBUG is true. No user input is consumed; the filesystem path originates from trusted
     * configuration.
     *
     * @return bool|null False when autoloading is aborted (for example missing directory); null on success.
     * @since 1.1.0
     *
     * Example:
     * ```
     * $autoloaded = $this->autoload();
     * if ($autoloaded === false) {
     *     // Controllers directory missing or empty.
     * }
     * ```
     */
    protected function autoload() {
        if (
            empty(self::$config['controllersPath']) ||
            !file_exists(self::$config['controllersPath'])
        ) { return false; }

        $controllersDir = scandir(self::$config['controllersPath']);

        if (
            empty($controllersDir) ||
            count($controllersDir) <= 2
        ) { return false; }

        foreach ($controllersDir as $controllerScript) {
            if (in_array($controllerScript, self::$config['restrictedControllers'])) { continue; }

            $controllerPath = self::$config['controllersPath'] . $controllerScript;
            $controllerClassName = explode('.php', $controllerScript)[0];

            if (
                !file_exists($controllerPath) ||
                !empty($this->controllers[$controllerClassName])
            ) { continue; }

            require_once $controllerPath;

            $controllerClass = sprintf(
                '\\ExMomentAuthor\\Modules\\Gpt\\Controllers\\%s',
                $controllerClassName
            );

            try {
                $this->controllers[$controllerClassName] = new $controllerClass();
            } catch (\Throwable $exception) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(
                        sprintf(
                            'ExMoment Author: GPT controller %s failed to initialize: %s',
                            $controllerClass,
                            $exception->getMessage()
                        )
                    );
                }

                continue;
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(
                    sprintf(
                        'ExMoment Author: GPT controller %s loaded.',
                        $controllerClass
                    )
                );
            }
        }
    }

    /**
     * Retrieve an ordered list of GPT models available to the plugin.
     *
     * Returns provider and model metadata discovered through the WordPress AI Client, enriched with
     * mandatory identifiers supplied via $ensureModelIds. No credentials are read by this module.
     *
     * @param array<int, string> $ensureModelIds Model identifiers that must be included in the
     *                                           response even if absent from the API.
     * @return array<int, array{id: string, name: string}> Ordered list of models keyed numerically; never null.
     * @since 1.1.0
     *
     * Example:
     * ```
     * $models = $gpt->getAllGptModels(['gpt-5-mini']);
     * if ($models === []) {
     *     // Fallback list applies; prompt user to verify API connectivity.
     * }
     * ```
     */
    public function getAllGptModels(array $ensureModelIds = []) {
        $models = array();
        $service = $this->resolveAiService();

        if ($service instanceof AiService) {
            foreach ($service->discover('text') as $provider) {
                foreach ($provider['models'] as $model) {
                    $models[$model['id']] = array(
                        'id'       => $model['id'],
                        'name'     => sprintf('%s — %s', $model['name'], $provider['name']),
                        'provider' => $provider['id'],
                    );
                }
            }
        }

        foreach ($this->normalizeModelIdList($ensureModelIds) as $modelId) {
            if (!isset($models[$modelId])) {
                $models[$modelId] = array(
                    'id'       => $modelId,
                    'name'     => self::formatModelName($modelId),
                    'provider' => '',
                );
            }
        }

        return array_values($models);
    }

    /**
     * Resolve the most capable GPT model identifier currently accessible.
     *
     * Prefers lightweight GPT-5 derivatives while gracefully degrading to the first available
     * identifier from the cached or fallback list. No external requests are performed directly; this
     * method relies on getAllGptModels() for data freshness.
     *
     * @return string GPT model identifier such as `gpt-5-mini`.
     * @since 1.1.0
     *
     * Example:
     * ```
     * $modelId = $gpt->getLatestGptModel();
     * ```
     */
    public function getLatestGptModel() {
        $models = $this->getAllGptModels();

        return isset($models[0]['id']) ? (string) $models[0]['id'] : '';
    }

    /**
     * Create a plain text generation through the internal AI service.
     *
     * Selects a maximum output token count from the internal budget map and issues a synchronous
     * provider-neutral request. This method is retained for internal compatibility. When debug mode
     * is active the remote call is bypassed and a deterministic payload is returned.
     *
     * @param string     $prompt Free-form prompt text already sanitised for API transport.
     * @param int|string $weight Token weight key mapped to max token allowances.
     * @return array<string, mixed>|false Array containing `generated_text` or false when weight is invalid.
     * @since 1.1.0
     *
     * Example:
     * ```
     * $completion = $gpt->completionCreate('Summarise the latest draft.', 1);
     * if ($completion === false) {
     *     // Weight configuration invalid; fall back or notify administrator.
     * }
     * ```
     */
    public function completionCreate($prompt, $weight = 0) {
        $maxTokens = $this->resolveMaxTokens($weight);

        if (empty($maxTokens)) {
            return false;
        }

        if ($this->isDebugModeEnabled()) {
            $this->logDebugBypass('legacy_completion');

            return $this->buildDebugCompletionPayload();
        }

        $result = $this->resolveAiService()->generateText(
            (string) $prompt,
            array(
                'max_tokens' => $maxTokens,
                'provider'   => SettingsController::getAiProvider(),
            )
        );

        if (empty($result['success'])) {
            return false;
        }

        return array(
            'generated_text' => $result['text'],
        );
    }

    /**
     * Dispatch a Chat Completions request with optional function-calling definitions.
     *
     * Validates token budgets, message payload shape, and model identifier before calling the
     * internal WordPress AI Client service. Provider-neutral diagnostics are captured for later
     * inspection. Service errors return a public message string while setting diagnostics; runtime
     * weight or payload validation returns false. Messages should be pre-sanitised and should not
     * include unescaped user HTML. Debug mode bypasses the remote call and returns a deterministic
     * stub response while still clearing diagnostics.
     *
     * @param array<int, array<string, mixed>> $messages Legacy conversation history. Each
     *                                                  message should include `role` and `content`
     *                                                  keys with validated values.
     * @param int|string                        $weight   Token weight key mapped to internal max token allowances.
     * @param array<int, array<string, mixed>>  $functions Optional function definitions to expose.
     * @param string|null                       $modelId  Preferred model identifier; falls back to getLatestGptModel() when empty.
     * @return object|string|false Response object on success, debug stub when bypassed, false on validation failure, or public error message on service errors.
     * @since 1.1.0
     *
     * Example:
     * ```
     * $response = $gpt->chatCompletionCreate($messages, 'single', $gpt->getAllFunctions());
     * if ($response === false || is_string($response)) {
     *     $diagnostics = $gpt->getLastChatCompletionDiagnostics();
     *     // Log diagnostics and show a sanitised admin notice.
     * }
     * ```
     */
    public function chatCompletionCreate($messages, $weight = 0, $functions = [], $modelId = null) {
        $this->resetChatCompletionDiagnostics();

        if ($this->isDebugModeEnabled()) {
            $this->logDebugBypass('chat_completion');

            return $this->buildDebugChatCompletionResponse();
        }

        $startTime = microtime(true);
        $requestedModelId = $modelId;

        $maxTokens = $this->resolveMaxTokens($weight);

        if (empty($maxTokens)) {
            $this->setChatCompletionDiagnostics(
                $this->buildChatCompletionDiagnostics([
                    'error_type' => 'invalid_weight',
                    'error_message' => 'Invalid token weight supplied.',
                ],
                $requestedModelId,
                $startTime)
            );

            return false;
        }

        if (!is_array($messages)) {
            $this->setChatCompletionDiagnostics(
                $this->buildChatCompletionDiagnostics([
                    'error_type' => 'invalid_messages_payload',
                    'error_message' => 'Messages payload must be an array.',
                ],
                $requestedModelId,
                $startTime)
            );

            return false;
        }

        unset($functions);

        $systemInstruction = '';
        $promptMessages = array();

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            if (($message['role'] ?? '') === 'system' && is_string($message['content'] ?? null)) {
                $systemInstruction = (string) $message['content'];
                continue;
            }

            $content = isset($message['content']) && is_string($message['content'])
                ? $message['content']
                : '';
            if ($content === '') {
                continue;
            }

            $messageParts = array(new MessagePart($content));
            $promptMessages[] = (($message['role'] ?? '') === 'assistant')
                ? new ModelMessage($messageParts)
                : new UserMessage($messageParts);
        }

        $attemptedModelId = is_string($modelId) ? $modelId : '';

        try {
            $generation = $this->resolveAiService()->generateText(
                $promptMessages,
                array(
                    'provider'           => SettingsController::getAiProvider(),
                    'model'              => $attemptedModelId,
                    'system_instruction' => $systemInstruction,
                    'max_tokens'         => $maxTokens,
                )
            );
        } catch (\Throwable $exception) {
            $this->setChatCompletionDiagnostics(
                $this->buildChatCompletionDiagnostics([
                    'error_type'    => 'runtime_exception',
                    'error_message' => $exception->getMessage(),
                ],
                $attemptedModelId,
                $startTime)
            );

            return __('The AI request could not be completed.', 'exmoment-author');
        }

        if (empty($generation['success'])) {
            $diagnostics = isset($generation['diagnostics']) && is_array($generation['diagnostics'])
                ? $generation['diagnostics']
                : array();
            $this->setChatCompletionDiagnostics(
                $this->buildChatCompletionDiagnostics(
                    $diagnostics,
                    $attemptedModelId,
                    $startTime
                )
            );

            return isset($generation['message'])
                ? (string) $generation['message']
                : __('The AI request could not be completed.', 'exmoment-author');
        }

        $message = (object) array(
            'role'    => 'assistant',
            'content' => (string) $generation['text'],
        );
        $choice = (object) array(
            'index'         => 0,
            'message'       => $message,
            'finish_reason' => 'stop',
        );
        $result = (object) array(
            'id'      => '',
            'object'  => 'ai_client.text_generation',
            'created' => time(),
            'model'   => (string) ($generation['model'] ?? $attemptedModelId),
            'choices' => array($choice),
            'usage'   => (object) array(),
        );

        $this->resetChatCompletionDiagnostics();

        return $result;
    }

    /**
     * Retrieve diagnostics captured during the most recent chat completion attempt.
     *
     * The payload describes HTTP metadata, error types, request identifiers, timing, and token usage.
     * It is safe to expose in logs once escaped; API keys are never included. Returns null when the
     * previous invocation succeeded or diagnostics were not recorded.
     *
     * @return array<string, mixed>|null Normalised diagnostics payload or null when unavailable.
     * @since 1.1.0
     *
     * Example:
     * ```
     * $diagnostics = $gpt->getLastChatCompletionDiagnostics();
     * if ($diagnostics !== null) {
     *     error_log(print_r($diagnostics, true));
     * }
     * ```
     */
    public function getLastChatCompletionDiagnostics() {
        if (!is_array($this->lastChatCompletionDiagnostics)) {
            return null;
        }

        return $this->lastChatCompletionDiagnostics;
    }

    /**
     * Reset stored diagnostics for the chat completion workflow.
     *
     * @return void
     */
    private function resetChatCompletionDiagnostics() {
        $this->lastChatCompletionDiagnostics = null;
    }

    /**
     * Persist chat completion diagnostics for later consumption.
     *
     * @param array<string, mixed>|null $diagnostics Normalised diagnostics payload.
     * @return void
     */
    private function setChatCompletionDiagnostics($diagnostics) {
        if (!is_array($diagnostics)) {
            $this->lastChatCompletionDiagnostics = null;

            return;
        }

        $this->lastChatCompletionDiagnostics = $diagnostics;
    }

    /**
     * Normalise diagnostics for chat completion failures.
     *
     * @param array<string, mixed> $diagnostics Raw diagnostics data from an exception or runtime validation.
     * @param string|int|null      $modelId     Model identifier attempted.
     * @param float                $startTime   Invocation start timestamp from microtime(true).
     * @return array<string, mixed> Normalised diagnostics safe for logging.
     */
    private function buildChatCompletionDiagnostics(array $diagnostics, $modelId, $startTime) {
        $normalized = [
            'http_status'     => null,
            'error_type'      => 'unknown_error',
            'error_code'      => null,
            'error_message'   => '',
            'exception_class' => '',
            'request_id'      => '',
            'model_attempted' => self::normalizeModelId($modelId),
            'timing_ms'       => $this->calculateTimingMilliseconds($startTime),
            'usage'           => [
                'prompt_tokens'     => null,
                'completion_tokens' => null,
                'total_tokens'      => null,
            ],
        ];

        if (isset($diagnostics['http_status']) && $diagnostics['http_status'] !== null) {
            $normalized['http_status'] = (int) $diagnostics['http_status'];
        }

        if (!empty($diagnostics['error_type'])) {
            $normalized['error_type'] = $this->sanitizeDiagnosticKey($diagnostics['error_type']);
        }

        if (array_key_exists('error_code', $diagnostics) && $diagnostics['error_code'] !== null && $diagnostics['error_code'] !== '') {
            $normalized['error_code'] = $this->sanitizeDiagnosticValue($diagnostics['error_code']);
        } elseif (!empty($diagnostics['source_error_code'])) {
            $normalized['error_code'] = $this->sanitizeDiagnosticValue($diagnostics['source_error_code']);
        }

        if (!empty($diagnostics['error_message'])) {
            $normalized['error_message'] = $this->sanitizeDiagnosticMessage($diagnostics['error_message']);
        }

        if (!empty($diagnostics['exception_class'])) {
            $normalized['exception_class'] = $this->sanitizeDiagnosticValue($diagnostics['exception_class']);
        }

        if (!empty($diagnostics['request_id'])) {
            $normalized['request_id'] = $this->sanitizeDiagnosticRequestId($diagnostics['request_id']);
        }

        if (isset($diagnostics['usage']) && is_array($diagnostics['usage'])) {
            $normalized['usage'] = $this->sanitizeUsageDiagnostics($diagnostics['usage']);
        }

        return $normalized;
    }

    /**
     * Calculate request duration in milliseconds.
     *
     * @param float $startTime Invocation start time.
     * @return int|null Elapsed time in milliseconds or null when input is invalid.
     */
    private function calculateTimingMilliseconds($startTime) {
        if (!is_float($startTime)) {
            return null;
        }

        $duration = microtime(true) - $startTime;

        if (!is_float($duration) && !is_numeric($duration)) {
            return null;
        }

        $durationMs = (int) round(max((float) $duration, 0) * 1000);

        return ($durationMs >= 0) ? $durationMs : null;
    }

    /**
     * Sanitize diagnostic string values while preserving basic punctuation.
     *
     * @param mixed $value Raw diagnostic value.
     * @return string Trimmed and truncated string suitable for logging.
     */
    private function sanitizeDiagnosticValue($value) {
        if (is_int($value)) {
            return (string) $value;
        }

        if (!is_string($value)) {
            return '';
        }

        $trimmed = trim($value);
        $trimmed = preg_replace('/[\x00-\x1F\x7F]+/u', '', $trimmed);

        if (!is_string($trimmed)) {
            return '';
        }

        if (!function_exists('mb_substr')) {
            return substr($trimmed, 0, 128);
        }

        return mb_substr($trimmed, 0, 128);
    }

    /**
     * Sanitize diagnostic keys such as error types.
     *
     * @param mixed $value Diagnostic key.
     * @return string
     */
    private function sanitizeDiagnosticKey($value) {
        if (!is_string($value)) {
            return 'unknown_error';
        }

        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9_\.\-]+/', '_', $normalized);

        if (!is_string($normalized) || $normalized === '') {
            return 'unknown_error';
        }

        if (!function_exists('mb_substr')) {
            return substr($normalized, 0, 64);
        }

        return mb_substr($normalized, 0, 64);
    }

    /**
     * Truncate and sanitize diagnostic messages.
     *
     * @param mixed $message Raw diagnostic message.
     * @return string Cleaned message capped at 300 characters.
     */
    private function sanitizeDiagnosticMessage($message) {
        if (is_array($message)) {
            $message = implode(' ', $message);
        }

        if (!is_string($message)) {
            return '';
        }

        $cleaned = trim($message);
        $cleaned = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $cleaned);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);

        if (!is_string($cleaned)) {
            return '';
        }

        if (!function_exists('mb_substr')) {
            return substr($cleaned, 0, 300);
        }

        return mb_substr($cleaned, 0, 300);
    }

    /**
     * Sanitize request identifiers extracted from headers.
     *
     * @param mixed $requestId Raw request identifier.
     * @return string Safe request id containing only alphanumeric characters, dashes, underscores, or periods.
     */
    private function sanitizeDiagnosticRequestId($requestId) {
        if (!is_string($requestId)) {
            return '';
        }

        $cleaned = trim($requestId);
        $cleaned = preg_replace('/[^a-zA-Z0-9\-_.]/', '', $cleaned);

        if (!is_string($cleaned)) {
            return '';
        }

        if (!function_exists('mb_substr')) {
            return substr($cleaned, 0, 64);
        }

        return mb_substr($cleaned, 0, 64);
    }

    /**
     * Normalise token usage diagnostics.
     *
     * @param array<string, mixed> $usage Raw usage data.
     * @return array<string, int|null> Token counts with null defaults when absent.
     */
    private function sanitizeUsageDiagnostics(array $usage) {
        $normalized = [
            'prompt_tokens'     => null,
            'completion_tokens' => null,
            'total_tokens'      => null,
        ];

        foreach ($normalized as $key => $default) {
            if (!array_key_exists($key, $usage)) {
                continue;
            }

            $value = $usage[$key];

            if (is_numeric($value)) {
                $normalized[$key] = (int) $value;
            }
        }

        return $normalized;
    }

    /**
     * Remove cached legacy model metadata for both the current request and persistent storage.
     *
     * @return void
     */
    public static function flushModelCache() {
        self::$modelListCache = [];
        self::$modelListCacheInitialised = false;

        if (function_exists('delete_transient')) {
            delete_transient(self::MODEL_LIST_TRANSIENT);
        }
    }

    /**
     * Generate a human-readable model label for a given identifier.
     *
     * @param string $modelId Model identifier.
     * @return string
     */
    public static function formatModelName($modelId) {
        $normalizedId = self::normalizeModelId($modelId);

        if ($normalizedId === '') {
            return '';
        }

        $readable = preg_replace('/[_-]+/', ' ', $normalizedId);
        $readable = (is_string($readable) ? $readable : $normalizedId);
        $readable = trim($readable);

        if ($readable === '') {
            return $normalizedId;
        }

        $readable = strtolower($readable);

        $readable = preg_replace_callback(
            '/(^|\s)([a-z0-9]+)/',
            static function ($matches) {
                $separator = $matches[1];
                $chunk = $matches[2];
                $chunkLower = strtolower($chunk);

                if ('gpt' === $chunkLower) {
                    return $separator . 'GPT';
                }

                if ('ai' === $chunkLower) {
                    return $separator . 'AI';
                }

                if (is_numeric($chunk)) {
                    return $separator . $chunk;
                }

                return $separator . ucfirst($chunkLower);
            },
            $readable
        );

        if (!is_string($readable)) {
            $readable = $normalizedId;
        }

        $readable = preg_replace('/\s+/', ' ', $readable);
        $readable = trim($readable);

        if ($readable === '') {
            $readable = $normalizedId;
        }

        $readable = preg_replace('/GPT ([0-9]+)([A-Za-z]?)/', 'GPT-$1$2', $readable);

        if (!is_string($readable) || $readable === '') {
            return $normalizedId;
        }

        return $readable;
    }

    /**
     * Retrieve the cached legacy model list when available.
     *
     * @return array<int, array{id: string, name: string}>|null Null when no cache exists yet; otherwise a normalised runtime or transient cache.
     */
    private function getCachedModelList() {
        if (self::$modelListCacheInitialised) {
            return self::$modelListCache;
        }

        if (!function_exists('get_transient')) {
            return null;
        }

        $cached = get_transient(self::MODEL_LIST_TRANSIENT);

        if (!is_array($cached)) {
            return null;
        }

        self::$modelListCache = $this->normalizeModelListStructure($cached);
        self::$modelListCacheInitialised = true;

        return self::$modelListCache;
    }

    /**
     * Persist a fetched model list in runtime and transient caches.
     *
     * @param array<int, array{id: string, name: string}> $models Normalised model list.
     * @return void
     */
    private function setCachedModelList(array $models) {
        $normalised = $this->normalizeModelListStructure($models);

        self::$modelListCache = $normalised;
        self::$modelListCacheInitialised = true;

        if (function_exists('set_transient')) {
            set_transient(
                self::MODEL_LIST_TRANSIENT,
                $normalised,
                self::MODEL_LIST_CACHE_TTL
            );
        }
    }

    /**
     * Fetch all available text models through the internal AI service.
     *
     * @return array<int, array{id: string, name: string}> Normalised discovered model list.
     */
    private function fetchAllGptModels() {
        $models = [];

        foreach ($this->resolveAiService()->discover('text') as $provider) {
            foreach ($provider['models'] as $model) {
                $models[$model['id']] = array(
                    'id'   => $model['id'],
                    'name' => $model['name'],
                );
            }
        }

        return array_values($models);
    }

    /**
     * Produce a fallback model list when no compatible model is available.
     *
     * @param array<int, string> $ensureModelIds Additional model identifiers to include.
     * @return array<int, array{id: string, name: string}>
     */
    private function buildFallbackModelList(array $ensureModelIds) {
        $modelIds = array_merge($ensureModelIds, [self::DEFAULT_MODEL_ID]);
        $modelIds = $this->normalizeModelIdList($modelIds);

        if ($modelIds === []) {
            $modelIds = [self::DEFAULT_MODEL_ID];
        }

        $models = [];

        foreach ($modelIds as $modelId) {
            $models[$modelId] = [
                'id'   => $modelId,
                'name' => self::formatModelName($modelId),
            ];
        }

        return array_values($models);
    }

    /**
     * Merge the cached model list with additional identifiers that must be present.
     *
     * @param array<int, array{id: string, name: string}> $models Cached model list.
     * @param array<int, string>                          $ensureModelIds Additional identifiers to include.
     * @return array<int, array{id: string, name: string}>
     */
    private function mergeModelListWithEnsures(array $models, array $ensureModelIds) {
        $normalized = [];

        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }

            $modelId = isset($model['id']) ? self::normalizeModelId($model['id']) : '';

            if ($modelId === '') {
                continue;
            }

            $modelName = isset($model['name']) ? $this->sanitizeModelName($model['name'], $modelId) : '';

            $normalized[$modelId] = [
                'id'   => $modelId,
                'name' => ($modelName !== '' ? $modelName : self::formatModelName($modelId)),
            ];
        }

        foreach ($this->normalizeModelIdList($ensureModelIds) as $modelId) {
            if (isset($normalized[$modelId])) {
                continue;
            }

            $normalized[$modelId] = [
                'id'   => $modelId,
                'name' => self::formatModelName($modelId),
            ];
        }

        return array_values($normalized);
    }

    /**
     * Normalise an array of model entries into a deterministic structure.
     *
     * @param array<int, mixed> $models Raw model entries.
     * @return array<int, array{id: string, name: string}>
     */
    private function normalizeModelListStructure(array $models) {
        return $this->mergeModelListWithEnsures($models, []);
    }

    /**
     * Normalise an array of model identifiers.
     *
     * @param array<int, mixed> $modelIds Raw identifiers.
     * @return array<int, string>
     */
    private function normalizeModelIdList(array $modelIds) {
        $normalized = [];

        foreach ($modelIds as $modelId) {
            $normalizedId = self::normalizeModelId($modelId);

            if ($normalizedId === '') {
                continue;
            }

            $normalized[$normalizedId] = $normalizedId;
        }

        return array_values($normalized);
    }

    /**
     * Normalise a single model identifier.
     *
     * @param mixed $modelId Raw model identifier.
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
     * Sanitize the human-readable name for a model entry.
     *
     * @param mixed  $name    Raw name returned by the API.
     * @param string $modelId Normalised model identifier.
     * @return string
     */
    private function sanitizeModelName($name, $modelId) {
        if (!is_string($name)) {
            return self::formatModelName($modelId);
        }

        $normalized = trim($name);
        $normalized = preg_replace('/[\r\n\t]+/', ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        if (function_exists('wp_strip_all_tags')) {
            $normalized = wp_strip_all_tags($normalized);
        }

        if (!is_string($normalized)) {
            $normalized = '';
        }

        $normalized = trim($normalized);

        if ($normalized === '') {
            return self::formatModelName($modelId);
        }

        return $normalized;
    }

    /**
     * Generate and attach an AI-created featured image for a post.
     *
     * The method builds a concise prompt from the first ~30 words of the post content, requests one
     * image through the WordPress AI Client, validates and stores the returned JPEG, WebP, or PNG
     * bytes without conversion, and assigns the resulting attachment as the post thumbnail. Execution is skipped
     * when the post already has a featured image, the
     * feature is disabled, or the prompt cannot be derived. Errors are logged in debug mode without
     * interrupting callers.
     *
     * @param int   $postId  Target post identifier.
     * @param array $options Optional future extension options; currently unused.
     * @return array<string, mixed> Structured outcome payload with success flag and attachment id when available.
     */
    public function generateFeaturedImageForPost($postId, array $options = []) {
        unset($options);

        $postId = absint($postId);
        if ($postId < 1) {
            return [
                'success' => false,
                'error' => 'invalid_post',
            ];
        }

        $imageSettings = $this->getImageGenerationSettings();
        $enabled = apply_filters('exmoau_enable_ai_featured_images', $imageSettings['enabled'], $postId);
        $enabled = apply_filters('exmoau_enable_ai_featured_images', $enabled, $postId);
        if (!$imageSettings['enabled'] || $enabled !== true) {
            return [
                'success' => false,
                'error' => 'feature_disabled',
            ];
        }

        if ($this->isDebugModeEnabled()) {
            $this->logDebugBypass('image_generation');

            return [
                'success' => false,
                'error' => 'debug_mode',
            ];
        }

        if (has_post_thumbnail($postId)) {
            return [
                'success' => false,
                'error' => 'thumbnail_exists',
            ];
        }

        $post = get_post($postId);
        if (!($post instanceof WP_Post)) {
            return [
                'success' => false,
                'error' => 'post_missing',
            ];
        }

        $alreadyGenerated = get_post_meta($postId, self::META_AI_FEATURED_IMAGE, true);

        if (is_numeric($alreadyGenerated) && (int) $alreadyGenerated === 1) {
            return [
                'success' => false,
                'error' => 'already_generated',
            ];
        }

        $authorContextEnabled = SettingsController::shouldIncludeAuthorNameInAiContext();
        $authorDisplayName = $authorContextEnabled
            ? JobsAiContextResolver::resolveAuthorDisplayName($post->post_author)
            : '';
        $authorContext = $authorContextEnabled
            ? JobsAiContextResolver::buildImageAuthorContext($authorDisplayName)
            : '';

        if ($authorContextEnabled && $authorContext === '') {
            $this->logImageGenerationDebug(
                $postId,
                'Image generation continued without author context because no public display name could be resolved.',
                array(
                    'author_context_enabled' => true,
                    'author_resolved'        => false,
                )
            );
        }

        $prompt = $this->buildImagePromptForPost(
            $post,
            $imageSettings['style_prompt'],
            $authorContext
        );
        if ($prompt === '') {
            return [
                'success' => false,
                'error' => 'prompt_unavailable',
            ];
        }

        $model = apply_filters('exmoau_ai_image_model', $imageSettings['model'], $postId);
        $model = is_string($model) ? trim($model) : '';
        $size = apply_filters(
            'exmoau_ai_image_size',
            $imageSettings['dimensions'],
            $postId
        );

        $allowedDimensions = SettingsController::getAllowedAiImageDimensions();
        if (is_string($size)) {
            $size = strtolower(trim($size));
        }

        if (!is_string($size) || !in_array($size, $allowedDimensions, true)) {
            $size = SettingsController::getDefaultAiImageDimensions();
        }

        $format = $imageSettings['format'];
        if (!is_string($format) || !in_array($format, SettingsController::getAllowedAiImageFormats(), true)) {
            $format = SettingsController::getDefaultAiImageFormat();
        }

        $this->logImageGenerationDebug(
            $postId,
            'Preparing AI image generation request.',
            array(
                'selected_model'         => $model,
                'prompt_length'          => strlen($prompt),
                'requested_size'         => $size,
                'requested_format'       => $format,
                'debug_mode'             => false,
                'author_context_enabled' => $authorContextEnabled,
                'author_resolved'        => $authorContext !== '',
            )
        );

        try {
            $generation = $this->resolveAiService()->generateImage(
                $prompt,
                array(
                    'provider'   => SettingsController::getAiProvider(),
                    'model'      => $model,
                    'dimensions' => $size,
                    'format'     => $format,
                )
            );
        } catch (\Throwable $exception) {
            $this->logImageGenerationDebug(
                $postId,
                'Unexpected image generation runtime error encountered.',
                array(
                    'selected_model'   => $model,
                    'requested_size'   => $size,
                    'requested_format' => $format,
                    'error_message'    => $this->sanitizeImageGenerationLogValue($exception->getMessage()),
                )
            );

            return [
                'success' => false,
                'error' => 'runtime_error',
            ];
        }

        if (empty($generation['success']) || empty($generation['file'])) {
            return array(
                'success' => false,
                'error'   => isset($generation['error']) ? sanitize_key($generation['error']) : 'provider_failure',
            );
        }

        $file = $generation['file'];
        $requestedMimeType = isset($generation['requested_mime_type'])
            ? strtolower(trim((string) $generation['requested_mime_type']))
            : '';
        $reportedMimeType = isset($generation['reported_mime_type'])
            ? strtolower(trim((string) $generation['reported_mime_type']))
            : '';
        $isRemoteFile = $file->isRemote();
        $isInlineFile = $file->isInline();
        $payloadType = $isRemoteFile ? 'url' : ($isInlineFile ? 'b64' : '');
        $payloadValue = $isRemoteFile
            ? (string) $file->getUrl()
            : ($isInlineFile ? (string) $file->getBase64Data() : '');
        $normalizedPayload = array(
            'has_data'   => $payloadValue !== '',
            'item_count' => 1,
            'has_url'    => $isRemoteFile,
            'has_b64'    => $isInlineFile,
            'type'       => $payloadType,
            'value'      => $payloadValue,
        );

        $this->logImageGenerationDebug(
            $postId,
            'Received WordPress AI Client image generation response.',
            array(
                'selected_model'         => $model,
                'response_contains_data' => $normalizedPayload['has_data'],
                'response_item_count'    => $normalizedPayload['item_count'],
                'first_item_has_url'     => $normalizedPayload['has_url'],
                'first_item_has_b64'     => $normalizedPayload['has_b64'],
                'requested_mime_type'    => $requestedMimeType,
                'reported_mime_type'     => $reportedMimeType,
            )
        );

        if (!$normalizedPayload['has_data'] || $normalizedPayload['type'] === '') {
            return [
                'success' => false,
                'error' => 'invalid_response',
            ];
        }

        $this->logImageGenerationDebug(
            $postId,
            'Attempting to persist generated image to WordPress media.',
            array(
                'selected_model' => $model,
                'media_save_attempted' => true,
                'payload_type' => $normalizedPayload['type'],
            )
        );

        $attachmentId = false;

        if ($normalizedPayload['type'] === 'b64') {
            $binary = base64_decode($normalizedPayload['value'], true);
            if (!is_string($binary) || $binary === '') {
                $this->logImageGenerationDebug(
                    $postId,
                    'Generated image base64 payload could not be decoded.',
                    array(
                        'selected_model' => $model,
                        'payload_type' => 'b64',
                    )
                );

                return array(
                    'success' => false,
                    'error' => 'decode_failure',
                );
            }

            $attachmentId = $this->saveFeaturedImageFromBinary(
                $postId,
                $binary,
                $post->post_title,
                $requestedMimeType,
                $reportedMimeType
            );
        } elseif ($normalizedPayload['type'] === 'url') {
            $attachmentId = $this->saveFeaturedImageFromUrl(
                $postId,
                $normalizedPayload['value'],
                $post->post_title,
                $requestedMimeType,
                $reportedMimeType
            );
        }

        if (!$attachmentId) {
            $this->logImageGenerationDebug(
                $postId,
                'Generated image could not be saved as a media attachment.',
                array(
                    'selected_model' => $model,
                    'payload_type' => $normalizedPayload['type'],
                )
            );

            return [
                'success' => false,
                'error' => 'attachment_failure',
            ];
        }

        update_post_meta($postId, self::META_AI_FEATURED_IMAGE, 1);

        $this->logImageGenerationDebug(
            $postId,
            'Generated image was saved and attached successfully.',
            array(
                'selected_model' => $model,
                'attachment_id' => (int) $attachmentId,
            )
        );

        return [
            'success' => true,
            'attachment_id' => (int) $attachmentId,
        ];
    }

    /**
     * Persist a generated image from a remote URL as a WordPress attachment.
     *
     * @param int    $postId    Target post identifier.
     * @param string $url       Remote image URL returned by the API.
     * @param string $postTitle          Post title used for attachment naming.
     * @param string $requestedMimeType Requested MIME type.
     * @param string $reportedMimeType  MIME type reported by the AI Client.
     * @return int|false
     */
    private function saveFeaturedImageFromUrl(
        $postId,
        $url,
        $postTitle,
        $requestedMimeType = '',
        $reportedMimeType = ''
    ) {
        $postId = absint($postId);
        $url = (is_string($url) ? esc_url_raw(trim($url)) : '');

        if ($postId < 1 || $url === '') {
            return false;
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $temporaryFile = download_url($url, 30);
        if (is_wp_error($temporaryFile) || !is_string($temporaryFile) || $temporaryFile === '') {
            return false;
        }

        $temporarySize = filesize($temporaryFile);
        if (!is_int($temporarySize) || $temporarySize < 1 || $temporarySize > self::MAX_AI_IMAGE_FILE_SIZE) {
            if (file_exists($temporaryFile)) {
                wp_delete_file($temporaryFile);
            }

            return false;
        }

        $binary = file_get_contents($temporaryFile);

        if (file_exists($temporaryFile)) {
            wp_delete_file($temporaryFile);
        }

        if (!is_string($binary) || $binary === '') {
            return false;
        }

        return $this->saveFeaturedImageFromBinary(
            $postId,
            $binary,
            $postTitle,
            $requestedMimeType,
            $reportedMimeType
        );
    }

    /**
     * Record sanitized image-generation diagnostics through the existing log service.
     *
     * @param int                 $postId  Related post identifier.
     * @param string              $message Summary log message.
     * @param array<string, mixed> $context Safe metadata context.
     * @return void
     */
    private function logImageGenerationDebug($postId, $message, array $context = array()) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $logger = \ExMomentAuthor\Modules\Log\LogService::getInstance();
        if (!($logger instanceof \ExMomentAuthor\Modules\Log\LogService)) {
            return;
        }

        $normalizedContext = array();

        foreach ($context as $key => $value) {
            $normalizedKey = sanitize_key((string) $key);

            if ($normalizedKey === '') {
                continue;
            }

            $normalizedContext[$normalizedKey] = $this->sanitizeImageGenerationLogValue($value);
        }

        $logger->debug('gpt.image', sanitize_text_field((string) $message), $normalizedContext, $postId);
    }

    /**
     * Normalize image-generation diagnostic values before logging.
     *
     * @param mixed $value Raw context value.
     * @return bool|int|float|string|null
     */
    private function sanitizeImageGenerationLogValue($value) {
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        if (is_scalar($value)) {
            $value = sanitize_text_field((string) $value);

            if (strlen($value) > 300) {
                $value = substr($value, 0, 300);
            }

            return $value;
        }

        return sanitize_text_field(wp_json_encode($value));
    }

    /**
     * Build a concise AI image prompt from post content.
     *
     * @param WP_Post $post          Target post object.
     * @param string  $stylePrompt   Global image style prompt.
     * @param string  $authorContext Optional public author context instruction.
     * @return string Sanitized prompt string.
     */
    private function buildImagePromptForPost(WP_Post $post, $stylePrompt = '', $authorContext = '') {
        $content = is_string($post->post_content) ? $post->post_content : '';
        $content = wp_strip_all_tags($content, true);
        $content = preg_replace('/[\r\n\t]+/', ' ', $content);
        $content = preg_replace('/\s+/', ' ', $content);
        $content = is_string($content) ? trim($content) : '';

        $excerpt = '';
        if (function_exists('wp_trim_words')) {
            $excerpt = wp_trim_words($content, 30, '');
        } else {
            $excerpt = $this->trimWordsFallback($content, 30);
        }

        $excerpt = is_string($excerpt) ? trim($excerpt) : '';
        if ($excerpt === '') {
            return '';
        }

        $title = is_string($post->post_title) ? $post->post_title : '';
        $title = wp_strip_all_tags($title, true);
        $title = is_string($title) ? trim($title) : '';

        $prompt = ($title !== '')
            ? sprintf('Featured image for article "%s". Visualize: %s', $title, $excerpt)
            : sprintf('Featured image concept: %s', $excerpt);

        $prompt = preg_replace('/\s+/', ' ', $prompt);
        $prompt = is_string($prompt) ? trim($prompt) : '';

        $stylePrompt = is_string($stylePrompt) ? $stylePrompt : '';
        $stylePrompt = wp_strip_all_tags($stylePrompt, true);
        $stylePrompt = preg_replace('/[\r\n\t]+/', ' ', $stylePrompt);
        $stylePrompt = preg_replace('/\s+/', ' ', $stylePrompt);
        $stylePrompt = is_string($stylePrompt) ? trim($stylePrompt) : '';

        $combinedPrompt = ($stylePrompt !== '') ? trim($stylePrompt . ' ' . $prompt) : $prompt;
        $combinedPrompt = preg_replace('/\s+/', ' ', $combinedPrompt);
        $combinedPrompt = is_string($combinedPrompt) ? trim($combinedPrompt) : '';

        if (strlen($combinedPrompt) > 500) {
            $combinedPrompt = substr($combinedPrompt, 0, 500);
        }

        $combinedPrompt = trim(
            $combinedPrompt . ' ' . self::FEATURED_IMAGE_COMPOSITION_INSTRUCTION
        );

        $authorContext = is_string($authorContext) ? $authorContext : '';
        $authorContext = wp_strip_all_tags($authorContext, true);
        $authorContext = preg_replace('/[\r\n\t]+/', ' ', $authorContext);
        $authorContext = preg_replace('/\s+/', ' ', $authorContext);
        $authorContext = is_string($authorContext) ? trim($authorContext) : '';

        if ($authorContext !== '') {
            $combinedPrompt = trim($combinedPrompt . ' ' . $authorContext);
        }

        return $combinedPrompt;
    }

    /**
     * Trim a string to a limited number of words without WordPress helpers.
     *
     * @param string $text  Raw text.
     * @param int    $limit Maximum word count.
     * @return string
     */
    private function trimWordsFallback($text, $limit) {
        $text = is_string($text) ? trim($text) : '';
        $limit = (int) $limit;

        if ($text === '' || $limit < 1) {
            return '';
        }

        $words = preg_split('/\s+/', $text);
        if (!is_array($words)) {
            return '';
        }

        if (count($words) <= $limit) {
            return trim(implode(' ', $words));
        }

        $sliced = array_slice($words, 0, $limit);

        return trim(implode(' ', $sliced));
    }

    /**
     * Persist binary image data as an attachment and set it as the post thumbnail.
     *
     * @param int    $postId            Target post identifier.
     * @param string $binary            Raw binary image data.
     * @param string $postTitle         Post title used for attachment naming.
     * @param string $requestedMimeType Requested MIME type.
     * @param string $reportedMimeType  MIME type reported by the AI Client.
     * @return int|false Attachment identifier on success, false on failure.
     */
    private function saveFeaturedImageFromBinary(
        $postId,
        $binary,
        $postTitle,
        $requestedMimeType = '',
        $reportedMimeType = ''
    ) {
        $postId = absint($postId);
        if ($postId < 1 || !is_string($binary) || $binary === '') {
            return false;
        }

        $validation = $this->validateGeneratedImageBinary($binary, $requestedMimeType, $reportedMimeType);
        if (empty($validation['success'])) {
            $this->logImageGenerationDebug(
                $postId,
                'Generated image payload failed MIME or file validation.',
                array(
                    'validation_error' => isset($validation['error']) ? $validation['error'] : 'invalid_image',
                )
            );

            return false;
        }

        if (!empty($validation['mismatch'])) {
            $this->logImageGenerationDebug(
                $postId,
                'Generated image MIME differed from the request or AI Client metadata; the verified file MIME was used.',
                array(
                    'requested_mime_type' => $validation['requested_mime_type'],
                    'reported_mime_type'  => $validation['reported_mime_type'],
                    'actual_mime_type'    => $validation['mime_type'],
                )
            );
        }

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $logger = \ExMomentAuthor\Modules\Log\LogService::getInstance();
                if ($logger instanceof \ExMomentAuthor\Modules\Log\LogService) {
                    $logger->debug(
                        'gpt.image',
                        sprintf('Upload directory unavailable for post %d: %s', $postId, (string) $uploads['error']),
                        array(),
                        $postId
                    );
                }
            }

            return false;
        }

        if (!isset($uploads['path']) || !isset($uploads['url'])) {
            return false;
        }

        if (!file_exists($uploads['path'])) {
            wp_mkdir_p($uploads['path']);
        }

        if (!is_dir($uploads['path']) || !is_writable($uploads['path'])) {
            return false;
        }

        $baseName = sprintf('exmoau-ai-image-%d-%d', $postId, time());
        $sanitizedBase = sanitize_file_name($baseName);
        $filename = wp_unique_filename(
            $uploads['path'],
            $sanitizedBase . '.' . $validation['extension']
        );
        $finalPath = trailingslashit($uploads['path']) . $filename;

        $written = file_put_contents($finalPath, $binary, LOCK_EX);
        if (!is_int($written) || $written !== strlen($binary)) {
            if (file_exists($finalPath)) {
                wp_delete_file($finalPath);
            }

            return false;
        }

        if (file_exists($finalPath)) {
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }
            if (is_object($wp_filesystem) && method_exists($wp_filesystem, 'chmod')) {
                $wp_filesystem->chmod($finalPath, 0644);
            }
        }

        $allowedMimes = array(
            'jpg|jpeg' => 'image/jpeg',
            'webp'     => 'image/webp',
            'png'      => 'image/png',
        );
        $filetype = wp_check_filetype_and_ext($finalPath, $filename, $allowedMimes);
        $verifiedMime = is_array($filetype) && isset($filetype['type'])
            ? strtolower((string) $filetype['type'])
            : '';
        $verifiedExtension = is_array($filetype) && isset($filetype['ext'])
            ? strtolower((string) $filetype['ext'])
            : '';

        if ($verifiedMime !== $validation['mime_type'] || $verifiedExtension !== $validation['extension']) {
            wp_delete_file($finalPath);

            return false;
        }

        $attachment = array(
            'post_mime_type' => $verifiedMime,
            'post_title'     => sanitize_text_field($postTitle !== '' ? $postTitle : basename($finalPath)),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );

        $attachmentId = wp_insert_attachment($attachment, $finalPath, $postId, true);
        if (is_wp_error($attachmentId)) {
            wp_delete_file($finalPath);

            return false;
        }

        if (!defined('ABSPATH')) {
            return false;
        }

        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $metadata = wp_generate_attachment_metadata($attachmentId, $finalPath);
        if (!is_wp_error($metadata) && is_array($metadata)) {
            wp_update_attachment_metadata($attachmentId, $metadata);
        }

        set_post_thumbnail($postId, $attachmentId);

        return (int) $attachmentId;
    }

    /**
     * Validate generated image bytes and derive authoritative attachment metadata.
     *
     * A supported MIME mismatch is accepted to preserve the existing featured-image
     * success path, but callers are told to log it and persist using the verified bytes.
     *
     * @param mixed  $binary            Raw image bytes.
     * @param string $requestedMimeType Requested MIME type.
     * @param string $reportedMimeType  MIME type reported by the AI Client.
     * @return array<string, mixed>
     */
    private function validateGeneratedImageBinary($binary, $requestedMimeType = '', $reportedMimeType = '') {
        if (!is_string($binary) || $binary === '') {
            return array(
                'success' => false,
                'error'   => 'empty_image',
            );
        }

        if (strlen($binary) > self::MAX_AI_IMAGE_FILE_SIZE) {
            return array(
                'success' => false,
                'error'   => 'image_too_large',
            );
        }

        $imageInfo = @getimagesizefromstring($binary);
        $actualMimeType = is_array($imageInfo) && isset($imageInfo['mime'])
            ? strtolower(trim((string) $imageInfo['mime']))
            : '';
        $extensions = array(
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/png'  => 'png',
        );

        if (!isset($extensions[$actualMimeType])) {
            return array(
                'success' => false,
                'error'   => 'unsupported_image_mime',
            );
        }

        $requestedMimeType = strtolower(trim($requestedMimeType));
        $reportedMimeType = strtolower(trim($reportedMimeType));
        $mismatch = ($requestedMimeType !== '' && $requestedMimeType !== $actualMimeType)
            || ($reportedMimeType !== '' && $reportedMimeType !== $actualMimeType);

        return array(
            'success'             => true,
            'error'               => '',
            'mime_type'           => $actualMimeType,
            'extension'           => $extensions[$actualMimeType],
            'requested_mime_type' => $requestedMimeType,
            'reported_mime_type'  => $reportedMimeType,
            'mismatch'            => $mismatch,
        );
    }

    /**
     * Generate legacy function-calling manifests from loaded controllers.
     *
     * Each controller contributes its description and JSON Schema parameters. The resulting array can
     * be passed directly to chatCompletionCreate(). Controllers are trusted code loaded from the
     * filesystem and should ensure their parameter schemas enforce strict validation when executed.
     *
     * @return array<int, array<string, mixed>> List of legacy function definitions.
     * @since 1.1.0
     *
     * Example:
     * ```
     * $functions = $gpt->getAllFunctions();
     * ```
     */
    public function getAllFunctions() {
        $functions = [];

        if (empty($this->controllers)) { return $functions; }

        foreach ($this->controllers as $controllerName => $controller) {

            $function = [
                'name' => $controllerName,
                'description' => $controller->description,
                'parameters' => array_merge(
                    [
                        'type' => 'object',
                    ],
                    $controller->parameters
                ),
            ];

            $functions[] = $function;
        }

        return $functions;
    }

    /**
     * Retrieve a previously autoloaded GPT controller instance by name.
     *
     * The lookup is case-sensitive and limited to controllers discovered during init(). Returns null
     * when the controller is not registered, preventing accidental execution of arbitrary classes.
     *
     * @param string $controllerName Controller identifier matching the filename without extension.
     * @return object|null Controller instance or null when not found.
     * @since 1.1.0
     *
     * Example:
     * ```
     * $controller = $gpt->getController('ImageGenerate');
     * if ($controller === null) {
     *     // Controller not available or blocked by configuration.
     * }
     * ```
     */
    public function getController($controllerName) {
        if (empty($this->controllers[$controllerName])) { return null; }

        return $this->controllers[$controllerName];
    }

    /**
     * Resolve the single internal AI service from the core module container.
     *
     * @return AiService
     */
    private function resolveAiService() {
        if ($this->aiService instanceof AiService) {
            return $this->aiService;
        }

        $core = ExMomentAuthorCoreSystem::getInstance();
        $service = $core->getModule('AiService');

        if (!($service instanceof AiService) && method_exists($core, 'autoload')) {
            $core->autoload();
            $service = $core->getModule('AiService');
        }

        if (!($service instanceof AiService)) {
            $service = new AiService();
        }

        $this->aiService = $service;

        return $this->aiService;
    }

    /**
     * Execute a GPT function controller using decoded JSON arguments.
     *
     * Accepts a trusted controller instance and a JSON-encoded argument map supplied by an AI model. The
     * JSON payload is decoded without additional sanitisation, so upstream code must validate content
     * before persistence or rendering. Controllers are expected to perform capability and input
     * checks internally and may return \WP_Error on failure.
     *
     * @param object $controller    Controller instance exposing a work() method.
     * @param string $argumentsJson JSON-encoded argument payload provided by an AI model.
     * @return mixed|\WP_Error Controller return value, often an array, string result, or WP_Error from work().
     * @since 1.1.0
     *
     * Example:
     * ```
     * $controller = $gpt->getController('Outline');
     * $result = $gpt->callFunction($controller, wp_json_encode(['topic' => 'Security best practices']));
     * if (is_wp_error($result)) {
     *     // Bubble up the error message to the UI after escaping.
     * }
     * ```
     */
    public function callFunction($controller, $argumentsJson) {
        $arguments = json_decode($argumentsJson, true);
        return $controller->work($arguments);
    }
}
