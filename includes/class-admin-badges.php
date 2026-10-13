<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Vendor Portal → Badges: print queue, batch printing and reprints.
 */
final class Admin_Badges {

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_zpvp_badges_bulk', array( $this, 'bulk' ) );
		add_action( 'admin_post_zpvp_badges_mark_printed', array( $this, 'mark_printed' ) );
	}

	public function menu(): void {
		add_submenu_page( '', 'Badges', 'Badges', Roles::CAP_MANAGE, 'zpvp-badges', array( $this, 'render' ) );
	}

	private function filters(): array {
		return array(
			'edition'       => absint( $_GET['edition'] ?? 0 ),
			'status'        => sanitize_key( $_GET['status'] ?? 'queued' ),
			'needs_payment' => ! empty( $_GET['needs_payment'] ),
		);
	}

	public function render(): void {
		$f        = $this->filters();
		$badges   = Badges::query( $f );
		$editions = get_posts( array( 'post_type' => Conferences::EDITION, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Badges</h1>
			<?php if ( isset( $_GET['zpvp_done'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( '%d badge(s) updated.', absint( $_GET['zpvp_done'] ) ) ); ?></p></div>
			<?php endif; ?>

			<form method="get" style="margin:12px 0">
				<input type="hidden" name="page" value="zpvp-badges">
				<select name="edition">
					<option value="">All conferences</option>
					<?php foreach ( $editions as $e ) : ?>
						<option value="<?php echo (int) $e->ID; ?>" <?php selected( $f['edition'], $e->ID ); ?>><?php echo esc_html( $e->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="status">
					<?php foreach ( array( 'queued' => 'Ready to print', 'printed' => 'Printed', 'void' => 'Void', 'all' => 'All statuses' ) as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['status'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
				<label><input type="checkbox" name="needs_payment" value="1" <?php checked( $f['needs_payment'] ); ?>> Needs payment only</label>
				<?php submit_button( 'Filter', '', '', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_self">
				<input type="hidden" name="action" value="zpvp_badges_bulk">
				<?php wp_nonce_field( 'zpvp_badges_bulk' ); ?>
				<p>
					<select name="bulk">
						<option value="print">Print selected</option>
						<option value="mark_printed">Mark selected as printed</option>
						<option value="queued">Send selected back to the print queue</option>
						<option value="void">Void selected</option>
					</select>
					<?php submit_button( 'Apply', 'primary', '', false ); ?>
					<span class="description" style="margin-left:8px"><?php echo esc_html( count( $badges ) . ' badge(s) shown.' ); ?></span>
				</p>
				<table class="widefat striped">
					<thead><tr>
						<td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.zpvp-badge-cb').forEach(c=>c.checked=this.checked)"></td>
						<th>Name</th><th>Title</th><th>Company</th><th>Conference</th><th>Type</th><th>Status</th><th>Printed</th><th>Payment</th>
					</tr></thead>
					<tbody>
					<?php if ( ! $badges ) : ?>
						<tr><td colspan="9">No badges match these filters.</td></tr>
					<?php endif; ?>
					<?php foreach ( $badges as $b ) : ?>
						<tr>
							<th class="check-column"><input type="checkbox" class="zpvp-badge-cb" name="ids[]" value="<?php echo (int) $b->id; ?>"></th>
							<td><strong><?php echo esc_html( $b->name ); ?></strong><br><a href="<?php echo esc_url( admin_url( 'admin.php?page=zpvp-registration&id=' . (int) $b->registration_id . '#attendees' ) ); ?>">Registration</a></td>
							<td><?php echo esc_html( $b->title ); ?></td>
							<td><?php echo esc_html( $b->company ); ?></td>
							<td><?php echo esc_html( $b->edition_id ? get_the_title( (int) $b->edition_id ) : '—' ); ?></td>
							<td><?php echo esc_html( Badges::TYPES[ $b->type ] ?? $b->type ); ?></td>
							<td><?php echo esc_html( Badges::STATUSES[ $b->status ] ?? $b->status ); ?><?php echo ( 'queued' === $b->status && (int) $b->print_count > 0 ) ? '<br><span class="description">Reprint</span>' : ''; ?></td>
							<td><?php echo esc_html( $b->printed_at ? mysql2date( 'M j, g:i a', $b->printed_at ) . ' (' . (int) $b->print_count . '×)' : '—' ); ?></td>
							<td><?php echo ( (int) $b->needs_payment && 'void' !== $b->status ) ? '<strong style="color:#b42318">Needs payment</strong>' : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</form>

			<?php if ( $f['edition'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px">
					<input type="hidden" name="action" value="zpvp_badges_bulk">
					<input type="hidden" name="bulk" value="print_all_queued">
					<input type="hidden" name="edition" value="<?php echo (int) $f['edition']; ?>">
					<?php wp_nonce_field( 'zpvp_badges_bulk' ); ?>
					<?php submit_button( 'Print all badges ready to print for this conference', 'secondary', '', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public function bulk(): void {
		if ( ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_badges_bulk' );
		$action = sanitize_key( $_POST['bulk'] ?? '' );
		$ids    = array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) );

		if ( 'print_all_queued' === $action ) {
			$ids    = wp_list_pluck( Badges::query( array( 'edition' => absint( $_POST['edition'] ?? 0 ), 'status' => 'queued' ) ), 'id' );
			$action = 'print';
		}
		if ( 'print' === $action ) {
			$this->print_view( $ids );
			exit;
		}

		$done = 0;
		if ( 'mark_printed' === $action ) {
			$done = Badges::mark_printed( $ids );
		} elseif ( in_array( $action, array( 'queued', 'void' ), true ) ) {
			$done = Badges::set_status( $ids, $action );
		}
		wp_safe_redirect( add_query_arg( 'zpvp_done', $done, wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=zpvp-badges' ) ) );
		exit;
	}

	public function mark_printed(): void {
		if ( ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_badges_mark_printed' );
		$done = Badges::mark_printed( array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ) );
		wp_safe_redirect( admin_url( 'admin.php?page=zpvp&tab=badges&zpvp_done=' . $done ) );
		exit;
	}

	/** Printable sheet for Avery 5392: 4" x 3" badges, six per US Letter page. */
	private function print_view( array $ids ): void {
		$badges = $ids ? Badges::query( array( 'ids' => $ids, 'status' => 'all' ) ) : array();
		$badges = array_values( array_filter( $badges, static fn( $b ) => 'void' !== $b->status ) );
		nocache_headers();
		?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Badges</title>
<style>
	/* Avery 5392: 4" x 3" name badges, 6 per US Letter sheet (2 across, 3 down),
	   1" top and bottom margin, 0.25" side margin, no gap between badges. */
	@page { size: 8.5in 11in; margin: 0; }
	:root { --dx: 0in; --dy: 0in; }
	html, body { margin: 0; padding: 0; }
	body { font-family: Arial, Helvetica, sans-serif; color: #111; background: #e5e5e5; }
	.toolbar { padding: 12px 16px; background: #f0f0f1; border-bottom: 1px solid #ccc; font-size: 14px; line-height: 2; }
	.toolbar button { font-size: 14px; padding: 6px 12px; margin-right: 8px; cursor: pointer; }
	.toolbar label { margin-right: 12px; white-space: nowrap; }
	.toolbar input[type=number] { width: 70px; }
	.sheet { box-sizing: border-box; width: 8.5in; height: 11in; margin: 16px auto; background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.25);
		padding: calc(1in + var(--dy)) 0 0 calc(0.25in + var(--dx)); display: grid; grid-template-columns: 4in 4in; grid-template-rows: repeat(3, 3in);
		overflow: hidden; page-break-after: always; break-after: page; }
	.sheet:last-child { page-break-after: auto; break-after: auto; }
	.badge { box-sizing: border-box; width: 4in; height: 3in; outline: 1px dashed #bbb; outline-offset: -1px; padding: 0.3in 0.3in; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; overflow: hidden; }
	.badge .conf { font-size: 9pt; text-transform: uppercase; letter-spacing: 0.05em; color: #444; line-height: 1.2; max-height: 2.4em; overflow: hidden; }
	.badge .name { font-size: 26pt; font-weight: 700; line-height: 1.1; margin: 0.12in 0 0.04in; white-space: nowrap; }
	.badge .title { font-size: 12pt; line-height: 1.2; }
	.badge .company { font-size: 14pt; font-weight: 600; margin-top: 0.06in; line-height: 1.2; }
	.badge .role { margin-top: 0.12in; font-size: 10pt; font-weight: 700; letter-spacing: 0.12em; }
	body.no-outlines .badge { outline: none; }
	@media print {
		body { background: #fff; }
		.toolbar { display: none; }
		.sheet { margin: 0; box-shadow: none; }
	}
</style></head><body>
<div class="toolbar">
	<button onclick="window.print()">Print <?php echo count( $badges ); ?> badge(s)</button>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
		<input type="hidden" name="action" value="zpvp_badges_mark_printed">
		<?php wp_nonce_field( 'zpvp_badges_mark_printed' ); ?>
		<?php foreach ( $badges as $b ) : ?><input type="hidden" name="ids[]" value="<?php echo (int) $b->id; ?>"><?php endforeach; ?>
		<button type="submit">Done — mark these as printed</button>
	</form>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=zpvp&tab=badges' ) ); ?>">Back without marking</a>
	<br>
	<strong>Avery 5392</strong> (4" &times; 3", 6 per sheet). In the print dialog choose <strong>Margins: None</strong> and <strong>Scale: 100%</strong> (not "Fit to page").
	<br>
	<label><input type="checkbox" id="zpvp-outlines"> Print badge outlines (test on plain paper first)</label>
	<label>Move right <input type="number" id="zpvp-dx" step="0.01" value="0"> in</label>
	<label>Move down <input type="number" id="zpvp-dy" step="0.01" value="0"> in</label>
	<span style="color:#555">Use a negative number to move left or up. Your adjustment is remembered on this computer.</span>
</div>
<script>
(function () {
	var root = document.documentElement, body = document.body;
	function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
	function set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
	var dx = document.getElementById('zpvp-dx'), dy = document.getElementById('zpvp-dy'), ol = document.getElementById('zpvp-outlines');
	dx.value = parseFloat(get('zpvp_badge_dx')) || 0;
	dy.value = parseFloat(get('zpvp_badge_dy')) || 0;
	ol.checked = get('zpvp_badge_outlines') === '1';
	function apply() {
		var x = Math.max(-0.5, Math.min(0.5, parseFloat(dx.value) || 0)), y = Math.max(-0.5, Math.min(0.5, parseFloat(dy.value) || 0));
		root.style.setProperty('--dx', x + 'in'); root.style.setProperty('--dy', y + 'in');
		body.classList.toggle('no-outlines', !ol.checked);
		set('zpvp_badge_dx', x); set('zpvp_badge_dy', y); set('zpvp_badge_outlines', ol.checked ? '1' : '0');
	}
	[dx, dy, ol].forEach(function (el) { el.addEventListener('input', apply); el.addEventListener('change', apply); });
	apply();
	// Shrink long names and companies so they stay on the badge (after the sheets load).
	document.addEventListener('DOMContentLoaded', function () { document.querySelectorAll('.badge').forEach(function (b) {
		[['.name', 14], ['.company', 10], ['.title', 9]].forEach(function (p) {
			var el = b.querySelector(p[0]); if (!el) { return; }
			var size = parseFloat(getComputedStyle(el).fontSize), max = b.clientWidth - 0.6 * 96;
			while (el.scrollWidth > max && size > p[1]) { size -= 1; el.style.fontSize = size + 'px'; }
		});
	}); });
})();
</script>
<?php foreach ( array_chunk( $badges, 6 ) as $page ) : ?>
	<div class="sheet">
	<?php foreach ( $page as $b ) : ?>
		<div class="badge">
			<div class="conf"><?php echo esc_html( $b->edition_id ? get_the_title( (int) $b->edition_id ) : '' ); ?></div>
			<div class="name"><?php echo esc_html( $b->name ); ?></div>
			<?php if ( $b->title ) : ?><div class="title"><?php echo esc_html( $b->title ); ?></div><?php endif; ?>
			<div class="company"><?php echo esc_html( $b->company ); ?></div>
			<div class="role">EXHIBITOR</div>
		</div>
	<?php endforeach; ?>
	</div>
<?php endforeach; ?>
<?php if ( ! $badges ) : ?><p style="padding:16px">No badges selected.</p><?php endif; ?>
</body></html>
		<?php
	}
}
