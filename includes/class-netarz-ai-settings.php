<?php
/**
 * Plugin settings: one option array, one schema.
 *
 * Every field is declared once in schema() with its type and default, so
 * sanitising, defaults and the settings screen all read the same table.
 *
 * @package NetArz_AI
 */

defined( 'ABSPATH' ) || exit;

class Netarz_AI_Settings {

	const OPTION = 'netarz_ai_settings';

	/** @var array|null */
	private static $cache = null;

	/**
	 * Field schema, grouped by settings tab.
	 *
	 * type: text | textarea | bool | int | color | select | api_key | model | email_list
	 *
	 * @return array<string, array<string, array>>
	 */
	public static function schema() {
		return array(
			'general'  => array(
				'api_key'              => array( 'type' => 'api_key', 'default' => '' ),
				'default_model'        => array( 'type' => 'model', 'default' => 'gpt-4.1-mini', 'model_type' => 'chat' ),
				'low_credit_usd'       => array( 'type' => 'float', 'default' => 1, 'min' => 0, 'max' => 10000 ),
				'site_description'     => array( 'type' => 'textarea', 'default' => '' ),
			),
			'chat'     => array(
				'chat_enabled'          => array( 'type' => 'bool', 'default' => false ),
				'chat_ai_enabled'       => array( 'type' => 'bool', 'default' => true ),
				'chat_model'            => array( 'type' => 'model', 'default' => '', 'model_type' => 'chat' ),
				'chat_agent_name'       => array( 'type' => 'text', 'default' => 'پشتیبانی' ),
				'chat_title'            => array( 'type' => 'text', 'default' => 'پشتیبانی آنلاین' ),
				'chat_welcome'          => array( 'type' => 'textarea', 'default' => 'سلام، در خدمتیم. سؤالتان را بنویسید تا همین‌جا جواب بدهیم.' ),
				'chat_color'            => array( 'type' => 'color', 'default' => '#f5b301' ),
				'chat_text_color'       => array( 'type' => 'color', 'default' => '#1a1a2e' ),
				'chat_position'         => array( 'type' => 'select', 'default' => 'right', 'options' => array( 'right', 'left' ) ),
				'chat_offset_bottom'    => array( 'type' => 'int', 'default' => 20, 'min' => 0, 'max' => 400 ),
				'chat_ask_name'         => array( 'type' => 'select', 'default' => 'optional', 'options' => array( 'off', 'optional', 'required' ) ),
				'chat_ask_contact'      => array( 'type' => 'select', 'default' => 'mobile_or_email', 'options' => array( 'off', 'optional', 'mobile_or_email', 'mobile', 'email' ) ),
				'chat_register'         => array( 'type' => 'select', 'default' => 'friendly', 'options' => array( 'friendly', 'formal' ) ),
				'chat_instructions'     => array( 'type' => 'textarea', 'default' => '' ),
				'chat_knowledge'        => array( 'type' => 'textarea', 'default' => '' ),
				'chat_use_content'      => array( 'type' => 'bool', 'default' => true ),
				'chat_content_types'    => array( 'type' => 'text', 'default' => 'post,page,product' ),
				'chat_min_confidence'   => array( 'type' => 'int', 'default' => 55, 'min' => 0, 'max' => 100 ),
				'chat_pause_on_agent'   => array( 'type' => 'bool', 'default' => true ),
				'chat_max_ai_replies'   => array( 'type' => 'int', 'default' => 30, 'min' => 1, 'max' => 500 ),
				'chat_daily_ai_limit'   => array( 'type' => 'int', 'default' => 500, 'min' => 1, 'max' => 100000 ),
				'chat_hourly_starts'    => array( 'type' => 'int', 'default' => 10, 'min' => 1, 'max' => 1000 ),
				'chat_max_length'       => array( 'type' => 'int', 'default' => 1500, 'min' => 100, 'max' => 5000 ),
				'chat_teaser_enabled'   => array( 'type' => 'bool', 'default' => true ),
				'chat_teaser_delay'     => array( 'type' => 'int', 'default' => 25, 'min' => 3, 'max' => 600 ),
				'chat_teaser_text'      => array( 'type' => 'text', 'default' => 'سؤالی دارید؟ همین‌جا بپرسید.' ),
				'chat_sound'            => array( 'type' => 'bool', 'default' => true ),
				'chat_hours_enabled'    => array( 'type' => 'bool', 'default' => false ),
				'chat_hours_start'      => array( 'type' => 'time', 'default' => '09:00' ),
				'chat_hours_end'        => array( 'type' => 'time', 'default' => '21:00' ),
				'chat_hours_days'       => array( 'type' => 'days', 'default' => '6,0,1,2,3' ),
				'chat_offline_text'     => array( 'type' => 'textarea', 'default' => 'الان همکارانمان آنلاین نیستند. پیامتان را بگذارید؛ در اولین ساعت کاری جواب می‌دهیم.' ),
				'chat_hide_on'          => array( 'type' => 'textarea', 'default' => '' ),
				'chat_hide_mobile'      => array( 'type' => 'bool', 'default' => false ),
				'chat_notify_emails'    => array( 'type' => 'email_list', 'default' => '' ),
				'chat_notify_on'        => array( 'type' => 'select', 'default' => 'handoff', 'options' => array( 'off', 'handoff', 'all' ) ),
				'chat_retention_days'   => array( 'type' => 'int', 'default' => 180, 'min' => 0, 'max' => 3650 ),
			),
			'tickets'  => array(
				'tickets_enabled'       => array( 'type' => 'bool', 'default' => true ),
				'tickets_guest'         => array( 'type' => 'bool', 'default' => true ),
				'tickets_departments'   => array( 'type' => 'textarea', 'default' => "پشتیبانی فنی\nفروش\nمالی" ),
				'tickets_ai_mode'       => array( 'type' => 'select', 'default' => 'draft', 'options' => array( 'off', 'draft', 'auto' ) ),
				'tickets_ai_model'      => array( 'type' => 'model', 'default' => '', 'model_type' => 'chat' ),
				'tickets_ai_confidence' => array( 'type' => 'int', 'default' => 75, 'min' => 0, 'max' => 100 ),
				'tickets_ai_daily_limit' => array( 'type' => 'int', 'default' => 200, 'min' => 1, 'max' => 100000 ),
				'tickets_ai_per_ticket' => array( 'type' => 'int', 'default' => 8, 'min' => 1, 'max' => 100 ),
				'tickets_attachments'   => array( 'type' => 'bool', 'default' => true ),
				'tickets_max_file_mb'   => array( 'type' => 'int', 'default' => 5, 'min' => 1, 'max' => 50 ),
				'tickets_file_types'    => array( 'type' => 'text', 'default' => 'jpg,jpeg,png,gif,webp,pdf,zip,txt' ),
				'tickets_notify_emails' => array( 'type' => 'email_list', 'default' => '' ),
				'tickets_auto_close'    => array( 'type' => 'int', 'default' => 7, 'min' => 0, 'max' => 365 ),
				'tickets_canned'        => array( 'type' => 'textarea', 'default' => '' ),
				'tickets_page_id'       => array( 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => PHP_INT_MAX ),
				'tickets_woo_account'   => array( 'type' => 'bool', 'default' => true ),
				'tickets_signature'     => array( 'type' => 'textarea', 'default' => '' ),
			),
			'writer'   => array(
				'writer_enabled'        => array( 'type' => 'bool', 'default' => true ),
				'writer_model'          => array( 'type' => 'model', 'default' => '', 'model_type' => 'chat' ),
				'writer_language'       => array( 'type' => 'text', 'default' => 'فارسی' ),
				'writer_tone'           => array( 'type' => 'select', 'default' => 'friendly', 'options' => array( 'friendly', 'formal', 'expert', 'persuasive', 'simple' ) ),
				'writer_seo_meta'       => array( 'type' => 'bool', 'default' => true ),
				'image_model'           => array( 'type' => 'model', 'default' => 'gpt-image-1-mini', 'model_type' => 'image' ),
				'vision_model'          => array( 'type' => 'model', 'default' => 'gpt-4.1-mini', 'model_type' => 'chat' ),
				'comments_ai_reply'     => array( 'type' => 'bool', 'default' => true ),
			),
			'advanced' => array(
				'role_support'          => array( 'type' => 'text', 'default' => 'administrator,editor,shop_manager' ),
				'role_writer'           => array( 'type' => 'text', 'default' => 'administrator,editor,author' ),
				'log_retention_days'    => array( 'type' => 'int', 'default' => 90, 'min' => 1, 'max' => 3650 ),
				'delete_on_uninstall'   => array( 'type' => 'bool', 'default' => false ),
			),
		);
	}

