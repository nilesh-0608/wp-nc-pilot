# wp-nc-pilot-connector

The local helper that lets **Claude** manage your WordPress site through the
[WP NC-Pilot](../wp-nc-pilot) plugin. It runs on your own computer as an MCP
**stdio** server and relays Claude's requests to your site over HTTPS.

You do **not** run this by hand. The WP NC-Pilot plugin gives you a ready-made
command that starts it for you.

## Setup (the easy way)

1. Install and activate the **WP NC-Pilot** plugin in your WordPress admin.
2. Open **WP NC-Pilot** in the admin menu and click **Connect to Claude**.
3. Copy the command it shows you and paste it into Claude Code (or paste the JSON
   block into the Claude Desktop config). That's it.

The command looks like this (the plugin fills in your details):

```
claude mcp add wp-nc-pilot \
  -e WP_URL=https://your-site.com \
  -e WP_USER=your_username \
  -e WP_APP_PASS="xxxx xxxx xxxx xxxx xxxx xxxx" \
  -- npx wp-nc-pilot-connector
```

## Environment variables

| Variable       | Meaning                                              |
| -------------- | ---------------------------------------------------- |
| `WP_URL`       | Your site's web address, e.g. `https://your-site.com`|
| `WP_USER`      | Your WordPress username                              |
| `WP_APP_PASS`  | The Application Password from the Connect button     |

## Tools

**Read (no toggle needed):**

| Tool               | What it does                                  |
| ------------------ | --------------------------------------------- |
| `check_connection` | Confirm the site is reachable; show what's enabled |
| `list_posts`       | List blog posts (filter by search/status)     |
| `get_post`         | Read one post, including its content          |
| `list_pages`       | List pages                                    |
| `list_media`       | List Media Library items                      |
| `list_plugins`     | List installed plugins and their active state |

**Write:**

| Tool                | What it does                                          |
| ------------------- | ---------------------------------------------------- |
| `create_post`       | Create a post (saved as **draft** unless you publish) |
| `update_post`       | Update a post by ID                                  |
| `create_page`       | Create a page (saved as **draft** unless you publish) |
| `update_page`       | Update a page by ID                                  |
| `upload_media`      | Upload a file from a local path or URL               |
| `activate_plugin`   | Activate a plugin — needs the "manage plugins" toggle |
| `deactivate_plugin` | Deactivate a plugin — needs the "manage plugins" toggle |

**Theme files (advanced):**

| Tool                | What it does                                          |
| ------------------- | ---------------------------------------------------- |
| `list_theme_files`  | List files in the active theme — needs "read theme files" |
| `read_theme_file`   | Read one theme file — needs "read theme files"       |
| `write_theme_file`  | Create/overwrite a theme file — needs "edit theme files"; backs up first |

New posts and pages default to **draft** for safety. Plugin activation and theme
editing are each gated by their own toggle on the plugin's settings page. Theme
edits are confined to the active theme and the previous version of every file is
backed up automatically before it is changed.

## Requirements

- Node.js 18 or newer.

## License

GPL-2.0-or-later.
