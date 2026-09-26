<?php
/**
 * Client for the NetArz AI gateway (OpenAI-compatible, https://netarz.ir/api/ai/v1).
 *
 * This is the only place the plugin talks to the network. The API key never
 * leaves the WordPress server: browsers call the plugin's own REST routes and
 * the plugin calls NetArz.
 *
 * @package NetArz_AI
 */

defined( 'ABSPATH' ) || exit;

class Netarz_AI_Api {

	const BASE = 'https://netarz.ir/api/ai/v1';

	const TOPUP_URL = 'https://netarz.ir/ai/topup';

	const PANEL_URL = 'https://netarz.ir/ai';

	const DOCS_URL = 'https://netarz.ir/docs/ai';

	/** Endpoint base. NETARZ_AI_API_BASE exists for local development only. */
	public static function base() {
		if ( defined( 'NETARZ_AI_API_BASE' ) && is_string( NETARZ_AI_API_BASE ) && '' !== NETARZ_AI_API_BASE ) {
			return untrailingslashit( NETARZ_AI_API_BASE );
		}
		return self::BASE;
	}

	public static function has_key() {
		return '' !== (string) Netarz_AI_Settings::get( 'api_key' );
	}

	/** "sk-ntz-v1-ab12…9f" — enough to recognise, never enough to use. */
	public static function masked_key() {
		$key = (string) Netarz_AI_Settings::get( 'api_key' );
		if ( '' === $key ) {
			return '';
		}
		$head = substr( $key, 0, min( 14, strlen( $key ) ) );
		$tail = strlen( $key ) > 18 ? substr( $key, -4 ) : '';
		return $head . str_repeat( '•', 8 ) . $tail;
	}

