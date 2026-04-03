<?php
/**
 * Event Syncer — propagates program changes to existing TEC events.
 *
 * When a shelter_program post is saved, this class finds all linked
 * TEC events (via _shelter_program_post_id meta) and updates them to
 * reflect the current program data. Future events are always updated;
 * past events are updated only if the staff checks the opt-in checkbox.
 *
 * Time changes are tricky: the event's date stays the same (that's
 * determined by the recurrence schedule), but the start/end times on
 * that date are updated to the new values.
 *
 * @package Shelter_Events\Core
 */

declare( strict_types=1 );

namespace Shelter_Events\Core;

final class Event_Syncer {

	/**
	 * Hook into the program save lifecycle.
	 *
	 * Runs at priority 20 so that Program_CPT::save_meta (priority 10)
	 * has already written the new meta values before we read them.
	 */
	public static function init(): void {
		add_action( 'save_post_' . Program_CPT::POST_TYPE, [ __CLASS__, 'on_program_save' ], 20, 2 );
	}

	/**
	 * Handle program save — sync changes to linked TEC events.
	 *
	 * @param int      $post_id Program CPT post ID.
	 * @param \WP_Post $post    Program post object.
	 */
	public static function on_program_save( int $post_id, \WP_Post $post ): void {
		// Same guards as save_meta — don't run on autosave, AJAX, etc.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! isset( $_POST['shelter_program_nonce'] ) ||
			! wp_verify_nonce( $_POST['shelter_program_nonce'], 'shelter_program_save' ) ) {
			return;
		}

		if ( $post->post_status !== 'publish' ) {
			return;
		}

		// Don't run during the initial import.
		if ( did_action( 'shelter_events_program_imported' ) ) {
			return;
		}

		$include_past = ! empty( $_POST['shelter_sync_include_past'] );

		$program_data = self::build_program_data( $post_id, $post );

		$updated = self::sync_events( $post_id, $program_data, $include_past );

