/**
 * Tiny WordPress REST client.
 *
 * Auth is HTTP Basic using a WordPress Application Password
 * (WP_USER : WP_APP_PASS). Node 18+ provides a global `fetch`.
 *
 * Every method returns parsed JSON or throws an Error with a human-readable
 * message — the MCP layer turns thrown errors into friendly tool errors.
 */

/**
 * Read + validate the connector's environment configuration.
 *
 * @returns {{ baseUrl: string, user: string, pass: string, authHeader: string }}
 */
export function readConfig() {
  const rawUrl = process.env.WP_URL;
  const user = process.env.WP_USER;
  const pass = process.env.WP_APP_PASS;

  const missing = [];
  if (!rawUrl) missing.push('WP_URL');
  if (!user) missing.push('WP_USER');
  if (!pass) missing.push('WP_APP_PASS');
  if (missing.length) {
    throw new Error(
      `Missing required setting(s): ${missing.join(', ')}. ` +
        'These come from the "Connect to Claude" button on the WP NC-Pilot settings page.'
    );
  }

  // Trim a trailing slash so we can join paths cleanly.
  const baseUrl = rawUrl.replace(/\/+$/, '');

  // Application Passwords are shown with spaces for readability; WordPress
  // accepts them with or without. We keep them as-is.
  const authHeader =
    'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64');

  return { baseUrl, user, pass, authHeader };
}

/**
 * A configured client bound to one site.
 */
export class WPClient {
  /**
   * @param {{ baseUrl: string, authHeader: string }} cfg
   */
  constructor(cfg) {
    this.baseUrl = cfg.baseUrl;
    this.authHeader = cfg.authHeader;
  }

  /**
   * Perform a REST request and return parsed JSON.
   *
   * @param {string} path   Path beginning with '/', e.g. '/wp/v2/posts'.
   * @param {object} [opts]
   * @param {string} [opts.method]  HTTP method (default GET).
   * @param {object} [opts.query]   Query params.
   * @param {object} [opts.body]    JSON body (for writes).
   * @returns {Promise<any>}
   */
  async request(path, opts = {}) {
    const { method = 'GET', query, body } = opts;

    const url = new URL(this.baseUrl + '/wp-json' + path);
    if (query) {
      for (const [k, v] of Object.entries(query)) {
        if (v !== undefined && v !== null && v !== '') {
          url.searchParams.set(k, String(v));
        }
      }
    }

    let res;
    try {
      res = await fetch(url, {
        method,
        headers: {
          Authorization: this.authHeader,
          Accept: 'application/json',
          ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
      });
    } catch (networkErr) {
      throw new Error(
        `Could not reach the site at ${this.baseUrl}. ` +
          `Check the web address and that the site is online. (${networkErr.message})`
      );
    }

    const text = await res.text();
    let data;
    try {
      data = text ? JSON.parse(text) : null;
    } catch {
      // Non-JSON response (often an HTML login/error page).
      data = null;
    }

    if (!res.ok) {
      throw new Error(this.describeHttpError(res.status, data, path));
    }

    return data;
  }

  /**
   * Upload a binary file to the Media Library.
   *
   * @param {object} args
   * @param {Buffer} args.buffer    File bytes.
   * @param {string} args.filename  Filename incl. extension.
   * @param {string} args.mimeType  e.g. 'image/png'.
   * @param {string} [args.title]   Optional media title.
   * @returns {Promise<any>}
   */
  async uploadMedia({ buffer, filename, mimeType, title }) {
    const url = new URL(this.baseUrl + '/wp-json/wp/v2/media');
    if (title) url.searchParams.set('title', title);

    let res;
    try {
      res = await fetch(url, {
        method: 'POST',
        headers: {
          Authorization: this.authHeader,
          Accept: 'application/json',
          'Content-Type': mimeType,
          'Content-Disposition': `attachment; filename="${filename}"`,
        },
        body: buffer,
      });
    } catch (networkErr) {
      throw new Error(
        `Could not reach the site at ${this.baseUrl}. (${networkErr.message})`
      );
    }

    const text = await res.text();
    let data;
    try {
      data = text ? JSON.parse(text) : null;
    } catch {
      data = null;
    }
    if (!res.ok) {
      throw new Error(this.describeHttpError(res.status, data, '/wp/v2/media'));
    }
    return data;
  }

  /**
   * Turn an HTTP error into a plain-English message.
   *
   * @param {number} status
   * @param {any} data
   * @param {string} path
   * @returns {string}
   */
  describeHttpError(status, data, path) {
    const apiMsg = data && data.message ? ` (${data.message})` : '';

    if (status === 401) {
      return (
        'Login failed. The username or application password is wrong, or the ' +
        'password was removed in WordPress. Click "Connect to Claude" again to make a new one.' +
        apiMsg
      );
    }
    if (status === 403) {
      return (
        'Permission denied. Your WordPress user is not allowed to do this, or ' +
        'the matching toggle is switched off on the WP NC-Pilot settings page.' +
        apiMsg
      );
    }
    if (status === 404) {
      return `Not found: ${path}.${apiMsg}`;
    }
    return `The site returned an error (HTTP ${status}) for ${path}.${apiMsg}`;
  }
}
