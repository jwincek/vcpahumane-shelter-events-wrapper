<?php
/**
 * Help page — renders README.md as a staff-friendly guide in the dashboard.
 *
 * Adds an "Events → Help" submenu page that reads the plugin's README.md,
 * converts it from Markdown to HTML using a lightweight parser, and renders
 * it in a styled admin page. No external dependencies required.
 *
 * @package Shelter_Events
 */

declare( strict_types=1 );

class Shelter_Events_Help {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register the Help submenu page under Events.
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=tribe_events',
			__( 'Shelter Events — Help & Documentation', 'shelter-events' ),
			__( 'Staff Guide & Help', 'shelter-events' ),
			'edit_posts',
			'shelter-events-help',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Enqueue admin styles for the help page.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'shelter-events-help' ) ) {
			return;
		}

		wp_enqueue_style(
			'shelter-events-help',
			SHELTER_EVENTS_URL . 'assets/css/help.css',
			[],
			SHELTER_EVENTS_VERSION
		);
	}

	/**
	 * Render the help page.
	 */
	public function render_page(): void {
		$readme_path = SHELTER_EVENTS_DIR . 'README.md';

		if ( ! file_exists( $readme_path ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Help', 'shelter-events' ) . '</h1>';
			echo '<p>' . esc_html__( 'README.md not found.', 'shelter-events' ) . '</p></div>';
			return;
		}

		$markdown = file_get_contents( $readme_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$html     = self::parse_markdown( $markdown );

		?>
		<div class="wrap shelter-events-help">
			<h1><?php esc_html_e( 'Shelter Events — Help & Documentation', 'shelter-events' ); ?></h1>

			<div class="shelter-help__nav">
				<a href="#staff-guide" class="button"><?php esc_html_e( 'Staff Guide', 'shelter-events' ); ?></a>
				<a href="#developer-guide" class="button"><?php esc_html_e( 'Developer Guide', 'shelter-events' ); ?></a>
				<a href="#changelog" class="button"><?php esc_html_e( 'Changelog', 'shelter-events' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=shelter_program' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Manage Programs', 'shelter-events' ); ?>
				</a>
			</div>

			<div class="shelter-help__content">
				<?php echo wp_kses_post( $html ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Lightweight Markdown-to-HTML parser.
	 *
	 * Handles the subset of Markdown used in the README: headings, paragraphs,
	 * bold, italic, code, code blocks, links, lists, horizontal rules, and tables.
	 * No external library needed.
	 *
	 * @param string $markdown Raw Markdown text.
	 * @return string HTML.
	 */
	private static function parse_markdown( string $markdown ): string {
		// Normalize line endings.
		$markdown = str_replace( [ "\r\n", "\r" ], "\n", $markdown );

		// Extract fenced code blocks first to protect their content.
		$code_blocks = [];
		$markdown    = preg_replace_callback(
			'/```(\w*)\n(.*?)```/s',
			function ( $matches ) use ( &$code_blocks ) {
				$key                = '%%CODEBLOCK_' . count( $code_blocks ) . '%%';
				$lang               = $matches[1] ? ' class="language-' . esc_attr( $matches[1] ) . '"' : '';
				$code_blocks[ $key ] = '<pre><code' . $lang . '>' . esc_html( $matches[2] ) . '</code></pre>';
				return $key;
			},
			$markdown
		);

		// Split into lines for block-level processing.
		$lines  = explode( "\n", $markdown );
		$html   = '';
		$in_list = false;
		$in_table = false;
		$table_header_done = false;
		$paragraph = '';

		$flush_paragraph = function () use ( &$paragraph, &$html ) {
			if ( trim( $paragraph ) !== '' ) {
				$html .= '<p>' . self::inline_markup( trim( $paragraph ) ) . '</p>' . "\n";
				$paragraph = '';
			}
		};

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			// Code block placeholder — output directly.
			if ( str_starts_with( $trimmed, '%%CODEBLOCK_' ) && str_ends_with( $trimmed, '%%' ) ) {
				$flush_paragraph();
				if ( $in_list ) { $html .= "</ul>\n"; $in_list = false; }
				if ( $in_table ) { $html .= "</tbody></table>\n"; $in_table = false; }
				// Placeholder is replaced later.
				$html .= $trimmed . "\n";
				continue;
			}

			// Horizontal rule.
			if ( preg_match( '/^(-{3,}|\*{3,})$/', $trimmed ) ) {
				$flush_paragraph();
				if ( $in_list ) { $html .= "</ul>\n"; $in_list = false; }
				if ( $in_table ) { $html .= "</tbody></table>\n"; $in_table = false; }
				$html .= "<hr>\n";
				continue;
			}

			// Headings.
			if ( preg_match( '/^(#{1,6})\s+(.+)$/', $trimmed, $m ) ) {
				$flush_paragraph();
				if ( $in_list ) { $html .= "</ul>\n"; $in_list = false; }
				if ( $in_table ) { $html .= "</tbody></table>\n"; $in_table = false; }
				$level = strlen( $m[1] );
				$text  = self::inline_markup( $m[2] );
				$id    = sanitize_title( wp_strip_all_tags( $m[2] ) );
				$html .= "<h{$level} id=\"{$id}\">{$text}</h{$level}>\n";
				continue;
			}

			// Table rows.
			if ( str_starts_with( $trimmed, '|' ) && str_ends_with( $trimmed, '|' ) ) {
				$flush_paragraph();
				if ( $in_list ) { $html .= "</ul>\n"; $in_list = false; }

				// Separator row (|---|---|) — skip, just mark header done.
				if ( preg_match( '/^\|[\s\-:|]+\|$/', $trimmed ) ) {
					$table_header_done = true;
					continue;
				}

				$cells = array_map( 'trim', explode( '|', trim( $trimmed, '|' ) ) );

				if ( ! $in_table ) {
					$html .= "<table class=\"wp-list-table widefat fixed striped\">\n<thead><tr>\n";
					foreach ( $cells as $cell ) {
						$html .= '<th>' . self::inline_markup( $cell ) . '</th>';
					}
					$html .= "</tr></thead>\n<tbody>\n";
					$in_table = true;
					$table_header_done = false;
					continue;
				}

				$html .= '<tr>';
				foreach ( $cells as $cell ) {
					$html .= '<td>' . self::inline_markup( $cell ) . '</td>';
				}
				$html .= "</tr>\n";
				continue;
			}

			// Close table if we hit a non-table line.
			if ( $in_table && ! str_starts_with( $trimmed, '|' ) ) {
				$html .= "</tbody></table>\n";
				$in_table = false;
				$table_header_done = false;
			}

			// Unordered list items.
			if ( preg_match( '/^[\-\*]\s+(.+)$/', $trimmed, $m ) ) {
				$flush_paragraph();
				if ( ! $in_list ) {
					$html .= "<ul>\n";
					$in_list = true;
				}
				$html .= '<li>' . self::inline_markup( $m[1] ) . "</li>\n";
				continue;
			}

			// Ordered list items.
			if ( preg_match( '/^\d+\.\s+(.+)$/', $trimmed, $m ) ) {
				$flush_paragraph();
				if ( ! $in_list ) {
					$html .= "<ol>\n";
					$in_list = true;
				}
				$html .= '<li>' . self::inline_markup( $m[1] ) . "</li>\n";
				continue;
			}

			// Close list if we hit a non-list line.
			if ( $in_list && ! preg_match( '/^[\-\*]\s/', $trimmed ) && ! preg_match( '/^\d+\./', $trimmed ) ) {
				// Check if it's ol or ul by looking back.
				$html .= str_contains( $html, '<ol>' ) && ! str_contains( $html, '</ol>' ) ? "</ol>\n" : "</ul>\n";
				$in_list = false;
			}

			// Empty line — flush paragraph.
			if ( $trimmed === '' ) {
				$flush_paragraph();
				continue;
			}

			// Regular text — accumulate into paragraph.
			$paragraph .= ( $paragraph !== '' ? ' ' : '' ) . $trimmed;
		}

		// Flush remaining state.
		$flush_paragraph();
		if ( $in_list ) { $html .= "</ul>\n"; }
		if ( $in_table ) { $html .= "</tbody></table>\n"; }

		// Restore code blocks.
		foreach ( $code_blocks as $key => $replacement ) {
			$html = str_replace( $key, $replacement, $html );
		}

		return $html;
	}

	/**
	 * Apply inline Markdown formatting.
	 *
	 * @param string $text Text to process.
	 * @return string HTML with inline formatting applied.
	 */
	private static function inline_markup( string $text ): string {
		// Inline code (must come first to protect content inside backticks).
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );

		// Bold + italic.
		$text = preg_replace( '/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $text );

		// Bold.
		$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );

		// Italic.
		$text = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $text );

		// Links.
		$text = preg_replace(
			'/\[([^\]]+)\]\(([^)]+)\)/',
			'<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>',
			$text
		);

		return $text;
	}
}
