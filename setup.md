# WP AI Fragments setup for agents

Install the plugin, connect an MCP client, and verify a native WordPress admin card. Human instructions are in [README.md](README.md); repository guidance is in [AGENTS.md](AGENTS.md). Report installation, protocol checks, and actual browser observations separately.

## 1. Inspect and choose a setup lane

Before creating anything, determine:

- Does the user have an existing WordPress site to connect? Record its URL, WordPress subdirectory if any, and plugin administration access.
- Is this repository already checked out? Reuse its checkout and configured MCP connection.
- Is local WordPress already running? After installing dependencies, run `npm run dev:status`. Keep a running site alive: starting Playground creates a fresh database.
- Can the client's MCP configuration be edited, and does it support MCP Apps, authenticated HTTP or STDIO, and this plugin's Codex viewer profile?
- Which tools and credentials are available? Check presence without printing secrets, cookies, Authorization headers, or private handoff metadata.

Use **lane A** for an existing remote site and **lane B** for a disposable local demo or repository work. Reuse a working setup instead of creating another site or duplicate MCP registration. When access or secret storage is unavailable, prepare the build and provide exact remaining UI steps; do not claim setup is complete.

## 2. Check compatibility

- The plugin requires PHP **8.0+**. Its header does not declare a minimum WordPress version; local development pins WordPress 7.0.4 and PHP 8.3.
- Source builds need Git, npm **8.19.2+**, Node **22.12+** (Vite also supports Node 20.19+ on 20.x), and Python 3 for packaging. `npm ci` uses the lockfile and applies the committed dependency patch.
- Remote browser handoff needs HTTPS and a browser accepting partitioned `Secure; HttpOnly; SameSite=None` session cookies.
- Approved viewers are the bundled test viewer and Codex origins matching `codex-sandbox://mcp-app-<48 lowercase hex characters>.web-sandbox.oaiusercontent.com`. Generic MCP Apps support alone is insufficient for interactive rendering.
- HTTP uses Basic authentication with WordPress Application Passwords. There is no OAuth flow. WordPress capabilities still govern screens and saves.
- The STDIO bridge accepts only WordPress hosts `localhost` and `127.0.0.1`.
- This is experimental: browser sessions last 20 minutes, with no production renewal, revocation management, or expired-ticket cleanup.

Do not broaden origin validation or remove frame/cookie restrictions just to make setup pass.

## 3A. Existing WordPress site

### Build and install

If a checkout is unavailable, clone `https://github.com/bgrgicak/wp-ai-fragments.git`. From its root:

```sh
npm ci
npm run package
```

Expected artifact: `dist/wp-ai-fragments.zip`. Upload it through **Plugins → Add New Plugin → Upload Plugin**, install, and activate **WP AI Fragments**. With filesystem access, install the three runtime files listed in the README under `wp-content/plugins/wp-ai-fragments/`, preserving paths. A checkout without `experiments/native-admin/dist/view.html` is incomplete. The WordPress host needs no Node.js, Python, or development server.

If already installed, verify activation and the bundle before reinstalling. No other WordPress plugin is required; WooCommerce is only needed for its own admin screens.

### Authenticate and connect

Use a dedicated account with the capabilities for the requested screens. MCP needs at least `read`, but settings and editing require their corresponding WordPress capabilities. Reuse a working Application Password or create a named one in **Users → Profile → Application Passwords**. Store it privately. If the user must create it, direct them to their profile and client secret storage; do not ask them to paste it into chat.

Configure one HTTP MCP connection named `wp-native-admin`:

```text
URL: https://your-site.example/wp-json/aif-proof/v1/mcp
Authentication: HTTP Basic (WordPress username + Application Password)
```

Include a WordPress subdirectory if present. If the client accepts headers only, set `Authorization: Basic <base64(username:application-password)>` in private settings. Base64 is not encryption. If pretty REST URLs are unavailable, direct HTTP can use `https://your-site.example/?rest_route=/aif-proof/v1/mcp`.

Use the client's supported configuration mechanism, preserve unrelated connections, and reconnect after saving. Continue to section 4. The local bridge and maintainer demo proxy are not remote-site connectors.

## 3B. Local development checkout

### Dependencies and lifecycle

From the existing checkout:

```sh
npm ci
npm run dev:status
```

Automatic provisioning also needs macOS `security`, `curl`, `jq`, Perl, OpenSSL, and `uuidgen`. Local PHP 8.0+ is needed for lint/fixture tests. The configured Playground runtime needs no Docker.

For a stopped environment when a fresh disposable site is intended:

```sh
npm run dev:start
```

This starts Playground, creates or finds the `wp-ai-agent` administrator, stores a new Application Password in Keychain service `wp-ai-fragments-mcp`, activates the plugin, and builds the viewer. Local admin is `http://localhost:8888/wp-admin/`, with development login `admin` / `password`.

For a running environment, activate and build without restarting:

```sh
python3 experiments/native-admin/setup-local.py
npm run build
```

The activation script may deactivate the prior proof entrypoint and old local WooCommerce editor override. It creates no content fixtures. When credentials alone are missing, run `npm run dev:provision` on macOS. Each run creates another Application Password; reuse working credentials.

