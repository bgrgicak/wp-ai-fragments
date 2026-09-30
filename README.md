# WP AI Fragments

Displays native WordPress admin pages inline through MCP Apps. The plugin has one user-facing tool:

```json
{
  "name": "show_wp_admin",
  "arguments": {"url": "/wp-admin/post.php?post=2&action=edit"}
}
```

`url` accepts an admin path or a full URL on the site's configured origin, for example `https://your-subdomain.jurassic.tube/wp-admin/options-writing.php`. Dashboard, post lists, editors, settings, media, and plugin admin pages use their original WordPress UI. Query strings and URL fragments are preserved. External URLs and paths outside the site's admin directory are rejected. WordPress still checks permissions and nonces for each screen and save.

The display follows the former Gutenberg tool: the native page appears in an iframe, surrounding admin chrome is hidden, and native forms, blocks, settings, and save handlers remain available. Embedded posts and pages use Gutenberg even when Classic Editor is active; ordinary browser sessions retain the site's editor selection. Other post types follow the site's configuration.

The component-only `open-session` tool supplies the existing browser handoff. It is hidden from the model-facing tool list by MCP Apps visibility metadata. A one-time ticket expires after 60 seconds; the partitioned browser session lasts 20 minutes. Cards in the same browser partition reuse a valid session for the same account, preserving native save nonces and the original expiry. A different account cannot replace a live shared session. On browsers with Web Locks, simultaneous handoffs are serialized. The component offers Reconnect after a session/transport failure or 20 seconds of stalled frame loading, while still accepting a late successful load.

The development demo uses the existing Jurassic Tube transport and Codex viewer profile. The PHP plugin loads in all WordPress environments; demo TLS overrides apply only when WordPress is configured as `local`. It does not provide production session renewal, revocation, or expired ticket cleanup. Browser handoff and session-check URLs use WordPress's admin directory, including subdirectory installations.

## Build the plugin

From the repository root:

```sh
npm install
npm run build
```

This bundles the plugin's current MCP Apps viewer into `experiments/native-admin/dist/view.html`, with JavaScript and CSS included in the HTML. It requires no running WordPress site, credentials, or development server. Include the generated file alongside `wp-ai-fragments.php` and `experiments/native-admin/native-admin.php` when uploading the plugin, preserving those paths. The hosting server does not need Node.js.

The optional local harness builds its own `host.html` when `node experiments/native-admin/serve.mjs` starts.

To build an installable ZIP, run `npm run package` (requires Python 3 locally). It rebuilds the viewer and writes `dist/wp-ai-fragments.zip` containing only the plugin's runtime files. Upload it through **Plugins → Add New Plugin → Upload Plugin**, then activate it. No additional WordPress plugins are required; WooCommerce is needed only to use WooCommerce admin screens. Connect an MCP Apps client using the supported Codex viewer profile to the authenticated `/wp-json/aif-proof/v1/mcp` endpoint using a WordPress Application Password. Direct HTTP connections support initialization, ping, tool discovery/calls, and resource discovery/reads. The bundled local STDIO bridge still rejects remote site URLs.

## Local development

Requires Node.js, npm, Python 3, and macOS Keychain for automatic MCP credentials. Playground runs without Docker.

```sh
npm install
npm run dev:status
```

If WordPress is stopped:

```sh
npm run dev:start
```

Starting Playground creates a fresh database. Do not restart a running site to refresh a card. Local admin is `http://localhost:8888/wp-admin/`, with username `admin` and password `password`. The environment installs this plugin and Classic Editor; additional WordPress plugins can be installed normally when needed.

For an already-running site, activate the consolidated entrypoint and rebuild:

```sh
python3 experiments/native-admin/setup-local.py
npm run build
```

Credentials are stored for `wp-ai-agent` under Keychain service `wp-ai-fragments-mcp`. Alternatively, copy `.wp-env.mcp.local.example` to the ignored `.wp-env.mcp.local` for `npm run mcp:start`, or pass `WP_ENV_SITE_URL`, `WP_MCP_USERNAME`, and `WP_API_PASSWORD` directly to the bridge.

## MCP and public transport

The existing `wp-native-admin` registration can keep its command:

```sh
node experiments/native-admin/mcp-server.mjs
```

The prior `scripts/mcp-proxy.sh` registration also runs this same bridge now. Configure one registration to avoid duplicate tools. Reconnect MCP after this refactor to discover `show_wp_admin` and the single resource `ui://wp-ai-fragments/wp-admin-v1.html`; open a fresh card because existing cards retain their old tool results.

The bridge authenticates locally at `/wp-json/aif-proof/v1/mcp`. The native page is served through the existing restricted proxy:

```sh
touch experiments/native-admin/.https-demo
node experiments/native-admin/public-proxy.mjs
```

Keep the existing Jurassic Tube SSH tunnel forwarding the approved public origin `https://your-subdomain.jurassic.tube` to loopback port **8893**. The bridge checks the public bootstrap endpoint before returning a card. Existing tunnel configuration is unchanged. Rebuild after changing `view.js` or `view.html`; WordPress serves `dist/view.html` on each resource read.

Restart an already-running public proxy after updating its code so it allows the admin-directory bootstrap endpoint. Open fresh cards after this update to receive their WordPress-generated bootstrap URLs.

See [TESTING.md](TESTING.md) for protocol checks and browser acceptance. Stop the local environment with `npm run dev:stop` when its data is no longer needed.
