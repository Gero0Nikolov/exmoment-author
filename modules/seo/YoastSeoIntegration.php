<?php

namespace ExMomentAuthor\Modules\Seo;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use WP_Post;
use WP_Post_Type;

class YoastSeoIntegration {

    private const YOAST_TITLE_META_KEY = '_yoast_wpseo_title';
    private const YOAST_DESCRIPTION_META_KEY = '_yoast_wpseo_metadesc';
    private const YOAST_FOCUS_KEY_META_KEY = '_yoast_wpseo_focuskw';
    /**
     * Cached Yoast detection result for the current request.
     *
     * @var bool|null
     */
    private static $yoastDetected = null;

    /**
     * Determine whether the integration can operate.
     *
     * Callback used when registering filters/actions to gate Yoast-dependent
     * behavior.
     *
     * @return bool True when Yoast is available for the current request.
     */
    public function isActive() {
        return $this->isYoastActive();
    }

    /**
     * Populate Yoast SEO metadata for a post when missing.
     *
     * Intended as an action callback when generated content supplies SEO
     * suggestions. The optional $context array can include any request-specific
     * data (e.g., prompt identifiers), though it is ignored here.
     *
     * @param int         $postId         Target post identifier from the action payload.
     * @param string|null $seoTitle       Suggested SEO title supplied by the caller.
     * @param string|null $seoDescription Suggested SEO description from the generator.
     * @param string|null $focusKeyphrase Suggested focus keyphrase to store.
     * @param array       $context        Optional context array; keys are caller-defined.
     * @return void
     */
    public function maybeUpdatePostSeo($postId, $seoTitle, $seoDescription, $focusKeyphrase = null, array $context = []) {
        unset($context);

        $postId = absint($postId);
        if ($postId < 1) {
            return;
        }

        if (!$this->isYoastActive()) {
            return;
        }

        $seoTitle = $this->sanitizeSeoField($seoTitle);
        $seoDescription = $this->sanitizeSeoField($seoDescription);
        $focusKeyphrase = $this->sanitizeFocusKeyphrase($focusKeyphrase);

        if ($seoTitle === '' && $seoDescription === '' && $focusKeyphrase === '') {
            return;
        }

        $post = get_post($postId);
        if (!($post instanceof WP_Post)) {
            return;
        }

        if (!$this->isSupportedPostType($post->post_type)) {
            return;
        }

        $existingTitle = get_post_meta($postId, self::YOAST_TITLE_META_KEY, true);
        $existingDescription = get_post_meta($postId, self::YOAST_DESCRIPTION_META_KEY, true);
        $existingFocusKeyphrase = get_post_meta($postId, self::YOAST_FOCUS_KEY_META_KEY, true);

        $currentTitle = is_string($existingTitle) ? trim($existingTitle) : '';
        $currentDescription = is_string($existingDescription) ? trim($existingDescription) : '';
        $currentFocusKeyphrase = is_string($existingFocusKeyphrase) ? trim($existingFocusKeyphrase) : '';

        if ($seoTitle !== '' && $currentTitle === '') {
            update_post_meta($postId, self::YOAST_TITLE_META_KEY, $seoTitle);
        }

        if ($seoDescription !== '' && $currentDescription === '') {
            update_post_meta($postId, self::YOAST_DESCRIPTION_META_KEY, $seoDescription);
        }

        if ($focusKeyphrase !== '' && $currentFocusKeyphrase === '') {
            update_post_meta($postId, self::YOAST_FOCUS_KEY_META_KEY, $focusKeyphrase);
        }
    }

    /**
     * Determine whether Yoast SEO is active.
     *
     * Detects the plugin using constants or core classes and caches the result
     * for the duration of the request.
     *
     * @return bool True when Yoast is detected, false otherwise.
     */
    private function isYoastActive() {
        if (self::$yoastDetected !== null) {
            return self::$yoastDetected;
        }

        $active = false;

        if (defined('WPSEO_VERSION')) {
            $active = true;
        } elseif (class_exists('\WPSEO_Meta') || class_exists('\YoastSEO')) {
            $active = true;
        }

        self::$yoastDetected = $active;

        return $active;
    }

    /**
     * Ensure the post type matches the directive constraints from the Jobs module.
     *
     * @param string $postType Post type slug passed from a caller or hook payload.
     * @return bool True when the post type is public, editor-enabled, and not excluded.
     */
    private function isSupportedPostType($postType) {
        if (!is_string($postType) || $postType === '') {
            return false;
        }

        if (!post_type_exists($postType)) {
            return false;
        }

        $object = get_post_type_object($postType);
        if (!($object instanceof WP_Post_Type)) {
            return false;
        }

        if (!post_type_supports($postType, 'editor')) {
            return false;
        }

        if (!$object->show_ui || (property_exists($object, 'internal') && $object->internal)) {
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

        return true;
    }

    /**
     * Sanitize strings for Yoast fields.
     *
     * Accepts raw values from action payloads or generated content and returns a
     * plain string compatible with Yoast meta fields.
     *
     * @param mixed $value Raw value for the meta field.
     * @return string Trimmed, sanitized string or an empty string when invalid.
     */
    private function sanitizeSeoField($value) {
        if (!is_string($value)) {
            return '';
        }

        $value = wp_strip_all_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value);
        if (!is_string($value)) {
            $value = '';
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = sanitize_text_field($value);

        return trim($value);
    }

    /**
     * Sanitize focus keyphrases.
     *
     * Accepts raw focus keyphrases from external callbacks and returns a
     * sanitized string acceptable to Yoast.
     *
     * @param mixed $value Raw focus keyphrase value.
     * @return string Trimmed, sanitized string or an empty string when invalid.
     */
    private function sanitizeFocusKeyphrase($value) {
        if (!is_string($value)) {
            return '';
        }

        $value = wp_strip_all_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value);
        if (!is_string($value)) {
            $value = '';
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = sanitize_text_field($value);

        return trim($value);
    }
}
