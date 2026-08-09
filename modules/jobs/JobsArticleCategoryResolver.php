<?php

namespace ExMomentAuthor\Modules\Jobs;

if (!defined('ABSPATH')) {
    exit;
}

use WP_Error;
use WP_Term;

/**
 * Build the WordPress category allowlist and validate AI-selected slugs.
 */
class JobsArticleCategoryResolver {

    /**
     * Maximum WordPress term-slug length.
     */
    private const MAX_SLUG_LENGTH = 200;

    /**
     * Retrieve current category terms as prompt-safe slug/name records.
     *
     * @return array{
     *     categories:array<int, array{slug:string,name:string}>,
     *     slugs:array<int, string>,
     *     error:string
     * }
     */
    public function getAvailableCategories() {
        $result = array(
            'categories' => array(),
            'slugs'      => array(),
            'error'      => '',
        );

        if (!taxonomy_exists('category')) {
            $result['error'] = 'category_taxonomy_unavailable';

            return $result;
        }

        $terms = get_terms(array(
            'taxonomy'   => 'category',
            'hide_empty' => false,
        ));

        if ($terms instanceof WP_Error || !is_array($terms)) {
            $result['error'] = 'category_terms_unavailable';

            return $result;
        }

        $seenSlugs = array();

        foreach ($terms as $term) {
            if (!($term instanceof WP_Term) || $term->taxonomy !== 'category') {
                continue;
            }

            $slug = is_string($term->slug) ? $term->slug : '';
            if ($this->validateSlugFormat($slug) !== '' || isset($seenSlugs[$slug])) {
                continue;
            }

            $termId = absint($term->term_id);
            if ($termId < 1) {
                continue;
            }

            $name = is_string($term->name) ? wp_specialchars_decode($term->name, ENT_QUOTES) : '';
            $name = sanitize_text_field($name);
            $name = trim($name);

            if ($name === '') {
                $name = $slug;
            }

            $seenSlugs[$slug] = true;
            $result['categories'][] = array(
                'slug' => $slug,
                'name' => $name,
            );
        }

        usort(
            $result['categories'],
            static function ($left, $right) {
                return strcmp($left['slug'], $right['slug']);
            }
        );

        foreach ($result['categories'] as $category) {
            $result['slugs'][] = $category['slug'];
        }

        return $result;
    }

    /**
     * Validate untrusted AI-selected slugs against the exact request allowlist.
     *
     * @param mixed    $selectedSlugs Slug list parsed from the AI response.
     * @param string[] $allowedSlugs  Exact slugs supplied in the AI request.
     * @return array{
     *     term_ids:array<int, int>,
     *     selected_term_ids:array<int, int>,
     *     ancestor_term_ids:array<int, int>,
     *     selected_slugs:array<int, string>,
     *     rejected_slugs:array<int, string>,
     *     rejections:array<int, array{value:string,reason:string}>,
     *     ancestor_rejections:array<int, array{term_id:int,reason:string}>,
     *     error:string
     * }
     */
    public function resolve($selectedSlugs, array $allowedSlugs) {
        $result = array(
            'term_ids'            => array(),
            'selected_term_ids'   => array(),
            'ancestor_term_ids'   => array(),
            'selected_slugs'      => array(),
            'rejected_slugs'      => array(),
            'rejections'          => array(),
            'ancestor_rejections' => array(),
            'error'               => '',
        );

        if (!is_array($selectedSlugs) || !array_is_list($selectedSlugs)) {
            $result['error'] = 'invalid_selection_type';

            return $result;
        }

        $allowedMap = $this->buildAllowedSlugMap($allowedSlugs);
        if (empty($selectedSlugs)) {
            $result['error'] = 'empty_selection';

            return $result;
        }

        $seenSelected = array();

        foreach ($selectedSlugs as $selectedSlug) {
            $formatError = $this->validateSlugFormat($selectedSlug);
            if ($formatError !== '') {
                $this->addRejection($result, $selectedSlug, $formatError);
                continue;
            }

            $slug = $selectedSlug;
            if (!isset($allowedMap[$slug])) {
                $this->addRejection($result, $slug, 'slug_not_allowlisted');
                continue;
            }

            if (isset($seenSelected[$slug])) {
                continue;
            }

            $term = get_term_by('slug', $slug, 'category');
            if (!($term instanceof WP_Term) || $term->taxonomy !== 'category' || $term->slug !== $slug) {
                $this->addRejection($result, $slug, 'category_term_missing');
                continue;
            }

            $termId = absint($term->term_id);
            if ($termId < 1) {
                $this->addRejection($result, $slug, 'category_term_invalid');
                continue;
            }

            $seenSelected[$slug] = true;
            $result['selected_slugs'][] = $slug;
            $result['selected_term_ids'][] = $termId;
        }

        $result['selected_term_ids'] = array_values(array_unique(array_map('absint', $result['selected_term_ids'])));

        if (empty($result['selected_slugs'])) {
            $result['error'] = 'no_valid_categories';
        } elseif (!empty($result['rejections'])) {
            $result['error'] = 'partial_invalid_selection';
        }

        if (!empty($result['selected_term_ids'])) {
            $hierarchy = $this->expandSelectedTermsToAncestors(
                $result['selected_slugs'],
                $result['selected_term_ids']
            );
            $result['term_ids'] = $hierarchy['term_ids'];
            $result['ancestor_term_ids'] = $hierarchy['ancestor_term_ids'];
            $result['ancestor_rejections'] = $hierarchy['ancestor_rejections'];
        }

        return $result;
    }

