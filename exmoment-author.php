<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/*
Plugin Name: ExMoment Author
Description: Generate, manage, and publish SEO-ready content faster with ExMoment Author’s AI workflows and content library.
Version: 1.0.0
Author: ExMoment Ltd.
Author URI: https://exmoment.com
License: GPLv2 or later
Text Domain: exmoment-author
Domain Path: /languages
*/

if (!defined('EXMOAU_PLUGIN_FILE')) {
    define('EXMOAU_PLUGIN_FILE', __FILE__);
}

require_once plugin_dir_path(__FILE__) . '/vendor/autoload.php';
require_once plugin_dir_path(__FILE__) . '/Core.php';
