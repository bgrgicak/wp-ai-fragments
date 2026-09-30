# Agent guide

For installation, authentication, MCP connection, and verification, follow [setup.md](setup.md). Human setup is in [README.md](README.md); browser acceptance and live test scope are in [TESTING.md](TESTING.md).

## Repository map

- `wp-ai-fragments.php`: WordPress plugin entrypoint, requiring PHP 8.0+.
- `experiments/native-admin/native-admin.php`: MCP HTTP endpoint and browser sessions.
- `experiments/native-admin/view.html` and `view.js`: MCP Apps viewer source; `npm run build` generates `experiments/native-admin/dist/view.html`.
- `experiments/native-admin/mcp-server.mjs`: loopback-only STDIO bridge.
- `scripts/mcp-proxy.sh`: wrapper loading ignored credentials before starting the bridge.
- `scripts/package-plugin.py`: runtime-only installable ZIP packaging.
- `.wp-env.json`: disposable Playground configuration.

## Working conventions

Reuse running WordPress and an existing MCP registration. Starting Playground creates a fresh database; do not restart it to refresh a card. Keep credentials in Keychain, ignored `.wp-env.mcp.local`, or private client environment settings. Do not print secrets or private handoff metadata.

Use `npm ci`, `npm run build` after viewer changes, and `npm run package` for an installable ZIP. Run the isolated checks in [setup.md](setup.md) for runtime/viewer changes. Full `npm test` requires a disposable live site and credentials and temporarily creates/deletes a draft; consult [TESTING.md](TESTING.md). Separate protocol results from observed browser acceptance.

Preserve WordPress permissions/nonces, admin URL validation, approved viewers, and private handoff. Public tunnel origins and account/allocation details belong in ignored local configuration. Keep Jurassic Tube instructions generic; see [docs/development-tunnel.md](docs/development-tunnel.md).
