<?php
/**
 * Small helpers shared by the modules.
 *
 * @package NetArz_AI
 */

defined( 'ABSPATH' ) || exit;

class Netarz_AI_Util {

	/** UTC "Y-m-d H:i:s" — every datetime column stores UTC. */
	public static function now() {
		return current_time( 'mysql', true );
	}

	/** UTC datetime string → site-local formatted date. */
	public static function date( $utc, $format = '' ) {
		if ( empty( $utc ) || '0000-00-00 00:00:00' === $utc ) {
			return '';
		}
		$format = $format ? $format : get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$ts     = strtotime( $utc . ' UTC' );
		return $ts ? wp_date( $format, $ts ) : '';
	}

	/** UTC datetime → "5 minutes ago". */
	public static function ago( $utc ) {
		$ts = $utc ? strtotime( $utc . ' UTC' ) : 0;
		if ( ! $ts ) {
			return '';
		}
		/* translators: %s: human time difference */
		return sprintf( __( '%s پیش', 'netarz-ai' ), human_time_diff( $ts, time() ) );
	}

	/** UTC datetime → Unix timestamp (0 if empty). */
	public static function ts( $utc ) {
		$ts = $utc ? strtotime( $utc . ' UTC' ) : 0;
		return $ts ? (int) $ts : 0;
	}

	/**
	 * Visitor IP, as WordPress sees it. Only ever stored hashed.
	 *
	 * Behind a reverse proxy or CDN every visitor shares REMOTE_ADDR; such sites
	 * can return the real client address through the `netarz_ai_client_ip` filter
	 * (only from a header their proxy sets, never one a visitor can forge).
	 */
	public static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'netarz_ai_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	public static function ip_hash() {
		return hash_hmac( 'sha256', self::ip(), wp_salt( 'nonce' ) );
	}

	/**
	 * Fixed-window counter. Returns false once $limit hits happen inside $window seconds.
	 *
	 * @param string $bucket unique name
	 */
	public static function rate_limit( $bucket, $limit, $window ) {
		$key   = 'netarz_ai_rl_' . md5( $bucket );
		$state = get_transient( $key );
		$now   = time();

		if ( ! is_array( $state ) || ! isset( $state['reset'] ) || $state['reset'] <= $now ) {
			$state = array(
				'count' => 0,
				'reset' => $now + (int) $window,
			);
		}

		if ( $state['count'] >= (int) $limit ) {
			return false;
		}

		$state['count']++;
		set_transient( $key, $state, max( 1, $state['reset'] - $now ) );
		return true;
	}

	/**
	 * Atomically add one to a named daily counter and return the new value.
	 *
	 * Stored as an option row updated in SQL, so parallel requests cannot all
	 * read the same old value and slip past a cap together.
	 */
	public static function bump_daily( $name ) {
		global $wpdb;
		$option = 'netarz_ai_count_' . sanitize_key( $name ) . '_' . wp_date( 'Ymd' );

		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'no')", $option ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s", $option ) ); // phpcs:ignore
		wp_cache_delete( $option, 'options' );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) ); // phpcs:ignore
	}

	/** Current value of a daily counter (0 when untouched today). */
	public static function daily( $name ) {
		global $wpdb;
		$option = 'netarz_ai_count_' . sanitize_key( $name ) . '_' . wp_date( 'Ymd' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) ); // phpcs:ignore
	}

	/** Drop counters from previous days. */
	public static function prune_counters() {
		global $wpdb;
		$today = wp_date( 'Ymd' );
		$rows  = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'netarz\\_ai\\_count\\_%'" ); // phpcs:ignore
		foreach ( $rows as $name ) {
			if ( substr( $name, -8 ) !== $today ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) ); // phpcs:ignore
			}
		}
	}

	/**
	 * Free text from a customer or operator, kept as typed.
	 *
	 * WordPress' sanitize_*_field() strip every %XX sequence (cutting pasted
	 * Persian URLs) and turn "<" into "&lt;". Message bodies are always escaped
	 * on output, so only invalid UTF-8 and control characters are removed here.
	 */
	public static function clean_text( $text, $max = 0 ) {
		$text = wp_check_invalid_utf8( (string) $text, true );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
		$text = trim( (string) $text );
		if ( $max > 0 && self::length( $text ) > $max ) {
			$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
		}
		return $text;
	}

	/** Random 36-char UUID v4. */
	public static function uuid() {
		return wp_generate_uuid4();
	}

	/** Random hex token of $bytes bytes. */
	public static function token( $bytes = 24 ) {
		return bin2hex( random_bytes( $bytes ) );
	}

	/** Are we inside the support hours set in the chat settings? */
	public static function within_hours() {
		if ( ! Netarz_AI_Settings::get( 'chat_hours_enabled' ) ) {
			return true;
		}

		$now   = new DateTime( 'now', wp_timezone() );
		$day   = (int) $now->format( 'w' );
		$days  = array_map( 'intval', array_filter( explode( ',', (string) Netarz_AI_Settings::get( 'chat_hours_days' ) ), 'strlen' ) );
		if ( ! in_array( $day, $days, true ) ) {
			return false;
		}

		$time  = $now->format( 'H:i' );
		$start = (string) Netarz_AI_Settings::get( 'chat_hours_start' );
		$end   = (string) Netarz_AI_Settings::get( 'chat_hours_end' );

		if ( $start <= $end ) {
			return $time >= $start && $time < $end;
		}
		// Overnight window, e.g. 18:00 → 02:00.
		return $time >= $start || $time < $end;
	}

	/**
	 * Pull the first JSON object out of a model reply (tolerates ```json fences and chatter).
	 *
	 * @return array|null
	 */
	public static function json_from_model( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return null;
		}
		$data = json_decode( $text, true );
		if ( is_array( $data ) ) {
			return $data;
		}
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $text );
		$data = json_decode( $text, true );
		if ( is_array( $data ) ) {
			return $data;
		}
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		return null;
	}

	/** Multibyte-safe cut to $max characters. */
	public static function cut( $text, $max ) {
		$text = (string) $text;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max ) ) . '…' : $text;
		}
		return strlen( $text ) > $max ? substr( $text, 0, $max ) . '…' : $text;
	}

	public static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text ) : strlen( (string) $text );
	}

	/** Persian/Arabic digits → Latin, so numbers validate. */
	public static function latin_digits( $text ) {
		return strtr( (string) $text, array(
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
		) );
	}

	/** Normalise a mobile number; returns '' when it does not look like one. */
	public static function mobile( $value ) {
		$value = preg_replace( '/[^\d+]/', '', self::latin_digits( $value ) );
		if ( preg_match( '/^(?:\+98|0098|98)?0?(9\d{9})$/', $value, $m ) ) {
			return '0' . $m[1];
		}
		// International numbers: keep digits with a leading +, 8–15 digits.
		if ( preg_match( '/^\+?\d{8,15}$/', $value ) ) {
			return $value;
		}
		return '';
	}

	/** Send a plain RTL HTML email. */
	public static function mail( $to, $subject, $html ) {
		$body  = '<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;font-size:14px;line-height:1.9;color:#1f2937">';
		$body .= $html;
		$body .= '<hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0 12px">';
		$body .= '<p style="font-size:12px;color:#6b7280">' . esc_html( get_bloginfo( 'name' ) ) . ' — ' . esc_html( home_url( '/' ) ) . '</p></div>';

		return wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}
