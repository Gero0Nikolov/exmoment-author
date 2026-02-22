<?php

namespace ExMomentAuthor\Modules\Help;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use WP_Admin_Bar;

/**
 * Adds the ExMoment Author Help link to the WordPress admin bar.
 */
class HelpAdminBar {

    private const ADMIN_BAR_NODE_ID = 'exmoau-help';
    private const DEFAULT_CAPABILITY = 'manage_options';
    private const FILTER_CAPABILITY = 'exmoau_help_admin_bar_cap';
    private const MENU_PARENT = 'tools.php';
    private const MENU_SLUG = 'exmoau-help';

    /**
     * Register the admin bar integration hook and expose the capability filter.
     *
     * Attaches {@see self::registerNode()} to the `admin_bar_menu` action and
     * exposes the {@see self::FILTER_CAPABILITY} (`exmoau_help_admin_bar_cap`)
     * filter so integrators can override the required capability.
     *
     * @since 1.1.0
     *
     * @return void
     */
    public function __construct() {
        add_action('admin_bar_menu', [$this, 'registerNode'], 100);
    }

    /**
     * Add the ExMoment Author Help node to the admin bar when visible to the user.
     *
     * Relies on {@see is_admin_bar_showing()} for visibility and uses sanitized
     * URLs and labels when registering the node. Capability checks honor the
     * {@see self::FILTER_CAPABILITY} (`exmoau_help_admin_bar_cap`) filter.
     *
     * @since 1.1.0
     *
     * @param WP_Admin_Bar $adminBar Admin bar instance provided by WordPress.
     *
     * @return void
     */
    public function registerNode(WP_Admin_Bar $adminBar) {
        if (!is_admin_bar_showing()) {
            return;
        }

        $capability = $this->getCapability();

        if (!current_user_can($capability)) {
            return;
        }

        if (null !== $adminBar->get_node(self::ADMIN_BAR_NODE_ID)) {
            return;
        }

        $adminBar->add_node([
            'id' => self::ADMIN_BAR_NODE_ID,
            'title' => esc_html__('ExMoment Help?', 'exmoment-author'),
            'href' => esc_url($this->getHelpPageUrl()),
            'meta' => [
                'title' => esc_attr__('Open the ExMoment Author Help page', 'exmoment-author'),
            ],
        ]);
    }

    /**
     * Retrieve the capability required to view the admin bar node.
     *
     * Reads the {@see self::FILTER_CAPABILITY} (`exmoau_help_admin_bar_cap`)
     * filter and falls back to {@see self::DEFAULT_CAPABILITY} when the filter
     * returns an empty or invalid value.
     *
     * @since 1.1.0
     *
     * @return string Capability string needed to render the admin bar node.
     */
    private function getCapability() {
        $capability = apply_filters(self::FILTER_CAPABILITY, self::DEFAULT_CAPABILITY);

        if (!is_string($capability) || '' === $capability) {
            return self::DEFAULT_CAPABILITY;
        }

        return $capability;
    }

    /**
     * Build the Help page URL.
     *
     * @since 1.1.0
     *
     * @return string Admin URL pointing to the ExMoment Author Help page.
     */
    private function getHelpPageUrl() {
        return admin_url(self::MENU_PARENT . '?page=' . self::MENU_SLUG);
    }
}
