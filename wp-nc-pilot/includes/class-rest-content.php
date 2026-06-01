<?php
/**
 * REST: POST /ncpilot/v1/delete
 *
 * Delete a post, page, or media item. Core's /wp/v2 endpoints can delete too,
 * but they can't honour our "delete content" toggle — so we wrap deletion here
 * and enforce the toggle (plus the per-item capability) server-side.
 *
 * Posts and pages go to the Trash unless force=true. Media items are removed
 * (WordPress does not trash attachments unless MEDIA_TRASH is enabled).
 *
 * @package WP_NC_Pilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCPilot_REST_Content {

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			NCPILOT_REST_NS,
			'/delete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_delete' ),
				'args'                => array(
					'type'  => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'post', 'page', 'media' ),
					),
					'id'    => array(
						'required' => true,
						'type'     => 'integer',
					),
					'force' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);
	}

	/**
	 * Gate: the "delete content" toggle must be on (per-item cap checked later).
	 *
	 * @return bool|WP_Error
	 */
	public function can_delete() {
		if ( ! current_user_can( 'delete_posts' ) ) {
			return new WP_Error(
				'ncpilot_forbidden',
				__( 'Your WordPress user is not allowed to delete content.', 'wp-nc-pilot' ),
				array( 'status' => 403 )
			);
		}
		if ( ! NCPilot_Security::is_enabled( NCPilot_Security::OPT_DELETE ) ) {
			return new WP_Error(
				'ncpilot_toggle_off',
				__( 'Deleting content is switched off. Turn on "Let Claude delete posts, pages, or media" on the WP NC-Pilot settings page.', 'wp-nc-pilot' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Delete the requested item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$type  = (string) $request['type'];
		$id    = (int) $request['id'];
		$force = (bool) $request['force'];

		$post = get_post( $id );
		if ( ! $post ) {
			return new WP_Error(
				'ncpilot_not_found',
				__( 'No item with that ID exists.', 'wp-nc-pilot' ),
				array( 'status' => 404 )
			);
		}

		// Make sure the ID actually matches the claimed type.
		$expected = ( 'media' === $type ) ? 'attachment' : $type;
		if ( $post->post_type !== $expected ) {
			return new WP_Error(
				'ncpilot_type_mismatch',
				/* translators: 1: requested type, 2: actual post type. */
				sprintf( __( 'Item #%1$d is not a %2$s.', 'wp-nc-pilot' ), $id, $type ),
				array( 'status' => 400 )
			);
		}

		// Per-item capability check.
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error(
				'ncpilot_forbidden',
				__( 'Your WordPress user is not allowed to delete this item.', 'wp-nc-pilot' ),
				array( 'status' => 403 )
			);
		}

		if ( 'media' === $type ) {
			$result = wp_delete_attachment( $id, $force );
			$trashed = false;
		} elseif ( $force ) {
			$result  = wp_delete_post( $id, true );
			$trashed = false;
		} else {
			$result  = wp_trash_post( $id );
			$trashed = true;
		}

		if ( ! $result ) {
			return new WP_Error(
				'ncpilot_delete_failed',
				__( 'The item could not be deleted.', 'wp-nc-pilot' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'id'      => $id,
				'type'    => $type,
				'trashed' => $trashed,
				'message' => $trashed
					? __( 'Moved to Trash.', 'wp-nc-pilot' )
					: __( 'Deleted permanently.', 'wp-nc-pilot' ),
			),
			200
		);
	}
}
