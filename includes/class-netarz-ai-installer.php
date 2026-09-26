<?php
/**
 * Tables, capabilities, cron and upgrades.
 *
 * @package NetArz_AI
 */

defined( 'ABSPATH' ) || exit;

class Netarz_AI_Installer {

	const CAP_SUPPORT = 'netarz_ai_support';

	const CAP_WRITER = 'netarz_ai_writer';

	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( $site_id );
				Netarz_AI_Settings::flush(); // The settings cache belongs to the previous site.
				self::install();
				restore_current_blog();
				Netarz_AI_Settings::flush();
			}
			return;
		}
		self::install();
	}

	public static function install() {
		self::create_tables();
		self::sync_caps();
		self::schedule();
		Netarz_AI_Tickets::ensure_page();
		update_option( 'netarz_ai_db_version', NETARZ_AI_DB_VERSION, false );
		// The WooCommerce "support" endpoint needs the rewrite rules rebuilt once.
		update_option( 'netarz_ai_flush_rewrite', 1, false );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'netarz_ai_daily' );
		wp_unschedule_hook( 'netarz_ai_ticket_ai' ); // Every event carries a ticket id; clear them all.
		flush_rewrite_rules();
	}

	/** Runs on every load; cheap when nothing changed. */
	public static function maybe_upgrade() {
		if ( get_option( 'netarz_ai_db_version' ) !== NETARZ_AI_DB_VERSION ) {
			self::install();
		}
		if ( ! wp_next_scheduled( 'netarz_ai_daily' ) ) {
			self::schedule();
		}
	}

	private static function schedule() {
		if ( ! wp_next_scheduled( 'netarz_ai_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'netarz_ai_daily' );
		}
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		// dbDelta is picky: two spaces after PRIMARY KEY, one field per line.
		dbDelta( "CREATE TABLE {$p}netarz_ai_chats (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL,
			token_hash char(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(120) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			mobile varchar(32) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'open',
			ai_enabled tinyint(1) NOT NULL DEFAULT 1,
			needs_human tinyint(1) NOT NULL DEFAULT 0,
			handoff_reason varchar(255) NOT NULL DEFAULT '',
			ai_replies int(11) NOT NULL DEFAULT 0,
			ai_seen_id bigint(20) unsigned NOT NULL DEFAULT 0,
			page_url varchar(500) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			ip_hash char(64) NOT NULL DEFAULT '',
			ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
			rating tinyint(1) NOT NULL DEFAULT 0,
			admin_seen_id bigint(20) unsigned NOT NULL DEFAULT 0,
			visitor_seen_id bigint(20) unsigned NOT NULL DEFAULT 0,
			agent_typing_at datetime DEFAULT NULL,
			visitor_seen_at datetime DEFAULT NULL,
			last_message_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY status_last (status,last_message_at),
			KEY email (email)
		) $charset;" );

		dbDelta( "CREATE TABLE {$p}netarz_ai_chat_messages (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			chat_id bigint(20) unsigned NOT NULL,
			sender varchar(10) NOT NULL,
			author varchar(120) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			body text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY chat_id (chat_id,id)
		) $charset;" );

		dbDelta( "CREATE TABLE {$p}netarz_ai_tickets (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			access_key char(32) NOT NULL,
			subject varchar(200) NOT NULL,
			department varchar(100) NOT NULL DEFAULT '',
			priority varchar(10) NOT NULL DEFAULT 'normal',
			status varchar(20) NOT NULL DEFAULT 'open',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(120) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(32) NOT NULL DEFAULT '',
			source varchar(10) NOT NULL DEFAULT 'form',
			order_ref varchar(60) NOT NULL DEFAULT '',
			assigned_to bigint(20) unsigned NOT NULL DEFAULT 0,
			ai_state varchar(20) NOT NULL DEFAULT '',
			ai_summary text,
			rating tinyint(1) NOT NULL DEFAULT 0,
			rating_note varchar(500) NOT NULL DEFAULT '',
			last_reply_by varchar(10) NOT NULL DEFAULT 'customer',
			last_reply_at datetime NOT NULL,
			closed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status,last_reply_at),
			KEY user_id (user_id),
			KEY email (email)
		) $charset;" );

		dbDelta( "CREATE TABLE {$p}netarz_ai_ticket_replies (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ticket_id bigint(20) unsigned NOT NULL,
			author_type varchar(10) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			author_name varchar(120) NOT NULL DEFAULT '',
			body longtext NOT NULL,
			attachments text,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY ticket_id (ticket_id,id)
		) $charset;" );

		dbDelta( "CREATE TABLE {$p}netarz_ai_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			feature varchar(40) NOT NULL,
			model varchar(120) NOT NULL DEFAULT '',
			endpoint varchar(40) NOT NULL DEFAULT '',
			status varchar(10) NOT NULL DEFAULT 'ok',
			error_code varchar(60) NOT NULL DEFAULT '',
			input_tokens int(11) NOT NULL DEFAULT 0,
			output_tokens int(11) NOT NULL DEFAULT 0,
			request_id varchar(64) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY feature (feature,created_at)
		) $charset;" );
	}

	/**
	 * Grant the plugin's two capabilities to the roles named in settings,
	 * and take them away from every other role.
	 */
	public static function sync_caps() {
		$wp_roles = wp_roles();
		$map      = array(
			self::CAP_SUPPORT => array_merge( array( 'administrator' ), Netarz_AI_Settings::csv( 'role_support' ) ),
			self::CAP_WRITER  => array_merge( array( 'administrator' ), Netarz_AI_Settings::csv( 'role_writer' ) ),
		);

		foreach ( $wp_roles->role_objects as $slug => $role ) {
			foreach ( $map as $cap => $roles ) {
				if ( in_array( $slug, $roles, true ) ) {
					if ( ! $role->has_cap( $cap ) ) {
						$role->add_cap( $cap );
					}
				} elseif ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}
}