    /**
     * Expand direct selections to deterministic root-to-leaf category paths.
     *
     * Branches are sorted by their selected canonical slug so neither the AI
     * response order nor the original allowlist position controls assignment.
     * WordPress owns ancestor traversal through get_ancestors().
     *
     * @param string[] $selectedSlugs   Validated selected slugs.
     * @param int[]    $selectedTermIds Direct selected term IDs.
     * @return array{
     *     term_ids:array<int, int>,
     *     ancestor_term_ids:array<int, int>,
     *     ancestor_rejections:array<int, array{term_id:int,reason:string}>
     * }
     */
    private function expandSelectedTermsToAncestors(array $selectedSlugs, array $selectedTermIds) {
        $branches = array();
        $selectedIdMap = array();

        foreach ($selectedTermIds as $selectedTermId) {
            $selectedTermId = absint($selectedTermId);
            if ($selectedTermId > 0) {
                $selectedIdMap[$selectedTermId] = true;
            }
        }

        foreach ($selectedSlugs as $index => $selectedSlug) {
            $selectedTermId = isset($selectedTermIds[$index]) ? absint($selectedTermIds[$index]) : 0;
            if (!is_string($selectedSlug) || $selectedTermId < 1) {
                continue;
            }

            $branches[] = array(
                'slug'    => $selectedSlug,
                'term_id' => $selectedTermId,
            );
        }

        usort(
            $branches,
            static function ($left, $right) {
                return strcmp($left['slug'], $right['slug']);
            }
        );

        $termIds = array();
        $ancestorTermIds = array();
        $ancestorRejections = array();
        $seenFinalIds = array();
        $seenAncestorIds = array();

        foreach ($branches as $branch) {
            $selectedTermId = $branch['term_id'];
            $selectedTerm = get_term($selectedTermId, 'category');

            if (!($selectedTerm instanceof WP_Term) || $selectedTerm->taxonomy !== 'category') {
                $this->addAncestorRejection($ancestorRejections, $selectedTermId, 'selected_term_missing_during_expansion');
                continue;
            }

            $ancestorIds = get_ancestors($selectedTermId, 'category', 'taxonomy');
            if (!is_array($ancestorIds)) {
                $ancestorIds = array();
                $this->addAncestorRejection($ancestorRejections, absint($selectedTerm->parent), 'ancestor_chain_unavailable');
            } elseif (absint($selectedTerm->parent) > 0 && empty($ancestorIds)) {
                $this->addAncestorRejection($ancestorRejections, absint($selectedTerm->parent), 'ancestor_chain_unavailable');
            }

            $ancestorIds = array_reverse(array_values(array_map('absint', $ancestorIds)));

            foreach ($ancestorIds as $ancestorId) {
                if ($ancestorId < 1) {
                    continue;
                }

                $ancestor = get_term($ancestorId, 'category');
                if (!($ancestor instanceof WP_Term) || $ancestor->taxonomy !== 'category') {
                    $this->addAncestorRejection($ancestorRejections, $ancestorId, 'ancestor_term_missing');
                    continue;
                }

                if (!isset($seenFinalIds[$ancestorId])) {
                    $termIds[] = $ancestorId;
                    $seenFinalIds[$ancestorId] = true;
                }

                if (!isset($selectedIdMap[$ancestorId]) && !isset($seenAncestorIds[$ancestorId])) {
                    $ancestorTermIds[] = $ancestorId;
                    $seenAncestorIds[$ancestorId] = true;
                }
            }

            if (!isset($seenFinalIds[$selectedTermId])) {
                $termIds[] = $selectedTermId;
                $seenFinalIds[$selectedTermId] = true;
            }
        }

        return array(
            'term_ids'            => $termIds,
            'ancestor_term_ids'   => $ancestorTermIds,
            'ancestor_rejections' => $ancestorRejections,
        );
    }

