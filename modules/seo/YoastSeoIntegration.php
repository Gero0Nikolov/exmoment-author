<?php

namespace ExMomentAuthor\Modules\Seo;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use WP_Post;
use WP_Post_Type;

class YoastSeoIntegration {

    private const YOAST_TITLE_META_KEY = '_yoast_wpseo_title';
    private const YOAST_DESCRIPTION_META_KEY = '_yoast_wpseo_metadesc';
    private const YOAST_FOCUS_KEY_META_KEY = '_yoast_wpseo_focuskw';
    private const YOAST_SEPARATOR_VARIABLE = '%%sep%%';
    private const YOAST_SITE_NAME_VARIABLE = '%%sitename%%';
    private const SEO_TITLE_MIN_LENGTH = 10;
    private const SEO_TITLE_MAX_LENGTH = 60;
    private const SEO_DESCRIPTION_MIN_LENGTH = 50;
    private const SEO_DESCRIPTION_MAX_LENGTH = 155;
    private const FOCUS_KEYPHRASE_MIN_WORDS = 2;
    private const FOCUS_KEYPHRASE_MAX_WORDS = 6;
    private const FOCUS_KEYPHRASE_MAX_LENGTH = 60;
    private const BAD_EXAMPLE_TITLE = 'Lifesaving Tactics for a Changing Marketplace';
    private const BAD_EXAMPLE_DESCRIPTION = 'Test new ads, refresh offers, diversify, choose work you enjoy, use marketplaces, and set up secure payment gateways for lasting resilience.';
    private const BAD_EXAMPLE_FOCUS_KEYPHRASE = 'changing marketplace tactics';
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

        $seoTitleText = self::normalizeYoastTitleText($seoTitle);
        $titleValidation = self::validateSeoTitleValue($seoTitleText);
        $seoTitleTemplate = (
            $titleValidation['valid'] ?
            self::composeYoastTitleTemplate($titleValidation['value']) :
            ''
        );
        $descriptionValidation = self::validateSeoDescriptionValue($seoDescription);
        $focusKeyphraseValidation = self::validateFocusKeyphraseValue($focusKeyphrase);

        if (
            !$titleValidation['valid'] &&
            !$descriptionValidation['valid'] &&
            !$focusKeyphraseValidation['valid']
        ) {
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

        if (
            $titleValidation['valid'] &&
            $seoTitleTemplate !== '' &&
            $this->canOverwriteExistingSeoValue('title', $existingTitle)
        ) {
            update_post_meta($postId, self::YOAST_TITLE_META_KEY, $seoTitleTemplate);
        }

        if (
            $descriptionValidation['valid'] &&
            $this->canOverwriteExistingSeoValue('description', $existingDescription)
        ) {
            update_post_meta($postId, self::YOAST_DESCRIPTION_META_KEY, $descriptionValidation['value']);
        }

        if (
            $focusKeyphraseValidation['valid'] &&
            $this->canOverwriteExistingSeoValue('focus_keyphrase', $existingFocusKeyphrase)
        ) {
            update_post_meta($postId, self::YOAST_FOCUS_KEY_META_KEY, $focusKeyphraseValidation['value']);
        }
    }

    /**
     * Normalize SEO metadata values before validation or persistence checks.
     *
     * @param mixed $value Raw metadata value.
     * @return string
     */
    public static function normalizeSeoValue($value) {
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

        $value = self::trimWrappingQuotes($value);
        $value = sanitize_text_field($value);

        return trim($value);
    }

    /**
     * Compose a canonical Yoast post-title template from generated title text.
     *
     * The generated value owns only the article-specific title. Exact Yoast
     * separator and site-name variables are removed before the canonical
     * suffix is appended, which prevents duplicate template variables without
     * guessing at rendered separators or site names.
     *
     * @param mixed $value Generated or legacy title candidate.
     * @return string Canonical Yoast title template, or an empty string when invalid.
     */
    public static function composeYoastTitleTemplate($value) {
        $titleText = self::normalizeYoastTitleText($value);
        $validation = self::validateSeoTitleValue($titleText);

        if (!$validation['valid']) {
            return '';
        }

        return sprintf(
            '%s %s %s',
            $validation['value'],
            self::YOAST_SEPARATOR_VARIABLE,
            self::YOAST_SITE_NAME_VARIABLE
        );
    }

