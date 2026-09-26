<?php
/**
 * What the assistant is allowed to know: the owner's own notes plus the site's
 * published content that matches the visitor's question.
 *
 * Retrieval is deliberately simple and local — keyword search over published
 * posts, pages and products — so nothing about the site leaves WordPress
 * except the handful of snippets that answer the current question.
 *
 * @package NetArz_AI
 */

defined( 'ABSPATH' ) || exit;

class Netarz_AI_Knowledge {

	const MAX_DOCS = 5;

	const SNIPPET = 700;

	/** Words that carry no meaning for search (Persian + English). */
	private static $stopwords = array(
		'از', 'به', 'با', 'در', 'که', 'این', 'آن', 'را', 'و', 'یا', 'تا', 'بر', 'برای', 'هم', 'اما', 'اگر', 'چه', 'چی', 'چطور', 'چگونه',
		'آیا', 'است', 'هست', 'هستند', 'بود', 'شد', 'شود', 'می', 'میشه', 'می‌شه', 'می‌شود', 'کنم', 'کنید', 'کن', 'دارم', 'دارید', 'داره',
		'دارد', 'من', 'ما', 'شما', 'او', 'اون', 'این‌ها', 'ها', 'های', 'یک', 'یه', 'رو', 'هر', 'خیلی', 'لطفا', 'لطفاً', 'سلام', 'ممنون',
		'مرسی', 'باید', 'نیست', 'کجا', 'کی', 'چند', 'چقدر', 'چرا', 'بله', 'نه', 'خب', 'پس', 'الان', 'تو', 'توی', 'ی', 'هستم', 'خواهم',
		'میخوام', 'می‌خوام', 'میخواهم', 'می‌خواهم', 'the', 'a', 'an', 'is', 'are', 'to', 'of', 'and', 'or', 'in', 'on', 'for', 'with',
		'what', 'how', 'can', 'do', 'does', 'i', 'you', 'my', 'me', 'it', 'this', 'that', 'please', 'hi', 'hello',
	);

	/**
	 * The knowledge block for a prompt.
	 *
	 * @param string $question the visitor's latest message(s)
	 */
	public static function context( $question ) {
		$parts = array();

		$site  = get_bloginfo( 'name' );
		$about = trim( (string) Netarz_AI_Settings::get( 'site_description' ) );
		$parts[] = "سایت: {$site}\nآدرس: " . home_url( '/' ) . ( '' !== $about ? "\nدربارهٔ کسب‌وکار: {$about}" : '' );

		$notes = trim( (string) Netarz_AI_Settings::get( 'chat_knowledge' ) );
		if ( '' !== $notes ) {
			$parts[] = "═══ اطلاعات رسمی که مدیر سایت نوشته (معتبرترین منبع) ═══\n" . $notes;
		}

		$pages = self::key_pages();
		if ( $pages ) {
			$parts[] = "═══ صفحه‌های مهم سایت ═══\n" . $pages;
		}

		if ( Netarz_AI_Settings::get( 'chat_use_content' ) ) {
			$docs = self::search( $question );
			if ( $docs ) {
				$lines = array();
				foreach ( $docs as $doc ) {
					$lines[] = "## {$doc['title']}\nلینک: {$doc['url']}" . ( $doc['meta'] ? "\n{$doc['meta']}" : '' ) . "\n{$doc['text']}";
				}
				$parts[] = "═══ مطالب سایت مرتبط با این سؤال ═══\n" . implode( "\n\n", $lines );
			}
		}

		return implode( "\n\n", $parts );
	}

	/** Shop, cart, account, contact and tickets pages, when they exist. */
	private static function key_pages() {
		$lines = array();

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$lines[] = 'فروشگاه: ' . wc_get_page_permalink( 'shop' );
			$lines[] = 'سبد خرید: ' . wc_get_page_permalink( 'cart' );
			$lines[] = 'حساب کاربری و سفارش‌ها: ' . wc_get_page_permalink( 'myaccount' );
		}

		$tickets = (int) Netarz_AI_Settings::get( 'tickets_page_id' );
		if ( $tickets && Netarz_AI_Settings::get( 'tickets_enabled' ) && 'publish' === get_post_status( $tickets ) ) {
			$lines[] = 'ثبت و پیگیری تیکت پشتیبانی: ' . rawurldecode( get_permalink( $tickets ) );
		}

