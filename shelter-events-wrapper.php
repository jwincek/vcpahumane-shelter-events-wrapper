<?php
/**
 * Plugin Name: Shelter Events Wrapper
 * Description: Manages recurring shelter events (BINGO, clinics, etc.) as a custom post type with a staff-friendly UI, generating TEC events automatically via WP-Cron.
 * Version:     2.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author:      VCPA Humane Society
 * Text Domain: shelter-events
 * License:     GPL-2.0-or-later
 *
 * @package Shelter_Events
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Plugin constants ──────────────────────────────────────────────────────────
define( 'SHELTER_EVENTS_VERSION', '2.0.0' );
define( 'SHELTER_EVENTS_FILE', __FILE__ );
define( 'SHELTER_EVENTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHELTER_EVENTS_URL', plugin_dir_url( __FILE__ ) );

// ── Autoloader ────────────────────────────────────────────────────────────────
spl_autoload_register( function ( string $class ): void {
	// Namespaced: Shelter_Events\Core\Config → includes/core/class-config.php
	if ( str_starts_with( $class, 'Shelter_Events\\' ) ) {
		$relative = substr( $class, strlen( 'Shelter_Events\\' ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$dir      = strtolower( implode( '/', $parts ) );
		$file     = SHELTER_EVENTS_DIR . 'includes/' . $dir . '/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
			return;
		}
	}

	// Legacy flat classes: Shelter_Events_Admin → includes/class-shelter-events-admin.php
	if ( str_starts_with( $class, 'Shelter_Events_' ) ) {
		$file = SHELTER_EVENTS_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
} );

// ── Activation ────────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, function (): void {
	// Load config for schema defaults and seed data.
	\Shelter_Events\Core\Config::init( SHELTER_EVENTS_DIR . 'config/' );

	// Register the CPT early so rewrite rules flush correctly.
	\Shelter_Events\Core\Program_CPT::register_post_type();
	\Shelter_Events\Core\Taxonomy_Registry::register_taxonomies();

	// Import JSON-defined programs as CPT posts (only on first activation).
	\Shelter_Events\Core\Program_Importer::import_from_config();

	// Schedule cron.
	if ( ! wp_next_scheduled( 'shelter_events_generate_recurring' ) ) {
		wp_schedule_event( time(), 'daily', 'shelter_events_generate_recurring' );
	}

	flush_rewrite_rules();
} );

// ── Deactivation ──────────────────────────────────────────────────────────────
register_deactivation_hook( __FILE__, function (): void {
	wp_clear_scheduled_hook( 'shelter_events_generate_recurring' );
	flush_rewrite_rules();
} );

// ── Initialization ────────────────────────────────────────────────────────────
function shelter_events_init(): void {
	// Bail early if The Events Calendar is not active.
	if ( ! class_exists( 'Tribe__Events__Main' ) ) {
		add_action( 'admin_notices', function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Shelter Events Wrapper requires The Events Calendar plugin to be installed and activated.', 'shelter-events' )
			);
		} );
		return;
	}

	// 1. Load JSON config (for schema defaults, generation settings, seed data reference).
	\Shelter_Events\Core\Config::init( SHELTER_EVENTS_DIR . 'config/' );

	// 2. Register the shelter_program CPT and its metabox.
	\Shelter_Events\Core\Program_CPT::init();

	// 3. Register custom taxonomy for program categories.
	\Shelter_Events\Core\Taxonomy_Registry::init();

	// 3a. Event Syncer — propagates program changes to existing TEC events on save.
	\Shelter_Events\Core\Event_Syncer::init();

	// 4. Register Abilities (WP 6.9+).
	if ( function_exists( 'wp_register_ability_category' ) ) {
		add_action( 'wp_abilities_api_categories_init', function (): void {
			wp_register_ability_category( 'shelter-events', [
				'label'       => __( 'Shelter Events', 'shelter-events' ),
				'description' => __( 'Animal shelter event management operations.', 'shelter-events' ),
			] );
		} );
		add_action( 'wp_abilities_api_init', [ \Shelter_Events\Abilities\Provider::class, 'register' ] );
	}

	// 5. WP-Cron handler — generate upcoming recurring event instances.
	add_action( 'shelter_events_generate_recurring', [ \Shelter_Events\Core\Event_Generator::class, 'run' ] );

	// 6. Admin settings / generate page.
	if ( is_admin() ) {
		new Shelter_Events_Admin();
	}

	// 7. Register blocks.
	new Shelter_Events_Blocks();

	// 8. REST routes.
	add_action( 'rest_api_init', [ 'Shelter_Events_REST', 'register_routes' ] );

	// 9. Front-end assets.
	add_action( 'wp_enqueue_scripts', function (): void {
		if ( is_post_type_archive( 'tribe_events' ) || is_singular( 'tribe_events' ) ) {
			wp_enqueue_style(
				'shelter-events-front',
				SHELTER_EVENTS_URL . 'assets/css/front.css',
				[],
				SHELTER_EVENTS_VERSION
			);
		}
	} );
}
add_action( 'plugins_loaded', 'shelter_events_init' );
