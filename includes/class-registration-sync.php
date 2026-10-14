<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Connects the existing Gravity Forms registration forms to the portal:
 * records registrations on submission, tracks card payments, counts booths,
 * asks vendors to log in before registering and prefills their details.
 */
final class Registration_Sync {

	/**
	 * Set while importing past entries (Historical): the vendor, conference and
	 * year to record, and no "entry processed" action (so no emails are sent).
	 *
	 * @var array{user_id?:int, edition?:int, year?:int}|null
	 */
	public static ?array $import = null;

	public function register(): void {
		add_action( 'gform_after_submission', array( $this, 'on_submission' ), 20, 2 );
		add_action( 'gform_post_payment_completed', array( $this, 'on_payment_completed' ), 10, 2 );
		add_action( 'gform_post_payment_refunded', array( $this, 'on_payment_refunded' ), 10, 2 );
		add_filter( 'zpvp_edition_booths_taken', array( $this, 'booths_taken' ), 10, 2 );
		add_filter( 'gform_get_form_filter', array( $this, 'require_login' ), 10, 2 );
		add_filter( 'gform_pre_render', array( $this, 'prefill' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Recording registrations                                             */
	/* ------------------------------------------------------------------ */

	public function on_submission( $entry, $form ): void {
		$form_id = (int) ( $form['id'] ?? 0 );
		if ( ! $form_id || 'spam' === ( $entry['status'] ?? '' ) ) {
			return;
		}

		if ( $form_id === Form_Map::annual_form_id() ) {
			if ( null === self::$import ) {
				$this->record_annual( $entry, $form );
				do_action( 'zpvp_entry_processed', (int) $entry['id'] );
			}
			return;
		}

		// The conference that chose this form (newest published one if several).
		// The series that owns this form, and its newest published conference (0 if none yet).
		$edition = isset( self::$import['edition'] ) ? (int) self::$import['edition'] : Conferences::for_form( $form_id );
		$series  = $edition ? (int) get_post_meta( $edition, 'series', true ) : Conferences::series_for_form( $form_id );
		if ( ! $series || Form_Map::is_sponsorship_form( $form_id ) ) {
			if ( Conferences::series_for_sponsorship_form( $form_id ) ) {
				$this->record_sponsorship( $entry, $form, isset( self::$import['edition'] ) ? (int) self::$import['edition'] : Conferences::for_sponsorship_form( $form_id ) );
				if ( null === self::$import ) {
					do_action( 'zpvp_entry_processed', (int) $entry['id'] );
				}
			}
			return;
		}
		$map    = Form_Map::for_form( $form_id );
		$year   = ! empty( self::$import['year'] ) ? (int) self::$import['year'] : (int) get_post_meta( $edition, 'year', true );
		if ( ! $year ) {
			$year = (int) substr( (string) $entry['date_created'], 0, 4 );
		}

		$registration_id = Registrations::create(
			$this->common( $entry, $map ) + array(
				'edition_id' => $edition,
				'series_id'  => $series,
				'year'       => $year,
				'type'       => 'individual',
				'booths'          => $this->booth_count( $entry, $map ),
				'included_badges' => $this->int_value( $entry, $map['included_badges'] ?? '' ),
				'paid_badges'     => $this->int_value( $entry, isset( $map['badge_product'] ) ? $map['badge_product'] . '.3' : '' ),
				'amount'          => $this->money( rgar( $entry, $map['total'] ) ),
			)
		);

		if ( $registration_id && ! empty( $map['personnel'] ) ) {
			Attendees::import_from_entry( $registration_id, $entry, (string) $map['personnel'] );
		}
		if ( null === self::$import ) {
			do_action( 'zpvp_entry_processed', (int) $entry['id'] );
		}
	}

	/**
	 * The annual form lists every conference as a booth dropdown. One
	 * registration is recorded per conference the vendor selected.
	 */
	private function record_annual( array $entry, array $form ): void {
		$map    = Form_Map::for_form( (int) $form['id'] );
		// The season the annual form is taking bookings for (from the conferences, not the form title).
		$year   = Annual_Form::season();
		$common = $this->common( $entry, $map );

		// The one-time pre-registration fee (the form's fixed-price products, not
		// the booth dropdowns) gets its own record so staff can track its payment.
		// Every conference booking from this entry links to it through annual_id.
		$fee       = $this->annual_fee( $entry, $form );
		$annual_id = Annual_Registrations::record(
			array(
				'year'           => $year,
				'gf_form_id'     => (int) $entry['form_id'],
				'gf_entry_id'    => (int) $entry['id'],
				'amount'         => $fee,
				'payment_status' => $fee > 0 ? $common['payment_status'] : 'no_charge',
			)
		);

		foreach ( Conferences::annual_fields() as $field_id ) {
			$value = (string) rgar( $entry, (string) $field_id );
			if ( '' === $value ) {
				continue;
			}
			[ $label, $price ] = array_pad( explode( '|', $value, 2 ), 2, '0' );

			// Unselected dropdowns are stored as "Select One|0".
			if ( preg_match( '/^\s*select/i', $label ) || $this->money( $price ) <= 0 ) {
				continue;
			}

			// The conference that chose this dropdown for the annual form's year.
			// If that year's conference doesn't exist yet, keep the booking under
			// the event's name (edition 0) so staff can see it and nothing is lost.
			$edition = Conferences::for_annual_field( (string) $field_id, $year );
			$group   = $edition ? $edition : Conferences::for_annual_field( (string) $field_id );
			$series  = $group ? (int) get_post_meta( $group, 'series', true ) : 0;
			if ( ! $series ) {
				continue;
			}

			Registrations::create(
				$common + array(
					'edition_id' => $edition,
					'series_id'  => $series,
					'year'       => $year,
					'type'       => 'annual',
					'annual_id'  => $annual_id,
					'booths'     => $this->booths_from_label( $label ),
					'amount'     => $this->money( $price ),
				)
			);
		}
	}

	/**
	 * A sponsorship form: one registration of type "sponsorship" with no booths.
	 * The level comes from the "Sponsorship Selection" choice (found by label,
	 * as its field ID differs between forms); extra tickets and a requested
	 * complimentary booth are written to the notes for staff.
	 */
	private function record_sponsorship( array $entry, array $form, int $edition ): void {
		$map    = Form_Map::for_form( (int) $form['id'] );
		$level  = '';
		$level_field = 0;
		$notes  = array();
		foreach ( $form['fields'] as $field ) {
			$label = strtolower( wp_strip_all_tags( (string) $field->label ) );
			if ( ! $level_field && 'product' === $field->type && in_array( $field->inputType, array( 'radio', 'select' ), true ) && str_contains( $label, 'sponsor' ) ) {
				$level_field = (int) $field->id;
				$level       = trim( explode( '|', (string) rgar( $entry, (string) $field->id ) )[0] );
			}
			if ( 'checkbox' === $field->type && str_contains( $label, 'booth' ) ) {
				foreach ( (array) $field->inputs as $input ) {
					if ( '' !== (string) rgar( $entry, (string) $input['id'] ) ) {
						$notes[] = 'Complimentary booth space: ' . wp_strip_all_tags( (string) $input['label'] );
						break;
					}
				}
			}
		}
		if ( class_exists( '\GFCommon' ) ) {
			$products = \GFCommon::get_product_fields( $form, $entry );
			foreach ( (array) ( $products['products'] ?? array() ) as $field_id => $p ) {
				$qty = (float) \GFCommon::to_number( (string) ( $p['quantity'] ?? 0 ) );
				if ( (int) $field_id !== $level_field && $qty > 0 ) {
					$notes[] = wp_strip_all_tags( (string) $p['name'] ) . ' × ' . rtrim( rtrim( number_format( $qty, 2 ), '0' ), '.' );
				}
			}
		}

		$year = ! empty( self::$import['year'] ) ? (int) self::$import['year'] : (int) get_post_meta( $edition, 'year', true );
		Registrations::create(
			$this->common( $entry, $map ) + array(
				'edition_id' => $edition,
				'series_id'  => $edition ? (int) get_post_meta( $edition, 'series', true ) : Conferences::series_for_sponsorship_form( (int) $form['id'] ),
				'year'       => $year ? $year : (int) substr( (string) $entry['date_created'], 0, 4 ),
				'type'       => 'sponsorship',
				'booths'     => 0,
				'item'       => sanitize_text_field( $level ),
				'amount'     => $this->money( rgar( $entry, $map['total'] ) ),
				'notes'      => implode( "\n", $notes ),
			)
		);
	}

	/**
	 * The annual form's one-time fee: the total of its single, hidden and
	 * calculated products. Booth dropdowns (select/radio products) are the
	 * conference bookings and are recorded separately.
	 */
	private function annual_fee( array $entry, array $form ): float {
		$fixed = array();
		foreach ( $form['fields'] as $field ) {
			if ( 'product' === $field->type && in_array( $field->inputType, array( 'singleproduct', 'hiddenproduct', 'calculation' ), true ) ) {
				$fixed[] = (int) $field->id;
			}
		}
		if ( ! $fixed || ! class_exists( '\GFCommon' ) ) {
			return 0.0;
		}
		$products = \GFCommon::get_product_fields( $form, $entry );
		$total    = 0.0;
		foreach ( (array) ( $products['products'] ?? array() ) as $field_id => $p ) {
			if ( ! in_array( (int) $field_id, $fixed, true ) ) {
				continue;
			}
			$price = $this->money( $p['price'] ?? 0 );
			foreach ( (array) ( $p['options'] ?? array() ) as $option ) {
				$price += (float) ( $option['price'] ?? 0 );
			}
			$qty    = '' === (string) ( $p['quantity'] ?? '' ) ? 1.0 : (float) \GFCommon::to_number( (string) $p['quantity'] );
			$total += $price * $qty;
		}
		return round( $total, 2 );
	}

	/**
	 * What the portal would record from an entry, without saving anything.
	 * Used by the Conference Builder's "Test with last entry".
	 */
	public function preview_entry( array $entry, array $form ): array {
		$form_id = (int) ( $form['id'] ?? 0 );
		$map     = Form_Map::for_form( $form_id );
		$common  = $this->common( $entry, $map );
		$out     = array(
			'entry_id'       => (int) $entry['id'],
			'date'           => mysql2date( 'M j, Y g:i a', (string) $entry['date_created'] ),
			'company'        => $common['company_name'],
			'contact'        => $common['contact_name'],
			'email'          => $common['contact_email'],
			'payment_method' => Registrations::method_label( $common['payment_method'] ),
			'payment_status' => Registrations::payment_label( $common['payment_status'] ),
			'po_number'      => $common['po_number'],
			'total'          => $this->money( rgar( $entry, $map['total'] ?? '' ) ),
			'bookings'       => array(),
			'people'         => array(),
		);

		if ( $form_id === Form_Map::annual_form_id() ) {
			$labels = Conferences::annual_dropdowns();
			foreach ( Conferences::annual_fields() as $field_id ) {
				[ $label, $price ] = array_pad( explode( '|', (string) rgar( $entry, (string) $field_id ), 2 ), 2, '0' );
				if ( '' === $label || preg_match( '/^\s*select/i', $label ) || $this->money( $price ) <= 0 ) {
					continue;
				}
				$out['bookings'][] = array(
					'conference' => $labels[ $field_id ] ?? 'Field ' . $field_id,
					'choice'     => $label,
					'booths'     => $this->booths_from_label( $label ),
					'amount'     => $this->money( $price ),
				);
			}
			return $out;
		}

		$out['booths']          = $this->booth_count( $entry, $map );
		$out['included_badges'] = $this->int_value( $entry, $map['included_badges'] ?? '' );
		$out['paid_badges']     = $this->int_value( $entry, ! empty( $map['badge_product'] ) ? $map['badge_product'] . '.3' : '' );
		if ( ! empty( $map['personnel'] ) ) {
			$rows = maybe_unserialize( (string) rgar( $entry, (string) $map['personnel'] ) );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				// Same reading as Attendees::import_from_entry(): first column name, second title.
				$values = is_array( $row ) ? array_values( $row ) : array( $row );
				$name   = sanitize_text_field( (string) ( $values[0] ?? '' ) );
				$title  = sanitize_text_field( (string) ( $values[1] ?? '' ) );
				if ( '' !== $name ) {
					$out['people'][] = $title ? $name . ', ' . $title : $name;
				}
			}
		}
		return $out;
	}

	/** Fields shared by every registration from one entry. */
	private function common( array $entry, array $map ): array {
		$method = $this->payment_method( $entry, $map );
		return array(
			'user_id'        => $this->vendor_id( $entry, $map ),
			'company_name'   => sanitize_text_field( (string) rgar( $entry, $map['company'] ) ),
			'contact_name'   => trim( sanitize_text_field( rgar( $entry, $map['first_name'] ) . ' ' . rgar( $entry, $map['last_name'] ) ) ),
			'contact_email'  => sanitize_email( (string) rgar( $entry, $map['email'] ) ),
			'payment_method' => $method,
			'po_number'      => sanitize_text_field( (string) rgar( $entry, $map['po_number'] ) ),
			'payment_status' => $this->initial_payment_status( $entry, $map, $method ),
			'gf_form_id'     => (int) $entry['form_id'],
			'gf_entry_id'    => (int) $entry['id'],
		);
	}

	/**
	 * The vendor account the registration belongs to: the logged-in vendor,
	 * or (for staff entering it in WP Admin) the vendor with the entry's email.
	 */
	private function vendor_id( array $entry, array $map ): int {
		if ( isset( self::$import['user_id'] ) ) {
			return (int) self::$import['user_id'];
		}
		$current = wp_get_current_user();
		if ( $current->exists() && Roles::is_vendor( $current ) ) {
			return (int) $current->ID;
		}
		$user = get_user_by( 'email', sanitize_email( (string) rgar( $entry, $map['email'] ) ) );
		return $user && Roles::is_vendor( $user ) ? (int) $user->ID : 0;
	}

	private function payment_method( array $entry, array $map ): string {
		$value = strtolower( (string) rgar( $entry, $map['payment_method'] ) );
		if ( str_contains( $value, 'credit' ) || str_contains( $value, 'card' ) ) {
			return 'card';
		}
		if ( str_contains( $value, 'check' ) ) {
			return 'check';
		}
		if ( str_contains( $value, 'po' ) || str_contains( $value, 'invoice' ) ) {
			return 'po';
		}
		return ! empty( $entry['transaction_id'] ) ? 'card' : '';
	}

	private function initial_payment_status( array $entry, array $map, string $method ): string {
		if ( $this->money( rgar( $entry, $map['total'] ) ) <= 0 ) {
			return 'no_charge';
		}
		if ( 'card' === $method ) {
			$status = strtolower( (string) ( $entry['payment_status'] ?? '' ) );
			if ( in_array( $status, array( 'paid', 'approved', 'active' ), true ) ) {
				return 'paid';
			}
			return 'failed' === $status ? 'failed' : 'pending';
		}
		return 'awaiting';
	}

	private function booth_count( array $entry, array $map ): int {
		$qty = rgar( $entry, $map['booths'] ?? '' );
		if ( is_numeric( $qty ) && (int) $qty > 0 ) {
			return (int) $qty;
		}
		$product_qty = rgar( $entry, ( $map['booth_product'] ?? '' ) . '.3' );
		if ( is_numeric( $product_qty ) && (int) $product_qty > 0 ) {
			return (int) $product_qty;
		}
		return 1;
	}

	private function int_value( array $entry, string $input_id ): int {
		if ( '' === $input_id ) {
			return 0;
		}
		$value = rgar( $entry, $input_id );
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	private function booths_from_label( string $label ): int {
		$label = strtolower( $label );
		foreach ( array( 'quadruple' => 4, 'triple' => 3, 'double' => 2, 'single' => 1 ) as $word => $count ) {
			if ( str_contains( $label, $word ) ) {
				return $count;
			}
		}
		return preg_match( '/(\d+)/', $label, $m ) ? max( 1, (int) $m[1] ) : 1;
	}

	private function money( $value ): float {
		return (float) \GFCommon::to_number( (string) $value );
	}

	/* ------------------------------------------------------------------ */
	/* Payments and counts                                                 */
	/* ------------------------------------------------------------------ */

	public function on_payment_completed( $entry, $action ): void {
		Registrations::set_payment_status_for_entry( (int) $entry['id'], 'paid' );
		Annual_Registrations::set_status_for_entry( (int) $entry['id'], 'paid' );
	}

	public function on_payment_refunded( $entry, $action ): void {
		Registrations::set_payment_status_for_entry( (int) $entry['id'], 'refunded' );
		Annual_Registrations::set_status_for_entry( (int) $entry['id'], 'refunded' );
	}

	public function booths_taken( $count, $edition_id ): int {
		return Registrations::booths_taken( (int) $edition_id );
	}

	/* ------------------------------------------------------------------ */
	/* Front end: login and prefill                                        */
	/* ------------------------------------------------------------------ */

	/** Conference registration forms require a vendor login (staff can always see them). */
	public function require_login( $form_string, $form ) {
		if ( is_admin() || is_user_logged_in() || ! Form_Map::is_linked_form( (int) ( $form['id'] ?? 0 ) ) ) {
			return $form_string;
		}
		if ( ! apply_filters( 'zpvp_require_login_for_registration', true, $form ) ) {
			return $form_string;
		}

		$here  = home_url( add_query_arg( array() ) );
		$login = add_query_arg( 'redirect_to', rawurlencode( $here ), Settings::page_url( 'login' ) );

		return sprintf(
			'<div class="zpvp zpvp-login-required"><p>%s</p><p><a class="zpvp-btn zpvp-btn--primary" href="%s">%s</a> <a class="zpvp-link" href="%s">%s</a></p></div>',
			esc_html__( 'Please log in to your vendor account to register for this conference.', 'zp-vendor-portal' ),
			esc_url( $login ),
			esc_html__( 'Log In', 'zp-vendor-portal' ),
			esc_url( Settings::page_url( 'register' ) ),
			esc_html__( 'Create a vendor account', 'zp-vendor-portal' )
		);
	}

	/** Prefill company, name and email for logged-in vendors. */
	public function prefill( $form ) {
		$user = wp_get_current_user();
		if ( ! $user->exists() || ! Roles::is_vendor( $user ) || ! Form_Map::is_linked_form( (int) ( $form['id'] ?? 0 ) ) ) {
			return $form;
		}
		$map    = Form_Map::for_form( (int) $form['id'] );
		$values = array(
			$map['company']    => Companies::company_name_for_user( (int) $user->ID ),
			$map['email']      => $user->user_email,
			$map['first_name'] => $user->first_name,
			$map['last_name']  => $user->last_name,
		);
		if ( ! empty( $map['phone'] ) ) {
			$values[ $map['phone'] ] = (string) get_user_meta( $user->ID, 'zpvp_phone', true );
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
}
