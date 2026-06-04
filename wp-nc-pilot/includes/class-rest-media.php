<?php
/**
 * REST: POST /ncpilot/v1/media — upload a file to the Media Library.
 *
 * Two ways to supply the file, both fully server-side (no local connector):
 *   - url:    a public URL the server downloads (SSRF-guarded via wp_safe_remote_get).
 *   - base64: raw file bytes, base64-encoded, that Claude read from the user's
 *             computer and sent inline.
 *
 * Gated by the "upload media" toggle AND the upload_files capability. The
 * filename's type must be in WordPress's allowed-mime list, so executable/script
 * files (e.g. .php) are rejected — an uploaded PHP file would be remote code
 * execution.
 *
 * @package WP_NC_Pilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCPilot_REST_Media {

	/** Largest upload accepted through this endpoint (10 MB). */
	const MAX_BYTES = 10485760;

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			NCPILOT_REST_NS,
			'/media',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( 'NCPilot_Security', 'can_upload_media' ),
				'args'                => array(
					'url'      => array(
						'required' => false,
						'type'     => 'string',
					),
					'base64'   => array(
						'required' => false,
						'type'     => 'string',
					),
					'filename' => array(
						'required' => false,
						'type'     => 'string',
					),
					'title'    => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Upload from a URL or inline base64 and create an attachment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$url      = (string) $request['url'];
		$base64   = (string) $request['base64'];
		$filename = (string) $request['filename'];
		$title    = (string) $request['title'];

		// Resolve the bytes + a filename from whichever source was given.
		if ( '' !== $base64 ) {
			// Reject oversized payloads BEFORE decoding, so a huge string can't
			// blow up memory only to be rejected afterwards. base64 expands ~4:3,
			// so the encoded form of a 10 MB file is at most ~13.98 MB.
			if ( strlen( $base64 ) > (int) ceil( self::MAX_BYTES / 3 ) * 4 ) {
				return new WP_Error(
					'ncpilot_too_large',
					__( 'That file is too large to upload here (over 10 MB).', 'wp-nc-pilot' ),
					array( 'status' => 413 )
				);
			}
			$bytes = $this->decode_base64( $base64 );
			if ( is_wp_error( $bytes ) ) {
				return $bytes;
			}
			if ( '' === $filename ) {
				return new WP_Error(
					'ncpilot_no_filename',
					__( 'When uploading file contents directly, a filename (with extension) is required.', 'wp-nc-pilot' ),
					array( 'status' => 400 )
				);
			}
		} elseif ( '' !== $url ) {
			$fetched = $this->fetch_url( $url );
			if ( is_wp_error( $fetched ) ) {
				return $fetched;
			}
			$bytes = $fetched['bytes'];
			if ( '' === $filename ) {
				$filename = $fetched['filename'];
			}
		} else {
			return new WP_Error(
				'ncpilot_no_source',
				__( 'Provide either a public "url" to download, or "base64" file contents with a "filename".', 'wp-nc-pilot' ),
				array( 'status' => 400 )
			);
		}

		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			return new WP_Error(
				'ncpilot_too_large',
				__( 'That file is too large to upload here (over 10 MB).', 'wp-nc-pilot' ),
				array( 'status' => 413 )
			);
		}

		// Validate type. This is the gate that stops PHP/script uploads (RCE).
		$filename = sanitize_file_name( $filename );
		$validated = $this->validate_type( $bytes, $filename );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		return $this->store_attachment( $bytes, $filename, $title );
	}

	/**
	 * Verify an upload's type by BOTH an explicit executable blocklist and the
	 * real file contents (via wp_check_filetype_and_ext, which uses fileinfo to
	 * cross-check the bytes against the extension). Name-based checking alone is
	 * bypassable with double extensions like "shell.php.jpg".
	 *
	 * @param string $bytes    Raw file bytes.
	 * @param string $filename Sanitised filename.
	 * @return true|WP_Error
	 */
	private function validate_type( $bytes, $filename ) {
		$bad_type = new WP_Error(
			'ncpilot_bad_type',
			__( 'That file type is not allowed to be uploaded to WordPress.', 'wp-nc-pilot' ),
			array( 'status' => 415 )
		);

		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		// Defence in depth: hard-block anything executable/scriptable, even if a
		// host's mime map or a missing fileinfo extension would otherwise allow it.
		$blocked = array(
			'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phtml', 'phps',
			'phar', 'shtml', 'cgi', 'pl', 'py', 'sh', 'bash', 'asp', 'aspx', 'jsp',
			'js', 'mjs', 'htaccess', 'htm', 'html', 'svg', 'exe', 'bat', 'cmd', 'com', 'dll',
		);
		if ( '' === $ext || in_array( $ext, $blocked, true ) ) {
			return $bad_type;
		}

		// Cross-check the real bytes against the extension. Write to a temp file
		// so fileinfo can sniff the content.
		$tmp = wp_tempnam( $filename );
		if ( $tmp ) {
			file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file for type sniffing.
			$check = wp_check_filetype_and_ext( $tmp, $filename );
			unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- our own temp file.
		} else {
			$check = wp_check_filetype( $filename );
		}

		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return $bad_type;
		}
		// If fileinfo decided the real content needs a different name/extension,
		// the declared extension lied about the bytes — reject.
		if ( ! empty( $check['proper_filename'] ) ) {
			return $bad_type;
		}

		return true;
	}

	/**
	 * Strictly decode a base64 payload (tolerating a data: URI prefix).
	 *
	 * @param string $input Base64 string.
	 * @return string|WP_Error Raw bytes, or error.
	 */
	private function decode_base64( $input ) {
		// Allow a "data:<mime>;base64,XXXX" prefix.
		if ( 0 === strpos( $input, 'data:' ) ) {
			$comma = strpos( $input, ',' );
			if ( false !== $comma ) {
				$input = substr( $input, $comma + 1 );
			}
		}
		$input = trim( $input );
		$bytes = base64_decode( $input, true );
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error(
				'ncpilot_bad_base64',
				__( 'The file contents were not valid base64.', 'wp-nc-pilot' ),
				array( 'status' => 400 )
			);
		}
		return $bytes;
	}

	/**
	 * Download a file from a public URL with SSRF protection.
	 *
	 * @param string $url URL to fetch.
	 * @return array|WP_Error { bytes, filename } or error.
	 */
	private function fetch_url( $url ) {
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $url ) {
			return new WP_Error(
				'ncpilot_bad_url',
				__( 'That does not look like a valid http(s) URL.', 'wp-nc-pilot' ),
				array( 'status' => 400 )
			);
		}

		// wp_safe_remote_get blocks the INITIAL host if it is private/loopback,
		// but it does not re-validate redirect targets. Disable redirects so an
		// attacker-controlled public URL cannot 302 us to an internal address
		// (e.g. 169.254.169.254 metadata), and force the external-host check on.
		add_filter( 'http_request_host_is_external', '__return_false', 999 );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
			)
		);
		remove_filter( 'http_request_host_is_external', '__return_false', 999 );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'ncpilot_fetch_failed',
				/* translators: %s: underlying error message. */
				sprintf( __( 'Could not download the file: %s', 'wp-nc-pilot' ), $response->get_error_message() ),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'ncpilot_fetch_failed',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'Could not download the file (HTTP %d).', 'wp-nc-pilot' ), $code ),
				array( 'status' => 502 )
			);
		}

		$bytes = wp_remote_retrieve_body( $response );
		if ( '' === $bytes ) {
			return new WP_Error(
				'ncpilot_fetch_empty',
				__( 'The downloaded file was empty.', 'wp-nc-pilot' ),
				array( 'status' => 502 )
			);
		}

		$name = basename( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		if ( '' === $name || false === strpos( $name, '.' ) ) {
			$name = 'upload';
		}

		return array(
			'bytes'    => $bytes,
			'filename' => $name,
		);
	}

	/**
	 * Write the bytes into the uploads dir and create an attachment post.
	 *
	 * @param string $bytes    Raw file bytes.
	 * @param string $filename Sanitised filename.
	 * @param string $title    Optional title.
	 * @return WP_REST_Response|WP_Error
	 */
	private function store_attachment( $bytes, $filename, $title ) {
		// Place the file into the uploads directory (unique name).
		$upload = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error(
				'ncpilot_upload_failed',
				/* translators: %s: underlying error message. */
				sprintf( __( 'The file could not be saved: %s', 'wp-nc-pilot' ), $upload['error'] ),
				array( 'status' => 500 )
			);
		}

		$file = $upload['file'];
		$type = wp_check_filetype( $file );

		$attachment = array(
			'post_mime_type' => $type['type'],
			'post_title'     => '' !== $title ? sanitize_text_field( $title ) : preg_replace( '/\.[^.]+$/', '', basename( $file ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $file, 0, true );
		if ( is_wp_error( $attach_id ) ) {
			// Clean up the orphaned file.
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return $attach_id;
		}

		// Generate thumbnails / metadata.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attach_id, $file );
		wp_update_attachment_metadata( $attach_id, $metadata );

		return new WP_REST_Response(
			array(
				'ok'         => true,
				'id'         => $attach_id,
				'source_url' => wp_get_attachment_url( $attach_id ),
				'title'      => get_the_title( $attach_id ),
			),
			200
		);
	}
}
