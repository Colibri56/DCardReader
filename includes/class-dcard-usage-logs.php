<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp_dcard_usage_logs - יומן פריסות לאנליטיקת רווחיות/עלויות. לא קשור
 * לתמליל השיחה (wp_dcard_sessions.transcript, לשימוש אנושי/מייל סיכום) -
 * זה יומן תפעולי-כספי נפרד, שורה אחת לכל קריאת AI בפועל.
 *
 * estimated_cost מועבר ל-log() כבר מחושב (snapshot) ע"י הקורא - תמיד
 * DCard_Settings::get_model_price(), שמקורו בקובץ data/ai-models.json
 * (לא DCard_Model_Pricing/wp_dcard_model_pricing - טבלה נפרדת שקיימת
 * בקוד אבל אינה בשימוש בפועל יותר, בדיוק כדי שלא יהיו שני מקורות תמחור
 * שסותרים זה את זה) - אותו מקור בדיוק שכבר קובע את העלות המוצגת
 * ב-session_completed/ai_usage ב-Google Sheets (ר' class-dcard-rest-
 * controller.php::estimate_cost). לא מחושב מחדש בזמן קריאה מאוחרת יותר,
 * כך שעדכון תעריף עתידי לא "משנה את ההיסטוריה".
 *
 * תיקון (התבקש: "עלול לטפוח לסכום משמעותי" / "להגביל את הסכום החודשי
 * שאני אמור לשלם ל-AI") - log() עכשיו כן נקרא בפועל, מתוך
 * session_card_selected_inner()/session_turn_inner() ב-class-dcard-rest-
 * controller.php, בדיוק ליד הקריאות הקיימות ל-DCard_DB::add_token_usage.
 * זו התשתית שעליה נבנים גם count_calls_today() (מגבלת קריאות AI יומית
 * למשתמש רשום בודד) וגם sum_cost_this_month()/maybe_send_monthly_budget_
 * alert() (תקרת הוצאה חודשית כוללת + התראת מייל) למטה.
 */
class DCard_Usage_Logs {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dcard_usage_logs';
	}

	public static function create_table() {
		global $wpdb;

		$table_name      = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NULL,
			model_used VARCHAR(100) NOT NULL,
			input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			estimated_cost DECIMAL(10,6) NOT NULL DEFAULT 0,
			timestamp DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY model_used (model_used),
			KEY timestamp (timestamp)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// FK אמיתי (REFERENCES) לא נוסף בכוונה: dbDelta לא מתמודד טוב עם
		// FOREIGN KEY constraints (לא מזהה אותם נכון ב-diff, יכול לנסות
		// להוסיף אותם שוב בכל הרצה ולזרוק שגיאת duplicate) - זו מגבלה
		// מתועדת וידועה שלו. user_id נשאר INT רגיל + KEY רגיל (לא FK
		// אכיף ברמת ה-DB) בשביל האינדקס, כמו ש-wp_dcard_sessions עצמו
		// לא משתמש ב-FK constraints בשום מקום בפלאגין הזה.
	}

	public static function ensure_table_exists() {
		global $wpdb;
		$table = self::table();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $found !== $table ) {
			self::create_table();
		}
	}

	/**
	 * $user_id יכול להיות null (פריסה אנונימית, אין עדיין משתמש רשום) -
	 * זה מצב תקין ומצופה, לא שגיאה; הטבלה מתעדת גם פריסות אנונימיות
	 * לצורכי עלות כוללת, גם בלי לדעת "של מי" הן.
	 *
	 * $estimated_cost_usd - תיקון (דווח בפועל ע"י המשתמש: "ציינת $3/$15
	 * ל-Sonnet, אני מכיר מחירים אחרים... עדיף לעדכן מקובץ חיצוני") - עד
	 * כה חושב כאן מחדש דרך DCard_Model_Pricing (טבלת wp_dcard_model_pricing,
	 * עם ברירות מחדל קשיחות ב-PHP, data/ai-models.json כלל לא בתמונה) -
	 * מקור תמחור *שני ונפרד* מזה שכבר קיים ומעודכן בקובץ data/ai-models.json
	 * (input_price_per_million/output_price_per_million, נטען ע"י
	 * DCard_Settings::get_model_price() בלי deploy), ששני המקומות האחרים
	 * שכבר מציגים עלות (session_completed/ai_usage ב-Google Sheets, ר'
	 * class-dcard-rest-controller.php::estimate_cost) כבר משתמשים בו. שני
	 * מקורות תמחור נפרדים = בדיוק מה שגרם לפער שתואר - עכשיו הקורא מעביר
	 * את העלות שכבר חושבה פעם אחת מ-ai-models.json, לא מחשב שוב ממקור אחר.
	 */
	public static function log( $user_id, $model_used, $input_tokens, $output_tokens, $estimated_cost_usd = 0.0 ) {
		global $wpdb;
		self::ensure_table_exists();

		$estimated_cost = (float) $estimated_cost_usd;

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'user_id'        => $user_id ? (int) $user_id : null,
				'model_used'     => $model_used,
				'input_tokens'   => (int) $input_tokens,
				'output_tokens'  => (int) $output_tokens,
				'estimated_cost' => $estimated_cost,
				'timestamp'      => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%d', '%f', '%s' )
		);

		if ( ! $inserted ) {
			DCard_Logger::error( 'Failed to insert dcard_usage_logs row', array( 'model_used' => $model_used, 'db_error' => $wpdb->last_error ) );
			return null;
		}

		self::maybe_send_monthly_budget_alert();

		return (int) $wpdb->insert_id;
	}

	/**
	 * כמה קריאות AI בפועל (שורות log()) בוצעו ע"י המשתמש הרשום הזה מאז
	 * תחילת היום (UTC - עקבי עם timestamp שנשמר כאן ב-current_time('mysql',
	 * true)). $user_id=null (משתמש אנונימי) תמיד מחזיר 0 - אין עדיין זהות
	 * יציבה בצד השרת למשתמש אנונימי שאפשר לאכוף מולה (ר' ההערה המלאה ליד
	 * $registered_user ב-class-dcard-rest-controller.php), אז המגבלה הזו
	 * חלה רק על משתמשים רשומים, כמו מנגנון הקרדיטים הקיים.
	 */
	public static function count_calls_today( $user_id ) {
		global $wpdb;
		self::ensure_table_exists();

		if ( ! $user_id ) {
			return 0;
		}

		$today_start_utc = gmdate( 'Y-m-d 00:00:00' );

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . self::table() . " WHERE user_id = %d AND timestamp >= %s",
			$user_id,
			$today_start_utc
		) );
	}

	/**
	 * סך כל ה-estimated_cost (כל המודלים, כל המשתמשים יחד) מאז תחילת
	 * החודש הקלנדרי הנוכחי (UTC) - הבסיס לתקרת ההוצאה החודשית.
	 */
	public static function sum_cost_this_month() {
		global $wpdb;
		self::ensure_table_exists();

		$month_start_utc = gmdate( 'Y-m-01 00:00:00' );

		return (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(estimated_cost), 0) FROM " . self::table() . " WHERE timestamp >= %s",
			$month_start_utc
		) );
	}

	/**
	 * תיקון (התבקש: "רק התראה למייל שלי, שחוצים 80%/100% מהתקציב
	 * שהוגדר") - בלי חסימה בפועל של שימוש (נבחר במפורש כצעד ראשון
	 * זהיר), רק מייל ל-admin_email. נבדק אחרי כל log() מוצלח (שאילתת
	 * SUM זולה, יש אינדקס על timestamp) - dcard_budget_alerts_sent_{YYYY-MM}
	 * (wp_option) מונע שליחת אותה התראה שוב ושוב על כל קריאת AI נוספת
	 * באותו חודש, ברגע שסף מסוים כבר נשלח פעם אחת.
	 */
	public static function maybe_send_monthly_budget_alert() {
		$budget = DCard_Quotas::get_monthly_ai_budget_usd();
		if ( $budget <= 0 ) {
			return; // 0/לא מוגדר = בלי תקרה, בלי התראות.
		}

		$spent = self::sum_cost_this_month();
		$pct   = ( $spent / $budget ) * 100;

		$month_key = gmdate( 'Y-m' );
		$sent      = get_option( 'dcard_budget_alerts_sent_' . $month_key, array() );
		$sent      = is_array( $sent ) ? $sent : array();

		foreach ( array( 100, 80 ) as $threshold ) {
			if ( $pct < $threshold || in_array( $threshold, $sent, true ) ) {
				continue;
			}

			$admin_email = get_option( 'admin_email' );
			if ( $admin_email ) {
				wp_mail(
					$admin_email,
					sprintf( 'הקורא בקלפים - %d%% מהתקציב החודשי ל-AI נוצל', $threshold ),
					sprintf(
						"שימוש ב-AI החודש (%s) הגיע ל-%s$ מתוך תקציב חודשי של %s$ שהוגדר (%d%%).\n\nלעדכון התקציב: ניהול מכסות (Settings > קורא בקלפים).",
						$month_key,
						number_format( $spent, 2 ),
						number_format( $budget, 2 ),
						round( $pct )
					)
				);
			}

			$sent[] = $threshold;
			update_option( 'dcard_budget_alerts_sent_' . $month_key, $sent, false );
			break; // הסף הגבוה ביותר שנחצה החודש מספיק לקריאה הזו - קריאה עתידית תתפוס את הבא אם עוד לא נשלח.
		}
	}
}
