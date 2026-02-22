<?php

namespace ExMomentAuthor\Modules\Cache;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use WP_Post;
use WP_Post_Type;

class FlygRecacheService {

    private const FLYG_COMMAND = 'craw';
    private const IS_SECURED_VALUE = 'yes';
    private const DEFAULT_TIMEOUT = 2;
    private const DEFAULT_REDIRECTION = 0;

    /**
     * Trigger Flyg recache requests for a published, public post and homepage.
     *
     * Validates eligibility, ensures the Flyg plugin is available, and fires
     * lightweight HTTP GET requests against Flyg's craw endpoint for the post
     * permalink and homepage. Requests are non-blocking with a short timeout to
     * avoid slowing the save_post lifecycle.
     *
     * @param WP_Post $post Post object to recache.
     * @return void
     */
    public function recacheForPost(WP_Post $post) {
        if (!$this->shouldRecache($post)) {
            return;
        }

        if (!class_exists('Flyg') || !method_exists('Flyg', 'getRecacheUrl')) {
            return;
        }

        $recacheData = \Flyg::getRecacheUrl($post->ID, $post);
        if (!is_array($recacheData)) {
            return;
        }

        $commandUrls = $this->collectCommandUrls($recacheData);
        $commandUrls = $this->ensureHomepageCommand($commandUrls);

        $shouldLog = defined('WP_DEBUG') && WP_DEBUG;
        $blocking = $shouldLog;

        foreach ($commandUrls as $commandUrl) {
            $response = wp_remote_get(
                $commandUrl,
                [
                    'timeout'     => self::DEFAULT_TIMEOUT,
                    'redirection' => self::DEFAULT_REDIRECTION,
                    'blocking'    => $blocking,
                ]
            );

            if ($shouldLog) {
                if (is_wp_error($response)) {
                    error_log(
                        sprintf(
                            'ExMoment Author: Flyg recache request failed for %s: %s',
                            esc_url_raw($commandUrl),
                            sanitize_text_field($response->get_error_message())
                        )
                    );

                    continue;
                }

                $statusCode = wp_remote_retrieve_response_code($response);
                if (!is_int($statusCode) || $statusCode < 200 || $statusCode >= 300) {
                    error_log(
                        sprintf(
                            'ExMoment Author: Flyg recache request returned status %s for %s.',
                            sanitize_text_field((string) $statusCode),
                            esc_url_raw($commandUrl)
                        )
                    );
                }
            }
        }
    }

    /**
     * Determine if a post should be recached.
     *
     * @param WP_Post $post Post object under evaluation.
     * @return bool True when recache should proceed.
     */
    private function shouldRecache(WP_Post $post) {
        $postId = absint($post->ID);
        if ($postId < 1) {
            return false;
        }

        if ($post->post_status !== 'publish') {
            return false;
        }

        $postType = $post->post_type;
        if (!is_string($postType) || $postType === '') {
            return false;
        }

        if ($postType === 'attachment') {
            return false;
        }

        if (!post_type_exists($postType)) {
            return false;
        }

        $postTypeObject = get_post_type_object($postType);
        if (!($postTypeObject instanceof WP_Post_Type)) {
            return false;
        }

        if (function_exists('is_post_type_viewable')) {
            if (!is_post_type_viewable($postTypeObject)) {
                return false;
            }
        } elseif (!$postTypeObject->public) {
            return false;
        }

        return true;
    }

    /**
     * Normalize Flyg recache URLs from getRecacheUrl output.
     *
     * @param array<string, mixed> $recacheData Flyg::getRecacheUrl payload.
     * @return array<int, string> List of sanitized craw command URLs.
     */
    private function collectCommandUrls(array $recacheData) {
        $commandUrls = [];

        $primaryRequest = $recacheData['requestUrl'] ?? '';
        $commandUrls = $this->maybeAddCommandUrl($commandUrls, $primaryRequest);

        $relatedRequests = $recacheData['relatedRequestUrl'] ?? [];
        if (is_string($relatedRequests)) {
            $relatedRequests = [$relatedRequests];
        }

        if (is_array($relatedRequests)) {
            foreach ($relatedRequests as $candidate) {
                $commandUrls = $this->maybeAddCommandUrl($commandUrls, $candidate);
            }
        }

        return array_values(array_unique($commandUrls));
    }

