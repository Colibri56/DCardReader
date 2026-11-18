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
 * תיקון ("עדכון מסלול ידני", התבקש אחרי מקרה תשלום שלא התעדכן בפועל):
 * כל שורה מקבלת טופס קטן (בחירת מסלול + כפתור) שפונה ל-admin-post.php
 * (ר' handle_manual_set_plan() למטה + החיווט ב-the-card-reader-core.php) -
 * קורא ל-DCard_Users::set_plan() בדיוק כמו ה-webhook עצמו (אותם קרדיטים/
 * תוקף/next_credit_reset_at), כדי שלא יהיה מסלול "מיוחד" שמתנהג אחרת,
 * ורושם ללוג מי ביצע את השינוי ומתי - לשני מקרי שימוש: תיקון עסקה
 * שנכשלה (ר' set_plan()), ושיוך ידני של תשלום שהגיע בלי משתמש תואם (ר'
 * ה-admin_email שנשלח מ-payment_webhook_inner).
 */
class DCard_Customers_Admin {

	/**
	 * אפשרויות בחירת המסלול בטופס העדכון הידני - חינמי + כל המסלולים
	 * בתשלום (ר' DCard_Plans::paid_plan_ids) - בכוונה *לא* מסונן לפי
	 * is_plan_active(), כדי שאפשר יהיה להחזיר ידנית גם למסלול שהושבת.
	 */
	private static function plan_options() {
		$options = array( DCard_Plans::FREE => DCard_Plans::label_for_plan( DCard_Plans::FREE ) );
		foreach ( DCard_Plans::paid_plan_ids() as $plan_id ) {
			$options[ $plan_id ] = DCard_Plans::label_for_plan( $plan_id );
		}
		return $options;
	}

	/**
	 * Handler של admin-post.php (action=dcard_manual_set_plan) - קורא
	 * ל-set_plan() בדיוק כמו payment_webhook_inner: אותו expires_at
	 * (היום + DCard_Plans::plan_duration_days, null למסלול חינמי), ואותו
	 * next_credit_reset_at (נקבע בתוך set_plan() עצמה). לא בודק/יוצר
	 * עסקת תשלום - זה עדכון מסלול בלבד, לא "תשלום מדומה".
	 */
	public static function handle_manual_set_plan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'אין הרשאה' );
		}
		check_admin_referer( 'dcard_manual_set_plan' );

		$redirect_url = admin_url( 'edit.php?post_type=projective_card&page=dcard-customers' );

		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$plan_id = isset( $_POST['plan_id'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_id'] ) ) : '';

		if ( ! $user_id || ! DCard_Plans::is_valid_plan( $plan_id ) ) {
			wp_safe_redirect( add_query_arg( 'dcard_manual_set_plan', 'error', $redirect_url ) );
			exit;
		}

		$expires_at = ( DCard_Plans::FREE === $plan_id )
			? null
			: gmdate( 'Y-m-d H:i:s', strtotime( '+' . DCard_Plans::plan_duration_days( $plan_id ) . ' days' ) );

		$success = DCard_Users::set_plan( $user_id, $plan_id, $expires_at );

		$admin_user = wp_get_current_user();
		DCard_Logger::info( 'עדכון מסלול ידני מעמוד הלקוחות', array(
			'target_user_id' => $user_id,
			'plan_id'        => $plan_id,
			'expires_at'     => $expires_at,
			'performed_by'   => $admin_user ? $admin_user->user_email : '',
			'success'        => $success,
		) );

		wp_safe_redirect( add_query_arg( 'dcard_manual_set_plan', $success ? 'success' : 'error', $redirect_url ) );
		exit;
	}

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
			<?php if ( isset( $_GET['dcard_manual_set_plan'] ) ) : ?>
				<?php if ( 'success' === $_GET['dcard_manual_set_plan'] ) : ?>
					<div class="notice notice-success is-dismissible"><p>המסלול עודכן בהצלחה.</p></div>
				<?php else : ?>
					<div class="notice notice-error is-dismissible"><p>עדכון המסלול נכשל - ר' הלוג (Settings > קורא בקלפים > לוג).</p></div>
				<?php endif; ?>
			<?php endif; ?>
			<p>כל משתמש רשום (נרשם בטופס ו/או שילם) - מסלול נוכחי, קרדיטים שנותרו (בנפרד למסרים ולשיחות מפתחות), תוקף, וסכום ששולם בסך הכול. ר' <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=projective_card&page=dcard-quotas' ) ); ?>">ניהול מכסות</a> לעריכת מספר הקרדיטים לכל מסלול.</p>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>אימייל</th>
						<th>שם</th>
						<th>טלפון</th>
						<th>מסלול</th>
						<th>סיבת ביטול</th>
						<th>קרדיטים - מסרים</th>
						<th>קרדיטים - שיחה מפתחת</th>
						<th>תוקף המסלול</th>
						<th>סה"כ שולם</th>
						<th>מספר עסקאות</th>
						<th>נרשם בתאריך</th>
						<th>עדכון מסלול ידני</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $customers ) ) : ?>
						<tr><td colspan="12">אין עדיין משתמשים רשומים.</td></tr>
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
								<td>
									<?php
									// §6.11: "הסיבה מוצגת גם בטבלת הלקוחות באדמין" - גם
									// במצב ביניים (cancel_requested_at, לפני תפוגה בפועל)
									// וגם אחרי המעבר ל-CANCELLED (cancelled_at).
									if ( ! empty( $customer['cancel_requested_at'] ) ) {
										echo 'בקשת ביטול: ' . esc_html( $customer['cancel_requested_at'] );
										if ( ! empty( $customer['cancel_reason'] ) ) {
											echo '<br>' . esc_html( $customer['cancel_reason'] );
										}
									} elseif ( ! empty( $customer['cancelled_at'] ) ) {
										echo 'בוטל: ' . esc_html( $customer['cancelled_at'] );
										if ( ! empty( $customer['cancel_reason'] ) ) {
											echo '<br>' . esc_html( $customer['cancel_reason'] );
										}
									} else {
										echo '-';
									}
									?>
								</td>
								<td><?php echo $is_unlimited_msg ? 'ללא הגבלה' : esc_html( $customer['current_credits_msg'] ); ?></td>
								<td><?php echo $is_unlimited_chat ? 'ללא הגבלה' : esc_html( $customer['current_credits_chat'] ); ?></td>
								<td><?php echo $customer['plan_expires_at'] ? esc_html( $customer['plan_expires_at'] ) : '-'; ?></td>
								<td><?php echo esc_html( number_format( (float) $customer['total_paid'], 2 ) ); ?> ₪</td>
								<td><?php echo esc_html( $customer['transactions_count'] ); ?></td>
								<td><?php echo esc_html( $customer['created_at'] ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:4px;align-items:center;white-space:nowrap;">
										<input type="hidden" name="action" value="dcard_manual_set_plan" />
										<input type="hidden" name="user_id" value="<?php echo esc_attr( $customer['id'] ); ?>" />
										<?php wp_nonce_field( 'dcard_manual_set_plan' ); ?>
										<select name="plan_id">
											<?php foreach ( self::plan_options() as $opt_id => $opt_label ) : ?>
												<option value="<?php echo esc_attr( $opt_id ); ?>" <?php selected( $opt_id, $plan_id ); ?>><?php echo esc_html( $opt_label ); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="submit" class="button button-small" onclick="return confirm('לעדכן את המסלול של <?php echo esc_js( $customer['email'] ); ?> ידנית? הפעולה תפעל בדיוק כמו תשלום בקארדקום (קרדיטים/תוקף יתעדכנו).');">עדכון</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
