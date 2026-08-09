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
        $results[] = 'PASS: ' . $label;

        return;
    }

    $failures[] = sprintf(
        'FAIL: %s (expected %s, actual %s)',
        $label,
        wp_json_encode($expected),
        wp_json_encode($actual)
    );
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
    $createdTermIds[] = $termId;

    return $termId;
};

try {
    $alphaId = $insertCategory('CD3 Alpha First ' . $suffix, 'cd3-alpha-first-' . $suffix);
    $strategyId = $insertCategory('CD3 Editorial Strategy ' . $suffix, 'cd3-editorial-strategy-' . $suffix);
    $customSlugId = $insertCategory('CD3 Markets and Money ' . $suffix, 'cd3-materially-different-' . $suffix);
    $childId = $insertCategory('CD3 Child Insights ' . $suffix, 'cd3-child-insights-' . $suffix, $strategyId);
    $entityId = $insertCategory('CD3 Research & Analysis ' . $suffix, 'cd3-research-analysis-' . $suffix);

    $resolver = new JobsArticleCategoryResolver();

    $resolvedById = $resolver->resolve(array((string) $strategyId));
    $assertSame(array($strategyId), $resolvedById['term_ids'], 'exact numeric ID is preferred');

    $resolvedAlpha = $resolver->resolve(array('cd3-alpha-first-' . $suffix));
    $assertSame(array($alphaId), $resolvedAlpha['term_ids'], 'first top-level category resolves by exact slug');

    $resolvedStrategy = $resolver->resolve(array(strtoupper('CD3 Editorial Strategy ' . $suffix)));
    $assertSame(array($strategyId), $resolvedStrategy['term_ids'], 'another top-level category resolves by normalized exact name');

    $resolvedChild = $resolver->resolve(array('cd3-child-insights-' . $suffix));
    $assertSame(array($childId), $resolvedChild['term_ids'], 'child category resolves without collapsing to its parent');

    $resolvedCustomSlug = $resolver->resolve(array('cd3-materially-different-' . $suffix));
    $assertSame(array($customSlugId), $resolvedCustomSlug['term_ids'], 'materially different display name and slug resolve by exact slug');

    $resolvedEntity = $resolver->resolve(array('CD3 Research &amp; Analysis ' . $suffix));
    $assertSame(array($entityId), $resolvedEntity['term_ids'], 'HTML entity encoding is normalized for exact name matching');

    $resolvedOrder = $resolver->resolve(array(
        'cd3-materially-different-' . $suffix,
        'cd3-alpha-first-' . $suffix,
        'cd3-child-insights-' . $suffix,
    ));
    $assertSame(
        array($customSlugId, $alphaId, $childId),
        $resolvedOrder['term_ids'],
        'category order does not force the first available term'
    );

    $unresolved = $resolver->resolve(array('alpha-first-' . $suffix));
    $assertSame(array(), $unresolved['term_ids'], 'loose substring matching is rejected');
    $assertSame(array('alpha-first-' . $suffix), $unresolved['unresolved'], 'failed matching is explicit');

    $malformed = $resolver->resolve(array('', null, array('not-scalar')));
    $assertSame(array(), $malformed['term_ids'], 'empty and malformed references do not resolve');
    $assertSame(array('(empty)', '(invalid)'), $malformed['unresolved'], 'empty and malformed references are reported explicitly');

    $controller = new JobsExecutionController();
    $createPost = new ReflectionMethod($controller, 'createPost');
    $resolvePostCategories = new ReflectionMethod($controller, 'resolvePostCategories');

    global $wpdb;
    $logsTable = $wpdb->prefix . 'exmoau_logs';
    $previousLogId = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$logsTable}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
    $loggedResolution = $resolvePostCategories->invoke(
        $controller,
        array(
            array(
                'category' => 'cd3-no-such-category-' . $suffix,
            ),
        ),
        'post',
        0
    );
    $assertSame(
        array('cd3-no-such-category-' . $suffix),
        $loggedResolution['unresolved'],
        'the execution path reports unresolved source-category context'
    );

    $loggedWarningId = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$logsTable} WHERE id > %d AND source = %s AND level = %s ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $previousLogId,
            'jobs.categorisation',
            'warning'
        )
    ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    if ($loggedWarningId > 0) {
        $createdLogIds[] = $loggedWarningId;
    }
    $assertSame(true, $loggedWarningId > 0, 'failed resolution is persisted as an explicit warning');

    $assignedPostId = $createPost->invoke(
        $controller,
        'CD3 Categorisation Assigned ' . $suffix,
        '<p>Focused runtime validation body.</p>',
        'post',
        'draft',
        1,
        array($childId)
    );

    if (is_wp_error($assignedPostId)) {
        throw new RuntimeException($assignedPostId->get_error_message());
    }

    $assignedPostId = absint($assignedPostId);
    $createdPostIds[] = $assignedPostId;
    $assertSame(array($childId), wp_get_post_categories($assignedPostId), 'post insertion assigns only the resolved child term');

    $multiPostId = $createPost->invoke(
        $controller,
        'CD3 Categorisation Multi ' . $suffix,
        '<p>Multiple legitimate source categories.</p>',
        'post',
        'draft',
        1,
        array($customSlugId, $alphaId)
    );

    if (is_wp_error($multiPostId)) {
        throw new RuntimeException($multiPostId->get_error_message());
    }

    $multiPostId = absint($multiPostId);
    $createdPostIds[] = $multiPostId;
    $actualMultiIds = wp_get_post_categories($multiPostId);
    sort($actualMultiIds);
    $expectedMultiIds = array($customSlugId, $alphaId);
    sort($expectedMultiIds);
    $assertSame($expectedMultiIds, $actualMultiIds, 'multiple legitimate category matches are preserved');

    $defaultPostId = $createPost->invoke(
        $controller,
        'CD3 Categorisation Default ' . $suffix,
        '<p>Invalid categories must not select the first created term.</p>',
        'post',
        'draft',
        1,
        array(999999999)
    );

    if (is_wp_error($defaultPostId)) {
        throw new RuntimeException($defaultPostId->get_error_message());
    }

    $defaultPostId = absint($defaultPostId);
    $createdPostIds[] = $defaultPostId;
    $defaultCategoryId = absint(get_option('default_category'));
    $assertSame(array($defaultCategoryId), wp_get_post_categories($defaultPostId), 'invalid IDs use WordPress default behavior, not the first available fixture');

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
    $failures[] = 'FAIL: unexpected exception: ' . $exception->getMessage();
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
