<?php
/*
 *
 *	***** Local Image Premium Support *****
 *
 *	This file initializes all LIPS Core components
 *	
 */
// If this file is called directly, abort. //
if ( !defined( 'WPINC' ) ) {
  die;
} // end if
// Define Our Constants
define( 'LIPS_CORE_INC', dirname( __FILE__ ) . '/assets/inc/' );
define( 'LIPS_CORE_IMG', plugins_url( 'assets/img/', __FILE__ ) );
define( 'LIPS_CORE_CSS', plugins_url( 'assets/css/', __FILE__ ) );
define( 'LIPS_CORE_JS', plugins_url( 'assets/js/', __FILE__ ) );


/*
 *
 *  Register CSS
 *
 */
function lips_register_core_css() {
  //wp_enqueue_style( 'lips-core', LIPS_CORE_CSS . 'lips-core.css', null, '1.0', 'all' );
};
add_action( 'wp_enqueue_scripts', 'lips_register_core_css' );
/*
 *
 *  Register JS/Jquery Ready
 *
 */
function lips_register_core_js() {
  // Register Core Plugin JS	
  //wp_enqueue_script( 'lips-core', LIPS_CORE_JS . 'lips-core.js', 'jquery', '1.0', true );
};
add_action( 'wp_enqueue_scripts', 'lips_register_core_js' );

function lips_admin_scripts() {
  // Register Admin support  
  wp_enqueue_style( 'lips-support-styles', LIPS_CORE_CSS . 'admin-styles-min.css', null, '1.0', 'all' );

  if ( current_user_can( 'edit_others_pages' ) ) {
    //	wp_enqueue_script( 'live-widget-script', plugin_dir_url( __FILE__ ) . 'js/widget.js' );
  }
}
add_action( 'admin_enqueue_scripts', 'lips_admin_scripts' );
// Register Login support
function lips_login_logo_and_styles() {
  wp_enqueue_style( 'custom_loginstyle', LIPS_CORE_CSS . 'login-min.css', null, '1.0', 'all' );
  wp_enqueue_style( "custom_loginstyle" );

}
add_action( 'login_head', 'lips_login_logo_and_styles' );
// Raleway font for login screen
function lips_enqueue_google_fonts() {
  wp_enqueue_style( 'raleway', 'https://fonts.googleapis.com/css?family=Raleway&display=swap', array(), '1.0' );
}
add_action( 'login_enqueue_scripts', 'lips_enqueue_google_fonts' );
// Moontime and Now Bold font for admin
function lips_enqueue_google_now_bold_font() {
    wp_enqueue_style('google-now-bold', 'https://fonts.googleapis.com/css?family=Google+Now:wght@700&display=swap', array(), '1.0');
	wp_enqueue_style('moontime', 'https://fonts.googleapis.com/css?family=Moontime&display=swap', array(), '1.0');
}
add_action( 'admin_enqueue_scripts', 'lips_enqueue_google_now_bold_font' );
/*
 *
 *  Includes
 *
 */
// Load the Functions
if ( file_exists( LIPS_CORE_INC . 'lips-core-functions.php' ) ) {
  require_once LIPS_CORE_INC . 'lips-core-functions.php';
}
// Load the ajax Request
if ( file_exists( LIPS_CORE_INC . 'lips-ajax-request.php' ) ) {
  require_once LIPS_CORE_INC . 'lips-ajax-request.php';
}
// Load the Shortcodes
if ( file_exists( LIPS_CORE_INC . 'lips-shortcodes.php' ) ) {
  require_once LIPS_CORE_INC . 'lips-shortcodes.php';
}
// Load the Check Search Engines File
if ( file_exists( LIPS_CORE_INC . 'sev-checker.php' ) ) {
  require_once LIPS_CORE_INC . 'sev-checker.php';
}


// Initialize the SEV checker
add_action('plugins_loaded', 'SEV_Checker::init');

// Run SEV check immediately on activation
register_activation_hook(__FILE__, function() {
    SEV_Checker::run_check();  // This will check and send email if needed
});

// Cleanup on deactivation
register_deactivation_hook(__FILE__, 'SEV_Checker::cleanup');