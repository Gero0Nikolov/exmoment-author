<?php

namespace ExMomentAuthor\Modules\Gpt;

use GuzzleHttp\Exception\RequestException;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use \OpenAI\OpenAI;

use ExMomentAuthor\Modules\Settings\SettingsController;
use WP_Post;

class GptController {

    public static $config;
    public static $roles;

    public $user;
    public $controllers;

    private static $weightsMap;
    private $client;

    /**
     * Captures diagnostics for the most recent chat completion request.
     *
     * @var array<string, mixed>|null
     */
    private $lastChatCompletionDiagnostics;

    /**
     * Cached OpenAI model metadata for the current process.
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
    private const DEFAULT_MODEL_ID = 'gpt-5-nano';

    /**
     * Transient key used to persist the OpenAI model list between requests.
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
     * Retrieve sanitized image generation settings from persisted configuration.
     *
     * @return array{model: string, style_prompt: string, dimensions: string, enabled: bool}
     */
    private function getImageGenerationSettings() {
        $model = SettingsController::getAiImageModel();
        $stylePrompt = SettingsController::getAiImageStylePrompt();
        $dimensions = SettingsController::getAiImageDimensions();
        $enabled = SettingsController::isAiImageGenerationEnabled();

        $model = ($model !== '' ? $model : SettingsController::getDefaultAiImageModel());
        $dimensions = ($dimensions !== '' ? $dimensions : SettingsController::getDefaultAiImageDimensions());

        return [
            'model' => $model,
            'style_prompt' => $stylePrompt,
            'dimensions' => $dimensions,
            'enabled' => $enabled,
        ];
    }

    /**
     * Bootstraps the GPT module, loads function controllers, and prepares the OpenAI client.
     *
     * The constructor merges the provided configuration with module defaults, prepares the
     * controller registry, and immediately initialises the OpenAI PHP SDK using the stored API
     * key. The API key is read from the secure settings store via SettingsController and is never
     * logged or echoed. Controller classes are autoloaded from the configured directory while
     * ensuring restricted files are ignored.
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
     * Initialise module services, autoload function controllers, and configure the OpenAI client.
     *
     * This method loads controller classes from disk and instantiates the OpenAI SDK with the API
     * key stored via SettingsController. It configures a custom Guzzle client and stream handler but
     * does not dispatch any HTTP requests by itself. The API key is never persisted, and failures to
     * load controllers are logged only when WP_DEBUG is enabled.
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

        $apiKey = SettingsController::getOption('openai_api_key');

        $this->configureClient($apiKey);
    }

    /**
     * Update the configured API key for the OpenAI client at runtime.
     *
     * Allows callers to provide a fresh credential without creating a new controller instance.
     * Debug mode short-circuits to avoid mutating the client while bypassing API calls.
     *
     * @param string $apiKey Sanitised OpenAI API key.
     * @return bool True when the client was configured successfully or debug mode short-circuits.
     */
    public function setApiKey($apiKey) {
        if ($this->isDebugModeEnabled()) {
            $this->logDebugBypass('api_key_override');

            return true;
        }

        return $this->configureClient($apiKey);
    }

