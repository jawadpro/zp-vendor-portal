<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Conference Builder (Vendor Portal → Conference Builder).
 *
 * One screen to set up a conference: event details, the series' forms, field
 * connectors, registration window and vendor information, with a flow strip
 * (Form → Connectors → Conference → Vendor portal → Badges/Invoices/Emails),
 * a readiness checklist and a live preview of what vendors will see.
 *
 * Unsaved changes are checked on the server (POST /builder/{id}/check) so the
 * checklist and preview use the same rules as the rest of the plugin, without
 * writing anything until staff click Save. The UI lives in assets/js/builder.js.
 */
final class Builder {

	public const PAGE = 'zpvp-builder';

	/** Conference fields edited by the builder and their ACF field keys. */
	private const FIELDS = array(
		'year'                   => 'field_zpvp_ed_year',
		'start_date'             => 'field_zpvp_ed_start',
		'end_date'               => 'field_zpvp_ed_end',
		'venue'                  => 'field_zpvp_ed_venue',
		'city'                   => 'field_zpvp_ed_city',
		'registration_opens'     => 'field_zpvp_ed_opens',
		'registration_closes'    => 'field_zpvp_ed_closes',
		'attendee_edit_deadline' => 'field_zpvp_ed_edit_deadline',
		'booth_capacity'         => 'field_zpvp_ed_capacity',
		'status_override'        => 'field_zpvp_ed_status_override',
		'hotel_info'             => 'field_zpvp_ed_hotel',
		'decorator_info'         => 'field_zpvp_ed_decorator',
		'electricity_info'       => 'field_zpvp_ed_electricity',
		'floor_plan'             => 'field_zpvp_ed_floor_plan',
	);

	private const DATE_FIELDS     = array( 'start_date', 'end_date' );
	private const DATETIME_FIELDS = array( 'registration_opens', 'registration_closes', 'attendee_edit_deadline' );
	private const HTML_FIELDS     = array( 'hotel_info', 'decorator_info', 'electricity_info' );

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'admin_menu', array( $this, 'menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 20, 2 );
		add_action( 'add_meta_boxes_' . Conferences::EDITION, array( $this, 'meta_box' ) );
		add_action( 'load-post.php', array( $this, 'open_builder' ) );
		add_action( 'load-post-new.php', array( $this, 'open_builder' ) );
		add_filter( 'redirect_post_location', array( $this, 'stay_classic' ), 10, 2 );
	}

