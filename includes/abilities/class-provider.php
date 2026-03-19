<?php
/**
 * Abilities Provider — registers shelter event abilities from config.
 *
 * Each ability is a thin, testable operation with JSON Schema validation
 * and permission callbacks, following the Petstablished pattern where
 * business logic lives in abilities rather than REST endpoints or admin UI.
 *
 * @package Shelter_Events\Abilities
 */

declare( strict_types=1 );

namespace Shelter_Events\Abilities;

use Shelter_Events\Core\Config;
use Shelter_Events\Core\Event_Generator;

final class Provider {

	/**
	 * Register all abilities from config/abilities.json.
	 */
	public static function register(): void {
		$abilities = Config::get_item( 'abilities', 'abilities', [] );

		foreach ( $abilities as $slug => $definition ) {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				break;
			}

			wp_register_ability( $slug, [
				'label'               => $definition['label'],
				'description'         => $definition['description'],
				'category'            => $definition['category'],
				'permission_callback' => self::build_permission_callback( $definition['permission_callback'] ?? 'manage_options' ),
				'callback'            => [ __CLASS__, "handle_{$slug}" ],
				'schema'              => $definition['schema'] ?? [],
			] );
		}
	}

	// ── Ability Handlers ──────────────────────────────────────────────────────

	/**
	 * Handle: shelter_generate_events
	 *
	 * @param array $args Validated args from the Abilities API.
	 * @return array Results with created event IDs.
	 */
	public static function handle_shelter_generate_events( array $args ): array {
		$config   = Config::get( 'events' );
		$programs = $config['programs'] ?? [];
		$weeks    = $args['weeks'] ?? (int) ( $config['generation']['lookahead_weeks'] ?? 8 );
		$dry_run  = $args['dry_run'] ?? false;
		$results  = [];

		if ( ! empty( $args['program'] ) ) {
			// Single program.
			$slug = $args['program'];
			if ( isset( $programs[ $slug ] ) ) {
				$results[ $slug ] = Event_Generator::generate_for_program( $slug, $programs[ $slug ], $weeks, $dry_run );
			}
		} else {
			// All programs.
			foreach ( $programs as $slug => $program ) {
				$results[ $slug ] = Event_Generator::generate_for_program( $slug, $program, $weeks, $dry_run );
			}
		}

		return [
			'success'  => true,
			'dry_run'  => $dry_run,
			'programs' => $results,
		];
	}

	/**
	 * Handle: shelter_list_programs
	 *
	 * @param array $args Validated args.
	 * @return array Program definitions.
	 */
	public static function handle_shelter_list_programs( array $args ): array {
		$programs = Config::get_item( 'events', 'programs', [] );

		$output = [];
		foreach ( $programs as $slug => $program ) {
			$output[] = [
				'slug'        => $slug,
				'title'       => $program['title'],
				'description' => $program['description'] ?? '',
				'category'    => $program['category'] ?? '',
				'days'        => $program['recurrence']['days'] ?? [],
				'start_time'  => $program['recurrence']['start_time'] ?? '',
				'end_time'    => $program['recurrence']['end_time'] ?? '',
				'cost'        => $program['cost'] ?? '',
			];
		}

		return [
			'success'  => true,
			'programs' => $output,
		];
	}

	/**
	 * Handle: shelter_cancel_event
	 *
	 * @param array $args Must contain 'event_id'.
	 * @return array Result.
	 */
	public static function handle_shelter_cancel_event( array $args ): array {
		$event_id = (int) $args['event_id'];
		$post     = get_post( $event_id );

		if ( ! $post || $post->post_type !== 'tribe_events' ) {
			return [
				'success' => false,
				'error'   => 'Event not found.',
			];
		}

		// Mark as cancelled via TEC's status meta + prepend title.
		update_post_meta( $event_id, '_shelter_cancelled', '1' );
		update_post_meta( $event_id, '_shelter_cancel_reason', sanitize_text_field( $args['reason'] ?? '' ) );

		// Update title to show cancellation.
		wp_update_post( [
			'ID'         => $event_id,
			'post_title' => '[CANCELLED] ' . $post->post_title,
		] );

		return [
			'success'  => true,
			'event_id' => $event_id,
			'message'  => 'Event marked as cancelled.',
		];
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Build a permission callback from a capability string.
	 *
	 * @param string $capability WordPress capability.
	 * @return callable
	 */
	private static function build_permission_callback( string $capability ): callable {
		return static function () use ( $capability ): bool {
			return current_user_can( $capability );
		};
	}
}
