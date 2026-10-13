<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Vendor Portal → Registrations list in WP Admin.
 */
final class Registrations_Table extends \WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'registration',
				'plural'   => 'registrations',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'company_name'   => 'Company',
			'conference'     => 'Conference',
			'type'           => 'Type',
			'booths'         => 'Booths',
			'booth_number'   => 'Booth #',
			'attendees'      => 'Personnel',
			'amount'         => 'Amount',
			'payment'        => 'Payment',
			'created_at'     => 'Registered',
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'company_name' => array( 'company_name', false ),
			'created_at'   => array( 'created_at', true ),
			'amount'       => array( 'amount', false ),
		);
	}

	public function prepare_items(): void {
		global $wpdb;
		$table    = Registrations::table();
		$per_page = 50;
		$page     = $this->get_pagenum();

		[ $sql_where, $args ] = Registrations::filter_sql( Registrations::request_filters() );

		$orderby = sanitize_key( $_GET['orderby'] ?? 'created_at' );
		$orderby = in_array( $orderby, array( 'company_name', 'created_at', 'amount' ), true ) ? $orderby : 'created_at';
		$order   = 'asc' === strtolower( sanitize_key( $_GET['order'] ?? 'desc' ) ) ? 'ASC' : 'DESC';

		$count_sql = "SELECT COUNT(*) FROM {$table} {$sql_where}";
		$rows_sql  = "SELECT * FROM {$table} {$sql_where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$total       = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );
		$this->items = (array) $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) ) ) );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->set_pagination_args( array( 'total_items' => $total, 'per_page' => $per_page ) );
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$editions = get_posts(
			array(
				'post_type'      => Conferences::EDITION,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$current_edition = absint( $_GET['edition'] ?? 0 );
		$current_payment = sanitize_key( $_GET['payment'] ?? '' );
		?>
		<div class="alignleft actions">
			<select name="edition">
				<option value="">All conferences</option>
				<?php foreach ( $editions as $e ) : ?>
					<option value="<?php echo esc_attr( (string) $e->ID ); ?>" <?php selected( $current_edition, $e->ID ); ?>><?php echo esc_html( $e->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="payment">
				<option value="">All payment statuses</option>
				<?php foreach ( Registrations::PAYMENT_STATUSES as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current_payment, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( 'Filter', '', 'filter_action', false ); ?>
			<a class="button" href="<?php echo esc_url( $this->export_url() ); ?>">Export CSV</a>
		</div>
		<?php
	}

	private function export_url(): string {
		$filters = Registrations::request_filters();
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'zpvp_export_registrations',
					'edition' => $filters['edition'] ? $filters['edition'] : null,
					'payment' => $filters['payment'] ? $filters['payment'] : null,
					's'       => '' !== $filters['search'] ? $filters['search'] : null,
				),
				admin_url( 'admin-post.php' )
			),
			'zpvp_export_registrations'
		);
	}

	public function column_default( $item, $column_name ) {
		return esc_html( (string) ( $item->$column_name ?? '' ) );
	}

	protected function column_company_name( $item ): string {
		$out = '<strong>' . esc_html( $item->company_name ? $item->company_name : '(no company)' ) . '</strong>';
		$who = array_filter( array( $item->contact_name, $item->contact_email ) );
		if ( $who ) {
			$out .= '<br><span class="description">' . esc_html( implode( ' · ', $who ) ) . '</span>';
		}
		if ( ! $item->user_id ) {
			$out .= '<br><span class="description">No vendor account linked</span>';
		}

		$actions = array(
			'edit'  => sprintf(
				'<a href="%s">Edit</a>',
				esc_url( admin_url( 'admin.php?page=zpvp-registration&id=' . (int) $item->id ) )
			),
			'entry' => sprintf(
				'<a href="%s">View entry</a>',
				esc_url( admin_url( 'admin.php?page=gf_entries&view=entry&id=' . (int) $item->gf_form_id . '&lid=' . (int) $item->gf_entry_id ) )
			),
		);
		$actions['document'] = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( Documents::url( (int) $item->id ) ),
			esc_html( 'receipt' === Documents::kind( $item ) ? 'Receipt PDF' : 'Invoice PDF' )
		);
		if ( in_array( $item->payment_status, array( 'awaiting', 'pending', 'failed' ), true ) ) {
			$actions['mark_paid'] = sprintf(
				'<a href="%s">Mark paid</a>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin-post.php?action=zpvp_mark_paid&id=' . (int) $item->id ),
						'zpvp_mark_paid_' . (int) $item->id
					)
				)
			);
		}
		return $out . $this->row_actions( $actions );
	}

	protected function column_conference( $item ): string {
		$label = Registrations::conference_label( $item );
		if ( ! $item->edition_id ) {
			return esc_html( $label ) . '<br><span class="description">No conference for this year yet</span>';
		}
		return esc_html( $label );
	}

	protected function column_type( $item ): string {
		return Registrations::type_label( (string) $item->type );
	}

	protected function column_booth_number( $item ): string {
		return $item->booth_number ? esc_html( $item->booth_number ) : '—';
	}

	protected function column_attendees( $item ): string {
		return esc_html( (string) Attendees::count( (int) $item->id ) );
	}

	protected function column_amount( $item ): string {
		return esc_html( '$' . number_format( (float) $item->amount, 2 ) );
	}

	protected function column_payment( $item ): string {
		$out = esc_html( Registrations::payment_label( $item->payment_status ) );
		$out .= '<br><span class="description">' . esc_html( Registrations::method_label( $item->payment_method ) );
		if ( $item->po_number ) {
			$out .= ' · PO ' . esc_html( $item->po_number );
		}
		return $out . '</span>';
	}

	protected function column_created_at( $item ): string {
		return esc_html( mysql2date( 'M j, Y g:i a', $item->created_at ) );
	}

	public function no_items(): void {
		echo 'No registrations yet. They appear here as vendors submit conference registration forms.';
	}
}
