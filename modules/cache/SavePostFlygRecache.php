<?php

namespace ExMomentAuthor\Modules\Cache;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use WP_Post;
use WP_Post_Type;

class SavePostFlygRecache {

    /**
     * Shared Flyg recache service instance.
     *
     * @var FlygRecacheService
     */
    private $flygRecacheService;

    /**
     * Register the save_post hook to trigger Flyg recache requests.
     *
     * @param array<string, mixed> $config Optional configuration overrides.
     */
    public function __construct(array $config = []) {
        $this->flygRecacheService = (
            !empty($config['flygRecacheService']) &&
            $config['flygRecacheService'] instanceof FlygRecacheService
        ) ?
            $config['flygRecacheService'] :
            new FlygRecacheService();

        add_action('save_post', [$this, 'handleSavePost'], 10, 3);
    }

    /**
     * Handle save_post events for published, public post types.
     *
     * @param int|mixed $postId Post identifier from WordPress.
     * @param mixed     $post   Raw post object from the hook.
     * @param bool      $update Whether this is an update to an existing post.
     * @return void
     */
    public function handleSavePost($postId, $post, $update) {
        unset($update);

        $postId = absint($postId);
        if ($postId < 1) {
            return;
        }

        if (!($post instanceof WP_Post)) {
            $post = get_post($postId);
            if (!($post instanceof WP_Post)) {
                return;
            }
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        if (function_exists('wp_is_post_autosave') && wp_is_post_autosave($postId)) {
            return;
        }

        if (function_exists('wp_is_post_revision') && wp_is_post_revision($postId)) {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        $postType = $post->post_type;
        if (!is_string($postType) || $postType === '') {
            return;
        }

        $postTypeObject = get_post_type_object($postType);
        if (!($postTypeObject instanceof WP_Post_Type)) {
            return;
        }

        if (!$postTypeObject->public) {
            return;
        }

        if ($postType === 'attachment') {
            return;
        }

        $this->flygRecacheService->recacheForPost($post);
    }
}