    /**
     * Validate a candidate SEO title.
     *
     * @param mixed $value Candidate title value.
     * @return array{valid:bool,value:string,reason:string}
     */
    public static function validateSeoTitleValue($value) {
        $normalizedValue = self::normalizeSeoValue($value);

        if ($normalizedValue === '') {
            return self::buildValidationResult(false, '', 'SEO title is missing.');
        }

        if (self::containsWrapperCommentary($normalizedValue)) {
            return self::buildValidationResult(false, '', 'SEO title contains wrapper text or labels.');
        }

        $length = self::getStringLength($normalizedValue);
        if ($length < self::SEO_TITLE_MIN_LENGTH) {
            return self::buildValidationResult(false, '', 'SEO title is too short.');
        }

        if ($length > self::SEO_TITLE_MAX_LENGTH) {
            return self::buildValidationResult(false, '', 'SEO title exceeds 60 characters.');
        }

        if (self::isKnownBadSeoValue($normalizedValue)) {
            return self::buildValidationResult(false, '', 'SEO title matches a known bad generated value.');
        }

        return self::buildValidationResult(true, $normalizedValue, '');
    }

    /**
     * Validate a candidate SEO description.
     *
     * @param mixed $value Candidate description value.
     * @return array{valid:bool,value:string,reason:string}
     */
    public static function validateSeoDescriptionValue($value) {
        $normalizedValue = self::normalizeSeoValue($value);

        if ($normalizedValue === '') {
            return self::buildValidationResult(false, '', 'SEO description is missing.');
        }

        if (self::containsWrapperCommentary($normalizedValue)) {
            return self::buildValidationResult(false, '', 'SEO description contains wrapper text or labels.');
        }

        $length = self::getStringLength($normalizedValue);
        if ($length < self::SEO_DESCRIPTION_MIN_LENGTH) {
            return self::buildValidationResult(false, '', 'SEO description is too short.');
        }

        if ($length > self::SEO_DESCRIPTION_MAX_LENGTH) {
            return self::buildValidationResult(false, '', 'SEO description exceeds 155 characters.');
        }

        if (self::isKnownBadSeoValue($normalizedValue)) {
            return self::buildValidationResult(false, '', 'SEO description matches a known bad generated value.');
        }

        return self::buildValidationResult(true, $normalizedValue, '');
    }

    /**
     * Validate a candidate focus keyphrase.
     *
     * @param mixed $value Candidate focus keyphrase value.
     * @return array{valid:bool,value:string,reason:string}
     */
    public static function validateFocusKeyphraseValue($value) {
        $normalizedValue = self::normalizeSeoValue($value);

        if ($normalizedValue === '') {
            return self::buildValidationResult(false, '', '');
        }

        if (self::containsWrapperCommentary($normalizedValue)) {
            return self::buildValidationResult(false, '', 'Focus keyphrase contains wrapper text or labels.');
        }

        if (self::getStringLength($normalizedValue) > self::FOCUS_KEYPHRASE_MAX_LENGTH) {
            return self::buildValidationResult(false, '', 'Focus keyphrase exceeds 60 characters.');
        }

        if (!preg_match('/^[\pL\pN]+(?:[\'’-][\pL\pN]+)?(?: [\pL\pN]+(?:[\'’-][\pL\pN]+)?){1,5}$/u', $normalizedValue)) {
            return self::buildValidationResult(false, '', 'Focus keyphrase must be a concise 2 to 6 word phrase.');
        }

        $wordCount = self::getWordCount($normalizedValue);
        if (
            $wordCount < self::FOCUS_KEYPHRASE_MIN_WORDS ||
            $wordCount > self::FOCUS_KEYPHRASE_MAX_WORDS
        ) {
            return self::buildValidationResult(false, '', 'Focus keyphrase must contain 2 to 6 words.');
        }

        if (self::isKnownBadSeoValue($normalizedValue)) {
            return self::buildValidationResult(false, '', 'Focus keyphrase matches a known bad generated value.');
        }

        return self::buildValidationResult(true, $normalizedValue, '');
    }

    /**
     * Determine whether a value matches a known bad generated artifact.
     *
     * @param mixed $value Candidate metadata value.
     * @return bool
     */
    public static function isKnownBadSeoValue($value) {
        $normalizedValue = self::normalizeSeoValue($value);

        if ($normalizedValue === '') {
            return false;
        }

        $knownBadValues = array(
            self::BAD_EXAMPLE_TITLE,
            self::BAD_EXAMPLE_DESCRIPTION,
            self::BAD_EXAMPLE_FOCUS_KEYPHRASE,
        );

        if (in_array($normalizedValue, $knownBadValues, true)) {
            return true;
        }

        return self::containsWrapperCommentary($normalizedValue);
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
        return self::normalizeSeoValue($value);
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
        return self::normalizeSeoValue($value);
    }

