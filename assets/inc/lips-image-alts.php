<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Image alt builder, render-time injection, and media scanner.
 */
class LIPS_Image_Alts {

	const OPTION_KEY          = 'li_tools_image_alt_settings';
	const SCAN_RESULTS_OPTION = 'lips_image_alts_scan_results';
	const SCAN_BUFFER_OPTION  = 'lips_image_alts_scan_buffer';
	const SCAN_BATCH          = 50;
	const CACHE_TTL      = HOUR_IN_SECONDS;
	const ALT_SEPARATOR  = ' – ';

	public static function init() {
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'filter_attachment_attributes' ), 10, 3 );
		add_filter( 'the_content', array( __CLASS__, 'filter_content_images' ), 15 );
		add_filter( 'render_block', array( __CLASS__, 'filter_rendered_block' ), 20, 2 );
		add_filter( 'widget_text_content', array( __CLASS__, 'filter_content_images' ), 15 );
		add_filter( 'widget_block_content', array( __CLASS__, 'filter_content_images' ), 15 );
		add_filter( 'elementor/widget/render_content', array( __CLASS__, 'filter_content_images' ), 15 );

		add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'preserve_scan_query_args' ), 9999 );
		add_action( 'wp_ajax_lips_scan_images', array( __CLASS__, 'ajax_scan_images' ) );
		add_filter( 'manage_media_columns', array( __CLASS__, 'add_media_list_columns' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_media_list_column' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_media_list_styles' ) );
	}

	/**
	 * Default checkbox settings.
	 */
	public static function get_default_settings() {
		return array(
			'page_title'      => false,
			'site_title'      => false,
			'page_slug'       => false,
			'focus_keyword'      => false,
			'override_existing'  => false,
			'scan_post_types'    => array( 'post', 'page' ),
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
			'focus_keyword'      => ! empty( $_POST['lips_alt_focus_keyword'] ),
			'override_existing'  => ! empty( $_POST['lips_alt_override_existing'] ),
			'scan_post_types'    => self::sanitize_scan_post_types( $scan_post_types ),
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
			'1.0.7',
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
					'rescan'       => __( 'Rescan', 'local-image-premium-support' ),
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
		$saved_scan      = self::get_saved_scan();
		$saved_rows      = $saved_scan['rows'];
		$has_saved_scan  = ! empty( $saved_scan['scanned_at'] );
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

				<label class="lips-alt-builder__option lips-alt-builder__option--separate">
					<input type="checkbox" name="lips_alt_override_existing" value="1" <?php checked( ! empty( $settings['override_existing'] ) ); ?>>
					<?php esc_html_e( 'Override existing alts', 'local-image-premium-support' ); ?>
				</label>
				<p class="lips-alt-builder__hint"><?php esc_html_e( 'Off by default. When enabled, generated alt text replaces alt text that is already set.', 'local-image-premium-support' ); ?></p>
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
			<p class="lips-alt-scanner__desc"><?php esc_html_e( 'Scan published content for images used on the front end. Empty alts are saved to the media library and are not overwritten once set. Purge any page cache after a scan so stored alts appear in cached HTML.', 'local-image-premium-support' ); ?></p>

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

			<p class="lips-alt-scanner__last" id="lips-alt-scan-last" <?php echo $has_saved_scan ? '' : 'hidden'; ?>>
				<?php
				printf(
					/* translators: %s: date and time of the last image scan. */
					esc_html__( 'Last scanned %s. Results stay here until you rescan.', 'local-image-premium-support' ),
					'<time id="lips-alt-scan-last-time">' . esc_html( $has_saved_scan ? self::format_scan_timestamp( $saved_scan['scanned_at'] ) : '' ) . '</time>'
				);
				?>
			</p>

			<div class="lips-alt-scanner__summary" id="lips-alt-scanner-summary" <?php echo $has_saved_scan ? '' : 'hidden'; ?>>
				<div class="lips-alt-scanner__stat">
					<span class="lips-alt-scanner__stat-label"><?php esc_html_e( 'Images found', 'local-image-premium-support' ); ?></span>
					<strong id="lips-alt-stat-total"><?php echo esc_html( (string) $saved_scan['total'] ); ?></strong>
				</div>
				<div class="lips-alt-scanner__stat">
					<span class="lips-alt-scanner__stat-label"><?php esc_html_e( 'Missing rendered alt', 'local-image-premium-support' ); ?></span>
					<strong id="lips-alt-stat-missing"><?php echo esc_html( (string) $saved_scan['missing'] ); ?></strong>
				</div>
				<div class="lips-alt-scanner__stat">
					<span class="lips-alt-scanner__stat-label"><?php esc_html_e( 'With rendered alt', 'local-image-premium-support' ); ?></span>
					<strong id="lips-alt-stat-with"><?php echo esc_html( (string) $saved_scan['with_alt'] ); ?></strong>
				</div>
			</div>

			<div class="lips-alt-scanner__controls">
				<button type="button" class="button button-primary" id="lips-alt-run-scan"><?php echo esc_html( $has_saved_scan ? __( 'Rescan', 'local-image-premium-support' ) : __( 'Run Scan', 'local-image-premium-support' ) ); ?></button>
				<div class="lips-alt-scanner__progress" id="lips-alt-scan-progress" hidden>
					<div class="lips-alt-scanner__progress-bar" id="lips-alt-scan-progress-bar"></div>
					<span class="lips-alt-scanner__progress-text" id="lips-alt-scan-progress-text"></span>
				</div>
			</div>

			<div class="lips-alt-scanner__table-wrap" id="lips-alt-scanner-results" <?php echo $has_saved_scan ? '' : 'hidden'; ?>>
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
					<tbody id="lips-alt-scanner-body">
						<?php
						if ( $has_saved_scan && empty( $saved_rows ) ) {
							echo '<tr><td colspan="6">' . esc_html__( 'No images found in the selected post types.', 'local-image-premium-support' ) . '</td></tr>';
						}

						foreach ( $saved_rows as $row ) {
							self::render_scan_result_row( $row );
						}
						?>
					</tbody>
				</table>
			</div>
		</section>
		<?php
	}

	/**
	 * Add Title and Alt columns to the media library list view.
	 */
	public static function add_media_list_columns( $columns ) {
		$updated = array();

		foreach ( $columns as $key => $label ) {
			$updated[ $key ] = $label;

			if ( 'title' === $key ) {
				$updated['lips_attachment_title'] = __( 'Title', 'local-image-premium-support' );
				$updated['lips_alt']              = __( 'Alt', 'local-image-premium-support' );
			}
		}

		if ( ! isset( $updated['lips_alt'] ) ) {
			$updated['lips_attachment_title'] = __( 'Title', 'local-image-premium-support' );
			$updated['lips_alt']              = __( 'Alt', 'local-image-premium-support' );
		}

		return $updated;
	}

	/**
	 * Render Title and Alt cells in the media library list view.
	 */
	public static function render_media_list_column( $column, $post_id ) {
		$post_id = (int) $post_id;

		if ( 'lips_attachment_title' === $column ) {
			$title = get_the_title( $post_id );
			echo '' !== $title ? esc_html( $title ) : esc_html( '—' );
			return;
		}

		if ( 'lips_alt' !== $column ) {
			return;
		}

		if ( ! wp_attachment_is_image( $post_id ) ) {
			echo esc_html( '—' );
			return;
		}

		$alt = trim( (string) get_post_meta( $post_id, '_wp_attachment_image_alt', true ) );

		if ( '' === $alt ) {
			echo '<span class="lips-media-alt-empty">' . esc_html__( 'None', 'local-image-premium-support' ) . '</span>';
			return;
		}

		echo esc_html( $alt );
	}

	/**
	 * Style empty alt text in the media library list view.
	 */
	public static function enqueue_media_list_styles( $hook ) {
		if ( 'upload.php' !== $hook ) {
			return;
		}

		wp_add_inline_style(
			'lips-support-styles',
			'.lips-media-alt-empty{color:#646970;}'
		);
	}

	/**
	 * Inject alt on WordPress attachment images and store it when missing.
	 */
	public static function filter_attachment_attributes( $attr, $attachment, $size ) {
		unset( $size );

		if ( is_admin() || ( ! empty( $attr['alt'] ) && ! self::should_override_existing() ) ) {
			return $attr;
		}

		$attachment_id = is_object( $attachment ) ? (int) $attachment->ID : 0;
		$alt           = self::resolve_alt_for_attachment( $attachment_id );

		if ( '' === $alt ) {
			return $attr;
		}

		$attr['alt'] = $alt;
		self::maybe_persist_attachment_alt( $attachment_id, $alt );

		return $attr;
	}

	/**
	 * Inject alt on images rendered inside blocks, including template parts.
	 */
	public static function filter_rendered_block( $block_content, $block ) {
		unset( $block );

		return self::filter_content_images( $block_content );
	}

	/**
	 * Inject alt on raw img tags and store it on the attachment when possible.
	 */
	public static function filter_content_images( $content ) {
		if ( is_admin() || ! is_string( $content ) || false === stripos( $content, '<img' ) ) {
			return $content;
		}

		$context_post_id = self::resolve_context_post_id( 0 );

		return preg_replace_callback(
			'/<img\b[^>]*>/i',
			function ( $matches ) use ( $context_post_id ) {
				return self::inject_alt_into_img_tag( $matches[0], $context_post_id );
			},
			$content
		);
	}

	/**
	 * Keep the image scan query at its batch size if another plugin changes it.
	 */
	public static function preserve_scan_query_args( $query ) {
		if ( ! $query instanceof WP_Query || empty( $query->query['lips_image_alts_scan'] ) ) {
			return;
		}

		$original = $query->query;

		$query->set( 'posts_per_page', self::SCAN_BATCH );
		$query->set( 'post_status', 'publish' );
		$query->set( 'ignore_sticky_posts', true );
		$query->set( 'no_found_rows', false );

		if ( isset( $original['post_type'] ) ) {
			$query->set( 'post_type', $original['post_type'] );
		}

		if ( isset( $original['offset'] ) ) {
			$query->set( 'offset', $original['offset'] );
		}
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
			delete_option( self::SCAN_BUFFER_OPTION );
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
				'ignore_sticky_posts'    => true,
				'cache_results'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'lips_image_alts_scan'   => true,
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

		$found_posts = (int) $query->found_posts;
		$finished    = ( $offset + count( $query->posts ) ) >= $found_posts;
		$scanned_at  = self::store_scan_batch( $rows, $finished );

		wp_send_json_success(
			array(
				'rows'         => $rows,
				'offset'       => $offset,
				'batch'        => count( $query->posts ),
				'total'        => $found_posts,
				'images_found' => count( $registry ),
				'has_more'     => ! $finished,
				'scanned_at'   => $scanned_at,
			)
		);
	}

	/**
	 * Last completed scan saved for the Image Alts screen.
	 */
	private static function get_saved_scan() {
		$saved = get_option( self::SCAN_RESULTS_OPTION, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$rows = isset( $saved['rows'] ) && is_array( $saved['rows'] ) ? $saved['rows'] : array();

		return array(
			'scanned_at' => isset( $saved['scanned_at'] ) ? (int) $saved['scanned_at'] : 0,
			'rows'       => $rows,
			'total'      => isset( $saved['total'] ) ? (int) $saved['total'] : count( $rows ),
			'missing'    => isset( $saved['missing'] ) ? (int) $saved['missing'] : 0,
			'with_alt'   => isset( $saved['with_alt'] ) ? (int) $saved['with_alt'] : 0,
		);
	}

	/**
	 * Append a scan batch. The visible result set is replaced only when the scan finishes.
	 */
	private static function store_scan_batch( $rows, $finished ) {
		$buffer = get_option( self::SCAN_BUFFER_OPTION, array() );

		if ( ! is_array( $buffer ) ) {
			$buffer = array();
		}

		if ( $rows ) {
			$buffer = array_merge( $buffer, $rows );
			update_option( self::SCAN_BUFFER_OPTION, $buffer, false );
		}

		if ( ! $finished ) {
			return '';
		}

		$missing = 0;
		$with    = 0;

		foreach ( $buffer as $row ) {
			if ( ! empty( $row['has_alt'] ) ) {
				$with++;
			} else {
				$missing++;
			}
		}

		$scanned_at = time();

		update_option(
			self::SCAN_RESULTS_OPTION,
			array(
				'scanned_at' => $scanned_at,
				'rows'       => $buffer,
				'total'      => count( $buffer ),
				'missing'    => $missing,
				'with_alt'   => $with,
			),
			false
		);

		delete_option( self::SCAN_BUFFER_OPTION );

		return self::format_scan_timestamp( $scanned_at );
	}

	/**
	 * Display date for a stored scan.
	 */
	private static function format_scan_timestamp( $timestamp ) {
		$timestamp = (int) $timestamp;

		if ( ! $timestamp ) {
			return '';
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
	}

	/**
	 * One saved scanner row.
	 */
	private static function render_scan_result_row( $row ) {
		if ( ! is_array( $row ) ) {
			return;
		}

		$thumbnail = isset( $row['thumbnail'] ) ? (string) $row['thumbnail'] : '';
		$filename  = isset( $row['filename'] ) ? (string) $row['filename'] : '';
		$url       = isset( $row['url'] ) ? (string) $row['url'] : '';
		$stored    = isset( $row['stored_alt'] ) ? (string) $row['stored_alt'] : '';
		$rendered  = isset( $row['effective_alt'] ) ? (string) $row['effective_alt'] : '';
		$dynamic   = ! empty( $row['is_dynamic'] );
		$usage     = isset( $row['usage'] ) ? (string) $row['usage'] : '';
		?>
		<tr>
			<td>
				<?php if ( $thumbnail ) : ?>
					<img class="lips-alt-scanner__thumb" src="<?php echo esc_url( $thumbnail ); ?>" alt="" width="50" height="50">
				<?php else : ?>
					—
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $filename ? $filename : '—' ); ?></td>
			<td>
				<?php if ( $url ) : ?>
					<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $url ); ?></a>
				<?php else : ?>
					—
				<?php endif; ?>
			</td>
			<td class="<?php echo '' === $stored ? 'is-muted' : ''; ?>"><?php echo esc_html( '' !== $stored ? $stored : __( 'None', 'local-image-premium-support' ) ); ?></td>
			<td class="<?php echo '' === $rendered ? 'is-missing' : ''; ?>">
				<?php if ( '' !== $rendered ) : ?>
					<?php echo esc_html( $rendered ); ?>
					<?php if ( $dynamic ) : ?>
						<br><span class="lips-alt-scanner__badge"><?php esc_html_e( 'Dynamic', 'local-image-premium-support' ); ?></span>
					<?php endif; ?>
				<?php else : ?>
					<?php esc_html_e( 'None', 'local-image-premium-support' ); ?>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( '' !== $usage ? $usage : '—' ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Build a scanner result row for an attachment.
	 */
	private static function build_scan_row( $attachment_id, $context_post_id, $registry_entry ) {
		$attachment_id   = (int) $attachment_id;
		$context_post_id = (int) $context_post_id;
		$file       = get_attached_file( $attachment_id );
		$filename   = $file ? wp_basename( $file ) : get_the_title( $attachment_id );
		$url        = wp_get_attachment_url( $attachment_id );
		$stored_alt = self::get_stored_attachment_alt( $attachment_id );

		$can_write = $context_post_id && self::has_active_settings() && ( '' === $stored_alt || self::should_override_existing() );

		if ( $can_write ) {
			$generated = self::get_alt_for_post( $context_post_id );

			if ( '' !== $generated && self::persist_attachment_alt( $attachment_id, $generated ) ) {
				$stored_alt = $generated;
			}
		}

		$thumb = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );

		return array(
			'id'            => $attachment_id,
			'thumbnail'     => $thumb ? $thumb : '',
			'filename'      => $filename,
			'url'           => $url ? $url : '',
			'stored_alt'    => $stored_alt,
			'effective_alt' => $stored_alt,
			'is_dynamic'    => false,
			'has_alt'       => '' !== $stored_alt,
			'usage'         => self::format_scan_usage( $registry_entry ),
		);
	}

	/**
	 * Images used on a page or post (featured and in content).
	 */
	private static function get_post_image_map( $post_id ) {
		$post_id = (int) $post_id;
		$images  = array();
		$featured = (int) get_post_thumbnail_id( $post_id );

		self::add_image_usage( $images, $featured, 'featured' );

		$blobs = array(
			(string) get_post_field( 'post_content', $post_id ),
			(string) get_post_meta( $post_id, '_elementor_data', true ),
		);

		foreach ( $blobs as $blob ) {
			foreach ( self::extract_attachment_ids_from_blob( $blob ) as $attachment_id ) {
				self::add_image_usage( $images, $attachment_id, 'content' );
			}
		}

		return $images;
	}

	/**
	 * Record how an attachment is used on the post being scanned.
	 */
	private static function add_image_usage( &$images, $attachment_id, $usage ) {
		$attachment_id = (int) $attachment_id;

		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		if ( ! isset( $images[ $attachment_id ] ) ) {
			$images[ $attachment_id ] = array(
				'featured' => false,
				'content'  => false,
			);
		}

		if ( isset( $images[ $attachment_id ][ $usage ] ) ) {
			$images[ $attachment_id ][ $usage ] = true;
		}
	}

	/**
	 * Attachment IDs referenced in post HTML, blocks, shortcodes, or builder data.
	 */
	private static function extract_attachment_ids_from_blob( $blob ) {
		if ( ! is_string( $blob ) || '' === $blob ) {
			return array();
		}

		$blob = str_replace( '\/', '/', $blob );
		$ids  = array();

		if ( preg_match_all( '/wp-image-(\d+)/', $blob, $matches ) ) {
			foreach ( $matches[1] as $attachment_id ) {
				$ids[] = (int) $attachment_id;
			}
		}

		if ( preg_match_all( '/"(?:id|mediaId)"\s*:\s*"?(\d+)"?/', $blob, $matches ) ) {
			foreach ( $matches[1] as $attachment_id ) {
				$ids[] = (int) $attachment_id;
			}
		}

		if ( preg_match_all( '/"ids"\s*:\s*\[([0-9,\s]+)\]/', $blob, $lists ) ) {
			foreach ( $lists[1] as $list ) {
				foreach ( explode( ',', $list ) as $attachment_id ) {
					$ids[] = (int) $attachment_id;
				}
			}
		}

		if ( preg_match_all( '/\[gallery\b[^\]]*ids=(["\'])([^"\']+)\1/', $blob, $galleries ) ) {
			foreach ( $galleries[2] as $list ) {
				foreach ( explode( ',', $list ) as $attachment_id ) {
					$ids[] = (int) $attachment_id;
				}
			}
		}

		if ( preg_match_all( '/<img\b[^>]*\bsrc=(["\'])([^"\']+)\1/i', $blob, $sources ) ) {
			foreach ( $sources[2] as $src ) {
				$ids[] = self::attachment_id_from_url( $src );
			}
		}

		if ( preg_match_all( '#https?://[^"\'\s>]+\.(?:jpe?g|png|gif|webp|avif)(?:\?[^"\'\s>]*)?#i', $blob, $urls ) ) {
			foreach ( $urls[0] as $url ) {
				if ( false === strpos( $url, '/uploads/' ) ) {
					continue;
				}

				$ids[] = self::attachment_id_from_url( $url );
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
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
		if ( is_singular() ) {
			$post_id = (int) get_queried_object_id();

			if ( self::is_alt_context_post( $post_id ) ) {
				return $post_id;
			}
		}

		global $post;

		if ( isset( $post->ID ) && self::is_alt_context_post( (int) $post->ID ) ) {
			return (int) $post->ID;
		}

		if ( $attachment_id ) {
			$parent_id = (int) wp_get_post_parent_id( $attachment_id );

			if ( self::is_alt_context_post( $parent_id ) ) {
				return $parent_id;
			}
		}

		return 0;
	}

	/**
	 * Whether a post can supply page title, slug, and focus keyword context.
	 */
	private static function is_alt_context_post( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! $post_id ) {
			return false;
		}

		$post_type = get_post_type( $post_id );

		if ( ! is_string( $post_type ) || '' === $post_type ) {
			return false;
		}

		$excluded = array(
			'attachment',
			'revision',
			'nav_menu_item',
			'wp_template',
			'wp_template_part',
			'wp_navigation',
			'wp_global_styles',
		);

		return ! in_array( $post_type, $excluded, true );
	}

	/**
	 * Fill an empty alt on one img tag, preferring the saved attachment alt.
	 */
	private static function inject_alt_into_img_tag( $tag, $context_post_id ) {
		if ( preg_match( '/\salt=(["\'])(.*?)\1/i', $tag, $alt_match ) && '' !== trim( $alt_match[2] ) && ! self::should_override_existing() ) {
			return $tag;
		}

		$attachment_id = self::attachment_id_from_img_tag( $tag );
		$alt           = '';

		if ( $attachment_id ) {
			$alt = self::resolve_alt_for_attachment( $attachment_id, $context_post_id );

			if ( '' !== $alt ) {
				self::maybe_persist_attachment_alt( $attachment_id, $alt );
			}
		} elseif ( $context_post_id && self::has_active_settings() ) {
			$alt = self::get_alt_for_post( $context_post_id );
		}

		if ( '' === $alt ) {
			return $tag;
		}

		$escaped = esc_attr( $alt );

		if ( preg_match( '/\salt=(["\']).*?\1/i', $tag ) ) {
			return preg_replace( '/\salt=(["\']).*?\1/i', 'alt="' . $escaped . '"', $tag, 1 );
		}

		return preg_replace( '/<img/i', '<img alt="' . $escaped . '"', $tag, 1 );
	}

	/**
	 * Saved media-library alt, or a generated alt from the current page.
	 */
	private static function resolve_alt_for_attachment( $attachment_id, $context_post_id = null ) {
		$attachment_id = (int) $attachment_id;
		$stored_alt    = self::get_stored_attachment_alt( $attachment_id );

		if ( '' !== $stored_alt && ! self::should_override_existing() ) {
			return $stored_alt;
		}

		if ( ! self::has_active_settings() ) {
			return $stored_alt;
		}

		if ( null === $context_post_id ) {
			$context_post_id = self::resolve_context_post_id( $attachment_id );
		}

		$context_post_id = (int) $context_post_id;

		if ( ! $context_post_id ) {
			return $stored_alt;
		}

		$generated = self::get_alt_for_post( $context_post_id );

		return '' !== $generated ? $generated : $stored_alt;
	}

	/**
	 * Whether generated alt text should replace alt text that is already set.
	 */
	private static function should_override_existing() {
		$settings = self::get_settings();

		return ! empty( $settings['override_existing'] );
	}

	/**
	 * Alt text already stored on the attachment.
	 */
	private static function get_stored_attachment_alt( $attachment_id ) {
		return trim( (string) get_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Save generated alt text. Existing alt text is replaced only when override is enabled.
	 */
	private static function persist_attachment_alt( $attachment_id, $alt ) {
		$attachment_id = (int) $attachment_id;
		$alt           = sanitize_text_field( trim( (string) $alt ) );

		if ( ! $attachment_id || '' === $alt || ! wp_attachment_is_image( $attachment_id ) ) {
			return false;
		}

		$existing = self::get_stored_attachment_alt( $attachment_id );

		if ( '' !== $existing && ! self::should_override_existing() ) {
			return false;
		}

		if ( $existing === $alt ) {
			return true;
		}

		return (bool) update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
	}

	/**
	 * Persist from a front-end render. Admin, REST, and cron requests only display the alt.
	 */
	private static function maybe_persist_attachment_alt( $attachment_id, $alt ) {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_customize_preview() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		return self::persist_attachment_alt( $attachment_id, $alt );
	}

	/**
	 * Attachment ID from an img tag class or file URL.
	 */
	private static function attachment_id_from_img_tag( $tag ) {
		if ( preg_match( '/wp-image-(\d+)/', $tag, $match ) ) {
			return (int) $match[1];
		}

		if ( preg_match( '/\bsrc=(["\'])([^"\']+)\1/i', $tag, $match ) ) {
			return self::attachment_id_from_url( $match[2] );
		}

		return 0;
	}

	/**
	 * Resolve an uploads URL, including resized filenames, to an attachment ID.
	 */
	private static function attachment_id_from_url( $url ) {
		static $cache = array();

		$url = trim( (string) $url );

		if ( '' === $url ) {
			return 0;
		}

		$url = html_entity_decode( $url, ENT_QUOTES );
		$url = strtok( $url, '?' );

		if ( isset( $cache[ $url ] ) ) {
			return $cache[ $url ];
		}

		$attachment_id = (int) attachment_url_to_postid( $url );

		if ( ! $attachment_id ) {
			$stripped = preg_replace( '/-\d+x\d+(?=\.[a-zA-Z0-9]+$)/', '', $url );
			$stripped = is_string( $stripped ) ? preg_replace( '/-scaled(?=\.[a-zA-Z0-9]+$)/', '', $stripped ) : $url;

			if ( is_string( $stripped ) && $stripped !== $url ) {
				$attachment_id = (int) attachment_url_to_postid( $stripped );
			}
		}

		$cache[ $url ] = $attachment_id;

		return $attachment_id;
	}

	/**
	 * SmartCrawl focus keyword(s) for a post.
	 */
	private static function get_focus_keyword( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! $post_id ) {
			return '';
		}

		if ( function_exists( 'smartcrawl_get_value' ) ) {
			$formatted = self::format_focus_keywords( smartcrawl_get_value( 'focus-keywords', $post_id ) );

			if ( '' !== $formatted ) {
				return $formatted;
			}
		}

		$meta_keys = array(
			'_wds_focus-keywords',
			'_wds_focus_keywords',
			'_wds_focus_keyword',
			'wds_focus_keyword',
		);

		foreach ( $meta_keys as $meta_key ) {
			$formatted = self::format_focus_keywords( get_post_meta( $post_id, $meta_key, true ) );

			if ( '' !== $formatted ) {
				return $formatted;
			}
		}

		$analysis = get_post_meta( $post_id, '_wds_analysis', true );

		if ( is_array( $analysis ) ) {
			foreach ( array( 'focus-keywords', 'focus_keywords', 'focuskw' ) as $key ) {
				if ( empty( $analysis[ $key ] ) ) {
					continue;
				}

				$formatted = self::format_focus_keywords( $analysis[ $key ] );

				if ( '' !== $formatted ) {
					return $formatted;
				}
			}
		}

		return '';
	}

	/**
	 * Normalize SmartCrawl focus keyword meta into a single alt string.
	 *
	 * Multiple keywords are joined with " - ".
	 */
	private static function format_focus_keywords( $keywords ) {
		$keywords = self::normalize_focus_keyword_value( $keywords );
		$parts    = self::collect_focus_keyword_parts( $keywords );

		if ( empty( $parts ) ) {
			return '';
		}

		return implode( ' - ', $parts );
	}

	/**
	 * Unserialize or decode stored focus keyword values.
	 */
	private static function normalize_focus_keyword_value( $value ) {
		if ( is_string( $value ) ) {
			$value = trim( $value );

			if ( '' === $value ) {
				return '';
			}

			$unserialized = maybe_unserialize( $value );

			if ( $unserialized !== $value ) {
				return $unserialized;
			}

			$decoded = json_decode( $value, true );

			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return $value;
	}

	/**
	 * Flatten SmartCrawl focus keyword data into string parts.
	 */
	private static function collect_focus_keyword_parts( $keywords ) {
		$keywords = self::normalize_focus_keyword_value( $keywords );

		if ( is_string( $keywords ) || is_numeric( $keywords ) ) {
			$text = trim( (string) $keywords );

			if ( '' === $text ) {
				return array();
			}

			if ( str_contains( $text, ',' ) ) {
				return array_values(
					array_filter(
						array_map( 'trim', explode( ',', $text ) )
					)
				);
			}

			return array( $text );
		}

		if ( ! is_array( $keywords ) ) {
			return array();
		}

		$parts = array();

		foreach ( $keywords as $value ) {
			if ( is_string( $value ) || is_numeric( $value ) ) {
				$text = trim( (string) $value );

				if ( '' !== $text ) {
					$parts[] = $text;
				}
				continue;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			if ( isset( $value['keyword'] ) ) {
				$text = trim( (string) $value['keyword'] );
			} elseif ( isset( $value['focus-keyword'] ) ) {
				$text = trim( (string) $value['focus-keyword'] );
			} else {
				$parts = array_merge( $parts, self::collect_focus_keyword_parts( $value ) );
				continue;
			}

			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}

		return array_values( array_unique( array_filter( $parts ) ) );
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
