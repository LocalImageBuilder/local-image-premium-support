<?php 
/*
*
*	***** Local Image Premium Support *****
*
*	Core Functions
*	
*/
// If this file is called directly, abort. //
if ( ! defined( 'WPINC' ) ) {die;} // end if

// Change the login logo URL to your custom URL
function lips_custom_login_url( $url ) {
    return 'https://localimageco.com/'; // Replace with your desired custom URL
}
add_filter( 'login_headerurl', 'lips_custom_login_url' );


function lips_register_support_menu_page() {
    add_menu_page( 'Local Image', '<span>Local</span> IMAGE', 'edit_posts', 'lips_support', 'lips_support_display', LIPS_CORE_IMG . 'LocalImage_Icon.png', 1.9 );
}
add_action( 'admin_menu', 'lips_register_support_menu_page' );

function lips_support_display( $current = 'general' ) { ?>
	
	<div id="li_support">
	<h1 class="support_logo">Help Desk</h1>    
    <?php lips_get_support_posts() ; ?> 	
	</div><!-- #dojo_support -->    
    <?php	
}

function lips_get_support_posts() {
	// Example usage with a custom URL
	$custom_api_url = 'https://portal.localimageco.net/support-plugin/wp-json/wp/v2/posts'; // Replace with your actual REST API URL
	$posts = lips_get_posts_from_custom_api( $custom_api_url );
	
	echo '<div class="lips-wrapper">';
	echo '<div class="tabs">';
	$firstIteration = true;
	
	if (is_array($posts)) {
		// Iterate through the posts and do something with them
		foreach ($posts as $post) {
			// Access post properties like $post['title']['rendered']
			//echo '<h3>'. $post['id'] . $post['title']['rendered'] . '</h3>';
			//echo '<p>' . $post['content']['rendered'] . '</p>';
			//print_r($post);
			if ($firstIteration) {
				$checked = 'checked';
			} else {
				$checked = '';
			}
			$firstIteration = false;
			$tab_id = isset( $post['id'] ) ? (string) $post['id'] : '';
			echo '<div class="tab">';
			echo '<input type="radio" name="css-tabs" id="tab-' . esc_attr( $tab_id ) . '" ' . esc_attr( $checked ) . ' class="tab-switch">';
			echo '<label for="tab-' . esc_attr( $tab_id ) . '" class="tab-label">' . wp_kses_post( $post['title']['rendered'] ?? '' ) . '</label>';
			echo '<div class="tab-content">' . wp_kses_post( $post['content']['rendered'] ?? '' ) . '</div>';
			echo '</div><!-- .tab -->';
		}
	} else {
		// Handle errors or no posts found
		echo esc_html( is_string( $posts ) ? $posts : '' );
	}
	echo '</div> <!-- .tabs -->';
	echo '</div><!-- .lips-wrapper -->';	
}

function lips_get_posts_from_custom_api( $api_url ) {
    // Use wp_remote_get() to retrieve the data from the REST API
    $response = wp_remote_get($api_url);

    // Check for a valid response
    if (is_wp_error($response)) {
        return 'Error retrieving posts: ' . $response->get_error_message();
    }

    // Decode the JSON response into an array
    $posts = json_decode(wp_remote_retrieve_body($response), true);

    // Check if posts are retrieved successfully
    if (empty($posts)) {
        return 'No posts found.';
    }

    // Return the array of posts
    return $posts;
}

// Includes
include_once 'new-install-functions.php';

// Initialize during plugin load
add_action('plugins_loaded', function() {
    SEV_Checker::init();
});