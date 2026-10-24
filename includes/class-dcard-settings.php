<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings > DCard AI - TWO independent model selectors (one per reading
 * mode) + API keys.
 *
 * "קבלת מסר" (Direct Message) and "שיחה אישית" (Personal Chat) can now each
 * run a different model - e.g. a cheaper/faster model for the one-shot
 * message and a stronger one for the back-and-forth chat, or the reverse,
 * for A/B testing. DCard_AI_Gateway::run_turn() takes a $reading_mode
 * argument ('direct' | 'chat') and resolves the model for that mode via
 * get_model_for_mode() below - the provider (which endpoint/payload shape)
 * is then derived from *which* model that resolves to, via get_model_choices()
 * (loaded from data/ai-models.json - see that method's docblock). Swapping
 * either model, or adding a brand new one, is a Settings page + JSON-file
 * change, not a deploy.
 *
 * Anthropic options are `claude-haiku-4-5` and `claude-sonnet-5` - the
 * current model IDs, not the old dated snapshots (`claude-3-haiku-20240307`,
 * `claude-3-5-sonnet-20240620`) that were tried earlier in this project and
 * confirmed broken: Anthropic returns a live 404 not_found_error for both,
 * whose message is literally "model: <that id>" - that exact string showed
 * up in the chat bubble, and later in a WooCommerce log entry. Don't add
 * those two id's back without confirming Anthropic has un-retired them.
 *
 * API keys are stored in wp_options (encrypted at rest is out of scope for
 * the beta). If you'd rather keep them out of the database entirely, define
 * DCARD_ANTHROPIC_API_KEY / DCARD_OPENAI_API_KEY as constants in
 * wp-config.php instead - DCard_Settings::get_api_key() prefers the
 * constant over the stored option when both exist.
 */
class DCard_Settings {

	const OPTION_GROUP   = 'dcard_ai_settings';
	const OPTION_MODEL_DIRECT = 'dcard_ai_model_direct'; // קבלת מסר
	const OPTION_MODEL_CHAT   = 'dcard_ai_model_chat';   // שיחה אישית
	const OPTION_ANTHROPIC_KEY = 'dcard_anthropic_api_key';
	const OPTION_OPENAI_KEY    = 'dcard_openai_api_key';
	const OPTION_GEMINI_KEY    = 'dcard_gemini_api_key';
	const OPTION_CLOSING_NEW_USER = 'dcard_closing_text_new_user';
	const OPTION_CLOSING_REGISTERED = 'dcard_closing_text_registered';
	const OPTION_FORCE_CARD = 'dcard_force_card_id'; // A/B testing: '' = random draw
	const OPTION_TEST_USER_NAME   = 'dcard_test_user_name';   // בדיקות: '' = כמו שנשלח מהלקוח
	const OPTION_TEST_USER_GENDER = 'dcard_test_user_gender'; // בדיקות: '' = כמו שנשלח מהלקוח
	const OPTION_MARKETING_TEXT  = 'dcard_marketing_email_text';  // פסקה שיווקית בסוף מייל הסיכום
	const OPTION_MARKETING_IMAGE = 'dcard_marketing_email_image'; // URL אופציונלי, תמונה מעל הפסקה
	const OPTION_ANALYTICS_WEBHOOK = 'dcard_analytics_webhook_url'; // בדיקות בטא: '' = לא נשלח כלום (DCard_Analytics)
	const OPTION_FEEDBACK_FORM_URL = 'dcard_feedback_form_url'; // בדיקות בטא: '' = בלי קריאה למשוב במייל הסיכום

	// נשאר קבוע בקוד בכוונה (לא בקובץ החוץ) - רשת ביטחון אם ai-models.json
	// חסר/פגום לגמרי (ר' get_model_choices() למטה): גם אז צריך מודל קיים
	// אחד שמובטח לעבוד, לא מפתח שתלוי בקובץ שבדיוק נכשל לטעון.
	const DEFAULT_MODEL = 'gpt-6-luna';
	// תיקון: רשת הביטחון למטה (get_model_choices(), כש-ai-models.json כשל
	// לגמרי) הייתה מניחה בקביעות שה-provider של DEFAULT_MODEL הוא claude -
	// נכון כל עוד DEFAULT_MODEL היה אנתרופיק, אבל שגוי עכשיו (gpt-6-luna
	// הוא OpenAI). אם זה נשאר שגוי ורשת הביטחון אי-פעם באמת מופעלת, הקריאה
	// הייתה יוצאת ל-API הלא נכון (claude עם מחרוזת מודל של OpenAI) ונכשלת
	// מיד. קבוע נפרד, מעודכן יחד עם DEFAULT_MODEL בכל פעם שהוא משתנה.
	const DEFAULT_MODEL_PROVIDER = 'openai';

	const SUPPORTED_PROVIDERS = array( 'claude', 'openai', 'gemini' );

	private static $model_choices        = null;
	private static $model_choices_errors = array();

	/**
	 * רשימת מודלי ה-AI - נטענת מ-data/ai-models.json, לא מקובעת בקוד.
	 * זו הדרך היחידה שצריך לגעת בה כדי להוסיף/להסיר/לעדכן מודל ל-A/B
	 * test (גם dropdown-ים, גם ניתוב ה-provider, גם payload shape ב-
	 * DCard_AI_Gateway קוראים מכאן) - עריכת ה-JSON מספיקה, בלי deploy.
	 *
	 * כל רשומה לא תקינה (id/label/provider חסר, provider לא מוכר, id
	 * כפול) נפסלת *בנפרד* לתוך $model_choices_errors, לא מפילה את כל
	 * הקובץ - כדי שטעות בודדת לא תשבית את כל הבחירה. אם אף רשומה לא
	 * שרדה (או שהקובץ עצמו חסר/JSON פגום) - נופלים לרשת ביטחון עם
	 * DEFAULT_MODEL לבדו, עם שגיאה בולטת שמסבירה למה (ר'
	 * render_model_choices_errors_notice() - admin_notice גלובלי, לא רק
	 * בעמוד ההגדרות עצמו, כדי שהתקלה תתגלה מיידית כמו שהתבקש).
	 */
	public static function get_model_choices() {
		if ( null !== self::$model_choices ) {
			return self::$model_choices;
		}

		self::$model_choices_errors = array();
		$choices = array();

		$path = DCARD_PLUGIN_DIR . 'data/ai-models.json';
		if ( ! file_exists( $path ) ) {
			self::$model_choices_errors[] = 'קובץ רשימת המודלים (' . $path . ') לא נמצא.';
		} else {
			$decoded = json_decode( (string) file_get_contents( $path ), true );

			if ( null === $decoded || ! isset( $decoded['models'] ) || ! is_array( $decoded['models'] ) ) {
				self::$model_choices_errors[] = 'קובץ רשימת המודלים (' . $path . ') לא תקין (JSON שגוי, או חסר מפתח "models").';
			} else {
				foreach ( $decoded['models'] as $index => $entry ) {
					$where = 'רשומה מספר ' . ( (int) $index + 1 );

					if ( ! is_array( $entry ) ) {
						self::$model_choices_errors[] = $where . ' ב-ai-models.json אינה אובייקט תקין - דילוג.';
						continue;
					}

					$id       = isset( $entry['id'] ) ? trim( (string) $entry['id'] ) : '';
					$label    = isset( $entry['label'] ) ? trim( (string) $entry['label'] ) : '';
					$provider = isset( $entry['provider'] ) ? trim( (string) $entry['provider'] ) : '';
					$verified = ! isset( $entry['verified'] ) || (bool) $entry['verified'];

					if ( '' === $id || '' === $label || '' === $provider ) {
						self::$model_choices_errors[] = $where . ' (' . ( $id ? $id : '?' ) . ') חסרה שדה חובה (id/label/provider) - דילוג.';
						continue;
					}

					if ( ! in_array( $provider, self::SUPPORTED_PROVIDERS, true ) ) {
						self::$model_choices_errors[] = 'מודל "' . $id . '" - provider לא מוכר ("' . $provider . '", צריך להיות אחד מ-' . implode( '/', self::SUPPORTED_PROVIDERS ) . ') - דילוג.';
						continue;
					}

					if ( isset( $choices[ $id ] ) ) {
						self::$model_choices_errors[] = 'מודל "' . $id . '" מופיע יותר מפעם אחת בקובץ - רק המופע הראשון נשמר.';
						continue;
					}

					$choices[ $id ] = array(
						'label'    => $verified ? $label : ( $label . ' (לא מאומת)' ),
						'provider' => $provider,
					);
				}
			}
		}

		if ( empty( $choices ) ) {
			self::$model_choices_errors[] = 'אף מודל תקין לא נטען מהקובץ - חוזרים לברירת המחדל היחידה (' . self::DEFAULT_MODEL . ') כרשת ביטחון, עד שהקובץ יתוקן.';
			$choices[ self::DEFAULT_MODEL ] = array(
				'label'    => self::DEFAULT_MODEL . ' (ברירת מחדל קשיחה - תקן את ai-models.json)',
				'provider' => self::DEFAULT_MODEL_PROVIDER,
			);
		} elseif ( ! isset( $choices[ self::DEFAULT_MODEL ] ) ) {
			// DEFAULT_MODEL קבוע בקוד - אם מי שעורך את ai-models.json מוחק
			// את המודל הזה ספציפית בלי לעדכן גם את הקבוע הזה, עדיין עדיף
			// להזהיר מאשר "לשקוע" בשקט למפתח שלא קיים ברשימה שכן נטענה.
			self::$model_choices_errors[] = 'ברירת המחדל הקבועה בקוד (' . self::DEFAULT_MODEL . ') לא קיימת ברשימה שבקובץ - מומלץ להוסיף אותה בחזרה או לעדכן את DEFAULT_MODEL בקוד.';
		}

		self::$model_choices = $choices;
		return self::$model_choices;
	}

	/**
	 * שגיאות טעינה/ולידציה שנצברו ב-get_model_choices() - נקראת זו תמיד
	 * קודם (אם עדיין לא רצה בבקשה הזו) כדי שיהיה מה להציג.
	 */
	public static function get_model_choices_errors() {
		if ( null === self::$model_choices ) {
			self::get_model_choices();
		}
		return self::$model_choices_errors;
	}

	/**
	 * admin_notice גלובלי (מחווט ב-the-card-reader-core.php, לא רק בעמוד
	 * Settings > DCard AI) - כדי שתקלה ב-ai-models.json תתגלה "מיידית",
	 * כבקשה, לא רק כשמישהו נכנס במקרה לעמוד ההגדרות.
	 */
	public static function render_model_choices_errors_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$errors = self::get_model_choices_errors();
		if ( empty( $errors ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>DCard AI - שגיאה בטעינת רשימת המודלים (data/ai-models.json):</strong></p><ul style="margin-right:20px;list-style:disc;">';
		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	// תיקון איחוד תפריטים: קודם add_options_page() - תת-תפריט של Settings,
	// "נעלם" מהמשתמש כי הוא לא מצפה לחפש הגדרות AI שם. עכשיו תת-תפריט
	// של ה-CPT "הקורא בקלפים" (projective_card, ר' class-cards-manager.php)
	// עצמו - בדיוק לצד "כל הקלפים"/"הוספת קלף חדש" שה-CPT כבר יוצר אוטומטית.
	public static function register_menu() {
		add_submenu_page(
			'edit.php?post_type=projective_card',
			'הגדרות AI',
			'הגדרות AI',
			'manage_options',
			'dcard-ai-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings() {
		$model_sanitizer = function ( $value ) {
			return isset( self::get_model_choices()[ $value ] ) ? $value : self::DEFAULT_MODEL;
		};

		register_setting( self::OPTION_GROUP, self::OPTION_MODEL_DIRECT, array(
			'sanitize_callback' => $model_sanitizer,
			'default'           => self::DEFAULT_MODEL,
		) );

		register_setting( self::OPTION_GROUP, self::OPTION_MODEL_CHAT, array(
			'sanitize_callback' => $model_sanitizer,
			'default'           => self::DEFAULT_MODEL,
		) );

		register_setting( self::OPTION_GROUP, self::OPTION_FORCE_CARD, array(
			'sanitize_callback' => function ( $value ) {
				$value = trim( (string) $value );
				if ( '' === $value ) {
					return '';
				}
				$id = absint( $value );
				return ( $id >= 1 && $id <= 72 ) ? $id : '';
			},
			'default' => '',
		) );

		register_setting( self::OPTION_GROUP, self::OPTION_TEST_USER_NAME, array(
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );

		register_setting( self::OPTION_GROUP, self::OPTION_TEST_USER_GENDER, array(
			'sanitize_callback' => function ( $value ) {
				return in_array( $value, array( 'male', 'female' ), true ) ? $value : '';
			},
			'default' => '',
		) );

		register_setting( self::OPTION_GROUP, self::OPTION_ANTHROPIC_KEY, array(
			'sanitize_callback' => 'sanitize_text_field',
		) );

		register_setting( self::OPTION_GROUP, self::OPTION_OPENAI_KEY, array(
			'sanitize_callback' => 'sanitize_text_field',
		) );

		register_setting( self::OPTION_GROUP, self::OPTION_GEMINI_KEY, array(
			'sanitize_callback' => 'sanitize_text_field',
		) );

		register_setting( self::OPTION_GROUP, self::OPTION_CLOSING_NEW_USER, array(
			'sanitize_callback' => 'sanitize_textarea_field',
		) );

		register_setting( self::OPTION_GROUP, self::OPTION_CLOSING_REGISTERED, array(
			'sanitize_callback' => 'sanitize_textarea_field',
		) );

		register_setting( self::OPTION_GROUP, self::OPTION_MARKETING_TEXT, array(
			// wp_kses_post (not sanitize_textarea_field) on purpose: this field is
			// meant to hold a real <a href="..."> link to the shop, since there's
			// no separate "shop URL" field - see get_marketing_text() below.
			'sanitize_callback' => 'wp_kses_post',
		) );

		register_setting( self::OPTION_GROUP, self::OPTION_MARKETING_IMAGE, array(
			'sanitize_callback' => 'esc_url_raw',
		) );

		// תיקון QA 1.1: שני השדות האלה שולחים PII בפועל (שם, מייל, תמלול שיחה
		// מלא ב-Analytics Webhook) - אסור שזה ייצא ב-http:// בטקסט גלוי. דוחה
		// ערך שאינו https://, ושומר את הערך הקודם במקום למחוק אותו לריק (כדי
		// שטעות הקלדה לא תשבור webhook שכבר מוגדר ועובד).
		register_setting( self::OPTION_GROUP, self::OPTION_ANALYTICS_WEBHOOK, array(
			'sanitize_callback' => function ( $value ) {
				return self::sanitize_https_url( $value, self::OPTION_ANALYTICS_WEBHOOK, 'Analytics Webhook URL' );
			},
		) );

		register_setting( self::OPTION_GROUP, self::OPTION_FEEDBACK_FORM_URL, array(
			'sanitize_callback' => function ( $value ) {
				return self::sanitize_https_url( $value, self::OPTION_FEEDBACK_FORM_URL, 'Feedback Form URL' );
			},
		) );
	}

	/**
	 * Shared by the two sanitizers above. '' is always allowed (field left
	 * empty = feature off). Anything non-empty must survive esc_url_raw()
	 * AND start with https:// - otherwise the submitted value is discarded
	 * and whatever was already saved is kept, with an admin notice
	 * explaining why.
	 */
	private static function sanitize_https_url( $value, $option_name, $label ) {
		$value = esc_url_raw( trim( (string) $value ) );

		if ( '' === $value ) {
			return '';
		}

		if ( 0 !== strpos( $value, 'https://' ) ) {
			$message = sprintf( '%s חייב להתחיל ב-https:// - הערך שהוזן לא נשמר, והוחזר הערך הקודם. השדה הזה שולח מידע אישי (שם, מייל, ולעיתים תמליל שיחה מלא), שאסור שייצא בטקסט גלוי דרך http.', $label );

			// add_settings_error() + settings_errors() הוא המנגנון המובנה של
			// וורדפרס, אבל הוא נשען על טרנזיינט (`settings_errors`) שעמוד
			// options.php אמור למלא לפני ה-redirect - מנגנון שידוע כלא אמין
			// בעקביות בעמודי הגדרות מותאמים אישית (add_options_page(), לא
			// options-general.php המובנה). נבדק בפועל: ההודעה לא הוצגה בכלל
			// אחרי השמירה. במקום להמר שוב על אותו מנגנון, שומרים גם בטרנזיינט
			// עצמאי משלנו (מפתח לפי משתמש) שנבדק ישירות ב-render_page() - לא
			// תלוי בשום דבר חיצוני, מובטח להציג את ההודעה. add_settings_error()
			// נשאר כשכבה נוספת לא-מזיקה, למקרה שהמנגנון המובנה כן יעבוד.
			add_settings_error( $option_name, $option_name . '_not_https', $message );
			self::add_notice( $message );

			return get_option( $option_name, '' );
		}

		return $value;
	}

	/**
	 * רשימת הודעות ממתינות להצגה (טרנזיינט עצמאי, ר' sanitize_https_url
	 * למעלה) - מערך ולא מחרוזת בודדת, כדי שדחיית שני שדות באותה שמירה
	 * תציג שתי הודעות נפרדות ולא תדרוס אחת את השנייה.
	 */
	private static function notice_transient_key() {
		return 'dcard_settings_notices_' . get_current_user_id();
	}

	private static function add_notice( $message ) {
		$notices   = get_transient( self::notice_transient_key() );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = $message;
		set_transient( self::notice_transient_key(), $notices, 60 );
	}

	/**
	 * קוראת ומנקה את ההודעות הממתינות - נקראת פעם אחת מ-render_page().
	 */
	private static function take_notices() {
		$key     = self::notice_transient_key();
		$notices = get_transient( $key );
		delete_transient( $key );
		return is_array( $notices ) ? $notices : array();
	}

	/**
	 * The exact model string DCard_AI_Gateway sends in the API payload for a
	 * given reading mode - always one of the get_model_choices() keys.
	 *
	 * @param string $reading_mode 'direct' (קבלת מסר) | 'chat' (שיחה אישית)
	 */
	public static function get_model_for_mode( $reading_mode ) {
		$option = ( 'direct' === $reading_mode ) ? self::OPTION_MODEL_DIRECT : self::OPTION_MODEL_CHAT;
		$model  = get_option( $option, self::DEFAULT_MODEL );
		return isset( self::get_model_choices()[ $model ] ) ? $model : self::DEFAULT_MODEL;
	}

	/**
	 * 'claude' | 'openai' - which endpoint/payload shape a given model needs.
	 * Falls back to 'claude' for a model string that isn't in the list
	 * (e.g. a stale value from before an option was removed).
	 */
	public static function get_provider_for_model( $model ) {
		return isset( self::get_model_choices()[ $model ] ) ? self::get_model_choices()[ $model ]['provider'] : 'claude';
	}

	/**
	 * Convenience wrapper - the provider implied by the model configured for
	 * a reading mode. Used wherever only the provider matters (API key
	 * lookup), not the exact model string. Defaults to the chat model when
	 * no mode is given (e.g. DCard_Mailer's fallback for a session with no
	 * recorded AI replies yet).
	 */
	public static function get_active_provider( $reading_mode = 'chat' ) {
		return self::get_provider_for_model( self::get_model_for_mode( $reading_mode ) );
	}

	/**
	 * @param string $provider 'claude' | 'openai' | 'gemini'
	 */
	public static function get_api_key( $provider ) {
		if ( 'claude' === $provider ) {
			if ( defined( 'DCARD_ANTHROPIC_API_KEY' ) && DCARD_ANTHROPIC_API_KEY ) {
				return DCARD_ANTHROPIC_API_KEY;
			}
			return get_option( self::OPTION_ANTHROPIC_KEY, '' );
		}

		if ( 'gemini' === $provider ) {
			if ( defined( 'DCARD_GEMINI_API_KEY' ) && DCARD_GEMINI_API_KEY ) {
				return DCARD_GEMINI_API_KEY;
			}
			return get_option( self::OPTION_GEMINI_KEY, '' );
		}

		if ( defined( 'DCARD_OPENAI_API_KEY' ) && DCARD_OPENAI_API_KEY ) {
			return DCARD_OPENAI_API_KEY;
		}
		return get_option( self::OPTION_OPENAI_KEY, '' );
	}

	/**
	 * Testing mode: returns the card ID (1-72) that every session must draw
	 * first, or null when the field is empty (normal random draw).
	 */
	public static function get_forced_card_id() {
		$value = get_option( self::OPTION_FORCE_CARD, '' );
		if ( '' === $value || null === $value ) {
			return null;
		}
		$id = absint( $value );
		return ( $id >= 1 && $id <= 72 ) ? $id : null;
	}

	/**
	 * Testing mode: same idea as get_forced_card_id() - when set, overrides
	 * whatever userName/userGender the frontend sends to /session/start, so
	 * QA doesn't need to fill the real (production) name/gender popup on
	 * every test run. '' = no override, use whatever the client sent.
	 */
	public static function get_test_user_name() {
		return trim( (string) get_option( self::OPTION_TEST_USER_NAME, '' ) );
	}

	/**
	 * @return string 'male' | 'female' | '' (no override)
	 */
	public static function get_test_user_gender() {
		$value = get_option( self::OPTION_TEST_USER_GENDER, '' );
		return in_array( $value, array( 'male', 'female' ), true ) ? $value : '';
	}

	public static function get_closing_text( $user_is_registered ) {
		if ( $user_is_registered ) {
			$text = get_option( self::OPTION_CLOSING_REGISTERED, '' );
			return $text ? $text : 'תודה שחזרת. מקווים שהתהליך היום עזר לך.';
		}
		$text = get_option( self::OPTION_CLOSING_NEW_USER, '' );
		return $text ? $text : 'תודה שהיית כאן היום.';
	}

	/**
	 * The marketing paragraph appended at the bottom of the summary email
	 * (DCard_Mailer). HTML is allowed (wp_kses_post) specifically so a real
	 * <a href="..."> to the shop can be added here - there's no separate
	 * "shop URL" field. The default below is the wording given when this was
	 * requested, with "כאן" left as plain text (no invented link) - edit this
	 * field to wrap it in an actual <a href="https://.../shop">כאן</a>.
	 */
	public static function get_marketing_text() {
		$text = get_option( self::OPTION_MARKETING_TEXT, '' );
		if ( '' !== $text ) {
			return $text;
		}
		return "הקלפים הם כלי מופלא להקשבה פנימית וגילוי הדרך שלנו. אם התחברתם למסרים ואתם רוצים להעמיק את החיבור לאינטואיציה גם בבית, אתם מוזמנים להכיר את ערכת הקלפים המלאה שלנו כאן בחנות האתר.\nכמובן שעומד לרשותכם באתר ידע רב בכל הקשור לתודעה והעולם שמעבר.\nאתם מוזמנים ליהנות ונשמח לענות לכל שאלה.";
	}

	/**
	 * Optional image URL shown above the marketing paragraph. Empty by
	 * default - not invented, since no image asset/URL was provided.
	 */
	public static function get_marketing_image_url() {
		return get_option( self::OPTION_MARKETING_IMAGE, '' );
	}

	/**
	 * Beta-testing analytics webhook (DCard_Analytics::track()). '' = tracking
	 * disabled entirely - not invented, since no real endpoint was given.
	 */
	public static function get_analytics_webhook_url() {
		return get_option( self::OPTION_ANALYTICS_WEBHOOK, '' );
	}

	/**
	 * Beta feedback form link, shown at the bottom of the summary email
	 * (DCard_Mailer). '' = no feedback CTA shown - not invented, since no
	 * real form URL was given.
	 */
	public static function get_feedback_form_url() {
		return get_option( self::OPTION_FEEDBACK_FORM_URL, '' );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$raw_direct = get_option( self::OPTION_MODEL_DIRECT, self::DEFAULT_MODEL );
		$raw_chat   = get_option( self::OPTION_MODEL_CHAT, self::DEFAULT_MODEL );
		$model_direct = self::get_model_for_mode( 'direct' );
		$model_chat   = self::get_model_for_mode( 'chat' );
		?>
		<div class="wrap">
			<h1>DCard AI - הגדרות</h1>
			<?php
			// תיקון QA 1.1: המנגנון המובנה (add_settings_error/settings_errors)
			// נשאר כאן כשכבה נוספת, אבל ההצגה בפועל מובטחת ע"י הטרנזיינט
			// העצמאי שלנו (ר' take_notices/add_notice למעלה) - נבדק בפועל
			// שהמנגנון המובנה לבדו לא תמיד מציג הודעה בעמוד הגדרות מותאם אישית.
			settings_errors();
			foreach ( self::take_notices() as $notice ) :
				?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( $notice ); ?></p>
				</div>
				<?php
			endforeach;
			?>
			<?php if ( $raw_direct !== $model_direct || $raw_chat !== $model_chat ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php if ( $raw_direct !== $model_direct ) : ?>
							המודל שהיה שמור עבור "קבלת מסר" (<code><?php echo esc_html( $raw_direct ); ?></code>) כבר לא ברשימת המודלים הזמינים - נפל אוטומטית לברירת המחדל <code><?php echo esc_html( $model_direct ); ?></code>.<br/>
						<?php endif; ?>
						<?php if ( $raw_chat !== $model_chat ) : ?>
							המודל שהיה שמור עבור "שיחה אישית" (<code><?php echo esc_html( $raw_chat ); ?></code>) כבר לא ברשימת המודלים הזמינים - נפל אוטומטית לברירת המחדל <code><?php echo esc_html( $model_chat ); ?></code>.<br/>
						<?php endif; ?>
						זה קרה בשקט, בלי שגיאה למשתמשים, אבל כדאי לבחור מחדש את המודל הרצוי למטה וללחוץ "שמור שינויים" כדי שהבחירה תישמר במפורש.
					</p>
				</div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dcard_ai_model_direct">מודל לקבלת מסר</label></th>
						<td>
							<select id="dcard_ai_model_direct" name="<?php echo esc_attr( self::OPTION_MODEL_DIRECT ); ?>">
								<?php foreach ( self::get_model_choices() as $model_id => $info ) : ?>
									<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $model_direct, $model_id ); ?>><?php echo esc_html( $info['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">המודל שמייצר את המסר הישיר במסלול "קבלת מסר אישי".</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dcard_ai_model_chat">מודל לשיחה אישית</label></th>
						<td>
							<select id="dcard_ai_model_chat" name="<?php echo esc_attr( self::OPTION_MODEL_CHAT ); ?>">
								<?php foreach ( self::get_model_choices() as $model_id => $info ) : ?>
									<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $model_chat, $model_id ); ?>><?php echo esc_html( $info['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">המודל שמנהל את הדיאלוג במסלול "שיחה אישית" - הקלף הראשון וכל הודעה בצ'אט.</p>
						</td>
					</tr>
					<tr>
						<td colspan="2">
							<p class="description">
								כל dropdown קובע גם את הספק (Anthropic/OpenAI) וגם את המודל המדויק לאותו מסלול בלבד - אין הגדרה נפרדת לספק, ואין קשר בין שני המסלולים. לשינוי מודל ל-A/B test, פשוט לבחור כאן ולשמור; אין צורך בהעלאת קוד. חשוב להזין למטה מפתח API של הספק המתאים לכל מודל שנבחר.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dcard_force_card">Force Card ID for Testing</label></th>
						<td>
							<input type="number" min="1" max="72" id="dcard_force_card" name="<?php echo esc_attr( self::OPTION_FORCE_CARD ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_FORCE_CARD, '' ) ); ?>" class="small-text" />
							<p class="description">
								אופציונלי, מספר בין 1 ל-72. השאר ריק לבחירה אקראית רגילה. כשהשדה מלא, הקלף הראשון בכל סשן יהיה הקלף הזה (למשל להשוואת Claude מול OpenAI על אותו קלף). קלף שני ושלישי ייבחרו אקראית, כי אסור לחזור על אותו קלף בסשן.
							</p>
							<?php if ( self::get_forced_card_id() ) : ?>
								<p class="description" style="color:#b32d2e;font-weight:600;">
									מצב בדיקה פעיל: כל המשתמשים באתר מקבלים כרגע את קלף מספר <?php echo (int) self::get_forced_card_id(); ?> כקלף ראשון. יש לרוקן את השדה בסיום הבדיקות.
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dcard_test_user_name">Test User Name</label></th>
						<td>
							<input type="text" id="dcard_test_user_name" name="<?php echo esc_attr( self::OPTION_TEST_USER_NAME ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_TEST_USER_NAME, '' ) ); ?>" class="regular-text" />
							<p class="description">אופציונלי. כשמלא, שם זה מוחלף בפועל בכל סשן חדש (במקום השם שמגיע מהפופאפ בפרונטאנד) - שימושי לבדיקות בלי למלא את הפופאפ בכל פעם. השאר ריק כדי לחזור להתנהגות הרגילה.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dcard_test_user_gender">Test User Gender</label></th>
						<td>
							<?php $test_gender = get_option( self::OPTION_TEST_USER_GENDER, '' ); ?>
							<select id="dcard_test_user_gender" name="<?php echo esc_attr( self::OPTION_TEST_USER_GENDER ); ?>">
								<option value="" <?php selected( $test_gender, '' ); ?>>- ללא override -</option>
								<option value="male" <?php selected( $test_gender, 'male' ); ?>>זכר</option>
								<option value="female" <?php selected( $test_gender, 'female' ); ?>>נקבה</option>
							</select>
							<p class="description">אופציונלי. כשנבחר, המגדר הזה מוחלף בפועל בכל סשן חדש (כולל טקסטים ותוכן שתלויים במגדר - פלייסהולדר, כפתור בחירת קלף וכו') - שוב, לבדיקות בלבד. "- ללא override -" = כמו שמגיע מהפופאפ בפרונטאנד.</p>
							<?php if ( self::get_test_user_name() || self::get_test_user_gender() ) : ?>
								<p class="description" style="color:#b32d2e;font-weight:600;">
									מצב בדיקה פעיל: הפרטים לעיל דורסים את מה שהפופאפ בפרונטאנד שולח. יש לרוקן בסיום הבדיקות.
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dcard_anthropic_key">Anthropic API Key</label></th>
						<td>
							<input type="password" id="dcard_anthropic_key" name="<?php echo esc_attr( self::OPTION_ANTHROPIC_KEY ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_ANTHROPIC_KEY, '' ) ); ?>" class="regular-text" autocomplete="off" />
							<?php if ( defined( 'DCARD_ANTHROPIC_API_KEY' ) && DCARD_ANTHROPIC_API_KEY ) : ?>
								<p class="description">מוגדר כרגע דרך קבוע ב-wp-config.php - הערך כאן מתעלם.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dcard_openai_key">OpenAI API Key</label></th>
						<td>
							<input type="password" id="dcard_openai_key" name="<?php echo esc_attr( self::OPTION_OPENAI_KEY ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_OPENAI_KEY, '' ) ); ?>" class="regular-text" autocomplete="off" />
							<?php if ( defined( 'DCARD_OPENAI_API_KEY' ) && DCARD_OPENAI_API_KEY ) : ?>
								<p class="description">מוגדר כרגע דרך קבוע ב-wp-config.php - הערך כאן מתעלם.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dcard_gemini_key">Google Gemini API Key</label></th>
						<td>
							<input type="password" id="dcard_gemini_key" name="<?php echo esc_attr( self::OPTION_GEMINI_KEY ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_GEMINI_KEY, '' ) ); ?>" class="regular-text" autocomplete="off" />
							<?php if ( defined( 'DCARD_GEMINI_API_KEY' ) && DCARD_GEMINI_API_KEY ) : ?>
								<p class="description">מוגדר כרגע דרך קבוע ב-wp-config.php - הערך כאן מתעלם.</p>
							<?php endif; ?>
							<p class="description">נדרש רק אם נבחר אחד ממודלי Gemini (לא מאומתים - ר' "verified" ב-data/ai-models.json).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dcard_closing_new">טקסט סיום - משתמש חדש</label></th>
						<td>
							<textarea id="dcard_closing_new" name="<?php echo esc_attr( self::OPTION_CLOSING_NEW_USER ); ?>" rows="3" class="large-text"><?php echo esc_textarea( get_option( self::OPTION_CLOSING_NEW_USER, '' ) ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dcard_closing_reg">טקסט סיום - משתמש רשום</label></th>
						<td>
							<textarea id="dcard_closing_reg" name="<?php echo esc_attr( self::OPTION_CLOSING_REGISTERED ); ?>" rows="3" class="large-text"><?php echo esc_textarea( get_option( self::OPTION_CLOSING_REGISTERED, '' ) ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dcard_marketing_text">פסקה שיווקית בסוף מייל הסיכום</label></th>
						<td>
							<textarea id="dcard_marketing_text" name="<?php echo esc_attr( self::OPTION_MARKETING_TEXT ); ?>" rows="5" class="large-text"><?php echo esc_textarea( self::get_marketing_text() ); ?></textarea>
							<p class="description">מוצג בתחתית מייל הסיכום שנשלח למשתמש. אפשר להכניס HTML פשוט (כולל קישור לחנות, לדוגמה: <code>&lt;a href="https://example.com/shop"&gt;לחנות&lt;/a&gt;</code>).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dcard_marketing_image">תמונה שיווקית (URL) - אופציונלי</label></th>
						<td>
							<input type="text" id="dcard_marketing_image" name="<?php echo esc_attr( self::OPTION_MARKETING_IMAGE ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_MARKETING_IMAGE, '' ) ); ?>" class="regular-text" placeholder="https://..." />
							<p class="description">אם מוזן, התמונה תוצג מעל הפסקה השיווקית במייל.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dcard_analytics_webhook">Analytics Webhook URL</label></th>
						<td>
							<input type="text" id="dcard_analytics_webhook" name="<?php echo esc_attr( self::OPTION_ANALYTICS_WEBHOOK ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_ANALYTICS_WEBHOOK, '' ) ); ?>" class="regular-text" placeholder="https://..." />
							<p class="description">אופציונלי, לבדיקות בטא. כשמוזן, שליפת קלף וסיום שיחה שולחים event ל-URL הזה (שם, מייל אם נמסר, מסלול, מספר תורים) ברקע - בלי להשפיע על זמן התגובה למשתמש. השאר ריק כדי לכבות מעקב לגמרי.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dcard_feedback_form_url">Feedback Form URL</label></th>
						<td>
							<input type="text" id="dcard_feedback_form_url" name="<?php echo esc_attr( self::OPTION_FEEDBACK_FORM_URL ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_FEEDBACK_FORM_URL, '' ) ); ?>" class="regular-text" placeholder="https://..." />
							<p class="description">אופציונלי, לבדיקות בטא. כשמוזן, בתחתית מייל הסיכום מופיעה קריאה-לפעולה ידידותית עם כפתור שמפנה ל-URL הזה. השאר ריק כדי לא להציג את הקטע הזה בכלל.</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
