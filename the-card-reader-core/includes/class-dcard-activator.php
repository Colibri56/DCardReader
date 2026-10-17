<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DCard_Activator {

	/**
	 * ר' DCard_Users::ensure_table_exists() (ואותה מתודה בכל אחת מהשלוש
	 * האחרות) - כל טבלה כאן גם "מתקנת את עצמה" באופן עצמאי אם חסרה,
	 * בלי תלות ב-dcard_db_version. activate()/maybe_upgrade() כאן הם
	 * עדיין הדרך ה"רגילה" (רצה אוטומטית בטעינה הראשונה אחרי deploy),
	 * אבל לא קריטית יותר - deploy חלקי לא יכול להשאיר טבלה חסרה לצמיתות
	 * כמו שקרה בעבר (ר' ההערה שכבר הייתה ב-class-dcard-users.php).
	 */
	private static function create_all_tables() {
		self::create_table();
		DCard_Users::create_table();
		DCard_Model_Pricing::create_table();
		DCard_Model_Pricing::seed_defaults_if_empty();
		DCard_Usage_Logs::create_table();
		DCard_Transactions::create_table();
	}

	public static function activate() {
		self::create_all_tables();
		update_option( 'dcard_db_version', DCARD_DB_VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'dcard_db_version' ) !== DCARD_DB_VERSION ) {
			self::create_all_tables();
			update_option( 'dcard_db_version', DCARD_DB_VERSION );
		}
	}

	private static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'dcard_sessions';
		$charset_collate = $wpdb->get_charset_collate();

		// state_json holds the conversation-control state required by agent-brain-instructions.md
		// (stage, refined_question, clarification_count, turn_count, insights, possible_next_step).
		// Without it the turn/card limits and stage gating described in that file cannot be enforced server-side.
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_token CHAR(36) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			user_name VARCHAR(100) NULL,
			user_gender VARCHAR(10) NULL,
			drawn_cards TEXT NULL,
			transcript LONGTEXT NULL,
			state_json TEXT NULL,
			email_sent_to VARCHAR(190) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_token (session_token),
			KEY status (status),
			KEY updated_at (updated_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
