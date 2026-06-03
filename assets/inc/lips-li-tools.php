<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * LI Tools admin hub and virtual llms.txt management.
 */
class LIPS_LI_Tools {

	const PAGE_SLUG   = 'lips-li-tools';
	const OPTION_KEY  = 'lips_llms_txt';
	const DEFAULT_TAB = 'llms-txt';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_serve_llms_txt' ), 1 );
		add_action( 'admin_menu', array( __CLASS__, 'register_tools_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
	}

	public static function enqueue_admin_assets( $hook ) {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'lips-li-tools',
			LIPS_CORE_CSS . 'lips-li-tools.css',
			array( 'lips-support-styles' ),
			'1.0.0'
		);
	}

	public static function maybe_serve_llms_txt() {
		if ( ! self::is_llms_txt_request() ) {
			return;
		}

		$content = get_option( self::OPTION_KEY, '' );

		if ( ! is_string( $content ) ) {
			$content = '';
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		// Plain text file output; content is sanitized when saved.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $content;
		exit;
	}

	public static function register_tools_page() {
		add_management_page(
			'LI Tools',
			'LI Tools',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_tools_page' )
		);
	}

	public static function handle_save() {
		if ( ! isset( $_POST['lips_llms_txt_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'lips_save_llms_txt' );

		$content = sanitize_textarea_field( wp_unslash( (string) ( $_POST['lips_llms_txt'] ?? '' ) ) );

		update_option( self::OPTION_KEY, $content );

		add_settings_error(
			'lips_li_tools',
			'lips_llms_txt_saved',
			__( 'llms.txt content saved.', 'local-image-premium-support' ),
			'success'
		);
	}

	public static function render_tools_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs        = self::get_tabs();
		$current_tab = self::get_current_tab();
		?>
		<div class="wrap lips-li-tools-wrap">
			<header class="lips-li-tools-header">
				<img
					class="lips-li-tools-header__icon"
					src="<?php echo esc_url( LIPS_CORE_IMG . 'LocalImage_Icon_2.png' ); ?>"
					alt=""
					width="44"
					height="44"
				>
				<div>
					<h1 class="lips-li-tools-header__title">
						<span>LI</span> Tools
					</h1>
					<p class="lips-li-tools-header__subtitle">
						<?php esc_html_e( 'Local Image site utilities and configuration.', 'local-image-premium-support' ); ?>
					</p>
				</div>
			</header>

			<nav class="lips-li-tools-tabs" aria-label="<?php esc_attr_e( 'LI Tools sections', 'local-image-premium-support' ); ?>">
				<?php foreach ( $tabs as $tab_id => $tab_label ) : ?>
					<a
						href="<?php echo esc_url( self::get_tab_url( $tab_id ) ); ?>"
						class="lips-li-tools-tabs__link<?php echo esc_attr( $current_tab === $tab_id ? ' is-active' : '' ); ?>"
					><?php echo esc_html( $tab_label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="lips-li-tools-panel">
				<div class="lips-li-tools-panel__inner">
					<?php settings_errors( 'lips_li_tools' ); ?>
					<?php
					switch ( $current_tab ) {
						case 'llms-txt':
							self::render_llms_txt_tab();
							break;
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	private static function render_llms_txt_tab() {
		$content  = get_option( self::OPTION_KEY, '' );
		$llms_url = home_url( '/llms.txt' );

		if ( ! is_string( $content ) ) {
			$content = '';
		}
		?>
		<h2 class="lips-li-tools-panel__title"><?php esc_html_e( 'llms.txt', 'local-image-premium-support' ); ?></h2>

		<div class="lips-li-tools-callout">
			<p>
				<?php
				esc_html_e(
					'llms.txt is a plain-text file that tells AI systems and large language models how to understand and use your site. It is served virtually — no file is written to your server.',
					'local-image-premium-support'
				);
				?>
			</p>
		</div>

		<div class="lips-li-tools-url">
			<span class="lips-li-tools-url__label"><?php esc_html_e( 'Public URL', 'local-image-premium-support' ); ?></span>
			<code class="lips-li-tools-url__value"><?php echo esc_html( $llms_url ); ?></code>
			<a
				class="lips-li-tools-url__link"
				href="<?php echo esc_url( $llms_url ); ?>"
				target="_blank"
				rel="noopener noreferrer"
			><?php esc_html_e( 'View live', 'local-image-premium-support' ); ?></a>
		</div>

		<form method="post" action="<?php echo esc_url( self::get_tab_url( 'llms-txt' ) ); ?>">
			<?php wp_nonce_field( 'lips_save_llms_txt' ); ?>

			<div class="lips-li-tools-editor">
				<label class="lips-li-tools-editor__label" for="lips_llms_txt">
					<?php esc_html_e( 'File content', 'local-image-premium-support' ); ?>
				</label>
				<textarea
					name="lips_llms_txt"
					id="lips_llms_txt"
					class="lips-li-tools-editor__textarea"
					rows="20"
					spellcheck="false"
				><?php echo esc_textarea( $content ); ?></textarea>
				<p class="lips-li-tools-editor__hint">
					<?php esc_html_e( 'Plain text only. Leave empty to serve a blank llms.txt response.', 'local-image-premium-support' ); ?>
				</p>
			</div>

			<div class="lips-li-tools-actions">
				<?php submit_button( __( 'Save llms.txt', 'local-image-premium-support' ), 'primary', 'lips_llms_txt_submit', false ); ?>
			</div>
		</form>
		<?php
	}

	private static function get_tabs() {
		return array(
			'llms-txt' => 'llms.txt',
		);
	}

	private static function get_current_tab() {
		$tabs = self::get_tabs();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin tab navigation only.
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return self::DEFAULT_TAB;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin tab navigation only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : self::DEFAULT_TAB;

		if ( ! isset( $tabs[ $tab ] ) ) {
			return self::DEFAULT_TAB;
		}

		return $tab;
	}

	private static function get_tab_url( $tab ) {
		return add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'tools.php' )
		);
	}

	private static function is_llms_txt_request() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}

		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( is_string( $home_path ) && '/' !== untrailingslashit( $home_path ) && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( untrailingslashit( $home_path ) ) );
		}

		$path = untrailingslashit( $path );

		return '/llms.txt' === $path;
	}
}
