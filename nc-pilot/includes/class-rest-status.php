<?php
/**
 * REST: GET /ncpilot/v1/status
 *
 * The connector hits this on startup. We use it both as a health/handshake
 * check and to record "last seen" so the settings page can show a live
 * connection badge.
 *
 * @package WP_NC_Pilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCPilot_REST_Status {

	/**
	 * Wire up REST route registration.
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the /status route.
	 */
	public function register_routes() {
		register_rest_route(
			NCPILOT_REST_NS,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => array( 'NCPilot_Security', 'can_ping' ),
			)
		);
	}

	/**
	 * Return health + capability snapshot, and stamp "last seen".
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_status( WP_REST_Request $request ) {
		// Record the contact so the badge flips to "Connected".
		NCPilot_Security::record_seen();

		$user = wp_get_current_user();

		return new WP_REST_Response(
			array(
				'ok'           => true,
				'version'      => NCPILOT_VERSION,
				'site'         => get_bloginfo( 'name' ),
				'user'         => $user ? $user->user_login : null,
				'capabilities' => NCPilot_Security::capabilities_snapshot(),
			),
			200
		);
	}
}
