# AI Fragments — Project Specification

**THIS IS A PROPOSAL DON'T TREAT AS FINAL SPECIFICATION OR TAKE IT FOR GRANTED. KEEP UPDATING THE SPECIFICATION AS THE PROJECT EVOLVES.**

**Status:** Proposed MVP · **Date:** 15 September 2026

## 1. Goal

Build a WordPress plugin that lets agents discover and present existing admin UI fragments inside a conversation, and perform supported changes without showing the UI.

* Reuse existing PHP-rendered interfaces and their behavior; avoid recreating every screen in JavaScript.
* Expose every agent-facing operation as a WordPress Ability.
* Connect abilities to agents through the WordPress MCP Adapter and present interactive UI through MCP Apps.
* Start with useful plugin configuration tasks. Expand through adapters for fragment families.
* Make security a release requirement. Automatic discovery must never automatically grant access or enable writes.

This is a compatibility framework with an initially small supported set. The MVP does not claim to work with most plugins.

## 2. User experience and MVP scope

| Request                                      | Expected behavior                                                                                                                    |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| “Change this product’s price to 10.”         | Resolve the product and invoke the pricing fragment’s declared update ability. Return the persisted price and currency.              |
| “I want to edit this product’s description.” | Show the existing description editor in chat, with clear product identity, current content, Save, and feedback.                      |
| “Show me the price editor again.”            | Reopen the fragment with fresh saved state. Do not silently reuse stale form data.                                                   |

The agent must resolve ambiguous products before changing them. Explicit Save commits manual edits; opening or typing in the embedded fragment must not create drafts or autosave content in the MVP.

### Initial supported fragments

| Fragment                        | Scope                                                                                                                                                                                                             |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| WooCommerce product pricing     | Regular price for an existing simple product with no active or scheduled sale. Show currency; validate WooCommerce price formatting. Other product types and sale pricing return an unsupported result initially. |
| WooCommerce product description | Full description of an existing simple product in the classic product editor. Reuse the existing editor and permitted formatting.                                                                                 |
| Classic Editor settings         | Default editor setting, as a second plugin integration to test whether the framework generalizes.                                                                                                                 |

The second plugin is a proposed implementation target. Confirm its rendering and save boundaries during the compatibility investigation.

### Deferred

* Dashboard widgets: future adapter and developer adoption path, not an MVP dependency.
* Broad wp-admin settings coverage, arbitrary plugin pages, and automatic support for unreviewed fragments.
* Variable products, bulk operations, product creation/deletion, payments, users/roles, credentials, and plugin installation.
* Multisite and the newer WooCommerce product editor.
* A universal browser automation agent or arbitrary PHP/SQL execution tool.

Future developer direction: register one fragment, its context and permissions, and abilities for its actions; make it usable in both wp-admin and agent conversations. The framework supplies discovery and rendering, while adapters declare the fragment-specific abilities that read or change data. A widget registration alone is insufficient.

## 3. Architecture

| Layer              | Responsibility                                                                                                        |
| ------------------ | --------------------------------------------------------------------------------------------------------------------- |
| Fragment registry  | Stable IDs, descriptions, supported contexts, adapter ownership, and supported operations.                            |
| Adapters           | Establish WordPress screen/object context, reuse rendering and assets, normalize supported values, validate and save. |
| Abilities          | Authoritative entry points for fragment discovery, rendering, and fragment-specific actions. Own permission checks and contracts.   |
| MCP integration    | Expose the selected abilities and associate the rendering tool with an MCP Apps UI resource.                          |
| Shared viewer      | Display the fragment, handle UI lifecycle, and invoke the fragment's declared abilities for reads and saves.          |