    /**
     * Build an exact lookup map from the request allowlist.
     *
     * @param string[] $allowedSlugs Candidate allowlist values.
     * @return array<string, bool>
     */
    private function buildAllowedSlugMap(array $allowedSlugs) {
        $allowedMap = array();

        foreach ($allowedSlugs as $allowedSlug) {
            if ($this->validateSlugFormat($allowedSlug) !== '') {
                continue;
            }

            $allowedMap[$allowedSlug] = true;
        }

        return $allowedMap;
    }

    /**
     * Validate a canonical slug without rewriting or approximating it.
     *
     * @param mixed $slug Candidate slug.
     * @return string Empty when valid, otherwise a stable rejection reason.
     */
    private function validateSlugFormat($slug) {
        if (!is_string($slug)) {
            return 'slug_not_string';
        }

        if ($slug === '' || $slug !== trim($slug)) {
            return 'slug_invalid_format';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($slug, 'UTF-8') : strlen($slug);
        if ($length < 1 || $length > self::MAX_SLUG_LENGTH) {
            return 'slug_invalid_length';
        }

        if (sanitize_title($slug) !== $slug) {
            return 'slug_invalid_format';
        }

        return '';
    }

    /**
     * Add a safe rejected-value diagnostic without changing matching behavior.
     *
     * @param array<string, mixed> $result Result accumulator.
     * @param mixed                $value  Rejected raw value.
     * @param string               $reason Stable rejection reason.
     * @return void
     */
    private function addRejection(array &$result, $value, $reason) {
        $label = is_scalar($value) ? sanitize_text_field((string) $value) : '(invalid-type)';
        $label = trim($label);

        if ($label === '') {
            $label = '(empty)';
        }

        if (strlen($label) > self::MAX_SLUG_LENGTH) {
            $label = substr($label, 0, self::MAX_SLUG_LENGTH);
        }

        $reason = sanitize_key($reason);
        $result['rejected_slugs'][] = $label;
        $result['rejections'][] = array(
            'value'  => $label,
            'reason' => $reason,
        );
    }

    /**
     * Record a safe hierarchy-expansion failure.
     *
     * @param array<int, array{term_id:int,reason:string}> $rejections Rejection accumulator.
     * @param int                                         $termId     Missing or inconsistent ancestor ID.
     * @param string                                      $reason     Stable rejection reason.
     * @return void
     */
    private function addAncestorRejection(array &$rejections, $termId, $reason) {
        $termId = absint($termId);
        $reason = sanitize_key($reason);

        foreach ($rejections as $rejection) {
            if ($rejection['term_id'] === $termId && $rejection['reason'] === $reason) {
                return;
            }
        }

        $rejections[] = array(
            'term_id' => $termId,
            'reason'  => $reason,
        );
    }
}