		// Store a transient so the admin can see feedback.
		if ( $updated > 0 ) {
			set_transient(
				'shelter_events_sync_result_' . $post_id,
				$updated,
				60
			);
		}
	}

	/**
	 * Build the program data array from the just-saved meta.
	 *
	 * @param int      $post_id Program post ID.
	 * @param \WP_Post $post    Program post object.
	 * @return array Program data in the same shape as get_active_programs().
	 */
	private static function build_program_data( int $post_id, \WP_Post $post ): array {
		$meta = Program_CPT::get_all_meta( $post_id );

		return [
			'post_id'         => $post_id,
			'slug'            => $post->post_name,
			'title'           => $post->post_title,
			'description'     => $post->post_content,
			'recurrence'      => [
				'start_time' => $meta['start_time'],
				'end_time'   => $meta['end_time'],
				'timezone'   => $meta['timezone'],
			],
			'venue'           => [
				'venue'   => $meta['venue_name'],
				'address' => $meta['venue_address'],
				'city'    => $meta['venue_city'],
				'state'   => $meta['venue_state'],
				'zip'     => $meta['venue_zip'],
			],
			'organizer'       => [
				'organizer' => $meta['organizer_name'],
				'phone'     => $meta['organizer_phone'],
				'email'     => $meta['organizer_email'],
				'website'   => $meta['organizer_website'],
			],
			'cost'            => $meta['cost'],
			'currency_symbol' => $meta['currency_symbol'],
			'featured'        => $meta['featured'] === 'yes',
			'tags'            => array_filter( array_map( 'trim', explode( ',', $meta['tags'] ) ) ),
			'website_url'     => $meta['website_url'],
			'facebook_url'    => $meta['facebook_url'],
			'meta'            => [
				'_shelter_program'              => $post->post_name,
				'_shelter_capacity'             => $meta['capacity'],
				'_shelter_contact_email'        => $meta['contact_email'],
				'_shelter_requires_appointment' => $meta['requires_appointment'],
				'_shelter_age_restriction'      => $meta['age_restriction'],
			],
		];
	}

	/**
	 * Find and update all linked TEC events for a program.
	 *
	 * @param int   $program_post_id The program CPT post ID.
	 * @param array $program         Program data array.
	 * @param bool  $include_past    Whether to also update past events.
	 * @return int Number of events updated.
	 */
	public static function sync_events( int $program_post_id, array $program, bool $include_past = false ): int {
		$meta_query = [
			[
				'key'   => '_shelter_program_post_id',
				'value' => $program_post_id,
				'type'  => 'NUMERIC',
			],
		];

		// If not including past events, only get events starting from today.
		if ( ! $include_past ) {
			$meta_query[] = [
				'key'     => '_EventStartDate',
				'value'   => current_time( 'Y-m-d 00:00:00' ),
				'compare' => '>=',
				'type'    => 'DATETIME',
			];
		}

		$events = get_posts( [
			'post_type'   => 'tribe_events',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_query'  => [
				'relation' => 'AND',
				...$meta_query,
			],
		] );

		if ( empty( $events ) ) {
			return 0;
		}

		$updated = 0;

		foreach ( $events as $event_id ) {
			$result = self::update_single_event( $event_id, $program );
			if ( $result ) {
				++$updated;
			}
		}

		return $updated;
	}

	/**
	 * Update a single TEC event to match the current program data.
	 *
	 * @param int   $event_id Event post ID.
	 * @param array $program  Program data.
	 * @return bool True on success.
	 */
	private static function update_single_event( int $event_id, array $program ): bool {
		// Skip events that have been replaced — they are frozen in their cancelled state.
		if ( get_post_meta( $event_id, '_shelter_replaced_by', true ) ) {
			return false;
		}

		$rec = $program['recurrence'];

		// Preserve the event's existing date, but update the time portion.
		$existing_start = get_post_meta( $event_id, '_EventStartDate', true );
		if ( ! $existing_start ) {
			return false;
		}

		$event_date = substr( $existing_start, 0, 10 ); // Y-m-d
		$new_start  = $event_date . ' ' . $rec['start_time'] . ':00';
		$new_end    = $event_date . ' ' . $rec['end_time'] . ':00';

		// Rebuild the event title with the program's current name.
		$date_obj  = \DateTime::createFromFormat( 'Y-m-d', $event_date );
		$cancelled = (bool) get_post_meta( $event_id, '_shelter_cancelled', true );
		$new_title = $program['title'] . ' — ' . $date_obj->format( 'l, F j, Y' );
		if ( $cancelled ) {
			$new_title = '[CANCELLED] ' . $new_title;
		}

		// Update the WP post itself (title, description).
		wp_update_post( [
			'ID'           => $event_id,
			'post_title'   => $new_title,
			'post_content' => $program['description'] ?? '',
		] );

		// Update TEC event meta via direct meta writes.
		// (Using the ORM's save() for existing events requires the full
		// event repository which is heavier than needed here.)
		update_post_meta( $event_id, '_EventStartDate', $new_start );
		update_post_meta( $event_id, '_EventEndDate', $new_end );
		update_post_meta( $event_id, '_EventStartDateUTC', get_gmt_from_date( $new_start ) );
		update_post_meta( $event_id, '_EventEndDateUTC', get_gmt_from_date( $new_end ) );
		update_post_meta( $event_id, '_EventTimezone', $rec['timezone'] ?? wp_timezone_string() );
		update_post_meta( $event_id, '_EventCost', $program['cost'] ?? '' );
		update_post_meta( $event_id, '_EventCurrencySymbol', $program['currency_symbol'] ?? '$' );

		// Event Website URL.
		if ( ! empty( $program['website_url'] ) ) {
			update_post_meta( $event_id, '_EventURL', $program['website_url'] );
		} else {
			delete_post_meta( $event_id, '_EventURL' );
		}

		// Facebook URL.
		if ( ! empty( $program['facebook_url'] ) ) {
			update_post_meta( $event_id, '_shelter_facebook_url', $program['facebook_url'] );
		} else {
			delete_post_meta( $event_id, '_shelter_facebook_url' );
		}

		// Featured status.
		if ( $program['featured'] ) {
			update_post_meta( $event_id, '_tribe_featured', '1' );
		} else {
			delete_post_meta( $event_id, '_tribe_featured' );
		}

		// Resolve and update venue.
		$venue_id = Event_Generator::resolve_venue_public( $program['venue'] ?? [] );
		if ( $venue_id ) {
			update_post_meta( $event_id, '_EventVenueID', $venue_id );
		}

		// Resolve and update organizer.
		$organizer_id = Event_Generator::resolve_organizer_public( $program['organizer'] ?? [] );
		if ( $organizer_id ) {
			update_post_meta( $event_id, '_EventOrganizerID', $organizer_id );
		}

		// Tags.
		if ( ! empty( $program['tags'] ) ) {
			wp_set_post_tags( $event_id, $program['tags'] );
		}

		// Custom shelter meta.
		if ( ! empty( $program['meta'] ) ) {
			foreach ( $program['meta'] as $key => $value ) {
				update_post_meta( $event_id, $key, $value );
			}
		}

		// Taxonomy.
		$terms = wp_get_object_terms( $program['post_id'], 'shelter_program_cat', [ 'fields' => 'slugs' ] );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) && taxonomy_exists( 'shelter_program_cat' ) ) {
			wp_set_object_terms( $event_id, $terms[0], 'shelter_program_cat' );
		}

		/**
		 * Fires after an existing event is synced with updated program data.
		 *
		 * @param int   $event_id        TEC event post ID.
		 * @param int   $program_post_id Program CPT post ID.
		 * @param array $program         The updated program data.
		 */
		do_action( 'shelter_events_event_synced', $event_id, $program['post_id'], $program );

		return true;
	}
}
