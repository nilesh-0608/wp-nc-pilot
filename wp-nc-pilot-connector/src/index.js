#!/usr/bin/env node
/**
 * WP NC-Pilot connector — a local MCP stdio server.
 *
 * Claude talks to this over stdio; this talks to a WordPress site over HTTPS
 * using the built-in REST API (/wp/v2/*) and the WP NC-Pilot plugin's own
 * endpoints (/ncpilot/v1/*).
 *
 * Phase 2: connection handshake + read-only content tools.
 *
 * IMPORTANT: stdout is reserved for the MCP protocol. All logging goes to
 * stderr via console.error.
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import { readFile } from 'node:fs/promises';
import { basename, extname } from 'node:path';

import { readConfig, WPClient } from './wp-client.js';

/** Minimal extension → MIME map for common media uploads. */
const MIME_BY_EXT = {
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.svg': 'image/svg+xml',
  '.pdf': 'application/pdf',
  '.mp4': 'video/mp4',
  '.mp3': 'audio/mpeg',
  '.zip': 'application/zip',
};

/** Guess a MIME type from a filename, defaulting to octet-stream. */
function guessMime(name) {
  return MIME_BY_EXT[extname(name).toLowerCase()] || 'application/octet-stream';
}

/**
 * Wrap a tool handler so thrown errors become friendly MCP tool errors
 * instead of crashing the server.
 *
 * @param {(args: any) => Promise<string>} fn Returns text to show the user.
 */
function tool(fn) {
  return async (args) => {
    try {
      const text = await fn(args || {});
      return { content: [{ type: 'text', text }] };
    } catch (err) {
      return {
        content: [{ type: 'text', text: `⚠️ ${err.message}` }],
        isError: true,
      };
    }
  };
}

/** Format a list of posts/pages for display. */
function formatPosts(items, label) {
  if (!Array.isArray(items) || items.length === 0) {
    return `No ${label} found.`;
  }
  const lines = items.map((p) => {
    const title = (p.title && (p.title.rendered ?? p.title)) || '(no title)';
    return `#${p.id} — ${stripTags(title)}  [${p.status}]  ${p.link || ''}`;
  });
  return `${items.length} ${label}:\n` + lines.join('\n');
}

