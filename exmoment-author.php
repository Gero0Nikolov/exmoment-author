<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/*
Plugin Name: ExMoment Author
Description: Generate, manage, and publish SEO-ready content faster with ExMoment Author’s AI workflows and content library.
Version: 1.3.4
Author: ExMoment Ltd.
Author URI: https://exmoment.com
License: GPLv2 or later
Requires at least: 7.0
Requires PHP: 8.3
Tested up to: 7.0
Text Domain: exmoment-author
Domain Path: /languages
*/

if (!defined('EXMOAU_PLUGIN_FILE')) {
    define('EXMOAU_PLUGIN_FILE', __FILE__);
}

require_once plugin_dir_path(__FILE__) . '/vendor/autoload.php';
require_once plugin_dir_path(__FILE__) . '/Core.php';
