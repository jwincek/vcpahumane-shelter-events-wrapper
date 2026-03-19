<?php
/**
 * Event Generator — the core engine.
 *
 * Reads program definitions from config/events.json and uses the TEC ORM
 * (tribe_events()->set_args()->create()) to generate individual event
 * instances for the configured lookahead period.
 *
 * Duplicate prevention is handled via a deterministic hash stored as post
 * meta — if an event for a given program + date already exists, it is skipped.
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
	 * Run the generation for all programs (WP-Cron entry point).
	 */
	public static function run(): void {
		$config   = Config::get( 'events' );
		$programs = $config['programs'] ?? [];
		$weeks    = (int) ( $config['generation']['lookahead_weeks'] ?? 8 );

		foreach ( $programs as $slug => $program ) {
			self::generate_for_program( $slug, $program, $weeks );
		}
	}

	/**
	 * Generate event instances for a single program.
	 *
	 * @param string $slug    Program slug.
	 * @param array  $program Program definition from config.
	 * @param int    $weeks   Weeks to look ahead.
	 * @param bool   $dry_run If true, return planned events without creating.
	 * @return array List of created (or planned) event IDs / dates.
	 */
	public static function generate_for_program( string $slug, array $program, int $weeks = 8, bool $dry_run = false ): array {
		$config    = Config::get( 'events' );
		$hash_key  = $config['generation']['duplicate_check_meta_key'] ?? '_shelter_generated_hash';
		$recurrence = $program['recurrence'];
		$tz         = new DateTimeZone( $recurrence['timezone'] ?? wp_timezone_string() );

		$start = new DateTime( 'today', $tz );
		$end   = ( clone $start )->add( new DateInterval( "P{$weeks}W" ) );
		$period = new DatePeriod( $start, new DateInterval( 'P1D' ), $end );

		$target_days = array_map( 'strtolower', $recurrence['days'] );
		$results     = [];

		foreach ( $period as $date ) {
			$day_name = strtolower( $date->format( 'l' ) );

			if ( ! in_array( $day_name, $target_days, true ) ) {
				continue;
			}

			$event_date = $date->format( 'Y-m-d' );
			$hash       = self::make_hash( $slug, $event_date );

			// Duplicate check.
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

			$event_id = self::create_event( $slug, $program, $date, $hash );

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
	 *
	 * @param string    $slug    Program slug.
	 * @param array     $program Program config.
	 * @param DateTime  $date    The specific date for this instance.
	 * @param string    $hash    Duplicate-prevention hash.
	 * @return int|false Post ID on success, false on failure.
	 */
	private static function create_event( string $slug, array $program, DateTime $date, string $hash ): int|false {
		$config  = Config::get( 'events' );
		$rec     = $program['recurrence'];
		$hash_key = $config['generation']['duplicate_check_meta_key'] ?? '_shelter_generated_hash';

		$start_date = $date->format( 'Y-m-d' ) . ' ' . $rec['start_time'] . ':00';
		$end_date   = $date->format( 'Y-m-d' ) . ' ' . $rec['end_time'] . ':00';

		// Resolve venue.
		$venue_id = self::resolve_venue( $program['venue_slug'] ?? '' );

		// Resolve organizer.
		$organizer_id = self::resolve_organizer( $program['organizer_slug'] ?? '' );

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

		if ( ! empty( $program['event_cat'] ) ) {
			$args['category'] = $program['event_cat'];
		}

		if ( $venue_id ) {
			$args['venue'] = $venue_id;
		}

		if ( $organizer_id ) {
			$args['organizer'] = $organizer_id;
		}

		// Use TEC ORM to create the event.
		$event = tribe_events()->set_args( $args )->create();

		if ( ! $event instanceof \WP_Post ) {
			return false;
		}

		// Store the dedup hash.
		update_post_meta( $event->ID, $hash_key, $hash );

		// Store program slug as meta.
		update_post_meta( $event->ID, '_shelter_program_slug', $slug );

		// Store any additional custom meta from config.
		if ( ! empty( $program['meta'] ) ) {
			foreach ( $program['meta'] as $key => $value ) {
				update_post_meta( $event->ID, $key, $value );
			}
		}

		// Assign to shelter_program taxonomy.
		if ( ! empty( $program['category'] ) && taxonomy_exists( 'shelter_program' ) ) {
			wp_set_object_terms( $event->ID, $program['category'], 'shelter_program' );
		}

		/**
		 * Fires after a shelter event instance is generated.
		 *
		 * @param int    $event_id Post ID.
		 * @param string $slug     Program slug.
		 * @param array  $program  Full program config.
		 * @param string $date     Y-m-d date string.
		 */
		do_action( 'shelter_events_event_created', $event->ID, $slug, $program, $date->format( 'Y-m-d' ) );

		return $event->ID;
	}

	// ── Venue / Organizer resolution ──────────────────────────────────────────

	/**
	 * Find or create a TEC Venue from config.
	 *
	 * @param string $venue_slug Key in config venues map.
	 * @return int|null Venue post ID or null.
	 */
	private static function resolve_venue( string $venue_slug ): ?int {
		if ( empty( $venue_slug ) ) {
			return null;
		}

		$venues = Config::get_item( 'events', 'venues', [] );
		$venue_config = $venues[ $venue_slug ] ?? null;

		if ( ! $venue_config || ! function_exists( 'tribe_venues' ) ) {
			return null;
		}

		// Check if venue already exists by title.
		$existing = get_posts( [
			'post_type'  => 'tribe_venue',
			'title'      => $venue_config['venue'],
			'numberposts' => 1,
			'fields'     => 'ids',
		] );

		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}

		// Create via ORM.
		$venue = tribe_venues()->set_args( $venue_config )->create();

		return $venue instanceof \WP_Post ? $venue->ID : null;
	}

	/**
	 * Find or create a TEC Organizer from config.
	 *
	 * @param string $organizer_slug Key in config organizers map.
	 * @return int|null Organizer post ID or null.
	 */
	private static function resolve_organizer( string $organizer_slug ): ?int {
		if ( empty( $organizer_slug ) ) {
			return null;
		}

		$organizers = Config::get_item( 'events', 'organizers', [] );
		$org_config = $organizers[ $organizer_slug ] ?? null;

		if ( ! $org_config || ! function_exists( 'tribe_organizers' ) ) {
			return null;
		}

		$existing = get_posts( [
			'post_type'  => 'tribe_organizer',
			'title'      => $org_config['organizer'],
			'numberposts' => 1,
			'fields'     => 'ids',
		] );

		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}

		$organizer = tribe_organizers()->set_args( $org_config )->create();

		return $organizer instanceof \WP_Post ? $organizer->ID : null;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Create a deterministic hash for duplicate detection.
	 *
	 * @param string $slug Program slug.
	 * @param string $date Y-m-d date.
	 * @return string SHA-256 hash.
	 */
	public static function make_hash( string $slug, string $date ): string {
		return hash( 'sha256', "shelter-event:{$slug}:{$date}" );
	}

	/**
	 * Check whether an event with the given hash already exists.
	 *
	 * @param string $meta_key The meta key to check.
	 * @param string $hash     The hash value.
	 * @return bool
	 */
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
