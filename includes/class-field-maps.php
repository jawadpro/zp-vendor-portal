<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Field connectors: which Gravity Forms field holds each piece of portal data
 * (company, email, total, booth personnel…) for one form.
 *
 * The built-in layouts in Form_Map cover the shared exhibitor, annual and
 * sponsorship templates. Staff can override single connectors per form in the
 * Conference Builder; the overrides are stored in one option and applied
 * through the existing zpvp_form_map filter, so registration sync, prefill and
 * documents all use them with no other changes.
 */
final class Field_Maps {

	public const OPTION = 'zpvp_field_maps';

	/** Portal fields staff can connect, in display order. */
	public const KEYS = array(
		'company'         => array( 'label' => 'Company', 'hint' => 'Company name on registrations, invoices and badges' ),
		'email'           => array( 'label' => 'Contact email', 'hint' => 'Links the registration to the vendor account' ),
		'first_name'      => array( 'label' => 'First name', 'hint' => 'Contact first name' ),
		'last_name'       => array( 'label' => 'Last name', 'hint' => 'Contact last name' ),
		'phone'           => array( 'label' => 'Phone', 'hint' => 'Prefilled for logged-in vendors' ),
		'address'         => array( 'label' => 'Address', 'hint' => 'Shown on invoices' ),
		'payment_method'  => array( 'label' => 'Payment method', 'hint' => 'Card, check or PO' ),
		'po_number'       => array( 'label' => 'PO number', 'hint' => 'Purchase order number' ),
		'total'           => array( 'label' => 'Total', 'hint' => 'Amount due for the registration' ),
		'booths'          => array( 'label' => 'Number of booths', 'hint' => 'Counts toward booth capacity' ),
		'booth_product'   => array( 'label' => 'Booth product', 'hint' => 'Used for the booth count if there is no number field' ),
		'personnel'       => array( 'label' => 'Booth personnel', 'hint' => 'Each row becomes a badge' ),
		'included_badges' => array( 'label' => 'Included badges', 'hint' => 'Badges included with the booth' ),
		'badge_product'   => array( 'label' => 'Extra badges product', 'hint' => 'Paid badges beyond those included' ),
	);

	/** Connectors a form cannot work without, by kind. */
	public const REQUIRED = array(
		'standard'    => array( 'company', 'email', 'payment_method', 'total' ),
		'annual'      => array( 'company', 'email', 'payment_method', 'total' ),
		'sponsorship' => array( 'company', 'email', 'total' ),
	);

	public function register(): void {
		add_filter( 'zpvp_form_map', array( __CLASS__, 'apply' ), 5, 2 );
	}

	/** Merge saved overrides into a form's map. An empty value means "not on this form". */
	public static function apply( $map, $form_id ) {
		$saved = self::overrides( (int) $form_id );
		foreach ( $saved as $key => $value ) {
			if ( isset( self::KEYS[ $key ] ) ) {
				$map[ $key ] = (string) $value;
			}
		}
		return $map;
	}

	/** @return array<string,string> */
	public static function overrides( int $form_id ): array {
		$all = (array) get_option( self::OPTION, array() );
		return isset( $all[ $form_id ] ) && is_array( $all[ $form_id ] ) ? array_map( 'strval', $all[ $form_id ] ) : array();
	}

	/** Save the connectors for a form, keeping only the ones that differ from its built-in layout. */
	public static function save( int $form_id, array $values ): void {
		$base = self::base( $form_id );
		$keep = array();
		foreach ( self::KEYS as $key => $info ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$value = preg_replace( '/[^0-9.]/', '', (string) $values[ $key ] );
			if ( $value !== (string) ( $base[ $key ] ?? '' ) ) {
				$keep[ $key ] = $value;
			}
		}
		$all = (array) get_option( self::OPTION, array() );
		if ( $keep ) {
			$all[ $form_id ] = $keep;
		} else {
			unset( $all[ $form_id ] );
		}
		update_option( self::OPTION, $all, false );
	}

