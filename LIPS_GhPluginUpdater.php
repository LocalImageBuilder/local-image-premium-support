<?php

declare(strict_types=1);

/**
 * Update WordPress plugin from a GitHub repository.
 */
class LIPS_GhPluginUpdater
{
    private $file;
    private $plugin_data;
    private $basename;
    private $slug;
    private $active = false;
    private $github_response;

    public function __construct($file)
    {
        $this->file     = $file;
        $this->basename = plugin_basename($this->file);
        $this->slug     = dirname($this->basename);
        if ('.' === $this->slug) {
            $this->slug = basename($this->file, '.php');
        }
    }

    public function init(): void
    {
        add_filter('pre_set_site_transient_update_plugins', array($this, 'modify_transient'), 10, 1);
        add_filter('http_request_args', array($this, 'set_header_token'), 10, 2);
        add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
    }

    public function modify_transient($transient)
    {
        if (! is_object($transient) || ! property_exists($transient, 'checked')) {
            return $transient;
        }

        if (! isset($transient->checked[ $this->basename ])) {
            return $transient;
        }

        $this->get_repository_info();
        $this->get_plugin_data();

        $remote_version = $this->normalize_version($this->github_response['tag_name'] ?? '');
        $local_version  = $transient->checked[ $this->basename ];

        if ($remote_version && version_compare($remote_version, $local_version, 'gt')) {
            $transient->response[ $this->basename ] = (object) array(
                'url'         => $this->plugin_data['PluginURI'],
                'slug'        => $this->slug,
                'package'     => $this->github_response['zipball_url'],
                'new_version' => $remote_version,
            );
        }

        return $transient;
    }

    public function plugin_popup($result, $action, $args)
    {
        if ('plugin_information' !== $action || empty($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $this->get_repository_info();
        $this->get_plugin_data();

        return (object) array(
            'name'              => $this->plugin_data['Name'],
            'slug'              => $this->slug,
            'version'           => $this->normalize_version($this->github_response['tag_name'] ?? ''),
            'author'            => $this->plugin_data['AuthorName'],
            'author_profile'    => $this->plugin_data['AuthorURI'],
            'last_updated'      => $this->github_response['published_at'] ?? '',
            'homepage'          => $this->plugin_data['PluginURI'],
            'short_description' => $this->plugin_data['Description'],
            'sections'          => array(
                'Description' => $this->plugin_data['Description'],
                'Updates'     => $this->github_response['body'] ?? '',
            ),
            'download_link'     => $this->github_response['zipball_url'],
        );
    }

    public function after_install($response, $hook_extra, $result)
    {
        global $wp_filesystem;

        $install_directory = plugin_dir_path($this->file);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;

        if ($this->active) {
            activate_plugin($this->basename);
        }

        return $response;
    }

    public function set_header_token($parsed_args, $url)
    {
        $parsed_url = parse_url($url);

        if ('api.github.com' === ($parsed_url['host'] ?? null) && isset($parsed_url['query'])) {
            parse_str($parsed_url['query'], $query);

            if (isset($query['access_token']) && LIPS_GHPU_AUTH_TOKEN) {
                $parsed_args['headers']['Authorization'] = 'token ' . LIPS_GHPU_AUTH_TOKEN;
                $this->active                            = is_plugin_active($this->basename);
            }
        }

        return $parsed_args;
    }

    private function get_repository_info(): void
    {
        if (null !== $this->github_response) {
            return;
        }

        $args = array(
            'method'      => 'GET',
            'timeout'     => 5,
            'redirection' => 5,
            'httpversion' => '1.0',
            'sslverify'   => true,
        );

        if (LIPS_GHPU_AUTH_TOKEN) {
            $args['headers'] = array(
                'Authorization' => 'token ' . LIPS_GHPU_AUTH_TOKEN,
            );
        }

        $request_uri = sprintf(LIPS_GH_REQUEST_URI, LIPS_GHPU_USERNAME, LIPS_GHPU_REPOSITORY);
        $request     = wp_remote_get($request_uri, $args);
        $response    = json_decode(wp_remote_retrieve_body($request), true);

        if (is_array($response) && isset($response[0])) {
            $response = $response[0];
        }

        if (LIPS_GHPU_AUTH_TOKEN && is_array($response) && ! empty($response['zipball_url'])) {
            $response['zipball_url'] = add_query_arg('access_token', LIPS_GHPU_AUTH_TOKEN, $response['zipball_url']);
        }

        $this->github_response = is_array($response) ? $response : array();
    }

    private function get_plugin_data(): void
    {
        if (null === $this->plugin_data) {
            $this->plugin_data = get_plugin_data($this->file);
        }
    }

    private function normalize_version($version): string
    {
        return ltrim((string) $version, 'vV');
    }
}
