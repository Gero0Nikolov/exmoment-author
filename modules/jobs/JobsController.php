<?php

namespace ExMomentAuthor\Modules\Jobs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Registers the admin-only ExMoment Author Jobs custom post type.
 *
 * Future enhancements such as meta boxes, save handlers, and list-table
 * customizations will be implemented in dedicated controllers to keep this
 * class focused solely on post type registration.
 */
class JobsController {

    private const POST_TYPE = 'exmoau_job';
    private const CAPABILITY = 'edit_posts';

    /**
     * Instantiate the controller and hook WordPress actions.
     *
     * Hooks `init` to register the custom post type on every admin page load
     * before WordPress renders the menu UI.
     *
     * @param array<string, mixed> $config Optional module configuration (unused).
     *
     * @since 1.1.0
     * @return void
     * @see add_action() Registers the `init` callback for post type setup.
     * @see self::CAPABILITY Capability required to access registration hooks.
     */
    public function __construct(array $config = []) {

        add_action('init', [$this, 'registerPostType']);
    }

    /**
     * Register the admin-only custom post type.
     *
     * Runs on the `init` hook to ensure labels, capabilities, and supports are
     * available before WordPress builds the admin menu and REST routes.
     *
     * @since 1.1.0
     * @return void
     * @see register_post_type() WordPress API used to register the custom post type.
     * @see self::CAPABILITY Ensures only users with the capability may manage jobs.
     */
    public function registerPostType() {
        $labels = [
            'name'                  => __('ExMoment Author Jobs', 'exmoment-author'),
            'singular_name'         => __('ExMoment Author Job', 'exmoment-author'),
            'menu_name'             => __('ExMoment Author Jobs', 'exmoment-author'),
            'name_admin_bar'        => __('ExMoment Author Job', 'exmoment-author'),
            'add_new'               => __('Add New', 'exmoment-author'),
            'add_new_item'          => __('Add New Job', 'exmoment-author'),
            'edit_item'             => __('Edit Job', 'exmoment-author'),
            'new_item'              => __('New Job', 'exmoment-author'),
            'view_item'             => __('View Job', 'exmoment-author'),
            'view_items'            => __('View Jobs', 'exmoment-author'),
            'search_items'          => __('Search Jobs', 'exmoment-author'),
            'not_found'             => __('No jobs found.', 'exmoment-author'),
            'not_found_in_trash'    => __('No jobs found in Trash.', 'exmoment-author'),
            'all_items'             => __('All Jobs', 'exmoment-author'),
            'archives'              => __('Job Archives', 'exmoment-author'),
            'attributes'            => __('Job Attributes', 'exmoment-author'),
            'insert_into_item'      => __('Insert into job', 'exmoment-author'),
            'uploaded_to_this_item' => __('Uploaded to this job', 'exmoment-author'),
        ];

        $capabilities = [
            'edit_post'              => self::CAPABILITY,
            'read_post'              => self::CAPABILITY,
            'delete_post'            => self::CAPABILITY,
            'edit_posts'             => self::CAPABILITY,
            'edit_others_posts'      => self::CAPABILITY,
            'publish_posts'          => self::CAPABILITY,
            'read_private_posts'     => self::CAPABILITY,
            'delete_posts'           => self::CAPABILITY,
            'delete_private_posts'   => self::CAPABILITY,
            'delete_published_posts' => self::CAPABILITY,
            'delete_others_posts'    => self::CAPABILITY,
            'edit_private_posts'     => self::CAPABILITY,
            'edit_published_posts'   => self::CAPABILITY,
            'create_posts'           => self::CAPABILITY,
        ];

        register_post_type(self::POST_TYPE, [
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_admin_bar'   => false,
            'show_in_nav_menus'   => false,
            'has_archive'         => false,
            'exclude_from_search' => true,
            'rewrite'             => false,
            'hierarchical'        => false,
            'supports'            => ['title'],
            'menu_icon'           => 'dashicons-clipboard',
            'capabilities'        => $capabilities,
            'show_in_rest'        => false,
            'delete_with_user'    => false,
        ]);
    }
}
