<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * My Conferences: the vendor's home. Upcoming registrations are shown as
 * exhibitor passes (booth number first), then open conferences, conference
 * information and previous registrations.
 */
final class Dashboard {

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

		<?php $this->open_conferences( $regs ); ?>
		<?php $this->resources( $upcoming ); ?>
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

	/** Hotel, decorator, electricity and documents for upcoming registrations. */
	private function resources( array $regs ): void {
		$editions = array_unique( array_filter( array_map( static fn( $r ) => (int) $r->edition_id, $regs ) ) );
		$blocks   = array();
		foreach ( $editions as $id ) {
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
			if ( $sections ) {
				$blocks[] = '<details class="zpvp-info"><summary>' . esc_html( get_the_title( $id ) ) . '</summary><div class="zpvp-info__grid">' . implode( '', $sections ) . '</div></details>';
			}
		}
		if ( $blocks ) {
			echo '<section class="zpvp-section" aria-labelledby="zpvp-h-info"><h2 id="zpvp-h-info">' . esc_html__( 'Conference information', 'zp-vendor-portal' ) . '</h2>' . implode( '', $blocks ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}
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
