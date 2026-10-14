<?php
/**
 * The check of WooCommerce orders: after the order, never before it. The customer
 * does not wait for a verdict; the shop manager finds it on the order.
 *
 * @package MailProbe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verifies the billing email of each new order in the background, and flags the
 * order when the address cannot receive emails or is disposable.
 */
class MailProbe_Orders {

	/**
	 * Background action that checks one order.
	 */
	const ACTION = 'mailprobe_check_order';

	/**
	 * Action Scheduler group of the plugin.
	 */
	const GROUP = 'mailprobe';

	/**
	 * Order meta: `pending`, a word of MailProbe_Policy::classify(), or `skipped`.
	 */
	const META = '_mailprobe_check';

	/**
	 * Order meta: the corrected address when the billing email looks like a typo.
	 */
	const META_SUGGESTION = '_mailprobe_suggestion';

	/**
	 * Seconds asked of the API. Nobody waits in front of a background job, so the
	 * check can take the time slow mail servers need.
	 */
	const ANSWER_WITHIN = 30;

	/**
	 * Tries for an error that passes on its own (network, rate limit, server).
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Hooks into both checkouts, the background action and the orders list.
	 */
	public static function hooks() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_classic_checkout' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'schedule' ), 20, 1 );
		add_action( self::ACTION, array( __CLASS__, 'check' ), 10, 2 );

