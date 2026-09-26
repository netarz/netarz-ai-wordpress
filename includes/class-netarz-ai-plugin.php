<?php
/**
 * Boots the modules.
 *
 * @package NetArz_AI
 */

defined( 'ABSPATH' ) || exit;

final class Netarz_AI_Plugin {

	/** @var Netarz_AI_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// WordPress 6.7+ wants translations loaded on init, not before; upgrades create a
		// translated page, so they wait for init as well.
		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 1 );
		add_action( 'init', array( 'Netarz_AI_Installer', 'maybe_upgrade' ), 5 );

		Netarz_AI_Chat::init();
		Netarz_AI_Tickets::init();
		Netarz_AI_Ticket_Agent::init();
		Netarz_AI_Writer::init();
		Netarz_AI_Privacy::init();

		if ( is_admin() ) {
			Netarz_AI_Admin::init();
		} else {
			// REST routes of the admin screens must exist on REST requests too.
			add_action( 'rest_api_init', array( 'Netarz_AI_Admin', 'routes' ) );
		}

		add_action( 'netarz_ai_daily', array( __CLASS__, 'prune_log' ) );
		add_action( 'netarz_ai_daily', array( 'Netarz_AI_Util', 'prune_counters' ) );

		// WooCommerce: the plugin reads orders only through the CRUD API, so it works with HPOS.
		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_woo_compat' ) );
	}

	public static function declare_woo_compat() {
		if ( class_exists( 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', NETARZ_AI_FILE, true );
		}
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'netarz-ai', false, dirname( plugin_basename( NETARZ_AI_FILE ) ) . '/languages' );
	}

	/** Keep the local usage log to its retention window. */
	public static function prune_log() {
		global $wpdb;
		$days = max( 1, (int) Netarz_AI_Settings::get( 'log_retention_days' ) );
		$wpdb->query( $wpdb->prepare( // phpcs:ignore
			"DELETE FROM {$wpdb->prefix}netarz_ai_log WHERE created_at < %s",
			gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
		) );
	}
}
