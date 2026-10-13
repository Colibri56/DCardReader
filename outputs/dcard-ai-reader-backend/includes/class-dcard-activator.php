<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DCard_Activator {

	public static function activate() {
		self::create_table();
		DCard_Users::create_table();
		update_option( 'dcard_db_version', DCARD_DB_VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'dcard_db_version' ) !== DCARD_DB_VERSION ) {
			self::create_table();
			// נוסף כאן (לא רק ב-activate()) כדי שהתקנות קיימות של הפלאגין
			// יקבלו את טבלת wp_dcard_users החדשה גם בלי הפעלה/כיבוי מחדש -
			// ר' עליית DCARD_DB_VERSION ב-dcard-ai-reader-backend.php.
			DCard_Users::create_table();
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
