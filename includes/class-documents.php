<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Invoices and receipts as PDFs, one per Gravity Forms entry (an annual
 * registration covers several conferences on one document). Generated on
 * request from current data, so the latest version is always downloaded.
 */
final class Documents {

	public const OPT_ISSUER       = 'zpvp_invoice_issuer';
	public const OPT_INSTRUCTIONS = 'zpvp_invoice_instructions';

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'serve' ), 1 );
	}

	public static function url( int $registration_id ): string {
		// Built with add_query_arg (not wp_nonce_url, which HTML-encodes "&") so the
		// URL also works when sent to the staff dashboard as JSON.
		return add_query_arg(
			array(
				'zpvp_document' => $registration_id,
				'_wpnonce'      => wp_create_nonce( 'zpvp_doc_' . $registration_id ),
			),
			Settings::page_url( 'dashboard' )
		);
	}

	/** Receipt when everything on the entry is settled, otherwise invoice. */
	public static function kind( object $reg ): string {
		foreach ( self::entry_registrations( $reg ) as $r ) {
			if ( ! in_array( $r->payment_status, array( 'paid', 'no_charge' ), true ) ) {
				return 'invoice';
			}
		}
		return 'receipt';
	}

	public static function label( object $reg ): string {
		return 'receipt' === self::kind( $reg ) ? __( 'Receipt (PDF)', 'zp-vendor-portal' ) : __( 'Invoice (PDF)', 'zp-vendor-portal' );
	}

	/** Same number as ZacPubs' Gravity Forms invoice emails: TCP-{form ID}-{entry ID}. */
	public static function number( object $reg ): string {
		return 'TCP-' . (int) $reg->gf_form_id . '-' . (int) $reg->gf_entry_id;
	}

	/** @return object[] All registrations created from the same entry. */
	private static function entry_registrations( object $reg ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . Registrations::table() . " WHERE gf_entry_id = %d AND status = 'active' ORDER BY id", (int) $reg->gf_entry_id )
		);
	}

	public function serve(): void {
		if ( ! isset( $_GET['zpvp_document'] ) ) {
			return;
		}
		$rid = absint( $_GET['zpvp_document'] );
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'zpvp_doc_' . $rid ) ) {
			wp_die( esc_html__( 'This download link has expired. Please go back to My Conferences and try again.', 'zp-vendor-portal' ), '', array( 'response' => 403 ) );
		}
		$reg  = Registrations::get( $rid );
		$user = wp_get_current_user();
		$ok   = $reg && ( current_user_can( Roles::CAP_MANAGE ) || ( $user->exists() && (int) $reg->user_id === (int) $user->ID ) );
		if ( ! $ok ) {
			wp_die( esc_html__( 'You do not have access to this document.', 'zp-vendor-portal' ), '', array( 'response' => 403 ) );
		}

		$pdf      = self::build( $reg );
		$filename = ( 'receipt' === self::kind( $reg ) ? 'receipt-' : 'invoice-' ) . self::number( $reg ) . '.pdf';
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . ( isset( $_GET['download'] ) ? 'attachment' : 'inline' ) . '; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF.
		exit;
	}

	/** Line items from the entry's products; any difference to the total becomes a fees line. */
	private static function line_items( object $reg, array $regs ): array {
		$lines = array();
		$total = 0.0;
		if ( class_exists( '\GFAPI' ) ) {
			$entry = \GFAPI::get_entry( (int) $reg->gf_entry_id );
			$form  = \GFAPI::get_form( (int) $reg->gf_form_id );
			if ( ! is_wp_error( $entry ) && $form ) {
				$products = \GFCommon::get_product_fields( $form, $entry );
				foreach ( (array) ( $products['products'] ?? array() ) as $p ) {
					$qty = (float) \GFCommon::to_number( (string) ( $p['quantity'] ?? 0 ) );
					if ( $qty <= 0 ) {
						continue;
					}
					$unit = (float) \GFCommon::to_number( (string) ( $p['price'] ?? 0 ) );
					$desc = wp_strip_all_tags( (string) $p['name'] );
					foreach ( (array) ( $p['options'] ?? array() ) as $o ) {
						$unit += (float) ( $o['price'] ?? 0 );
						$desc .= ' – ' . wp_strip_all_tags( (string) ( $o['option_name'] ?? $o['option_label'] ?? '' ) );
					}
					if ( 0.0 === $unit ) {
						continue;
					}
					$lines[] = array( 'desc' => $desc, 'qty' => $qty, 'unit' => $unit, 'amount' => $unit * $qty );
				}
				$map   = Form_Map::for_form( (int) $reg->gf_form_id );
				$total = (float) \GFCommon::to_number( (string) rgar( $entry, $map['total'] ?? '' ) );
			}
		}
		if ( ! $lines ) {
			foreach ( $regs as $r ) {
				$lines[] = array( 'desc' => Registrations::conference_label( $r ) . ' – ' . Registrations::summary( $r ), 'qty' => 1, 'unit' => (float) $r->amount, 'amount' => (float) $r->amount );
			}
		}
		$sum = array_sum( array_column( $lines, 'amount' ) );
		if ( $total <= 0 ) {
			$total = $sum;
		} elseif ( abs( $total - $sum ) >= 0.01 ) {
			$lines[] = array( 'desc' => __( 'Processing fees and adjustments', 'zp-vendor-portal' ), 'qty' => 1, 'unit' => $total - $sum, 'amount' => $total - $sum );
		}
		return array( $lines, $total );
	}

	private static function money( float $v ): string {
		return ( $v < 0 ? '-$' : '$' ) . number_format( abs( $v ), 2 );
	}

	public static function build( object $reg ): string {
		$regs   = self::entry_registrations( $reg );
		$regs   = $regs ? $regs : array( $reg );
		$kind   = self::kind( $reg );
		$pdf    = new Pdf();
		$left   = 54;
		$right  = Pdf::WIDTH - 54;
		$y      = Pdf::HEIGHT - 60;
		$issuer = trim( (string) get_option( self::OPT_ISSUER, get_bloginfo( 'name' ) ) );

		// Issuer (top left).
		foreach ( preg_split( '/\R/', $issuer ) as $i => $line ) {
			$pdf->text( $left, $y - $i * 14, $line, 0 === $i ? 13 : 9.5, 0 === $i );
		}

		// Title block (top right).
		$pdf->text_right( $right, $y, 'receipt' === $kind ? 'RECEIPT' : 'INVOICE', 22, true );
		$meta = array(
			__( 'Number', 'zp-vendor-portal' ) => self::number( $reg ),
			__( 'Date', 'zp-vendor-portal' )   => mysql2date( 'F j, Y', $reg->created_at ),
		);
		if ( 'receipt' === $kind ) {
			$meta[ __( 'Status', 'zp-vendor-portal' ) ] = __( 'Paid', 'zp-vendor-portal' );
		} elseif ( $reg->po_number ) {
			$meta[ __( 'PO number', 'zp-vendor-portal' ) ] = $reg->po_number;
		}
		$my = $y - 26;
		foreach ( $meta as $k => $v ) {
			$pdf->text_right( $right - 110, $my, $k . ':', 9.5 );
			$pdf->text_right( $right, $my, (string) $v, 9.5, true );
			$my -= 14;
		}

		// Bill to.
		$y = min( $y - 14 * count( preg_split( '/\R/', $issuer ) ), $my ) - 24;
		$pdf->text( $left, $y, strtoupper( __( 'Bill to', 'zp-vendor-portal' ) ), 8.5, true, 0.4 );
		$y -= 15;
		$bill = array_filter( array_merge( array( $reg->company_name, $reg->contact_name ), self::address_lines( $reg ), array( $reg->contact_email ) ) );
		foreach ( $bill as $i => $line ) {
			$pdf->text( $left, $y, (string) $line, 10, 0 === $i );
			$y -= 13;
		}

		// Conferences.
		$y -= 10;
		$pdf->text( $left, $y, strtoupper( _n( 'Conference', 'Conferences', count( $regs ), 'zp-vendor-portal' ) ), 8.5, true, 0.4 );
		$y -= 15;
		foreach ( $regs as $r ) {
			$dates = $r->edition_id ? Conferences::date_range( (int) $r->edition_id ) : '';
			$pdf->text( $left, $y, Registrations::conference_label( $r ) . ( $dates ? '  (' . $dates . ')' : '' ), 10 );
			$y -= 13;
		}

		// Line items.
		[ $lines, $total ] = self::line_items( $reg, $regs );
		$y -= 14;
		$pdf->box( $left, $y - 5, $right - $left, 20, 0.93 );
		$pdf->text( $left + 6, $y + 1, __( 'Description', 'zp-vendor-portal' ), 9, true );
		$pdf->text_right( $right - 190, $y + 1, __( 'Qty', 'zp-vendor-portal' ), 9, true );
		$pdf->text_right( $right - 100, $y + 1, __( 'Unit price', 'zp-vendor-portal' ), 9, true );
		$pdf->text_right( $right - 6, $y + 1, __( 'Amount', 'zp-vendor-portal' ), 9, true );
		$y -= 22;

		foreach ( $lines as $line ) {
			$wrapped = $pdf->wrap( $line['desc'], 290, 9.5 );
			if ( $y - 13 * count( $wrapped ) < 120 ) {
				$pdf->add_page();
				$y = Pdf::HEIGHT - 60;
			}
			foreach ( $wrapped as $i => $w ) {
				$pdf->text( $left + 6, $y - $i * 12, $w, 9.5 );
			}
			$pdf->text_right( $right - 190, $y, rtrim( rtrim( number_format( $line['qty'], 2 ), '0' ), '.' ), 9.5 );
			$pdf->text_right( $right - 100, $y, self::money( $line['unit'] ), 9.5 );
			$pdf->text_right( $right - 6, $y, self::money( $line['amount'] ), 9.5 );
			$y -= 12 * count( $wrapped ) + 6;
			$pdf->line( $left, $y + 2, $right, $y + 2 );
			$y -= 8;
		}

		// Totals.
		$y -= 4;
		$pdf->text_right( $right - 100, $y, __( 'Total', 'zp-vendor-portal' ), 11, true );
		$pdf->text_right( $right - 6, $y, self::money( $total ), 11, true );
		$y -= 16;
		$method = Registrations::method_label( $reg->payment_method );
		if ( 'receipt' === $kind ) {
			$pdf->text_right( $right - 100, $y, __( 'Paid', 'zp-vendor-portal' ) . ( '—' !== $method ? ' (' . $method . ')' : '' ), 10 );
			$pdf->text_right( $right - 6, $y, self::money( $total ), 10 );
			$y -= 14;
			$pdf->text_right( $right - 100, $y, __( 'Balance due', 'zp-vendor-portal' ), 10, true );
			$pdf->text_right( $right - 6, $y, self::money( 0 ), 10, true );
		} else {
			$pdf->text_right( $right - 100, $y, __( 'Amount due', 'zp-vendor-portal' ), 11, true );
			$pdf->text_right( $right - 6, $y, self::money( $total ), 11, true );
		}
		$y -= 34;

		// Payment instructions (invoices only).
		if ( 'invoice' === $kind ) {
			$instructions = trim( (string) get_option( self::OPT_INSTRUCTIONS, '' ) );
			$intro        = 'po' === $reg->payment_method
				? sprintf( __( 'Payment method: invoice with PO number %s.', 'zp-vendor-portal' ), $reg->po_number ? $reg->po_number : '—' )
				: sprintf( __( 'Payment method: %s.', 'zp-vendor-portal' ), $method );
			$pdf->text( $left, $y, strtoupper( __( 'Payment', 'zp-vendor-portal' ) ), 8.5, true, 0.4 );
			$y -= 15;
			foreach ( $pdf->wrap( $intro . ( $instructions ? "\n" . $instructions : '' ), $right - $left, 9.5 ) as $w ) {
				$pdf->text( $left, $y, $w, 9.5 );
				$y -= 12;
			}
		}

		$pdf->line( $left, 60, $right, 60 );
		$pdf->text( $left, 46, sprintf( __( 'Generated %s', 'zp-vendor-portal' ), wp_date( 'F j, Y g:i a' ) ), 8, false, 0.45 );
		$pdf->text_right( $right, 46, self::number( $reg ), 8 );

		return $pdf->output();
	}

	/** Company address from the entry's address field. */
	private static function address_lines( object $reg ): array {
		if ( ! class_exists( '\GFAPI' ) ) {
			return array();
		}
		$entry = \GFAPI::get_entry( (int) $reg->gf_entry_id );
		if ( is_wp_error( $entry ) ) {
			return array();
		}
		$map = Form_Map::for_form( (int) $reg->gf_form_id );
		$f   = (string) ( $map['address'] ?? '' );
		if ( '' === $f ) {
			return array();
		}
		$street = trim( rgar( $entry, $f . '.1' ) . ' ' . rgar( $entry, $f . '.2' ) );
		$city   = trim( implode( ', ', array_filter( array( rgar( $entry, $f . '.3' ), trim( rgar( $entry, $f . '.4' ) . ' ' . rgar( $entry, $f . '.5' ) ) ) ) ) );
		return array_filter( array( $street, $city ) );
	}
}
