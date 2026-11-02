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

	// תיקון (התבקש: "לקוח שמתמיד במשך יום X שאלות... לעצור אותו בהודעה
	// מנומסת שמזמינה להמשיך מחר" + "להגביל את הסכום החודשי") - שתי מגבלות
	// נפרדות: קריאות AI ביום למשתמש *רשום* בודד (ר' DCard_Usage_Logs::
	// count_calls_today, שאוכפת את זה ב-class-dcard-rest-controller.php),
	// ותקציב דולרי חודשי *כולל* (כל המשתמשים/המודלים יחד - ר'
	// DCard_Usage_Logs::maybe_send_monthly_budget_alert).
	const OPTION_DAILY_AI_CALL_LIMIT = 'dcard_daily_ai_call_limit';
	const OPTION_MONTHLY_AI_BUDGET   = 'dcard_monthly_ai_budget_usd';

	// תיקון (התבקש: "הגדרה חיצונית של מספר השימושים המקסימלי... לכל
	// מסלול", ואז הורחב: "לא רק למסלול נרקיס") - קרדיטים (סשנים) בכל אחד
	// מהמסלולים בתשלום היו קשיחים ב-DCard_Plans::all(); עכשיו מוגדרים כאן,
	// באותו עמוד שבו כבר מוגדרת מכסת המסלול החינמי. נורית/נורית-שנתי
	// ברירת המחדל שלהם היא DCard_Plans::UNLIMITED_CREDITS (999999) - מספר
	// כזה (או כל מספר >= 999999) ממשיך להתפרש כ"ללא הגבלה" ע"י
	// DCard_Plans::is_unlimited(), כדי לא להוסיף מושג/שדה "checkbox ללא
	// הגבלה" נפרד רק בשביל זה.
	const OPTION_NARKIS_CREDITS       = 'dcard_narkis_plan_credits';
	const OPTION_NURIT_CREDITS        = 'dcard_nurit_plan_credits';
	const OPTION_NURIT_YEARLY_CREDITS = 'dcard_nurit_yearly_plan_credits';
	const DEFAULT_NARKIS_CREDITS       = 15;
	// 999999 = ללא הגבלה (זהה ל-DCard_Plans::UNLIMITED_CREDITS - לא
	// מפנים לקבוע של מחלקה אחרת כאן כדי לא להסתמך על סדר טעינת הקבצים).
	const DEFAULT_NURIT_CREDITS        = 999999;
	const DEFAULT_NURIT_YEARLY_CREDITS = 999999;

	// ברירות מחדל זמניות בלבד - אין שום משמעות "נכונה" ידועה מראש למגבלות
	// האלה, המנהל צריך לכוון אותן בעצמו בעמוד ההגדרות לפי מה שמתאים לעסק.
	const DEFAULT_ANON_MSG_LIMIT  = 1;
	const DEFAULT_ANON_CHAT_LIMIT = 5;
	const DEFAULT_REG_MSG_LIMIT   = 10;
	const DEFAULT_REG_CHAT_LIMIT  = 30;
	// הוגדר יחד עם המשתמש (31.10.26): 40 קריאות AI ביום למשתמש רשום בודד
	// (מאפשר שיחה מפתחת אחת ארוכה-בינונית ביום בלי להרגיש חסום), ותקציב
	// AI חודשי כולל של $150 (נקבע כברירת מחדל זהירה, ניתן לכוונן).
	const DEFAULT_DAILY_AI_CALL_LIMIT = 40;
	const DEFAULT_MONTHLY_AI_BUDGET   = 150;

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
		register_setting( self::OPTION_GROUP, self::OPTION_DAILY_AI_CALL_LIMIT, array(
			'sanitize_callback' => $int_sanitizer,
			'default'           => self::DEFAULT_DAILY_AI_CALL_LIMIT,
		) );
		register_setting( self::OPTION_GROUP, self::OPTION_NARKIS_CREDITS, array(
			'sanitize_callback' => $int_sanitizer,
			'default'           => self::DEFAULT_NARKIS_CREDITS,
		) );
		register_setting( self::OPTION_GROUP, self::OPTION_NURIT_CREDITS, array(
			'sanitize_callback' => $int_sanitizer,
			'default'           => self::DEFAULT_NURIT_CREDITS,
		) );
		register_setting( self::OPTION_GROUP, self::OPTION_NURIT_YEARLY_CREDITS, array(
			'sanitize_callback' => $int_sanitizer,
			'default'           => self::DEFAULT_NURIT_YEARLY_CREDITS,
		) );
		register_setting( self::OPTION_GROUP, self::OPTION_MONTHLY_AI_BUDGET, array(
			// תקציב בדולרים, יכול להיות עם אגורות (150.5) - לא absint כמו
			// שאר המגבלות (שהן כולן ספירת סשנים/קריאות, מספרים שלמים).
			'sanitize_callback' => function ( $value ) {
				$float = (float) $value;
				return $float > 0 ? round( $float, 2 ) : 0.0;
			},
			'default'           => self::DEFAULT_MONTHLY_AI_BUDGET,
		) );
	}

	public static function get_daily_ai_call_limit() {
		return (int) get_option( self::OPTION_DAILY_AI_CALL_LIMIT, self::DEFAULT_DAILY_AI_CALL_LIMIT );
	}

	public static function get_monthly_ai_budget_usd() {
		return (float) get_option( self::OPTION_MONTHLY_AI_BUDGET, self::DEFAULT_MONTHLY_AI_BUDGET );
	}

	public static function get_narkis_plan_credits() {
		return (int) get_option( self::OPTION_NARKIS_CREDITS, self::DEFAULT_NARKIS_CREDITS );
	}

	public static function get_nurit_plan_credits() {
		return (int) get_option( self::OPTION_NURIT_CREDITS, self::DEFAULT_NURIT_CREDITS );
	}

	public static function get_nurit_yearly_plan_credits() {
		return (int) get_option( self::OPTION_NURIT_YEARLY_CREDITS, self::DEFAULT_NURIT_YEARLY_CREDITS );
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
			<p>ארבעת השדות הראשונים חלים כרגע רק על משתמשים אנונימיים (ר' הערה בקוד) - נספרים בדפדפן של המשתמש (localStorage), לא בשרת. שני השדות האחרונים (מגבלת קריאות יומית ותקציב חודשי) כן נאכפים בצד השרת.</p>
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
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_ANON_MSG_LIMIT ); ?>">מגבלת סשנים (קבלת מסר) - אנונימי</label></th>
						<td>
							<input type="number" min="0" step="1" id="<?php echo esc_attr( self::OPTION_ANON_MSG_LIMIT ); ?>" name="<?php echo esc_attr( self::OPTION_ANON_MSG_LIMIT ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_ANON_MSG_LIMIT, self::DEFAULT_ANON_MSG_LIMIT ) ); ?>" class="small-text" />
							<p class="description">כמה <strong>סשנים</strong> (שאלה אחת שנשאלה בתיבת הטקסט הראשית, עד 3 קלפים בכל סשן) במסלול "קבלת מסר" משתמש אנונימי יכול לפתוח לפני שמוצג קיר הרשמה - לא מספר הודעות/קלפים בפועל.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_ANON_CHAT_LIMIT ); ?>">מגבלת סשנים (שיחה פתוחה) - אנונימי</label></th>
						<td>
							<input type="number" min="0" step="1" id="<?php echo esc_attr( self::OPTION_ANON_CHAT_LIMIT ); ?>" name="<?php echo esc_attr( self::OPTION_ANON_CHAT_LIMIT ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_ANON_CHAT_LIMIT, self::DEFAULT_ANON_CHAT_LIMIT ) ); ?>" class="small-text" />
							<p class="description">כמה <strong>סשנים</strong> במסלול "שיחה פתוחה" משתמש אנונימי יכול לפתוח לפני שמוצג קיר הרשמה - לא מספר השאלות שנשלחות בתוך השיחה.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_REG_MSG_LIMIT ); ?>">מגבלת סשנים (קבלת מסר) - רשום (מסלול חינמי)</label></th>
						<td>
							<input type="number" min="0" step="1" id="<?php echo esc_attr( self::OPTION_REG_MSG_LIMIT ); ?>" name="<?php echo esc_attr( self::OPTION_REG_MSG_LIMIT ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_REG_MSG_LIMIT, self::DEFAULT_REG_MSG_LIMIT ) ); ?>" class="small-text" />
							<p class="description">נאכף בפועל: זה גם מספר הקרדיטים (סשנים) שמשתמש רשום במסלול "חינמי" מקבל (ר' DCard_Plans::free_plan_credits) - קלף שני/שלישי באותו סשן, וכל הודעות השיחה סביבו, לא מורידים קרדיט נוסף.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_REG_CHAT_LIMIT ); ?>">מגבלת סשנים (שיחה פתוחה) - רשום</label></th>
						<td>
							<input type="number" min="0" step="1" id="<?php echo esc_attr( self::OPTION_REG_CHAT_LIMIT ); ?>" name="<?php echo esc_attr( self::OPTION_REG_CHAT_LIMIT ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_REG_CHAT_LIMIT, self::DEFAULT_REG_CHAT_LIMIT ) ); ?>" class="small-text" />
							<p class="description">שמור לשלב הבא - עדיין אין הבדל בפועל בין מסלולים לפי המספר הזה ספציפית (ר' הערה למעלה - מסלול "חינמי" נשלט כרגע ע"י OPTION_REG_MSG_LIMIT בלבד, לשני סוגי הסשן).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_NARKIS_CREDITS ); ?>">קרדיטים (סשנים) במסלול "נרקיס" (תשלום חודשי)</label></th>
						<td>
							<input type="number" min="0" step="1" id="<?php echo esc_attr( self::OPTION_NARKIS_CREDITS ); ?>" name="<?php echo esc_attr( self::OPTION_NARKIS_CREDITS ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_NARKIS_CREDITS, self::DEFAULT_NARKIS_CREDITS ) ); ?>" class="small-text" />
							<p class="description">כמה קרדיטים (סשנים) מקבל לקוח ששילם על מסלול "נרקיס" (מק"ט 601) לחידוש החודשי שלו.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_NURIT_CREDITS ); ?>">קרדיטים (סשנים) במסלול "נורית" (תשלום חודשי)</label></th>
						<td>
							<input type="number" min="0" step="1" id="<?php echo esc_attr( self::OPTION_NURIT_CREDITS ); ?>" name="<?php echo esc_attr( self::OPTION_NURIT_CREDITS ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_NURIT_CREDITS, self::DEFAULT_NURIT_CREDITS ) ); ?>" class="small-text" />
							<p class="description">ברירת המחדל (999999) מתפרשת בקוד כ"ללא הגבלה" - כל מספר מ-999999 ומעלה נחשב ללא הגבלה. להגביל בפועל - להזין מספר קרדיטים אמיתי (למשל 100).</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_NURIT_YEARLY_CREDITS ); ?>">קרדיטים (סשנים) במסלול "נורית (שנתי)"</label></th>
						<td>
							<input type="number" min="0" step="1" id="<?php echo esc_attr( self::OPTION_NURIT_YEARLY_CREDITS ); ?>" name="<?php echo esc_attr( self::OPTION_NURIT_YEARLY_CREDITS ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_NURIT_YEARLY_CREDITS, self::DEFAULT_NURIT_YEARLY_CREDITS ) ); ?>" class="small-text" />
							<p class="description">אותו עקרון כמו "נורית": 999999 ומעלה = ללא הגבלה, מספר קטן יותר = הגבלה בפועל.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_DAILY_AI_CALL_LIMIT ); ?>">מגבלת קריאות AI ביום - משתמש רשום בודד</label></th>
						<td>
							<input type="number" min="0" step="1" id="<?php echo esc_attr( self::OPTION_DAILY_AI_CALL_LIMIT ); ?>" name="<?php echo esc_attr( self::OPTION_DAILY_AI_CALL_LIMIT ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_DAILY_AI_CALL_LIMIT, self::DEFAULT_DAILY_AI_CALL_LIMIT ) ); ?>" class="small-text" />
							<p class="description">נאכף בצד השרת (ר' DCard_Usage_Logs::count_calls_today): כשמשתמש רשום בודד עובר את המספר הזה של קריאות AI בפועל (שליפת קלף + כל הודעה בשיחה) <strong>באותו יום</strong>, הוא מקבל הודעה מנומסת לחזור מחר - בלי לפתוח קיר תשלום. 0 = בלי מגבלה.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_MONTHLY_AI_BUDGET ); ?>">תקציב AI חודשי כולל ($)</label></th>
						<td>
							<input type="number" min="0" step="0.01" id="<?php echo esc_attr( self::OPTION_MONTHLY_AI_BUDGET ); ?>" name="<?php echo esc_attr( self::OPTION_MONTHLY_AI_BUDGET ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_MONTHLY_AI_BUDGET, self::DEFAULT_MONTHLY_AI_BUDGET ) ); ?>" class="small-text" />
							<p class="description">סכום כולל (כל המשתמשים וכל המודלים יחד) לחודש קלנדרי. בחציית 80% ו-100% נשלחת התראת מייל אחת לכתובת הניהול (admin_email) - <strong>בלי חסימה אוטומטית של שימוש</strong> (נבחר במכוון כצעד ראשון זהיר). 0 = בלי מעקב/התראה.</p>
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
