# Testing the native Product data MCP demo

## Preconditions and startup

Do not restart an already-working environment merely to refresh a card. `npm run dev:start` starts a fresh Playground database and provisions a new application password. Export anything valuable first. A full app restart also stopped demo processes during development.

For a fresh disposable environment, from the repository root:

```sh
npm install
npm run dev:start
touch experiments/native-admin/.https-demo
```

Confirm the seed output's product ID and confirm that the record is a WooCommerce product. The current fixture is **12**. Post 13 is an ACF field group in this database. The tool path in `mcp-server.mjs` and compatibility mapping in `native-admin.php` must agree with the selected product. Do not overwrite a different post type to preserve an old ID.

Check that `/wp-json/aif-proof/v1/mcp` exists and requires authentication. The proof plugin is exposed by the root `native-admin-proof.php` symlink. If activation is needed, `python3 experiments/native-admin/setup-local.py` activates it, but also creates a separate draft fixture; its printed ID is not automatically the MCP tool's selection.

Build the single-file MCP resources and start the optional harness:

```sh
AIF_PROOF_PUBLIC_ORIGIN=https://bero.jurassic.tube node experiments/native-admin/serve.mjs
```

This builds `dist/view.html` and `dist/host.html` (ignored by Git), then listens on loopback ports 8890/8891. Keep it in a separate terminal. Port 8888 must already be serving WordPress.

Start the public transport in another terminal:

```sh
node experiments/native-admin/public-proxy.mjs
```

Use the existing Jurassic Tube installation/account to forward the approved `bero` subdomain to **127.0.0.1:8893**, not directly to WordPress:

```sh
jurassictube -u berislavgrgicak -s bero -h 127.0.0.1:8893
```

The installed client in this session used an insecure `curl -k` for allocation. The actual demo instead obtained the allocation with certificate validation and used SSH directly. To reproduce that verified path for this account:

```sh
curl -q -fsS 'https://jurassic.tube/getone.php?sub=bero&username=berislavgrgicak&env=standalone&version=0.1.3&host='
# Use the returned port; 8700 was this session's allocation.
ssh -o BatchMode=yes -o ExitOnForwardFailure=yes -o ConnectTimeout=10 \
  -o ServerAliveInterval=30 -i "$HOME/.jurassictub/key" \
  -T -N -R :8700:127.0.0.1:8893 tunneler_berislavgrgicak@jurassic.tube
```

Do not overwrite another active tunnel. These values are this demo's configuration, not portable defaults. No private key or application password belongs in the repository.

Register the local MCP bridge if not already configured:

```sh
codex mcp add wp-native-admin -- node "$PWD/experiments/native-admin/mcp-server.mjs"
```

Refresh MCP connections after changing the STDIO server's code/tool schema. Verify the new tool/resource version actually loaded; old card results can retain the original path and cached policy. PHP/CSS updates are read on the next native page request and do not require a WordPress restart. Rebuild after editing `view.js` or `view.html`.

## Server checks (not a browser-rendering claim)

```sh
php -l experiments/native-admin/native-admin.php
node --check experiments/native-admin/mcp-server.mjs
node --check experiments/native-admin/public-proxy.mjs
python3 experiments/native-admin/test-protocol.py
```

The suite defaults to local transport `http://localhost:8888`, advertised origin `https://bero.jurassic.tube`, and product 12. Override `WP_ENV_SITE_URL`, `AIF_PROOF_PUBLIC_ORIGIN`, or `AIF_PROOF_PRODUCT_ID` only when they match the actual configured fixture. Credentials come from Keychain or `WP_API_PASSWORD` / `WP_MCP_USERNAME`.

It checks 20 properties: authentication, private handoff, replay/verifier/origin rejection, cookie attributes, session restoration, framing, native nonces, selected product type, plugin asset origins and retained controls. Explicitly sending a cookie in this suite does not test browser cookie policy. The invalid-nonce POST must fail; no valid content save is performed.

Public transport checks:

