<?php
/**
 * Plugin Name:       WP AI Fragments
 * Description:       Exposes supported WordPress admin UI fragments through the Abilities API and MCP Apps.
 * Version:           0.1.0
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

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		wp_register_ability_category(
			'wp-ai-fragments',
			array(
				'label'       => __( 'WP AI Fragments', 'wp-ai-fragments' ),
				'description' => __( 'Supported WordPress admin UI fragments for agent clients.', 'wp-ai-fragments' ),
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		wp_register_ability(
			'wp-ai-fragments/health-check',
			array(
				'label'               => __( 'Check WP AI Fragments', 'wp-ai-fragments' ),
				'description'         => __( 'Confirms that the plugin and WordPress Abilities API are available.', 'wp-ai-fragments' ),
				'category'            => 'wp-ai-fragments',
				'execute_callback'    => static function (): array {
					return array(
						'status'            => 'ok',
						'plugin_version'    => '0.1.0',
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
					'public'   => true,
					'mcp'      => array( 'public' => true ),
					'readonly' => true,
				),
			)
		);
	}
);
