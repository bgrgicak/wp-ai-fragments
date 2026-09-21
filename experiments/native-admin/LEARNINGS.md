# Native wp-admin in MCP Apps: demo findings

Date: 2026-09-21. Final result: the user confirmed that the inline demo works after the asset URL fix. The current UI is WooCommerce's real **Product data** panel and native **Update** button for product **12**, cropped with CSS. It is not a reconstructed editor, screenshot, or custom product form.

## Working architecture

```text
Chat MCP tool: wp-native-admin / show_product_description (historical tool name)
  -> local STDIO mcp-server.mjs
  -> authenticated localhost:8888/wp-json/aif-proof/v1/mcp
  -> MCP Apps HTML resource + App SDK
  -> nested iframe at https://your-subdomain.jurassic.tube
  -> Jurassic Tube SSH reverse tunnel, remote port REMOTE_PORT
  -> loopback public-proxy.mjs on 8893
  -> original WordPress document on 8888
```

The MCP resource uses `text/html;profile=mcp-app`, tool `_meta.ui.resourceUri`, and an exact `ui.csp.frameDomains` entry. The STDIO bridge also supplies `openai/outputTemplate`. The final resource identifier is `ui://aif-proof/native-admin-jurassic-v4.html`; older native-admin resource names remain readable for existing cards.

The proof is separate from the main plugin's fragment discovery/custom-editor implementation. `/wp-json/wp-ai-fragments/v1/mcp` and `/wp-json/aif-proof/v1/mcp` are different endpoints. A passing test for one does not validate the other. The root `native-admin-proof.php` symlink makes the experiment discoverable by WordPress; in this environment plugin entrypoint selection sometimes made the main endpoint unavailable. Check active plugins and routes instead of assuming both run.

## Authentication and framing

- The app creates a random verifier and sends its SHA-256 challenge through the app-only `open-session` tool. The one-use ticket is returned in private `_meta`, not model-visible content.
- The bootstrap page receives ticket + verifier by `postMessage`, with exact source and origin checks, then redeems via a same-origin POST. Ticket lifetime is 60 seconds; session lifetime is 20 minutes.
- The browser receives an HttpOnly, Secure, SameSite=None, Partitioned `__Host-aif-proof` cookie. The server maps this to real WordPress authentication cookies and session tokens. Native forms and REST calls retain WordPress nonces and capability checks.
- Only embedded authenticated responses and the validated bootstrap get the framing exception. Ordinary wp-admin/login responses retain their protection.
- The observed chat origin is `codex-sandbox://mcp-app-<48 hex characters>.web-sandbox.oaiusercontent.com`. The grant is bound to the exact viewer origin. The local harness has an explicitly allowed origin as well.
- `frameDomains` permits the nested frame from the host side; WordPress's `frame-ancestors` must permit every ancestor from the document side. These are independent checks.
- The partitioned cookie worked in the observed host. The unpartitioned control cookie was also accepted. This does **not** establish compatibility with browsers that block all third-party cookies.

## Failure modes and fixes

| Symptom | Finding and resolution |
| --- | --- |
| HTTP frame blocked by CSP | Installed Codex host filtered HTTP entries from frame domains, including localhost. Use HTTPS. |
| `playground.test` reached the wrong server | It was already used. A dedicated name avoided collision; a hosts entry alone did not configure routing. |
| Herd route still returned wrong certificate / 502 | Homebrew nginx, not Herd nginx, owned port 443. A dedicated loopback TLS port avoided altering the existing server. |
| `ERR_CERT_AUTHORITY_INVALID` / Zen `SEC_ERROR_BAD_SIGNATURE` | Herd and older Valet had different CA keys with identical issuer names. Serve the leaf and the correct signing chain. OpenSSL and a macOS trust check alone were insufficient browser evidence. Codex's browser subsequently loaded HTTPS without a warning. Zen was not independently reverified after the fix. |
| `ERR_BLOCKED_BY_LOCAL_NETWORK_ACCESS_CHECKS` | Public MCP sandbox to loopback was blocked independently of TLS/CSP. A custom hosts alias still resolves to loopback. The user-authorized Jurassic Tube public endpoint provided a usable deployment path without disabling browser checks. |
| `Unknown resource` and stale CSP | Tool calls and UI resource reads could come from different running MCP server instances. Version resource identifiers, retain aliases, refresh connections, and confirm the actual returned origin/path. Repeated restarts alone are not verification. |
| Browser harness refused viewer origin | `location.origin` on `about:srcdoc` did not represent the inherited document origin. Use `window.origin`. |
| Native markup rendered but WooCommerce tabs looked like plain links | Plugin assets used `https://localhost:8888` while core assets used the public origin. The URL replacement originally covered only `http://localhost:8888`. Fix both schemes in `plugins_url`, `content_url`, `script_loader_src`, and `style_loader_src`. |
| In-app browser returned `ERR_BLOCKED_BY_CLIENT` for Jurassic Tube | Both direct and harness navigation were blocked in that testing surface. The actual chat MCP card nevertheless rendered, as shown by user screenshots and later confirmation. Do not generalize one surface's result to another. |
| Product 13 turned into an ACF field group | Restarting the Playground-backed environment rebuilt the database. The seed product became ID 12; probing ACF created a different record at ID 13. Verify record type and content after each startup. The user approved product 12. |
| Context A8C connection timed out | Its endpoint was reached through a hosts-file mapping of `public-api.wordpress.com` to a sandbox IP. The mapping was left unchanged. Context A8C was attempted but was not used to establish the final tunnel. |