		// Orders list, with and without High-Performance Order Storage.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
	}

	/**
	 * The classic (shortcode) checkout.
	 *
	 * @param int      $order_id    The order ID.
	 * @param array    $posted_data The checkout fields.
	 * @param WC_Order $order       The order.
	 */
	public static function on_classic_checkout( $order_id, $posted_data = array(), $order = null ) {
		self::schedule( $order ? $order : $order_id );
	}

	/**
	 * Queues the check of an order, once.
	 *
	 * @param WC_Order|int $order The order or its ID.
	 */
	public static function schedule( $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		$settings = MailProbe::settings();
		if ( ! $order || empty( $settings['check_orders'] ) || ! MailProbe::connected() ) {
			return;
		}
		if ( '' === (string) $order->get_billing_email() || '' !== (string) $order->get_meta( self::META ) ) {
			return;
		}
		$order->update_meta_data( self::META, 'pending' );
		$order->save();
		self::enqueue( $order->get_id(), 1, 0 );
	}

	/**
	 * Queues the background action, through Action Scheduler (shipped with
	 * WooCommerce), WP-Cron as a last resort.
	 *
	 * @param int $order_id The order ID.
	 * @param int $attempt  The try about to be queued.
	 * @param int $delay    Seconds before it runs.
	 */
	private static function enqueue( $order_id, $attempt, $delay ) {
		$args = array(
			'order_id' => (int) $order_id,
			'attempt'  => (int) $attempt,
		);
		if ( 0 === $delay && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION, $args, self::GROUP );
		} elseif ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::ACTION, $args, self::GROUP );
		} else {
			wp_schedule_single_event( time() + $delay, self::ACTION, array_values( $args ) );
		}
	}

	/**
	 * The background action: checks the billing email and writes the verdict on
	 * the order.
	 *
	 * @param int $order_id The order ID.
	 * @param int $attempt  The try, from 1.
	 */
	public static function check( $order_id, $attempt = 1 ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'pending' !== $order->get_meta( self::META ) ) {
			return;
		}
		$email = (string) $order->get_billing_email();
		if ( '' === $email || ! MailProbe::connected() ) {
			$order->delete_meta_data( self::META );
			$order->save();
			return;
		}

		$result = MailProbe::client()->verify( $email, self::ANSWER_WITHIN );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && ! empty( $data['transient'] ) && $attempt < self::MAX_ATTEMPTS ) {
				self::enqueue( $order_id, $attempt + 1, ! empty( $data['retry'] ) ? (int) $data['retry'] : 5 * MINUTE_IN_SECONDS );
				return;
			}
			MailProbe::note_error( $result );
			$order->update_meta_data( self::META, 'skipped' );
			$order->add_order_note(
				/* translators: %s: why the check could not be made. */
				sprintf( __( 'MailProbe could not check the billing email: %s', 'mailprobe' ), $result->get_error_message() )
			);
			$order->save();
			return;
		}

		MailProbe::clear_alert();
		MailProbe::count( 'checked' );
		$word       = MailProbe_Policy::classify( $result );
		$suggestion = MailProbe_Policy::suggestion( $result );
		$order->update_meta_data( self::META, $word );
		if ( null !== $suggestion ) {
			$order->update_meta_data( self::META_SUGGESTION, $suggestion );
		}
		if ( MailProbe_Policy::order_flagged( $word ) ) {
			MailProbe::count( 'flagged' );
			$order->add_order_note( self::note( $word, $email, $result, $suggestion ) );
		}
		$order->save();

		/**
		 * An order was checked. For a site that wants more than the flag, for
		 * example holding the flagged orders.
		 *
		 * @param WC_Order $order  The order.
		 * @param string   $word   `valid`, `risky`, `unknown`, `role`, `invalid` or `disposable`.
		 * @param array    $result The MailProbe result.
		 */
		do_action( 'mailprobe_order_checked', $order, $word, $result );
	}

	/**
	 * The private note left on a flagged order.
	 *
	 * @param string      $word       `invalid` or `disposable`.
	 * @param string      $email      The billing email.
	 * @param array       $result     The MailProbe result.
	 * @param string|null $suggestion The corrected address, if any.
	 * @return string
	 */
	private static function note( $word, $email, $result, $suggestion ) {
		if ( 'disposable' === $word ) {
			/* translators: %s: the billing email. */
			$note = sprintf( __( 'MailProbe: the billing email %s is a disposable address.', 'mailprobe' ), $email );
		} else {
			/* translators: 1: the billing email, 2: why, for example "the domain has no mail server". */
			$note = sprintf( __( 'MailProbe: the billing email %1$s cannot receive emails (%2$s).', 'mailprobe' ), $email, MailProbe_Policy::reason_text( $result ) );
		}
		if ( null !== $suggestion ) {
			/* translators: %s: the email address with the likely typo corrected. */
			$note .= ' ' . sprintf( __( 'Did the customer mean %s?', 'mailprobe' ), $suggestion );
		}
		return $note;
	}

	/**
	 * Adds the "Email check" column after the status.
	 *
	 * @param array $columns The columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$with = array();
		foreach ( $columns as $key => $label ) {
			$with[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$with['mailprobe'] = __( 'Email check', 'mailprobe' );
			}
		}
		if ( ! isset( $with['mailprobe'] ) ) {
			$with['mailprobe'] = __( 'Email check', 'mailprobe' );
		}
		return $with;
	}

	/**
	 * Fills the column, with the colours WooCommerce gives order statuses.
	 *
	 * @param string           $column The column.
	 * @param WC_Order|int|mixed $order  The order (orders table) or its ID (posts table).
	 */
	public static function render_column( $column, $order ) {
		if ( 'mailprobe' !== $column ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		$word   = $order ? (string) $order->get_meta( self::META ) : '';
		$labels = array(
			'pending'    => array( __( 'Checking', 'mailprobe' ), 'status-pending' ),
			'valid'      => array( __( 'Valid', 'mailprobe' ), 'status-completed' ),
			'risky'      => array( __( 'Unconfirmed', 'mailprobe' ), 'status-on-hold' ),
			'unknown'    => array( __( 'Unknown', 'mailprobe' ), 'status-on-hold' ),
			'role'       => array( __( 'Shared address', 'mailprobe' ), 'status-processing' ),
			'invalid'    => array( __( 'Cannot receive email', 'mailprobe' ), 'status-failed' ),
			'disposable' => array( __( 'Disposable', 'mailprobe' ), 'status-failed' ),
			'skipped'    => array( __( 'Not checked', 'mailprobe' ), 'status-cancelled' ),
		);
		if ( ! isset( $labels[ $word ] ) ) {
			echo '&ndash;';
			return;
		}
		printf( '<mark class="order-status %1$s"><span>%2$s</span></mark>', esc_attr( $labels[ $word ][1] ), esc_html( $labels[ $word ][0] ) );
	}
}
