# WP NC-Pilot

**Manage your WordPress site by talking to Claude — no terminal, no config files.**

WP NC-Pilot lets you run your website from a conversation with Claude: write and
edit posts and pages, manage your media, switch plugins on or off, and (if you
allow it) edit theme files. Setup is one button and one copy-paste.

It has two halves that work together:

1. **The WordPress plugin** (`wp-nc-pilot/`) — a normal plugin zip you upload in
   your WordPress admin. It adds a **WP NC-Pilot** screen with a one-click
   "Connect to Claude" button.
2. **The connector** (`wp-nc-pilot-connector/`) — a tiny helper that runs on your
   own computer (via `npx`, no install) and relays Claude's requests to your site.

```
Claude  ──stdio──▶  wp-nc-pilot-connector (on your computer)  ──HTTPS──▶  your WordPress site
```

## Setup (about two minutes)

**You need:** an admin login to your WordPress site, and either
[Claude Code](https://claude.com/claude-code) or the Claude Desktop app.
[Node.js 18+](https://nodejs.org) must be installed (it runs the connector).

1. **Install the plugin.** In WordPress, go to **Plugins → Add New → Upload
   Plugin**, choose `wp-nc-pilot.zip`, and click **Install Now**, then **Activate**.
2. **Open WP NC-Pilot** from the admin menu (the superhero icon).
3. **Click "Connect to Claude".** It creates a secure password and shows you a
   ready-to-paste command. (You'll see the password only once — that's normal.)
4. **Paste the command** into Claude Code, *or* paste the JSON block into your
   Claude Desktop config. Done.
5. Come back to the WP NC-Pilot screen — the status turns **green** once Claude
   connects.

Now just talk to Claude: *"List my draft posts,"* *"Create a page called
Pricing,"* *"Upload this image,"* and so on.

## What Claude is allowed to do

Everything risky starts **switched off**. On the WP NC-Pilot screen you decide,
with plain-language toggles, exactly what Claude may do:

| Toggle                         | Default | What it allows                                   |
| ------------------------------ | ------- | ------------------------------------------------ |
| Read theme files               | off     | Claude can look at your theme's code             |
| **Edit** theme files           | off     | Claude can change theme code (backups made first)|
| Turn plugins on/off            | off     | Activate/deactivate installed plugins            |
| Delete posts, pages, or media  | off     | Remove content (to Trash where possible)         |

Creating and editing posts, pages, and media works as soon as you connect (it
only needs your normal WordPress permissions). New posts and pages are saved as
**drafts** unless you ask to publish.

## Safety

- Every request is authenticated with a WordPress **Application Password**.
- Theme edits are **confined to your active theme** — paths that try to escape it
  are rejected.
- Every theme file is **backed up** (a timestamped copy) before it is changed or
  deleted, so changes can be undone.
- If your host blocks direct file editing (some require FTP details), you get a
  clear message instead of a silent failure.
- Dangerous abilities are off until you turn them on.

## Repository layout

```
wp-nc-pilot/             The WordPress plugin (PHP, no build step)
wp-nc-pilot-connector/   The local MCP connector (Node, published to npm)
assets/                  Plugin icon + banner artwork
```

See each folder's README for details.

## License

GPL-2.0-or-later.
