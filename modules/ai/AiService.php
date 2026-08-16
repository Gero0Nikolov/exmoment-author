<?php

namespace ExMomentAuthor\Modules\Ai;

if (!defined('ABSPATH')) {
    exit;
}

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WP_Error;

/**
 * Provider-agnostic gateway for every ExMoment Author AI request.
 */
class AiService {

    /**
     * Test whether the WordPress 7.0 AI Client is available for this request.
     *
     * @return bool
     */
    public function isAvailable() {
        return function_exists('wp_ai_client_prompt')
            && function_exists('wp_supports_ai')
            && wp_supports_ai()
            && class_exists(AiClient::class);
    }

    /**
     * Discover configured providers and models for a generation capability.
     *
     * @param string $capability Either text or image.
     * @return array<int, array<string, mixed>>
     */
    public function discover($capability = 'text') {
        if (!$this->isAvailable()) {
            return array();
        }

        try {
            $registry = AiClient::defaultRegistry();
            $requirements = new ModelRequirements(
                array($this->getCapability($capability)),
                array()
            );
            $matches = $registry->findModelsMetadataForSupport($requirements);
        } catch (\Throwable $exception) {
            $this->logDebug('ai.discovery', $exception->getMessage(), array('capability' => $capability));

            return array();
        }

        $providers = array();

        foreach ($matches as $providerModels) {
            $provider = $providerModels->getProvider();
            $providerId = sanitize_key($provider->getId());

            if ($providerId === '') {
                continue;
            }

            $models = array();
            foreach ($providerModels->getModels() as $model) {
                $modelId = $this->sanitizeIdentifier($model->getId());

                if ($modelId === '') {
                    continue;
                }

                $models[] = array(
                    'id'   => $modelId,
                    'name' => sanitize_text_field($model->getName()),
                );
            }

            $providers[] = array(
                'id'         => $providerId,
                'name'       => sanitize_text_field($provider->getName()),
                'configured' => $registry->isProviderConfigured($providerId),
                'models'     => $models,
            );
        }

        $knownProviderIds = array_column($providers, 'id');
        foreach ($registry->getRegisteredProviderIds() as $providerId) {
            $providerId = sanitize_key($providerId);

            if ($providerId === '' || in_array($providerId, $knownProviderIds, true)) {
                continue;
            }

            try {
                $providerClass = $registry->getProviderClassName($providerId);
                $metadata = $providerClass::metadata();
                $providers[] = array(
                    'id'         => $providerId,
                    'name'       => sanitize_text_field($metadata->getName()),
                    'configured' => $registry->isProviderConfigured($providerId),
                    'models'     => array(),
                );
            } catch (\Throwable $exception) {
                $this->logDebug('ai.discovery', $exception->getMessage(), array('provider' => $providerId));
            }
        }

        return $providers;
    }

    /**
     * Return a status payload suitable for generation guards and the admin UI.
     *
     * @param string $providerId Optional provider preference.
     * @param string $modelId Optional model preference.
     * @param string $capability Either text or image.
     * @return array<string, mixed>
     */
    public function getStatus($providerId = '', $modelId = '', $capability = 'text') {
        $providerId = sanitize_key((string) $providerId);
        $modelId = $this->sanitizeIdentifier($modelId);
        $providers = $this->discover($capability);
        $selection = $this->resolveSelection($providers, $providerId, $modelId);

        return array(
            'client_available'     => $this->isAvailable(),
            'providers'            => $providers,
            'provider_available'   => !empty($providers),
            'provider_configured'  => !empty($selection['provider']),
            'selected_provider'    => $selection['provider'],
            'selected_model'       => $selection['model'],
            'requested_provider'   => $providerId,
            'requested_model'      => $modelId,
            'connection_status'    => $this->getConnectionStatus($providers, $selection, $providerId, $modelId),
        );
    }

