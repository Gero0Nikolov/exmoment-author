<?php

use ExMomentAuthor\Modules\Jobs\JobsArticleCategoryResolver;
use ExMomentAuthor\Modules\Jobs\JobsExecutionController;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI: wp eval-file tests/runtime/article-categorisation-regression.php\n");
    exit(1);
}

$keepFixtures = isset($args) && is_array($args) && in_array('--keep-fixtures', $args, true);
$suffix = strtolower(wp_generate_password(8, false, false));
$createdTermIds = array();
$createdPostIds = array();
$createdLogIds = array();
$failures = array();
$results = array();

$assertSame = static function ($expected, $actual, $label) use (&$failures, &$results) {
    if ($expected === $actual) {
        array_push($results, 'PASS: ' . $label);

        return;
    }

    array_push(
        $failures,
        sprintf(
            'FAIL: %s (expected %s, actual %s)',
            $label,
            wp_json_encode($expected),
            wp_json_encode($actual)
        )
    );
};

$assertContains = static function ($needle, $haystack, $label) use (&$failures, &$results) {
    if (is_string($haystack) && strpos($haystack, $needle) !== false) {
        array_push($results, 'PASS: ' . $label);

        return;
    }

    array_push($failures, sprintf('FAIL: %s (missing %s)', $label, wp_json_encode($needle)));
};

$insertCategory = static function ($name, $slug, $parent = 0) use (&$createdTermIds) {
    $inserted = wp_insert_term(
        $name,
        'category',
        array(
            'slug'   => $slug,
            'parent' => absint($parent),
        )
    );

    if (is_wp_error($inserted)) {
        throw new RuntimeException($inserted->get_error_message());
    }

    $termId = absint($inserted['term_id']);
    array_push($createdTermIds, $termId);

    return $termId;
};

$categoryCount = static function () {
    $termIds = get_terms(array(
        'taxonomy'   => 'category',
        'hide_empty' => false,
        'fields'     => 'ids',
    ));

    return is_array($termIds) ? count($termIds) : -1;
};

