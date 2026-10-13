<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Conferences: one record per yearly event (post type zp_conf_edition).
 *
 * Each conference chooses its own Gravity Forms registration form(s) and, if
 * it is on the annual Conference Year form, its booth dropdown there. New
 * registrations from a form go to the newest published conference that uses
 * it (drafts only as a fallback, so nothing is ever dropped).
 *
 * zp_conf_series is kept as a hidden, automatically maintained grouping of the
 * same event across years ("conference name"). Staff never see it; it keeps
 * series_id in the registrations table, reports and duplicate protection working.
 */
final class Conferences {

	public const SERIES  = 'zp_conf_series';
	public const EDITION = 'zp_conf_edition';

	private const MODEL_OPTION = 'zpvp_conference_model';

	public const STATUSES = array(
		'not_scheduled' => 'Not scheduled',
		'upcoming'      => 'Registration not open yet',
		'open'          => 'Open',
		'sold_out'      => 'Sold out',
		'closed'        => 'Registration closed',
		'past'          => 'Past',
	);

	public function register(): void {
		$this->post_types();
		self::maybe_migrate();
		self::maybe_migrate_v3();

		if ( function_exists( 'acf_add_local_field_group' ) ) {
			if ( did_action( 'acf/init' ) ) {
				$this->fields();
			} else {
				add_action( 'acf/init', array( $this, 'fields' ) );
			}
		}

		add_action( 'admin_post_zpvp_start_season', array( $this, 'start_season_action' ) );
		add_action( 'acf/save_post', array( $this, 'after_save' ), 20 );
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );

