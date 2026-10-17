<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Freemium PLG funnel, step 1: usage quotas for anonymous vs. registered
 * users. This class only owns the admin settings page (top-level menu,
 * "קורא בקלפים - הגדרות") and the wp_options the four limits live in, plus
 * localizing the two ANONYMOUS limits to the frontend. The actual
 * tracking/gatekeeping (localStorage counters + triggerRegistrationWall())
 * lives in frontend/elementor-code.html - there is no server-side
 * enforcement of these limits yet (a determined user could clear
 * localStorage and keep going); that's a known, accepted gap for this
 * first step, same as the client-side-only MAX_USER_QUESTIONS cap already
 * in the frontend before this feature.
 *
 * "Registered" vs "anonymous" isn't resolved here at all - this plugin has
 * no concept of a logged-in/registered end user yet (DCard sessions aren't
 * tied to a WP user account). dcard_reg_msg_limit/dcard_reg_chat_limit are
 * therefore stored and shown in the admin UI (so the fields exist ahead of
 * that later work, as asked), but nothing currently reads them.
 */
class DCard_Quotas {

	const OPTION_GROUP = 'dcard_quotas_settings';

	const OPTION_ANON_MSG_LIMIT  = 'dcard_anon_msg_limit';
	const OPTION_ANON_CHAT_LIMIT = 'dcard_anon_chat_limit';
	const OPTION_REG_MSG_LIMIT   = 'dcard_reg_msg_limit';
	const OPTION_REG_CHAT_LIMIT  = 'dcard_reg_chat_limit';

	// ברירות מחדל זמניות בלבד - אין שום משמעות "נכונה" ידועה מראש למגבלות
	// האלה, המנהל צריך לכוון אותן בעצמו בעמוד ההגדרות לפי מה שמתאים לעסק.
	const DEFAULT_ANON_MSG_LIMIT  = 1;
	const DEFAULT_ANON_CHAT_LIMIT = 5;
	const DEFAULT_REG_MSG_LIMIT   = 10;
	const DEFAULT_REG_CHAT_LIMIT  = 30;