	/** @return array<string, array> field name → definition, flattened */
	public static function fields() {
		$out = array();
		foreach ( self::schema() as $tab => $fields ) {
			foreach ( $fields as $name => $def ) {
				$def['tab']   = $tab;
				$out[ $name ] = $def;
			}
		}
		return $out;
	}

	/** @return array<string, mixed> */
	public static function defaults() {
		$out = array();
		foreach ( self::fields() as $name => $def ) {
			$out[ $name ] = $def['default'];
		}
		return $out;
	}

	/** @return array<string, mixed> */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * @param string $key
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/** Resolve the model for a feature: its own setting, or the general default. */
	public static function model( $key ) {
		$model = (string) self::get( $key );
		return '' !== $model ? $model : (string) self::get( 'default_model' );
	}

	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Merge the submitted fields of one tab into the stored settings.
	 *
	 * Only fields of $tab are touched, so saving one tab never resets another.
	 *
	 * @param string $tab
	 * @param array  $input raw $_POST values (already unslashed)
	 */
	public static function save_tab( $tab, array $input ) {
		$schema = self::schema();
		if ( ! isset( $schema[ $tab ] ) ) {
			return;
		}

		$current = self::all();
		foreach ( $schema[ $tab ] as $name => $def ) {
			if ( 'api_key' === $def['type'] ) {
				$raw = isset( $input[ $name ] ) ? trim( (string) $input[ $name ] ) : '';
				// The field shows a mask; an untouched mask keeps the stored key.
				if ( '' === $raw || false !== strpos( $raw, '•' ) ) {
					if ( ! empty( $input[ $name . '_clear' ] ) ) {
						$current[ $name ] = '';
					}
					continue;
				}
				$current[ $name ] = self::sanitize_value( $def, $raw );
				continue;
			}
			$current[ $name ] = self::sanitize_value( $def, isset( $input[ $name ] ) ? $input[ $name ] : null );
		}

		update_option( self::OPTION, $current, true );
		self::flush();
	}

