<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * The separate badge forms: Extra Representative Order Form, Name Badge Order
 * Form, Onsite Badges and Onsite Name Badge Change Form.
 *
 * Each submission is recorded as a badge order and linked to the vendor's
 * conference registration. The people on the order become booth personnel
 * (so their badges join the print queue) and the badges the vendor paid for
 * are added to the registration's purchased badges. Orders that can't be
 * linked with confidence wait for staff on Vendor Portal → Badge orders.
 *
 * The forms don't require a login (on-site forms are often filled in at the
 * event). Logged-in vendors get their details prefilled and choose the
 * registration from a list added to the top of the form.
 */
final class Badge_Orders {

	public const OPT_FORMS = 'zpvp_badge_forms';
	public const OPT_PRICE = 'zpvp_badge_price';
	public const PAGE      = 'zpvp-badge-orders';

	public const KINDS = array(
		'extra_rep'     => 'Extra representative order',
		'name_badges'   => 'Name badge order',
		'onsite'        => 'On-site badges',
		'onsite_change' => 'On-site name badge change',
	);

	/**
	 * Field layouts from the Gravity Forms audit (forms 42, 60, 55 and 52).
	 * people: list field; people_cols: which list columns hold first name,
	 * last name (or the full name) and title. Change with the zpvp_badge_form_map filter.
	 */
	public const LAYOUTS = array(
		'extra_rep'     => array(
			'company'     => '9',
			'email'       => '3',
			'first_name'  => '2.3',
			'last_name'   => '2.6',
			'conference'  => '55',
			'additional'  => '62.3',
			'people'      => '63',
			'people_cols' => array( 'name' => 0, 'title' => 1 ),
		),
		'name_badges'   => array(
			'company'     => '10',
			'email'       => '14',
			'first_name'  => '15.3',
			'last_name'   => '15.6',
			'conference'  => '1',
			'reps'        => array( array( '3.3', '3.6' ), array( '4.3', '4.6' ) ),
			'additional'  => '13.3',
			'people'      => '5',
			'people_cols' => array( 'name' => 0, 'title' => 1 ),
			'total'       => '16',
		),
		'onsite'        => array(
			'company'     => '1',
			'booth'       => '4',
			'email'       => '20',
			'replacement' => '6.3',
			'additional'  => '7.3',
			'people'      => '21',
			'people_cols' => array( 'first' => 0, 'last' => 1, 'title' => 2 ),
			'total'       => '16',
		),
		'onsite_change' => array(
			'company'     => '9',
			'booth'       => '69',
			'email'       => '3',
			'first_name'  => '2.3',
			'last_name'   => '2.6',
			'conference'  => '55',
			'replacement' => '62.3',
			'additional'  => '67.3',
			'people'      => '63',
			'people_cols' => array( 'name' => 0, 'title' => 1 ),
		),
	);

	/** Form titles recognised on install (exact, case-insensitive). */
	private const TITLES = array(
		'extra representative order form'      => 'extra_rep',
		'necjca extra representative order form' => 'extra_rep',
		'name badge order form'                => 'name_badges',
		'onsite badges'                        => 'onsite',
		'onsite name badge change form'        => 'onsite_change',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'zpvp_badge_orders';
	}

