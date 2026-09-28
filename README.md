# NC-Pilot

**Manage your WordPress site by talking to Claude — no terminal, no config files.**

NC-Pilot lets you run your website from a conversation with Claude: write and
edit posts and pages, manage your media, switch plugins on or off, and (if you
allow it) edit theme files. Setup is one button and one copy-paste.

It is **one plugin** — nothing to install on your computer. The plugin turns your
site into a secure server that Claude talks to directly over the web (a "remote
MCP server"). No Node, no npm, no command-line helper.

```
Claude (Code or Desktop)  ──HTTPS / MCP──▶  your WordPress site (this plugin)
```

## Setup (about two minutes)

**You need:** an admin login to your WordPress site (on HTTPS), and either
[Claude Code](https://claude.com/claude-code) or the Claude Desktop app.

1. **Install the plugin.** In WordPress, go to **Plugins → Add New → Upload
   Plugin**, choose `nc-pilot.zip`, and click **Install Now**, then **Activate**.
2. **Open NC-Pilot** from the admin menu (the superhero icon).
3. **Create an Application Password** on your profile screen (the NC-Pilot page
   links straight to it), then paste it into NC-Pilot. It builds a
   ready-to-paste command in your browser — the password is never sent to or
   stored by the plugin.
4. **Paste the command** into Claude Code, *or* paste the JSON block into your
   Claude Desktop config. Done.
5. Come back to the NC-Pilot screen — the status turns **green** once Claude
   connects.

Now just talk to Claude: *"List my draft posts,"* *"Create a page called
Pricing,"* *"Upload this image,"* and so on.

## What Claude is allowed to do

Everything risky starts **switched off**. On the NC-Pilot screen you decide,
with plain-language toggles, exactly what Claude may do:

| Toggle                         | Default | What it allows                                   |
| ------------------------------ | ------- | ------------------------------------------------ |
| Read theme files               | off     | Claude can look at your theme's code             |
| **Edit** theme files           | off     | Claude can change theme code (backups made first)|
| Delete posts, pages, or media  | off     | Remove content (to Trash where possible)         |
| Upload media                   | off     | Add images/files (from a URL or your computer)   |
| Install plugins                | off     | Search WordPress.org and install (stays inactive)|

Creating and editing posts and pages works as soon as you connect (it only needs
your normal WordPress permissions). New posts and pages are saved as **drafts**
unless you ask to publish.

## Safety

- Every request is authenticated with a WordPress **Application Password**.
- Theme edits are **confined to your active theme** — paths that try to escape it
  are rejected.
- Every theme file is **backed up** (a timestamped copy) before it is changed or
  deleted, so changes can be undone.
- If your host blocks direct file editing (some require FTP details), you get a
  clear message instead of a silent failure.
- Dangerous abilities are off until you turn them on.

## How it works

The plugin registers a single MCP endpoint, `POST /wp-json/ncpilot/v1/mcp`, that
speaks JSON-RPC 2.0 (the MCP "Streamable HTTP" transport). Claude authenticates
with your Application Password and calls tools like `create_post`, `list_pages`,
or `upload_media`. Each tool is dispatched internally to the plugin's own REST
routes, so every capability toggle and permission check is enforced server-side.

## Repository layout

```
nc-pilot/   The WordPress plugin (PHP, no build step) — this is the whole product
assets/        Plugin icon + banner artwork
```

## License

GPL-2.0-or-later.

## Elementor pages and components

With Elementor active, NC-Pilot can work with existing Elementor pages:

- `get_elementor_page`: read component IDs, settings, children and a revision token.
- `add_elementor_component`: add a container, section, column or registered widget at a chosen parent and position.
- `edit_elementor_component`: update selected controls, preserving other settings and children.
- `remove_elementor_component`: remove a component and its children (requires the delete-content toggle).

Save the page in Elementor first. Find its ID using `list_pages`, then read it
before each change and pass the returned `revision`. Stale revisions are rejected.
Settings use Elementor control names such as `title` for a heading. Supplied
controls replace their whole value, including nested values. Saves use Elementor's
document API to invalidate generated CSS and caches. Published pages change
immediately; page status is preserved. Supported layouts are classic Elementor
containers, sections, columns and widgets; Atomic elements and nested-widget child
insertion are not supported. Administrator, per-page edit and Elementor document
permissions are required.
