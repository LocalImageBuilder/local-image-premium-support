<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Image alt builder, render-time injection, and media scanner.
 */
class LIPS_Image_Alts {

	const OPTION_KEY     = 'li_tools_image_alt_settings';
	const SCAN_BATCH     = 50;
	const CACHE_TTL      = HOUR_IN_SECONDS;
	const ALT_SEPARATOR  = ' – ';

	public static function init() {
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'filter_attachment_attributes' ), 10, 3 );
		add_filter( 'the_content', array( __CLASS__, 'filter_content_images' ), 15 );

		add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
		add_action( 'wp_ajax_lips_scan_images', array( __CLASS__, 'ajax_scan_images' ) );
	}

	/**
	 * Default checkbox settings.
	 */
	public static function get_default_settings() {
		return array(
			'page_title'      => false,
			'site_title'      => false,
			'page_slug'       => false,
			'focus_keyword'   => false,
			'scan_post_types' => array( 'post', 'page' ),
		);
	}

	/**
	 * Saved alt builder settings.
	 */
	public static function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = wp_parse_args( $settings, self::get_default_settings() );
		$settings['scan_post_types'] = self::sanitize_scan_post_types( $settings['scan_post_types'] ?? array() );

		return $settings;
	}

	/**
	 * Whether any alt builder option is enabled.
	 */
	public static function has_active_settings() {
		$settings = self::get_settings();

		foreach ( array( 'page_title', 'site_title', 'page_slug', 'focus_keyword' ) as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether SmartCrawl Pro is active.
	 */
	public static function is_smartcrawl_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'smartcrawl-seo/wds_file.php' )
			|| is_plugin_active( 'smartcrawl-pro/smartcrawl.php' )
			|| defined( 'SMARTCRAWL_VERSION' );
	}

	/**
	 * Save alt builder settings from the admin form.
	 */
	public static function handle_save() {
		if ( ! isset( $_POST['lips_image_alts_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'lips_save_image_alts' );

		$scan_post_types = isset( $_POST['lips_scan_post_types'] ) ? (array) wp_unslash( $_POST['lips_scan_post_types'] ) : array();

		$settings = array(
			'page_title'      => ! empty( $_POST['lips_alt_page_title'] ),
			'site_title'      => ! empty( $_POST['lips_alt_site_title'] ),
			'page_slug'       => ! empty( $_POST['lips_alt_page_slug'] ),
			'focus_keyword'   => ! empty( $_POST['lips_alt_focus_keyword'] ),
			'scan_post_types' => self::sanitize_scan_post_types( $scan_post_types ),
		);

		update_option( self::OPTION_KEY, $settings );
		self::clear_alt_cache();

		add_settings_error(
			'lips_li_tools',
			'lips_image_alts_saved',
			__( 'Image alt settings saved.', 'local-image-premium-support' ),
			'success'
		);
	}

	/**
	 * Enqueue admin assets for the Image Alts tab.
	 */
	public static function enqueue_admin_assets() {
		wp_enqueue_script(
			'lips-image-alts-admin',
			LIPS_CORE_JS . 'lips-image-alts-admin.js',
			array(),
			'1.0.3',
			true
		);

		$preview_post_id = self::get_preview_post_id();

		wp_localize_script(
			'lips-image-alts-admin',
			'lipsImageAlts',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'scanNonce'        => wp_create_nonce( 'lips_image_alts_scan' ),
				'separator'        => self::ALT_SEPARATOR,
				'smartcrawlActive' => self::is_smartcrawl_active(),
				'settings'         => self::get_settings(),
				'preview'          => self::get_preview_parts( $preview_post_id ),
				'i18n'             => array(
					'scanning'     => __( 'Scanning…', 'local-image-premium-support' ),
					'runScan'      => __( 'Run Scan', 'local-image-premium-support' ),
					'scanError'    => __( 'Scan failed. Please try again.', 'local-image-premium-support' ),
					'scanComplete' => __( 'Scan complete.', 'local-image-premium-support' ),
					'noResults'       => __( 'No images found in the selected post types.', 'local-image-premium-support' ),
					'selectPostType'  => __( 'Select at least one post type to scan.', 'local-image-premium-support' ),
					'none'         => __( 'None', 'local-image-premium-support' ),
					'dynamic'      => __( 'Dynamic', 'local-image-premium-support' ),
				),
			)
		);
	}

	/**
	 * Render the Image Alts admin tab.
	 */
	public static function render_tab() {
		$settings        = self::get_settings();
		$preview_post_id = self::get_preview_post_id();
		$preview_alt     = self::build_alt_string( $preview_post_id, $settings );
		$show_focus      = self::is_smartcrawl_active();
		?>
		<h2 class="lips-li-tools-panel__title"><?php esc_html_e( 'Image Alts', 'local-image-premium-support' ); ?></h2>

		<div class="lips-li-tools-callout">
			<p>
				<?php
				esc_html_e(
					'Build alt text dynamically from page and site context. Alts are injected at render time so crawlers receive them without bulk database updates.',
					'local-image-premium-support'
				);
				?>
			</p>
		</div>

		<form method="post" action="<?php echo esc_url( LIPS_LI_Tools::get_tab_url( 'image-alts' ) ); ?>" class="lips-alt-builder-form" id="lips-image-alts-form">
			<?php wp_nonce_field( 'lips_save_image_alts' ); ?>

			<fieldset class="lips-alt-builder">
				<legend class="lips-alt-builder__legend"><?php esc_html_e( 'Alt Builder', 'local-image-premium-support' ); ?></legend>
				<p class="lips-alt-builder__hint"><?php esc_html_e( 'Selected values are joined with “ – ” in order.', 'local-image-premium-support' ); ?></p>

				<label class="lips-alt-builder__option">
					<input type="checkbox" name="lips_alt_page_title" value="1" <?php checked( $settings['page_title'] ); ?> data-lips-alt-part="page_title">
					<?php esc_html_e( 'Page Title', 'local-image-premium-support' ); ?>
				</label>

				<label class="lips-alt-builder__option">
					<input type="checkbox" name="lips_alt_site_title" value="1" <?php checked( $settings['site_title'] ); ?> data-lips-alt-part="site_title">
					<?php esc_html_e( 'Site Title', 'local-image-premium-support' ); ?>
				</label>

				<label class="lips-alt-builder__option">
					<input type="checkbox" name="lips_alt_page_slug" value="1" <?php checked( $settings['page_slug'] ); ?> data-lips-alt-part="page_slug">
					<?php esc_html_e( 'Page Slug', 'local-image-premium-support' ); ?>
				</label>

				<?php if ( $show_focus ) : ?>
					<label class="lips-alt-builder__option">
						<input type="checkbox" name="lips_alt_focus_keyword" value="1" <?php checked( $settings['focus_keyword'] ); ?> data-lips-alt-part="focus_keyword">
						<?php esc_html_e( 'Focus Keyword', 'local-image-premium-support' ); ?>
						<span class="lips-alt-builder__badge"><?php esc_html_e( 'SmartCrawl', 'local-image-premium-support' ); ?></span>
					</label>
				<?php endif; ?>
			</fieldset>

			<div class="lips-alt-preview">
				<label class="lips-alt-preview__label" for="lips-alt-preview-value"><?php esc_html_e( 'Live preview', 'local-image-premium-support' ); ?></label>
				<input
					type="text"
					id="lips-alt-preview-value"
					class="lips-alt-preview__value"
					readonly
					value="<?php echo esc_attr( $preview_alt ); ?>"
					placeholder="<?php esc_attr_e( 'Select options above to preview an example alt string.', 'local-image-premium-support' ); ?>"
				>
			</div>

			<div class="lips-li-tools-actions">
				<?php submit_button( __( 'Save Settings', 'local-image-premium-support' ), 'primary', 'lips_image_alts_submit', false ); ?>
			</div>
		</form>

		<section class="lips-alt-scanner" id="lips-alt-scanner">
			<h3 class="lips-alt-scanner__title"><?php esc_html_e( 'Scan Images', 'local-image-premium-support' ); ?></h3>
			<p class="lips-alt-scanner__desc"><?php esc_html_e( 'Scan published content for images used in the body or as featured images. Unused media library files are skipped.', 'local-image-premium-support' ); ?></p>

			<fieldset class="lips-alt-scanner__post-types">
				<legend class="lips-alt-scanner__post-types-legend"><?php esc_html_e( 'Post types to scan', 'local-image-premium-support' ); ?></legend>
				<p class="lips-alt-scanner__post-types-hint"><?php esc_html_e( 'Saved when you click Save Settings above.', 'local-image-premium-support' ); ?></p>
				<?php foreach ( self::get_scannable_post_type_choices() as $slug => $post_type ) : ?>
					<label class="lips-alt-scanner__post-type">
						<input
							type="checkbox"
							form="lips-image-alts-form"
							name="lips_scan_post_types[]"
							value="<?php echo esc_attr( $slug ); ?>"
							<?php checked( in_array( $slug, $settings['scan_post_types'], true ) ); ?>
							data-lips-scan-post-type="<?php echo esc_attr( $slug ); ?>"
						>
						<?php echo esc_html( $post_type->labels->singular_name ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<div class="lips-alt-scanner__summary" id="lips-alt-scanner-summary" hidden>
				<div class="lips-alt-scanner__stat">
					<span class="lips-alt-scanner__stat-label"><?php esc_html_e( 'Images found', 'local-image-premium-support' ); ?></span>
					<strong id="lips-alt-stat-total">0</strong>
				</div>
				<div class="lips-alt-scanner__stat">
					<span class="lips-alt-scanner__stat-label"><?php esc_html_e( 'Missing rendered alt', 'local-image-premium-support' ); ?></span>
					<strong id="lips-alt-stat-missing">0</strong>
				</div>
				<div class="lips-alt-scanner__stat">
					<span class="lips-alt-scanner__stat-label"><?php esc_html_e( 'With rendered alt', 'local-image-premium-support' ); ?></span>
					<strong id="lips-alt-stat-with">0</strong>
				</div>
			</div>

			<div class="lips-alt-scanner__controls">
				<button type="button" class="button button-primary" id="lips-alt-run-scan"><?php esc_html_e( 'Run Scan', 'local-image-premium-support' ); ?></button>
				<div class="lips-alt-scanner__progress" id="lips-alt-scan-progress" hidden>
					<div class="lips-alt-scanner__progress-bar" id="lips-alt-scan-progress-bar"></div>
					<span class="lips-alt-scanner__progress-text" id="lips-alt-scan-progress-text"></span>
				</div>
			</div>

			<div class="lips-alt-scanner__table-wrap" id="lips-alt-scanner-results" hidden>
				<table class="lips-alt-scanner__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Thumbnail', 'local-image-premium-support' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Filename', 'local-image-premium-support' ); ?></th>
							<th scope="col"><?php esc_html_e( 'URL', 'local-image-premium-support' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Stored Alt', 'local-image-premium-support' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Rendered Alt', 'local-image-premium-support' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Usage', 'local-image-premium-support' ); ?></th>
						</tr>
					</thead>
					<tbody id="lips-alt-scanner-body"></tbody>
				</table>
			</div>
		</section>
		<?php
	}

	/**
	 * Inject alt on WordPress attachment images.
	 */
	public static function filter_attachment_attributes( $attr, $attachment, $size ) {
		unset( $size );

		if ( ! empty( $attr['alt'] ) || ! self::has_active_settings() ) {
			return $attr;
		}

		$post_id = self::resolve_context_post_id( is_object( $attachment ) ? (int) $attachment->ID : 0 );

		if ( ! $post_id ) {
			return $attr;
		}

		$alt = self::get_alt_for_post( $post_id );

		if ( $alt ) {
			$attr['alt'] = $alt;
		}

		return $attr;
	}

	/**
	 * Inject alt on raw img tags in post content.
	 */
	public static function filter_content_images( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, '<img' ) || ! self::has_active_settings() ) {
			return $content;
		}

		$post_id = self::resolve_context_post_id( 0 );

		if ( ! $post_id ) {
			return $content;
		}

		$alt = self::get_alt_for_post( $post_id );

		if ( ! $alt ) {
			return $content;
		}

		return preg_replace_callback(
			'/<img\b[^>]*>/i',
			function ( $matches ) use ( $alt ) {
				$tag = $matches[0];

				if ( preg_match( '/\salt=(["\'])(.*?)\1/i', $tag, $alt_match ) && '' !== trim( $alt_match[2] ) ) {
					return $tag;
				}

				if ( preg_match( '/\salt=(["\'])\1/i', $tag ) ) {
					return preg_replace( '/\salt=(["\'])\1/i', 'alt="' . esc_attr( $alt ) . '"', $tag, 1 );
				}

				if ( preg_match( '/\salt=(["\'])(.*?)\1/i', $tag ) ) {
					return preg_replace( '/\salt=(["\'])(.*?)\1/i', 'alt="' . esc_attr( $alt ) . '"', $tag, 1 );
				}

				return preg_replace( '/<img/i', '<img alt="' . esc_attr( $alt ) . '"', $tag, 1 );
			},
			$content
		);
	}

	/**
	 * AJAX: scan pages and posts for used images in batches.
	 */
	public static function ajax_scan_images() {
		check_ajax_referer( 'lips_image_alts_scan', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'local-image-premium-support' ) ), 403 );
		}

		$offset       = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$post_types   = isset( $_POST['post_types'] ) ? (array) wp_unslash( $_POST['post_types'] ) : array();
		$post_types   = self::sanitize_scan_post_types( $post_types );
		$registry_key = self::get_scan_registry_key( $post_types );

		if ( 0 === $offset ) {
			delete_transient( $registry_key );
		}

		$registry = get_transient( $registry_key );

		if ( ! is_array( $registry ) ) {
			$registry = array();
		}

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => self::SCAN_BATCH,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$rows = array();

		foreach ( $query->posts as $post_id ) {
			$post_id   = (int) $post_id;
			$image_map = self::get_post_image_map( $post_id );

			foreach ( $image_map as $attachment_id => $usage_types ) {
				if ( ! isset( $registry[ $attachment_id ] ) ) {
					$registry[ $attachment_id ] = array(
						'context_post_id' => $post_id,
						'posts'           => array(),
					);
				}

				$registry[ $attachment_id ]['posts'][ $post_id ] = $usage_types;

				if ( ! isset( $registry[ $attachment_id ]['added_to_results'] ) ) {
					$registry[ $attachment_id ]['added_to_results'] = true;
					$rows[] = self::build_scan_row( $attachment_id, $registry[ $attachment_id ]['context_post_id'], $registry[ $attachment_id ] );
				}
			}
		}

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['usage'] = self::format_scan_usage( $registry[ $row['id'] ] );
		}

		set_transient( $registry_key, $registry, 15 * MINUTE_IN_SECONDS );

		wp_send_json_success(
			array(
				'rows'         => $rows,
				'offset'       => $offset,
				'batch'        => count( $query->posts ),
				'total'        => (int) $query->found_posts,
				'images_found' => count( $registry ),
				'has_more'     => ( $offset + count( $query->posts ) ) < (int) $query->found_posts,
			)
		);
	}

	/**
	 * Build a scanner result row for an attachment.
	 */
	private static function build_scan_row( $attachment_id, $context_post_id, $registry_entry ) {
		$attachment_id   = (int) $attachment_id;
		$context_post_id = (int) $context_post_id;
		$file            = get_attached_file( $attachment_id );
		$filename        = $file ? wp_basename( $file ) : get_the_title( $attachment_id );
		$url             = wp_get_attachment_url( $attachment_id );
		$stored_alt      = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		$effective_alt   = self::get_effective_alt_for_scan( $stored_alt, $context_post_id );
		$thumb           = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );

		return array(
			'id'            => $attachment_id,
			'thumbnail'     => $thumb ? $thumb : '',
			'filename'      => $filename,
			'url'           => $url ? $url : '',
			'stored_alt'    => $stored_alt,
			'effective_alt' => $effective_alt,
			'is_dynamic'    => '' === $stored_alt && '' !== $effective_alt,
			'has_alt'       => '' !== $effective_alt,
			'usage'         => self::format_scan_usage( $registry_entry ),
		);
	}

	/**
	 * Images used on a page or post (featured and in content).
	 */
	private static function get_post_image_map( $post_id ) {
		$post_id  = (int) $post_id;
		$images   = array();
		$featured = (int) get_post_thumbnail_id( $post_id );

		if ( $featured && wp_attachment_is_image( $featured ) ) {
			$images[ $featured ] = array(
				'featured' => true,
				'content'  => false,
			);
		}

		$content = (string) get_post_field( 'post_content', $post_id );

		if ( '' !== $content ) {
			preg_match_all( '/wp-image-(\d+)/', $content, $matches );

			if ( ! empty( $matches[1] ) ) {
				foreach ( $matches[1] as $attachment_id ) {
					$attachment_id = (int) $attachment_id;

					if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
						continue;
					}

					if ( ! isset( $images[ $attachment_id ] ) ) {
						$images[ $attachment_id ] = array(
							'featured' => false,
							'content'  => true,
						);
					} else {
						$images[ $attachment_id ]['content'] = true;
					}
				}
			}
		}

		return $images;
	}

	/**
	 * Human-readable usage string for scanner rows.
	 */
	private static function format_scan_usage( $registry_entry ) {
		if ( empty( $registry_entry['posts'] ) || ! is_array( $registry_entry['posts'] ) ) {
			return '—';
		}

		$labels = array();

		foreach ( $registry_entry['posts'] as $post_id => $usage_types ) {
			$parts = array();

			if ( ! empty( $usage_types['featured'] ) ) {
				$parts[] = __( 'Featured', 'local-image-premium-support' );
			}

			if ( ! empty( $usage_types['content'] ) ) {
				$parts[] = __( 'In Content', 'local-image-premium-support' );
			}

			$post_title = get_the_title( (int) $post_id );
			$labels[]   = trim( implode( ', ', $parts ) . ': ' . $post_title );
		}

		return $labels ? implode( ' | ', $labels ) : '—';
	}

	/**
	 * Public post types available in the scanner UI.
	 */
	public static function get_scannable_post_types() {
		$excluded = array(
			'attachment',
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_navigation',
			'wp_global_styles',
		);

		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		foreach ( $excluded as $slug ) {
			unset( $post_types[ $slug ] );
		}

		/**
		 * Filter scannable post types for the Image Alts scanner.
		 *
		 * @param WP_Post_Type[] $post_types Post type objects keyed by slug.
		 */
		return apply_filters( 'lips_image_alts_scannable_post_types', $post_types );
	}

	/**
	 * Post types for the scanner UI, with posts and pages listed first.
	 */
	private static function get_scannable_post_type_choices() {
		$post_types = self::get_scannable_post_types();
		$ordered    = array();

		foreach ( array( 'post', 'page' ) as $slug ) {
			if ( isset( $post_types[ $slug ] ) ) {
				$ordered[ $slug ] = $post_types[ $slug ];
				unset( $post_types[ $slug ] );
			}
		}

		uasort(
			$post_types,
			static function ( $left, $right ) {
				return strcasecmp( $left->labels->singular_name, $right->labels->singular_name );
			}
		);

		return array_merge( $ordered, $post_types );
	}

	/**
	 * Validate scanner post type selection.
	 */
	private static function sanitize_scan_post_types( $post_types ) {
		$allowed    = array_keys( self::get_scannable_post_types() );
		$post_types = array_values(
			array_intersect(
				array_map( 'sanitize_key', (array) $post_types ),
				$allowed
			)
		);

		if ( empty( $post_types ) ) {
			return array( 'post', 'page' );
		}

		return $post_types;
	}

	/**
	 * Transient key for deduplicating images during an active scan.
	 */
	private static function get_scan_registry_key( $post_types = array() ) {
		$user_id = get_current_user_id();

		if ( empty( $post_types ) ) {
			return 'lips_scan_registry_' . $user_id;
		}

		sort( $post_types );

		return 'lips_scan_registry_' . $user_id . '_' . md5( implode( ',', $post_types ) );
	}

	/**
	 * Build alt string for a post using saved settings.
	 */
	public static function build_alt_string( $post_id, $settings = null ) {
		if ( null === $settings ) {
			$settings = self::get_settings();
		}

		$post_id = (int) $post_id;
		$parts   = array();

		if ( ! empty( $settings['page_title'] ) && $post_id ) {
			$title = get_the_title( $post_id );
			if ( $title ) {
				$parts[] = $title;
			}
		}

		if ( ! empty( $settings['site_title'] ) ) {
			$site_title = get_bloginfo( 'name' );
			if ( $site_title ) {
				$parts[] = $site_title;
			}
		}

		if ( ! empty( $settings['page_slug'] ) && $post_id ) {
			$slug = get_post_field( 'post_name', $post_id );
			if ( is_string( $slug ) && $slug ) {
				$parts[] = $slug;
			}
		}

		if ( ! empty( $settings['focus_keyword'] ) && self::is_smartcrawl_active() && $post_id ) {
			$keyword = self::get_focus_keyword( $post_id );
			if ( $keyword ) {
				$parts[] = $keyword;
			}
		}

		return implode( self::ALT_SEPARATOR, array_filter( $parts ) );
	}

	/**
	 * Cached alt lookup for a post.
	 */
	private static function get_alt_for_post( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! $post_id ) {
			return '';
		}

		$cache_key = self::get_cache_key( $post_id );
		$cached    = get_transient( $cache_key );

		if ( is_string( $cached ) ) {
			return $cached;
		}

		$alt = self::build_alt_string( $post_id );
		set_transient( $cache_key, $alt, self::CACHE_TTL );

		return $alt;
	}

	/**
	 * Resolve post context for alt generation.
	 */
	private static function resolve_context_post_id( $attachment_id = 0 ) {
		$post_id = (int) get_queried_object_id();

		if ( $post_id ) {
			return $post_id;
		}

		if ( $attachment_id ) {
			$parent_id = (int) wp_get_post_parent_id( $attachment_id );
			if ( $parent_id ) {
				return $parent_id;
			}
		}

		global $post;

		if ( isset( $post->ID ) ) {
			return (int) $post->ID;
		}

		return 0;
	}

	/**
	 * Effective alt for scanner rows (stored meta or dynamic render).
	 */
	private static function get_effective_alt_for_scan( $stored_alt, $context_post_id ) {
		if ( '' !== $stored_alt ) {
			return $stored_alt;
		}

		if ( ! self::has_active_settings() || ! $context_post_id ) {
			return '';
		}

		return self::get_alt_for_post( (int) $context_post_id );
	}

	/**
	 * SmartCrawl focus keyword for a post.
	 */
	private static function get_focus_keyword( $post_id ) {
		$keyword = get_post_meta( $post_id, 'wds_focus_keyword', true );

		if ( is_string( $keyword ) && '' !== trim( $keyword ) ) {
			return trim( $keyword );
		}

		$keywords = get_post_meta( $post_id, '_wds_focus_keywords', true );

		if ( is_array( $keywords ) ) {
			$keywords = array_filter( array_map( 'trim', $keywords ) );
			if ( $keywords ) {
				return implode( ', ', $keywords );
			}
		}

		if ( is_string( $keywords ) && '' !== trim( $keywords ) ) {
			return trim( $keywords );
		}

		return '';
	}

	/**
	 * Preview sample values for the admin UI.
	 */
	private static function get_preview_parts( $post_id ) {
		$post_id = (int) $post_id;

		return array(
			'page_title'    => $post_id ? get_the_title( $post_id ) : __( 'Sample Page Title', 'local-image-premium-support' ),
			'site_title'    => get_bloginfo( 'name' ) ?: __( 'Sample Site', 'local-image-premium-support' ),
			'page_slug'     => $post_id ? (string) get_post_field( 'post_name', $post_id ) : 'sample-page',
			'focus_keyword' => $post_id ? self::get_focus_keyword( $post_id ) : __( 'sample keyword', 'local-image-premium-support' ),
		);
	}

	/**
	 * Use a recent published post for admin preview context.
	 */
	private static function get_preview_post_id() {
		$posts = get_posts(
			array(
				'numberposts' => 1,
				'post_status' => 'publish',
				'post_type'   => array( 'post', 'page' ),
			)
		);

		return ! empty( $posts[0]->ID ) ? (int) $posts[0]->ID : 0;
	}

	/**
	 * Transient cache key for a post alt string.
	 */
	private static function get_cache_key( $post_id ) {
		return 'lips_alt_' . (int) $post_id . '_' . md5( wp_json_encode( self::get_settings() ) );
	}

	/**
	 * Clear scanner state after settings change.
	 *
	 * Alt transients are keyed by post ID and settings hash, so saved changes
	 * use fresh keys automatically. Leftover entries expire within CACHE_TTL.
	 */
	private static function clear_alt_cache() {
		delete_transient( self::get_scan_registry_key() );
	}
}
