<?php
/**
 * Plugin Name:       WP AI Fragments
 * Description:       Discovers WordPress admin UI fragments and renders them as focused native MCP Apps components.
 * Version:           0.2.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Requires Plugins:  mcp-adapter
 * Author:            WP AI Fragments contributors
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-ai-fragments
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WP_AI_FRAGMENTS_DISCOVERY_OPTION = 'wp_ai_fragments_discovered';
const WP_AI_FRAGMENTS_VIEWER_URI        = 'ui://wp-ai-fragments/fragment-editor-v2.html';
const WP_AI_FRAGMENTS_LEGACY_VIEWER_URI = 'ui://wp-ai-fragments/fragment-viewer.html';

/**
 * Return the current wp-admin URL without fragment-tool control parameters.
 */
function wp_ai_fragments_current_admin_url(): string {
	global $pagenow;

	$args = array();
	foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Capturing the location of a read-only discovery request.
		if ( ! is_scalar( $value ) || in_array( $key, array( 'wp_ai_fragments_discover', 'wp_ai_fragments_focus', '_wpnonce' ), true ) ) {
			continue;
		}

		$args[ sanitize_key( (string) $key ) ] = sanitize_text_field( wp_unslash( (string) $value ) );
	}

	return add_query_arg( $args, admin_url( $pagenow ?: 'admin.php' ) );
}

/**
 * Whether a registered Settings API page belongs to the current admin screen.
 */
function wp_ai_fragments_is_current_settings_page( string $page, string $screen_id ): bool {
	global $pagenow;

	if ( "options-{$page}.php" === $pagenow ) {
		return true;
	}

	$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Identifying the current read-only discovery screen.

	return $page === $current_page || $page === $screen_id || str_ends_with( $screen_id, '_page_' . $page );
}

/**
 * Attribute a registered callback to WordPress core or an installed plugin.
 *
 * @param mixed $callback Registered WordPress callback.
 */
function wp_ai_fragments_callback_plugin( $callback ): string {
	try {
		if ( is_array( $callback ) && 2 === count( $callback ) ) {
			$reflection = new ReflectionMethod( $callback[0], (string) $callback[1] );
		} elseif ( is_string( $callback ) && str_contains( $callback, '::' ) ) {
			$reflection = new ReflectionMethod( $callback );
		} elseif ( is_string( $callback ) || $callback instanceof Closure ) {
			$reflection = new ReflectionFunction( $callback );
		} elseif ( is_object( $callback ) && is_callable( $callback ) ) {
			$reflection = new ReflectionMethod( $callback, '__invoke' );
		} else {
			return 'wordpress';
		}

		$file = $reflection->getFileName();
	} catch ( ReflectionException $exception ) {
		return 'wordpress';
	}

	if ( ! $file ) {
		return 'wordpress';
	}

	$plugin_dir = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
	$file       = wp_normalize_path( $file );

	if ( ! str_starts_with( $file, $plugin_dir ) ) {
		return 'wordpress';
	}

	$relative = substr( $file, strlen( $plugin_dir ) );
	$parts    = explode( '/', $relative );

	return count( $parts ) > 1 ? sanitize_key( $parts[0] ) : sanitize_key( basename( $parts[0], '.php' ) );
}

/**
 * Capture fragments registered on a deliberately probed admin request.
 */
