<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * My Conferences: the vendor's home. Upcoming registrations are shown as
 * exhibitor passes (booth number first), then open conferences, conference
 * information and previous registrations.
 */
final class Dashboard {

	/** Conference info popup content per edition, built once per page. */
	private array $info = array();

	public function register(): void {
		add_shortcode( 'zpvp_dashboard', array( $this, 'render' ) );
	}

	public function render(): string {
		if ( ! current_user_can( Roles::CAP_PORTAL ) ) {
			return '';
		}
		$banner = '';
		if ( Roles::is_preview() ) {
			$who    = Roles::viewer();
			$banner = '<div class="zpvp-notice" style="background:#fff4d6;border-left:4px solid #c98a00;padding:10px 14px;margin-bottom:16px"><strong>' . esc_html__( 'Staff preview', 'zp-vendor-portal' ) . ':</strong> ' . esc_html( sprintf( /* translators: %s: vendor name */ __( 'you are seeing the portal exactly as %s sees it. Nothing can be changed here.', 'zp-vendor-portal' ), $who->display_name ) ) . '</div>';
		}
		if ( isset( $_GET['registration'] ) ) {
			return Frontend::shell( 'dashboard', $banner . Vendor_Attendees::render( absint( $_GET['registration'] ) ) );
		}
		if ( 'account' === sanitize_key( $_GET['view'] ?? '' ) ) {
			return Frontend::shell( 'account', Account::render() );
		}
		return Frontend::shell( 'dashboard', $banner . $this->home() );
	}

	private function home(): string {
		$user = Roles::viewer();
		$name = $user->first_name ? $user->first_name : $user->display_name;
		$regs = Registrations::for_user( (int) $user->ID );

		$today    = current_time( 'Ymd' );
		$upcoming = array();
		$previous = array();
		foreach ( $regs as $reg ) {
			$end     = $reg->edition_id ? (string) get_post_meta( (int) $reg->edition_id, 'end_date', true ) : '';
			$is_past = $end ? $end < $today : ( (int) $reg->year < (int) current_time( 'Y' ) );
			if ( $is_past ) {
				$previous[] = $reg;
			} else {
				$upcoming[] = $reg;
			}
		}

		ob_start();
		?>
		<div class="zpvp-page-head">
			<h1><?php echo esc_html( sprintf( __( 'Welcome back, %s', 'zp-vendor-portal' ), $name ) ); ?></h1>
		</div>

		<section class="zpvp-section" aria-labelledby="zpvp-h-upcoming">
			<h2 id="zpvp-h-upcoming"><?php esc_html_e( 'Your upcoming conferences', 'zp-vendor-portal' ); ?></h2>
			<?php if ( $upcoming ) : ?>
				<div class="zpvp-passes">
					<?php foreach ( $upcoming as $reg ) { $this->pass( $reg ); } ?>
				</div>
			<?php else : ?>
				<p class="zpvp-empty"><?php esc_html_e( 'You have no upcoming registrations. Choose a conference below to register.', 'zp-vendor-portal' ); ?></p>
			<?php endif; ?>
		</section>

		<?php $this->info_dialogs(); ?>
		<?php $this->open_conferences( $regs ); ?>
		<?php $this->previous( $previous ); ?>
		<?php
		return (string) ob_get_clean();
	}

