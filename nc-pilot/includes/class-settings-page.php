<?php
/**
 * Admin settings page — the friendly heart of the plugin.
 *
 * Responsibilities:
 *  - Top-level "NC-Pilot" admin menu.
 *  - Guide the user to create an Application Password themselves on their
 *    own profile screen (core UI). The plugin never creates, receives, or
 *    stores that password.
 *  - Build copy-paste setup commands for Claude Code AND Claude Desktop
 *    entirely in the browser (assets/js/admin.js).
 *  - Live connection status badge.
 *  - Capability toggles (all OFF by default), each with a plain-language warning.
 *
 * @package WP_NC_Pilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCPilot_Settings_Page {

	const MENU_SLUG     = 'nc-pilot';
	const APP_PASS_NAME = 'NC-Pilot';
	const NONCE_TOGGLES = 'ncpilot_toggles';
	const SCRIPT_HANDLE = 'ncpilot-admin';

	/**
	 * Admin page hook suffix, so assets load only on our page.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Hook into admin.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_ncpilot_save_toggles', array( $this, 'handle_save_toggles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the top-level menu.
	 */
	public function add_menu() {
		$this->page_hook = (string) add_menu_page(
			__( 'NC-Pilot', 'nc-pilot' ),
			__( 'NC-Pilot', 'nc-pilot' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-superhero',
			81
		);
	}

	/**
	 * Load the setup script (builds the commands in the browser) on our page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( '' === $this->page_hook || $hook_suffix !== $this->page_hook ) {
			return;
		}
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			NCPILOT_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			NCPILOT_VERSION,
			true
		);
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'ncpilotSetup',
			array(
				'mcpUrl'        => rest_url( NCPILOT_REST_NS . '/mcp' ),
				'missingFields' => __( 'Enter your username and the Application Password first.', 'nc-pilot' ),
				'copied'        => __( 'Copied!', 'nc-pilot' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Form handlers (admin-post.php targets)
	 * ------------------------------------------------------------------- */

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

		$user = wp_get_current_user();
		// Display-only flag set on our own admin-post redirect; no state change
		// here, so a nonce is not required to read it.
		$just_saved   = isset( $_GET['ncpilot_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_connected = NCPilot_Security::is_connected();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'NC-Pilot', 'nc-pilot' ) . '</h1>';
		echo '<p>' . esc_html__( 'Let Claude help you manage this website. Set it up once below, then talk to Claude to create posts, edit pages, and more.', 'nc-pilot' ) . '</p>';

		if ( $just_saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'nc-pilot' ) . '</p></div>';
		}

		$this->render_status_badge( $is_connected );
		$this->render_connect_section( $user );
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
	 * "Connect to Claude" section.
	 *
	 * The user creates an Application Password on the core profile screen and
	 * pastes it here. admin.js assembles the commands in the browser; this form
	 * has no action and is never submitted to the server.
	 *
	 * @param WP_User $user Current user.
	 */
	private function render_connect_section( $user ) {
		echo '<hr><h2>' . esc_html__( '1. Connect to Claude', 'nc-pilot' ) . '</h2>';

		if ( ! function_exists( 'wp_is_application_passwords_available' ) || ! wp_is_application_passwords_available() ) {
			echo '<div class="notice notice-error inline"><p>'
				. esc_html__( 'Application Passwords are turned off on this site. They usually require HTTPS. Ask your host to enable them, then come back here.', 'nc-pilot' )
				. '</p></div>';
			return;
		}

		$this->render_app_password_steps();
		$this->render_command_builder( $user );
	}

	/**
	 * Step A: how to create an Application Password with core's own screen.
	 */
	private function render_app_password_steps() {
		$profile_url = admin_url( 'profile.php#application-passwords-section' );

		echo '<h3>' . esc_html__( 'Step A — Create an Application Password', 'nc-pilot' ) . '</h3>';
		echo '<ol>';
		echo '<li><a href="' . esc_url( $profile_url ) . '" target="_blank" rel="noopener">'
			. esc_html__( 'Open your profile → Application Passwords', 'nc-pilot' ) . '</a></li>';
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: suggested application password name. */
				__( 'Type a name such as "%s" and click "Add New Application Password".', 'nc-pilot' ),
				self::APP_PASS_NAME
			)
		) . '</li>';
		echo '<li>' . esc_html__( 'Copy the password WordPress shows you (it is shown only once).', 'nc-pilot' ) . '</li>';
		echo '</ol>';
	}

	/**
	 * Step B: paste the password, get ready-to-paste commands (built client-side).
	 *
	 * @param WP_User $user Current user.
	 */
	private function render_command_builder( $user ) {
		echo '<h3>' . esc_html__( 'Step B — Build your setup command', 'nc-pilot' ) . '</h3>';
		echo '<p>' . esc_html__( 'Paste the password below. It stays in your browser: NC-Pilot never sends it anywhere or saves it.', 'nc-pilot' ) . '</p>';

		echo '<form id="ncpilot-setup-form" autocomplete="off"><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="ncpilot-username">' . esc_html__( 'Username', 'nc-pilot' ) . '</label></th>';
		echo '<td><input type="text" id="ncpilot-username" class="regular-text" value="' . esc_attr( $user->user_login ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="ncpilot-app-password">' . esc_html__( 'Application Password', 'nc-pilot' ) . '</label></th>';
		echo '<td><input type="password" id="ncpilot-app-password" class="regular-text" autocomplete="new-password" spellcheck="false"></td></tr>';
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary button-large">' . esc_html__( 'Show my setup commands', 'nc-pilot' ) . '</button></p>';
		echo '<div id="ncpilot-setup-error" class="notice notice-error inline" hidden><p></p></div>';
		echo '</form>';

		echo '<div id="ncpilot-setup-output" hidden>';
		echo '<div class="notice notice-warning inline"><p>'
			. esc_html__( 'These commands contain your password. Do not share them. If one leaks, revoke that Application Password on your profile screen.', 'nc-pilot' )
			. '</p></div>';

		echo '<h3>' . esc_html__( 'For Claude Code (terminal app)', 'nc-pilot' ) . '</h3>';
		echo '<p>' . esc_html__( 'Copy this whole command and paste it into Claude Code:', 'nc-pilot' ) . '</p>';
		$this->render_copy_box( 'ncpilot-cc', 4 );

		echo '<h3>' . esc_html__( 'For Claude Desktop app', 'nc-pilot' ) . '</h3>';
		echo '<p>' . esc_html__( 'Prefer the Claude Desktop app? Open Settings → Developer → Edit Config, and add this inside "mcpServers":', 'nc-pilot' ) . '</p>';
		$this->render_copy_box( 'ncpilot-desktop', 12 );

		echo '<p>' . esc_html__( 'After you run it, come back here — the status above turns green once Claude connects.', 'nc-pilot' ) . '</p>';
		echo '</div>';
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
			NCPilot_Security::OPT_DELETE,
			__( 'Let Claude delete posts, pages, or media', 'nc-pilot' ),
			__( '⚠️ Permanent. Deleted items go to the Trash where possible, but treat this as permanent. Also allows removing Elementor components and their children.', 'nc-pilot' )
		);
		$this->render_toggle_row(
			NCPilot_Security::OPT_UPLOAD,
			__( 'Let Claude upload media', 'nc-pilot' ),
			__( 'Claude can add images and files to your Media Library, either from a web link or by uploading a file from your computer. Only file types WordPress already allows are accepted.', 'nc-pilot' )
		);
		$this->render_toggle_row(
			NCPilot_Security::OPT_INSTALL,
			__( 'Let Claude install plugins', 'nc-pilot' ),
			__( '⚠️ Advanced. Claude can search the official WordPress.org directory and install plugins from it. Nothing is downloaded from anywhere else. Installed plugins always stay inactive — only you can activate them, from the Plugins screen.', 'nc-pilot' )
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
	 * An empty read-only textarea (filled by admin.js) with a Copy button.
	 *
	 * @param string $id   Unique DOM id.
	 * @param int    $rows Visible rows.
	 */
	private function render_copy_box( $id, $rows ) {
		echo '<textarea id="' . esc_attr( $id ) . '" readonly rows="' . esc_attr( (string) $rows ) . '" '
			. 'style="width:100%;max-width:760px;font-family:monospace;"></textarea>';
		echo '<p><button type="button" class="button ncpilot-copy" data-target="' . esc_attr( $id ) . '">'
			. esc_html__( 'Copy', 'nc-pilot' ) . '</button></p>';
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