    /**
     * Generate text through the WordPress AI Client.
     *
     * @param mixed  $prompt Prompt string or WordPress AI Client message array.
     * @param array  $options Provider, model, system instruction, and token limit.
     * @return array<string, mixed>
     */
    public function generateText($prompt, array $options = array()) {
        $startedAt = microtime(true);
        $providerId = sanitize_key((string) ($options['provider'] ?? ''));
        $modelId = $this->sanitizeIdentifier($options['model'] ?? '');
        $status = $this->getStatus($providerId, $modelId, 'text');
        $status['operation'] = 'text_generation';
        $status['capability'] = 'text_generation';

        if ($status['connection_status'] !== 'connected') {
            return $this->failure($status['connection_status'], $status, $startedAt);
        }

        try {
            $builder = wp_ai_client_prompt($prompt);
            $builder = $this->applySelection($builder, $status);

            $systemInstruction = isset($options['system_instruction']) && is_string($options['system_instruction'])
                ? trim($options['system_instruction'])
                : '';
            if ($systemInstruction !== '') {
                $builder->using_system_instruction($systemInstruction);
            }

            $maxTokens = isset($options['max_tokens']) ? absint($options['max_tokens']) : 0;
            if ($maxTokens > 0) {
                $builder->using_max_tokens($maxTokens);
            }

            if (isset($options['temperature']) && is_numeric($options['temperature'])) {
                $builder->using_temperature((float) $options['temperature']);
            }

            if (!$builder->is_supported_for_text_generation()) {
                $text = $builder->generate_text();

                if (!is_wp_error($text)) {
                    return $this->failure('unsupported_capability', $status, $startedAt);
                }
            } else {
                $text = $builder->generate_text();
            }
        } catch (\Throwable $exception) {
            return $this->failure(
                $this->classifyThrowable($exception),
                $status,
                $startedAt,
                $exception->getMessage(),
                array('exception_class' => get_class($exception))
            );
        }

        if (is_wp_error($text)) {
            return $this->failureFromWpError($text, $status, $startedAt);
        }

        $text = is_string($text) ? trim($text) : '';
        if ($text === '') {
            return $this->failure('empty_response', $status, $startedAt);
        }

        return array(
            'success'     => true,
            'text'        => $text,
            'provider'    => $status['selected_provider'],
            'model'       => $status['selected_model'],
            'timing_ms'   => $this->getTiming($startedAt),
            'diagnostics' => null,
        );
    }

    /**
     * Generate an image through the WordPress AI Client.
     *
     * @param string $prompt Sanitized image prompt.
     * @param array  $options Provider, model, aspect ratio, and output format preferences.
     * @return array<string, mixed>
     */
    public function generateImage($prompt, array $options = array()) {
        $startedAt = microtime(true);
        $providerId = sanitize_key((string) ($options['provider'] ?? ''));
        $modelId = $this->sanitizeIdentifier($options['model'] ?? '');
        $status = $this->getStatus($providerId, $modelId, 'image');
        $status['operation'] = 'image_generation';
        $status['capability'] = 'image_generation';
        $hasRequestedFormat = array_key_exists('format', $options);
        $outputMimeType = $this->normalizeImageOutputMimeType($options['format'] ?? '');

        if ($status['connection_status'] !== 'connected') {
            return $this->failure($status['connection_status'], $status, $startedAt);
        }

        if ($hasRequestedFormat && $outputMimeType === '') {
            return $this->failure('invalid_request', $status, $startedAt, 'Invalid image output format requested.');
        }

        try {
            $builder = wp_ai_client_prompt((string) $prompt);
            $builder = $this->applySelection($builder, $status);

            $aspectRatio = $this->normalizeAspectRatio($options['dimensions'] ?? '');
            if ($aspectRatio !== '') {
                $builder->as_output_media_aspect_ratio($aspectRatio);
            }

            if ($outputMimeType !== '') {
                $builder = $this->applyImageOutputMimeType($builder, $outputMimeType);
            }

            if (!$builder->is_supported_for_image_generation()) {
                return $this->failure(
                    'unsupported_capability',
                    $status,
                    $startedAt,
                    $outputMimeType !== '' ? 'The selected model does not support the requested image MIME type.' : ''
                );
            }

            $file = $builder->generate_image();
        } catch (\Throwable $exception) {
            return $this->failure(
                $this->classifyThrowable($exception),
                $status,
                $startedAt,
                $exception->getMessage(),
                array('exception_class' => get_class($exception))
            );
        }

        if (is_wp_error($file)) {
            return $this->failureFromWpError($file, $status, $startedAt);
        }

        if (!($file instanceof File)) {
            return $this->failure('invalid_response', $status, $startedAt);
        }

        $reportedMimeType = strtolower(trim($file->getMimeType()));
        if (!in_array($reportedMimeType, $this->getAllowedImageMimeTypes(), true)) {
            return $this->failure(
                'invalid_response',
                $status,
                $startedAt,
                'The AI Client returned a file outside the allowed image MIME types.'
            );
        }

        return array(
            'success'             => true,
            'file'                => $file,
            'provider'            => $status['selected_provider'],
            'model'               => $status['selected_model'],
            'requested_mime_type' => $outputMimeType,
            'reported_mime_type'  => $reportedMimeType,
            'timing_ms'           => $this->getTiming($startedAt),
            'diagnostics'         => null,
        );
    }

