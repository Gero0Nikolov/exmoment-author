<?php

use ExMomentAuthor\Modules\Gpt\GptController;
use ExMomentAuthor\Modules\Jobs\JobsAiContextResolver;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file through WP-CLI: wp eval-file tests/runtime/featured-image-prompt-regression.php\n");
    exit(1);
}

$failures = array();
$results = array();

$assertContains = static function ($needle, $haystack, $label) use (&$failures, &$results) {
    if (is_string($haystack) && strpos($haystack, $needle) !== false) {
        array_push($results, 'PASS: ' . $label);

        return;
    }

    array_push($failures, sprintf('FAIL: %s (missing %s)', $label, wp_json_encode($needle)));
};

$assertNotContains = static function ($needle, $haystack, $label) use (&$failures, &$results) {
    if (is_string($haystack) && strpos($haystack, $needle) === false) {
        array_push($results, 'PASS: ' . $label);

        return;
    }

    array_push($failures, sprintf('FAIL: %s (unexpected %s)', $label, wp_json_encode($needle)));
};

$controllerReflection = new ReflectionClass(GptController::class);
$controller = $controllerReflection->newInstanceWithoutConstructor();
$buildPrompt = $controllerReflection->getMethod('buildImagePromptForPost');
$post = new WP_Post(
    (object) array(
        'ID'           => 611,
        'post_author'  => 1,
        'post_title'   => 'Protect your peace first: reduce triggers that steal your sleep',
        'post_content' => '<p>Protect your sleep by reducing disturbances and planning ahead for identity theft risks.</p>',
    )
);

$authorContext = JobsAiContextResolver::buildImageAuthorContext('Elias Cohen');
$authorPrompt = $buildPrompt->invoke($controller, $post, 'Natural editorial photography.', $authorContext);
$neutralPrompt = $buildPrompt->invoke($controller, $post, '', '');

$assertContains(
    'Protect your peace first: reduce triggers that steal your sleep',
    $authorPrompt,
    'the article title remains the primary image subject'
);
$assertContains(
    'rather than a generic lifestyle scene',
    $authorPrompt,
    'generic stock-photo composition is explicitly discouraged'
);
$assertContains(
    'instead of defaulting to women',
    $authorPrompt,
    'repetitive female-subject casting is explicitly discouraged'
);
$assertContains('Elias Cohen', $authorPrompt, 'the selected public author name reaches the image prompt');
$assertContains(
    'align that subject\'s gender presentation',
    $authorPrompt,
    'the public author name guides an article-relevant primary subject'
);
$assertContains(
    'If the name is ambiguous, use a gender-neutral or person-free composition.',
    $authorPrompt,
    'ambiguous public names have a safe composition fallback'
);
$assertContains(
    'instead of defaulting to women',
    $neutralPrompt,
    'subject-variation guidance also applies when author context is disabled'
);
$assertNotContains('Elias Cohen', $neutralPrompt, 'disabled author context does not expose the author name');

foreach (array_merge($results, $failures) as $line) {
    fwrite(STDOUT, $line . "\n");
}

if (!empty($failures)) {
    exit(1);
}
