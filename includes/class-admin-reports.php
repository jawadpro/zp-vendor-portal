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
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_zpvp_export_attendees', array( $this, 'export_attendees' ) );
		add_action( 'admin_post_zpvp_export_badges', array( $this, 'export_badges' ) );
		add_action( 'admin_post_zpvp_export_contacts', array( $this, 'export_contacts' ) );
	}

	public function menu(): void {
		add_submenu_page( 'zpvp', 'Reports', 'Reports', Roles::CAP_MANAGE, 'zpvp-reports', array( $this, 'render' ) );
	}

	public function assets( $hook ): void {
		if ( str_ends_with( (string) $hook, '_page_zpvp-reports' ) ) {
			wp_enqueue_style( 'zpvp-admin-settings', ZPVP_URL . 'assets/css/admin-settings.css', array(), ZPVP_VERSION );
		}
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
		$collected = $t['revenue'] > 0 ? (int) round( 100 * $t['paid'] / $t['revenue'] ) : null;
		$cards     = array(
			array( 'Registrations', number_format( $t['registrations'] ), '' ),
			array( 'Booths sold', number_format( $t['booths'] ), '' ),
			array( 'Booth personnel', number_format( $t['attendees'] ), '' ),
			array( 'Registered value', self::money( $t['revenue'] ), '' ),
			array( 'Paid', self::money( $t['paid'] ), null !== $collected ? $collected . '% collected' : '' ),
			array( 'Outstanding', self::money( $t['outstanding'] ), $t['outstanding'] > 0 ? 'Awaiting or pending' : 'Nothing owed' ),
		);
		$filtered = $f['edition'] || $f['year'] || $f['payment'] || $f['category'] || '' !== $f['search'] || $f['from'] || $f['to'];
		$exports  = array(
			array( 'zpvp_export_registrations', 'Registrations', 'One row per registration, with payment, booths and vendor categories.' ),
			array( 'zpvp_export_attendees', 'Booth personnel', 'Everyone listed on a booth, with company and booth number.' ),
			array( 'zpvp_export_badges', 'Badges', 'Every badge with its print status, for badge printing or checks.' ),
			array( 'zpvp_export_contacts', 'Vendor contacts', 'Every contact at every vendor company, for Mailchimp or Brevo. Only the category filter applies.' ),
		);
		?>
		<div class="wrap zpvp-settings zpvp-report">
			<header class="zpvp-settings__head">
				<h1>Reports</h1>
				<p>Registrations, booth sales and payments by conference. Filter the figures below, then export the same selection as CSV.</p>
			</header>

			<form method="get" class="zpvp-report__filters">
				<input type="hidden" name="page" value="zpvp-reports">
				<div class="zpvp-report__field">
					<label for="zpvp-r-edition">Conference</label>
					<select id="zpvp-r-edition" name="edition">
						<option value="">All conferences</option>
						<?php foreach ( $editions as $e ) : ?>
							<option value="<?php echo (int) $e->ID; ?>" <?php selected( $f['edition'], $e->ID ); ?>><?php echo esc_html( $e->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="zpvp-report__field">
					<label for="zpvp-r-year">Year</label>
					<select id="zpvp-r-year" name="year">
						<option value="">All years</option>
						<?php foreach ( $years as $y ) : ?>
							<option value="<?php echo (int) $y; ?>" <?php selected( $f['year'], (int) $y ); ?>><?php echo (int) $y; ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="zpvp-report__field">
					<label for="zpvp-r-payment">Payment</label>
					<select id="zpvp-r-payment" name="payment">
						<option value="">All payment statuses</option>
						<option value="outstanding" <?php selected( $f['payment'], 'outstanding' ); ?>>Outstanding (awaiting or pending)</option>
						<?php foreach ( Registrations::PAYMENT_STATUSES as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['payment'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php if ( Companies::all_categories() ) : ?>
				<div class="zpvp-report__field">
					<label for="zpvp-r-category">Vendor category</label>
					<select id="zpvp-r-category" name="category">
						<option value="">All categories</option>
						<?php foreach ( Companies::all_categories() as $tid => $label ) : ?>
							<option value="<?php echo (int) $tid; ?>" <?php selected( (int) $f['category'], (int) $tid ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>
				<div class="zpvp-report__field zpvp-report__field--search">
					<label for="zpvp-r-search">Vendor</label>
					<input type="search" id="zpvp-r-search" name="s" placeholder="Company, contact or email" value="<?php echo esc_attr( $f['search'] ); ?>">
				</div>
				<div class="zpvp-report__field">
					<label for="zpvp-r-from">Registered from</label>
					<input type="date" id="zpvp-r-from" name="from" value="<?php echo esc_attr( $f['from'] ); ?>">
				</div>
				<div class="zpvp-report__field">
					<label for="zpvp-r-to">to</label>
					<input type="date" id="zpvp-r-to" name="to" value="<?php echo esc_attr( $f['to'] ); ?>">
				</div>
				<div class="zpvp-report__buttons">
					<?php submit_button( 'Apply filters', 'primary', '', false ); ?>
					<?php if ( $filtered ) : ?><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=zpvp-reports' ) ); ?>">Clear</a><?php endif; ?>
				</div>
			</form>

			<div class="zpvp-report__kpis">
				<?php foreach ( $cards as $c ) : ?>
					<div class="zpvp-report__kpi">
						<span class="zpvp-report__kpi-label"><?php echo esc_html( $c[0] ); ?></span>
						<strong class="zpvp-report__kpi-value"><?php echo esc_html( $c[1] ); ?></strong>
						<?php if ( 'Paid' === $c[0] && null !== $collected ) : ?>
							<span class="zpvp-report__meter" aria-hidden="true"><span style="width:<?php echo (int) min( 100, $collected ); ?>%"></span></span>
						<?php endif; ?>
						<?php if ( $c[2] ) : ?><span class="zpvp-report__kpi-note"><?php echo esc_html( $c[2] ); ?></span><?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<section class="zpvp-table-card zpvp-report__table">
				<div class="zpvp-report__section-head">
					<h2 class="zpvp-table-card__title">By conference</h2>
					<span class="zpvp-count"><?php echo count( $rows ); ?></span>
				</div>
				<div class="zpvp-report__scroll">
				<table class="widefat striped">
					<thead><tr>
						<th scope="col">Conference</th>
						<th scope="col" class="zpvp-num">Registrations</th>
						<th scope="col">Booths sold</th>
						<th scope="col" class="zpvp-num">Personnel</th>
						<th scope="col" class="zpvp-num">Registered value</th>
						<th scope="col" class="zpvp-num">Paid</th>
						<th scope="col" class="zpvp-num">Outstanding</th>
					</tr></thead>
					<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="7" class="zpvp-report__empty">No registrations match these filters.</td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $r ) : ?>
						<?php
						$cap   = $r->edition_id ? (int) get_post_meta( (int) $r->edition_id, 'booth_capacity', true ) : 0;
						$label = Registrations::conference_label( $r );
						$pct   = $cap ? min( 100, (int) round( 100 * (int) $r->booths / $cap ) ) : null;
						$link  = admin_url( 'admin.php?page=zpvp' . ( $r->edition_id ? '&edition=' . (int) $r->edition_id : '' ) );
						?>
						<tr>
							<td>
								<a class="zpvp-report__conf" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $label ); ?></a>
								<?php if ( ! $r->edition_id ) : ?><span class="zpvp-muted">No conference set up for this year yet</span><?php endif; ?>
								<?php if ( (int) $r->failed ) : ?><span class="zpvp-muted"><?php echo esc_html( sprintf( _n( '%d failed payment not counted', '%d failed payments not counted', (int) $r->failed, 'zp-vendor-portal' ), (int) $r->failed ) ); ?></span><?php endif; ?>
							</td>
							<td class="zpvp-num"><?php echo esc_html( number_format( (int) $r->registrations ) ); ?></td>
							<td class="zpvp-report__booth-cell">
								<span class="zpvp-report__booths"><?php echo esc_html( (int) $r->booths . ( $cap ? ' of ' . $cap : '' ) ); ?></span>
								<?php if ( null !== $pct ) : ?>
									<span class="zpvp-report__meter<?php echo $pct >= 100 ? ' is-full' : ''; ?>" title="<?php echo esc_attr( $pct . '% of capacity' ); ?>"><span style="width:<?php echo (int) $pct; ?>%"></span></span>
								<?php else : ?>
									<span class="zpvp-muted">No capacity set</span>
								<?php endif; ?>
							</td>
							<td class="zpvp-num"><?php echo esc_html( number_format( (int) $r->attendees ) ); ?></td>
							<td class="zpvp-num"><?php echo esc_html( self::money( $r->revenue ) ); ?></td>
							<td class="zpvp-num"><?php echo esc_html( self::money( $r->paid ) ); ?></td>
							<td class="zpvp-num"><?php echo (float) $r->outstanding > 0 ? '<span class="zpvp-report__owed">' . esc_html( self::money( $r->outstanding ) ) . '</span>' : '<span class="zpvp-muted-inline">' . esc_html( self::money( 0 ) ) . '</span>'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
					<?php if ( count( $rows ) > 1 ) : ?>
					<tfoot><tr>
						<th scope="row">Total</th>
						<td class="zpvp-num"><?php echo esc_html( number_format( $t['registrations'] ) ); ?></td>
						<td><?php echo esc_html( number_format( $t['booths'] ) ); ?></td>
						<td class="zpvp-num"><?php echo esc_html( number_format( $t['attendees'] ) ); ?></td>
						<td class="zpvp-num"><?php echo esc_html( self::money( $t['revenue'] ) ); ?></td>
						<td class="zpvp-num"><?php echo esc_html( self::money( $t['paid'] ) ); ?></td>
						<td class="zpvp-num"><?php echo esc_html( self::money( $t['outstanding'] ) ); ?></td>
					</tr></tfoot>
					<?php endif; ?>
				</table>
				</div>
				<p class="zpvp-report__note">Amounts are the booth amounts recorded for each conference. The one-time fee on the annual form isn't tied to a single conference, so it isn't included here; the full amount is on the vendor's invoice.</p>
			</section>

			<section class="zpvp-table-card">
				<div class="zpvp-report__section-head">
					<h2 class="zpvp-table-card__title">Export to CSV</h2>
				</div>
				<p class="zpvp-table-card__intro"><?php echo $filtered ? 'Exports use the filters above.' : 'Exports include everything. Apply filters above to narrow them.'; ?></p>
				<div class="zpvp-report__exports">
					<?php foreach ( $exports as $x ) : ?>
						<a class="zpvp-report__export" href="<?php echo esc_url( $this->export_url( $x[0], $f ) ); ?>">
							<span class="dashicons dashicons-download" aria-hidden="true"></span>
							<span><strong><?php echo esc_html( $x[1] ); ?></strong><span class="zpvp-muted"><?php echo esc_html( $x[2] ); ?></span></span>
						</a>
					<?php endforeach; ?>
				</div>
			</section>
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
