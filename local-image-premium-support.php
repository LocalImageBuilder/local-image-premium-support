<?php
/*
Plugin Name: Local Image Premium Support
Plugin URI: https://localimageco.com
Description: Premium hosting plugin for support and upgrades. Only at Local Image.
Version: 1.0.13
Author: Local Image
Author URI: https://localimageco.com/contact
Text Domain: local-image-premium-support
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'LIPS_PLUGIN_FILE', __FILE__ );
define( 'LIPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once LIPS_PLUGIN_DIR . 'core-init.php';
