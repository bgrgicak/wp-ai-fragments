> Historical checkpoint before the database restart and Jurassic Tube fix. Superseded by [the final demo findings](../LEARNINGS.md). Product 13 is no longer the WooCommerce fixture.

# Product 13 MCP Apps check — 2026-09-21

Result: native wp-admin renders in the local MCP Apps test host. Inline rendering in the current Codex conversation is **not verified**.

Demo: http://127.0.0.1:8890/?path=%2Fwp-admin%2Fpost.php%3Fpost%3D13%26action%3Dedit

1. `python3 experiments/native-admin/test-protocol.py` passed all 17 checks, including resource MIME/CSP metadata, private handoff, replay rejection, native authentication/nonces, and preservation of ordinary admin SAMEORIGIN protection.
2. Codex's in-app browser loaded the test host using the real MCP Apps SDK handshake. The frame chain was host → sandbox → MCP resource → original `/wp-admin/post.php?post=13&action=edit` → native TinyMCE. The visible Product description contained “A seeded product for exercising real wp-admin fragment discovery.” This matched WooCommerce's authenticated product 13 response. No product content was changed. WordPress showed an existing edit lock belonging to admin; it was not taken over.
3. The same component with `&noframes=1` completed its MCP Apps handshake, but the WordPress frame displayed “This content is blocked. Contact the site owner to fix the issue.” Thus a successful handshake alone does not prove the native document can render.

The browser accepted the partitioned transport session, but also accepted the unpartitioned control cookie. This run does not prove behavior with third-party cookies blocked or production HTTPS.

## Inline blocker

The configured `scripts/mcp-proxy.sh` points to `/wp-json/wp-ai-fragments/v1/mcp`, which currently returns HTTP 404 `rest_no_route`. An actual MCP SDK client connected to that bridge failed tools/list with “WordPress connection failed during initialization.” No wp-ai-fragments tools are available to this conversation.

The proof endpoint `/wp-json/aif-proof/v1/mcp` is working, but its ancestor policy and handoff origin checks are fixed to the local test host. Merely pointing Codex at this endpoint would not establish compatibility with Codex's actual component sandbox. The native resource must be invoked and inspected in that host before claiming inline success. A screenshot, browser tab, custom editor, or visualization is not a substitute for that test.