    /**
     * Configure the OpenAI client using the supplied API key.
     *
     * This prepares the SDK for subsequent remote calls but does not make an HTTP request. Passing
     * an empty API key clears the client reference to prevent accidental network traffic.
     *
     * @param string $apiKey Potentially untrimmed API key value.
     * @return bool True when the client is ready for use; false when no valid key is provided or an error occurs.
     */
    private function configureClient($apiKey) {
        $apiKey = (is_string($apiKey) ? trim($apiKey) : '');

        if ($apiKey === '') {
            $this->client = null;

            return false;
        }

        try {
            $this->client = \OpenAI::factory()
                ->withApiKey($apiKey)
                // ->withOrganization('your-organization') // default: null
                // ->withProject('ExMomentAuthor') // default: null
                ->withBaseUri('api.openai.com/v1') // default: api.openai.com/v1
                ->withHttpClient($httpClient = new \GuzzleHttp\Client([])) // default: HTTP client found using PSR-18 HTTP Client Discovery
                // ->withHttpHeader('X-My-Header', 'foo')
                // ->withQueryParam('my-param', 'bar')
                ->withStreamHandler(fn (RequestInterface $request): ResponseInterface => $httpClient->send($request, [
                    'stream' => false // Allows to provide a custom stream handler for the http client.
                ]))
                ->make();
        } catch (\Throwable $exception) {
            $this->client = null;

            if (defined('WP_DEBUG') && WP_DEBUG) {
                $logger = \ExMomentAuthor\Modules\Log\LogService::getInstance();
                if ($logger instanceof \ExMomentAuthor\Modules\Log\LogService) {
                    $logger->debug('gpt.client', sprintf('Failed to configure GPT client: %s', $exception->getMessage()), [], 0);
                }
            }

            return false;
        }

        return true;
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
        $defaultWeightKey = SettingsController::getDefaultOpenAiWeightKey();

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
     * @return object Generic response object mirroring the shape of OpenAI\Responses\Chat\CreateResponse.
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
     * Returns cached model metadata enriched with any mandatory identifiers supplied via
     * $ensureModelIds. The method first checks a runtime cache, then a WordPress transient, and
     * finally the OpenAI models API when necessary. API failures fall back to a deterministic
     * default list to keep the UI responsive. External requests honour the SDK's configured
     * timeouts; transient storage expires after five minutes. Debug mode bypasses the remote call
     * and immediately returns the fallback list merged with required identifiers.
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
        $ensureModelIds[] = self::DEFAULT_MODEL_ID;
        $ensureModelIds = $this->normalizeModelIdList($ensureModelIds);

        if ($this->isDebugModeEnabled()) {
            $this->logDebugBypass('model_listing');

            return $this->buildFallbackModelList($ensureModelIds);
        }

        $cachedModels = $this->getCachedModelList();

        if (null === $cachedModels) {
            $fetchedModels = $this->fetchAllGptModels();

            if ($fetchedModels !== []) {
                $this->setCachedModelList($fetchedModels);
                $cachedModels = $fetchedModels;
            } else {
                self::$modelListCache = [];
                self::$modelListCacheInitialised = true;
                $cachedModels = [];
            }
        }

        if ($cachedModels === []) {
            return $this->buildFallbackModelList($ensureModelIds);
        }

        return $this->mergeModelListWithEnsures($cachedModels, $ensureModelIds);
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
        $preferredOrder = [
            self::DEFAULT_MODEL_ID,
            'gpt-5-mini',
            'gpt-5-nano',
        ];

        $gptModels = $this->getAllGptModels($preferredOrder);
        $availableIds = array_map(
            static function ($model) {
                return (is_array($model) && isset($model['id'])) ? $model['id'] : '';
            },
            $gptModels
        );

        foreach ($preferredOrder as $modelId) {
            if (in_array($modelId, $availableIds, true)) {
                return $modelId;
            }
        }

        foreach ($availableIds as $modelId) {
            if ($modelId !== '') {
                return $modelId;
            }
        }

        return self::DEFAULT_MODEL_ID;
    }