try {
    $alphaSlug = 'cd3-alpha-first-' . $suffix;
    $strategySlug = 'cd3-editorial-strategy-' . $suffix;
    $marketsSlug = 'cd3-materially-different-' . $suffix;
    $childSlug = 'cd3-child-insights-' . $suffix;
    $grandchildSlug = 'cd3-grandchild-details-' . $suffix;
    $siblingSlug = 'cd3-sibling-insights-' . $suffix;
    $secondBranchSlug = 'cd3-second-branch-' . $suffix;
    $deletedSlug = 'cd3-deleted-between-' . $suffix;

    $alphaId = $insertCategory('CD3 Alpha First ' . $suffix, $alphaSlug);
    $strategyId = $insertCategory('CD3 Editorial Strategy ' . $suffix, $strategySlug);
    $marketsId = $insertCategory('CD3 Markets and Money ' . $suffix, $marketsSlug);
    $childId = $insertCategory('CD3 Child Insights ' . $suffix, $childSlug, $strategyId);
    $grandchildId = $insertCategory('CD3 Grandchild Details ' . $suffix, $grandchildSlug, $childId);
    $siblingId = $insertCategory('CD3 Sibling Insights ' . $suffix, $siblingSlug, $strategyId);
    $secondBranchId = $insertCategory('CD3 Second Branch ' . $suffix, $secondBranchSlug, $marketsId);
    $deletedId = $insertCategory('CD3 Deleted Between ' . $suffix, $deletedSlug);

    $resolver = new JobsArticleCategoryResolver();
    $allowlist = $resolver->getAvailableCategories();
    $allowedSlugs = $allowlist['slugs'];

    $assertSame('', $allowlist['error'], 'the current WordPress category allowlist loads successfully');
    $assertSame(true, in_array($alphaSlug, $allowedSlugs, true), 'a top-level category slug enters the AI allowlist');
    $assertSame(true, in_array($childSlug, $allowedSlugs, true), 'a child category slug remains independently selectable');

    $alphaResolution = $resolver->resolve(array($alphaSlug), $allowedSlugs);
    $assertSame(array($alphaId), $alphaResolution['term_ids'], 'one valid top-level category slug resolves exactly');
    $assertSame(array($alphaId), $alphaResolution['selected_term_ids'], 'a top-level selection remains the direct selected term');
    $assertSame(array(), $alphaResolution['ancestor_term_ids'], 'a top-level selection adds no ancestors');

    $strategyResolution = $resolver->resolve(array($strategySlug), $allowedSlugs);
    $assertSame(array($strategyId), $strategyResolution['term_ids'], 'another top-level category slug resolves exactly');

    $childResolution = $resolver->resolve(array($childSlug), $allowedSlugs);
    $assertSame(array($childId), $childResolution['selected_term_ids'], 'a child-category slug remains the direct AI-selected term');
    $assertSame(array($strategyId), $childResolution['ancestor_term_ids'], 'a child-category selection adds its parent automatically');
    $assertSame(array($strategyId, $childId), $childResolution['term_ids'], 'a child-category selection produces a root-to-child assignment');

    $grandchildResolution = $resolver->resolve(array($grandchildSlug), $allowedSlugs);
    $assertSame(array($grandchildId), $grandchildResolution['selected_term_ids'], 'a grandchild remains the direct AI-selected term');
    $assertSame(array($strategyId, $childId), $grandchildResolution['ancestor_term_ids'], 'a grandchild adds its complete ancestor chain');
    $assertSame(array($strategyId, $childId, $grandchildId), $grandchildResolution['term_ids'], 'a grandchild assignment is ordered from root to selected leaf');

    $sharedParentResolution = $resolver->resolve(array($childSlug, $siblingSlug), $allowedSlugs);
    $assertSame(array($strategyId, $childId, $siblingId), $sharedParentResolution['term_ids'], 'multiple children share one deduplicated parent');
    $assertSame(array($strategyId), $sharedParentResolution['ancestor_term_ids'], 'a shared parent is reported once as an automatic ancestor');

    $multipleBranchResolution = $resolver->resolve(array($secondBranchSlug, $childSlug), $allowedSlugs);
    $assertSame(
        array($strategyId, $childId, $marketsId, $secondBranchId),
        $multipleBranchResolution['term_ids'],
        'multiple hierarchy branches are ordered deterministically by selected slug'
    );
    $reversedBranchResolution = $resolver->resolve(array($childSlug, $secondBranchSlug), $allowedSlugs);
    $assertSame($multipleBranchResolution['term_ids'], $reversedBranchResolution['term_ids'], 'AI selection order does not change final hierarchy ordering');

    $multipleResolution = $resolver->resolve(array($strategySlug, $marketsSlug), $allowedSlugs);
    $assertSame(array($strategyId, $marketsId), $multipleResolution['term_ids'], 'multiple valid category slugs are preserved');

    $duplicateResolution = $resolver->resolve(array($strategySlug, $strategySlug), $allowedSlugs);
    $assertSame(array($strategyId), $duplicateResolution['term_ids'], 'duplicate returned slugs are deduplicated');
    $assertSame(array($strategySlug), $duplicateResolution['selected_slugs'], 'duplicate slugs produce one canonical selection');

    $unknownSlug = 'cd3-unknown-' . $suffix;
    $termCountBeforeUnknown = $categoryCount();
    $unknownResolution = $resolver->resolve(array($unknownSlug), $allowedSlugs);
    $termCountAfterUnknown = $categoryCount();
    $assertSame(array(), $unknownResolution['term_ids'], 'an unknown slug resolves to no term IDs');
    $assertSame(array(), $unknownResolution['ancestor_term_ids'], 'an invalid slug triggers no ancestor expansion');
    $assertSame(array($unknownSlug), $unknownResolution['rejected_slugs'], 'an unknown slug is reported explicitly');
    $assertSame($termCountBeforeUnknown, $termCountAfterUnknown, 'an unknown slug never creates a category term');

    $nameResolution = $resolver->resolve(array('CD3 Markets and Money ' . $suffix), $allowedSlugs);
    $assertSame(array(), $nameResolution['term_ids'], 'a category name is rejected when a slug was required');

    $malformedResolution = $resolver->resolve($strategySlug, $allowedSlugs);
    $assertSame('invalid_selection_type', $malformedResolution['error'], 'a malformed non-array selection is rejected');

    $emptyResolution = $resolver->resolve(array(), $allowedSlugs);
    $assertSame('empty_selection', $emptyResolution['error'], 'an empty category array is explicit');

    wp_delete_term($deletedId, 'category');
    $createdTermIds = array_values(array_filter(
        $createdTermIds,
        static function ($termId) use ($deletedId) {
            return absint($termId) !== $deletedId;
        }
    ));
    $deletedResolution = $resolver->resolve(array($deletedSlug), $allowedSlugs);
    $assertSame(array(), $deletedResolution['term_ids'], 'a category deleted after the request is not assigned');
    $assertSame('category_term_missing', $deletedResolution['rejections'][0]['reason'], 'deleted-category rejection has a stable reason');

    $orderResolution = $resolver->resolve(
        array($strategySlug),
        array($alphaSlug, $strategySlug, $marketsSlug)
    );
    $assertSame(array($strategyId), $orderResolution['term_ids'], 'allowlist order does not affect the selected category');
    $assertSame(false, in_array($alphaId, $orderResolution['term_ids'], true), 'the first allowlist category is not a fallback');

    $controllerReflection = new ReflectionClass(JobsExecutionController::class);
    $controller = $controllerReflection->newInstanceWithoutConstructor();
    $buildMessages = $controllerReflection->getMethod('buildMessages');
    $parseArticleResponse = $controllerReflection->getMethod('parseArticleResponse');
    $createPost = $controllerReflection->getMethod('createPost');
    $logCategoryWarning = $controllerReflection->getMethod('logCategoryResolutionWarning');

    $messages = $buildMessages->invoke(
        $controller,
        'Custom editorial prompt retained.',
        array(
            array(
                'category' => 'source-library-label',
                'filename' => 'source.md',
                'content'  => 'A source about editorial strategy.',
            ),
        ),
        'Author context retained.',
        array(
            array(
                'slug' => $alphaSlug,
                'name' => 'CD3 Alpha First ' . $suffix,
            ),
            array(
                'slug' => $strategySlug,
                'name' => 'CD3 Editorial Strategy ' . $suffix,
            ),
        )
    );
    $systemMessage = isset($messages[0]['content']) ? $messages[0]['content'] : '';
    $assertContains('Mandatory WordPress category-selection contract', $systemMessage, 'the category contract is mandatory');
    $assertContains($strategySlug, $systemMessage, 'the exact category slug reaches the AI request');
    $assertContains('select only the most specific appropriate slug', $systemMessage, 'the AI is instructed to select the specific category while WordPress owns ancestor expansion');
    $assertContains('Custom editorial prompt retained.', $systemMessage, 'a custom editorial prompt remains present');
    $assertContains('Author context retained.', $systemMessage, 'enabled author context remains present');

    $messagesWithoutAuthor = $buildMessages->invoke(
        $controller,
        'Global editorial prompt retained.',
        array(
            array(
                'category' => 'source-library-label',
                'filename' => 'source.md',
                'content'  => 'A second source.',
            ),
        ),
        '',
        array(
            array(
                'slug' => $marketsSlug,
                'name' => 'CD3 Markets and Money ' . $suffix,
            ),
        )
    );
    $systemWithoutAuthor = isset($messagesWithoutAuthor[0]['content']) ? $messagesWithoutAuthor[0]['content'] : '';
    $assertContains($marketsSlug, $systemWithoutAuthor, 'the category contract remains when author context is disabled');
    $assertContains('Global editorial prompt retained.', $systemWithoutAuthor, 'the global editorial prompt remains present');

    $validResponse = sprintf(
        "# CD3 Strategy Article\n\n<p>Focused article body.</p>\n\n===SEO_META_START===\nSEO_TITLE: CD3 Strategy Article\nSEO_DESCRIPTION: Focused strategy description.\nFOCUS_KEYPHRASE: editorial strategy\nCATEGORY_SLUGS_JSON: [\"%s\"]\n===SEO_META_END===",
        $strategySlug
    );
    $parsedValidResponse = $parseArticleResponse->invoke($controller, $validResponse);
    $assertSame(array($strategySlug), $parsedValidResponse['category_slugs'], 'the structured AI response returns a slug array');
    $assertSame('', $parsedValidResponse['category_selection_error'], 'valid category JSON has no parse error');

    $malformedResponse = "# Invalid Category Shape\n\n<p>Body.</p>\n\n===SEO_META_START===\nSEO_TITLE: Invalid Category Shape\nSEO_DESCRIPTION: Invalid category shape test.\nFOCUS_KEYPHRASE: category test\nCATEGORY_SLUGS_JSON: \"{$strategySlug}\"\n===SEO_META_END===";
    $parsedMalformedResponse = $parseArticleResponse->invoke($controller, $malformedResponse);
    $assertSame(array(), $parsedMalformedResponse['category_slugs'], 'a scalar category response never becomes a slug list');
    $assertSame('category_slugs_invalid_type', $parsedMalformedResponse['category_selection_error'], 'a scalar category response has a stable parse error');

    $duplicateFieldResponse = sprintf(
        "# Duplicate Category Field\n\n<p>Body.</p>\n\n===SEO_META_START===\nSEO_TITLE: Duplicate Category Field\nSEO_DESCRIPTION: Duplicate category field test.\nFOCUS_KEYPHRASE: category test\nCATEGORY_SLUGS_JSON: [\"%s\"]\nCATEGORY_SLUGS_JSON: [\"%s\"]\n===SEO_META_END===",
        $strategySlug,
        $marketsSlug
    );
    $parsedDuplicateFieldResponse = $parseArticleResponse->invoke($controller, $duplicateFieldResponse);
    $assertSame('category_slugs_duplicate', $parsedDuplicateFieldResponse['category_selection_error'], 'a duplicate category field has a category-specific parse error');
    $assertSame(false, isset($parsedDuplicateFieldResponse['seo_meta']['invalid_fields']['category_slugs_json']), 'category errors remain separate from SEO validation errors');

    $users = get_users(array(
        'number' => 1,
        'fields' => 'ID',
    ));
    $authorId = !empty($users) ? absint($users[0]) : 1;
    $assignedPostId = $createPost->invoke(
        $controller,
        'CD3 AI Slug Assignment ' . $suffix,
        '<p>Strict category assignment body.</p>',
        'post',
        'draft',
        $authorId,
        $grandchildResolution['term_ids']
    );

    if (is_wp_error($assignedPostId)) {
        throw new RuntimeException($assignedPostId->get_error_message());
    }

    $assignedPostId = absint($assignedPostId);
    array_push($createdPostIds, $assignedPostId);
    $assignedPostCategories = wp_get_post_categories($assignedPostId);
    sort($assignedPostCategories, SORT_NUMERIC);
    $expectedPostCategories = array($strategyId, $childId, $grandchildId);
    sort($expectedPostCategories, SORT_NUMERIC);
    $assertSame($expectedPostCategories, $assignedPostCategories, 'the selected grandchild and every validated ancestor are assigned to the post');

    $defaultPostId = $createPost->invoke(
        $controller,
        'CD3 No Invalid Fallback ' . $suffix,
        '<p>Invalid selections supply no unrelated term IDs.</p>',
        'post',
        'draft',
        $authorId,
        $unknownResolution['term_ids']
    );

    if (is_wp_error($defaultPostId)) {
        throw new RuntimeException($defaultPostId->get_error_message());
    }

    $defaultPostId = absint($defaultPostId);
    array_push($createdPostIds, $defaultPostId);
    $defaultPostCategories = wp_get_post_categories($defaultPostId);
    $assertSame(false, in_array($alphaId, $defaultPostCategories, true), 'invalid selection does not assign the first allowlist term');

    global $wpdb;
    $logsTable = $wpdb->prefix . 'exmoau_logs';
    $previousLogId = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$logsTable}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
    $logCategoryWarning->invoke($controller, 0, $allowedSlugs, $unknownResolution);
    $loggedWarningId = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$logsTable} WHERE id > %d AND source = %s AND level = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $previousLogId,
            'jobs.categorisation',
            'warning'
        )
    ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

    if ($loggedWarningId > 0) {
        array_push($createdLogIds, $loggedWarningId);
    }

    $assertSame(true, $loggedWarningId > 0, 'invalid AI category selection is logged separately');

    foreach (array_merge($results, $failures) as $line) {
        fwrite(STDOUT, $line . "\n");
    }

    fwrite(
        STDOUT,
        wp_json_encode(array(
            'suffix'        => $suffix,
            'term_ids'      => $createdTermIds,
            'post_ids'      => $createdPostIds,
            'log_ids'       => $createdLogIds,
            'keep_fixtures' => $keepFixtures,
            'failure_count' => count($failures),
        )) . "\n"
    );
} catch (Throwable $exception) {
    array_push($failures, 'FAIL: unexpected exception: ' . $exception->getMessage());
    fwrite(STDERR, end($failures) . "\n");
} finally {
    if (!$keepFixtures) {
        foreach (array_reverse($createdPostIds) as $postId) {
            wp_delete_post($postId, true);
        }

        foreach (array_reverse($createdTermIds) as $termId) {
            wp_delete_term($termId, 'category');
        }

        foreach (array_reverse($createdLogIds) as $logId) {
            $wpdb->delete(
                $wpdb->prefix . 'exmoau_logs',
                array('id' => absint($logId)),
                array('%d')
            ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        }
    }
}

if (!empty($failures)) {
    exit(1);
}