WordPress already maintains rendering registries for [meta boxes](https://developer.wordpress.org/reference/functions/do_meta_boxes/) and [Settings API fields](https://developer.wordpress.org/reference/functions/do_settings_fields/). Use these where appropriate, but do not assume all core/plugin controls participate or that a rendering callback has an independent save operation.

### Rendering strategy

* Investigate two reuse methods: a dedicated fragment document with the required admin context, and the original screen rendered with a restricted presentation of the selected fragment.
* Choose per adapter based on observed dependencies. Keep original controls, editor behavior, and required assets where feasible.
* Every interactive path in the embedded experience must use the ability-backed save contract. A hidden original form must not become an unrestricted alternate write path.
* Review original AJAX handlers, autosave, links, and dialogs as part of the adapter. Block unsupported actions explicitly.
* If a fragment cannot function within these boundaries, report it as unsupported rather than weakening isolation or quietly replacing it with a new form.

Saving is a separate compatibility problem: WooCommerce’s classic product handler can reset unrelated values when expected form fields are omitted. Adapters must perform scoped updates through supported application APIs, preserving validation and required hooks. Do not forward a partial form to the full product handler. [WooCommerce source](https://woocommerce.github.io/code-reference/files/woocommerce-includes-admin-meta-boxes-class-wc-meta-box-product-data.html)

## 4. Ability contracts

Names below are proposed. Register input/output schemas, execution callbacks, and permission callbacks through the [Abilities API](https://developer.wordpress.org/reference/functions/wp_register_ability/).

| Ability             | Inputs                                 | Output                                                                                                                                                 |
| ------------------- | -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `ui/list-fragments` | Optional plugin slug                   | Authorized, enabled fragment IDs, labels, descriptions, plugin slugs, required rendering inputs, and declared action abilities.                        |
| `ui/render-fragment` | Fragment ID and required render inputs | Display descriptor, authorized rendering information, fresh saved state, and any restricted session information needed by the client to show the UI. |

* Calling `ui/list-fragments` without a plugin slug returns all authorized supported fragments. The plugin slug is a filter only and never grants access or selects executable callbacks.
* Each fragment registration declares the abilities used for its supported reads and actions. These are ordinary, fragment-specific WordPress abilities rather than generic inspect or submit operations.
* Direct agent edits use the fragment's declared action ability without opening the UI. The shared viewer uses the same ability-backed action when the user explicitly saves.
* Existing domain abilities may be reused when they provide the required scope, validation, and permission model. Otherwise, the adapter must register a narrower action ability.
* Any rendering session identifies server state; possession alone does not authorize access.
* Fragment action abilities accept only their declared fields and operations. Reject unknown fields, unsupported operations, or mismatched context.
* Return consistent errors where applicable: `FORBIDDEN`, `NOT_FOUND`, `UNSUPPORTED_COMPONENT`, `UNSUPPORTED_CONTEXT`, `VALIDATION_ERROR`, `SESSION_EXPIRED`, `CONFLICT`, and `RENDER_FAILED`. Avoid leaking inaccessible object details.

## 5. MCP and UI delivery

* Use the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) for ability exposure. Configure a narrowly scoped server containing the two framework abilities and only the reviewed fragment action abilities; verify that generic discovery/execution cannot reach unrelated abilities.
* Add a shared, declared MCP Apps viewer resource and link the rendering tool to it through UI metadata. Treat this as integration work to verify, not an assumed feature of the installed adapter.
* The viewer calls abilities through the host bridge. Authentication resolves to the same effective WordPress user for agent calls and UI calls.
* Static viewer resources may be cached. Per-user fragment data, rendered content, and editing sessions must remain authenticated and must not be served from a shared cache.
* Clients without MCP Apps support receive structured results and an explicit UI-unavailable response. Do not claim a fragment was displayed.

[MCP Apps](https://apps.extensions.modelcontextprotocol.io/api/documents/overview.html) provides UI resources, sandboxed rendering, and communication. It does not automatically make WordPress cookies, scripts, AJAX requests, or form submissions portable.

**First technical gate:** demonstrate authenticated rendering and an ability-backed save in the actual target agent/client. Test browser restrictions and WordPress framing policies explicitly. Any embedded endpoint must be restricted to the fragment session; never relax framing for all of wp-admin.

## 6. Development environment

* Use project-local `@wordpress/env` with `wp-env start --runtime=playground`; pin tested WordPress, PHP, dependency, and plugin versions.
* Mount the project plugin and install the MCP Adapter. Use a WordPress version with the built-in Abilities API.
* Install WooCommerce, Classic Editor, and Yoast SEO. Activate only the required plugins per test profile; Yoast is available for later compatibility investigation.
* Seed reproducible products, descriptions, posts, and comments. Include unsupported product types and sale states as rejection fixtures.
* Create administrator, shop manager, editor, subscriber, and anonymous test cases. Give the agent a dedicated test identity.
* Capture email; use fake data and no live payment keys, webhooks, customer credentials, or production integrations.
* Provide start/reset/seed instructions, PHP/browser diagnostics, an MCP connection example with secret placeholders, and an MCP Apps viewer test harness.
* Keep local services on loopback where supported. If the agent is remote, document and test an authenticated HTTPS bridge with unique credentials; never expose the default wp-env login publicly.

The [Playground runtime](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/#experimental-wordpress-playground-runtime) currently uses SQLite and does not support `wp-env run`. The seed/reset workflow must work with that runtime. Run a conventional WordPress/MySQL compatibility check before claiming broader support.

## 7. Security requirements

### Authorization and execution

* Deny exposure by default. Enable only reviewed adapters, fragments, and actions.
* Check the effective user’s capabilities and object access on every ability call and every fragment-data/rendering request. MCP authentication alone is insufficient.
* Bind sessions to user, site, fragment, object, allowed actions, and expiry. Recheck permissions after role changes; reject replay by another identity.
* Resolve callbacks/assets from trusted adapter code. Never accept executable PHP, SQL, filesystem paths, callback names, or arbitrary fetch destinations from the agent.
* Installed plugin PHP remains trusted site code. Browser iframe isolation does not sandbox its server-side execution.

### Browser, data, and agent boundaries

* Keep admin cookies, application passwords, nonces, and other credentials out of model-visible results and logs. Never borrow a more privileged browser session for a less privileged MCP caller.
* Apply restrictive CSP and iframe permissions. Validate message origin, source window, session, and schema; reject arbitrary tool names/actions in messages.
* Preserve WordPress content sanitization. Do not execute scripts supplied through product descriptions or other editable content. Review adapter-loaded scripts separately from content.
* Treat returned labels, descriptions, and plugin content as untrusted data, never agent instructions or authorization.
* Return only declared, necessary fields. Do not expose whole option arrays, arbitrary post metadata, private diagnostics, or secrets through inspection.
* Cookie-authenticated endpoints require CSRF protection. Nonces and read-only annotations are not substitutes for authorization.

### Writes and audit

* Validate field allowlists, types, ranges, and object context on the server. Preserve unrelated settings and data.
* Detect stale state before saving and reject conflicting submissions. Define adapter-specific atomicity/locking; do not claim a non-atomic precheck prevents races.
* Deduplicate repeated request IDs within the authorized session. Avoid duplicate writes or side effects after retries.
* Record actor, fragment, object, action, changed field names, outcome, and correlation ID. Exclude secrets and full content by default.
* No MVP write is enabled until its authorization, validation, data-preservation, and concurrency tests pass.

## 8. Implementation milestones

1. **Environment and connection:** reproducible fixtures; agent can discover/call only the intended abilities; a test MCP Apps viewer opens.
2. **WooCommerce pricing:** existing regular-price UI renders; manual and agent edits use the same declared pricing ability and persist; unrelated product state remains intact.
3. **Product description:** original editor works with permitted formatting; no unintended autosave; reopening shows persisted content.
4. **Second plugin:** Classic Editor setting works through the same viewer and ability contracts, with an adapter providing plugin-specific behavior.
5. **Security and compatibility:** abuse tests pass, supported versions are documented, and unsupported cases fail explicitly.

Resolve rendering/authentication feasibility in milestone 1 before expanding the fragment catalog. A localhost-only rendering demo is not sufficient evidence for an external MCP client.

## 9. Acceptance criteria

| Area                | Required evidence                                                                                                                                  |
| ------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| End-to-end use      | Actual agent discovers a fragment, displays it in an MCP Apps client, and observes an ability-backed saved result.                                 |
| Direct editing      | Agent changes regular price through the fragment's declared pricing ability without opening UI; persisted value is verified after reload.           |
| UI reuse            | Original supported PHP controls/editor are used; no separate recreated product form.                                                               |
| Persistence         | Manual description edits and the second plugin’s setting survive reload and reopening.                                                             |
| Scope preservation  | Snapshot relevant product/settings state before and after saves. Only intended fields and documented derived fields change.                        |
| Access control      | Anonymous/unauthorized calls, changed object IDs, cross-user sessions, expired sessions, and revoked permissions are rejected without leakage.     |
| Browser security    | CSRF, script injection, forged messages, unexpected navigation, and content attempting to instruct the agent do not gain access or trigger writes. |
| Concurrency/retries | Concurrent editing produces a conflict instead of silently overwriting; retries do not duplicate effects.                                          |
| Failure behavior    | Unsupported fragments/contexts and clients without UI support produce explicit errors; no privileged fallback.                                     |

## 10. Deliverables

* Plugin source with fragment registry, adapters, the two framework abilities, reviewed fragment action abilities, and MCP integration.
* Shared MCP Apps viewer and documented support for the tested client.
* Reproducible wp-env/Playground setup, fixtures, test identities, and connection instructions.
* Functional and security tests with results, supported-version matrix, and documented limitations.
* Short adapter-author guide covering rendering context, assets, permissions, state, and saving.

Unresolved implementation decisions are the target MCP Apps client, authenticated UI-delivery mechanism, and the rendering strategy required by each initial fragment. Record the tested choices during the first milestones; do not replace these questions with assumptions.