    /**
     * Append a sanitized recache URL when valid.
     *
     * @param array<int, string> $commandUrls Current collection of URLs.
     * @param mixed              $candidate   Potential command URL.
     * @return array<int, string>
     */
    private function maybeAddCommandUrl(array $commandUrls, $candidate) {
        if (!is_string($candidate)) {
            return $commandUrls;
        }

        $candidate = trim($candidate);
        if ($candidate === '') {
            return $commandUrls;
        }

        $validated = $this->sanitizeCommandUrl($candidate);
        if ($validated === '') {
            return $commandUrls;
        }

        $commandUrls[] = $validated;

        return $commandUrls;
    }

    /**
     * Ensure the homepage craw command is present.
     *
     * @param array<int, string> $commandUrls Existing command URLs.
     * @return array<int, string> Command URLs including homepage request.
     */
    private function ensureHomepageCommand(array $commandUrls) {
        $homepage = $this->normalizeTargetUrl(home_url('/'));
        if ($homepage === '') {
            return $commandUrls;
        }

        foreach ($commandUrls as $commandUrl) {
            $targetUrl = $this->extractTargetUrl($commandUrl);
            if ($targetUrl === '') {
                continue;
            }

            if ($this->normalizeTargetUrl($targetUrl) === $homepage) {
                return $commandUrls;
            }
        }

        $homepageCommand = $this->buildCrawCommandUrl($homepage);
        if ($homepageCommand !== '') {
            $commandUrls[] = $homepageCommand;
        }

        return array_values(array_unique($commandUrls));
    }

    /**
     * Construct a Flyg craw command URL for a target.
     *
     * @param string $targetUrl Normalized target URL to recache.
     * @return string
     */
    private function buildCrawCommandUrl($targetUrl) {
        if (!is_string($targetUrl) || $targetUrl === '') {
            return '';
        }

        $baseUrl = home_url('/');
        $baseUrl = wp_http_validate_url($baseUrl);
        if (!is_string($baseUrl)) {
            return '';
        }

        $queryArgs = [
            'flygCommand' => sanitize_text_field(self::FLYG_COMMAND),
            'isSecured'   => sanitize_text_field(self::IS_SECURED_VALUE),
            'url'         => $targetUrl,
        ];

        $commandUrl = add_query_arg($queryArgs, $baseUrl);
        $commandUrl = $this->sanitizeCommandUrl($commandUrl);

        return $commandUrl;
    }

    /**
     * Extract the intended recache target from a craw command URL.
     *
     * @param string $commandUrl Command URL containing Flyg query parameters.
     * @return string Normalized target URL or an empty string when missing.
     */
    private function extractTargetUrl($commandUrl) {
        $parts = wp_parse_url($commandUrl);
        if (!is_array($parts)) {
            return '';
        }

        $query = $parts['query'] ?? '';
        if (!is_string($query) || $query === '') {
            return '';
        }

        $parameters = [];
        wp_parse_str($query, $parameters);

        $targetUrl = $parameters['url'] ?? '';
        if (!is_string($targetUrl) || $targetUrl === '') {
            return '';
        }

        $targetUrl = rawurldecode($targetUrl);

        return $this->normalizeTargetUrl($targetUrl);
    }

    /**
     * Sanitize a craw command URL for safe dispatch.
     *
     * @param string $candidate Raw URL candidate.
     * @return string Sanitized URL or an empty string when invalid.
     */
    private function sanitizeCommandUrl($candidate) {
        $validated = wp_http_validate_url($candidate);
        if (!is_string($validated)) {
            return '';
        }

        $parts = wp_parse_url($validated);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = $parts['scheme'] ?? '';
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }

        return $validated;
    }

    /**
     * Normalize a target URL for comparison and reuse.
     *
     * @param string $targetUrl URL to normalize.
     * @return string Normalized URL or an empty string.
     */
    private function normalizeTargetUrl($targetUrl) {
        if (!is_string($targetUrl) || $targetUrl === '') {
            return '';
        }

        $validated = wp_http_validate_url($targetUrl);
        if (!is_string($validated)) {
            return '';
        }

        $normalized = trailingslashit(untrailingslashit($validated));

        return $normalized;
    }
}
