<?php 
/*
Plugin Name: Local Image Premium Support
Plugin URI: https://localimageco.com
Description: Premium hosting plugin for support and upgrades. Only at Local Image.
Version: 1.0.8
Author: Local Image
Author URI: https://localimageco.com/contact
Text Domain: local-image-premium-support
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

// If this file is called directly, abort. //
if ( ! defined( 'WPINC' ) ) {die;} // end if

// GitHub updates: version from main, download matching tag zip (public repo, no API).
if ( is_admin() ) {
	if ( ! defined( 'LIPS_GHPU_USERNAME' ) ) {
		define( 'LIPS_GHPU_USERNAME', 'LocalImageBuilder' );
	}
	if ( ! defined( 'LIPS_GHPU_REPOSITORY' ) ) {
		define( 'LIPS_GHPU_REPOSITORY', 'local-image-premium-support' );
	}
	if ( ! defined( 'LIPS_GHPU_BRANCH' ) ) {
		define( 'LIPS_GHPU_BRANCH', 'main' );
	}

	require_once plugin_dir_path( __FILE__ ) . 'LIPS_GhPluginUpdater.php';

	$lips_updater = new LIPS_GhPluginUpdater( __FILE__ );
	$lips_updater->init();
}

// Let's Initialize Everything
if ( file_exists( plugin_dir_path( __FILE__ ) . 'core-init.php' ) ) {
require_once( plugin_dir_path( __FILE__ ) . 'core-init.php' );
}
