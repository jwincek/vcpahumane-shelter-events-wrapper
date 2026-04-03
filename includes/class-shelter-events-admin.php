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

	/** @var string Option key for global blackout dates. */
	public const BLACKOUT_OPTION = 'shelter_events_blackout_dates';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_init', [ $this, 'handle_generate_action' ] );
		add_action( 'admin_init', [ $this, 'handle_blackout_save' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'admin_post_shelter_replace_event', [ $this, 'handle_replace_action' ] );
		add_filter( 'post_row_actions', [ $this, 'add_replace_row_action' ], 10, 2 );
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

	/**
	 * Save global blackout dates from the Generate Events page.
	 */
	public function handle_blackout_save(): void {
		if ( ! isset( $_POST['shelter_blackout_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['shelter_blackout_nonce'], 'shelter_save_blackout_dates' ) ) {
			wp_die( __( 'Security check failed.', 'shelter-events' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions.', 'shelter-events' ) );
		}

		$raw   = sanitize_textarea_field( $_POST['shelter_blackout_dates'] ?? '' );
		$dates = \Shelter_Events\Core\Program_CPT::parse_blackout_dates( $raw );

		update_option( self::BLACKOUT_OPTION, $dates );

		wp_safe_redirect( add_query_arg( [
			'page'             => 'shelter-events-generate',
			'blackout_updated' => '1',
		], admin_url( 'edit.php?post_type=tribe_events' ) ) );
		exit;
	}

	/**
	 * Get the global blackout dates as an array of Y-m-d strings.
	 *
	 * @return string[]
	 */
	public static function get_global_blackout_dates(): array {
		return get_option( self::BLACKOUT_OPTION, [] );
	}

	/**
	 * Add a "Replace" row action to shelter-generated events in the TEC events list.
	 */
	public function add_replace_row_action( array $actions, \WP_Post $post ): array {
		if ( $post->post_type !== 'tribe_events' ) {
			return $actions;
		}

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return $actions;
		}

		// Only for shelter-generated events.
		if ( ! get_post_meta( $post->ID, '_shelter_program_slug', true ) ) {
			return $actions;
		}

		// Already replaced or cancelled.
		if ( get_post_meta( $post->ID, '_shelter_replaced_by', true )
			|| get_post_meta( $post->ID, '_shelter_cancelled', true ) ) {
			return $actions;
		}

		// Only future events.
		$start = get_post_meta( $post->ID, '_EventStartDate', true );
		if ( $start && strtotime( $start ) < time() ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=shelter_replace_event&event_id=' . $post->ID ),
			'shelter_replace_event_' . $post->ID
		);

		$actions['shelter_replace'] = sprintf(
			'<a href="%s" class="shelter-replace-action">%s</a>',
			esc_url( $url ),
			esc_html__( 'Replace', 'shelter-events' )
		);

		return $actions;
	}

	/**
	 * Handle the "Replace" admin-post action — cancels the original, creates
	 * a draft replacement, and redirects to the TEC block editor.
	 */
	public function handle_replace_action(): void {
		$event_id = (int) ( $_GET['event_id'] ?? 0 );

		if ( ! $event_id
			|| ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'shelter_replace_event_' . $event_id ) ) {
			wp_die( __( 'Security check failed.', 'shelter-events' ) );
		}

		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( __( 'Insufficient permissions.', 'shelter-events' ) );
		}

		$result = \Shelter_Events\Abilities\Provider::handle_shelter_replace_event( [
			'event_id' => $event_id,
		] );

		if ( ! $result['success'] ) {
			wp_die( $result['error'] ?? __( 'Replace failed.', 'shelter-events' ) );
		}

		// Redirect to the block editor for the new replacement event.
		wp_safe_redirect( admin_url( 'post.php?post=' . $result['replacement_event_id'] . '&action=edit' ) );
		exit;
	}

	public function enqueue_admin_assets( string $hook ): void {
		$is_generate_page = str_contains( $hook, 'shelter-events-generate' );
		$is_events_list   = $hook === 'edit.php'
			&& ( get_current_screen()->post_type ?? '' ) === 'tribe_events';

		if ( ! $is_generate_page && ! $is_events_list ) {
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
			<h1>
				<?php esc_html_e( 'Generate Shelter Events', 'shelter-events' ); ?>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=tribe_events&page=shelter-events-help#staff-guide' ) ); ?>"
					class="page-title-action">
					<?php esc_html_e( 'Staff Guide & Help', 'shelter-events' ); ?>
				</a>
			</h1>

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

			<?php if ( isset( $_GET['blackout_updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Global blackout dates saved.', 'shelter-events' ); ?></p>
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
										$is_variable = ( $prog['meta']['_shelter_variable_pricing'] ?? 'no' ) === 'yes';
										if ( $is_variable ) :
											echo esc_html__( 'Varies', 'shelter-events' );
										else :
											$cost = $prog['cost'];
											echo esc_html(
												( $cost === '0' || $cost === '' )
													? __( 'Free', 'shelter-events' )
													: ( $prog['currency_symbol'] ?? '$' ) . $cost
											);
										endif;
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

			<!-- Upcoming Generated Events -->
			<?php if ( function_exists( 'tribe_events' ) && current_user_can( 'edit_others_posts' ) ) :
				$global_blackout_set = array_flip( self::get_global_blackout_dates() );
				$upcoming = get_posts( [
					'post_type'   => 'tribe_events',
					'post_status' => 'any',
					'numberposts' => 20,
					'orderby'     => 'meta_value',
					'meta_key'    => '_EventStartDate',
					'order'       => 'ASC',
					'meta_query'  => [
						'relation' => 'AND',
						[
							'key'     => '_shelter_program_slug',
							'compare' => 'EXISTS',
						],
						[
							'key'     => '_EventStartDate',
							'value'   => current_time( 'Y-m-d 00:00:00' ),
							'compare' => '>=',
							'type'    => 'DATETIME',
						],
					],
				] );
			?>
				<?php if ( ! empty( $upcoming ) ) : ?>
					<div class="shelter-card">
						<h2><?php esc_html_e( 'Upcoming Generated Events', 'shelter-events' ); ?></h2>
						<table class="wp-list-table widefat fixed striped shelter-upcoming-events">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Event', 'shelter-events' ); ?></th>
									<th><?php esc_html_e( 'Program', 'shelter-events' ); ?></th>
									<th><?php esc_html_e( 'Date', 'shelter-events' ); ?></th>
									<th><?php esc_html_e( 'Status', 'shelter-events' ); ?></th>
									<th><?php esc_html_e( 'Actions', 'shelter-events' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $upcoming as $event ) :
									$event_start   = get_post_meta( $event->ID, '_EventStartDate', true );
									$programme     = get_post_meta( $event->ID, '_shelter_program_slug', true );
									$cancelled     = (bool) get_post_meta( $event->ID, '_shelter_cancelled', true );
									$replaced_by   = (int) get_post_meta( $event->ID, '_shelter_replaced_by', true );
									$start_dt      = new DateTime( $event_start );
									$event_ymd     = $start_dt->format( 'Y-m-d' );
									$on_blackout   = isset( $global_blackout_set[ $event_ymd ] );
								?>
									<tr>
										<td><strong><?php echo esc_html( $event->post_title ); ?></strong></td>
										<td><?php echo esc_html( $programme ); ?></td>
										<td><?php echo esc_html( $start_dt->format( 'D, M j, Y — g:i A' ) ); ?></td>
										<td>
											<?php if ( $replaced_by ) : ?>
												<span class="shelter-status shelter-status--replaced">
													<?php esc_html_e( 'Replaced', 'shelter-events' ); ?>
												</span>
											<?php elseif ( $cancelled ) : ?>
												<span class="shelter-status shelter-status--cancelled">
													<?php esc_html_e( 'Cancelled', 'shelter-events' ); ?>
												</span>
											<?php elseif ( $on_blackout ) : ?>
												<span class="shelter-status shelter-status--blackout">
													<?php esc_html_e( 'Blackout', 'shelter-events' ); ?>
												</span>
											<?php else : ?>
												<span class="shelter-status shelter-status--active">
													<?php esc_html_e( 'Active', 'shelter-events' ); ?>
												</span>
											<?php endif; ?>
										</td>
										<td class="shelter-event-actions">
											<?php if ( $replaced_by ) : ?>
												<a href="<?php echo esc_url( get_edit_post_link( $replaced_by ) ); ?>">
													<?php esc_html_e( 'Edit Replacement', 'shelter-events' ); ?>
												</a>
											<?php elseif ( ! $cancelled ) : ?>
												<?php
												$replace_url = wp_nonce_url(
													admin_url( 'admin-post.php?action=shelter_replace_event&event_id=' . $event->ID ),
													'shelter_replace_event_' . $event->ID
												);
												?>
												<a href="<?php echo esc_url( $replace_url ); ?>" class="shelter-replace-action">
													<?php esc_html_e( 'Replace', 'shelter-events' ); ?>
												</a>
												<a href="<?php echo esc_url( get_edit_post_link( $event->ID ) ); ?>">
													<?php esc_html_e( 'Edit', 'shelter-events' ); ?>
												</a>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			<?php endif; ?>

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

			<!-- Global Blackout Dates -->
			<div class="shelter-card">
				<h2><?php esc_html_e( 'Global Blackout Dates', 'shelter-events' ); ?></h2>
				<p class="description" style="margin-top:0;">
					<?php esc_html_e( 'Events will not be generated on these dates for any program. Individual programs can also have their own blackout dates in their settings.', 'shelter-events' ); ?>
				</p>

				<form method="post">
					<?php wp_nonce_field( 'shelter_save_blackout_dates', 'shelter_blackout_nonce' ); ?>

					<?php
					$global_dates = self::get_global_blackout_dates();
					$dates_text   = ! empty( $global_dates ) ? implode( "\n", $global_dates ) : '';
					?>

					<div class="shelter-blackout-layout">
						<div class="shelter-blackout-layout__input">
							<textarea name="shelter_blackout_dates" rows="8" class="widefat"
								placeholder="<?php esc_attr_e( "2026-12-25\n2026-11-26\n2026-01-01", 'shelter-events' ); ?>"
							><?php echo esc_textarea( $dates_text ); ?></textarea>
							<span class="description">
								<?php esc_html_e( 'YYYY-MM-DD format, one date per line.', 'shelter-events' ); ?>
							</span>
						</div>

						<?php if ( ! empty( $global_dates ) ) : ?>
							<div class="shelter-blackout-layout__summary">
								<strong><?php esc_html_e( 'Current blackout dates:', 'shelter-events' ); ?></strong>
								<ul class="shelter-blackout-list">
									<?php foreach ( $global_dates as $d ) :
										$dt = \DateTime::createFromFormat( 'Y-m-d', $d );
									?>
										<li>
											<?php
											echo $dt
												? esc_html( $dt->format( 'l, F j, Y' ) )
												: esc_html( $d );
											?>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>
					</div>

					<?php submit_button( __( 'Save Blackout Dates', 'shelter-events' ), 'secondary', 'submit', true ); ?>
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
