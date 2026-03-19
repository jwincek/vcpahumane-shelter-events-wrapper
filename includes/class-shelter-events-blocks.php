<?php
/**
 * Block registration for Shelter Events.
 *
 * Registers a server-rendered "Shelter Event List" block that displays
 * upcoming events for a given program, delegating to TEC's ORM for queries.
 *
 * @package Shelter_Events
 */

declare( strict_types=1 );

class Shelter_Events_Blocks {

	public function __construct() {
		add_action( 'init', [ $this, 'register_blocks' ] );
	}

	/**
	 * Register all plugin blocks.
	 */
	public function register_blocks(): void {
		register_block_type( SHELTER_EVENTS_DIR . 'blocks/shelter-event-list', [
			'render_callback' => [ $this, 'render_event_list' ],
			'attributes'      => [
				'program' => [
					'type'    => 'string',
					'default' => '',
				],
				'count' => [
					'type'    => 'number',
					'default' => 5,
				],
				'showCost' => [
					'type'    => 'boolean',
					'default' => true,
				],
				'showVenue' => [
					'type'    => 'boolean',
					'default' => true,
				],
				'layout' => [
					'type'    => 'string',
					'default' => 'list',
					'enum'    => [ 'list', 'card', 'compact' ],
				],
			],
		] );
	}

	/**
	 * Server-side render callback for shelter-event-list block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	public function render_event_list( array $attributes ): string {
		if ( ! function_exists( 'tribe_events' ) ) {
			return '<p class="shelter-events-error">' .
				esc_html__( 'The Events Calendar is required.', 'shelter-events' ) .
				'</p>';
		}

		$program  = $attributes['program'] ?? '';
		$count    = (int) ( $attributes['count'] ?? 5 );
		$layout   = $attributes['layout'] ?? 'list';

		$query = tribe_events()
			->where( 'starts_after', 'now' )
			->per_page( $count )
			->order( 'ASC' );

		if ( $program ) {
			$query = $query->where( 'meta_equals', '_shelter_program_slug', $program );
		}

		$events = $query->all();

		if ( empty( $events ) ) {
			return '<p class="shelter-events-empty">' .
				esc_html__( 'No upcoming events scheduled.', 'shelter-events' ) .
				'</p>';
		}

		$wrapper_class = 'shelter-event-list shelter-event-list--' . esc_attr( $layout );

		ob_start();
		?>
		<div class="<?php echo esc_attr( $wrapper_class ); ?>">
			<?php foreach ( $events as $event ) :
				$event_id    = $event->ID;
				$start       = get_post_meta( $event_id, '_EventStartDate', true );
				$end         = get_post_meta( $event_id, '_EventEndDate', true );
				$cancelled   = (bool) get_post_meta( $event_id, '_shelter_cancelled', true );
				$start_dt    = new DateTime( $start );
				$end_dt      = new DateTime( $end );
				$program_slug = get_post_meta( $event_id, '_shelter_program_slug', true );
			?>
				<article class="shelter-event-item<?php echo $cancelled ? ' shelter-event-item--cancelled' : ''; ?>"
					data-program="<?php echo esc_attr( $program_slug ); ?>">

					<div class="shelter-event-item__date">
						<span class="shelter-event-item__day"><?php echo esc_html( $start_dt->format( 'D' ) ); ?></span>
						<span class="shelter-event-item__month-day">
							<?php echo esc_html( $start_dt->format( 'M j' ) ); ?>
						</span>
					</div>

					<div class="shelter-event-item__details">
						<h3 class="shelter-event-item__title">
							<a href="<?php echo esc_url( get_permalink( $event_id ) ); ?>">
								<?php echo esc_html( get_the_title( $event_id ) ); ?>
							</a>
						</h3>

						<div class="shelter-event-item__meta">
							<span class="shelter-event-item__time">
								<?php
								echo esc_html(
									$start_dt->format( 'g:i A' ) . ' – ' . $end_dt->format( 'g:i A' )
								);
								?>
							</span>

							<?php if ( ! empty( $attributes['showVenue'] ) ) : ?>
								<span class="shelter-event-item__venue">
									<?php echo esc_html( tribe_get_venue( $event_id ) ); ?>
								</span>
							<?php endif; ?>

							<?php if ( ! empty( $attributes['showCost'] ) ) : ?>
								<span class="shelter-event-item__cost">
									<?php echo esc_html( tribe_get_cost( $event_id, true ) ); ?>
								</span>
							<?php endif; ?>
						</div>

						<?php if ( $cancelled ) : ?>
							<span class="shelter-event-item__badge shelter-event-item__badge--cancelled">
								<?php esc_html_e( 'Cancelled', 'shelter-events' ); ?>
							</span>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
