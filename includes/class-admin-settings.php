<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Vendor Portal → Settings: tabs for Overview (setup checklist), Invoices &
 * receipts, Emails, Registration forms, Menu link and Email log. Saving reuses the
 * existing handlers (zpvp_save_settings, zpvp_email_settings); forms are connected on Setup's screen.
 */
final class Admin_Settings {

	public const SCREEN = 'vendor-portal_page_zpvp-settings';

	private const TABS = array(
		'overview' => 'Overview',
		'invoices' => 'Invoices & receipts',
		'emails'   => 'Emails',
		'series'   => 'Conference series',
		'menu'     => 'Menu link',
		'log'      => 'Email log',
	);

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public static function url( string $tab = 'overview', array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => 'zpvp-settings', 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public function assets( $hook ): void {
		if ( self::SCREEN === $hook ) {
			wp_enqueue_style( 'zpvp-admin-settings', ZPVP_URL . 'assets/css/admin-settings.css', array(), ZPVP_VERSION );
		}
	}

	private static function current_tab(): string {
		$tab = sanitize_key( $_GET['tab'] ?? 'overview' );
		if ( 'forms' === $tab ) {
			$tab = 'series'; // Old links.
		}
		return isset( self::TABS[ $tab ] ) ? $tab : 'overview';
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	public function render(): void {
		$tab = self::current_tab();
		?>
		<div class="wrap zpvp-settings">
			<header class="zpvp-settings__head">
				<h1>Vendor Portal settings</h1>
				<p>Everything the portal needs to run: invoice details, automatic emails and the conferences it knows about.</p>
			</header>

			<?php $this->flash(); ?>
			<?php Setup::banner(); ?>

			<nav class="zpvp-settings__tabs" aria-label="Settings sections">
				<?php foreach ( self::TABS as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="<?php echo $key === $tab ? 'is-active' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="zpvp-settings__body">
				<?php
				switch ( $tab ) {
					case 'invoices':
						$this->tab_invoices();
						break;
					case 'emails':
						$this->tab_emails();
						break;
					case 'series':
						$this->tab_series();
						break;
					case 'menu':
						$this->tab_menu();
						break;
					case 'log':
						$this->tab_log();
						break;
					default:
						$this->tab_overview();
				}
				?>
			</div>
		</div>
		<?php
	}

	/** Confirmation messages from the save handlers. */
	private function flash(): void {
		$msg = '';
		if ( isset( $_GET['zpvp_settings_saved'] ) ) {
			$msg = 'Invoice settings saved.';
		} elseif ( isset( $_GET['zpvp_msg'] ) ) {
			$msg = sanitize_text_field( wp_unslash( $_GET['zpvp_msg'] ) );
		}
		if ( $msg ) {
			echo '<div class="zpvp-flash" role="status">' . esc_html( $msg ) . '</div>';
		}
	}

	private static function card_open( string $title, string $desc ): void {
		echo '<section class="zpvp-card"><div class="zpvp-card__intro"><h2>' . esc_html( $title ) . '</h2><p>' . wp_kses( $desc, array( 'a' => array( 'href' => array() ), 'strong' => array(), 'code' => array() ) ) . '</p></div><div class="zpvp-card__fields">';
	}

	private static function card_close(): void {
		echo '</div></section>';
	}

	/* ------------------------------------------------------------------ */
	/* Overview                                                            */
	/* ------------------------------------------------------------------ */

	/** @return array<int, array{ok:bool|null, title:string, detail:string, link?:string, label?:string}> */
	private function checklist(): array {
		$items   = array();
		$issuer  = (string) get_option( Documents::OPT_ISSUER, '' );
		$instr   = (string) get_option( Documents::OPT_INSTRUCTIONS, '' );
		$mail    = Mailer::settings();
		$linked  = Conferences::has_links();
		$open    = count( Conferences::current_editions() );
		$form_id = Settings::registration_form_id();
		$form    = ( $form_id && class_exists( '\GFAPI' ) ) ? \GFAPI::get_form( $form_id ) : null;

		$missing = Setup::missing_requirements();
		$items[] = array(
			'ok'     => ! $missing,
			'title'  => 'Required plugins',
			'detail' => $missing ? implode( ' ', $missing ) : 'Gravity Forms, the User Registration add-on and ACF PRO are active.',
			'link'   => admin_url( 'plugins.php' ),
			'label'  => 'Plugins',
		);

		$pages_ok = Settings::page_id( 'register' ) && Settings::page_id( 'login' ) && Settings::page_id( 'dashboard' );
		$items[]  = array(
			'ok'     => $pages_ok,
			'title'  => 'Portal pages',
			'detail' => $pages_ok ? 'Vendor Registration, Vendor Login and My Conferences are published.' : 'One or more portal pages are missing. Deactivate and reactivate the plugin to recreate them.',
		);
		$items[]  = array(
			'ok'     => $form && ! empty( $form['is_active'] ),
			'title'  => 'Vendor account form',
			'detail' => $form ? ( ! empty( $form['is_active'] ) ? 'Form ' . $form_id . ' creates vendor accounts.' : 'Form ' . $form_id . ' is switched off in Gravity Forms, so vendors cannot create accounts.' ) : 'No vendor registration form is set.',
			'link'   => admin_url( 'admin.php?page=gf_edit_forms' ),
			'label'  => 'Open Forms',
		);
		$items[]  = array(
			'ok'     => $linked,
			'title'  => 'Conference series',
			'detail' => $linked ? 'Conference series have their forms chosen.' : 'No conference series yet. Create them from your registration forms.',
			'link'   => $linked ? self::url( 'series' ) : admin_url( 'admin.php?page=' . Setup::PAGE ),
			'label'  => $linked ? 'Conference series' : 'Create series',
		);
		$items[]  = array(
			'ok'     => $open > 0 ? true : null,
			'title'  => 'Open conferences',
			'detail' => $open ? $open . ' conference(s) currently taking registrations.' : 'No conference is open for registration right now. That is fine between seasons.',
			'link'   => admin_url( 'edit.php?post_type=' . Conferences::EDITION ),
			'label'  => 'Conferences',
		);
		$issuer_ok = '' !== trim( $issuer ) && false === stripos( $issuer, 'test' );
		$items[]   = array(
			'ok'     => $issuer_ok,
			'title'  => 'Invoice company details',
			'detail' => $issuer_ok ? 'Set. Shown at the top of every invoice and receipt.' : ( '' === trim( $issuer ) ? 'Not set yet.' : 'Still contains TEST placeholder text.' ),
			'link'   => self::url( 'invoices' ),
			'label'  => 'Fix',
		);
		$instr_ok = '' !== trim( $instr ) && false === stripos( $instr, 'test' );
		$items[]  = array(
			'ok'     => $instr_ok,
			'title'  => 'Payment instructions',
			'detail' => $instr_ok ? 'Set. Printed on invoices for check and PO payments.' : ( '' === trim( $instr ) ? 'Not set yet. Vendors paying by check will not know where to send it.' : 'Still contains TEST placeholder text.' ),
			'link'   => self::url( 'invoices' ),
			'label'  => 'Fix',
		);
		$items[]  = array(
			'ok'     => is_email( (string) $mail['reply_to'] ) ? true : false,
			'title'  => 'Reply-to address',
			'detail' => is_email( (string) $mail['reply_to'] ) ? 'Vendor replies go to ' . $mail['reply_to'] . '.' : 'Not set. Vendor replies to portal emails will go to the site\'s default address.',
			'link'   => self::url( 'emails' ),
			'label'  => 'Fix',
		);

		$smtp = json_decode( (string) get_option( 'gravitysmtp_config', '' ), true );
		if ( is_array( $smtp ) ) {
			$test    = 'true' === ( $smtp['test_mode'] ?? '' );
			$items[] = array(
				'ok'     => $test ? null : true,
				'title'  => 'Email delivery',
				'detail' => $test ? 'Gravity SMTP test mode is on: emails are logged but not delivered. Right for testing; switch it off on the live site.' : 'Emails are delivered through Gravity SMTP.',
				'link'   => admin_url( 'admin.php?page=gravitysmtp-settings' ),
				'label'  => 'SMTP settings',
			);
		}
		$an = get_option( 'gravityformsaddon_gravityformsauthorizenet_settings' );
		if ( is_array( $an ) && isset( $an['mode'] ) ) {
			$live    = 'production' === $an['mode'];
			$items[] = array(
				'ok'     => null,
				'title'  => 'Card payments',
				'detail' => $live ? 'Authorize.Net is in live (production) mode: real cards are charged.' : 'Authorize.Net is in sandbox (test) mode: no real charges.',
				'link'   => admin_url( 'admin.php?page=gf_settings&subview=gravityformsauthorizenet' ),
				'label'  => 'Payment settings',
			);
		}
		return $items;
	}

	private function tab_overview(): void {
		$items   = $this->checklist();
		$todo    = count( array_filter( $items, static fn( $i ) => false === $i['ok'] ) );
		$vendors = count( get_users( array( 'role' => Roles::VENDOR, 'fields' => 'ID' ) ) );
		$eds     = wp_count_posts( Conferences::EDITION );
		?>
		<div class="zpvp-stats">
			<div><span>Vendor accounts</span><strong><?php echo (int) $vendors; ?></strong></div>
			<div><span>Conference series</span><strong><?php echo (int) ( wp_count_posts( Conferences::SERIES )->publish ?? 0 ); ?></strong></div>
			<div><span>Conferences</span><strong><?php echo (int) ( $eds->publish ?? 0 ); ?></strong><?php echo ! empty( $eds->draft ) ? '<em>' . (int) $eds->draft . ' draft</em>' : ''; ?></div>
			<div><span>Open for registration</span><strong><?php echo count( Conferences::current_editions() ); ?></strong></div>
		</div>

		<section class="zpvp-card zpvp-card--stack">
			<div class="zpvp-card__intro">
				<h2>Setup checklist</h2>
				<p><?php echo $todo ? esc_html( sprintf( '%d item(s) need attention before going live.', $todo ) ) : 'Everything needed for the live site is in place.'; ?></p>
			</div>
			<ul class="zpvp-check">
				<?php foreach ( $items as $i ) : ?>
					<?php $state = true === $i['ok'] ? 'ok' : ( false === $i['ok'] ? 'todo' : 'info' ); ?>
					<li class="zpvp-check__item is-<?php echo esc_attr( $state ); ?>">
						<span class="zpvp-check__icon" aria-hidden="true"><?php echo 'ok' === $state ? '&#10003;' : ( 'todo' === $state ? '!' : 'i' ); ?></span>
						<span class="screen-reader-text"><?php echo esc_html( 'ok' === $state ? 'Done:' : ( 'todo' === $state ? 'Needs attention:' : 'Note:' ) ); ?></span>
						<div class="zpvp-check__text"><strong><?php echo esc_html( $i['title'] ); ?></strong><span><?php echo esc_html( $i['detail'] ); ?></span></div>
						<?php if ( ! empty( $i['link'] ) ) : ?>
							<a class="button<?php echo 'todo' === $state ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $i['link'] ); ?>"><?php echo esc_html( $i['label'] ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>

		<?php self::card_open( 'Portal pages', 'The three pages vendors use. They were created automatically; you can edit their text like any page, but keep the shortcode or form on them.' ); ?>
		<ul class="zpvp-pages">
			<?php foreach ( array( 'register' => array( 'Vendor Registration', 'Vendors create their account here.' ), 'login' => array( 'Vendor Login', 'Log in, and forgotten or reset passwords.' ), 'dashboard' => array( 'My Conferences', 'The vendor\'s home after logging in.' ) ) as $key => $p ) : ?>
				<li>
					<div><strong><?php echo esc_html( $p[0] ); ?></strong><span><?php echo esc_html( $p[1] ); ?></span></div>
					<?php if ( Settings::page_id( $key ) ) : ?>
						<a class="button" href="<?php echo esc_url( Settings::page_url( $key ) ); ?>" target="_blank" rel="noopener">View</a>
						<a class="button" href="<?php echo esc_url( get_edit_post_link( Settings::page_id( $key ), 'url' ) ); ?>">Edit</a>
					<?php else : ?>
						<em>Missing</em>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php self::card_close(); ?>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Invoices                                                            */
	/* ------------------------------------------------------------------ */

	private function tab_invoices(): void {
		$issuer = (string) get_option( Documents::OPT_ISSUER, get_bloginfo( 'name' ) );
		$instr  = (string) get_option( Documents::OPT_INSTRUCTIONS, '' );
		global $wpdb;
		$sample = (int) $wpdb->get_var( 'SELECT id FROM ' . Registrations::table() . " WHERE status = 'active' ORDER BY id DESC LIMIT 1" );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="zpvp_save_settings">
			<?php wp_nonce_field( 'zpvp_save_settings' ); ?>

			<?php self::card_open( 'Company details', 'Printed at the top of every invoice and receipt, and in the footer of portal emails. Put the company name on the <strong>first line</strong> (shown in bold), then address, phone and email on the next lines.' ); ?>
			<div class="zpvp-split">
				<p>
					<label for="zpvp-issuer">Company details</label>
					<textarea id="zpvp-issuer" name="issuer" rows="5" class="large-text" placeholder="ZacPubs&#10;Street, City, TX ZIP&#10;Phone · email"><?php echo esc_textarea( $issuer ); ?></textarea>
				</p>
				<div class="zpvp-preview" aria-hidden="true">
					<span class="zpvp-preview__label">Invoice preview</span>
					<div class="zpvp-preview__sheet">
						<div class="zpvp-preview__issuer" id="zpvp-preview-issuer"></div>
						<div class="zpvp-preview__title">INVOICE</div>
					</div>
				</div>
			</div>
			<?php self::card_close(); ?>

			<?php self::card_open( 'Payment instructions', 'Printed on invoices for vendors who pay by <strong>check</strong> or <strong>purchase order</strong>. Say who to make checks payable to, where to mail them, and who to contact with questions. Receipts don\'t show this.' ); ?>
			<p>
				<label for="zpvp-instructions">Payment instructions</label>
				<textarea id="zpvp-instructions" name="instructions" rows="4" class="large-text" placeholder="Make checks payable to ZacPubs and mail to … Please include the invoice number with your payment."><?php echo esc_textarea( $instr ); ?></textarea>
			</p>
			<?php self::card_close(); ?>

			<div class="zpvp-actions">
				<?php submit_button( 'Save invoice settings', 'primary', 'submit', false ); ?>
				<?php if ( $sample ) : ?>
					<a class="button" href="<?php echo esc_url( Documents::url( $sample ) ); ?>" target="_blank" rel="noopener">Open a sample invoice</a>
					<span class="description">Save first to see your changes on the sample.</span>
				<?php endif; ?>
			</div>
		</form>
		<script>
		( function () {
			var src = document.getElementById( 'zpvp-issuer' ), out = document.getElementById( 'zpvp-preview-issuer' );
			if ( ! src || ! out ) { return; }
			function draw() {
				out.textContent = '';
				src.value.split( /\r?\n/ ).forEach( function ( line, i ) {
					var el = document.createElement( i === 0 ? 'strong' : 'span' );
					el.textContent = line || ( i === 0 ? 'Company name' : '' );
					out.appendChild( el );
				} );
			}
			src.addEventListener( 'input', draw );
			draw();
		}() );
		</script>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Menu link                                                           */
	/* ------------------------------------------------------------------ */

	private function tab_menu(): void {
		$menu     = Menu::settings();
		$defaults = Menu::defaults();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="zpvp_menu_settings">
			<?php wp_nonce_field( 'zpvp_menu_settings' ); ?>

			<?php self::card_open( 'Header menu link', 'Added at the end of the site\'s top menu. Visitors who aren\'t logged in as a vendor see the <strong>logged-out</strong> label and go to the vendor login page. Logged-in vendors see the <strong>logged-in</strong> label, which opens their portal and has a <strong>Log out</strong> sub-item. Leave a label blank to use the default.' ); ?>
			<?php self::toggle( 'enabled', (bool) $menu['enabled'], 'Show the vendor link in the menu', 'Turn off to hide it for everyone. Vendors can still reach the portal from their emails or by going to the login page directly.' ); ?>
			<p>
				<label for="zpvp-menu-out">Logged-out label</label>
				<input type="text" id="zpvp-menu-out" name="label_out" class="regular-text" maxlength="40" value="<?php echo esc_attr( $menu['label_out'] ); ?>" placeholder="<?php echo esc_attr( $defaults['label_out'] ); ?>">
			</p>
			<p>
				<label for="zpvp-menu-in">Logged-in label</label>
				<input type="text" id="zpvp-menu-in" name="label_in" class="regular-text" maxlength="40" value="<?php echo esc_attr( $menu['label_in'] ); ?>" placeholder="<?php echo esc_attr( $defaults['label_in'] ); ?>">
			</p>
			<?php self::card_close(); ?>

			<div class="zpvp-actions">
				<?php submit_button( 'Save menu link', 'primary', 'submit', false ); ?>
				<a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener">View site</a>
			</div>
		</form>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Emails                                                              */
	/* ------------------------------------------------------------------ */

	private static function toggle( string $name, bool $on, string $title, string $desc, string $extra = '' ): void {
		?>
		<div class="zpvp-toggle">
			<label class="zpvp-switch">
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $on ); ?>>
				<span class="zpvp-switch__track" aria-hidden="true"></span>
				<span class="zpvp-toggle__title"><?php echo esc_html( $title ); ?></span>
			</label>
			<p class="zpvp-toggle__desc"><?php echo wp_kses( $desc, array( 'strong' => array() ) ); ?></p>
			<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
		</div>
		<?php
	}

	private function tab_emails(): void {
		$s = Mailer::settings();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="zpvp_email_settings">
			<input type="hidden" name="zpvp_recipients_form" value="1">
			<?php wp_nonce_field( 'zpvp_email_settings' ); ?>

			<?php self::card_open( 'Automatic emails', 'Sent to the vendor\'s contact email and account email. Each email is sent only once per registration, so re-saving or re-processing never sends duplicates.' ); ?>
			<?php
			self::toggle( 'confirm_payment', (bool) $s['confirm_payment'], 'Payment received', '<strong>When:</strong> a card payment clears, or staff click Mark paid. Includes the receipt as a PDF.' );
			self::toggle(
				'deadline_reminder',
				(bool) $s['deadline_reminder'],
				'Booth staff reminder',
				'<strong>When:</strong> a few days before each conference\'s attendee edit deadline. Lists the people currently registered so the vendor can correct names before badges are printed.',
				'<p class="zpvp-toggle__extra"><label for="zpvp-days">Send</label> <input id="zpvp-days" type="number" name="reminder_days" min="1" max="30" value="' . (int) $s['reminder_days'] . '"> <span>days before the deadline</span></p>'
			);
			self::toggle( 'confirm_registration', (bool) $s['confirm_registration'], 'Registration confirmation', '<strong>When:</strong> right after a vendor submits a conference form. Includes the invoice as a PDF. Keep this <strong>off</strong> while the conference forms still send their own Gravity Forms confirmation, or vendors get two emails.' );
			?>
			<?php self::card_close(); ?>

			<?php self::card_open( 'Who receives them', 'Portal emails (confirmations, receipts, reminders and the messages you send to a conference\'s vendors) go to the person who registered and the vendor account. Companies can have several contacts; choose whether they all get a copy.' ); ?>
			<?php self::toggle( 'company_contacts', Companies::email_all_contacts(), 'Every contact at the company', 'When <strong>on</strong>, every contact listed on the vendor company also receives conference emails. When <strong>off</strong>, only the person who registered does.' ); ?>
			<?php self::toggle( 'change_notices', (bool) Change_Notices::settings()['enabled'], 'Conference change notices', '<strong>When:</strong> staff change a published conference\'s dates, venue, city, hotel, decorator, electricity information, floor plan, documents or booth staff deadline. Registered vendors get one email listing what changed, sent ' . (int) Change_Notices::settings()['delay'] . ' minutes after the last edit so several edits go out together. It can be cancelled from the conference screen before it goes.' ); ?>
			<?php self::card_close(); ?>

			<?php self::card_open( 'Replies', 'When a vendor replies to any portal email, the reply goes to this address. Use a shared inbox that someone checks, for example conferences@zacpubs.com.' ); ?>
			<p>
				<label for="zpvp-reply">Reply-to address</label>
				<input id="zpvp-reply" type="email" name="reply_to" class="regular-text" value="<?php echo esc_attr( (string) $s['reply_to'] ); ?>" placeholder="conferences@zacpubs.com">
			</p>
			<?php self::card_close(); ?>

			<div class="zpvp-actions">
				<?php submit_button( 'Save email settings', 'primary', 'submit', false ); ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=zpvp&compose=1' ) ); ?>">Email the vendors of a conference</a>
			</div>
		</form>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Registration forms                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Every form chosen on a conference, with the conference that records its
	 * new registrations.
	 *
	 * @return array<int, array{form:int, target:int, others:int[]}>
	 */
	public static function form_routes(): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft') AND pm.meta_key IN ('gf_forms','sponsorship_forms')",
				Conferences::EDITION
			)
		);
		$ids = array();
		foreach ( $rows as $row ) {
			$kind = 'sponsorship_forms' === $row->meta_key ? 'sponsorship' : 'registration';
			foreach ( (array) maybe_unserialize( $row->meta_value ) as $fid ) {
				if ( (int) $fid ) {
					$ids[ $kind . ':' . (int) $fid ] = array( $kind, (int) $fid );
				}
			}
		}
		$out = array();
		foreach ( $ids as [ $kind, $fid ] ) {
			$all   = 'sponsorship' === $kind ? Conferences::using_sponsorship_form( $fid ) : Conferences::using_form( $fid );
			$out[] = array( 'form' => $fid, 'kind' => $kind, 'target' => $all[0] ?? 0, 'others' => array_slice( $all, 1 ) );
		}
		return $out;
	}

	private function tab_series(): void {
		$all = get_posts( array( 'post_type' => Conferences::SERIES, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$gf  = class_exists( '\GFAPI' );
		$forms_cell = static function ( array $ids ) use ( $gf ): string {
			if ( ! $ids ) {
				return '<span class="zpvp-muted">—</span>';
			}
			$out = array();
			foreach ( $ids as $fid ) {
				$form  = $gf ? \GFAPI::get_form( $fid ) : null;
				$on    = $form && ! empty( $form['is_active'] );
				$out[] = esc_html( $form ? $form['title'] : 'Form ' . $fid ) . ' <span class="zpvp-muted">Form ' . (int) $fid . '</span> <span class="zpvp-form-tag ' . ( $on ? 'is-on' : 'is-off' ) . '">' . ( $on ? 'on' : 'off' ) . '</span>';
			}
			return implode( '<br>', $out );
		};
		$labels = Conferences::annual_dropdowns();

		self::card_open( 'Conference series', 'A series is one event that happens every year, such as the West Texas conference. It holds the forms the event always uses: its registration form, its sponsorship form and its booth dropdown on the annual form. Each year\'s conference (dates, venue, capacity) belongs to a series and uses its forms automatically. New registrations go to the newest <strong>published</strong> conference of the series.' );
		?>
		<p class="zpvp-inline-actions">
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Setup::PAGE ) ); ?>">Create series from forms</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Conferences::SERIES ) ); ?>">Add series</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Conferences::SERIES ) ); ?>">All series</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Conferences::EDITION ) ); ?>">Conferences</a>
		</p>
		<?php
		self::card_close();

		$next = Conferences::next_season_year();
		$plan = Conferences::season_plan( $next );
		self::card_open( 'Next season', 'Starts the next year in one click: a draft of every series\' conference for ' . (int) $next . ', copied from its latest year with the same venue and information, and every date moved forward. Vendors see nothing until you publish each draft, and the annual form switches to the new year once next year\'s conferences are published.' );
		?>
		<?php if ( $plan['sources'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="zpvp-inline-actions">
				<input type="hidden" name="action" value="zpvp_start_season">
				<input type="hidden" name="year" value="<?php echo (int) $next; ?>">
				<?php wp_nonce_field( 'zpvp_start_season' ); ?>
				<?php submit_button( 'Start ' . (int) $next . ' season', 'primary', 'submit', false ); ?>
				<span class="zpvp-muted"><?php echo esc_html( sprintf( '%d draft conferences will be created%s.', count( $plan['sources'] ), $plan['existing'] ? sprintf( '; %d already exist', count( $plan['existing'] ) ) : '' ) ); ?></span>
			</form>
		<?php else : ?>
			<p><span class="zpvp-status is-ok">Every series has a <?php echo (int) $next; ?> conference</span>
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Conferences::EDITION . '&post_status=draft' ) ); ?>">See drafts</a></p>
		<?php endif; ?>
		<?php self::card_close(); ?>

		<div class="zpvp-table-card">
			<h2 class="zpvp-table-card__title">Series (<?php echo count( $all ); ?>)</h2>
			<table class="widefat striped zpvp-series-table">
				<thead><tr><th>Series</th><th>Registration form</th><th>Sponsorship form</th><th>Annual form dropdown</th><th>Conferences</th></tr></thead>
				<tbody>
				<?php if ( ! $all ) : ?>
					<tr><td colspan="5">No series yet. Click "Create series from forms" above.</td></tr>
				<?php endif; ?>
				<?php foreach ( $all as $s ) : ?>
					<?php $field = (string) get_post_meta( $s->ID, 'annual_product_field', true ); ?>
					<tr>
						<td><strong><a href="<?php echo esc_url( (string) get_edit_post_link( $s->ID, 'url' ) ); ?>"><?php echo esc_html( $s->post_title ); ?></a></strong>
							<br><a class="zpvp-muted" href="<?php echo esc_url( Series::add_url( (int) $s->ID ) ); ?>">Add next year's conference</a></td>
						<td><?php echo $forms_cell( Series::form_ids( (int) $s->ID, 'gf_forms' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo $forms_cell( Series::form_ids( (int) $s->ID, 'sponsorship_forms' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo '' === $field ? '<span class="zpvp-muted">—</span>' : esc_html( $labels[ $field ] ?? 'Field ' . $field ); ?></td>
						<td><?php echo Series::conference_list( (int) $s->ID ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="zpvp-legend"><span class="zpvp-form-tag is-on">on</span> the form is active in Gravity Forms. <span class="zpvp-form-tag is-off">off</span> switched off; switch it on when that conference's registration opens.</p>
		</div>

		<?php $this->annual_table(); ?>
		<?php
	}

	/** Annual form: season, automatic years and the conference each booth dropdown books. */
	private function annual_table(): void {
		$form = Annual_Form::form();
		if ( ! $form ) {
			return;
		}
		$season   = Annual_Form::season();
		$override = Annual_Form::override();
		$detected = Annual_Form::detected_season();
		$plan     = Annual_Form::convert( false );
		$todo     = $plan['changes'];
		?>
		<section class="zpvp-card">
			<div class="zpvp-card__intro">
				<h2>Annual form: <?php echo esc_html( Annual_Form::replace( (string) $form['title'] ) ); ?></h2>
				<p>Vendors book several conferences at once on this form. It is reused every year: it takes bookings for the newest year that has published conferences on it, and shows each conference's dates and venue from the conference itself.</p>
			</div>
			<div class="zpvp-card__fields">
				<p style="font-size:15px;margin-top:0">Taking bookings for <strong><?php echo (int) $season; ?></strong>
					<span class="zpvp-muted"><?php echo $override ? '(set manually)' : '(worked out from the published conferences)'; ?></span></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="zpvp-inline-actions">
					<input type="hidden" name="action" value="zpvp_annual_season">
					<?php wp_nonce_field( 'zpvp_annual_season' ); ?>
					<label for="zpvp-season" class="screen-reader-text">Season</label>
					<select id="zpvp-season" name="season">
						<option value="0" <?php selected( 0, $override ); ?>>Automatic (<?php echo (int) $detected; ?>)</option>
						<?php for ( $y = $detected - 1; $y <= $detected + 2; $y++ ) : ?>
							<option value="<?php echo (int) $y; ?>" <?php selected( $y, $override ); ?>><?php echo (int) $y; ?></option>
						<?php endfor; ?>
					</select>
					<?php submit_button( 'Save', 'secondary', 'submit', false ); ?>
				</form>
				<p class="description">Leave on Automatic. When you publish next year's conferences, the form switches to that year by itself.</p>

				<?php if ( $todo ) : ?>
					<div class="zpvp-banner" style="margin-top:16px">
						<div><strong>The form still has <?php echo count( $todo ); ?> fixed years or conference dates.</strong> Make them automatic once, and the form and its page never need editing for the year again. A backup of the form is saved first.</div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="zpvp_annual_convert">
							<?php wp_nonce_field( 'zpvp_annual_convert' ); ?>
							<?php submit_button( 'Make years automatic', 'primary', 'submit', false ); ?>
						</form>
					</div>
					<details class="zpvp-advanced">
						<summary>See what will change</summary>
						<table class="widefat striped"><thead><tr><th>Where</th><th>Now</th><th>After</th></tr></thead><tbody>
						<?php foreach ( $todo as $c ) : ?>
							<tr><td><?php echo esc_html( $c['where'] ); ?></td><td><?php echo esc_html( $c['before'] ); ?></td><td><?php echo esc_html( $c['after'] ); ?></td></tr>
						<?php endforeach; ?>
						</tbody></table>
					</details>
				<?php else : ?>
					<p><span class="zpvp-status is-ok">Years are automatic</span> <span class="zpvp-muted">The form shows {season} as <?php echo (int) $season; ?> and {season_prev} as <?php echo (int) ( $season - 1 ); ?>.</span></p>
				<?php endif; ?>
			</div>
		</section>

		<div class="zpvp-table-card">
			<h2 class="zpvp-table-card__title">What each booth dropdown books in <?php echo (int) $season; ?></h2>
			<table class="widefat striped">
				<thead><tr><th>Booth dropdown</th><th>Books</th><th>Shown on the form</th></tr></thead>
				<tbody>
				<?php foreach ( Conferences::annual_dropdowns() as $fid => $label ) : ?>
					<?php
					$target = Annual_Form::conference_for( (string) $fid );
					$any    = $target ? $target : Conferences::for_annual_field( (string) $fid );
					$field  = \GFFormsModel::get_field( $form, $fid );
					$auto   = $field && str_contains( (string) $field->description, Annual_Form::DETAILS );
					?>
					<tr>
						<td><?php echo esc_html( $label ); ?></td>
						<td>
							<?php if ( $target ) : ?>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $target, 'url' ) ); ?>"><?php echo esc_html( get_the_title( $target ) ); ?></a>
								<?php if ( 'publish' !== get_post_status( $target ) ) : ?><span class="zpvp-muted">Draft: publish it so vendors see it on My Conferences.</span><?php endif; ?>
							<?php elseif ( $any ) : ?>
								<span class="zpvp-status is-bad">Hidden on the form</span>
								<span class="zpvp-muted">No <?php echo (int) $season; ?> conference yet. Use "Copy for next year" on <a href="<?php echo esc_url( (string) get_edit_post_link( $any, 'url' ) ); ?>"><?php echo esc_html( get_the_title( $any ) ); ?></a>.</span>
							<?php else : ?>
								<span class="zpvp-muted">Not chosen on any series (shown on the form, bookings not tracked)</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $target && $auto ) : ?>
								<?php echo esc_html( Annual_Form::details( $target ) ); ?>
								<?php if ( ! get_post_meta( $target, 'start_date', true ) ) : ?><span class="zpvp-muted">Old text from the form, until you add dates to the conference.</span><?php endif; ?>
							<?php elseif ( $field ) : ?>
								<span class="zpvp-muted"><?php echo esc_html( wp_strip_all_tags( Annual_Form::replace( (string) $field->description ) ) ?: '—' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Email log                                                           */
	/* ------------------------------------------------------------------ */

	private function tab_log(): void {
		global $wpdb;
		$log = (array) $wpdb->get_results( 'SELECT * FROM ' . Mailer::table() . ' ORDER BY id DESC LIMIT 100' );
		$labels = array(
			'payment_received'          => 'Payment received',
			'deadline_reminder'         => 'Booth staff reminder',
			'registration_confirmation' => 'Registration confirmation',
			'conference_message'        => 'Message to vendors',
			'message_test'              => 'Test message',
		);
		?>
		<div class="zpvp-table-card">
			<p class="zpvp-table-card__intro">The last 100 emails sent by the portal. Failed emails show the reason. Emails sent while Gravity SMTP test mode is on are listed here but were not delivered.</p>
			<table class="widefat striped">
				<thead><tr><th>Sent</th><th>Email</th><th>To</th><th>Subject</th><th>Status</th></tr></thead>
				<tbody>
				<?php if ( ! $log ) : ?>
					<tr><td colspan="5">No emails sent yet.</td></tr>
				<?php endif; ?>
				<?php foreach ( $log as $l ) : ?>
					<tr>
						<td class="zpvp-nowrap"><?php echo esc_html( mysql2date( 'M j, g:i a', $l->created_at ) ); ?></td>
						<td><?php echo esc_html( $labels[ $l->type ] ?? ucwords( str_replace( '_', ' ', $l->type ) ) ); ?></td>
						<td><?php echo esc_html( $l->recipient ); ?></td>
						<td><?php echo esc_html( $l->subject ); ?></td>
						<td><?php echo 'sent' === $l->status ? '<span class="zpvp-status is-ok">Sent</span>' : '<span class="zpvp-status is-bad">Failed</span> <span class="zpvp-muted">' . esc_html( (string) $l->error ) . '</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
