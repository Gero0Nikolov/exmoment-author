<?php

use ExMomentAuthor\Modules\Jobs\JobsExecutionController;
use ExMomentAuthor\Modules\Seo\YoastSeoIntegration;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI: wp eval-file tests/runtime/yoast-seo-title-template-regression.php\n");
    exit(1);
}

$failures = array();
$results = array();
$createdPostIds = array();
$originalTitlesOption = null;
$originalSiteTitle = null;

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

try {
    $plainTitle = 'How to Get Out of Debt';
    $expectedTemplate = $plainTitle . ' %%sep%% %%sitename%%';

    $assertSame(
        $expectedTemplate,
        YoastSeoIntegration::composeYoastTitleTemplate($plainTitle),
        'A: a plain generated title receives the canonical Yoast suffix'
    );
    $assertSame(
        $expectedTemplate,
        YoastSeoIntegration::composeYoastTitleTemplate($expectedTemplate),
        'B: a title already containing both variables is not duplicated'
    );
    $assertSame(
        $expectedTemplate,
        YoastSeoIntegration::composeYoastTitleTemplate($plainTitle . ' %%sep%%'),
        'C: a title containing only the separator variable is canonicalized once'
    );
    $assertSame(
        $expectedTemplate,
        YoastSeoIntegration::composeYoastTitleTemplate($plainTitle . ' %%sitename%%'),
        'D: a title containing only the site-name variable is canonicalized once'
    );
    $assertSame(
        1,
        substr_count(YoastSeoIntegration::composeYoastTitleTemplate($expectedTemplate), '%%sep%%'),
        'canonical composition contains one separator variable'
    );
    $assertSame(
        1,
        substr_count(YoastSeoIntegration::composeYoastTitleTemplate($expectedTemplate), '%%sitename%%'),
        'canonical composition contains one site-name variable'
    );
    $assertSame(
        '',
        YoastSeoIntegration::composeYoastTitleTemplate(''),
        'E: an empty title preserves invalid-title handling'
    );
    $assertSame(
        '',
        YoastSeoIntegration::composeYoastTitleTemplate(array('malformed')),
        'F: a non-string generated title is rejected safely'
    );
    $assertSame(
        'Budget - Planning Guide %%sep%% %%sitename%%',
        YoastSeoIntegration::composeYoastTitleTemplate('Budget - Planning Guide'),
        'literal title punctuation is not stripped or guessed'
    );

    $activePostId = wp_insert_post(
        array(
            'post_title'   => 'Yoast title integration regression',
            'post_content' => '<p>Temporary regression fixture.</p>',
            'post_status'  => 'draft',
            'post_type'    => 'post',
        ),
        true
    );

    if (is_wp_error($activePostId)) {
        throw new RuntimeException($activePostId->get_error_message());
    }

    $activePostId = absint($activePostId);
    array_push($createdPostIds, $activePostId);
    $integration = new YoastSeoIntegration();
    $jobController = new JobsExecutionController();
    $jobControllerReflection = new ReflectionClass(JobsExecutionController::class);
    $yoastProperty = $jobControllerReflection->getProperty('yoastSeoIntegration');
    $yoastProperty->setValue($jobController, $integration);
    $persistSeoMeta = $jobControllerReflection->getMethod('maybePopulateYoastSeoMeta');

    $assertSame(true, $integration->isActive(), 'the installed Yoast integration is detected as active');
    $assertSame(
        40,
        has_action('save_post_exmoau_job', array($jobController, 'maybeRunJobOnSave')),
        'the publish-transition job hook is registered at the canonical execution priority'
    );

    $persistSeoMeta->invoke(
        $jobController,
        $activePostId,
        array(
            'seo_title'       => $plainTitle,
            'seo_description' => 'A focused description long enough to satisfy the existing Yoast metadata validation contract.',
            'focus_keyphrase' => 'debt reduction plan',
        )
    );

    $storedTemplate = get_post_meta($activePostId, '_yoast_wpseo_title', true);
    $assertSame(
        $expectedTemplate,
        $storedTemplate,
        'the real job metadata persistence method stores the variable-based template'
    );

    $firstSaveResult = wp_update_post(
        array(
            'ID'           => $activePostId,
            'post_content' => '<p>Temporary regression fixture after a normal post update.</p>',
        ),
        true
    );
    $assertSame($activePostId, $firstSaveResult, 'a normal post update completes after job metadata persistence');
    $assertSame(
        $expectedTemplate,
        get_post_meta($activePostId, '_yoast_wpseo_title', true),
        'a later post-save step does not overwrite the canonical template with the raw title'
    );

    wp_update_post(
        array(
            'ID'          => $activePostId,
            'post_status' => 'publish',
        )
    );
    $assertSame(
        $expectedTemplate,
        get_post_meta($activePostId, '_yoast_wpseo_title', true),
        'a draft-to-publish transition preserves the canonical persisted template'
    );
    $assertSame(
        1,
        substr_count(get_post_meta($activePostId, '_yoast_wpseo_title', true), '%%sep%%'),
        'repeated post saves leave exactly one separator variable'
    );
    $assertSame(
        1,
        substr_count(get_post_meta($activePostId, '_yoast_wpseo_title', true), '%%sitename%%'),
        'repeated post saves leave exactly one site-name variable'
    );

    $integration->maybeUpdatePostSeo(
        $activePostId,
        'A different generated title',
        'A different valid description confirms an existing generated SEO title remains protected.',
        'existing title protection'
    );
    $assertSame(
        $expectedTemplate,
        get_post_meta($activePostId, '_yoast_wpseo_title', true),
        'an existing valid generated title template is not overwritten'
    );

    $maximumTitleTemplate = str_repeat('A', 60) . ' %%sep%% %%sitename%%';
    update_post_meta($activePostId, '_yoast_wpseo_title', $maximumTitleTemplate);
    $integration->maybeUpdatePostSeo(
        $activePostId,
        'Another valid generated title',
        'Another valid description confirms template variables do not invalidate a 60-character base title.',
        'maximum title protection'
    );
    $assertSame(
        $maximumTitleTemplate,
        get_post_meta($activePostId, '_yoast_wpseo_title', true),
        'a valid 60-character existing title remains protected after adding Yoast variables'
    );

    $detectionProperty = new ReflectionProperty(YoastSeoIntegration::class, 'yoastDetected');
    $detectionProperty->setValue(null, false);
    delete_post_meta($activePostId, '_yoast_wpseo_title');

    $integration->maybeUpdatePostSeo(
        $activePostId,
        'Safe unavailable title',
        'A second valid description proves unavailable Yoast handling returns without writing metadata.',
        'safe unavailable handling'
    );

    $assertSame(
        '',
        get_post_meta($activePostId, '_yoast_wpseo_title', true),
        'G: unavailable Yoast handling returns safely without writing title meta'
    );
    $detectionProperty->setValue(null, null);

    if (!function_exists('wpseo_replace_vars') || !class_exists('WPSEO_Option_Titles')) {
        throw new RuntimeException('Yoast variable replacement APIs are unavailable.');
    }

    $post = get_post($activePostId);
    if (!($post instanceof WP_Post)) {
        throw new RuntimeException('Temporary Yoast regression post could not be loaded.');
    }

    $originalTitlesOption = get_option('wpseo_titles', array());
    $originalSiteTitle = get_option('blogname', '');
    $separatorOptions = WPSEO_Option_Titles::get_instance()->get_separator_options();
    $originalSeparatorKey = isset($originalTitlesOption['separator']) ? $originalTitlesOption['separator'] : '';
    $temporarySeparatorKey = '';

    foreach ($separatorOptions as $separatorKey => $separatorValue) {
        if ($separatorKey !== $originalSeparatorKey) {
            $temporarySeparatorKey = $separatorKey;
            break;
        }
    }

    if ($temporarySeparatorKey === '') {
        throw new RuntimeException('No alternate Yoast separator is available for regression validation.');
    }

    $temporarySiteTitle = 'ExMoment Yoast Variable Regression';
    update_option('blogname', $temporarySiteTitle);
    $siteTitleRendered = wpseo_replace_vars($expectedTemplate, $post);

    $assertContains(
        $temporarySiteTitle,
        $siteTitleRendered,
        'the Yoast site-name variable resolves the current WordPress site title dynamically'
    );

    update_option('blogname', $originalSiteTitle);
    $originalSiteTitle = null;

    $originalRenderedTitle = $siteTitleRendered;
    $temporaryTitlesOption = $originalTitlesOption;
    $temporaryTitlesOption['separator'] = $temporarySeparatorKey;
    update_option('wpseo_titles', $temporaryTitlesOption);
    WPSEO_Options::clear_cache();

    $temporaryRenderedTitle = wpseo_replace_vars($expectedTemplate, $post);
    $temporarySeparator = html_entity_decode(
        $separatorOptions[$temporarySeparatorKey],
        ENT_QUOTES,
        'UTF-8'
    );

    $assertSame(
        true,
        $originalRenderedTitle !== $temporaryRenderedTitle,
        'H: changing the Yoast separator changes the rendered title without changing stored variables'
    );
    $assertContains(
        $temporarySeparator,
        html_entity_decode($temporaryRenderedTitle, ENT_QUOTES, 'UTF-8'),
        'the changed rendered title contains Yoast\'s configured alternate separator'
    );
    $assertSame(
        $expectedTemplate,
        YoastSeoIntegration::composeYoastTitleTemplate($plainTitle),
        'separator changes do not alter the stored title-template contract'
    );

    update_option('wpseo_titles', $originalTitlesOption);
    WPSEO_Options::clear_cache();
    $originalTitlesOption = null;
} catch (Throwable $exception) {
    array_push($failures, 'FAIL: regression setup (' . $exception->getMessage() . ')');
} finally {
    if (is_array($originalTitlesOption)) {
        update_option('wpseo_titles', $originalTitlesOption);

        if (class_exists('WPSEO_Options')) {
            WPSEO_Options::clear_cache();
        }
    }

    if (is_string($originalSiteTitle)) {
        update_option('blogname', $originalSiteTitle);
    }

    if (isset($detectionProperty) && $detectionProperty instanceof ReflectionProperty) {
        $detectionProperty->setValue(null, null);
    }

    foreach (array_reverse($createdPostIds) as $createdPostId) {
        wp_delete_post($createdPostId, true);
    }
}

foreach (array_merge($results, $failures) as $line) {
    fwrite(STDOUT, $line . "\n");
}

if (!empty($failures)) {
    exit(1);
}