/** Strip HTML tags + decode a couple of common entities for tidy output. */
function stripTags(s) {
  return String(s)
    .replace(/<[^>]*>/g, '')
    .replace(/&amp;/g, '&')
    .replace(/&#8217;/g, '’')
    .replace(/&#8211;/g, '–')
    .trim();
}

async function main() {
  const cfg = readConfig();
  const wp = new WPClient(cfg);

  const server = new McpServer({
    name: 'wp-nc-pilot-connector',
    version: '0.1.0',
  });

  /* ----------------------------- Status ------------------------------ */

  server.registerTool(
    'check_connection',
    {
      title: 'Check connection to WordPress',
      description:
        'Verify the connection to the WordPress site and report which capabilities ' +
        'the site owner has enabled. Use this first if anything seems wrong.',
      inputSchema: {},
    },
    tool(async () => {
      const s = await wp.request('/ncpilot/v1/status');
      const caps = Object.entries(s.capabilities || {})
        .map(([k, v]) => `  - ${k}: ${v ? 'ON' : 'off'}`)
        .join('\n');
      return (
        `Connected to "${s.site}" as ${s.user}.\n` +
        `Plugin version ${s.version}.\nEnabled capabilities:\n${caps}`
      );
    })
  );

  /* ----------------------------- Posts ------------------------------- */

  server.registerTool(
    'list_posts',
    {
      title: 'List posts',
      description:
        'List blog posts on the WordPress site. Optionally filter by a search ' +
        'term or status (publish, draft, pending, future, private).',
      inputSchema: {
        search: z.string().optional().describe('Words to search for in posts.'),
        status: z
          .string()
          .optional()
          .describe('publish | draft | pending | future | private'),
        per_page: z
          .number()
          .int()
          .min(1)
          .max(100)
          .optional()
          .describe('How many to return (default 20).'),
      },
    },
    tool(async ({ search, status, per_page }) => {
      const items = await wp.request('/wp/v2/posts', {
        query: { search, status, per_page: per_page ?? 20, context: 'edit' },
      });
      return formatPosts(items, 'posts');
    })
  );

  server.registerTool(
    'get_post',
    {
      title: 'Get one post',
      description: 'Read a single post by its numeric ID, including its content.',
      inputSchema: {
        id: z.number().int().describe('The post ID.'),
      },
    },
    tool(async ({ id }) => {
      const p = await wp.request(`/wp/v2/posts/${id}`, {
        query: { context: 'edit' },
      });
      const title = stripTags((p.title && p.title.rendered) || '(no title)');
      const content = stripTags((p.content && p.content.rendered) || '');
      return (
        `#${p.id} — ${title}  [${p.status}]\n${p.link || ''}\n\n` +
        content.slice(0, 4000)
      );
    })
  );

  /* ----------------------------- Pages ------------------------------- */

  server.registerTool(
    'list_pages',
    {
      title: 'List pages',
      description: 'List the pages on the WordPress site (About, Contact, etc.).',
      inputSchema: {
        search: z.string().optional().describe('Words to search for in pages.'),
        per_page: z.number().int().min(1).max(100).optional(),
      },
    },
    tool(async ({ search, per_page }) => {
      const items = await wp.request('/wp/v2/pages', {
        query: { search, per_page: per_page ?? 20, context: 'edit' },
      });
      return formatPosts(items, 'pages');
    })
  );

  /* ----------------------------- Media ------------------------------- */

  server.registerTool(
    'list_media',
    {
      title: 'List media',
      description: 'List items in the WordPress Media Library (images, files).',
      inputSchema: {
        search: z.string().optional(),
        per_page: z.number().int().min(1).max(100).optional(),
      },
    },
    tool(async ({ search, per_page }) => {
      const items = await wp.request('/wp/v2/media', {
        query: { search, per_page: per_page ?? 20 },
      });
      if (!items.length) return 'No media found.';
      const lines = items.map(
        (m) =>
          `#${m.id} — ${stripTags(
            (m.title && m.title.rendered) || '(untitled)'
          )}  [${m.media_type}]  ${m.source_url || ''}`
      );
      return `${items.length} media items:\n` + lines.join('\n');
    })
  );

  /* ---------------------------- Plugins ------------------------------ */

  server.registerTool(
    'list_plugins',
    {
      title: 'List plugins',
      description:
        'List the plugins installed on the WordPress site and whether each is active.',
      inputSchema: {},
    },
    tool(async () => {
      const items = await wp.request('/wp/v2/plugins');
      if (!Array.isArray(items) || !items.length) return 'No plugins found.';
      const lines = items.map(
        (p) =>
          `${p.status === 'active' ? '●' : '○'} ${stripTags(p.name)} ` +
          `(${p.version || '?'})  [${p.status}]`
      );
      return `${items.length} plugins (● active, ○ inactive):\n` + lines.join('\n');
    })
  );

  /* -------------------------- Content writes ------------------------- */

  const statusField = z
    .enum(['draft', 'publish', 'pending', 'private', 'future'])
    .optional()
    .describe('Defaults to draft for new items, for safety.');

  server.registerTool(
    'create_post',
    {
      title: 'Create a post',
      description:
        'Create a new blog post. It is saved as a DRAFT unless you set status to ' +
        '"publish". Returns the new post ID and its edit/view link.',
      inputSchema: {
        title: z.string().describe('The post title.'),
        content: z
          .string()
          .optional()
          .describe('The post body. HTML is allowed.'),
        excerpt: z.string().optional(),
        status: statusField,
      },
    },
    tool(async ({ title, content, excerpt, status }) => {
      const p = await wp.request('/wp/v2/posts', {
        method: 'POST',
        body: {
          title,
          content: content ?? '',
          excerpt: excerpt ?? '',
          status: status ?? 'draft',
        },
      });
      return `Created post #${p.id} [${p.status}].\n${p.link || ''}`;
    })
  );

  server.registerTool(
    'update_post',
    {
      title: 'Update a post',
      description:
        'Update an existing post by ID. Only the fields you provide are changed. ' +
        'Set status to "publish" to publish a draft.',
      inputSchema: {
        id: z.number().int().describe('The post ID to update.'),
        title: z.string().optional(),
        content: z.string().optional(),
        excerpt: z.string().optional(),
        status: statusField,
      },
    },
    tool(async ({ id, title, content, excerpt, status }) => {
      const body = {};
      if (title !== undefined) body.title = title;
      if (content !== undefined) body.content = content;
      if (excerpt !== undefined) body.excerpt = excerpt;
      if (status !== undefined) body.status = status;
      if (Object.keys(body).length === 0) {
        throw new Error('Nothing to update — provide at least one field to change.');
      }
      const p = await wp.request(`/wp/v2/posts/${id}`, { method: 'POST', body });
      return `Updated post #${p.id} [${p.status}].\n${p.link || ''}`;
    })
  );

  server.registerTool(
    'create_page',
    {
      title: 'Create a page',
      description:
        'Create a new page (like About or Contact). Saved as a DRAFT unless status ' +
        'is "publish".',
      inputSchema: {
        title: z.string().describe('The page title.'),
        content: z.string().optional().describe('The page body. HTML is allowed.'),
        status: statusField,
        parent: z
          .number()
          .int()
          .optional()
          .describe('ID of a parent page, to nest this under it.'),
      },
    },
    tool(async ({ title, content, status, parent }) => {
      const body = { title, content: content ?? '', status: status ?? 'draft' };
      if (parent !== undefined) body.parent = parent;
      const p = await wp.request('/wp/v2/pages', { method: 'POST', body });
      return `Created page #${p.id} [${p.status}].\n${p.link || ''}`;
    })
  );

  server.registerTool(
    'update_page',
    {
      title: 'Update a page',
      description: 'Update an existing page by ID. Only the fields you provide change.',
      inputSchema: {
        id: z.number().int().describe('The page ID to update.'),
        title: z.string().optional(),
        content: z.string().optional(),
        status: statusField,
      },
    },
    tool(async ({ id, title, content, status }) => {
      const body = {};
      if (title !== undefined) body.title = title;
      if (content !== undefined) body.content = content;
      if (status !== undefined) body.status = status;
      if (Object.keys(body).length === 0) {
        throw new Error('Nothing to update — provide at least one field to change.');
      }
      const p = await wp.request(`/wp/v2/pages/${id}`, { method: 'POST', body });
      return `Updated page #${p.id} [${p.status}].\n${p.link || ''}`;
    })
  );

  server.registerTool(
    'upload_media',
    {
      title: 'Upload media',
      description:
        'Upload a file to the Media Library, either from a local file path on this ' +
        'computer or from a public URL. Returns the new media ID and its URL.',
      inputSchema: {
        path: z
          .string()
          .optional()
          .describe('Local file path on this computer.'),
        url: z
          .string()
          .optional()
          .describe('Public URL to download and upload instead of a local path.'),
        filename: z
          .string()
          .optional()
          .describe('Override the stored filename (with extension).'),
        title: z.string().optional().describe('Optional media title.'),
      },
    },
    tool(async ({ path, url, filename, title }) => {
      let buffer;
      let name = filename;

      if (path) {
        buffer = await readFile(path);
        name = name || basename(path);
      } else if (url) {
        const res = await fetch(url);
        if (!res.ok) {
          throw new Error(`Could not download the file from ${url} (HTTP ${res.status}).`);
        }
        buffer = Buffer.from(await res.arrayBuffer());
        name = name || basename(new URL(url).pathname) || 'upload';
      } else {
        throw new Error('Provide either a local file "path" or a "url".');
      }

      const m = await wp.uploadMedia({
        buffer,
        filename: name,
        mimeType: guessMime(name),
        title,
      });
      return `Uploaded media #${m.id}.\n${m.source_url || ''}`;
    })
  );

  /* ----------------------- Plugins (toggle-gated) -------------------- */

  server.registerTool(
    'activate_plugin',
    {
      title: 'Activate a plugin',
      description:
        'Turn on an already-installed plugin. Requires the site owner to enable the ' +
        '"manage plugins" toggle. Use the plugin path from list_plugins (e.g. "akismet/akismet").',
      inputSchema: {
        plugin: z.string().describe('Plugin path, e.g. "akismet/akismet".'),
      },
    },
    tool(async ({ plugin }) => {
      const r = await wp.request('/ncpilot/v1/plugin', {
        method: 'POST',
        body: { plugin, action: 'activate' },
      });
      return `Activated "${r.name}" (${r.version}). Active: ${r.active}.`;
    })
  );

  server.registerTool(
    'deactivate_plugin',
    {
      title: 'Deactivate a plugin',
      description:
        'Turn off an active plugin. Requires the "manage plugins" toggle. Use the plugin ' +
        'path from list_plugins.',
      inputSchema: {
        plugin: z.string().describe('Plugin path, e.g. "akismet/akismet".'),
      },
    },
    tool(async ({ plugin }) => {
      const r = await wp.request('/ncpilot/v1/plugin', {
        method: 'POST',
        body: { plugin, action: 'deactivate' },
      });
      return `Deactivated "${r.name}". Active: ${r.active}.`;
    })
  );

  /* ------------------- Theme files (toggle-gated) -------------------- */

  server.registerTool(
    'list_theme_files',
    {
      title: 'List theme files',
      description:
        "List the files in the site's active theme. Requires the site owner to " +
        'enable the "read theme files" toggle.',
      inputSchema: {},
    },
    tool(async () => {
      const r = await wp.request('/ncpilot/v1/theme-files');
      if (!r.count) return `Theme "${r.theme}" has no listable files.`;
      const lines = r.files.map((f) => `  ${f.path}  (${f.size} bytes)`);
      return `Theme "${r.theme}" — ${r.count} files:\n` + lines.join('\n');
    })
  );

  server.registerTool(
    'read_theme_file',
    {
      title: 'Read a theme file',
      description:
        'Read one file from the active theme by its path (as shown by ' +
        'list_theme_files). Requires the "read theme files" toggle.',
      inputSchema: {
        path: z
          .string()
          .describe('Theme-relative path, e.g. "style.css" or "inc/setup.php".'),
      },
    },
    tool(async ({ path }) => {
      const r = await wp.request('/ncpilot/v1/theme-file', {
        query: { path },
      });
      return `--- ${r.path} (${r.size} bytes) ---\n${r.contents}`;
    })
  );

  server.registerTool(
    'write_theme_file',
    {
      title: 'Write a theme file',
      description:
        'Overwrite (or create) a file in the active theme. THIS EDITS LIVE SITE ' +
        'CODE and can change how the site looks or works. A timestamped backup of ' +
        'the existing file is made automatically on the server before saving. ' +
        'Requires the "edit theme files" toggle, which is off by default.',
      inputSchema: {
        path: z
          .string()
          .describe('Theme-relative path, e.g. "style.css".'),
        content: z
          .string()
          .describe('The full new contents of the file.'),
      },
    },
    tool(async ({ path, content }) => {
      const r = await wp.request('/ncpilot/v1/theme-file', {
        method: 'POST',
        body: { path, content },
      });
      const what = r.created ? 'Created' : 'Updated';
      const bak = r.backup_saved ? ' A backup of the previous version was saved.' : '';
      return `${what} ${r.path} (${r.bytes} bytes).${bak}`;
    })
  );

  server.registerTool(
    'delete_theme_file',
    {
      title: 'Delete a theme file',
      description:
        'Delete a file from the active theme. A timestamped backup is saved on the ' +
        'server first, so it can be recovered. Requires the "edit theme files" toggle.',
      inputSchema: {
        path: z.string().describe('Theme-relative path to delete.'),
      },
    },
    tool(async ({ path }) => {
      const r = await wp.request('/ncpilot/v1/theme-file', {
        method: 'DELETE',
        query: { path },
      });
      return `Deleted ${r.deleted}. A backup was saved on the server.`;
    })
  );

  /* -------------------- Delete content (toggle-gated) ---------------- */

  /**
   * Build a delete tool for a given content type.
   */
  function deleteContentTool(name, type, noun, permanentNote) {
    server.registerTool(
      name,
      {
        title: `Delete a ${noun}`,
        description:
          `Delete a ${noun} by ID. ${permanentNote} Requires the "delete content" toggle, ` +
          'which is off by default.',
        inputSchema: {
          id: z.number().int().describe(`The ${noun} ID.`),
          force: z
            .boolean()
            .optional()
            .describe('Skip the Trash and delete permanently.'),
        },
      },
      tool(async ({ id, force }) => {
        const r = await wp.request('/ncpilot/v1/delete', {
          method: 'POST',
          body: { type, id, force: force ?? false },
        });
        return `${r.message} (${noun} #${r.id})`;
      })
    );
  }

  deleteContentTool(
    'delete_post',
    'post',
    'post',
    'Goes to the Trash unless you set force to true.'
  );
  deleteContentTool(
    'delete_page',
    'page',
    'page',
    'Goes to the Trash unless you set force to true.'
  );
  deleteContentTool(
    'delete_media',
    'media',
    'media item',
    'This is permanent — WordPress does not keep media in the Trash.'
  );

  /* --------------------------- Connect ------------------------------- */

  const transport = new StdioServerTransport();
  await server.connect(transport);

  // Handshake ping: flips the settings-page badge to "Connected ✓".
  // Best-effort — never block startup on it.
  wp
    .request('/ncpilot/v1/status')
    .then((s) =>
      console.error(
        `[wp-nc-pilot] Connected to "${s.site}" as ${s.user} (plugin ${s.version}).`
      )
    )
    .catch((e) => console.error(`[wp-nc-pilot] Status check failed: ${e.message}`));

  console.error('[wp-nc-pilot] Connector running on stdio.');
}

main().catch((err) => {
  console.error(`[wp-nc-pilot] Fatal: ${err.message}`);
  process.exit(1);
});