	// תיקון איחוד תפריטים: קודם add_menu_page() נפרד ("DCard Quotas") - עכשיו
	// תת-תפריט של ה-CPT "הקורא בקלפים" (projective_card), לצד "הגדרות AI"
	// (DCard_Settings) ותת-התפריטים האוטומטיים של ה-CPT עצמו. כל התוסף
	// נגיש כעת מתפריט ניהול אחד, כפי שהתבקש.
	public static function register_menu() {
		add_submenu_page(
			'edit.php?post_type=projective_card',
			'ניהול מכסות',
			'ניהול מכסות',
			'manage_options',
			'dcard-quotas',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings() {
		$int_sanitizer = function ( $value ) {
			$int = absint( $value ); // שולל/לא-מספרי -> 0, לא שומר ערך לא תקין בשקט
			return $int;
		};

		register_setting( self::OPTION_GROUP, self::OPTION_ANON_MSG_LIMIT, array(
			'sanitize_callback' => $int_sanitizer,
			'default'           => self::DEFAULT_ANON_MSG_LIMIT,
		) );
		register_setting( self::OPTION_GROUP, self::OPTION_ANON_CHAT_LIMIT, array(
			'sanitize_callback' => $int_sanitizer,
			'default'           => self::DEFAULT_ANON_CHAT_LIMIT,
		) );
		register_setting( self::OPTION_GROUP, self::OPTION_REG_MSG_LIMIT, array(
			'sanitize_callback' => $int_sanitizer,
			'default'           => self::DEFAULT_REG_MSG_LIMIT,
		) );
		register_setting( self::OPTION_GROUP, self::OPTION_REG_CHAT_LIMIT, array(
			'sanitize_callback' => $int_sanitizer,
			'default'           => self::DEFAULT_REG_CHAT_LIMIT,
		) );
	}

	/**
	 * אותו מנגנון הודעות עצמאי (לא רק settings_errors() המובנה) כמו ב-
	 * DCard_Settings::add_notice()/take_notices() - כבר הוכח שההודעות
	 * המובנות של וורדפרס לא בהכרח מוצגות בעמודי ניהול מותאמים אישית.
	 * כרגע ה-sanitizer כאן לא בודק תקינות שדורשת הודעת שגיאה (absint תמיד
	 * "מצליח", רק לא שומר קלט לא תקין בשקט) - אבל מוכן לשימוש עתידי
	 * (למשל ולידציה "chat limit > msg limit") בלי לבנות מנגנון נוסף.
	 */
	private static function notice_transient_key() {
		return 'dcard_quotas_notices_' . get_current_user_id();
	}

	private static function add_notice( $message ) {
		$notices   = get_transient( self::notice_transient_key() );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = $message;
		set_transient( self::notice_transient_key(), $notices, 60 );
	}

	private static function take_notices() {
		$notices = get_transient( self::notice_transient_key() );
		delete_transient( self::notice_transient_key() );
		return is_array( $notices ) ? $notices : array();
	}

	public static function render_page() {
		?>
		<div class="wrap" dir="rtl">
			<h1>קורא בקלפים - הגדרות מכסות (Freemium)</h1>
			<p>המגבלות האלה חלות כרגע רק על משתמשים אנונימיים (ר' הערה בקוד) - נספרות בדפדפן של המשתמש (localStorage), לא בשרת.</p>
			<?php
			settings_errors();
			foreach ( self::take_notices() as $notice ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
			}
			?>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_ANON_MSG_LIMIT ); ?>">מגבלת הודעות (קבלת מסר) - אנונימי</label></th>
						<td>
							<input type="number" min="0" step="1" id="<?php echo esc_attr( self::OPTION_ANON_MSG_LIMIT ); ?>" name="<?php echo esc_attr( self::OPTION_ANON_MSG_LIMIT ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_ANON_MSG_LIMIT, self::DEFAULT_ANON_MSG_LIMIT ) ); ?>" class="small-text" />
							<p class="description">כמה פעמים משתמש אנונימי יכול לבחור "קבלת מסר" לפני שמוצג קיר הרשמה.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_ANON_CHAT_LIMIT ); ?>">מגבלת שאלות (שיחה פתוחה) - אנונימי</label></th>
						<td>
							<input type="number" min="0" step="1" id="<?php echo esc_attr( self::OPTION_ANON_CHAT_LIMIT ); ?>" name="<?php echo esc_attr( self::OPTION_ANON_CHAT_LIMIT ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_ANON_CHAT_LIMIT, self::DEFAULT_ANON_CHAT_LIMIT ) ); ?>" class="small-text" />
							<p class="description">כמה שאלות חופשיות בשיחה פתוחה משתמש אנונימי יכול לשלוח לפני שמוצג קיר הרשמה.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_REG_MSG_LIMIT ); ?>">מגבלת הודעות (קבלת מסר) - רשום</label></th>
						<td>
							<input type="number" min="0" step="1" id="<?php echo esc_attr( self::OPTION_REG_MSG_LIMIT ); ?>" name="<?php echo esc_attr( self::OPTION_REG_MSG_LIMIT ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_REG_MSG_LIMIT, self::DEFAULT_REG_MSG_LIMIT ) ); ?>" class="small-text" />
							<p class="description">שמור לשלב הבא - עדיין לא קיים מנגנון משתמש רשום/התחברות בפלאגין הזה, אז הערך הזה עדיין לא נאכף בפועל.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_REG_CHAT_LIMIT ); ?>">מגבלת שאלות (שיחה פתוחה) - רשום</label></th>
						<td>
							<input type="number" min="0" step="1" id="<?php echo esc_attr( self::OPTION_REG_CHAT_LIMIT ); ?>" name="<?php echo esc_attr( self::OPTION_REG_CHAT_LIMIT ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_REG_CHAT_LIMIT, self::DEFAULT_REG_CHAT_LIMIT ) ); ?>" class="small-text" />
							<p class="description">שמור לשלב הבא - ר' הערה למעלה.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'שמירה' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * תיקון QA 4.3 (אותו דפוס): אין כאן קובץ JS אמיתי שנרשם/נטען ב-wp_enqueue_
	 * scripts (כל קוד הצד-לקוח הוא <script> אחד בתוך ווידג'ט HTML של
	 * אלמנטור, לא asset מנוהל) - אז בדיוק כמו עם ה-nonce (ר' dcard-ai-reader-
	 * backend.php), חובה "handle" מדומה (ללא src) רק כדי ש-wp_localize_script
	 * יוכל לצרף אליו משתנה. נרשם+מלוקלייז ב-wp_head (בלי in_footer, כך
	 * שהתסריט שהפלאגין מזריק יושב ב-<head>) ולא ב-wp_footer, מהסיבה
	 * המתועדת שם: ה-<script> של הווידג'ט עצמו יכול לשבת בכל מקום בעמוד,
	 * וחייב שה-window.dcardSettings יהיה קיים לפני שהוא רץ, לא משנה היכן.
	 */
	public static function enqueue_frontend_settings() {
		wp_register_script( 'dcard-quotas-stub', '', array(), null, false );
		wp_enqueue_script( 'dcard-quotas-stub' );
		wp_localize_script( 'dcard-quotas-stub', 'dcardSettings', array(
			'anonMsgLimit'  => (int) get_option( self::OPTION_ANON_MSG_LIMIT, self::DEFAULT_ANON_MSG_LIMIT ),
			'anonChatLimit' => (int) get_option( self::OPTION_ANON_CHAT_LIMIT, self::DEFAULT_ANON_CHAT_LIMIT ),
		) );
	}
}
