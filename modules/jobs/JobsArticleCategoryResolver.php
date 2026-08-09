<?php

namespace ExMomentAuthor\Modules\Jobs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use WP_Error;
use WP_Term;

/**
 * Resolve job source-category references to existing WordPress category terms.
 */
class JobsArticleCategoryResolver {

    /**
     * Resolve category IDs without creating or guessing terms.
     *
     * Numeric references prefer exact term IDs. String references otherwise
     * use normalized exact slug or name matching. Ambiguous duplicate names
     * are rejected rather than resolved by term order.
     *
     * @param array<int, mixed> $references Category IDs, names, or slugs.
     * @return array{
     *     term_ids:array<int, int>,
     *     unresolved:array<int, string>,
     *     ambiguous:array<string, array<int, int>>,
     *     error:string
     * }
     */
    public function resolve(array $references) {
        $result = array(
            'term_ids'   => array(),
            'unresolved' => array(),
            'ambiguous'  => array(),
            'error'      => '',
        );
        $invalidReferences = array();
        $references = $this->normalizeReferences($references, $invalidReferences);
        $result['unresolved'] = $invalidReferences;

        if (empty($references)) {
            return $result;
        }

        if (!taxonomy_exists('category')) {
            $result['unresolved'] = array_values(array_unique(array_merge($result['unresolved'], $references)));
            $result['error'] = 'category_taxonomy_unavailable';

            return $result;
        }

        $terms = get_terms(array(
            'taxonomy'   => 'category',
            'hide_empty' => false,
        ));

        if ($terms instanceof WP_Error) {
            $result['unresolved'] = array_values(array_unique(array_merge($result['unresolved'], $references)));
            $result['error'] = 'category_terms_unavailable';

            return $result;
        }

        $indexes = $this->buildTermIndexes($terms);

        foreach ($references as $reference) {
            $label = $this->referenceLabel($reference);
            $termId = $this->resolveReference($reference, $indexes, $result['ambiguous']);

            if ($termId < 1 || !$this->isValidCategoryTerm($termId)) {
                $result['unresolved'][] = $label;
                continue;
            }

            $result['term_ids'][] = $termId;
        }

        $result['term_ids'] = array_values(array_unique(array_map('absint', $result['term_ids'])));
        $result['unresolved'] = array_values(array_unique($result['unresolved']));

        return $result;
    }

    /**
     * Normalize supported scalar references while preserving their type.
     *
     * @param array<int, mixed> $references       Raw references.
     * @param string[]          $invalidReferences Invalid-reference accumulator.
     * @return array<int, int|string>
     */
    private function normalizeReferences(array $references, array &$invalidReferences) {
        $normalized = array();

        foreach ($references as $reference) {
            if (is_int($reference)) {
                if ($reference > 0) {
                    $normalized[] = $reference;
                } else {
                    $invalidReferences[] = $this->referenceLabel($reference);
                }

                continue;
            }

            if (!is_string($reference)) {
                $invalidReferences[] = $this->referenceLabel($reference);
                continue;
            }

            $reference = trim($reference);
            if ($reference === '') {
                $invalidReferences[] = '(empty)';
                continue;
            }

            $normalized[] = $reference;
        }

        $invalidReferences = array_values(array_unique($invalidReferences));

        return array_values(array_unique($normalized, SORT_REGULAR));
    }

    /**
     * Build deterministic lookup indexes for the category taxonomy.
     *
     * @param array<int, mixed> $terms Term objects returned by get_terms().
     * @return array{
     *     by_id:array<int, int>,
     *     by_slug:array<string, int>,
     *     by_name:array<string, array<int, int>>
     * }
     */
    private function buildTermIndexes(array $terms) {
        $indexes = array(
            'by_id'   => array(),
            'by_slug' => array(),
            'by_name' => array(),
        );

        foreach ($terms as $term) {
            if (!($term instanceof WP_Term) || $term->taxonomy !== 'category') {
                continue;
            }

            $termId = absint($term->term_id);
            if ($termId < 1) {
                continue;
            }

            $slugKey = $this->normalizeSlug($term->slug);
            $nameKey = $this->normalizeName($term->name);

            $indexes['by_id'][$termId] = $termId;

            if ($slugKey !== '') {
                $indexes['by_slug'][$slugKey] = $termId;
            }

            if ($nameKey !== '') {
                if (!isset($indexes['by_name'][$nameKey])) {
                    $indexes['by_name'][$nameKey] = array();
                }

                $indexes['by_name'][$nameKey][] = $termId;
            }
        }

        return $indexes;
    }

    /**
     * Resolve one category reference against the prepared indexes.
     *
     * @param int|string                                $reference Raw category reference.
     * @param array<string, array<int|string, mixed>>    $indexes   Category lookup indexes.
     * @param array<string, array<int, int>>             $ambiguous Ambiguous-name accumulator.
     * @return int Resolved category term ID or zero.
     */
    private function resolveReference($reference, array $indexes, array &$ambiguous) {
        $numericId = 0;

        if (is_int($reference)) {
            $numericId = $reference;
        } elseif (is_string($reference) && preg_match('/^[1-9][0-9]*$/D', $reference)) {
            $numericId = (int) $reference;
        }

        if ($numericId > 0 && isset($indexes['by_id'][$numericId])) {
            return $numericId;
        }

        $slugKey = $this->normalizeSlug($reference);
        if ($slugKey !== '' && isset($indexes['by_slug'][$slugKey])) {
            return (int) $indexes['by_slug'][$slugKey];
        }

        $nameKey = $this->normalizeName($reference);
        if ($nameKey === '' || !isset($indexes['by_name'][$nameKey])) {
            return 0;
        }

        $nameMatches = array_values(array_unique(array_map('absint', $indexes['by_name'][$nameKey])));
        if (count($nameMatches) !== 1) {
            $ambiguous[$this->referenceLabel($reference)] = $nameMatches;

            return 0;
        }

        return (int) $nameMatches[0];
    }

    /**
     * Normalize a category name for exact, case-insensitive comparison.
     *
     * @param mixed $value Raw name.
     * @return string
     */
    private function normalizeName($value) {
        $value = $this->decodeReference($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', $value);
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    /**
     * Normalize a category slug for exact, case-insensitive comparison.
     *
     * @param mixed $value Raw slug.
     * @return string
     */
    private function normalizeSlug($value) {
        $value = $this->decodeReference($value);
        if ($value === '') {
            return '';
        }

        return strtolower(trim($value));
    }

    /**
     * Decode stored/displayed entities before exact comparisons.
     *
     * @param mixed $value Raw reference.
     * @return string
     */
    private function decodeReference($value) {
        if (!is_string($value) && !is_int($value)) {
            return '';
        }

        $value = (string) $value;
        $value = wp_specialchars_decode($value, ENT_QUOTES);

        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Create a safe diagnostic label for a category reference.
     *
     * @param mixed $reference Raw reference.
     * @return string
     */
    private function referenceLabel($reference) {
        $label = $this->decodeReference($reference);
        $label = sanitize_text_field($label);

        return $label !== '' ? $label : '(invalid)';
    }

    /**
     * Verify a resolved term still exists in the category taxonomy.
     *
     * @param int $termId Candidate term ID.
     * @return bool
     */
    private function isValidCategoryTerm($termId) {
        $term = get_term(absint($termId), 'category');

        return $term instanceof WP_Term && $term->taxonomy === 'category';
    }
}
