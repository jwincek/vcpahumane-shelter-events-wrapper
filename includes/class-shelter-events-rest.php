<?php
/**
 * REST API routes for front-end consumption.
 *
 * Following the Petstablished pattern: thin REST routes that delegate
 * to abilities / core logic, with their own permission callbacks so
 * they work for anonymous front-end visitors.
 *
 * @package Shelter_Events
 */

declare( strict_types=1 );

class Shelter_Events_REST {

	private const NAMESPACE = 'shelter-events/v1';

	/**
	 * Register REST routes.
	 */
	public static function register_routes(): void {
		// GET /shelter-events/v1/programs — public list of programs.
		register_rest_route( self::NAMESPACE, '/programs', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_programs' ],
			'permission_callback' => '__return_true',
		] );

		// GET /shelter-events/v1/upcoming?program=bingo — upcoming events by program.
		register_rest_route( self::NAMESPACE, '/upcoming', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_upcoming' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'program' => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'per_page' => [
					'type'    => 'integer',
					'default' => 10,
					'minimum' => 1,
					'maximum' => 50,
				],
			],
		] );

		// POST /shelter-events/v1/generate — admin-only generate.
		register_rest_route( self::NAMESPACE, '/generate', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'generate_events' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => [
				'program' => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'weeks' => [
					'type'    => 'integer',
					'default' => 8,
					'minimum' => 1,
					'maximum' => 52,
				],
				'dry_run' => [
					'type'    => 'boolean',
					'default' => false,
				],
			],
		] );

		// POST /shelter-events/v1/cancel — admin-only cancel.
		register_rest_route( self::NAMESPACE, '/cancel', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'cancel_event' ],
			'permission_callback' => function () {
				return current_user_can( 'edit_others_posts' );
			},
			'args'                => [
				'event_id' => [
					'type'     => 'integer',
					'required' => true,
				],
				'reason' => [
					'type' => 'string',
				],
			],
		] );
	}

	/**
	 * GET /programs
	 */
	public static function get_programs( \WP_REST_Request $request ): \WP_REST_Response {
		$programs = \Shelter_Events\Core\Config::get_item( 'events', 'programs', [] );

		$output = [];
		foreach ( $programs as $slug => $prog ) {
			$output[] = [
				'slug'        => $slug,
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

	/**
	 * GET /upcoming
	 */
	public static function get_upcoming( \WP_REST_Request $request ): \WP_REST_Response {
		$program  = $request->get_param( 'program' );
		$per_page = $request->get_param( 'per_page' );

		$query_args = [
			'posts_per_page' => $per_page,
			'start_date'     => 'now',
			'order'          => 'ASC',
		];

		$query = tribe_events()->where( 'starts_after', 'now' );

		if ( $program ) {
			$query = $query->where( 'meta_equals', '_shelter_program_slug', $program );
		}

		$events = $query->per_page( $per_page )->order( 'ASC' )->all();

		$output = [];
		foreach ( $events as $event ) {
			$output[] = [
				'id'           => $event->ID,
				'title'        => get_the_title( $event ),
				'start_date'   => get_post_meta( $event->ID, '_EventStartDate', true ),
				'end_date'     => get_post_meta( $event->ID, '_EventEndDate', true ),
				'url'          => get_permalink( $event ),
				'programme'    => get_post_meta( $event->ID, '_shelter_program_slug', true ),
				'cancelled'    => (bool) get_post_meta( $event->ID, '_shelter_cancelled', true ),
				'venue'        => tribe_get_venue( $event->ID ),
				'cost'         => tribe_get_cost( $event->ID, true ),
			];
		}

		return new \WP_REST_Response( $output, 200 );
	}

	/**
	 * POST /generate
	 */
	public static function generate_events( \WP_REST_Request $request ): \WP_REST_Response {
		$args = [
			'program' => $request->get_param( 'program' ),
			'weeks'   => $request->get_param( 'weeks' ),
			'dry_run' => $request->get_param( 'dry_run' ),
		];

		$result = \Shelter_Events\Abilities\Provider::handle_shelter_generate_events( $args );

		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /cancel
	 */
	public static function cancel_event( \WP_REST_Request $request ): \WP_REST_Response {
		$args = [
			'event_id' => $request->get_param( 'event_id' ),
			'reason'   => $request->get_param( 'reason' ) ?? '',
		];

		$result = \Shelter_Events\Abilities\Provider::handle_shelter_cancel_event( $args );

		$status = $result['success'] ? 200 : 404;

		return new \WP_REST_Response( $result, $status );
	}
}