    /**
     * Convert an administrator-facing image format to its MIME type.
     *
     * @param mixed $format Requested image format.
     * @return string Empty when no valid format was supplied.
     */
    private function normalizeImageOutputMimeType($format) {
        if (!is_string($format)) {
            return '';
        }

        $mimeTypes = array(
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'png'  => 'image/png',
        );
        $format = strtolower(trim($format));

        return isset($mimeTypes[$format]) ? $mimeTypes[$format] : '';
    }

    /**
     * Return MIME types accepted from the AI image boundary.
     *
     * @return string[]
     */
    private function getAllowedImageMimeTypes() {
        return array(
            'image/jpeg',
            'image/webp',
            'image/png',
        );
    }

    /**
     * Apply a validated output MIME type to the WordPress prompt builder.
     *
     * @param object $builder        WordPress AI prompt builder.
     * @param string $outputMimeType Validated output MIME type.
     * @return object
     */
    private function applyImageOutputMimeType($builder, $outputMimeType) {
        if (!in_array($outputMimeType, $this->getAllowedImageMimeTypes(), true)) {
            return $builder;
        }

        return $builder->as_output_mime_type($outputMimeType);
    }

    /**
     * Apply a validated provider/model selection to a prompt builder.
     *
     * @param object $builder WordPress AI prompt builder.
     * @param array  $status Validated status payload.
     * @return object
     */
    private function applySelection($builder, array $status) {
        $providerId = (string) ($status['selected_provider'] ?? '');
        $modelId = (string) ($status['selected_model'] ?? '');

        if ($providerId !== '' && $modelId !== '') {
            $model = AiClient::defaultRegistry()->getProviderModel($providerId, $modelId);
            $builder->using_model($model);
        } elseif ($providerId !== '') {
            $builder->using_provider($providerId);
        }

        return $builder;
    }

    /**
     * Resolve requested preferences against discovered compatible models.
     *
     * @param array  $providers Discovered providers.
     * @param string $providerId Requested provider.
     * @param string $modelId Requested model.
     * @return array{provider: string, model: string}
     */
    private function resolveSelection(array $providers, $providerId, $modelId) {
        foreach ($providers as $provider) {
            if (empty($provider['configured'])) {
                continue;
            }

            if ($providerId !== '' && $provider['id'] !== $providerId) {
                continue;
            }

            foreach ($provider['models'] as $model) {
                if ($modelId !== '' && $model['id'] !== $modelId) {
                    continue;
                }

                return array(
                    'provider' => $provider['id'],
                    'model'    => $model['id'],
                );
            }

            if ($modelId === '' && !empty($provider['models'])) {
                return array(
                    'provider' => $provider['id'],
                    'model'    => $provider['models'][0]['id'],
                );
            }
        }

        return array('provider' => '', 'model' => '');
    }

