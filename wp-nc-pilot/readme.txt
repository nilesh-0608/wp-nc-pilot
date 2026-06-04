=== WP NC-Pilot ===
Contributors: wpncpilot
Tags: ai, claude, mcp, automation, content
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage your WordPress site by talking to Claude. One-click setup — no terminal, no extra software.

== Description ==

WP NC-Pilot lets you run your website by chatting with Claude. Create posts, edit
pages, manage media, switch plugins on or off, and (optionally) edit theme files —
all from a conversation.

This plugin IS the connection. It turns your site into a secure server that Claude
talks to directly over the web (a "remote MCP server"). There is nothing to install
on your computer — no Node, no command-line tools, no helper apps.

Setup is one click. Press "Connect to Claude", copy the command (for Claude Code) or
the small config snippet (for the Claude Desktop app), and paste it in. That's it.

Everything risky is switched OFF by default. You decide, with plain-language
toggles, exactly what Claude may do.

== Security ==

* Claude connects using a WordPress Application Password (standard HTTP Basic auth
  over HTTPS); every request is authenticated as your user.
* Risky capabilities (editing theme files, deleting content, uploading media) are
  OFF until you turn them on.
* Theme file edits are confined to your active theme and every file is backed up
  before it is changed.
* Uploads are restricted to file types WordPress already allows; executable/script
  files are rejected, and the real file contents are checked against the extension.
* Note for nginx hosts: theme-file backups live in
  wp-content/uploads/wp-nc-pilot-backups/. Apache is protected by a bundled
  .htaccess; on nginx, add a rule to deny that folder if your uploads are public.

== Installation ==

1. In wp-admin, go to Plugins → Add New → Upload Plugin and upload the zip.
2. Activate it.
3. Open "WP NC-Pilot" in the menu and press "Connect to Claude".
4. Copy the command shown and paste it into Claude.

== Changelog ==

= 2.0.0 =
* Major: the plugin is now a self-contained remote MCP server. Claude Code and
  Claude Desktop connect directly to the site over HTTPS — the separate
  `wp-nc-pilot-connector` (Node/npm) helper is no longer needed and has been
  retired. The setup command on the settings page now uses
  `claude mcp add --transport http`.
* New: POST /ncpilot/v1/mcp — JSON-RPC 2.0 endpoint implementing initialize,
  tools/list, and tools/call. Tool calls are dispatched internally to the same
  REST routes, so all capability toggles and permission checks are unchanged.
* New: upload media (from a public URL or by uploading a local file), gated by a
  new "Let Claude upload media" toggle (off by default). Uploads are type-checked
  against the file's real contents to block executable/script files.
* Upgrading: after updating, click "Connect to Claude" again to get the new
  one-paste setup command, then re-add the connection in Claude.

= 1.0.1 =
* Security hardening: delete endpoint now also checks the delete capability;
  the setup command is shell-escaped; the status endpoint requires admin; theme
  backups are stored in a protected uploads folder instead of the theme; the
  one-time password is held for a shorter time. Theme file listing now ignores
  symlinks, and uninstall clears leftover temporary data.

= 1.0.0 =
* Phase 5: deleting. Delete theme files (backed up first) and delete posts,
  pages, or media — all gated by the matching capability toggle. Top-level
  documentation and plain-language polish.
* REST: DELETE /ncpilot/v1/theme-file, POST /ncpilot/v1/delete.

= 0.3.0 =
* Phase 4: theme files. Read and write files in the active theme, gated by the
  "read theme files" / "edit theme files" toggles. Paths are confined to the
  active theme (traversal blocked) and every file is backed up before it is
  overwritten. Friendly message when the host blocks direct file editing.
* REST: GET /ncpilot/v1/theme-files, GET+POST /ncpilot/v1/theme-file.

= 0.2.0 =
* Phase 3: content writes via the connector (create/update posts and pages,
  upload media) and plugin activate/deactivate gated by the "manage plugins"
  toggle.
* REST: POST /ncpilot/v1/plugin (toggle-enforced activate/deactivate).

= 0.1.0 =
* Phase 1: one-click setup, Application Password generation, pre-filled Claude Code
  and Claude Desktop commands, live connection status badge, and capability toggles.
* REST: GET /ncpilot/v1/status handshake endpoint.
