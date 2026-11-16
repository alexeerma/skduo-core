<?php
/**
 * Plugin Name: SK DUO Core
 * Description: Core content types, data helpers, and REST API endpoints for the SK DUO website.
 * Version: 1.0.9
 * Author: Aleksander Eerma
 * Author URI: https://alexeerma.ee/
 * Text Domain: skduo
 */

defined( 'ABSPATH' ) || exit;

define( 'SKDUO_CORE_VERSION', '1.0.9' );
define( 'SKDUO_CORE_PATH', plugin_dir_path( __FILE__ ) );

function skduo_core_load_files(): void {
	$files = [
		'includes/helpers.php',
		'includes/cpt-mang.php',
		'includes/match-admin.php',
		'includes/cpt-meeskond.php',
		'includes/cpt-mangija.php',
		'includes/cpt-noored.php',
		'includes/cpt-toetaja.php',
		'includes/rest-api.php',
		'includes/dataproject-security.php',
		'includes/dataproject.php',
		'includes/dataproject-admin.php',
	];

	foreach ( $files as $file ) {
		require_once SKDUO_CORE_PATH . $file;
	}
}

skduo_core_load_files();

register_activation_hook( __FILE__, function (): void {
	update_option( 'skduo_core_flush_rewrites', '1', false );
} );

register_deactivation_hook( __FILE__, function (): void {
	wp_clear_scheduled_hook( 'skduo_dp_hourly_sync' );
	flush_rewrite_rules( false );
} );

add_action( 'init', function (): void {
	if ( '1' !== get_option( 'skduo_core_flush_rewrites' ) ) {
		return;
	}

	flush_rewrite_rules( false );
	delete_option( 'skduo_core_flush_rewrites' );
}, 99 );
