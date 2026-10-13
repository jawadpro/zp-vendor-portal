<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Conference series: one record per event that happens every year (e.g. the
 * West Texas conference). The series holds the forms the event always uses:
 * its registration form(s), sponsorship form(s) and its booth dropdown on the
 * annual form. Each year's conference (zp_conf_edition) belongs to a series
 * and uses its forms. Series screens sit under Vendor Portal → Settings.
 */
final class Series {

	public function register(): void {
		if ( function_exists( 'acf_add_local_field_group' ) ) {
			if ( did_action( 'acf/init' ) ) {
				$this->fields();
			} else {
				add_action( 'acf/init', array( $this, 'fields' ) );
			}
		}
		add_filter( 'acf/load_field/key=field_zpvp_series_gf_forms', array( $this, 'form_choices' ) );
		add_filter( 'acf/load_field/key=field_zpvp_series_sponsor_forms', array( $this, 'form_choices' ) );
		add_filter( 'acf/load_field/key=field_zpvp_series_annual_field', array( $this, 'annual_choices' ) );
		add_filter( 'enter_title_here', array( $this, 'placeholder' ), 10, 2 );
		add_filter( 'manage_' . Conferences::SERIES . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . Conferences::SERIES . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'add_meta_boxes_' . Conferences::SERIES, array( $this, 'meta_boxes' ) );
		add_action( 'admin_post_zpvp_add_conference', array( $this, 'add_conference' ) );
		add_action( 'save_post_' . Conferences::SERIES, array( $this, 'retitle_conferences' ), 20 );
	}

