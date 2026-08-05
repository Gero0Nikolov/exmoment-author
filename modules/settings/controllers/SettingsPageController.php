<?php

namespace ExMomentAuthor\Modules\Settings\Controllers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use ExMomentAuthor\Modules\Settings\SettingsController;

/**
 * Registers the Settings admin page and renders its view.
 */
class SettingsPageController {

    /**
     * Default tab slug displayed when no explicit selection is provided.
     */
    private const DEFAULT_TAB = 'ai-client';

    /**
     * Register WordPress hooks for the Settings page.
     *
     * Registers callbacks within the admin context:
     * - `admin_menu` to add the options page.
     * - `admin_init` to register settings and sections.
     *
     * @return void
     */
    public function register() {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [SettingsController::class, 'register']);
    }

    /**
     * Add the ExMoment Author settings page to the WordPress admin menu.
     *
     * Intended to run during the `admin_menu` hook; registers the page under
     * Settings with the capability requirement of `manage_options` and points
     * to {@see self::render} for output.
     *
     * @return void
     */
    public function addMenu() {
        add_options_page(
            esc_html__('ExMoment Author', 'exmoment-author'),
            esc_html__('ExMoment Author', 'exmoment-author'),
            'manage_options',
            SettingsController::PAGE_SLUG,
            [$this, 'render']
        );
    }

    /**
     * Render the Settings page view.
     *
     * Invoked via the options page callback in the admin area. Ensures the
     * current user can manage options, displays registered settings errors,
     * resolves the active tab, and includes the settings view template when
     * present.
     *
     * @return void
     */
    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'exmoment-author'));
        }

        settings_errors(SettingsController::SETTINGS_GROUP);

        $tabs = $this->getTabs();
        $activeTab = $this->determineActiveTab(array_keys($tabs));

        $viewPath = dirname(__DIR__) . '/views/index.php';

        if (file_exists($viewPath)) {
            include $viewPath;
        }
    }

    /**
     * Retrieve the registered settings tabs and their associated partials.
     *
     * @return array<string, array{label: string, partial: string}> Associative
     *     array keyed by tab slug, each containing a translated label and the
     *     absolute path to the partial that renders the tab content.
     */
    private function getTabs() {
        $partialsDirectory = dirname(__DIR__) . '/views/partials/';

        return [
            'ai-client' => [
                'label'   => __('AI Client', 'exmoment-author'),
                'partial' => $partialsDirectory . 'ai-client.php',
            ],
            'ai-setup' => [
                'label'   => __('AI Setup', 'exmoment-author'),
                'partial' => $partialsDirectory . 'ai-setup.php',
            ],
        ];
    }

    /**
     * Determine which tab should be displayed based on the request context.
     *
     * Expects a list of allowed tab slugs and inspects the `tab` query
     * parameter in the admin request, returning a sanitized, known slug or the
     * default when none is provided.
     *
     * @param array<int, string> $tabKeys Registered tab keys provided by
     *     {@see self::getTabs()}.
     * @return string Active tab slug to display.
     */
    private function determineActiveTab(array $tabKeys) {
        $requestedTab = filter_input(INPUT_GET, 'tab', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if (is_string($requestedTab)) {
            $requestedTab = sanitize_key($requestedTab);
        } else {
            $requestedTab = '';
        }

        if ($requestedTab !== '' && in_array($requestedTab, $tabKeys, true)) {
            return $requestedTab;
        }

        return self::DEFAULT_TAB;
    }
}
