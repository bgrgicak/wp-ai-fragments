# Testing native WordPress admin

Keep the current WordPress site running; restarting Playground resets its database. Activate with `python3 experiments/native-admin/setup-local.py`, run `npm run build`, and keep the configured public proxy and HTTPS tunnel running. See [the development tunnel guide](docs/development-tunnel.md). Live tests read `AIF_PROOF_PUBLIC_ORIGIN` or the ignored `.https-demo` origin file; otherwise they use the local site URL.

```sh
php -l wp-ai-fragments.php
php -l experiments/native-admin/native-admin.php
npm test
```

The suite checks runtime loading in all WordPress environments, local-only demo overrides, subdirectory bootstrap/session-check URLs, simultaneous browser handoffs, stalled-frame recovery, and native error-page visibility when admin footers are absent. Against the running site it checks both direct HTTP and STDIO tool/resource discovery, HTTP ping, accepted admin URLs, rejected external/traversal URLs, hidden session tools, private handoff, verifier/origin/replay rejection, native session restoration, scoped frame headers, and native settings forms. A second card with a different approved viewer must preserve the first card's cookie and REST nonce. Isolated checks cover account mismatch, expiry, and revoked-session replacement. It discovers an existing editable post/page for Gutenberg bootstrap checks rather than assuming a fixture ID. It creates, saves, reads, and deletes one temporary draft to verify native REST persistence. Existing content is not changed.

These checks prove protocol/server behavior, not visible chat rendering or browser cookie policy. For actual acceptance, reconnect `wp-native-admin` and open fresh `show_wp_admin` cards for:

1. `/wp-admin/`: native dashboard with surrounding admin chrome hidden.
2. `/wp-admin/edit.php`: native post list, search, and pagination.
3. `/wp-admin/options-writing.php`: native settings form and Save Changes.
4. An existing post/page edit URL: Gutenberg canvas, inserter, settings, and Save.
5. Any installed plugin's admin URL: its complete native screen, without product-specific crops.

Use a disposable draft for a save test. Change a paragraph, save through the native editor, reopen, and independently verify the saved content. Record visible interaction separately from protocol checks.

The optional loopback MCP Apps harness is `node experiments/native-admin/serve.mjs`, then `http://127.0.0.1:8890/`. Pass an encoded admin path in `?path=...` to select another screen. It exercises the SDK/frame chain in a separate browser, which can have different cookie or network policies from Codex. `&noframes=1` and `&noforms=1` are negative controls for sandbox restrictions.

## Refactor verification (2026-09-30)

The build, PHP/JavaScript syntax checks, viewer behavior suite, STDIO bridge suite, and live protocol suite pass. Dashboard, post list, media library, settings, Gutenberg initialization, HTTPS script/style origins, and temporary-draft REST persistence were verified. The in-app browser harness displayed the native Writing Settings form with Save Changes enabled. A subsequent Gutenberg harness navigation was blocked by the browser, so visible Gutenberg interaction and its Save button still require the chat acceptance check above.

After the review fixes, the expanded suite and installable ZIP build pass. The live HTTP checks verify resource discovery, ping, and a second viewer reusing the first viewer's cookie and REST nonce. Native draft creation, save, independent read, and deletion all pass with that shared session. Deployment environments, subdirectory URLs, Web Locks serialization, stalled-frame recovery, and account/expiry/revocation boundaries are covered by isolated fixtures; visible chat acceptance was not rerun for these fixes.
