<?php
namespace ZPVP;

defined( 'ABSPATH' ) || exit;

/**
 * Makes the plugin self-installing on any site with Gravity Forms:
 * - creates the Vendor Registration form and its User Registration feed
 *   (tagged, so it is never duplicated and other forms are never touched);
 * - reports missing required plugins;
 * - "Connect conference forms": detects conference registration forms and
 *   the annual form, and lets staff confirm them as conference series.
 */
final class Setup {

	public const FORM_KEY       = 'zpvp_form';
	public const OPT_ANNUAL     = 'zpvp_annual_form_id';
	public const PAGE           = 'zpvp-connect';

	public function register(): void {
		add_action( 'admin_init', array( $this, 'lazy_install' ) );
		add_action( 'admin_menu', array( $this, 'menu' ), 40 );
		add_action( 'admin_post_zpvp_connect_forms', array( $this, 'connect' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Requirements                                                        */
	/* ------------------------------------------------------------------ */

	/** @return string[] Human-readable problems; empty when all is well. */
	public static function missing_requirements(): array {
		$missing = array();
		if ( ! class_exists( '\GFAPI' ) ) {
			$missing[] = 'Gravity Forms is not active. The portal needs it for vendor accounts and conference registrations.';
		} elseif ( ! function_exists( 'gf_user_registration' ) ) {
			$missing[] = 'The Gravity Forms User Registration add-on is not active. Vendors cannot create accounts until it is.';
		}
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			$missing[] = 'Advanced Custom Fields PRO is not active. Conference dates, capacity and resources cannot be edited without it.';
		}
		return $missing;
	}

	/* ------------------------------------------------------------------ */
	/* Plugin-owned forms                                                  */
	/* ------------------------------------------------------------------ */

	/** Find the plugin's Vendor Registration form by its tag. */
	public static function find_registration_form(): int {
		if ( ! class_exists( '\GFAPI' ) ) {
			return 0;
		}
		$stored = Settings::registration_form_id();
		if ( $stored ) {
			$form = \GFAPI::get_form( $stored );
			if ( $form && empty( $form['is_trash'] ) ) {
				return $stored;
			}
		}
		foreach ( \GFAPI::get_forms( null, false ) as $form ) {
			if ( 'vendor_registration' === ( $form[ self::FORM_KEY ] ?? '' ) ) {
				return (int) $form['id'];
			}
		}
		return 0;
	}

	/** Create the Vendor Registration form and account feed if they don't exist. */
	public static function install_forms(): void {
		if ( ! class_exists( '\GFAPI' ) ) {
			return;
		}
		$form_id = self::find_registration_form();
		if ( ! $form_id ) {
			$form_id = \GFAPI::add_form( self::registration_form_definition() );
			if ( is_wp_error( $form_id ) ) {
				return;
			}
		}
		update_option( Settings::OPT_REG_FORM, (int) $form_id, false );
		self::install_feed( (int) $form_id );

		if ( ! get_option( self::OPT_ANNUAL ) ) {
			$annual = self::detect_annual_form();
			if ( $annual ) {
				update_option( self::OPT_ANNUAL, $annual, false );
			}
		}
	}

	private static function install_feed( int $form_id ): void {
		if ( ! function_exists( 'gf_user_registration' ) ) {
			return;
		}
		$feeds = \GFAPI::get_feeds( null, $form_id, 'gravityformsuserregistration' );
		if ( ! is_wp_error( $feeds ) && $feeds ) {
			return; // Keep the existing feed and any changes staff made to it.
		}
		\GFAPI::add_feed(
			$form_id,
			array(
				'feedName'                         => 'Create Vendor Account',
				'feedType'                         => 'create',
				'username'                         => '2',
				'first_name'                       => '1.3',
				'last_name'                        => '1.6',
				'displayname'                      => 'firstlast',
				'email'                            => '2',
				'password'                         => '3',
				'role'                             => Roles::VENDOR,
				'userMeta'                         => array(
					array( 'key' => 'gf_custom', 'custom_key' => 'zpvp_company_name', 'value' => '4' ),
					array( 'key' => 'gf_custom', 'custom_key' => 'zpvp_job_title', 'value' => '5' ),
					array( 'key' => 'gf_custom', 'custom_key' => 'zpvp_phone', 'value' => '6' ),
				),
				'sendEmail'                        => '0', // The plugin sends its own welcome email.
				'userActivationEnable'             => '1',
				'userActivationValue'              => 'email',
				'feed_condition_conditional_logic' => '0',
			),
			'gravityformsuserregistration'
		);
	}

	private static function registration_form_definition(): array {
		return array(
			'title'          => 'Vendor Registration',
			'description'    => 'Create your vendor account to register for conferences and manage your booth, attendees and badges.',
			'labelPlacement' => 'top_label',
			'button'         => array( 'type' => 'text', 'text' => 'Create Account' ),
			'is_active'      => true,
			self::FORM_KEY   => 'vendor_registration',
			'fields'         => array(
				array(
					'id'         => 1,
					'type'       => 'name',
					'label'      => 'Name',
					'isRequired' => true,
					'nameFormat' => 'advanced',
					'inputs'     => array(
						array( 'id' => '1.2', 'label' => 'Prefix', 'name' => '', 'isHidden' => true ),
						array( 'id' => '1.3', 'label' => 'First', 'name' => '' ),
						array( 'id' => '1.4', 'label' => 'Middle', 'name' => '', 'isHidden' => true ),
						array( 'id' => '1.6', 'label' => 'Last', 'name' => '' ),
						array( 'id' => '1.8', 'label' => 'Suffix', 'name' => '', 'isHidden' => true ),
					),
				),
				array( 'id' => 2, 'type' => 'email', 'label' => 'Email', 'isRequired' => true, 'description' => 'This will be your login.' ),
				array(
					'id'                      => 3,
					'type'                    => 'password',
					'label'                   => 'Password',
					'isRequired'              => true,
					'passwordStrengthEnabled' => true,
					'minPasswordStrength'     => 'good',
					'inputs'                  => array(
						array( 'id' => '3', 'label' => 'Enter Password', 'name' => '' ),
						array( 'id' => '3.2', 'label' => 'Confirm Password', 'name' => '' ),
					),
				),
				array( 'id' => 4, 'type' => 'text', 'label' => 'Company Name', 'isRequired' => true ),
				array( 'id' => 5, 'type' => 'text', 'label' => 'Job Title', 'isRequired' => false ),
				array( 'id' => 6, 'type' => 'phone', 'label' => 'Phone', 'isRequired' => true, 'phoneFormat' => 'standard' ),
			),
			'confirmations'  => array(
				'zpvp_conf' => array(
					'id'        => 'zpvp_conf',
					'name'      => 'Default Confirmation',
					'isDefault' => true,
					'type'      => 'message',
					'message'   => 'Thanks for registering. Please check your email for a link to activate your vendor account.',
				),
			),
			'notifications'  => array(),
		);
	}

	/**
	 * Covers sites where Gravity Forms (or the User Registration add-on) was
	 * activated after this plugin: finish the setup on the next admin page load.
	 */
	public function lazy_install(): void {
		if ( ! class_exists( '\GFAPI' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$form_id = self::find_registration_form();
		$needs   = ! $form_id || ( function_exists( 'gf_user_registration' ) && ! \GFAPI::get_feeds( null, $form_id, 'gravityformsuserregistration' ) );
		if ( $needs ) {
			self::install_forms();
			Pages::install();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Detecting conference forms                                          */
	/* ------------------------------------------------------------------ */

	/** Suggested series name from a form title. */
	public static function suggest_name( string $title ): string {
		$name = preg_replace( '/\b(19|20)\d{2}\b/', '', $title );
		$name = preg_replace( '/[\s\-–:]*\b(exhibitors?|registrations?|historic|vendors?|booths?)\b/i', '', (string) $name );
		$name = trim( preg_replace( '/\s{2,}/', ' ', (string) $name ), " \t-–:" );
		return '' !== $name ? $name : $title;
	}

	/**
	 * Every Gravity Form with a verdict: conference (booth + payment/product),
	 * annual (many booth dropdowns) or other. Plugin-owned forms are skipped.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function analyse_forms(): array {
		if ( ! class_exists( '\GFAPI' ) ) {
			return array();
		}
		$out = array();
		foreach ( \GFAPI::get_forms( null, false, 'title', 'ASC' ) as $form ) {
			if ( ! empty( $form[ self::FORM_KEY ] ) ) {
				continue;
			}
			$labels   = strtolower( implode( ' | ', array_map( static fn( $f ) => (string) $f->label, $form['fields'] ) ) );
			$products = 0;
			$selects  = array();
			$has_list = false;
			foreach ( $form['fields'] as $f ) {
				if ( 'product' === $f->type ) {
					++$products;
					if ( in_array( $f->inputType, array( 'select', 'radio' ), true ) ) {
						$selects[ (string) $f->id ] = (string) $f->label;
					}
				}
				if ( 'list' === $f->type ) {
					$has_list = true;
				}
			}
			$has_pay   = str_contains( $labels, 'payment method' ) || str_contains( $labels, 'credit card' );
			$has_booth = str_contains( $labels, 'booth' );
			$annual    = count( $selects ) >= 5 && $has_pay;
			$out[]     = array(
				'id'         => (int) $form['id'],
				'title'      => (string) $form['title'],
				'active'     => ! empty( $form['is_active'] ),
				'entries'    => (int) \GFAPI::count_entries( (int) $form['id'] ),
				'annual'     => $annual,
				'conference' => ! $annual && $has_booth && ( $has_pay || $products > 0 || $has_list ) && ! self::is_extra_form( (string) $form['title'] ),
				'name'       => self::suggest_name( (string) $form['title'] ),
				'selects'    => $selects,
			);
		}
		return $out;
	}

	public static function detect_annual_form(): int {
		$best = 0;
		$size = 0;
		foreach ( self::analyse_forms() as $f ) {
			if ( $f['annual'] && ( count( $f['selects'] ) > $size || ( count( $f['selects'] ) === $size && $f['active'] ) ) ) {
				$best = $f['id'];
				$size = count( $f['selects'] );
			}
		}
		return $best;
	}

	/**
	 * Forms that look like a registration but are not one: test and archived copies,
	 * badge and extra-representative orders, virtual shows, attendee forms, and
	 * sponsorship forms (linked as a series' sponsorship form instead).
	 */
	public static function is_extra_form( string $title ): bool {
		return (bool) preg_match( '/\b(test|demo|historic|badges?|representatives?|virtual|attendee|change|sponsors?|sponsorships?)\b/i', $title );
	}

	/** The annual form's booth dropdown that best matches a series name. */
	public static function suggest_annual_field( string $name, array $selects ): string {
		$norm = static fn( $s ) => trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^a-z0-9 ]+/', ' ', str_replace( '&', ' and ', strtolower( $s ) ) ) ) );

		// 1. Words that tell sister events apart (Winter, Fall, West...) must agree on both sides.
		$keys = array( 'winter', 'fall', 'summer', 'spring', 'legislative', 'west', 'south', 'north', 'east' );
		$n    = $norm( $name );
		$ok   = array();
		foreach ( $selects as $id => $label ) {
			$l = $norm( $label );
			foreach ( $keys as $k ) {
				if ( (bool) preg_match( '/\b' . $k . '\b/', $n ) !== (bool) preg_match( '/\b' . $k . '\b/', $l ) ) {
					continue 2;
				}
			}
			$ok[ $id ] = $l;
		}

		// 2. Short names: "TACERA" or "JPCA" match the initials of a dropdown's words.
		if ( preg_match_all( '/\b[A-Z]{3,}\b/', $name, $m ) ) {
			foreach ( $ok as $id => $l ) {
				$words    = array_diff( explode( ' ', $l ), array( 'of', 'the', 'and', 'for', 's', '' ) );
				$initials = strtoupper( implode( '', array_map( static fn( $w ) => $w[0], $words ) ) );
				foreach ( $m[0] as $acronym ) {
					if ( str_starts_with( $initials, $acronym ) ) {
						return (string) $id;
					}
				}
			}
		}

		// 3. Otherwise the closest wording.
		$best  = '';
		$score = 0.0;
		foreach ( $ok as $id => $l ) {
			similar_text( $n, $l, $pct );
			if ( $pct > $score ) {
				$score = $pct;
				$best  = (string) $id;
			}
		}
		return $score >= 55 ? $best : '';
	}

	public static function needs_connecting(): bool {
		return class_exists( '\GFAPI' ) && ! Conferences::has_links();
	}

	/** Inline banner for the plugin's own screens (which hide other notices). */
	public static function banner(): void {
		foreach ( self::missing_requirements() as $problem ) {
			echo '<div class="zpvp-banner zpvp-banner--error"><strong>Required plugin missing.</strong> ' . esc_html( $problem ) . '</div>';
		}
		if ( self::needs_connecting() ) {
			$count = count( array_filter( self::analyse_forms(), static fn( $f ) => $f['conference'] ) );
			echo '<div class="zpvp-banner"><div><strong>Connect your conference registration forms.</strong> '
				. esc_html( $count ? sprintf( 'We found %d Gravity Forms that look like conference registrations. Review them to start tracking registrations, booths and badges.', $count ) : 'Choose which Gravity Forms are conference registrations to start tracking registrations, booths and badges.' )
				. '</div><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">Review and connect</a></div>';
		}
	}

	/** Same messages on the Plugins screen and the Conferences list. */
	public function admin_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( Roles::CAP_MANAGE ) ) {
			return;
		}
		if ( ! in_array( $screen->id, array( 'plugins', 'edit-' . Conferences::EDITION ), true ) ) {
			return;
		}
		foreach ( self::missing_requirements() as $problem ) {
			echo '<div class="notice notice-error"><p><strong>ZP Vendor Portal:</strong> ' . esc_html( $problem ) . '</p></div>';
		}
		if ( self::needs_connecting() ) {
			echo '<div class="notice notice-info"><p><strong>ZP Vendor Portal:</strong> connect your conference registration forms to start tracking registrations. <a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">Review and connect</a></p></div>';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Connect conference forms screen                                     */
	/* ------------------------------------------------------------------ */

	public function menu(): void {
		add_submenu_page( '', 'Connect conference forms', 'Connect conference forms', Roles::CAP_MANAGE, self::PAGE, array( $this, 'render' ) );
	}

	public function render(): void {
		$forms     = self::analyse_forms();
		$annual_id = isset( $_GET['annual'] ) ? absint( $_GET['annual'] ) : ( (int) get_option( self::OPT_ANNUAL ) ?: self::detect_annual_form() );
		$annual    = null;
		foreach ( $forms as $f ) {
			if ( $f['id'] === $annual_id ) {
				$annual = $f;
			}
		}
		$selects  = $annual ? $annual['selects'] : array();
		$linked   = array();
		foreach ( $forms as $f ) {
			$target = Conferences::series_for_form( (int) $f['id'] );
			if ( $target ) {
				$linked[ (int) $f['id'] ] = get_the_title( $target );
			}
		}
		$year = Annual_Form::season();
		// Sponsorship forms share the booth form's layout but are linked as a series' sponsorship form instead.
		$is_sponsor = static fn( $f ) => false !== stripos( (string) $f['title'], 'sponsor' );
		$likely     = array_filter( $forms, static fn( $f ) => $f['conference'] && ! $f['annual'] && ! $is_sponsor( $f ) );
		$other      = array_filter( $forms, static fn( $f ) => ( ! $f['conference'] || $is_sponsor( $f ) ) && ! $f['annual'] );
		?>
		<div class="wrap zpvp-settings zpvp-connect">
			<header class="zpvp-settings__head">
				<h1>Create conference series from forms</h1>
				<p>Tick the Gravity Forms that vendors use to register for a conference. Each ticked form is added to a <strong>conference series</strong> (one series per event, reused every year). Give two forms the same series name to put both in one series, for example an old and a new form for the same event.</p>
			</header>

			<?php foreach ( self::missing_requirements() as $problem ) : ?>
				<div class="zpvp-banner zpvp-banner--error"><strong>Required plugin missing.</strong> <?php echo esc_html( $problem ); ?></div>
			<?php endforeach; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zpvp_connect_forms">
				<?php wp_nonce_field( 'zpvp_connect_forms' ); ?>

				<section class="zpvp-card">
					<div class="zpvp-card__intro">
						<h2>Annual registration form</h2>
						<p>The form where vendors book several conferences at once, with one booth dropdown per conference. Leave "None" if you don't have one.</p>
					</div>
					<div class="zpvp-card__fields">
						<label for="zpvp-annual">Annual form</label>
						<select id="zpvp-annual" name="annual_form" onchange="location.href='<?php echo esc_js( admin_url( 'admin.php?page=' . self::PAGE . '&annual=' ) ); ?>'+this.value">
							<option value="0">None</option>
							<?php foreach ( $forms as $f ) : ?>
								<?php if ( count( $f['selects'] ) >= 2 ) : ?>
									<option value="<?php echo (int) $f['id']; ?>" <?php selected( $annual_id, $f['id'] ); ?>><?php echo esc_html( $f['id'] . ' – ' . Annual_Form::replace( (string) $f['title'] ) . ( $f['annual'] ? ' (detected)' : '' ) ); ?></option>
								<?php endif; ?>
							<?php endforeach; ?>
						</select>
						<p class="description">Each conference's booth dropdown on this form is matched automatically; you can change it in the table below.</p>
						<label for="zpvp-year" style="margin-top:12px;display:block">Conference year</label>
						<input type="number" id="zpvp-year" name="year" min="2015" max="2100" value="<?php echo (int) $year; ?>" class="small-text">
						<label style="display:block;margin-top:8px"><input type="checkbox" name="create_conferences" value="1" checked> Also create a conference for this year (as a draft) in each series that doesn't have one</label>
					</div>
				</section>

				<?php $this->table( 'Forms that look like conference registrations', $likely, true, $selects, $linked ); ?>

				<details class="zpvp-advanced" <?php echo $likely ? '' : 'open'; ?>>
					<summary>Other forms (<?php echo count( $other ); ?>)</summary>
					<p>Contact, subscription, sponsorship and other forms. Tick one only if it is also a conference registration.</p>
					<?php $this->table( '', $other, false, $selects, $linked ); ?>
				</details>

				<div class="zpvp-actions" style="margin-top:16px">
					<?php submit_button( 'Create series', 'primary', 'submit', false ); ?>
					<a class="button" href="<?php echo esc_url( Admin_Settings::url( 'series' ) ); ?>">Cancel</a>
					<span class="description">Forms already in a series are skipped. A name matching an existing series adds the form to it.</span>
				</div>
			</form>
		</div>
		<?php
	}

	private function table( string $title, array $forms, bool $checked, array $selects, array $linked ): void {
		if ( ! $forms ) {
			if ( $title ) {
				echo '<div class="zpvp-table-card"><p class="zpvp-table-card__intro">No forms were detected as conference registrations. Look under "Other forms" below.</p></div>';
			}
			return;
		}
		?>
		<div class="zpvp-table-card">
			<?php if ( $title ) : ?><h2 class="zpvp-table-card__title"><?php echo esc_html( $title ); ?></h2><?php endif; ?>
			<table class="widefat striped zpvp-connect-table">
				<thead><tr>
					<td class="check-column"><span class="screen-reader-text">Connect</span></td>
					<th>Gravity Form</th><th>Series name</th><?php echo $selects ? '<th>Booth field on annual form</th>' : ''; ?>
				</tr></thead>
				<tbody>
				<?php foreach ( $forms as $f ) : ?>
					<?php $already = isset( $linked[ $f['id'] ] ); ?>
					<tr>
						<th class="check-column" scope="row">
							<input type="checkbox" name="forms[<?php echo (int) $f['id']; ?>][on]" value="1" <?php checked( $checked && ! $already ); ?> <?php disabled( $already ); ?> aria-label="<?php echo esc_attr( 'Connect form ' . $f['id'] ); ?>">
						</th>
						<td>
							<strong><?php echo esc_html( $f['title'] ); ?></strong>
							<span class="zpvp-muted">Form <?php echo (int) $f['id']; ?> · <?php echo esc_html( number_format( $f['entries'] ) ); ?> entries · <?php echo $f['active'] ? 'active' : 'switched off'; ?><?php echo $already ? ' · already on “' . esc_html( $linked[ $f['id'] ] ) . '”' : ''; ?></span>
						</td>
						<td><input type="text" class="regular-text" name="forms[<?php echo (int) $f['id']; ?>][name]" value="<?php echo esc_attr( $f['name'] ); ?>" aria-label="<?php echo esc_attr( 'Series name for form ' . $f['id'] ); ?>" <?php disabled( $already ); ?>></td>
						<?php if ( $selects ) : ?>
							<?php $suggest = self::suggest_annual_field( $f['name'], $selects ); ?>
							<td>
								<select name="forms[<?php echo (int) $f['id']; ?>][annual_field]" aria-label="<?php echo esc_attr( 'Annual booth field for form ' . $f['id'] ); ?>" <?php disabled( $already ); ?>>
									<option value="">Not on the annual form</option>
									<?php foreach ( $selects as $sid => $label ) : ?>
										<option value="<?php echo esc_attr( (string) $sid ); ?>" <?php selected( $suggest, (string) $sid ); ?>><?php echo esc_html( $sid . ' – ' . wp_trim_words( $label, 10, '…' ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function connect(): void {
		if ( ! current_user_can( Roles::CAP_MANAGE ) ) {
			wp_die( 'You are not allowed to do this.' );
		}
		check_admin_referer( 'zpvp_connect_forms' );

		$annual = absint( $_POST['annual_form'] ?? 0 );
		update_option( self::OPT_ANNUAL, $annual, false );

		$year = absint( $_POST['year'] ?? 0 );
		if ( $year < 2015 || $year > 2100 ) {
			$year = (int) gmdate( 'Y' );
		}

		// Group ticked forms by series name.
		$groups = array();
		foreach ( (array) ( $_POST['forms'] ?? array() ) as $form_id => $row ) {
			if ( empty( $row['on'] ) ) {
				continue;
			}
			$name = sanitize_text_field( wp_unslash( $row['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$key = strtolower( $name );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'name' => $name, 'forms' => array(), 'annual' => '' );
			}
			$groups[ $key ]['forms'][] = (string) absint( $form_id );
			$field                     = sanitize_text_field( wp_unslash( $row['annual_field'] ?? '' ) );
			if ( '' === $groups[ $key ]['annual'] && '' !== $field ) {
				$groups[ $key ]['annual'] = $field;
			}
		}

		$make    = ! empty( $_POST['create_conferences'] );
		$created = 0;
		$updated = 0;
		$confs   = 0;
		foreach ( $groups as $g ) {
			$series = Conferences::group_by_name( $g['name'] );
			if ( $series ) {
				++$updated;
			} else {
				$series = (int) wp_insert_post( array( 'post_type' => Conferences::SERIES, 'post_status' => 'publish', 'post_title' => $g['name'] ) );
				if ( ! $series ) {
					continue;
				}
				++$created;
			}
			$forms = array_values( array_filter( array_unique( array_merge( array_map( 'strval', (array) get_post_meta( $series, 'gf_forms', true ) ), $g['forms'] ) ) ) );
			Conferences::set_field( $series, 'gf_forms', 'field_zpvp_series_gf_forms', $forms );
			if ( $g['annual'] && '' === (string) get_post_meta( $series, 'annual_product_field', true ) ) {
				Conferences::set_field( $series, 'annual_product_field', 'field_zpvp_series_annual_field', $g['annual'] );
			}
			if ( $make && ! Conferences::of_series( array( $series ), $year ) ) {
				$id = wp_insert_post( array( 'post_type' => Conferences::EDITION, 'post_status' => 'draft', 'post_title' => $g['name'] . ' ' . $year ), true );
				if ( ! is_wp_error( $id ) ) {
					Conferences::set_field( $id, 'series', 'field_zpvp_ed_series', $series );
					Conferences::set_field( $id, 'year', 'field_zpvp_ed_year', $year );
					Conferences::set_field( $id, 'status_override', 'field_zpvp_ed_status_override', 'auto' );
					Conferences::finalise( (int) $id );
					++$confs;
				}
			}
		}

		$msg = sprintf( '%d series created, %d existing series updated.', $created, $updated );
		if ( $make ) {
			$msg .= sprintf( ' %d draft conferences created for %d; open each one to add its dates, then publish it.', $confs, $year );
		}
		wp_safe_redirect( Admin_Settings::url( 'series', array( 'zpvp_msg' => rawurlencode( $msg ) ) ) );
		exit;
	}

	/** Use ACF when available so its field reference meta is written too. */
	private static function set( int $post_id, string $name, $value ): void {
		if ( function_exists( 'update_field' ) ) {
			update_field( $name, $value, $post_id );
		} else {
			update_post_meta( $post_id, $name, $value );
		}
	}
}
