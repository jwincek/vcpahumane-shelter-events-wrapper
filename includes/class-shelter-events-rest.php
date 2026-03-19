<?php
/**
 * REST API routes v2 — reads programs from the CPT.
 *
 * @package Shelter_Events
 */

declare( strict_types=1 );

class Shelter_Events_REST {

	private const NAMESPACE = 'shelter-events/v1';

	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/programs', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_programs' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/upcoming', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_upcoming' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'program'  => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'per_page' => [ 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50 ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/generate', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'generate_events' ],
			'permission_callback' => fn() => current_user_can( 'manage_options' ),
			'args'                => [
				'program' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'weeks'   => [ 'type' => 'integer', 'default' => 8, 'minimum' => 1, 'maximum' => 52 ],
				'dry_run' => [ 'type' => 'boolean', 'default' => false ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/cancel', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'cancel_event' ],
			'permission_callback' => fn() => current_user_can( 'edit_others_posts' ),
			'args'                => [
				'event_id' => [ 'type' => 'integer', 'required' => true ],
				'reason'   => [ 'type' => 'string' ],
			],
		] );
	}

	public static function get_programs( \WP_REST_Request $request ): \WP_REST_Response {
		$programs = \Shelter_Events\Core\Program_CPT::get_active_programs();

		$output = [];
		foreach ( $programs as $prog ) {
			$output[] = [
				'slug'        => $prog['slug'],
				'title'       => $prog['title'],
				'description' => $prog['description'] ?? '',
				'days'        => $prog['recurrence']['days'] ?? [],
				'start_time'  => $prog['recurrence']['start_time'] ?? '',
				'end_time'    => $prog['recurrence']['end_time'] ?? '',
				'cost'        => ( $prog['currency_symbol'] ?? '$' ) . ( $prog['cost'] ?? '0' ),
				'category'    => $prog['category'] ?? '',
			];
		}

		return new \WP_REST_Response( $output, 200 );
	}

	public static function get_upcoming( \WP_REST_Request $request ): \WP_REST_Response {
		$program  = $request->get_param( 'program' );
		$per_page = $request->get_param( 'per_page' );

		$query = tribe_events()->where( 'starts_after', 'now' )->per_page( $per_page )->order( 'ASC' );

		if ( $program ) {
			$query = $query->where( 'meta_equals', '_shelter_program_slug', $program );
		}

		$events = $query->all();
		$output = [];

		foreach ( $events as $event ) {
			$output[] = [
				'id'         => $event->ID,
				'title'      => get_the_title( $event ),
				'start_date' => get_post_meta( $event->ID, '_EventStartDate', true ),
				'end_date'   => get_post_meta( $event->ID, '_EventEndDate', true ),
				'url'        => get_permalink( $event ),
				'programme'  => get_post_meta( $event->ID, '_shelter_program_slug', true ),
				'cancelled'  => (bool) get_post_meta( $event->ID, '_shelter_cancelled', true ),
				'venue'      => tribe_get_venue( $event->ID ),
				'cost'       => tribe_get_cost( $event->ID, true ),
			];
		}

		return new \WP_REST_Response( $output, 200 );
	}

	public static function generate_events( \WP_REST_Request $request ): \WP_REST_Response {
		$result = \Shelter_Events\Abilities\Provider::handle_shelter_generate_events( [
			'program' => $request->get_param( 'program' ),
			'weeks'   => $request->get_param( 'weeks' ),
			'dry_run' => $request->get_param( 'dry_run' ),
		] );

		return new \WP_REST_Response( $result, 200 );
	}

	public static function cancel_event( \WP_REST_Request $request ): \WP_REST_Response {
		$result = \Shelter_Events\Abilities\Provider::handle_shelter_cancel_event( [
			'event_id' => $request->get_param( 'event_id' ),
			'reason'   => $request->get_param( 'reason' ) ?? '',
		] );

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 404 );
	}
}