	/**
	 * The builder is the conference editor: opening or adding a conference goes
	 * there. The classic screen stays reachable with &classic=1 (builder's
	 * "Classic editor" button), and saving it keeps you on it.
	 */
	public function open_builder(): void {
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) || ! empty( $_GET['classic'] ) || ! current_user_can( Roles::CAP_MANAGE ) ) {
			return;
		}
		global $pagenow;
		if ( 'post-new.php' === $pagenow ) {
			if ( Conferences::EDITION === sanitize_key( $_GET['post_type'] ?? '' ) ) {
				wp_safe_redirect( add_query_arg( 'new', 1, self::url() ) );
				exit;
			}
			return;
		}
		$id = absint( $_GET['post'] ?? 0 );
		if ( $id && 'edit' === ( $_GET['action'] ?? '' ) && Conferences::EDITION === get_post_type( $id ) ) {
			$url = self::url( $id );
			if ( isset( $_GET['zpvp_copy'] ) ) {
				$url = add_query_arg( 'zpvp_copy', sanitize_key( $_GET['zpvp_copy'] ), $url );
			}
			wp_safe_redirect( $url );
			exit;
		}
	}

	public function stay_classic( $location, $post_id ) {
		return Conferences::EDITION === get_post_type( (int) $post_id ) ? add_query_arg( 'classic', 1, $location ) : $location;
	}

	public static function url( int $edition_id = 0 ): string {
		return admin_url( 'admin.php?page=' . self::PAGE . ( $edition_id ? '&conference=' . $edition_id : '' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Admin screen                                                        */
	/* ------------------------------------------------------------------ */

	public function menu(): void {
		add_submenu_page( 'zpvp', 'Conference Builder', 'Conference Builder', Roles::CAP_MANAGE, self::PAGE, array( $this, 'render' ) );
	}

	public function row_action( $actions, $post ) {
		if ( $post instanceof \WP_Post && Conferences::EDITION === $post->post_type && current_user_can( Roles::CAP_MANAGE ) ) {
			$actions['zpvp_classic'] = '<a href="' . esc_url( add_query_arg( 'classic', 1, (string) get_edit_post_link( $post->ID, 'url' ) ) ) . '">Classic editor</a>';
		}
		return $actions;
	}

	public function meta_box(): void {
		add_meta_box(
			'zpvp-builder-link',
			'Conference Builder',
			static function ( $post ) {
				echo '<p>The builder is the main conference editor. This classic screen is kept for anything the builder does not cover.</p>';
				echo '<p><a class="button button-primary" href="' . esc_url( self::url( (int) $post->ID ) ) . '">Open in builder</a></p>';
			},
			Conferences::EDITION,
			'side',
			'high'
		);
	}

	public function assets( $hook ): void {
		if ( 'vendor-portal_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'zpvp-portal', ZPVP_URL . 'assets/css/portal.css', array(), ZPVP_VERSION );
		wp_enqueue_style( 'zpvp-flatpickr', ZPVP_URL . 'assets/vendor/flatpickr/flatpickr.min.css', array(), '4.6.13' );
		wp_enqueue_style( 'zpvp-loading', ZPVP_URL . 'assets/css/loading.css', array(), (string) filemtime( ZPVP_PATH . 'assets/css/loading.css' ) );
		wp_enqueue_style( 'zpvp-builder', ZPVP_URL . 'assets/css/builder.css', array( 'zpvp-portal', 'zpvp-flatpickr' ), ZPVP_VERSION );
		wp_enqueue_script( 'zpvp-flatpickr', ZPVP_URL . 'assets/vendor/flatpickr/flatpickr.min.js', array(), '4.6.13', true );
		wp_enqueue_script( 'zpvp-builder', ZPVP_URL . 'assets/js/builder.js', array( 'zpvp-flatpickr' ), (string) filemtime( ZPVP_PATH . 'assets/js/builder.js' ), true );
		wp_localize_script(
			'zpvp-builder',
			'ZPVP_BUILDER',
			array(
				'rest'      => esc_url_raw( rest_url( Rest::NS . '/builder' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'initial'   => absint( $_GET['conference'] ?? 0 ),
				'pageUrl'   => self::url(),
				'dashboard' => admin_url( 'admin.php?page=zpvp' ),
			)
		);
	}

	public function render(): void {
		echo '<div class="wrap zpvp-b" id="zpvp-builder"><h1 class="zpvp-b__title">Conference Builder</h1><p class="zpvp-loading" role="status">Loading Conference Builder…</p>'
			. '<noscript><p>The builder needs JavaScript. You can still edit conferences on the <a href="' . esc_url( admin_url( 'edit.php?post_type=' . Conferences::EDITION ) ) . '">Conferences</a> screen.</p></noscript></div>';
	}

	/* ------------------------------------------------------------------ */
	/* REST                                                                */
	/* ------------------------------------------------------------------ */

	public function routes(): void {
		$perm = array( Rest::class, 'can' );
		$num  = static fn( $v ) => is_numeric( $v );
		$ns   = Rest::NS;

		register_rest_route( $ns, '/builder', array( 'methods' => 'GET', 'callback' => array( $this, 'r_boot' ), 'permission_callback' => $perm ) );
		register_rest_route( $ns, '/builder/new', array( 'methods' => 'POST', 'callback' => array( $this, 'r_new' ), 'permission_callback' => $perm ) );
		register_rest_route(
			$ns,
			'/builder/(?P<id>\d+)',
			array(
				array( 'methods' => 'GET', 'callback' => array( $this, 'r_get' ), 'permission_callback' => $perm, 'args' => array( 'id' => array( 'validate_callback' => $num ) ) ),
				array( 'methods' => 'POST', 'callback' => array( $this, 'r_save' ), 'permission_callback' => $perm, 'args' => array( 'id' => array( 'validate_callback' => $num ) ) ),
			)
		);
		register_rest_route( $ns, '/builder/(?P<id>\d+)/check', array( 'methods' => 'POST', 'callback' => array( $this, 'r_check' ), 'permission_callback' => $perm, 'args' => array( 'id' => array( 'validate_callback' => $num ) ) ) );
		register_rest_route(
			$ns,
			'/builder/form/(?P<form>\d+)',
			array(
				array( 'methods' => 'GET', 'callback' => array( $this, 'r_form' ), 'permission_callback' => $perm, 'args' => array( 'form' => array( 'validate_callback' => $num ) ) ),
				array( 'methods' => 'POST', 'callback' => array( $this, 'r_form_save' ), 'permission_callback' => $perm, 'args' => array( 'form' => array( 'validate_callback' => $num ) ) ),
			)
		);
		register_rest_route( $ns, '/builder/form/(?P<form>\d+)/test', array( 'methods' => 'GET', 'callback' => array( $this, 'r_form_test' ), 'permission_callback' => $perm, 'args' => array( 'form' => array( 'validate_callback' => $num ) ) ) );
	}

	private static function edition_or_error( int $id ) {
		if ( ! $id || Conferences::EDITION !== get_post_type( $id ) ) {
			return new \WP_Error( 'zpvp_not_found', 'Conference not found.', array( 'status' => 404 ) );
		}
		return $id;
	}

	/** Lists the builder needs once: conferences, series, forms, annual dropdowns. */
	public function r_boot(): array {
		$conferences = array();
		$ids         = get_posts(
			array(
				'post_type'        => Conferences::EDITION,
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		foreach ( $ids as $id ) {
			$conferences[] = array(
				'id'        => (int) $id,
				'title'     => self::plain( $id ),
				'year'      => (int) get_post_meta( $id, 'year', true ),
				'published' => 'publish' === get_post_status( $id ),
				'status'    => Conferences::status_label( Conferences::status( (int) $id ) ),
			);
		}
		usort( $conferences, static fn( $a, $b ) => array( $b['year'], $a['title'] ) <=> array( $a['year'], $b['title'] ) );

		$series = array();
		foreach ( get_posts( array( 'post_type' => Conferences::SERIES, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => true ) ) as $s ) {
			$series[] = array(
				'id'                   => (int) $s->ID,
				'title'                => $s->post_title,
				'forms'                => Series::form_ids( (int) $s->ID, 'gf_forms' ),
				'sponsorship_forms'    => Series::form_ids( (int) $s->ID, 'sponsorship_forms' ),
				'annual_product_field' => (string) get_post_meta( $s->ID, 'annual_product_field', true ),
			);
		}

		// Open the conference staff are most likely working on: an open one, else the newest draft.
		$default = 0;
		foreach ( array( 'open', 'draft' ) as $pass ) {
			foreach ( $conferences as $c ) {
				$match = 'open' === $pass
					? $c['published'] && in_array( $c['status'], array( Conferences::status_label( 'open' ), Conferences::status_label( 'sold_out' ) ), true )
					: ! $c['published'];
				if ( $match ) {
					$default = $c['id'];
					break 2;
				}
			}
		}

		$statuses = array();
		foreach ( array( 'auto' => 'Automatic (from dates and capacity)', 'open' => 'Force open', 'sold_out' => 'Sold out', 'closed' => 'Closed' ) as $k => $v ) {
			$statuses[] = array( 'value' => $k, 'label' => $v );
		}

		return array(
			'conferences' => $conferences,
			'default'     => $default ? $default : ( $conferences[0]['id'] ?? 0 ),
			'series'      => $series,
			'forms'       => self::form_list(),
			'annual'      => array(
				'form_id'   => Form_Map::annual_form_id(),
				'season'    => Annual_Form::season(),
				'dropdowns' => array_map( static fn( $k, $v ) => array( 'value' => (string) $k, 'label' => $v ), array_keys( Conferences::annual_dropdowns() ), Conferences::annual_dropdowns() ),
			),
			'overrides'   => $statuses,
			'pages'       => array_map(
				static fn( $p ) => array( 'id' => (int) $p->ID, 'title' => html_entity_decode( $p->post_title, ENT_QUOTES ) ),
				get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => true ) )
			),
			'nextYear'    => Conferences::next_season_year(),
		);
	}

	public function r_get( \WP_REST_Request $r ) {
		$id = self::edition_or_error( absint( $r['id'] ) );
		return is_wp_error( $id ) ? $id : self::state( $id );
	}

	public function r_check( \WP_REST_Request $r ) {
		$id = self::edition_or_error( absint( $r['id'] ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return self::evaluate( $id, self::clean( (array) $r->get_param( 'values' ), self::load( $id ) ) );
	}

	public function r_save( \WP_REST_Request $r ) {
		$id = self::edition_or_error( absint( $r['id'] ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$values = self::clean( (array) $r->get_param( 'values' ), self::load( $id ) );
		if ( ! $values['series'] || Conferences::SERIES !== get_post_type( (int) $values['series'] ) ) {
			return new \WP_Error( 'zpvp_series', 'Choose the conference series first.', array( 'status' => 400 ) );
		}
		if ( $values['year'] < 2015 || $values['year'] > 2100 ) {
			return new \WP_Error( 'zpvp_year', 'Enter the conference year.', array( 'status' => 400 ) );
		}
		$taken = self::year_taken( $id, $values['series'], $values['year'] );
		if ( $taken ) {
			return new \WP_Error( 'zpvp_dup', sprintf( '%s already exists. Open that conference instead.', self::plain( $taken ) ), array( 'status' => 409 ) );
		}
		$status = sanitize_key( (string) $r->get_param( 'status' ) );
		// Publishing needs a clean check list, enforced here as well as by the disabled Publish button.
		// Saves to a conference that is already live stay allowed, so staff can fix one problem at a time.
		if ( 'publish' === $status && 'publish' !== get_post_status( $id ) ) {
			$evaluation = self::evaluate( $id, $values );
			if ( ! empty( $evaluation['counts']['error'] ) ) {
				return new \WP_Error( 'zpvp_publish_blocked', 'Fix the items marked Needs attention before publishing.', array( 'status' => 400, 'checks' => $evaluation['checks'] ) );
			}
		}
		self::persist( $id, $values );

		if ( in_array( $status, array( 'publish', 'draft' ), true ) && get_post_status( $id ) !== $status ) {
			wp_update_post( array( 'ID' => $id, 'post_status' => $status ) );
		}
		Conferences::finalise( $id );
		clean_post_cache( $id );
		return self::state( $id ) + array( 'saved' => true );
	}

	/** Create a conference: next year's copy of a series' latest one, or a new draft. */
	public function r_new( \WP_REST_Request $r ) {
		$series = absint( $r->get_param( 'series' ) );
		$name   = sanitize_text_field( (string) $r->get_param( 'name' ) );
		$year   = absint( $r->get_param( 'year' ) );
		if ( $year < 2015 || $year > 2100 ) {
			return new \WP_Error( 'zpvp_year', 'Enter the conference year.', array( 'status' => 400 ) );
		}
		if ( ! $series && '' !== $name ) {
			$series = Conferences::group_by_name( $name );
			if ( ! $series ) {
				$series = (int) wp_insert_post( array( 'post_type' => Conferences::SERIES, 'post_status' => 'publish', 'post_title' => $name ) );
			}
		}
		if ( ! $series || Conferences::SERIES !== get_post_type( $series ) ) {
			return new \WP_Error( 'zpvp_series', 'Choose a series or type a new event name.', array( 'status' => 400 ) );
		}
		$existing = self::year_taken( 0, $series, $year );
		if ( $existing ) {
			return array( 'id' => $existing, 'created' => false );
		}
		$latest = Series::conferences( $series )[0] ?? 0;
		if ( $latest ) {
			[ $id, $created ] = Conferences::copy_to_year( $latest, $year );
		} else {
			$id = (int) wp_insert_post(
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
			return new \WP_Error( 'zpvp_create', 'The conference could not be created.', array( 'status' => 500 ) );
		}
		Conferences::set_field( (int) $id, 'series', 'field_zpvp_ed_series', $series );
		Conferences::finalise( (int) $id );
		return array( 'id' => (int) $id, 'created' => (bool) $created, 'copied' => (bool) $latest );
	}

	public function r_form( \WP_REST_Request $r ): array {
		return Field_Maps::describe( absint( $r['form'] ) );
	}

	public function r_form_save( \WP_REST_Request $r ) {
		$form_id = absint( $r['form'] );
		if ( ! class_exists( '\GFAPI' ) || ! \GFAPI::get_form( $form_id ) ) {
			return new \WP_Error( 'zpvp_form', 'Form not found.', array( 'status' => 404 ) );
		}
		Field_Maps::save( $form_id, (array) $r->get_param( 'map' ) );
		return Field_Maps::describe( $form_id ) + array( 'saved' => true );
	}

	public function r_form_test( \WP_REST_Request $r ) {
		$form_id = absint( $r['form'] );
		$form    = class_exists( '\GFAPI' ) ? \GFAPI::get_form( $form_id ) : null;
		if ( ! $form ) {
			return new \WP_Error( 'zpvp_form', 'Form not found.', array( 'status' => 404 ) );
		}
		$entries = \GFAPI::get_entries( $form_id, array( 'status' => 'active' ), array( 'key' => 'date_created', 'direction' => 'DESC' ), array( 'offset' => 0, 'page_size' => 1 ) );
		if ( is_wp_error( $entries ) || ! $entries ) {
			return array( 'found' => false, 'message' => 'This form has no entries yet, so there is nothing to test with. Submit a test entry, then try again.' );
		}
		return array( 'found' => true ) + ( new Registration_Sync() )->preview_entry( $entries[0], $form );
	}

	/* ------------------------------------------------------------------ */
	/* Values                                                              */
	/* ------------------------------------------------------------------ */

	/** Current saved values, in the builder's input formats. */
	public static function load( int $id ): array {
		$series = (int) get_post_meta( $id, 'series', true );
		$v      = array(
			'series'               => $series,
			'forms'                => Series::form_ids( $series, 'gf_forms' ),
			'sponsorship_forms'    => Series::form_ids( $series, 'sponsorship_forms' ),
			'annual_product_field' => $series ? (string) get_post_meta( $series, 'annual_product_field', true ) : '',
		);
		foreach ( array_keys( self::FIELDS ) as $key ) {
			$raw = (string) get_post_meta( $id, $key, true );
			if ( in_array( $key, self::DATE_FIELDS, true ) ) {
				$d   = '' !== $raw ? \DateTime::createFromFormat( 'Ymd', $raw ) : false;
				$raw = $d ? $d->format( 'Y-m-d' ) : '';
			} elseif ( in_array( $key, self::DATETIME_FIELDS, true ) ) {
				$raw = '' !== $raw ? substr( str_replace( ' ', 'T', $raw ), 0, 16 ) : '';
			}
			$v[ $key ] = $raw;
		}
		$v['year']             = (int) $v['year'];
		$v['floor_plan']       = (int) $v['floor_plan'];
		$reg_url               = (string) get_post_meta( $id, 'registration_url', true );
		$v['registration_url'] = is_numeric( $reg_url ) ? (int) $reg_url : 0;
		$v['resource_files']   = array();
		$count                 = (int) get_post_meta( $id, 'resource_files', true );
		for ( $i = 0; $i < $count; $i++ ) {
			$file = (int) get_post_meta( $id, 'resource_files_' . $i . '_file', true );
			if ( $file ) {
				$v['resource_files'][] = array( 'file' => $file, 'label' => (string) get_post_meta( $id, 'resource_files_' . $i . '_label', true ) );
			}
		}
		$v['status_override'] = '' === $v['status_override'] ? 'auto' : $v['status_override'];
		return $v;
	}

	/** Validate submitted values; anything missing keeps its saved value. */
	public static function clean( array $in, array $saved ): array {
		$v = $saved;
		if ( array_key_exists( 'series', $in ) ) {
			$s           = absint( $in['series'] );
			$v['series'] = ( $s && Conferences::SERIES === get_post_type( $s ) ) ? $s : 0;
		}
		foreach ( array( 'forms', 'sponsorship_forms' ) as $key ) {
			if ( array_key_exists( $key, $in ) ) {
				$v[ $key ] = array_values( array_unique( array_filter( array_map( 'absint', (array) $in[ $key ] ) ) ) );
			}
		}
		if ( array_key_exists( 'annual_product_field', $in ) ) {
			$field                     = preg_replace( '/[^0-9.]/', '', (string) $in['annual_product_field'] );
			$v['annual_product_field'] = isset( Conferences::annual_dropdowns()[ $field ] ) ? $field : '';
		}
		if ( array_key_exists( 'registration_url', $in ) ) {
			$page                  = absint( $in['registration_url'] );
			$v['registration_url'] = ( $page && 'page' === get_post_type( $page ) ) ? $page : 0;
		}
		if ( array_key_exists( 'resource_files', $in ) ) {
			$v['resource_files'] = array();
			foreach ( array_slice( (array) $in['resource_files'], 0, 50 ) as $row ) {
				$file = absint( is_array( $row ) ? ( $row['file'] ?? 0 ) : 0 );
				if ( $file && 'attachment' === get_post_type( $file ) ) {
					$v['resource_files'][] = array( 'file' => $file, 'label' => sanitize_text_field( (string) ( $row['label'] ?? '' ) ) );
				}
			}
		}
		foreach ( array_keys( self::FIELDS ) as $key ) {
			if ( ! array_key_exists( $key, $in ) ) {
				continue;
			}
			$raw = $in[ $key ];
			if ( in_array( $key, self::DATE_FIELDS, true ) ) {
				$v[ $key ] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $raw ) ? (string) $raw : '';
			} elseif ( in_array( $key, self::DATETIME_FIELDS, true ) ) {
				$v[ $key ] = preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', (string) $raw ) ? substr( (string) $raw, 0, 16 ) : '';
			} elseif ( in_array( $key, self::HTML_FIELDS, true ) ) {
				$v[ $key ] = wp_kses_post( (string) $raw );
			} elseif ( 'year' === $key ) {
				$v[ $key ] = absint( $raw );
			} elseif ( 'floor_plan' === $key ) {
				$v[ $key ] = absint( $raw );
			} elseif ( 'booth_capacity' === $key ) {
				$v[ $key ] = '' === trim( (string) $raw ) ? '' : (string) absint( $raw );
			} elseif ( 'status_override' === $key ) {
				$v[ $key ] = in_array( $raw, array( 'auto', 'open', 'sold_out', 'closed' ), true ) ? (string) $raw : 'auto';
			} else {
				$v[ $key ] = sanitize_text_field( (string) $raw );
			}
		}
		return $v;
	}

	/** Write values to the conference (and the forms to its series). */
	private static function persist( int $id, array $v ): void {
		Conferences::set_field( $id, 'series', 'field_zpvp_ed_series', $v['series'] );
		foreach ( self::FIELDS as $key => $ref ) {
			$value = $v[ $key ];
			if ( in_array( $key, self::DATE_FIELDS, true ) ) {
				$value = '' !== $value ? str_replace( '-', '', $value ) : '';
			} elseif ( in_array( $key, self::DATETIME_FIELDS, true ) ) {
				$value = '' !== $value ? str_replace( 'T', ' ', $value ) . ':00' : '';
			} elseif ( 'floor_plan' === $key ) {
				$value = $value ? (int) $value : '';
			}
			Conferences::set_field( $id, $key, $ref, $value );
		}
		$manual = (string) get_post_meta( $id, 'registration_url', true );
		if ( $v['registration_url'] || is_numeric( $manual ) ) {
			// A typed URL set in the classic editor is kept unless a page is chosen here.
			Conferences::set_field( $id, 'registration_url', 'field_zpvp_ed_reg_url', $v['registration_url'] ? (string) $v['registration_url'] : '' );
		}
		self::persist_files( $id, $v['resource_files'] );
		$series = (int) $v['series'];
		Conferences::set_field( $series, 'gf_forms', 'field_zpvp_series_gf_forms', array_map( 'strval', $v['forms'] ) );
		Conferences::set_field( $series, 'sponsorship_forms', 'field_zpvp_series_sponsor_forms', array_map( 'strval', $v['sponsorship_forms'] ) );
		Conferences::set_field( $series, 'annual_product_field', 'field_zpvp_series_annual_field', $v['annual_product_field'] );
	}

	/** A registration link typed as a URL in the classic editor ('' when none or a page was chosen). */
	private static function typed_url( int $id ): string {
		$manual = (string) get_post_meta( $id, 'registration_url', true );
		return is_numeric( $manual ) ? '' : $manual;
	}

	/** Documents are an ACF repeater: a row count plus one label and file per row. */
	private static function persist_files( int $id, array $rows ): void {
		$old = (int) get_post_meta( $id, 'resource_files', true );
		foreach ( array_values( $rows ) as $i => $row ) {
			Conferences::set_field( $id, 'resource_files_' . $i . '_label', 'field_zpvp_ed_file_label', $row['label'] );
			Conferences::set_field( $id, 'resource_files_' . $i . '_file', 'field_zpvp_ed_file_file', (string) $row['file'] );
		}
		for ( $i = count( $rows ); $i < $old; $i++ ) {
			foreach ( array( 'label', 'file' ) as $sub ) {
				delete_post_meta( $id, 'resource_files_' . $i . '_' . $sub );
				delete_post_meta( $id, '_resource_files_' . $i . '_' . $sub );
			}
		}
		Conferences::set_field( $id, 'resource_files', 'field_zpvp_ed_files', count( $rows ) );
	}

	/** Another conference of the same series and year (0 if none). */
	private static function year_taken( int $id, int $series, int $year ): int {
		foreach ( Series::conferences( $series ) as $c ) {
			if ( $c !== $id && (int) get_post_meta( $c, 'year', true ) === $year ) {
				return $c;
			}
		}
		return 0;
	}

	/* ------------------------------------------------------------------ */
	/* Evaluation: checks, flow, routing, preview                          */
	/* ------------------------------------------------------------------ */

	private static function state( int $id ): array {
		$values = self::load( $id );
		return array(
			'id'        => $id,
			'published' => 'publish' === get_post_status( $id ),
			'values'    => $values,
			'editUrl'   => add_query_arg( 'classic', 1, (string) get_edit_post_link( $id, 'url' ) ),
			'floorPlan' => self::attachment( (int) $values['floor_plan'] ),
			'files'     => array_map( static fn( $r ) => self::attachment( (int) $r['file'] ), $values['resource_files'] ),
			'after'     => self::after( $id ),
			'regPage'   => array(
				'typed'    => self::typed_url( $id ),
				'detected' => ( static function ( $found ) {
					return $found['page'] ? array( 'title' => self::plain( (int) $found['page'] ), 'url' => (string) get_permalink( (int) $found['page'] ) ) : null;
				} )( Conferences::detect_registration_page( $id ) ),
			),
		) + self::evaluate( $id, $values );
	}

	/** What staff can do with a saved conference: change notice, email vendors, copy for next year. */
	private static function after( int $id ): array {
		$settings = Change_Notices::settings();
		$next     = wp_next_scheduled( Change_Notices::HOOK, array( $id ) );
		$last     = get_post_meta( $id, '_zpvp_last_change_notice', true );
		$notice   = static fn( string $do ) => add_query_arg(
			array( 'action' => 'zpvp_change_notice', 'do' => $do, 'edition' => $id, 'back' => 'builder', '_wpnonce' => wp_create_nonce( 'zpvp_change_notice_' . $id ) ),
			admin_url( 'admin-post.php' )
		);
		return array(
			'noticesOn'     => (bool) $settings['enabled'],
			'delay'         => (int) $settings['delay'],
			'pending'       => Change_Notices::describe( $id ),
			'sendsAt'       => $next ? wp_date( 'g:i a', $next ) : '',
			'sendUrl'       => $notice( 'send' ),
			'cancelUrl'     => $notice( 'cancel' ),
			'lastNotice'    => is_array( $last ) && ! empty( $last['at'] ) ? sprintf( '%s, %d registration(s)', mysql2date( 'M j, Y g:i a', $last['at'] ), (int) $last['sent'] ) : '',
			'registrations' => count( Admin_Messages::registrations( $id, 'all' ) ),
			'emailUrl'      => admin_url( 'admin.php?page=zpvp&compose=1&edition=' . $id ),
			'copyUrl'       => add_query_arg( 'back', 'builder', wp_specialchars_decode( Conferences::copy_url( $id ) ) ),
			'nextYear'      => ( (int) get_post_meta( $id, 'year', true ) ?: (int) substr( (string) get_post_meta( $id, 'start_date', true ), 0, 4 ) ?: (int) current_time( 'Y' ) ) + 1,
		);
	}

	private static function attachment( int $id ): array {
		$url = $id ? wp_get_attachment_url( $id ) : '';
		return $url ? array( 'id' => $id, 'url' => $url, 'name' => wp_basename( (string) get_attached_file( $id ) ) ) : array( 'id' => 0, 'url' => '', 'name' => '' );
	}

	/** Everything that depends on the (possibly unsaved) values. */
	public static function evaluate( int $id, array $v ): array {
		$published  = 'publish' === get_post_status( $id );
		$series     = (int) $v['series'];
		$name       = $series ? get_post_field( 'post_title', $series ) : '';
		$title      = trim( $name . ' ' . ( $v['year'] ? $v['year'] : '' ) );
		$taken      = Conferences::booths_taken( $id );
		$capacity   = '' === (string) $v['booth_capacity'] ? 0 : (int) $v['booth_capacity'];
		$status     = self::status( $v, $taken );
		$checks     = array();
		$add        = static function ( string $group, string $level, string $text, string $step ) use ( &$checks ) {
			$checks[] = array( 'group' => $group, 'level' => $level, 'text' => $text, 'step' => $step );
		};

		/* Event */
		if ( ! $series ) {
			$add( 'event', 'error', 'Choose the conference series (the event this is a year of).', 'event' );
		}
		if ( ! $v['year'] ) {
			$add( 'event', 'error', 'Enter the conference year.', 'event' );
		} elseif ( $series && ( $dup = self::year_taken( $id, $series, (int) $v['year'] ) ) ) {
			$add( 'event', 'error', sprintf( '%s already exists. Each event can have one conference per year.', self::plain( $dup ) ), 'event' );
		}
		if ( '' === $v['start_date'] ) {
			$add( 'event', 'warn', 'Add the start date. Without it vendors see no dates and the annual form shows "Dates to be announced".', 'event' );
		} elseif ( '' !== $v['end_date'] && $v['end_date'] < $v['start_date'] ) {
			$add( 'event', 'error', 'The end date is before the start date.', 'event' );
		} else {
			$add( 'event', 'ok', 'Dates: ' . self::date_range( $v ) . '.', 'event' );
		}
		if ( '' === trim( $v['venue'] . $v['city'] ) ) {
			$add( 'event', 'warn', 'Add the venue and city so vendors know where it is.', 'event' );
		}

		/* Forms and routing */
		$forms      = array();
		$all_forms  = array();
		$annual     = (string) $v['annual_product_field'];
		foreach ( array( 'registration' => $v['forms'], 'sponsorship' => $v['sponsorship_forms'] ) as $role => $ids ) {
			foreach ( $ids as $form_id ) {
				$forms[] = self::form_info( (int) $form_id, $role, $id, $v, $published );
				$all_forms[] = (int) $form_id;
			}
		}
		if ( ! $v['forms'] && '' === $annual ) {
			$add( 'forms', 'error', 'Choose a registration form, or a booth dropdown on the annual form, so vendors can register.', 'forms' );
		}
		foreach ( $forms as $f ) {
			foreach ( $f['issues'] as $issue ) {
				$add( 'forms', $issue[0], $issue[1], 'forms' );
			}
			if ( ! $f['issues'] ) {
				$add( 'forms', 'ok', sprintf( '%s is on, on a page, and its %s are recorded here.', $f['title'], 'sponsorship' === $f['role'] ? 'sponsorships' : 'registrations' ), 'forms' );
			}
		}
		$season = Annual_Form::season();
		if ( '' !== $annual ) {
			$label = Conferences::annual_dropdowns()[ $annual ] ?? 'field ' . $annual;
			if ( (int) $v['year'] === $season ) {
				$add( 'forms', 'ok', sprintf( 'Annual form: the "%s" dropdown books this conference for %d.', $label, $season ), 'forms' );
			} else {
				$add( 'forms', 'info', sprintf( 'Annual form: the "%s" dropdown is taking bookings for %d, so they go to that year\'s conference, not this one.', $label, $season ), 'forms' );
			}
			$all_forms[] = Form_Map::annual_form_id();
		}

		/* Connectors */
		$connectors = array();
		foreach ( array_unique( array_filter( $all_forms ) ) as $form_id ) {
			$d            = Field_Maps::describe( (int) $form_id );
			$connected    = count( array_filter( $d['rows'], static fn( $row ) => in_array( $row['state'], array( 'auto', 'custom' ), true ) ) );
			$connectors[] = array( 'id' => (int) $form_id, 'title' => $d['form']['title'], 'state' => $d['state'], 'connected' => $connected, 'total' => count( $d['rows'] ), 'missing' => $d['missing'], 'kind' => $d['kind'] );
			if ( ! $d['form']['exists'] ) {
				continue;
			}
			if ( 'error' === $d['state'] ) {
				$add( 'connectors', 'error', sprintf( '%s: %d required field connector(s) need a field. Registrations would be missing data.', $d['form']['title'], max( 1, $d['missing'] ) ), 'connectors' );
			} elseif ( 'warn' === $d['state'] ) {
				$add( 'connectors', 'warn', sprintf( '%s: some optional connectors have suggestions waiting to be saved.', $d['form']['title'] ), 'connectors' );
			} else {
				$add( 'connectors', 'ok', sprintf( '%s: %d of %d fields connected.', $d['form']['title'], $connected, count( $d['rows'] ) ), 'connectors' );
			}
		}

		/* Registration window */
		if ( '' === $v['registration_opens'] ) {
			$add( 'registration', 'warn', 'Set when registration opens. Until then the conference shows as "Not scheduled" and is not listed under Open for registration.', 'registration' );
		} elseif ( '' !== $v['registration_closes'] && $v['registration_closes'] <= $v['registration_opens'] ) {
			$add( 'registration', 'error', 'Registration closes before it opens.', 'registration' );
		} else {
			$add( 'registration', 'ok', sprintf( 'Registration opens %s%s.', self::when( $v['registration_opens'] ), '' !== $v['registration_closes'] ? ' and closes ' . self::when( $v['registration_closes'] ) : ', with no closing date' ), 'registration' );
		}
		if ( '' !== $v['start_date'] && '' !== $v['registration_closes'] && substr( $v['registration_closes'], 0, 10 ) > ( '' !== $v['end_date'] ? $v['end_date'] : $v['start_date'] ) ) {
			$add( 'registration', 'warn', 'Registration closes after the conference ends.', 'registration' );
		}
		if ( '' === $v['attendee_edit_deadline'] ) {
			$add( 'registration', 'info', 'No booth staff deadline: vendors can change booth staff until the conference starts.', 'registration' );
		} elseif ( '' !== $v['start_date'] && substr( $v['attendee_edit_deadline'], 0, 10 ) > $v['start_date'] ) {
			$add( 'registration', 'warn', 'The booth staff deadline is after the conference starts, so badges may change after printing.', 'registration' );
		}
		if ( $capacity ) {
			$add( 'registration', $taken >= $capacity ? 'warn' : 'ok', sprintf( '%d of %d booths taken, %d left.', $taken, $capacity, max( 0, $capacity - $taken ) ), 'registration' );
		} else {
			$add( 'registration', 'info', 'No booth capacity set, so registration never sells out on its own.', 'registration' );
		}
		if ( 'auto' !== $v['status_override'] ) {
			$add( 'registration', 'warn', sprintf( 'Status is fixed to "%s" instead of following the dates.', Conferences::status_label( $v['status_override'] ) ), 'registration' );
		}

		/* Vendor information */
		$has_info = array_filter( array_map( static fn( $k ) => trim( wp_strip_all_tags( (string) $v[ $k ] ) ), self::HTML_FIELDS ) );
		if ( ! $v['floor_plan'] ) {
			$add( 'resources', 'info', 'No floor plan yet. Vendors see it on My Conferences once you add it.', 'resources' );
		}
		if ( ! $has_info ) {
			$add( 'resources', 'info', 'No hotel, decorator or electricity information yet.', 'resources' );
		} else {
			$add( 'resources', 'ok', sprintf( 'Vendors see %d information section(s).', count( $has_info ) + ( $v['floor_plan'] ? 1 : 0 ) ), 'resources' );
		}

		/* Publish */
		if ( ! $published ) {
			$add( 'publish', 'warn', 'Draft: vendors do not see this conference yet. Publish when the checks above are green.', 'publish' );
		} elseif ( in_array( $status, array( 'open', 'sold_out' ), true ) ) {
			$add( 'publish', 'ok', 'Published and listed under Open for registration on My Conferences.', 'publish' );
		} else {
			$add( 'publish', 'info', sprintf( 'Published. Vendors see it under Open for registration once it is open (now: %s).', Conferences::status_label( $status ) ), 'publish' );
		}

		$levels = array( 'error' => 0, 'warn' => 0, 'info' => 0, 'ok' => 0 );
		foreach ( $checks as $c ) {
			++$levels[ $c['level'] ];
		}

		return array(
			'title'      => '' !== $title ? $title : 'New conference',
			'status'     => $status,
			'statusText' => Conferences::status_label( $status ),
			'counts'     => $levels,
			'checks'     => $checks,
			'formsInfo'  => $forms,
			'connectors' => $connectors,
			'flow'       => self::flow( $checks, $forms, $connectors, $title, $status, $published, $v ),
			'summary'    => self::summary( $forms, $v, $title, $taken, $capacity, $status, $published, $season ),
			'preview'    => self::preview( $v, $title, $status, $published, $id ),
			'booths'     => array( 'taken' => $taken, 'capacity' => $capacity ),
		);
	}

	private static function worst( array $checks, array $groups ): string {
		$rank = array( 'ok' => 0, 'info' => 0, 'warn' => 1, 'error' => 2 );
		$w    = 0;
		foreach ( $checks as $c ) {
			if ( in_array( $c['group'], $groups, true ) ) {
				$w = max( $w, $rank[ $c['level'] ] );
			}
		}
		return array( 'ok', 'warn', 'error' )[ $w ];
	}

	private static function flow( array $checks, array $forms, array $connectors, string $title, string $status, bool $published, array $v ): array {
		$reg      = count( array_filter( $forms, static fn( $f ) => 'registration' === $f['role'] ) );
		$spon     = count( $forms ) - $reg;
		$parts    = array_filter( array( $reg ? $reg . ' registration' : '', $spon ? $spon . ' sponsorship' : '', '' !== $v['annual_product_field'] ? 'annual form' : '' ) );
		$connected = array_sum( array_column( $connectors, 'connected' ) );
		$total     = array_sum( array_column( $connectors, 'total' ) );
		$personnel = false;
		foreach ( $connectors as $c ) {
			if ( 'standard' === $c['kind'] ) {
				$personnel = true;
			}
		}
		return array(
			array( 'key' => 'forms', 'title' => 'Gravity Forms', 'sub' => $parts ? implode( ' + ', $parts ) : 'None chosen', 'state' => self::worst( $checks, array( 'forms' ) ) ),
			array( 'key' => 'connectors', 'title' => 'Field connectors', 'sub' => $total ? $connected . ' of ' . $total . ' connected' : 'Nothing to connect', 'state' => $total ? self::worst( $checks, array( 'connectors' ) ) : 'warn' ),
			array( 'key' => 'event', 'title' => '' !== $title ? $title : 'Conference', 'sub' => Conferences::status_label( $status ), 'state' => self::worst( $checks, array( 'event', 'registration' ) ) ),
			array( 'key' => 'publish', 'title' => 'Vendor portal', 'sub' => $published ? ( in_array( $status, array( 'open', 'sold_out' ), true ) ? 'Visible to vendors' : 'Published, not open yet' ) : 'Draft, not visible', 'state' => self::worst( $checks, array( 'publish', 'resources' ) ) ),
			array( 'key' => 'outputs', 'title' => 'Badges · Invoices · Emails', 'sub' => $personnel ? 'Booth staff become badges' : 'Invoices and emails', 'state' => self::worst( $checks, array( 'connectors' ) ) ),
		);
	}

	/** Plain-language "what happens" lines. */
	private static function summary( array $forms, array $v, string $title, int $taken, int $capacity, string $status, bool $published, int $season ): array {
		$out = array();
		foreach ( $forms as $f ) {
			$out[] = $f['route'];
		}
		if ( '' !== $v['annual_product_field'] ) {
			$label = Conferences::annual_dropdowns()[ $v['annual_product_field'] ] ?? '';
			$out[] = (int) $v['year'] === $season
				? sprintf( 'Vendors who pick "%s" on the annual form get a booking here; the annual pre-registration fee is tracked separately.', $label )
				: sprintf( 'Annual form bookings for "%s" go to the %d conference.', $label, $season );
		}
		$out[] = $capacity
			? sprintf( 'Booths: %d of %d taken. At %d the conference shows Sold out.', $taken, $capacity, $capacity )
			: sprintf( 'Booths: %d taken, no limit set.', $taken );
		$deadline = '' !== $v['attendee_edit_deadline'] ? self::when( $v['attendee_edit_deadline'] ) : ( '' !== $v['start_date'] ? 'the start date' : 'the conference starts' );
		$out[]    = sprintf( 'Vendors can change booth staff until %s. Each person becomes a badge in the print queue.', $deadline );
		$out[]    = 'Each registration gets an invoice. Once it is paid the vendor gets a receipt by email.';
		$out[]    = $published
			? ( in_array( $status, array( 'open', 'sold_out' ), true ) ? 'Vendors see it now under Open for registration.' : 'Vendors see it under Open for registration when registration opens.' )
			: 'Not visible to vendors until you publish it.';
		return $out;
	}

	/** One form: page, on/off, where its submissions go, issues. */
	private static function form_info( int $form_id, string $role, int $id, array $v, bool $published ): array {
		$form   = class_exists( '\GFAPI' ) ? \GFAPI::get_form( $form_id ) : null;
		$title  = $form ? (string) $form['title'] : 'Form ' . $form_id;
		$issues = array();
		$page   = 0;
		if ( ! $form ) {
			$issues[] = array( 'error', sprintf( 'Form %d no longer exists in Gravity Forms. Remove it.', $form_id ) );
		} else {
			if ( empty( $form['is_active'] ) ) {
				$issues[] = array( 'warn', sprintf( '%s is switched off in Gravity Forms. Switch it on before registration opens.', $title ) );
			}
			$page = self::page_for_form( $form_id );
			if ( ! $page && 'registration' === $role && ! $v['registration_url'] && '' === self::typed_url( $id ) ) {
				$issues[] = array( 'warn', sprintf( '%s is not on any published page, so the Register button has nowhere to go.', $title ) );
			}
			$other = self::other_series( $form_id, (int) $v['series'], $role );
			if ( $other ) {
				$issues[] = array( 'error', sprintf( '%s is also linked to the series "%s". A form can only feed one series.', $title, self::plain( $other ) ) );
			}
		}

		$target = self::route( $id, (int) $v['series'], (int) $v['year'], $published );
		$noun   = 'sponsorship' === $role ? 'sponsorships' : 'registrations';
		if ( $target === $id ) {
			$route = $published
				? sprintf( 'New %s from %s are recorded under %s.', $noun, $title, self::title_for( $id, $v ) )
				: sprintf( 'New %s from %s are recorded under %s, but vendors only see it once it is published.', $noun, $title, self::title_for( $id, $v ) );
		} elseif ( $target ) {
			$route = sprintf( 'New %s from %s still go to %s, the newest published year.', $noun, $title, self::plain( $target ) );
			if ( ! $published ) {
				$route .= ' Publishing this conference moves them here.';
			}
			$issues[] = array( 'info', $route );
		} else {
			$route = sprintf( 'New %s from %s are kept under the series until a conference is set up.', $noun, $title );
		}

		return array(
			'id'     => $form_id,
			'title'  => $title,
			'role'   => $role,
			'active' => $form ? ! empty( $form['is_active'] ) : false,
			'exists' => (bool) $form,
			'page'   => $page ? array( 'title' => self::plain( $page ), 'url' => (string) get_permalink( $page ) ) : null,
			'edit'   => admin_url( 'admin.php?page=gf_edit_forms&id=' . $form_id ),
			'route'  => $route,
			'issues' => $issues,
		);
	}

	private static function title_for( int $id, array $v ): string {
		$name = $v['series'] ? get_post_field( 'post_title', (int) $v['series'] ) : self::plain( $id );
		return trim( $name . ' ' . ( $v['year'] ? $v['year'] : '' ) );
	}

	/**
	 * Which conference new submissions go to with these values: the series'
	 * newest published conference, drafts only as a fallback (same rule as
	 * Conferences::for_form()).
	 */
	private static function route( int $id, int $series, int $year, bool $published ): int {
		if ( ! $series ) {
			return 0;
		}
		$rows = array();
		foreach ( Series::conferences( $series ) as $c ) {
			if ( $c !== $id ) {
				$rows[] = array( 'publish' === get_post_status( $c ) ? 1 : 0, (int) get_post_meta( $c, 'year', true ), $c );
			}
		}
		$rows[] = array( $published ? 1 : 0, $year, $id );
		usort( $rows, static fn( $a, $b ) => $b <=> $a );
		return (int) $rows[0][2];
	}

	/** A published series other than this one that lists the form in the same role. */
	private static function other_series( int $form_id, int $series, string $role ): int {
		$key = 'sponsorship' === $role ? 'sponsorship_forms' : 'gf_forms';
		foreach ( Conferences::series_with( $key, '"' . $form_id . '"', 'LIKE' ) as $s ) {
			if ( $s !== $series ) {
				return $s;
			}
		}
		return 0;
	}

	private static function page_for_form( int $form_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish'
				AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s )
				ORDER BY post_modified DESC LIMIT 1",
				'%[gravityform%id="' . $form_id . '"%',
				"%[gravityform%id='" . $form_id . "'%",
				'%"formId":"' . $form_id . '"%'
			)
		);
	}

	/** Conferences::status() for unsaved values. */
	private static function status( array $v, int $taken ): string {
		if ( 'auto' !== $v['status_override'] ) {
			return $v['status_override'];
		}
		$now   = current_time( 'Y-m-d\TH:i' );
		$today = current_time( 'Y-m-d' );
		if ( '' !== $v['end_date'] && $today > $v['end_date'] ) {
			return 'past';
		}
		if ( '' === $v['registration_opens'] ) {
			return 'not_scheduled';
		}
		if ( $now < $v['registration_opens'] ) {
			return 'upcoming';
		}
		if ( '' !== $v['registration_closes'] && $now > $v['registration_closes'] ) {
			return 'closed';
		}
		$capacity = (int) $v['booth_capacity'];
		return ( $capacity > 0 && $taken >= $capacity ) ? 'sold_out' : 'open';
	}

	private static function when( string $local ): string {
		$d = \DateTime::createFromFormat( 'Y-m-d\TH:i', substr( $local, 0, 16 ) );
		return $d ? $d->format( 'M j, Y g:i a' ) : $local;
	}

	private static function date_range( array $v ): string {
		$s = '' !== $v['start_date'] ? \DateTime::createFromFormat( 'Y-m-d', $v['start_date'] ) : false;
		$e = '' !== $v['end_date'] ? \DateTime::createFromFormat( 'Y-m-d', $v['end_date'] ) : false;
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

	/* ------------------------------------------------------------------ */
	/* Preview (same markup and classes as My Conferences)                 */
	/* ------------------------------------------------------------------ */

	private static function preview( array $v, string $title, string $status, bool $published, int $id ): array {
		$title  = '' !== $title ? $title : 'Conference name';
		$range  = self::date_range( $v );
		$s      = '' !== $v['start_date'] ? \DateTime::createFromFormat( 'Y-m-d', $v['start_date'] ) : false;
		$e      = '' !== $v['end_date'] ? \DateTime::createFromFormat( 'Y-m-d', $v['end_date'] ) : false;
		$month  = $s ? $s->format( 'M' ) : 'Date';
		$days   = $s ? $s->format( 'j' ) . ( $e && $e->format( 'Ymd' ) !== $s->format( 'Ymd' ) ? '–' . ( $e->format( 'm' ) === $s->format( 'm' ) ? $e->format( 'j' ) : $e->format( 'M j' ) ) : '' ) : 'TBA';
		$closes = '' !== $v['registration_closes'] ? \DateTime::createFromFormat( 'Y-m-d\TH:i', $v['registration_closes'] ) : false;
		$line   = implode( '. ', array_filter( array( trim( $range . ( $v['city'] ? ', ' . $v['city'] : '' ), ', ' ), $closes ? 'Registration closes ' . $closes->format( 'F j' ) : '' ) ) );

		if ( 'sold_out' === $status ) {
			$cta = '<span class="zpvp-pill zpvp-pill--soldout">Sold out</span>';
		} elseif ( 'open' === $status ) {
			$cta = '<span class="zpvp-btn zpvp-btn--accent">Register</span>';
		} else {
			$cta = '<span class="zpvp-pill">' . esc_html( Conferences::status_label( $status ) ) . '</span>';
		}
		$open = '<ul class="zpvp-events"><li class="zpvp-event">'
			. '<div class="zpvp-event__date" aria-hidden="true"><span>' . esc_html( $month ) . '</span><strong>' . esc_html( $days ) . '</strong></div>'
			. '<div class="zpvp-event__main"><h3>' . esc_html( $title ) . '</h3><p>' . esc_html( '' !== $line ? $line : 'Dates to be announced' ) . '</p></div>'
			. '<div class="zpvp-event__cta">' . $cta . '</div></li></ul>';

		$visible = $published && in_array( $status, array( 'open', 'sold_out' ), true );
		if ( ! $published ) {
			$note = 'Draft: vendors do not see this card yet.';
		} elseif ( ! $visible ) {
			$opens = '' !== $v['registration_opens'] ? self::when( $v['registration_opens'] ) : '';
			$note  = 'upcoming' === $status && $opens ? 'Vendors see this card from ' . $opens . '.' : 'Vendors do not see this card while the status is "' . Conferences::status_label( $status ) . '".';
		} else {
			$note = 'Vendors see this card now under Open for registration.';
		}

		$when = implode( ', ', array_filter( array( '' !== $range ? $range : (string) $v['year'], $v['city'] ) ) );
		$pass = '<div class="zpvp-passes"><article class="zpvp-pass">'
			. '<header class="zpvp-pass__top"><span class="zpvp-pass__hole" aria-hidden="true"></span><h3 class="zpvp-pass__event">' . esc_html( $title ) . '</h3>' . ( $when ? '<p class="zpvp-pass__when">' . esc_html( $when ) . '</p>' : '' ) . '</header>'
			. '<div class="zpvp-pass__body"><div class="zpvp-pass__booth"><span>Booth</span><em>Number to be assigned</em></div>'
			. '<dl class="zpvp-pass__facts"><div><dt>Booths</dt><dd>1</dd></div><div><dt>Payment</dt><dd><span class="zpvp-pill zpvp-pill--awaiting">Awaiting payment</span></dd></div><div><dt>Booth staff</dt><dd>2 people</dd></div></dl></div>'
			. '<footer class="zpvp-pass__actions"><span class="zpvp-btn zpvp-btn--primary">Manage booth staff</span><span class="zpvp-btn">Invoice</span></footer>'
			. '</article></div>';

		$sections = array();
		foreach ( array( 'hotel_info' => 'Hotel', 'decorator_info' => 'Decorator', 'electricity_info' => 'Electricity' ) as $key => $label ) {
			if ( '' !== trim( wp_strip_all_tags( (string) $v[ $key ] ) ) ) {
				$sections[] = '<div class="zpvp-info__item"><h4>' . esc_html( $label ) . '</h4>' . wp_kses_post( wpautop( (string) $v[ $key ] ) ) . '</div>';
			}
		}
		$files = array();
		if ( $v['floor_plan'] && wp_get_attachment_url( (int) $v['floor_plan'] ) ) {
			$files[] = '<li><a href="' . esc_url( (string) wp_get_attachment_url( (int) $v['floor_plan'] ) ) . '" target="_blank" rel="noopener">Floor plan</a></li>';
		}
		foreach ( $v['resource_files'] as $row ) {
			$file  = (int) $row['file'];
			$label = (string) $row['label'];
			if ( $file && wp_get_attachment_url( $file ) ) {
				$files[] = '<li><a href="' . esc_url( (string) wp_get_attachment_url( $file ) ) . '" target="_blank" rel="noopener">' . esc_html( $label ? $label : self::plain( $file ) ) . '</a></li>';
			}
		}
		if ( $files ) {
			$sections[] = '<div class="zpvp-info__item"><h4>Documents</h4><ul>' . implode( '', $files ) . '</ul></div>';
		}
		$info = $sections
			? '<details class="zpvp-info" open><summary>' . esc_html( $title ) . '</summary><div class="zpvp-info__grid">' . implode( '', $sections ) . '</div></details>'
			: '<p class="zpvp-empty">Nothing here yet. Add hotel, decorator or electricity information or a floor plan, and registered vendors see it on My Conferences.</p>';

		return array(
			'open'    => $open,
			'openNote' => $note,
			'visible' => $visible,
			'pass'    => $pass,
			'info'    => $info,
		);
	}

	/** A post title as plain text (get_the_title() returns HTML entities). */
	private static function plain( int $id ): string {
		return html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/** Forms staff can choose (not the portal's own account form or the annual form). */
	private static function form_list(): array {
		$out = array();
		if ( ! class_exists( '\GFAPI' ) ) {
			return $out;
		}
		$forms = \GFAPI::get_forms( null, false, 'title', 'ASC' );
		usort( $forms, static fn( $a, $b ) => array( (int) empty( $a['is_active'] ), $a['title'] ) <=> array( (int) empty( $b['is_active'] ), $b['title'] ) );
		foreach ( $forms as $form ) {
			if ( ! empty( $form[ Setup::FORM_KEY ] ) || (int) $form['id'] === Form_Map::annual_form_id() || (int) $form['id'] === (int) get_option( Settings::OPT_REG_FORM ) ) {
				continue;
			}
			$out[] = array( 'id' => (int) $form['id'], 'title' => (string) $form['title'], 'active' => ! empty( $form['is_active'] ) );
		}
		return $out;
	}
}
