<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Vendor Portal → Past registrations: connects registrations made before a
 * vendor had an account (or before the portal existed) to vendor accounts.
 *
 * Matching is conservative: only an exact email match to exactly one vendor
 * account links automatically. Everything else is listed for staff, who can
 * link it by hand. Gravity Forms entries and payments are never changed; the
 * portal only adds its own registration records.
 */
final class Historical {

	public const PAGE  = 'zpvp-historical';
	private const BATCH = 200;

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 35 );
		add_action( 'admin_post_zpvp_historical', array( $this, 'handle' ) );
	}

	public function menu(): void {
		add_submenu_page( 'zpvp', 'Past registrations', 'Past registrations', Roles::CAP_MANAGE, self::PAGE, array( $this, 'render' ) );
	}

	/** The vendor account with exactly this email, or 0. */
	private static function vendor_by_email( string $email ): int {
		$email = sanitize_email( $email );
		if ( ! $email ) {
			return 0;
		}
		$user = get_user_by( 'email', $email );
		return ( $user && Roles::is_vendor( $user ) ) ? (int) $user->ID : 0;
	}

	/* ------------------------------------------------------------------ */
	/* 1. Recorded registrations without an account                        */
	/* ------------------------------------------------------------------ */

	/** @return array{matched: array<int, array{reg:object, user:int}>, unmatched: object[]} */
	public static function unlinked(): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . Registrations::table() . " WHERE user_id = 0 AND status = 'active' ORDER BY created_at DESC LIMIT 2000" );
		$out  = array( 'matched' => array(), 'unmatched' => array() );
		foreach ( $rows as $r ) {
			$uid = self::vendor_by_email( (string) $r->contact_email );
			if ( $uid ) {
				$out['matched'][] = array( 'reg' => $r, 'user' => $uid );
			} else {
				$out['unmatched'][] = $r;
			}
		}
		return $out;
	}

	public static function link_registration( int $registration_id, int $user_id ): bool {
		$reg  = Registrations::get( $registration_id );
		$user = get_userdata( $user_id );
		if ( ! $reg || ! $user || ! Roles::is_vendor( $user ) ) {
			return false;
		}
		Registrations::update(
			$registration_id,
			array(
				'user_id'    => $user_id,
				'company_id' => Companies::company_for_user( $user_id ),
				'notes'      => trim( (string) $reg->notes . "\n" . sprintf( 'Linked to vendor account %s by %s on %s.', $user->user_email, wp_get_current_user()->display_name, current_time( 'mysql' ) ) ),
			)
		);
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* 2. Entries submitted before the portal                              */
	/* ------------------------------------------------------------------ */

	/** Conference and sponsorship forms with their series. @return array<int, int> form => series */
	private static function forms(): array {
		$out = array();
		$all = get_posts( array( 'post_type' => Conferences::SERIES, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) );
		foreach ( $all as $series ) {
			foreach ( array_merge( Series::form_ids( (int) $series, 'gf_forms' ), Series::form_ids( (int) $series, 'sponsorship_forms' ) ) as $form ) {
				$out[ (int) $form ] = (int) $series;
			}
		}
		unset( $out[ Form_Map::annual_form_id() ] ); // The annual form books several conferences per entry; leave it out.
		return $out;
	}

	/**
	 * Past entries of conference forms the portal hasn't recorded.
	 *
	 * @return array{matched: array<int, array{entry:array, form:int, user:int}>, unmatched: array<int, array{entry:array, form:int, email:string}>, total:int}
	 */
	public static function unrecorded( int $limit = 1000 ): array {
		global $wpdb;
		$out = array( 'matched' => array(), 'unmatched' => array(), 'total' => 0 );
		if ( ! class_exists( '\GFAPI' ) ) {
			return $out;
		}
		$recorded = array_flip( array_map( 'intval', (array) $wpdb->get_col( 'SELECT DISTINCT gf_entry_id FROM ' . Registrations::table() ) ) );
		foreach ( self::forms() as $form_id => $series ) {
			$map    = Form_Map::for_form( $form_id );
			$offset = 0;
			do {
				$entries = \GFAPI::get_entries( $form_id, array( 'status' => 'active' ), array( 'key' => 'id', 'direction' => 'DESC' ), array( 'offset' => $offset, 'page_size' => 200 ) );
				if ( is_wp_error( $entries ) ) {
					break;
				}
				foreach ( $entries as $e ) {
					if ( isset( $recorded[ (int) $e['id'] ] ) ) {
						continue;
					}
					++$out['total'];
					$email = (string) rgar( $e, (string) ( $map['email'] ?? '3' ) );
					$uid   = self::vendor_by_email( $email );
					if ( $uid && count( $out['matched'] ) < $limit ) {
						$out['matched'][] = array( 'entry' => $e, 'form' => $form_id, 'user' => $uid );
					} elseif ( ! $uid && count( $out['unmatched'] ) < 100 ) {
						$out['unmatched'][] = array( 'entry' => $e, 'form' => $form_id, 'email' => $email );
					}
				}
				$offset += 200;
			} while ( count( $entries ) === 200 && $offset < 5000 );
		}
		return $out;
	}

	/**
	 * The conference an old entry was for: the series' conference that starts
	 * soonest on or after the entry date (within 18 months). Without one, the
	 * registration is kept under the series and the entry's year.
	 *
	 * @return array{0:int, 1:int} edition ID, year
	 */
	private static function edition_for_entry( int $series, string $date ): array {
		$day  = gmdate( 'Ymd', strtotime( $date ) );
		$best = 0;
		$when = '';
		foreach ( Conferences::of_series( array( $series ) ) as $id ) {
			$start = (string) get_post_meta( $id, 'start_date', true );
			if ( $start && $start >= $day && $start <= gmdate( 'Ymd', strtotime( $date . ' +18 months' ) ) && ( ! $best || $start < $when ) ) {
				$best = (int) $id;
				$when = $start;
			}
		}
		$year = $best ? ( (int) get_post_meta( $best, 'year', true ) ?: (int) substr( $when, 0, 4 ) ) : (int) substr( $date, 0, 4 );
		return array( $best, $year );
	}

	/** Record one past entry for a vendor without sending any email. */
	public static function import_entry( array $entry, int $form_id, int $user_id ): bool {
		$form   = \GFAPI::get_form( $form_id );
		$series = self::forms()[ $form_id ] ?? 0;
		if ( ! $form || ! $series ) {
			return false;
		}
		[ $edition, $year ]       = self::edition_for_entry( $series, (string) $entry['date_created'] );
		Registration_Sync::$import = array( 'user_id' => $user_id, 'edition' => $edition, 'year' => $year );
		try {
			( new Registration_Sync() )->on_submission( $entry, $form );
		} finally {
			Registration_Sync::$import = null;
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Screen                                                              */
	/* ------------------------------------------------------------------ */

	public function render(): void {
		$unlinked = self::unlinked();
		$past     = self::unrecorded();
		$vendors  = get_users( array( 'role' => Roles::VENDOR, 'orderby' => 'display_name', 'number' => 3000 ) );
		$post     = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1>Past registrations</h1>
			<p>Connect registrations to vendor accounts so vendors see them under Previous conferences. Only an exact email match to one vendor account links automatically; you link anything else by hand. Gravity Forms entries and payments are never changed, and no emails are sent.</p>
			<?php if ( isset( $_GET['zpvp_msg'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['zpvp_msg'] ) ) ); ?></p></div>
			<?php endif; ?>

			<h2>1. Registrations without a vendor account</h2>
			<p>Registrations recorded by the portal whose email had no vendor account at the time.</p>
			<?php if ( $unlinked['matched'] ) : ?>
				<form method="post" action="<?php echo esc_url( $post ); ?>">
					<input type="hidden" name="action" value="zpvp_historical">
					<input type="hidden" name="do" value="link_all">
					<?php wp_nonce_field( 'zpvp_historical' ); ?>
					<p><button class="button button-primary"><?php echo esc_html( sprintf( 'Link %d registration(s) to the matching vendor account', count( $unlinked['matched'] ) ) ); ?></button></p>
				</form>
			<?php else : ?>
				<p><em>None can be matched automatically.</em></p>
			<?php endif; ?>
			<?php self::manual_table( array_map( static fn( $r ) => array( 'type' => 'reg', 'id' => (int) $r->id, 'when' => $r->created_at, 'what' => Registrations::conference_label( $r ), 'company' => $r->company_name, 'email' => $r->contact_email ), array_slice( $unlinked['unmatched'], 0, 100 ) ), $vendors ); ?>

			<h2 style="margin-top:2em">2. Entries from before the portal</h2>
			<p><?php echo esc_html( sprintf( '%d conference or sponsorship form entries have no portal registration. %d of them match a vendor account by email.', $past['total'], count( $past['matched'] ) ) ); ?>
			Each is recorded for the conference that started soonest after the entry date (or the series and the entry's year if that conference isn't set up). The annual multi-conference form is left out.</p>
			<?php if ( $past['matched'] ) : ?>
				<form method="post" action="<?php echo esc_url( $post ); ?>">
					<input type="hidden" name="action" value="zpvp_historical">
					<input type="hidden" name="do" value="import_all">
					<?php wp_nonce_field( 'zpvp_historical' ); ?>
					<p><button class="button button-primary"><?php echo esc_html( sprintf( 'Import %d matched entr%s (up to %d at a time)', count( $past['matched'] ), 1 === count( $past['matched'] ) ? 'y' : 'ies', self::BATCH ) ); ?></button></p>
				</form>
			<?php endif; ?>
			<?php
			self::manual_table(
				array_map(
					static fn( $u ) => array(
						'type'    => 'entry',
						'id'      => (int) $u['entry']['id'],
						'form'    => (int) $u['form'],
						'when'    => (string) $u['entry']['date_created'],
						'what'    => ( \GFAPI::get_form( (int) $u['form'] )['title'] ?? 'Form ' . $u['form'] ),
						'company' => (string) rgar( $u['entry'], (string) ( Form_Map::for_form( (int) $u['form'] )['company'] ?? '9' ) ),
						'email'   => $u['email'],
					),
					$past['unmatched']
				),
				$vendors
			);
			?>
		</div>
		<?php
	}

	/** Unmatched rows, each with a vendor picker. */
	private static function manual_table( array $rows, array $vendors ): void {
		if ( ! $rows ) {
			echo '<p><em>Nothing left to link by hand.</em></p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Date</th><th>Conference / form</th><th>Company</th><th>Email</th><th>Link to vendor</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( mysql2date( 'M j, Y', $r['when'] ) ) . '</td><td>' . esc_html( $r['what'] ) . '</td><td>' . esc_html( $r['company'] ) . '</td><td>' . esc_html( $r['email'] ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:6px">';
			echo '<input type="hidden" name="action" value="zpvp_historical"><input type="hidden" name="do" value="' . ( 'reg' === $r['type'] ? 'link_one' : 'import_one' ) . '">';
			echo '<input type="hidden" name="id" value="' . (int) $r['id'] . '"><input type="hidden" name="form" value="' . (int) ( $r['form'] ?? 0 ) . '">';
			wp_nonce_field( 'zpvp_historical' );
			echo '<select name="user" style="max-width:260px"><option value="">Choose a vendor…</option>';
			foreach ( $vendors as $v ) {
				echo '<option value="' . (int) $v->ID . '">' . esc_html( Companies::company_name_for_user( (int) $v->ID ) . ' – ' . $v->display_name . ' (' . $v->user_email . ')' ) . '</option>';
			}
			echo '</select><button class="button">Link</button></form></td></tr>';
		}
		echo '</tbody></table>';
	}

	public function handle(): void {
		if ( ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_historical' );
		$do   = sanitize_key( $_POST['do'] ?? '' );
		$done = 0;
		if ( 'link_all' === $do ) {
			foreach ( array_slice( self::unlinked()['matched'], 0, self::BATCH ) as $m ) {
				$done += self::link_registration( (int) $m['reg']->id, (int) $m['user'] ) ? 1 : 0;
			}
			$msg = sprintf( '%d registration(s) linked.', $done );
		} elseif ( 'import_all' === $do ) {
			foreach ( self::unrecorded( self::BATCH )['matched'] as $m ) {
				$done += self::import_entry( $m['entry'], (int) $m['form'], (int) $m['user'] ) ? 1 : 0;
			}
			$msg = sprintf( '%d past entr%s recorded.', $done, 1 === $done ? 'y' : 'ies' );
		} elseif ( 'link_one' === $do ) {
			$msg = self::link_registration( absint( $_POST['id'] ?? 0 ), absint( $_POST['user'] ?? 0 ) ) ? 'Registration linked.' : 'Choose a vendor first.';
		} else {
			$entry = class_exists( '\GFAPI' ) ? \GFAPI::get_entry( absint( $_POST['id'] ?? 0 ) ) : null;
			$uid   = absint( $_POST['user'] ?? 0 );
			$msg   = ( $entry && ! is_wp_error( $entry ) && $uid && self::import_entry( $entry, absint( $_POST['form'] ?? 0 ), $uid ) ) ? 'Entry recorded for that vendor.' : 'Choose a vendor first.';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'zpvp_msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
