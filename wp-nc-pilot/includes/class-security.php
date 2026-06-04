<?php
/**
 * Security helpers: capability + toggle gates, option storage, and (later phases)
 * theme-path confinement and file backups.
 *
 * @package WP_NC_Pilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central security / settings helper.
 *
 * Capability toggles are stored as individual options so each endpoint can be
 * gated independently. Everything dangerous defaults to OFF.
 */
class NCPilot_Security {

	/**
	 * Option keys for the capability toggles shown on the settings page.
	 * Value is '1' (on) or '' / absent (off).
	 */
	const OPT_READ_THEME  = 'ncpilot_cap_read_theme';
	const OPT_EDIT_THEME  = 'ncpilot_cap_edit_theme';
	const OPT_PLUGINS     = 'ncpilot_cap_plugins';
	const OPT_DELETE      = 'ncpilot_cap_delete';
	const OPT_UPLOAD      = 'ncpilot_cap_upload';

	/** Timestamp (unix) of the connector's last successful /status ping. */
	const OPT_LAST_SEEN   = 'ncpilot_last_seen';

	/**
	 * All toggle option keys, with their default value on install.
	 * Every capability ships OFF.
	 *
	 * @return array<string,string>
	 */
	public static function toggle_defaults() {
		return array(
			self::OPT_READ_THEME => '',
			self::OPT_EDIT_THEME => '',
			self::OPT_PLUGINS    => '',
			self::OPT_DELETE     => '',
			self::OPT_UPLOAD     => '',
		);
	}

	/**
	 * Seed default options on activation without clobbering existing choices.
	 */
	public static function set_default_options() {
		foreach ( self::toggle_defaults() as $key => $default ) {
			if ( false === get_option( $key, false ) ) {
				add_option( $key, $default );
			}
		}
	}

	/**
	 * Is a given capability toggle enabled?
	 *
	 * @param string $option_key One of the OPT_* constants.
	 * @return bool
	 */
	public static function is_enabled( $option_key ) {
		return '1' === (string) get_option( $option_key, '' );
	}

	/**
	 * Snapshot of all toggles, for the /status endpoint and the settings UI.
	 *
	 * @return array<string,bool>
	 */
	public static function capabilities_snapshot() {
		return array(
			'read_theme_files'     => self::is_enabled( self::OPT_READ_THEME ),
			'edit_theme_files'     => self::is_enabled( self::OPT_EDIT_THEME ),
			'manage_plugins'       => self::is_enabled( self::OPT_PLUGINS ),
			'delete_content'       => self::is_enabled( self::OPT_DELETE ),
			'upload_media'         => self::is_enabled( self::OPT_UPLOAD ),
		);
	}

	/**
	 * Record that the connector just contacted us (drives the status badge).
	 */
	public static function record_seen() {
		update_option( self::OPT_LAST_SEEN, time() );
	}

	/**
	 * Unix timestamp of last connector contact, or 0 if never.
	 *
	 * @return int
	 */
	public static function last_seen() {
		return (int) get_option( self::OPT_LAST_SEEN, 0 );
	}

	/**
	 * Has the connector pinged us within the "connected" window?
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$seen = self::last_seen();
		return $seen > 0 && ( time() - $seen ) <= NCPILOT_CONNECTED_WINDOW;
	}

	/**
	 * Permission callback for /status. The connector authenticates as an admin
	 * via its Application Password, so we require manage_options here — this also
	 * stops lower-privileged users from reading the capability configuration.
	 *
	 * @return bool
	 */
	public static function can_ping() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission callback for the MCP endpoint. Kept separate from can_ping so
	 * the MCP server's auth requirement is explicit and cannot be loosened by a
	 * change to the status-ping gate. The MCP server can drive every capability,
	 * so it requires full admin (manage_options); each individual tool is still
	 * gated by its own route's permission check at dispatch time.
	 *
	 * @return bool
	 */
	public static function can_use_mcp() {
		return current_user_can( 'manage_options' );
	}