## What is actually native

`product-data-only.css` hides surrounding admin menus, notices, post fields, other meta boxes, and publishing controls other than Update. It keeps `#woocommerce-product-data`, its type selector/tabs/fields, the original `#post` form, hidden inputs, nonces, and `#publishing-action`. CSS visibility does not remove fields from form submission. Saving is WordPress's normal form behavior; no custom save adapter was introduced.

The crop is only injected for authenticated embedded product-edit requests. Ordinary admin screens are unaffected. These selectors are WooCommerce/classic-editor specific and must be rechecked after upstream UI changes. Hiding controls is presentation, not an authorization boundary. Native notices are hidden by the crop, so validation/save errors may need a future scoped presentation.

The tool keeps its original name `show_product_description` for compatibility, but its description now documents Product data. Existing cards that still request post 13 are explicitly redirected during the Codex session grant to the user-approved product 12. This is temporary demo compatibility, not a generic ID mapping feature.

## Evidence and limits

- Earlier HTTP protocol/authentication suite: 17 passing checks. The updated 20-check suite passed before this commit, including product type, public plugin-asset origins and retained native controls. Main viewer build and PHP/JavaScript syntax checks passed. The main MCP route returned 404 in this active proof profile, so its separate rendering smoke test was not run.
- Earlier browser test: real nested MCP SDK harness rendered the original description editor. Removing frame permission blocked the frame even though the MCP handshake completed.
- Direct browser check after reset: native WooCommerce product 12 loaded with its actual description; post 13 was an ACF field group.
- Actual chat screenshots demonstrated wp-admin, then the cropped Product data panel. After the asset fix, the user explicitly confirmed: “Great! It's working.”
- Asset regression diagnosis inspected generated authenticated admin HTML. All 77 selected WooCommerce/core asset URLs had no remaining localhost reference after the fix. WooCommerce admin CSS, meta-boxes.js, meta-boxes-product.js, and SelectWoo returned HTTP 200 with CSS/JavaScript content types through the public tunnel.
- `render-status` is supplemental browser-reported evidence. Check its timestamp, viewer, post ID and `product_data_ready`; an old success is not a new test. Hidden description editor dimensions can legitimately be zero after cropping.
- A successful tool call, handshake, HTTP 200, homepage, or screenshot of another browser is insufficient proof that the interactive chat editor works.
- A persisted product-data change through Update was **not independently verified in this session**. Run the reversible save test in TESTING.md before claiming end-to-end persistence.

## Operational lessons

1. Keep WordPress and the tunnel alive during a demo. App restarts stopped local processes in this session; even a detached child was not a reliable persistence guarantee.
2. `npm run dev:start` can rebuild the database and rotate the application password. Do not use it as a casual reconnect command. Export valuable data first, verify IDs after startup, and read Keychain credentials per RPC rather than caching them indefinitely.
3. Use the existing approved Jurassic Tube account/domain. The allocated remote port is session/configuration dependent; do not assume REMOTE_PORT for other accounts or forever.
4. The public proxy blocks unauthenticated non-asset traffic, login, XML-RPC and Authorization headers. Its cookie-presence gate is only a transport filter; PHP performs actual session validation. Keep this disposable, local-environment-only proof off production and close the tunnel after use.
5. Do not commit credentials, private keys, grants, cookies, authenticated HTML dumps, or request logs. Generated MCP HTML is rebuilt from source. The demo's public hostname is configuration, not a secret.
6. The public origin, selected product and host policy are currently fixed fixtures. Generalized deployment, revocation, policy negotiation, expired-grant cleanup, multisite and durable database storage remain future work.

## References

- [MCP Apps CSP and CORS](https://apps.extensions.modelcontextprotocol.io/api/documents/csp-and-cors.html)
- [OpenAI iframe and embedded-page guidance](https://developers.openai.com/plugins/app-guidelines#iframes-and-embedded-pages)
- [Chrome Local Network Access](https://developer.chrome.com/blog/local-network-access)
- [Jetpack development environment / Jurassic Tube](https://github.com/Automattic/jetpack/blob/trunk/docs/development-environment.md)
