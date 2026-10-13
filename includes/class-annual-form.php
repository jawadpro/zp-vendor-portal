<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * The annual Conference Year form, reused every year.
 *
 * - The season (e.g. 2027) comes from the conferences: the newest year with a
 *   published conference on the annual form. Staff can override it in Settings.
 * - Form text uses {season}, {season_prev} and {season_next}; they are filled in
 *   when the form is shown, validated and saved, and in its emails and
 *   confirmation, so all of them always agree.
 * - A booth dropdown's description can be {conference_details}: dates, venue
 *   and city from that dropdown's conference for the season.
 * - A dropdown with no conference for the season is left off the form.
 * - Pages use [zpvp_season] / [zpvp_season offset="-1"] and {season} in titles.
 * - convert() turns a form with fixed years into this, once, with a backup.
 */
final class Annual_Form {

	public const OPT_SEASON = 'zpvp_annual_season';
	public const FALLBACK   = 'annual_description_fallback';
	public const DETAILS    = '{conference_details}';

	private static ?int $season = null;

	public function register(): void {
		add_filter( 'gform_pre_render', array( $this, 'prepare' ), 5 );
		add_filter( 'gform_pre_validation', array( $this, 'prepare' ), 5 );
		add_filter( 'gform_pre_submission_filter', array( $this, 'prepare' ), 5 );
		add_filter( 'gform_replace_merge_tags', array( $this, 'merge_tags' ), 10, 2 );

		foreach ( array( 'the_title', 'single_post_title', 'wpseo_title', 'wpseo_opengraph_title' ) as $hook ) {
			add_filter( $hook, array( $this, 'text' ), 20 );
		}
		add_filter( 'document_title_parts', array( $this, 'title_parts' ), 20 );
		add_shortcode( 'zpvp_season', array( $this, 'shortcode' ) );

		add_action( 'admin_post_zpvp_annual_season', array( $this, 'save_season' ) );
		add_action( 'admin_post_zpvp_annual_convert', array( $this, 'handle_convert' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Season                                                              */
	/* ------------------------------------------------------------------ */

	/** The year the annual form is taking bookings for. */
	public static function season(): int {
		if ( null === self::$season ) {
			$override     = self::override();
			self::$season = $override ? $override : self::detected_season();
		}
		return self::$season;
	}

	public static function override(): int {
		$year = (int) get_option( self::OPT_SEASON, 0 );
		return ( $year >= 2015 && $year <= 2100 ) ? $year : 0;
	}

	/** Newest year with a published conference on the annual form (any status if none is published). */
	public static function detected_season(): int {
		$year = self::newest_year( true );
		if ( ! $year ) {
			$year = self::newest_year( false );
		}
		if ( ! $year ) {
			$form = self::form();
			$year = ( $form && preg_match( '/\b(20\d{2})\b/', (string) $form['title'], $m ) ) ? (int) $m[1] : (int) gmdate( 'Y' );
		}
		return $year;
	}

	private static function newest_year( bool $published ): int {
		global $wpdb;
		$status = $published ? "p.post_status = 'publish'" : "p.post_status IN ('publish','future','draft','pending','private')";
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(CAST(y.meta_value AS UNSIGNED)) FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = 'series'
				JOIN {$wpdb->postmeta} a ON a.post_id = s.meta_value AND a.meta_key = 'annual_product_field' AND a.meta_value <> ''
				JOIN {$wpdb->postmeta} y ON y.post_id = p.ID AND y.meta_key = 'year'
				WHERE p.post_type = %s AND {$status}", // phpcs:ignore WordPress.DB.PreparedSQL -- fixed strings.
				Conferences::EDITION
			)
		);
	}

	public static function form(): ?array {
		$id   = Form_Map::annual_form_id();
		$form = ( $id && class_exists( '\GFAPI' ) ) ? \GFAPI::get_form( $id ) : null;
		return $form ? $form : null;
	}

	/** Fill in {season}, {season_prev} and {season_next}. */
	public static function replace( string $text ): string {
		if ( ! str_contains( $text, '{season' ) ) {
			return $text;
		}
		$s = self::season();
		return str_replace( array( '{season}', '{season_prev}', '{season_next}' ), array( (string) $s, (string) ( $s - 1 ), (string) ( $s + 1 ) ), $text );
	}

	/** The conference a booth dropdown books this season (0 = none, so the dropdown is left off). */
	public static function conference_for( string $field_id ): int {
		return Conferences::for_annual_field( $field_id, self::season() );
	}

	/** "April 27–30, 2027 · Janell Event Center, San Angelo" from the conference. */
	public static function details( int $edition_id ): string {
		$start = \DateTime::createFromFormat( 'Ymd', (string) get_post_meta( $edition_id, 'start_date', true ) );
		if ( $start ) {
			$end = \DateTime::createFromFormat( 'Ymd', (string) get_post_meta( $edition_id, 'end_date', true ) );
			if ( ! $end || $end->format( 'Ymd' ) <= $start->format( 'Ymd' ) ) {
				$range = $start->format( 'F j, Y' );
			} elseif ( $end->format( 'Y-m' ) === $start->format( 'Y-m' ) ) {
				$range = $start->format( 'F j' ) . '–' . $end->format( 'j, Y' );
			} elseif ( $end->format( 'Y' ) === $start->format( 'Y' ) ) {
				$range = $start->format( 'F j' ) . ' – ' . $end->format( 'F j, Y' );
			} else {
				$range = $start->format( 'F j, Y' ) . ' – ' . $end->format( 'F j, Y' );
			}
			$place = implode( ', ', array_filter( array( trim( (string) get_post_meta( $edition_id, 'venue', true ) ), trim( (string) get_post_meta( $edition_id, 'city', true ) ) ) ) );
			return $place ? $range . ' · ' . $place : $range;
		}
		$fallback = trim( (string) get_post_meta( $edition_id, self::FALLBACK, true ) );
		return ( '' !== $fallback && ! str_contains( $fallback, '{' ) ) ? $fallback : 'Dates to be announced';
	}

	/* ------------------------------------------------------------------ */
	/* Form display, validation and saving                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Runs on display, validation and submission with the same result, so the
	 * saved entry matches what the vendor saw. Works on copies of the fields so
	 * Gravity Forms' cached form is never changed.
	 */
	public function prepare( $form ) {
		if ( ! is_array( $form ) || (int) ( $form['id'] ?? 0 ) !== Form_Map::annual_form_id() || ! Form_Map::annual_form_id() ) {
			return $form;
		}
		$linked = array_flip( Conferences::annual_fields() );

		foreach ( array( 'title', 'description' ) as $key ) {
			if ( isset( $form[ $key ] ) && is_string( $form[ $key ] ) ) {
				$form[ $key ] = self::replace( $form[ $key ] );
			}
		}

		$fields = array();
		foreach ( (array) $form['fields'] as $original ) {
			$field = is_object( $original ) ? clone $original : $original;
			$id    = (string) ( is_object( $field ) ? $field->id : ( $field['id'] ?? '' ) );

			if ( isset( $linked[ $id ] ) ) {
				$conference = self::conference_for( $id );
				if ( ! $conference ) {
					continue; // No conference this season: not bookable.
				}
				if ( str_contains( (string) $field->description, self::DETAILS ) ) {
					$field->description = str_replace( self::DETAILS, esc_html( self::details( $conference ) ), (string) $field->description );
				}
			}

			foreach ( array( 'label', 'description', 'content', 'placeholder', 'defaultValue', 'checkboxLabel' ) as $prop ) {
				if ( is_string( $field->$prop ?? null ) && '' !== $field->$prop ) {
					$field->$prop = self::replace( $field->$prop );
				}
			}
			if ( is_array( $field->choices ?? null ) ) {
				$choices = $field->choices;
				foreach ( $choices as $i => $choice ) {
					foreach ( array( 'text', 'value' ) as $k ) {
						if ( isset( $choice[ $k ] ) && is_string( $choice[ $k ] ) ) {
							$choices[ $i ][ $k ] = self::replace( $choice[ $k ] );
						}
					}
				}
				$field->choices = $choices;
			}
			$fields[] = $field;
		}

		// Drop section headings left with nothing under them.
		$out   = array();
		$count = count( $fields );
		foreach ( $fields as $i => $field ) {
			if ( 'section' === $field->type ) {
				$next = $fields[ $i + 1 ] ?? null;
				if ( ! $next || in_array( $next->type, array( 'section', 'page' ), true ) ) {
					continue;
				}
			}
			$out[] = $field;
		}
		$form['fields'] = $out;
		return $form;
	}

	/** Emails, confirmation and anything else Gravity Forms runs merge tags on. */
	public function merge_tags( $text, $form ) {
		if ( is_string( $text ) && is_array( $form ) && (int) ( $form['id'] ?? 0 ) === Form_Map::annual_form_id() ) {
			return self::replace( $text );
		}
		return $text;
	}

	/** Page titles (the page, browser tab, Yoast and menus). */
	public function text( $text ) {
		return is_string( $text ) ? self::replace( $text ) : $text;
	}

	public function title_parts( $parts ) {
		if ( isset( $parts['title'] ) && is_string( $parts['title'] ) ) {
			$parts['title'] = self::replace( $parts['title'] );
		}
		return $parts;
	}

	/** [zpvp_season] or [zpvp_season offset="-1"]. */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'offset' => 0 ), (array) $atts );
		return (string) ( self::season() + (int) $atts['offset'] );
	}

	/* ------------------------------------------------------------------ */
	/* One-time conversion from fixed years                                */
	/* ------------------------------------------------------------------ */

	/**
	 * What "Make years automatic" changes (and, with $apply, does it).
	 *
	 * @return array{changes: array<int, array{where:string, before:string, after:string}>, error?: string, backup?: string}
	 */
	public static function convert( bool $apply = false ): array {
		$form = self::form();
		if ( ! $form ) {
			return array( 'changes' => array(), 'error' => 'No annual form is set up.' );
		}
		// Snapshot first: Gravity Forms hands out shared field objects, so they must be
		// copied before the loop below edits them.
		$snapshot = (string) wp_json_encode( $form );
		$s      = self::season();
		$tokens = array( (string) $s => '{season}', (string) ( $s - 1 ) => '{season_prev}', (string) ( $s + 1 ) => '{season_next}' );
		$codes  = array( (string) $s => '[zpvp_season]', (string) ( $s - 1 ) => '[zpvp_season offset="-1"]', (string) ( $s + 1 ) => '[zpvp_season offset="1"]' );
		$swap   = static fn( string $t, array $map ) => (string) preg_replace_callback( '/\b(20\d{2})\b/', static fn( $m ) => $map[ $m[1] ] ?? $m[1], $t );
		$short  = static fn( string $t ) => mb_strimwidth( trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $t ) ) ), 0, 110, '…' );
		$linked = array_flip( Conferences::annual_fields() );

		$changes   = array();
		$fallbacks = array();
		$note      = static function ( string $where, string $before, string $after ) use ( &$changes, $short ) {
			if ( $before !== $after ) {
				$changes[] = array( 'where' => $where, 'before' => $short( $before ), 'after' => $short( $after ) );
			}
		};

		foreach ( array( 'title' => 'Form title', 'description' => 'Form description' ) as $key => $label ) {
			$before = (string) ( $form[ $key ] ?? '' );
			$after  = $swap( $before, $tokens );
			$note( $label, $before, $after );
			$form[ $key ] = $after;
		}

		foreach ( $form['fields'] as $field ) {
			$id   = (string) $field->id;
			$name = wp_trim_words( wp_strip_all_tags( (string) $field->label ), 8, '…' );

			// A booked conference's description becomes its live details.
			if ( isset( $linked[ $id ] ) && '' !== trim( (string) $field->description ) && ! str_contains( (string) $field->description, self::DETAILS ) ) {
				$conference = self::conference_for( $id );
				if ( $conference ) {
					$fallbacks[ $conference ] = (string) $field->description;
					$note( $name . ' – description', (string) $field->description, 'Dates and venue from “' . get_the_title( $conference ) . '”' );
					$field->description = self::DETAILS;
				}
			}

			foreach ( array( 'label', 'description', 'content', 'placeholder', 'defaultValue', 'checkboxLabel' ) as $prop ) {
				if ( is_string( $field->$prop ?? null ) && '' !== $field->$prop ) {
					$after = $swap( $field->$prop, $tokens );
					$note( $name . ' – ' . $prop, $field->$prop, $after );
					$field->$prop = $after;
				}
			}
			if ( is_array( $field->choices ?? null ) ) {
				$choices = $field->choices;
				foreach ( $choices as $i => $choice ) {
					$text  = (string) ( $choice['text'] ?? '' );
					$after = $swap( $text, $tokens );
					$note( $name . ' – option', $text, $after );
					$choices[ $i ]['text']  = $after;
					$choices[ $i ]['value'] = $swap( (string) ( $choice['value'] ?? '' ), $tokens );
				}
				$field->choices = $choices;
			}
		}

		foreach ( array( 'notifications' => array( 'subject', 'message' ), 'confirmations' => array( 'message' ) ) as $group => $keys ) {
			foreach ( (array) ( $form[ $group ] ?? array() ) as $nid => $item ) {
				foreach ( $keys as $k ) {
					$before = (string) ( $item[ $k ] ?? '' );
					$after  = $swap( $before, $tokens );
					$note( ( 'notifications' === $group ? 'Email' : 'Confirmation' ) . ' “' . ( $item['name'] ?? $nid ) . '” – ' . $k, $before, $after );
					$form[ $group ][ $nid ][ $k ] = $after;
				}
			}
		}

		$pages = array();
		foreach ( self::pages( (int) $form['id'] ) as $page ) {
			$title   = $swap( $page->post_title, $tokens );
			$content = $swap( $page->post_content, $codes );
			$note( 'Page “' . $page->post_title . '” – title', $page->post_title, $title );
			$note( 'Page “' . $page->post_title . '” – text', $page->post_content, $content );
			if ( $title !== $page->post_title || $content !== $page->post_content ) {
				$pages[] = array( 'page' => $page, 'title' => $title, 'content' => $content );
			}
		}

		$result = array( 'changes' => $changes );
		if ( ! $apply || ! $changes ) {
			return $result;
		}

		// Backup: the untouched form and pages, as JSON.
		$dir = WP_CONTENT_DIR . '/zpvp-backups';
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
			file_put_contents( $dir . '/.htaccess', 'Deny from all' );
		}
		$backup = $dir . '/annual-form-' . (int) $form['id'] . '-' . gmdate( 'Ymd-His' ) . '.json';
		$pages_before = array_map( static fn( $p ) => array( 'ID' => $p['page']->ID, 'post_title' => $p['page']->post_title, 'post_content' => $p['page']->post_content ), $pages );
		file_put_contents( $backup, (string) wp_json_encode( array( 'form' => json_decode( $snapshot, true ), 'pages' => $pages_before ), JSON_PRETTY_PRINT ) );

		$saved = \GFAPI::update_form( $form );
		if ( is_wp_error( $saved ) ) {
			return $result + array( 'error' => $saved->get_error_message(), 'backup' => $backup );
		}
		foreach ( $fallbacks as $conference => $text ) {
			update_post_meta( $conference, self::FALLBACK, $text );
		}
		foreach ( $pages as $p ) {
			wp_update_post( array( 'ID' => $p['page']->ID, 'post_title' => $p['title'], 'post_content' => $p['content'] ) );
		}
		return $result + array( 'backup' => $backup );
	}

	/** Published pages that show the form. */
	private static function pages( int $form_id ): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish'
				AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s )",
				'%[gravityform%id="' . $form_id . '"%',
				"%[gravityform%id='" . $form_id . "'%",
				'%"formId":"' . $form_id . '"%'
			)
		);
		return array_filter( array_map( 'get_post', array_map( 'intval', $ids ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings actions                                                    */
	/* ------------------------------------------------------------------ */

	public function save_season(): void {
		if ( ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_annual_season' );
		$year = absint( $_POST['season'] ?? 0 );
		update_option( self::OPT_SEASON, ( $year >= 2015 && $year <= 2100 ) ? $year : 0, false );
		self::$season = null;
		$msg = $year ? sprintf( 'The annual form now takes bookings for %d.', $year ) : sprintf( 'The annual form season is automatic again (%d).', self::season() );
		wp_safe_redirect( Admin_Settings::url( 'forms', array( 'zpvp_msg' => rawurlencode( $msg ) ) ) );
		exit;
	}

	public function handle_convert(): void {
		if ( ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_annual_convert' );
		$result = self::convert( true );
		$msg    = ! empty( $result['error'] )
			? 'Could not update the annual form: ' . $result['error']
			: sprintf( 'Years are now automatic: %d places updated. A backup of the old form was saved.', count( $result['changes'] ) );
		wp_safe_redirect( Admin_Settings::url( 'forms', array( 'zpvp_msg' => rawurlencode( $msg ) ) ) );
		exit;
	}
}