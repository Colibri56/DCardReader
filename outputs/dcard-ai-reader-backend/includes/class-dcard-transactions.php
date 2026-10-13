<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp_dcard_transactions - היסטוריית רכישות קארדקום. נכתב מתוך ה-webhook
 * הפיננסי (ר' DCard_REST_Controller::payment_webhook_inner()) - שורה אחת
 * לכל אירוע תשלום/חידוש שקארדקום מדווח עליו.
 *
 * cardcom_transaction_id הוא UNIQUE בכוונה: קארדקום (כמו כל webhook
 * פיננסי) עשוי לשלוח את אותה התראה יותר מפעם אחת (retry ברשת, וכו') -
 * record_if_new() מזהה כפילות לפי המזהה הזה ולא כותב שורה שוב, כדי
 * שעדכון מסלול/קרדיטים לא "יוכפל" בטעות על אותה עסקה.
 */
class DCard_Transactions {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dcard_transactions';
	}

	public static function create_table() {
		global $wpdb;

		$table_name      = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NULL,
			cardcom_transaction_id VARCHAR(100) NOT NULL,
			plan_id VARCHAR(20) NOT NULL,
			amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL DEFAULT 'ILS',
			transaction_date DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY cardcom_transaction_id (cardcom_transaction_id),
			KEY user_id (user_id)
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

	public static function exists( $cardcom_transaction_id ) {
		global $wpdb;
		self::ensure_table_exists();

		$id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM " . self::table() . " WHERE cardcom_transaction_id = %s", $cardcom_transaction_id )
		);
		return (bool) $id;
	}

	/**
	 * @return int|null|'duplicate' - 'duplicate' אם cardcom_transaction_id
	 * הזה כבר קיים (ר' הערת ה-class למעלה), id חדש בהצלחה, null בכשל DB.
	 */
	public static function record_if_new( $user_id, $cardcom_transaction_id, $plan_id, $amount, $currency, $transaction_date = null ) {
		global $wpdb;
		self::ensure_table_exists();

		if ( self::exists( $cardcom_transaction_id ) ) {
			DCard_Logger::info( 'Duplicate Cardcom transaction id - skipping insert (already recorded)', array( 'cardcom_transaction_id' => $cardcom_transaction_id ) );
			return 'duplicate';
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'user_id'                 => $user_id ? (int) $user_id : null,
				'cardcom_transaction_id'  => $cardcom_transaction_id,
				'plan_id'                 => $plan_id,
				'amount'                  => $amount,
				'currency'                => $currency ? $currency : 'ILS',
				'transaction_date'        => $transaction_date ? $transaction_date : current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%f', '%s', '%s' )
		);

		if ( ! $inserted ) {
			DCard_Logger::error( 'Failed to insert dcard_transactions row', array( 'cardcom_transaction_id' => $cardcom_transaction_id, 'db_error' => $wpdb->last_error ) );
			return null;
		}

		return (int) $wpdb->insert_id;
	}
}
