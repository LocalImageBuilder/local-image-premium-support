<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function lips_after_site_install( $args ) {
	// Check if the function has already run
	if ( ! get_option( 'lips_after_site_install_done' ) ) {
		// Your custom logic here
		// For example, create default content or set up user roles

		// Mark the function as executed
		update_option( 'lips_after_site_install_done', true );
	}
}
add_action( 'wp_initialize_site', 'lips_after_site_install' );