	/**
	 * @param array $def
	 * @param mixed $value
	 * @return mixed
	 */
	public static function sanitize_value( array $def, $value ) {
		switch ( $def['type'] ) {
			case 'bool':
				return ! empty( $value );

			case 'int':
				$value = is_numeric( $value ) ? (int) $value : (int) $def['default'];
				return max( (int) $def['min'], min( (int) $def['max'], $value ) );

			case 'float':
				$value = is_numeric( $value ) ? (float) $value : (float) $def['default'];
				return max( (float) $def['min'], min( (float) $def['max'], $value ) );

			case 'color':
				$color = sanitize_hex_color( (string) $value );
				return $color ? $color : $def['default'];

			case 'select':
				return in_array( (string) $value, $def['options'], true ) ? (string) $value : $def['default'];

			case 'time':
				return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value ) ? (string) $value : $def['default'];

			case 'days':
				// Days are PHP's date('w'): 0 = Sunday … 6 = Saturday.
				$days = is_array( $value ) ? $value : explode( ',', (string) $value );
				$keep = array();
				foreach ( $days as $day ) {
					$day = trim( (string) $day );
					if ( '' !== $day && ctype_digit( $day ) && (int) $day <= 6 ) {
						$keep[ (int) $day ] = (int) $day;
					}
				}
				return implode( ',', array_values( $keep ) );

			case 'api_key':
				return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value );

			case 'model':
				return preg_replace( '/[^A-Za-z0-9_.:\/\-]/', '', (string) $value );

			case 'email_list':
				$emails = preg_split( '/[\s,;]+/', (string) $value );
				$emails = array_filter( array_map( 'sanitize_email', (array) $emails ), 'is_email' );
				return implode( ', ', array_unique( $emails ) );

			case 'textarea':
				// Not sanitize_textarea_field(): it strips %XX from pasted URLs. Always escaped on output.
				return Netarz_AI_Util::clean_text( wp_strip_all_tags( (string) $value ), 20000 );

			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/** Departments as a clean list. @return string[] */
	public static function departments() {
		$lines = preg_split( '/\r\n|\r|\n/', (string) self::get( 'tickets_departments' ) );
		$lines = array_values( array_unique( array_filter( array_map( 'trim', $lines ) ) ) );
		return $lines ? $lines : array( __( 'پشتیبانی', 'netarz-ai' ) );
	}

	/** Canned replies: one per line, "title | text". @return array<int, array{title:string, text:string}> */
	public static function canned_replies() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) self::get( 'tickets_canned' ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			$out[] = array(
				'title' => $parts[0],
				'text'  => isset( $parts[1] ) && '' !== $parts[1] ? str_replace( '\n', "\n", $parts[1] ) : $parts[0],
			);
		}
		return $out;
	}

	/** Email list setting → array, falling back to the site admin. @return string[] */
	public static function emails( $key ) {
		$list = array_filter( array_map( 'trim', explode( ',', (string) self::get( $key ) ) ), 'is_email' );
		return $list ? array_values( $list ) : array( get_option( 'admin_email' ) );
	}

	/** Comma list → clean array of slugs. @return string[] */
	public static function csv( $key ) {
		return array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) self::get( $key ) ) ) ) );
	}
}
