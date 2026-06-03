<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once LIPS_CORE_INC . 'new-install-functions.php';

/**
 * Point the login logo to Local Image.
 */
function lips_custom_login_url( $url ) {
	return 'https://localimageco.com/';
}
add_filter( 'login_headerurl', 'lips_custom_login_url' );

/**
 * Register the Help Desk admin menu.
 */
function lips_register_support_menu_page() {
	add_menu_page(
		'Local Image',
		'<span>Local</span> IMAGE',
		'edit_posts',
		'lips_support',
		'lips_support_display',
		LIPS_CORE_IMG . 'LocalImage_Icon.png',
		1.9
	);
}
add_action( 'admin_menu', 'lips_register_support_menu_page' );

/**
 * Render the Help Desk page.
 */
function lips_support_display() {
	?>
	<div id="li_support">
		<h1 class="support_logo">Help Desk</h1>
		<?php lips_get_support_posts(); ?>
	</div>
	<?php
}

/**
 * Fetch and render support articles from the portal API.
 */
function lips_get_support_posts() {
	$api_url = 'https://portal.localimageco.net/support-plugin/wp-json/wp/v2/posts';
	$posts   = lips_get_posts_from_custom_api( $api_url );

	echo '<div class="lips-wrapper">';
	echo '<div class="tabs">';

	if ( is_array( $posts ) ) {
		$first = true;

		foreach ( $posts as $post ) {
			$checked = $first ? 'checked' : '';
			$first   = false;
			$tab_id  = isset( $post['id'] ) ? (string) $post['id'] : '';

			echo '<div class="tab">';
			echo '<input type="radio" name="css-tabs" id="tab-' . esc_attr( $tab_id ) . '" ' . esc_attr( $checked ) . ' class="tab-switch">';
			echo '<label for="tab-' . esc_attr( $tab_id ) . '" class="tab-label">' . wp_kses_post( $post['title']['rendered'] ?? '' ) . '</label>';
			echo '<div class="tab-content">' . wp_kses_post( $post['content']['rendered'] ?? '' ) . '</div>';
			echo '</div>';
		}
	} else {
		echo esc_html( is_string( $posts ) ? $posts : '' );
	}

	echo '</div>';
	echo '</div>';
}

/**
 * Retrieve posts from a WordPress REST API endpoint.
 *
 * @param string $api_url REST API posts URL.
 * @return array|string Post data or error message.
 */
function lips_get_posts_from_custom_api( $api_url ) {
	$response = wp_remote_get( $api_url );

	if ( is_wp_error( $response ) ) {
		return 'Error retrieving posts: ' . $response->get_error_message();
	}

	$posts = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( empty( $posts ) ) {
		return 'No posts found.';
	}

	return $posts;
}