	/**
	 * Low-level request.
	 *
	 * @param string     $method  GET|POST
	 * @param string     $path    path under /api/ai/v1, e.g. "/me"
	 * @param array|null $body    JSON body for POST
	 * @param array      $opts    timeout, auth (bool), query (array)
	 * @return array|WP_Error decoded JSON (+ "_request_id") or an error whose code is the gateway's
	 */
	public static function request( $method, $path, $body = null, array $opts = array() ) {
		$opts = wp_parse_args( $opts, array(
			'timeout' => 30,
			'auth'    => true,
			'query'   => array(),
		) );

		if ( $opts['auth'] && ! self::has_key() ) {
			return new WP_Error( 'missing_api_key', __( 'کلید API نِت اَرز هنوز وارد نشده است. از «هوش مصنوعی نِت اَرز ← تنظیمات» واردش کنید.', 'netarz-ai' ) );
		}

		$url = self::base() . $path;
		if ( $opts['query'] ) {
			$url = add_query_arg( array_map( 'rawurlencode', $opts['query'] ), $url );
		}

		$headers = array(
			'Accept'            => 'application/json',
			'User-Agent'        => 'NetArz-AI-WordPress/' . NETARZ_AI_VERSION . '; ' . home_url( '/' ),
			'X-NetArz-Client'   => 'wordpress/' . NETARZ_AI_VERSION,
		);
		if ( $opts['auth'] ) {
			$headers['Authorization'] = 'Bearer ' . Netarz_AI_Settings::get( 'api_key' );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => (int) $opts['timeout'],
			'headers'     => $headers,
			'redirection' => 0,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'network_error', sprintf(
				/* translators: %s: transport error */
				__( 'اتصال به سرور نِت اَرز برقرار نشد: %s', 'netarz-ai' ),
				$response->get_error_message()
			) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( $status >= 400 || ! is_array( $data ) ) {
			$code    = 'http_' . $status;
			$message = '';
			if ( is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ) {
				$code    = ! empty( $data['error']['code'] ) ? (string) $data['error']['code'] : $code;
				$message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';
			}
			if ( '' === $message ) {
				$message = $status >= 400
					/* translators: %d: HTTP status */
					? sprintf( __( 'سرور نِت اَرز خطای %d برگرداند.', 'netarz-ai' ), $status )
					: __( 'پاسخ سرور نِت اَرز قابل خواندن نبود.', 'netarz-ai' );
			}

			if ( 'insufficient_credit' === $code || 402 === $status ) {
				set_transient( 'netarz_ai_out_of_credit', 1, 10 * MINUTE_IN_SECONDS );
				delete_transient( 'netarz_ai_me' );
			}

			return new WP_Error( $code, $message, array( 'status' => $status ) );
		}

		$data['_request_id'] = (string) wp_remote_retrieve_header( $response, 'x-request-id' );

		return $data;
	}

	/**
	 * Account, balance and key details. Cached for five minutes.
	 *
	 * @return array|WP_Error
	 */
	public static function me( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( 'netarz_ai_me' );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$me = self::request( 'GET', '/me', null, array( 'timeout' => 15 ) );
		if ( ! is_wp_error( $me ) ) {
			set_transient( 'netarz_ai_me', $me, 5 * MINUTE_IN_SECONDS );
			if ( isset( $me['balance']['usd'] ) && (float) $me['balance']['usd'] > 0 ) {
				delete_transient( 'netarz_ai_out_of_credit' );
			}
		}
		return $me;
	}

	/** @return array|WP_Error */
	public static function usage( $from, $to, $group = 'day' ) {
		return self::request( 'GET', '/usage', null, array(
			'timeout' => 15,
			'query'   => array(
				'from'  => $from,
				'to'    => $to,
				'group' => $group,
			),
		) );
	}

	/**
	 * Public model catalogue (no key needed). Cached for six hours.
	 *
	 * @param string $type chat|image|embedding|audio …
	 * @return array[] each: id, name, type, capabilities, pricing, pricing_toman, is_free
	 */
	public static function models( $type = 'chat' ) {
		$type      = sanitize_key( $type );
		$cache_key = 'netarz_ai_models_' . $type;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$list = self::request( 'GET', '/models', null, array(
			'auth'    => false,
			'timeout' => 20,
			'query'   => array( 'type' => $type ),
		) );
		if ( is_wp_error( $list ) || empty( $list['data'] ) || ! is_array( $list['data'] ) ) {
			return array();
		}

		$models = array();
		foreach ( $list['data'] as $model ) {
			if ( ! is_array( $model ) || empty( $model['id'] ) ) {
				continue;
			}
			$models[] = array(
				'id'           => (string) $model['id'],
				'name'         => isset( $model['name'] ) ? (string) $model['name'] : (string) $model['id'],
				'type'         => isset( $model['type'] ) ? (string) $model['type'] : $type,
				'capabilities' => isset( $model['capabilities'] ) && is_array( $model['capabilities'] ) ? $model['capabilities'] : array(),
				'pricing'      => isset( $model['pricing'] ) && is_array( $model['pricing'] ) ? $model['pricing'] : array(),
				'pricing_toman' => isset( $model['pricing_toman'] ) && is_array( $model['pricing_toman'] ) ? $model['pricing_toman'] : array(),
				'is_free'      => ! empty( $model['is_free'] ),
			);
		}

		set_transient( $cache_key, $models, 6 * HOUR_IN_SECONDS );
		return $models;
	}

	/**
	 * Chat completion.
	 *
	 * @param array  $messages OpenAI-style messages
	 * @param array  $args     model, temperature, max_tokens, json (bool), feature (for the local log), timeout
	 * @return array|WP_Error  { content, model, usage, request_id }
	 */
	public static function chat( array $messages, array $args = array() ) {
		$args = wp_parse_args( $args, array(
			'model'       => Netarz_AI_Settings::model( 'default_model' ),
			'temperature' => 0.4,
			'max_tokens'  => 1200,
			'json'        => false,
			'feature'     => 'other',
			'timeout'     => 60,
		) );

		$body = array(
			'model'      => $args['model'],
			'messages'   => array_values( $messages ),
			'max_tokens' => (int) $args['max_tokens'],
		);
		if ( null !== $args['temperature'] ) {
			$body['temperature'] = (float) $args['temperature'];
		}
		if ( $args['json'] ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$result = self::request( 'POST', '/chat/completions', $body, array( 'timeout' => (int) $args['timeout'] ) );

		// Some models refuse temperature or json mode. Retry once without them.
		if ( is_wp_error( $result ) && self::is_parameter_error( $result ) ) {
			unset( $body['temperature'], $body['response_format'] );
			$result = self::request( 'POST', '/chat/completions', $body, array( 'timeout' => (int) $args['timeout'] ) );
		}

		if ( is_wp_error( $result ) ) {
			self::log( $args['feature'], $args['model'], 'chat', null, $result );
			return $result;
		}

		$content = '';
		if ( isset( $result['choices'][0]['message']['content'] ) ) {
			$content = $result['choices'][0]['message']['content'];
			if ( is_array( $content ) ) {
				// Content parts → plain text.
				$text = '';
				foreach ( $content as $part ) {
					if ( is_array( $part ) && isset( $part['text'] ) ) {
						$text .= $part['text'];
					}
				}
				$content = $text;
			}
		}
		$content = trim( (string) $content );

		$out = array(
			'content'    => $content,
			'model'      => isset( $result['model'] ) ? (string) $result['model'] : $args['model'],
			'usage'      => isset( $result['usage'] ) && is_array( $result['usage'] ) ? $result['usage'] : array(),
			'request_id' => $result['_request_id'],
		);

		self::log( $args['feature'], $args['model'], 'chat', $out, null );

		if ( '' === $content ) {
			return new WP_Error( 'empty_response', __( 'مدل پاسخی برنگرداند. دوباره امتحان کنید یا مدل دیگری انتخاب کنید.', 'netarz-ai' ) );
		}

		return $out;
	}

	/**
	 * Image generation. Returns raw PNG bytes.
	 *
	 * @return array|WP_Error { bytes, mime, request_id }
	 */
	public static function image( $prompt, array $args = array() ) {
		$args = wp_parse_args( $args, array(
			'model'   => Netarz_AI_Settings::get( 'image_model' ),
			'size'    => '1024x1024',
			'quality' => 'medium',
		) );

		$body = array(
			'model'   => $args['model'] ? $args['model'] : 'gpt-image-1-mini',
			'prompt'  => $prompt,
			'size'    => $args['size'],
			'quality' => $args['quality'],
			'n'       => 1,
		);

		$result = self::request( 'POST', '/images/generations', $body, array( 'timeout' => 180 ) );
		if ( is_wp_error( $result ) ) {
			self::log( 'image', $body['model'], 'images', null, $result );
			return $result;
		}

		$bytes = '';
		if ( ! empty( $result['data'][0]['b64_json'] ) ) {
			$bytes = base64_decode( (string) $result['data'][0]['b64_json'], true );
		} elseif ( ! empty( $result['data'][0]['url'] ) ) {
			$download = wp_remote_get( esc_url_raw( $result['data'][0]['url'] ), array( 'timeout' => 60 ) );
			if ( ! is_wp_error( $download ) && 200 === (int) wp_remote_retrieve_response_code( $download ) ) {
				$bytes = wp_remote_retrieve_body( $download );
			}
		}

		$usage = isset( $result['usage'] ) && is_array( $result['usage'] ) ? $result['usage'] : array();
		self::log( 'image', $body['model'], 'images', array( 'usage' => $usage, 'request_id' => $result['_request_id'] ), null );

		if ( ! $bytes ) {
			return new WP_Error( 'empty_image', __( 'تصویری برنگشت. دوباره امتحان کنید.', 'netarz-ai' ) );
		}

		$info = function_exists( 'getimagesizefromstring' ) ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$mime = $info && ! empty( $info['mime'] ) ? $info['mime'] : 'image/png';

		return array(
			'bytes'      => $bytes,
			'mime'       => $mime,
			'request_id' => $result['_request_id'],
		);
	}

	private static function is_parameter_error( WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		if ( 400 !== $status ) {
			return false;
		}
		$text = strtolower( $error->get_error_code() . ' ' . $error->get_error_message() );
		return false !== strpos( $text, 'temperature' ) || false !== strpos( $text, 'response_format' ) || false !== strpos( $text, 'unsupported' );
	}

	/**
	 * Record one call in the local usage log (metadata only, never the prompt).
	 *
	 * @param array|null    $ok
	 * @param WP_Error|null $error
	 */
	public static function log( $feature, $model, $endpoint, $ok, $error ) {
		global $wpdb;

		$usage = is_array( $ok ) && isset( $ok['usage'] ) && is_array( $ok['usage'] ) ? $ok['usage'] : array();
		$in    = isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : ( isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0 );
		$out   = isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : ( isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0 );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'netarz_ai_log',
			array(
				'feature'       => substr( sanitize_key( $feature ), 0, 40 ),
				'model'         => substr( (string) $model, 0, 120 ),
				'endpoint'      => substr( (string) $endpoint, 0, 40 ),
				'status'        => $error ? 'error' : 'ok',
				'error_code'    => $error ? substr( (string) $error->get_error_code(), 0, 60 ) : '',
				'input_tokens'  => $in,
				'output_tokens' => $out,
				'request_id'    => is_array( $ok ) && isset( $ok['request_id'] ) ? substr( (string) $ok['request_id'], 0, 64 ) : '',
				'user_id'       => get_current_user_id(),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s' )
		);
	}

	/** A message an admin can act on, for a gateway error. */
	public static function friendly_error( WP_Error $error ) {
		switch ( $error->get_error_code() ) {
			case 'insufficient_credit':
				return __( 'اعتبار هوش مصنوعی حساب نِت اَرز تمام شده است. از پنل نِت اَرز اعتبار بخرید.', 'netarz-ai' );
			case 'invalid_api_key':
			case 'api_key_revoked':
			case 'api_key_expired':
			case 'api_key_disabled':
			case 'missing_api_key':
				return $error->get_error_message() . ' ' . __( 'کلید را در تنظیمات افزونه بررسی کنید.', 'netarz-ai' );
			case 'rate_limit_exceeded':
				return __( 'تعداد درخواست‌ها در این دقیقه از سقف کلید بیشتر شد. کمی بعد دوباره امتحان کنید.', 'netarz-ai' );
			default:
				return $error->get_error_message();
		}
	}
}