    /**
     * Determine whether an existing stored value can be replaced safely.
     *
     * @param string $field         Metadata field identifier.
     * @param mixed  $existingValue Existing Yoast value.
     * @return bool
     */
    private function canOverwriteExistingSeoValue($field, $existingValue) {
        $normalizedValue = self::normalizeSeoValue($existingValue);

        if ($normalizedValue === '') {
            return true;
        }

        if (self::isKnownBadSeoValue($normalizedValue)) {
            return true;
        }

        if ($field === 'title') {
            return !$this->isValidExistingSeoTitle($normalizedValue);
        }

        if ($field === 'description') {
            return !$this->isValidExistingSeoDescription($normalizedValue);
        }

        if ($field === 'focus_keyphrase') {
            return !$this->isValidExistingFocusKeyphrase($normalizedValue);
        }

        return false;
    }

    /**
     * Determine whether the existing title is valid.
     *
     * @param mixed $value Existing Yoast title.
     * @return bool
     */
    private function isValidExistingSeoTitle($value) {
        $validation = self::validateSeoTitleValue($value);

        if (!empty($validation['valid'])) {
            return true;
        }

        $validation = self::validateSeoTitleValue(self::normalizeYoastTitleText($value));

        return !empty($validation['valid']);
    }

    /**
     * Normalize title text while removing only exact Yoast suffix variables.
     *
     * Literal separators and site names are deliberately preserved because
     * they may be legitimate article-title text and cannot be identified
     * safely without fuzzy matching.
     *
     * @param mixed $value Generated or stored title candidate.
     * @return string Article-specific title text without Yoast title variables.
     */
    private static function normalizeYoastTitleText($value) {
        $normalizedValue = self::normalizeSeoValue($value);

        if ($normalizedValue === '') {
            return '';
        }

        $normalizedValue = preg_replace(
            '/%%(?:sep|sitename)%%/iu',
            ' ',
            $normalizedValue
        );

        if (!is_string($normalizedValue)) {
            return '';
        }

        $normalizedValue = preg_replace('/\s+/u', ' ', $normalizedValue);

        return is_string($normalizedValue) ? trim($normalizedValue) : '';
    }

    /**
     * Determine whether the existing description is valid.
     *
     * @param mixed $value Existing Yoast description.
     * @return bool
     */
    private function isValidExistingSeoDescription($value) {
        $validation = self::validateSeoDescriptionValue($value);

        return !empty($validation['valid']);
    }

    /**
     * Determine whether the existing focus keyphrase is valid.
     *
     * @param mixed $value Existing Yoast focus keyphrase.
     * @return bool
     */
    private function isValidExistingFocusKeyphrase($value) {
        $validation = self::validateFocusKeyphraseValue($value);

        return !empty($validation['valid']);
    }

    /**
     * Build a structured validation result payload.
     *
     * @param bool   $isValid Validation result flag.
     * @param string $value   Normalized candidate value.
     * @param string $reason  Rejection reason.
     * @return array{valid:bool,value:string,reason:string}
     */
    private static function buildValidationResult($isValid, $value, $reason) {
        return array(
            'valid' => (bool) $isValid,
            'value' => (is_string($value) ? $value : ''),
            'reason' => (is_string($reason) ? $reason : ''),
        );
    }

    /**
     * Detect obvious wrapper text or label artifacts in SEO metadata.
     *
     * @param string $value Candidate metadata value.
     * @return bool
     */
    private static function containsWrapperCommentary($value) {
        if (!is_string($value) || $value === '') {
            return false;
        }

        if (preg_match('/===SEO_META_/i', $value)) {
            return true;
        }

        return (bool) preg_match(
            '/^\s*(seo[_ ]?title|seo[_ ]?description|meta description|focus keyphrase|focus[_ ]?keyphrase|suggested slug|example|output format|title|description|keyphrase)\s*:/i',
            $value
        );
    }

    /**
     * Remove matching wrapping quotes from a metadata value when safe.
     *
     * @param string $value Candidate metadata value.
     * @return string
     */
    private static function trimWrappingQuotes($value) {
        if (!is_string($value) || $value === '') {
            return '';
        }

        $pairs = array(
            array('"', '"'),
            array("'", "'"),
            array('“', '”'),
            array('‘', '’'),
        );

        foreach ($pairs as $pair) {
            $start = $pair[0];
            $end = $pair[1];

            if (strpos($value, $start) === 0 && substr($value, -strlen($end)) === $end) {
                $value = substr($value, strlen($start), -strlen($end));
                break;
            }
        }

        return trim($value);
    }

    /**
     * Count the words in a normalized SEO string.
     *
     * @param string $value Candidate metadata value.
     * @return int
     */
    private static function getWordCount($value) {
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }

        $words = preg_split('/\s+/u', trim($value));

        return is_array($words) ? count($words) : 0;
    }

    /**
     * Measure string length using multibyte support when available.
     *
     * @param string $value Candidate metadata value.
     * @return int
     */
    private static function getStringLength($value) {
        if (!is_string($value)) {
            return 0;
        }

        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value, 'UTF-8');
        }

        return (int) strlen($value);
    }
}
