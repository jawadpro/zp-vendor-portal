<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Vendor-facing attendee management on My Conferences
 * (/my-conferences/?registration=ID).
 */
final class Vendor_Attendees {

	private static array $errors = array();

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle' ), 20 );
	}

	public static function url( int $registration_id ): string {
		return Roles::preview_arg( add_query_arg( 'registration', $registration_id, Settings::page_url( 'dashboard' ) ) );
	}

	/** The registration, only if it belongs to the logged-in vendor. */
	public static function owned( int $registration_id ): ?object {
		$reg     = Registrations::get( $registration_id );
		$user_id = (int) Roles::viewer()->ID;
		return ( $reg && Companies::user_can_access( $user_id, $reg ) ) ? $reg : null;
	}

	public function handle(): void {
		if ( ! Settings::is_page( 'dashboard' ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		if ( 'attendees' !== sanitize_key( $_POST['zpvp_action'] ?? '' ) || Roles::is_preview() ) {
			return;
		}
		$rid = absint( $_POST['registration'] ?? 0 );
		$reg = self::owned( $rid );
		if ( ! $reg ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_zpvp_nonce'] ?? '' ) ), 'zpvp_attendees_' . $rid ) ) {
			self::$errors[] = __( 'Your session expired. Please try again.', 'zp-vendor-portal' );
			return;
		}
		if ( ! Attendees::vendor_can_edit( $reg ) ) {
			self::$errors[] = __( 'The deadline for changing booth staff has passed.', 'zp-vendor-portal' );
			return;
		}

		$result = Attendees::save_from_request( $rid, (array) ( $_POST['attendees'] ?? array() ), (array) ( $_POST['new'] ?? array() ), 'vendor' );
		if ( $result['errors'] ) {
			self::$errors = array_unique( $result['errors'] );
			return;
		}
		wp_safe_redirect( add_query_arg( 'zpvp_saved', 1, self::url( $rid ) ) );
		exit;
	}

	/** Unpaid extra badges: pay online on the Name Badge Order Form, or download an invoice. */
	private static function extra_badges( object $reg ): void {
		$owed = Documents::owed_badges( $reg );
		$pay  = Badge_Orders::order_url( (int) $reg->id );
		if ( $owed ) {
			echo '<div class="zpvp-notice zpvp-notice--warning"><p>';
			echo esc_html( sprintf( /* translators: %s: amount */ __( 'Amount due for extra badges: %s.', 'zp-vendor-portal' ), '$' . number_format( $owed * Badge_Orders::price(), 2 ) ) );
			echo '</p><p>';
			if ( $pay ) {
				echo '<a class="zpvp-btn zpvp-btn--accent" href="' . esc_url( $pay ) . '">' . esc_html__( 'Pay for extra badges', 'zp-vendor-portal' ) . '</a> ';
			}
			echo '<a class="zpvp-btn" href="' . esc_url( Documents::badge_invoice_url( (int) $reg->id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Invoice for extra badges', 'zp-vendor-portal' ) . '<span class="screen-reader-text"> ' . esc_html__( '(PDF, opens in a new tab)', 'zp-vendor-portal' ) . '</span></a>';
			echo '</p></div>';
		} elseif ( $pay && ! Attendees::vendor_can_edit( $reg ) ) {
			echo '<p><a class="zpvp-link" href="' . esc_url( $pay ) . '">' . esc_html__( 'Need more name badges? Order them here.', 'zp-vendor-portal' ) . '</a></p>';
		}
		$orders = Badge_Orders::for_registration( (int) $reg->id );
		if ( $orders ) {
			echo '<details class="zpvp-info"><summary>' . esc_html( sprintf( _n( '%d badge order', '%d badge orders', count( $orders ), 'zp-vendor-portal' ), count( $orders ) ) ) . '</summary><ul>';
			foreach ( $orders as $o ) {
				echo '<li>' . esc_html( sprintf( '%s, %s: %d additional, %d replacement. %s', Badge_Orders::KINDS[ $o->kind ] ?? '', mysql2date( 'M j, Y', $o->created_at ), (int) $o->additional_qty, (int) $o->replacement_qty, Registrations::payment_label( $o->payment_status ) ) ) . '</li>';
			}
			echo '</ul></details>';
		}
	}

	public static function render( int $registration_id ): string {
		$reg = self::owned( $registration_id );
		ob_start();
		echo '<div class="zpvp-staff">';
		echo '<p class="zpvp-back"><a href="' . esc_url( Roles::preview_arg( Settings::page_url( 'dashboard' ) ) ) . '">' . esc_html__( 'Back to my conferences', 'zp-vendor-portal' ) . '</a></p>';

		if ( ! $reg ) {
			echo '<div class="zpvp-notice zpvp-notice--error">' . esc_html__( 'Registration not found.', 'zp-vendor-portal' ) . '</div></div>';
			return (string) ob_get_clean();
		}

		$attendees = Attendees::for_registration( $registration_id );
		$editable  = Attendees::vendor_can_edit( $reg ); // In a staff preview the form shows as the vendor sees it; handle() ignores saves.
		$until     = Attendees::editable_until( $reg );
		?>
		<div class="zpvp-page-head">
		<h1><?php echo esc_html( Registrations::conference_label( $reg ) ); ?></h1>
		<p>
			<?php
			echo esc_html(
				implode(
					' · ',
					array_filter(
						array(
							$reg->edition_id ? Conferences::date_range( (int) $reg->edition_id ) : '',
							/* translators: %d: number of booths */
							Registrations::summary( $reg ),
							$reg->booth_number ? sprintf( __( 'Booth %s', 'zp-vendor-portal' ), $reg->booth_number ) : '',
						)
					)
				)
			);
			?>
		</p>
		</div>

		<?php if ( isset( $_GET['zpvp_saved'] ) && ! self::$errors ) : ?>
			<div class="zpvp-notice zpvp-notice--success" role="status"><?php esc_html_e( 'Booth staff saved.', 'zp-vendor-portal' ); ?></div>
		<?php endif; ?>
		<?php foreach ( self::$errors as $error ) : ?>
			<div class="zpvp-notice zpvp-notice--error"><?php echo esc_html( $error ); ?></div>
		<?php endforeach; ?>

		<h2><?php esc_html_e( 'Booth staff', 'zp-vendor-portal' ); ?></h2>
		<p class="zpvp-badge-summary"><?php echo esc_html( Badges::summary( $reg ) ); ?></p>
		<?php self::extra_badges( $reg ); ?>
		<?php if ( $editable ) : ?>
			<p class="zpvp-deadline"><?php echo esc_html( sprintf( __( 'You can make changes until %s. Name badges are printed from this list.', 'zp-vendor-portal' ), mysql2date( 'F j, Y g:i a', (string) $until ) ) ); ?></p>
			<form method="post" class="zpvp-form zpvp-form--wide">
				<input type="hidden" name="zpvp_action" value="attendees">
				<input type="hidden" name="registration" value="<?php echo (int) $registration_id; ?>">
				<input type="hidden" name="_zpvp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'zpvp_attendees_' . $registration_id ) ); ?>">
				<?php Attendees::render_fields( $attendees, true ); ?>
				<p><button type="submit" class="zpvp-btn zpvp-btn--primary"><?php esc_html_e( 'Save booth staff', 'zp-vendor-portal' ); ?></button></p>
			</form>
		<?php else : ?>
			<p>
				<?php
				echo esc_html(
					$until
						? sprintf( __( 'Changes closed on %s. Please contact ZacPubs if something needs to be updated.', 'zp-vendor-portal' ), mysql2date( 'F j, Y', $until ) )
						: __( 'Booth personnel can be changed once the conference dates are confirmed.', 'zp-vendor-portal' )
				);
				?>
			</p>
			<?php Attendees::render_fields( $attendees, false ); ?>
		<?php endif; ?>
		<?php
		echo '</div>';
		return (string) ob_get_clean();
	}
}