		add_filter( 'manage_' . self::EDITION . '_posts_columns', array( $this, 'edition_columns' ) );
		add_action( 'manage_' . self::EDITION . '_posts_custom_column', array( $this, 'edition_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'add_meta_boxes_' . self::EDITION, array( $this, 'meta_boxes' ) );
		add_action( 'admin_post_zpvp_copy_conference', array( $this, 'copy_conference' ) );
		add_action( 'admin_notices', array( $this, 'copy_notice' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Post types and fields                                               */
	/* ------------------------------------------------------------------ */

	private function post_types(): void {
		$common = array(
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => 'zpvp',
			'show_in_rest'        => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => array( 'title' ),
			'delete_with_user'    => false, // Deleting a WordPress user must never delete conferences or series.
		);

		// Series screens are reached from Vendor Portal → Settings → Conference series.
		register_post_type(
			self::SERIES,
			array(
				'show_ui'      => true,
				'show_in_menu' => false,
			) + $common + array(
				'labels' => array(
					'name'          => 'Conference Series',
					'singular_name' => 'Conference Series',
					'add_new_item'  => 'Add Conference Series',
					'edit_item'     => 'Edit Conference Series',
					'all_items'     => 'Conference Series',
					'not_found'     => 'No series found.',
				),
			)
		);

		register_post_type(
			self::EDITION,
			$common + array(
				'labels' => array(
					'name'          => 'Conferences',
					'singular_name' => 'Conference',
					'menu_name'     => 'Conferences',
					'all_items'     => 'Conferences',
					'add_new_item'  => 'Add Conference',
					'edit_item'     => 'Edit Conference',
					'search_items'  => 'Search Conferences',
					'not_found'     => 'No conferences found.',
				),
			)
		);
	}

	public function fields(): void {
		acf_add_local_field_group(
			array(
				'key'      => 'group_zpvp_edition',
				'title'    => 'Conference Details',
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => self::EDITION ) ) ),
				'fields'   => array(
					array( 'key' => 'field_zpvp_ed_tab_main', 'label' => 'Event', 'type' => 'tab' ),
					array(
						'key'           => 'field_zpvp_ed_series',
						'name'          => 'series',
						'label'         => 'Conference series',
						'type'          => 'post_object',
						'post_type'     => array( self::SERIES ),
						'return_format' => 'id',
						'ui'            => 1,
						'required'      => 1,
						'instructions'  => 'The event this conference is a year of. Its registration, sponsorship and annual-form settings come from the series.',
						'wrapper'       => array( 'width' => '70' ),
					),
					array(
						'key'      => 'field_zpvp_ed_year',
						'name'     => 'year',
						'label'    => 'Year',
						'type'     => 'number',
						'required' => 1,
						'min'      => 2015,
						'max'      => 2100,
						'wrapper'  => array( 'width' => '30' ),
					),
					array(
						'key'            => 'field_zpvp_ed_start',
						'name'           => 'start_date',
						'label'          => 'Start date',
						'type'           => 'date_picker',
						'display_format' => 'm/d/Y',
						'return_format'  => 'Y-m-d',
						'wrapper'        => array( 'width' => '50' ),
					),
					array(
						'key'            => 'field_zpvp_ed_end',
						'name'           => 'end_date',
						'label'          => 'End date',
						'type'           => 'date_picker',
						'display_format' => 'm/d/Y',
						'return_format'  => 'Y-m-d',
						'wrapper'        => array( 'width' => '50' ),
					),
					array( 'key' => 'field_zpvp_ed_venue', 'name' => 'venue', 'label' => 'Venue', 'type' => 'text', 'wrapper' => array( 'width' => '50' ) ),
					array( 'key' => 'field_zpvp_ed_city', 'name' => 'city', 'label' => 'City', 'type' => 'text', 'wrapper' => array( 'width' => '50' ) ),

					array( 'key' => 'field_zpvp_ed_tab_reg', 'label' => 'Registration', 'type' => 'tab' ),
					array(
						'key'          => 'field_zpvp_ed_reg_url',
						'name'         => 'registration_url',
						'label'        => 'Registration page (override)',
						'type'         => 'page_link',
						'post_type'    => array( 'page' ),
						'allow_null'   => 1,
						'instructions' => 'Optional. Leave empty and the page showing this conference\'s registration form is used automatically.',
					),
					array(
						'key'            => 'field_zpvp_ed_opens',
						'name'           => 'registration_opens',
						'label'          => 'Registration opens',
						'type'           => 'date_time_picker',
						'display_format' => 'm/d/Y g:i a',
						'return_format'  => 'Y-m-d H:i:s',
						'wrapper'        => array( 'width' => '50' ),
					),
					array(
						'key'            => 'field_zpvp_ed_closes',
						'name'           => 'registration_closes',
						'label'          => 'Registration closes',
						'type'           => 'date_time_picker',
						'display_format' => 'm/d/Y g:i a',
						'return_format'  => 'Y-m-d H:i:s',
						'wrapper'        => array( 'width' => '50' ),
					),
					array(
						'key'            => 'field_zpvp_ed_edit_deadline',
						'name'           => 'attendee_edit_deadline',
						'label'          => 'Attendee edit deadline',
						'type'           => 'date_time_picker',
						'display_format' => 'm/d/Y g:i a',
						'return_format'  => 'Y-m-d H:i:s',
						'instructions'   => 'Vendors can change their booth personnel until this time.',
						'wrapper'        => array( 'width' => '50' ),
					),
					array(
						'key'          => 'field_zpvp_ed_capacity',
						'name'         => 'booth_capacity',
						'label'        => 'Booth capacity',
						'type'         => 'number',
						'min'          => 0,
						'instructions' => 'Total booths available. Leave empty for no limit.',
						'wrapper'      => array( 'width' => '50' ),
					),
					array(
						'key'           => 'field_zpvp_ed_status_override',
						'name'          => 'status_override',
						'label'         => 'Status',
						'type'          => 'select',
						'choices'       => array(
							'auto'     => 'Automatic (from dates and capacity)',
							'open'     => 'Force open',
							'sold_out' => 'Sold out',
							'closed'   => 'Closed',
						),
						'default_value' => 'auto',
						'instructions'  => 'Leave on Automatic unless you need to override it.',
					),

					array( 'key' => 'field_zpvp_ed_tab_resources', 'label' => 'Resources', 'type' => 'tab' ),
					array( 'key' => 'field_zpvp_ed_hotel', 'name' => 'hotel_info', 'label' => 'Hotel information', 'type' => 'wysiwyg', 'media_upload' => 0, 'toolbar' => 'basic' ),
					array( 'key' => 'field_zpvp_ed_floor_plan', 'name' => 'floor_plan', 'label' => 'Floor plan', 'type' => 'file', 'return_format' => 'id' ),
					array( 'key' => 'field_zpvp_ed_decorator', 'name' => 'decorator_info', 'label' => 'Decorator information', 'type' => 'wysiwyg', 'media_upload' => 0, 'toolbar' => 'basic' ),
					array( 'key' => 'field_zpvp_ed_electricity', 'name' => 'electricity_info', 'label' => 'Electricity information', 'type' => 'wysiwyg', 'media_upload' => 0, 'toolbar' => 'basic' ),
					array(
						'key'          => 'field_zpvp_ed_files',
						'name'         => 'resource_files',
						'label'        => 'Other files',
						'type'         => 'repeater',
						'layout'       => 'table',
						'button_label' => 'Add file',
						'sub_fields'   => array(
							array( 'key' => 'field_zpvp_ed_file_label', 'name' => 'label', 'label' => 'Label', 'type' => 'text' ),
							array( 'key' => 'field_zpvp_ed_file_file', 'name' => 'file', 'label' => 'File', 'type' => 'file', 'return_format' => 'id' ),
						),
					),
				),
			)
		);
	}

	/** Gravity Forms by name, active forms first. */
	public function form_choices( array $field ): array {
		$field['choices'] = array();
		if ( ! class_exists( '\GFAPI' ) ) {
			return $field;
		}
		$forms = \GFAPI::get_forms( null, false, 'title', 'ASC' );
		usort( $forms, static fn( $a, $b ) => (int) ! empty( $b['is_active'] ) <=> (int) ! empty( $a['is_active'] ) );
		foreach ( $forms as $form ) {
			if ( ! empty( $form[ Setup::FORM_KEY ] ) || (int) $form['id'] === Form_Map::annual_form_id() ) {
				continue;
			}
			$field['choices'][ (string) $form['id'] ] = $form['title'] . ' (form ' . $form['id'] . ')' . ( $form['is_active'] ? '' : ' – switched off' );
		}
		return $field;
	}

	/** Booth dropdowns on the annual form, by label. */
	public function annual_choices( array $field ): array {
		$field['choices'] = self::annual_dropdowns();
		$annual           = Form_Map::annual_form_id();
		if ( ! $annual ) {
			$field['instructions'] = 'No annual Conference Year form is set up, so there is nothing to choose.';
		} elseif ( class_exists( '\GFAPI' ) && ( $form = \GFAPI::get_form( $annual ) ) ) {
			$field['instructions'] .= ' Annual form: ' . Annual_Form::replace( (string) $form['title'] ) . '.';
		}
		return $field;
	}

	/** @return array<string, string> field id => label */
	public static function annual_dropdowns(): array {
		$out    = array();
		$annual = Form_Map::annual_form_id();
		$form   = ( $annual && class_exists( '\GFAPI' ) ) ? \GFAPI::get_form( $annual ) : null;
		if ( ! $form ) {
			return $out;
		}
		foreach ( $form['fields'] as $f ) {
			if ( 'product' === $f->type && in_array( $f->inputType, array( 'select', 'radio' ), true ) ) {
				$out[ (string) $f->id ] = wp_trim_words( wp_strip_all_tags( (string) $f->label ), 14, '…' );
			}
		}
		return $out;
	}

	public function after_save( $post_id ): void {
		if ( is_numeric( $post_id ) && self::EDITION === get_post_type( (int) $post_id ) ) {
			self::finalise( (int) $post_id );
		}
	}

	/** Keep the hidden grouping and the "Name Year" title in step with the fields. */
	public static function finalise( int $id ): void {
		$series = (int) get_post_meta( $id, 'series', true );
		if ( $series && Conferences::SERIES === get_post_type( $series ) ) {
			update_post_meta( $id, 'conference_name', get_post_field( 'post_title', $series ) );
		} else {
			self::sync_group( $id );
		}
		$name = trim( (string) get_post_meta( $id, 'conference_name', true ) );
		$year = (int) get_post_meta( $id, 'year', true );
		if ( '' === $name || ! $year ) {
			return;
		}
		$title = $name . ' ' . $year;
		if ( get_post_field( 'post_title', $id ) !== $title ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => $title,
					'post_name'  => sanitize_title( $title ),
				)
			);
		}
	}

	/** Point the conference at the hidden group for its name, creating or renaming it as needed. */
	private static function sync_group( int $id ): void {
		$name = trim( (string) get_post_meta( $id, 'conference_name', true ) );
		if ( '' === $name ) {
			return;
		}
		$current = (int) get_post_meta( $id, 'series', true );
		if ( $current && get_post_field( 'post_title', $current ) === $name ) {
			return;
		}
		$found = self::group_by_name( $name );
		if ( ! $found && $current && get_post( $current ) && self::group_size( $current ) <= 1 ) {
			// Only this conference uses the group: a rename, not a new event.
			wp_update_post( array( 'ID' => $current, 'post_title' => $name ) );
			return;
		}
		if ( ! $found ) {
			$found = (int) wp_insert_post(
				array(
					'post_type'   => self::SERIES,
					'post_status' => 'publish',
					'post_title'  => $name,
				)
			);
		}
		if ( $found ) {
			update_post_meta( $id, 'series', $found );
		}
	}

	public static function group_by_name( string $name ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_title = %s ORDER BY ID ASC LIMIT 1",
				self::SERIES,
				$name
			)
		);
	}

	private static function group_size( int $group ): int {
		return count( self::query( array( array( 'key' => 'series', 'value' => $group ) ) ) );
	}

	public function title_placeholder( $text, $post ) {
		if ( $post instanceof \WP_Post && self::EDITION === $post->post_type ) {
			return 'Filled in automatically from the conference name and year';
		}
		return $text;
	}

	/* ------------------------------------------------------------------ */
	/* Which conference a form belongs to                                  */
	/* ------------------------------------------------------------------ */

	/** Conferences in any working status, published first, then newest year. */
	private static function query( array $meta_query ): array {
		$ids = get_posts(
			array(
				'post_type'        => self::EDITION,
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'meta_query'       => $meta_query,
				'suppress_filters' => true,
			)
		);
		usort(
			$ids,
			static function ( $a, $b ) {
				$pa = 'publish' === get_post_status( $a ) ? 1 : 0;
				$pb = 'publish' === get_post_status( $b ) ? 1 : 0;
				return array( $pb, (int) get_post_meta( $b, 'year', true ), $b ) <=> array( $pa, (int) get_post_meta( $a, 'year', true ), $a );
			}
		);
		return array_map( 'intval', $ids );
	}

	/** All conferences that list a form. */
	public static function using_form( int $form_id ): array {
		return $form_id ? self::of_series( self::series_with( 'gf_forms', '"' . $form_id . '"', 'LIKE' ) ) : array();
	}

	/** Published series whose meta matches. */
	public static function series_with( string $key, string $value, string $compare = '=' ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => self::SERIES,
					'post_status'      => 'publish',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'suppress_filters' => true,
					'meta_query'       => array( array( 'key' => $key, 'value' => $value, 'compare' => $compare ) ),
				)
			)
		);
	}

	/** Conferences of these series (published first, then newest year), optionally one year. */
	public static function of_series( array $series, int $year = 0 ): array {
		if ( ! $series ) {
			return array();
		}
		$meta = array( array( 'key' => 'series', 'value' => array_map( 'intval', $series ), 'compare' => 'IN' ) );
		if ( $year ) {
			$meta[] = array( 'key' => 'year', 'value' => $year );
		}
		return self::query( $meta );
	}

	/** The series that lists a form as its registration form. */
	public static function series_for_form( int $form_id ): int {
		return $form_id ? ( self::series_with( 'gf_forms', '"' . $form_id . '"', 'LIKE' )[0] ?? 0 ) : 0;
	}

	/** The series that lists a form as its sponsorship form. */
	public static function series_for_sponsorship_form( int $form_id ): int {
		return $form_id ? ( self::series_with( 'sponsorship_forms', '"' . $form_id . '"', 'LIKE' )[0] ?? 0 ) : 0;
	}

	/** The conference that records new registrations from a form: the newest published one using it. */
	public static function for_form( int $form_id ): int {
		return self::using_form( $form_id )[0] ?? 0;
	}

	/** All conferences that chose a form as their sponsorship form. */
	public static function using_sponsorship_form( int $form_id ): array {
		return $form_id ? self::of_series( self::series_with( 'sponsorship_forms', '"' . $form_id . '"', 'LIKE' ) ) : array();
	}

	/** The conference that records new sponsorships from a form (same rule as registrations). */
	public static function for_sponsorship_form( int $form_id ): int {
		return self::using_sponsorship_form( $form_id )[0] ?? 0;
	}

	/** Sponsorship form IDs chosen on a conference. */
	public static function sponsorship_forms( int $edition_id ): array {
		return Series::form_ids( (int) get_post_meta( $edition_id, 'series', true ), 'sponsorship_forms' );
	}

	/** The conference for an annual-form booth dropdown in a given year (0 = any year). */
	public static function for_annual_field( string $field_id, int $year = 0 ): int {
		if ( '' === $field_id ) {
			return 0;
		}
		return self::of_series( self::series_with( 'annual_product_field', $field_id ), $year )[0] ?? 0;
	}

	/** @return string[] annual-form booth dropdowns chosen on any conference. */
	public static function annual_fields(): array {
		global $wpdb;
		return array_values(
			array_filter(
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
						WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_key = 'annual_product_field' AND pm.meta_value <> ''",
						self::SERIES
					)
				),
				'strlen'
			)
		);
	}

	/** True once at least one conference has a form or annual dropdown chosen. */
	public static function has_links(): bool {
		return (bool) ( self::series_with( 'gf_forms', '"', 'LIKE' ) || self::series_with( 'sponsorship_forms', '"', 'LIKE' ) || self::series_with( 'annual_product_field', '', '!=' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Registration page                                                   */
	/* ------------------------------------------------------------------ */

	/** Form IDs chosen on the conference, active forms first. */
	public static function forms( int $edition_id ): array {
		$forms = Series::form_ids( (int) get_post_meta( $edition_id, 'series', true ), 'gf_forms' );
		if ( class_exists( '\GFAPI' ) ) {
			usort(
				$forms,
				static function ( $a, $b ) {
					$fa = \GFAPI::get_form( $a );
					$fb = \GFAPI::get_form( $b );
					return (int) ! empty( $fb['is_active'] ) <=> (int) ! empty( $fa['is_active'] );
				}
			);
		}
		return $forms;
	}

	/** @deprecated 0.16.0 Use forms(). */
	public static function series_forms( int $edition_id ): array {
		return self::forms( $edition_id );
	}

	/** The published page that shows one of the conference's forms (shortcode or block). */
	public static function detect_registration_page( int $edition_id ): array {
		global $wpdb;
		foreach ( self::forms( $edition_id ) as $form_id ) {
			$page = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish'
					AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s )
					ORDER BY post_modified DESC LIMIT 1",
					'%[gravityform%id="' . $form_id . '"%',
					"%[gravityform%id='" . $form_id . "'%",
					'%"formId":"' . $form_id . '"%'
				)
			);
			if ( $page ) {
				return array( 'page' => $page, 'form' => $form_id );
			}
		}
		return array( 'page' => 0, 'form' => 0 );
	}

	/** Where the Register button goes: the manual override, otherwise the detected page. */
	public static function registration_page_url( int $edition_id ): string {
		$manual = (string) get_post_meta( $edition_id, 'registration_url', true );
		if ( '' !== $manual ) {
			return is_numeric( $manual ) ? (string) get_permalink( (int) $manual ) : $manual;
		}
		$found = self::detect_registration_page( $edition_id );
		return $found['page'] ? (string) get_permalink( $found['page'] ) : '';
	}

	public function meta_boxes(): void {
		add_meta_box( 'zpvp-regpage', 'Registration', array( $this, 'registration_box' ), self::EDITION, 'side', 'high' );
		add_meta_box( 'zpvp-copy', 'Next year', array( $this, 'copy_box' ), self::EDITION, 'side' );
	}

	/** Plain-language summary of where this conference's registrations come from and go. */
	public function registration_box( $post ): void {
		$id     = (int) $post->ID;
		$forms  = self::forms( $id );
		$annual = (string) Series::annual_field( $id );
		$ok     = 'color:#1e6b34';
		$warn   = 'color:#9c1f25';

		$sponsor = self::sponsorship_forms( $id );
		if ( ! $forms && '' === $annual && ! $sponsor ) {
			$series = (int) get_post_meta( $id, 'series', true );
			echo $series
				? '<p><strong>No forms yet.</strong> Choose them on the series <a href="' . esc_url( (string) get_edit_post_link( $series, 'url' ) ) . '">' . esc_html( get_the_title( $series ) ) . '</a>.</p>'
				: '<p><strong>Choose this conference\'s series</strong> on the Event tab, then click Update.</p>';
			return;
		}

		$series = (int) get_post_meta( $id, 'series', true );
		echo '<p class="description">Forms come from the series <a href="' . esc_url( (string) get_edit_post_link( $series, 'url' ) ) . '">' . esc_html( get_the_title( $series ) ) . '</a>. Change them there.</p>';
		$here = false;
		foreach ( $forms as $form_id ) {
			$form   = class_exists( '\GFAPI' ) ? \GFAPI::get_form( $form_id ) : null;
			$target = self::for_form( $form_id );
			echo '<p><strong>' . esc_html( $form ? $form['title'] : 'Form ' . $form_id ) . '</strong> <span class="description">(form ' . (int) $form_id . ')</span><br>';
			if ( ! $form ) {
				echo '<span style="' . esc_attr( $warn ) . '">This form no longer exists in Gravity Forms.</span>';
			} elseif ( $target === $id ) {
				$here = true;
				echo '<span style="' . esc_attr( $ok ) . '">✓ New registrations from this form are recorded here.</span>';
			} elseif ( $target ) {
				echo '<span style="' . esc_attr( $warn ) . '">New registrations from this form go to <a href="' . esc_url( (string) get_edit_post_link( $target, 'url' ) ) . '">' . esc_html( get_the_title( $target ) ) . '</a>, the newest published conference using it.</span>';
			}
			if ( $form && empty( $form['is_active'] ) ) {
				echo '<br><span style="' . esc_attr( $warn ) . '">Switched off in Gravity Forms. Switch it on under Forms before registration opens.</span>';
			}
			echo '</p>';
		}

		foreach ( $sponsor as $form_id ) {
			$form   = class_exists( '\GFAPI' ) ? \GFAPI::get_form( $form_id ) : null;
			$target = self::for_sponsorship_form( $form_id );
			echo '<p><strong>' . esc_html( $form ? $form['title'] : 'Form ' . $form_id ) . '</strong> <span class="description">(sponsorship, form ' . (int) $form_id . ')</span><br>';
			if ( ! $form ) {
				echo '<span style="' . esc_attr( $warn ) . '">This form no longer exists in Gravity Forms.</span>';
			} elseif ( $target === $id ) {
				echo '<span style="' . esc_attr( $ok ) . '">✓ New sponsorships from this form are recorded here.</span>';
			} elseif ( $target ) {
				echo '<span style="' . esc_attr( $warn ) . '">New sponsorships from this form go to <a href="' . esc_url( (string) get_edit_post_link( $target, 'url' ) ) . '">' . esc_html( get_the_title( $target ) ) . '</a>, the newest published conference using it.</span>';
			}
			if ( $form && empty( $form['is_active'] ) ) {
				echo '<br><span style="' . esc_attr( $warn ) . '">Switched off in Gravity Forms.</span>';
			}
			echo '</p>';
		}

		if ( '' !== $annual ) {
			$labels = self::annual_dropdowns();
			$label  = $labels[ $annual ] ?? 'field ' . $annual;
			$season = Annual_Form::season();
			echo '<p><strong>Annual form</strong><br>Vendors can also book this conference with the “' . esc_html( $label ) . '” dropdown.<br>';
			echo (int) get_post_meta( $id, 'year', true ) === $season
				? '<span style="' . esc_attr( $ok ) . '">✓ The annual form is taking bookings for ' . (int) $season . ', so those bookings are recorded here. Its dates and venue are shown on the form.</span>'
				: 'The annual form is taking bookings for ' . (int) $season . ', so bookings go to that year\'s conference.';
			echo '</p>';
		}

		if ( $here && 'publish' !== $post->post_status ) {
			echo '<p style="' . esc_attr( $warn ) . '">This conference is not published yet. Registrations are still recorded here, but vendors will not see it on My Conferences until you publish it.</p>';
		}

		$manual = (string) get_post_meta( $id, 'registration_url', true );
		echo '<hr><p><strong>Registration page</strong><br>';
		if ( '' !== $manual ) {
			$url = is_numeric( $manual ) ? (string) get_permalink( (int) $manual ) : $manual;
			echo 'Chosen on the Registration tab: <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( is_numeric( $manual ) ? get_the_title( (int) $manual ) : $url ) . '</a>';
		} elseif ( $forms ) {
			$found = self::detect_registration_page( $id );
			echo $found['page']
				? 'Found automatically: <a href="' . esc_url( (string) get_permalink( $found['page'] ) ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( $found['page'] ) ) . '</a>'
				: '<span style="' . esc_attr( $warn ) . '">No published page shows this form yet. Add the form to a page, or choose a page on the Registration tab.</span>';
		} else {
			echo 'Annual form only.';
		}
		echo '</p>';
	}

	/* ------------------------------------------------------------------ */
	/* Copy for next year                                                  */
	/* ------------------------------------------------------------------ */

	public static function copy_url( int $edition_id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=zpvp_copy_conference&from=' . $edition_id ), 'zpvp_copy_' . $edition_id );
	}

	public function copy_box( $post ): void {
		echo '<p>Start next year\'s conference from this one: same name, registration form, venue, capacity and information, with all dates moved forward one year.</p>';
		echo '<p><a class="button" href="' . esc_url( self::copy_url( (int) $post->ID ) ) . '">Copy for next year</a></p>';
	}

	public function row_actions( $actions, $post ) {
		if ( $post instanceof \WP_Post && self::EDITION === $post->post_type && current_user_can( 'edit_posts' ) ) {
			$actions['zpvp_copy'] = '<a href="' . esc_url( self::copy_url( (int) $post->ID ) ) . '">Copy for next year</a>';
		}
		return $actions;
	}

	public function copy_conference(): void {
		$from = absint( $_GET['from'] ?? 0 );
		if ( ! $from || ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_copy_' . $from );
		$source = get_post( $from );
		if ( ! $source || self::EDITION !== $source->post_type ) {
			wp_die( 'Conference not found.' );
		}
		[ $id, $created ] = self::copy_to_year( $from, (int) get_post_meta( $from, 'year', true ) + 1 );
		if ( ! $id ) {
			wp_die( 'The conference could not be copied.' );
		}
		wp_safe_redirect( add_query_arg( 'zpvp_copy', $created ? 'created' : 'exists', get_edit_post_link( $id, 'url' ) ) );
		exit;
	}

	/**
	 * Copy a conference to another year as a draft: same name, forms, venue and
	 * information, dates moved by the difference in years. Never duplicates.
	 *
	 * @return array{0:int, 1:bool} [conference ID, whether it was created]
	 */
	public static function copy_to_year( int $from, int $year ): array {
		$name = trim( (string) get_post_meta( $from, 'conference_name', true ) );
		if ( '' === $name ) {
			$name = trim( (string) preg_replace( '/\s*\b20\d{2}\s*$/', '', get_post_field( 'post_title', $from ) ) );
		}
		if ( '' === $name || $year < 2015 ) {
			return array( 0, false );
		}
		$existing = self::query(
			array(
				array( 'key' => 'conference_name', 'value' => $name ),
				array( 'key' => 'year', 'value' => $year ),
			)
		);
		if ( $existing ) {
			return array( (int) $existing[0], false );
		}

		$title = $name . ' ' . $year;
		$new   = wp_insert_post(
			array(
				'post_type'   => self::EDITION,
				'post_status' => 'draft',
				'post_title'  => $title,
				'post_name'   => sanitize_title( $title ),
			),
			true
		);
		if ( is_wp_error( $new ) ) {
			return array( 0, false );
		}

		$skip = array( '_edit_lock', '_edit_last', '_zpvp_test_data', '_wp_old_slug', Annual_Form::FALLBACK );
		foreach ( get_post_meta( $from ) as $key => $values ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				add_post_meta( $new, $key, maybe_unserialize( $value ) );
			}
		}
		$shift = $year - (int) get_post_meta( $from, 'year', true );
		self::set_field( (int) $new, 'conference_name', 'field_zpvp_ed_name', $name );
		update_post_meta( $new, 'year', $year );
		update_post_meta( $new, 'status_override', 'auto' );
		update_post_meta( $new, 'registration_url', '' );
		foreach ( array( 'start_date' => 'Ymd', 'end_date' => 'Ymd', 'registration_opens' => 'Y-m-d H:i:s', 'registration_closes' => 'Y-m-d H:i:s', 'attendee_edit_deadline' => 'Y-m-d H:i:s' ) as $key => $format ) {
			$value = (string) get_post_meta( $from, $key, true );
			$date  = '' !== $value ? \DateTime::createFromFormat( $format, $value ) : false;
			update_post_meta( $new, $key, $date ? $date->modify( sprintf( '%+d year', $shift ) )->format( $format ) : '' );
		}
		return array( (int) $new, true );
	}

	/* ------------------------------------------------------------------ */
	/* Start next season                                                   */
	/* ------------------------------------------------------------------ */

	/** The year after the newest year that has a published conference. */
	public static function next_season_year(): int {
		global $wpdb;
		foreach ( array( "= 'publish'", "IN ('publish','future','draft','pending','private')" ) as $status ) {
			$year = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(CAST(y.meta_value AS UNSIGNED)) FROM {$wpdb->posts} p
					JOIN {$wpdb->postmeta} y ON y.post_id = p.ID AND y.meta_key = 'year'
					WHERE p.post_type = %s AND p.post_status {$status}", // phpcs:ignore WordPress.DB.PreparedSQL -- fixed strings.
					self::EDITION
				)
			);
			if ( $year ) {
				return $year + 1;
			}
		}
		return (int) gmdate( 'Y' ) + 1;
	}

	/**
	 * For each event: the conference to copy (its latest year before $year), or
	 * the existing conference if that year already exists.
	 *
	 * @return array{sources:int[], existing:int[]}
	 */
	public static function season_plan( int $year ): array {
		$by = array();
		foreach ( self::query( array( array( 'key' => 'year', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ) ) ) as $id ) {
			$group         = (int) get_post_meta( $id, 'series', true );
			$key           = $group ? 'g' . $group : 'n' . strtolower( trim( (string) get_post_meta( $id, 'conference_name', true ) ) );
			$by[ $key ][]  = $id;
		}
		$plan = array( 'sources' => array(), 'existing' => array() );
		foreach ( $by as $ids ) {
			$best = 0;
			$best_year = 0;
			$done = 0;
			foreach ( $ids as $id ) { // Published first, then newest year.
				$y = (int) get_post_meta( $id, 'year', true );
				if ( $y === $year ) {
					$done = $id;
					break;
				}
				if ( $y < $year && $y > $best_year ) {
					$best      = $id;
					$best_year = $y;
				}
			}
			if ( $done ) {
				$plan['existing'][] = $done;
			} elseif ( $best ) {
				$plan['sources'][] = $best;
			}
		}
		return $plan;
	}

	/** @return array{created:int, existing:int} */
	public static function start_season( int $year ): array {
		$plan    = self::season_plan( $year );
		$created = 0;
		foreach ( $plan['sources'] as $from ) {
			[ $id, $new ] = self::copy_to_year( $from, $year );
			if ( $id && $new ) {
				self::finalise( $id );
				++$created;
			}
		}
		return array( 'created' => $created, 'existing' => count( $plan['existing'] ) );
	}

	public function start_season_action(): void {
		if ( ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_start_season' );
		$year   = absint( $_POST['year'] ?? 0 );
		$result = $year ? self::start_season( $year ) : array( 'created' => 0, 'existing' => 0 );
		$msg    = sprintf( '%d season started: %d draft conferences created (%d already existed). Open each draft to check its dates and venue, then publish it.', $year, $result['created'], $result['existing'] );
		wp_safe_redirect( Admin_Settings::url( 'forms', array( 'zpvp_msg' => rawurlencode( $msg ) ) ) );
		exit;
	}

	public function copy_notice(): void {
		$state = sanitize_key( $_GET['zpvp_copy'] ?? '' );
		if ( 'created' === $state ) {
			echo '<div class="notice notice-warning"><p><strong>Copied from last year and saved as a draft.</strong> All dates were moved forward one year, so check the exact dates on the Event and Registration tabs and update the hotel and floor plan if they changed. Last year\'s conference keeps receiving registrations until you click Publish here; after that, new registrations from the same form come to this one.</p></div>';
		} elseif ( 'exists' === $state ) {
			echo '<div class="notice notice-info"><p>A conference with this name and year already exists, so it was opened instead of creating a duplicate.</p></div>';
		}
	}

	/* ------------------------------------------------------------------ */
	/* One-time move from series to conferences (0.16.0)                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Copies each series' forms, annual dropdown and name onto its conferences.
	 * A series with no conference yet gets a draft conference for the annual
	 * form's year, so its form keeps recording registrations. Runs once.
	 */
	public static function maybe_migrate(): void {
		if ( in_array( get_option( self::MODEL_OPTION ), array( 'v2', 'v3' ), true ) ) {
			return;
		}
		$year   = (int) gmdate( 'Y' );
		$annual = Form_Map::annual_form_id();
		if ( $annual && class_exists( '\GFAPI' ) && ( $form = \GFAPI::get_form( $annual ) ) && preg_match( '/\b(20\d{2})\b/', (string) $form['title'], $m ) ) {
			$year = (int) $m[1];
		}

		$series = get_posts( array( 'post_type' => self::SERIES, 'post_status' => 'publish', 'posts_per_page' => -1, 'suppress_filters' => true ) );
		foreach ( $series as $s ) {
			$forms = array_values( array_filter( array_map( 'strval', (array) get_post_meta( $s->ID, 'gf_forms', true ) ) ) );
			$field = (string) get_post_meta( $s->ID, 'annual_product_field', true );
			$eds   = self::query( array( array( 'key' => 'series', 'value' => $s->ID ) ) );

			if ( ! $eds ) {
				if ( ! $forms && '' === $field ) {
					continue;
				}
				$new = wp_insert_post(
					array(
						'post_type'   => self::EDITION,
						'post_status' => 'draft',
						'post_title'  => $s->post_title . ' ' . $year,
					),
					true
				);
				if ( is_wp_error( $new ) ) {
					continue;
				}
				update_post_meta( $new, 'series', $s->ID );
				self::set_field( $new, 'year', 'field_zpvp_ed_year', $year );
				self::set_field( $new, 'status_override', 'field_zpvp_ed_status_override', 'auto' );
				$eds = array( (int) $new );
			}

			foreach ( $eds as $ed ) {
				if ( '' === trim( (string) get_post_meta( $ed, 'conference_name', true ) ) ) {
					self::set_field( $ed, 'conference_name', 'field_zpvp_ed_name', $s->post_title );
				}
				if ( ! array_filter( (array) get_post_meta( $ed, 'gf_forms', true ) ) && $forms ) {
					self::set_field( $ed, 'gf_forms', 'field_zpvp_ed_gf_forms', $forms );
				}
				if ( '' === (string) get_post_meta( $ed, 'annual_product_field', true ) && '' !== $field ) {
					self::set_field( $ed, 'annual_product_field', 'field_zpvp_ed_annual_field', $field );
				}
			}
		}
		update_option( self::MODEL_OPTION, 'v2', false );
	}

	/**
	 * 0.19.0: forms live on the series again. Each conference's forms, sponsorship
	 * forms and annual dropdown are merged onto its series. Runs once.
	 */
	public static function maybe_migrate_v3(): void {
		if ( 'v3' === get_option( self::MODEL_OPTION ) ) {
			return;
		}
		$eds = get_posts( array( 'post_type' => self::EDITION, 'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private' ), 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) );
		foreach ( $eds as $ed ) {
			$series = (int) get_post_meta( $ed, 'series', true );
			if ( ! $series || self::SERIES !== get_post_type( $series ) ) {
				self::sync_group( (int) $ed );
				$series = (int) get_post_meta( $ed, 'series', true );
			}
			if ( ! $series ) {
				continue;
			}
			update_post_meta( $ed, '_series', 'field_zpvp_ed_series' );
			foreach ( array( 'gf_forms' => 'field_zpvp_series_gf_forms', 'sponsorship_forms' => 'field_zpvp_series_sponsor_forms' ) as $key => $ref ) {
				$merged = array_values( array_unique( array_filter( array_map( 'strval', array_merge( (array) get_post_meta( $series, $key, true ), (array) get_post_meta( $ed, $key, true ) ) ) ) ) );
				if ( $merged ) {
					self::set_field( $series, $key, $ref, $merged );
				}
			}
			$field = (string) get_post_meta( $ed, 'annual_product_field', true );
			if ( '' !== $field && '' === (string) get_post_meta( $series, 'annual_product_field', true ) ) {
				self::set_field( $series, 'annual_product_field', 'field_zpvp_series_annual_field', $field );
			}
		}
		update_option( self::MODEL_OPTION, 'v3', false );
	}

	/** Value plus ACF's field reference, so the edit screen shows it. */
	public static function set_field( int $post_id, string $name, string $key, $value ): void {
		update_post_meta( $post_id, $name, $value );
		update_post_meta( $post_id, '_' . $name, $key );
	}

	/* ------------------------------------------------------------------ */
	/* Status helpers                                                      */
	/* ------------------------------------------------------------------ */

	public static function status( int $edition_id ): string {
		$override = (string) get_post_meta( $edition_id, 'status_override', true );
		if ( $override && 'auto' !== $override ) {
			return $override;
		}

		$now    = current_time( 'mysql' );
		$today  = current_time( 'Ymd' );
		$end    = (string) get_post_meta( $edition_id, 'end_date', true ); // Stored as Ymd.
		$opens  = (string) get_post_meta( $edition_id, 'registration_opens', true );
		$closes = (string) get_post_meta( $edition_id, 'registration_closes', true );

		if ( $end && $today > $end ) {
			return 'past';
		}
		if ( ! $opens ) {
			return 'not_scheduled';
		}
		if ( $now < $opens ) {
			return 'upcoming';
		}
		if ( $closes && $now > $closes ) {
			return 'closed';
		}

		$capacity = (int) get_post_meta( $edition_id, 'booth_capacity', true );
		if ( $capacity > 0 && self::booths_taken( $edition_id ) >= $capacity ) {
			return 'sold_out';
		}
		return 'open';
	}

	/** Booths already registered for a conference (the registration module hooks in here). */
	public static function booths_taken( int $edition_id ): int {
		return (int) apply_filters( 'zpvp_edition_booths_taken', 0, $edition_id );
	}

	public static function status_label( string $status ): string {
		return self::STATUSES[ $status ] ?? ucfirst( str_replace( '_', ' ', $status ) );
	}

	/** Human-readable date range from Ymd meta values. */
	public static function date_range( int $edition_id ): string {
		$start = (string) get_post_meta( $edition_id, 'start_date', true );
		$end   = (string) get_post_meta( $edition_id, 'end_date', true );
		if ( ! $start ) {
			return '';
		}
		$s = \DateTime::createFromFormat( 'Ymd', $start );
		$e = $end ? \DateTime::createFromFormat( 'Ymd', $end ) : null;
		if ( ! $s ) {
			return '';
		}
		if ( ! $e || $e->format( 'Ymd' ) === $s->format( 'Ymd' ) ) {
			return $s->format( 'M j, Y' );
		}
		if ( $s->format( 'Y-m' ) === $e->format( 'Y-m' ) ) {
			return $s->format( 'M j' ) . '–' . $e->format( 'j, Y' );
		}
		return $s->format( 'M j' ) . ' – ' . $e->format( 'M j, Y' );
	}

	/**
	 * Conferences a vendor can currently act on (open or sold out), soonest first.
	 *
	 * @return int[]
	 */
	public static function current_editions(): array {
		$ids = get_posts(
			array(
				'post_type'      => self::EDITION,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_key'       => 'start_date',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
			)
		);
		return array_values( array_filter( $ids, static fn( $id ) => in_array( self::status( (int) $id ), array( 'open', 'sold_out' ), true ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Admin list columns                                                  */
	/* ------------------------------------------------------------------ */

	public function edition_columns( array $cols ): array {
		return array(
			'cb'          => $cols['cb'] ?? '',
			'title'       => 'Conference',
			'zpvp_dates'  => 'Dates',
			'zpvp_forms'  => 'Registration form',
			'zpvp_reg'    => 'Registration window',
			'zpvp_cap'    => 'Booths',
			'zpvp_status' => 'Status',
		);
	}

	public function edition_column( string $col, int $id ): void {
		switch ( $col ) {
			case 'zpvp_dates':
				echo esc_html( self::date_range( $id ) ?: '—' );
				$city = (string) get_post_meta( $id, 'city', true );
				if ( $city ) {
					echo '<br><span class="description">' . esc_html( $city ) . '</span>';
				}
				break;
			case 'zpvp_forms':
				$forms = self::forms( $id );
				if ( ! $forms ) {
					echo Series::annual_field( $id ) ? 'Annual form only' : '<span style="color:#9c1f25">None chosen</span>';
					foreach ( self::sponsorship_forms( $id ) as $f ) {
						echo '<br>' . esc_html( 'Sponsorship: form ' . (int) $f );
					}
					break;
				}
				$out = array();
				foreach ( $forms as $f ) {
					$form  = class_exists( '\GFAPI' ) ? \GFAPI::get_form( $f ) : null;
					$state = $form ? ( ! empty( $form['is_active'] ) ? 'on' : 'off' ) : 'missing';
					$line  = 'Form ' . (int) $f . ' (' . $state . ')';
					if ( self::for_form( $f ) !== $id ) {
						$line .= ' – registrations go to a newer conference';
					}
					$out[] = esc_html( $line );
				}
				foreach ( self::sponsorship_forms( $id ) as $f ) {
					$out[] = esc_html( 'Sponsorship: form ' . (int) $f );
				}
				echo implode( '<br>', $out ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
				break;
			case 'zpvp_reg':
				$o = (string) get_post_meta( $id, 'registration_opens', true );
				$c = (string) get_post_meta( $id, 'registration_closes', true );
				echo esc_html( ( $o ? mysql2date( 'M j, Y', $o ) : '—' ) . ' → ' . ( $c ? mysql2date( 'M j, Y', $c ) : '—' ) );
				break;
			case 'zpvp_cap':
				$cap = (int) get_post_meta( $id, 'booth_capacity', true );
				echo esc_html( self::booths_taken( $id ) . ' / ' . ( $cap ? $cap : '∞' ) );
				break;
			case 'zpvp_status':
				echo esc_html( self::status_label( self::status( $id ) ) );
				break;
		}
	}
}