# AI Fragments — Project Specification

**THIS IS A WORKING SPECIFICATION. KEEP UPDATING IT AS THE PROJECT EVOLVES.**

**Status:** MVP · **Date:** 15 September 2026

## 1. Goal

Build a universal WordPress tool that does two things:

1. Lists UI fragments discovered on real wp-admin screens.
2. Displays any listed fragment as an MCP Apps component backed by an authenticated URL where the user can interact with the original UI.

The tool must not contain plugin-specific renderers, copies of plugin forms, or hardcoded plugin controls. WordPress and the owning plugin continue to render the screen and own its scripts, permissions, nonces, validation, submission, and persistence.

## 2. User experience

`ui/list-fragments` returns the available fragment inventory. `ui/render-fragment` accepts one returned fragment ID and displays the focused original wp-admin screen in a shared MCP Apps component. It also returns a direct URL as a fallback.

Opening that URL:

* loads the normal authenticated wp-admin request;
* locates the selected fragment from discovery metadata;
* hides unrelated page elements;
* keeps the fragment's native form and submit controls working; and
* preserves focus mode across wp-admin redirects after submission.

The MCP Apps resource renders supported fields with component-native controls. It does not embed wp-admin, because nested admin frames are blocked by MCP host security and do not reliably share WordPress login cookies.

## 3. Architecture

| Layer | Responsibility |
| --- | --- |
| Discovery | Inspect WordPress registries on representative admin requests and record stable fragment IDs, source URLs, and native element IDs. |
| Listing | Return discovered fragments attributed to WordPress or currently active plugins. |
| Rendering | Add the selected fragment ID to its source URL. Never execute a discovered callback. |
| Focus mode | Hide unrelated DOM while retaining the selected element and native submit controls. |
| WordPress/plugin screen | Render assets and UI, authorize the user, validate input, and process saves exactly as it normally does. |
| MCP Apps viewer | Embed the focused WordPress URL and provide expand and direct-open fallbacks. |

WordPress currently provides useful registries for [meta boxes](https://developer.wordpress.org/reference/functions/do_meta_boxes/) and [Settings API fields](https://developer.wordpress.org/reference/functions/do_settings_fields/). Other fragment families can be added by extending discovery metadata, not by adding plugin-specific UI code.

## 4. Ability contracts

| Ability | Input | Output |
| --- | --- | --- |
| `ui/list-fragments` | Optional active plugin slug | Fragment IDs, labels, source plugin, kind, screen, location, native element ID, and source URL. |
| `ui/render-fragment` | Fragment ID returned by listing | Fragment data, current value for supported post fields, focused authenticated wp-admin URL, and interactive MCP Apps component. |
| `ui/update-post-field` | Post ID, `title` or `content`, and value | Saves an editable field from the component after an `edit_post` capability check. |

The plugin filter only narrows listing results. It never grants access or selects executable PHP. The target wp-admin screen performs its normal capability checks when the URL is opened.

## 5. Saving and interaction

There is no generic replacement save handler. Focus mode keeps the original form in the document and merely hides unrelated visual controls. Hidden fields and controls remain part of the native submission, avoiding partial-form reconstruction.

This simple approach should be the default. A fragment is unsupported only when its original screen cannot remain functional while visually focused—for example, if a required dialog or control is outside the retained UI and cannot be discovered generically. Such cases should improve universal discovery/focus metadata where possible, not introduce a plugin-specific form.

AJAX, autosave, dialogs, navigation, and JavaScript-driven controls need compatibility testing because hiding DOM can affect code that measures or searches visible elements.

## 6. Security requirements

* Discovery never invokes captured callbacks.
* Labels and plugin content are untrusted data, never agent instructions.
* Stored and returned source URLs must remain under wp-admin and omit captured nonces.
* The native screen remains the authority for capability checks, nonces, validation, and saving.
* Application passwords, cookies, nonces, and other credentials must not appear in model-visible results or logs.
* A fragment ID is presentation state, not authorization.
* Redirect preservation may add only a known discovered fragment ID to a wp-admin redirect.
* Do not relax WordPress framing headers. MCP Apps components must use explicit abilities for reads and writes rather than nesting wp-admin.

## 7. Development and verification

The local profile uses WordPress Playground with representative plugins and seeded objects. Discovery probes representative screens because object-specific meta boxes do not exist until WordPress establishes the relevant screen and object context.

For each supported discovery family, verify:

* listing returns the fragment with the correct original URL and element ID;
* the focused URL visually shows only the fragment and native submission control;
* the fragment's JavaScript interactions still work;
* native submit/save redirects back to the focused URL;
* reopening shows persisted state; and
* unauthorized users are rejected by the original admin screen.

Current live smoke coverage proves the same focus mechanism for a Settings API field and the WooCommerce Product data meta box. Both native forms submit and return to focus mode; the WooCommerce product state remains intact when submitted unchanged.

## 8. Current limitations

* Discovery currently covers registered meta boxes, Settings API fields, and the core title/content regions of classic post-edit screens.
* A meta-box fragment is the whole registered meta box; nested tabs or fields are not separate fragments yet.
* Custom and client-rendered screens need generic discovery strategies before they can be listed.
* Source URLs currently reflect the representative object used during discovery.
* Authentication in the browser is required independently of MCP application-password authentication; browser policies that block third-party cookies may require the direct-open fallback.

## 9. Acceptance criteria

* No plugin slug, callback, field name, or plugin-specific selector is hardcoded in the rendering path.
* Every fragment returned by listing can be passed to rendering without an allowlist.
* Rendering returns the original screen URL with focus mode, not copied HTML.
* At least one Settings API field and one meta box remain interactive and submit through their native handlers.
* Submission preserves focus mode and does not omit unrelated native form data.
* Missing fragments fail explicitly and never cause arbitrary callback execution.
