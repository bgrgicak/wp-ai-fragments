# WP AI Fragments

WordPress plugin experiment for exposing reviewed wp-admin UI fragments to agents through the WordPress Abilities API, the official MCP Adapter, and MCP Apps.

## Local development

Prerequisites: Node.js 20.18 or newer and npm.

```sh
npm install
npm run dev:start
```

The Playground-backed WordPress site runs at <http://127.0.0.1:8888>. The default development administrator is `admin` / `password`.

The environment pins WordPress 7.0.4, PHP 8.3, MCP Adapter 0.6.1, WooCommerce 11.1.0, Classic Editor 1.7.0, Yoast SEO 28.4, Advanced Custom Fields 6.8.10, and Contact Form 7 6.1.7. Only activate the integration plugins needed for the current test profile.

## MCP connection

The WordPress MCP server endpoint is:

```text
http://127.0.0.1:8888/wp-json/mcp/mcp-adapter-default-server
```

`npm run dev:start` provisions a dedicated `wp-ai-agent` user and stores a fresh WordPress application password in macOS Keychain under service `wp-ai-fragments-mcp`. Playground starts from a clean database, so this provisioning intentionally runs after every start. As a portable fallback, copy `.wp-env.mcp.local.example` to `.wp-env.mcp.local` and fill in credentials manually; the local file is ignored by Git.

Start the STDIO-to-HTTP bridge with:

```sh
npm run mcp:start
```

Codex can register that bridge as a project-specific WordPress MCP server by launching `scripts/mcp-proxy.sh` as its STDIO command.

The first smoke test is the read-only `wp-ai-fragments/health-check` ability. The MCP Adapter exposes public abilities through its discover, inspect, and execute meta-tools.
