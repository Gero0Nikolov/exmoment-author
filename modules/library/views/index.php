<?php
/**
 * ExMoment Author Library admin page scaffold.
 *
 * Expects `$data` to include a nonce, AJAX action map, and upload limits prepared
 * by the controller. Values are escaped for attribute context before being passed
 * to the JavaScript-driven library component via data attributes. The interactive
 * behaviour is handled by scripts registered elsewhere; no callbacks are executed
 * directly in this template.
 */

if (!defined('ABSPATH')) {
    exit;
}

$nonce = (isset($data['nonce']) ? $data['nonce'] : '');
$actions = (isset($data['actions']) && is_array($data['actions']) ? $data['actions'] : []);
$limits = (isset($data['limits']) && is_array($data['limits']) ? $data['limits'] : []);

$actionPayload = wp_json_encode($actions);
$limitsPayload = wp_json_encode($limits);

$ajaxUrl = admin_url('admin-ajax.php');
?>

<div class="wrap">
    <h1 class="wp-heading-inline">
        <?php esc_html_e('ExMoment Author Library', 'exmoment-author'); ?>
    </h1>
    <p class="description">
        <?php esc_html_e('Browse uploaded categories, preview files, and manage the ExMoment Author library.', 'exmoment-author'); ?>
    </p>

    <div
        class="exmoau-library"
        data-exmoau-library
        data-library-nonce="<?php echo esc_attr($nonce); ?>"
        data-library-actions="<?php echo esc_attr($actionPayload); ?>"
        data-library-limits="<?php echo esc_attr($limitsPayload); ?>"
        data-library-ajax-url="<?php echo esc_url($ajaxUrl); ?>"
    >
        <header class="exmoau-library__header">
            <div class="exmoau-library__header-row exmoau-library__header-row--actions">
                <div class="exmoau-library__header-actions">
                    <button
                        type="button"
                        class="button"
                        data-library-back
                        disabled
                    >
                        <?php esc_html_e('Back to categories', 'exmoment-author'); ?>
                    </button>
                    <button
                        type="button"
                        class="button button-primary"
                        data-library-upload
                    >
                        <?php esc_html_e('Upload', 'exmoment-author'); ?>
                    </button>
                    <input
                        type="file"
                        class="exmoau-library__upload-input"
                        name="library_archive"
                        accept=".zip"
                        data-library-upload-input
                        hidden
                    />
                </div>
            </div>
            <div class="exmoau-library__header-row exmoau-library__header-row--state">
                <div class="exmoau-library__status" data-library-status>
                    <span class="spinner" data-library-status-spinner></span>
                    <span class="exmoau-library__status-message" data-library-status-message aria-live="polite"></span>
                </div>
                <div class="exmoau-library__notice notice exmoau-is-hidden" data-library-notice></div>
            </div>
        </header>

        <div class="exmoau-library__body">
            <section class="exmoau-library__pane exmoau-library__pane--categories" data-library-categories-pane>
                <h2 class="screen-reader-text"><?php esc_html_e('Categories', 'exmoment-author'); ?></h2>
                <div class="exmoau-library__content">
                    <div
                        class="exmoau-library__loader"
                        data-library-loader="categories"
                        role="status"
                        aria-live="polite"
                        aria-hidden="true"
                    >
                        <span class="spinner"></span>
                        <span class="exmoau-library__loader-text"><?php esc_html_e('Loading content…', 'exmoment-author'); ?></span>
                    </div>
                    <ul class="exmoau-library__list" data-library-categories></ul>
                    <p class="exmoau-library__empty-message exmoau-is-hidden" data-library-categories-empty>
                        <?php esc_html_e('No categories available yet.', 'exmoment-author'); ?>
                    </p>
                </div>
            </section>

            <section class="exmoau-library__pane exmoau-library__pane--files exmoau-is-hidden" data-library-files-pane>
                <div class="exmoau-library__files-header">
                    <h2 class="exmoau-library__files-title" data-library-files-title></h2>
                    <div class="exmoau-library__pagination" data-library-pagination></div>
                </div>
                <div class="exmoau-library__content">
                    <div
                        class="exmoau-library__loader"
                        data-library-loader="files"
                        role="status"
                        aria-live="polite"
                        aria-hidden="true"
                    >
                        <span class="spinner"></span>
                        <span class="exmoau-library__loader-text"><?php esc_html_e('Loading content…', 'exmoment-author'); ?></span>
                    </div>
                    <ul class="exmoau-library__list" data-library-files></ul>
                    <p class="exmoau-library__empty-message exmoau-is-hidden" data-library-files-empty>
                        <?php esc_html_e('No files found in this category.', 'exmoment-author'); ?>
                    </p>
                </div>
            </section>
        </div>

        <div class="exmoau-library__modal exmoau-is-hidden" data-library-modal role="dialog" aria-modal="true" aria-labelledby="exmoau-library-modal-title">
            <div class="exmoau-library__modal-dialog">
                <header class="exmoau-library__modal-header">
                    <h2 id="exmoau-library-modal-title" class="exmoau-library__modal-title" data-library-modal-title></h2>
                    <button type="button" class="button exmoau-library__modal-close" data-library-modal-close>
                        <?php esc_html_e('Close', 'exmoment-author'); ?>
                    </button>
                </header>
                <div class="exmoau-library__modal-body">
                    <div
                        class="exmoau-library__loader exmoau-library__loader--modal"
                        data-library-modal-loader
                        role="status"
                        aria-live="polite"
                        aria-hidden="true"
                    >
                        <span class="spinner"></span>
                        <span class="exmoau-library__loader-text"><?php esc_html_e('Loading content…', 'exmoment-author'); ?></span>
                    </div>
                    <pre data-library-modal-content></pre>
                </div>
            </div>
        </div>
    </div>
</div>
