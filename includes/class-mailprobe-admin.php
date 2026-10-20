<?php
/**
 * The admin side: the settings page (Settings > MailProbe) and the two notices.
 *
 * @package MailProbe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page and notices.
 */
class MailProbe_Admin {

	/**
	 * Slug of the settings page.
	 */
	const PAGE = 'mailprobe';

	/**
	 * Where an account and its API key are created.
	 */
	const SIGNUP_URL = 'https://mailprobe.dev/login/';

	/**
	 * Where credits are bought.
	 */
	const PRICING_URL = 'https://mailprobe.dev/pricing/';

	/**
	 * The plugin's support forum on WordPress.org.
	 */
	const SUPPORT_URL = 'https://wordpress.org/support/plugin/mailprobe/';

	/**
	 * Where a review of the plugin is left on WordPress.org.
	 */
	const REVIEW_URL = 'https://wordpress.org/support/plugin/mailprobe/reviews/#new-post';

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_mailprobe_dismiss', array( __CLASS__, 'dismiss' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MAILPROBE_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Settings > MailProbe.
	 */
	public static function menu() {
		add_options_page( 'MailProbe', 'MailProbe', 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	/**
	 * The setting, its sanitizer, and the text suggested for the privacy policy.
	 */
	public static function register() {
		register_setting(
			self::PAGE,
			MailProbe::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => MailProbe::defaults(),
			)
		);

		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				'MailProbe',
				wp_kses_post(
					wpautop(
						sprintf(
							/* translators: %s: URL of the MailProbe privacy policy. */
							__( 'When a visitor creates an account or places an order, this site sends the email address entered to MailProbe (mailprobe.dev), a service of Iris Digital hosted in France, to check that the address can receive emails. MailProbe checks the address in real time and does not store it once the check is done. MailProbe privacy policy: %s', 'mailprobe' ),
							'https://mailprobe.dev/privacy/'
						)
					)
				)
			);
		}
	}

	/**
	 * "Settings" next to "Deactivate" on the plugins screen.
	 *
	 * @param array $links The links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Settings', 'mailprobe' ) )
		);
		return $links;
	}

	/**
	 * URL of the settings page.
	 *
	 * @return string
	 */
	private static function page_url() {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}

	/**
	 * Cleans what the form sends. An empty key field keeps the saved key; a new key
	 * is tried against the API before it replaces the old one.
	 *
	 * @param mixed $input The submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		// WordPress runs the sanitizer twice on the first save of an option (once
		// through update_option, once more through add_option): the key would be
		// tried twice and the message shown twice.
		static $done = array();
		$signature   = md5( wp_json_encode( $input ) );
		if ( isset( $done[ $signature ] ) ) {
			return $done[ $signature ];
		}
		$done[ $signature ] = self::sanitize_once( $input );
		return $done[ $signature ];
	}

	/**
	 * The sanitizer itself.
	 *
	 * @param mixed $input The submitted values.
	 * @return array
	 */
	private static function sanitize_once( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$previous = MailProbe::settings();
		$clean    = array(
			'api_key'          => $previous['api_key'],
			'block_invalid'    => ! empty( $input['block_invalid'] ),
			'block_disposable' => ! empty( $input['block_disposable'] ),
			'block_role'       => ! empty( $input['block_role'] ),
			'check_orders'     => ! empty( $input['check_orders'] ),
		);

		if ( ! empty( $input['remove_key'] ) ) {
			$clean['api_key'] = '';
			MailProbe::clear_alert();
			return $clean;
		}

		$key = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';
		if ( '' === $key || $key === $previous['api_key'] ) {
			return $clean;
		}
		if ( 0 !== strpos( $key, 'mp_live_' ) ) {
			add_settings_error( MailProbe::OPTION, 'mailprobe_key_format', __( 'A MailProbe API key starts with mp_live_. The previous key was kept.', 'mailprobe' ) );
			return $clean;
		}

		$credits = ( new MailProbe_Client( $key ) )->credits();
		if ( is_wp_error( $credits ) && 'mailprobe_unauthorized' === $credits->get_error_code() ) {
			add_settings_error( MailProbe::OPTION, 'mailprobe_key_refused', __( 'MailProbe refused this API key. The previous key was kept.', 'mailprobe' ) );
			return $clean;
		}
		$clean['api_key'] = $key;
		MailProbe::clear_alert();
		if ( is_wp_error( $credits ) ) {
			/* translators: %s: the error. */
			add_settings_error( MailProbe::OPTION, 'mailprobe_key_unchecked', sprintf( __( 'The key is saved, but it could not be tried: %s', 'mailprobe' ), $credits->get_error_message() ), 'warning' );
		} else {
			/* translators: %s: number of credits. */
			add_settings_error( MailProbe::OPTION, 'mailprobe_key_ok', sprintf( _n( 'Connected to MailProbe: %s credit left.', 'Connected to MailProbe: %s credits left.', $credits, 'mailprobe' ), number_format_i18n( $credits ) ), 'success' );
		}
		return $clean;
	}

	/**
	 * The settings page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = MailProbe::settings();
		$stats    = MailProbe::stats();
		$name     = MailProbe::OPTION;
		$from_wp_config = MailProbe::key_from_constant();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MailProbe', 'mailprobe' ); ?></h1>
			<p><?php esc_html_e( 'Refuses registrations with an email address that cannot receive emails or is disposable, and flags WooCommerce orders whose billing email has the same problem. Each check uses one MailProbe credit.', 'mailprobe' ); ?></p>
			<?php settings_errors( $name ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::PAGE ); ?>

				<h2><?php esc_html_e( 'Connection', 'mailprobe' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mailprobe-api-key"><?php esc_html_e( 'API key', 'mailprobe' ); ?></label></th>
						<td>
							<?php if ( $from_wp_config ) : ?>
								<p><?php esc_html_e( 'Set in wp-config.php (MAILPROBE_API_KEY).', 'mailprobe' ); ?></p>
							<?php else : ?>
								<input type="password" id="mailprobe-api-key" class="regular-text" autocomplete="off" spellcheck="false"
									name="<?php echo esc_attr( $name ); ?>[api_key]" value=""
									placeholder="<?php echo esc_attr( '' !== $settings['api_key'] ? substr( $settings['api_key'], 0, 12 ) . '…' : 'mp_live_…' ); ?>" />
								<?php if ( '' !== $settings['api_key'] ) : ?>
									<p class="description"><?php esc_html_e( 'A key is saved. Leave the field empty to keep it.', 'mailprobe' ); ?></p>
									<p><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[remove_key]" value="1" /> <?php esc_html_e( 'Remove the saved key', 'mailprobe' ); ?></label></p>
								<?php endif; ?>
							<?php endif; ?>
							<?php self::render_connection(); ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Registration', 'mailprobe' ); ?></h2>
				<p><?php esc_html_e( 'The WordPress registration form and the WooCommerce "My account" form. The visitor waits three seconds at most; when MailProbe cannot answer in time or cannot be reached, the registration goes through.', 'mailprobe' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Refuse', 'mailprobe' ); ?></th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[block_invalid]" value="1" <?php checked( $settings['block_invalid'] ); ?> />
									<?php esc_html_e( 'Addresses that cannot receive emails (no mail server, mailbox that does not exist, bad format)', 'mailprobe' ); ?></label><br />
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[block_disposable]" value="1" <?php checked( $settings['block_disposable'] ); ?> />
									<?php esc_html_e( 'Disposable addresses (throwaway inboxes)', 'mailprobe' ); ?></label><br />
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[block_role]" value="1" <?php checked( $settings['block_role'] ); ?> />
									<?php esc_html_e( 'Shared addresses such as info@, contact@, sales@', 'mailprobe' ); ?></label>
								<p class="description"><?php esc_html_e( 'Never refused: addresses at domains that accept every address, and addresses at providers that do not allow checks, such as Outlook and Hotmail. They cannot be confirmed, and most of them belong to real people.', 'mailprobe' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</table>

				<?php if ( class_exists( 'WooCommerce' ) ) : ?>
					<h2><?php esc_html_e( 'WooCommerce orders', 'mailprobe' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Check orders', 'mailprobe' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[check_orders]" value="1" <?php checked( $settings['check_orders'] ); ?> />
									<?php esc_html_e( 'Check the billing email of each new order', 'mailprobe' ); ?></label>
								<p class="description"><?php esc_html_e( 'The check runs in the background after the order: the customer never waits and no order is refused. An order whose email cannot receive emails or is disposable gets a private note and a red mark in the orders list.', 'mailprobe' ); ?></p>
							</td>
						</tr>
					</table>
				<?php else : ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>[check_orders]" value="<?php echo esc_attr( $settings['check_orders'] ? '1' : '' ); ?>" />
				<?php endif; ?>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Activity', 'mailprobe' ); ?></h2>
			<?php if ( empty( $stats['since'] ) ) : ?>
				<p><?php esc_html_e( 'No address checked yet.', 'mailprobe' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					// One plural form per count: "1 registration refused", not "1 registrations".
					$checked = (int) $stats['checked'];
					$blocked = (int) $stats['blocked'];
					$flagged = (int) $stats['flagged'];
					$counts  = array(
						/* translators: %s: number of addresses. */
						sprintf( _n( '%s address checked', '%s addresses checked', $checked, 'mailprobe' ), number_format_i18n( $checked ) ),
						/* translators: %s: number of registrations. */
						sprintf( _n( '%s registration refused', '%s registrations refused', $blocked, 'mailprobe' ), number_format_i18n( $blocked ) ),
						/* translators: %s: number of orders. */
						sprintf( _n( '%s order flagged', '%s orders flagged', $flagged, 'mailprobe' ), number_format_i18n( $flagged ) ),
					);
					echo esc_html(
						sprintf(
							/* translators: 1: date, 2: the three counts as a list, for example "8 addresses checked, 2 registrations refused, and 1 order flagged". */
							__( 'Since %1$s: %2$s.', 'mailprobe' ),
							date_i18n( get_option( 'date_format' ), (int) $stats['since'] ),
							wp_sprintf( '%l', $counts )
						)
					);
					?>
				</p>
			<?php endif; ?>

			<p class="description">
				<?php
				printf(
					'%1$s <a href="%2$s" target="_blank" rel="noopener">%3$s</a> &middot; <a href="%4$s" target="_blank" rel="noopener">%5$s</a>',
					esc_html__( 'MailProbe on WordPress.org:', 'mailprobe' ),
					esc_url( self::SUPPORT_URL ),
					esc_html__( 'support forum', 'mailprobe' ),
					esc_url( self::REVIEW_URL ),
					esc_html__( 'leave a review', 'mailprobe' )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * The line under the key: the balance, or how to get a key.
	 */
	private static function render_connection() {
		if ( ! MailProbe::connected() ) {
			echo '<p>' . wp_kses(
				sprintf(
					/* translators: 1: sign-up URL. */
					__( 'No account yet? <a href="%1$s" target="_blank" rel="noopener">Create one on mailprobe.dev</a>: 100 checks are free. Then open the Developer tab of your MailProbe dashboard, create an API key and paste it here.', 'mailprobe' ),
					esc_url( self::SIGNUP_URL )
				),
				array(
					'a' => array(
						'href'   => true,
						'target' => true,
						'rel'    => true,
					),
				)
			) . '</p>';
			return;
		}
		$credits = MailProbe::client()->credits();
		if ( is_wp_error( $credits ) ) {
			echo '<p class="description">' . esc_html( $credits->get_error_message() ) . '</p>';
			return;
		}
		echo '<p class="description">' . wp_kses(
			sprintf(
				/* translators: 1: number of credits, 2: pricing URL. */
				_n( 'Connected: %1$s credit left. <a href="%2$s" target="_blank" rel="noopener">Buy credits</a>', 'Connected: %1$s credits left. <a href="%2$s" target="_blank" rel="noopener">Buy credits</a>', $credits, 'mailprobe' ),
				number_format_i18n( $credits ),
				esc_url( self::PRICING_URL )
			),
			array(
				'a' => array(
					'href'   => true,
					'target' => true,
					'rel'    => true,
				),
			)
		) . '</p>';
	}

	/**
	 * Two notices, for administrators only: a key is missing (plugins screen only),
	 * or the checks stopped (no credits, key refused) until someone acts.
	 */
	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? $screen->id : '';

		if ( ! MailProbe::connected() ) {
			if ( 'plugins' === $id ) {
				printf(
					'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
					esc_html__( 'MailProbe is active but not connected yet.', 'mailprobe' ),
					esc_url( self::page_url() ),
					esc_html__( 'Add your API key', 'mailprobe' )
				);
			}
			return;
		}

		$alert = MailProbe::alert();
		$where = array( 'dashboard', 'plugins', 'settings_page_' . self::PAGE, 'edit-shop_order', 'woocommerce_page_wc-orders' );
		if ( null === $alert || ! in_array( $id, $where, true ) ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=mailprobe_dismiss' ), 'mailprobe_dismiss' );
		echo '<div class="notice notice-warning"><p>';
		if ( 'mailprobe_no_credits' === $alert['code'] ) {
			echo esc_html__( 'MailProbe has no credits left: registrations and orders are no longer checked.', 'mailprobe' )
				. ' <a href="' . esc_url( self::PRICING_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Buy credits', 'mailprobe' ) . '</a>';
		} else {
			echo esc_html__( 'MailProbe refused the API key: registrations and orders are no longer checked.', 'mailprobe' )
				. ' <a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Check the key', 'mailprobe' ) . '</a>';
		}
		echo ' &middot; <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'mailprobe' ) . '</a></p></div>';
	}

	/**
	 * Dismisses the alert until the next error that stops the checks.
	 */
	public static function dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'mailprobe' ), 403 );
		}
		check_admin_referer( 'mailprobe_dismiss' );
		MailProbe::clear_alert();
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