	/** One upcoming registration as an exhibitor pass. */
	private function pass( object $reg ): void {
		$people   = Attendees::count( (int) $reg->id );
		$editable = Attendees::vendor_can_edit( $reg );
		$city     = $reg->edition_id ? (string) get_post_meta( (int) $reg->edition_id, 'city', true ) : '';
		$when     = implode( ', ', array_filter( array( $reg->edition_id ? Conferences::date_range( (int) $reg->edition_id ) : (string) $reg->year, $city ) ) );
		?>
		<article class="zpvp-pass">
			<header class="zpvp-pass__top">
				<span class="zpvp-pass__hole" aria-hidden="true"></span>
				<h3 class="zpvp-pass__event"><?php echo esc_html( Registrations::conference_label( $reg ) ); ?></h3>
				<?php if ( $when ) : ?><p class="zpvp-pass__when"><?php echo esc_html( $when ); ?></p><?php endif; ?>
				<?php if ( $reg->edition_id && '' !== $this->info_html( (int) $reg->edition_id ) ) : ?>
					<button type="button" class="zpvp-pass__info" data-zpvp-dialog="zpvp-info-<?php echo (int) $reg->edition_id; ?>" aria-haspopup="dialog"><?php esc_html_e( 'Conference info', 'zp-vendor-portal' ); ?></button>
				<?php endif; ?>
			</header>
			<div class="zpvp-pass__body">
				<div class="zpvp-pass__booth">
					<?php if ( 'sponsorship' === $reg->type ) : ?>
						<span><?php esc_html_e( 'Sponsorship', 'zp-vendor-portal' ); ?></span>
						<strong><?php echo esc_html( Registrations::summary( $reg ) ); ?></strong>
					<?php else : ?>
						<span><?php echo esc_html( _n( 'Booth', 'Booths', max( 1, (int) $reg->booths ), 'zp-vendor-portal' ) ); ?></span>
					<?php if ( $reg->booth_number ) : ?>
						<strong><?php echo esc_html( $reg->booth_number ); ?></strong>
					<?php else : ?>
						<em><?php esc_html_e( 'Number to be assigned', 'zp-vendor-portal' ); ?></em>
					<?php endif; ?>
					<?php endif; ?>
				</div>
				<?php if ( (int) $reg->user_id && (int) $reg->user_id !== (int) Roles::viewer()->ID && $reg->contact_name ) : ?>
					<p class="zpvp-pass__by"><?php echo esc_html( sprintf( __( 'Registered by %s', 'zp-vendor-portal' ), $reg->contact_name ) ); ?></p>
				<?php endif; ?>
				<dl class="zpvp-pass__facts">
					<?php if ( 'sponsorship' !== $reg->type ) : ?><div><dt><?php esc_html_e( 'Booths', 'zp-vendor-portal' ); ?></dt><dd><?php echo esc_html( (string) $reg->booths ); ?></dd></div><?php endif; ?>
					<div><dt><?php esc_html_e( 'Payment', 'zp-vendor-portal' ); ?></dt><dd><?php echo Frontend::pill( $reg->payment_status ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd></div>
					<?php if ( 'sponsorship' !== $reg->type ) : ?><div><dt><?php esc_html_e( 'Booth staff', 'zp-vendor-portal' ); ?></dt><dd><?php echo esc_html( sprintf( _n( '%d person', '%d people', $people, 'zp-vendor-portal' ), $people ) ); ?></dd></div><?php endif; ?>
				</dl>
			</div>
			<footer class="zpvp-pass__actions">
				<?php if ( 'sponsorship' !== $reg->type ) : ?><a class="zpvp-btn zpvp-btn--primary" href="<?php echo esc_url( Vendor_Attendees::url( (int) $reg->id ) ); ?>"><?php echo esc_html( $editable ? __( 'Manage booth staff', 'zp-vendor-portal' ) : __( 'View booth staff', 'zp-vendor-portal' ) ); ?></a><?php endif; ?>
				<a class="zpvp-btn" href="<?php echo esc_url( Documents::url( (int) $reg->id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( 'receipt' === Documents::kind( $reg ) ? __( 'Receipt', 'zp-vendor-portal' ) : __( 'Invoice', 'zp-vendor-portal' ) ); ?><span class="screen-reader-text"> <?php esc_html_e( '(PDF, opens in a new tab)', 'zp-vendor-portal' ); ?></span></a>
			</footer>
		</article>
		<?php
	}

	private function open_conferences( array $regs ): void {
		$ids  = Conferences::current_editions();
		$mine = array();
		foreach ( $regs as $r ) {
			$mine[ (int) $r->edition_id ] = (int) $r->id;
		}
		?>
		<section class="zpvp-section" aria-labelledby="zpvp-h-open">
			<h2 id="zpvp-h-open"><?php esc_html_e( 'Open for registration', 'zp-vendor-portal' ); ?></h2>
			<?php if ( ! $ids ) : ?>
				<p class="zpvp-empty"><?php esc_html_e( 'No conferences are taking registrations right now. New conferences appear here as soon as registration opens.', 'zp-vendor-portal' ); ?></p>
			<?php else : ?>
				<ul class="zpvp-events">
				<?php foreach ( $ids as $id ) : ?>
					<?php
					$status          = Conferences::status( $id );
					$url             = Conferences::registration_page_url( $id );
					$city            = (string) get_post_meta( $id, 'city', true );
					$closes          = (string) get_post_meta( $id, 'registration_closes', true );
					[ $month, $days ] = Frontend::date_block( $id );
					?>
					<li class="zpvp-event">
						<div class="zpvp-event__date" aria-hidden="true"><span><?php echo esc_html( $month ); ?></span><strong><?php echo esc_html( $days ); ?></strong></div>
						<div class="zpvp-event__main">
							<h3><?php echo esc_html( get_the_title( $id ) ); ?></h3>
							<p>
								<?php
								echo esc_html(
									implode(
										'. ',
										array_filter(
											array(
												trim( Conferences::date_range( $id ) . ( $city ? ', ' . $city : '' ), ', ' ),
												$closes ? sprintf( __( 'Registration closes %s', 'zp-vendor-portal' ), mysql2date( 'F j', $closes ) ) : '',
											)
										)
									)
								);
								?>
							</p>
						</div>
						<div class="zpvp-event__cta">
							<?php if ( isset( $mine[ $id ] ) ) : ?>
								<span class="zpvp-pill zpvp-pill--registered"><?php esc_html_e( 'You are registered', 'zp-vendor-portal' ); ?></span>
								<?php if ( $url && 'sold_out' !== $status ) : ?>
									<a class="zpvp-link" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Register more booths', 'zp-vendor-portal' ); ?></a>
								<?php endif; ?>
							<?php elseif ( 'sold_out' === $status ) : ?>
								<span class="zpvp-pill zpvp-pill--soldout"><?php esc_html_e( 'Sold out', 'zp-vendor-portal' ); ?></span>
							<?php elseif ( $url ) : ?>
								<a class="zpvp-btn zpvp-btn--accent" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Register', 'zp-vendor-portal' ); ?></a>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}

	/** Hotel, decorator, electricity and documents for one conference; empty when there is nothing to show. */
	private function info_html( int $id ): string {
		if ( isset( $this->info[ $id ] ) ) {
			return $this->info[ $id ];
		}
		$sections = array();
		foreach ( array( 'hotel_info' => __( 'Hotel', 'zp-vendor-portal' ), 'decorator_info' => __( 'Decorator', 'zp-vendor-portal' ), 'electricity_info' => __( 'Electricity', 'zp-vendor-portal' ) ) as $key => $label ) {
			$html = (string) get_post_meta( $id, $key, true );
			if ( '' !== trim( wp_strip_all_tags( $html ) ) ) {
				$sections[] = '<div class="zpvp-info__item"><h4>' . esc_html( $label ) . '</h4>' . wp_kses_post( wpautop( $html ) ) . '</div>';
			}
		}
		$files = array();
		$plan  = (int) get_post_meta( $id, 'floor_plan', true );
		if ( $plan && wp_get_attachment_url( $plan ) ) {
			$files[] = '<li><a href="' . esc_url( wp_get_attachment_url( $plan ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Floor plan', 'zp-vendor-portal' ) . '</a></li>';
		}
		$count = (int) get_post_meta( $id, 'resource_files', true );
		for ( $i = 0; $i < $count; $i++ ) {
			$file  = (int) get_post_meta( $id, 'resource_files_' . $i . '_file', true );
			$label = (string) get_post_meta( $id, 'resource_files_' . $i . '_label', true );
			if ( $file && wp_get_attachment_url( $file ) ) {
				$files[] = '<li><a href="' . esc_url( wp_get_attachment_url( $file ) ) . '" target="_blank" rel="noopener">' . esc_html( $label ? $label : get_the_title( $file ) ) . '</a></li>';
			}
		}
		if ( $files ) {
			$sections[] = '<div class="zpvp-info__item"><h4>' . esc_html__( 'Documents', 'zp-vendor-portal' ) . '</h4><ul>' . implode( '', $files ) . '</ul></div>';
		}
		$this->info[ $id ] = $sections ? '<div class="zpvp-info__grid">' . implode( '', $sections ) . '</div>' : '';
		return $this->info[ $id ];
	}

	/** One popup per conference whose card offers "Conference info". */
	private function info_dialogs(): void {
		$ids = array_keys( array_filter( $this->info ) );
		if ( ! $ids ) {
			return;
		}
		foreach ( $ids as $id ) {
			$venue = implode( ', ', array_filter( array( (string) get_post_meta( $id, 'venue', true ), (string) get_post_meta( $id, 'city', true ) ) ) );
			$when  = implode( '. ', array_filter( array( Conferences::date_range( $id ), $venue ) ) );
			?>
			<dialog class="zpvp-dialog" id="zpvp-info-<?php echo (int) $id; ?>" aria-labelledby="zpvp-info-<?php echo (int) $id; ?>-title">
				<header class="zpvp-dialog__head">
					<div>
						<h2 id="zpvp-info-<?php echo (int) $id; ?>-title"><?php echo esc_html( get_the_title( $id ) ); ?></h2>
						<?php if ( $when ) : ?><p><?php echo esc_html( $when ); ?></p><?php endif; ?>
					</div>
					<button type="button" class="zpvp-dialog__close" data-zpvp-close aria-label="<?php esc_attr_e( 'Close', 'zp-vendor-portal' ); ?>">&times;</button>
				</header>
				<div class="zpvp-dialog__body"><?php echo $this->info[ $id ]; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in info_html(). ?></div>
			</dialog>
			<?php
		}
		?>
		<script>
		( function () {
			document.addEventListener( 'click', function ( e ) {
				var open = e.target.closest( '[data-zpvp-dialog]' );
				if ( open ) {
					var d = document.getElementById( open.getAttribute( 'data-zpvp-dialog' ) );
					if ( d && d.showModal ) { d.showModal(); }
					return;
				}
				var dlg = e.target.closest( '.zpvp-dialog' );
				// Close button, or a click on the backdrop (the dialog element itself).
				if ( dlg && ( e.target.closest( '[data-zpvp-close]' ) || e.target === dlg ) ) { dlg.close(); }
			} );
		}() );
		</script>
		<?php
	}

	private function previous( array $regs ): void {
		if ( ! $regs ) {
			return;
		}
		?>
		<section class="zpvp-section" aria-labelledby="zpvp-h-prev">
			<h2 id="zpvp-h-prev"><?php esc_html_e( 'Previous conferences', 'zp-vendor-portal' ); ?></h2>
			<ul class="zpvp-history">
			<?php foreach ( $regs as $reg ) : ?>
				<li>
					<span class="zpvp-history__name"><?php echo esc_html( Registrations::conference_label( $reg ) ); ?></span>
					<span class="zpvp-history__meta"><?php echo esc_html( Registrations::summary( $reg ) . ( $reg->booth_number ? ', ' . sprintf( __( 'booth %s', 'zp-vendor-portal' ), $reg->booth_number ) : '' ) ); ?></span>
					<a class="zpvp-link" href="<?php echo esc_url( Documents::url( (int) $reg->id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( 'receipt' === Documents::kind( $reg ) ? __( 'Receipt', 'zp-vendor-portal' ) : __( 'Invoice', 'zp-vendor-portal' ) ); ?></a>
				</li>
			<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}
}