	public function register(): void {
		add_action( 'gform_after_submission', array( $this, 'on_submission' ), 20, 2 );
		add_action( 'gform_post_payment_completed', array( $this, 'on_payment' ), 20, 1 );
		add_action( 'gform_post_payment_refunded', array( $this, 'on_refund' ), 20, 1 );
		add_filter( 'gform_pre_render', array( $this, 'prefill' ) );
		add_filter( 'gform_form_tag', array( $this, 'registration_picker' ), 10, 2 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 30 );
			add_action( 'admin_post_zpvp_badge_order', array( $this, 'handle_admin' ) );
			add_action( 'admin_post_zpvp_badge_forms', array( $this, 'save_forms' ) );
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(20) NOT NULL DEFAULT '',
			gf_form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			gf_entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
			registration_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			company_name varchar(255) NOT NULL DEFAULT '',
			contact_email varchar(190) NOT NULL DEFAULT '',
			conference_text varchar(255) NOT NULL DEFAULT '',
			booth_number varchar(50) NOT NULL DEFAULT '',
			replacement_qty smallint(5) unsigned NOT NULL DEFAULT 0,
			additional_qty smallint(5) unsigned NOT NULL DEFAULT 0,
			people longtext NULL,
			amount decimal(10,2) NOT NULL DEFAULT 0,
			payment_status varchar(20) NOT NULL DEFAULT 'pending',
			status varchar(20) NOT NULL DEFAULT 'unmatched',
			applied tinyint(1) NOT NULL DEFAULT 0,
			note varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY gf_entry_id (gf_entry_id),
			KEY registration_id (registration_id),
			KEY status (status)
			) {$charset};"
		);
		self::detect_forms();
	}

	/* ------------------------------------------------------------------ */
	/* Which forms are badge forms                                         */
	/* ------------------------------------------------------------------ */

	/** @return array<int, string> form ID => kind */
	public static function forms(): array {
		$forms = get_option( self::OPT_FORMS, array() );
		return (array) apply_filters( 'zpvp_badge_forms', is_array( $forms ) ? array_map( 'strval', $forms ) : array() );
	}

	public static function kind( int $form_id ): string {
		$kind = self::forms()[ $form_id ] ?? '';
		return isset( self::KINDS[ $kind ] ) ? $kind : '';
	}

	public static function map( int $form_id ): array {
		$kind = self::kind( $form_id );
		return $kind ? (array) apply_filters( 'zpvp_badge_form_map', self::LAYOUTS[ $kind ], $form_id, $kind ) : array();
	}

	/** Recognise the badge forms by title. Runs on install; never overrides a choice staff made. */
	public static function detect_forms(): void {
		if ( ! class_exists( '\GFAPI' ) || false !== get_option( self::OPT_FORMS, false ) ) {
			return;
		}
		$found = array();
		foreach ( \GFAPI::get_forms( null, false ) as $form ) {
			$title = strtolower( trim( preg_replace( '/\s*\(\d+\)$/', '', (string) $form['title'] ) ) );
			if ( isset( self::TITLES[ $title ] ) ) {
				$found[ (int) $form['id'] ] = self::TITLES[ $title ];
			}
		}
		update_option( self::OPT_FORMS, $found, false );
	}

	/** Price of one additional name badge (the badge forms charge $100). */
	public static function price(): float {
		return (float) get_option( self::OPT_PRICE, 100 );
	}

	/** The published page that shows a form (shortcode or block). */
	public static function form_page_url( int $form_id ): string {
		global $wpdb;
		if ( ! $form_id ) {
			return '';
		}
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
		return $page ? (string) get_permalink( $page ) : '';
	}

	/** Where vendors go to pay for extra badges: the Name Badge Order Form page, for this registration. */
	public static function order_url( int $registration_id ): string {
		$form = (int) array_search( 'name_badges', self::forms(), true );
		$url  = self::form_page_url( $form );
		return $url ? add_query_arg( 'registration', $registration_id, $url ) : '';
	}

	/* ------------------------------------------------------------------ */
	/* Front end                                                           */
	/* ------------------------------------------------------------------ */

	/** Registrations a vendor can order badges for: active, conference not over (one week grace). */
	public static function open_registrations( int $user_id ): array {
		$cutoff = wp_date( 'Ymd', current_time( 'timestamp', true ) - 7 * DAY_IN_SECONDS );
		return array_values(
			array_filter(
				Companies::registrations_for_user( $user_id ),
				static function ( $r ) use ( $cutoff ) {
					if ( 'sponsorship' === $r->type ) {
						return false;
					}
					$end = $r->edition_id ? (string) get_post_meta( (int) $r->edition_id, 'end_date', true ) : '';
					return $end ? $end >= $cutoff : (int) $r->year >= (int) current_time( 'Y' );
				}
			)
		);
	}

	/** A "Which registration is this for?" list right after the form's opening tag. */
	public function registration_picker( $form_tag, $form ) {
		$form_id = (int) ( $form['id'] ?? 0 );
		$user    = wp_get_current_user();
		if ( is_admin() || ! self::kind( $form_id ) || ! $user->exists() || ! Roles::is_vendor( $user ) ) {
			return $form_tag;
		}
		$regs = self::open_registrations( (int) $user->ID );
		if ( ! $regs ) {
			return $form_tag;
		}
		$chosen = absint( $_REQUEST['zpvp_registration'] ?? ( $_GET['registration'] ?? 0 ) );
		if ( ! $chosen && 1 === count( $regs ) ) {
			$chosen = (int) $regs[0]->id;
		}
		$html  = '<div class="zpvp zpvp-badge-picker"><label for="zpvp-registration-' . $form_id . '">' . esc_html__( 'Which registration are these badges for?', 'zp-vendor-portal' ) . '</label>';
		$html .= '<select id="zpvp-registration-' . $form_id . '" name="zpvp_registration">';
		$html .= '<option value="">' . esc_html__( 'Choose your conference…', 'zp-vendor-portal' ) . '</option>';
		foreach ( $regs as $r ) {
			$label = Registrations::conference_label( $r ) . ( $r->booth_number ? ' – ' . sprintf( __( 'booth %s', 'zp-vendor-portal' ), $r->booth_number ) : '' );
			$html .= '<option value="' . (int) $r->id . '"' . selected( $chosen, (int) $r->id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select><p class="zpvp-help">' . esc_html__( 'The people you list are added to your booth staff and their badges are printed by ZacPubs.', 'zp-vendor-portal' ) . '</p></div>';
		return $form_tag . $html;
	}

	/** Prefill company, email, name and booth number for logged-in vendors. */
	public function prefill( $form ) {
		$form_id = (int) ( $form['id'] ?? 0 );
		$user    = wp_get_current_user();
		if ( ! self::kind( $form_id ) || ! $user->exists() || ! Roles::is_vendor( $user ) ) {
			return $form;
		}
		$map    = self::map( $form_id );
		$values = array();
		foreach ( array( 'company' => Companies::company_name_for_user( (int) $user->ID ), 'email' => $user->user_email, 'first_name' => $user->first_name, 'last_name' => $user->last_name ) as $key => $value ) {
			if ( ! empty( $map[ $key ] ) && '' !== (string) $value ) {
				$values[ (string) $map[ $key ] ] = (string) $value;
			}
		}
		$regs = self::open_registrations( (int) $user->ID );
		if ( ! empty( $map['booth'] ) && 1 === count( $regs ) && $regs[0]->booth_number ) {
			$values[ (string) $map['booth'] ] = (string) $regs[0]->booth_number;
		}
		foreach ( $form['fields'] as $field ) {
			if ( isset( $values[ (string) $field->id ] ) && '' === (string) $field->defaultValue ) {
				$field->defaultValue = $values[ (string) $field->id ];
			}
			if ( is_array( $field->inputs ) ) {
				$inputs = $field->inputs;
				foreach ( $inputs as $i => $input ) {
					if ( isset( $values[ (string) $input['id'] ] ) && empty( $input['defaultValue'] ) ) {
						$inputs[ $i ]['defaultValue'] = $values[ (string) $input['id'] ];
					}
				}
				$field->inputs = $inputs;
			}
		}
		return $form;
	}

	/* ------------------------------------------------------------------ */
	/* Recording orders                                                    */
	/* ------------------------------------------------------------------ */

	public function on_submission( $entry, $form ): void {
		$form_id = (int) ( $form['id'] ?? 0 );
		if ( ! self::kind( $form_id ) || 'spam' === ( $entry['status'] ?? '' ) ) {
			return;
		}
		$chosen = absint( $_POST['zpvp_registration'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- Gravity Forms verified the submission.
		self::record( $entry, $form, $chosen, (int) get_current_user_id() );
	}

	/** Record (or re-read) an entry as a badge order and link it when possible. Returns the order ID. */
	public static function record( array $entry, array $form, int $chosen_registration = 0, int $user_id = 0 ): int {
		global $wpdb;
		$form_id = (int) $form['id'];
		$kind    = self::kind( $form_id );
		$map     = self::map( $form_id );
		$entry_id = (int) $entry['id'];

		$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE gf_entry_id = %d', $entry_id ) );
		if ( $existing ) {
			return $existing;
		}

		$people      = self::people( $entry, $map );
		$additional  = self::qty( $entry, $map['additional'] ?? '' );
		$replacement = self::qty( $entry, $map['replacement'] ?? '' );
		$amount      = self::amount( $entry, $form, $map );
		$status      = self::payment_status( $entry, $amount );
		$now         = current_time( 'mysql' );

		$wpdb->insert(
			self::table(),
			array(
				'kind'            => $kind,
				'gf_form_id'      => $form_id,
				'gf_entry_id'     => $entry_id,
				'user_id'         => $user_id,
				'company_name'    => sanitize_text_field( (string) rgar( $entry, $map['company'] ?? '' ) ),
				'contact_email'   => sanitize_email( (string) rgar( $entry, $map['email'] ?? '' ) ),
				'conference_text' => sanitize_text_field( html_entity_decode( explode( '|', (string) rgar( $entry, $map['conference'] ?? '' ) )[0], ENT_QUOTES ) ),
				'booth_number'    => sanitize_text_field( (string) rgar( $entry, $map['booth'] ?? '' ) ),
				'replacement_qty' => $replacement,
				'additional_qty'  => $additional,
				'people'          => wp_json_encode( $people ),
				'amount'          => $amount,
				'payment_status'  => $status,
				'status'          => 'unmatched',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		$order_id = (int) $wpdb->insert_id;
		if ( ! $order_id ) {
			return 0;
		}

		$order = self::get( $order_id );
		$reg   = self::match( $order, $chosen_registration );
		if ( $reg ) {
			self::link( $order_id, (int) $reg->id, 'Linked automatically.' );
		}
		return $order_id;
	}

	/**
	 * People listed on the order: the list field, plus the named
	 * representatives on the Name Badge Order Form.
	 *
	 * @return array<int, array{name:string, title:string}>
	 */
	private static function people( array $entry, array $map ): array {
		$out = array();
		foreach ( (array) ( $map['reps'] ?? array() ) as $pair ) {
			$name = trim( sanitize_text_field( rgar( $entry, (string) $pair[0] ) . ' ' . rgar( $entry, (string) $pair[1] ) ) );
			if ( '' !== $name ) {
				$out[] = array( 'name' => $name, 'title' => '' );
			}
		}
		$rows = maybe_unserialize( (string) rgar( $entry, (string) ( $map['people'] ?? '' ) ) );
		$cols = (array) ( $map['people_cols'] ?? array( 'name' => 0, 'title' => 1 ) );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$v = is_array( $row ) ? array_values( $row ) : array( $row );
			if ( isset( $cols['first'] ) ) {
				$name = trim( ( $v[ $cols['first'] ] ?? '' ) . ' ' . ( $v[ $cols['last'] ] ?? '' ) );
			} else {
				$name = (string) ( $v[ $cols['name'] ] ?? '' );
			}
			$name = sanitize_text_field( $name );
			if ( '' !== $name ) {
				$out[] = array( 'name' => $name, 'title' => sanitize_text_field( (string) ( $v[ $cols['title'] ?? 1 ] ?? '' ) ) );
			}
		}
		return $out;
	}

	private static function qty( array $entry, string $input ): int {
		if ( '' === $input ) {
			return 0;
		}
		$v = rgar( $entry, $input );
		return is_numeric( $v ) ? max( 0, (int) $v ) : 0;
	}

	private static function amount( array $entry, array $form, array $map ): float {
		if ( ! empty( $map['total'] ) && is_numeric( \GFCommon::to_number( (string) rgar( $entry, (string) $map['total'] ) ) ) ) {
			return round( (float) \GFCommon::to_number( (string) rgar( $entry, (string) $map['total'] ) ), 2 );
		}
		$total    = 0.0;
		$products = \GFCommon::get_product_fields( $form, $entry );
		foreach ( (array) ( $products['products'] ?? array() ) as $p ) {
			$qty    = '' === (string) ( $p['quantity'] ?? '' ) ? 1.0 : (float) \GFCommon::to_number( (string) $p['quantity'] );
			$total += (float) \GFCommon::to_number( (string) ( $p['price'] ?? 0 ) ) * $qty;
		}
		return round( $total, 2 );
	}

	private static function payment_status( array $entry, float $amount ): string {
		if ( $amount <= 0 ) {
			return 'no_charge';
		}
		$status = strtolower( (string) ( $entry['payment_status'] ?? '' ) );
		if ( in_array( $status, array( 'paid', 'approved', 'active' ), true ) ) {
			return 'paid';
		}
		return 'failed' === $status ? 'failed' : 'pending';
	}

	/**
	 * The registration an order belongs to, only when there is one clear answer:
	 * the registration the vendor picked (if it is theirs), otherwise the
	 * vendor's (or same-email / same-company) registrations for current
	 * conferences, narrowed by booth number and conference wording.
	 */
	public static function match( object $order, int $chosen = 0 ): ?object {
		$user_id = (int) $order->user_id;
		if ( $chosen ) {
			$reg = Registrations::get( $chosen );
			if ( $reg && 'active' === $reg->status && ( user_can( $user_id, Roles::CAP_MANAGE ) || Companies::user_can_access( $user_id, $reg ) ) ) {
				return $reg;
			}
		}

		$candidates = array();
		if ( $user_id && ! user_can( $user_id, Roles::CAP_MANAGE ) ) {
			$candidates = self::open_registrations( $user_id );
		}
		if ( ! $candidates ) {
			$candidates = self::current_registrations();
			$email      = strtolower( (string) $order->contact_email );
			$company    = Companies::normalize( (string) $order->company_name );
			$candidates = array_values(
				array_filter(
					$candidates,
					static function ( $r ) use ( $email, $company ) {
						if ( $email && $email === strtolower( (string) $r->contact_email ) ) {
							return true;
						}
						if ( $email && $r->user_id ) {
							$u = get_userdata( (int) $r->user_id );
							if ( $u && strtolower( $u->user_email ) === $email ) {
								return true;
							}
						}
						return '' !== $company && Companies::normalize( (string) $r->company_name ) === $company;
					}
				)
			);
		}
		if ( count( $candidates ) > 1 && '' !== (string) $order->booth_number ) {
			$booth  = strtolower( trim( (string) $order->booth_number ) );
			$narrow = array_values( array_filter( $candidates, static fn( $r ) => '' !== (string) $r->booth_number && in_array( $booth, array_map( 'trim', explode( ',', strtolower( (string) $r->booth_number ) ) ), true ) ) );
			$candidates = $narrow ? $narrow : $candidates;
		}
		if ( count( $candidates ) > 1 && '' !== (string) $order->conference_text ) {
			$best   = array();
			$scored = 0;
			foreach ( $candidates as $r ) {
				$score = self::similarity( (string) $order->conference_text, Registrations::conference_label( $r ) );
				if ( $score > $scored ) {
					$scored = $score;
					$best   = array( $r );
				} elseif ( $score === $scored && $score > 0 ) {
					$best[] = $r;
				}
			}
			$candidates = $best ? $best : $candidates;
		}
		if ( count( $candidates ) > 1 ) {
			// Several conferences still possible: the one happening now or next.
			$today  = current_time( 'Ymd' );
			$now    = array_values(
				array_filter(
					$candidates,
					static function ( $r ) use ( $today ) {
						$s = $r->edition_id ? (string) get_post_meta( (int) $r->edition_id, 'start_date', true ) : '';
						$e = $r->edition_id ? (string) get_post_meta( (int) $r->edition_id, 'end_date', true ) : '';
						return $s && $s <= $today && ( ! $e || $e >= $today );
					}
				)
			);
			$candidates = 1 === count( $now ) ? $now : $candidates;
		}
		return 1 === count( $candidates ) ? $candidates[0] : null;
	}

	/** Words shared by two conference names (ignoring short and common words). */
	private static function similarity( string $a, string $b ): int {
		$words = static function ( string $s ): array {
			$s     = strtolower( html_entity_decode( $s, ENT_QUOTES ) );
			$s     = str_replace( array( '&', 'cjc' ), array( ' ', 'county judges commissioners' ), $s );
			$parts = preg_split( '/[^a-z0-9]+/', $s );
			$stop  = array( 'the', 'and', 'of', 'for', 'in', 'conf', 'conference', 'association', 'texas', 'annual', 'school', '2024', '2025', '2026', '2027', '2028' );
			return array_unique( array_filter( $parts, static fn( $w ) => strlen( $w ) > 2 && ! in_array( $w, $stop, true ) ) );
		};
		return count( array_intersect( $words( $a ), $words( $b ) ) );
	}

	/** Active, non-sponsorship registrations for conferences that haven't ended (one week grace). */
	private static function current_registrations(): array {
		global $wpdb;
		$cutoff = wp_date( 'Ymd', current_time( 'timestamp', true ) - 7 * DAY_IN_SECONDS );
		$rows   = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . Registrations::table() . " WHERE status = 'active' AND type <> 'sponsorship' AND year >= %d", (int) current_time( 'Y' ) - 1 )
		);
		return array_values(
			array_filter(
				$rows,
				static function ( $r ) use ( $cutoff ) {
					$end = $r->edition_id ? (string) get_post_meta( (int) $r->edition_id, 'end_date', true ) : '';
					return $end ? $end >= $cutoff : (int) $r->year >= (int) current_time( 'Y' );
				}
			)
		);
	}

	/**
	 * Link an order to a registration and apply it: the people become booth
	 * personnel and paid or replacement badges are added to the registration's
	 * purchased badges. Applying happens once; relinking moves nothing already applied.
	 */
	public static function link( int $order_id, int $registration_id, string $note = '' ): bool {
		global $wpdb;
		$order = self::get( $order_id );
		$reg   = Registrations::get( $registration_id );
		if ( ! $order || ! $reg || (int) $order->applied ) {
			return false;
		}
		$wpdb->update(
			self::table(),
			array( 'registration_id' => $registration_id, 'status' => 'linked', 'note' => mb_substr( $note, 0, 255 ), 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $order_id )
		);
		if ( 'failed' !== $order->payment_status ) {
			self::apply( self::get( $order_id ), $reg );
		}
		return true;
	}

	private static function apply( object $order, object $reg ): void {
		global $wpdb;
		$source = in_array( $order->kind, array( 'onsite', 'onsite_change' ), true ) ? 'onsite' : 'badge_form';
		$people = json_decode( (string) $order->people, true );
		$have   = array_map( static fn( $a ) => strtolower( trim( $a->name ) ), Attendees::for_registration( (int) $reg->id ) );

		// Purchased (and free replacement) badges first, so the new people aren't flagged as unpaid.
		$covered = (int) $order->additional_qty + (int) $order->replacement_qty;
		if ( $covered > 0 && (int) $reg->included_badges > 0 ) {
			Registrations::update( (int) $reg->id, array( 'paid_badges' => (int) $reg->paid_badges + $covered ) );
		}
		foreach ( is_array( $people ) ? $people : array() as $p ) {
			$name = (string) ( $p['name'] ?? '' );
			if ( '' === $name || in_array( strtolower( trim( $name ) ), $have, true ) ) {
				continue; // Already listed.
			}
			Attendees::add( (int) $reg->id, array( 'name' => $name, 'title' => (string) ( $p['title'] ?? '' ) ), $source );
			$have[] = strtolower( trim( $name ) );
		}
		Badges::sync_registration( (int) $reg->id );

		$note = trim( (string) $reg->notes . "\n" . sprintf( '%s %s (entry %d): %d additional, %d replacement badge(s), %d people.', self::KINDS[ $order->kind ] ?? 'Badge order', mysql2date( 'M j, Y', $order->created_at ), (int) $order->gf_entry_id, (int) $order->additional_qty, (int) $order->replacement_qty, is_array( $people ) ? count( $people ) : 0 ) );
		Registrations::update( (int) $reg->id, array( 'notes' => $note ) );
		$wpdb->update( self::table(), array( 'applied' => 1, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $order->id ) );
		do_action( 'zpvp_badge_order_applied', (int) $order->id, (int) $reg->id );
	}

	public function on_payment( $entry ): void {
		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE gf_entry_id = %d', (int) $entry['id'] ) );
		if ( ! $order ) {
			return;
		}
		$wpdb->update( self::table(), array( 'payment_status' => 'paid', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $order->id ) );
		if ( 'linked' === $order->status && ! (int) $order->applied && $order->registration_id ) {
			$reg = Registrations::get( (int) $order->registration_id );
			if ( $reg ) {
				self::apply( self::get( (int) $order->id ), $reg );
			}
		}
	}

	public function on_refund( $entry ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'payment_status' => 'refunded', 'updated_at' => current_time( 'mysql' ) ), array( 'gf_entry_id' => (int) $entry['id'] ) );
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
		return $row ? $row : null;
	}

	public static function unmatched_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'unmatched'" );
	}

	/** @return object[] */
	public static function for_registration( int $registration_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE registration_id = %d ORDER BY id DESC', $registration_id ) );
	}

	/* ------------------------------------------------------------------ */
	/* Staff screen: Vendor Portal → Badge orders                          */
	/* ------------------------------------------------------------------ */

	public function menu(): void {
		$count = self::unmatched_count();
		$label = 'Badge orders' . ( $count ? ' <span class="awaiting-mod">' . (int) $count . '</span>' : '' );
		add_submenu_page( 'zpvp', 'Badge orders', $label, Roles::CAP_MANAGE, self::PAGE, array( $this, 'render' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	public function render(): void {
		global $wpdb;
		$view   = sanitize_key( $_GET['view'] ?? 'unmatched' );
		$where  = 'unmatched' === $view ? "WHERE status = 'unmatched'" : ( 'ignored' === $view ? "WHERE status = 'ignored'" : '' );
		$orders = (array) $wpdb->get_results( 'SELECT * FROM ' . self::table() . " {$where} ORDER BY id DESC LIMIT 300" ); // phpcs:ignore -- fixed strings.
		$regs   = self::current_registrations();
		usort( $regs, static fn( $a, $b ) => strcasecmp( Registrations::conference_label( $a ) . $a->company_name, Registrations::conference_label( $b ) . $b->company_name ) );
		$forms  = self::forms();
		?>
		<div class="wrap">
			<h1>Badge orders</h1>
			<p>Submissions from the badge forms (extra representatives, name badge orders and on-site badges). Each one is linked to the vendor's registration: the people become booth staff, so their badges go to the print queue, and the badges they paid for are added to the registration.</p>
			<?php if ( isset( $_GET['zpvp_msg'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['zpvp_msg'] ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $forms ) : ?>
				<div class="notice notice-warning"><p>No badge forms are connected yet. Choose them below.</p></div>
			<?php endif; ?>

			<ul class="subsubsub">
				<?php foreach ( array( 'unmatched' => 'Needs a registration (' . self::unmatched_count() . ')', 'all' => 'All', 'ignored' => 'Ignored' ) as $k => $label ) : ?>
					<li><a href="<?php echo esc_url( self::url( array( 'view' => $k ) ) ); ?>" class="<?php echo $view === $k ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a> |</li>
				<?php endforeach; ?>
			</ul>
			<table class="widefat striped" style="clear:both">
				<thead><tr><th>Received</th><th>Form</th><th>Company / email</th><th>Conference / booth</th><th>Badges</th><th>People</th><th>Payment</th><th>Registration</th></tr></thead>
				<tbody>
				<?php if ( ! $orders ) : ?>
					<tr><td colspan="8"><?php echo 'unmatched' === $view ? 'Every badge order is linked to a registration.' : 'No badge orders yet.'; ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $orders as $o ) : ?>
					<?php $people = json_decode( (string) $o->people, true ); ?>
					<tr>
						<td><?php echo esc_html( mysql2date( 'M j, Y g:i a', $o->created_at ) ); ?><br><a href="<?php echo esc_url( admin_url( 'admin.php?page=gf_entries&view=entry&id=' . (int) $o->gf_form_id . '&lid=' . (int) $o->gf_entry_id ) ); ?>">Entry <?php echo (int) $o->gf_entry_id; ?></a></td>
						<td><?php echo esc_html( self::KINDS[ $o->kind ] ?? $o->kind ); ?></td>
						<td><strong><?php echo esc_html( $o->company_name ); ?></strong><br><?php echo esc_html( $o->contact_email ); ?></td>
						<td><?php echo esc_html( $o->conference_text ); ?><?php echo $o->booth_number ? '<br>Booth ' . esc_html( $o->booth_number ) : ''; ?></td>
						<td><?php echo esc_html( sprintf( '%d additional, %d replacement', (int) $o->additional_qty, (int) $o->replacement_qty ) ); ?></td>
						<td><?php echo esc_html( implode( ', ', array_map( static fn( $p ) => $p['name'] . ( $p['title'] ? ' (' . $p['title'] . ')' : '' ), is_array( $people ) ? $people : array() ) ) ); ?></td>
						<td><?php echo esc_html( Registrations::payment_label( $o->payment_status ) . ( (float) $o->amount > 0 ? ' $' . number_format( (float) $o->amount, 2 ) : '' ) ); ?></td>
						<td>
							<?php if ( 'linked' === $o->status && $o->registration_id && ( $r = Registrations::get( (int) $o->registration_id ) ) ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=zpvp-registration&id=' . (int) $r->id ) ); ?>"><?php echo esc_html( $r->company_name . ' – ' . Registrations::conference_label( $r ) ); ?></a>
								<?php if ( ! (int) $o->applied ) : ?><br><em>Waiting for payment</em><?php endif; ?>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="zpvp_badge_order">
									<input type="hidden" name="order" value="<?php echo (int) $o->id; ?>">
									<?php wp_nonce_field( 'zpvp_badge_order_' . (int) $o->id ); ?>
									<select name="registration" style="max-width:280px">
										<option value="">Choose a registration…</option>
										<?php foreach ( $regs as $r ) : ?>
											<option value="<?php echo (int) $r->id; ?>"><?php echo esc_html( Registrations::conference_label( $r ) . ': ' . $r->company_name . ( $r->booth_number ? ' (booth ' . $r->booth_number . ')' : '' ) ); ?></option>
										<?php endforeach; ?>
									</select>
									<button class="button button-primary" name="do" value="link">Link</button>
									<?php if ( 'ignored' !== $o->status ) : ?><button class="button" name="do" value="ignore">Ignore</button><?php endif; ?>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:2em">Badge forms</h2>
			<p>Which Gravity Forms are badge forms. They were recognised by their titles; change them here if a form is replaced.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zpvp_badge_forms">
				<?php wp_nonce_field( 'zpvp_badge_forms' ); ?>
				<table class="widefat striped" style="max-width:760px">
					<thead><tr><th>Form</th><th>Badge form type</th></tr></thead>
					<tbody>
					<?php foreach ( class_exists( '\GFAPI' ) ? \GFAPI::get_forms( null, false, 'title', 'ASC' ) : array() as $f ) : ?>
						<?php
						$fid  = (int) $f['id'];
						$kind = $forms[ $fid ] ?? '';
						if ( ! $kind && ! preg_match( '/badge|representative|\brep\b/i', (string) $f['title'] ) ) {
							continue;
						}
						?>
						<tr>
							<td><?php echo esc_html( $f['title'] . ' (#' . $fid . ')' . ( empty( $f['is_active'] ) ? ' – inactive' : '' ) ); ?></td>
							<td><select name="forms[<?php echo (int) $fid; ?>]">
								<option value="">Not a badge form</option>
								<?php foreach ( self::KINDS as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $kind, $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><label>Price of one additional badge $<input type="number" name="price" min="0" step="0.01" value="<?php echo esc_attr( (string) self::price() ); ?>" style="width:90px"></label>
				<span class="description">Used on the invoice for extra badges that haven't been paid for.</span></p>
				<?php submit_button( 'Save badge forms', 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_admin(): void {
		$id = absint( $_POST['order'] ?? 0 );
		if ( ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_badge_order_' . $id );
		global $wpdb;
		$msg = '';
		if ( 'ignore' === sanitize_key( $_POST['do'] ?? '' ) ) {
			$wpdb->update( self::table(), array( 'status' => 'ignored', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
			$msg = 'Badge order ignored.';
		} else {
			$rid = absint( $_POST['registration'] ?? 0 );
			$msg = ( $rid && self::link( $id, $rid, sprintf( 'Linked by %s.', wp_get_current_user()->display_name ) ) ) ? 'Badge order linked. Its people were added to the booth staff and badge queue.' : 'Choose a registration to link the order to.';
		}
		wp_safe_redirect( self::url( array( 'zpvp_msg' => rawurlencode( $msg ) ) ) );
		exit;
	}

	public function save_forms(): void {
		if ( ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_badge_forms' );
		$forms = array();
		foreach ( (array) ( $_POST['forms'] ?? array() ) as $fid => $kind ) {
			$kind = sanitize_key( $kind );
			if ( isset( self::KINDS[ $kind ] ) ) {
				$forms[ absint( $fid ) ] = $kind;
			}
		}
		update_option( self::OPT_FORMS, $forms, false );
		update_option( self::OPT_PRICE, max( 0, (float) ( $_POST['price'] ?? 100 ) ), false );
		wp_safe_redirect( self::url( array( 'view' => 'all', 'zpvp_msg' => rawurlencode( 'Badge forms saved.' ) ) ) );
		exit;
	}
}
