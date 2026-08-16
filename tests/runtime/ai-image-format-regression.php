<?php

use ExMomentAuthor\Modules\Ai\AiService;
use ExMomentAuthor\Modules\Gpt\GptController;
use ExMomentAuthor\Modules\Settings\SettingsController;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run this file after loading WordPress.\n");
    exit(1);
}

if (!function_exists('add_settings_error')) {
    require_once ABSPATH . 'wp-admin/includes/template.php';
}

$failures = array();
$results = array();
$createdAttachmentIds = array();
$createdPostIds = array();
$optionName = 'exmoau_ai_image_format';
$optionSentinel = new stdClass();
$originalOption = get_option($optionName, $optionSentinel);
$originalUserId = get_current_user_id();
$administrators = get_users(
    array(
        'role'   => 'administrator',
        'number' => 1,
        'fields' => 'ID',
    )
);

$assertSame = static function ($expected, $actual, $label) use (&$failures, &$results) {
    if ($expected === $actual) {
        $results[] = 'PASS: ' . $label;

        return;
    }

    $failures[] = sprintf(
        'FAIL: %s (expected %s, received %s)',
        $label,
        wp_json_encode($expected),
        wp_json_encode($actual)
    );
};

$assertTrue = static function ($actual, $label) use (&$failures, &$results) {
    if ($actual === true) {
        $results[] = 'PASS: ' . $label;

        return;
    }

    $failures[] = sprintf('FAIL: %s', $label);
};

$makeImage = static function ($format) {
    $image = imagecreatetruecolor(2, 2);
    if ($image === false) {
        return '';
    }

    $color = imagecolorallocate($image, 50, 100, 150);
    imagefill($image, 0, 0, $color);
    ob_start();

    if ($format === 'jpeg') {
        imagejpeg($image, null, 90);
    } elseif ($format === 'webp') {
        imagewebp($image, null, 90);
    } else {
        imagepng($image);
    }

    $binary = ob_get_clean();
    imagedestroy($image);

    return is_string($binary) ? $binary : '';
};

if (isset($administrators[0])) {
    wp_set_current_user((int) $administrators[0]);
}

try {
    delete_option($optionName);
    delete_transient('exmoau_settings_cache');
    $assertSame('webp', SettingsController::getAiImageFormat(), 'the missing setting defaults to WebP');

    foreach (array('jpeg', 'webp', 'png') as $format) {
        update_option($optionName, $format);
        delete_transient('exmoau_settings_cache');
        $assertSame($format, SettingsController::getAiImageFormat(), sprintf('%s is a valid stored setting', $format));
    }

    update_option($optionName, 'png');
    delete_transient('exmoau_settings_cache');
    $assertSame(
        'png',
        SettingsController::sanitizeAiImageFormat('gif'),
        'an invalid format preserves the previous valid setting'
    );

    $serviceReflection = new ReflectionClass(AiService::class);
    $service = $serviceReflection->newInstanceWithoutConstructor();
    $normalizeFormat = $serviceReflection->getMethod('normalizeImageOutputMimeType');
    $applyMimeType = $serviceReflection->getMethod('applyImageOutputMimeType');
    $expectedMimes = array(
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'png'  => 'image/png',
    );

    foreach ($expectedMimes as $format => $mimeType) {
        $assertSame(
            $mimeType,
            $normalizeFormat->invoke($service, $format),
            sprintf('%s maps to the expected request MIME', $format)
        );

        $builder = new class() {
            public $mimeType = '';

            public function as_output_mime_type($mimeType) {
                $this->mimeType = $mimeType;

                return $this;
            }
        };
        $returnedBuilder = $applyMimeType->invoke($service, $builder, $mimeType);
        $assertSame($builder, $returnedBuilder, sprintf('%s keeps the fluent builder', $format));
        $assertSame($mimeType, $builder->mimeType, sprintf('%s reaches as_output_mime_type()', $format));
    }

    $controllerReflection = new ReflectionClass(GptController::class);
    $controller = $controllerReflection->newInstanceWithoutConstructor();
    $validateBinary = $controllerReflection->getMethod('validateGeneratedImageBinary');
    $saveBinary = $controllerReflection->getMethod('saveFeaturedImageFromBinary');

    foreach ($expectedMimes as $format => $mimeType) {
        $binary = $makeImage($format);
        $validation = $validateBinary->invoke($controller, $binary, $mimeType, $mimeType);
        $expectedExtension = $format === 'jpeg' ? 'jpg' : $format;
        $assertTrue(!empty($validation['success']), sprintf('%s bytes pass image validation', $format));
        $assertSame($mimeType, $validation['mime_type'], sprintf('%s MIME is detected from bytes', $format));
        $assertSame($expectedExtension, $validation['extension'], sprintf('%s extension follows detected MIME', $format));
        $assertSame(false, $validation['mismatch'], sprintf('%s has no MIME mismatch', $format));

        $postId = wp_insert_post(
            array(
                'post_title'  => sprintf('ExMoment Author %s format regression', strtoupper($format)),
                'post_status' => 'draft',
                'post_type'   => 'post',
            ),
            true
        );
        if (is_wp_error($postId)) {
            $failures[] = sprintf('FAIL: %s attachment test post could not be created', $format);

            continue;
        }

        $createdPostIds[] = (int) $postId;
        $attachmentId = $saveBinary->invoke($controller, $postId, $binary, 'Format regression', $mimeType, $mimeType);
        if (!is_int($attachmentId) || $attachmentId < 1) {
            $failures[] = sprintf('FAIL: %s attachment could not be created', $format);

            continue;
        }

        $createdAttachmentIds[] = $attachmentId;
        $attachedPath = get_attached_file($attachmentId);
        $assertSame($mimeType, get_post_mime_type($attachmentId), sprintf('%s attachment MIME is correct', $format));
        $assertSame(
            $expectedExtension,
            strtolower((string) pathinfo((string) $attachedPath, PATHINFO_EXTENSION)),
            sprintf('%s attachment extension is correct', $format)
        );
        $assertSame($attachmentId, (int) get_post_thumbnail_id($postId), sprintf('%s is assigned as featured image', $format));
    }

    $pngBinary = $makeImage('png');
    $mismatch = $validateBinary->invoke($controller, $pngBinary, 'image/jpeg', 'image/jpeg');
    $assertTrue(!empty($mismatch['success']), 'an allowed returned MIME mismatch remains persistable');
    $assertSame('image/png', $mismatch['mime_type'], 'actual returned MIME remains authoritative on mismatch');
    $assertSame('png', $mismatch['extension'], 'actual returned MIME controls extension on mismatch');
    $assertSame(true, $mismatch['mismatch'], 'a returned MIME mismatch is surfaced');

    $invalid = $validateBinary->invoke($controller, 'not an image', 'image/png', 'image/png');
    $assertSame(false, $invalid['success'], 'non-image bytes are rejected');
} finally {
    foreach ($createdAttachmentIds as $attachmentId) {
        wp_delete_attachment($attachmentId, true);
    }

    foreach ($createdPostIds as $postId) {
        wp_delete_post($postId, true);
    }

    if ($originalOption === $optionSentinel) {
        delete_option($optionName);
    } else {
        update_option($optionName, $originalOption);
    }

    delete_transient('exmoau_settings_cache');
    wp_set_current_user($originalUserId);
}

foreach (array_merge($results, $failures) as $line) {
    fwrite(STDOUT, $line . "\n");
}

if (!empty($failures)) {
    exit(1);
}
