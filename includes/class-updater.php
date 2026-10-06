<?php
/**
 * GitHub Releases auto-updater for HR Nomination Form.
 * Self-contained without external Composer dependencies.
 *
 * @package HR_Nomination_Form
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HR_Nomination_Updater
 */
class HR_Nomination_Updater {

	/**
	 * Default GitHub repository slug.
	 *
	 * @var string
	 */
	const DEFAULT_REPO = 'Hunter28-lucky/Work_HR_new_Plugin';

	/**
	 * Plugin file basename (e.g. hr-nomination-form/hr-nomination-form.php).
	 *
	 * @var string
	 */
	private string $plugin_basename;

	/**
	 * Plugin slug (e.g. hr-nomination-form).
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Current plugin version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Cache expiration duration in seconds (6 hours default).
	 *
	 * @var int
	 */
	const CACHE_HOURS = 6;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Main plugin file path.
	 * @param string $version Current plugin version.
	 */
	public function __construct( string $plugin_file, string $version ) {
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->slug            = dirname( $this->plugin_basename );
		$this->version         = $version;

		// WordPress update transient hook
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_plugin_update' ) );

		// Plugin info modal popup hook
		add_filter( 'plugins_api', array( $this, 'filter_plugin_information' ), 20, 3 );

		// Fix destination folder name after update from GitHub zipball
		add_filter( 'upgrader_post_install', array( $this, 'post_install_rename_folder' ), 10, 3 );

		// Authenticate download requests for private repos
		add_filter( 'http_request_args', array( $this, 'authenticate_github_requests' ), 10, 2 );

		// Manual update check handler
		add_action( 'admin_post_hr_check_updates', array( $this, 'handle_manual_update_check' ) );

		// Add "Check for Updates" link to plugin listing
		add_filter( 'plugin_action_links_' . $this->plugin_basename, array( $this, 'add_update_check_link' ) );
	}