    /**
     * Determine a stable connection state.
     *
     * @param array $providers Discovered providers.
     * @param array $selection Resolved selection.
     * @param string $providerId Requested provider.
     * @param string $modelId Requested model.
     * @return string
     */
    private function getConnectionStatus(array $providers, array $selection, $providerId, $modelId) {
        if (!$this->isAvailable()) {
            return 'client_unavailable';
        }

        if (empty($providers)) {
            return 'provider_unavailable';
        }

        $matchingProviders = array_filter(
            $providers,
            static function ($provider) use ($providerId) {
                return $providerId === '' || $provider['id'] === $providerId;
            }
        );

        if ($providerId !== '' && empty($matchingProviders)) {
            return 'provider_unavailable';
        }

        $configuredProviders = array_filter(
            $matchingProviders,
            static function ($provider) {
                return !empty($provider['configured']);
            }
        );

        if (empty($configuredProviders)) {
            return 'provider_not_configured';
        }

        if (empty($selection['provider'])) {
            return ($modelId !== '' ? 'invalid_model' : 'unsupported_capability');
        }

        return 'connected';
    }

    /**
     * Map a public capability name to the official AI Client enum.
     *
     * @param string $capability Public capability name.
     * @return CapabilityEnum
     */
    private function getCapability($capability) {
        return ($capability === 'image')
            ? CapabilityEnum::imageGeneration()
            : CapabilityEnum::textGeneration();
    }

    /**
     * Normalize a model identifier without imposing provider-specific rules.
     *
     * @param mixed $value Raw identifier.
     * @return string
     */
    private function sanitizeIdentifier($value) {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);
        $value = preg_replace('/[^A-Za-z0-9._:\/-]/', '', $value);

