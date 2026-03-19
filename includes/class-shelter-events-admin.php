<?php
/**
 * Admin settings page for the Shelter Events Wrapper.
 *
 * Provides:
 * - Overview of configured programs and their schedules
 * - "Generate Now" button to trigger immediate event creation
 * - Log of recently generated events
 * - Override controls for individual programs
 *
 * @package Shelter_Events
 */

declare( strict_types=1 );

class Shelter_Events_Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_init', [ $this, 'handle_generate_action' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	/**
	 * Add submenu under Events (The Events Calendar).
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=tribe_events',
			__( 'Shelter Programs', 'shelter-events' ),
			__( 'Shelter Programs', 'shelter-events' ),
			'manage_options',
			'shelter-events-programs',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Handle the "Generate Now" form submission.
	 */
	public function handle_generate_action(): void {
		if ( ! isset( $_POST['shelter_generate_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['shelter_generate_nonce'], 'shelter_generate_events' ) ) {
			wp_die( __( 'Security check failed.', 'shelter-events' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions.', 'shelter-events' ) );
		}

		$program = sanitize_text_field( $_POST['program'] ?? '' );
		$weeks   = (int) ( $_POST['weeks'] ?? 8 );
		$dry_run = ! empty( $_POST['dry_run'] );

		$config   = \Shelter_Events\Core\Config::get( 'events' );
		$programs = $config['programs'] ?? [];
		$results  = [];

		if ( $program && isset( $programs[ $program ] ) ) {
			$results[ $program ] = \Shelter_Events\Core\Event_Generator::generate_for_program(
				$program,
				$programs[ $program ],
				$weeks,
				$dry_run
			);
		} else {
			foreach ( $programs as $slug => $prog ) {
				$results[ $slug ] = \Shelter_Events\Core\Event_Generator::generate_for_program(
					$slug,
					$prog,
					$weeks,
					$dry_run
				);
			}
		}

		// Store results in transient for display.
		set_transient( 'shelter_events_last_generation', $results, 300 );

		wp_safe_redirect( add_query_arg( [
			'page'      => 'shelter-events-programs',
			'generated' => '1',
			'dry_run'   => $dry_run ? '1' : '0',
		], admin_url( 'edit.php?post_type=tribe_events' ) ) );
		exit;
	}

	/**
	 * Enqueue admin styles.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'shelter-events-programs' ) ) {
			return;
		}

		wp_enqueue_style(
			'shelter-events-admin',
			SHELTER_EVENTS_URL . 'assets/css/admin.css',
			[],
			SHELTER_EVENTS_VERSION
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_page(): void {
		$config   = \Shelter_Events\Core\Config::get( 'events' );
		$programs = $config['programs'] ?? [];
		$gen      = $config['generation'] ?? [];
		$results  = get_transient( 'shelter_events_last_generation' );

		$next_cron = wp_next_scheduled( 'shelter_events_generate_recurring' );
		?>
		<div class="wrap shelter-events-admin">
			<h1><?php esc_html_e( 'Shelter Event Programs', 'shelter-events' ); ?></h1>

			<?php if ( isset( $_GET['generated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						$mode = ( $_GET['dry_run'] ?? '0' ) === '1'
							? __( 'Dry run complete — no events were created.', 'shelter-events' )
							: __( 'Events generated successfully!', 'shelter-events' );
						echo esc_html( $mode );
						?>
					</p>
				</div>
			<?php endif; ?>

			<!-- Program Overview -->
			<div class="shelter-card">
				<h2><?php esc_html_e( 'Configured Programs', 'shelter-events' ); ?></h2>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Program', 'shelter-events' ); ?></th>
							<th><?php esc_html_e( 'Days', 'shelter-events' ); ?></th>
							<th><?php esc_html_e( 'Time', 'shelter-events' ); ?></th>
							<th><?php esc_html_e( 'Venue', 'shelter-events' ); ?></th>
							<th><?php esc_html_e( 'Cost', 'shelter-events' ); ?></th>
							<th><?php esc_html_e( 'Category', 'shelter-events' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $programs as $slug => $prog ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $prog['title'] ); ?></strong>
									<br><code><?php echo esc_html( $slug ); ?></code>
								</td>
								<td><?php echo esc_html( implode( ', ', array_map( 'ucfirst', $prog['recurrence']['days'] ) ) ); ?></td>
								<td><?php echo esc_html( $prog['recurrence']['start_time'] . ' – ' . $prog['recurrence']['end_time'] ); ?></td>
								<td><?php echo esc_html( $prog['venue_slug'] ?? '—' ); ?></td>
								<td><?php echo esc_html( ( $prog['currency_symbol'] ?? '$' ) . ( $prog['cost'] ?? 'Free' ) ); ?></td>
								<td><?php echo esc_html( ucfirst( $prog['category'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<!-- Generate Form -->
			<div class="shelter-card">
				<h2><?php esc_html_e( 'Generate Events', 'shelter-events' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %d = lookahead weeks */
						esc_html__( 'Create event instances for the next %d weeks. The daily cron job does this automatically.', 'shelter-events' ),
						(int) ( $gen['lookahead_weeks'] ?? 8 )
					);
					?>
				</p>

				<?php if ( $next_cron ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s = formatted date */
							esc_html__( 'Next scheduled auto-generation: %s', 'shelter-events' ),
							esc_html( wp_date( 'F j, Y \a\t g:i A', $next_cron ) )
						);
						?>
					</p>
				<?php endif; ?>

				<form method="post">
					<?php wp_nonce_field( 'shelter_generate_events', 'shelter_generate_nonce' ); ?>

					<table class="form-table">
						<tr>
							<th><label for="program"><?php esc_html_e( 'Program', 'shelter-events' ); ?></label></th>
							<td>
								<select name="program" id="program">
									<option value=""><?php esc_html_e( '— All Programs —', 'shelter-events' ); ?></option>
									<?php foreach ( $programs as $slug => $prog ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>">
											<?php echo esc_html( $prog['title'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="weeks"><?php esc_html_e( 'Weeks Ahead', 'shelter-events' ); ?></label></th>
							<td>
								<input type="number" name="weeks" id="weeks" min="1" max="52"
									value="<?php echo esc_attr( (string) ( $gen['lookahead_weeks'] ?? 8 ) ); ?>"
									class="small-text" />
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Options', 'shelter-events' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="dry_run" value="1" />
									<?php esc_html_e( 'Dry run (preview only, no events created)', 'shelter-events' ); ?>
								</label>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Generate Now', 'shelter-events' ), 'primary', 'submit', true ); ?>
				</form>
			</div>

			<!-- Last Generation Results -->
			<?php if ( is_array( $results ) && ! empty( $results ) ) : ?>
				<div class="shelter-card">
					<h2><?php esc_html_e( 'Last Generation Results', 'shelter-events' ); ?></h2>
					<?php foreach ( $results as $slug => $events ) : ?>
						<h3><?php echo esc_html( $programs[ $slug ]['title'] ?? $slug ); ?></h3>
						<?php if ( empty( $events ) ) : ?>
							<p class="description"><?php esc_html_e( 'No new events to generate (all dates already exist).', 'shelter-events' ); ?></p>
						<?php else : ?>
							<ul class="shelter-results-list">
								<?php foreach ( $events as $event ) : ?>
									<li>
										<?php echo esc_html( $event['date'] ); ?>
										<?php if ( isset( $event['event_id'] ) ) : ?>
											— <a href="<?php echo esc_url( get_edit_post_link( $event['event_id'] ) ); ?>">
												<?php esc_html_e( 'Edit', 'shelter-events' ); ?>
											</a>
										<?php else : ?>
											<em><?php esc_html_e( '(dry run)', 'shelter-events' ); ?></em>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
