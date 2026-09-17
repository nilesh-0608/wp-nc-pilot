<?php
/**
 * REST: GET  /ncpilot/v1/plugin-search   — search the WordPress.org directory.
 *       POST /ncpilot/v1/plugin-install  — install a plugin from WordPress.org.
 *
 * Both are gated by the "install plugins" toggle. Installs use core's own
 * Plugin_Upgrader and only ever download from WordPress.org (the same source
 * wp-admin > Plugins > Add New uses). Arbitrary URLs / zips are NOT accepted.
 *
 * @package WP_NC_Pilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCPilot_REST_Plugin_Install {

	/** Max search results returned. */
	const SEARCH_MAX = 20;

	/** Slug rule for WordPress.org plugins. */
	const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,199}$/';

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			NCPILOT_REST_NS,
			'/plugin-search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_search' ),
				'permission_callback' => array( 'NCPilot_Security', 'can_install_plugins' ),
				'args'                => array(
					'q'        => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => self::SEARCH_MAX,
					),
				),
			)
		);

		register_rest_route(
			NCPILOT_REST_NS,
			'/plugin-install',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_install' ),
				'permission_callback' => array( 'NCPilot_Security', 'can_install_plugins' ),
				'args'                => array(
					'slug'     => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => 'WordPress.org plugin slug, e.g. "akismet".',
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => array( $this, 'validate_slug' ),
					),
					'activate' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Slug must look like a WordPress.org slug.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public function validate_slug( $value ) {
		return is_string( $value ) && 1 === preg_match( self::SLUG_PATTERN, $value );
	}

	/**
	 * Search WordPress.org.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_search( WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$api = plugins_api(
			'query_plugins',
			array(
				'search'   => (string) $request['q'],
				'per_page' => (int) $request['per_page'],
				'fields'   => array(
					'short_description' => true,
					'active_installs'   => true,
					'rating'            => true,
					'tested'            => true,
					'versions'          => false,
					'sections'          => false,
					'banners'           => false,
					'icons'             => false,
					'screenshots'       => false,
				),
			)
		);
		if ( is_wp_error( $api ) ) {
			return $this->api_error( $api );
		}

		$installed = array_keys( get_plugins() );
		$items     = array();
		foreach ( (array) $api->plugins as $p ) {
			$p    = (array) $p;
			$slug = isset( $p['slug'] ) ? (string) $p['slug'] : '';
			if ( '' === $slug ) {
				continue;
			}
			$items[] = array(
				'slug'            => $slug,
				'name'            => isset( $p['name'] ) ? wp_strip_all_tags( (string) $p['name'] ) : $slug,
				'version'         => isset( $p['version'] ) ? (string) $p['version'] : '',
				'author'          => isset( $p['author'] ) ? wp_strip_all_tags( (string) $p['author'] ) : '',
				'rating'          => isset( $p['rating'] ) ? (int) $p['rating'] : 0,
				'active_installs' => isset( $p['active_installs'] ) ? (int) $p['active_installs'] : 0,
				'tested'          => isset( $p['tested'] ) ? (string) $p['tested'] : '',
				'description'     => isset( $p['short_description'] ) ? wp_strip_all_tags( (string) $p['short_description'] ) : '',
				'installed'       => (bool) $this->find_installed( $slug, $installed ),
			);
		}

		return new WP_REST_Response(
			array(
				'query' => (string) $request['q'],
				'total' => isset( $api->info['results'] ) ? (int) $api->info['results'] : count( $items ),
				'items' => $items,
			),
			200
		);
	}

	/**
	 * Install (and optionally activate) a WordPress.org plugin.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_install( WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$slug     = (string) $request['slug'];
		$activate = (bool) $request['activate'];

		$existing = $this->find_installed( $slug, array_keys( get_plugins() ) );
		if ( $existing ) {
			return new WP_Error(
				'ncpilot_already_installed',
				/* translators: %s: plugin slug. */
				sprintf( __( 'Plugin "%s" is already installed. Use activate_plugin to turn it on.', 'nc-pilot' ), $slug ),
				array(
					'status' => 409,
					'plugin' => $existing,
				)
			);
		}

		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'sections' => false,
					'versions' => false,
					'banners'  => false,
					'icons'    => false,
				),
			)
		);
		if ( is_wp_error( $api ) ) {
			return $this->api_error( $api, 404 );
		}
		// Defense in depth: plugins_api() already talks only to api.wordpress.org,
		// but we still refuse any package URL that is not on a WordPress.org host.
		// The download itself is then performed by core's Plugin_Upgrader, i.e.
		// the exact same code path and trust boundary as Plugins > Add New.
		if ( empty( $api->download_link ) || ! $this->is_wporg_url( $api->download_link ) ) {
			return new WP_Error(
				'ncpilot_bad_download_source',
				__( 'Refusing to install: the package is not hosted on WordPress.org.', 'nc-pilot' ),
				array( 'status' => 400 )
			);
		}

		// Fail fast with our plain-language message on hosts that need FTP
		// credentials; the upgrader would otherwise surface a cryptic skin error.
		$fs = NCPilot_Security::init_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) ) {
			return $this->install_error( $result->get_error_message() );
		}
		if ( is_wp_error( $skin->result ) ) {
			return $this->install_error( $skin->result->get_error_message() );
		}
		$errors = $skin->get_errors();
		if ( $errors && $errors->has_errors() ) {
			return $this->install_error( $errors->get_error_message() );
		}
		if ( ! $result ) {
			return $this->install_error( __( 'the upgrader returned no result.', 'nc-pilot' ) );
		}

		$plugin_file = $upgrader->plugin_info();
		if ( ! $plugin_file ) {
			return $this->install_error( __( 'installed, but the main plugin file could not be found.', 'nc-pilot' ) );
		}

		$data = array(
			'ok'        => true,
			'slug'      => $slug,
			'plugin'    => $plugin_file,
			'name'      => isset( $api->name ) ? wp_strip_all_tags( (string) $api->name ) : $slug,
			'version'   => isset( $api->version ) ? (string) $api->version : '',
			'active'    => false,
			'activated' => false,
		);

		if ( $activate ) {
			$data = $this->maybe_activate( $plugin_file, $data );
		}

		return new WP_REST_Response( $data, 201 );
	}

	/**
	 * Activate after install, but only if the "manage plugins" toggle is on.
	 *
	 * @param string $plugin_file Plugin file key.
	 * @param array  $data        Response so far.
	 * @return array Updated response (never throws; failure is reported inline).
	 */
	private function maybe_activate( $plugin_file, array $data ) {
		if ( ! NCPilot_Security::is_enabled( NCPilot_Security::OPT_PLUGINS ) || ! current_user_can( 'activate_plugins' ) ) {
			$data['notice'] = __( 'Installed but not activated: the "Let Claude turn plugins on or off" toggle is off.', 'nc-pilot' );
			return $data;
		}
		$r = activate_plugin( $plugin_file );
		if ( is_wp_error( $r ) ) {
			/* translators: %s: underlying error message. */
			$data['notice'] = sprintf( __( 'Installed but activation failed: %s', 'nc-pilot' ), $r->get_error_message() );
			return $data;
		}
		$data['active']    = is_plugin_active( $plugin_file );
		$data['activated'] = true;
		return $data;
	}

	/**
	 * Find an installed plugin whose folder matches the slug.
	 *
	 * @param string   $slug      WordPress.org slug.
	 * @param string[] $installed Keys from get_plugins().
	 * @return string|false Plugin file key, or false.
	 */
	private function find_installed( $slug, array $installed ) {
		foreach ( $installed as $file ) {
			if ( 0 === strpos( $file, $slug . '/' ) || $file === $slug . '.php' ) {
				return $file;
			}
		}
		return false;
	}

	/**
	 * Only allow packages served from *.wordpress.org / *.w.org.
	 *
	 * @param string $url Download URL.
	 * @return bool
	 */
	private function is_wporg_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return false;
		}
		$host = strtolower( $host );
		return 'wordpress.org' === $host
			|| '.wordpress.org' === substr( $host, -14 )
			|| 'w.org' === $host
			|| '.w.org' === substr( $host, -6 );
	}

	/**
	 * Wrap a plugins_api() error.
	 *
	 * @param WP_Error $err    Error.
	 * @param int      $status HTTP status.
	 * @return WP_Error
	 */
	private function api_error( WP_Error $err, $status = 502 ) {
		return new WP_Error(
			'ncpilot_wporg_api_failed',
			/* translators: %s: underlying error message. */
			sprintf( __( 'Could not reach the WordPress.org plugin directory: %s', 'nc-pilot' ), $err->get_error_message() ),
			array( 'status' => $status )
		);
	}

	/**
	 * Wrap an installer failure.
	 *
	 * @param string $reason Reason text.
	 * @return WP_Error
	 */
	private function install_error( $reason ) {
		return new WP_Error(
			'ncpilot_install_failed',
			/* translators: %s: underlying error message. */
			sprintf( __( 'Could not install the plugin: %s', 'nc-pilot' ), $reason ),
			array( 'status' => 500 )
		);
	}
}
