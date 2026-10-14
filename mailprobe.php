<?php
/**
 * Plugin Name:          MailProbe
 * Plugin URI:           https://mailprobe.dev/
 * Description:          Refuses registrations with an email address that cannot receive emails or is disposable, and flags WooCommerce orders whose billing email has the same problem. Checks by MailProbe, hosted in the EU (France).
 * Version:              0.1.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Author:               Iris Digital
 * Author URI:           https://irisdigital.tech/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          mailprobe
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 *
 * @package MailProbe
 */

defined( 'ABSPATH' ) || exit;

define( 'MAILPROBE_VERSION', '0.1.0' );
define( 'MAILPROBE_FILE', __FILE__ );
if ( ! defined( 'MAILPROBE_API_BASE' ) ) {
	define( 'MAILPROBE_API_BASE', 'https://mailprobe.dev/api/v1' );
}

require_once __DIR__ . '/includes/class-mailprobe-client.php';
require_once __DIR__ . '/includes/class-mailprobe-policy.php';
require_once __DIR__ . '/includes/class-mailprobe.php';
require_once __DIR__ . '/includes/class-mailprobe-registration.php';
require_once __DIR__ . '/includes/class-mailprobe-orders.php';
require_once __DIR__ . '/includes/class-mailprobe-admin.php';

add_action(
	'plugins_loaded',
	static function () {
		MailProbe_Registration::hooks();
		if ( class_exists( 'WooCommerce' ) ) {
			MailProbe_Orders::hooks();
		}
		if ( is_admin() ) {
			MailProbe_Admin::hooks();
		}
	}
);

// Compatible with the orders tables (HPOS) and the block checkout.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MAILPROBE_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MAILPROBE_FILE, true );
		}
	}
);