		foreach ( array( 'contact', 'contact-us', 'تماس-با-ما', 'about', 'about-us', 'درباره-ما' ) as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page && 'publish' === $page->post_status ) {
				$lines[] = get_the_title( $page ) . ': ' . rawurldecode( get_permalink( $page ) );
			}
		}

		return implode( "\n", array_unique( $lines ) );
	}

	/**
	 * Find published content that matches the question.
	 *
	 * Every keyword is searched on its own and results are ranked by how many
	 * keywords hit, because WordPress' search ANDs all terms and a chatty
	 * question would otherwise match nothing.
	 *
	 * @return array<int, array{title:string, url:string, meta:string, text:string}>
	 */
	public static function search( $question, $limit = self::MAX_DOCS ) {
		$keywords = self::keywords( $question );
		if ( ! $keywords ) {
			return array();
		}

		$types = array_values( array_filter( Netarz_AI_Settings::csv( 'chat_content_types' ), 'post_type_exists' ) );
		if ( ! $types ) {
			return array();
		}

		$scores = array();
		foreach ( $keywords as $i => $word ) {
			$ids = get_posts( array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				's'                => $word,
				'fields'           => 'ids',
				'posts_per_page'   => 10,
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'has_password'     => false,
			) );
			foreach ( $ids as $rank => $id ) {
				// Keywords come longest (most specific) first; earlier results are better matches.
				$scores[ $id ] = ( isset( $scores[ $id ] ) ? $scores[ $id ] : 0 ) + 10 - min( 9, $rank ) + max( 0, 3 - $i );
			}
		}

		if ( ! $scores ) {
			return array();
		}

		arsort( $scores );
		$docs = array();
		foreach ( array_slice( array_keys( $scores ), 0, (int) $limit ) as $id ) {
			$doc = self::document( (int) $id );
			if ( $doc ) {
				$docs[] = $doc;
			}
		}
		return $docs;
	}

	/** @return array{title:string, url:string, meta:string, text:string}|null */
	public static function document( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return null;
		}

		$meta = '';
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				$bits = array();
				$price = $product->get_price();
				if ( '' !== (string) $price ) {
					$bits[] = 'قیمت: ' . html_entity_decode( wp_strip_all_tags( wc_price( wc_get_price_to_display( $product ) ) ), ENT_QUOTES, 'UTF-8' );
				}
				if ( $product->is_on_sale() && $product->get_regular_price() ) {
					$bits[] = 'قیمت قبل از تخفیف: ' . html_entity_decode( wp_strip_all_tags( wc_price( (float) $product->get_regular_price() ) ), ENT_QUOTES, 'UTF-8' );
				}
				$bits[] = 'موجودی: ' . ( $product->is_in_stock() ? 'موجود' : 'ناموجود' );
				if ( $product->get_sku() ) {
					$bits[] = 'کد کالا: ' . $product->get_sku();
				}
				$meta = implode( ' | ', $bits );
			}
		}

		$text = $post->post_excerpt ? $post->post_excerpt . "\n" : '';
		$text .= self::plain( $post->post_content );

		return array(
			'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			// Readable Persian slugs: the model copies links verbatim into answers.
			'url'   => rawurldecode( get_permalink( $post ) ),
			'meta'  => $meta,
			'text'  => Netarz_AI_Util::cut( trim( $text ), self::SNIPPET ),
		);
	}

	/** Post content → plain text without shortcodes, blocks or markup. */
	public static function plain( $content ) {
		$content = strip_shortcodes( (string) $content );
		$content = preg_replace( '/<!--.*?-->/s', '', $content );
		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $content ) );
	}

	/** @return string[] up to five meaningful words, longest first */
	public static function keywords( $text ) {
		$text  = Netarz_AI_Util::latin_digits( $text );
		$text  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
		// Unify Arabic forms of «ی» and «ک», and drop the zero-width joiner splits.
		$text  = strtr( $text, array( 'ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', "\xE2\x80\x8C" => ' ' ) );
		$words = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $words ) {
			return array();
		}

		$stop  = array_flip( self::$stopwords );
		$found = array();
		foreach ( $words as $word ) {
			if ( isset( $stop[ $word ] ) || Netarz_AI_Util::length( $word ) < 2 || ctype_digit( $word ) ) {
				continue;
			}
			$found[ $word ] = Netarz_AI_Util::length( $word );
		}

		arsort( $found );
		return array_slice( array_keys( $found ), 0, 5 );
	}
}
