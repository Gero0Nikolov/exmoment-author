<?php

namespace ExMomentAuthor\Modules\Help;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use ExMomentAuthor\Modules\Settings\SettingsController;

/**
 * Registers the ExMoment Author Help admin page and plugin list action.
 */
class HelpController {

    private const CAPABILITY = 'manage_options';
    private const MENU_PARENT = 'tools.php';
    private const MENU_SLUG = 'exmoau-help';

    /**
     * Bootstrap the Help module hooks.
     *
     * Registers the {@see self::registerPage()} callback on the `admin_menu`
     * action and wires {@see self::filterHelpActionLinks()} into the
     * `plugin_action_links_{plugin}` and `network_admin_plugin_action_links_{plugin}`
     * filters when the plugin basename is available. These hooks ensure the
     * admin page and contextual links stay restricted to administrators with
     * the manage_options capability.
     *
     * @since 1.1.0
     * @return void
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'registerPage']);

        $pluginBasename = $this->getPluginBasename();

        if ('' !== $pluginBasename) {
            $this->addHelpAction($pluginBasename);
        }
    }

    /**
     * Register the Tools → ExMoment Author Help admin page.
     *
     * Hooks into `admin_menu` to add a submenu page under Tools. Limits the
     * menu registration to the manage_options capability to keep access scoped
     * to administrators.
     *
     * @since 1.1.0
     * @return void
     */
    public function registerPage() {
        add_submenu_page(
            self::MENU_PARENT,
            esc_html__('ExMoment Author Help', 'exmoment-author'),
            esc_html__('ExMoment Author Help', 'exmoment-author'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderPage']
        );
    }

    /**
     * Render the Help page content.
     *
     * Callback for the submenu page registered via {@see self::registerPage()}.
     * All translatable strings and dynamic URLs are escaped prior to output to
     * prevent XSS vulnerabilities.
     *
     * @since 1.1.0
     * @return void
     */
    public function renderPage() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'exmoment-author'));
        }

        $sections = $this->getSections();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('ExMoment Author Help', 'exmoment-author'); ?></h1>
            <p><?php echo esc_html__('Use these quick links to navigate the ExMoment Author administration tools.', 'exmoment-author'); ?></p>
            <ul>
                <?php foreach ($sections as $section) : ?>
                    <li>
                        <a href="<?php echo esc_url($section['url']); ?>"><?php echo esc_html($section['label']); ?></a>
                        <?php 
                        /* translators: %s: Section description. */
                        printf(esc_html__(': %s', 'exmoment-author'), esc_html($section['description'])); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p><?php echo esc_html__('For full procedures, review the internal ExMoment Author documentation folder.', 'exmoment-author'); ?></p>
        </div>
        <?php
    }

    /**
     * Register Help action links on the plugin list table.
     *
     * Hooks {@see self::filterHelpActionLinks()} into the
     * `plugin_action_links_{plugin}` and `network_admin_plugin_action_links_{plugin}`
     * filters. Restricts registration to manage_options users and delegates
     * output to the filter that escapes URLs and link text before display.
     *
     * @since 1.1.0
     * @param string $pluginBasename Plugin file basename for the current plugin.
     * @return void
     */
    public function addHelpAction($pluginBasename) {
        add_filter('plugin_action_links_' . $pluginBasename, [$this, 'filterHelpActionLinks']);
        add_filter('network_admin_plugin_action_links_' . $pluginBasename, [$this, 'filterHelpActionLinks']);
    }

    /**
     * Append the Help action to the plugin list table.
     *
     * Callback for the `plugin_action_links_{plugin}` and
     * `network_admin_plugin_action_links_{plugin}` filters. Ensures only
     * manage_options users receive the link and that the markup is escaped prior
     * to rendering in the plugins screen.
     *
     * @since 1.1.0
     * @param array<string, string> $actions Existing action links.
     * @return array<string, string> Modified action links including the Help link when permitted.
     */
    public function filterHelpActionLinks($actions) {
        if (!current_user_can(self::CAPABILITY)) {
            return $actions;
        }

        $actions['exmoau-help'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($this->getPageUrl()),
            esc_html__('Help?', 'exmoment-author')
        );

        return $actions;
    }

    /**
     * Build the Help page URL.
     *
     * @since 1.1.0
     * @return string Fully qualified admin URL to the ExMoment Author Help page.
     */
    private function getPageUrl() {
        return admin_url(self::MENU_PARENT . '?page=' . self::MENU_SLUG);
    }

    /**
     * Retrieve the plugin basename for filter registration.
     *
     * @since 1.1.0
     * @return string Plugin basename when defined; empty string otherwise.
     */
    private function getPluginBasename() {
        if (defined('EXMOAU_PLUGIN_FILE')) {
            return plugin_basename(EXMOAU_PLUGIN_FILE);
        }

        return '';
    }

    /**
     * Compile the Help page section definitions.
     *
     * @since 1.1.0
     * @return array<int, array<string, string>> List of section metadata used to render quick links.
     */
    private function getSections() {
        return [
            [
                'label' => __('Settings', 'exmoment-author'),
                'url' => admin_url('options-general.php?page=' . SettingsController::PAGE_SLUG),
                'description' => __('Configure ExMoment Author defaults and API credentials.', 'exmoment-author'),
            ],
            [
                'label' => __('Jobs', 'exmoment-author'),
                'url' => admin_url('edit.php?post_type=exmoau_job'),
                'description' => __('Review, schedule, and run ExMoment Author jobs.', 'exmoment-author'),
            ],
            [
                'label' => __('Library', 'exmoment-author'),
                'url' => admin_url('tools.php?page=exmoau-library'),
                'description' => __('Manage the source library bundled with ExMoment Author.', 'exmoment-author'),
            ],
            [
                'label' => __('Log', 'exmoment-author'),
                'url' => admin_url('tools.php?page=exmoau-log'),
                'description' => __('Review diagnostic events captured by ExMoment Author.', 'exmoment-author'),
            ],
            [
                'label' => __('Help', 'exmoment-author'),
                'url' => $this->getPageUrl(),
                'description' => __('Return to these quick directions for administrators.', 'exmoment-author'),
            ],
        ];
    }
}
