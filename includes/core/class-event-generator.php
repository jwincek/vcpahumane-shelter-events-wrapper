<?php
/**
 * Event Generator v2 — reads programs from the shelter_program CPT.
 *
 * The generator queries published, active shelter_program posts via
 * Program_CPT::get_active_programs(), then creates individual TEC
 * event instances via the ORM for the configured lookahead period.
 *
 * @package Shelter_Events\Core
 */

declare( strict_types=1 );

namespace Shelter_Events\Core;

use DateInterval;
use DatePeriod;
use DateTime;
use DateTimeZone;

final class Event_Generator {

	/**
	 * Run the generation for all active programs (WP-Cron entry point).
	 */
	public static function run(): void {
		$gen_config = Config::get_item( 'events', 'generation', [] );
		$weeks      = (int) ( $gen_config['lookahead_weeks'] ?? 8 );
		$programs   = Program_CPT::get_active_programs();

		foreach ( $programs as $program ) {
			self::generate_for_program( $program, $weeks );
		}
	}

	/**
	 * Generate event instances for a single program.
	 *
	 * @param array $program  Program definition from Program_CPT::get_active_programs().
	 * @param int   $weeks    Weeks to look ahead.
	 * @param bool  $dry_run  If true, return planned events without creating.
	 * @return array List of created (or planned) event data.
	 */
	public static function generate_for_program( array $program, int $weeks = 8, bool $dry_run = false ): array {
		$gen_config = Config::get_item( 'events', 'generation', [] );
		$hash_key   = $gen_config['duplicate_check_meta_key'] ?? '_shelter_generated_hash';
		$recurrence = $program['recurrence'];
		$tz         = new DateTimeZone( $recurrence['timezone'] ?? wp_timezone_string() );

		$start  = new DateTime( 'today', $tz );
		$end    = ( clone $start )->add( new DateInterval( "P{$weeks}W" ) );
		$period = new DatePeriod( $start, new DateInterval( 'P1D' ), $end );

		$target_days = array_map( 'strtolower', $recurrence['days'] );
		$slug        = $program['slug'];
		$results     = [];

		foreach ( $period as $date ) {
			$day_name = strtolower( $date->format( 'l' ) );

			if ( ! in_array( $day_name, $target_days, true ) ) {
				continue;
			}

			$event_date = $date->format( 'Y-m-d' );
			$hash       = self::make_hash( $slug, $event_date );

			if ( self::event_exists( $hash_key, $hash ) ) {
				continue;
			}

			if ( $dry_run ) {
				$results[] = [
					'date'  => $event_date,
					'hash'  => $hash,
					'title' => $program['title'] . ' — ' . $date->format( 'l, F j, Y' ),
				];
				continue;
			}

			$event_id = self::create_event( $program, $date, $hash );

			if ( $event_id ) {
				$results[] = [
					'event_id' => $event_id,
					'date'     => $event_date,
					'hash'     => $hash,
				];
			}
		}

		return $results;
	}

	/**
	 * Create a single TEC event via the ORM.
	 */
	private static function create_event( array $program, DateTime $date, string $hash ): int|false {
		$gen_config = Config::get_item( 'events', 'generation', [] );
		$hash_key   = $gen_config['duplicate_check_meta_key'] ?? '_shelter_generated_hash';
		$rec        = $program['recurrence'];

		$start_date = $date->format( 'Y-m-d' ) . ' ' . $rec['start_time'] . ':00';
		$end_date   = $date->format( 'Y-m-d' ) . ' ' . $rec['end_time'] . ':00';

		$venue_id     = self::resolve_venue( $program['venue'] ?? [] );
		$organizer_id = self::resolve_organizer( $program['organizer'] ?? [] );

		$args = [
			'title'           => $program['title'] . ' — ' . $date->format( 'l, F j, Y' ),
			'status'          => 'publish',
			'start_date'      => $start_date,
			'end_date'        => $end_date,
			'timezone'        => $rec['timezone'] ?? wp_timezone_string(),
			'description'     => $program['description'] ?? '',
			'cost'            => $program['cost'] ?? '',
			'currency_symbol' => $program['currency_symbol'] ?? '$',
			'featured'        => $program['featured'] ?? false,
			'tag'             => $program['tags'] ?? [],
		];

		// Map the program's website URL to TEC's native Event Website field (_EventURL).
		if ( ! empty( $program['website_url'] ) ) {
			$args['url'] = $program['website_url'];
		}

		if ( ! empty( $program['event_cat'] ) ) {
			$args['category'] = $program['event_cat'];
		}

		if ( $venue_id ) {
			$args['venue'] = $venue_id;
		}
		if ( $organizer_id ) {
			$args['organizer'] = $organizer_id;
		}

		$event = tribe_events()->set_args( $args )->create();

		if ( ! $event instanceof \WP_Post ) {
			return false;
		}

		// Dedup hash.
		update_post_meta( $event->ID, $hash_key, $hash );

		// Program slug for queries.
		update_post_meta( $event->ID, '_shelter_program_slug', $program['slug'] );

		// Link back to the program CPT post.
		if ( ! empty( $program['post_id'] ) ) {
			update_post_meta( $event->ID, '_shelter_program_post_id', $program['post_id'] );
		}

		// Additional custom meta.
		if ( ! empty( $program['meta'] ) ) {
			foreach ( $program['meta'] as $key => $value ) {
				if ( $value !== '' ) {
					update_post_meta( $event->ID, $key, $value );
				}
			}
		}

		// Store Facebook URL as separate meta (TEC only has one _EventURL field).
		if ( ! empty( $program['facebook_url'] ) ) {
			update_post_meta( $event->ID, '_shelter_facebook_url', $program['facebook_url'] );
		}

		// Assign shelter_program_cat taxonomy.
		if ( ! empty( $program['category'] ) && taxonomy_exists( 'shelter_program_cat' ) ) {
			wp_set_object_terms( $event->ID, $program['category'], 'shelter_program_cat' );
		}

		do_action( 'shelter_events_event_created', $event->ID, $program['slug'], $program, $date->format( 'Y-m-d' ) );

		return $event->ID;
	}

	// ── Venue / Organizer resolution ──────────────────────────────────────────

	private static function resolve_venue( array $venue_data ): ?int {
		if ( empty( $venue_data['venue'] ) || ! function_exists( 'tribe_venues' ) ) {
			return null;
		}

		$existing = get_posts( [
			'post_type'   => 'tribe_venue',
			'title'       => $venue_data['venue'],
			'numberposts' => 1,
			'fields'      => 'ids',
		] );

		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}

		$venue = tribe_venues()->set_args( $venue_data )->create();
		return $venue instanceof \WP_Post ? $venue->ID : null;
	}

	private static function resolve_organizer( array $org_data ): ?int {
		if ( empty( $org_data['organizer'] ) || ! function_exists( 'tribe_organizers' ) ) {
			return null;
		}

		$existing = get_posts( [
			'post_type'   => 'tribe_organizer',
			'title'       => $org_data['organizer'],
			'numberposts' => 1,
			'fields'      => 'ids',
		] );

		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}

		$organizer = tribe_organizers()->set_args( $org_data )->create();
		return $organizer instanceof \WP_Post ? $organizer->ID : null;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	public static function make_hash( string $slug, string $date ): string {
		return hash( 'sha256', "shelter-event:{$slug}:{$date}" );
	}

	private static function event_exists( string $meta_key, string $hash ): bool {
		global $wpdb;

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				$meta_key,
				$hash
			)
		);

		return (int) $exists > 0;
	}
}