	public static function kind( int $form_id ): string {
		if ( $form_id && $form_id === Form_Map::annual_form_id() ) {
			return 'annual';
		}
		return Form_Map::is_sponsorship_form( $form_id ) ? 'sponsorship' : 'standard';
	}

	/** The built-in layout for a form, before overrides. */
	public static function base( int $form_id ): array {
		switch ( self::kind( $form_id ) ) {
			case 'annual':
				return Form_Map::ANNUAL;
			case 'sponsorship':
				return Form_Map::SPONSORSHIP;
		}
		return Form_Map::STANDARD;
	}

	/** Connector keys that apply to a kind of form. */
	public static function keys_for( string $kind ): array {
		$base = 'annual' === $kind ? Form_Map::ANNUAL : ( 'sponsorship' === $kind ? Form_Map::SPONSORSHIP : Form_Map::STANDARD );
		return array_values( array_filter( array_keys( self::KEYS ), static fn( $k ) => array_key_exists( $k, $base ) ) );
	}

	/**
	 * Everything the builder needs to show one form's connectors.
	 *
	 * @return array{form:array,kind:string,options:array,rows:array,state:string,missing:int}
	 */
	public static function describe( int $form_id ): array {
		$form = class_exists( '\GFAPI' ) ? \GFAPI::get_form( $form_id ) : null;
		if ( ! $form ) {
			return array( 'form' => array( 'id' => $form_id, 'title' => 'Form ' . $form_id, 'exists' => false ), 'kind' => 'standard', 'options' => array(), 'rows' => array(), 'state' => 'error', 'missing' => 0 );
		}
		$kind     = self::kind( $form_id );
		$options  = self::field_options( $form );
		$valid    = array_column( $options, 'label', 'value' );
		$base     = self::base( $form_id );
		$current  = Form_Map::for_form( $form_id );
		$saved    = self::overrides( $form_id );
		$required = self::REQUIRED[ $kind ];
		$rows     = array();
		$worst    = 'ok';
		$missing  = 0;

		foreach ( self::keys_for( $kind ) as $key ) {
			$value      = (string) ( $current[ $key ] ?? '' );
			$is_req     = in_array( $key, $required, true );
			$suggestion = '';
			if ( '' !== $value && isset( $valid[ $value ] ) ) {
				$state  = array_key_exists( $key, $saved ) ? 'custom' : 'auto';
				$status = array_key_exists( $key, $saved ) ? 'Connected' : 'Matched automatically';
			} else {
				$suggestion = self::detect( $key, $form );
				if ( '' === $value && array_key_exists( $key, $saved ) && ! $is_req ) {
					$state  = 'off';
					$status = 'Not used on this form';
				} elseif ( '' !== $suggestion ) {
					$state  = 'suggest';
					$status = 'Suggested from the field label. Save to use it';
				} elseif ( $is_req ) {
					$state  = 'missing';
					$status = 'Required. Pick the field that holds this';
				} else {
					$state  = 'off';
					$status = 'Not on this form';
				}
				if ( '' !== $value && ! isset( $valid[ $value ] ) && 'off' !== $state ) {
					$status = 'Field ' . $value . ' is not on this form. ' . $status;
				}
			}
			if ( 'missing' === $state ) {
				$worst = 'error';
				++$missing;
			} elseif ( 'suggest' === $state && 'error' !== $worst ) {
				$worst = $is_req ? 'error' : 'warn';
				if ( $is_req ) {
					++$missing;
				}
			}
			$rows[] = array(
				'key'        => $key,
				'label'      => self::KEYS[ $key ]['label'],
				'hint'       => self::KEYS[ $key ]['hint'],
				'required'   => $is_req,
				'value'      => isset( $valid[ $value ] ) ? $value : '',
				'default'    => (string) ( $base[ $key ] ?? '' ),
				'suggestion' => $suggestion,
				'state'      => $state,
				'status'     => $status,
			);
		}

		return array(
			'form'    => array(
				'id'     => $form_id,
				'title'  => (string) $form['title'],
				'exists' => true,
				'active' => ! empty( $form['is_active'] ),
				'edit'   => admin_url( 'admin.php?page=gf_edit_forms&id=' . $form_id ),
			),
			'kind'    => $kind,
			'options' => $options,
			'rows'    => $rows,
			'state'   => $worst,
			'missing' => $missing,
		);
	}

