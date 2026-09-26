<?php
/**
 * WordPress privacy tools: export and erase a person's chats and tickets by email.
 *
 * @package NetArz_AI
 */

defined( 'ABSPATH' ) || exit;

class Netarz_AI_Privacy {

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	public static function register_exporter( $exporters ) {
		$exporters['netarz-ai'] = array(
			'exporter_friendly_name' => __( 'گفت‌وگوها و تیکت‌های پشتیبانی', 'netarz-ai' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['netarz-ai'] = array(
			'eraser_friendly_name' => __( 'گفت‌وگوها و تیکت‌های پشتیبانی', 'netarz-ai' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/** @return int[] user id for this email, or 0 */
	private static function user_id( $email ) {
		$user = get_user_by( 'email', $email );
		return $user ? (int) $user->ID : 0;
	}

	public static function export( $email, $page = 1 ) {
		global $wpdb;
		$items   = array();
		$user_id = self::user_id( $email );

		$chats = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Netarz_AI_Chat::table() . ' WHERE email = %s OR ( user_id > 0 AND user_id = %d )', $email, $user_id ) ); // phpcs:ignore
		foreach ( $chats as $chat ) {
			$lines = array();
			foreach ( Netarz_AI_Chat::messages( $chat->id, 0, 1000 ) as $m ) {
				$lines[] = $m->sender . ': ' . $m->body;
			}
			$items[] = array(
				'group_id'    => 'netarz-ai-chats',
				'group_label' => __( 'گفت‌وگوهای آنلاین', 'netarz-ai' ),
				'item_id'     => 'chat-' . $chat->id,
				'data'        => array(
					array( 'name' => __( 'نام', 'netarz-ai' ), 'value' => $chat->name ),
					array( 'name' => __( 'موبایل', 'netarz-ai' ), 'value' => $chat->mobile ),
					array( 'name' => __( 'تاریخ', 'netarz-ai' ), 'value' => $chat->created_at ),
					array( 'name' => __( 'متن گفت‌وگو', 'netarz-ai' ), 'value' => implode( "\n", $lines ) ),
				),
			);
		}

		$tickets = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Netarz_AI_Tickets::table() . ' WHERE email = %s OR ( user_id > 0 AND user_id = %d )', $email, $user_id ) ); // phpcs:ignore
		foreach ( $tickets as $ticket ) {
			$lines = array();
			foreach ( Netarz_AI_Tickets::replies( $ticket->id, false ) as $r ) {
				$lines[] = $r->author_type . ': ' . $r->body;
			}
			$items[] = array(
				'group_id'    => 'netarz-ai-tickets',
				'group_label' => __( 'تیکت‌های پشتیبانی', 'netarz-ai' ),
				'item_id'     => 'ticket-' . $ticket->id,
				'data'        => array(
					array( 'name' => __( 'موضوع', 'netarz-ai' ), 'value' => $ticket->subject ),
					array( 'name' => __( 'نام', 'netarz-ai' ), 'value' => $ticket->name ),
					array( 'name' => __( 'موبایل', 'netarz-ai' ), 'value' => $ticket->phone ),
					array( 'name' => __( 'تاریخ', 'netarz-ai' ), 'value' => $ticket->created_at ),
					array( 'name' => __( 'پیام‌ها', 'netarz-ai' ), 'value' => implode( "\n\n", $lines ) ),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		$user_id = self::user_id( $email );
		$removed = 0;

		$chat_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Netarz_AI_Chat::table() . ' WHERE email = %s OR ( user_id > 0 AND user_id = %d )', $email, $user_id ) ); // phpcs:ignore
		foreach ( $chat_ids as $id ) {
			$wpdb->delete( Netarz_AI_Chat::messages_table(), array( 'chat_id' => (int) $id ), array( '%d' ) ); // phpcs:ignore
			$wpdb->delete( Netarz_AI_Chat::table(), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore
			$removed++;
		}

		$ticket_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Netarz_AI_Tickets::table() . ' WHERE email = %s OR ( user_id > 0 AND user_id = %d )', $email, $user_id ) ); // phpcs:ignore
		foreach ( $ticket_ids as $id ) {
			Netarz_AI_Tickets::delete_files( Netarz_AI_Tickets::replies( (int) $id, true ) );
			$wpdb->delete( Netarz_AI_Tickets::replies_table(), array( 'ticket_id' => (int) $id ), array( '%d' ) ); // phpcs:ignore
			$wpdb->delete( Netarz_AI_Tickets::table(), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore
			$removed++;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	public static function policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'هوش مصنوعی نِت اَرز', 'netarz-ai' ),
			'<p>' . esc_html__( 'وقتی از گفت‌وگوی آنلاین یا فرم تیکت استفاده می‌کنید، نام، راه تماس و متن پیام‌هایتان روی همین سایت ذخیره می‌شود. برای پاسخ‌گویی خودکار، متن پیام و بخش‌های مرتبط از مطالب سایت برای وب‌سرویس هوش مصنوعی نِت اَرز (netarz.ir) فرستاده می‌شود و از آن‌جا به شرکت سازندهٔ مدل هوش مصنوعی می‌رسد. نشانی IP به صورت درهم‌سازی‌شده و فقط برای جلوگیری از سوءاستفاده نگه داشته می‌شود.', 'netarz-ai' ) . '</p>'
		);
	}
}
