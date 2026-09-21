# WP AI Fragments

WordPress plugin experiment for discovering wp-admin UI fragments and displaying them as focused, interactive native screens inside MCP Apps through the WordPress Abilities API and official MCP Adapter.

## Working native wp-admin demo

The confirmed chat demo is the separate [native-admin experiment](experiments/native-admin/README.md): actual WooCommerce **Product data** and **Update**, cropped with CSS and embedded through MCP Apps over Jurassic Tube. Start with its [testing/setup instructions](experiments/native-admin/TESTING.md) and [findings](experiments/native-admin/LEARNINGS.md). The historical tool name is `show_product_description`; the current product is 12.

The main fragment viewer described below is a different implementation. Its no-iframe behavior and smoke test do not describe or validate the native-admin demo.

## Local development

Prerequisites: Node.js 20.18 or newer and npm.

```sh
npm install
npm run dev:start
```

The Playground-backed WordPress site runs at <http://127.0.0.1:8888>. The default development administrator is `admin` / `password`.

The environment pins WordPress 7.0.4, PHP 8.3, MCP Adapter 0.6.1, WooCommerce 11.1.0, Classic Editor 1.7.0, Yoast SEO 28.4, Advanced Custom Fields 6.8.10, and Contact Form 7 6.1.7. Only activate the integration plugins needed for the current test profile.

Starting the environment also creates an idempotent WooCommerce product fixture and verifies Contact Form 7's example form. Re-run only the data seeding step with `npm run dev:seed`.

The seed step probes representative admin screens and records meta boxes, Settings API fields, and classic post title/content regions for `ui/list-fragments`. See [projects/fragment-discovery.md](projects/fragment-discovery.md) for the discovery model and current plugin observations.

## MCP connection

The WordPress MCP server endpoint is:

```text
http://127.0.0.1:8888/wp-json/wp-ai-fragments/v1/mcp
```

`npm run dev:start` provisions a dedicated `wp-ai-agent` user and stores a fresh WordPress application password in macOS Keychain under service `wp-ai-fragments-mcp`. Playground starts from a clean database, so this provisioning intentionally runs after every start. As a portable fallback, copy `.wp-env.mcp.local.example` to `.wp-env.mcp.local` and fill in credentials manually; the local file is ignored by Git.

Start the STDIO-to-HTTP bridge with:

```sh
npm run mcp:start
```

Codex can register that bridge as a project-specific WordPress MCP server by launching `scripts/mcp-proxy.sh` as its STDIO command.

The project server exposes only `ui/list-fragments` and `ui/render-fragment` plus the shared interactive viewer resource. The general MCP Adapter server remains available separately for ability-level diagnostics.

The read-only `ui/list-fragments` ability returns fragments discovered on real admin requests. Its optional `plugin` input filters by active plugin slug.

`ui/render-fragment` returns an MCP Apps component for every discovered fragment. Supported post fields (currently title and content) are loaded into native component controls and saved through the app-only `ui/update-post-field` ability. Other fragment types keep the focused authenticated wp-admin URL as a fallback.

The MCP component never embeds wp-admin in a nested iframe. WordPress capability checks run on every read and save ability, while the focused URL remains available for controls that cannot yet be represented safely inside the component. WordPress's normal frame protection remains unchanged.

Run `npm run build` after changing the MCP Apps launcher. With the development site running, `npm run test:rendering` verifies the MCP tool-to-launcher link and native focused URL.

## Testing

See [TESTING.md](TESTING.md) for the two test profiles and required inline verification.
