<?php
/**
 * REST: POST /ncpilot/v1/plugin
 *
 * Activate or deactivate an already-installed plugin. Core's /wp/v2/plugins
 * endpoint can do this too, but it can't honour our own "manage plugins"
 * capability toggle — so we wrap it here and enforce the toggle server-side.
 *
 * Installing new plugins is intentionally NOT supported (activation only).
 *
 * @package WP_NC_Pilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCPilot_REST_Plugins {

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			NCPILOT_REST_NS,
			'/plugin',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_manage_plugins' ),
				'args'                => array(
					'plugin' => array(
						'required'    => true,
						'type'        => 'string',
						'description' => 'Plugin path, e.g. "akismet/akismet" or "akismet/akismet.php".',
					),
					'action' => array(
						'required'    => true,
						'type'        => 'string',
						'enum'        => array( 'activate', 'deactivate' ),
					),
				),
			)
		);
	}

	/**
	 * Permission: must be able to activate plugins AND have the toggle on.
	 *
	 * @return bool|WP_Error
	 */
	public function can_manage_plugins() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error(
				'ncpilot_forbidden',
				__( 'Your WordPress user is not allowed to manage plugins.', 'nc-pilot' ),
				array( 'status' => 403 )
			);
		}
		if ( ! NCPilot_Security::is_enabled( NCPilot_Security::OPT_PLUGINS ) ) {
			return new WP_Error(
				'ncpilot_toggle_off',
				__( 'Managing plugins is switched off. Turn on "Let Claude turn plugins on or off" on the NC-Pilot settings page.', 'nc-pilot' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Activate or deactivate a plugin.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugin = $this->resolve_plugin_file( (string) $request['plugin'] );
		if ( ! $plugin ) {
			return new WP_Error(
				'ncpilot_plugin_not_found',
				/* translators: %s: plugin identifier supplied by the caller. */
				sprintf( __( 'No installed plugin matches "%s". Use the exact path from the plugin list.', 'nc-pilot' ), (string) $request['plugin'] ),
				array( 'status' => 404 )
			);
		}

		$action = (string) $request['action'];

		if ( 'activate' === $action ) {
			// Single-site activation; silent=false runs activation hooks.
			$result = activate_plugin( $plugin );
			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					'ncpilot_activate_failed',
					/* translators: %s: underlying error message. */
					sprintf( __( 'Could not activate the plugin: %s', 'nc-pilot' ), $result->get_error_message() ),
					array( 'status' => 500 )
				);
			}
		} else {
			deactivate_plugins( array( $plugin ) );
		}

		$data = $this->plugin_state( $plugin );
		$data['ok']     = true;
		$data['action'] = $action;

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Resolve a caller-supplied identifier to a real plugin file key.
	 *
	 * Accepts "akismet/akismet" or "akismet/akismet.php" and validates it
	 * against the installed plugin list.
	 *
	 * @param string $input Raw identifier.
	 * @return string|false Validated plugin file, or false.
	 */
	private function resolve_plugin_file( $input ) {
		$input = ltrim( wp_normalize_path( $input ), '/' );
		$all   = array_keys( get_plugins() );

		if ( in_array( $input, $all, true ) ) {
			return $input;
		}
		$with_php = $input . '.php';
		if ( in_array( $with_php, $all, true ) ) {
			return $with_php;
		}
		return false;
	}

	/**
	 * Current name/version/active state for a plugin.
	 *
	 * @param string $plugin Plugin file key.
	 * @return array
	 */
	private function plugin_state( $plugin ) {
		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
		return array(
			'plugin'  => $plugin,
			'name'    => isset( $data['Name'] ) ? $data['Name'] : $plugin,
			'version' => isset( $data['Version'] ) ? $data['Version'] : '',
			'active'  => is_plugin_active( $plugin ),
		);
	}
}
