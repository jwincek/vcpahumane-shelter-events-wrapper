<?php
/**
 * Plugin Name: Shelter Events Wrapper
 * Description: Config-driven wrapper for The Events Calendar — manages recurring shelter events (BINGO nights, spay/neuter clinics, etc.) via the TEC ORM and WP 6.9 Abilities API.
 * Version:     1.0.0
 * Requires at least: 6.7
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
define( 'SHELTER_EVENTS_VERSION', '1.0.0' );
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
	\Shelter_Events\Core\Config::init( SHELTER_EVENTS_DIR . 'config/' );

	// Schedule the recurring-event generation cron if not already scheduled.
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

	// 1. Load JSON config.
	\Shelter_Events\Core\Config::init( SHELTER_EVENTS_DIR . 'config/' );

	// 2. Register custom taxonomy for event programs (BINGO, Clinic, etc.).
	\Shelter_Events\Core\Taxonomy_Registry::init();

	// 3. Register Abilities (WP 6.9+).
	if ( function_exists( 'wp_register_ability_category' ) ) {
		add_action( 'wp_abilities_api_categories_init', function (): void {
			wp_register_ability_category( 'shelter-events', [
				'label'       => __( 'Shelter Events', 'shelter-events' ),
				'description' => __( 'Animal shelter event management operations.', 'shelter-events' ),
			] );
		} );
		add_action( 'wp_abilities_api_init', [ \Shelter_Events\Abilities\Provider::class, 'register' ] );
	}

	// 4. WP-Cron handler — generate upcoming recurring event instances.
	add_action( 'shelter_events_generate_recurring', [ \Shelter_Events\Core\Event_Generator::class, 'run' ] );

	// 5. Admin settings page.
	if ( is_admin() ) {
		new Shelter_Events_Admin();
	}

	// 6. Register blocks (requires TEC + WP block editor).
	new Shelter_Events_Blocks();

	// 7. REST routes for front-end AJAX (calendar feeds, etc.).
	add_action( 'rest_api_init', [ 'Shelter_Events_REST', 'register_routes' ] );

	// 8. Enqueue front-end assets.
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