	/* ---------------------------------------------------------------------
	 * Theme file safety (Phase 4)
	 * ------------------------------------------------------------------- */

	/**
	 * Confine a caller-supplied relative path to the active theme directory.
	 *
	 * Resolves against get_stylesheet_directory() (the active/child theme) and
	 * uses realpath to defeat traversal (../, symlinks, etc.). Works for files
	 * that do not exist yet by validating the parent directory instead.
	 *
	 * @param string $relative Path relative to the theme root.
	 * @return string|false Normalised absolute path inside the theme, or false.
	 */
	public static function safe_theme_path( $relative ) {
		if ( ! is_string( $relative ) || '' === $relative ) {
			return false;
		}
		// Reject null bytes outright.
		if ( false !== strpos( $relative, "\0" ) ) {
			return false;
		}

		$base_real = realpath( get_stylesheet_directory() );
		if ( false === $base_real ) {
			return false;
		}
		$base = wp_normalize_path( $base_real );

		$relative = ltrim( wp_normalize_path( $relative ), '/' );
		$target   = wp_normalize_path( $base . '/' . $relative );

		$real = realpath( $target );
		if ( false !== $real ) {
			$real = wp_normalize_path( $real );
			return self::is_inside( $real, $base ) ? $real : false;
		}

		// File does not exist yet — validate the parent directory.
		$parent = realpath( dirname( $target ) );
		if ( false === $parent ) {
			return false;
		}
		$parent = wp_normalize_path( $parent );
		if ( ! self::is_inside( $parent, $base ) ) {
			return false;
		}
		return $parent . '/' . basename( $target );
	}

	/**
	 * Is $path the base dir itself or strictly inside it?
	 *
	 * @param string $path Normalised absolute path.
	 * @param string $base Normalised absolute base dir.
	 * @return bool
	 */
	private static function is_inside( $path, $base ) {
		return $path === $base || 0 === strpos( $path, $base . '/' );
	}

	/**
	 * Suffix used for backup copies. Lets the lister hide them.
	 */
	const BACKUP_MARKER = '.ncpilot-bak-';

	/**
	 * Initialise WP_Filesystem, returning the global handle or a WP_Error with
	 * a plain-language message when the host blocks direct file writes.
	 *
	 * @return WP_Filesystem_Base|WP_Error
	 */
	public static function init_filesystem() {
		global $wp_filesystem;
		require_once ABSPATH . 'wp-admin/includes/file.php';

		// Ask for credentials without rendering a form. On hosts that allow
		// 'direct' access this returns true; otherwise it returns false and we
		// can't proceed without FTP details we don't have.
		ob_start();
		$creds = request_filesystem_credentials( '', '', false, false, null );
		ob_end_clean();

		if ( false === $creds || ! WP_Filesystem( $creds ) ) {
			return new WP_Error(
				'ncpilot_fs_unavailable',
				__( 'This host does not allow editing files directly from WordPress (it needs FTP details or has file editing disabled). Theme file changes are not possible here.', 'wp-nc-pilot' ),
				array( 'status' => 409 )
			);
		}
		return $wp_filesystem;
	}

