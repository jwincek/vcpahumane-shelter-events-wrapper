<?php
/**
 * Admin page for event generation — now reads programs from CPT.
 *
 * The program configuration itself lives in the shelter_program CPT
 * editor. This page just shows an overview and the Generate Now button.
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

	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=tribe_events',
			__( 'Generate Events', 'shelter-events' ),
			__( 'Generate Events', 'shelter-events' ),
			'manage_options',
			'shelter-events-generate',
			[ $this, 'render_page' ]
		);
	}

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

		$program_slug = sanitize_text_field( $_POST['program'] ?? '' );
		$weeks        = (int) ( $_POST['weeks'] ?? 8 );
		$dry_run      = ! empty( $_POST['dry_run'] );

		$args = [
			'program' => $program_slug ?: null,
			'weeks'   => $weeks,
			'dry_run' => $dry_run,
		];

		$results = \Shelter_Events\Abilities\Provider::handle_shelter_generate_events( $args );

		set_transient( 'shelter_events_last_generation', $results, 300 );

		wp_safe_redirect( add_query_arg( [
			'page'      => 'shelter-events-generate',
			'generated' => '1',
			'dry_run'   => $dry_run ? '1' : '0',
		], admin_url( 'edit.php?post_type=tribe_events' ) ) );
		exit;
	}

	public function enqueue_admin_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'shelter-events-generate' ) ) {
			return;
		}

		wp_enqueue_style(
			'shelter-events-admin',
			SHELTER_EVENTS_URL . 'assets/css/admin.css',
			[],
			SHELTER_EVENTS_VERSION
		);
	}

	public function render_page(): void {
		$programs  = \Shelter_Events\Core\Program_CPT::get_active_programs();
		$gen       = \Shelter_Events\Core\Config::get_item( 'events', 'generation', [] );
		$results   = get_transient( 'shelter_events_last_generation' );
		$next_cron = wp_next_scheduled( 'shelter_events_generate_recurring' );
		?>
		<div class="wrap shelter-events-admin">
			<h1><?php esc_html_e( 'Generate Shelter Events', 'shelter-events' ); ?></h1>

			<?php if ( isset( $_GET['generated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						echo esc_html(
							( $_GET['dry_run'] ?? '0' ) === '1'
								? __( 'Dry run complete — no events were created.', 'shelter-events' )
								: __( 'Events generated successfully!', 'shelter-events' )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<!-- Active Programs Overview -->
			<div class="shelter-card">
				<h2>
					<?php esc_html_e( 'Active Programs', 'shelter-events' ); ?>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=shelter_program' ) ); ?>"
						class="page-title-action">
						<?php esc_html_e( 'Manage Programs', 'shelter-events' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=shelter_program' ) ); ?>"
						class="page-title-action">
						<?php esc_html_e( 'Add New', 'shelter-events' ); ?>
					</a>
				</h2>

				<?php if ( empty( $programs ) ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s = URL to add new program */
							__( 'No active programs found. <a href="%s">Create one</a> to get started.', 'shelter-events' ),
							esc_url( admin_url( 'post-new.php?post_type=shelter_program' ) )
						);
						?>
					</p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Program', 'shelter-events' ); ?></th>
								<th><?php esc_html_e( 'Days', 'shelter-events' ); ?></th>
								<th><?php esc_html_e( 'Time', 'shelter-events' ); ?></th>
								<th><?php esc_html_e( 'Cost', 'shelter-events' ); ?></th>
								<th><?php esc_html_e( 'Venue', 'shelter-events' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $programs as $prog ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $prog['title'] ); ?></strong></td>
									<td>
										<?php
										echo esc_html( implode( ', ', array_map(
											fn( $d ) => ucfirst( substr( $d, 0, 3 ) ),
											$prog['recurrence']['days']
										) ) );
										?>
									</td>
									<td><?php echo esc_html( $prog['recurrence']['start_time'] . ' – ' . $prog['recurrence']['end_time'] ); ?></td>
									<td>
										<?php
										$cost = $prog['cost'];
										echo esc_html(
											( $cost === '0' || $cost === '' )
												? __( 'Free', 'shelter-events' )
												: ( $prog['currency_symbol'] ?? '$' ) . $cost
										);
										?>
									</td>
									<td><?php echo esc_html( $prog['venue']['venue'] ?? '—' ); ?></td>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $prog['post_id'] ) ); ?>">
											<?php esc_html_e( 'Edit', 'shelter-events' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<!-- Generate Form -->
			<div class="shelter-card">
				<h2><?php esc_html_e( 'Generate Events', 'shelter-events' ); ?></h2>
				<?php if ( $next_cron ) : ?>
					<p class="description">
						<?php
						printf(
							esc_html__( 'Next automatic generation: %s', 'shelter-events' ),
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
									<option value=""><?php esc_html_e( '— All Active Programs —', 'shelter-events' ); ?></option>
									<?php foreach ( $programs as $prog ) : ?>
										<option value="<?php echo esc_attr( $prog['slug'] ); ?>">
											<?php echo esc_html( $prog['title'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="weeks"><?php esc_html_e( 'Weeks ahead', 'shelter-events' ); ?></label></th>
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
									<?php esc_html_e( 'Dry run (preview only)', 'shelter-events' ); ?>
								</label>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Generate Now', 'shelter-events' ), 'primary', 'submit', true ); ?>
				</form>
			</div>

			<!-- Results -->
			<?php if ( is_array( $results ) && ! empty( $results['programs'] ?? [] ) ) : ?>
				<div class="shelter-card">
					<h2><?php esc_html_e( 'Last Generation Results', 'shelter-events' ); ?></h2>
					<?php foreach ( $results['programs'] as $slug => $events ) : ?>
						<h3><?php echo esc_html( $slug ); ?></h3>
						<?php if ( empty( $events ) ) : ?>
							<p class="description"><?php esc_html_e( 'No new events needed (all dates already exist).', 'shelter-events' ); ?></p>
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
