<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'LIPS_CORE_INC', LIPS_PLUGIN_DIR . 'assets/inc/' );
define( 'LIPS_CORE_IMG', plugins_url( 'assets/img/', LIPS_PLUGIN_FILE ) );
define( 'LIPS_CORE_CSS', plugins_url( 'assets/css/', LIPS_PLUGIN_FILE ) );
define( 'LIPS_CORE_JS', plugins_url( 'assets/js/', LIPS_PLUGIN_FILE ) );

require_once LIPS_CORE_INC . 'lips-core-functions.php';
require_once LIPS_CORE_INC . 'sev-checker.php';
require_once LIPS_CORE_INC . 'lips-li-tools.php';
require_once LIPS_CORE_INC . 'lips-image-alts.php';
require_once LIPS_CORE_INC . 'lips-github-updater.php';

add_action( 'admin_enqueue_scripts', 'lips_admin_scripts' );
add_action( 'login_head', 'lips_login_logo_and_styles' );
add_action( 'login_enqueue_scripts', 'lips_enqueue_google_fonts' );

add_action( 'plugins_loaded', array( 'SEV_Checker', 'init' ) );
add_action( 'plugins_loaded', array( 'LIPS_LI_Tools', 'init' ) );
add_action( 'plugins_loaded', array( 'LIPS_Image_Alts', 'init' ) );
add_action( 'plugins_loaded', 'lips_init_github_updater' );

register_activation_hook( LIPS_PLUGIN_FILE, array( 'SEV_Checker', 'run_check' ) );
register_activation_hook( LIPS_PLUGIN_FILE, 'lips_check_for_update_on_activation' );
register_deactivation_hook( LIPS_PLUGIN_FILE, array( 'SEV_Checker', 'cleanup' ) );

/**
 * Enqueue admin styles and fonts.
 */
function lips_admin_scripts() {
	wp_enqueue_style( 'lips-support-styles', LIPS_CORE_CSS . 'admin-styles-min.css', array(), '1.0.12' );
	wp_enqueue_style( 'google-now-bold', 'https://fonts.googleapis.com/css?family=Google+Now:wght@700&display=swap', array(), '1.0' );
	wp_enqueue_style( 'moontime', 'https://fonts.googleapis.com/css?family=Moontime&display=swap', array(), '1.0' );
}

/**
 * Enqueue login screen styles.
 */
function lips_login_logo_and_styles() {
	wp_enqueue_style( 'lips-login', LIPS_CORE_CSS . 'login-min.css', array(), '1.0.12' );
}

/**
 * Enqueue login screen fonts.
 */
function lips_enqueue_google_fonts() {
	wp_enqueue_style( 'raleway', 'https://fonts.googleapis.com/css?family=Raleway&display=swap', array(), '1.0' );
}

/**
 * Register GitHub-based plugin updates.
 */
function lips_init_github_updater() {
	if ( ! defined( 'LIPS_GHPU_USERNAME' ) ) {
		define( 'LIPS_GHPU_USERNAME', 'LocalImageBuilder' );
	}

	if ( ! defined( 'LIPS_GHPU_REPOSITORY' ) ) {
		define( 'LIPS_GHPU_REPOSITORY', 'local-image-premium-support' );
	}

	if ( ! defined( 'LIPS_GHPU_BRANCH' ) ) {
		define( 'LIPS_GHPU_BRANCH', 'main' );
	}

	if ( ! defined( 'LIPS_GHPU_AUTH_TOKEN' ) ) {
		define( 'LIPS_GHPU_AUTH_TOKEN', '' );
	}

	$updater = new LIPS_GhPluginUpdater( LIPS_PLUGIN_FILE );
	$updater->init();
}

/**
 * Check for a GitHub update immediately after activation.
 *
 * plugins_loaded may have already run before a newly activated plugin
 * is loaded, so the updater is initialized here before checking.
 */
function lips_check_for_update_on_activation() {
	lips_init_github_updater();

	if ( ! function_exists( 'wp_update_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/update.php';
	}

	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
}

add_filter( 'plugin_action_links_' . plugin_basename( LIPS_PLUGIN_FILE ), 'lips_plugin_action_links' );
add_action( 'admin_post_lips_check_update', 'lips_handle_check_for_update' );
add_action( 'admin_notices', 'lips_update_check_notice' );

/**
 * Plugins screen links for settings and a manual update check.
 */
function lips_plugin_action_links( $links ) {
	$actions = array();

	if ( current_user_can( 'manage_options' ) ) {
		$actions[] = '<a href="' . esc_url( admin_url( 'tools.php?page=lips-li-tools' ) ) . '">' . esc_html__( 'Settings', 'local-image-premium-support' ) . '</a>';
	}

	if ( current_user_can( 'update_plugins' ) ) {
		$check_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=lips_check_update' ),
			'lips_check_update'
		);
		$actions[] = '<a href="' . esc_url( $check_url ) . '">' . esc_html__( 'Check for update', 'local-image-premium-support' ) . '</a>';
	}

	return array_merge( $actions, $links );
}

/**
 * Refresh the GitHub update check from the Plugins screen.
 */
function lips_handle_check_for_update() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		wp_die( esc_html__( 'You do not have permission to update plugins.', 'local-image-premium-support' ) );
	}

	check_admin_referer( 'lips_check_update' );

	lips_check_for_update_on_activation();
	set_transient( 'lips_update_checked_' . get_current_user_id(), 1, MINUTE_IN_SECONDS );

	wp_safe_redirect( admin_url( 'plugins.php' ) );
	exit;
}

/**
 * Confirm a manual update check on the Plugins screen.
 */
function lips_update_check_notice() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$notice_key = 'lips_update_checked_' . get_current_user_id();

	if ( ! get_transient( $notice_key ) ) {
		return;
	}

	delete_transient( $notice_key );

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || 'plugins' !== $screen->id ) {
		return;
	}

	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Update check complete. If a newer version is available, it is listed below.', 'local-image-premium-support' ) . '</p></div>';
}
