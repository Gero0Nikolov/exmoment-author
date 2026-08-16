<?php

use ExMomentAuthor\Modules\Jobs\JobsExecutionController;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI: wp eval-file tests/runtime/post-generated-hook-regression.php\n");
    exit(1);
}

$passed = 0;
$failed = 0;
$temporaryPostId = 0;
$temporaryJobId = 0;
$hookName = 'exmoau_post_generated';
$originalHook = isset($GLOBALS['wp_filter'][$hookName]) ? $GLOBALS['wp_filter'][$hookName] : null;

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
    unset($GLOBALS['wp_filter'][$hookName]);

    $temporaryJobId = wp_insert_post(
        array(
            'post_title'  => 'Post generated hook regression job',
            'post_status' => 'draft',
            'post_type'   => 'exmoau_job',
        ),
        true
    );
    $temporaryPostId = wp_insert_post(
        array(
            'post_title'   => 'Post generated hook regression article',
            'post_content' => 'Persisted content available to optional lifecycle listeners.',
            'post_status'  => 'draft',
            'post_type'    => 'post',
        ),
        true
    );

    $assert(is_int($temporaryJobId) && $temporaryJobId > 0, 'temporary Author job created');
    $assert(is_int($temporaryPostId) && $temporaryPostId > 0, 'temporary generated post created');

    $controller = new JobsExecutionController();
    $reflection = new ReflectionClass(JobsExecutionController::class);
    $dispatcher = $reflection->getMethod('dispatchPostGeneratedEvent');
    $dispatcher->setAccessible(true);
    $events = array();

    $listener = static function ($postId, $jobId, $context) use (&$events) {
        $events[] = array(
            'postId'  => $postId,
            'jobId'   => $jobId,
            'context' => $context,
        );
    };
    add_action($hookName, $listener, 10, 3);

    $cases = array(
        array('executionType' => 'single_instant', 'trigger' => 'manual', 'index' => 1, 'count' => 1),
        array('executionType' => 'single_scheduled', 'trigger' => 'schedule', 'index' => 1, 'count' => 1),
        array('executionType' => 'repeating_scheduled', 'trigger' => 'schedule', 'index' => 2, 'count' => 3),
    );

    foreach ($cases as $case) {
        $dispatcher->invoke(
            $controller,
            $temporaryPostId,
            $temporaryJobId,
            $case['executionType'],
            array(
                'trigger'              => $case['trigger'],
                'generation_iteration' => $case['index'],
                'generation_total'     => $case['count'],
                'article_body'         => 'must not be forwarded',
                'provider_secret'      => 'must not be forwarded',
            )
        );
    }

    $assert(count($events) === 3, 'one event fires for each applicable per-post execution mode');

    foreach ($events as $index => $event) {
        $case = $cases[$index];
        $assert($event['postId'] === $temporaryPostId, 'event carries the exact generated post ID');
        $assert($event['jobId'] === $temporaryJobId, 'event carries the exact originating job ID');
        $assert(
            array_keys($event['context']) === array('executionType', 'generationIndex', 'generationCount', 'trigger'),
            'event context contains only allowlisted keys'
        );
        $assert($event['context']['executionType'] === $case['executionType'], 'execution type is retained');
        $assert($event['context']['generationIndex'] === $case['index'], 'generation index is retained');
        $assert($event['context']['generationCount'] === $case['count'], 'generation count is retained');
        $assert($event['context']['trigger'] === $case['trigger'], 'trigger is retained');
    }

    remove_action($hookName, $listener, 10);
    $dispatcher->invoke(
        $controller,
        $temporaryPostId,
        $temporaryJobId,
        'single_instant',
        array(
            'trigger'              => 'manual',
            'generation_iteration' => 1,
            'generation_total'     => 1,
        )
    );
    $assert(count($events) === 3, 'listener absence has no effect and creates no additional event observation');
} finally {
    unset($GLOBALS['wp_filter'][$hookName]);
    if ($originalHook !== null) {
        $GLOBALS['wp_filter'][$hookName] = $originalHook;
    }

    if (is_int($temporaryPostId) && $temporaryPostId > 0) {
        wp_delete_post($temporaryPostId, true);
    }

    if (is_int($temporaryJobId) && $temporaryJobId > 0) {
        wp_delete_post($temporaryJobId, true);
    }
}

echo sprintf('RESULT: %d passed, %d failed', $passed, $failed) . PHP_EOL;

if ($failed > 0) {
    exit(1);
}