        return is_string($value) ? substr($value, 0, 200) : '';
    }

    /**
     * Convert dimensions to a provider-agnostic aspect ratio.
     *
     * @param mixed $dimensions Dimensions preset.
     * @return string
     */
    private function normalizeAspectRatio($dimensions) {
        $ratios = array(
            '1024x1024' => '1:1',
            '1536x1024' => '3:2',
            '1024x1536' => '2:3',
        );

        return isset($ratios[$dimensions]) ? $ratios[$dimensions] : '';
    }

    /**
     * Build a normalized service failure.
     *
     * @param string $code Stable error code.
     * @param array  $status Connection status.
     * @param float  $startedAt Request start.
     * @param string $debugMessage Provider detail for debug logs only.
     * @param array  $context Safe diagnostic context.
     * @return array<string, mixed>
     */
    private function failure($code, array $status, $startedAt, $debugMessage = '', array $context = array()) {
        $debugEnabled = defined('WP_DEBUG') && WP_DEBUG;
        $diagnostics = array(
            'operation'         => sanitize_key((string) ($status['operation'] ?? '')),
            'capability'        => sanitize_key((string) ($status['capability'] ?? '')),
            'error_type'        => sanitize_key($code),
            'error_message'     => ($debugEnabled ? sanitize_text_field($debugMessage) : ''),
            'provider'          => sanitize_key((string) ($status['selected_provider'] ?? '')),
            'model_attempted'   => $this->sanitizeIdentifier(
                (string) (($status['requested_model'] ?? '') ?: ($status['selected_model'] ?? ''))
            ),
            'timing_ms'         => $this->getTiming($startedAt),
            'source_error_code' => sanitize_key((string) ($context['source_error_code'] ?? '')),
            'http_status'        => absint($context['http_status'] ?? 0),
            'exception_class'    => sanitize_text_field((string) ($context['exception_class'] ?? '')),
        );

        if ($debugEnabled && $debugMessage !== '') {
            $this->logDebug('ai.generation', $debugMessage, $diagnostics);
        }

        return array(
            'success'     => false,
            'error'       => sanitize_key($code),
            'message'     => $this->getPublicErrorMessage($code),
            'diagnostics' => $diagnostics,
        );
    }

    /**
     * Convert a WordPress AI Client error to a normalized service failure.
     *
     * @param WP_Error $error WordPress AI Client error.
     * @param array    $status Connection status.
     * @param float    $startedAt Request start.
     * @return array<string, mixed>
     */
    private function failureFromWpError(WP_Error $error, array $status, $startedAt) {
        $sourceCode = sanitize_key($error->get_error_code());
        $errorData = $error->get_error_data();
        $httpStatus = is_array($errorData) ? absint($errorData['status'] ?? 0) : 0;
        $exceptionClass = is_array($errorData)
            ? sanitize_text_field((string) ($errorData['exception_class'] ?? ''))
            : '';
        $code = $this->classifyWpError($sourceCode, $httpStatus);

        return $this->failure(
            $code,
            $status,
            $startedAt,
            $error->get_error_message(),
            array(
                'source_error_code' => $sourceCode,
                'http_status'       => $httpStatus,
                'exception_class'   => $exceptionClass,
            )
        );
    }

    /**
     * Classify a WordPress AI Client error into a stable provider-neutral category.
     *
     * @param string $sourceCode Original WordPress error code.
     * @param int    $httpStatus Upstream or wrapper HTTP status.
     * @return string
     */
    private function classifyWpError($sourceCode, $httpStatus) {
        if ($httpStatus === 401) {
            return 'authentication_error';
        }

        if ($httpStatus === 402 || $httpStatus === 403) {
            return 'permission_or_billing_error';
        }

        if ($httpStatus === 429) {
            return 'rate_or_quota_error';
        }

        if ($sourceCode === 'prompt_invalid_argument'
            || $sourceCode === 'prompt_token_limit_reached'
            || $httpStatus === 400
            || $httpStatus === 422
        ) {
            return 'invalid_request';
        }

        if ($sourceCode === 'prompt_network_error'
            || $sourceCode === 'prompt_upstream_server_error'
            || $httpStatus >= 500
        ) {
            return 'timeout_or_outage';
        }

        return 'unknown_error';
    }

    /**
     * Classify an unexpected exception without exposing its message to users.
     *
     * @param \Throwable $exception Runtime exception.
     * @return string
     */
    private function classifyThrowable(\Throwable $exception) {
        $className = strtolower(get_class($exception));

        if (strpos($className, 'timeout') !== false || strpos($className, 'network') !== false) {
            return 'timeout_or_outage';
        }

        return 'unknown_error';
    }

    /**
     * Return a provider-neutral user-facing error.
     *
     * @param string $code Stable error code.
     * @return string
     */
    private function getPublicErrorMessage($code) {
        $messages = array(
            'client_unavailable'       => __('The WordPress AI Client is unavailable.', 'exmoment-author'),
            'provider_unavailable'     => __('No compatible AI provider is available.', 'exmoment-author'),
            'provider_not_configured'  => __('No compatible AI provider is configured.', 'exmoment-author'),
            'invalid_model'            => __('The selected AI model is unavailable.', 'exmoment-author'),
            'unsupported_capability'   => __('The selected AI provider does not support this request.', 'exmoment-author'),
            'authentication_error'     => __('The AI provider could not authenticate this request. Reconnect it in WordPress Connectors.', 'exmoment-author'),
            'permission_or_billing_error' => __('The AI provider denied this request. Review the account permissions and billing status.', 'exmoment-author'),
            'rate_or_quota_error'       => __('The AI provider rate limit or quota was reached. Wait and try again, or review the provider quota.', 'exmoment-author'),
            'invalid_request'           => __('The AI request was invalid. Review the selected model and request settings.', 'exmoment-author'),
            'timeout_or_outage'         => __('The AI provider timed out or is temporarily unavailable. Please try again.', 'exmoment-author'),
            'unknown_error'             => __('The AI request failed for an unknown reason. Enable WordPress debug logging for diagnostics.', 'exmoment-author'),
            'empty_response'           => __('The AI provider returned an empty response.', 'exmoment-author'),
            'invalid_response'         => __('The AI provider returned an invalid image response.', 'exmoment-author'),
        );

        return isset($messages[$code])
            ? $messages[$code]
            : __('The AI request could not be completed.', 'exmoment-author');
    }

    /**
     * Calculate elapsed request time.
     *
     * @param float $startedAt Request start.
     * @return int
     */
    private function getTiming($startedAt) {
        return (int) round(max(0, microtime(true) - (float) $startedAt) * 1000);
    }

    /**
     * Log provider detail only when WordPress debug logging is enabled.
     *
     * @param string $channel Log channel.
     * @param string $message Debug message.
     * @param array  $context Safe context.
     * @return void
     */
    private function logDebug($channel, $message, array $context = array()) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $logger = \ExMomentAuthor\Modules\Log\LogService::getInstance();
        if ($logger instanceof \ExMomentAuthor\Modules\Log\LogService) {
            $logger->debug($channel, sanitize_text_field($message), $context, 0);
        }
    }
}