	/** Fields and sub-inputs of a form as choices: "Name › First (2.3)". */
	public static function field_options( array $form ): array {
		$out = array();
		foreach ( (array) $form['fields'] as $field ) {
			if ( in_array( $field->type, array( 'html', 'section', 'page', 'captcha' ), true ) ) {
				continue;
			}
			$label = trim( wp_strip_all_tags( (string) ( $field->adminLabel ? $field->adminLabel : $field->label ) ) );
			$label = '' === $label ? ucfirst( (string) $field->type ) : wp_trim_words( $label, 9, '…' );
			$out[] = array(
				'value' => (string) $field->id,
				'label' => $label . ' (' . $field->id . ')',
				'type'  => (string) $field->type,
			);
			if ( is_array( $field->inputs ) && 'checkbox' !== $field->type ) {
				foreach ( $field->inputs as $input ) {
					if ( ! empty( $input['isHidden'] ) ) {
						continue;
					}
					$out[] = array(
						'value' => (string) $input['id'],
						'label' => $label . ' › ' . wp_strip_all_tags( (string) $input['label'] ) . ' (' . $input['id'] . ')',
						'type'  => (string) $field->type,
					);
				}
			}
		}
		return $out;
	}

	/** Best guess for a connector from field types and labels. Empty string when nothing fits. */
	public static function detect( string $key, array $form ): string {
		foreach ( (array) $form['fields'] as $field ) {
			$label = strtolower( wp_strip_all_tags( (string) $field->label . ' ' . (string) $field->adminLabel ) );
			$type  = (string) $field->type;
			$input = (string) ( $field->inputType ?? '' );
			switch ( $key ) {
				case 'email':
					if ( 'email' === $type ) {
						return (string) $field->id;
					}
					break;
				case 'first_name':
				case 'last_name':
					if ( 'name' === $type ) {
						return $field->id . ( 'first_name' === $key ? '.3' : '.6' );
					}
					break;
				case 'company':
					if ( in_array( $type, array( 'text', 'hidden' ), true ) && preg_match( '/\b(company|organi[sz]ation|business|firm)\b/', $label ) ) {
						return (string) $field->id;
					}
					break;
				case 'phone':
					if ( 'phone' === $type ) {
						return (string) $field->id;
					}
					break;
				case 'address':
					if ( 'address' === $type ) {
						return (string) $field->id;
					}
					break;
				case 'total':
					if ( 'total' === $type ) {
						return (string) $field->id;
					}
					break;
				case 'payment_method':
					if ( in_array( $type, array( 'radio', 'select' ), true ) && str_contains( $label, 'payment' ) ) {
						return (string) $field->id;
					}
					break;
				case 'po_number':
					if ( in_array( $type, array( 'text', 'number' ), true ) && preg_match( '/\b(po|p\.o\.|purchase order)\b/', $label ) ) {
						return (string) $field->id;
					}
					break;
				case 'personnel':
					if ( 'list' === $type && preg_match( '/personnel|staff|representative|attendee/', $label ) ) {
						return (string) $field->id;
					}
					break;
				case 'booths':
					if ( in_array( $type, array( 'number', 'quantity' ), true ) && str_contains( $label, 'booth' ) ) {
						return (string) $field->id;
					}
					break;
				case 'booth_product':
					if ( 'product' === $type && str_contains( $label, 'booth' ) ) {
						return (string) $field->id;
					}
					break;
				case 'included_badges':
					if ( in_array( $type, array( 'number', 'hidden', 'calculation' ), true ) && str_contains( $label, 'badge' ) && preg_match( '/includ|free|complimentary/', $label ) ) {
						return (string) $field->id;
					}
					break;
				case 'badge_product':
					if ( 'product' === $type && str_contains( $label, 'badge' ) && 'calculation' !== $input ) {
						return (string) $field->id;
					}
					break;
			}
		}
		return '';
	}
}
