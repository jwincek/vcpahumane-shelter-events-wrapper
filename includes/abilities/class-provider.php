<?php
/**
 * Abilities Provider v2 — delegates to CPT-backed programs.
 *
 * @package Shelter_Events\Abilities
 */

declare( strict_types=1 );

namespace Shelter_Events\Abilities;

use Shelter_Events\Core\Config;
use Shelter_Events\Core\Event_Generator;
use Shelter_Events\Core\Program_CPT;

final class Provider {

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

	/**
	 * Handle: shelter_generate_events
	 */
	public static function handle_shelter_generate_events( array $args ): array {
		$gen_config = Config::get_item( 'events', 'generation', [] );
		$weeks      = $args['weeks'] ?? (int) ( $gen_config['lookahead_weeks'] ?? 8 );
		$dry_run    = $args['dry_run'] ?? false;
		$programs   = Program_CPT::get_active_programs();
		$results    = [];

		if ( ! empty( $args['program'] ) ) {
			// Filter to a single program by slug.
			$target = $args['program'];
			$programs = array_filter( $programs, fn( $p ) => $p['slug'] === $target );
		}

		foreach ( $programs as $program ) {
			$results[ $program['slug'] ] = Event_Generator::generate_for_program( $program, $weeks, $dry_run );
		}

		return [
			'success'  => true,
			'dry_run'  => $dry_run,
			'programs' => $results,
		];
	}

	/**
	 * Handle: shelter_list_programs
	 */
	public static function handle_shelter_list_programs( array $args ): array {
		$programs = Program_CPT::get_active_programs();

		$output = [];
		foreach ( $programs as $program ) {
			$output[] = [
				'slug'        => $program['slug'],
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
	 */
	public static function handle_shelter_cancel_event( array $args ): array {
		$event_id = (int) $args['event_id'];
		$post     = get_post( $event_id );

		if ( ! $post || $post->post_type !== 'tribe_events' ) {
			return [ 'success' => false, 'error' => 'Event not found.' ];
		}

		update_post_meta( $event_id, '_shelter_cancelled', '1' );
		update_post_meta( $event_id, '_shelter_cancel_reason', sanitize_text_field( $args['reason'] ?? '' ) );

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

	private static function build_permission_callback( string $capability ): callable {
		return static fn(): bool => current_user_can( $capability );
	}
}
