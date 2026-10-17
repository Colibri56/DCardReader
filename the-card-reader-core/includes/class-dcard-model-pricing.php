<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp_dcard_model_pricing - תעריפי API לפי מודל, כדי שמחירים לא יהיו
 * קשיחים בקוד (יכולים להתעדכן - Anthropic/OpenAI משנים תעריפים מדי פעם -
 * בלי deploy, ישירות ב-DB). DCard_Usage_Logs::log() קורא לכאן בזמן אמת
 * כדי לחשב ולקבע (snapshot) את העלות ההיסטורית של כל פריסה - שינוי תעריף
 * מאוחר יותר לא משנה עלויות שכבר נרשמו.
 *
 * אין כאן עדיין ממשק ניהול (Settings page) להזנת/עדכון תעריפים -
 * get_or_seed_default() מכניס כמה שורות התחלתיות סבירות כך שהטבלה לא
 * ריקה מהיום הראשון, אבל אלה הערכות בלבד שצריך לאמת/לעדכן בפועל מול
 * התמחור הרשמי העדכני לפני הסתמכות עליהן לדיווח רווחיות אמיתי.
 */
class DCard_Model_Pricing {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dcard_model_pricing';
	}

	public static function create_table() {
		global $wpdb;

		$table_name      = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			model_name VARCHAR(100) NOT NULL,
			input_cost_per_1m DECIMAL(10,4) NOT NULL DEFAULT 0,
			output_cost_per_1m DECIMAL(10,4) NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY model_name (model_name)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
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
	 * upsert לפי model_name - קריאה חזרה עם אותו שם מעדכנת את התעריף
	 * הקיים (לא כפילות), כדי שעדכון תעריף יהיה פעולה אחת פשוטה.
	 */
	public static function set_pricing( $model_name, $input_cost_per_1m, $output_cost_per_1m ) {
		global $wpdb;
		self::ensure_table_exists();

		$now = current_time( 'mysql', true );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM " . self::table() . " WHERE model_name = %s", $model_name )
		);

		if ( $existing_id ) {
			return false !== $wpdb->update(
				self::table(),
				array( 'input_cost_per_1m' => $input_cost_per_1m, 'output_cost_per_1m' => $output_cost_per_1m, 'updated_at' => $now ),
				array( 'id' => $existing_id ),
				array( '%f', '%f', '%s' ),
				array( '%d' )
			);
		}

		return false !== $wpdb->insert(
			self::table(),
			array(
				'model_name'          => $model_name,
				'input_cost_per_1m'   => $input_cost_per_1m,
				'output_cost_per_1m'  => $output_cost_per_1m,
				'updated_at'          => $now,
			),
			array( '%s', '%f', '%f', '%s' )
		);
	}

	public static function get_pricing( $model_name ) {
		global $wpdb;
		self::ensure_table_exists();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE model_name = %s", $model_name ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * עלות משוערת (דולר) לפריסה אחת - 0.0 (לא שגיאה/חריגה) אם המודל
	 * לא קיים בטבלה, כדי שיומן השימוש (DCard_Usage_Logs::log) לעולם לא
	 * יכשל רק בגלל תעריף חסר; המודל החסר נרשם כ-warning כדי שיתגלה.
	 */
	public static function estimate_cost( $model_name, $input_tokens, $output_tokens ) {
		$pricing = self::get_pricing( $model_name );
		if ( ! $pricing ) {
			DCard_Logger::warning( 'No pricing row for model - logging usage with estimated_cost=0', array( 'model_name' => $model_name ) );
			return 0.0;
		}

		$input_cost  = ( (int) $input_tokens / 1000000 ) * (float) $pricing['input_cost_per_1m'];
		$output_cost = ( (int) $output_tokens / 1000000 ) * (float) $pricing['output_cost_per_1m'];

		return round( $input_cost + $output_cost, 6 );
	}

	/**
	 * שורות ברירת מחדל - רק אם הטבלה ריקה (לא דורס ערכים שעודכנו ידנית).
	 * *** המספרים כאן הם הערכה ראשונית, לא תעריף רשמי מאומת - לבדוק מול
	 * Anthropic/OpenAI בפועל לפני הסתמכות עליהם לדיווח רווחיות אמיתי. ***
	 */
	public static function seed_defaults_if_empty() {
		global $wpdb;
		self::ensure_table_exists();

		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
		if ( $count > 0 ) {
			return;
		}

		$defaults = array(
			'claude-haiku-4-5'  => array( 1.00, 5.00 ),
			'claude-sonnet-5'   => array( 3.00, 15.00 ),
			'claude-sonnet-5-5' => array( 3.00, 15.00 ),
			'gpt-4o-mini'       => array( 0.15, 0.60 ),
			'gpt-4o'            => array( 2.50, 10.00 ),
		);

		foreach ( $defaults as $model_name => $costs ) {
			self::set_pricing( $model_name, $costs[0], $costs[1] );
		}
	}
}
