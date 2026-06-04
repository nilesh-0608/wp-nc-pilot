<?php
/**
 * REST: POST /ncpilot/v1/mcp — native remote MCP server.
 *
 * This makes the plugin itself a Model Context Protocol server over HTTP
 * ("Streamable HTTP" transport), so Claude Code / Claude Desktop can connect
 * directly to the site. No local connector, no Node, no npm.
 *
 * The endpoint speaks JSON-RPC 2.0 and implements the minimal tool-serving
 * contract: initialize, notifications/initialized, ping, tools/list, tools/call.
 * Responses are plain application/json (no SSE, no session id) — every tool is
 * a simple request/response, so streaming is unnecessary.
 *
 * tools/call never re-implements business logic: it builds a WP_REST_Request
 * and dispatches it INTERNALLY via rest_do_request() to the same /wp/v2/* and
 * /ncpilot/v1/* routes the old connector used. All capability toggles and
 * per-item permission checks therefore still apply, unchanged.
 *
 * Auth: the caller authenticates with a WordPress Application Password (HTTP
 * Basic), exactly as before. The route requires manage_options.
 *
 * @package WP_NC_Pilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCPilot_REST_MCP {

	/** MCP protocol version we advertise when the client does not request one. */
	const DEFAULT_PROTOCOL = '2025-06-18';

	/** JSON-RPC error codes we use. */
	const ERR_PARSE           = -32700;
	const ERR_INVALID_REQUEST = -32600;
	const ERR_METHOD          = -32601;

	/**
	 * Wire up REST route registration.
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the /mcp route (POST only — JSON-RPC over HTTP).
	 */
	public function register_routes() {
		register_rest_route(
			NCPILOT_REST_NS,
			'/mcp',
			array(
				// POST carries the JSON-RPC messages.
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => array( 'NCPilot_Security', 'can_use_mcp' ),
				),
				// MCP clients open a GET to start a server→client SSE stream, and
				// may DELETE to end a session. This server is stateless and has no
				// SSE stream, so per the MCP spec we answer 405 (NOT 404, which a
				// strict client treats as "endpoint missing" and fails the
				// connection on).
				array(
					'methods'             => 'GET, DELETE',
					'callback'            => array( $this, 'method_not_allowed' ),
					'permission_callback' => array( 'NCPilot_Security', 'can_use_mcp' ),
				),
			)
		);
	}

	/**
	 * Respond to GET/DELETE on the MCP endpoint with 405 + an Allow header,
	 * signalling that only POST (request/response) is supported here.
	 *
	 * @return WP_REST_Response
	 */
	public function method_not_allowed() {
		$response = new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => null,
				'error'   => array(
					'code'    => self::ERR_INVALID_REQUEST,
					'message' => 'Method Not Allowed. This MCP endpoint only supports POST.',
				),
			),
			405
		);
		$response->header( 'Allow', 'POST' );
		return $response;
	}

	/* ---------------------------------------------------------------------
	 * JSON-RPC envelope
	 * ------------------------------------------------------------------- */

	/**
	 * Entry point: parse the JSON-RPC body and dispatch one or many messages.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		$raw  = $request->get_body();
		$data = json_decode( $raw, true );

		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_REST_Response( $this->rpc_error( null, self::ERR_PARSE, 'Parse error.' ), 200 );
		}

		// Batch (a JSON array of messages).
		if ( is_array( $data ) && $this->is_list( $data ) ) {
			if ( count( $data ) > 50 ) {
				return new WP_REST_Response( $this->rpc_error( null, self::ERR_INVALID_REQUEST, 'Batch too large (max 50 messages).' ), 200 );
			}
			$out = array();
			foreach ( $data as $msg ) {
				$resp = $this->handle_message( is_array( $msg ) ? $msg : array() );
				if ( null !== $resp ) {
					$out[] = $resp;
				}
			}
			if ( empty( $out ) ) {
				return new WP_REST_Response( null, 202 ); // Only notifications.
			}
			return new WP_REST_Response( $out, 200 );
		}

		$resp = $this->handle_message( is_array( $data ) ? $data : array() );
		if ( null === $resp ) {
			return new WP_REST_Response( null, 202 ); // Notification: no body.
		}
		return new WP_REST_Response( $resp, 200 );
	}

	/**
	 * Handle a single JSON-RPC message.
	 *
	 * @param array $msg Decoded message.
	 * @return array|null JSON-RPC response, or null for notifications.
	 */
	private function handle_message( array $msg ) {
		$method          = isset( $msg['method'] ) ? (string) $msg['method'] : '';
		$params          = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();
		$has_id          = array_key_exists( 'id', $msg );
		$id              = $has_id ? $msg['id'] : null;
		$is_notification = ! $has_id;

		switch ( $method ) {
			case 'initialize':
				// initialize is a request, never a notification.
				if ( $is_notification ) {
					return null;
				}
				// Mark "connected" so the settings-page badge turns green.
				NCPilot_Security::record_seen();
				// Negotiate the protocol: echo the client's version if we support
				// it, otherwise answer with our default. Clamp the input length.
				$requested = isset( $params['protocolVersion'] ) ? substr( (string) $params['protocolVersion'], 0, 32 ) : '';
				$supported = array( '2025-06-18', '2025-03-26', '2024-11-05' );
				$protocol  = in_array( $requested, $supported, true ) ? $requested : self::DEFAULT_PROTOCOL;
				return $this->rpc_result(
					$id,
					array(
						'protocolVersion' => $protocol,
						'capabilities'    => array( 'tools' => (object) array() ),
						'serverInfo'      => array(
							'name'    => 'nc-pilot',
							'version' => NCPILOT_VERSION,
						),
					)
				);

			case 'ping':
				return $this->rpc_result( $id, (object) array() );

			case 'tools/list':
				return $this->rpc_result( $id, array( 'tools' => $this->tool_specs() ) );

			case 'tools/call':
				$name = isset( $params['name'] ) ? (string) $params['name'] : '';
				$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
				return $this->call_tool( $id, $name, $args );

			default:
				// Unknown notifications (e.g. notifications/initialized) are ignored.
				if ( $is_notification ) {
					return null;
				}
				return $this->rpc_error( $id, self::ERR_METHOD, 'Method not found: ' . substr( $method, 0, 64 ) );
		}
	}

	/**
	 * Build a JSON-RPC success response.
	 *
	 * @param mixed $id     Request id.
	 * @param mixed $result Result payload.
	 * @return array
	 */
	private function rpc_result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * Build a JSON-RPC error response.
	 *
	 * @param mixed  $id      Request id.
	 * @param int    $code    Error code.
	 * @param string $message Error message.
	 * @return array
	 */
	private function rpc_error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/**
	 * Is $data a sequential list (JSON array) rather than an object?
	 *
	 * @param array $data Decoded data.
	 * @return bool
	 */
	private function is_list( array $data ) {
		if ( empty( $data ) ) {
			return false;
		}
		return array_keys( $data ) === range( 0, count( $data ) - 1 );
	}

	/* ---------------------------------------------------------------------
	 * tools/call dispatch
	 * ------------------------------------------------------------------- */

	/**
	 * Run a tool and wrap the result (or error) as an MCP tool result.
	 *
	 * Tool failures are returned as a normal result with isError=true (per the
	 * MCP spec), NOT as a JSON-RPC protocol error.
	 *
	 * @param mixed  $id   Request id.
	 * @param string $name Tool name.
	 * @param array  $args Tool arguments.
	 * @return array
	 */
	private function call_tool( $id, $name, array $args ) {
		try {
			$text = $this->dispatch_tool( $name, $args );
			return $this->rpc_result(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => $text,
						),
					),
				)
			);
		} catch ( Exception $e ) {
			return $this->rpc_result(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => '⚠️ ' . $e->getMessage(),
						),
					),
					'isError' => true,
				)
			);
		}
	}

	/**
	 * Map a tool name to its handler.
	 *
	 * @param string $name Tool name.
	 * @param array  $args Arguments.
	 * @return string Text to show the user.
	 * @throws Exception On unknown tool or any failure.
	 */
	private function dispatch_tool( $name, array $args ) {
		switch ( $name ) {
			case 'check_connection':
				return $this->tool_check_connection();
			case 'list_posts':
				return $this->tool_list( '/wp/v2/posts', 'posts', $args, true );
			case 'get_post':
				return $this->tool_get_post( $args );
			case 'list_pages':
				return $this->tool_list( '/wp/v2/pages', 'pages', $args, false );
			case 'list_media':
				return $this->tool_list_media( $args );
			case 'list_plugins':
				return $this->tool_list_plugins();
			case 'create_post':
				return $this->tool_create( '/wp/v2/posts', 'post', $args );
			case 'update_post':
				return $this->tool_update( '/wp/v2/posts', 'post', $args, array( 'title', 'content', 'excerpt', 'status' ) );
			case 'create_page':
				return $this->tool_create_page( $args );
			case 'update_page':
				return $this->tool_update( '/wp/v2/pages', 'page', $args, array( 'title', 'content', 'status' ) );
			case 'upload_media':
				return $this->tool_upload_media( $args );
			case 'activate_plugin':
				return $this->tool_plugin( 'activate', $args );
			case 'deactivate_plugin':
				return $this->tool_plugin( 'deactivate', $args );
			case 'list_theme_files':
				return $this->tool_list_theme_files();
			case 'read_theme_file':
				return $this->tool_read_theme_file( $args );
			case 'write_theme_file':
				return $this->tool_write_theme_file( $args );
			case 'delete_theme_file':
				return $this->tool_delete_theme_file( $args );
			case 'delete_post':
				return $this->tool_delete( 'post', 'post', $args );
			case 'delete_page':
				return $this->tool_delete( 'page', 'page', $args );
			case 'delete_media':
				return $this->tool_delete( 'media', 'media item', $args );
			default:
				throw new Exception( esc_html( 'Unknown tool: ' . $name ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Internal REST dispatch
	 * ------------------------------------------------------------------- */

	/**
	 * Dispatch an internal REST request and return its data, or throw on error.
	 *
	 * Runs in the current (authenticated) user context, so every route's own
	 * permission_callback and capability/toggle gates are enforced.
	 *
	 * @param string $method HTTP method (GET, POST, DELETE).
	 * @param string $route  Full route path, e.g. "/wp/v2/posts/12".
	 * @param array  $params Parameters (null values are skipped).
	 * @return mixed Decoded response data.
	 * @throws Exception With the friendly error message on failure.
	 */
	private function rest( $method, $route, array $params = array() ) {
		$req = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			if ( null !== $value ) {
				$req->set_param( $key, $value );
			}
		}

		$res = rest_do_request( $req );
		if ( $res->is_error() ) {
			$err = $res->as_error();
			throw new Exception( esc_html( $err->get_error_message() ) );
		}
		return $res->get_data();
	}

	/* ---------------------------------------------------------------------
	 * Tool handlers
	 * ------------------------------------------------------------------- */

	/**
	 * check_connection: site + enabled capabilities.
	 *
	 * @return string
	 */
	private function tool_check_connection() {
		$s    = $this->rest( 'GET', '/ncpilot/v1/status' );
		$caps = array();
		if ( isset( $s['capabilities'] ) && is_array( $s['capabilities'] ) ) {
			foreach ( $s['capabilities'] as $key => $val ) {
				$caps[] = '  - ' . $key . ': ' . ( $val ? 'ON' : 'off' );
			}
		}
		return sprintf(
			"Connected to \"%s\" as %s.\nPlugin version %s.\nEnabled capabilities:\n%s",
			isset( $s['site'] ) ? $s['site'] : '',
			isset( $s['user'] ) ? $s['user'] : '',
			isset( $s['version'] ) ? $s['version'] : '',
			implode( "\n", $caps )
		);
	}

	/**
	 * Shared list for posts/pages.
	 *
	 * @param string $route       /wp/v2/posts or /wp/v2/pages.
	 * @param string $label       "posts" or "pages".
	 * @param array  $args        Tool args (search, status, per_page).
	 * @param bool   $with_status Whether to forward a status filter.
	 * @return string
	 */
	private function tool_list( $route, $label, array $args, $with_status ) {
		$params = array(
			'per_page' => $this->per_page( $args ),
			'context'  => 'edit',
		);
		if ( isset( $args['search'] ) ) {
			$params['search'] = (string) $args['search'];
		}
		if ( $with_status && isset( $args['status'] ) ) {
			$params['status'] = (string) $args['status'];
		}
		$items = $this->rest( 'GET', $route, $params );
		return $this->format_posts( $items, $label );
	}

	/**
	 * get_post: one post with content.
	 *
	 * @param array $args Args (id).
	 * @return string
	 * @throws Exception When id is missing.
	 */
	private function tool_get_post( array $args ) {
		$id = $this->require_int( $args, 'id' );
		$p  = $this->rest( 'GET', '/wp/v2/posts/' . $id, array( 'context' => 'edit' ) );

		$title   = $this->strip( $this->title_of( $p ) );
		$content = $this->strip( isset( $p['content']['rendered'] ) ? $p['content']['rendered'] : '' );

		return sprintf(
			"#%s — %s  [%s]\n%s\n\n%s",
			isset( $p['id'] ) ? $p['id'] : '?',
			$title,
			isset( $p['status'] ) ? $p['status'] : '',
			isset( $p['link'] ) ? $p['link'] : '',
			function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 4000 ) : substr( $content, 0, 4000 )
		);
	}

	/**
	 * list_media.
	 *
	 * @param array $args Args (search, per_page).
	 * @return string
	 */
	private function tool_list_media( array $args ) {
		$params = array( 'per_page' => $this->per_page( $args ) );
		if ( isset( $args['search'] ) ) {
			$params['search'] = (string) $args['search'];
		}
		$items = $this->rest( 'GET', '/wp/v2/media', $params );
		if ( ! is_array( $items ) || ! count( $items ) ) {
			return 'No media found.';
		}
		$lines = array();
		foreach ( $items as $m ) {
			$title = $this->strip( $this->title_of( $m, '(untitled)' ) );
			$lines[] = '#' . $m['id'] . ' — ' . $title
				. '  [' . ( isset( $m['media_type'] ) ? $m['media_type'] : '' ) . ']  '
				. ( isset( $m['source_url'] ) ? $m['source_url'] : '' );
		}
		return count( $items ) . " media items:\n" . implode( "\n", $lines );
	}

	/**
	 * list_plugins.
	 *
	 * @return string
	 */
	private function tool_list_plugins() {
		$items = $this->rest( 'GET', '/wp/v2/plugins' );
		if ( ! is_array( $items ) || ! count( $items ) ) {
			return 'No plugins found.';
		}
		$lines = array();
		foreach ( $items as $p ) {
			$active  = isset( $p['status'] ) && 'active' === $p['status'];
			$lines[] = ( $active ? '●' : '○' ) . ' '
				. $this->strip( isset( $p['name'] ) ? $p['name'] : '' )
				. ' (' . ( isset( $p['version'] ) ? $p['version'] : '?' ) . ')'
				. '  [' . ( isset( $p['status'] ) ? $p['status'] : '' ) . ']';
		}
		return count( $items ) . " plugins (● active, ○ inactive):\n" . implode( "\n", $lines );
	}

	/**
	 * create_post / create page share most of this; posts use excerpt.
	 *
	 * @param string $route /wp/v2/posts.
	 * @param string $noun  "post".
	 * @param array  $args  Args.
	 * @return string
	 * @throws Exception When title missing.
	 */
	private function tool_create( $route, $noun, array $args ) {
		$body = array(
			'title'   => $this->require_string( $args, 'title' ),
			'content' => isset( $args['content'] ) ? (string) $args['content'] : '',
			'excerpt' => isset( $args['excerpt'] ) ? (string) $args['excerpt'] : '',
			'status'  => isset( $args['status'] ) ? (string) $args['status'] : 'draft',
		);
		$p = $this->rest( 'POST', $route, $body );
		return $this->created_line( 'Created ' . $noun, $p );
	}

	/**
	 * create_page (supports an optional parent; no excerpt).
	 *
	 * @param array $args Args.
	 * @return string
	 * @throws Exception When title missing.
	 */
	private function tool_create_page( array $args ) {
		$body = array(
			'title'   => $this->require_string( $args, 'title' ),
			'content' => isset( $args['content'] ) ? (string) $args['content'] : '',
			'status'  => isset( $args['status'] ) ? (string) $args['status'] : 'draft',
		);
		if ( isset( $args['parent'] ) ) {
			$body['parent'] = (int) $args['parent'];
		}
		$p = $this->rest( 'POST', '/wp/v2/pages', $body );
		return $this->created_line( 'Created page', $p );
	}

	/**
	 * update_post / update_page: only provided fields change.
	 *
	 * @param string $route  /wp/v2/posts or /wp/v2/pages.
	 * @param string $noun   "post" or "page".
	 * @param array  $args   Args.
	 * @param array  $fields Field names this content type accepts.
	 * @return string
	 * @throws Exception When id missing or nothing to update.
	 */
	private function tool_update( $route, $noun, array $args, array $fields ) {
		$id   = $this->require_int( $args, 'id' );
		$body = array();
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				$body[ $field ] = (string) $args[ $field ];
			}
		}
		if ( empty( $body ) ) {
			throw new Exception( esc_html( 'Nothing to update — provide at least one field to change.' ) );
		}
		$p = $this->rest( 'POST', $route . '/' . $id, $body );
		return $this->created_line( 'Updated ' . $noun, $p );
	}

	/**
	 * upload_media: from a public URL or inline base64 file contents.
	 *
	 * @param array $args Args (url | base64+filename, title).
	 * @return string
	 * @throws Exception When neither source is provided (surfaced by the endpoint).
	 */
	private function tool_upload_media( array $args ) {
		$body = array();
		if ( isset( $args['url'] ) ) {
			$body['url'] = (string) $args['url'];
		}
		if ( isset( $args['base64'] ) ) {
			$body['base64'] = (string) $args['base64'];
		}
		if ( isset( $args['filename'] ) ) {
			$body['filename'] = (string) $args['filename'];
		}
		if ( isset( $args['title'] ) ) {
			$body['title'] = (string) $args['title'];
		}
		$m = $this->rest( 'POST', '/ncpilot/v1/media', $body );
		return 'Uploaded media #' . ( isset( $m['id'] ) ? $m['id'] : '?' ) . ".\n"
			. ( isset( $m['source_url'] ) ? $m['source_url'] : '' );
	}

	/**
	 * activate_plugin / deactivate_plugin.
	 *
	 * @param string $action "activate" or "deactivate".
	 * @param array  $args   Args (plugin).
	 * @return string
	 * @throws Exception When plugin missing.
	 */
	private function tool_plugin( $action, array $args ) {
		$plugin = $this->require_string( $args, 'plugin' );
		$r      = $this->rest(
			'POST',
			'/ncpilot/v1/plugin',
			array(
				'plugin' => $plugin,
				'action' => $action,
			)
		);
		$name   = isset( $r['name'] ) ? $r['name'] : $plugin;
		$active = ! empty( $r['active'] ) ? 'true' : 'false';
		if ( 'activate' === $action ) {
			$version = isset( $r['version'] ) ? $r['version'] : '';
			return 'Activated "' . $name . '" (' . $version . '). Active: ' . $active . '.';
		}
		return 'Deactivated "' . $name . '". Active: ' . $active . '.';
	}

	/**
	 * list_theme_files.
	 *
	 * @return string
	 */
	private function tool_list_theme_files() {
		$r = $this->rest( 'GET', '/ncpilot/v1/theme-files' );
		$theme = isset( $r['theme'] ) ? $r['theme'] : '';
		if ( empty( $r['count'] ) || empty( $r['files'] ) || ! is_array( $r['files'] ) ) {
			return 'Theme "' . $theme . '" has no listable files.';
		}
		$lines = array();
		foreach ( $r['files'] as $f ) {
			$lines[] = '  ' . $f['path'] . '  (' . $f['size'] . ' bytes)';
		}
		return 'Theme "' . $theme . '" — ' . $r['count'] . " files:\n" . implode( "\n", $lines );
	}

	/**
	 * read_theme_file.
	 *
	 * @param array $args Args (path).
	 * @return string
	 * @throws Exception When path missing.
	 */
	private function tool_read_theme_file( array $args ) {
		$path = $this->require_string( $args, 'path' );
		$r    = $this->rest( 'GET', '/ncpilot/v1/theme-file', array( 'path' => $path ) );
		return '--- ' . $r['path'] . ' (' . $r['size'] . " bytes) ---\n" . $r['contents'];
	}

	/**
	 * write_theme_file.
	 *
	 * @param array $args Args (path, content).
	 * @return string
	 * @throws Exception When path/content missing.
	 */
	private function tool_write_theme_file( array $args ) {
		$path    = $this->require_string( $args, 'path' );
		$content = $this->require_string( $args, 'content' );
		$r       = $this->rest(
			'POST',
			'/ncpilot/v1/theme-file',
			array(
				'path'    => $path,
				'content' => $content,
			)
		);
		$what = ! empty( $r['created'] ) ? 'Created' : 'Updated';
		$bak  = ! empty( $r['backup_saved'] ) ? ' A backup of the previous version was saved.' : '';
		return $what . ' ' . $r['path'] . ' (' . $r['bytes'] . ' bytes).' . $bak;
	}

	/**
	 * delete_theme_file.
	 *
	 * @param array $args Args (path).
	 * @return string
	 * @throws Exception When path missing.
	 */
	private function tool_delete_theme_file( array $args ) {
		$path = $this->require_string( $args, 'path' );
		$r    = $this->rest( 'DELETE', '/ncpilot/v1/theme-file', array( 'path' => $path ) );
		return 'Deleted ' . $r['deleted'] . '. A backup was saved on the server.';
	}

	/**
	 * delete_post / delete_page / delete_media.
	 *
	 * @param string $type "post" | "page" | "media".
	 * @param string $noun Human noun for the message.
	 * @param array  $args Args (id, force).
	 * @return string
	 * @throws Exception When id missing.
	 */
	private function tool_delete( $type, $noun, array $args ) {
		$id    = $this->require_int( $args, 'id' );
		$force = ! empty( $args['force'] );
		$r     = $this->rest(
			'POST',
			'/ncpilot/v1/delete',
			array(
				'type'  => $type,
				'id'    => $id,
				'force' => $force,
			)
		);
		$message = isset( $r['message'] ) ? $r['message'] : 'Done.';
		return $message . ' (' . $noun . ' #' . ( isset( $r['id'] ) ? $r['id'] : $id ) . ')';
	}

	/* ---------------------------------------------------------------------
	 * Formatting + small helpers
	 * ------------------------------------------------------------------- */

	/**
	 * "Created post #12 [draft].\nhttps://..." style line.
	 *
	 * @param string $verb Leading verb phrase, e.g. "Created post".
	 * @param array  $p    Post/page data.
	 * @return string
	 */
	private function created_line( $verb, array $p ) {
		return $verb . ' #' . ( isset( $p['id'] ) ? $p['id'] : '?' )
			. ' [' . ( isset( $p['status'] ) ? $p['status'] : '' ) . "].\n"
			. ( isset( $p['link'] ) ? $p['link'] : '' );
	}

	/**
	 * Format a list of posts/pages.
	 *
	 * @param mixed  $items Items from /wp/v2.
	 * @param string $label "posts" or "pages".
	 * @return string
	 */
	private function format_posts( $items, $label ) {
		if ( ! is_array( $items ) || ! count( $items ) ) {
			return 'No ' . $label . ' found.';
		}
		$lines = array();
		foreach ( $items as $p ) {
			$lines[] = '#' . ( isset( $p['id'] ) ? $p['id'] : '?' ) . ' — '
				. $this->strip( $this->title_of( $p ) )
				. '  [' . ( isset( $p['status'] ) ? $p['status'] : '' ) . ']  '
				. ( isset( $p['link'] ) ? $p['link'] : '' );
		}
		return count( $items ) . ' ' . $label . ":\n" . implode( "\n", $lines );
	}

	/**
	 * Pull a title (rendered or plain) from a post/page/media item.
	 *
	 * @param array  $p        Item.
	 * @param string $fallback Default when no title.
	 * @return string
	 */
	private function title_of( array $p, $fallback = '(no title)' ) {
		if ( isset( $p['title']['rendered'] ) ) {
			return $p['title']['rendered'];
		}
		if ( isset( $p['title'] ) && is_string( $p['title'] ) ) {
			return $p['title'];
		}
		return $fallback;
	}

	/**
	 * Strip HTML tags and decode entities for tidy plain-text output.
	 *
	 * @param string $s Raw string.
	 * @return string
	 */
	private function strip( $s ) {
		$s = wp_strip_all_tags( (string) $s );
		return trim( html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Clamp per_page to 1..100, defaulting to 20.
	 *
	 * @param array $args Tool args.
	 * @return int
	 */
	private function per_page( array $args ) {
		$n = isset( $args['per_page'] ) ? (int) $args['per_page'] : 20;
		if ( $n < 1 ) {
			$n = 1;
		}
		if ( $n > 100 ) {
			$n = 100;
		}
		return $n;
	}

	/**
	 * Require a non-empty string argument.
	 *
	 * @param array  $args Args.
	 * @param string $key  Key.
	 * @return string
	 * @throws Exception When missing.
	 */
	private function require_string( array $args, $key ) {
		if ( ! isset( $args[ $key ] ) || '' === (string) $args[ $key ] ) {
			throw new Exception( esc_html( 'Missing required value: ' . $key . '.' ) );
		}
		return (string) $args[ $key ];
	}

	/**
	 * Require an integer argument.
	 *
	 * @param array  $args Args.
	 * @param string $key  Key.
	 * @return int
	 * @throws Exception When missing.
	 */
	private function require_int( array $args, $key ) {
		if ( ! isset( $args[ $key ] ) || ! is_numeric( $args[ $key ] ) ) {
			throw new Exception( esc_html( 'Missing required number: ' . $key . '.' ) );
		}
		return (int) $args[ $key ];
	}

	/* ---------------------------------------------------------------------
	 * Tool catalogue (tools/list)
	 * ------------------------------------------------------------------- */

	/**
	 * The MCP tool definitions advertised to the client.
	 *
	 * @return array
	 */
	private function tool_specs() {
		$status_desc = 'Defaults to draft for new items, for safety.';
		$status_prop = $this->prop_string( 'draft | publish | pending | private | future. ' . $status_desc );

		return array(
			$this->spec(
				'check_connection',
				'Check connection to WordPress',
				'Verify the connection to the WordPress site and report which capabilities the site owner has enabled. Use this first if anything seems wrong.',
				array()
			),
			$this->spec(
				'list_posts',
				'List posts',
				'List blog posts on the WordPress site. Optionally filter by a search term or status (publish, draft, pending, future, private).',
				array(
					'search'   => $this->prop_string( 'Words to search for in posts.' ),
					'status'   => $this->prop_string( 'publish | draft | pending | future | private' ),
					'per_page' => $this->prop_int( 'How many to return (default 20, max 100).', 1, 100 ),
				)
			),
			$this->spec(
				'get_post',
				'Get one post',
				'Read a single post by its numeric ID, including its content.',
				array( 'id' => $this->prop_int( 'The post ID.' ) ),
				array( 'id' )
			),
			$this->spec(
				'list_pages',
				'List pages',
				'List the pages on the WordPress site (About, Contact, etc.).',
				array(
					'search'   => $this->prop_string( 'Words to search for in pages.' ),
					'per_page' => $this->prop_int( 'How many to return (default 20, max 100).', 1, 100 ),
				)
			),
			$this->spec(
				'list_media',
				'List media',
				'List items in the WordPress Media Library (images, files).',
				array(
					'search'   => $this->prop_string( 'Words to search for.' ),
					'per_page' => $this->prop_int( 'How many to return (default 20, max 100).', 1, 100 ),
				)
			),
			$this->spec(
				'list_plugins',
				'List plugins',
				'List the plugins installed on the WordPress site and whether each is active.',
				array()
			),
			$this->spec(
				'create_post',
				'Create a post',
				'Create a new blog post. It is saved as a DRAFT unless you set status to "publish". Returns the new post ID and its link.',
				array(
					'title'   => $this->prop_string( 'The post title.' ),
					'content' => $this->prop_string( 'The post body. HTML is allowed.' ),
					'excerpt' => $this->prop_string( 'Optional excerpt.' ),
					'status'  => $status_prop,
				),
				array( 'title' )
			),
			$this->spec(
				'update_post',
				'Update a post',
				'Update an existing post by ID. Only the fields you provide are changed. Set status to "publish" to publish a draft.',
				array(
					'id'      => $this->prop_int( 'The post ID to update.' ),
					'title'   => $this->prop_string( 'New title.' ),
					'content' => $this->prop_string( 'New body. HTML is allowed.' ),
					'excerpt' => $this->prop_string( 'New excerpt.' ),
					'status'  => $status_prop,
				),
				array( 'id' )
			),
			$this->spec(
				'create_page',
				'Create a page',
				'Create a new page (like About or Contact). Saved as a DRAFT unless status is "publish".',
				array(
					'title'   => $this->prop_string( 'The page title.' ),
					'content' => $this->prop_string( 'The page body. HTML is allowed.' ),
					'status'  => $status_prop,
					'parent'  => $this->prop_int( 'ID of a parent page, to nest this under it.' ),
				),
				array( 'title' )
			),
			$this->spec(
				'update_page',
				'Update a page',
				'Update an existing page by ID. Only the fields you provide change.',
				array(
					'id'      => $this->prop_int( 'The page ID to update.' ),
					'title'   => $this->prop_string( 'New title.' ),
					'content' => $this->prop_string( 'New body. HTML is allowed.' ),
					'status'  => $status_prop,
				),
				array( 'id' )
			),
			$this->spec(
				'upload_media',
				'Upload media',
				'Upload a file to the Media Library. Provide EITHER a public "url" the server downloads, OR the file contents as base64 (read the local file yourself and pass it as "base64" with a "filename"). Requires the site owner to enable the "upload media" toggle. Returns the new media ID and its URL.',
				array(
					'url'      => $this->prop_string( 'Public URL of a file to download and upload.' ),
					'base64'   => $this->prop_string( 'File contents, base64-encoded (for a local file). Requires filename.' ),
					'filename' => $this->prop_string( 'Filename with extension, e.g. "photo.jpg". Required with base64.' ),
					'title'    => $this->prop_string( 'Optional media title.' ),
				)
			),
			$this->spec(
				'activate_plugin',
				'Activate a plugin',
				'Turn on an already-installed plugin. Requires the site owner to enable the "manage plugins" toggle. Use the plugin path from list_plugins (e.g. "akismet/akismet").',
				array( 'plugin' => $this->prop_string( 'Plugin path, e.g. "akismet/akismet".' ) ),
				array( 'plugin' )
			),
			$this->spec(
				'deactivate_plugin',
				'Deactivate a plugin',
				'Turn off an active plugin. Requires the "manage plugins" toggle. Use the plugin path from list_plugins.',
				array( 'plugin' => $this->prop_string( 'Plugin path, e.g. "akismet/akismet".' ) ),
				array( 'plugin' )
			),
			$this->spec(
				'list_theme_files',
				'List theme files',
				"List the files in the site's active theme. Requires the site owner to enable the \"read theme files\" toggle.",
				array()
			),
			$this->spec(
				'read_theme_file',
				'Read a theme file',
				'Read one file from the active theme by its path (as shown by list_theme_files). Requires the "read theme files" toggle.',
				array( 'path' => $this->prop_string( 'Theme-relative path, e.g. "style.css" or "inc/setup.php".' ) ),
				array( 'path' )
			),
			$this->spec(
				'write_theme_file',
				'Write a theme file',
				'Overwrite (or create) a file in the active theme. THIS EDITS LIVE SITE CODE and can change how the site looks or works. A timestamped backup of the existing file is made automatically on the server before saving. Requires the "edit theme files" toggle, which is off by default.',
				array(
					'path'    => $this->prop_string( 'Theme-relative path, e.g. "style.css".' ),
					'content' => $this->prop_string( 'The full new contents of the file.' ),
				),
				array( 'path', 'content' )
			),
			$this->spec(
				'delete_theme_file',
				'Delete a theme file',
				'Delete a file from the active theme. A timestamped backup is saved on the server first, so it can be recovered. Requires the "edit theme files" toggle.',
				array( 'path' => $this->prop_string( 'Theme-relative path to delete.' ) ),
				array( 'path' )
			),
			$this->spec(
				'delete_post',
				'Delete a post',
				'Delete a post by ID. Goes to the Trash unless you set force to true. Requires the "delete content" toggle, which is off by default.',
				array(
					'id'    => $this->prop_int( 'The post ID.' ),
					'force' => $this->prop_bool( 'Skip the Trash and delete permanently.' ),
				),
				array( 'id' )
			),
			$this->spec(
				'delete_page',
				'Delete a page',
				'Delete a page by ID. Goes to the Trash unless you set force to true. Requires the "delete content" toggle, which is off by default.',
				array(
					'id'    => $this->prop_int( 'The page ID.' ),
					'force' => $this->prop_bool( 'Skip the Trash and delete permanently.' ),
				),
				array( 'id' )
			),
			$this->spec(
				'delete_media',
				'Delete a media item',
				'Delete a media item by ID. This is permanent — WordPress does not keep media in the Trash. Requires the "delete content" toggle, which is off by default.',
				array(
					'id'    => $this->prop_int( 'The media item ID.' ),
					'force' => $this->prop_bool( 'Delete permanently (media is always permanent).' ),
				),
				array( 'id' )
			),
		);
	}

	/**
	 * Build one tool spec with a JSON-Schema input.
	 *
	 * @param string $name        Tool name.
	 * @param string $title       Human title.
	 * @param string $description Description.
	 * @param array  $properties  Property name => schema.
	 * @param array  $required    Required property names.
	 * @return array
	 */
	private function spec( $name, $title, $description, array $properties, array $required = array() ) {
		$schema = array(
			'type'       => 'object',
			'properties' => empty( $properties ) ? (object) array() : $properties,
		);
		if ( ! empty( $required ) ) {
			$schema['required'] = array_values( $required );
		}
		return array(
			'name'        => $name,
			'title'       => $title,
			'description' => $description,
			'inputSchema' => $schema,
		);
	}

	/**
	 * String property schema.
	 *
	 * @param string $description Description.
	 * @return array
	 */
	private function prop_string( $description ) {
		return array(
			'type'        => 'string',
			'description' => $description,
		);
	}

	/**
	 * Integer property schema with optional min/max.
	 *
	 * @param string   $description Description.
	 * @param int|null $min         Minimum.
	 * @param int|null $max         Maximum.
	 * @return array
	 */
	private function prop_int( $description, $min = null, $max = null ) {
		$p = array(
			'type'        => 'integer',
			'description' => $description,
		);
		if ( null !== $min ) {
			$p['minimum'] = $min;
		}
		if ( null !== $max ) {
			$p['maximum'] = $max;
		}
		return $p;
	}

	/**
	 * Boolean property schema.
	 *
	 * @param string $description Description.
	 * @return array
	 */
	private function prop_bool( $description ) {
		return array(
			'type'        => 'boolean',
			'description' => $description,
		);
	}
}
