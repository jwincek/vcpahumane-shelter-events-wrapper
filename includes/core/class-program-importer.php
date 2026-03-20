<?php
/**
 * Program Importer — seeds CPT posts from config/events.json on first activation.
 *
 * This runs once (guarded by an option flag) and converts the JSON program
 * definitions into shelter_program CPT posts with all meta fields populated.
 * After import, the JSON file is no longer the source of truth — the CPT is.
 *
 * @package Shelter_Events\Core
 */

declare( strict_types=1 );

namespace Shelter_Events\Core;

final class Program_Importer {

	/** @var string Option key to track whether import has run. */
	private const IMPORTED_FLAG = 'shelter_events_programs_imported';

	/**
	 * Import programs from config/events.json if not already imported.
	 */
	public static function import_from_config(): void {
		if ( get_option( self::IMPORTED_FLAG ) ) {
			return;
		}

		$config   = Config::get( 'events' );
		$programs = $config['programs'] ?? [];
		$venues   = $config['venues'] ?? [];
		$orgs     = $config['organizers'] ?? [];

		if ( empty( $programs ) ) {
			update_option( self::IMPORTED_FLAG, true );
			return;
		}

		foreach ( $programs as $slug => $program ) {
			// Check if a post with this slug already exists.
			$existing = get_posts( [
				'post_type'   => Program_CPT::POST_TYPE,
				'name'        => $slug,
				'numberposts' => 1,
				'fields'      => 'ids',
			] );

			if ( ! empty( $existing ) ) {
				continue;
			}

			// Create the CPT post.
			$post_id = wp_insert_post( [
				'post_type'    => Program_CPT::POST_TYPE,
				'post_title'   => $program['title'],
				'post_content' => $program['description'] ?? '',
				'post_status'  => 'publish',
				'post_name'    => $slug,
			] );

			if ( is_wp_error( $post_id ) || ! $post_id ) {
				continue;
			}

			// Map JSON structure to CPT meta fields.
			$rec = $program['recurrence'] ?? [];
			$meta_map = [
				'recurrence_days'      => $rec['days'] ?? [],
				'start_time'           => $rec['start_time'] ?? '18:00',
				'end_time'             => $rec['end_time'] ?? '21:00',
				'timezone'             => $rec['timezone'] ?? 'America/New_York',
				'cost'                 => $program['cost'] ?? '0',
				'currency_symbol'      => $program['currency_symbol'] ?? '$',
				'featured'             => ! empty( $program['featured'] ) ? 'yes' : 'no',
				'tags'                 => implode( ', ', $program['tags'] ?? [] ),
				'active'               => 'yes',
				'website_url'          => $program['website_url'] ?? '',
				'facebook_url'         => $program['facebook_url'] ?? '',
			];

			// Resolve venue from JSON venue slug.
			$venue_slug = $program['venue_slug'] ?? '';
			if ( $venue_slug && isset( $venues[ $venue_slug ] ) ) {
				$v = $venues[ $venue_slug ];
				$meta_map['venue_name']    = $v['venue'] ?? '';
				$meta_map['venue_address'] = $v['address'] ?? '';
				$meta_map['venue_city']    = $v['city'] ?? '';
				$meta_map['venue_state']   = $v['state'] ?? '';
				$meta_map['venue_zip']     = $v['zip'] ?? '';
			}

			// Resolve organizer from JSON organizer slug.
			$org_slug = $program['organizer_slug'] ?? '';
			if ( $org_slug && isset( $orgs[ $org_slug ] ) ) {
				$o = $orgs[ $org_slug ];
				$meta_map['organizer_name']    = $o['organizer'] ?? '';
				$meta_map['organizer_phone']   = $o['phone'] ?? '';
				$meta_map['organizer_email']   = $o['email'] ?? '';
				$meta_map['organizer_website'] = $o['website'] ?? '';
			}

			// Pull extra meta from the program definition.
			$program_meta = $program['meta'] ?? [];
			$meta_map['capacity']             = $program_meta['_shelter_capacity'] ?? '';
			$meta_map['contact_email']        = $program_meta['_shelter_contact_email'] ?? '';
			$meta_map['requires_appointment'] = ( $program_meta['_shelter_requires_appointment'] ?? '' ) === 'yes' ? 'yes' : 'no';
			$meta_map['age_restriction']      = $program_meta['_shelter_age_restriction'] ?? '';

			// Store all meta.
			foreach ( $meta_map as $field => $value ) {
				update_post_meta( $post_id, '_shelter_prog_' . $field, $value );
			}

			// Assign taxonomy term if category is defined.
			if ( ! empty( $program['category'] ) && taxonomy_exists( 'shelter_program_cat' ) ) {
				wp_set_object_terms( $post_id, $program['category'], 'shelter_program_cat' );
			}

			/**
			 * Fires after a program is imported from JSON config.
			 *
			 * @param int    $post_id  The new CPT post ID.
			 * @param string $slug     The program slug from JSON.
			 * @param array  $program  The raw JSON program definition.
			 */
			do_action( 'shelter_events_program_imported', $post_id, $slug, $program );
		}

		update_option( self::IMPORTED_FLAG, true );
	}

	/**
	 * Reset the import flag (for testing or re-import).
	 */
	public static function reset(): void {
		delete_option( self::IMPORTED_FLAG );
	}
}
