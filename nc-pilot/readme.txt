=== NC-Pilot ===
Contributors: nilesh060895
Tags: ai, claude, mcp, automation, content
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage your WordPress site by talking to Claude. Simple setup — no extra software to install.

== Description ==

NC-Pilot lets you run your website by chatting with Claude. Create posts, edit
pages, manage media, install plugins from WordPress.org (you activate them),
and (optionally) edit theme files — all from a conversation.

This plugin IS the connection. It turns your site into a secure server that Claude
talks to directly over the web (a "remote MCP server"). There is nothing to install
on your computer — no Node, no command-line tools, no helper apps.

Setup takes a minute. Create an Application Password on your own profile screen
(a standard WordPress feature), paste it into the NC-Pilot page, and copy the
command (for Claude Code) or the small config snippet (for the Claude Desktop app)
it builds for you. The password stays in your browser; NC-Pilot never creates,
receives, or stores it.

Everything risky is switched OFF by default. You decide, with plain-language
toggles, exactly what Claude may do.

NC-Pilot is an independent project. It is not affiliated with, endorsed by, or
sponsored by Anthropic. "Claude" is a trademark of Anthropic, PBC.

== External Services ==

This plugin does not send your site's data to any third-party service on its
own. It works the other way round: your Claude client (Claude Code or the
Claude Desktop app, running on your machine or Anthropic's servers) connects
*inbound* to your site's REST endpoint using an Application Password you create.
What Claude reads or changes is controlled by the capability toggles on the
settings page.

The plugin makes outbound requests in only two cases, both started by you:

* Uploading media from a public URL: the plugin downloads that one file from
  the URL you supplied.
* Searching for or installing a plugin (when the "install plugins" toggle is
  on): the plugin queries the official WordPress.org plugin directory API
  (api.wordpress.org) and downloads the package from downloads.wordpress.org,
  exactly as wp-admin > Plugins > Add New does. Packages from any other host
  are refused. See https://wordpress.org/about/privacy/ for WordPress.org's
  privacy policy.

No other data leaves your site.

== Security ==

* Claude connects using a WordPress Application Password (standard HTTP Basic auth
  over HTTPS); every request is authenticated as your user.
* NC-Pilot does not create users or Application Passwords. You create the
  Application Password yourself under Users → Profile, and can revoke it there.
* Risky capabilities (editing theme files, deleting content, uploading media,
  installing plugins) are OFF until you turn them on.
* NC-Pilot never activates or deactivates plugins. Plugins it installs stay
  inactive until you activate them from the Plugins screen.
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
3. Open "NC-Pilot" in the menu and follow Step A to create an Application
   Password on your profile screen.
4. Paste it into Step B, then copy the command shown and paste it into Claude.

== Changelog ==

= 2.4.0 =
* Removed: activating and deactivating plugins (the "turn plugins on or off"
  toggle, the activate_plugin / deactivate_plugin tools and
  POST /ncpilot/v1/plugin). Plugins installed by Claude always stay inactive
  until you activate them yourself.
* Changed: NC-Pilot no longer creates an Application Password. You create one
  under Users → Profile → Application Passwords and paste it into the setup
  page; the setup commands are built in your browser and the password is never
  sent to or stored by the plugin.
* Upgrading: existing connections keep working. The old "NC-Pilot MCP"
  Application Password stays valid until you revoke it on your profile screen.

= 2.3.0 =
* New: inspect Elementor pages and add, edit or remove their components.
* Preserve unrelated settings and children when editing component controls.
* Require a fresh page revision before changes and enforce Elementor permissions.
* Component removal requires the existing delete-content toggle.
* Save through Elementor's document API to refresh CSS and document caches.


= 2.2.0 =
* New: "Let Claude install plugins" toggle (off by default). Claude can search
  the official WordPress.org directory and install plugins from it. Only
  WordPress.org packages are accepted; activation after install still requires
  the separate "turn plugins on or off" toggle. Respects DISALLOW_FILE_MODS.
* New MCP tools: search_plugins, install_plugin.
* New REST: GET /ncpilot/v1/plugin-search, POST /ncpilot/v1/plugin-install.
* Fix: uninstall now also removes the upload/install toggle options.
* Bundled GPL-2.0 LICENSE, added Author URI, trademark disclaimer and an
  External Services section to the readme.

= 2.1.0 =
* Renamed to "NC-Pilot" (slug nc-pilot) for the WordPress.org directory.
* Passed the official Plugin Check: exception messages are now escaped, and
  read-only admin GET flags are annotated as not requiring a nonce.

= 2.0.1 =
* Fix: the MCP endpoint now answers GET and DELETE requests with HTTP 405
  (Method Not Allowed) instead of 404. MCP clients open a GET to probe for a
  server-sent-events stream when connecting; the previous 404 made some clients
  treat the endpoint as missing and fail to connect. POST (the actual JSON-RPC
  transport) was unaffected.

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
