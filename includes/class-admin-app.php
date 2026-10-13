<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * The staff workspace (Vendor Portal → Dashboard): one screen with a
 * conference switcher, live totals, Registrations and Badges tabs, a side
 * panel for editing a registration and a pop-up for emailing vendors.
 * Rendering happens in assets/js/admin-app.js against the zpvp/v1 REST API.
 */
final class Admin_App {

	/** Menu order: daily work first, setup last. */
	private const ORDER = array(
		'zpvp',
		'edit.php?post_type=zp_conf_edition',
		'zpvp-reports',
		'zpvp-settings',
	);

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'reorder' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'in_admin_header', array( $this, 'quiet_screen' ), 1000 );
	}

	/**
	 * Other plugins' notices (promotions, nags) push the workspace off screen.
	 * Hide them on the Dashboard, Reports and Settings only; every other admin page is untouched.
	 */
	public function quiet_screen(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && in_array( $screen->id, array( 'toplevel_page_zpvp', 'vendor-portal_page_zpvp-reports', 'vendor-portal_page_zpvp-settings' ), true ) ) {
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
			remove_all_actions( 'user_admin_notices' );
		}
	}

	public function reorder(): void {
		global $submenu;
		if ( empty( $submenu['zpvp'] ) ) {
			return;
		}
		usort(
			$submenu['zpvp'],
			static function ( $a, $b ) {
				$ia = array_search( $a[2], self::ORDER, true );
				$ib = array_search( $b[2], self::ORDER, true );
				return ( false === $ia ? 99 : $ia ) <=> ( false === $ib ? 99 : $ib );
			}
		);
	}

	private static function editions(): array {
		$posts = get_posts(
			array(
				'post_type'      => Conferences::EDITION,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_key'       => 'start_date',
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
			)
		);
		// Conferences without dates are left out by the meta ordering; add them.
		$ids   = wp_list_pluck( $posts, 'ID' );
		$extra = get_posts( array( 'post_type' => Conferences::EDITION, 'post_status' => 'publish', 'posts_per_page' => -1, 'post__not_in' => $ids ? $ids : array( 0 ) ) );
		$out   = array();
		foreach ( array_merge( $posts, $extra ) as $p ) {
			$status = Conferences::status( (int) $p->ID );
			$out[]  = array(
				'id'     => (int) $p->ID,
				'title'  => $p->post_title,
				'status' => $status,
				'label'  => Conferences::status_label( $status ),
				'dates'  => Conferences::date_range( (int) $p->ID ),
			);
		}
		return $out;
	}

	public function assets( $hook ): void {
		if ( 'toplevel_page_zpvp' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'zpvp-admin-app', ZPVP_URL . 'assets/css/admin-app.css', array(), ZPVP_VERSION );
		wp_enqueue_script( 'zpvp-admin-app', ZPVP_URL . 'assets/js/admin-app.js', array(), ZPVP_VERSION, true );

		$editions = self::editions();
		$default  = 0;
		foreach ( $editions as $e ) {
			if ( in_array( $e['status'], array( 'open', 'sold_out' ), true ) ) {
				$default = $e['id'];
				break;
			}
		}
		$statuses = array();
		foreach ( Registrations::PAYMENT_STATUSES as $k => $v ) {
			$statuses[] = array( 'value' => $k, 'label' => $v );
		}
		wp_localize_script(
			'zpvp-admin-app',
			'ZPVP_APP',
			array(
				'rest'       => esc_url_raw( rest_url( Rest::NS ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'postUrl'    => admin_url( 'admin-post.php' ),
				'printNonce' => wp_create_nonce( 'zpvp_badges_bulk' ),
				'editions'   => $editions,
				'default'    => $default,
				'statuses'   => $statuses,
				'newConf'    => admin_url( 'post-new.php?post_type=' . Conferences::EDITION ),
				'reports'    => admin_url( 'admin.php?page=zpvp-reports' ),
				'exportUrl'  => add_query_arg(
					array(
						'action'   => 'zpvp_export_registrations',
						'_wpnonce' => wp_create_nonce( 'zpvp_export_registrations' ),
					),
					admin_url( 'admin-post.php' )
				),
			)
		);
	}

	public function render(): void {
		?>
		<div class="wrap zpvp-app" id="zpvp-app">
			<div class="zpvp-app__head">
				<h1>Vendor Portal</h1>
				<div class="zpvp-app__head-actions">
					<button type="button" class="button" data-action="compose">Email vendors</button>
					<a class="button" id="zpvp-new-conf" href="#">Add conference</a>
				</div>
			</div>

			<?php Setup::banner(); ?>

			<div class="zpvp-app__bar">
				<label for="zpvp-edition" class="zpvp-app__bar-label">Conference</label>
				<select id="zpvp-edition"></select>
				<span class="zpvp-app__status" id="zpvp-edition-status"></span>
			</div>

			<div class="zpvp-kpis" id="zpvp-kpis" aria-live="polite"></div>

			<div class="zpvp-tabs" role="tablist" aria-label="Workspace">
				<button type="button" role="tab" id="zpvp-tab-registrations" aria-controls="zpvp-panel-registrations" aria-selected="true" data-tab="registrations">Registrations <span class="zpvp-count" data-count="registrations"></span></button>
				<button type="button" role="tab" id="zpvp-tab-badges" aria-controls="zpvp-panel-badges" aria-selected="false" data-tab="badges" tabindex="-1">Badges <span class="zpvp-count" data-count="badges"></span></button>
			</div>

			<section class="zpvp-panel" id="zpvp-panel-registrations" role="tabpanel" aria-labelledby="zpvp-tab-registrations">
				<div class="zpvp-toolbar">
					<input type="search" id="zpvp-search" class="zpvp-search" placeholder="Search company, contact or email" aria-label="Search registrations">
					<div class="zpvp-chips" id="zpvp-pay-chips" role="group" aria-label="Payment filter"></div>
					<a class="button zpvp-export" id="zpvp-export" href="#">Export CSV</a>
				</div>
				<div class="zpvp-table-wrap"><table class="zpvp-grid" id="zpvp-reg-table"></table></div>
				<div class="zpvp-pager" id="zpvp-pager"></div>
			</section>

			<section class="zpvp-panel" id="zpvp-panel-badges" role="tabpanel" aria-labelledby="zpvp-tab-badges" hidden>
				<div class="zpvp-toolbar">
					<div class="zpvp-chips" id="zpvp-badge-chips" role="group" aria-label="Badge filter"></div>
				</div>
				<div class="zpvp-bulkbar" id="zpvp-bulkbar">
					<span id="zpvp-selected"></span>
					<button type="button" class="button button-primary" data-bulk="print">Print</button>
					<button type="button" class="button" data-bulk="mark_printed">Mark printed</button>
					<button type="button" class="button" data-bulk="queued">Send back to print queue</button>
					<button type="button" class="button zpvp-danger" data-bulk="void">Void</button>
				</div>
				<div class="zpvp-table-wrap"><table class="zpvp-grid" id="zpvp-badge-table"></table></div>
			</section>

			<div class="zpvp-drawer" id="zpvp-drawer" hidden>
				<div class="zpvp-drawer__scrim" data-close></div>
				<aside class="zpvp-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="zpvp-drawer-title" tabindex="-1">
					<div class="zpvp-drawer__body" id="zpvp-drawer-body"></div>
				</aside>
			</div>

			<div class="zpvp-modal" id="zpvp-modal" hidden>
				<div class="zpvp-drawer__scrim" data-close></div>
				<div class="zpvp-modal__box" role="dialog" aria-modal="true" aria-labelledby="zpvp-modal-title" tabindex="-1">
					<div class="zpvp-modal__head"><h2 id="zpvp-modal-title">Email vendors</h2><button type="button" class="zpvp-x" data-close aria-label="Close">&times;</button></div>
					<form id="zpvp-compose" class="zpvp-form">
						<p><label for="zpvp-c-edition">Conference</label><select id="zpvp-c-edition" name="edition" required></select></p>
						<p><label for="zpvp-c-aud">Send to</label>
							<select id="zpvp-c-aud" name="audience">
								<option value="all">All registered vendors</option>
								<option value="paid">Paid only</option>
								<option value="unpaid">Payment outstanding only</option>
							</select></p>
						<p><label for="zpvp-c-subject">Subject</label><input id="zpvp-c-subject" name="subject" type="text" required></p>
						<p><label for="zpvp-c-body">Message</label><textarea id="zpvp-c-body" name="body" rows="9" required></textarea>
							<span class="description">Placeholders: {first_name} {company} {conference} {dates} {booth_number} {portal_link}. Each vendor gets a personal copy.</span></p>
						<p class="zpvp-modal__actions">
							<button type="button" class="button" data-send="test">Send a test to me</button>
							<button type="button" class="button button-primary" data-send="send">Send to vendors</button>
						</p>
					</form>
				</div>
			</div>

			<div class="zpvp-toast" id="zpvp-toast" role="status" aria-live="polite"></div>
			<noscript><p>This screen needs JavaScript. You can still use <a href="<?php echo esc_url( admin_url( 'admin.php?page=zpvp-registrations' ) ); ?>">the classic registrations list</a>.</p></noscript>
		</div>
		<?php
	}
}
