<?php

use ExMomentAuthor\Modules\Jobs\JobsMetaController;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI: wp eval-file tests/runtime/library-listing-regression.php\n");
    exit(1);
}

$passed = 0;
$failed = 0;
$temporaryJobId = 0;
$originalPost = $_POST;
$originalReferer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null;
$originalUserId = get_current_user_id();

$assert = static function ($condition, $message) use (&$passed, &$failed) {
    if ($condition) {
        ++$passed;
        echo 'PASS: ' . $message . PHP_EOL;

        return;
    }

    ++$failed;
    echo 'FAIL: ' . $message . PHP_EOL;
};

try {
    wp_set_current_user(1);

    $temporaryJobId = wp_insert_post(
        array(
            'post_title'  => 'Library listing regression job',
            'post_status' => 'draft',
            'post_type'   => 'exmoau_job',
        ),
        true
    );
    $assert(is_int($temporaryJobId) && $temporaryJobId > 0, 'temporary Author job created');

    update_post_meta(
        $temporaryJobId,
        'exmoau_setup_mixture_directories',
        array('available-category', 'removed-category')
    );
    update_post_meta($temporaryJobId, 'exmoau_setup_mixture_uniqueness', '1');
    update_post_meta($temporaryJobId, 'exmoau_setup_mixture_per_category', '3');
    update_post_meta($temporaryJobId, 'exmoau_setup_directive_post_type', 'post');
    update_post_meta($temporaryJobId, 'exmoau_setup_directive_post_status', 'draft');
    update_post_meta($temporaryJobId, 'exmoau_setup_directive_post_author', '1');
    update_post_meta($temporaryJobId, 'exmoau_setup_directive_generation_count', '1');

    $controller = new JobsMetaController();
    $reflection = new ReflectionClass(JobsMetaController::class);
    $libraryProperty = $reflection->getProperty('libraryStructure');
    $libraryProperty->setAccessible(true);
    $libraryProperty->setValue(
        $controller,
        array(
            'available-category' => array(
                'label' => 'available-category',
            ),
        )
    );

    ob_start();
    $controller->renderSetupMetaBox(get_post($temporaryJobId));
    $markup = (string) ob_get_clean();

    $matches = array();
    $matched = preg_match('/data-exmoau-job-setup="([^"]+)"/', $markup, $matches) === 1;
    $config = $matched
        ? json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true)
        : null;

    $assert(is_array($config), 'server-rendered setup configuration is valid JSON');
    $assert(
        is_array($config) && $config['directories'] === array('available-category'),
        'stale stored directories are omitted from the browser request state'
    );
    $assert(
        get_post_meta($temporaryJobId, 'exmoau_setup_mixture_directories', true) === array('available-category', 'removed-category'),
        'rendering does not mutate the stored job configuration'
    );

    $_SERVER['HTTP_REFERER'] = home_url('/wp-admin/post.php?post=' . $temporaryJobId . '&action=edit');
    $_POST = array(
        'action'                 => 'exmoau_get_mixture_tab',
        'nonce'                  => wp_create_nonce('exmoau_job_setup_tabs'),
        'post_id'                => (string) $temporaryJobId,
        'page'                   => '1',
        'uniqueness'             => '1',
        'selected_directories'   => $config['directories'],
        'per_category'           => '3',
        'directive'              => 'post',
    );

    $prepare = $reflection->getMethod('prepareSetupAjaxRequest');
    $prepare->setAccessible(true);
    $context = $prepare->invoke($controller);
    $assert(is_array($context), 'the previously failing rendered request shape now validates');
    $assert(
        is_array($context) && $context['selected_directories'] === array('available-category'),
        'the validated request retains the available directory'
    );

    $ajaxDieHandler = static function () {
        return static function () {
            throw new RuntimeException('ajax-complete');
        };
    };
    add_filter('wp_die_ajax_handler', $ajaxDieHandler);

    ob_start();
    try {
        $controller->handleMixtureTabAjax();
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'ajax-complete') {
            throw $exception;
        }
    }
    $responseBody = (string) ob_get_clean();
    remove_filter('wp_die_ajax_handler', $ajaxDieHandler);
    $response = json_decode($responseBody, true);

    $assert(is_array($response) && $response['success'] === true, 'Mixture AJAX handler returns success');
    $assert(
        is_array($response) && array_keys($response['data']) === array(
            'html',
            'directories',
            'page',
            'totalPages',
            'totalDirectories',
            'pageSize',
            'selected',
            'uniqueness',
            'perCategory',
            'perCategoryAdjusted',
        ),
        'Mixture AJAX response structure is preserved'
    );
    $assert(
        is_array($response) && $response['data']['directories'] === array('available-category'),
        'Mixture AJAX response exposes only allowlisted directories'
    );

    $_POST['selected_directories'] = array('removed-category');
    $invalidStale = $prepare->invoke($controller);
    $assert(
        is_wp_error($invalidStale) && $invalidStale->get_error_code() === 'invalid_directories',
        'a forged stale directory remains rejected'
    );

    $_POST['selected_directories'] = array('../outside-library');
    $invalidTraversal = $prepare->invoke($controller);
    $assert(
        is_wp_error($invalidTraversal) && $invalidTraversal->get_error_code() === 'invalid_directories',
        'directory traversal input remains rejected'
    );

    $_POST['selected_directories'] = array('available-category');
    $_POST['nonce'] = 'invalid';
    $invalidNonce = $prepare->invoke($controller);
    $assert(
        is_wp_error($invalidNonce) && $invalidNonce->get_error_code() === 'invalid_nonce',
        'invalid nonce remains rejected'
    );

    $_POST['nonce'] = wp_create_nonce('exmoau_job_setup_tabs');
    $_SERVER['HTTP_REFERER'] = 'https://attacker.invalid/';
    $invalidReferer = $prepare->invoke($controller);
    $assert(
        is_wp_error($invalidReferer) && $invalidReferer->get_error_code() === 'invalid_referer',
        'cross-host referrer remains rejected'
    );
} finally {
    $_POST = $originalPost;

    if ($originalReferer === null) {
        unset($_SERVER['HTTP_REFERER']);
    } else {
        $_SERVER['HTTP_REFERER'] = $originalReferer;
    }

    wp_set_current_user($originalUserId);

    if (is_int($temporaryJobId) && $temporaryJobId > 0) {
        wp_delete_post($temporaryJobId, true);
    }
}

echo sprintf('RESULT: %d passed, %d failed', $passed, $failed) . PHP_EOL;

if ($failed > 0) {
    exit(1);
}