function wp_ai_fragments_capture_current_screen(): void {
	if ( ! isset( $_GET['wp_ai_fragments_discover'] ) || '1' !== $_GET['wp_ai_fragments_discover'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	global $wp_meta_boxes, $wp_settings_fields;

	$discovered = get_option( WP_AI_FRAGMENTS_DISCOVERY_OPTION, array() );
	$screen     = get_current_screen();
	$screen_id  = $screen ? $screen->id : 'unknown';
	$source_url = wp_ai_fragments_current_admin_url();

	if ( isset( $wp_meta_boxes[ $screen_id ] ) && is_array( $wp_meta_boxes[ $screen_id ] ) ) {
		foreach ( $wp_meta_boxes[ $screen_id ] as $context => $priorities ) {
			foreach ( (array) $priorities as $boxes ) {
				foreach ( (array) $boxes as $box_id => $box ) {
					if ( ! is_array( $box ) || empty( $box['callback'] ) ) {
						continue;
					}

					$id                = sprintf( 'meta-box/%s/%s/%s', $screen_id, $context, $box_id );
					$discovered[ $id ] = array(
						'id'         => $id,
						'label'      => trim( wp_strip_all_tags( (string) $box['title'] ) ),
						'plugin'     => wp_ai_fragments_callback_plugin( $box['callback'] ),
						'kind'       => 'meta-box',
						'screen'     => $screen_id,
						'location'   => (string) $context,
						'element_id' => (string) $box_id,
						'source_url' => $source_url,
					);
				}
			}
		}
	}

	if ( $screen && 'post' === $screen->base && in_array( $GLOBALS['pagenow'] ?? '', array( 'post.php', 'post-new.php' ), true ) ) {
		$post_type_object = get_post_type_object( $screen->post_type );
		$post_type_label  = $post_type_object ? $post_type_object->labels->singular_name : __( 'Post', 'wp-ai-fragments' );
		$post_fields      = array(
			'title'   => array(
				'label'      => sprintf( __( '%s title', 'wp-ai-fragments' ), $post_type_label ),
				'element_id' => 'titlediv',
			),
			'content' => array(
				'label'      => sprintf( __( '%s content', 'wp-ai-fragments' ), $post_type_label ),
				'element_id' => 'postdivrich',
			),
		);

		foreach ( $post_fields as $field_name => $field ) {
			$id                = sprintf( 'post-field/%s/%s', $screen_id, $field_name );
			$discovered[ $id ] = array(
				'id'         => $id,
				'label'      => $field['label'],
				'plugin'     => 'wordpress',
				'kind'       => 'post-field',
				'screen'     => $screen_id,
				'location'   => 'normal',
				'element_id' => $field['element_id'],
				'source_url' => $source_url,
			);
		}
	}

	foreach ( (array) $wp_settings_fields as $page => $sections ) {
		if ( ! wp_ai_fragments_is_current_settings_page( (string) $page, $screen_id ) ) {
			continue;
		}

		foreach ( (array) $sections as $section => $fields ) {
			foreach ( (array) $fields as $field_id => $field ) {
				if ( ! is_array( $field ) || empty( $field['callback'] ) ) {
					continue;
				}

				$id                = sprintf( 'settings-field/%s/%s/%s', $page, $section, $field_id );
				$discovered[ $id ] = array(
					'id'         => $id,
					'label'      => trim( wp_strip_all_tags( (string) $field['title'] ) ),
					'plugin'     => wp_ai_fragments_callback_plugin( $field['callback'] ),
					'kind'       => 'settings-field',
					'screen'     => (string) $page,
					'location'   => (string) $section,
					'element_id' => isset( $field['args']['label_for'] ) ? (string) $field['args']['label_for'] : (string) $field_id,
					'source_url' => $source_url,
				);
			}
		}
	}

	update_option( WP_AI_FRAGMENTS_DISCOVERY_OPTION, $discovered, false );
}
add_action( 'admin_footer', 'wp_ai_fragments_capture_current_screen', PHP_INT_MAX );

/**
 * List fragments available to the current user.
 *
 * @param string|null $plugin_filter Optional plugin slug filter.
 * @return array<int, array<string, mixed>>
 */
function wp_ai_fragments_list( ?string $plugin_filter = null ): array {
	$available      = array();
	$active_plugins = array_map(
		static function ( string $plugin_file ): string {
			$parts = explode( '/', $plugin_file );
			return count( $parts ) > 1 ? sanitize_key( $parts[0] ) : sanitize_key( basename( $parts[0], '.php' ) );
		},
		(array) get_option( 'active_plugins', array() )
	);

	foreach ( (array) get_option( WP_AI_FRAGMENTS_DISCOVERY_OPTION, array() ) as $fragment ) {
		if ( ! is_array( $fragment ) || empty( $fragment['id'] ) || empty( $fragment['plugin'] ) || empty( $fragment['element_id'] ) || empty( $fragment['source_url'] ) ) {
			continue;
		}

		if ( $plugin_filter && $fragment['plugin'] !== $plugin_filter ) {
			continue;
		}

		if ( 'wordpress' !== $fragment['plugin'] && ! in_array( $fragment['plugin'], $active_plugins, true ) ) {
			continue;
		}

		$available[] = $fragment;
	}

	usort(
		$available,
		static function ( array $left, array $right ): int {
			return strcmp( $left['id'], $right['id'] );
		}
	);

	return $available;
}

/**
 * Find a discovered fragment by its stable ID.
 *
 * @return array<string, mixed>|null
 */
function wp_ai_fragments_find( string $fragment_id ): ?array {
	foreach ( wp_ai_fragments_list() as $fragment ) {
		if ( $fragment_id === $fragment['id'] ) {
			return $fragment;
		}
	}

	return null;
}

/**
 * Return the fragment's original authenticated wp-admin URL in focus mode.
 *
 * @param string $fragment_id Stable discovered fragment ID.
 */
function wp_ai_fragments_view_url( string $fragment_id ): string {
	$fragment = wp_ai_fragments_find( $fragment_id );
	if ( ! $fragment || empty( $fragment['source_url'] ) ) {
		return '';
	}

	return add_query_arg( 'wp_ai_fragments_focus', $fragment_id, (string) $fragment['source_url'] );
}

/**
 * Resolve one discovered fragment to its native interactive admin URL.
 *
 * @param string $fragment_id Stable discovered fragment ID.
 * @return array<string, string>|WP_Error
 */
function wp_ai_fragments_render( string $fragment_id ) {
	$fragment = wp_ai_fragments_find( $fragment_id );
	if ( ! $fragment ) {
		return new WP_Error( 'not_found', __( 'The fragment is not available.', 'wp-ai-fragments' ) );
	}

	$result = array(
		'fragment_id' => $fragment_id,
		'label'       => (string) $fragment['label'],
		'url'         => wp_ai_fragments_view_url( $fragment_id ),
		'mode'        => 'link',
		'post_id'     => 0,
		'field'       => '',
		'value'       => '',
		'editable'    => false,
	);

	if ( 'post-field' !== $fragment['kind'] ) {
		return $result;
	}

	$query = array();
	parse_str( (string) wp_parse_url( (string) $fragment['source_url'], PHP_URL_QUERY ), $query );
	$post_id = isset( $query['post'] ) ? absint( $query['post'] ) : 0;
	$field   = (string) basename( $fragment_id );
	if ( ! $post_id || ! in_array( $field, array( 'title', 'content' ), true ) || ! get_post( $post_id ) ) {
		return $result;
	}

	$result['mode']     = 'post-field';
	$result['post_id']  = $post_id;
	$result['field']    = $field;
	$result['value']    = (string) get_post_field( 'title' === $field ? 'post_title' : 'post_content', $post_id, 'raw' );
	$result['editable'] = current_user_can( 'edit_post', $post_id );

	return $result;
}

/**
 * Update a post field displayed by the MCP Apps component.
 *
 * @return array<string, int|string>|WP_Error
 */
function wp_ai_fragments_update_post_field( int $post_id, string $field, string $value ) {
	$post = get_post( $post_id );
	if ( ! $post || ! in_array( $field, array( 'title', 'content' ), true ) ) {
		return new WP_Error( 'not_found', __( 'The editable post field is not available.', 'wp-ai-fragments' ) );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', __( 'You do not have permission to edit this post.', 'wp-ai-fragments' ) );
	}

	$post_data = array( 'ID' => $post_id );
	if ( 'title' === $field ) {
		$post_data['post_title'] = sanitize_text_field( $value );
	} else {
		$post_data['post_content'] = wp_kses_post( $value );
	}

	$updated = wp_update_post( wp_slash( $post_data ), true );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	return array(
		'post_id'      => $post_id,
		'field'        => $field,
		'value'        => (string) get_post_field( 'title' === $field ? 'post_title' : 'post_content', $post_id, 'raw' ),
		'modified_gmt' => (string) get_post_field( 'post_modified_gmt', $post_id, 'raw' ),
	);
}

/**
 * Return the fragment requested for the current admin screen.
 *
 * @return array<string, mixed>|null
 */
function wp_ai_fragments_focused_fragment(): ?array {
	if ( empty( $_GET['wp_ai_fragments_focus'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presentation-only query parameter.
		return null;
	}

	return wp_ai_fragments_find( sanitize_text_field( wp_unslash( $_GET['wp_ai_fragments_focus'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

add_action(
	'admin_head',
	static function (): void {
		if ( ! wp_ai_fragments_focused_fragment() ) {
			return;
		}
		?>
		<style>
			body.wp-ai-fragments-focus { opacity: 0; }
			body.wp-ai-fragments-focus.wp-ai-fragments-ready { opacity: 1; }
			body.wp-ai-fragments-focus .wp-ai-fragments-hidden { display: none !important; }
			html.wp-toolbar { padding-top: 0; }
			body.wp-ai-fragments-focus #wpcontent { margin-left: 0; padding: 24px; }
			body.wp-ai-fragments-focus #poststuff #post-body.columns-2 {
				display: flex;
				flex-direction: column;
				margin-right: 0;
			}
			body.wp-ai-fragments-focus #poststuff #post-body.columns-2 #post-body-content {
				order: 0;
				width: 100%;
			}
			body.wp-ai-fragments-focus #poststuff #post-body.columns-2 #postbox-container-2 {
				order: 1;
				float: none;
				width: 100%;
			}
			body.wp-ai-fragments-focus #poststuff #post-body.columns-2 #postbox-container-1 {
				order: 2;
				float: none;
				width: 100%;
				margin-right: 0;
			}
			body.wp-ai-fragments-focus #poststuff #post-body.columns-2 #side-sortables {
				min-height: 0;
				width: 100%;
			}
			body.wp-ai-fragments-focus #submitdiv { box-sizing: border-box; width: 100%; }
		</style>
		<?php
	}
);

add_action(
	'admin_footer',
	static function (): void {
		$fragment = wp_ai_fragments_focused_fragment();
		if ( ! $fragment ) {
			return;
		}
		?>
		<script>
		(() => {
			const fragment = <?php echo wp_json_encode( $fragment ); ?>;
			let target = document.getElementById(fragment.element_id);

			if (target && fragment.kind === 'settings-field') {
				target = target.closest('tr') || target;
			}
			if (!target && fragment.kind === 'settings-field') {
				target = [...document.querySelectorAll('tr')].find((row) =>
					row.querySelector('th')?.textContent.trim() === fragment.label
				);
			}

			if (!target) {
				console.warn('WP AI Fragments could not find', fragment.id);
				document.body.classList.add('wp-ai-fragments-ready');
				return;
			}

			const roots = [target];
			const form = target.closest('form');
			if (form) {
				form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((control) => {
					roots.push(control.closest('p.submit') || control);
				});

				const focusInput = document.createElement('input');
				focusInput.type = 'hidden';
				focusInput.name = 'wp_ai_fragments_focus';
				focusInput.value = fragment.id;
				form.append(focusInput);
			}

			const visible = new Set();
			for (const root of roots) {
				for (let node = root; node && node !== document.body; node = node.parentElement) {
					visible.add(node);
				}
			}

			document.body.querySelectorAll('*').forEach((node) => {
				if (!visible.has(node) && !roots.some((root) => root.contains(node))) {
					node.classList.add('wp-ai-fragments-hidden');
				}
			});

			document.body.classList.add('wp-ai-fragments-ready');
			target.scrollIntoView({ block: 'start' });
		})();
		</script>
		<?php
	},
	PHP_INT_MAX
);

add_filter(
	'admin_body_class',
	static function ( string $classes ): string {
		if ( wp_ai_fragments_focused_fragment() ) {
			$classes .= ' wp-ai-fragments-focus';
		}

		return $classes;
	}
);

add_filter(
	'wp_redirect',
	static function ( string $location ): string {
		if ( empty( $_POST['wp_ai_fragments_focus'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Native form handlers remain responsible for authorization and CSRF protection.
			return $location;
		}

		$fragment_id = sanitize_text_field( wp_unslash( $_POST['wp_ai_fragments_focus'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$path        = (string) wp_parse_url( $location, PHP_URL_PATH );
		if ( ! wp_ai_fragments_find( $fragment_id ) || ! str_contains( $path, '/wp-admin/' ) ) {
			return $location;
		}

		return add_query_arg( 'wp_ai_fragments_focus', $fragment_id, $location );
	}
);

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		wp_register_ability_category(
			'wp-ai-fragments',
			array(
				'label'       => __( 'WP AI Fragments', 'wp-ai-fragments' ),
				'description' => __( 'Discovered WordPress admin UI fragments for agent clients.', 'wp-ai-fragments' ),
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		wp_register_ability(
			'ui/render-fragment',
			array(
				'label'               => __( 'Render an admin UI fragment', 'wp-ai-fragments' ),
				'description'         => __( 'Renders a discovered fragment as an MCP Apps component. Supported post fields can be edited directly; other fragments include their focused WordPress admin URL. Use this when a user asks to view or edit a listed fragment.', 'wp-ai-fragments' ),
				'category'            => 'wp-ai-fragments',
				'execute_callback'    => static function ( array $input ) {
					return wp_ai_fragments_render( $input['fragment_id'] );
				},
				'permission_callback' => static function ( array $input ) {
					if ( ! wp_ai_fragments_find( $input['fragment_id'] ) ) {
						return new WP_Error( 'not_found', __( 'The fragment is not available.', 'wp-ai-fragments' ) );
					}

					return current_user_can( 'read' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'fragment_id' => array(
							'type'        => 'string',
							'description' => __( 'A stable fragment ID returned by ui/list-fragments.', 'wp-ai-fragments' ),
						),
					),
					'required'             => array( 'fragment_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'fragment_id' => array( 'type' => 'string' ),
						'label'       => array( 'type' => 'string' ),
						'url'         => array( 'type' => 'string', 'format' => 'uri' ),
						'mode'        => array( 'type' => 'string', 'enum' => array( 'post-field', 'link' ) ),
						'post_id'     => array( 'type' => 'integer', 'minimum' => 0 ),
						'field'       => array( 'type' => 'string' ),
						'value'       => array( 'type' => 'string' ),
						'editable'    => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'fragment_id', 'label', 'url', 'mode', 'post_id', 'field', 'value', 'editable' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'public'       => true,
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'_meta'  => array(
							'ui' => array( 'resourceUri' => WP_AI_FRAGMENTS_VIEWER_URI ),
						),
					),
					'readonly'     => true,
				),
			)
		);

		wp_register_ability(
			'ui/update-post-field',
			array(
				'label'               => __( 'Update a post field from a fragment', 'wp-ai-fragments' ),
				'description'         => __( 'Updates a supported post title or content field from the WP AI Fragments MCP Apps component.', 'wp-ai-fragments' ),
				'category'            => 'wp-ai-fragments',
				'execute_callback'    => static function ( array $input ) {
					return wp_ai_fragments_update_post_field( (int) $input['post_id'], (string) $input['field'], (string) $input['value'] );
				},
				'permission_callback' => static function ( array $input ): bool {
					return current_user_can( 'edit_post', (int) $input['post_id'] );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'field'   => array( 'type' => 'string', 'enum' => array( 'title', 'content' ) ),
						'value'   => array( 'type' => 'string' ),
					),
					'required'             => array( 'post_id', 'field', 'value' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'      => array( 'type' => 'integer' ),
						'field'        => array( 'type' => 'string' ),
						'value'        => array( 'type' => 'string' ),
						'modified_gmt' => array( 'type' => 'string' ),
					),
					'required'             => array( 'post_id', 'field', 'value', 'modified_gmt' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'public'       => true,
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'_meta'  => array(
							'ui' => array( 'visibility' => array( 'app' ) ),
						),
					),
				),
			)
		);

		wp_register_ability(
			'ui/list-fragments',
			array(
				'label'               => __( 'List admin UI fragments', 'wp-ai-fragments' ),
				'description'         => __( 'Lists admin UI fragments discovered from real WordPress admin screens.', 'wp-ai-fragments' ),
				'category'            => 'wp-ai-fragments',
				'execute_callback'    => static function ( array $input ): array {
					$plugin_filter = isset( $input['plugin'] ) ? sanitize_key( $input['plugin'] ) : null;

					return array(
						'fragments' => wp_ai_fragments_list( $plugin_filter ),
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'read' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => __( 'Optional installed plugin slug.', 'wp-ai-fragments' ),
							'pattern'     => '^[a-z0-9][a-z0-9-]*$',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'fragments' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'id'       => array( 'type' => 'string' ),
									'label'    => array( 'type' => 'string' ),
									'plugin'   => array( 'type' => 'string' ),
									'kind'     => array(
										'type' => 'string',
									'enum' => array( 'meta-box', 'settings-field', 'post-field' ),
									),
									'screen'     => array( 'type' => 'string' ),
									'location'   => array( 'type' => 'string' ),
									'element_id' => array( 'type' => 'string' ),
									'source_url' => array( 'type' => 'string', 'format' => 'uri' ),
								),
								'required'             => array( 'id', 'label', 'plugin', 'kind', 'screen', 'location', 'element_id', 'source_url' ),
								'additionalProperties' => false,
							),
						),
					),
					'required'             => array( 'fragments' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'public'       => true,
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'readonly'     => true,
				),
			)
		);

		wp_register_ability(
			'wp-ai-fragments/health-check',
			array(
				'label'               => __( 'Check WP AI Fragments', 'wp-ai-fragments' ),
				'description'         => __( 'Confirms that the plugin and WordPress Abilities API are available.', 'wp-ai-fragments' ),
				'category'            => 'wp-ai-fragments',
				'execute_callback'    => static function (): array {
					return array(
						'status'            => 'ok',
						'plugin_version'    => '0.2.0',
						'wordpress_version' => get_bloginfo( 'version' ),
					);
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'read' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'status'            => array( 'type' => 'string' ),
						'plugin_version'    => array( 'type' => 'string' ),
						'wordpress_version' => array( 'type' => 'string' ),
					),
					'required'             => array( 'status', 'plugin_version', 'wordpress_version' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'public'       => true,
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'readonly'     => true,
				),
			)
		);
	}
);

/**
 * Register a narrow MCP Apps server with the shared fragment viewer.
 *
 * @param \WP\MCP\Core\McpAdapter $adapter MCP Adapter singleton.
 */
function wp_ai_fragments_register_mcp_server( $adapter ): void {
	if ( ! class_exists( '\\WP\\MCP\\Domain\\Resources\\McpResource' ) ) {
		return;
	}

	$viewer_path  = __DIR__ . '/build/fragment-viewer.html';
	$admin_parts  = wp_parse_url( admin_url() );
	$admin_origin = '';
	if ( isset( $admin_parts['scheme'], $admin_parts['host'] ) ) {
		$admin_origin = $admin_parts['scheme'] . '://' . $admin_parts['host'];
		if ( isset( $admin_parts['port'] ) ) {
			$admin_origin .= ':' . $admin_parts['port'];
		}
	}
	$resources = array();
	foreach (
		array(
			WP_AI_FRAGMENTS_VIEWER_URI        => 'wp-ai-fragments-editor-v2',
			WP_AI_FRAGMENTS_LEGACY_VIEWER_URI => 'wp-ai-fragments-viewer-legacy',
		) as $resource_uri => $resource_name
	) {
		$resource = \WP\MCP\Domain\Resources\McpResource::fromArray(
			array(
				'uri'         => $resource_uri,
				'name'        => $resource_name,
				'title'       => __( 'WordPress UI fragment editor', 'wp-ai-fragments' ),
				'description' => __( 'Displays supported WordPress fields as native MCP Apps controls and links other fragments to their focused admin interface.', 'wp-ai-fragments' ),
				'mimeType'    => 'text/html;profile=mcp-app',
				'handler'     => static function () use ( $viewer_path, $admin_origin, $resource_uri ) {
					if ( ! is_readable( $viewer_path ) ) {
						return new WP_Error( 'viewer_unavailable', __( 'The fragment viewer has not been built.', 'wp-ai-fragments' ) );
					}

					return array(
						array(
							'uri'      => $resource_uri,
							'mimeType' => 'text/html;profile=mcp-app',
							'text'     => (string) file_get_contents( $viewer_path ),
							'_meta'    => array(
								'ui'                    => array( 'prefersBorder' => true ),
								'openai/widgetCSP'      => array(
									'redirect_domains' => $admin_origin ? array( $admin_origin ) : array(),
								),
								'openai/widgetDescription' => __( 'An interactive WordPress field editor with a focused wp-admin fallback.', 'wp-ai-fragments' ),
							),
						),
					);
				},
				'permission'  => static function (): bool {
					return current_user_can( 'read' );
				},
			)
		);

		if ( ! is_wp_error( $resource ) ) {
			$resources[] = $resource;
		}
	}

	if ( ! $resources ) {
		return;
	}

	$adapter->create_server(
		'wp-ai-fragments',
		'wp-ai-fragments/v1',
		'mcp',
		'WP AI Fragments',
		'Discovered WordPress admin UI fragments rendered through a shared MCP Apps editor.',
		'v0.2.0',
		array( \WP\MCP\Transport\HttpTransport::class ),
		\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
		\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
		array( 'ui/list-fragments', 'ui/render-fragment', 'ui/update-post-field' ),
		$resources,
		array()
	);
}
add_action( 'mcp_adapter_init', 'wp_ai_fragments_register_mcp_server', 20 );
