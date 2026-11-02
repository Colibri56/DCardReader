<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * עמוד ניהול חדש - "לקוחות" - תשובה ישירה לבקשה "איפה רואים את יתרת
 * הקרדיטים של הלקוח?": עד כה זה היה נגיש רק בגישה גולמית ל-DB. מציג כל
 * משתמש רשום (wp_dcard_users) עם המסלול/קרדיטים/תפוגה הנוכחיים שלו, וכמה
 * בסך הכול שילם (SUM על wp_dcard_transactions - הנתון כבר נשמר שם מתוך
 * payment_webhook_inner, לא נדרש שדה/טבלה נוספת).
 *
 * קריאה בלבד (read-only) בכוונה - אין כאן עדיין ערוך/מחק; שינוי מסלול
 * ידני, אם יידרש, הוא תכונה נפרדת.
 */
class DCard_Customers_Admin {

	public static function register_menu() {
		add_submenu_page(
			'edit.php?post_type=projective_card',
			'לקוחות',
			'לקוחות',
			'manage_options',
			'dcard-customers',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * LEFT JOIN כך שלקוח שעוד לא שילם כלום (מסלול free, אין שורות
	 * transactions) מוצג עם total_paid=0, לא נעלם מהרשימה.
	 */
	private static function get_customers() {
		global $wpdb;
		DCard_Users::ensure_table_exists();
		DCard_Transactions::ensure_table_exists();

		$users_table = $wpdb->prefix . 'dcard_users';
		$tx_table    = $wpdb->prefix . 'dcard_transactions';

		return $wpdb->get_results(
			"SELECT u.*, COALESCE(SUM(t.amount), 0) AS total_paid, COUNT(t.id) AS transactions_count
			 FROM {$users_table} u
			 LEFT JOIN {$tx_table} t ON t.user_id = u.id
			 GROUP BY u.id
			 ORDER BY u.updated_at DESC",
			ARRAY_A
		);
	}

	public static function render_page() {
		$customers = self::get_customers();
		?>
		<div class="wrap" dir="rtl">
			<h1>לקוחות</h1>
			<p>כל משתמש רשום (נרשם בטופס ו/או שילם) - מסלול נוכחי, קרדיטים שנותרו (בנפרד למסרים ולשיחות מפתחות), תוקף, וסכום ששולם בסך הכול. ר' <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=projective_card&page=dcard-quotas' ) ); ?>">ניהול מכסות</a> לעריכת מספר הקרדיטים לכל מסלול.</p>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>אימייל</th>
						<th>שם</th>
						<th>טלפון</th>
						<th>מסלול</th>
						<th>קרדיטים - מסרים</th>
						<th>קרדיטים - שיחה מפתחת</th>
						<th>תוקף המסלול</th>
						<th>סה"כ שולם</th>
						<th>מספר עסקאות</th>
						<th>נרשם בתאריך</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $customers ) ) : ?>
						<tr><td colspan="10">אין עדיין משתמשים רשומים.</td></tr>
					<?php else : ?>
						<?php foreach ( $customers as $customer ) : ?>
							<?php
							$plan_id           = $customer['current_plan'];
							$is_unlimited_msg  = class_exists( 'DCard_Plans' ) && DCard_Plans::is_unlimited( $plan_id, 'msg' );
							$is_unlimited_chat = class_exists( 'DCard_Plans' ) && DCard_Plans::is_unlimited( $plan_id, 'chat' );
							$plan_label        = class_exists( 'DCard_Plans' ) ? DCard_Plans::label_for_plan( $plan_id ) : $plan_id;
							?>
							<tr>
								<td><?php echo esc_html( $customer['email'] ); ?></td>
								<td><?php echo esc_html( $customer['name'] ); ?></td>
								<td><?php echo esc_html( $customer['phone'] ); ?></td>
								<td><?php echo esc_html( $plan_label ); ?></td>
								<td><?php echo $is_unlimited_msg ? 'ללא הגבלה' : esc_html( $customer['current_credits_msg'] ); ?></td>
								<td><?php echo $is_unlimited_chat ? 'ללא הגבלה' : esc_html( $customer['current_credits_chat'] ); ?></td>
								<td><?php echo $customer['plan_expires_at'] ? esc_html( $customer['plan_expires_at'] ) : '-'; ?></td>
								<td><?php echo esc_html( number_format( (float) $customer['total_paid'], 2 ) ); ?> ₪</td>
								<td><?php echo esc_html( $customer['transactions_count'] ); ?></td>
								<td><?php echo esc_html( $customer['created_at'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
