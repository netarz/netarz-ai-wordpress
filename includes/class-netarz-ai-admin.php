<?php
/**
 * wp-admin: menus, dashboard, inbox, tickets, writer, images, log and settings.
 *
 * @package NetArz_AI
 */

defined( 'ABSPATH' ) || exit;

class Netarz_AI_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_netarz_ai_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_netarz_ai_test', array( __CLASS__, 'test_connection' ) );
		add_action( 'admin_post_netarz_ai_ticket', array( __CLASS__, 'ticket_action' ) );
		add_action( 'admin_post_netarz_ai_dismiss', array( __CLASS__, 'dismiss' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NETARZ_AI_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Menu                                                                */
	/* ------------------------------------------------------------------ */

	/** Items that wait for a person: conversations + tickets. */
	private static function waiting_counts() {
		global $wpdb;
		$chats   = Netarz_AI_Settings::get( 'chat_enabled' ) ? Netarz_AI_Chat::unread_count() : 0;
		$tickets = Netarz_AI_Settings::get( 'tickets_enabled' )
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Netarz_AI_Tickets::table() . " WHERE status IN ('open','customer_reply')" ) // phpcs:ignore
			: 0;
		return array( $chats, $tickets );
	}

	private static function bubble( $count, $class = '' ) {
		return $count > 0 ? ' <span class="awaiting-mod ' . esc_attr( $class ) . '"><span class="nzai-count">' . (int) $count . '</span></span>' : '';
	}

	public static function menu() {
		$support = Netarz_AI_Installer::CAP_SUPPORT;
		$writer  = Netarz_AI_Installer::CAP_WRITER;
		$first   = current_user_can( 'manage_options' ) ? 'manage_options' : ( current_user_can( $support ) ? $support : $writer );

		list( $chats, $tickets ) = self::waiting_counts();

		add_menu_page(
			__( 'هوش مصنوعی نِت اَرز', 'netarz-ai' ),
			__( 'هوش مصنوعی', 'netarz-ai' ) . self::bubble( $chats + $tickets ),
			$first,
			'netarz-ai',
			array( __CLASS__, 'page_dashboard' ),
			self::menu_icon(),
			58
		);

		add_submenu_page( 'netarz-ai', __( 'پیشخوان', 'netarz-ai' ), __( 'پیشخوان', 'netarz-ai' ), $first, 'netarz-ai', array( __CLASS__, 'page_dashboard' ) );
		if ( Netarz_AI_Settings::get( 'chat_enabled' ) ) {
			add_submenu_page( 'netarz-ai', __( 'گفت‌وگوهای آنلاین', 'netarz-ai' ), __( 'گفت‌وگوها', 'netarz-ai' ) . self::bubble( $chats, 'nzai-chat-bubble' ), $support, 'netarz-ai-inbox', array( __CLASS__, 'page_inbox' ) );
		}
		if ( Netarz_AI_Settings::get( 'tickets_enabled' ) ) {
			add_submenu_page( 'netarz-ai', __( 'تیکت‌ها', 'netarz-ai' ), __( 'تیکت‌ها', 'netarz-ai' ) . self::bubble( $tickets ), $support, 'netarz-ai-tickets', array( __CLASS__, 'page_tickets' ) );
		}
		if ( Netarz_AI_Settings::get( 'writer_enabled' ) ) {
			add_submenu_page( 'netarz-ai', __( 'نویسندهٔ هوشمند', 'netarz-ai' ), __( 'نویسندهٔ هوشمند', 'netarz-ai' ), $writer, 'netarz-ai-writer', array( __CLASS__, 'page_writer' ) );
			add_submenu_page( 'netarz-ai', __( 'ساخت تصویر', 'netarz-ai' ), __( 'ساخت تصویر', 'netarz-ai' ), $writer, 'netarz-ai-images', array( __CLASS__, 'page_images' ) );
		}
		add_submenu_page( 'netarz-ai', __( 'گزارش مصرف', 'netarz-ai' ), __( 'گزارش مصرف', 'netarz-ai' ), 'manage_options', 'netarz-ai-log', array( __CLASS__, 'page_log' ) );
		add_submenu_page( 'netarz-ai', __( 'تنظیمات', 'netarz-ai' ), __( 'تنظیمات', 'netarz-ai' ), 'manage_options', 'netarz-ai-settings', array( __CLASS__, 'page_settings' ) );
	}

	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="black" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=netarz-ai-settings' ) ) . '">' . esc_html__( 'تنظیمات', 'netarz-ai' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		// The menu badge refreshes itself on every admin screen.
		if ( current_user_can( Netarz_AI_Installer::CAP_SUPPORT ) && Netarz_AI_Settings::get( 'chat_enabled' ) ) {
			wp_enqueue_script( 'netarz-ai-badge', NETARZ_AI_URL . 'assets/js/admin-badge.js', array( 'wp-api-fetch' ), NETARZ_AI_VERSION, true );
			wp_localize_script( 'netarz-ai-badge', 'NetarzAIBadge', array(
				'path'  => '/' . Netarz_AI_Chat::NS . '/inbox/unread',
				'inbox' => false !== strpos( (string) $hook, 'netarz-ai-inbox' ),
			) );
		}

		if ( false === strpos( (string) $hook, 'netarz-ai' ) ) {
			return;
		}
		wp_enqueue_style( 'netarz-ai-admin', NETARZ_AI_URL . 'assets/css/admin.css', array(), NETARZ_AI_VERSION );

		if ( false !== strpos( $hook, 'netarz-ai-inbox' ) ) {
			wp_enqueue_script( 'netarz-ai-inbox', NETARZ_AI_URL . 'assets/js/admin-inbox.js', array( 'wp-api-fetch' ), NETARZ_AI_VERSION, true );
			wp_localize_script( 'netarz-ai-inbox', 'NetarzAIInbox', array(
				'ns'      => '/' . Netarz_AI_Chat::NS,
				'open'    => isset( $_GET['chat'] ) ? absint( $_GET['chat'] ) : 0, // phpcs:ignore
				'isAdmin' => current_user_can( 'manage_options' ),
				'aiReady' => Netarz_AI_Api::has_key(),
				'i18n'    => self::inbox_strings(),
			) );
		}
		if ( false !== strpos( $hook, 'netarz-ai-tickets' ) ) {
			wp_enqueue_script( 'netarz-ai-tickets-admin', NETARZ_AI_URL . 'assets/js/admin-tickets.js', array( 'jquery', 'wp-api-fetch' ), NETARZ_AI_VERSION, true );
			wp_localize_script( 'netarz-ai-tickets-admin', 'NetarzAITickets', array(
				'ns'     => '/' . Netarz_AI_Chat::NS,
				'canned' => Netarz_AI_Settings::canned_replies(),
				'i18n'   => array(
					'working'  => __( 'در حال نوشتن…', 'netarz-ai' ),
					'error'    => __( 'خطا', 'netarz-ai' ),
					'confirm'  => __( 'این تیکت و همهٔ پیوست‌هایش برای همیشه حذف شود؟', 'netarz-ai' ),
					'overwrite' => __( 'متن فعلی پاسخ جایگزین شود؟', 'netarz-ai' ),
				),
			) );
		}
	}

	private static function inbox_strings() {
		return array(
			'all'        => __( 'همه', 'netarz-ai' ),
			'waiting'    => __( 'منتظر همکار', 'netarz-ai' ),
			'open'       => __( 'باز', 'netarz-ai' ),
			'closed'     => __( 'بسته', 'netarz-ai' ),
			'search'     => __( 'جست‌وجو در نام، تماس یا متن…', 'netarz-ai' ),
			'empty'      => __( 'گفت‌وگویی نیست.', 'netarz-ai' ),
			'pick'       => __( 'یک گفت‌وگو را از فهرست انتخاب کنید.', 'netarz-ai' ),
			'reply'      => __( 'پاسخ شما… (Enter ارسال، Shift+Enter خط تازه)', 'netarz-ai' ),
			'send'       => __( 'ارسال', 'netarz-ai' ),
			'aiOn'       => __( 'دستیار روشن', 'netarz-ai' ),
			'aiOff'      => __( 'دستیار خاموش', 'netarz-ai' ),
			'aiGlobalOff' => __( 'دستیار در تنظیمات خاموش است', 'netarz-ai' ),
			'close'      => __( 'بستن گفت‌وگو', 'netarz-ai' ),
			'reopen'     => __( 'باز کردن دوباره', 'netarz-ai' ),
			'ticket'     => __( 'تبدیل به تیکت', 'netarz-ai' ),
			'openTicket' => __( 'دیدن تیکت', 'netarz-ai' ),
			'delete'     => __( 'حذف', 'netarz-ai' ),
			'confirmDel' => __( 'این گفت‌وگو برای همیشه حذف شود؟', 'netarz-ai' ),
			'draft'      => __( 'پیشنهاد پاسخ', 'netarz-ai' ),
			'drafting'   => __( 'در حال نوشتن پیشنهاد…', 'netarz-ai' ),
			'visitor'    => __( 'بازدیدکننده', 'netarz-ai' ),
			'ai'         => __( 'دستیار', 'netarz-ai' ),
			'agent'      => __( 'همکار', 'netarz-ai' ),
			'online'     => __( 'روی صفحه است', 'netarz-ai' ),
			'offline'    => __( 'بیرون از صفحه', 'netarz-ai' ),
			'reason'     => __( 'علت سپردن به همکار:', 'netarz-ai' ),
			'page'       => __( 'صفحه', 'netarz-ai' ),
			'started'    => __( 'شروع', 'netarz-ai' ),
			'rating'     => __( 'امتیاز', 'netarz-ai' ),
			'more'       => __( 'بیشتر', 'netarz-ai' ),
			'seen'       => __( 'دیده شد', 'netarz-ai' ),
			'error'      => __( 'خطا', 'netarz-ai' ),
			'newMsg'     => __( 'پیام تازه', 'netarz-ai' ),
			'back'       => __( 'بازگشت', 'netarz-ai' ),
			'user'       => __( 'حساب کاربری', 'netarz-ai' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Notices                                                             */
	/* ------------------------------------------------------------------ */

	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen  = get_current_screen();
		$on_ours = $screen && false !== strpos( (string) $screen->id, 'netarz-ai' );

		if ( ! Netarz_AI_Api::has_key() ) {
			if ( $on_ours || ( $screen && 'plugins' === $screen->id ) ) {
				printf(
					'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
					esc_html__( 'برای فعال شدن امکانات هوش مصنوعی، کلید API نِت اَرز را وارد کنید.', 'netarz-ai' ),
					esc_url( admin_url( 'admin.php?page=netarz-ai-settings' ) ),
					esc_html__( 'وارد کردن کلید', 'netarz-ai' )
				);
			}
			return;
		}

		// Low credit: from the cached account call, never a fresh request per page.
		$me = get_transient( 'netarz_ai_me' );
		$out = (bool) get_transient( 'netarz_ai_out_of_credit' );
		$low = is_array( $me ) && isset( $me['balance']['usd'] ) && (float) $me['balance']['usd'] < (float) Netarz_AI_Settings::get( 'low_credit_usd' );
		if ( ( $out || $low ) && get_transient( 'netarz_ai_dismiss_credit_' . get_current_user_id() ) === false ) {
			$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=netarz_ai_dismiss' ), 'netarz_ai_dismiss' );
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s <a class="button button-primary" href="%s" target="_blank" rel="noopener">%s</a> <a href="%s">%s</a></p></div>',
				esc_html__( 'هوش مصنوعی نِت اَرز:', 'netarz-ai' ),
				$out ? esc_html__( 'اعتبار تمام شده و دستیار نمی‌تواند جواب بدهد.', 'netarz-ai' ) : esc_html( sprintf(
					/* translators: %s: balance */
					__( 'اعتبار رو به اتمام است (%s).', 'netarz-ai' ),
					isset( $me['balance']['usd_display'] ) ? $me['balance']['usd_display'] : ''
				) ),
				esc_url( Netarz_AI_Api::TOPUP_URL ),
				esc_html__( 'افزایش اعتبار', 'netarz-ai' ),
				esc_url( $dismiss ),
				esc_html__( 'تا فردا نشان نده', 'netarz-ai' )
			);
		}
	}

	public static function dismiss() {
		check_admin_referer( 'netarz_ai_dismiss' );
		set_transient( 'netarz_ai_dismiss_credit_' . get_current_user_id(), 1, DAY_IN_SECONDS );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Dashboard                                                           */
	/* ------------------------------------------------------------------ */

	private static function header( $title, $subtitle = '' ) {
		echo '<div class="wrap nzai-wrap" dir="rtl">';
		echo '<div class="nzai-hero"><div class="nzai-hero-mark">' . self::spark() . '</div><div><h1>' . esc_html( $title ) . '</h1>';
		if ( $subtitle ) {
			echo '<p>' . esc_html( $subtitle ) . '</p>';
		}
		echo '</div></div>';
		settings_errors( 'netarz_ai' );
		self::flash();
	}

	private static function footer() {
		echo '<p class="nzai-foot">' . esc_html__( 'همهٔ درخواست‌های هوش مصنوعی از سرور سایت شما مستقیم به وب‌سرویس نِت اَرز می‌رود؛ کلید API هرگز به مرورگر بازدیدکننده نمی‌رسد.', 'netarz-ai' ) . '</p></div>';
	}

	private static function spark() {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/></svg>';
	}

	private static function flash() {
		$msg = get_transient( 'netarz_ai_admin_msg_' . get_current_user_id() );
		if ( is_array( $msg ) ) {
			delete_transient( 'netarz_ai_admin_msg_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'error' === $msg['type'] ? 'error' : 'success', esc_html( $msg['text'] ) );
		}
	}

	private static function redirect( $url, $text, $type = 'success' ) {
		set_transient( 'netarz_ai_admin_msg_' . get_current_user_id(), array(
			'text' => $text,
			'type' => $type,
		), MINUTE_IN_SECONDS );
		wp_safe_redirect( $url );
		exit;
	}

	public static function page_dashboard() {
		global $wpdb;
		self::header( __( 'هوش مصنوعی نِت اَرز', 'netarz-ai' ), __( 'چت پشتیبانی، تیکتینگ و ابزارهای محتوا — با اعتبار حساب نِت اَرز شما.', 'netarz-ai' ) );

		$has_key = Netarz_AI_Api::has_key();
		$me      = $has_key ? Netarz_AI_Api::me( isset( $_GET['refresh'] ) ) : null; // phpcs:ignore
		$is_mgr  = current_user_can( 'manage_options' );

		echo '<div class="nzai-grid">';

		// Credit card.
		echo '<section class="nzai-card nzai-credit">';
		echo '<h2>' . esc_html__( 'اعتبار هوش مصنوعی', 'netarz-ai' ) . '</h2>';
		if ( ! $has_key ) {
			echo '<p class="nzai-muted">' . esc_html__( 'هنوز به حساب نِت اَرز وصل نشده‌اید.', 'netarz-ai' ) . '</p>';
			echo '<ol class="nzai-steps"><li>' . wp_kses_post( sprintf(
				/* translators: %s: link */
				__( 'در %s ثبت‌نام کنید و اعتبار بخرید.', 'netarz-ai' ),
				'<a href="' . esc_url( Netarz_AI_Api::PANEL_URL ) . '" target="_blank" rel="noopener">netarz.ir/ai</a>'
			) ) . '</li><li>' . esc_html__( 'یک پروژه و یک کلید API بسازید (کلید با sk-ntz-v1- شروع می‌شود).', 'netarz-ai' ) . '</li><li>' . esc_html__( 'کلید را در تنظیمات افزونه وارد کنید.', 'netarz-ai' ) . '</li></ol>';
			if ( $is_mgr ) {
				echo '<p><a class="button button-primary button-hero" href="' . esc_url( admin_url( 'admin.php?page=netarz-ai-settings' ) ) . '">' . esc_html__( 'وارد کردن کلید API', 'netarz-ai' ) . '</a></p>';
			}
		} elseif ( is_wp_error( $me ) ) {
			echo '<p class="nzai-bad">' . esc_html( Netarz_AI_Api::friendly_error( $me ) ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( add_query_arg( 'refresh', 1 ) ) . '">' . esc_html__( 'دوباره امتحان کنید', 'netarz-ai' ) . '</a></p>';
		} else {
			$usd   = isset( $me['balance']['usd_display'] ) ? $me['balance']['usd_display'] : '';
			$toman = isset( $me['balance']['toman_estimate'] ) ? (int) $me['balance']['toman_estimate'] : 0;
			$low   = isset( $me['balance']['usd'] ) && (float) $me['balance']['usd'] < (float) Netarz_AI_Settings::get( 'low_credit_usd' );
			echo '<div class="nzai-balance' . ( $low ? ' is-low' : '' ) . '"><span class="nzai-balance-usd" dir="ltr">' . esc_html( $usd ) . '</span>';
			/* translators: %s: toman amount */
			echo '<span class="nzai-balance-toman">' . esc_html( sprintf( __( 'حدود %s تومان', 'netarz-ai' ), number_format_i18n( $toman ) ) ) . '</span></div>';
			echo '<dl class="nzai-dl">';
			if ( ! empty( $me['project']['name'] ) ) {
				echo '<dt>' . esc_html__( 'پروژه', 'netarz-ai' ) . '</dt><dd>' . esc_html( $me['project']['name'] ) . '</dd>';
			}
			if ( ! empty( $me['key']['prefix'] ) ) {
				echo '<dt>' . esc_html__( 'کلید', 'netarz-ai' ) . '</dt><dd dir="ltr"><code>' . esc_html( $me['key']['prefix'] ) . '…</code></dd>';
			}
			if ( isset( $me['key']['spend_limit_usd'] ) && null !== $me['key']['spend_limit_usd'] ) {
				/* translators: 1: spent, 2: limit */
				echo '<dt>' . esc_html__( 'سقف هزینهٔ کلید', 'netarz-ai' ) . '</dt><dd dir="ltr">$' . esc_html( number_format( (float) $me['key']['spent_usd'], 2 ) ) . ' / $' . esc_html( $me['key']['spend_limit_usd'] ) . '</dd>';
			}
			if ( ! empty( $me['key']['rpm_limit'] ) ) {
				echo '<dt>' . esc_html__( 'سقف درخواست در دقیقه', 'netarz-ai' ) . '</dt><dd>' . esc_html( number_format_i18n( (int) $me['key']['rpm_limit'] ) ) . '</dd>';
			}
			echo '</dl>';
			echo '<p class="nzai-actions"><a class="button button-primary" href="' . esc_url( Netarz_AI_Api::TOPUP_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'افزایش اعتبار', 'netarz-ai' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( Netarz_AI_Api::PANEL_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'پنل نِت اَرز', 'netarz-ai' ) . '</a> ';
			echo '<a class="button-link" href="' . esc_url( add_query_arg( 'refresh', 1 ) ) . '">' . esc_html__( 'به‌روزرسانی', 'netarz-ai' ) . '</a></p>';
			echo '<p class="nzai-muted nzai-small">' . esc_html__( 'اعتبار به دلار نگه داشته می‌شود و هر درخواست به اندازهٔ مصرف واقعی از آن کم می‌شود. برای کنترل هزینه، در پنل نِت اَرز برای کلید سقف هزینه بگذارید.', 'netarz-ai' ) . '</p>';
		}
		echo '</section>';

		// Today at a glance.
		$today_utc = gmdate( 'Y-m-d H:i:s', strtotime( wp_date( 'Y-m-d 00:00:00' ) . ' ' . wp_timezone_string() ) );
		$chats_today = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Netarz_AI_Chat::table() . ' WHERE created_at >= %s', $today_utc ) ); // phpcs:ignore
		$ai_today    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}netarz_ai_log WHERE status = 'ok' AND created_at >= %s", $today_utc ) ); // phpcs:ignore
		$err_today   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}netarz_ai_log WHERE status = 'error' AND created_at >= %s", $today_utc ) ); // phpcs:ignore
		list( $waiting_chats, $open_tickets ) = self::waiting_counts();

		echo '<section class="nzai-card nzai-stats"><h2>' . esc_html__( 'امروز', 'netarz-ai' ) . '</h2><div class="nzai-stat-grid">';
		self::stat( __( 'گفت‌وگوی تازه', 'netarz-ai' ), $chats_today, admin_url( 'admin.php?page=netarz-ai-inbox' ) );
		self::stat( __( 'گفت‌وگوی منتظر همکار', 'netarz-ai' ), $waiting_chats, admin_url( 'admin.php?page=netarz-ai-inbox' ), $waiting_chats > 0 );
		self::stat( __( 'تیکت منتظر پاسخ', 'netarz-ai' ), $open_tickets, admin_url( 'admin.php?page=netarz-ai-tickets' ), $open_tickets > 0 );
		self::stat( __( 'درخواست هوش مصنوعی', 'netarz-ai' ), $ai_today, $is_mgr ? admin_url( 'admin.php?page=netarz-ai-log' ) : '' );
		if ( $err_today ) {
			self::stat( __( 'درخواست ناموفق', 'netarz-ai' ), $err_today, $is_mgr ? admin_url( 'admin.php?page=netarz-ai-log&status=error' ) : '', true );
		}
		echo '</div></section>';

		// Modules.
		$s = Netarz_AI_Settings::all();
		echo '<section class="nzai-card nzai-modules"><h2>' . esc_html__( 'امکانات', 'netarz-ai' ) . '</h2><ul>';
		self::module( __( 'چت پشتیبانی آنلاین', 'netarz-ai' ), $s['chat_enabled'], __( 'ویجت چت در همهٔ صفحه‌ها، با دستیار هوشمند و سپردن به همکار.', 'netarz-ai' ), 'chat' );
		self::module( __( 'دستیار هوشمند چت', 'netarz-ai' ), $s['chat_enabled'] && $s['chat_ai_enabled'], __( 'از روی دانش و مطالب سایت جواب می‌دهد و هرجا مطمئن نبود، گفت‌وگو را به شما می‌سپارد.', 'netarz-ai' ), 'chat' );
		self::module( __( 'تیکتینگ', 'netarz-ai' ), $s['tickets_enabled'], __( 'ثبت و پیگیری تیکت برای مهمان و عضو، پیوست، بخش‌ها و امتیازدهی.', 'netarz-ai' ), 'tickets' );
		/* translators: %s: mode */
		self::module( __( 'هوش مصنوعی تیکت', 'netarz-ai' ), $s['tickets_enabled'] && 'off' !== $s['tickets_ai_mode'], sprintf( __( 'حالت فعلی: %s', 'netarz-ai' ), self::option_label( 'tickets_ai_mode', $s['tickets_ai_mode'] ) ), 'tickets' );
		self::module( __( 'نویسندهٔ هوشمند و ساخت تصویر', 'netarz-ai' ), $s['writer_enabled'], __( 'مقاله، بازنویسی، سئو، توضیح محصول، ترجمه، تصویر شاخص و متن جایگزین.', 'netarz-ai' ), 'writer' );
		echo '</ul></section>';

		echo '</div>';

		// Usage chart (last 30 days, from NetArz).
		if ( $has_key && ! is_wp_error( $me ) ) {
			self::usage_chart();
		}

		if ( Netarz_AI_Settings::get( 'tickets_enabled' ) ) {
			$page = (int) Netarz_AI_Settings::get( 'tickets_page_id' );
			echo '<section class="nzai-card"><h2>' . esc_html__( 'نشانی‌ها و کدهای کوتاه', 'netarz-ai' ) . '</h2><ul class="nzai-list">';
			if ( $page && get_post( $page ) ) {
				echo '<li>' . esc_html__( 'صفحهٔ پشتیبانی و تیکت:', 'netarz-ai' ) . ' <a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank">' . esc_html( get_permalink( $page ) ) . '</a></li>';
			}
			echo '<li><code>[netarz_ai_tickets]</code> — ' . esc_html__( 'فرم ثبت، فهرست و پیگیری تیکت در هر برگه', 'netarz-ai' ) . '</li>';
			echo '<li><code>&lt;a href="#netarz-chat"&gt;</code> ' . esc_html__( 'یا هر عنصری با ویژگی', 'netarz-ai' ) . ' <code>data-netarz-chat</code> — ' . esc_html__( 'دکمه‌ای که پنجرهٔ چت را باز می‌کند', 'netarz-ai' ) . '</li>';
			echo '</ul></section>';
		}

		self::footer();
	}

	private static function stat( $label, $value, $link = '', $alert = false ) {
		echo '<div class="nzai-stat' . ( $alert ? ' is-alert' : '' ) . '">';
		echo $link ? '<a href="' . esc_url( $link ) . '">' : '<div>';
		echo '<strong>' . esc_html( number_format_i18n( (int) $value ) ) . '</strong><span>' . esc_html( $label ) . '</span>';
		echo $link ? '</a>' : '</div>';
		echo '</div>';
	}

	private static function module( $label, $on, $desc, $tab ) {
		$url = current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=netarz-ai-settings&tab=' . $tab ) : '';
		echo '<li><span class="nzai-pill ' . ( $on ? 'is-on' : 'is-off' ) . '">' . ( $on ? esc_html__( 'روشن', 'netarz-ai' ) : esc_html__( 'خاموش', 'netarz-ai' ) ) . '</span>';
		echo '<div><strong>' . ( $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label ) ) . '</strong><p>' . esc_html( $desc ) . '</p></div></li>';
	}

	private static function usage_chart() {
		$cached = get_transient( 'netarz_ai_usage_30' );
		if ( ! is_array( $cached ) ) {
			$usage = Netarz_AI_Api::usage( wp_date( 'Y-m-d', time() - 29 * DAY_IN_SECONDS ), wp_date( 'Y-m-d' ) );
			if ( is_wp_error( $usage ) ) {
				return;
			}
			$cached = $usage;
			set_transient( 'netarz_ai_usage_30', $cached, 15 * MINUTE_IN_SECONDS );
		}

		$rows = array();
		foreach ( ( isset( $cached['data'] ) && is_array( $cached['data'] ) ? $cached['data'] : array() ) as $row ) {
			$rows[ (string) $row['key'] ] = $row;
		}

		$days = array();
		$max  = 0.0;
		for ( $i = 29; $i >= 0; $i-- ) {
			$key          = wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$usd          = isset( $rows[ $key ] ) ? (float) $rows[ $key ]['usd'] : 0.0;
			$req          = isset( $rows[ $key ] ) ? (int) $rows[ $key ]['requests'] : 0;
			$days[ $key ] = array( $usd, $req );
			$max          = max( $max, $usd );
		}

		echo '<section class="nzai-card nzai-usage"><h2>' . esc_html__( 'مصرف ۳۰ روز اخیر این پروژه', 'netarz-ai' ) . '</h2>';
		/* translators: %s: total USD */
		echo '<p class="nzai-muted">' . esc_html( sprintf( __( 'جمع هزینه: %s دلار', 'netarz-ai' ), number_format( (float) ( isset( $cached['total_usd'] ) ? $cached['total_usd'] : 0 ), 4 ) ) ) . '</p>';

		$w   = 30 * 22;
		$h   = 140;
		echo '<div class="nzai-chart" dir="ltr"><svg viewBox="0 0 ' . (int) $w . ' ' . (int) ( $h + 20 ) . '" role="img" aria-label="' . esc_attr__( 'نمودار هزینهٔ روزانه', 'netarz-ai' ) . '">';
		$x = 0;
		foreach ( $days as $day => $vals ) {
			$bar = $max > 0 ? max( 2, round( $vals[0] / $max * $h ) ) : 2;
			$y   = $h - $bar;
			printf(
				'<g><rect x="%1$d" y="%2$d" width="16" height="%3$d" rx="3" class="%4$s"><title>%5$s</title></rect></g>',
				(int) $x + 3,
				(int) $y,
				(int) $bar,
				$vals[0] > 0 ? 'on' : 'off',
				esc_html( $day . ' — $' . number_format( $vals[0], 4 ) . ' — ' . $vals[1] . ' req' )
			);
			if ( 0 === ( $x / 22 ) % 5 ) {
				printf( '<text x="%d" y="%d">%s</text>', (int) $x + 3, (int) $h + 15, esc_html( substr( $day, 5 ) ) );
			}
			$x += 22;
		}
		echo '</svg></div></section>';
	}

	public static function dashboard_widget() {
		if ( ! current_user_can( Netarz_AI_Installer::CAP_SUPPORT ) ) {
			return;
		}
		wp_add_dashboard_widget( 'netarz_ai_widget', __( 'هوش مصنوعی نِت اَرز', 'netarz-ai' ), array( __CLASS__, 'render_dashboard_widget' ) );
	}

	public static function render_dashboard_widget() {
		list( $chats, $tickets ) = self::waiting_counts();
		$me = get_transient( 'netarz_ai_me' );
		echo '<div dir="rtl" class="nzai-dw">';
		if ( is_array( $me ) && isset( $me['balance']['usd_display'] ) ) {
			/* translators: %s: balance */
			echo '<p>' . esc_html( sprintf( __( 'اعتبار: %s', 'netarz-ai' ), $me['balance']['usd_display'] ) ) . '</p>';
		}
		/* translators: %d: count */
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=netarz-ai-inbox' ) ) . '">' . esc_html( sprintf( __( '%d گفت‌وگو منتظر شماست', 'netarz-ai' ), $chats ) ) . '</a></p>';
		/* translators: %d: count */
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=netarz-ai-tickets' ) ) . '">' . esc_html( sprintf( __( '%d تیکت منتظر پاسخ است', 'netarz-ai' ), $tickets ) ) . '</a></p>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=netarz-ai' ) ) . '">' . esc_html__( 'پیشخوان هوش مصنوعی', 'netarz-ai' ) . '</a></p></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Inbox                                                               */
	/* ------------------------------------------------------------------ */

	public static function page_inbox() {
		echo '<div class="wrap nzai-wrap nzai-inbox-wrap" dir="rtl"><h1 class="screen-reader-text">' . esc_html__( 'گفت‌وگوهای آنلاین', 'netarz-ai' ) . '</h1>';
		if ( ! Netarz_AI_Settings::get( 'chat_ai_enabled' ) || ! Netarz_AI_Api::has_key() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'دستیار هوشمند چت خاموش است یا کلید API وارد نشده؛ همهٔ پیام‌ها منتظر پاسخ شما می‌مانند.', 'netarz-ai' ) . '</p></div>';
		}
		echo '<div id="nzai-inbox" class="nzai-inbox"><p class="nzai-muted">' . esc_html__( 'در حال بارگذاری…', 'netarz-ai' ) . '</p></div></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Tickets                                                             */
	/* ------------------------------------------------------------------ */

	public static function page_tickets() {
		$id = isset( $_GET['ticket'] ) ? absint( $_GET['ticket'] ) : 0; // phpcs:ignore
		if ( $id ) {
			self::ticket_view( $id );
			return;
		}
		self::ticket_list();
	}

	private static function ticket_list() {
		global $wpdb;
		self::header( __( 'تیکت‌های پشتیبانی', 'netarz-ai' ) );

		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'active'; // phpcs:ignore
		$dept   = isset( $_GET['dept'] ) ? sanitize_text_field( wp_unslash( $_GET['dept'] ) ) : ''; // phpcs:ignore
		$search = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : ''; // phpcs:ignore
		$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore
		$per    = 25;

		$where = array( '1=1' );
		$args  = array();
		if ( 'active' === $status ) {
			$where[] = "status <> 'closed'";
		} elseif ( 'waiting' === $status ) {
			$where[] = "status IN ('open','customer_reply')";
		} elseif ( array_key_exists( $status, Netarz_AI_Tickets::statuses() ) ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( '' !== $dept ) {
			$where[] = 'department = %s';
			$args[]  = $dept;
		}
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(subject LIKE %s OR name LIKE %s OR email LIKE %s OR phone LIKE %s OR id = %d)';
			array_push( $args, $like, $like, $like, $like, absint( Netarz_AI_Util::latin_digits( $search ) ) );
		}
		$sql_where = implode( ' AND ', $where );
		$table     = Netarz_AI_Tickets::table();

		$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $total_sql, $args ) ) : $wpdb->get_var( $total_sql ) ); // phpcs:ignore

		$list_args = array_merge( $args, array( $per, ( $paged - 1 ) * $per ) );
		$tickets   = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT * FROM {$table} WHERE {$sql_where}
			ORDER BY ( status IN ('open','customer_reply') ) DESC,
				CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END,
				last_reply_at DESC
			LIMIT %d OFFSET %d",
			$list_args
		) );

		$base = admin_url( 'admin.php?page=netarz-ai-tickets' );
		$tabs = array(
			'active'  => __( 'جاری', 'netarz-ai' ),
			'waiting' => __( 'منتظر پاسخ ما', 'netarz-ai' ),
			'answered' => __( 'پاسخ داده شد', 'netarz-ai' ),
			'on_hold' => __( 'در حال پیگیری', 'netarz-ai' ),
			'closed'  => __( 'بسته', 'netarz-ai' ),
			'all'     => __( 'همه', 'netarz-ai' ),
		);

		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( $tabs as $key => $label ) {
			$links[] = '<li><a href="' . esc_url( add_query_arg( 'status', $key, $base ) ) . '"' . ( $status === $key ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo implode( ' | </li>', $links ) . '</li></ul>'; // phpcs:ignore

		echo '<form method="get" class="nzai-filter"><input type="hidden" name="page" value="netarz-ai-tickets"><input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
		echo '<select name="dept"><option value="">' . esc_html__( 'همهٔ بخش‌ها', 'netarz-ai' ) . '</option>';
		foreach ( Netarz_AI_Settings::departments() as $d ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $d ), selected( $dept, $d, false ) );
		}
		echo '</select> <input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'شماره، موضوع، نام، ایمیل…', 'netarz-ai' ) . '"> ';
		submit_button( __( 'فیلتر', 'netarz-ai' ), 'secondary', '', false );
		echo '</form>';

		echo '<table class="widefat striped nzai-table"><thead><tr><th>#</th><th>' . esc_html__( 'موضوع', 'netarz-ai' ) . '</th><th>' . esc_html__( 'مشتری', 'netarz-ai' ) . '</th><th>' . esc_html__( 'بخش', 'netarz-ai' ) . '</th><th>' . esc_html__( 'اولویت', 'netarz-ai' ) . '</th><th>' . esc_html__( 'وضعیت', 'netarz-ai' ) . '</th><th>' . esc_html__( 'آخرین پیام', 'netarz-ai' ) . '</th></tr></thead><tbody>';
		if ( ! $tickets ) {
			echo '<tr><td colspan="7">' . esc_html__( 'تیکتی پیدا نشد.', 'netarz-ai' ) . '</td></tr>';
		}
		foreach ( $tickets as $t ) {
			$url = add_query_arg( 'ticket', (int) $t->id, $base );
			echo '<tr class="' . ( in_array( $t->status, array( 'open', 'customer_reply' ), true ) ? 'is-waiting' : '' ) . '">';
			echo '<td><a href="' . esc_url( $url ) . '">' . (int) $t->id . '</a></td>';
			echo '<td><a class="row-title" href="' . esc_url( $url ) . '">' . esc_html( $t->subject ) . '</a>' . ( 'chat' === $t->source ? ' <span class="nzai-tag">' . esc_html__( 'از چت', 'netarz-ai' ) . '</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( $t->name ) . '<br><small dir="ltr">' . esc_html( $t->email ) . '</small></td>';
			echo '<td>' . esc_html( $t->department ) . '</td>';
			echo '<td><span class="nzai-prio is-' . esc_attr( $t->priority ) . '">' . esc_html( Netarz_AI_Tickets::label( Netarz_AI_Tickets::priorities(), $t->priority ) ) . '</span></td>';
			echo '<td><span class="nzai-status is-' . esc_attr( $t->status ) . '">' . esc_html( Netarz_AI_Tickets::label( Netarz_AI_Tickets::statuses(), $t->status ) ) . '</span></td>';
			echo '<td>' . esc_html( Netarz_AI_Util::ago( $t->last_reply_at ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( array( // phpcs:ignore
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			) ) . '</div></div>';
		}

		self::footer();
	}

	private static function ticket_view( $id ) {
		$ticket = Netarz_AI_Tickets::find( $id );
		if ( ! $ticket ) {
			self::header( __( 'تیکت پیدا نشد', 'netarz-ai' ) );
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=netarz-ai-tickets' ) ) . '">' . esc_html__( '→ همهٔ تیکت‌ها', 'netarz-ai' ) . '</a></p>';
			self::footer();
			return;
		}

		/* translators: 1: id, 2: subject */
		self::header( sprintf( __( 'تیکت #%1$d: %2$s', 'netarz-ai' ), $ticket->id, $ticket->subject ) );
		$post  = admin_url( 'admin-post.php' );
		$agent = (string) Netarz_AI_Settings::get( 'chat_agent_name' );

		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=netarz-ai-tickets' ) ) . '">' . esc_html__( '→ همهٔ تیکت‌ها', 'netarz-ai' ) . '</a></p>';
		echo '<div class="nzai-ticket" data-id="' . (int) $ticket->id . '">';

		// Thread.
		echo '<div class="nzai-ticket-main">';
		echo '<div class="nzai-card nzai-summary"><div class="nzai-summary-head"><strong>' . esc_html__( 'خلاصهٔ هوشمند', 'netarz-ai' ) . '</strong> <button type="button" class="button button-small nzai-summarize">' . esc_html__( 'خلاصه کردن', 'netarz-ai' ) . '</button></div>';
		echo '<p class="nzai-summary-text">' . ( $ticket->ai_summary ? nl2br( esc_html( $ticket->ai_summary ) ) : '<span class="nzai-muted">' . esc_html__( 'هنوز خلاصه‌ای ساخته نشده.', 'netarz-ai' ) . '</span>' ) . '</p></div>';

		foreach ( Netarz_AI_Tickets::replies( $ticket->id, true ) as $reply ) {
			if ( 'system' === $reply->author_type ) {
				echo '<p class="nzai-sysline">' . esc_html( $reply->body ) . ' — ' . esc_html( Netarz_AI_Util::date( $reply->created_at ) ) . '</p>';
				continue;
			}
			$labels = array(
				'customer' => __( 'مشتری', 'netarz-ai' ),
				'staff'    => __( 'پشتیبانی', 'netarz-ai' ),
				'ai'       => __( 'دستیار هوشمند (ارسال‌شده)', 'netarz-ai' ),
				'note'     => __( 'یادداشت داخلی', 'netarz-ai' ),
				'draft'    => __( 'پیش‌نویس هوشمند (مشتری نمی‌بیند)', 'netarz-ai' ),
			);
			$name = 'ai' === $reply->author_type ? $agent : $reply->author_name;
			echo '<article class="nzai-reply is-' . esc_attr( $reply->author_type ) . '">';
			echo '<header><strong>' . esc_html( '' !== $name ? $name : __( 'بدون نام', 'netarz-ai' ) ) . '</strong> <span class="nzai-tag">' . esc_html( Netarz_AI_Tickets::label( $labels, $reply->author_type ) ) . '</span><time>' . esc_html( Netarz_AI_Util::date( $reply->created_at ) ) . '</time></header>';
			echo '<div class="nzai-reply-body">' . wp_kses_post( wpautop( make_clickable( esc_html( $reply->body ) ) ) ) . '</div>';
			Netarz_AI_Tickets::render_files( $ticket, $reply );
			if ( 'draft' === $reply->author_type ) {
				echo '<p><button type="button" class="button nzai-use-draft" data-text="' . esc_attr( $reply->body ) . '">' . esc_html__( 'استفاده از این پیش‌نویس', 'netarz-ai' ) . '</button></p>';
			}
			echo '</article>';
		}

		// Reply form.
		echo '<form method="post" action="' . esc_url( $post ) . '" enctype="multipart/form-data" class="nzai-card nzai-reply-form">';
		wp_nonce_field( 'netarz_ai_ticket_' . $ticket->id );
		echo '<input type="hidden" name="action" value="netarz_ai_ticket"><input type="hidden" name="ticket_id" value="' . (int) $ticket->id . '">';
		echo '<div class="nzai-reply-tools"><strong>' . esc_html__( 'پاسخ', 'netarz-ai' ) . '</strong>';
		echo '<button type="button" class="button nzai-draft">' . esc_html__( 'نوشتن پیش‌نویس با هوش مصنوعی', 'netarz-ai' ) . '</button>';
		$canned = Netarz_AI_Settings::canned_replies();
		if ( $canned ) {
			echo '<select class="nzai-canned"><option value="">' . esc_html__( 'پاسخ آماده…', 'netarz-ai' ) . '</option>';
			foreach ( $canned as $i => $c ) {
				printf( '<option value="%d">%s</option>', (int) $i, esc_html( $c['title'] ) );
			}
			echo '</select>';
		}
		echo '<span class="spinner"></span></div>';
		echo '<textarea name="body" rows="8" class="large-text nzai-reply-text"></textarea>';
		if ( Netarz_AI_Settings::get( 'tickets_attachments' ) ) {
			echo '<p><input type="file" name="attachments[]" multiple></p>';
		}
		echo '<p class="nzai-reply-actions">';
		echo '<label><input type="checkbox" name="signature" value="1" checked> ' . esc_html__( 'امضا اضافه شود', 'netarz-ai' ) . '</label> ';
		echo '<select name="after_status"><option value="answered">' . esc_html__( 'بعد از ارسال: پاسخ داده شد', 'netarz-ai' ) . '</option><option value="on_hold">' . esc_html__( 'بعد از ارسال: در حال پیگیری', 'netarz-ai' ) . '</option><option value="closed">' . esc_html__( 'بعد از ارسال: بستن تیکت', 'netarz-ai' ) . '</option></select> ';
		echo '<button type="submit" name="do" value="reply" class="button button-primary">' . esc_html__( 'ارسال پاسخ به مشتری', 'netarz-ai' ) . '</button> ';
		echo '<button type="submit" name="do" value="note" class="button">' . esc_html__( 'ثبت یادداشت داخلی', 'netarz-ai' ) . '</button>';
		echo '</p></form>';
		echo '</div>';

		// Side panel.
		echo '<aside class="nzai-ticket-side">';
		echo '<form method="post" action="' . esc_url( $post ) . '" class="nzai-card">';
		wp_nonce_field( 'netarz_ai_ticket_' . $ticket->id );
		echo '<input type="hidden" name="action" value="netarz_ai_ticket"><input type="hidden" name="ticket_id" value="' . (int) $ticket->id . '"><input type="hidden" name="do" value="update">';
		echo '<h3>' . esc_html__( 'مشخصات', 'netarz-ai' ) . '</h3>';
		echo '<p><label>' . esc_html__( 'وضعیت', 'netarz-ai' ) . '<select name="status" class="widefat">';
		foreach ( Netarz_AI_Tickets::statuses() as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $ticket->status, $key, false ), esc_html( $label ) );
		}
		echo '</select></label></p><p><label>' . esc_html__( 'اولویت', 'netarz-ai' ) . '<select name="priority" class="widefat">';
		foreach ( Netarz_AI_Tickets::priorities() as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $ticket->priority, $key, false ), esc_html( $label ) );
		}
		echo '</select></label></p><p><label>' . esc_html__( 'بخش', 'netarz-ai' ) . '<select name="department" class="widefat">';
		$depts = Netarz_AI_Settings::departments();
		if ( ! in_array( $ticket->department, $depts, true ) ) {
			$depts[] = $ticket->department;
		}
		foreach ( $depts as $d ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $d ), selected( $ticket->department, $d, false ) );
		}
		echo '</select></label></p><p><label>' . esc_html__( 'مسئول', 'netarz-ai' ) . '<select name="assigned_to" class="widefat"><option value="0">—</option>';
		foreach ( get_users( array( 'capability' => Netarz_AI_Installer::CAP_SUPPORT, 'fields' => array( 'ID', 'display_name' ), 'number' => 100 ) ) as $u ) {
			printf( '<option value="%d"%s>%s</option>', (int) $u->ID, selected( (int) $ticket->assigned_to, (int) $u->ID, false ), esc_html( $u->display_name ) );
		}
		echo '</select></label></p>';
		submit_button( __( 'ذخیره', 'netarz-ai' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<div class="nzai-card"><h3>' . esc_html__( 'مشتری', 'netarz-ai' ) . '</h3><dl class="nzai-dl">';
		echo '<dt>' . esc_html__( 'نام', 'netarz-ai' ) . '</dt><dd>' . esc_html( $ticket->name ) . '</dd>';
		echo '<dt>' . esc_html__( 'ایمیل', 'netarz-ai' ) . '</dt><dd dir="ltr"><a href="mailto:' . esc_attr( $ticket->email ) . '">' . esc_html( $ticket->email ) . '</a></dd>';
		if ( '' !== $ticket->phone ) {
			echo '<dt>' . esc_html__( 'موبایل', 'netarz-ai' ) . '</dt><dd dir="ltr">' . esc_html( $ticket->phone ) . '</dd>';
		}
		if ( (int) $ticket->user_id ) {
			echo '<dt>' . esc_html__( 'حساب', 'netarz-ai' ) . '</dt><dd><a href="' . esc_url( get_edit_user_link( (int) $ticket->user_id ) ) . '">' . esc_html__( 'دیدن کاربر', 'netarz-ai' ) . '</a></dd>';
		} else {
			echo '<dt>' . esc_html__( 'حساب', 'netarz-ai' ) . '</dt><dd>' . esc_html__( 'مهمان', 'netarz-ai' ) . '</dd>';
		}
		if ( '' !== $ticket->order_ref ) {
			$order_link = '';
			if ( function_exists( 'wc_get_order' ) && ctype_digit( (string) $ticket->order_ref ) ) {
				$order = wc_get_order( (int) $ticket->order_ref );
				if ( $order ) {
					$order_link = $order->get_edit_order_url();
				}
			}
			echo '<dt>' . esc_html__( 'سفارش', 'netarz-ai' ) . '</dt><dd>' . ( $order_link ? '<a href="' . esc_url( $order_link ) . '">' . esc_html( $ticket->order_ref ) . '</a>' : esc_html( $ticket->order_ref ) ) . '</dd>';
		}
		echo '<dt>' . esc_html__( 'ثبت', 'netarz-ai' ) . '</dt><dd>' . esc_html( Netarz_AI_Util::date( $ticket->created_at ) ) . '</dd>';
		if ( (int) $ticket->rating ) {
			echo '<dt>' . esc_html__( 'امتیاز', 'netarz-ai' ) . '</dt><dd>' . esc_html( str_repeat( '★', (int) $ticket->rating ) ) . ( $ticket->rating_note ? '<br><small>' . esc_html( $ticket->rating_note ) . '</small>' : '' ) . '</dd>';
		}
		echo '</dl><p><a href="' . esc_url( Netarz_AI_Tickets::view_url( $ticket ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'دیدن تیکت از نگاه مشتری', 'netarz-ai' ) . '</a></p></div>';

		if ( current_user_can( 'manage_options' ) ) {
			echo '<form method="post" action="' . esc_url( $post ) . '" class="nzai-card nzai-danger">';
			wp_nonce_field( 'netarz_ai_ticket_' . $ticket->id );
			echo '<input type="hidden" name="action" value="netarz_ai_ticket"><input type="hidden" name="ticket_id" value="' . (int) $ticket->id . '"><input type="hidden" name="do" value="delete">';
			echo '<button type="submit" class="button-link-delete nzai-delete-ticket">' . esc_html__( 'حذف همیشگی تیکت', 'netarz-ai' ) . '</button></form>';
		}
		echo '</aside></div>';

		self::footer();
	}

	/** admin-post handler for the ticket screen. */
	public static function ticket_action() {
		$id = isset( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;
		check_admin_referer( 'netarz_ai_ticket_' . $id );
		if ( ! current_user_can( Netarz_AI_Installer::CAP_SUPPORT ) ) {
			wp_die( esc_html__( 'اجازهٔ این کار را ندارید.', 'netarz-ai' ), '', array( 'response' => 403 ) );
		}

		$ticket = Netarz_AI_Tickets::find( $id );
		$back   = admin_url( 'admin.php?page=netarz-ai-tickets&ticket=' . $id );
		if ( ! $ticket ) {
			self::redirect( admin_url( 'admin.php?page=netarz-ai-tickets' ), __( 'تیکت پیدا نشد.', 'netarz-ai' ), 'error' );
		}

		$do   = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		$user = wp_get_current_user();

		switch ( $do ) {
			case 'reply':
			case 'note':
				$body = isset( $_POST['body'] ) ? Netarz_AI_Util::clean_text( wp_unslash( $_POST['body'] ) ) : ''; // phpcs:ignore
				if ( '' === $body ) {
					self::redirect( $back, __( 'متن پاسخ خالی است.', 'netarz-ai' ), 'error' );
				}
				$files = Netarz_AI_Tickets::take_uploads();
				if ( is_wp_error( $files ) ) {
					self::redirect( $back, $files->get_error_message(), 'error' );
				}
				if ( 'note' === $do ) {
					Netarz_AI_Tickets::add_reply( $ticket, 'note', $body, $user->ID, $user->display_name, $files );
					self::redirect( $back, __( 'یادداشت ثبت شد.', 'netarz-ai' ) );
				}
				if ( ! empty( $_POST['signature'] ) ) {
					$body = Netarz_AI_Ticket_Agent::with_signature( $body );
				}
				Netarz_AI_Tickets::add_reply( $ticket, 'staff', $body, $user->ID, $user->display_name, $files );
				$after = isset( $_POST['after_status'] ) ? sanitize_key( $_POST['after_status'] ) : 'answered';
				if ( in_array( $after, array( 'on_hold', 'closed' ), true ) ) {
					Netarz_AI_Tickets::set_status( Netarz_AI_Tickets::find( $id ), $after );
				}
				if ( ! (int) $ticket->assigned_to ) {
					Netarz_AI_Tickets::update( $id, array( 'assigned_to' => $user->ID ) );
				}
				self::redirect( $back, __( 'پاسخ برای مشتری فرستاده شد.', 'netarz-ai' ) );
				break;

			case 'update':
				$priority = isset( $_POST['priority'] ) ? sanitize_key( $_POST['priority'] ) : $ticket->priority;
				$dept     = isset( $_POST['department'] ) ? sanitize_text_field( wp_unslash( $_POST['department'] ) ) : $ticket->department;
				$fields   = array(
					'priority'    => array_key_exists( $priority, Netarz_AI_Tickets::priorities() ) ? $priority : $ticket->priority,
					'department'  => '' !== $dept ? Netarz_AI_Util::cut( $dept, 95 ) : $ticket->department,
					'assigned_to' => isset( $_POST['assigned_to'] ) ? absint( $_POST['assigned_to'] ) : (int) $ticket->assigned_to,
				);
				Netarz_AI_Tickets::update( $id, $fields );
				$status = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : $ticket->status;
				Netarz_AI_Tickets::set_status( $ticket, $status );
				self::redirect( $back, __( 'ذخیره شد.', 'netarz-ai' ) );
				break;

			case 'delete':
				if ( ! current_user_can( 'manage_options' ) ) {
					self::redirect( $back, __( 'فقط مدیر سایت می‌تواند تیکت را حذف کند.', 'netarz-ai' ), 'error' );
				}
				global $wpdb;
				Netarz_AI_Tickets::delete_files( Netarz_AI_Tickets::replies( $id, true ) );
				$wpdb->delete( Netarz_AI_Tickets::replies_table(), array( 'ticket_id' => $id ), array( '%d' ) ); // phpcs:ignore
				$wpdb->delete( Netarz_AI_Tickets::table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore
				$wpdb->update( Netarz_AI_Chat::table(), array( 'ticket_id' => 0 ), array( 'ticket_id' => $id ) ); // phpcs:ignore
				self::redirect( admin_url( 'admin.php?page=netarz-ai-tickets' ), __( 'تیکت حذف شد.', 'netarz-ai' ) );
				break;
		}

		self::redirect( $back, __( 'کاری انجام نشد.', 'netarz-ai' ), 'error' );
	}

	/** REST routes used by the ticket screen. */
	public static function routes() {
		$can = function () {
			return current_user_can( Netarz_AI_Installer::CAP_SUPPORT );
		};
		register_rest_route( Netarz_AI_Chat::NS, '/tickets/(?P<id>\d+)/draft', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_ticket_draft' ),
			'permission_callback' => $can,
		) );
		register_rest_route( Netarz_AI_Chat::NS, '/tickets/(?P<id>\d+)/summary', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_ticket_summary' ),
			'permission_callback' => $can,
		) );
	}

	public static function rest_ticket_draft( WP_REST_Request $request ) {
		$ticket = Netarz_AI_Tickets::find( (int) $request['id'] );
		if ( ! $ticket ) {
			return new WP_Error( 'not_found', __( 'تیکت پیدا نشد.', 'netarz-ai' ), array( 'status' => 404 ) );
		}
		$draft = Netarz_AI_Ticket_Agent::draft( $ticket );
		if ( is_wp_error( $draft ) ) {
			return new WP_Error( $draft->get_error_code(), Netarz_AI_Api::friendly_error( $draft ), array( 'status' => 502 ) );
		}
		return rest_ensure_response( array( 'draft' => $draft ) );
	}

	public static function rest_ticket_summary( WP_REST_Request $request ) {
		$ticket = Netarz_AI_Tickets::find( (int) $request['id'] );
		if ( ! $ticket ) {
			return new WP_Error( 'not_found', __( 'تیکت پیدا نشد.', 'netarz-ai' ), array( 'status' => 404 ) );
		}
		$summary = Netarz_AI_Ticket_Agent::summarize( $ticket );
		if ( is_wp_error( $summary ) ) {
			return new WP_Error( $summary->get_error_code(), Netarz_AI_Api::friendly_error( $summary ), array( 'status' => 502 ) );
		}
		return rest_ensure_response( array( 'summary' => $summary ) );
	}

	/* ------------------------------------------------------------------ */
	/* Writer & images                                                     */
	/* ------------------------------------------------------------------ */

	public static function page_writer() {
		self::header( __( 'نویسندهٔ هوشمند', 'netarz-ai' ), __( 'موضوع را بدهید، مقاله را تحویل بگیرید؛ بعد در ویرایشگر وردپرس ویرایش و منتشرش کنید.', 'netarz-ai' ) );
		?>
		<div class="nzai-writer" id="nzai-writer">
			<div class="nzai-card nzai-writer-form">
				<p><label for="nzai-wp-topic"><strong><?php esc_html_e( 'موضوع یا عنوان', 'netarz-ai' ); ?></strong></label>
				<input type="text" id="nzai-wp-topic" class="large-text" placeholder="<?php esc_attr_e( 'مثلاً: راهنمای انتخاب کفش کوهنوردی برای مبتدی‌ها', 'netarz-ai' ); ?>"></p>
				<p><label for="nzai-wp-keywords"><?php esc_html_e( 'کلیدواژه‌ها (اختیاری)', 'netarz-ai' ); ?></label>
				<input type="text" id="nzai-wp-keywords" class="large-text"></p>
				<p><label for="nzai-wp-notes"><?php esc_html_e( 'نکته‌ها و اطلاعاتی که باید در متن بیاید (اختیاری)', 'netarz-ai' ); ?></label>
				<textarea id="nzai-wp-notes" class="large-text" rows="4"></textarea></p>
				<div class="nzai-row">
					<label><?php esc_html_e( 'لحن', 'netarz-ai' ); ?>
						<select id="nzai-wp-tone">
							<?php foreach ( Netarz_AI_Writer::tones() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( Netarz_AI_Settings::get( 'writer_tone' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></label>
					<label><?php esc_html_e( 'طول', 'netarz-ai' ); ?>
						<select id="nzai-wp-length">
							<?php foreach ( Netarz_AI_Writer::lengths() as $key => $def ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( 'medium', $key ); ?>><?php echo esc_html( $def[0] ); ?></option>
							<?php endforeach; ?>
						</select></label>
					<label><?php esc_html_e( 'زبان', 'netarz-ai' ); ?>
						<input type="text" id="nzai-wp-language" value="<?php echo esc_attr( Netarz_AI_Settings::get( 'writer_language' ) ); ?>"></label>
					<label><?php esc_html_e( 'نوع', 'netarz-ai' ); ?>
						<select id="nzai-wp-type"><option value="post"><?php esc_html_e( 'نوشته', 'netarz-ai' ); ?></option><option value="page"><?php esc_html_e( 'برگه', 'netarz-ai' ); ?></option></select></label>
				</div>
				<p><button type="button" class="button button-primary button-hero" id="nzai-wp-go"><?php esc_html_e( 'نوشتن مقاله', 'netarz-ai' ); ?></button>
				<button type="button" class="button" id="nzai-wp-outline"><?php esc_html_e( 'فقط سرفصل‌ها', 'netarz-ai' ); ?></button> <span class="spinner"></span></p>
				<p class="nzai-error-text" id="nzai-wp-error" hidden></p>
			</div>
			<div class="nzai-card nzai-writer-out" id="nzai-wp-out" hidden>
				<div class="nzai-writer-bar">
					<button type="button" class="button button-primary" id="nzai-wp-save"><?php esc_html_e( 'ذخیره به‌عنوان پیش‌نویس تازه', 'netarz-ai' ); ?></button>
					<button type="button" class="button" id="nzai-wp-copy"><?php esc_html_e( 'کپی HTML', 'netarz-ai' ); ?></button>
					<span id="nzai-wp-saved"></span>
				</div>
				<article class="nzai-preview" id="nzai-wp-preview"></article>
			</div>
		</div>
		<?php
		self::footer();
	}

	public static function page_images() {
		self::header( __( 'ساخت تصویر با هوش مصنوعی', 'netarz-ai' ), __( 'تصویر را توصیف کنید؛ نتیجه مستقیم در کتابخانهٔ رسانه ذخیره می‌شود.', 'netarz-ai' ) );
		?>
		<div class="nzai-card nzai-images" id="nzai-images">
			<p><label for="nzai-img-prompt"><strong><?php esc_html_e( 'توصیف تصویر', 'netarz-ai' ); ?></strong></label>
			<textarea id="nzai-img-prompt" class="large-text" rows="4" placeholder="<?php esc_attr_e( 'مثلاً: یک فنجان قهوه روی میز چوبی کنار پنجره، نور صبح، عکاسی واقعی', 'netarz-ai' ); ?>"></textarea></p>
			<div class="nzai-row">
				<label><?php esc_html_e( 'اندازه', 'netarz-ai' ); ?>
					<select id="nzai-img-size"><option value="1024x1024"><?php esc_html_e( 'مربع ۱۰۲۴', 'netarz-ai' ); ?></option><option value="1536x1024"><?php esc_html_e( 'افقی ۱۵۳۶×۱۰۲۴', 'netarz-ai' ); ?></option><option value="1024x1536"><?php esc_html_e( 'عمودی ۱۰۲۴×۱۵۳۶', 'netarz-ai' ); ?></option></select></label>
				<label><?php esc_html_e( 'کیفیت', 'netarz-ai' ); ?>
					<select id="nzai-img-quality"><option value="low"><?php esc_html_e( 'پایین (ارزان‌تر)', 'netarz-ai' ); ?></option><option value="medium" selected><?php esc_html_e( 'متوسط', 'netarz-ai' ); ?></option><option value="high"><?php esc_html_e( 'بالا', 'netarz-ai' ); ?></option></select></label>
			</div>
			<p><button type="button" class="button button-primary button-hero" id="nzai-img-go"><?php esc_html_e( 'ساخت تصویر', 'netarz-ai' ); ?></button> <span class="spinner"></span></p>
			<p class="nzai-error-text" id="nzai-img-error" hidden></p>
			<div class="nzai-img-grid" id="nzai-img-grid"></div>
			<p class="nzai-muted nzai-small">
				<?php
				/* translators: %s: model id */
				echo esc_html( sprintf( __( 'مدل فعلی: %s — در تنظیمات قابل تغییر است. هزینهٔ هر تصویر به کیفیت و اندازه بستگی دارد.', 'netarz-ai' ), Netarz_AI_Settings::get( 'image_model' ) ) );
				?>
			</p>
		</div>
		<?php
		self::footer();
	}

	/* ------------------------------------------------------------------ */
	/* Log                                                                 */
	/* ------------------------------------------------------------------ */

	public static function page_log() {
		global $wpdb;
		self::header( __( 'گزارش مصرف', 'netarz-ai' ), __( 'هر درخواستی که این سایت به نِت اَرز فرستاده؛ فقط مشخصات، نه متن پیام‌ها.', 'netarz-ai' ) );

		$table   = $wpdb->prefix . 'netarz_ai_log';
		$status  = isset( $_GET['status'] ) && 'error' === $_GET['status'] ? 'error' : ''; // phpcs:ignore
		$paged   = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore
		$per     = 50;
		$where   = $status ? "WHERE status = 'error'" : '';
		$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" ); // phpcs:ignore
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( $paged - 1 ) * $per ) ); // phpcs:ignore
		$summary = $wpdb->get_results( $wpdb->prepare( "SELECT feature, COUNT(*) AS n, SUM(input_tokens) AS tin, SUM(output_tokens) AS tout, SUM(status = 'error') AS errors FROM {$table} WHERE created_at >= %s GROUP BY feature ORDER BY n DESC", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore

		$features = self::feature_labels();

		echo '<div class="nzai-card"><h2>' . esc_html__( '۳۰ روز اخیر به تفکیک امکان', 'netarz-ai' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'امکان', 'netarz-ai' ) . '</th><th>' . esc_html__( 'درخواست', 'netarz-ai' ) . '</th><th>' . esc_html__( 'ناموفق', 'netarz-ai' ) . '</th><th>' . esc_html__( 'توکن ورودی', 'netarz-ai' ) . '</th><th>' . esc_html__( 'توکن خروجی', 'netarz-ai' ) . '</th></tr></thead><tbody>';
		if ( ! $summary ) {
			echo '<tr><td colspan="5">' . esc_html__( 'هنوز درخواستی ثبت نشده.', 'netarz-ai' ) . '</td></tr>';
		}
		foreach ( $summary as $row ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( isset( $features[ $row->feature ] ) ? $features[ $row->feature ] : $row->feature ),
				esc_html( number_format_i18n( (int) $row->n ) ),
				esc_html( number_format_i18n( (int) $row->errors ) ),
				esc_html( number_format_i18n( (int) $row->tin ) ),
				esc_html( number_format_i18n( (int) $row->tout ) )
			);
		}
		echo '</tbody></table><p class="nzai-muted nzai-small">' . esc_html__( 'هزینهٔ دقیق هر درخواست در پنل نِت اَرز (بخش مصرف) دیده می‌شود.', 'netarz-ai' ) . '</p></div>';

		$base = admin_url( 'admin.php?page=netarz-ai-log' );
		echo '<ul class="subsubsub"><li><a href="' . esc_url( $base ) . '"' . ( '' === $status ? ' class="current"' : '' ) . '>' . esc_html__( 'همه', 'netarz-ai' ) . '</a> | </li><li><a href="' . esc_url( add_query_arg( 'status', 'error', $base ) ) . '"' . ( 'error' === $status ? ' class="current"' : '' ) . '>' . esc_html__( 'ناموفق‌ها', 'netarz-ai' ) . '</a></li></ul>';
		echo '<table class="widefat striped nzai-table"><thead><tr><th>' . esc_html__( 'زمان', 'netarz-ai' ) . '</th><th>' . esc_html__( 'امکان', 'netarz-ai' ) . '</th><th>' . esc_html__( 'مدل', 'netarz-ai' ) . '</th><th>' . esc_html__( 'نتیجه', 'netarz-ai' ) . '</th><th>' . esc_html__( 'توکن', 'netarz-ai' ) . '</th><th>' . esc_html__( 'شناسهٔ درخواست', 'netarz-ai' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td dir="ltr"><code>%s</code></td><td>%s</td><td dir="ltr">%s → %s</td><td dir="ltr"><code>%s</code></td></tr>',
				esc_html( Netarz_AI_Util::date( $row->created_at ) ),
				esc_html( isset( $features[ $row->feature ] ) ? $features[ $row->feature ] : $row->feature ),
				esc_html( $row->model ),
				'ok' === $row->status ? '<span class="nzai-pill is-on">' . esc_html__( 'موفق', 'netarz-ai' ) . '</span>' : '<span class="nzai-pill is-off" title="' . esc_attr( $row->error_code ) . '">' . esc_html( $row->error_code ) . '</span>',
				esc_html( number_format_i18n( (int) $row->input_tokens ) ),
				esc_html( number_format_i18n( (int) $row->output_tokens ) ),
				esc_html( $row->request_id )
			);
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'موردی نیست.', 'netarz-ai' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( array( // phpcs:ignore
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			) ) . '</div></div>';
		}
		self::footer();
	}

	private static function feature_labels() {
		$labels = array(
			'chat'           => __( 'پاسخ چت', 'netarz-ai' ),
			'chat_draft'     => __( 'پیشنهاد پاسخ چت', 'netarz-ai' ),
			'ticket_auto'    => __( 'پاسخ خودکار تیکت', 'netarz-ai' ),
			'ticket_draft'   => __( 'پیش‌نویس تیکت', 'netarz-ai' ),
			'ticket_summary' => __( 'خلاصهٔ تیکت', 'netarz-ai' ),
			'image'          => __( 'ساخت تصویر', 'netarz-ai' ),
			'alt_text'       => __( 'متن جایگزین تصویر', 'netarz-ai' ),
			'comment_reply'  => __( 'پاسخ دیدگاه', 'netarz-ai' ),
			'test'           => __( 'آزمایش اتصال', 'netarz-ai' ),
		);
		foreach ( Netarz_AI_Writer::tasks() as $key => $task ) {
			$labels[ 'writer_' . $key ] = __( 'نویسنده:', 'netarz-ai' ) . ' ' . $task['label'];
		}
		return $labels;
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	private static function tabs() {
		return array(
			'general'  => __( 'اتصال و عمومی', 'netarz-ai' ),
			'chat'     => __( 'چت پشتیبانی', 'netarz-ai' ),
			'tickets'  => __( 'تیکتینگ', 'netarz-ai' ),
			'writer'   => __( 'نویسنده و تصویر', 'netarz-ai' ),
			'advanced' => __( 'پیشرفته', 'netarz-ai' ),
		);
	}

	/** Label and help text for every setting. */
	private static function field_text() {
		return array(
			'api_key'               => array( __( 'کلید API نِت اَرز', 'netarz-ai' ), __( 'کلید پروژه از netarz.ir/ai؛ با sk-ntz-v1- شروع می‌شود. برای کنترل هزینه، در پنل نِت اَرز برای این کلید سقف هزینه بگذارید.', 'netarz-ai' ) ),
			'default_model'         => array( __( 'مدل پیش‌فرض', 'netarz-ai' ), __( 'هر امکانی که مدل جدا ندارد از این مدل استفاده می‌کند. قیمت‌ها برای هر یک میلیون توکن ورودی/خروجی است.', 'netarz-ai' ) ),
			'low_credit_usd'        => array( __( 'هشدار کم بودن اعتبار (دلار)', 'netarz-ai' ), __( 'وقتی اعتبار کمتر از این مقدار شد، در پیشخوان هشدار داده می‌شود.', 'netarz-ai' ) ),
			'site_description'      => array( __( 'دربارهٔ کسب‌وکار شما', 'netarz-ai' ), __( 'چند جمله: چه می‌فروشید، به چه کسانی، و چه چیزی شما را متفاوت می‌کند. همهٔ امکانات هوش مصنوعی از آن استفاده می‌کنند.', 'netarz-ai' ) ),
			'chat_enabled'          => array( __( 'نمایش ویجت چت', 'netarz-ai' ), __( 'پنجرهٔ گفت‌وگوی آنلاین در پایین همهٔ صفحه‌های سایت.', 'netarz-ai' ) ),
			'chat_ai_enabled'       => array( __( 'پاسخ‌گویی دستیار هوشمند', 'netarz-ai' ), __( 'خاموش باشد، همهٔ پیام‌ها منتظر پاسخ همکاران می‌مانند.', 'netarz-ai' ) ),
			'chat_model'            => array( __( 'مدل چت', 'netarz-ai' ), __( 'برای چت، مدلی سریع و ارزان با پشتیبانی JSON مناسب است.', 'netarz-ai' ) ),
			'chat_agent_name'       => array( __( 'نام پشتیبان', 'netarz-ai' ), __( 'نامی که کنار پیام‌های پشتیبانی دیده می‌شود.', 'netarz-ai' ) ),
			'chat_title'            => array( __( 'عنوان پنجره', 'netarz-ai' ), '' ),
			'chat_welcome'          => array( __( 'پیام خوشامد', 'netarz-ai' ), '' ),
			'chat_color'            => array( __( 'رنگ اصلی', 'netarz-ai' ), '' ),
			'chat_text_color'       => array( __( 'رنگ متن روی رنگ اصلی', 'netarz-ai' ), '' ),
			'chat_position'         => array( __( 'جای دکمه', 'netarz-ai' ), '' ),
			'chat_offset_bottom'    => array( __( 'فاصله از پایین صفحه (پیکسل)', 'netarz-ai' ), __( 'اگر قالب شما نوار ثابت پایین دارد، این عدد را بیشتر کنید.', 'netarz-ai' ) ),
			'chat_ask_name'         => array( __( 'پرسیدن نام', 'netarz-ai' ), '' ),
			'chat_ask_contact'      => array( __( 'پرسیدن راه تماس', 'netarz-ai' ), __( 'برای کاربران واردشده پرسیده نمی‌شود.', 'netarz-ai' ) ),
			'chat_register'         => array( __( 'لحن دستیار', 'netarz-ai' ), '' ),
			'chat_instructions'     => array( __( 'دستورهای اختصاصی دستیار', 'netarz-ai' ), __( 'مثلاً: «دربارهٔ رقبا حرف نزن»، «برای سفارش عمده، شمارهٔ فروش را بده». این دستورها بر رفتار پیش‌فرض مقدم است. مثل دانش اختصاصی، عمومی فرضش کنید.', 'netarz-ai' ) ),
			'chat_knowledge'        => array( __( 'دانش اختصاصی', 'netarz-ai' ), __( 'پرسش‌های پرتکرار، شرایط ارسال، ساعت کاری، روش‌های پرداخت، گارانتی، شماره تماس… دستیار فقط از همین‌ها و مطالب سایت حرف می‌زند. این متن را عمومی فرض کنید: رمز، اطلاعات مالی یا هر چیز محرمانه‌ای ننویسید.', 'netarz-ai' ) ),
			'chat_use_content'      => array( __( 'جست‌وجو در مطالب سایت', 'netarz-ai' ), __( 'دستیار نوشته‌ها، برگه‌ها و محصولات مرتبط با سؤال را پیدا می‌کند و از آن‌ها جواب می‌دهد.', 'netarz-ai' ) ),
			'chat_content_types'    => array( __( 'نوع‌های محتوا برای جست‌وجو', 'netarz-ai' ), __( 'با کاما جدا کنید؛ مثلاً post,page,product', 'netarz-ai' ) ),
			'chat_min_confidence'   => array( __( 'حداقل اطمینان برای پاسخ (٪)', 'netarz-ai' ), __( 'اگر دستیار کمتر از این مطمئن بود، به‌جای جواب دادن گفت‌وگو را به همکار می‌سپارد.', 'netarz-ai' ) ),
			'chat_pause_on_agent'   => array( __( 'با پاسخ همکار، دستیار ساکت شود', 'netarz-ai' ), __( 'وقتی خودتان جواب دادید یا گفت‌وگو به همکار سپرده شد، دستیار در آن گفت‌وگو دیگر جواب نمی‌دهد (از صندوق دوباره روشن می‌شود).', 'netarz-ai' ) ),
			'chat_max_ai_replies'   => array( __( 'سقف پاسخ خودکار در هر گفت‌وگو', 'netarz-ai' ), '' ),
			'chat_daily_ai_limit'   => array( __( 'سقف پاسخ خودکار در روز (کل سایت)', 'netarz-ai' ), __( 'محافظ هزینه در برابر سوءاستفاده.', 'netarz-ai' ) ),
			'chat_hourly_starts'    => array( __( 'سقف شروع گفت‌وگو از هر اتصال در ساعت', 'netarz-ai' ), '' ),
			'chat_max_length'       => array( __( 'حداکثر طول هر پیام (نویسه)', 'netarz-ai' ), '' ),
			'chat_teaser_enabled'   => array( __( 'پیام دعوت', 'netarz-ai' ), __( 'یک بار در هر بازدید، کنار دکمهٔ چت ظاهر می‌شود.', 'netarz-ai' ) ),
			'chat_teaser_delay'     => array( __( 'تأخیر پیام دعوت (ثانیه)', 'netarz-ai' ), '' ),
			'chat_teaser_text'      => array( __( 'متن پیام دعوت', 'netarz-ai' ), '' ),
			'chat_sound'            => array( __( 'صدای پیام تازه', 'netarz-ai' ), '' ),
			'chat_hours_enabled'    => array( __( 'ساعت کاری همکاران', 'netarz-ai' ), __( 'بیرون از این ساعت‌ها وضعیت «خارج از ساعت کاری» نشان داده می‌شود.', 'netarz-ai' ) ),
			'chat_hours_start'      => array( __( 'شروع', 'netarz-ai' ), '' ),
			'chat_hours_end'        => array( __( 'پایان', 'netarz-ai' ), '' ),
			'chat_hours_days'       => array( __( 'روزهای کاری', 'netarz-ai' ), '' ),
			'chat_offline_text'     => array( __( 'پیام خارج از ساعت کاری', 'netarz-ai' ), '' ),
			'chat_hide_on'          => array( __( 'پنهان در این صفحه‌ها', 'netarz-ai' ), __( 'هر خط یک مسیر (مثل /checkout) یا شناسهٔ برگه. * یعنی هر چیزی.', 'netarz-ai' ) ),
			'chat_hide_mobile'      => array( __( 'پنهان در موبایل', 'netarz-ai' ), '' ),
			'chat_notify_emails'    => array( __( 'ایمیل اعلان گفت‌وگو', 'netarz-ai' ), __( 'خالی = ایمیل مدیر سایت. چند ایمیل را با کاما جدا کنید.', 'netarz-ai' ) ),
			'chat_notify_on'        => array( __( 'چه وقت ایمیل بفرستد', 'netarz-ai' ), '' ),
			'chat_retention_days'   => array( __( 'نگه‌داری گفت‌وگوهای بسته (روز)', 'netarz-ai' ), __( '۰ یعنی همیشه نگه دار.', 'netarz-ai' ) ),
			'tickets_enabled'       => array( __( 'فعال بودن تیکتینگ', 'netarz-ai' ), '' ),
			'tickets_guest'         => array( __( 'ثبت تیکت برای مهمان', 'netarz-ai' ), __( 'مهمان با نام و ایمیل تیکت می‌فرستد و با لینک مخصوص پیگیری می‌کند.', 'netarz-ai' ) ),
			'tickets_departments'   => array( __( 'بخش‌ها', 'netarz-ai' ), __( 'هر خط یک بخش.', 'netarz-ai' ) ),
			'tickets_ai_mode'       => array( __( 'هوش مصنوعی تیکت', 'netarz-ai' ), __( 'پیش‌نویس: دستیار برای هر پیام مشتری پیش‌نویس خصوصی می‌نویسد. خودکار: وقتی مطمئن است خودش جواب می‌دهد، وگرنه پیش‌نویس می‌گذارد.', 'netarz-ai' ) ),
			'tickets_ai_model'      => array( __( 'مدل تیکت', 'netarz-ai' ), '' ),
			'tickets_ai_confidence' => array( __( 'حداقل اطمینان برای پاسخ خودکار (٪)', 'netarz-ai' ), '' ),
			'tickets_ai_daily_limit' => array( __( 'سقف کار هوش مصنوعی روی تیکت‌ها در روز', 'netarz-ai' ), __( 'پیش‌نویس و پاسخ خودکار روی هم؛ بعد از آن تا فردا فقط همکاران جواب می‌دهند.', 'netarz-ai' ) ),
			'tickets_ai_per_ticket' => array( __( 'سقف کار هوش مصنوعی روی هر تیکت', 'netarz-ai' ), __( 'جلوی تیکتی را می‌گیرد که با پیام‌های پشت‌سرهم اعتبار را خرج می‌کند.', 'netarz-ai' ) ),
			'tickets_attachments'   => array( __( 'پیوست فایل', 'netarz-ai' ), __( 'فایل‌ها با نام تصادفی در پوشهٔ محافظت‌شده ذخیره و فقط برای صاحب تیکت و همکاران نمایش داده می‌شوند.', 'netarz-ai' ) ),
			'tickets_max_file_mb'   => array( __( 'حداکثر حجم هر فایل (مگابایت)', 'netarz-ai' ), '' ),
			'tickets_file_types'    => array( __( 'پسوندهای مجاز', 'netarz-ai' ), __( 'فایل‌های اجرایی و اسکریپت همیشه رد می‌شوند.', 'netarz-ai' ) ),
			'tickets_notify_emails' => array( __( 'ایمیل اعلان تیکت', 'netarz-ai' ), __( 'خالی = ایمیل مدیر سایت.', 'netarz-ai' ) ),
			'tickets_auto_close'    => array( __( 'بستن خودکار پس از (روز)', 'netarz-ai' ), __( 'تیکتی که جواب داده‌اید و مشتری تا این مدت پاسخ نداده بسته می‌شود. ۰ = هرگز.', 'netarz-ai' ) ),
			'tickets_canned'        => array( __( 'پاسخ‌های آماده', 'netarz-ai' ), __( 'هر خط: عنوان | متن. برای خط تازه در متن \n بنویسید.', 'netarz-ai' ) ),
			'tickets_page_id'       => array( __( 'برگهٔ پشتیبانی', 'netarz-ai' ), __( 'برگه‌ای که کد کوتاه [netarz_ai_tickets] در آن است؛ لینک‌های ایمیل به آن می‌رود.', 'netarz-ai' ) ),
			'tickets_woo_account'   => array( __( 'زبانهٔ «پشتیبانی» در حساب ووکامرس', 'netarz-ai' ), '' ),
			'tickets_signature'     => array( __( 'امضای پاسخ‌ها', 'netarz-ai' ), '' ),
			'writer_enabled'        => array( __( 'ابزارهای نویسندگی', 'netarz-ai' ), __( 'جعبهٔ «دستیار هوشمند» در ویرایشگر، صفحهٔ نویسنده، ساخت تصویر، متن جایگزین و پاسخ دیدگاه.', 'netarz-ai' ) ),
			'writer_model'          => array( __( 'مدل نویسنده', 'netarz-ai' ), '' ),
			'writer_language'       => array( __( 'زبان پیش‌فرض نوشتن', 'netarz-ai' ), '' ),
			'writer_tone'           => array( __( 'لحن پیش‌فرض', 'netarz-ai' ), '' ),
			'writer_seo_meta'       => array( __( 'چاپ توضیحات متا', 'netarz-ai' ), __( 'فقط وقتی افزونهٔ سئوی دیگری (Yoast، Rank Math، AIOSEO، SEOPress) فعال نیست. با آن افزونه‌ها، مقادیر در فیلدهای خودشان ذخیره می‌شود.', 'netarz-ai' ) ),
			'image_model'           => array( __( 'مدل تصویر', 'netarz-ai' ), '' ),
			'vision_model'          => array( __( 'مدل دیدن تصویر (متن جایگزین)', 'netarz-ai' ), __( 'مدلی که تصویر را می‌فهمد.', 'netarz-ai' ) ),
			'comments_ai_reply'     => array( __( 'پیشنهاد پاسخ دیدگاه', 'netarz-ai' ), '' ),
			'role_support'          => array( __( 'نقش‌های پشتیبان', 'netarz-ai' ), __( 'نامک نقش‌ها با کاما؛ این نقش‌ها گفت‌وگوها و تیکت‌ها را می‌بینند. مدیر همیشه دسترسی دارد.', 'netarz-ai' ) ),
			'role_writer'           => array( __( 'نقش‌های نویسنده', 'netarz-ai' ), __( 'این نقش‌ها ابزارهای نویسندگی را دارند.', 'netarz-ai' ) ),
			'log_retention_days'    => array( __( 'نگه‌داری گزارش مصرف (روز)', 'netarz-ai' ), '' ),
			'delete_on_uninstall'   => array( __( 'پاک کردن همه‌چیز هنگام حذف افزونه', 'netarz-ai' ), __( 'گفت‌وگوها، تیکت‌ها، پیوست‌ها و تنظیمات پاک می‌شود. برگشت‌پذیر نیست.', 'netarz-ai' ) ),
		);
	}

	private static function option_labels() {
		return array(
			'chat_position'    => array( 'right' => __( 'راست', 'netarz-ai' ), 'left' => __( 'چپ', 'netarz-ai' ) ),
			'chat_ask_name'    => array( 'off' => __( 'نپرس', 'netarz-ai' ), 'optional' => __( 'اختیاری', 'netarz-ai' ), 'required' => __( 'الزامی', 'netarz-ai' ) ),
			'chat_ask_contact' => array(
				'off'             => __( 'نپرس', 'netarz-ai' ),
				'optional'        => __( 'اختیاری (موبایل یا ایمیل)', 'netarz-ai' ),
				'mobile_or_email' => __( 'الزامی: موبایل یا ایمیل', 'netarz-ai' ),
				'mobile'          => __( 'الزامی: موبایل', 'netarz-ai' ),
				'email'           => __( 'الزامی: ایمیل', 'netarz-ai' ),
			),
			'chat_register'    => array( 'friendly' => __( 'محاوره‌ای و گرم', 'netarz-ai' ), 'formal' => __( 'رسمی', 'netarz-ai' ) ),
			'chat_notify_on'   => array( 'off' => __( 'هیچ‌وقت', 'netarz-ai' ), 'handoff' => __( 'وقتی گفت‌وگو به همکار سپرده شد', 'netarz-ai' ), 'all' => __( 'هر گفت‌وگوی تازه و سپرده‌شده', 'netarz-ai' ) ),
			'tickets_ai_mode'  => array( 'off' => __( 'خاموش', 'netarz-ai' ), 'draft' => __( 'فقط پیش‌نویس برای همکار', 'netarz-ai' ), 'auto' => __( 'پاسخ خودکار وقتی مطمئن است', 'netarz-ai' ) ),
			'writer_tone'      => Netarz_AI_Writer::tones(),
		);
	}

	private static function option_label( $field, $value ) {
		$labels = self::option_labels();
		return isset( $labels[ $field ][ $value ] ) ? $labels[ $field ][ $value ] : $value;
	}

	public static function page_settings() {
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'general';

		self::header( __( 'تنظیمات هوش مصنوعی نِت اَرز', 'netarz-ai' ) );

		echo '<nav class="nav-tab-wrapper nzai-tabs">';
		foreach ( $tabs as $key => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( admin_url( 'admin.php?page=netarz-ai-settings&tab=' . $key ) ), $key === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';

		if ( 'general' === $tab && Netarz_AI_Api::has_key() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="nzai-test">';
			wp_nonce_field( 'netarz_ai_test' );
			echo '<input type="hidden" name="action" value="netarz_ai_test"><button class="button">' . esc_html__( 'آزمایش اتصال و به‌روزرسانی فهرست مدل‌ها', 'netarz-ai' ) . '</button></form>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="nzai-settings">';
		wp_nonce_field( 'netarz_ai_settings_' . $tab );
		echo '<input type="hidden" name="action" value="netarz_ai_save_settings"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';

		$schema = Netarz_AI_Settings::schema();
		$text   = self::field_text();
		$values = Netarz_AI_Settings::all();
		$groups = array(
			'chat_agent_name'     => __( 'ظاهر و متن‌ها', 'netarz-ai' ),
			'chat_register'       => __( 'رفتار دستیار', 'netarz-ai' ),
			'chat_max_ai_replies' => __( 'محافظ‌های هزینه و سوءاستفاده', 'netarz-ai' ),
			'chat_teaser_enabled' => __( 'دعوت به گفت‌وگو', 'netarz-ai' ),
			'chat_hours_enabled'  => __( 'ساعت کاری', 'netarz-ai' ),
			'chat_hide_on'        => __( 'نمایش و اعلان‌ها', 'netarz-ai' ),
			'tickets_ai_mode'     => __( 'هوش مصنوعی', 'netarz-ai' ),
			'tickets_attachments' => __( 'پیوست و اعلان', 'netarz-ai' ),
			'image_model'         => __( 'تصویر و دیدگاه‌ها', 'netarz-ai' ),
		);

		foreach ( $schema[ $tab ] as $name => $def ) {
			if ( isset( $groups[ $name ] ) ) {
				echo '<tr class="nzai-group"><th colspan="2"><h2>' . esc_html( $groups[ $name ] ) . '</h2></th></tr>';
			}
			$label = isset( $text[ $name ] ) ? $text[ $name ][0] : $name;
			$help  = isset( $text[ $name ] ) ? $text[ $name ][1] : '';
			echo '<tr><th scope="row"><label for="nzai-f-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
			self::render_field( $name, $def, $values[ $name ] );
			if ( '' !== $help ) {
				echo '<p class="description">' . esc_html( $help ) . '</p>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		submit_button( __( 'ذخیرهٔ تنظیمات', 'netarz-ai' ) );
		echo '</form>';
		self::footer();
	}

	private static function render_field( $name, array $def, $value ) {
		$id   = 'nzai-f-' . $name;
		$attr = 'id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"';

		switch ( $def['type'] ) {
			case 'bool':
				printf( '<label><input type="checkbox" %s value="1"%s> %s</label>', $attr, checked( (bool) $value, true, false ), esc_html__( 'فعال', 'netarz-ai' ) ); // phpcs:ignore
				break;

			case 'int':
			case 'float':
				printf( '<input type="number" class="small-text" %s value="%s" min="%s" max="%s" step="%s">', $attr, esc_attr( $value ), esc_attr( $def['min'] ), esc_attr( $def['max'] ), 'float' === $def['type'] ? '0.1' : '1' ); // phpcs:ignore
				if ( 'tickets_page_id' === $name ) {
					wp_dropdown_pages( array(
						'name'              => 'tickets_page_id_select',
						'id'                => 'nzai-f-page-select',
						'selected'          => (int) $value,
						'show_option_none'  => __( '— انتخاب برگه —', 'netarz-ai' ),
						'option_none_value' => 0,
					) );
					echo '<script>document.getElementById("nzai-f-page-select")&&document.getElementById("nzai-f-page-select").addEventListener("change",function(){document.getElementById("nzai-f-tickets_page_id").value=this.value;});</script>';
				}
				break;

			case 'color':
				printf( '<input type="color" %s value="%s">', $attr, esc_attr( $value ) ); // phpcs:ignore
				break;

			case 'time':
				printf( '<input type="time" %s value="%s">', $attr, esc_attr( $value ) ); // phpcs:ignore
				break;

			case 'days':
				$chosen = array_filter( explode( ',', (string) $value ), 'strlen' );
				$names  = array( 6 => __( 'شنبه', 'netarz-ai' ), 0 => __( 'یکشنبه', 'netarz-ai' ), 1 => __( 'دوشنبه', 'netarz-ai' ), 2 => __( 'سه‌شنبه', 'netarz-ai' ), 3 => __( 'چهارشنبه', 'netarz-ai' ), 4 => __( 'پنجشنبه', 'netarz-ai' ), 5 => __( 'جمعه', 'netarz-ai' ) );
				echo '<input type="hidden" name="' . esc_attr( $name ) . '[]" value="">';
				foreach ( $names as $num => $day ) {
					printf( '<label class="nzai-day"><input type="checkbox" name="%s[]" value="%d"%s> %s</label> ', esc_attr( $name ), (int) $num, checked( in_array( (string) $num, $chosen, true ), true, false ), esc_html( $day ) );
				}
				break;

			case 'select':
				$labels = self::option_labels();
				echo '<select ' . $attr . '>'; // phpcs:ignore
				foreach ( $def['options'] as $opt ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $opt ), selected( $value, $opt, false ), esc_html( isset( $labels[ $name ][ $opt ] ) ? $labels[ $name ][ $opt ] : $opt ) );
				}
				echo '</select>';
				break;

			case 'textarea':
				$rows = in_array( $name, array( 'chat_knowledge', 'chat_instructions', 'site_description' ), true ) ? 8 : 3;
				printf( '<textarea class="large-text" rows="%d" %s>%s</textarea>', (int) $rows, $attr, esc_textarea( $value ) ); // phpcs:ignore
				break;

			case 'api_key':
				$masked = Netarz_AI_Api::masked_key();
				printf( '<input type="text" class="regular-text" dir="ltr" autocomplete="off" spellcheck="false" %s value="%s" placeholder="sk-ntz-v1-…">', $attr, esc_attr( $masked ) ); // phpcs:ignore
				if ( '' !== $masked ) {
					echo ' <label><input type="checkbox" name="api_key_clear" value="1"> ' . esc_html__( 'حذف کلید', 'netarz-ai' ) . '</label>';
				}
				echo '<p class="description"><a href="' . esc_url( Netarz_AI_Api::PANEL_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'ساخت کلید در پنل نِت اَرز', 'netarz-ai' ) . '</a> · <a href="' . esc_url( Netarz_AI_Api::DOCS_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'مستندات', 'netarz-ai' ) . '</a></p>';
				break;

			case 'model':
				$models = Netarz_AI_Api::models( isset( $def['model_type'] ) ? $def['model_type'] : 'chat' );
				if ( ! $models ) {
					printf( '<input type="text" class="regular-text" dir="ltr" %s value="%s" placeholder="%s">', $attr, esc_attr( $value ), esc_attr__( 'شناسهٔ مدل', 'netarz-ai' ) ); // phpcs:ignore
					echo '<p class="description">' . esc_html__( 'فهرست مدل‌ها از نِت اَرز دریافت نشد؛ شناسه را دستی وارد کنید.', 'netarz-ai' ) . '</p>';
					break;
				}
				echo '<select class="nzai-model" ' . $attr . '>'; // phpcs:ignore
				if ( 'default_model' !== $name && 'image_model' !== $name ) {
					echo '<option value="">' . esc_html__( '— همان مدل پیش‌فرض —', 'netarz-ai' ) . '</option>';
				}
				$found = false;
				foreach ( $models as $model ) {
					if ( 'vision_model' === $name && empty( $model['capabilities']['vision'] ) ) {
						continue;
					}
					$found = $found || $model['id'] === $value;
					printf( '<option value="%s"%s>%s</option>', esc_attr( $model['id'] ), selected( $value, $model['id'], false ), esc_html( self::model_label( $model ) ) );
				}
				if ( '' !== (string) $value && ! $found ) {
					printf( '<option value="%1$s" selected>%1$s</option>', esc_attr( $value ) );
				}
				echo '</select>';
				break;

			case 'email_list':
			case 'text':
			default:
				$ltr = in_array( $def['type'], array( 'email_list' ), true ) || in_array( $name, array( 'chat_content_types', 'tickets_file_types', 'role_support', 'role_writer' ), true );
				printf( '<input type="text" class="regular-text" %s value="%s"%s>', $attr, esc_attr( $value ), $ltr ? ' dir="ltr"' : '' ); // phpcs:ignore
		}
	}

	private static function model_label( array $model ) {
		$label = $model['name'] . ' — ' . $model['id'];
		if ( $model['is_free'] ) {
			return $label . ' — ' . __( 'رایگان', 'netarz-ai' );
		}
		$t = $model['pricing_toman'];
		if ( isset( $t['input_per_million'], $t['output_per_million'] ) ) {
			/* translators: 1: input price, 2: output price */
			return $label . ' — ' . sprintf( __( '%1$s / %2$s تومان برای هر میلیون توکن', 'netarz-ai' ), number_format_i18n( (float) $t['input_per_million'] ), number_format_i18n( (float) $t['output_per_million'] ) );
		}
		return $label;
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'اجازهٔ این کار را ندارید.', 'netarz-ai' ), '', array( 'response' => 403 ) );
		}
		$tab = isset( $_POST['tab'] ) ? sanitize_key( $_POST['tab'] ) : '';
		check_admin_referer( 'netarz_ai_settings_' . $tab );

		$old_key = (string) Netarz_AI_Settings::get( 'api_key' );
		$before  = Netarz_AI_Settings::all();

		Netarz_AI_Settings::save_tab( $tab, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised per field.

		$after = Netarz_AI_Settings::all();
		if ( $old_key !== (string) $after['api_key'] ) {
			delete_transient( 'netarz_ai_me' );
			delete_transient( 'netarz_ai_usage_30' );
			delete_transient( 'netarz_ai_out_of_credit' );
		}
		if ( 'advanced' === $tab ) {
			Netarz_AI_Installer::sync_caps();
		}
		if ( $before['tickets_woo_account'] !== $after['tickets_woo_account'] || $before['tickets_enabled'] !== $after['tickets_enabled'] ) {
			update_option( 'netarz_ai_flush_rewrite', 1, false );
		}

		$message = __( 'تنظیمات ذخیره شد.', 'netarz-ai' );
		$type    = 'success';
		if ( 'general' === $tab && '' !== (string) $after['api_key'] && $old_key !== (string) $after['api_key'] ) {
			$me = Netarz_AI_Api::me( true );
			if ( is_wp_error( $me ) ) {
				$message = __( 'تنظیمات ذخیره شد، ولی اتصال با این کلید برقرار نشد:', 'netarz-ai' ) . ' ' . Netarz_AI_Api::friendly_error( $me );
				$type    = 'error';
			} else {
				/* translators: %s: balance */
				$message = sprintf( __( 'کلید تأیید شد و به حساب نِت اَرز وصل شدید. اعتبار: %s', 'netarz-ai' ), isset( $me['balance']['usd_display'] ) ? $me['balance']['usd_display'] : '' );
			}
		}

		self::redirect( admin_url( 'admin.php?page=netarz-ai-settings&tab=' . $tab ), $message, $type );
	}

	public static function test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'اجازهٔ این کار را ندارید.', 'netarz-ai' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'netarz_ai_test' );

		foreach ( array( 'chat', 'image', 'embedding', 'audio' ) as $type ) {
			delete_transient( 'netarz_ai_models_' . $type );
		}
		delete_transient( 'netarz_ai_usage_30' );

		$me = Netarz_AI_Api::me( true );
		if ( is_wp_error( $me ) ) {
			self::redirect( admin_url( 'admin.php?page=netarz-ai-settings' ), Netarz_AI_Api::friendly_error( $me ), 'error' );
		}
		$count = count( Netarz_AI_Api::models( 'chat' ) );
		/* translators: 1: balance, 2: number of models */
		self::redirect( admin_url( 'admin.php?page=netarz-ai-settings' ), sprintf( __( 'اتصال برقرار است. اعتبار: %1$s — %2$d مدل گفت‌وگو در دسترس است.', 'netarz-ai' ), isset( $me['balance']['usd_display'] ) ? $me['balance']['usd_display'] : '', $count ) );
	}
}
