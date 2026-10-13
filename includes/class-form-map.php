<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Where to find each piece of registration data in the conference forms.
 * Field IDs come from the task 01 Gravity Forms audit.
 */
final class Form_Map {

	/** The shared exhibitor template (forms 9–39). */
	public const STANDARD = array(
		'first_name'     => '2.3',
		'last_name'      => '2.6',
		'email'          => '3',
		'company'        => '9',
		'address'        => '10',
		'phone'          => '23',
		'booths'         => '25',
		'booth_product'  => '26',
		'total'          => '27',
		'payment_method' => '20',
		'po_number'      => '21',
		'personnel'      => '45',
		'included_badges' => '46',
		'badge_product'  => '31',
	);

	/** The annual Conference Year registration form (44). */
	public const ANNUAL = array(
		'first_name'     => '2.3',
		'last_name'      => '2.6',
		'email'          => '3',
		'company'        => '9',
		'address'        => '64',
		'total'          => '68',
		'payment_method' => '20',
		'po_number'      => '21',
	);

	/** Sponsorship forms (30, 31, 33, 38, 46, 47 share this layout). The level field is found by its label. */
	public const SPONSORSHIP = array(
		'first_name'     => '2.3',
		'last_name'      => '2.6',
		'email'          => '3',
		'company'        => '9',
		'address'        => '10',
		'phone'          => '23',
		'total'          => '56',
		'payment_method' => '20',
		'po_number'      => '21',
	);

	public static function is_sponsorship_form( int $form_id ): bool {
		return $form_id && ! Conferences::series_for_form( $form_id ) && Conferences::series_for_sponsorship_form( $form_id ) > 0;
	}

	public static function annual_form_id(): int {
		return (int) apply_filters( 'zpvp_annual_form_id', (int) get_option( Setup::OPT_ANNUAL, 0 ) );
	}

	public static function for_form( int $form_id ): array {
		if ( $form_id === self::annual_form_id() ) {
			$map = self::ANNUAL;
		} elseif ( self::is_sponsorship_form( $form_id ) ) {
			$map = self::SPONSORSHIP;
		} else {
			$map = self::STANDARD;
		}
		return (array) apply_filters( 'zpvp_form_map', $map, $form_id );
	}

	/** Group (series) of the conference that records new registrations from a form. */
	public static function series_for_form( int $form_id ): int {
		return Conferences::series_for_form( $form_id );
	}

	/** @deprecated 0.16.0 Annual dropdowns are chosen per conference; see Conferences::annual_fields(). */
	public static function annual_series(): array {
		$out = array();
		foreach ( Conferences::annual_fields() as $field ) {
			$edition = Conferences::for_annual_field( $field );
			if ( $edition ) {
				$out[ $field ] = (int) get_post_meta( $edition, 'series', true );
			}
		}
		return $out;
	}

	public static function is_linked_form( int $form_id ): bool {
		return $form_id === self::annual_form_id() || Conferences::series_for_form( $form_id ) > 0 || Conferences::series_for_sponsorship_form( $form_id ) > 0;
	}
}
