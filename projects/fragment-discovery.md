# Fragment discovery

`ui/list-fragments` enumerates fragments found in WordPress's admin registries on representative real requests. Each result records enough generic metadata to reopen its original screen in focus mode.

## Proposed pipeline

1. **Inventory candidate families on a real admin request.** After WordPress and active plugins have initialized the relevant screen, inspect:
   * `$wp_meta_boxes` for meta boxes, including screen, context, priority, ID, title, callback, and callback arguments.
   * `$wp_settings_sections` and `$wp_settings_fields` for Settings API sections and fields.
   * the core title and content regions on classic post-edit screens.
   * `$menu`, `$submenu`, `$_registered_pages`, and callbacks attached to page hooks for plugin-owned admin pages.
   * registered blocks and editor panels separately; they are not represented by the PHP meta-box registry.
2. **Attribute the callback.** Resolve callable source files with `ReflectionFunction` or `ReflectionMethod`, then map paths under `WP_PLUGIN_DIR` to an installed plugin slug. Treat labels and callback metadata as untrusted diagnostics.
3. **Store normalized metadata.** Use stable IDs containing the registry family, screen, location, and registered ID. Store labels, attributed plugin slugs, native element IDs, and nonce-free source URLs without invoking callbacks.
4. **List active results.** `ui/list-fragments` returns the inventory and filters out entries attributed to inactive plugins. Its optional `plugin` input narrows results by source slug.
5. **Add more discovery sources later.** Registered blocks, custom PHP screens, and client-rendered regions need their own inventory mechanisms because they do not appear in the meta-box or Settings API globals.

Do not call arbitrary discovered callbacks. Rendering reopens the original screen, and any save uses that screen's native handler.

## Test-site observations

The pinned local profile currently includes WooCommerce, Classic Editor, Yoast SEO, Advanced Custom Fields, and Contact Form 7. A seeded simple product provides object context.

* The product edit request registers `woocommerce-product-data`, `postexcerpt`, product images, product taxonomy boxes, reviews, and the Yoast SEO meta box. WooCommerce pricing is nested inside `woocommerce-product-data`, so the current fragment is the whole Product data box rather than a hardcoded price editor.
* The classic product editor also exposes the core title and full-content regions as `post-field/product/title` and `post-field/product/content`, allowing prompts about a product's name or long description to resolve without treating those regions as plugin-specific metaboxes.
* Classic Editor contributes `classic-editor-1` and `classic-editor-2` through the Settings API on the Writing screen.
* Contact Form 7 exposes a mixed custom editor: a Form post box plus Mail, Messages, and Additional Settings tabs. Those regions use post-box-like markup but are not registered in `$wp_meta_boxes`, so the current PHP-registry inventory does not list them.
* Yoast's settings screen is client-rendered and has no server-rendered post boxes, while its post/product SEO panel does register a meta box. DOM or PHP-registry discovery alone therefore cannot cover both experiences.
* ACF uses meta boxes for its own field-group, post-type, and taxonomy editors and dynamically adds field-group boxes to matching object screens. Candidate discovery must run with representative objects and location rules, not just a plugin activation check.

These differences require additional generic discovery families for custom and client-rendered screens. They do not justify plugin-specific renderers.