	/**
	 * Back up a file before it is overwritten or deleted.
	 *
	 * Backups are stored in a protected folder under uploads (NOT next to the
	 * theme file) so old versions of theme code — which may contain secrets —
	 * are not downloadable from a guessable public URL.
	 *
	 * @param string              $absolute      Confined absolute path.
	 * @param WP_Filesystem_Base  $wp_filesystem Initialised filesystem.
	 * @return true|WP_Error
	 */
	public static function backup_file( $absolute, $wp_filesystem ) {
		// Nothing to back up for a brand-new file.
		if ( ! $wp_filesystem->exists( $absolute ) ) {
			return true;
		}

		$dir = self::backup_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		// Flatten the theme-relative path so the backup name is unique-ish and
		// readable, e.g. "inc_setup.php.ncpilot-bak-1717000000".
		$base = wp_normalize_path( realpath( get_stylesheet_directory() ) );
		$rel  = ltrim( str_replace( $base, '', wp_normalize_path( $absolute ) ), '/' );
		$flat = str_replace( '/', '_', $rel );

		$backup = trailingslashit( $dir ) . $flat . self::BACKUP_MARKER . time();

		if ( ! $wp_filesystem->copy( $absolute, $backup, true, FS_CHMOD_FILE ) ) {
			return new WP_Error(
				'ncpilot_backup_failed',
				__( 'Could not create a backup of the file before changing it, so the change was stopped.', 'wp-nc-pilot' ),
				array( 'status' => 500 )
			);
		}
		return true;
	}

	/**
	 * Ensure the protected backup directory exists, and return its path.
	 *
	 * Guards it with a .htaccess deny rule and an index.php so the contents
	 * cannot be browsed or downloaded (Apache). On nginx, hosts should ensure
	 * dotfiles/uploads execution rules apply; these are inert backup copies.
	 *
	 * @return string|WP_Error Absolute directory path, or error.
	 */
	private static function backup_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'ncpilot_backup_dir', $uploads['error'], array( 'status' => 500 ) );
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'wp-nc-pilot-backups';

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'ncpilot_backup_dir',
				__( 'Could not create the backup folder.', 'wp-nc-pilot' ),
				array( 'status' => 500 )
			);
		}

		// Drop guard files once.
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $dir;
	}

	/**
	 * Permission: can read theme files (edit_themes cap + read toggle).
	 *
	 * @return bool|WP_Error
	 */
	public static function can_read_theme_files() {
		return self::theme_gate( self::OPT_READ_THEME, __( 'Reading theme files is switched off. Turn on "Allow Claude to read theme files" on the WP NC-Pilot settings page.', 'wp-nc-pilot' ) );
	}

	/**
	 * Permission: can edit theme files (edit_themes cap + edit toggle).
	 *
	 * @return bool|WP_Error
	 */
	public static function can_edit_theme_files() {
		return self::theme_gate( self::OPT_EDIT_THEME, __( 'Editing theme files is switched off. Turn on "Allow Claude to edit theme files" on the WP NC-Pilot settings page.', 'wp-nc-pilot' ) );
	}

	/**
	 * Shared cap + toggle gate for theme endpoints.
	 *
	 * @param string $toggle_key  OPT_* constant for the required toggle.
	 * @param string $toggle_msg  Message shown when the toggle is off.
	 * @return bool|WP_Error
	 */
	private static function theme_gate( $toggle_key, $toggle_msg ) {
		if ( ! current_user_can( 'edit_themes' ) ) {
			return new WP_Error(
				'ncpilot_forbidden',
				__( 'Your WordPress user is not allowed to work with theme files.', 'wp-nc-pilot' ),
				array( 'status' => 403 )
			);
		}
		if ( ! self::is_enabled( $toggle_key ) ) {
			return new WP_Error( 'ncpilot_toggle_off', $toggle_msg, array( 'status' => 403 ) );
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Media uploads (Phase 6)
	 * ------------------------------------------------------------------- */

	/**
	 * Permission: can upload media (upload_files cap + the upload toggle).
	 *
	 * @return bool|WP_Error
	 */
	public static function can_upload_media() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'ncpilot_forbidden',
				__( 'Your WordPress user is not allowed to upload files.', 'wp-nc-pilot' ),
				array( 'status' => 403 )
			);
		}
		if ( ! self::is_enabled( self::OPT_UPLOAD ) ) {
			return new WP_Error(
				'ncpilot_toggle_off',
				__( 'Uploading media is switched off. Turn on "Let Claude upload media" on the WP NC-Pilot settings page.', 'wp-nc-pilot' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}
}
