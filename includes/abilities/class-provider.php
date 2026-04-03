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

	/**
	 * Handle: shelter_replace_event
	 *
	 * Cancels a generated event and creates a draft replacement pre-populated
	 * with the original's date, time, venue, and organizer. The replacement
	 * is not linked to the program, so the syncer will not overwrite it.
	 */
	public static function handle_shelter_replace_event( array $args ): array {
		$event_id = (int) $args['event_id'];
		$post     = get_post( $event_id );

		if ( ! $post || $post->post_type !== 'tribe_events' ) {
			return [ 'success' => false, 'error' => 'Event not found.' ];
		}

		if ( get_post_meta( $event_id, '_shelter_replaced_by', true ) ) {
			return [ 'success' => false, 'error' => 'Event has already been replaced.' ];
		}

		if ( get_post_meta( $event_id, '_shelter_cancelled', true ) ) {
			return [ 'success' => false, 'error' => 'Event is already cancelled.' ];
		}

		// Read the original event's scheduling data.
		$start_date      = get_post_meta( $event_id, '_EventStartDate', true );
		$end_date        = get_post_meta( $event_id, '_EventEndDate', true );
		$timezone        = get_post_meta( $event_id, '_EventTimezone', true ) ?: wp_timezone_string();
		$venue_id        = (int) get_post_meta( $event_id, '_EventVenueID', true );
		$organizer_id    = (int) get_post_meta( $event_id, '_EventOrganizerID', true );
		$cost            = get_post_meta( $event_id, '_EventCost', true );
		$currency_symbol = get_post_meta( $event_id, '_EventCurrencySymbol', true ) ?: '$';

		// Cancel the original event.
		update_post_meta( $event_id, '_shelter_cancelled', '1' );
		update_post_meta( $event_id, '_shelter_cancel_reason', 'Replaced by a special event.' );

		$original_title = $post->post_title;
		if ( strpos( $original_title, '[CANCELLED]' ) !== 0 ) {
			wp_update_post( [
				'ID'         => $event_id,
				'post_title' => '[CANCELLED] ' . $original_title,
			] );
		}

		// Create the replacement event as a draft via TEC ORM.
		$replacement_args = [
			'title'           => $original_title,
			'status'          => 'draft',
			'start_date'      => $start_date,
			'end_date'        => $end_date,
			'timezone'        => $timezone,
			'cost'            => $cost,
			'currency_symbol' => $currency_symbol,
		];

		if ( $venue_id ) {
			$replacement_args['venue'] = $venue_id;
		}
		if ( $organizer_id ) {
			$replacement_args['organizer'] = $organizer_id;
		}

		$replacement = tribe_events()->set_args( $replacement_args )->create();

		if ( ! $replacement instanceof \WP_Post ) {
			return [ 'success' => false, 'error' => 'Failed to create replacement event.' ];
		}

		// Cross-reference the pair.
		update_post_meta( $event_id, '_shelter_replaced_by', $replacement->ID );
		update_post_meta( $replacement->ID, '_shelter_replaces_event', $event_id );

		return [
			'success'              => true,
			'original_event_id'    => $event_id,
			'replacement_event_id' => $replacement->ID,
		];
	}

	private static function build_permission_callback( string $capability ): callable {
		return static fn(): bool => current_user_can( $capability );
	}
}
