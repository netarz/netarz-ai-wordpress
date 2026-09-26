<?php
/**
 * Live support chat: the visitor widget, its REST API and the operator inbox API.
 *
 * Visitors are anonymous. A conversation is identified by its UUID plus a
 * secret token handed to the browser once at start; only the token's SHA-256
 * is stored. Operators need the netarz_ai_support capability.
 *
 * @package NetArz_AI
 */

defined( 'ABSPATH' ) || exit;

class Netarz_AI_Chat {

	const NS = 'netarz-ai/v1';

	const SENDERS = array( 'visitor', 'agent', 'ai', 'system' );

	/** An operator counts as "typing" for this many seconds after a keystroke ping. */
	const TYPING_TTL = 8;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'mount' ) );
		add_action( 'netarz_ai_daily', array( __CLASS__, 'housekeeping' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Tables                                                              */
	/* ------------------------------------------------------------------ */

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'netarz_ai_chats';
	}

	public static function messages_table() {
		global $wpdb;
		return $wpdb->prefix . 'netarz_ai_chat_messages';
	}

	/** @return object|null */
	public static function find( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore
	}

	/** @return object|null */
	public static function find_by_uuid( $uuid ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE uuid = %s', (string) $uuid ) ); // phpcs:ignore
	}

	public static function update( $id, array $fields ) {
		global $wpdb;
		return $wpdb->update( self::table(), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	/**
	 * Append a message and bump the conversation's clock.
	 *
	 * @return int message id
	 */
	public static function add_message( $chat_id, $sender, $body, $author = '', $user_id = 0 ) {
		global $wpdb;
		$now = Netarz_AI_Util::now();
		$wpdb->insert( self::messages_table(), array( // phpcs:ignore
			'chat_id'    => (int) $chat_id,
			'sender'     => in_array( $sender, self::SENDERS, true ) ? $sender : 'system',
			'author'     => Netarz_AI_Util::cut( (string) $author, 110 ),
			'user_id'    => (int) $user_id,
			'body'       => (string) $body,
			'created_at' => $now,
		), array( '%d', '%s', '%s', '%d', '%s', '%s' ) );
		$id = (int) $wpdb->insert_id;
		self::update( $chat_id, array( 'last_message_at' => $now ) );
		return $id;
	}

	/** @return object[] messages with id > $after, oldest first */
	public static function messages( $chat_id, $after = 0, $limit = 200 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			'SELECT * FROM ' . self::messages_table() . ' WHERE chat_id = %d AND id > %d ORDER BY id ASC LIMIT %d',
			(int) $chat_id,
			(int) $after,
			(int) $limit
		) );
	}

	/** @return object[] the last $limit messages, oldest first */
	public static function recent_messages( $chat_id, $limit = 30 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			'SELECT * FROM ' . self::messages_table() . ' WHERE chat_id = %d ORDER BY id DESC LIMIT %d',
			(int) $chat_id,
			(int) $limit
		) );
		return array_reverse( $rows );
	}

	/* ------------------------------------------------------------------ */
	/* Front end                                                           */
	/* ------------------------------------------------------------------ */

	/** Should the widget render on this request? */
	public static function visible() {
		if ( ! Netarz_AI_Settings::get( 'chat_enabled' ) || is_admin() || is_feed() || is_embed() || wp_doing_ajax() ) {
			return false;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}

		$path  = isset( $_SERVER['REQUEST_URI'] ) ? rawurldecode( (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) ) : '/';
		$rules = preg_split( '/\r\n|\r|\n/', (string) Netarz_AI_Settings::get( 'chat_hide_on' ) );
		foreach ( $rules as $rule ) {
			$rule = trim( $rule );
			if ( '' === $rule ) {
				continue;
			}
			if ( ctype_digit( $rule ) ) {
				if ( is_singular() && (int) get_queried_object_id() === (int) $rule ) {
					return false;
				}
				continue;
			}
			// "/checkout" hides that path and everything under it; "*" is a wildcard.
			$pattern = '#^' . str_replace( '\*', '.*', preg_quote( untrailingslashit( $rule ), '#' ) ) . '(/.*)?$#u';
			if ( preg_match( $pattern, untrailingslashit( $path ) ) ) {
				return false;
			}
		}

		return (bool) apply_filters( 'netarz_ai_chat_visible', true );
	}

	public static function enqueue() {
		if ( ! self::visible() ) {
			return;
		}

		wp_enqueue_style( 'netarz-ai-chat', NETARZ_AI_URL . 'assets/css/chat-widget.css', array(), NETARZ_AI_VERSION );
		wp_enqueue_script( 'netarz-ai-chat', NETARZ_AI_URL . 'assets/js/chat-widget.js', array(), NETARZ_AI_VERSION, true );

		$s    = Netarz_AI_Settings::all();
		$user = wp_get_current_user();

		$config = array(
			'rest'        => esc_url_raw( rest_url( self::NS . '/chat' ) ),
			'nonce'       => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'title'       => $s['chat_title'],
			'agentName'   => $s['chat_agent_name'],
			'welcome'     => $s['chat_welcome'],
			'color'       => $s['chat_color'],
			'textColor'   => $s['chat_text_color'],
			'position'    => $s['chat_position'],
			'offset'      => (int) $s['chat_offset_bottom'],
			'askName'     => $s['chat_ask_name'],
			'askContact'  => $s['chat_ask_contact'],
			'teaser'      => $s['chat_teaser_enabled'] ? $s['chat_teaser_text'] : '',
			'teaserDelay' => (int) $s['chat_teaser_delay'],
			'sound'       => (bool) $s['chat_sound'],
			'hideMobile'  => (bool) $s['chat_hide_mobile'],
			'maxLength'   => (int) $s['chat_max_length'],
			'tickets'     => (bool) $s['tickets_enabled'],
			'user'        => $user->exists() ? array(
				'name'  => $user->display_name,
				'email' => $user->user_email,
			) : null,
			'i18n'        => self::strings(),
		);

		wp_add_inline_script( 'netarz-ai-chat', 'window.NetarzAIChat = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/** Widget copy, in one place so it can be translated. */
	private static function strings() {
		return array(
			'open'         => __( 'گفت‌وگو با پشتیبانی', 'netarz-ai' ),
			'close'        => __( 'بستن', 'netarz-ai' ),
			'name'         => __( 'نام شما', 'netarz-ai' ),
			'mobile'       => __( 'شمارهٔ موبایل', 'netarz-ai' ),
			'email'        => __( 'ایمیل', 'netarz-ai' ),
			'mobileOrEmail' => __( 'موبایل یا ایمیل', 'netarz-ai' ),
			'contactHint'  => __( 'اگر صفحه را بستید، با همین راه پیگیری می‌کنیم.', 'netarz-ai' ),
			'optional'     => __( '(اختیاری)', 'netarz-ai' ),
			'message'      => __( 'پیام', 'netarz-ai' ),
			'placeholder'  => __( 'سؤال یا مشکلتان را بنویسید…', 'netarz-ai' ),
			'start'        => __( 'شروع گفت‌وگو', 'netarz-ai' ),
			'send'         => __( 'ارسال', 'netarz-ai' ),
			'typeHere'     => __( 'پیامتان را بنویسید…', 'netarz-ai' ),
			'typing'       => __( 'در حال نوشتن…', 'netarz-ai' ),
			'handoff'      => __( 'گفت‌وگو به همکارمان سپرده شد؛ همین‌جا جواب می‌دهد.', 'netarz-ai' ),
			'human'        => __( 'صحبت با همکار پشتیبانی', 'netarz-ai' ),
			'ended'        => __( 'این گفت‌وگو بسته شده.', 'netarz-ai' ),
			'newChat'      => __( 'شروع گفت‌وگوی تازه', 'netarz-ai' ),
			'end'          => __( 'پایان گفت‌وگو', 'netarz-ai' ),
			'endConfirm'   => __( 'گفت‌وگو بسته شود؟', 'netarz-ai' ),
			'rate'         => __( 'این گفت‌وگو چطور بود؟', 'netarz-ai' ),
			'thanks'       => __( 'ممنون از نظرتان.', 'netarz-ai' ),
			'ticket'       => __( 'ثبت به‌صورت تیکت', 'netarz-ai' ),
			'ticketEmail'  => __( 'ایمیل برای پیگیری تیکت', 'netarz-ai' ),
			'ticketDone'   => __( 'تیکت ثبت شد. لینک پیگیری به ایمیلتان رفت.', 'netarz-ai' ),
			'seen'         => __( 'دیده شد', 'netarz-ai' ),
			'sent'         => __( 'ارسال شد', 'netarz-ai' ),
			'mute'         => __( 'بی‌صدا کردن', 'netarz-ai' ),
			'unmute'       => __( 'روشن‌کردن صدا', 'netarz-ai' ),
			'offline'      => __( 'خارج از ساعت کاری', 'netarz-ai' ),
			'online'       => __( 'آنلاین', 'netarz-ai' ),
			'errorGeneric' => __( 'ارسال نشد. اتصال اینترنت را بررسی کنید و دوباره بفرستید.', 'netarz-ai' ),
			'errorName'    => __( 'نامتان را بنویسید.', 'netarz-ai' ),
			'errorContact' => __( 'یک راه تماس درست وارد کنید.', 'netarz-ai' ),
			'errorMessage' => __( 'پیامتان را بنویسید.', 'netarz-ai' ),
			'tooLong'      => __( 'پیام خیلی طولانی است. کوتاه‌ترش کنید یا در دو پیام بفرستید.', 'netarz-ai' ),
			'you'          => __( 'شما', 'netarz-ai' ),
		);
	}

	public static function mount() {
		if ( wp_script_is( 'netarz-ai-chat', 'enqueued' ) ) {
			echo '<div id="netarz-ai-chat-root" class="nzai-root" hidden></div>';
		}
	}

	/* ------------------------------------------------------------------ */
	/* REST routes                                                         */
	/* ------------------------------------------------------------------ */

	public static function routes() {
		$uuid = '(?P<uuid>[a-f0-9\-]{36})';

		register_rest_route( self::NS, '/chat/status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_status' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/chat/start', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_start' ),
			'permission_callback' => '__return_true',
		) );

		$visitor = array(
			'poll'   => array( 'GET', 'rest_poll' ),
			'send'   => array( 'POST', 'rest_send' ),
			'reply'  => array( 'POST', 'rest_reply' ),
			'human'  => array( 'POST', 'rest_human' ),
			'rate'   => array( 'POST', 'rest_rate' ),
			'end'    => array( 'POST', 'rest_end' ),
			'ticket' => array( 'POST', 'rest_ticket' ),
		);
		foreach ( $visitor as $path => $def ) {
			register_rest_route( self::NS, '/chat/' . $uuid . '/' . $path, array(
				'methods'             => $def[0],
				'callback'            => array( __CLASS__, $def[1] ),
				'permission_callback' => '__return_true', // The token check happens in visitor_chat().
			) );
		}

		// Operator inbox.
		$can = array( __CLASS__, 'can_operate' );
		register_rest_route( self::NS, '/inbox', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_inbox' ),
			'permission_callback' => $can,
		) );
		register_rest_route( self::NS, '/inbox/unread', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_unread' ),
			'permission_callback' => $can,
		) );
		register_rest_route( self::NS, '/inbox/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_thread' ),
				'permission_callback' => $can,
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'rest_delete' ),
				'permission_callback' => $can,
			),
		) );
		foreach ( array( 'reply', 'typing', 'ai', 'status', 'ticket', 'draft' ) as $action ) {
			register_rest_route( self::NS, '/inbox/(?P<id>\d+)/' . $action, array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_op_' . $action ),
				'permission_callback' => $can,
			) );
		}
	}

	public static function can_operate() {
		return current_user_can( Netarz_AI_Installer::CAP_SUPPORT );
	}

	/**
	 * Resolve the visitor's conversation from UUID + token.
	 *
	 * @return object|WP_Error
	 */
	private static function visitor_chat( WP_REST_Request $request ) {
		$chat  = self::find_by_uuid( (string) $request['uuid'] );
		// Header only: a token in the query string would end up in access logs.
		$token = (string) $request->get_header( 'x_netarz_token' );

		if ( ! $chat || '' === $token || ! hash_equals( $chat->token_hash, hash( 'sha256', $token ) ) ) {
			return new WP_Error( 'chat_not_found', __( 'این گفت‌وگو پیدا نشد.', 'netarz-ai' ), array( 'status' => 404 ) );
		}
		return $chat;
	}

	/** What the widget needs to know about the conversation right now. */
	private static function state( $chat ) {
		$typing = $chat->agent_typing_at && ( time() - Netarz_AI_Util::ts( $chat->agent_typing_at ) ) < self::TYPING_TTL;

		return array(
			'status'   => $chat->status,
			'handoff'  => (bool) $chat->needs_human,
			'typing'   => $typing,
			'seen'     => (int) $chat->admin_seen_id,
			'rating'   => (int) $chat->rating,
			'ticket'   => (int) $chat->ticket_id > 0,
			'online'   => Netarz_AI_Util::within_hours(),
			'aiActive' => self::ai_active( $chat ),
			'awaiting' => self::ai_active( $chat ) && self::awaiting_ai( $chat ),
		);
	}

	/**
	 * Does the conversation end in visitor messages the assistant has not handled yet?
	 * The widget asks for a reply only while this is true, so a page change or a
	 * dropped request never leaves a question unanswered, and nothing is answered twice.
	 */
	public static function awaiting_ai( $chat ) {
		global $wpdb;
		$last = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore
			'SELECT id, sender FROM ' . self::messages_table() . " WHERE chat_id = %d AND sender <> 'system' ORDER BY id DESC LIMIT 1",
			(int) $chat->id
		) );
		return $last && 'visitor' === $last->sender && (int) $last->id > (int) $chat->ai_seen_id;
	}

	/** Will the assistant answer the next visitor message in this conversation? */
	public static function ai_active( $chat ) {
		return Netarz_AI_Settings::get( 'chat_ai_enabled' )
			&& Netarz_AI_Api::has_key()
			&& 'open' === $chat->status
			&& (int) $chat->ai_enabled
			&& ! (int) $chat->needs_human;
	}

	/** Messages as the visitor sees them. */
	private static function visitor_messages( array $rows ) {
		$agent = (string) Netarz_AI_Settings::get( 'chat_agent_name' );
		$out   = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'     => (int) $row->id,
				'sender' => 'visitor' === $row->sender ? 'visitor' : ( 'system' === $row->sender ? 'system' : 'agent' ),
				'author' => 'visitor' === $row->sender || 'system' === $row->sender ? '' : ( '' !== $row->author ? $row->author : $agent ),
				'body'   => $row->body,
				'time'   => Netarz_AI_Util::date( $row->created_at, get_option( 'time_format' ) ),
			);
		}
		return $out;
	}

	public static function rest_status() {
		return rest_ensure_response( array(
			'online'  => Netarz_AI_Util::within_hours(),
			'offline' => (string) Netarz_AI_Settings::get( 'chat_offline_text' ),
		) );
	}

	public static function rest_start( WP_REST_Request $request ) {
		if ( ! Netarz_AI_Settings::get( 'chat_enabled' ) ) {
			return new WP_Error( 'chat_disabled', __( 'گفت‌وگوی آنلاین فعلاً خاموش است.', 'netarz-ai' ), array( 'status' => 403 ) );
		}

		// Honeypot: a field no human sees.
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return new WP_Error( 'spam', __( 'پیام فرستاده نشد. صفحه را تازه کنید و دوباره بفرستید.', 'netarz-ai' ), array( 'status' => 400 ) );
		}

		$user    = wp_get_current_user();
		$s       = Netarz_AI_Settings::all();
		$message = Netarz_AI_Util::clean_text( $request->get_param( 'message' ) );
		$name    = trim( sanitize_text_field( (string) $request->get_param( 'name' ) ) );
		$contact = trim( sanitize_text_field( (string) $request->get_param( 'contact' ) ) );
		$email   = '';
		$mobile  = '';

		if ( '' === $message ) {
			return new WP_Error( 'message_required', __( 'پیامتان را بنویسید.', 'netarz-ai' ), array( 'status' => 422 ) );
		}
		if ( Netarz_AI_Util::length( $message ) > (int) $s['chat_max_length'] ) {
			return new WP_Error( 'message_too_long', __( 'پیام خیلی طولانی است. کوتاه‌ترش کنید یا در دو پیام بفرستید.', 'netarz-ai' ), array( 'status' => 422 ) );
		}

		if ( $user->exists() ) {
			$name  = '' !== $name ? $name : $user->display_name;
			$email = $user->user_email;
		} else {
			if ( 'required' === $s['chat_ask_name'] && '' === $name ) {
				return new WP_Error( 'name_required', __( 'نامتان را بنویسید.', 'netarz-ai' ), array( 'status' => 422 ) );
			}

			$mode = $s['chat_ask_contact'];
			if ( '' !== $contact ) {
				if ( is_email( $contact ) ) {
					$email = sanitize_email( $contact );
				} else {
					$mobile = Netarz_AI_Util::mobile( $contact );
				}
			}
			$valid = array(
				'off'             => true,
				'optional'        => '' === $contact || '' !== $email || '' !== $mobile,
				'mobile_or_email' => '' !== $email || '' !== $mobile,
				'mobile'          => '' !== $mobile,
				'email'           => '' !== $email,
			);
			if ( empty( $valid[ $mode ] ) ) {
				return new WP_Error( 'contact_required', __( 'یک راه تماس درست وارد کنید.', 'netarz-ai' ), array( 'status' => 422 ) );
			}
		}

		if ( ! Netarz_AI_Util::rate_limit( 'chat_start_' . Netarz_AI_Util::ip_hash(), (int) $s['chat_hourly_starts'], HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'too_many', __( 'در این یک ساعت گفت‌وگوهای زیادی از این اتصال شروع شده. کمی بعد دوباره امتحان کنید.', 'netarz-ai' ), array( 'status' => 429 ) );
		}

		global $wpdb;
		$token = Netarz_AI_Util::token();
		$now   = Netarz_AI_Util::now();
		$ua    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$page  = esc_url_raw( (string) $request->get_param( 'page' ) );

		$wpdb->insert( self::table(), array( // phpcs:ignore
			'uuid'            => Netarz_AI_Util::uuid(),
			'token_hash'      => hash( 'sha256', $token ),
			'user_id'         => (int) $user->ID,
			'name'            => Netarz_AI_Util::cut( $name, 110 ),
			'email'           => $email,
			'mobile'          => $mobile,
			'status'          => 'open',
			'ai_enabled'      => 1,
			'page_url'        => substr( $page, 0, 490 ),
			'user_agent'      => substr( $ua, 0, 250 ),
			'ip_hash'         => Netarz_AI_Util::ip_hash(),
			'last_message_at' => $now,
			'created_at'      => $now,
		) );
		$chat_id = (int) $wpdb->insert_id;
		if ( ! $chat_id ) {
			return new WP_Error( 'db_error', __( 'گفت‌وگو ثبت نشد. دوباره امتحان کنید.', 'netarz-ai' ), array( 'status' => 500 ) );
		}

		$welcome = trim( (string) $s['chat_welcome'] );
		if ( '' !== $welcome ) {
			self::add_message( $chat_id, 'agent', $welcome, $s['chat_agent_name'] );
		}
		self::add_message( $chat_id, 'visitor', $message, $name, (int) $user->ID );

		$chat = self::find( $chat_id );

		// Outside support hours with nobody (and no assistant) to answer: say so now.
		$offline = trim( (string) $s['chat_offline_text'] );
		if ( ! self::ai_active( $chat ) && ! Netarz_AI_Util::within_hours() && '' !== $offline ) {
			self::add_message( $chat_id, 'system', $offline );
		}

		if ( 'all' === $s['chat_notify_on'] ) {
			self::notify( $chat, 'new', $message );
		}

		do_action( 'netarz_ai_chat_started', $chat );

		return rest_ensure_response( array(
			'uuid'     => $chat->uuid,
			'token'    => $token,
			'state'    => self::state( $chat ),
			'messages' => self::visitor_messages( self::messages( $chat_id ) ),
		) );
	}

	public static function rest_poll( WP_REST_Request $request ) {
		$chat = self::visitor_chat( $request );
		if ( is_wp_error( $chat ) ) {
			return $chat;
		}

		$after = max( 0, (int) $request->get_param( 'after' ) );
		$rows  = self::messages( $chat->id, $after );

		// "Seen" only when the panel is open in a visible tab; background polls just say "on the page".
		$latest = $rows ? (int) end( $rows )->id : 0;
		if ( $request->get_param( 'seen' ) && $latest > (int) $chat->visitor_seen_id ) {
			self::update( $chat->id, array(
				'visitor_seen_id' => $latest,
				'visitor_seen_at' => Netarz_AI_Util::now(),
			) );
		} else {
			self::update( $chat->id, array( 'visitor_seen_at' => Netarz_AI_Util::now() ) );
		}

		return rest_ensure_response( array(
			'state'    => self::state( $chat ),
			'messages' => self::visitor_messages( $rows ),
		) );
	}

	public static function rest_send( WP_REST_Request $request ) {
		$chat = self::visitor_chat( $request );
		if ( is_wp_error( $chat ) ) {
			return $chat;
		}
		if ( 'open' !== $chat->status ) {
			return new WP_Error( 'chat_closed', __( 'این گفت‌وگو بسته شده.', 'netarz-ai' ), array( 'status' => 409 ) );
		}

		$body = Netarz_AI_Util::clean_text( $request->get_param( 'body' ) );
		if ( '' === $body ) {
			return new WP_Error( 'message_required', __( 'پیامتان را بنویسید.', 'netarz-ai' ), array( 'status' => 422 ) );
		}
		if ( Netarz_AI_Util::length( $body ) > (int) Netarz_AI_Settings::get( 'chat_max_length' ) ) {
			return new WP_Error( 'message_too_long', __( 'پیام خیلی طولانی است. کوتاه‌ترش کنید یا در دو پیام بفرستید.', 'netarz-ai' ), array( 'status' => 422 ) );
		}
		if ( ! Netarz_AI_Util::rate_limit( 'chat_send_' . $chat->id, 20, MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'too_many', __( 'پیام‌ها خیلی پشت سر هم آمد. چند ثانیه صبر کنید.', 'netarz-ai' ), array( 'status' => 429 ) );
		}

		// The same text twice within a few seconds is a double tap, not a new message.
		$last = self::recent_messages( $chat->id, 1 );
		if ( $last && 'visitor' === $last[0]->sender && $last[0]->body === $body && ( time() - Netarz_AI_Util::ts( $last[0]->created_at ) ) < 10 ) {
			$id = (int) $last[0]->id;
		} else {
			$id = self::add_message( $chat->id, 'visitor', $body, $chat->name, (int) $chat->user_id );
			if ( 'all' === Netarz_AI_Settings::get( 'chat_notify_on' ) && ! self::ai_active( $chat ) ) {
				self::notify( $chat, 'message', $body );
			}
		}

		$after = max( 0, (int) $request->get_param( 'after' ) );

		return rest_ensure_response( array(
			'id'       => $id,
			'state'    => self::state( self::find( $chat->id ) ),
			'messages' => self::visitor_messages( self::messages( $chat->id, $after ) ),
		) );
	}

	/** Ask the assistant to answer what the visitor has written so far. */
	public static function rest_reply( WP_REST_Request $request ) {
		$chat = self::visitor_chat( $request );
		if ( is_wp_error( $chat ) ) {
			return $chat;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		ignore_user_abort( true ); // A reply that is already paid for still gets saved.

		$after = max( 0, (int) $request->get_param( 'after' ) );

		// Each call can cost a model request: cap them per conversation and per connection.
		if ( ! Netarz_AI_Util::rate_limit( 'chat_reply_' . $chat->id, 30, 10 * MINUTE_IN_SECONDS )
			|| ! Netarz_AI_Util::rate_limit( 'chat_reply_ip_' . Netarz_AI_Util::ip_hash(), 90, HOUR_IN_SECONDS ) ) {
			$result = 'busy';
		} else {
			$result = Netarz_AI_Chat_Agent::respond( $chat );
		}
		$chat   = self::find( $chat->id );

		return rest_ensure_response( array(
			'result'   => $result,
			'state'    => self::state( $chat ),
			'messages' => self::visitor_messages( self::messages( $chat->id, $after ) ),
		) );
	}

	public static function rest_human( WP_REST_Request $request ) {
		$chat = self::visitor_chat( $request );
		if ( is_wp_error( $chat ) ) {
			return $chat;
		}
		if ( 'open' === $chat->status && ! (int) $chat->needs_human ) {
			self::handoff( $chat, __( 'مشتری خودش خواست با همکار صحبت کند.', 'netarz-ai' ), '' );
		}
		$after = max( 0, (int) $request->get_param( 'after' ) );
		$chat  = self::find( $chat->id );
		return rest_ensure_response( array(
			'state'    => self::state( $chat ),
			'messages' => self::visitor_messages( self::messages( $chat->id, $after ) ),
		) );
	}

	public static function rest_rate( WP_REST_Request $request ) {
		$chat = self::visitor_chat( $request );
		if ( is_wp_error( $chat ) ) {
			return $chat;
		}
		$rating = (int) $request->get_param( 'rating' );
		if ( $rating < 1 || $rating > 5 ) {
			return new WP_Error( 'invalid_rating', __( 'امتیاز باید بین ۱ تا ۵ باشد.', 'netarz-ai' ), array( 'status' => 422 ) );
		}
		self::update( $chat->id, array( 'rating' => $rating ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function rest_end( WP_REST_Request $request ) {
		$chat = self::visitor_chat( $request );
		if ( is_wp_error( $chat ) ) {
			return $chat;
		}
		if ( 'open' === $chat->status ) {
			self::update( $chat->id, array( 'status' => 'closed' ) );
			self::add_message( $chat->id, 'system', __( 'گفت‌وگو بسته شد.', 'netarz-ai' ) );
		}
		return rest_ensure_response( array( 'state' => self::state( self::find( $chat->id ) ) ) );
	}

	public static function rest_ticket( WP_REST_Request $request ) {
		$chat = self::visitor_chat( $request );
		if ( is_wp_error( $chat ) ) {
			return $chat;
		}
		if ( ! Netarz_AI_Settings::get( 'tickets_enabled' ) ) {
			return new WP_Error( 'tickets_disabled', __( 'ثبت تیکت فعلاً خاموش است.', 'netarz-ai' ), array( 'status' => 403 ) );
		}
		if ( (int) $chat->ticket_id ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}

		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( '' === $email ) {
			$email = $chat->email;
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'email_required', __( 'برای پیگیری تیکت یک ایمیل درست لازم است.', 'netarz-ai' ), array( 'status' => 422 ) );
		}
		if ( ! Netarz_AI_Util::rate_limit( 'chat_ticket_' . Netarz_AI_Util::ip_hash(), 5, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'too_many', __( 'کمی بعد دوباره امتحان کنید.', 'netarz-ai' ), array( 'status' => 429 ) );
		}

		$ticket_id = Netarz_AI_Tickets::create_from_chat( $chat, $email );
		if ( is_wp_error( $ticket_id ) ) {
			return $ticket_id;
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ------------------------------------------------------------------ */
	/* Shared actions                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Pass the conversation to a person.
	 *
	 * @param string $reason  note for the operator
	 * @param string $message what the visitor is told ('' = the default line)
	 */
	public static function handoff( $chat, $reason, $message ) {
		$fields = array(
			'needs_human'    => 1,
			'handoff_reason' => Netarz_AI_Util::cut( (string) $reason, 250 ),
		);
		if ( Netarz_AI_Settings::get( 'chat_pause_on_agent' ) ) {
			$fields['ai_enabled'] = 0;
		}
		self::update( $chat->id, $fields );

		$message = trim( (string) $message );
		if ( '' === $message ) {
			$message = Netarz_AI_Util::within_hours()
				? __( 'گفت‌وگو را به همکارم می‌سپارم؛ همین‌جا جوابتان را می‌دهد.', 'netarz-ai' )
				: (string) Netarz_AI_Settings::get( 'chat_offline_text' );
		}
		if ( '' !== $message ) {
			self::add_message( $chat->id, 'agent', $message, Netarz_AI_Settings::get( 'chat_agent_name' ) );
		}

		if ( 'off' !== Netarz_AI_Settings::get( 'chat_notify_on' ) ) {
			self::notify( self::find( $chat->id ), 'handoff', $reason );
		}

		do_action( 'netarz_ai_chat_handoff', $chat, $reason );
	}

	/** Email the support team. Throttled to one mail per conversation per 10 minutes. */
	public static function notify( $chat, $event, $text ) {
		if ( ! $chat || ! Netarz_AI_Util::rate_limit( 'chat_mail_' . $chat->id, 1, 10 * MINUTE_IN_SECONDS ) ) {
			return;
		}

		$link    = admin_url( 'admin.php?page=netarz-ai-inbox&chat=' . (int) $chat->id );
		$who     = '' !== $chat->name ? $chat->name : __( 'بازدیدکننده', 'netarz-ai' );
		$subject = 'handoff' === $event
			/* translators: %s: visitor name */
			? sprintf( __( 'گفت‌وگوی %s به پاسخ شما نیاز دارد', 'netarz-ai' ), $who )
			/* translators: %s: visitor name */
			: sprintf( __( 'پیام تازه در گفت‌وگوی آنلاین از %s', 'netarz-ai' ), $who );

		$html  = '<p>' . esc_html( $subject ) . '</p>';
		$html .= '<blockquote style="margin:12px 0;padding:10px 14px;background:#f9fafb;border-right:3px solid #f5b301">' . nl2br( esc_html( Netarz_AI_Util::cut( $text, 600 ) ) ) . '</blockquote>';
		$html .= '<p><a href="' . esc_url( $link ) . '">' . esc_html__( 'باز کردن گفت‌وگو', 'netarz-ai' ) . '</a></p>';

		Netarz_AI_Util::mail( Netarz_AI_Settings::emails( 'chat_notify_emails' ), $subject, $html );
	}

	/* ------------------------------------------------------------------ */
	/* Operator inbox                                                      */
	/* ------------------------------------------------------------------ */

	/** Conversations that are waiting for a person. */
	public static function unread_count() {
		global $wpdb;
		$c  = self::table();
		$m  = self::messages_table();
		$ai = Netarz_AI_Settings::get( 'chat_ai_enabled' ) && Netarz_AI_Api::has_key() ? 1 : 0;

		return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
			"SELECT COUNT(*) FROM {$c} c WHERE c.status = 'open' AND ( c.needs_human = 1 OR c.ai_enabled = 0 OR %d = 0 )
			AND EXISTS ( SELECT 1 FROM {$m} m WHERE m.chat_id = c.id AND m.sender = 'visitor' AND m.id > c.admin_seen_id )",
			$ai
		) );
	}

	public static function rest_unread() {
		return rest_ensure_response( array( 'count' => self::unread_count() ) );
	}

	public static function rest_inbox( WP_REST_Request $request ) {
		global $wpdb;
		$c = self::table();
		$m = self::messages_table();

		$filter = sanitize_key( (string) $request->get_param( 'filter' ) );
		$search = trim( sanitize_text_field( (string) $request->get_param( 'search' ) ) );
		$page   = max( 1, (int) $request->get_param( 'page' ) );
		$per    = 30;

		$where = array( '1=1' );
		$args  = array();
		switch ( $filter ) {
			case 'waiting':
				$where[] = "c.status = 'open' AND c.needs_human = 1";
				break;
			case 'open':
				$where[] = "c.status = 'open'";
				break;
			case 'closed':
				$where[] = "c.status = 'closed'";
				break;
		}
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = "( c.name LIKE %s OR c.email LIKE %s OR c.mobile LIKE %s OR EXISTS ( SELECT 1 FROM {$m} s WHERE s.chat_id = c.id AND s.body LIKE %s ) )";
			array_push( $args, $like, $like, $like, $like );
		}

		$sql_where = implode( ' AND ', $where );
		$args[]    = $per;
		$args[]    = ( $page - 1 ) * $per;

		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			"SELECT c.*,
				( SELECT COUNT(*) FROM {$m} u WHERE u.chat_id = c.id AND u.sender = 'visitor' AND u.id > c.admin_seen_id ) AS unread,
				( SELECT l.body FROM {$m} l WHERE l.chat_id = c.id AND l.sender <> 'system' ORDER BY l.id DESC LIMIT 1 ) AS last_body,
				( SELECT l.sender FROM {$m} l WHERE l.chat_id = c.id AND l.sender <> 'system' ORDER BY l.id DESC LIMIT 1 ) AS last_sender
			FROM {$c} c WHERE {$sql_where}
			ORDER BY ( c.status = 'open' AND c.needs_human = 1 ) DESC, c.last_message_at DESC
			LIMIT %d OFFSET %d",
			$args
		) );

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::inbox_item( $row );
		}

		return rest_ensure_response( array(
			'items'  => $items,
			'unread' => self::unread_count(),
			'more'   => count( $rows ) === $per,
		) );
	}

	private static function inbox_item( $row ) {
		return array(
			'id'          => (int) $row->id,
			'name'        => '' !== $row->name ? $row->name : __( 'بازدیدکننده', 'netarz-ai' ) . ' #' . (int) $row->id,
			'contact'     => '' !== $row->mobile ? $row->mobile : $row->email,
			'status'      => $row->status,
			'waiting'     => (bool) $row->needs_human,
			'ai'          => (bool) $row->ai_enabled,
			'unread'      => isset( $row->unread ) ? (int) $row->unread : 0,
			'last'        => isset( $row->last_body ) ? Netarz_AI_Util::cut( (string) $row->last_body, 90 ) : '',
			'last_sender' => isset( $row->last_sender ) ? (string) $row->last_sender : '',
			'ago'         => Netarz_AI_Util::ago( $row->last_message_at ),
			'rating'      => (int) $row->rating,
		);
	}

	/** @return object|WP_Error */
	private static function op_chat( WP_REST_Request $request ) {
		$chat = self::find( (int) $request['id'] );
		return $chat ? $chat : new WP_Error( 'chat_not_found', __( 'این گفت‌وگو پیدا نشد.', 'netarz-ai' ), array( 'status' => 404 ) );
	}

	/** Messages as the operator sees them. */
	private static function op_messages( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'     => (int) $row->id,
				'sender' => $row->sender,
				'author' => $row->author,
				'body'   => $row->body,
				'time'   => Netarz_AI_Util::date( $row->created_at ),
			);
		}
		return $out;
	}

	public static function rest_thread( WP_REST_Request $request ) {
		$chat = self::op_chat( $request );
		if ( is_wp_error( $chat ) ) {
			return $chat;
		}

		$after = max( 0, (int) $request->get_param( 'after' ) );
		$rows  = self::messages( $chat->id, $after, 500 );

		$latest = 0;
		foreach ( $rows as $row ) {
			$latest = max( $latest, (int) $row->id );
		}
		if ( $latest > (int) $chat->admin_seen_id ) {
			self::update( $chat->id, array( 'admin_seen_id' => $latest ) );
			$chat->admin_seen_id = $latest;
		}

		$visitor_typing = false; // The widget does not report keystrokes; only seen state.

		return rest_ensure_response( array(
			'chat'     => array(
				'id'          => (int) $chat->id,
				'name'        => '' !== $chat->name ? $chat->name : __( 'بازدیدکننده', 'netarz-ai' ) . ' #' . (int) $chat->id,
				'email'       => $chat->email,
				'mobile'      => $chat->mobile,
				'user_id'     => (int) $chat->user_id,
				'user_link'   => (int) $chat->user_id ? get_edit_user_link( (int) $chat->user_id ) : '',
				'status'      => $chat->status,
				'waiting'     => (bool) $chat->needs_human,
				'reason'      => $chat->handoff_reason,
				'ai'          => (bool) $chat->ai_enabled,
				'ai_global'   => (bool) Netarz_AI_Settings::get( 'chat_ai_enabled' ),
				'ai_replies'  => (int) $chat->ai_replies,
				'page'        => $chat->page_url,
				'agent'       => $chat->user_agent,
				'rating'      => (int) $chat->rating,
				'ticket_id'   => (int) $chat->ticket_id,
				'ticket_link' => (int) $chat->ticket_id ? admin_url( 'admin.php?page=netarz-ai-tickets&ticket=' . (int) $chat->ticket_id ) : '',
				'created'     => Netarz_AI_Util::date( $chat->created_at ),
				'visitor_seen' => (int) $chat->visitor_seen_id,
				'visitor_online' => $chat->visitor_seen_at && ( time() - Netarz_AI_Util::ts( $chat->visitor_seen_at ) ) < 40,
				'typing'      => $visitor_typing,
			),
			'messages' => self::op_messages( $rows ),
		) );
	}

	public static function rest_op_reply( WP_REST_Request $request ) {
		$chat = self::op_chat( $request );
		if ( is_wp_error( $chat ) ) {
			return $chat;
		}
		$body = Netarz_AI_Util::clean_text( $request->get_param( 'body' ), 5000 );
		if ( '' === $body ) {
			return new WP_Error( 'message_required', __( 'متن پاسخ خالی است.', 'netarz-ai' ), array( 'status' => 422 ) );
		}

		$user   = wp_get_current_user();
		$fields = array(
			'needs_human'     => 0,
			'agent_typing_at' => null,
		);
		if ( Netarz_AI_Settings::get( 'chat_pause_on_agent' ) ) {
			$fields['ai_enabled'] = 0;
		}
		if ( 'closed' === $chat->status ) {
			$fields['status'] = 'open';
		}
		self::update( $chat->id, $fields );

		$id = self::add_message( $chat->id, 'agent', $body, $user->display_name, $user->ID );
		self::update( $chat->id, array( 'admin_seen_id' => $id ) );

		return self::rest_thread( $request );
	}

	public static function rest_op_typing( WP_REST_Request $request ) {
		$chat = self::op_chat( $request );
		if ( is_wp_error( $chat ) ) {
			return $chat;
		}
		self::update( $chat->id, array( 'agent_typing_at' => Netarz_AI_Util::now() ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function rest_op_ai( WP_REST_Request $request ) {
		$chat = self::op_chat( $request );
		if ( is_wp_error( $chat ) ) {
			return $chat;
		}
		$on     = rest_sanitize_boolean( $request->get_param( 'enabled' ) );
		$fields = array( 'ai_enabled' => $on ? 1 : 0 );
		if ( $on ) {
			// A fresh start: otherwise a chat that hit its reply cap would hand off again at once.
			$fields['needs_human'] = 0;
			$fields['ai_replies']  = 0;
		}
		self::update( $chat->id, $fields );
		return self::rest_thread( $request );
	}

	public static function rest_op_status( WP_REST_Request $request ) {
		$chat = self::op_chat( $request );
		if ( is_wp_error( $chat ) ) {
			return $chat;
		}
		$status = 'closed' === $request->get_param( 'status' ) ? 'closed' : 'open';
		if ( $status !== $chat->status ) {
			$fields = array( 'status' => $status );
			if ( 'closed' === $status ) {
				$fields['needs_human'] = 0;
			}
			self::update( $chat->id, $fields );
			self::add_message( $chat->id, 'system', 'closed' === $status ? __( 'گفت‌وگو بسته شد.', 'netarz-ai' ) : __( 'گفت‌وگو دوباره باز شد.', 'netarz-ai' ) );
		}
		return self::rest_thread( $request );
	}

	public static function rest_op_ticket( WP_REST_Request $request ) {
		$chat = self::op_chat( $request );
		if ( is_wp_error( $chat ) ) {
			return $chat;
		}
		if ( (int) $chat->ticket_id ) {
			return self::rest_thread( $request );
		}
		if ( ! is_email( $chat->email ) && ! (int) $chat->user_id ) {
			return new WP_Error( 'email_required', __( 'این مشتری ایمیل ثبت نکرده؛ تیکت بدون ایمیل قابل پیگیری نیست.', 'netarz-ai' ), array( 'status' => 422 ) );
		}
		$owner = (int) $chat->user_id ? get_userdata( (int) $chat->user_id ) : false;
		$email = is_email( $chat->email ) ? $chat->email : ( $owner ? $owner->user_email : '' );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'email_required', __( 'این مشتری ایمیل ثبت نکرده؛ تیکت بدون ایمیل قابل پیگیری نیست.', 'netarz-ai' ), array( 'status' => 422 ) );
		}
		$ticket = Netarz_AI_Tickets::create_from_chat( $chat, $email );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}
		return self::rest_thread( $request );
	}

	/** A suggested reply for the operator — never sent by itself. */
	public static function rest_op_draft( WP_REST_Request $request ) {
		$chat = self::op_chat( $request );
		if ( is_wp_error( $chat ) ) {
			return $chat;
		}
		$draft = Netarz_AI_Chat_Agent::draft( $chat );
		if ( is_wp_error( $draft ) ) {
			return new WP_Error( $draft->get_error_code(), Netarz_AI_Api::friendly_error( $draft ), array( 'status' => 502 ) );
		}
		return rest_ensure_response( array( 'draft' => $draft ) );
	}

	public static function rest_delete( WP_REST_Request $request ) {
		global $wpdb;
		$chat = self::op_chat( $request );
		if ( is_wp_error( $chat ) ) {
			return $chat;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'فقط مدیر سایت می‌تواند گفت‌وگو را حذف کند.', 'netarz-ai' ), array( 'status' => 403 ) );
		}
		$wpdb->delete( self::messages_table(), array( 'chat_id' => (int) $chat->id ), array( '%d' ) ); // phpcs:ignore
		$wpdb->delete( self::table(), array( 'id' => (int) $chat->id ), array( '%d' ) ); // phpcs:ignore
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/* ------------------------------------------------------------------ */
	/* Housekeeping                                                        */
	/* ------------------------------------------------------------------ */

	public static function housekeeping() {
		global $wpdb;
		$c = self::table();
		$m = self::messages_table();

		// Quiet for two days → closed. A chat still waiting for a person stays open until someone answers.
		$wpdb->query( $wpdb->prepare( // phpcs:ignore
			"UPDATE {$c} SET status = 'closed' WHERE status = 'open' AND needs_human = 0 AND last_message_at < %s",
			gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS )
		) );

		$days = (int) Netarz_AI_Settings::get( 'chat_retention_days' );
		if ( $days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$c} WHERE status = 'closed' AND last_message_at < %s LIMIT 1000", $cutoff ) ); // phpcs:ignore
			if ( $ids ) {
				$in = implode( ',', array_map( 'intval', $ids ) );
				$wpdb->query( "DELETE FROM {$m} WHERE chat_id IN ({$in})" ); // phpcs:ignore
				$wpdb->query( "DELETE FROM {$c} WHERE id IN ({$in})" ); // phpcs:ignore
			}
		}
	}
}
