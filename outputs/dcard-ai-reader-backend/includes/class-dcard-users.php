<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Freemium PLG funnel, step 2: a persistent "registered user" record,
 * created/updated by the Elementor registration form's Webhook action
 * (POST /wp-json/dcard/v1/register - ר' DCard_REST_Controller::session_register()).
 *
 * This is deliberately its OWN table, not a row in wp_dcard_sessions:
 * sessions are ephemeral by design (DCard_DB::cleanup_old_sessions() purges
 * them after 2-48h), but a registered user's identity has to outlive any
 * single session - that's the entire point of registering.
 *
 * Also deliberately NOT a real WP user account (wp_users/wp_insert_user) -
 * this is a lightweight "is this browser a known registered lead"
 * marker for the quota check, not a login/auth system. id (AUTO_INCREMENT)
 * is the user_id returned to the frontend, which the frontend is expected
 * to persist in localStorage (see the registration webhook's JS side) and
 * send back on future sessions once that's wired up.
 *
 * NOTE (scope of this step): this class only creates/updates the record
 * and marks it registered - it does not yet change what quota the
 * frontend actually enforces. DCard_Quotas::OPTION_REG_MSG_LIMIT/
 * OPTION_REG_CHAT_LIMIT already exist for that, but nothing reads them
 * yet (same gap documented in class-dcard-quotas.php) - the frontend
 * still needs to be updated to look up this user_id and use the
 * registered limits instead of the anonymous ones once it has one.
 */
class DCard_Users {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dcard_users';
	}

	public static function create_table() {
		global $wpdb;

		$table_name      = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL,
			name VARCHAR(100) NULL,
			is_registered TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * יצירה או עדכון לפי email (המזהה היציב היחיד שיש לנו מטופס ההרשמה) -
	 * UNIQUE KEY email לעיל הופך את זה לפעולת upsert אמיתית: הרשמה חדשה
	 * יוצרת שורה; הרשמה חוזרת עם אותו email (למשל מדפדפן/מכשיר אחר) מעדכנת
	 * את ה-name ואת updated_at על השורה הקיימת ומחזירה את אותו id קבוע,
	 * בלי ליצור כפילות. מחזיר את ה-id (int) בהצלחה, או null בכשל.
	 */
	/**
	 * תיקון: 500 חוזר ב-webhook ההרשמה (דווח בפועל) אותר כנובע מהטבלה
	 * שלא נוצרה בפועל בשרת - כנראה כתוצאה מפריסה חלקית, שבה dcard-ai-
	 * reader-backend.php (עם הגרסה המעודכנת) עלה בלי class-dcard-
	 * activator.php המעודכן: הבדיקה ב-maybe_upgrade() אז "מצליחה" עם
	 * הלוגיקה הישנה (לא יוצרת wp_dcard_users בכלל), אבל עדיין שומרת את
	 * המספר החדש ב-dcard_db_version - ואז אפילו אחרי שמעלים את הקובץ
	 * הנכון, ה-guard הזה כבר "נוצל" ולא ירוץ שוב לבד, כי ה-option כבר
	 * תואם. כדי לא להיות תלויים בכך שהפריסה תמיד תהיה אטומית/מסונכרנת,
	 * upsert_by_email בודק ישירות אם הטבלה קיימת ומייצר אותה על המקום אם
	 * לא - בלי תלות ב-DCard_Activator/dcard_db_version בכלל.
	 */
	private static function ensure_table_exists() {
		global $wpdb;
		$table = self::table();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $found !== $table ) {
			self::create_table();
		}
	}

	public static function upsert_by_email( $email, $name ) {
		global $wpdb;

		self::ensure_table_exists();

		$now = current_time( 'mysql', true );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM " . self::table() . " WHERE email = %s", $email )
		);

		if ( $existing_id ) {
			$wpdb->update(
				self::table(),
				array( 'name' => $name, 'is_registered' => 1, 'updated_at' => $now ),
				array( 'id' => $existing_id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			return (int) $existing_id;
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'email'         => $email,
				'name'          => $name,
				'is_registered' => 1,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			DCard_Logger::error( 'Failed to insert dcard_users row', array( 'email' => $email, 'db_error' => $wpdb->last_error ) );
			return null;
		}

		return (int) $wpdb->insert_id;
	}

	public static function get_by_id( $user_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", $user_id ),
			ARRAY_A
		);

		return $row ? $row : null;
	}
}
