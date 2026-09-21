# Native wp-admin MCP demo

Working demo confirmed by the user on 2026-09-21: the real WooCommerce **Product data** panel and **Update** button for product **12**, embedded in chat through MCP Apps. CSS hides the rest of the native page. The tool's historical name is `show_product_description`.

Read [LEARNINGS.md](LEARNINGS.md) for the full investigation and [TESTING.md](TESTING.md) for setup, validation and cleanup. The earlier [product-13 report](evidence/product-13-verification.md) is historical, not current setup guidance.

## Current topology

- WordPress: `http://localhost:8888`, Playground-backed wp-env, environment type `local`.
- Local MCP: `mcp-server.mjs` → `/wp-json/aif-proof/v1/mcp`, authenticated using the `wp-ai-agent` application password from macOS Keychain (`wp-ai-fragments-mcp`).
- Native iframe: `https://your-subdomain.jurassic.tube` → SSH tunnel → `public-proxy.mjs` on loopback port 8893 → WordPress.
- Resource: `ui://aif-proof/native-admin-jurassic-v4.html`, MIME `text/html;profile=mcp-app`.
- Optional browser harness: port 8890 → sandbox 8891 → the same MCP resource and native WordPress document.

The `.https-demo` marker enables public-origin URLs for locally transported MCP requests. The public proxy marks forwarded WordPress requests as HTTPS. The origin is currently a fixed demo fixture in PHP and the proxy.

## Components

| File | Purpose |
| --- | --- |
| `native-admin.php` | Local-only PHP plugin: MCP endpoint, one-time handoff, native authentication restoration, scoped framing and crop, diagnostics. |
| `mcp-server.mjs` | STDIO tool/resource bridge; credentials remain local. |
| `view.js`, `view.html` | MCP Apps SDK wrapper and iframe, no replacement WordPress form. |
| `product-data-only.css` | Native Product data + Update crop. |
| `public-proxy.mjs` | Restricted loopback transport for the public tunnel. |
| `host.js`, `host.html`, `serve.mjs` | Optional browser test harness; also builds the single-file resources. |
| `test-protocol.py` | Server protocol/authentication and asset-origin regression checks. |
| `setup-local.py` | Activate/deactivate proof; also creates a separate draft test product, whose ID must not be assumed. |
| `https-proxy.mjs` | Historical Herd TLS diagnostic transport; not the working chat deployment. |

The root `native-admin-proof.php` symlink exposes the experiment to WordPress. The main project's fragment viewer is a separate implementation. The proof deliberately uses native nested iframes; the main viewer does not.

## Limits

This is a disposable demonstration, not a production-ready authentication design. Native WordPress authorization remains in force. The framing exception applies only to the bound embedded session; ordinary admin framing protection remains. The proof replaces its local response CSP rather than merging an arbitrary production policy. Tickets last 60 seconds and native sessions 20 minutes; renewal/revocation and expired ticket cleanup are not implemented.

A quick demo prompt:

> Show me the product details for product 12 so I can update its price and inventory.
