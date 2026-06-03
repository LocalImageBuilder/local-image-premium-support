<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Update WordPress plugin from a GitHub repository without the GitHub API.
 *
 * Reads the Version header from main, then downloads a matching release tag zip.
 * Public repos need no token; private repos require LIPS_GHPU_AUTH_TOKEN in wp-config.php.
 */
class LIPS_GhPluginUpdater {

	private $file;
	private $plugin_data;
	private $basename;
	private $slug;
	private $active = false;
	private $remote_version;

	public function __construct( $file ) {
		$this->file     = $file;
		$this->basename = plugin_basename( $this->file );
		$this->slug     = dirname( $this->basename );

		if ( '.' === $this->slug ) {
			$this->slug = basename( $this->file, '.php' );
		}
	}

	public function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'modify_transient' ), 10, 1 );
		add_filter( 'plugins_api', array( $this, 'plugin_popup' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );

		if ( LIPS_GHPU_AUTH_TOKEN ) {
			add_filter( 'http_request_args', array( $this, 'authorize_github_request' ), 10, 2 );
		}
	}

	public function modify_transient( $transient ) {
		if ( ! is_object( $transient ) || ! property_exists( $transient, 'checked' ) ) {
			return $transient;
		}

		if ( ! isset( $transient->checked[ $this->basename ] ) ) {
			return $transient;
		}

		$this->get_plugin_data();
		$remote_version = $this->get_remote_version();
		$local_version  = $transient->checked[ $this->basename ];

		if ( ! $remote_version || version_compare( $remote_version, $local_version, '<=' ) ) {
			return $transient;
		}

		$package_url = $this->get_package_url( $remote_version );

		if ( ! $this->package_exists( $package_url ) ) {
			return $transient;
		}

		$transient->response[ $this->basename ] = (object) array(
			'url'         => $this->plugin_data['PluginURI'],
			'slug'        => $this->slug,
			'package'     => $package_url,
			'new_version' => $remote_version,
		);

		return $transient;
	}

	public function plugin_popup( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$this->get_plugin_data();
		$remote_version = $this->get_remote_version();

		return (object) array(
			'name'              => $this->plugin_data['Name'],
			'slug'              => $this->slug,
			'version'           => $remote_version,
			'author'            => $this->plugin_data['AuthorName'],
			'author_profile'    => $this->plugin_data['AuthorURI'],
			'homepage'          => $this->plugin_data['PluginURI'],
			'short_description' => $this->plugin_data['Description'],
			'sections'          => array(
				'Description' => $this->plugin_data['Description'],
			),
			'download_link'     => $this->get_package_url( $remote_version ),
		);
	}

	public function after_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $response;
		}

		if ( ! is_array( $result ) || empty( $result['destination'] ) ) {
			return $response;
		}

		$this->active          = is_plugin_active( $this->basename );
		$install_directory     = plugin_dir_path( $this->file );
		$wp_filesystem->move( $result['destination'], $install_directory, true );
		$result['destination'] = $install_directory;

		if ( $this->active ) {
			activate_plugin( $this->basename );
		}

		return $response;
	}

	public function authorize_github_request( $parsed_args, $url ) {
		if ( ! $this->is_github_url( $url ) ) {
			return $parsed_args;
		}

		$parsed_args['headers']['Authorization'] = 'Bearer ' . LIPS_GHPU_AUTH_TOKEN;

		if ( is_plugin_active( $this->basename ) ) {
			$this->active = true;
		}

		return $parsed_args;
	}

	private function get_remote_version() {
		if ( null !== $this->remote_version ) {
			return $this->remote_version;
		}

		$this->remote_version = '';
		$request              = wp_remote_get( $this->get_raw_plugin_url(), $this->get_request_args() );

		if ( is_wp_error( $request ) ) {
			return $this->remote_version;
		}

		$code = (int) wp_remote_retrieve_response_code( $request );

		if ( $code < 200 || $code >= 300 ) {
			return $this->remote_version;
		}

		$body = wp_remote_retrieve_body( $request );

		if ( preg_match( '/Version:\s*([^\r\n*]+)/i', $body, $matches ) ) {
			$this->remote_version = trim( $matches[1] );
		}

		return $this->remote_version;
	}

	private function get_package_url( $version ) {
		$url = sprintf(
			'https://github.com/%s/%s/archive/refs/tags/%s.zip',
			rawurlencode( LIPS_GHPU_USERNAME ),
			rawurlencode( LIPS_GHPU_REPOSITORY ),
			rawurlencode( $version )
		);

		if ( LIPS_GHPU_AUTH_TOKEN ) {
			$url = add_query_arg( 'access_token', LIPS_GHPU_AUTH_TOKEN, $url );
		}

		return $url;
	}

	private function get_raw_plugin_url() {
		return sprintf(
			'https://raw.githubusercontent.com/%s/%s/%s/%s',
			rawurlencode( LIPS_GHPU_USERNAME ),
			rawurlencode( LIPS_GHPU_REPOSITORY ),
			rawurlencode( LIPS_GHPU_BRANCH ),
			rawurlencode( basename( $this->file ) )
		);
	}

	private function package_exists( $url ) {
		$response = wp_remote_head( $url, $this->get_request_args() );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return $code >= 200 && $code < 400;
	}

	private function get_request_args() {
		$args = array(
			'timeout'     => 15,
			'redirection' => 5,
			'sslverify'   => true,
		);

		if ( LIPS_GHPU_AUTH_TOKEN ) {
			$args['headers'] = array(
				'Authorization' => 'Bearer ' . LIPS_GHPU_AUTH_TOKEN,
			);
		}

		return $args;
	}

	private function is_github_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) ) {
			return false;
		}

		return in_array( $host, array( 'github.com', 'raw.githubusercontent.com', 'codeload.github.com', 'api.github.com' ), true );
	}

	private function get_plugin_data() {
		if ( null === $this->plugin_data ) {
			$this->plugin_data = get_plugin_data( $this->file );
		}
	}
}
