<?php
/**
 * Removes what the plugin stored: its settings, counters and alert, and the
 * checks still queued. The verdicts written on orders stay: they belong to the
 * orders.
 *
 * @package MailProbe
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'mailprobe_settings' );
delete_option( 'mailprobe_stats' );
delete_option( 'mailprobe_alert' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'mailprobe_check_order' );
}
wp_clear_scheduled_hook( 'mailprobe_check_order' );