```sh
# Expected 403: ordinary anonymous access is deliberately restricted.
curl -q -sSI https://bero.jurassic.tube/
# Expected 200, with text/css and JavaScript content types respectively.
curl -q -sSI https://bero.jurassic.tube/wp-content/plugins/woocommerce/assets/css/admin.css
curl -q -sSI https://bero.jurassic.tube/wp-content/plugins/woocommerce/assets/js/admin/meta-boxes-product.js
```

Inspect generated admin asset URLs if controls look unstyled: neither `http://localhost:8888` nor `https://localhost:8888` may remain in plugin stylesheet/script sources. A valid page response with broken scripts is a failed demo.

## Actual chat acceptance test

1. Invoke `wp-native-admin` / `show_product_description`, or ask: “Show me the product details for product 12 so I can update its price and inventory.”
2. Inspect the **inline MCP card**. The visible section must be the real WooCommerce Product data panel and native Update button, not an ACF field group, custom form, screenshot, or separate browser tab.
3. Check WooCommerce styling: tabs form the native sidebar, the product type selector is present, and General shows the relevant fields for the actual product type. A vertical list of plain blue links and external-product fields on a simple product indicates failed assets/scripts.
4. Click Inventory, Shipping and General. Each tab must switch the native panel. Verify the enhanced product/search controls where applicable. Keep the product unchanged for this rendering-only pass.
5. Ensure navigation, title/description, other meta boxes and unrelated publishing controls are hidden. Confirm the Update button is visible and enabled. Hidden native form inputs/nonces must remain in the document.
6. For a **separate reversible save test**, record a disposable product field's current value, change it, click Update, reopen a fresh component and verify persistence. Restore the original value and verify again. Do not claim saving works merely because the button is present. This save test has not yet been independently recorded for this session.
7. Check the authenticated local `/wp-json/aif-proof/v1/render-status` diagnostics. Require a fresh timestamp, the actual `codex-sandbox://...` viewer, post ID 12 and `product_data_ready: true`. Hidden description-editor width/height can be zero by design. Diagnostics supplement inspection; they do not prove click behavior or persisted saving.

If the assistant cannot inspect the chat UI, ask the user to verify the current card. Do not use another capture mechanism to bypass Computer Use's refusal to inspect Codex. The user confirmed the final demo worked on 2026-09-21; do not convert that into an unsupported claim that every future run passed.

## Browser harness and negative controls

Open:

```text
http://127.0.0.1:8890/?path=%2Fwp-admin%2Fpost.php%3Fpost%3D12%26action%3Dedit
```

The frame chain is harness → sandbox → MCP resource → native WordPress. This tests the MCP SDK path but is a different host from chat. In this session the in-app browser blocked Jurassic Tube with `ERR_BLOCKED_BY_CLIENT` while the actual chat card rendered; record each surface separately.

- Add `&noframes=1`: the MCP handshake can succeed, but the native iframe must be blocked.
- Add `&noforms=1`: native form submission should be blocked by the ancestor sandbox; do not use a valuable product for this test.
- Add `&resume=1`: tests an existing browser partition; expect authentication failure after expiry or in a new partition.
- Wrong verifier/origin and reused grants must fail (covered by the protocol suite).
- Ordinary wp-admin/login framing protection must remain in place.

Do not disable certificate checks, CSP or browser local-network protections to count a test as passing.

## Other project tests

`npm run build` and `npm run test:rendering` target the **main fragment viewer**, not this native proof. The latter requires `/wp-json/wp-ai-fragments/v1/mcp` to be active and performs a same-value content update. It may return 404 in the proof profile because of plugin entrypoint selection. Report that as unavailable for this profile; do not report the native suite as a substitute pass.

## Cleanup

Stop the SSH reverse tunnel and the public proxy after the demo. A directly launched foreground SSH tunnel stops with Ctrl-C. For a client-managed tunnel use `jurassictube -b -s bero`. Stop the optional host and WordPress only when their data is no longer needed.

`python3 experiments/native-admin/setup-local.py --deactivate` deactivates the proof and removes the root symlink. The symlink is tracked in this snapshot; restore it intentionally when starting the proof again. Session/ticket lifetimes are bounded, but the experiment does not implement expired-ticket database cleanup or explicit session revocation.
