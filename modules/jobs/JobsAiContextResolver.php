<?php

namespace ExMomentAuthor\Modules\Jobs;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Error;
use WP_User;

/**
 * Resolves provider-neutral editorial prompts and public author context for jobs.
 */
class JobsAiContextResolver {

    /**
     * Post meta key for the optional per-job editorial system prompt.
     */
    public const META_CUSTOM_SYSTEM_PROMPT = 'exmoau_job_custom_system_prompt';

    /**
     * Maximum number of characters accepted for a per-job system prompt.
     */
    public const MAX_CUSTOM_SYSTEM_PROMPT_LENGTH = 10000;

    /**
     * Validate and normalize a custom system prompt without truncating it.
     *
     * @param mixed $value Untrusted prompt value.
     * @return string|WP_Error Normalized prompt or a validation error.
     */
    public static function sanitizeCustomSystemPrompt($value) {
        if (!is_string($value)) {
            return new WP_Error(
                'invalid_custom_system_prompt_type',
                __('The custom system prompt must be plain text.', 'exmoment-author')
            );
        }

        $value = str_replace(array("\r\n", "\r"), "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        $value = is_string($value) ? sanitize_textarea_field($value) : '';
        $value = trim($value);

        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length > self::MAX_CUSTOM_SYSTEM_PROMPT_LENGTH) {
            return new WP_Error(
                'custom_system_prompt_too_long',
                __('The custom system prompt must be 10,000 characters or fewer. The previous valid prompt was preserved.', 'exmoment-author')
            );
        }

        return $value;
    }

    /**
     * Resolve the single effective editorial prompt for a job.
     *
     * @param int    $jobId       Job post identifier.
     * @param string $globalPrompt Effective AI Setup prompt.
     * @return array{prompt:string,source:string,override_used:bool,prompt_length:int,prompt_hash:string,invalid_override:bool}
     */
    public static function resolveSystemPrompt($jobId, $globalPrompt) {
        $jobId = absint($jobId);
        $globalPrompt = is_string($globalPrompt) ? trim($globalPrompt) : '';
        $storedPrompt = $jobId > 0
            ? get_post_meta($jobId, self::META_CUSTOM_SYSTEM_PROMPT, true)
            : '';
        $normalizedPrompt = self::sanitizeCustomSystemPrompt($storedPrompt);
        $invalidOverride = is_wp_error($normalizedPrompt);

        if ($invalidOverride || $normalizedPrompt === '') {
            $prompt = $globalPrompt;
            $source = 'global';
            $overrideUsed = false;
        } else {
            $prompt = $normalizedPrompt;
            $source = 'job_override';
            $overrideUsed = true;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($prompt) : strlen($prompt);

        return array(
            'prompt'           => $prompt,
            'source'           => $source,
            'override_used'    => $overrideUsed,
            'prompt_length'    => $length,
            'prompt_hash'      => $prompt !== '' ? hash('sha256', $prompt) : '',
            'invalid_override' => $invalidOverride,
        );
    }

    /**
     * Resolve only the public WordPress display name for an effective author.
     *
     * @param int $authorId WordPress user identifier.
     * @return string Sanitized display name or an empty string.
     */
    public static function resolveAuthorDisplayName($authorId) {
        $authorId = absint($authorId);
        if ($authorId < 1) {
            return '';
        }

        $author = get_user_by('id', $authorId);
        if (!($author instanceof WP_User) || !is_string($author->display_name)) {
            return '';
        }

        $displayName = sanitize_text_field($author->display_name);

        return trim($displayName);
    }

    /**
     * Build article-generation author metadata that discourages visible attribution.
     *
     * @param string $displayName Public author display name.
     * @return string Context instruction or an empty string.
     */
    public static function buildArticleAuthorContext($displayName) {
        $displayName = is_string($displayName) ? sanitize_text_field($displayName) : '';
        $displayName = trim($displayName);

        if ($displayName === '') {
            return '';
        }

        return sprintf(
            'Author context: This article is being written for %s. Use the author identity only as contextual guidance for tone and voice. Do not mention the author unless the supplied editorial instructions require it.',
            $displayName
        );
    }

    /**
     * Build image-generation author metadata without requesting a portrait or text.
     *
     * @param string $displayName Public author display name.
     * @return string Context instruction or an empty string.
     */
    public static function buildImageAuthorContext($displayName) {
        $displayName = is_string($displayName) ? sanitize_text_field($displayName) : '';
        $displayName = trim($displayName);

        if ($displayName === '') {
            return '';
        }

        return sprintf(
            'Author context: The accompanying article is authored by %s. Use this only to understand the editorial tone. Do not include the author, their likeness, their name, a byline, signature, watermark, or logo in the image unless the image instructions explicitly require it.',
            $displayName
        );
    }
}
