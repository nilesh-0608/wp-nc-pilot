<?php
/**
 * REST: theme file list / read / write.
 *
 *   GET  /ncpilot/v1/theme-files          recursive file list of active theme
 *   GET  /ncpilot/v1/theme-file?path=...  read one file
 *   POST /ncpilot/v1/theme-file           write one file (backup first)
 *
 * Every path is confined to the active theme directory via
 * NCPilot_Security::safe_theme_path(). Writing is effectively remote code
 * execution, so it is gated by BOTH the edit_themes capability AND the
 * "edit theme files" toggle, and every file is backed up before it changes.
 *
 * @package WP_NC_Pilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCPilot_REST_Files {

	/** Largest file we will read or write through the API (1 MB). */
	const MAX_BYTES = 1048576;

	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			NCPILOT_REST_NS,
			'/theme-files',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_files' ),
				'permission_callback' => array( 'NCPilot_Security', 'can_read_theme_files' ),
			)
		);

		register_rest_route(
			NCPILOT_REST_NS,
			'/theme-file',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read_file' ),
					'permission_callback' => array( 'NCPilot_Security', 'can_read_theme_files' ),
					'args'                => array(
						'path' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'write_file' ),
					'permission_callback' => array( 'NCPilot_Security', 'can_edit_theme_files' ),
					'args'                => array(
						'path'    => array(
							'required' => true,
							'type'     => 'string',
						),
						'content' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_file' ),
					'permission_callback' => array( 'NCPilot_Security', 'can_edit_theme_files' ),
					'args'                => array(
						'path' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);
	}

	/**
	 * Recursive list of files in the active theme (backups hidden).
	 *
	 * @return WP_REST_Response
	 */
	public function list_files() {
		$base = realpath( get_stylesheet_directory() );
		$files = array();

		if ( $base && is_dir( $base ) ) {
			$base_norm = wp_normalize_path( $base );
			$iter      = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iter as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				// Skip symlinks and anything that resolves outside the theme, so
				// the listing can never leak the names of files elsewhere on disk.
				if ( $file->isLink() ) {
					continue;
				}
				$real = realpath( $file->getPathname() );
				if ( false === $real ) {
					continue;
				}
				$abs = wp_normalize_path( $real );
				if ( 0 !== strpos( $abs, $base_norm . '/' ) ) {
					continue;
				}
				// Hide our own backup copies.
				if ( false !== strpos( $abs, NCPilot_Security::BACKUP_MARKER ) ) {
					continue;
				}
				$rel = ltrim( substr( $abs, strlen( $base_norm ) ), '/' );
				$files[] = array(
					'path' => $rel,
					'size' => $file->getSize(),
				);
				if ( count( $files ) >= 5000 ) {
					break;
				}
			}
		}

		// Stable, predictable order.
		usort(
			$files,
			static function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			}
		);

		return new WP_REST_Response(
			array(
				'theme' => wp_get_theme()->get( 'Name' ),
				'count' => count( $files ),
				'files' => $files,
			),
			200
		);
	}

	/**
	 * Read one theme file.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function read_file( WP_REST_Request $request ) {
		$abs = NCPilot_Security::safe_theme_path( (string) $request['path'] );
		if ( false === $abs ) {
			return $this->bad_path();
		}
		if ( ! is_file( $abs ) ) {
			return new WP_Error(
				'ncpilot_not_found',
				__( 'That file does not exist in the theme.', 'nc-pilot' ),
				array( 'status' => 404 )
			);
		}
		if ( filesize( $abs ) > self::MAX_BYTES ) {
			return new WP_Error(
				'ncpilot_too_large',
				__( 'That file is too large to open here (over 1 MB).', 'nc-pilot' ),
				array( 'status' => 413 )
			);
		}

		$contents = file_get_contents( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- confined path, read-only.
		if ( false === $contents ) {
			return new WP_Error(
				'ncpilot_read_failed',
				__( 'The file could not be read.', 'nc-pilot' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'path'     => ltrim( wp_normalize_path( (string) $request['path'] ), '/' ),
				'size'     => strlen( $contents ),
				'contents' => $contents,
			),
			200
		);
	}

	/**
	 * Write one theme file (backup first).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function write_file( WP_REST_Request $request ) {
		$abs = NCPilot_Security::safe_theme_path( (string) $request['path'] );
		if ( false === $abs ) {
			return $this->bad_path();
		}

		$content = (string) $request['content'];
		if ( strlen( $content ) > self::MAX_BYTES ) {
			return new WP_Error(
				'ncpilot_too_large',
				__( 'That content is too large to save here (over 1 MB).', 'nc-pilot' ),
				array( 'status' => 413 )
			);
		}

		$fs = NCPilot_Security::init_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs; // Friendly "host blocks file editing" message.
		}

		$backup = NCPilot_Security::backup_file( $abs, $fs );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		$existed = $fs->exists( $abs );
		if ( ! $fs->put_contents( $abs, $content, FS_CHMOD_FILE ) ) {
			return new WP_Error(
				'ncpilot_write_failed',
				__( 'The file could not be saved. The host may be blocking changes to this file.', 'nc-pilot' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'ok'           => true,
				'path'         => ltrim( wp_normalize_path( (string) $request['path'] ), '/' ),
				'bytes'        => strlen( $content ),
				'created'      => ! $existed,
				'backup_saved' => $existed,
			),
			200
		);
	}

	/**
	 * Delete one theme file (backs it up first so it can be recovered).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_file( WP_REST_Request $request ) {
		$abs = NCPilot_Security::safe_theme_path( (string) $request['path'] );
		if ( false === $abs ) {
			return $this->bad_path();
		}
		if ( ! is_file( $abs ) ) {
			return new WP_Error(
				'ncpilot_not_found',
				__( 'That file does not exist in the theme.', 'nc-pilot' ),
				array( 'status' => 404 )
			);
		}

		$fs = NCPilot_Security::init_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		// Keep a backup copy so the delete is recoverable.
		$backup = NCPilot_Security::backup_file( $abs, $fs );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		if ( ! $fs->delete( $abs, false, 'f' ) ) {
			return new WP_Error(
				'ncpilot_delete_failed',
				__( 'The file could not be deleted. The host may be blocking changes to this file.', 'nc-pilot' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'ok'           => true,
				'deleted'      => ltrim( wp_normalize_path( (string) $request['path'] ), '/' ),
				'backup_saved' => true,
			),
			200
		);
	}

	/**
	 * Shared "path escaped the theme" error.
	 *
	 * @return WP_Error
	 */
	private function bad_path() {
		return new WP_Error(
			'ncpilot_bad_path',
			__( 'That path is not allowed. Only files inside the active theme can be opened.', 'nc-pilot' ),
			array( 'status' => 400 )
		);
	}
}