    /**
     * Create a legacy text completion using the deprecated Completions API.
     *
     * Selects a max token count from the internal weight map and issues a synchronous OpenAI
     * completions request. This method is maintained for backward compatibility and should only be
     * invoked with sanitised prompts; sensitive data is transmitted over TLS to api.openai.com. When
     * debug mode is active the remote call is bypassed and a deterministic payload is returned.
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

        $result = $this->client->completions()->create([
            'model' => 'text-davinci-003',
            'prompt' => $prompt,
            'max_tokens' => $maxTokens,
        ]);

        return ([
            'generated_text' => $result['choices'][0]['text']
        ]);
    }

    /**
     * Dispatch a Chat Completions request with optional function-calling definitions.
     *
     * Validates token weights, message payload shape, and model identifier before calling the OpenAI
     * Chat API. Diagnostics such as HTTP status, request id, and timing are captured for later
     * inspection. API errors return the exception message string while setting diagnostics; runtime
     * weight or payload validation returns false. Messages should be pre-sanitised and should not
     * include unescaped user HTML. Debug mode bypasses the remote call and returns a deterministic
     * stub response while still clearing diagnostics.
     *
     * @param array<int, array<string, mixed>> $messages Conversation history in OpenAI format. Each
     *                                                  message should include `role` and `content`
     *                                                  keys with validated values.
     * @param int|string                        $weight   Token weight key mapped to internal max token allowances.
     * @param array<int, array<string, mixed>>  $functions Optional function definitions to expose.
     * @param string|null                       $modelId  Preferred model identifier; falls back to getLatestGptModel() when empty.
     * @return \OpenAI\Responses\Chat\CreateResponse|object|string|false Response object on success, debug stub when bypassed, false on validation failure, or error message string on API errors.
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

        if (!is_string($modelId) || $modelId === '') {
            $modelId = $this->getLatestGptModel();
        }

        $attemptedModelId = $modelId;

        $args = [
            'model' => $modelId,
            'messages' => $messages,
            // 'max_tokens' => $maxTokens,
            'max_completion_tokens' => $maxTokens,
            'user' => $this->user,
            // 'temperature' => self::$config['temperature'],
        ];

        if (!empty($functions)) {
            $args['functions'] = $functions;
        }

        try {
            $result = $this->client->chat()->create($args);
        } catch (ErrorException $exception) {
            $this->setChatCompletionDiagnostics(
                $this->buildChatCompletionDiagnostics([
                    'http_status'   => $exception->getStatusCode(),
                    'error_type'    => $exception->getErrorType() ?: 'api_error',
                    'error_code'    => $exception->getErrorCode(),
                    'error_message' => $exception->getErrorMessage(),
                    'request_id'    => $exception->response->getHeaderLine('x-request-id'),
                ],
                $attemptedModelId,
                $startTime)
            );

            return $exception->getMessage();
        } catch (TransporterException $exception) {
            $this->setChatCompletionDiagnostics(
                $this->buildChatCompletionDiagnostics(
                    $this->buildTransporterDiagnostics($exception),
                    $attemptedModelId,
                    $startTime
                )
            );

            return $exception->getMessage();
        } catch (\Throwable $exception) {
            $this->setChatCompletionDiagnostics(
                $this->buildChatCompletionDiagnostics([
                    'error_type'    => 'runtime_exception',
                    'error_message' => $exception->getMessage(),
                ],
                $attemptedModelId,
                $startTime)
            );

            return $exception->getMessage();
        }

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
            'http_status'   => null,
            'error_type'    => 'unknown_error',
            'error_code'    => null,
            'error_message' => '',
            'request_id'    => '',
            'model_attempted' => self::normalizeModelId($modelId),
            'timing_ms'     => $this->calculateTimingMilliseconds($startTime),
            'usage'         => [
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
        }

        if (!empty($diagnostics['error_message'])) {
            $normalized['error_message'] = $this->sanitizeDiagnosticMessage($diagnostics['error_message']);
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
     * Produce diagnostics for transporter-level exceptions.
     *
     * @param TransporterException $exception Transport-level exception instance.
     * @return array<string, mixed> Sanitised subset of HTTP and request metadata.
     */
    private function buildTransporterDiagnostics(TransporterException $exception) {
        $diagnostics = [
            'error_type'    => 'transport_error',
            'error_message' => $exception->getMessage(),
        ];

        $previous = $exception->getPrevious();

        if ($previous instanceof RequestException) {
            $response = $previous->getResponse();

            if ($response) {
                $diagnostics['http_status'] = $response->getStatusCode();
                $diagnostics['request_id'] = $response->getHeaderLine('x-request-id');
            }
        }

        return $diagnostics;
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
     * Remove cached OpenAI model metadata for both the current request and persistent storage.
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
     * Retrieve the cached OpenAI model list when available.
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
     * Persist the fetched OpenAI model list in the runtime and transient caches.
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
     * Fetch all available OpenAI models via the API client.
     *
     * Performs a remote request to api.openai.com when a client is configured and debug mode is
     * disabled; otherwise returns an empty array so callers can fall back to cached or static data.
     *
     * @return array<int, array{id: string, name: string}> Normalised list of models retrieved from the API or empty on failure.
     */
    private function fetchAllGptModels() {
        if (empty($this->client)) {
            return [];
        }

        if ($this->isDebugModeEnabled()) {
            $this->logDebugBypass('model_fetch');

            return [];
        }

        try {
            $response = $this->client->models()->list();
        } catch (\Throwable $exception) {
            return [];
        }

        if (!is_object($response) || !isset($response->data) || !is_iterable($response->data)) {
            return [];
        }

        $models = [];

        foreach ($response->data as $result) {
            if (!is_object($result)) {
                continue;
            }

            if (isset($result->object) && 'model' !== $result->object) {
                continue;
            }

            $modelId = '';

            if (isset($result->id)) {
                $modelId = (string) $result->id;
            }

            $normalizedId = self::normalizeModelId($modelId);

            if ($normalizedId === '') {
                continue;
            }

            $modelName = '';

            if (isset($result->name) && is_string($result->name)) {
                $modelName = $result->name;
            } elseif (isset($result->displayName) && is_string($result->displayName)) {
                $modelName = $result->displayName;
            } elseif (isset($result->display_name) && is_string($result->display_name)) {
                $modelName = $result->display_name;
            }

            $models[$normalizedId] = [
                'id'   => $normalizedId,
                'name' => $this->sanitizeModelName($modelName, $normalizedId),
            ];
        }

        return $this->sortModelList($models);
    }

    /**
     * Produce a fallback model list when OpenAI cannot be reached.
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

        return $this->sortModelList($models);
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

        return $this->sortModelList($normalized);
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
     * Sort model entries prioritising GPT-5 family identifiers.
     *
     * GPT-5 models (ids beginning with `gpt-5`) are listed first. Within the GPT-5
     * group, numeric variants (for example `gpt-5.2`, `gpt-5.1`, `gpt-5`) are ordered
     * from newest to oldest by their parsed version number. Named variants that do not
     * expose a numeric suffix (for example `gpt-5o`, `gpt-5-mini`) fall back to
     * descending lexicographical ordering by id to maintain deterministic behaviour.
     * All remaining models are sorted alphabetically (case-insensitive) by their name,
     * using the id as a tie-breaker.
     *
     * @param array<int|string, array{id: string, name: string}> $models Model entries keyed by identifier.
     * @return array<int, array{id: string, name: string}>
     */
    private function sortModelList(array $models) {
        $list = array_values($models);

        usort(
            $list,
            static function ($a, $b) {
                $idA = (is_array($a) && isset($a['id']) && is_string($a['id'])) ? $a['id'] : '';
                $idB = (is_array($b) && isset($b['id']) && is_string($b['id'])) ? $b['id'] : '';
                $nameA = (is_array($a) && isset($a['name']) && is_string($a['name'])) ? $a['name'] : '';
                $nameB = (is_array($b) && isset($b['name']) && is_string($b['name'])) ? $b['name'] : '';

                $isGpt5A = self::isGpt5Model($idA);
                $isGpt5B = self::isGpt5Model($idB);

                if ($isGpt5A && !$isGpt5B) {
                    return -1;
                }

                if (!$isGpt5A && $isGpt5B) {
                    return 1;
                }

                if ($isGpt5A && $isGpt5B) {
                    $versionA = self::parseGpt5NumericVersion($idA);
                    $versionB = self::parseGpt5NumericVersion($idB);

                    if (null === $versionA && null !== $versionB) {
                        return 1;
                    }

                    if (null !== $versionA && null === $versionB) {
                        return -1;
                    }

                    if (null !== $versionA && null !== $versionB) {
                        $maxLength = max(count($versionA), count($versionB));

                        for ($index = 0; $index < $maxLength; $index++) {
                            $partA = $versionA[$index] ?? 0;
                            $partB = $versionB[$index] ?? 0;

                            if ($partA === $partB) {
                                continue;
                            }

                            return ($partA > $partB) ? -1 : 1;
                        }
                    }

                    return strcmp($idB, $idA);
                }

                $normalizedNameA = strtolower($nameA);
                $normalizedNameB = strtolower($nameB);

                if ($normalizedNameA === $normalizedNameB) {
                    return strcmp($idA, $idB);
                }

                return strcmp($normalizedNameA, $normalizedNameB);
            }
        );

        return $list;
    }

    /**
     * Determine whether the provided model identifier is part of the GPT-5 family.
     *
     * @param string $modelId Model identifier.
     * @return bool
     */
    private static function isGpt5Model($modelId) {
        if (!is_string($modelId) || $modelId === '') {
            return false;
        }

        return strpos($modelId, 'gpt-5') === 0;
    }

    /**
     * Extract numeric GPT-5 version components for comparison.
     *
     * @param string $modelId Model identifier.
     * @return array<int, int>|null
     */
    private static function parseGpt5NumericVersion($modelId) {
        if ('gpt-5' === $modelId) {
            return [5, 0];
        }

        if (preg_match('/^gpt-5[\.-]?([0-9]+(?:\.[0-9]+)*)$/', $modelId, $matches) !== 1) {
            return null;
        }

        $parts = explode('.', $matches[1]);
        $parts = array_map('intval', $parts);

        array_unshift($parts, 5);

        return $parts;
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
     * The method builds a concise prompt from the first ~30 words of the post content, requests a
     * single GPT Image generation using the configured OpenAI client, stores it under the uploads
     * directory as WebP when supported, and assigns the resulting attachment as the post thumbnail.
     * When a selected GPT image model is unavailable, the request can retry once with the legacy
     * `dall-e-3` fallback. Execution is skipped when the post already has a featured image, the
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

        $prompt = $this->buildImagePromptForPost($post, $imageSettings['style_prompt']);
        if ($prompt === '') {
            return [
                'success' => false,
                'error' => 'prompt_unavailable',
            ];
        }

        if (!is_object($this->client)) {
            return [
                'success' => false,
                'error' => 'client_unavailable',
            ];
        }

        $model = apply_filters('exmoau_ai_image_model', $imageSettings['model'], $postId);
        $model = SettingsController::normalizeAiImageModelSelection($model, $imageSettings['model']);
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

        $this->logImageGenerationDebug(
            $postId,
            'Preparing AI image generation request.',
            array(
                'selected_model' => $model,
                'prompt_length' => strlen($prompt),
                'requested_size' => $size,
                'debug_mode' => false,
            )
        );

        $requestArgs = $this->buildImageGenerationRequestArgs($model, $prompt, $size);

        try {
            $this->logImageGenerationDebug(
                $postId,
                'Attempting OpenAI image generation request.',
                array(
                    'selected_model' => $model,
                    'requested_size' => $size,
                    'request_attempted' => true,
                )
            );

            $response = $this->client->images()->create($requestArgs);
        } catch (ErrorException $exception) {
            $this->logImageGenerationDebug(
                $postId,
                'OpenAI image generation request failed.',
                $this->buildImageGenerationErrorContext($exception, $model, false)
            );

            if ($this->shouldRetryLegacyImageModel($exception, $model)) {
                $requestArgs = $this->buildImageGenerationRequestArgs('dall-e-3', $prompt, $size);

                $this->logImageGenerationDebug(
                    $postId,
                    'Retrying OpenAI image generation with legacy fallback model.',
                    array(
                        'selected_model' => $model,
                        'fallback_model' => 'dall-e-3',
                        'requested_size' => $size,
                        'request_attempted' => true,
                    )
                );

                try {
                    $response = $this->client->images()->create($requestArgs);
                } catch (ErrorException $retryException) {
                    $this->logImageGenerationDebug(
                        $postId,
                        'Legacy fallback image generation request failed.',
                        $this->buildImageGenerationErrorContext($retryException, 'dall-e-3', true)
                    );

                    return array(
                        'success' => false,
                        'error' => 'api_error',
                    );
                }
            } else {
                return array(
                    'success' => false,
                    'error' => 'api_error',
                );
            }
        } catch (TransporterException $exception) {
            $this->logImageGenerationDebug(
                $postId,
                'OpenAI image transport error encountered.',
                array(
                    'selected_model' => $model,
                    'requested_size' => $size,
                    'error_message' => $this->sanitizeImageGenerationLogValue($exception->getMessage()),
                )
            );

            return [
                'success' => false,
                'error' => 'transport_error',
            ];
        } catch (\Throwable $exception) {
            $this->logImageGenerationDebug(
                $postId,
                'Unexpected image generation runtime error encountered.',
                array(
                    'selected_model' => $model,
                    'requested_size' => $size,
                    'error_message' => $this->sanitizeImageGenerationLogValue($exception->getMessage()),
                )
            );

            return [
                'success' => false,
                'error' => 'runtime_error',
            ];
        }

        $normalizedPayload = $this->normalizeGeneratedImagePayload($response);

        $this->logImageGenerationDebug(
            $postId,
            'Received OpenAI image generation response.',
            array(
                'selected_model' => $model,
                'response_contains_data' => $normalizedPayload['has_data'],
                'response_item_count' => $normalizedPayload['item_count'],
                'first_item_has_url' => $normalizedPayload['has_url'],
                'first_item_has_b64' => $normalizedPayload['has_b64'],
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

            $attachmentId = $this->saveFeaturedImageFromBinary($postId, $binary, $post->post_title);
        } elseif ($normalizedPayload['type'] === 'url') {
            $attachmentId = $this->saveFeaturedImageFromUrl($postId, $normalizedPayload['value'], $post->post_title);
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
     * Build the request payload for OpenAI image generation.
     *
     * GPT Image models in the current API return base64 payloads without
     * requiring `response_format`, and sending that parameter now raises an
     * `unknown_parameter` error. Keep the payload minimal and model-agnostic.
     *
     * @param string $model  Selected image model identifier.
     * @param string $prompt Sanitized prompt text.
     * @param string $size   Allowed image dimensions preset.
     * @return array<string, mixed>
     */
    private function buildImageGenerationRequestArgs($model, $prompt, $size) {
        return array(
            'model' => (string) $model,
            'prompt' => (string) $prompt,
            'n' => 1,
            'size' => (string) $size,
        );
    }

    /**
     * Normalize the first usable generated image item from the OpenAI response.
     *
     * Supports both base64-style and URL-style payloads so the storage layer
     * can handle GPT Image and legacy image responses centrally.
     *
     * @param mixed $response Image generation response object.
     * @return array{has_data: bool, item_count: int, has_url: bool, has_b64: bool, type: string, value: string}
     */
    private function normalizeGeneratedImagePayload($response) {
        $normalized = array(
            'has_data' => false,
            'item_count' => 0,
            'has_url' => false,
            'has_b64' => false,
            'type' => '',
            'value' => '',
        );

        if (!is_object($response) || !isset($response->data) || !is_array($response->data)) {
            return $normalized;
        }

        $normalized['item_count'] = count($response->data);

        if (empty($response->data[0]) || !is_object($response->data[0])) {
            return $normalized;
        }

        $first = $response->data[0];
        $normalized['has_data'] = true;

        if (isset($first->url) && is_string($first->url)) {
            $url = trim($first->url);

            if ($url !== '') {
                $normalized['has_url'] = true;
                $normalized['type'] = 'url';
                $normalized['value'] = $url;
            }
        }

        if (isset($first->b64_json) && is_string($first->b64_json)) {
            $b64 = trim($first->b64_json);

            if ($b64 !== '') {
                $normalized['has_b64'] = true;

                if ($normalized['type'] === '') {
                    $normalized['type'] = 'b64';
                    $normalized['value'] = $b64;
                }
            }
        }

        return $normalized;
    }

    /**
     * Build a sanitized logging context for OpenAI image request failures.
     *
     * @param ErrorException $exception     API exception raised by the OpenAI client.
     * @param string         $model         Model used for the failed request.
     * @param bool           $usedFallback  Whether this context belongs to the legacy fallback attempt.
     * @return array<string, mixed>
     */
    private function buildImageGenerationErrorContext(ErrorException $exception, $model, $usedFallback) {
        return array(
            'selected_model' => (string) $model,
            'used_legacy_fallback' => ($usedFallback === true),
            'error_code' => $this->sanitizeImageGenerationLogValue($exception->getErrorCode()),
            'error_type' => $this->sanitizeImageGenerationLogValue($exception->getErrorType()),
            'error_message' => $this->sanitizeImageGenerationLogValue($exception->getErrorMessage()),
        );
    }

    /**
     * Persist a generated image from a remote URL as a WordPress attachment.
     *
     * @param int    $postId    Target post identifier.
     * @param string $url       Remote image URL returned by the API.
     * @param string $postTitle Post title used for attachment naming.
     * @return int|false
     */
    private function saveFeaturedImageFromUrl($postId, $url, $postTitle) {
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

        $binary = file_get_contents($temporaryFile);

        if (file_exists($temporaryFile)) {
            wp_delete_file($temporaryFile);
        }

        if (!is_string($binary) || $binary === '') {
            return false;
        }

        return $this->saveFeaturedImageFromBinary($postId, $binary, $postTitle);
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
     * Determine whether a model-specific image generation error should retry with the legacy fallback.
     *
     * @param ErrorException $exception OpenAI API exception thrown by the image request.
     * @param string         $model     Model attempted for the original request.
     * @return bool
     */
    private function shouldRetryLegacyImageModel(ErrorException $exception, $model) {
        if (!is_string($model) || $model === 'dall-e-3') {
            return false;
        }

        $errorCode = strtolower((string) $exception->getErrorCode());
        $errorType = strtolower((string) $exception->getErrorType());
        $message = strtolower((string) $exception->getErrorMessage());

        $retrySignals = array(
            'model_not_found',
            'unsupported_model',
            'invalid_model',
        );

        foreach ($retrySignals as $signal) {
            if ($signal === $errorCode || $signal === $errorType || false !== strpos($message, $signal)) {
                return true;
            }
        }

        return (
            false !== strpos($message, 'model')
            && (
                false !== strpos($message, 'not found')
                || false !== strpos($message, 'unsupported')
                || false !== strpos($message, 'not available')
                || false !== strpos($message, 'does not exist')
            )
        );
    }

    /**
     * Build a concise AI image prompt from post content.
     *
     * @param WP_Post $post Target post object.
     * @return string Sanitized prompt string.
     */
    private function buildImagePromptForPost(WP_Post $post, $stylePrompt = '') {
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
     * @param int    $postId    Target post identifier.
     * @param string $binary    Raw binary image data.
     * @param string $postTitle Post title used for attachment naming.
     * @return int|false Attachment identifier on success, false on failure.
     */
    private function saveFeaturedImageFromBinary($postId, $binary, $postTitle) {
        $postId = absint($postId);
        if ($postId < 1 || !is_string($binary) || $binary === '') {
            return false;
        }

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $logger = \ExMomentAuthor\Modules\Log\LogService::getInstance();
                if ($logger instanceof \ExMomentAuthor\Modules\Log\LogService) {
                    $logger->debug('gpt.image', sprintf('Upload directory unavailable for post %d: %s', $postId, (string) $uploads['error']), [], $postId);
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

        $baseName = sprintf('exmoau-ai-image-%d-%d', $postId, time());
        $sanitizedBase = sanitize_file_name($baseName);
        $initialFilename = wp_unique_filename($uploads['path'], $sanitizedBase . '.png');
        $initialPath = trailingslashit($uploads['path']) . $initialFilename;

        $written = file_put_contents($initialPath, $binary, LOCK_EX);
        if ($written === false) {
            return false;
        }

        $finalPath = $initialPath;
        $finalMime = 'image/png';

        $editor = wp_get_image_editor($initialPath);
        if (!is_wp_error($editor)) {
            $webpFilename = pathinfo($initialFilename, PATHINFO_FILENAME) . '.webp';
            $webpFilename = wp_unique_filename($uploads['path'], sanitize_file_name($webpFilename));
            $webpPath = trailingslashit($uploads['path']) . $webpFilename;
            $saved = $editor->save($webpPath, 'image/webp');

            if (!is_wp_error($saved) && isset($saved['path']) && is_string($saved['path']) && $saved['path'] !== '') {
                $finalPath = $saved['path'];
                $finalMime = isset($saved['mime-type']) ? $saved['mime-type'] : 'image/webp';

                if ($finalPath !== $initialPath && file_exists($initialPath)) {
                    wp_delete_file($initialPath);
                }
            }
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

        $filetype = wp_check_filetype($finalPath, null);
        $mime = is_array($filetype) && isset($filetype['type']) && $filetype['type'] !== ''
            ? $filetype['type']
            : $finalMime;

        $attachment = [
            'post_mime_type' => $mime,
            'post_title' => sanitize_text_field($postTitle !== '' ? $postTitle : basename($finalPath)),
            'post_content' => '',
            'post_status' => 'inherit',
        ];

        $attachmentId = wp_insert_attachment($attachment, $finalPath, $postId, true);
        if (is_wp_error($attachmentId)) {
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
     * Generate OpenAI function-calling manifests from loaded controllers.
     *
     * Each controller contributes its description and JSON Schema parameters. The resulting array can
     * be passed directly to chatCompletionCreate(). Controllers are trusted code loaded from the
     * filesystem and should ensure their parameter schemas enforce strict validation when executed.
     *
     * @return array<int, array<string, mixed>> List of function definitions compatible with OpenAI.
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
     * Execute a GPT function controller using decoded JSON arguments.
     *
     * Accepts a trusted controller instance and a JSON-encoded argument map supplied by OpenAI. The
     * JSON payload is decoded without additional sanitisation, so upstream code must validate content
     * before persistence or rendering. Controllers are expected to perform capability and input
     * checks internally and may return \WP_Error on failure.
     *
     * @param object $controller    Controller instance exposing a work() method.
     * @param string $argumentsJson JSON-encoded argument payload provided by OpenAI.
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