	/**
	 * Add "Check for Updates" link to plugin action links.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_update_check_link( array $links ): array {
		$check_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=hr_check_updates' ),
			'hr_check_updates_nonce'
		);
		$links['check_updates'] = '<a href="' . esc_url( $check_url ) . '" style="color:#0f766e;font-weight:600;">' . esc_html__( 'Check for Updates', 'hr-nomination-form' ) . '</a>';
		return $links;
	}

	/**
	 * Handle manual update check request from wp-admin.
	 */
	public function handle_manual_update_check(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'hr-nomination-form' ) );
		}

		check_admin_referer( 'hr_check_updates_nonce' );

		// Clear cached GitHub release transient
		$repo      = $this->get_github_repo();
		$cache_key = 'hr_gh_rel_' . md5( $repo );
		delete_transient( $cache_key );

		// Force fresh WordPress update transient refresh
		delete_site_transient( 'update_plugins' );
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		// Query release directly
		$release = $this->get_latest_github_release( true );

		$status = 'no_update';
		if ( $release && ! empty( $release['tag_name'] ) ) {
			$remote_version = ltrim( $release['tag_name'], 'v' );
			if ( version_compare( $remote_version, $this->version, '>' ) ) {
				$status = 'update_available';
			}
		}

		$referer = wp_get_referer();
		if ( ! $referer ) {
			$referer = admin_url( 'admin.php?page=hr-nomination-settings' );
		}

		wp_safe_redirect( add_query_arg( array( 'hr_update_check' => $status ), $referer ) );
		exit;
	}

	/**
	 * Check GitHub Releases API for plugin updates.
	 *
	 * @param object $transient Site update plugins transient.
	 * @return object
	 */
	public function check_for_plugin_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_github_release();
		if ( ! $release || empty( $release['tag_name'] ) ) {
			return $transient;
		}

		$remote_version = ltrim( $release['tag_name'], 'v' );

		if ( version_compare( $remote_version, $this->version, '>' ) ) {
			$download_url = $this->get_release_download_url( $release );

			$item = (object) array(
				'id'            => 'hr-nomination-form/' . $this->plugin_basename,
				'slug'          => $this->slug,
				'plugin'        => $this->plugin_basename,
				'new_version'   => $remote_version,
				'url'           => $release['html_url'] ?? 'https://github.com/' . $this->get_github_repo(),
				'package'       => $download_url,
				'requires'      => '6.0',
				'requires_php'  => '8.0',
				'tested'        => get_bloginfo( 'version' ),
				'icons'         => array(),
				'banners'       => array(),
			);

			$transient->response[ $this->plugin_basename ] = $item;
			unset( $transient->no_update[ $this->plugin_basename ] );
		} else {
			$item = (object) array(
				'id'            => 'hr-nomination-form/' . $this->plugin_basename,
				'slug'          => $this->slug,
				'plugin'        => $this->plugin_basename,
				'new_version'   => $this->version,
				'url'           => 'https://github.com/' . $this->get_github_repo(),
				'package'       => '',
				'requires'      => '6.0',
				'requires_php'  => '8.0',
			);
			$transient->no_update[ $this->plugin_basename ] = $item;
		}

		return $transient;
	}

	/**
	 * Display plugin release details in the WordPress "View version details" modal.
	 *
	 * @param false|object|array $result Result object.
	 * @param string             $action Action name.
	 * @param object             $args Query args.
	 * @return object|false
	 */
	public function filter_plugin_information( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_latest_github_release();
		if ( ! $release ) {
			return $result;
		}

		$remote_version = ltrim( $release['tag_name'] ?? $this->version, 'v' );
		$changelog_md   = $release['body'] ?? 'Regular bug fixes and updates.';
		$changelog_html = wpautop( esc_html( $changelog_md ) );

		$info = (object) array(
			'name'          => __( 'HR Nomination Form', 'hr-nomination-form' ),
			'slug'          => $this->slug,
			'version'       => $remote_version,
			'author'        => '<a href="https://github.com/' . esc_attr( $this->get_github_repo() ) . '">Hunter28-lucky</a>',
			'author_profile'=> 'https://github.com/' . esc_attr( $this->get_github_repo() ),
			'homepage'      => $release['html_url'] ?? 'https://github.com/' . $this->get_github_repo(),
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'tested'        => get_bloginfo( 'version' ),
			'download_link' => $this->get_release_download_url( $release ),
			'last_updated'  => $release['published_at'] ?? current_time( 'mysql' ),
			'sections'      => array(
				'description' => __( 'Handles HR nomination form submissions, email notifications with CSV attachments, database logging with CSV & Excel exports, and auto-updates from GitHub.', 'hr-nomination-form' ),
				'changelog'   => $changelog_html,
			),
		);

		return $info;
	}

	/**
	 * Rename unzipped release folder to hr-nomination-form so WordPress matches the plugin directory.
	 *
	 * @param bool  $response Install response.
	 * @param array $hook_extra Extra hook information.
	 * @param array $result Result array.
	 * @return array
	 */
	public function post_install_rename_folder( $response, array $hook_extra, array $result ): array {
		global $wp_filesystem;

		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $result;
		}

		$proper_destination  = WP_PLUGIN_DIR . '/' . $this->slug;
		$current_destination = untrailingslashit( $result['destination'] );

		if ( $current_destination !== $proper_destination ) {
			$wp_filesystem->move( $current_destination, $proper_destination );
			$result['destination'] = $proper_destination;
		}

		return $result;
	}

	/**
	 * Authenticate downloads for private GitHub repositories.
	 *
	 * @param array  $args Request arguments.
	 * @param string $url Target URL.
	 * @return array
	 */
	public function authenticate_github_requests( array $args, string $url ): array {
		$token = $this->get_github_token();
		if ( empty( $token ) ) {
			return $args;
		}

		$repo = $this->get_github_repo();
		if ( false !== strpos( $url, 'api.github.com' ) || ( ! empty( $repo ) && false !== strpos( $url, $repo ) ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
			$args['headers']['Accept']        = 'application/vnd.github.v3+json, application/octet-stream';
		}

		return $args;
	}

	/**
	 * Fetch latest release from GitHub API with 6-hour transient caching.
	 *
	 * @param bool $force_fresh Bypass cache if true.
	 * @return array|null
	 */
	public function get_latest_github_release( bool $force_fresh = false ): ?array {
		$repo = $this->get_github_repo();
		if ( empty( $repo ) ) {
			return null;
		}

		$cache_key = 'hr_gh_rel_' . md5( $repo );

		if ( ! $force_fresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		$api_url = sprintf( 'https://api.github.com/repos/%s/releases/latest', trim( $repo, '/' ) );
		$args    = array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			),
		);

		$token = $this->get_github_token();
		if ( ! empty( $token ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get( $api_url, $args );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Cache failure for 10 minutes to prevent API hammer
			set_transient( $cache_key, array(), 10 * MINUTE_IN_SECONDS );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return null;
		}

		// Cache successful response for 6 hours
		set_transient( $cache_key, $body, self::CACHE_HOURS * HOUR_IN_SECONDS );

		return $body;
	}

	/**
	 * Find downloadable package URL from release assets or fallback to zipball.
	 *
	 * @param array $release GitHub release payload.
	 * @return string
	 */
	private function get_release_download_url( array $release ): string {
		// Look for attached zip asset first (e.g. hr-nomination-form.zip)
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				$name = strtolower( $asset['name'] ?? '' );
				if ( false !== strpos( $name, '.zip' ) ) {
					return $asset['browser_download_url'];
				}
			}
		}

		// Fallback to release zipball URL
		return $release['zipball_url'] ?? '';
	}

	/**
	 * Get configured GitHub repository slug.
	 *
	 * @return string
	 */
	public function get_github_repo(): string {
		$repo = trim( (string) get_option( 'hr_github_repo', self::DEFAULT_REPO ) );
		return ! empty( $repo ) ? $repo : self::DEFAULT_REPO;
	}

	/**
	 * Get configured GitHub Personal Access Token.
	 *
	 * @return string
	 */
	public function get_github_token(): string {
		return trim( (string) get_option( 'hr_github_token', '' ) );
	}
}