If provisioning fails, inspect status before continuing: WordPress may already be running. Without macOS Keychain, use this manual path for a stopped site:

```sh
npx wp-env start --runtime=playground
python3 experiments/native-admin/setup-local.py
npm run build
test -f .wp-env.mcp.local || cp .wp-env.mcp.local.example .wp-env.mcp.local
```

Create an Application Password through the local profile UI for `admin` or a dedicated account you create. Privately edit the ignored `.wp-env.mcp.local` with `WP_ENV_SITE_URL`, `WP_MCP_USERNAME` set to that account, and its `WP_API_PASSWORD`. This manual path does not create `wp-ai-agent`, which is the example file's default. The wrapper sources it as shell code: quote values as needed and use trusted content. Alternatively, configure those variables in private client environment settings. Do not overwrite an existing credential file.

### Register local MCP

Add or reuse one STDIO MCP connection named `wp-native-admin`:

```text
Command: sh
Arguments: ["/absolute/path/to/wp-ai-fragments/scripts/mcp-proxy.sh"]
```

Replace the placeholder with the verified checkout path. The wrapper resolves its repository root and loads `.wp-env.mcp.local`. With client environment variables or Keychain credentials, `node` with the absolute path to `experiments/native-admin/mcp-server.mjs` is also valid. Keep only one registration.

The bridge defaults to `http://localhost:8888` and `wp-ai-agent`; `WP_API_PASSWORD` overrides Keychain. `npm run mcp:start` waits for JSON-RPC over STDIN; it does not register a client connection.

### Ensure browser reachability

Protocol access and browser access are separate. The viewer must load the WordPress origin returned by `show_wp_admin` and accept its frame and cookie policies.

Use a configured public HTTPS tunnel, such as Jurassic Tube, forwarding to the restricted proxy at `127.0.0.1:8893`. Write the assigned HTTPS origin to the ignored `experiments/native-admin/.https-demo` file. See [the development tunnel guide](docs/development-tunnel.md) for generic account, hostname, and SSH examples. The bridge checks public bootstrap availability; TLS overrides apply only to local WordPress environments.

For separate loopback browser debugging:

```sh
node experiments/native-admin/serve.mjs
```

Open `http://127.0.0.1:8890/`. This builds the host bundle and reads credentials from environment variables or Keychain. The harness and live tests do not load `.wp-env.mcp.local` automatically. When using that trusted file, export its values before starting the harness or tests:

```sh
set -a
. ./.wp-env.mcp.local
set +a
```

This is a credentialed test harness and must stay local. Browser policies can differ from Codex. If chat browser transport is unavailable, finish installation/protocol checks and report the browser reachability blocker rather than claiming visible acceptance.

## 4. Verify in layers

### Artifact and isolated checks

For repository work, verify the generated bundle and run:

```sh
php -l wp-ai-fragments.php
php -l experiments/native-admin/native-admin.php
node experiments/native-admin/test-bootstrap.mjs
node experiments/native-admin/test-view.mjs
```

The Node commands run isolated fixtures and need no WordPress site or credentials; `test-bootstrap.mjs` also invokes PHP. If PHP is unavailable, report its lint and bootstrap fixture checks as unrun; building alone does not validate PHP.

### Authenticated MCP checks

Using the configured client:

1. Initialize successfully and confirm `tools` and `resources` capabilities.
2. Discover `show_wp_admin`. Raw discovery also lists `open-session` with app-only visibility; keep it hidden from model-facing tool selection.
3. Discover and read `ui://wp-ai-fragments/wp-admin-v1.html`, with MIME type `text/html;profile=mcp-app` and bundled viewer content.
4. Call `show_wp_admin` with `{"url":"/wp-admin/"}`. Expect a same-site `origin`, admin `path`, WordPress-generated `bootstrapUrl`, and MCP Apps resource metadata. Use the actual admin path for a subdirectory site.

The viewer handles private handoff; do not manually call `open-session` or expose tickets.

`npm test` adds live STDIO and HTTP checks. Run it only against the disposable local environment with credentials and the expected origin configuration; `test-protocol.py` reads the private public-origin configuration and otherwise uses the local site URL. The suite creates, saves, reads, and deletes a temporary draft. It is not a production-site acceptance suite. See [TESTING.md](TESTING.md).

### Visible acceptance

Open fresh cards in the intended client and confirm the native dashboard at `/wp-admin/`, the list and its controls at `/wp-admin/edit.php`, and an account-permitted editor or settings screen. For an authorized write check, use a disposable draft, save through the native UI, and independently reopen it to verify persistence.

Report observed interactions separately from protocol discovery. Tests sending cookies manually cannot prove browser cookie acceptance. Diagnose HTTPS, browser reachability, viewer approval, frame headers, and partitioned cookies using the README troubleshooting table.

## 5. Report the result

Report the site URL, installation/activation result, connection name and transport, checks actually performed, and any blocker. Omit secrets. Rebuild after viewer changes, reconnect after discovery/configuration changes, and open fresh cards.

Leave a running local site available unless the user asked to stop it. `npm run dev:stop` stops it; a subsequent Playground start creates a fresh database. `npm run dev:destroy` discards the environment. Do not use either merely to refresh a card.
