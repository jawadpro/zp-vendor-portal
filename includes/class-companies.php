<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Vendor companies, categories and multiple contacts.
 *
 * - A company (post type zp_vendor_company) has one or more contacts: vendor
 *   accounts with user meta zpvp_company_id. One contact is the primary contact.
 * - Every vendor account belongs to a company. New accounts get their own
 *   company; staff add colleagues to it, or merge duplicate companies.
 * - Registrations carry company_id. Every contact at a company sees and manages
 *   the company's registrations (booth staff, documents, badge orders); nobody
 *   else does.
 * - Vendor categories (taxonomy zp_vendor_category) are assigned to companies
 *   and used by reports, the dashboard and vendor emails.
 */
final class Companies {

	public const POST_TYPE = 'zp_vendor_company';
	public const TAXONOMY  = 'zp_vendor_category';
	public const META      = 'zpvp_company_id';
	public const OPT_EMAIL = 'zpvp_company_emails';

	/** Starting categories from the brief; staff can edit them. */
	private const DEFAULT_CATEGORIES = array( 'Road & Bridge', 'Heavy Equipment', 'Software & Technology', 'Financial Services', 'Insurance', 'Consulting & Engineering', 'Public Safety', 'Other' );

	public function register(): void {
		add_action( 'init', array( $this, 'post_type' ), 6 );
		add_action( 'added_user_meta', array( $this, 'company_name_changed' ), 10, 4 );
		add_action( 'updated_user_meta', array( $this, 'company_name_changed' ), 10, 4 );
		add_action( 'zpvp_registration_created', array( $this, 'stamp_registration' ), 5, 1 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'menu' ), 25 );
			add_filter( 'parent_file', array( $this, 'categories_parent' ) );
			add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'meta_boxes' ) );
			add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ), 10, 2 );
			add_action( 'admin_post_zpvp_merge_companies', array( $this, 'merge_action' ) );
			add_action( 'admin_notices', array( $this, 'duplicates_notice' ) );
			add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
			add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
			add_action( 'show_user_profile', array( $this, 'user_profile' ) );
			add_action( 'edit_user_profile', array( $this, 'user_profile' ) );
		}
	}

	public function post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => 'Vendor companies',
					'singular_name' => 'Vendor company',
					'add_new_item'  => 'Add vendor company',
					'edit_item'     => 'Edit vendor company',
					'search_items'  => 'Search companies',
					'not_found'     => 'No vendor companies found.',
					'menu_name'     => 'Vendor companies',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'zpvp',
				'supports'     => array( 'title' ),
				'capability_type' => 'post',
				'capabilities' => array(
					'edit_post'          => Roles::CAP_MANAGE,
					'read_post'          => Roles::CAP_MANAGE,
					'delete_post'        => Roles::CAP_MANAGE,
					'edit_posts'         => Roles::CAP_MANAGE,
					'edit_others_posts'  => Roles::CAP_MANAGE,
					'delete_posts'       => Roles::CAP_MANAGE,
					'publish_posts'      => Roles::CAP_MANAGE,
					'read_private_posts' => Roles::CAP_MANAGE,
					'create_posts'       => Roles::CAP_MANAGE,
					'edit_published_posts'   => Roles::CAP_MANAGE,
					'delete_published_posts' => Roles::CAP_MANAGE,
					'delete_others_posts'    => Roles::CAP_MANAGE,
					'edit_private_posts'     => Roles::CAP_MANAGE,
					'delete_private_posts'   => Roles::CAP_MANAGE,
					'read'                   => 'read',
				),
				'map_meta_cap' => false,
			)
		);
		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => 'Vendor categories',
					'singular_name' => 'Vendor category',
					'menu_name'     => 'Vendor categories',
					'add_new_item'  => 'Add category',
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'hierarchical'      => true,
				'capabilities'      => array(
					'manage_terms' => Roles::CAP_MANAGE,
					'edit_terms'   => Roles::CAP_MANAGE,
					'delete_terms' => Roles::CAP_MANAGE,
					'assign_terms' => Roles::CAP_MANAGE,
				),
			)
		);
	}

	public function menu(): void {
		add_submenu_page( 'zpvp', 'Vendor categories', 'Vendor categories', Roles::CAP_MANAGE, 'edit-tags.php?taxonomy=' . self::TAXONOMY . '&post_type=' . self::POST_TYPE );
	}

	/** Keep the Vendor Portal menu open on the categories screen. */
	public function categories_parent( $parent_file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return ( $screen && self::TAXONOMY === $screen->taxonomy ) ? 'zpvp' : $parent_file;
	}

	/* ------------------------------------------------------------------ */
	/* Install and migration                                               */
	/* ------------------------------------------------------------------ */

	/** Give every vendor a company and stamp registrations with it. Safe to re-run. */
	public static function install(): void {
		global $wpdb;
		( new self() )->post_type();
		if ( ! get_option( 'zpvp_categories_seeded' ) ) {
			foreach ( self::DEFAULT_CATEGORIES as $name ) {
				if ( ! term_exists( $name, self::TAXONOMY ) ) {
					wp_insert_term( $name, self::TAXONOMY );
				}
			}
			update_option( 'zpvp_categories_seeded', 1, false );
		}
		foreach ( get_users( array( 'role' => Roles::VENDOR, 'fields' => 'ID', 'number' => 5000 ) ) as $uid ) {
			self::ensure_company( (int) $uid );
		}
		// Registrations recorded before companies existed.
		$rows = (array) $wpdb->get_results( 'SELECT id, user_id FROM ' . Registrations::table() . ' WHERE company_id = 0 AND user_id > 0' );
		foreach ( $rows as $r ) {
			$cid = self::company_for_user( (int) $r->user_id );
			if ( $cid ) {
				$wpdb->update( Registrations::table(), array( 'company_id' => $cid ), array( 'id' => (int) $r->id ) );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Lookups                                                             */
	/* ------------------------------------------------------------------ */

	public static function company_for_user( int $user_id ): int {
		if ( ! $user_id ) {
			return 0;
		}
		$cid = (int) get_user_meta( $user_id, self::META, true );
		if ( $cid && self::POST_TYPE === get_post_type( $cid ) && 'trash' !== get_post_status( $cid ) ) {
			return $cid;
		}
		return self::ensure_company( $user_id );
	}

	/** The vendor's company, created from their company name if they don't have one. */
	public static function ensure_company( int $user_id ): int {
		$user = get_userdata( $user_id );
		if ( ! $user || ! Roles::is_vendor( $user ) ) {
			return 0;
		}
		$cid = (int) get_user_meta( $user_id, self::META, true );
		if ( $cid && self::POST_TYPE === get_post_type( $cid ) && 'trash' !== get_post_status( $cid ) ) {
			return $cid;
		}
		$name = trim( (string) get_user_meta( $user_id, 'zpvp_company_name', true ) );
		$cid  = (int) wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => '' !== $name ? $name : $user->display_name,
			)
		);
		if ( $cid ) {
			update_post_meta( $cid, 'primary_contact', $user_id );
			update_user_meta( $user_id, self::META, $cid );
		}
		return $cid;
	}

	/** @return \WP_User[] */
	public static function contacts( int $company_id ): array {
		if ( ! $company_id ) {
			return array();
		}
		return get_users(
			array(
				'meta_key'   => self::META,
				'meta_value' => $company_id,
				'orderby'    => 'display_name',
				'number'     => 200,
			)
		);
	}

	public static function primary_contact( int $company_id ): int {
		return (int) get_post_meta( $company_id, 'primary_contact', true );
	}

	/** Company name to show for a vendor: their company's name, else what they typed. */
	public static function company_name_for_user( int $user_id ): string {
		$cid = (int) get_user_meta( $user_id, self::META, true );
		if ( $cid && get_post( $cid ) ) {
			return (string) get_post_field( 'post_title', $cid );
		}
		return (string) get_user_meta( $user_id, 'zpvp_company_name', true );
	}

	/** @return int[] term IDs */
	public static function categories( int $company_id ): array {
		$terms = $company_id ? wp_get_object_terms( $company_id, self::TAXONOMY, array( 'fields' => 'ids' ) ) : array();
		return is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );
	}

	public static function category_names( int $company_id ): array {
		$terms = $company_id ? wp_get_object_terms( $company_id, self::TAXONOMY, array( 'fields' => 'names' ) ) : array();
		return is_wp_error( $terms ) ? array() : array_map( static fn( $n ) => html_entity_decode( $n, ENT_QUOTES ), $terms );
	}

	/** @return array<int, string> term ID => name */
	public static function all_categories(): array {
		$terms = get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ) );
		$out   = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			$out[ (int) $t->term_id ] = html_entity_decode( $t->name, ENT_QUOTES );
		}
		return $out;
	}

	/** Companies in any of the given categories (child categories included). */
	public static function in_categories( array $term_ids ): array {
		$term_ids = array_filter( array_map( 'intval', $term_ids ) );
		if ( ! $term_ids ) {
			return array();
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'tax_query'      => array( array( 'taxonomy' => self::TAXONOMY, 'terms' => $term_ids, 'include_children' => true ) ),
				)
			)
		);
	}

	public static function normalize( string $name ): string {
		$name = strtolower( html_entity_decode( $name, ENT_QUOTES ) );
		$name = preg_replace( '/[^a-z0-9 ]+/', ' ', $name );
		$name = preg_replace( '/\b(inc|llc|ltd|co|corp|corporation|company|the|lp|llp|pllc)\b/', ' ', (string) $name );
		return trim( preg_replace( '/\s+/', ' ', (string) $name ) );
	}

	/* ------------------------------------------------------------------ */
	/* Access rules                                                        */
	/* ------------------------------------------------------------------ */

	/** A vendor can see and manage a registration they made, or one made by their company. */
	public static function user_can_access( int $user_id, object $reg ): bool {
		if ( ! $user_id || 'active' !== ( $reg->status ?? 'active' ) ) {
			return false;
		}
		if ( (int) $reg->user_id === $user_id ) {
			return true;
		}
		$cid = (int) get_user_meta( $user_id, self::META, true );
		return $cid > 0 && (int) ( $reg->company_id ?? 0 ) === $cid;
	}

	/** Active registrations a vendor can see: their own and their company's. */
	public static function registrations_for_user( int $user_id ): array {
		global $wpdb;
		if ( ! $user_id ) {
			return array();
		}
		$cid = self::company_for_user( $user_id );
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Registrations::table() . " WHERE status = 'active' AND ( user_id = %d OR ( company_id > 0 AND company_id = %d ) ) ORDER BY year DESC, created_at DESC",
				$user_id,
				$cid
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Keeping things in step                                              */
	/* ------------------------------------------------------------------ */

	/** New registrations belong to the vendor's company. */
	public function stamp_registration( $registration_id ): void {
		global $wpdb;
		$reg = Registrations::get( (int) $registration_id );
		if ( $reg && $reg->user_id && ! (int) $reg->company_id ) {
			$cid = self::company_for_user( (int) $reg->user_id );
			if ( $cid ) {
				$wpdb->update( Registrations::table(), array( 'company_id' => $cid ), array( 'id' => (int) $reg->id ) );
			}
		}
	}

	/**
	 * When a vendor's company name is set (sign-up or Account page): create
	 * their company, or rename it if they are its only contact.
	 */
	public function company_name_changed( $meta_id, $user_id, $meta_key, $value ): void {
		if ( 'zpvp_company_name' !== $meta_key || '' === trim( (string) $value ) ) {
			return;
		}
		$user_id = (int) $user_id;
		$cid     = (int) get_user_meta( $user_id, self::META, true );
		if ( ! $cid || ! get_post( $cid ) ) {
			self::ensure_company( $user_id );
			return;
		}
		$contacts = self::contacts( $cid );
		if ( 1 === count( $contacts ) && (int) $contacts[0]->ID === $user_id && get_post_field( 'post_title', $cid ) !== (string) $value ) {
			wp_update_post( array( 'ID' => $cid, 'post_title' => sanitize_text_field( (string) $value ) ) );
		}
	}

	/** Add a vendor to a company (their registrations come along). */
	public static function add_contact( int $company_id, int $user_id ): void {
		global $wpdb;
		$old = (int) get_user_meta( $user_id, self::META, true );
		update_user_meta( $user_id, self::META, $company_id );
		$wpdb->update( Registrations::table(), array( 'company_id' => $company_id ), array( 'user_id' => $user_id ) );
		if ( ! self::primary_contact( $company_id ) ) {
			update_post_meta( $company_id, 'primary_contact', $user_id );
		}
		if ( $old && $old !== $company_id && ! self::contacts( $old ) ) {
			wp_trash_post( $old ); // The vendor's old company has nobody left.
		}
	}

	/** Remove a contact: they get a company of their own again, with the registrations they made. */
	public static function remove_contact( int $company_id, int $user_id ): void {
		global $wpdb;
		delete_user_meta( $user_id, self::META );
		$new = self::ensure_company( $user_id );
		$wpdb->update( Registrations::table(), array( 'company_id' => $new ), array( 'user_id' => $user_id, 'company_id' => $company_id ) );
		if ( self::primary_contact( $company_id ) === $user_id ) {
			$left = self::contacts( $company_id );
			update_post_meta( $company_id, 'primary_contact', $left ? (int) $left[0]->ID : 0 );
		}
	}

	/** Move every contact, registration and category of $from into $into, then trash $from. */
	public static function merge( int $from, int $into ): void {
		global $wpdb;
		if ( ! $from || ! $into || $from === $into ) {
			return;
		}
		foreach ( self::contacts( $from ) as $u ) {
			update_user_meta( (int) $u->ID, self::META, $into );
		}
		$wpdb->update( Registrations::table(), array( 'company_id' => $into ), array( 'company_id' => $from ) );
		wp_set_object_terms( $into, array_unique( array_merge( self::categories( $into ), self::categories( $from ) ) ), self::TAXONOMY );
		if ( ! self::primary_contact( $into ) ) {
			update_post_meta( $into, 'primary_contact', self::primary_contact( $from ) );
		}
		wp_trash_post( $from );
	}

	/* ------------------------------------------------------------------ */
	/* Staff screens                                                       */
	/* ------------------------------------------------------------------ */

	public function columns( array $cols ): array {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['zpvp_contacts']      = 'Contacts';
				$out['zpvp_registrations'] = 'Registrations';
			}
		}
		unset( $out['date'] );
		return $out;
	}

	public function column( string $col, int $id ): void {
		global $wpdb;
		if ( 'zpvp_contacts' === $col ) {
			$primary = self::primary_contact( $id );
			echo esc_html(
				implode(
					', ',
					array_map( static fn( $u ) => $u->display_name . ( (int) $u->ID === $primary ? ' (primary)' : '' ), self::contacts( $id ) )
				)
			);
		} elseif ( 'zpvp_registrations' === $col ) {
			echo (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Registrations::table() . " WHERE company_id = %d AND status = 'active'", $id ) );
		}
	}

	public function meta_boxes(): void {
		add_meta_box( 'zpvp-contacts', 'Contacts', array( $this, 'contacts_box' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'zpvp-company-regs', 'Registrations', array( $this, 'registrations_box' ), self::POST_TYPE, 'normal' );
		add_meta_box( 'zpvp-merge', 'Merge', array( $this, 'merge_box' ), self::POST_TYPE, 'side', 'low' );
	}

	public function contacts_box( $post ): void {
		$contacts = self::contacts( (int) $post->ID );
		$primary  = self::primary_contact( (int) $post->ID );
		wp_nonce_field( 'zpvp_company_' . $post->ID, '_zpvp_company' );
		?>
		<p>Every contact can log in to the vendor portal and see and manage this company's registrations, booth staff, invoices and badge orders. Conference emails go to every contact unless that is switched off in Settings → Emails.</p>
		<table class="widefat striped">
			<thead><tr><th>Name</th><th>Email</th><th>Primary contact</th><th>Remove</th></tr></thead>
			<tbody>
			<?php if ( ! $contacts ) : ?><tr><td colspan="4">No contacts yet.</td></tr><?php endif; ?>
			<?php foreach ( $contacts as $u ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( get_edit_user_link( (int) $u->ID ) ); ?>"><?php echo esc_html( $u->display_name ); ?></a></td>
					<td><?php echo esc_html( $u->user_email ); ?></td>
					<td><input type="radio" name="zpvp_primary" value="<?php echo (int) $u->ID; ?>" <?php checked( $primary, (int) $u->ID ); ?> aria-label="Primary contact"></td>
					<td><input type="checkbox" name="zpvp_remove[]" value="<?php echo (int) $u->ID; ?>" aria-label="Remove <?php echo esc_attr( $u->display_name ); ?>"></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><label for="zpvp-add-contact"><strong>Add a contact</strong></label><br>
		<input id="zpvp-add-contact" type="email" name="zpvp_add_contact" class="regular-text" placeholder="colleague@company.com">
		<span class="description">The email of a vendor account. Their registrations move to this company. If they don't have an account yet, ask them to create one at the vendor registration page first.</span></p>
		<?php
	}

	public function registrations_box( $post ): void {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Registrations::table() . " WHERE company_id = %d AND status = 'active' ORDER BY year DESC, created_at DESC LIMIT 50", (int) $post->ID ) );
		if ( ! $rows ) {
			echo '<p>No registrations yet.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Conference</th><th>Registered by</th><th>Booths</th><th>Payment</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$by = (string) $r->contact_name;
			if ( '' === $by && $r->user_id ) {
				$u  = get_userdata( (int) $r->user_id );
				$by = $u ? $u->display_name : '';
			}
			echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=zpvp-registration&id=' . (int) $r->id ) ) . '">' . esc_html( Registrations::conference_label( $r ) ) . '</a></td><td>' . esc_html( '' !== $by ? $by : (string) $r->contact_email ) . '</td><td>' . esc_html( Registrations::summary( $r ) ) . '</td><td>' . esc_html( Registrations::payment_label( $r->payment_status ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public function merge_box( $post ): void {
		?>
		<p>Duplicate of another company? Move this company's contacts, registrations and categories into it.</p>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Merge this company into the one chosen? This cannot be undone.');">
			<input type="hidden" name="action" value="zpvp_merge_companies">
			<input type="hidden" name="from" value="<?php echo (int) $post->ID; ?>">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'zpvp_merge_' . (int) $post->ID ) ); ?>">
			<select name="into" style="max-width:100%">
				<option value="">Choose a company…</option>
				<?php foreach ( get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'exclude' => array( (int) $post->ID ) ) ) as $c ) : ?>
					<option value="<?php echo (int) $c->ID; ?>"><?php echo esc_html( $c->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
			<p><button class="button">Merge</button></p>
		</form>
		<?php
	}

	public function save( $post_id, $post ): void {
		if ( ! isset( $_POST['_zpvp_company'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_zpvp_company'] ) ), 'zpvp_company_' . $post_id ) || ! current_user_can( Roles::CAP_MANAGE ) ) {
			return;
		}
		$post_id = (int) $post_id;
		foreach ( array_map( 'absint', (array) ( $_POST['zpvp_remove'] ?? array() ) ) as $uid ) {
			if ( (int) get_user_meta( $uid, self::META, true ) === $post_id ) {
				self::remove_contact( $post_id, $uid );
			}
		}
		$email = sanitize_email( wp_unslash( $_POST['zpvp_add_contact'] ?? '' ) );
		if ( $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user && Roles::is_vendor( $user ) ) {
				self::add_contact( $post_id, (int) $user->ID );
			} else {
				set_transient( 'zpvp_company_notice_' . get_current_user_id(), sprintf( 'No vendor account uses %s, so no contact was added. Ask them to create a vendor account first.', $email ), 60 );
			}
		}
		$primary = absint( $_POST['zpvp_primary'] ?? 0 );
		if ( $primary && (int) get_user_meta( $primary, self::META, true ) === $post_id ) {
			update_post_meta( $post_id, 'primary_contact', $primary );
		}
	}

	public function merge_action(): void {
		$from = absint( $_GET['from'] ?? 0 );
		$into = absint( $_GET['into'] ?? 0 );
		if ( ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_merge_' . $from );
		if ( $into > 0 && self::POST_TYPE === get_post_type( $into ) && self::POST_TYPE === get_post_type( $from ) ) {
			self::merge( $from, $into );
			wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $into ) );
			exit;
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) );
		exit;
	}

	/** Groups of companies whose names only differ by punctuation or "Inc", "LLC" and so on. */
	public static function duplicates(): array {
		$groups = array();
		foreach ( get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $p ) {
			$key = self::normalize( $p->post_title );
			if ( '' !== $key ) {
				$groups[ $key ][] = $p;
			}
		}
		return array_values( array_filter( $groups, static fn( $g ) => count( $g ) > 1 ) );
	}

	public function duplicates_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( Roles::CAP_MANAGE ) ) {
			return;
		}
		$note = get_transient( 'zpvp_company_notice_' . get_current_user_id() );
		if ( $note && self::POST_TYPE === $screen->post_type ) {
			delete_transient( 'zpvp_company_notice_' . get_current_user_id() );
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $note ) . '</p></div>';
		}
		if ( 'edit-' . self::POST_TYPE !== $screen->id ) {
			return;
		}
		$groups = self::duplicates();
		if ( ! $groups ) {
			return;
		}
		echo '<div class="notice notice-info"><p><strong>Possible duplicate companies.</strong> These have the same name apart from punctuation or "Inc", "LLC" and similar. Merging puts their contacts together, so they all see each other\'s registrations. Only merge companies that really are the same business.</p><ul>';
		foreach ( array_slice( $groups, 0, 20 ) as $g ) {
			$keep  = $g[0];
			$links = array();
			foreach ( array_slice( $g, 1 ) as $dup ) {
				$url     = wp_nonce_url( admin_url( 'admin-post.php?action=zpvp_merge_companies&from=' . (int) $dup->ID . '&into=' . (int) $keep->ID ), 'zpvp_merge_' . (int) $dup->ID );
				$links[] = '<a href="' . esc_url( $url ) . '" onclick="return confirm(\'Merge these companies?\')">' . esc_html( sprintf( 'merge "%s" into it', $dup->post_title ) ) . '</a>';
			}
			echo '<li>' . esc_html( $keep->post_title ) . ' (' . count( self::contacts( (int) $keep->ID ) ) . ' contact(s)): ' . implode( ', ', $links ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		}
		echo '</ul></div>';
	}

	/** Company link on the WordPress user screen. */
	public function user_profile( $user ): void {
		if ( ! Roles::is_vendor( $user ) || ! current_user_can( Roles::CAP_MANAGE ) ) {
			return;
		}
		$cid = (int) get_user_meta( $user->ID, self::META, true );
		echo '<h2>Vendor company</h2><p>';
		echo $cid && get_post( $cid ) ? '<a href="' . esc_url( get_edit_post_link( $cid ) ) . '">' . esc_html( get_post_field( 'post_title', $cid ) ) . '</a>' : 'None yet.';
		echo '</p>';
	}

	/* ------------------------------------------------------------------ */
	/* Emails                                                              */
	/* ------------------------------------------------------------------ */

	/** Send conference emails to every contact at the company (default) or only the person who registered. */
	public static function email_all_contacts(): bool {
		return (bool) get_option( self::OPT_EMAIL, 1 );
	}

	/** @return string[] */
	public static function contact_emails( int $company_id ): array {
		return array_map( static fn( $u ) => (string) $u->user_email, self::contacts( $company_id ) );
	}
}
