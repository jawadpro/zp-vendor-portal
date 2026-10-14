<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Vendor Portal → Reports: totals and per-conference breakdown with the
 * same filters as Registrations, plus CSV exports for registrations,
 * booth personnel and badges.
 */
final class Admin_Reports {

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 15 );
		add_action( 'admin_post_zpvp_export_attendees', array( $this, 'export_attendees' ) );
		add_action( 'admin_post_zpvp_export_badges', array( $this, 'export_badges' ) );
		add_action( 'admin_post_zpvp_export_contacts', array( $this, 'export_contacts' ) );
	}

	public function menu(): void {
		add_submenu_page( 'zpvp', 'Reports', 'Reports', Roles::CAP_MANAGE, 'zpvp-reports', array( $this, 'render' ) );
	}

	/** Per-conference rows for the current filters. */
	public static function summary( array $f ): array {
		global $wpdb;
		$rt = Registrations::table();
		$at = Attendees::table();
		[ $where, $args ] = Registrations::filter_sql( $f, 'r.' );

		$sql = "SELECT r.edition_id, r.series_id, r.year,
				COUNT(*) AS registrations,
				SUM(CASE WHEN r.payment_status NOT IN ('failed','refunded') THEN r.booths ELSE 0 END) AS booths,
				SUM(CASE WHEN r.payment_status NOT IN ('failed','refunded') THEN r.amount ELSE 0 END) AS revenue,
				SUM(CASE WHEN r.payment_status IN ('paid','no_charge') THEN r.amount ELSE 0 END) AS paid,
				SUM(CASE WHEN r.payment_status IN ('awaiting','pending') THEN r.amount ELSE 0 END) AS outstanding,
				SUM(CASE WHEN r.payment_status = 'failed' THEN 1 ELSE 0 END) AS failed,
				SUM(COALESCE(ac.people, 0)) AS attendees
			FROM {$rt} r
			LEFT JOIN (SELECT registration_id, COUNT(*) AS people FROM {$at} WHERE status = 'active' GROUP BY registration_id) ac ON ac.registration_id = r.id
			{$where}
			GROUP BY r.edition_id, r.series_id, r.year
			ORDER BY r.year DESC, revenue DESC";

		return (array) ( $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql ) );
	}

	private static function money( $v ): string {
		return '$' . number_format( (float) $v, 2 );
	}

	private function export_url( string $action, array $f ): string {
		$args = array_filter(
			array(
				'action'  => $action,
				'edition' => $f['edition'] ? $f['edition'] : null,
				'year'    => $f['year'] ? $f['year'] : null,
				'payment' => $f['payment'] ? $f['payment'] : null,
				'category' => ! empty( $f['category'] ) ? $f['category'] : null,
				's'       => '' !== $f['search'] ? $f['search'] : null,
				'from'    => $f['from'] ? $f['from'] : null,
				'to'      => $f['to'] ? $f['to'] : null,
			)
		);
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), $action );
	}

	public function render(): void {
		global $wpdb;
		$f        = Registrations::request_filters();
		$rows     = self::summary( $f );
		$editions = get_posts( array( 'post_type' => Conferences::EDITION, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$years    = $wpdb->get_col( 'SELECT DISTINCT year FROM ' . Registrations::table() . " WHERE status = 'active' AND year > 0 ORDER BY year DESC" );

		$t = array( 'registrations' => 0, 'booths' => 0, 'revenue' => 0, 'paid' => 0, 'outstanding' => 0, 'attendees' => 0 );
		foreach ( $rows as $r ) {
			foreach ( $t as $k => $v ) {
				$t[ $k ] += (float) $r->$k;
			}
		}
		$cards = array(
			'Registrations'       => number_format( $t['registrations'] ),
			'Booths'              => number_format( $t['booths'] ),
			'Booth personnel'     => number_format( $t['attendees'] ),
			'Registered value'    => self::money( $t['revenue'] ),
			'Paid'                => self::money( $t['paid'] ),
			'Outstanding'         => self::money( $t['outstanding'] ),
		);
		?>
		<div class="wrap">
			<h1>Reports</h1>

			<form method="get" style="margin:12px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
				<input type="hidden" name="page" value="zpvp-reports">
				<select name="edition">
					<option value="">All conferences</option>
					<?php foreach ( $editions as $e ) : ?>
						<option value="<?php echo (int) $e->ID; ?>" <?php selected( $f['edition'], $e->ID ); ?>><?php echo esc_html( $e->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="year">
					<option value="">All years</option>
					<?php foreach ( $years as $y ) : ?>
						<option value="<?php echo (int) $y; ?>" <?php selected( $f['year'], (int) $y ); ?>><?php echo (int) $y; ?></option>
					<?php endforeach; ?>
				</select>
				<select name="payment">
					<option value="">All payment statuses</option>
					<option value="outstanding" <?php selected( $f['payment'], 'outstanding' ); ?>>Outstanding (awaiting or pending)</option>
					<?php foreach ( Registrations::PAYMENT_STATUSES as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['payment'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( Companies::all_categories() ) : ?>
				<select name="category">
					<option value="">All vendor categories</option>
					<?php foreach ( Companies::all_categories() as $tid => $label ) : ?>
						<option value="<?php echo (int) $tid; ?>" <?php selected( (int) $f['category'], (int) $tid ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php endif; ?>
				<input type="search" name="s" placeholder="Vendor, contact or email" value="<?php echo esc_attr( $f['search'] ); ?>">
				<label>From <input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>"></label>
				<label>To <input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>"></label>
				<?php submit_button( 'Apply', '', '', false ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=zpvp-reports' ) ); ?>">Reset</a>
			</form>

			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px;margin:16px 0">
				<?php foreach ( $cards as $label => $value ) : ?>
					<div style="background:#fff;border:1px solid #dcdcde;padding:14px 16px">
						<div style="color:#50575e;font-size:12px;text-transform:uppercase;letter-spacing:.04em"><?php echo esc_html( $label ); ?></div>
						<div style="font-size:22px;font-weight:600;margin-top:4px"><?php echo esc_html( $value ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<h2>By conference</h2>
			<table class="widefat striped">
				<thead><tr>
					<th>Conference</th><th>Registrations</th><th>Booths sold / capacity</th><th>Personnel</th>
					<th>Registered value</th><th>Paid</th><th>Outstanding</th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="7">No registrations match these filters.</td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<?php
					$cap   = $r->edition_id ? (int) get_post_meta( (int) $r->edition_id, 'booth_capacity', true ) : 0;
					$label = Registrations::conference_label( $r );
					$pct   = $cap ? min( 100, round( 100 * (int) $r->booths / $cap ) ) : null;
					$link  = admin_url( 'admin.php?page=zpvp-registrations' . ( $r->edition_id ? '&edition=' . (int) $r->edition_id : '' ) );
					?>
					<tr>
						<td><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $label ); ?></a>
							<?php if ( ! $r->edition_id ) : ?><br><span class="description">No conference set up for this year yet</span><?php endif; ?>
							<?php if ( (int) $r->failed ) : ?><br><span class="description"><?php echo esc_html( (int) $r->failed . ' failed payment(s) excluded' ); ?></span><?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format( (int) $r->registrations ) ); ?></td>
						<td>
							<?php echo esc_html( (int) $r->booths . ' / ' . ( $cap ? $cap : '∞' ) ); ?>
							<?php if ( null !== $pct ) : ?>
								<div style="background:#f0f0f1;height:6px;margin-top:4px;max-width:140px"><div style="background:<?php echo $pct >= 100 ? '#b42318' : '#1d5c8c'; ?>;height:6px;width:<?php echo (int) $pct; ?>%"></div></div>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format( (int) $r->attendees ) ); ?></td>
						<td><?php echo esc_html( self::money( $r->revenue ) ); ?></td>
						<td><?php echo esc_html( self::money( $r->paid ) ); ?></td>
						<td><?php echo (float) $r->outstanding > 0 ? '<strong>' . esc_html( self::money( $r->outstanding ) ) . '</strong>' : esc_html( self::money( 0 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">Amounts are the booth amounts recorded per conference. On the annual form, the one-time annual fee is not assigned to a single conference, so it is not included here; the full amount appears on the vendor's invoice.</p>

			<h2>Export (uses the filters above)</h2>
			<p>
				<a class="button" href="<?php echo esc_url( $this->export_url( 'zpvp_export_registrations', $f ) ); ?>">Registrations CSV</a>
				<a class="button" href="<?php echo esc_url( $this->export_url( 'zpvp_export_attendees', $f ) ); ?>">Booth personnel CSV</a>
				<a class="button" href="<?php echo esc_url( $this->export_url( 'zpvp_export_badges', $f ) ); ?>">Badges CSV</a>
				<a class="button" href="<?php echo esc_url( $this->export_url( 'zpvp_export_contacts', $f ) ); ?>">Vendor contacts CSV</a>
			</p>
			<p class="description">Vendor contacts lists every contact at every vendor company with their categories and the last conference they registered for (only the category filter applies). Use it to import vendors into Mailchimp or Brevo.</p>
		</div>
		<?php
	}

	private function guard( string $action ): void {
		if ( ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( $action );
	}

	public function export_attendees(): void {
		global $wpdb;
		$this->guard( 'zpvp_export_attendees' );
		[ $where, $args ] = Registrations::filter_sql( Registrations::request_filters(), 'r.' );
		$sql  = 'SELECT a.*, r.edition_id, r.series_id, r.year, r.company_name, r.booth_number, r.id AS reg_id
			FROM ' . Attendees::table() . ' a JOIN ' . Registrations::table() . " r ON r.id = a.registration_id
			{$where} AND a.status = 'active' ORDER BY r.company_name, a.name";
		$rows = (array) ( $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql ) );

		Csv::download(
			'booth-personnel-' . current_time( 'Y-m-d' ) . '.csv',
			array( 'Conference', 'Company', 'Booth #', 'Name', 'Title', 'Email', 'Added by', 'Registration ID' ),
			array_map(
				static fn( $a ) => array(
					Registrations::conference_label( $a ),
					$a->company_name,
					$a->booth_number,
					$a->name,
					$a->title,
					$a->email,
					ucfirst( $a->source ),
					$a->reg_id,
				),
				$rows
			)
		);
	}

	/** Every contact at every vendor company (optionally one category): for mailing lists. */
	public function export_contacts(): void {
		global $wpdb;
		$this->guard( 'zpvp_export_contacts' );
		$category  = absint( $_GET['category'] ?? 0 );
		$companies = $category ? Companies::in_categories( array( $category ) ) : array_map( 'intval', get_posts( array( 'post_type' => Companies::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) ) );
		$rows      = array();
		foreach ( $companies as $cid ) {
			$last    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Registrations::table() . " WHERE company_id = %d AND status = 'active' ORDER BY year DESC, created_at DESC LIMIT 1", $cid ) );
			$primary = Companies::primary_contact( $cid );
			foreach ( Companies::contacts( $cid ) as $u ) {
				$rows[] = array(
					get_the_title( $cid ),
					implode( '; ', Companies::category_names( $cid ) ),
					$u->first_name,
					$u->last_name,
					$u->user_email,
					(string) get_user_meta( $u->ID, 'zpvp_phone', true ),
					(string) get_user_meta( $u->ID, 'zpvp_job_title', true ),
					(int) $u->ID === $primary ? 'Yes' : 'No',
					$last ? Registrations::conference_label( $last ) : '',
				);
			}
		}
		Csv::download(
			'vendor-contacts-' . current_time( 'Y-m-d' ) . '.csv',
			array( 'Company', 'Categories', 'First name', 'Last name', 'Email', 'Phone', 'Job title', 'Primary contact', 'Last conference' ),
			$rows
		);
	}

	public function export_badges(): void {
		global $wpdb;
		$this->guard( 'zpvp_export_badges' );
		[ $where, $args ] = Registrations::filter_sql( Registrations::request_filters(), 'r.' );
		$sql  = 'SELECT b.*, r.series_id, r.year, r.booth_number
			FROM ' . Badges::table() . ' b JOIN ' . Registrations::table() . " r ON r.id = b.registration_id
			{$where} AND b.status <> 'void' ORDER BY b.company, b.name";
		$rows = (array) ( $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql ) );

		Csv::download(
			'badges-' . current_time( 'Y-m-d' ) . '.csv',
			array( 'Conference', 'Name', 'Title', 'Company', 'Booth #', 'Type', 'Status', 'Times printed', 'Last printed', 'Needs payment' ),
			array_map(
				static fn( $b ) => array(
					Registrations::conference_label( $b ),
					$b->name,
					$b->title,
					$b->company,
					$b->booth_number,
					Badges::TYPES[ $b->type ] ?? $b->type,
					Badges::STATUSES[ $b->status ] ?? $b->status,
					$b->print_count,
					$b->printed_at,
					(int) $b->needs_payment ? 'Yes' : 'No',
				),
				$rows
			)
		);
	}
}
