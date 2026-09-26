<?php
/**
 * Content tools for editors: writing, rewriting, SEO, product copy,
 * images, alt text and comment replies.
 *
 * Every tool is one REST route; the editor meta box, the Writer page, the
 * media modal and the comments screen all call the same routes.
 *
 * @package NetArz_AI
 */

defined( 'ABSPATH' ) || exit;

class Netarz_AI_Writer {

	const META_DESCRIPTION = '_netarz_ai_meta_description';

	const META_TITLE = '_netarz_ai_meta_title';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'attachment_field' ), 10, 2 );
		add_filter( 'comment_row_actions', array( __CLASS__, 'comment_action' ), 10, 2 );
		add_action( 'wp_head', array( __CLASS__, 'print_meta' ), 1 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ), 20 );
	}

	public static function enabled() {
		return (bool) Netarz_AI_Settings::get( 'writer_enabled' );
	}

	public static function can_write() {
		return current_user_can( Netarz_AI_Installer::CAP_WRITER );
	}

	/* ------------------------------------------------------------------ */
	/* Tasks                                                               */
	/* ------------------------------------------------------------------ */

	/** @return array<string, array{label:string, format:string, tokens:int}> */
	public static function tasks() {
		return array(
			'article'   => array( 'label' => __( 'نوشتن مقالهٔ کامل', 'netarz-ai' ), 'format' => 'html', 'tokens' => 4000 ),
			'outline'   => array( 'label' => __( 'ساختار و سرفصل مقاله', 'netarz-ai' ), 'format' => 'html', 'tokens' => 1200 ),
			'rewrite'   => array( 'label' => __( 'بازنویسی و روان‌تر کردن', 'netarz-ai' ), 'format' => 'html', 'tokens' => 4000 ),
			'expand'    => array( 'label' => __( 'گسترش متن', 'netarz-ai' ), 'format' => 'html', 'tokens' => 4000 ),
			'shorten'   => array( 'label' => __( 'کوتاه‌تر کردن', 'netarz-ai' ), 'format' => 'html', 'tokens' => 2500 ),
			'proofread' => array( 'label' => __( 'ویرایش املایی و نگارشی', 'netarz-ai' ), 'format' => 'html', 'tokens' => 4000 ),
			'translate' => array( 'label' => __( 'ترجمه', 'netarz-ai' ), 'format' => 'html', 'tokens' => 4000 ),
			'summary'   => array( 'label' => __( 'خلاصه (برای چکیده)', 'netarz-ai' ), 'format' => 'text', 'tokens' => 500 ),
			'titles'    => array( 'label' => __( 'پیشنهاد عنوان', 'netarz-ai' ), 'format' => 'list', 'tokens' => 600 ),
			'seo'       => array( 'label' => __( 'عنوان و توضیحات سئو', 'netarz-ai' ), 'format' => 'json', 'tokens' => 600 ),
			'tags'      => array( 'label' => __( 'پیشنهاد برچسب', 'netarz-ai' ), 'format' => 'list', 'tokens' => 300 ),
			'faq'       => array( 'label' => __( 'پرسش‌های متداول', 'netarz-ai' ), 'format' => 'html', 'tokens' => 2000 ),
			'product'   => array( 'label' => __( 'توضیحات محصول', 'netarz-ai' ), 'format' => 'json', 'tokens' => 2500 ),
			'social'    => array( 'label' => __( 'متن شبکه‌های اجتماعی', 'netarz-ai' ), 'format' => 'text', 'tokens' => 800 ),
			'image_prompt' => array( 'label' => __( 'پیشنهاد پرامپت تصویر شاخص', 'netarz-ai' ), 'format' => 'text', 'tokens' => 300 ),
		);
	}

	public static function tones() {
		return array(
			'friendly'   => __( 'صمیمی و گرم', 'netarz-ai' ),
			'formal'     => __( 'رسمی', 'netarz-ai' ),
			'expert'     => __( 'تخصصی و دقیق', 'netarz-ai' ),
			'persuasive' => __( 'ترغیب‌کننده (فروش)', 'netarz-ai' ),
			'simple'     => __( 'ساده و قابل‌فهم برای همه', 'netarz-ai' ),
		);
	}

	public static function lengths() {
		return array(
			'short'  => array( __( 'کوتاه (حدود ۴۰۰ کلمه)', 'netarz-ai' ), 400 ),
			'medium' => array( __( 'متوسط (حدود ۸۰۰ کلمه)', 'netarz-ai' ), 800 ),
			'long'   => array( __( 'بلند (حدود ۱۵۰۰ کلمه)', 'netarz-ai' ), 1500 ),
		);
	}

	/**
	 * Run one writing task.
	 *
	 * @param array $p task, input, title, content, tone, language, length, keywords, target, post_id
	 * @return array|WP_Error { format, html?, text?, items?, data? }
	 */
	public static function run( array $p ) {
		$tasks = self::tasks();
		$task  = isset( $p['task'] ) ? (string) $p['task'] : '';
		if ( ! isset( $tasks[ $task ] ) ) {
			return new WP_Error( 'invalid_task', __( 'این کار شناخته نشد.', 'netarz-ai' ), array( 'status' => 400 ) );
		}

		$tone     = isset( $p['tone'] ) && array_key_exists( $p['tone'], self::tones() ) ? $p['tone'] : (string) Netarz_AI_Settings::get( 'writer_tone' );
		$language = isset( $p['language'] ) && '' !== trim( (string) $p['language'] ) ? sanitize_text_field( $p['language'] ) : (string) Netarz_AI_Settings::get( 'writer_language' );
		$lengths  = self::lengths();
		$length   = isset( $p['length'], $lengths[ $p['length'] ] ) ? $lengths[ $p['length'] ][1] : 800;
		$keywords = isset( $p['keywords'] ) ? sanitize_text_field( $p['keywords'] ) : '';
		$input    = isset( $p['input'] ) ? trim( (string) $p['input'] ) : '';
		$title    = isset( $p['title'] ) ? trim( sanitize_text_field( $p['title'] ) ) : '';
		$content  = isset( $p['content'] ) ? (string) $p['content'] : '';
		$target   = isset( $p['target'] ) && '' !== trim( (string) $p['target'] ) ? sanitize_text_field( $p['target'] ) : 'English';

		$plain = Netarz_AI_Knowledge::plain( $content );
		// Long posts: keep the prompt (and the bill) bounded.
		$plain_cut = Netarz_AI_Util::cut( $plain, 12000 );
		$html_cut  = Netarz_AI_Util::cut( self::lean_html( $content ), 16000 );

		$tone_label = self::label( self::tones(), $tone );
		$site       = get_bloginfo( 'name' );
		$about      = trim( (string) Netarz_AI_Settings::get( 'site_description' ) );

		$system = "تو نویسنده و ویراستار حرفه‌ای محتوای وب برای سایت «{$site}» هستی."
			. ( '' !== $about ? " دربارهٔ این کسب‌وکار: {$about}" : '' )
			. "\nزبان خروجی: {$language}. لحن: {$tone_label}."
			. "\nقاعده‌ها: ادعای ساختگی (آمار، قیمت، گارانتی، «بهترین») ننویس؛ جمله‌ها روشن و کوتاه باشد؛ پرکنندهٔ کلیشه‌ای مثل «در دنیای امروز» یا «در این مقاله قصد داریم» ننویس.";

		$html_rule = "\nخروجی را فقط به صورت HTML تمیز بنویس و فقط از این تگ‌ها استفاده کن: h2, h3, p, ul, ol, li, strong, em, blockquote, a, table, thead, tbody, tr, th, td. بدون html/head/body، بدون h1، بدون ``` و بدون توضیح اضافه.";

		$source = '' !== $input ? $input : ( '' !== $plain_cut ? $plain_cut : $title );

		switch ( $task ) {
			case 'article':
				$topic = '' !== $input ? $input : $title;
				if ( '' === $topic ) {
					return self::need( __( 'موضوع یا عنوان مقاله را بنویسید.', 'netarz-ai' ) );
				}
				$user = "یک مقالهٔ کامل حدود {$length} کلمه دربارهٔ این موضوع بنویس: {$topic}"
					. ( '' !== $keywords ? "\nکلیدواژه‌ها که باید طبیعی در متن بیایند: {$keywords}" : '' )
					. "\nمقدمهٔ کوتاه، چند بخش با h2 و در صورت نیاز h3، فهرست جایی که کمک می‌کند، و جمع‌بندی کاربردی." . $html_rule;
				break;

			case 'outline':
				$topic = '' !== $input ? $input : $title;
				if ( '' === $topic ) {
					return self::need( __( 'موضوع یا عنوان را بنویسید.', 'netarz-ai' ) );
				}
				$user = "برای مقاله‌ای دربارهٔ «{$topic}» یک ساختار سرفصل پیشنهاد کن: h2 و h3 ها، و زیر هر کدام یک خط توضیح در p یا li."
					. ( '' !== $keywords ? "\nکلیدواژه‌ها: {$keywords}" : '' ) . $html_rule;
				break;

			case 'rewrite':
			case 'expand':
			case 'shorten':
			case 'proofread':
			case 'translate':
				$text = '' !== $input ? $input : $html_cut;
				if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
					return self::need( __( 'متنی برای کار وجود ندارد. متن را وارد کنید یا اول محتوای نوشته را بنویسید.', 'netarz-ai' ) );
				}
				$how = array(
					'rewrite'   => 'این متن را بازنویسی کن تا روان‌تر، دقیق‌تر و خواندنی‌تر شود؛ معنا و اطلاعات را عوض نکن.',
					'expand'    => 'این متن را با توضیح، مثال و جزئیات مفید گسترش بده؛ چیزی خلاف متن اصلی نساز.',
					'shorten'   => 'این متن را به حدود نصف کوتاه کن و نکته‌های اصلی را نگه دار.',
					'proofread' => 'فقط غلط‌های املایی، نگارشی، نیم‌فاصله و علائم نگارشی این متن را درست کن؛ جمله‌ها را بازنویسی نکن.',
					'translate' => "این متن را به زبان {$target} ترجمه کن؛ روان و طبیعی، با حفظ ساختار.",
				);
				$user = $how[ $task ] . $html_rule . "\n\nمتن:\n" . $text;
				break;

			case 'summary':
				if ( '' === trim( $source ) ) {
					return self::need( __( 'متنی برای خلاصه‌کردن وجود ندارد.', 'netarz-ai' ) );
				}
				$user = "یک خلاصهٔ دو تا سه جمله‌ای (حداکثر ۵۰ کلمه) برای چکیدهٔ این نوشته بنویس. فقط متن خلاصه را برگردان.\n\n" . $source;
				break;

			case 'titles':
				$user = "برای این نوشته ۶ عنوان جذاب، دقیق و مناسب سئو (کمتر از ۶۵ نویسه) پیشنهاد کن. هر عنوان در یک خط، بدون شماره و بدون علامت.\n\n"
					. ( '' !== $title ? "عنوان فعلی: {$title}\n" : '' ) . Netarz_AI_Util::cut( $source, 4000 );
				break;

			case 'tags':
				$user = "برای این نوشته ۵ تا ۸ برچسب کوتاه پیشنهاد کن. هر برچسب در یک خط، بدون شماره و بدون #.\n\n" . Netarz_AI_Util::cut( $source, 4000 );
				break;

			case 'seo':
				if ( '' === trim( $source ) ) {
					return self::need( __( 'اول عنوان یا متن نوشته را بنویسید.', 'netarz-ai' ) );
				}
				$user = "برای این صفحه عنوان سئو (حداکثر ۶۰ نویسه) و توضیحات متا (بین ۱۲۰ تا ۱۵۵ نویسه، ترغیب‌کننده و دقیق) بنویس."
					. ( '' !== $keywords ? " کلیدواژهٔ اصلی: {$keywords}." : '' )
					. "\nفقط JSON برگردان: {\"title\": \"...\", \"description\": \"...\", \"focus_keyword\": \"...\"}\n\n"
					. ( '' !== $title ? "عنوان نوشته: {$title}\n" : '' ) . Netarz_AI_Util::cut( $source, 5000 );
				break;

			case 'faq':
				$user = "بر اساس این متن ۵ پرسش متداول واقعی که خواننده ممکن است بپرسد با پاسخ کوتاه بنویس. هر پرسش در h3 و پاسخ در p. فقط از اطلاعات متن استفاده کن."
					. $html_rule . "\n\n" . Netarz_AI_Util::cut( $source, 8000 );
				break;

			case 'product':
				$name = '' !== $title ? $title : $input;
				if ( '' === $name ) {
					return self::need( __( 'نام محصول را بنویسید.', 'netarz-ai' ) );
				}
				$facts = '' !== $input && $input !== $name ? $input : $plain_cut;
				$user  = "برای محصول «{$name}» توضیحات فروشگاهی بنویس."
					. ( '' !== $facts ? "\nاطلاعات و ویژگی‌های واقعی محصول (فقط از همین‌ها استفاده کن):\n{$facts}" : "\nجز آنچه از نام محصول پیداست ویژگی فنی نساز." )
					. ( '' !== $keywords ? "\nکلیدواژه‌ها: {$keywords}" : '' )
					. "\nفقط JSON برگردان: {\"description\": \"توضیحات کامل به HTML با h3، p، ul\", \"short_description\": \"دو تا سه جملهٔ کوتاه به HTML ساده\"}";
				break;

			case 'social':
				$user = "برای معرفی این نوشته در شبکه‌های اجتماعی یک متن کوتاه (حداکثر ۶۰ کلمه) با یک دعوت به خواندن بنویس. ایموجی نگذار. فقط متن را برگردان.\n\n"
					. ( '' !== $title ? "عنوان: {$title}\n" : '' ) . Netarz_AI_Util::cut( $source, 4000 );
				break;

			case 'image_prompt':
				$user = "برای تصویر شاخص این نوشته یک پرامپت تصویرسازی بنویس: یک پاراگراف توصیفی، بدون نوشته روی تصویر، سبک عکاسی یا تصویرسازی مدرن و تمیز. فقط پرامپت را برگردان.\n\n"
					. ( '' !== $title ? "عنوان: {$title}\n" : '' ) . Netarz_AI_Util::cut( $source, 3000 );
				break;

			default:
				return self::need( __( 'این کار شناخته نشد.', 'netarz-ai' ) );
		}

		$format = $tasks[ $task ]['format'];
		$result = Netarz_AI_Api::chat( array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => $user ),
		), array(
			'model'       => Netarz_AI_Settings::model( 'writer_model' ),
			'json'        => 'json' === $format,
			'temperature' => in_array( $task, array( 'proofread', 'translate', 'seo' ), true ) ? 0.2 : 0.7,
			'max_tokens'  => $tasks[ $task ]['tokens'],
			'feature'     => 'writer_' . $task,
			'timeout'     => 'article' === $task ? 180 : 90,
		) );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), Netarz_AI_Api::friendly_error( $result ), array( 'status' => 502 ) );
		}

		$out = trim( $result['content'] );

		switch ( $format ) {
			case 'html':
				$html = self::clean_html( $out );
				return array( 'format' => 'html', 'html' => $html );

			case 'list':
				$items = array();
				foreach ( preg_split( '/\r\n|\r|\n/', wp_strip_all_tags( $out ) ) as $line ) {
					// A /u regex, not trim(): trim() works on bytes and would cut the UTF-8 of «ث».
					$line = self::trim_quotes( preg_replace( '/^\s*(?:[-*•#]|\d+[.)\-]|[۰-۹]+[.)\-])\s*/u', '', $line ) );
					if ( '' !== $line ) {
						$items[] = $line;
					}
				}
				return array( 'format' => 'list', 'items' => array_slice( array_values( array_unique( $items ) ), 0, 10 ) );

			case 'json':
				$data = Netarz_AI_Util::json_from_model( $out );
				if ( ! is_array( $data ) ) {
					return new WP_Error( 'bad_output', __( 'خروجی مدل قابل خواندن نبود. دوباره امتحان کنید.', 'netarz-ai' ), array( 'status' => 502 ) );
				}
				if ( 'product' === $task ) {
					$data = array(
						'description'       => self::clean_html( isset( $data['description'] ) ? (string) $data['description'] : '' ),
						'short_description' => self::clean_html( isset( $data['short_description'] ) ? (string) $data['short_description'] : '' ),
					);
				} else {
					$data = array(
						'title'         => sanitize_text_field( isset( $data['title'] ) ? (string) $data['title'] : '' ),
						'description'   => sanitize_text_field( isset( $data['description'] ) ? (string) $data['description'] : '' ),
						'focus_keyword' => sanitize_text_field( isset( $data['focus_keyword'] ) ? (string) $data['focus_keyword'] : '' ),
					);
				}
				return array( 'format' => 'json', 'data' => $data );

			default:
				return array( 'format' => 'text', 'text' => trim( wp_strip_all_tags( $out ) ) );
		}
	}

	/** Strip surrounding whitespace and quote marks, multibyte-safe. */
	private static function trim_quotes( $text ) {
		return (string) preg_replace( '/^[\s"\'«»“”]+|[\s"\'«»“”]+$/u', '', (string) $text );
	}

	private static function need( $message ) {
		return new WP_Error( 'missing_input', $message, array( 'status' => 422 ) );
	}

	private static function label( array $map, $key ) {
		return isset( $map[ $key ] ) ? $map[ $key ] : $key;
	}

	/** Tags a writing result may contain: text structure only — no media, scripts or styling. */
	private static function allowed_tags() {
		$plain = array();
		$tags  = array( 'h2', 'h3', 'h4', 'p', 'br', 'ul', 'ol', 'li', 'strong', 'em', 'b', 'i', 'blockquote', 'code', 'table', 'thead', 'tbody', 'tr', 'th', 'td' );
		foreach ( $tags as $tag ) {
			$plain[ $tag ] = array();
		}
		$plain['a'] = array(
			'href'  => true,
			'title' => true,
		);
		return $plain;
	}

	/** Model HTML → safe post HTML (fences, wrappers, scripts and media stripped). */
	public static function clean_html( $html ) {
		$html = preg_replace( '/^```(?:html)?\s*|\s*```$/i', '', trim( (string) $html ) );
		$html = preg_replace( '#<(script|style|iframe|noscript|svg|object)\b[^>]*>.*?</\1\s*>#is', '', $html );
		$html = preg_replace( '#</?(?:html|head|body)[^>]*>#i', '', $html );
		$html = preg_replace( '#<h1([^>]*)>(.*?)</h1>#is', '<h2$1>$2</h2>', $html );
		// Plain text answers still read as paragraphs.
		if ( false === strpos( $html, '<' ) ) {
			$html = wpautop( esc_html( $html ) );
		}
		return trim( wp_kses( $html, self::allowed_tags(), array( 'http', 'https', 'mailto', 'tel' ) ) );
	}

	/** Post content without block comments, for the model to edit. */
	private static function lean_html( $content ) {
		$content = strip_shortcodes( (string) $content );
		$content = preg_replace( '/<!--.*?-->/s', '', $content );
		return trim( wp_kses( $content, array(
			'h2' => array(), 'h3' => array(), 'h4' => array(), 'p' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(),
			'strong' => array(), 'em' => array(), 'b' => array(), 'i' => array(), 'blockquote' => array(),
			'a' => array( 'href' => true ), 'table' => array(), 'thead' => array(), 'tbody' => array(), 'tr' => array(), 'th' => array(), 'td' => array(),
		) ) );
	}

	/* ------------------------------------------------------------------ */
	/* REST                                                                */
	/* ------------------------------------------------------------------ */

	public static function routes() {
		$ns = Netarz_AI_Chat::NS;

		register_rest_route( $ns, '/writer/run', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_run' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/writer/draft', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_create_draft' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/writer/image', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_image' ),
			'permission_callback' => array( __CLASS__, 'can_image' ),
		) );
		register_rest_route( $ns, '/writer/alt', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_alt' ),
			'permission_callback' => array( __CLASS__, 'can_image' ),
		) );
		register_rest_route( $ns, '/writer/seo', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_save_seo' ),
			'permission_callback' => array( __CLASS__, 'can_write' ),
		) );
		register_rest_route( $ns, '/writer/comment', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_comment' ),
			'permission_callback' => function () {
				return current_user_can( 'moderate_comments' ) && self::can_write();
			},
		) );
	}

	public static function can_image() {
		return self::can_write() && current_user_can( 'upload_files' );
	}

	/** @return true|WP_Error */
	private static function guard() {
		if ( ! self::enabled() ) {
			return new WP_Error( 'writer_disabled', __( 'ابزارهای نویسندگی در تنظیمات افزونه خاموش است.', 'netarz-ai' ), array( 'status' => 403 ) );
		}
		if ( ! Netarz_AI_Util::rate_limit( 'writer_' . get_current_user_id(), 40, 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'too_many', __( 'درخواست‌ها زیاد شد. چند دقیقه صبر کنید.', 'netarz-ai' ), array( 'status' => 429 ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return true;
	}

	/** Content of a post the current user may edit, or ''. */
	private static function post_content( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return '';
		}
		$post = get_post( $post_id );
		return $post ? (string) $post->post_content : '';
	}

	public static function rest_run( WP_REST_Request $request ) {
		$ok = self::guard();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		$params = array(
			'task'     => sanitize_key( (string) $request->get_param( 'task' ) ),
			'input'    => (string) $request->get_param( 'input' ),
			'title'    => (string) $request->get_param( 'title' ),
			'content'  => (string) $request->get_param( 'content' ),
			'tone'     => sanitize_key( (string) $request->get_param( 'tone' ) ),
			'language' => (string) $request->get_param( 'language' ),
			'length'   => sanitize_key( (string) $request->get_param( 'length' ) ),
			'keywords' => (string) $request->get_param( 'keywords' ),
			'target'   => (string) $request->get_param( 'target' ),
		);
		// The editor sends unsaved content; fall back to the saved post.
		if ( '' === trim( $params['content'] ) && $request->get_param( 'post_id' ) ) {
			$params['content'] = self::post_content( $request->get_param( 'post_id' ) );
		}
		if ( Netarz_AI_Util::length( $params['input'] ) > 20000 ) {
			return new WP_Error( 'too_long', __( 'متن ورودی خیلی طولانی است.', 'netarz-ai' ), array( 'status' => 422 ) );
		}

		$result = self::run( $params );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/** Writer page → a new draft post. */
	public static function rest_create_draft( WP_REST_Request $request ) {
		$type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$type = in_array( $type, array( 'post', 'page' ), true ) ? $type : 'post';
		$pto  = get_post_type_object( $type );
		if ( ! $pto || ! current_user_can( $pto->cap->create_posts ) ) {
			return new WP_Error( 'forbidden', __( 'اجازهٔ ساختن این نوع نوشته را ندارید.', 'netarz-ai' ), array( 'status' => 403 ) );
		}

		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$html  = self::clean_html( (string) $request->get_param( 'html' ) );
		if ( '' === $html ) {
			return new WP_Error( 'missing_input', __( 'متنی برای ذخیره وجود ندارد.', 'netarz-ai' ), array( 'status' => 422 ) );
		}

		$post_id = wp_insert_post( array(
			'post_title'   => '' !== $title ? $title : __( 'نوشتهٔ تازه', 'netarz-ai' ),
			'post_content' => $html,
			'post_status'  => 'draft',
			'post_type'    => $type,
			'post_author'  => get_current_user_id(),
		), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$description = sanitize_text_field( (string) $request->get_param( 'meta_description' ) );
		if ( '' !== $description ) {
			self::save_seo( $post_id, '', $description );
		}

		return rest_ensure_response( array(
			'id'   => $post_id,
			'edit' => get_edit_post_link( $post_id, 'raw' ),
		) );
	}

	public static function rest_image( WP_REST_Request $request ) {
		$ok = self::guard();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		$prompt = trim( sanitize_textarea_field( (string) $request->get_param( 'prompt' ) ) );
		if ( '' === $prompt ) {
			return new WP_Error( 'missing_input', __( 'توصیف تصویر را بنویسید.', 'netarz-ai' ), array( 'status' => 422 ) );
		}
		$sizes   = array( '1024x1024', '1536x1024', '1024x1536' );
		$size    = in_array( $request->get_param( 'size' ), $sizes, true ) ? $request->get_param( 'size' ) : '1024x1024';
		$quality = in_array( $request->get_param( 'quality' ), array( 'low', 'medium', 'high' ), true ) ? $request->get_param( 'quality' ) : 'medium';
		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			$post_id = 0;
		}

		$image = Netarz_AI_Api::image( $prompt, array(
			'size'    => $size,
			'quality' => $quality,
		) );
		if ( is_wp_error( $image ) ) {
			return new WP_Error( $image->get_error_code(), Netarz_AI_Api::friendly_error( $image ), array( 'status' => 502 ) );
		}

		$attachment_id = self::save_image( $image['bytes'], $image['mime'], $prompt, $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		if ( $post_id && rest_sanitize_boolean( $request->get_param( 'featured' ) ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}

		return rest_ensure_response( array(
			'id'    => $attachment_id,
			'url'   => wp_get_attachment_url( $attachment_id ),
			'thumb' => wp_get_attachment_image_url( $attachment_id, 'medium' ),
			'edit'  => get_edit_post_link( $attachment_id, 'raw' ),
		) );
	}

	/** Bytes → media library item. @return int|WP_Error */
	private static function save_image( $bytes, $mime, $prompt, $post_id ) {
		$ext  = array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
		);
		$ext  = isset( $ext[ $mime ] ) ? $ext[ $mime ] : 'png';
		$slug = sanitize_title( Netarz_AI_Util::cut( $prompt, 40 ) );
		$name = ( '' !== $slug && '…' !== $slug ? $slug : 'ai-image' ) . '-' . wp_generate_password( 6, false ) . '.' . $ext;

		$upload = wp_upload_bits( $name, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'upload_error', $upload['error'], array( 'status' => 500 ) );
		}

		$attachment_id = wp_insert_attachment( array(
			'post_mime_type' => $mime,
			'post_title'     => Netarz_AI_Util::cut( $prompt, 80 ),
			'post_content'   => '',
			'post_excerpt'   => '',
			'post_status'    => 'inherit',
		), $upload['file'], $post_id, true );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', Netarz_AI_Util::cut( $prompt, 120 ) );
		update_post_meta( $attachment_id, '_netarz_ai_generated', 1 );

		return (int) $attachment_id;
	}

	/** Describe an image for its alt text, using a vision model. */
	public static function rest_alt( WP_REST_Request $request ) {
		$ok = self::guard();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		$id = absint( $request->get_param( 'attachment_id' ) );
		if ( ! $id || ! wp_attachment_is_image( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'invalid_image', __( 'این تصویر پیدا نشد.', 'netarz-ai' ), array( 'status' => 404 ) );
		}

		$data_url = self::image_data_url( $id );
		if ( is_wp_error( $data_url ) ) {
			return $data_url;
		}

		$parent  = wp_get_post_parent_id( $id );
		$context = $parent ? get_the_title( $parent ) : '';

		$result = Netarz_AI_Api::chat( array(
			array(
				'role'    => 'system',
				'content' => 'برای ویژگی alt تصویر، یک توصیف کوتاه و دقیق (حداکثر ۱۲۰ نویسه) به زبان ' . Netarz_AI_Settings::get( 'writer_language' ) . ' بنویس. با «تصویرِ» شروع نکن. فقط متن توصیف را برگردان.',
			),
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => '' !== $context ? 'این تصویر در صفحه‌ای با عنوان «' . $context . '» استفاده شده.' : 'این تصویر را توصیف کن.',
					),
					array(
						'type'      => 'image_url',
						'image_url' => array( 'url' => $data_url ),
					),
				),
			),
		), array(
			'model'       => Netarz_AI_Settings::model( 'vision_model' ),
			'temperature' => 0.2,
			'max_tokens'  => 120,
			'feature'     => 'alt_text',
		) );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), Netarz_AI_Api::friendly_error( $result ), array( 'status' => 502 ) );
		}

		$alt = Netarz_AI_Util::cut( self::trim_quotes( wp_strip_all_tags( $result['content'] ) ), 150 );
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );

		return rest_ensure_response( array( 'alt' => $alt ) );
	}

	/** A small JPEG of the image as a data: URL (upstream cannot reach a local site). */
	private static function image_data_url( $attachment_id ) {
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'invalid_image', __( 'فایل تصویر پیدا نشد.', 'netarz-ai' ), array( 'status' => 404 ) );
		}

		$editor = wp_get_image_editor( $path );
		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( 768, 768, false );
			$editor->set_quality( 80 );
			// wp_tempnam() lives in wp-admin/includes and is missing on REST requests.
			$tmp   = trailingslashit( get_temp_dir() ) . 'netarz-ai-alt-' . wp_generate_password( 12, false ) . '.jpg';
			$saved = $editor->save( $tmp, 'image/jpeg' );
			if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) && file_exists( $saved['path'] ) ) {
				$bytes = file_get_contents( $saved['path'] ); // phpcs:ignore
				wp_delete_file( $saved['path'] );
				if ( false !== $bytes && '' !== $bytes ) {
					return 'data:image/jpeg;base64,' . base64_encode( $bytes );
				}
			}
		}

		// No image library: send the original if it is small enough.
		if ( filesize( $path ) > 1500 * KB_IN_BYTES ) {
			return new WP_Error( 'image_too_big', __( 'تصویر بزرگ است و کتابخانهٔ تصویر سرور در دسترس نیست.', 'netarz-ai' ), array( 'status' => 422 ) );
		}
		$mime = get_post_mime_type( $attachment_id );
		return 'data:' . $mime . ';base64,' . base64_encode( file_get_contents( $path ) ); // phpcs:ignore
	}

	public static function rest_save_seo( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', __( 'اجازهٔ ویرایش این نوشته را ندارید.', 'netarz-ai' ), array( 'status' => 403 ) );
		}
		$saved = self::save_seo(
			$post_id,
			sanitize_text_field( (string) $request->get_param( 'title' ) ),
			sanitize_text_field( (string) $request->get_param( 'description' ) ),
			sanitize_text_field( (string) $request->get_param( 'focus_keyword' ) )
		);
		return rest_ensure_response( array( 'saved' => true, 'plugin' => $saved ) );
	}

	/**
	 * Store SEO title/description where the active SEO plugin reads them, and in our own meta.
	 *
	 * @return string which SEO plugin received the values ('' = ours only)
	 */
	public static function save_seo( $post_id, $title, $description, $keyword = '' ) {
		$plugin = self::seo_plugin();

		if ( '' !== $title ) {
			update_post_meta( $post_id, self::META_TITLE, $title );
		}
		if ( '' !== $description ) {
			update_post_meta( $post_id, self::META_DESCRIPTION, $description );
		}

		$keys = array(
			'yoast'    => array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw' ),
			'rankmath' => array( 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword' ),
			'aioseo'   => array( '_aioseo_title', '_aioseo_description', '' ),
			'seopress' => array( '_seopress_titles_title', '_seopress_titles_desc', '_seopress_analysis_target_kw' ),
		);
		if ( isset( $keys[ $plugin ] ) ) {
			list( $t, $d, $k ) = $keys[ $plugin ];
			if ( '' !== $title ) {
				update_post_meta( $post_id, $t, $title );
			}
			if ( '' !== $description ) {
				update_post_meta( $post_id, $d, $description );
			}
			if ( '' !== $keyword && '' !== $k ) {
				update_post_meta( $post_id, $k, $keyword );
			}
		}
		return $plugin;
	}

	/** Which SEO plugin owns the <head>, if any. */
	public static function seo_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			return 'aioseo';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		return '';
	}

	/** Meta description on the front end, only when no SEO plugin prints its own. */
	public static function print_meta() {
		if ( ! Netarz_AI_Settings::get( 'writer_seo_meta' ) || '' !== self::seo_plugin() || ! is_singular() ) {
			return;
		}
		$description = get_post_meta( get_queried_object_id(), self::META_DESCRIPTION, true );
		if ( '' !== (string) $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
		}
	}

	public static function document_title( $title ) {
		if ( ! Netarz_AI_Settings::get( 'writer_seo_meta' ) || '' !== self::seo_plugin() || ! is_singular() ) {
			return $title;
		}
		$custom = (string) get_post_meta( get_queried_object_id(), self::META_TITLE, true );
		return '' !== $custom ? $custom : $title;
	}

	public static function rest_comment( WP_REST_Request $request ) {
		$ok = self::guard();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$comment = get_comment( absint( $request->get_param( 'comment_id' ) ) );
		if ( ! $comment || ! current_user_can( 'edit_comment', $comment->comment_ID ) ) {
			return new WP_Error( 'invalid_comment', __( 'این دیدگاه پیدا نشد.', 'netarz-ai' ), array( 'status' => 404 ) );
		}

		$post    = get_post( $comment->comment_post_ID );
		$site    = get_bloginfo( 'name' );
		$context = $post ? Netarz_AI_Util::cut( Netarz_AI_Knowledge::plain( $post->post_content ), 3000 ) : '';

		$result = Netarz_AI_Api::chat( array(
			array(
				'role'    => 'system',
				'content' => "تو از طرف سایت «{$site}» به دیدگاه خواننده‌ها جواب می‌دهی. کوتاه (حداکثر سه جمله)، گرم، مؤدب و دقیق بنویس و خواننده را «شما» خطاب کن. فقط از متن نوشته استفاده کن و چیزی نساز. اگر دیدگاه انتقاد است، بدون دفاع تند، مسئولانه جواب بده. فقط متن پاسخ را برگردان.",
			),
			array(
				'role'    => 'user',
				'content' => ( $post ? 'عنوان نوشته: ' . get_the_title( $post ) . "\nمتن نوشته (خلاصه):\n" . $context . "\n\n" : '' )
					. 'دیدگاه ' . $comment->comment_author . ":\n" . $comment->comment_content,
			),
		), array(
			'model'       => Netarz_AI_Settings::model( 'writer_model' ),
			'temperature' => 0.5,
			'max_tokens'  => 300,
			'feature'     => 'comment_reply',
		) );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), Netarz_AI_Api::friendly_error( $result ), array( 'status' => 502 ) );
		}
		return rest_ensure_response( array( 'reply' => trim( wp_strip_all_tags( $result['content'] ) ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Admin UI hooks                                                      */
	/* ------------------------------------------------------------------ */

	public static function meta_box( $post_type ) {
		if ( ! self::enabled() || ! self::can_write() ) {
			return;
		}
		$pto = get_post_type_object( $post_type );
		if ( ! $pto || ! $pto->public || 'attachment' === $post_type ) {
			return;
		}
		add_meta_box( 'netarz-ai-writer', __( 'دستیار هوشمند نِت اَرز', 'netarz-ai' ), array( __CLASS__, 'render_meta_box' ), $post_type, 'side', 'high' );
	}

	public static function render_meta_box( $post ) {
		$is_product = 'product' === $post->post_type;
		$tasks      = self::tasks();
		$meta_desc  = (string) get_post_meta( $post->ID, self::META_DESCRIPTION, true );
		?>
		<div class="nzai-wbox" data-post="<?php echo (int) $post->ID; ?>" data-type="<?php echo esc_attr( $post->post_type ); ?>">
			<?php if ( ! Netarz_AI_Api::has_key() ) : ?>
				<p class="nzai-wbox-warn"><?php esc_html_e( 'کلید API نِت اَرز وارد نشده است.', 'netarz-ai' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=netarz-ai-settings' ) ); ?>"><?php esc_html_e( 'تنظیمات', 'netarz-ai' ); ?></a></p>
			<?php endif; ?>
			<p>
				<label for="nzai-w-task"><strong><?php esc_html_e( 'چه کاری انجام شود؟', 'netarz-ai' ); ?></strong></label>
				<select id="nzai-w-task" class="nzai-w-task widefat">
					<?php
					$order = $is_product
						? array( 'product', 'seo', 'rewrite', 'proofread', 'faq', 'translate', 'image_prompt' )
						: array( 'article', 'outline', 'rewrite', 'expand', 'shorten', 'proofread', 'summary', 'titles', 'seo', 'tags', 'faq', 'translate', 'social', 'image_prompt' );
					foreach ( $order as $key ) {
						printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $tasks[ $key ]['label'] ) );
					}
					?>
				</select>
			</p>
			<p class="nzai-w-input-wrap">
				<label for="nzai-w-input"><?php esc_html_e( 'موضوع، متن یا توضیح (اختیاری)', 'netarz-ai' ); ?></label>
				<textarea id="nzai-w-input" class="nzai-w-input widefat" rows="3" placeholder="<?php esc_attr_e( 'خالی بماند تا از عنوان و محتوای همین نوشته استفاده شود.', 'netarz-ai' ); ?>"></textarea>
			</p>
			<div class="nzai-w-opts">
				<select class="nzai-w-tone" aria-label="<?php esc_attr_e( 'لحن', 'netarz-ai' ); ?>">
					<?php foreach ( self::tones() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( Netarz_AI_Settings::get( 'writer_tone' ), $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select class="nzai-w-length" aria-label="<?php esc_attr_e( 'طول', 'netarz-ai' ); ?>">
					<?php foreach ( self::lengths() as $key => $def ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( 'medium', $key ); ?>><?php echo esc_html( $def[0] ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="text" class="nzai-w-keywords widefat" placeholder="<?php esc_attr_e( 'کلیدواژه‌ها (اختیاری)', 'netarz-ai' ); ?>">
				<input type="text" class="nzai-w-target widefat" placeholder="<?php esc_attr_e( 'زبان مقصد ترجمه، مثلاً English', 'netarz-ai' ); ?>" hidden>
			</div>
			<p><button type="button" class="button button-primary nzai-w-run"><?php esc_html_e( 'اجرا', 'netarz-ai' ); ?></button> <span class="spinner"></span></p>
			<div class="nzai-w-result" hidden></div>
			<?php if ( $meta_desc ) : ?>
				<p class="description"><strong><?php esc_html_e( 'توضیحات متای فعلی:', 'netarz-ai' ); ?></strong> <?php echo esc_html( $meta_desc ); ?></p>
			<?php endif; ?>
			<hr>
			<details class="nzai-w-image">
				<summary><strong><?php esc_html_e( 'ساخت تصویر شاخص', 'netarz-ai' ); ?></strong></summary>
				<textarea class="nzai-w-prompt widefat" rows="3" placeholder="<?php esc_attr_e( 'توصیف تصویر… (خالی = پیشنهاد از روی عنوان)', 'netarz-ai' ); ?>"></textarea>
				<p>
					<select class="nzai-w-size">
						<option value="1536x1024"><?php esc_html_e( 'افقی', 'netarz-ai' ); ?></option>
						<option value="1024x1024"><?php esc_html_e( 'مربع', 'netarz-ai' ); ?></option>
						<option value="1024x1536"><?php esc_html_e( 'عمودی', 'netarz-ai' ); ?></option>
					</select>
					<select class="nzai-w-quality">
						<option value="medium"><?php esc_html_e( 'کیفیت متوسط', 'netarz-ai' ); ?></option>
						<option value="low"><?php esc_html_e( 'کیفیت پایین (ارزان‌تر)', 'netarz-ai' ); ?></option>
						<option value="high"><?php esc_html_e( 'کیفیت بالا', 'netarz-ai' ); ?></option>
					</select>
				</p>
				<p><button type="button" class="button nzai-w-imagine"><?php esc_html_e( 'ساخت و تنظیم به‌عنوان تصویر شاخص', 'netarz-ai' ); ?></button> <span class="spinner"></span></p>
				<div class="nzai-w-image-result"></div>
			</details>
		</div>
		<?php
	}

	public static function enqueue( $hook ) {
		if ( ! self::enabled() || ! self::can_write() ) {
			return;
		}
		$screens = array( 'post.php', 'post-new.php', 'upload.php', 'edit-comments.php', 'toplevel_page_netarz-ai', 'netarz-ai_page_netarz-ai-writer', 'netarz-ai_page_netarz-ai-images' );
		$is_ours = false !== strpos( (string) $hook, 'netarz-ai' );
		if ( ! in_array( $hook, $screens, true ) && ! $is_ours ) {
			return;
		}
		if ( 'upload.php' === $hook || 'post.php' === $hook || 'post-new.php' === $hook ) {
			wp_enqueue_media();
		}
		wp_enqueue_style( 'netarz-ai-admin', NETARZ_AI_URL . 'assets/css/admin.css', array(), NETARZ_AI_VERSION );
		wp_enqueue_script( 'netarz-ai-writer', NETARZ_AI_URL . 'assets/js/writer.js', array( 'jquery', 'wp-api-fetch' ), NETARZ_AI_VERSION, true );
		wp_localize_script( 'netarz-ai-writer', 'NetarzAIWriter', array(
			'ns'   => Netarz_AI_Chat::NS,
			'i18n' => array(
				'working'    => __( 'در حال نوشتن…', 'netarz-ai' ),
				'imaging'    => __( 'در حال ساخت تصویر؛ ممکن است یک دقیقه طول بکشد…', 'netarz-ai' ),
				'insert'     => __( 'افزودن به انتهای محتوا', 'netarz-ai' ),
				'replace'    => __( 'جایگزینی کل محتوا', 'netarz-ai' ),
				'copy'       => __( 'کپی', 'netarz-ai' ),
				'copied'     => __( 'کپی شد', 'netarz-ai' ),
				'useTitle'   => __( 'استفاده به‌عنوان عنوان', 'netarz-ai' ),
				'useExcerpt' => __( 'قراردادن در چکیده', 'netarz-ai' ),
				'saveSeo'    => __( 'ذخیرهٔ عنوان و توضیحات سئو', 'netarz-ai' ),
				'saved'      => __( 'ذخیره شد', 'netarz-ai' ),
				'addTags'    => __( 'افزودن به برچسب‌ها', 'netarz-ai' ),
				'useDesc'    => __( 'قراردادن در توضیحات محصول', 'netarz-ai' ),
				'useShort'   => __( 'قراردادن در توضیح کوتاه', 'netarz-ai' ),
				'confirmReplace' => __( 'کل محتوای فعلی جایگزین شود؟', 'netarz-ai' ),
				'altWorking' => __( 'در حال نوشتن متن جایگزین…', 'netarz-ai' ),
				'altDone'    => __( 'متن جایگزین ذخیره شد.', 'netarz-ai' ),
				'reply'      => __( 'پیشنهاد پاسخ با هوش مصنوعی', 'netarz-ai' ),
				'error'      => __( 'خطا', 'netarz-ai' ),
				'seoTitle'   => __( 'عنوان سئو', 'netarz-ai' ),
				'seoDesc'    => __( 'توضیحات متا', 'netarz-ai' ),
				'chars'      => __( 'نویسه', 'netarz-ai' ),
				'savePost'   => __( 'ذخیره به‌عنوان پیش‌نویس تازه', 'netarz-ai' ),
				'openEditor' => __( 'باز کردن در ویرایشگر', 'netarz-ai' ),
				'setFeatured' => __( 'تصویر شاخص تنظیم شد.', 'netarz-ai' ),
				'openMedia'  => __( 'دیدن در کتابخانهٔ رسانه', 'netarz-ai' ),
			),
		) );
	}

	/** "Generate alt text" button inside the media modal / attachment screen. */
	public static function attachment_field( $fields, $post ) {
		if ( ! self::enabled() || ! self::can_write() || ! current_user_can( 'upload_files' ) || ! wp_attachment_is_image( $post->ID ) ) {
			return $fields;
		}
		$fields['netarz_ai_alt'] = array(
			'label' => __( 'هوش مصنوعی', 'netarz-ai' ),
			'input' => 'html',
			'html'  => '<button type="button" class="button nzai-alt-btn" data-id="' . (int) $post->ID . '">' . esc_html__( 'نوشتن متن جایگزین (alt)', 'netarz-ai' ) . '</button> <span class="nzai-alt-status"></span>',
		);
		return $fields;
	}

	public static function comment_action( $actions, $comment ) {
		if ( ! self::enabled() || ! Netarz_AI_Settings::get( 'comments_ai_reply' ) || ! self::can_write() || ! current_user_can( 'moderate_comments' ) ) {
			return $actions;
		}
		$actions['netarz_ai'] = '<a href="#" class="nzai-comment-reply" data-id="' . (int) $comment->comment_ID . '" data-post="' . (int) $comment->comment_post_ID . '">' . esc_html__( 'پیشنهاد پاسخ هوشمند', 'netarz-ai' ) . '</a>';
		return $actions;
	}
}
