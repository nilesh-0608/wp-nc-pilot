<?php
/**
 * Admin settings page — the friendly heart of the plugin.
 *
 * Responsibilities:
 *  - Top-level "NC-Pilot" admin menu.
 *  - "Connect to Claude" button that mints an Application Password.
 *  - Pre-filled, copy-paste setup commands for Claude Code AND Claude Desktop.
 *  - Live connection status badge.
 *  - Capability toggles (all OFF by default), each with a plain-language warning.
 *
 * @package WP_NC_Pilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCPilot_Settings_Page {

	const MENU_SLUG       = 'nc-pilot';
	const APP_PASS_NAME   = 'NC-Pilot MCP';
	const NONCE_CONNECT   = 'ncpilot_connect';
	const NONCE_TOGGLES   = 'ncpilot_toggles';
	const TRANSIENT_PREFIX = 'ncpilot_newpass_';

	/**
	 * Hook into admin.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_ncpilot_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_ncpilot_save_toggles', array( $this, 'handle_save_toggles' ) );
	}

	/**
	 * Register the top-level menu.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'NC-Pilot', 'nc-pilot' ),
			__( 'NC-Pilot', 'nc-pilot' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-superhero',
			81
		);
	}

	/* ---------------------------------------------------------------------
	 * Form handlers (admin-post.php targets)
	 * ------------------------------------------------------------------- */

	/**
	 * Handle the "Connect to Claude" button: create an Application Password.
	 */
	public function handle_connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nc-pilot' ) );
		}
		check_admin_referer( self::NONCE_CONNECT );

		$user_id = get_current_user_id();

		// Some hosts disable Application Passwords (e.g. non-HTTPS, or a filter).
		if ( ! function_exists( 'wp_is_application_passwords_available' ) || ! wp_is_application_passwords_available() ) {
			$this->redirect_with_error(
				__( 'Application Passwords are turned off on this site. They usually require HTTPS. Ask your host to enable them, then try again.', 'nc-pilot' )
			);
			return;
		}

		$created = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => self::APP_PASS_NAME )
		);

		if ( is_wp_error( $created ) ) {
			$this->redirect_with_error( $created->get_error_message() );
			return;
		}

		// $created = array( $plaintext_password, $item ). Show the plaintext ONCE.
		$plaintext = isset( $created[0] ) ? $created[0] : '';

		// Short-lived: the page is loaded immediately after this redirect, which
		// pops and deletes it. Keep the database exposure window small.
		set_transient(
			self::TRANSIENT_PREFIX . $user_id,
			$plaintext,
			2 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( add_query_arg( 'ncpilot_connected', '1', $this->page_url() ) );
		exit;
	}

	/**
	 * Save the capability toggles.
	 */
	public function handle_save_toggles() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nc-pilot' ) );
		}
		check_admin_referer( self::NONCE_TOGGLES );

		$keys = array_keys( NCPilot_Security::toggle_defaults() );
		foreach ( $keys as $key ) {
			// Checkbox present in POST => '1', absent => ''.
			$value = isset( $_POST[ $key ] ) ? '1' : '';
			update_option( $key, $value );
		}

		wp_safe_redirect( add_query_arg( 'ncpilot_saved', '1', $this->page_url() ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------- */

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user        = wp_get_current_user();
		$new_pass    = $this->pop_new_password( $user->ID );
		// Display-only flags set on our own admin-post redirects; no state change
		// here, so a nonce is not required to read them.
		$has_error   = isset( $_GET['ncpilot_error'] ) ? sanitize_text_field( wp_unslash( $_GET['ncpilot_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$just_saved  = isset( $_GET['ncpilot_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_connected = NCPilot_Security::is_connected();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'NC-Pilot', 'nc-pilot' ) . '</h1>';
		echo '<p>' . esc_html__( 'Let Claude help you manage this website. Set it up once below, then talk to Claude to create posts, edit pages, and more.', 'nc-pilot' ) . '</p>';

		if ( $has_error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $has_error ) . '</p></div>';
		}
		if ( $just_saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'nc-pilot' ) . '</p></div>';
		}

		$this->render_status_badge( $is_connected );
		$this->render_connect_section( $user, $new_pass );
		$this->render_toggles_section();

		echo '</div>';
	}

	/**
	 * Connection status badge.
	 *
	 * @param bool $is_connected Whether the connector pinged recently.
	 */
	private function render_status_badge( $is_connected ) {
		$last = NCPilot_Security::last_seen();
		echo '<h2>' . esc_html__( 'Connection status', 'nc-pilot' ) . '</h2>';

		if ( $is_connected ) {
			echo '<p><span style="display:inline-block;padding:4px 12px;border-radius:12px;background:#d6f5d6;color:#0a6b0a;font-weight:600;">'
				. esc_html__( 'Connected ✓', 'nc-pilot' ) . '</span> ';
			echo esc_html__( 'Claude reached your site recently.', 'nc-pilot' ) . '</p>';
		} else {
			echo '<p><span style="display:inline-block;padding:4px 12px;border-radius:12px;background:#e2e2e2;color:#555;font-weight:600;">'
				. esc_html__( 'Not connected yet', 'nc-pilot' ) . '</span> ';
			if ( $last > 0 ) {
				/* translators: %s: human-readable time difference. */
				echo esc_html( sprintf( __( 'Last seen %s ago.', 'nc-pilot' ), human_time_diff( $last ) ) );
			} else {
				echo esc_html__( 'Finish the setup below, then run the command in Claude.', 'nc-pilot' );
			}
			echo '</p>';
		}
	}

	/**
	 * "Connect to Claude" section: button before, commands after.
	 *
	 * @param WP_User     $user     Current user.
	 * @param string|null $new_pass Freshly minted plaintext password, or null.
	 */
	private function render_connect_section( $user, $new_pass ) {
		echo '<hr><h2>' . esc_html__( '1. Connect to Claude', 'nc-pilot' ) . '</h2>';

		if ( null === $new_pass ) {
			echo '<p>' . esc_html__( 'Click the button to create a secure password for Claude. You will see a ready-to-paste command next.', 'nc-pilot' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="ncpilot_connect">';
			wp_nonce_field( self::NONCE_CONNECT );
			submit_button( __( 'Connect to Claude', 'nc-pilot' ), 'primary large' );
			echo '</form>';
			return;
		}

		// We just generated a password — show it once, plus pre-filled commands.
		$username = $user->user_login;
		$mcp_url  = rest_url( NCPILOT_REST_NS . '/mcp' );
		// HTTP Basic token. WordPress strips spaces from application passwords
		// when authenticating, so we send the compact (space-free) form.
		$token    = base64_encode( $username . ':' . str_replace( ' ', '', (string) $new_pass ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		echo '<div class="notice notice-warning inline"><p><strong>'
			. esc_html__( 'Copy this now — it is shown only once.', 'nc-pilot' )
			. '</strong> ' . esc_html__( 'For your security, WordPress will not show this password again. If you lose it, just click Connect again to make a new one.', 'nc-pilot' )
			. '</p></div>';

		// --- Claude Code command ---
		echo '<h3>' . esc_html__( 'For Claude Code (terminal app)', 'nc-pilot' ) . '</h3>';
		echo '<p>' . esc_html__( 'Copy this whole command and paste it into Claude Code:', 'nc-pilot' ) . '</p>';
		$cc_command = $this->build_cc_command( $mcp_url, $token );
		$this->render_copy_box( 'ncpilot-cc', $cc_command );

		// --- Claude Desktop JSON ---
		echo '<h3>' . esc_html__( 'For Claude Desktop app', 'nc-pilot' ) . '</h3>';
		echo '<p>' . esc_html__( 'Prefer the Claude Desktop app? Open Settings → Developer → Edit Config, and add this inside "mcpServers":', 'nc-pilot' ) . '</p>';
		$desktop_json = $this->build_desktop_json( $mcp_url, $token );
		$this->render_copy_box( 'ncpilot-desktop', $desktop_json );

		echo '<p>' . esc_html__( 'After you run it, come back here — the status above turns green once Claude connects.', 'nc-pilot' ) . '</p>';
	}

	/**
	 * Capability toggles section.
	 */
	private function render_toggles_section() {
		echo '<hr><h2>' . esc_html__( '2. What is Claude allowed to do?', 'nc-pilot' ) . '</h2>';
		echo '<p>' . esc_html__( 'Everything risky starts switched OFF. Turn on only what you need. You can change these any time.', 'nc-pilot' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="ncpilot_save_toggles">';
		wp_nonce_field( self::NONCE_TOGGLES );

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->render_toggle_row(
			NCPilot_Security::OPT_READ_THEME,
			__( 'Let Claude read theme files', 'nc-pilot' ),
			__( 'Low risk. Claude can look at your theme\'s code to understand your site.', 'nc-pilot' )
		);
		$this->render_toggle_row(
			NCPilot_Security::OPT_EDIT_THEME,
			__( 'Let Claude EDIT theme files', 'nc-pilot' ),
			__( '⚠️ Advanced. Claude can change your theme\'s code, which can break how your site looks or works. A backup of each file is saved automatically before any change.', 'nc-pilot' )
		);
		$this->render_toggle_row(
			NCPilot_Security::OPT_PLUGINS,
			__( 'Let Claude turn plugins on or off', 'nc-pilot' ),
			__( 'Claude can activate or deactivate plugins you already installed. It cannot install new ones.', 'nc-pilot' )
		);
		$this->render_toggle_row(
			NCPilot_Security::OPT_DELETE,
			__( 'Let Claude delete posts, pages, or media', 'nc-pilot' ),
			__( '⚠️ Permanent. Deleted items go to the Trash where possible, but treat this as permanent.', 'nc-pilot' )
		);
		$this->render_toggle_row(
			NCPilot_Security::OPT_UPLOAD,
			__( 'Let Claude upload media', 'nc-pilot' ),
			__( 'Claude can add images and files to your Media Library, either from a web link or by uploading a file from your computer. Only file types WordPress already allows are accepted.', 'nc-pilot' )
		);

		echo '</tbody></table>';
		submit_button( __( 'Save permissions', 'nc-pilot' ) );
		echo '</form>';
	}

	/**
	 * One toggle row.
	 *
	 * @param string $option_key Option key.
	 * @param string $label      Plain-language label.
	 * @param string $help       Plain-language help text.
	 */
	private function render_toggle_row( $option_key, $label, $help ) {
		$checked = NCPilot_Security::is_enabled( $option_key );
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( $option_key ) . '" value="1" ' . checked( $checked, true, false ) . '> ';
		echo esc_html__( 'Enabled', 'nc-pilot' ) . '</label>';
		echo '<p class="description">' . esc_html( $help ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * A read-only textarea with a Copy button (no external JS needed).
	 *
	 * @param string $id      Unique DOM id.
	 * @param string $content Text to display/copy.
	 */
	private function render_copy_box( $id, $content ) {
		$rows = max( 3, substr_count( $content, "\n" ) + 1 );
		echo '<textarea id="' . esc_attr( $id ) . '" readonly rows="' . esc_attr( $rows ) . '" '
			. 'style="width:100%;max-width:760px;font-family:monospace;" '
			. 'onclick="this.select();">' . esc_textarea( $content ) . '</textarea>';
		echo '<p><button type="button" class="button" '
			. 'onclick="(function(){var t=document.getElementById(\'' . esc_js( $id ) . '\');t.select();document.execCommand(\'copy\');})();">'
			. esc_html__( 'Copy', 'nc-pilot' ) . '</button></p>';
	}

	/* ---------------------------------------------------------------------
	 * Command builders
	 * ------------------------------------------------------------------- */

	/**
	 * Build the `claude mcp add` command for Claude Code (remote HTTP MCP).
	 *
	 * @param string $mcp_url The /mcp endpoint URL.
	 * @param string $token   Base64 HTTP Basic token (user:app_pass).
	 * @return string
	 */
	private function build_cc_command( $mcp_url, $token ) {
		// Shell-escape every value: the user pastes this into a terminal, so a
		// URL or token must not be able to alter the command.
		return sprintf(
			"claude mcp add --transport http nc-pilot \\\n"
			. "  %s \\\n"
			. "  --header %s",
			$this->shell_arg( $mcp_url ),
			$this->shell_arg( 'Authorization: Basic ' . $token )
		);
	}

	/**
	 * Single-quote a value for safe pasting into a POSIX shell.
	 *
	 * We avoid escapeshellarg() because it is locale/OS dependent (and uses
	 * double quotes on Windows); the command is always for a POSIX shell.
	 *
	 * @param string $value Raw value.
	 * @return string Single-quoted, escaped value.
	 */
	private function shell_arg( $value ) {
		return "'" . str_replace( "'", "'\\''", (string) $value ) . "'";
	}

	/**
	 * Build the Claude Desktop JSON snippet (the "wp-nc-pilot" entry that goes
	 * inside the user's "mcpServers" object) for a remote HTTP MCP server.
	 *
	 * @param string $mcp_url The /mcp endpoint URL.
	 * @param string $token   Base64 HTTP Basic token (user:app_pass).
	 * @return string
	 */
	private function build_desktop_json( $mcp_url, $token ) {
		$config = array(
			'mcpServers' => array(
				'nc-pilot' => array(
					'type'    => 'http',
					'url'     => $mcp_url,
					'headers' => array(
						'Authorization' => 'Basic ' . $token,
					),
				),
			),
		);
		return wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/* ---------------------------------------------------------------------
	 * Small helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Pull the one-time plaintext password out of its transient (and delete it).
	 *
	 * @param int $user_id User id.
	 * @return string|null
	 */
	private function pop_new_password( $user_id ) {
		$key  = self::TRANSIENT_PREFIX . $user_id;
		$pass = get_transient( $key );
		if ( false === $pass ) {
			return null;
		}
		delete_transient( $key );
		return $pass;
	}

	/**
	 * Redirect back to the page carrying an error message.
	 *
	 * @param string $message Human-readable error.
	 */
	private function redirect_with_error( $message ) {
		wp_safe_redirect(
			add_query_arg(
				'ncpilot_error',
				rawurlencode( $message ),
				$this->page_url()
			)
		);
		exit;
	}

	/**
	 * URL of this settings page.
	 *
	 * @return string
	 */
	private function page_url() {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}
}
