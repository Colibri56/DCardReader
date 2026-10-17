<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp_dcard_usage_logs - יומן פריסות לאנליטיקת רווחיות/עלויות. לא קשור
 * לתמליל השיחה (wp_dcard_sessions.transcript, לשימוש אנושי/מייל סיכום) -
 * זה יומן תפעולי-כספי נפרד, שורה אחת לכל קריאת AI בפועל.
 *
 * estimated_cost מחושב ונקבע (snapshot) בזמן ה-log() עצמו, מהתעריף
 * שבטבלת wp_dcard_model_pricing *באותו רגע* (ר' DCard_Model_Pricing::
 * estimate_cost) - לא מחושב מחדש בזמן קריאה מאוחרת יותר, כך שעדכון
 * תעריף עתידי לא "משנה את ההיסטוריה".
 *
 * *** הערה: עדיין אין קריאה בפועל ל-log() מתוך DCard_AI_Gateway/
 * DCard_REST_Controller - הטבלה והמתודה מוכנות (per the request: "infra
 * for future pricing"), אבל שום קוד קיים עדיין לא רושם שורה בפועל אחרי
 * פריסה אמיתית. חיווט בפועל (קריאה ל-DCard_Usage_Logs::log() מתוך
 * session_card_selected_inner()/session_turn_inner() עם usage.input_tokens/
 * output_tokens מתשובת ה-API) הוא השלב הבא, לא כלול כאן. ***
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
	 */
	public static function log( $user_id, $model_used, $input_tokens, $output_tokens ) {
		global $wpdb;
		self::ensure_table_exists();

		$estimated_cost = DCard_Model_Pricing::estimate_cost( $model_used, $input_tokens, $output_tokens );

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

		return (int) $wpdb->insert_id;
	}
}
