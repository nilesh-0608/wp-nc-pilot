=== WP NC-Pilot ===
Contributors: wpncpilot
Tags: ai, claude, mcp, automation, content
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage your WordPress site by talking to Claude. One-click setup — no terminal, no config files.

== Description ==

WP NC-Pilot lets you run your website by chatting with Claude. Create posts, edit
pages, manage media, switch plugins on or off, and (optionally) edit theme files —
all from a conversation.

Setup is one click. Press "Connect to Claude", copy the command it gives you, and
paste it into Claude Code or the Claude Desktop app. That's it. No terminal needed
beforehand, no passwords to invent, no long web addresses to assemble.

Everything risky is switched OFF by default. You decide, with plain-language
toggles, exactly what Claude may do.

This plugin works together with the free `wp-nc-pilot-connector` package, which runs
on your own computer and relays your requests to your site securely.

== Security ==

* All operations require login via a WordPress Application Password.
* Risky capabilities (editing theme files, deleting content) are OFF until you turn
  them on.
* Theme file edits are confined to your active theme and every file is backed up
  before it is changed.

== Installation ==

1. In wp-admin, go to Plugins → Add New → Upload Plugin and upload the zip.
2. Activate it.
3. Open "WP NC-Pilot" in the menu and press "Connect to Claude".
4. Copy the command shown and paste it into Claude.

== Changelog ==

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