	public function fields(): void {
		acf_add_local_field_group(
			array(
				'key'      => 'group_zpvp_series',
				'title'    => 'Forms for this series',
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => Conferences::SERIES ) ) ),
				'fields'   => array(
					array(
						'key'           => 'field_zpvp_series_gf_forms',
						'name'          => 'gf_forms',
						'label'         => 'Registration form',
						'type'          => 'select',
						'multiple'      => 1,
						'ui'            => 1,
						'allow_null'    => 1,
						'return_format' => 'value',
						'choices'       => array(),
						'instructions'  => 'The Gravity Form vendors fill in to register for this event. It is reused every year. You can add an older form for the same event too.',
					),
					array(
						'key'           => 'field_zpvp_series_sponsor_forms',
						'name'          => 'sponsorship_forms',
						'label'         => 'Sponsorship form',
						'type'          => 'select',
						'multiple'      => 1,
						'ui'            => 1,
						'allow_null'    => 1,
						'return_format' => 'value',
						'choices'       => array(),
						'instructions'  => 'The Gravity Form sponsors fill in for this event, if it has one.',
					),
					array(
						'key'           => 'field_zpvp_series_annual_field',
						'name'          => 'annual_product_field',
						'label'         => 'Booth dropdown on the annual form',
						'type'          => 'select',
						'allow_null'    => 1,
						'ui'            => 1,
						'return_format' => 'value',
						'choices'       => array(),
						'instructions'  => 'If vendors can also book this event on the annual Conference Year form, choose its booth dropdown. Leave empty if it is not on the annual form.',
					),
				),
			)
		);
	}

	public function form_choices( array $field ): array {
		return ( new Conferences() )->form_choices( $field );
	}

	public function annual_choices( array $field ): array {
		return ( new Conferences() )->annual_choices( $field );
	}

	public function placeholder( $text, $post ) {
		if ( $post instanceof \WP_Post && Conferences::SERIES === $post->post_type ) {
			return 'Event name without the year, e.g. West Texas County Judges and Commissioners Association Conference';
		}
		return $text;
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/** Form IDs stored on a series (gf_forms or sponsorship_forms). */
	public static function form_ids( int $series, string $key ): array {
		return $series ? array_values( array_filter( array_map( 'intval', (array) get_post_meta( $series, $key, true ) ) ) ) : array();
	}

	/** The annual-form booth dropdown of the series a conference belongs to. */
	public static function annual_field( int $edition_id ): string {
		$series = (int) get_post_meta( $edition_id, 'series', true );
		return $series ? (string) get_post_meta( $series, 'annual_product_field', true ) : '';
	}

	/** Conferences in a series, newest year first. */
	public static function conferences( int $series ): array {
		$ids = get_posts(
			array(
				'post_type'        => Conferences::EDITION,
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'meta_query'       => array( array( 'key' => 'series', 'value' => $series ) ),
				'suppress_filters' => true,
			)
		);
		usort( $ids, static fn( $a, $b ) => (int) get_post_meta( $b, 'year', true ) <=> (int) get_post_meta( $a, 'year', true ) );
		return array_map( 'intval', $ids );
	}

	private static function form_list( array $ids ): string {
		if ( ! $ids ) {
			return '<span class="zpvp-muted">—</span>';
		}
		$out = array();
		foreach ( $ids as $id ) {
			$form  = class_exists( '\GFAPI' ) ? \GFAPI::get_form( $id ) : null;
			$out[] = esc_html( $form ? $form['title'] : 'Form ' . $id ) . ' <span class="description">(form ' . (int) $id . ', ' . ( $form ? ( ! empty( $form['is_active'] ) ? 'on' : 'off' ) : 'missing' ) . ')</span>';
		}
		return implode( '<br>', $out );
	}

	public static function conference_list( int $series ): string {
		$out = array();
		foreach ( self::conferences( $series ) as $c ) {
			$status = get_post_status( $c );
			$out[]  = '<a href="' . esc_url( (string) get_edit_post_link( $c, 'url' ) ) . '">' . (int) get_post_meta( $c, 'year', true ) . '</a>' . ( 'publish' === $status ? '' : ' <span class="description">(' . esc_html( 'draft' === $status ? 'draft' : $status ) . ')</span>' );
		}
		return $out ? implode( ', ', $out ) : '<span class="description">No conferences yet</span>';
	}

	public static function add_url( int $series ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=zpvp_add_conference&series=' . $series ), 'zpvp_add_conf_' . $series );
	}

	/* ------------------------------------------------------------------ */
	/* Screens                                                             */
	/* ------------------------------------------------------------------ */

	public function columns( array $cols ): array {
		return array(
			'cb'           => $cols['cb'] ?? '',
			'title'        => 'Series',
			'zpvp_forms'   => 'Registration form',
			'zpvp_sponsor' => 'Sponsorship form',
			'zpvp_annual'  => 'Annual form dropdown',
			'zpvp_confs'   => 'Conferences',
		);
	}

	public function column( string $col, int $id ): void {
		switch ( $col ) {
			case 'zpvp_forms':
				echo self::form_list( self::form_ids( $id, 'gf_forms' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in form_list().
				break;
			case 'zpvp_sponsor':
				echo self::form_list( self::form_ids( $id, 'sponsorship_forms' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'zpvp_annual':
				$field  = (string) get_post_meta( $id, 'annual_product_field', true );
				$labels = Conferences::annual_dropdowns();
				echo '' === $field ? '<span class="zpvp-muted">—</span>' : esc_html( $labels[ $field ] ?? 'Field ' . $field );
				break;
			case 'zpvp_confs':
				echo self::conference_list( $id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in conference_list().
				break;
		}
	}

	public function row_actions( $actions, $post ) {
		if ( $post instanceof \WP_Post && Conferences::SERIES === $post->post_type && current_user_can( 'edit_posts' ) ) {
			$actions['zpvp_add'] = '<a href="' . esc_url( self::add_url( (int) $post->ID ) ) . '">Add next year\'s conference</a>';
		}
		return $actions;
	}

	public function meta_boxes(): void {
		add_meta_box( 'zpvp-series-confs', 'Conferences in this series', array( $this, 'conferences_box' ), Conferences::SERIES, 'side', 'high' );
	}

	public function conferences_box( $post ): void {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>Save the series first, then add its conferences here.</p>';
			return;
		}
		echo '<p>' . self::conference_list( (int) $post->ID ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>Each year\'s conference uses the forms set on this series.</p>';
		echo '<p><a class="button" href="' . esc_url( self::add_url( (int) $post->ID ) ) . '">Add next year\'s conference</a></p>';
	}

	/** Next year's conference for a series: a copy of its latest year, or a new draft. */
	public function add_conference(): void {
		$series = absint( $_GET['series'] ?? 0 );
		if ( ! $series || ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_add_conf_' . $series );
		if ( Conferences::SERIES !== get_post_type( $series ) ) {
			wp_die( 'Series not found.' );
		}
		$latest = self::conferences( $series )[0] ?? 0;
		if ( $latest ) {
			[ $id, $created ] = Conferences::copy_to_year( $latest, (int) get_post_meta( $latest, 'year', true ) + 1 );
		} else {
			$year    = Annual_Form::season();
			$id      = (int) wp_insert_post(
				array(
					'post_type'   => Conferences::EDITION,
					'post_status' => 'draft',
					'post_title'  => get_post_field( 'post_title', $series ) . ' ' . $year,
				)
			);
			$created = (bool) $id;
			if ( $id ) {
				Conferences::set_field( $id, 'series', 'field_zpvp_ed_series', $series );
				Conferences::set_field( $id, 'year', 'field_zpvp_ed_year', $year );
				Conferences::set_field( $id, 'status_override', 'field_zpvp_ed_status_override', 'auto' );
			}
		}
		if ( ! $id ) {
			wp_die( 'The conference could not be created.' );
		}
		Conferences::finalise( (int) $id );
		wp_safe_redirect( add_query_arg( 'zpvp_copy', $created ? 'created' : 'exists', get_edit_post_link( (int) $id, 'url' ) ) );
		exit;
	}

	/** Renaming a series renames its conferences ("Name Year"). */
	public function retitle_conferences( $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			return;
		}
		foreach ( self::conferences( (int) $post_id ) as $c ) {
			Conferences::finalise( $c );
		}
	}
}