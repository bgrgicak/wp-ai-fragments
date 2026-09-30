<?php
/**
 * Plugin Name: WP AI Fragments
 * Description: Display native WordPress admin pages as interactive MCP Apps.
 * Version: 0.3.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

require_once __DIR__ . '/experiments/native-admin/native-admin.php';
