<?php
/**
 * Support tickets: storage, customer-facing pages, attachments and mail.
 *
 * Customers use the [netarz_ai_tickets] shortcode (a page is created on
 * activation) or the "Support" tab of the WooCommerce account. Guests reach
 * their ticket through a link carrying a secret key; members through their
 * account. Staff work in wp-admin (see Netarz_AI_Tickets_Admin in class-netarz-ai-admin.php).
 *
 * @package NetArz_AI
 */

defined( 'ABSPATH' ) || exit;

class Netarz_AI_Tickets {

	const WOO_ENDPOINT = 'support-tickets';

	const MAX_FILES = 3;

	/** @var array flash message for the current render */
	private static $flash = array();

	public static function init() {
		add_shortcode( 'netarz_ai_tickets', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ) );
		add_action( 'init', array( __CLASS__, 'maybe_download' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'netarz_ai_daily', array( __CLASS__, 'auto_close' ) );

		// WooCommerce "My account → Support" tab.
		add_action( 'init', array( __CLASS__, 'woo_endpoint' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_flush' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'woo_menu' ) );
		add_action( 'woocommerce_account_' . self::WOO_ENDPOINT . '_endpoint', array( __CLASS__, 'woo_content' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Vocabulary                                                          */
	/* ------------------------------------------------------------------ */

	public static function statuses() {
		return array(
			'open'           => __( 'باز', 'netarz-ai' ),
			'customer_reply' => __( 'پاسخ مشتری', 'netarz-ai' ),
			'answered'       => __( 'پاسخ داده شد', 'netarz-ai' ),
			'on_hold'        => __( 'در حال پیگیری', 'netarz-ai' ),
			'closed'         => __( 'بسته', 'netarz-ai' ),
		);
	}

	/** Status as the customer reads it. */
	public static function customer_status( $status ) {
		$map = array(
			'open'           => __( 'در انتظار پاسخ', 'netarz-ai' ),
			'customer_reply' => __( 'در انتظار پاسخ', 'netarz-ai' ),
			'answered'       => __( 'پاسخ داده شد', 'netarz-ai' ),
			'on_hold'        => __( 'در حال پیگیری', 'netarz-ai' ),
			'closed'         => __( 'بسته', 'netarz-ai' ),
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : $status;
	}

	public static function priorities() {
		return array(
			'low'    => __( 'کم', 'netarz-ai' ),
			'normal' => __( 'معمولی', 'netarz-ai' ),
			'high'   => __( 'زیاد', 'netarz-ai' ),
			'urgent' => __( 'فوری', 'netarz-ai' ),
		);
	}

	public static function label( array $map, $key ) {
		return isset( $map[ $key ] ) ? $map[ $key ] : $key;
	}

	/* ------------------------------------------------------------------ */
	/* Storage                                                             */
	/* ------------------------------------------------------------------ */

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'netarz_ai_tickets';
	}

	public static function replies_table() {
		global $wpdb;
		return $wpdb->prefix . 'netarz_ai_ticket_replies';
	}

	/** @return object|null */
	public static function find( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore
	}

	public static function update( $id, array $fields ) {
		global $wpdb;
		return $wpdb->update( self::table(), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	/**
	 * @param bool $staff include internal notes and AI drafts
	 * @return object[]
	 */
	public static function replies( $ticket_id, $staff = false ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . self::replies_table() . ' WHERE ticket_id = %d';
		if ( ! $staff ) {
			$sql .= " AND author_type NOT IN ('note','draft')";
		}
		return $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY id ASC', (int) $ticket_id ) ); // phpcs:ignore
	}

	/** @return object|null */
	public static function find_reply( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::replies_table() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore
	}

	/**
	 * Open a ticket.
	 *
	 * @param array $data subject, body, department, priority, user_id, name, email, phone, source, order_ref, attachments
	 * @return int|WP_Error ticket id
	 */
	public static function create( array $data ) {
		global $wpdb;

		$data = wp_parse_args( $data, array(
			'subject'     => '',
			'body'        => '',
			'department'  => '',
			'priority'    => 'normal',
			'user_id'     => 0,
			'name'        => '',
			'email'       => '',
			'phone'       => '',
			'source'      => 'form',
			'order_ref'   => '',
			'attachments' => array(),
			'notify'      => true,
		) );

		if ( '' === trim( $data['subject'] ) || '' === trim( $data['body'] ) ) {
			return new WP_Error( 'missing_fields', __( 'موضوع و متن تیکت را بنویسید.', 'netarz-ai' ) );
		}
		if ( ! is_email( $data['email'] ) ) {
			return new WP_Error( 'invalid_email', __( 'ایمیل درست نیست.', 'netarz-ai' ) );
		}

		$departments = Netarz_AI_Settings::departments();
		$now         = Netarz_AI_Util::now();

		$ok = $wpdb->insert( self::table(), array( // phpcs:ignore
			'access_key'    => Netarz_AI_Util::token( 16 ),
			'subject'       => Netarz_AI_Util::cut( sanitize_text_field( $data['subject'] ), 190 ),
			'department'    => in_array( $data['department'], $departments, true ) ? $data['department'] : $departments[0],
			'priority'      => array_key_exists( $data['priority'], self::priorities() ) ? $data['priority'] : 'normal',
			'status'        => 'open',
			'user_id'       => (int) $data['user_id'],
			'name'          => Netarz_AI_Util::cut( sanitize_text_field( $data['name'] ), 110 ),
			'email'         => sanitize_email( $data['email'] ),
			'phone'         => substr( sanitize_text_field( $data['phone'] ), 0, 30 ),
			'source'        => in_array( $data['source'], array( 'form', 'chat', 'admin', 'woo' ), true ) ? $data['source'] : 'form',
			'order_ref'     => substr( sanitize_text_field( $data['order_ref'] ), 0, 60 ),
			'last_reply_by' => 'customer',
			'last_reply_at' => $now,
			'created_at'    => $now,
		) );
		if ( ! $ok ) {
			return new WP_Error( 'db_error', __( 'تیکت ثبت نشد. دوباره امتحان کنید.', 'netarz-ai' ) );
		}
		$ticket_id = (int) $wpdb->insert_id;

		self::insert_reply( $ticket_id, 'customer', $data['body'], (int) $data['user_id'], $data['name'], $data['attachments'] );

		$ticket = self::find( $ticket_id );

		if ( $data['notify'] ) {
			self::mail_staff( $ticket, 'new', $data['body'] );
			self::mail_customer_created( $ticket );
		}

		Netarz_AI_Ticket_Agent::queue( $ticket_id );

		do_action( 'netarz_ai_ticket_created', $ticket );

		return $ticket_id;
	}

	/** Raw insert of one message. @return int reply id */
	private static function insert_reply( $ticket_id, $type, $body, $user_id, $name, array $attachments = array() ) {
		global $wpdb;
		$wpdb->insert( self::replies_table(), array( // phpcs:ignore
			'ticket_id'   => (int) $ticket_id,
			'author_type' => $type,
			'user_id'     => (int) $user_id,
			'author_name' => Netarz_AI_Util::cut( (string) $name, 110 ),
			'body'        => (string) $body,
			'attachments' => $attachments ? wp_json_encode( array_values( $attachments ) ) : null,
			'created_at'  => Netarz_AI_Util::now(),
		) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Add a message and move the ticket along.
	 *
	 * @param string $type customer | staff | ai | note | draft | system
	 * @return int reply id
	 */
	public static function add_reply( $ticket, $type, $body, $user_id = 0, $name = '', array $attachments = array() ) {
		$id  = self::insert_reply( $ticket->id, $type, $body, $user_id, $name, $attachments );
		$now = Netarz_AI_Util::now();

		switch ( $type ) {
			case 'customer':
				self::update( $ticket->id, array(
					'status'        => 'customer_reply',
					'last_reply_by' => 'customer',
					'last_reply_at' => $now,
					'closed_at'     => null,
					'ai_state'      => '',
				) );
				self::mail_staff( self::find( $ticket->id ), 'reply', $body );
				Netarz_AI_Ticket_Agent::queue( (int) $ticket->id );
				break;

			case 'staff':
			case 'ai':
				self::update( $ticket->id, array(
					'status'        => 'answered',
					'last_reply_by' => 'staff',
					'last_reply_at' => $now,
					'closed_at'     => null,
				) );
				self::mail_customer_reply( self::find( $ticket->id ), $body );
				break;
		}

		return $id;
	}

	public static function set_status( $ticket, $status, $by_customer = false ) {
		if ( ! array_key_exists( $status, self::statuses() ) || $status === $ticket->status ) {
			return;
		}
		$fields = array( 'status' => $status );
		if ( 'closed' === $status ) {
			$fields['closed_at'] = Netarz_AI_Util::now();
			self::insert_reply( $ticket->id, 'system', $by_customer ? __( 'مشتری تیکت را بست.', 'netarz-ai' ) : __( 'تیکت بسته شد.', 'netarz-ai' ), 0, '' );
		} elseif ( 'closed' === $ticket->status ) {
			$fields['closed_at'] = null;
			self::insert_reply( $ticket->id, 'system', __( 'تیکت دوباره باز شد.', 'netarz-ai' ), 0, '' );
		}
		self::update( $ticket->id, $fields );
	}

	/** Turn a live chat into a ticket and tell the customer where to follow it. */
	public static function create_from_chat( $chat, $email ) {
		$lines = array();
		foreach ( Netarz_AI_Chat::messages( $chat->id, 0, 500 ) as $row ) {
			if ( 'system' === $row->sender ) {
				continue;
			}
			$who     = 'visitor' === $row->sender ? ( '' !== $chat->name ? $chat->name : __( 'مشتری', 'netarz-ai' ) ) : ( '' !== $row->author ? $row->author : __( 'پشتیبانی', 'netarz-ai' ) );
			$lines[] = $who . ': ' . $row->body;
		}

		$first = '';
		foreach ( Netarz_AI_Chat::messages( $chat->id, 0, 50 ) as $row ) {
			if ( 'visitor' === $row->sender ) {
				$first = $row->body;
				break;
			}
		}

		$ticket_id = self::create( array(
			/* translators: %s: first words of the chat */
			'subject' => sprintf( __( 'گفت‌وگوی آنلاین: %s', 'netarz-ai' ), Netarz_AI_Util::cut( $first, 60 ) ),
			'body'    => __( 'این تیکت از گفت‌وگوی آنلاین سایت ساخته شده. متن گفت‌وگو:', 'netarz-ai' ) . "\n\n" . implode( "\n\n", $lines ),
			'user_id' => (int) $chat->user_id,
			'name'    => $chat->name,
			'email'   => $email,
			'phone'   => $chat->mobile,
			'source'  => 'chat',
		) );

		if ( is_wp_error( $ticket_id ) ) {
			return $ticket_id;
		}

		Netarz_AI_Chat::update( $chat->id, array(
			'ticket_id'   => $ticket_id,
			'email'       => '' !== $chat->email ? $chat->email : $email,
			'needs_human' => 0,
		) );
		/* translators: %d: ticket number */
		Netarz_AI_Chat::add_message( $chat->id, 'system', sprintf( __( 'تیکت شمارهٔ %d ساخته شد؛ لینک پیگیری به ایمیل رفت.', 'netarz-ai' ), $ticket_id ) );

		return $ticket_id;
	}

	/* ------------------------------------------------------------------ */
	/* Access                                                              */
	/* ------------------------------------------------------------------ */

	/** The page that hosts the shortcode, if any. */
	public static function page_url() {
		$page = (int) Netarz_AI_Settings::get( 'tickets_page_id' );
		if ( $page && 'publish' === get_post_status( $page ) ) {
			return get_permalink( $page );
		}
		// The configured page is gone: find (or make) one that carries the shortcode,
		// so links in emails always open a page that can show the ticket.
		$page = self::ensure_page( true );
		if ( $page && 'publish' === get_post_status( $page ) ) {
			return get_permalink( $page );
		}
		if ( function_exists( 'wc_get_account_endpoint_url' ) && self::woo_active() ) {
			return wc_get_account_endpoint_url( self::WOO_ENDPOINT );
		}
		return home_url( '/' );
	}

	/** Link to one ticket; guests get the secret key in it. */
	public static function view_url( $ticket, $with_key = true ) {
		$args = array( 'ticket' => (int) $ticket->id );
		if ( $with_key ) {
			$args['key'] = $ticket->access_key;
		}
		return add_query_arg( $args, self::page_url() );
	}

	public static function is_staff() {
		return current_user_can( Netarz_AI_Installer::CAP_SUPPORT );
	}

	/** May the current visitor see this ticket? (owner, key holder, or staff) */
	public static function can_view( $ticket, $key = '' ) {
		if ( ! $ticket ) {
			return false;
		}
		if ( is_user_logged_in() && (int) $ticket->user_id && (int) $ticket->user_id === get_current_user_id() ) {
			return true;
		}
		if ( '' !== (string) $key && hash_equals( (string) $ticket->access_key, (string) $key ) ) {
			return true;
		}
		return self::is_staff();
	}

	/* ------------------------------------------------------------------ */
	/* Attachments                                                         */
	/* ------------------------------------------------------------------ */

	/** Absolute path of the private upload folder, created on demand with deny rules. */
	public static function upload_dir() {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'netarz-ai-tickets';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore
		}
		return $dir;
	}

	/** @return string[] allowed extensions */
	public static function allowed_types() {
		$types = array_filter( array_map( 'trim', explode( ',', strtolower( (string) Netarz_AI_Settings::get( 'tickets_file_types' ) ) ) ) );
		// Never anything a server could execute, whatever the setting says.
		$blocked = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'shtml', 'cgi', 'pl', 'py', 'asp', 'aspx', 'jsp', 'sh', 'exe', 'bat', 'cmd', 'js', 'html', 'htm', 'svg', 'svgz', 'xml', 'htaccess' );
		return array_values( array_diff( array_map( 'sanitize_key', $types ), $blocked ) );
	}

	/**
	 * Validate and store uploaded files from $_FILES[$field].
	 *
	 * @return array|WP_Error list of {file, name, size, mime}
	 */
	public static function take_uploads( $field = 'attachments' ) {
		if ( ! Netarz_AI_Settings::get( 'tickets_attachments' ) || empty( $_FILES[ $field ] ) || ! is_array( $_FILES[ $field ]['name'] ) ) { // phpcs:ignore
			return array();
		}

		$files   = $_FILES[ $field ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$max     = (int) Netarz_AI_Settings::get( 'tickets_max_file_mb' ) * MB_IN_BYTES;
		$allowed = self::allowed_types();
		$out     = array();
		$count   = 0;

		foreach ( $files['name'] as $i => $name ) {
			if ( '' === (string) $name || UPLOAD_ERR_NO_FILE === (int) $files['error'][ $i ] ) {
				continue;
			}
			if ( ++$count > self::MAX_FILES ) {
				/* translators: %d: max files */
				return new WP_Error( 'too_many_files', sprintf( __( 'حداکثر %d فایل می‌توانید پیوست کنید.', 'netarz-ai' ), self::MAX_FILES ) );
			}
			if ( UPLOAD_ERR_OK !== (int) $files['error'][ $i ] || ! is_uploaded_file( $files['tmp_name'][ $i ] ) ) {
				return new WP_Error( 'upload_error', __( 'بارگذاری فایل ناموفق بود.', 'netarz-ai' ) );
			}
			$size = (int) $files['size'][ $i ];
			if ( $size <= 0 || $size > $max ) {
				/* translators: %d: size in MB */
				return new WP_Error( 'file_too_big', sprintf( __( 'حجم هر فایل باید کمتر از %d مگابایت باشد.', 'netarz-ai' ), (int) Netarz_AI_Settings::get( 'tickets_max_file_mb' ) ) );
			}

			$clean = sanitize_file_name( wp_basename( (string) $name ) );
			$check = wp_check_filetype_and_ext( $files['tmp_name'][ $i ], $clean );
			$ext   = $check['ext'] ? strtolower( $check['ext'] ) : '';
			if ( '' === $ext || ! in_array( $ext, $allowed, true ) ) {
				/* translators: %s: allowed extensions */
				return new WP_Error( 'file_type', sprintf( __( 'نوع فایل مجاز نیست. نوع‌های مجاز: %s', 'netarz-ai' ), implode( '، ', $allowed ) ) );
			}

			$dir    = self::upload_dir();
			$sub    = gmdate( 'Y/m' );
			wp_mkdir_p( $dir . '/' . $sub );
			$stored = $sub . '/' . Netarz_AI_Util::token( 16 ) . '.' . $ext;

			if ( ! @move_uploaded_file( $files['tmp_name'][ $i ], $dir . '/' . $stored ) ) { // phpcs:ignore
				return new WP_Error( 'upload_error', __( 'فایل ذخیره نشد.', 'netarz-ai' ) );
			}
			@chmod( $dir . '/' . $stored, 0644 ); // phpcs:ignore

			$out[] = array(
				'file' => $stored,
				'name' => Netarz_AI_Util::cut( $clean, 120 ),
				'size' => $size,
				'mime' => $check['type'] ? $check['type'] : 'application/octet-stream',
			);
		}

		return $out;
	}

	/** Remove stored files of a set of replies (used when a ticket is deleted). */
	public static function delete_files( array $replies ) {
		$dir = self::upload_dir();
		foreach ( $replies as $reply ) {
			foreach ( self::attachments( $reply ) as $file ) {
				$path = $dir . '/' . $file['file'];
				if ( self::safe_path( $path ) && is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
		}
	}

	/** @return array[] */
	public static function attachments( $reply ) {
		if ( empty( $reply->attachments ) ) {
			return array();
		}
		$list = json_decode( (string) $reply->attachments, true );
		return is_array( $list ) ? array_values( array_filter( $list, function ( $f ) {
			return is_array( $f ) && ! empty( $f['file'] );
		} ) ) : array();
	}

	private static function safe_path( $path ) {
		$base = realpath( self::upload_dir() );
		$real = realpath( $path );
		return $base && $real && 0 === strpos( $real, $base . DIRECTORY_SEPARATOR );
	}

	public static function download_url( $ticket, $reply_id, $index ) {
		return add_query_arg( array(
			'netarz_ai_file' => (int) $reply_id,
			'i'              => (int) $index,
			'key'            => $ticket->access_key,
		), home_url( '/' ) );
	}

	/** Stream an attachment to someone allowed to see its ticket. */
	public static function maybe_download() {
		if ( empty( $_GET['netarz_ai_file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$reply  = self::find_reply( absint( $_GET['netarz_ai_file'] ) ); // phpcs:ignore
		$ticket = $reply ? self::find( $reply->ticket_id ) : null;
		$key    = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore

		if ( ! $reply || ! self::can_view( $ticket, $key ) || ( in_array( $reply->author_type, array( 'note', 'draft' ), true ) && ! self::is_staff() ) ) {
			wp_die( esc_html__( 'این فایل در دسترس نیست.', 'netarz-ai' ), '', array( 'response' => 404 ) );
		}

		$files = self::attachments( $reply );
		$index = isset( $_GET['i'] ) ? absint( $_GET['i'] ) : 0; // phpcs:ignore
		if ( ! isset( $files[ $index ] ) ) {
			wp_die( esc_html__( 'این فایل در دسترس نیست.', 'netarz-ai' ), '', array( 'response' => 404 ) );
		}

		$path = self::upload_dir() . '/' . $files[ $index ]['file'];
		if ( ! self::safe_path( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'این فایل در دسترس نیست.', 'netarz-ai' ), '', array( 'response' => 404 ) );
		}

		$mime   = (string) $files[ $index ]['mime'];
		$inline = in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf' ), true );

		nocache_headers();
		header( 'Content-Type: ' . ( $inline ? $mime : 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'" );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . "; filename*=UTF-8''" . rawurlencode( $files[ $index ]['name'] ) );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		readfile( $path ); // phpcs:ignore
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Mail                                                                */
	/* ------------------------------------------------------------------ */

	private static function mail_staff( $ticket, $event, $body ) {
		if ( ! $ticket ) {
			return;
		}
		$link    = admin_url( 'admin.php?page=netarz-ai-tickets&ticket=' . (int) $ticket->id );
		$subject = 'new' === $event
			/* translators: 1: ticket id, 2: subject */
			? sprintf( __( 'تیکت تازه #%1$d: %2$s', 'netarz-ai' ), $ticket->id, $ticket->subject )
			/* translators: 1: ticket id, 2: subject */
			: sprintf( __( 'پاسخ مشتری در تیکت #%1$d: %2$s', 'netarz-ai' ), $ticket->id, $ticket->subject );

		$html  = '<p><strong>' . esc_html( $ticket->name ) . '</strong> (' . esc_html( $ticket->email ) . ') — ' . esc_html( $ticket->department ) . ' — ' . esc_html( self::label( self::priorities(), $ticket->priority ) ) . '</p>';
		$html .= '<blockquote style="margin:12px 0;padding:10px 14px;background:#f9fafb;border-right:3px solid #f5b301">' . nl2br( esc_html( Netarz_AI_Util::cut( $body, 1500 ) ) ) . '</blockquote>';
		$html .= '<p><a href="' . esc_url( $link ) . '">' . esc_html__( 'باز کردن تیکت در پیشخوان', 'netarz-ai' ) . '</a></p>';

		Netarz_AI_Util::mail( Netarz_AI_Settings::emails( 'tickets_notify_emails' ), $subject, $html );
	}

	private static function mail_customer_created( $ticket ) {
		/* translators: %d: ticket id */
		$subject = sprintf( __( 'تیکت شما ثبت شد (#%d)', 'netarz-ai' ), $ticket->id );
		// No customer-typed name in this mail: anyone can enter any address in the guest form.
		$html    = '<p>' . esc_html__( 'سلام، تیکت شما با موضوع زیر ثبت شد و همکاران ما به‌زودی جواب می‌دهند.', 'netarz-ai' ) . '</p>';
		$html   .= '<p><strong>' . esc_html( $ticket->subject ) . '</strong></p>';
		$html   .= '<p><a href="' . esc_url( self::view_url( $ticket ) ) . '">' . esc_html__( 'دیدن و پیگیری تیکت', 'netarz-ai' ) . '</a></p>';
		$html   .= '<p style="font-size:12px;color:#6b7280">' . esc_html__( 'این لینک مخصوص شماست؛ آن را برای دیگران نفرستید.', 'netarz-ai' ) . '</p>';

		Netarz_AI_Util::mail( $ticket->email, $subject, $html );
	}

	private static function mail_customer_reply( $ticket, $body ) {
		/* translators: 1: ticket id, 2: subject */
		$subject = sprintf( __( 'پاسخ تیکت #%1$d: %2$s', 'netarz-ai' ), $ticket->id, $ticket->subject );
		$html    = '<p>' . esc_html__( 'به تیکت شما پاسخ داده شد:', 'netarz-ai' ) . '</p>';
		$html   .= '<blockquote style="margin:12px 0;padding:10px 14px;background:#f9fafb;border-right:3px solid #f5b301">' . nl2br( esc_html( Netarz_AI_Util::cut( $body, 3000 ) ) ) . '</blockquote>';
		$html   .= '<p><a href="' . esc_url( self::view_url( $ticket ) ) . '">' . esc_html__( 'دیدن تیکت و ارسال پاسخ', 'netarz-ai' ) . '</a></p>';

		Netarz_AI_Util::mail( $ticket->email, $subject, $html );
	}

	/* ------------------------------------------------------------------ */
	/* Form handling (POST → redirect → GET)                               */
	/* ------------------------------------------------------------------ */

	public static function handle_post() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || empty( $_POST['netarz_ai_ticket_action'] ) ) { // phpcs:ignore
			return;
		}
		if ( ! Netarz_AI_Settings::get( 'tickets_enabled' ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['netarz_ai_ticket_action'] ) ); // phpcs:ignore
		$nonce  = isset( $_POST['_nzai_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_nzai_nonce'] ) ) : '';
		$back   = self::current_url();

		// Members: a real CSRF check. Guests: full-page caches serve stale nonces, and a guest
		// action is already guarded by the ticket's secret key, the honeypot and rate limits.
		if ( is_user_logged_in() && ! wp_verify_nonce( $nonce, 'netarz_ai_ticket_' . $action ) ) {
			self::redirect( $back, 'error', __( 'مهلت فرم تمام شده بود. صفحه را تازه کنید و دوباره بفرستید.', 'netarz-ai' ) );
		}

		switch ( $action ) {
			case 'new':
				self::post_new( $back );
				break;
			case 'reply':
				self::post_reply( $back );
				break;
			case 'close':
				self::post_close( $back );
				break;
			case 'rate':
				self::post_rate( $back );
				break;
			case 'lookup':
				self::post_lookup( $back );
				break;
		}
	}

	private static function field( $name ) {
		return isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : ''; // phpcs:ignore
	}

	private static function post_new( $back ) {
		$user = wp_get_current_user();
		if ( ! $user->exists() && ! Netarz_AI_Settings::get( 'tickets_guest' ) ) {
			self::redirect( $back, 'error', __( 'برای ثبت تیکت اول وارد حساب کاربری شوید.', 'netarz-ai' ) );
		}
		if ( '' !== trim( (string) self::field( 'website' ) ) ) {
			self::redirect( $back, 'error', __( 'فرم فرستاده نشد. صفحه را تازه کنید و دوباره بفرستید.', 'netarz-ai' ) );
		}
		if ( ! Netarz_AI_Util::rate_limit( 'ticket_new_' . Netarz_AI_Util::ip_hash(), 6, HOUR_IN_SECONDS ) ) {
			self::redirect( $back, 'error', __( 'در این یک ساعت تیکت‌های زیادی ثبت شده. کمی بعد دوباره امتحان کنید.', 'netarz-ai' ) );
		}

		$subject = trim( sanitize_text_field( self::field( 'subject' ) ) );
		$body    = Netarz_AI_Util::clean_text( self::field( 'body' ) );
		$name    = $user->exists() ? $user->display_name : trim( sanitize_text_field( self::field( 'name' ) ) );
		$email   = $user->exists() ? $user->user_email : sanitize_email( self::field( 'email' ) );
		$phone   = Netarz_AI_Util::mobile( self::field( 'phone' ) );

		self::remember_form( array(
			'subject'    => $subject,
			'body'       => $body,
			'name'       => $name,
			'email'      => $email,
			'phone'      => $phone,
			'department' => sanitize_text_field( self::field( 'department' ) ),
			'priority'   => sanitize_key( self::field( 'priority' ) ),
		) );

		if ( '' === $subject || '' === $body ) {
			self::redirect( $back, 'error', __( 'موضوع و متن تیکت را بنویسید.', 'netarz-ai' ) );
		}
		if ( ! $user->exists() && ( '' === $name || ! is_email( $email ) ) ) {
			self::redirect( $back, 'error', __( 'نام و یک ایمیل درست لازم است تا بتوانیم جواب بدهیم.', 'netarz-ai' ) );
		}
		if ( Netarz_AI_Util::length( $body ) > 10000 ) {
			self::redirect( $back, 'error', __( 'متن تیکت خیلی طولانی است. کوتاه‌ترش کنید یا توضیح بیشتر را در فایل پیوست بگذارید.', 'netarz-ai' ) );
		}

		$files = self::take_uploads();
		if ( is_wp_error( $files ) ) {
			self::redirect( $back, 'error', $files->get_error_message() );
		}

		$order_ref = '';
		$order_id  = absint( self::field( 'order_id' ) );
		if ( $order_id && $user->exists() && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order && (int) $order->get_customer_id() === (int) $user->ID ) {
				$order_ref = (string) $order->get_id();
			}
		}

		$priority = sanitize_key( self::field( 'priority' ) );
		if ( 'urgent' === $priority ) {
			$priority = 'high'; // Only staff mark a ticket urgent.
		}

		$ticket_id = self::create( array(
			'subject'     => $subject,
			'body'        => $body,
			'department'  => sanitize_text_field( self::field( 'department' ) ),
			'priority'    => $priority,
			'user_id'     => (int) $user->ID,
			'name'        => $name,
			'email'       => $email,
			'phone'       => $phone,
			'source'      => 'form',
			'order_ref'   => $order_ref,
			'attachments' => $files,
		) );

		if ( is_wp_error( $ticket_id ) ) {
			self::redirect( $back, 'error', $ticket_id->get_error_message() );
		}

		self::forget_form();
		$ticket = self::find( $ticket_id );
		$url    = add_query_arg( array(
			'ticket' => $ticket->id,
			'key'    => $user->exists() ? false : $ticket->access_key,
		), remove_query_arg( array( 'ticket', 'key', 'new', 'nzai_msg', 'nzai_type' ), $back ) );

		self::redirect( $url, 'success', $user->exists()
			? __( 'تیکت ثبت شد. پاسخ را همین‌جا و در ایمیلتان می‌بینید.', 'netarz-ai' )
			: __( 'تیکت ثبت شد. لینک پیگیری را به ایمیلتان هم فرستادیم؛ این صفحه را هم می‌توانید نشانه‌گذاری کنید.', 'netarz-ai' ) );
	}

	/** @return object ticket the current visitor may act on, or redirects */
	private static function posted_ticket( $back ) {
		$ticket = self::find( absint( self::field( 'ticket_id' ) ) );
		$key    = sanitize_text_field( self::field( 'key' ) );
		if ( ! $ticket || ! self::can_view( $ticket, $key ) ) {
			self::redirect( $back, 'error', __( 'این تیکت پیدا نشد.', 'netarz-ai' ) );
		}
		return $ticket;
	}

	private static function post_reply( $back ) {
		$ticket = self::posted_ticket( $back );
		$body   = Netarz_AI_Util::clean_text( self::field( 'body' ) );

		if ( '' === $body ) {
			self::redirect( $back, 'error', __( 'متن پاسخ را بنویسید.', 'netarz-ai' ) );
		}
		if ( Netarz_AI_Util::length( $body ) > 10000 ) {
			self::redirect( $back, 'error', __( 'متن پاسخ خیلی طولانی است. کوتاه‌ترش کنید یا در دو پاسخ بفرستید.', 'netarz-ai' ) );
		}
		if ( ! Netarz_AI_Util::rate_limit( 'ticket_reply_' . $ticket->id, 20, HOUR_IN_SECONDS ) ) {
			self::redirect( $back, 'error', __( 'پاسخ‌ها خیلی پشت سر هم فرستاده شد. کمی بعد دوباره امتحان کنید.', 'netarz-ai' ) );
		}

		$files = self::take_uploads();
		if ( is_wp_error( $files ) ) {
			self::remember_form( array( 'reply' => $body ) );
			self::redirect( $back, 'error', $files->get_error_message() );
		}

		$user = wp_get_current_user();
		$name = ( $user->exists() && (int) $user->ID === (int) $ticket->user_id ) ? $user->display_name : $ticket->name;

		if ( 'closed' === $ticket->status ) {
			self::set_status( $ticket, 'open', true );
			$ticket = self::find( $ticket->id );
		}
		self::add_reply( $ticket, 'customer', $body, (int) $user->ID, $name, $files );
		self::forget_form();

		self::redirect( $back, 'success', __( 'پاسخ شما ثبت شد.', 'netarz-ai' ) );
	}

	private static function post_close( $back ) {
		$ticket = self::posted_ticket( $back );
		self::set_status( $ticket, 'closed', true );
		self::redirect( $back, 'success', __( 'تیکت بسته شد. اگر باز هم سؤالی داشتید، با یک پاسخ تازه دوباره باز می‌شود.', 'netarz-ai' ) );
	}

	private static function post_rate( $back ) {
		$ticket = self::posted_ticket( $back );
		$rating = absint( self::field( 'rating' ) );
		if ( $rating < 1 || $rating > 5 ) {
			self::redirect( $back, 'error', __( 'یک امتیاز از ۱ تا ۵ انتخاب کنید.', 'netarz-ai' ) );
		}
		self::update( $ticket->id, array(
			'rating'      => $rating,
			'rating_note' => Netarz_AI_Util::cut( sanitize_textarea_field( self::field( 'rating_note' ) ), 480 ),
		) );
		self::redirect( $back, 'success', __( 'ممنون از نظرتان.', 'netarz-ai' ) );
	}

	/** Resend the tracking link. Always answers the same, so it reveals nothing. */
	private static function post_lookup( $back ) {
		$id    = absint( Netarz_AI_Util::latin_digits( self::field( 'ticket_no' ) ) );
		$email = sanitize_email( self::field( 'email' ) );

		if ( Netarz_AI_Util::rate_limit( 'ticket_lookup_' . Netarz_AI_Util::ip_hash(), 5, HOUR_IN_SECONDS ) ) {
			$ticket = $id ? self::find( $id ) : null;
			if ( $ticket && is_email( $email ) && strtolower( $ticket->email ) === strtolower( $email ) ) {
				self::mail_customer_created( $ticket );
			}
		}
		self::redirect( $back, 'success', __( 'اگر شماره و ایمیل با هم جور باشند، لینک پیگیری همین الان به ایمیلتان رفت.', 'netarz-ai' ) );
	}

	/*
	 * PRG helpers. The message — and, after a failed submit, the form values —
	 * ride in a short-lived transient keyed by a random token that only this
	 * visitor's redirect URL carries, so nobody else (not even someone on the
	 * same IP) is ever shown them.
	 */

	/** @var array values to carry into the next render after a failed submit */
	private static $keep = array();

	private static function redirect( $url, $type, $message ) {
		$token = Netarz_AI_Util::token( 8 );
		set_transient( 'netarz_ai_flash_' . $token, array(
			'type'    => $type,
			'message' => $message,
			'form'    => 'error' === $type ? self::$keep : array(),
		), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'nzai_msg', $token, remove_query_arg( 'nzai_msg', $url ) ) );
		exit;
	}

	private static function flash() {
		if ( self::$flash ) {
			return self::$flash;
		}
		$token = isset( $_GET['nzai_msg'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_GET['nzai_msg'] ) ) : ''; // phpcs:ignore
		if ( '' === $token ) {
			return array();
		}
		$flash = get_transient( 'netarz_ai_flash_' . $token );
		self::$flash = is_array( $flash ) ? $flash : array();
		return self::$flash;
	}

	private static function remember_form( array $values ) {
		self::$keep = array_merge( self::$keep, $values );
	}

	private static function forget_form() {
		self::$keep = array();
	}

	private static function old( $key ) {
		$flash = self::flash();
		return isset( $flash['form'][ $key ] ) ? (string) $flash['form'][ $key ] : '';
	}

	/** Where a form came from — the page to return to after handling it. */
	private static function current_url() {
		$ref = wp_get_referer();
		return $ref ? $ref : self::here();
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	public static function register_assets() {
		wp_register_style( 'netarz-ai-tickets', NETARZ_AI_URL . 'assets/css/tickets.css', array(), NETARZ_AI_VERSION );
	}

	public static function shortcode() {
		if ( ! Netarz_AI_Settings::get( 'tickets_enabled' ) ) {
			return '';
		}
		wp_enqueue_style( 'netarz-ai-tickets' );

		ob_start();
		echo '<div class="nzai-tk" dir="rtl">';
		self::render_flash();

		$id  = isset( $_GET['ticket'] ) ? absint( $_GET['ticket'] ) : 0; // phpcs:ignore
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore

		if ( $id ) {
			$ticket = self::find( $id );
			if ( self::can_view( $ticket, $key ) ) {
				self::render_ticket( $ticket, $key );
			} else {
				echo '<p class="nzai-tk-alert is-error">' . esc_html__( 'این تیکت پیدا نشد یا لینک آن کامل نیست. اگر مهمان هستید، از لینکی که به ایمیلتان رفت استفاده کنید.', 'netarz-ai' ) . '</p>';
				self::render_lookup();
			}
		} elseif ( isset( $_GET['new'] ) || ! is_user_logged_in() ) { // phpcs:ignore
			if ( is_user_logged_in() || Netarz_AI_Settings::get( 'tickets_guest' ) ) {
				self::render_new();
			} else {
				echo '<p class="nzai-tk-alert">' . esc_html__( 'برای ثبت تیکت اول وارد حساب کاربری شوید.', 'netarz-ai' ) . ' <a href="' . esc_url( wp_login_url( self::current_url() ) ) . '">' . esc_html__( 'ورود', 'netarz-ai' ) . '</a></p>';
			}
			if ( ! is_user_logged_in() ) {
				self::render_lookup();
			}
		} else {
			self::render_list();
		}

		echo '</div>';
		return ob_get_clean();
	}

	private static function render_flash() {
		$flash = self::flash();
		if ( ! empty( $flash['message'] ) ) {
			printf( '<p class="nzai-tk-alert is-%s" role="alert">%s</p>', 'error' === $flash['type'] ? 'error' : 'success', esc_html( $flash['message'] ) );
		}
	}

	/** Base URL of the page currently rendering (keeps WooCommerce endpoint URLs intact). */
	private static function base_url() {
		return remove_query_arg( array( 'ticket', 'key', 'new', 'nzai_msg' ), self::here() );
	}

	/** The URL of this request (REQUEST_URI already carries any subdirectory). */
	private static function here() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore
		$home = wp_parse_url( home_url( '/' ) );
		$base = ( is_ssl() ? 'https' : 'http' ) . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		return esc_url_raw( $base . '/' . ltrim( (string) $uri, '/' ) );
	}

	private static function render_list() {
		global $wpdb;
		$user    = wp_get_current_user();
		$tickets = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY last_reply_at DESC LIMIT 100',
			(int) $user->ID
		) );

		echo '<div class="nzai-tk-head"><h3>' . esc_html__( 'تیکت‌های پشتیبانی', 'netarz-ai' ) . '</h3>';
		echo '<a class="nzai-tk-btn" href="' . esc_url( add_query_arg( 'new', 1, self::base_url() ) ) . '">' . esc_html__( 'تیکت تازه', 'netarz-ai' ) . '</a></div>';

		if ( ! $tickets ) {
			echo '<div class="nzai-tk-empty"><p>' . esc_html__( 'هنوز تیکتی ثبت نکرده‌اید. هر سؤال یا مشکلی داشتید، یک تیکت بفرستید تا همکاران ما پیگیری کنند.', 'netarz-ai' ) . '</p></div>';
			return;
		}

		echo '<ul class="nzai-tk-list">';
		foreach ( $tickets as $ticket ) {
			$url     = add_query_arg( 'ticket', (int) $ticket->id, self::base_url() );
			$waiting = 'answered' === $ticket->status;
			echo '<li><a href="' . esc_url( $url ) . '" class="' . ( $waiting ? 'is-new' : '' ) . '">';
			echo '<span class="nzai-tk-no">#' . (int) $ticket->id . '</span>';
			echo '<span class="nzai-tk-subject">' . esc_html( $ticket->subject ) . '</span>';
			echo '<span class="nzai-tk-status is-' . esc_attr( $ticket->status ) . '">' . esc_html( self::customer_status( $ticket->status ) ) . '</span>';
			echo '<span class="nzai-tk-date">' . esc_html( Netarz_AI_Util::ago( $ticket->last_reply_at ) ) . '</span>';
			echo '</a></li>';
		}
		echo '</ul>';
	}

	private static function render_new() {
		$user  = wp_get_current_user();
		$depts = Netarz_AI_Settings::departments();
		$prios = self::priorities();
		unset( $prios['urgent'] );

		if ( $user->exists() ) {
			echo '<p class="nzai-tk-back"><a href="' . esc_url( self::base_url() ) . '">' . esc_html__( '→ همهٔ تیکت‌ها', 'netarz-ai' ) . '</a></p>';
		}
		echo '<h3>' . esc_html__( 'ثبت تیکت پشتیبانی', 'netarz-ai' ) . '</h3>';
		echo '<form method="post" enctype="multipart/form-data" class="nzai-tk-form">';
		wp_nonce_field( 'netarz_ai_ticket_new', '_nzai_nonce' );
		echo '<input type="hidden" name="netarz_ai_ticket_action" value="new">';
		echo '<input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="nzai-tk-hp" aria-hidden="true">';

		if ( ! $user->exists() ) {
			echo '<div class="nzai-tk-row">';
			self::input( 'name', __( 'نام و نام خانوادگی', 'netarz-ai' ), 'text', self::old( 'name' ), true, 'name' );
			self::input( 'email', __( 'ایمیل', 'netarz-ai' ), 'email', self::old( 'email' ), true, 'email', true );
			echo '</div>';
			self::input( 'phone', __( 'شمارهٔ موبایل (اختیاری)', 'netarz-ai' ), 'tel', self::old( 'phone' ), false, 'tel', true );
		}

		echo '<div class="nzai-tk-row">';
		echo '<p class="nzai-tk-field"><label for="nzai-dept">' . esc_html__( 'بخش', 'netarz-ai' ) . '</label><select id="nzai-dept" name="department">';
		foreach ( $depts as $dept ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $dept ), selected( self::old( 'department' ), $dept, false ) );
		}
		echo '</select></p>';
		echo '<p class="nzai-tk-field"><label for="nzai-prio">' . esc_html__( 'اولویت', 'netarz-ai' ) . '</label><select id="nzai-prio" name="priority">';
		$old_prio = self::old( 'priority' ) ? self::old( 'priority' ) : 'normal';
		foreach ( $prios as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $old_prio, $key, false ), esc_html( $label ) );
		}
		echo '</select></p></div>';

		// WooCommerce: let members point at the order this is about.
		if ( $user->exists() && function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders( array(
				'customer_id' => $user->ID,
				'limit'       => 20,
				'orderby'     => 'date',
				'order'       => 'DESC',
			) );
			if ( $orders ) {
				echo '<p class="nzai-tk-field"><label for="nzai-order">' . esc_html__( 'سفارش مرتبط (اختیاری)', 'netarz-ai' ) . '</label><select id="nzai-order" name="order_id"><option value="">—</option>';
				foreach ( $orders as $order ) {
					printf(
						'<option value="%d">%s</option>',
						(int) $order->get_id(),
						/* translators: 1: order number, 2: date */
						esc_html( sprintf( __( 'سفارش %1$s — %2$s', 'netarz-ai' ), $order->get_order_number(), $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '' ) )
					);
				}
				echo '</select></p>';
			}
		}

		self::input( 'subject', __( 'موضوع', 'netarz-ai' ), 'text', self::old( 'subject' ), true );
		echo '<p class="nzai-tk-field"><label for="nzai-body">' . esc_html__( 'شرح درخواست', 'netarz-ai' ) . ' <span class="req">*</span></label>';
		echo '<textarea id="nzai-body" name="body" rows="7" required maxlength="10000">' . esc_textarea( self::old( 'body' ) ) . '</textarea></p>';
		self::file_input();
		echo '<p><button type="submit" class="nzai-tk-btn">' . esc_html__( 'ثبت تیکت', 'netarz-ai' ) . '</button></p>';
		echo '</form>';
	}

	private static function render_lookup() {
		echo '<details class="nzai-tk-lookup"><summary>' . esc_html__( 'قبلاً تیکت ثبت کرده‌اید؟ لینک پیگیری را دوباره بگیرید', 'netarz-ai' ) . '</summary>';
		echo '<form method="post" class="nzai-tk-form">';
		wp_nonce_field( 'netarz_ai_ticket_lookup', '_nzai_nonce' );
		echo '<input type="hidden" name="netarz_ai_ticket_action" value="lookup"><div class="nzai-tk-row">';
		self::input( 'ticket_no', __( 'شمارهٔ تیکت', 'netarz-ai' ), 'text', '', true, 'off', true );
		self::input( 'email', __( 'ایمیلی که با آن ثبت کردید', 'netarz-ai' ), 'email', '', true, 'email', true );
		echo '</div><p><button type="submit" class="nzai-tk-btn is-ghost">' . esc_html__( 'ارسال لینک به ایمیل', 'netarz-ai' ) . '</button></p></form></details>';
	}

	private static function render_ticket( $ticket, $key ) {
		$is_owner = is_user_logged_in() && (int) $ticket->user_id === get_current_user_id();
		$key      = $is_owner ? '' : $ticket->access_key;
		$agent    = (string) Netarz_AI_Settings::get( 'chat_agent_name' );

		if ( is_user_logged_in() ) {
			echo '<p class="nzai-tk-back"><a href="' . esc_url( self::base_url() ) . '">' . esc_html__( '→ همهٔ تیکت‌ها', 'netarz-ai' ) . '</a></p>';
		}

		echo '<div class="nzai-tk-ticket-head">';
		echo '<h3>' . esc_html( $ticket->subject ) . '</h3>';
		echo '<p class="nzai-tk-meta"><span>#' . (int) $ticket->id . '</span><span>' . esc_html( $ticket->department ) . '</span>';
		echo '<span class="nzai-tk-status is-' . esc_attr( $ticket->status ) . '">' . esc_html( self::customer_status( $ticket->status ) ) . '</span>';
		if ( '' !== $ticket->order_ref ) {
			/* translators: %s: order number */
			echo '<span>' . esc_html( sprintf( __( 'سفارش %s', 'netarz-ai' ), $ticket->order_ref ) ) . '</span>';
		}
		echo '</p></div>';

		echo '<div class="nzai-tk-thread">';
		foreach ( self::replies( $ticket->id ) as $reply ) {
			if ( 'system' === $reply->author_type ) {
				echo '<p class="nzai-tk-sys">' . esc_html( $reply->body ) . ' <small>' . esc_html( Netarz_AI_Util::date( $reply->created_at ) ) . '</small></p>';
				continue;
			}
			$mine = 'customer' === $reply->author_type;
			$name = $mine ? ( '' !== $reply->author_name ? $reply->author_name : __( 'شما', 'netarz-ai' ) ) : ( 'ai' === $reply->author_type ? $agent : $reply->author_name );
			echo '<article class="nzai-tk-msg ' . ( $mine ? 'is-customer' : 'is-staff' ) . '">';
			echo '<header><strong>' . esc_html( $name ) . '</strong>';
			if ( ! $mine ) {
				echo '<span class="nzai-tk-badge">' . esc_html__( 'پشتیبانی', 'netarz-ai' ) . '</span>';
			}
			echo '<time>' . esc_html( Netarz_AI_Util::date( $reply->created_at ) ) . '</time></header>';
			echo '<div class="nzai-tk-body">' . wp_kses_post( wpautop( make_clickable( esc_html( $reply->body ) ) ) ) . '</div>';
			self::render_files( $ticket, $reply );
			echo '</article>';
		}
		echo '</div>';

		// Reply form (also reopens a closed ticket).
		echo '<form method="post" enctype="multipart/form-data" class="nzai-tk-form nzai-tk-reply">';
		wp_nonce_field( 'netarz_ai_ticket_reply', '_nzai_nonce' );
		echo '<input type="hidden" name="netarz_ai_ticket_action" value="reply">';
		echo '<input type="hidden" name="ticket_id" value="' . (int) $ticket->id . '">';
		echo '<input type="hidden" name="key" value="' . esc_attr( $key ) . '">';
		echo '<p class="nzai-tk-field"><label for="nzai-reply">' . ( 'closed' === $ticket->status ? esc_html__( 'این تیکت بسته شده؛ با فرستادن پاسخ دوباره باز می‌شود.', 'netarz-ai' ) : esc_html__( 'پاسخ شما', 'netarz-ai' ) ) . '</label>';
		echo '<textarea id="nzai-reply" name="body" rows="5" required maxlength="10000">' . esc_textarea( self::old( 'reply' ) ) . '</textarea></p>';
		self::file_input();
		echo '<p class="nzai-tk-actions"><button type="submit" class="nzai-tk-btn">' . esc_html__( 'ارسال پاسخ', 'netarz-ai' ) . '</button></p>';
		echo '</form>';

		if ( 'closed' !== $ticket->status ) {
			echo '<form method="post" class="nzai-tk-inline">';
			wp_nonce_field( 'netarz_ai_ticket_close', '_nzai_nonce' );
			echo '<input type="hidden" name="netarz_ai_ticket_action" value="close"><input type="hidden" name="ticket_id" value="' . (int) $ticket->id . '"><input type="hidden" name="key" value="' . esc_attr( $key ) . '">';
			echo '<button type="submit" class="nzai-tk-link">' . esc_html__( 'مشکلم حل شد؛ تیکت را ببند', 'netarz-ai' ) . '</button></form>';
		} elseif ( ! (int) $ticket->rating ) {
			echo '<form method="post" class="nzai-tk-form nzai-tk-rate">';
			wp_nonce_field( 'netarz_ai_ticket_rate', '_nzai_nonce' );
			echo '<input type="hidden" name="netarz_ai_ticket_action" value="rate"><input type="hidden" name="ticket_id" value="' . (int) $ticket->id . '"><input type="hidden" name="key" value="' . esc_attr( $key ) . '">';
			echo '<p><strong>' . esc_html__( 'از پشتیبانی راضی بودید؟', 'netarz-ai' ) . '</strong></p><p class="nzai-tk-stars">';
			for ( $i = 5; $i >= 1; $i-- ) {
				printf( '<input type="radio" id="nzai-star-%1$d" name="rating" value="%1$d" required><label for="nzai-star-%1$d" title="%1$d">★</label>', (int) $i );
			}
			echo '</p><p class="nzai-tk-field"><textarea name="rating_note" rows="2" maxlength="480" placeholder="' . esc_attr__( 'اگر نکته‌ای دارید بنویسید (اختیاری)', 'netarz-ai' ) . '"></textarea></p>';
			echo '<p><button type="submit" class="nzai-tk-btn is-ghost">' . esc_html__( 'ثبت امتیاز', 'netarz-ai' ) . '</button></p></form>';
		}
	}

	public static function render_files( $ticket, $reply ) {
		$files = self::attachments( $reply );
		if ( ! $files ) {
			return;
		}
		echo '<ul class="nzai-tk-files">';
		foreach ( $files as $i => $file ) {
			printf(
				'<li><a href="%s" target="_blank" rel="noopener">%s</a> <small>%s</small></li>',
				esc_url( self::download_url( $ticket, $reply->id, $i ) ),
				esc_html( $file['name'] ),
				esc_html( size_format( (int) $file['size'] ) )
			);
		}
		echo '</ul>';
	}

	private static function input( $name, $label, $type, $value, $required, $autocomplete = '', $ltr = false ) {
		printf(
			'<p class="nzai-tk-field"><label for="nzai-%1$s">%2$s%3$s</label><input id="nzai-%1$s" name="%1$s" type="%4$s" value="%5$s"%6$s%7$s%8$s></p>',
			esc_attr( $name ),
			esc_html( $label ),
			$required ? ' <span class="req">*</span>' : '',
			esc_attr( $type ),
			esc_attr( $value ),
			$required ? ' required' : '',
			$autocomplete ? ' autocomplete="' . esc_attr( $autocomplete ) . '"' : '',
			$ltr ? ' dir="ltr"' : ''
		);
	}

	private static function file_input() {
		if ( ! Netarz_AI_Settings::get( 'tickets_attachments' ) ) {
			return;
		}
		$types = self::allowed_types();
		echo '<p class="nzai-tk-field"><label for="nzai-files">' . esc_html__( 'پیوست (اختیاری)', 'netarz-ai' ) . '</label>';
		echo '<input id="nzai-files" type="file" name="attachments[]" multiple accept="' . esc_attr( '.' . implode( ',.', $types ) ) . '">';
		echo '<small>' . esc_html( sprintf(
			/* translators: 1: count, 2: MB, 3: types */
			__( 'تا %1$d فایل، هرکدام حداکثر %2$d مگابایت (%3$s)', 'netarz-ai' ),
			self::MAX_FILES,
			(int) Netarz_AI_Settings::get( 'tickets_max_file_mb' ),
			implode( '، ', $types )
		) ) . '</small></p>';
	}

	/* ------------------------------------------------------------------ */
	/* WooCommerce account tab                                             */
	/* ------------------------------------------------------------------ */

	public static function woo_active() {
		return class_exists( 'WooCommerce' ) && Netarz_AI_Settings::get( 'tickets_enabled' ) && Netarz_AI_Settings::get( 'tickets_woo_account' );
	}

	public static function woo_endpoint() {
		if ( self::woo_active() ) {
			add_rewrite_endpoint( self::WOO_ENDPOINT, EP_ROOT | EP_PAGES );
		}
	}

	/** Rebuild rewrite rules once, after every plugin has registered its own on init. */
	public static function maybe_flush() {
		if ( get_option( 'netarz_ai_flush_rewrite' ) ) {
			delete_option( 'netarz_ai_flush_rewrite' );
			flush_rewrite_rules( false );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = self::WOO_ENDPOINT;
		return $vars;
	}

	public static function woo_menu( $items ) {
		if ( ! self::woo_active() ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$out[ self::WOO_ENDPOINT ] = __( 'پشتیبانی', 'netarz-ai' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out[ self::WOO_ENDPOINT ] ) ) {
			$out[ self::WOO_ENDPOINT ] = __( 'پشتیبانی', 'netarz-ai' );
		}
		return $out;
	}

	public static function woo_content() {
		echo self::shortcode(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while rendering.
	}

	/* ------------------------------------------------------------------ */
	/* Housekeeping                                                        */
	/* ------------------------------------------------------------------ */

	/** Close tickets we answered that the customer left alone for N days. */
	public static function auto_close() {
		global $wpdb;
		$days = (int) Netarz_AI_Settings::get( 'tickets_auto_close' );
		if ( $days < 1 ) {
			return;
		}
		$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore
			"SELECT id FROM " . self::table() . " WHERE status = 'answered' AND last_reply_at < %s LIMIT 200",
			gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
		) );
		foreach ( $ids as $id ) {
			$ticket = self::find( $id );
			if ( $ticket ) {
				self::set_status( $ticket, 'closed' );
			}
		}
	}

	/**
	 * Make sure a page with the shortcode exists and is the configured one.
	 *
	 * Runs once on first activation, and again ($force) when a ticket link is
	 * needed but the configured page was deleted.
	 *
	 * @return int page id (0 when nothing could be done)
	 */
	public static function ensure_page( $force = false ) {
		if ( ! $force && get_option( 'netarz_ai_page_created' ) ) {
			return (int) Netarz_AI_Settings::get( 'tickets_page_id' );
		}
		update_option( 'netarz_ai_page_created', 1, false );

		$current = (int) Netarz_AI_Settings::get( 'tickets_page_id' );
		if ( $current && 'publish' === get_post_status( $current ) ) {
			return $current;
		}

		// A reinstall must not create a second support page next to the old one.
		global $wpdb;
		$page_id = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1",
			'%' . $wpdb->esc_like( '[netarz_ai_tickets' ) . '%'
		) );

		if ( ! $page_id ) {
			$page_id = wp_insert_post( array(
				'post_title'   => __( 'پشتیبانی و تیکت', 'netarz-ai' ),
				'post_name'    => 'support',
				'post_content' => '<!-- wp:shortcode -->[netarz_ai_tickets]<!-- /wp:shortcode -->',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			) );
		}
		if ( ! $page_id || is_wp_error( $page_id ) ) {
			return 0;
		}
		$all                    = Netarz_AI_Settings::all();
		$all['tickets_page_id'] = (int) $page_id;
		update_option( Netarz_AI_Settings::OPTION, $all, true );
		Netarz_AI_Settings::flush();
		return (int) $page_id;
	}
}
