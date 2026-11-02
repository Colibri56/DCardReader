<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Freemium PLG funnel, step 1: usage quotas for anonymous vs. registered
 * users. This class only owns the admin settings page (top-level menu,
 * "קורא בקלפים - הגדרות") and the wp_options the limits live in, plus
 * localizing the two ANONYMOUS limits to the frontend. The actual
 * tracking/gatekeeping for anonymous users (localStorage counters +
 * triggerRegistrationWall()) lives in frontend/elementor-code.html; the
 * registered-user limits below ARE enforced server-side (ר' DCard_Users
 * ו-DCard_Plans).
 *
 * תיקון (התבקש: "לכל מסלול יש להגדיר כמות מקסימלית למסרים וכמות
 * מקסימלית לשיחה מפתחת... להציג את זה כטבלה ולא טור ארוך") - כל שדה
 * "קרדיטים" כאן פוצל לשני שדות נפרדים (מסר/שיחה מפתחת) לכל מסלול, ו-
 * render_page() מציג את כולם (כולל האנונימי, חינמי) כטבלה אחת עם עמודות
 * "מקס' מסרים" ו"מקס' שיחות מפתחות" - לא טור ארוך של שדות בודדים. ר'
 * ההסבר המלא ב-DCard_Plans על איך הקרדיטים האלה בפועל מנוצלים/מקוזזים.
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

	// קרדיטים לכל מסלול בתשלום, מפוצלים מסר/שיחה מפתחת (ר' הערת ה-class
	// למעלה). 999999 ומעלה = ללא הגבלה (זהה ל-DCard_Plans::UNLIMITED_
	// CREDITS - לא מפנים לקבוע של מחלקה אחרת כאן כדי לא להסתמך על סדר
	// טעינת הקבצים).
	const OPTION_NARKIS_MSG_CREDITS        = 'dcard_narkis_msg_credits';
	const OPTION_NARKIS_CHAT_CREDITS       = 'dcard_narkis_chat_credits';
	const OPTION_NURIT_MSG_CREDITS         = 'dcard_nurit_msg_credits';
	const OPTION_NURIT_CHAT_CREDITS        = 'dcard_nurit_chat_credits';
	const OPTION_NURIT_YEARLY_MSG_CREDITS  = 'dcard_nurit_yearly_msg_credits';
	const OPTION_NURIT_YEARLY_CHAT_CREDITS = 'dcard_nurit_yearly_chat_credits';

	// ברירות מחדל זמניות בלבד - אין שום משמעות "נכונה" ידועה מראש למגבלות
	// האלה, המנהל צריך לכוון אותן בעצמו בעמוד ההגדרות לפי מה שמתאים לעסק.
	// "נרקיס" (המסלול המוגבל) מקבל 15/15 כנקודת פתיחה (אותו מספר שהיה
	// קודם למאגר המשולב) - "נורית"/"נורית (שנתי)" נשארים ללא הגבלה בשני
	// הצירים כברירת מחדל, כמו שהיו קודם.
	const DEFAULT_ANON_MSG_LIMIT  = 1;
	const DEFAULT_ANON_CHAT_LIMIT = 5;
	const DEFAULT_REG_MSG_LIMIT   = 10;
	const DEFAULT_REG_CHAT_LIMIT  = 30;
	const DEFAULT_NARKIS_MSG_CREDITS        = 15;
	const DEFAULT_NARKIS_CHAT_CREDITS       = 15;
	const DEFAULT_NURIT_MSG_CREDITS         = 999999;
	const DEFAULT_NURIT_CHAT_CREDITS        = 999999;
	const DEFAULT_NURIT_YEARLY_MSG_CREDITS  = 999999;
	const DEFAULT_NURIT_YEARLY_CHAT_CREDITS = 999999;
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

		$int_options = array(
			self::OPTION_ANON_MSG_LIMIT             => self::DEFAULT_ANON_MSG_LIMIT,
			self::OPTION_ANON_CHAT_LIMIT            => self::DEFAULT_ANON_CHAT_LIMIT,
			self::OPTION_REG_MSG_LIMIT              => self::DEFAULT_REG_MSG_LIMIT,
			self::OPTION_REG_CHAT_LIMIT             => self::DEFAULT_REG_CHAT_LIMIT,
			self::OPTION_DAILY_AI_CALL_LIMIT        => self::DEFAULT_DAILY_AI_CALL_LIMIT,
			self::OPTION_NARKIS_MSG_CREDITS         => self::DEFAULT_NARKIS_MSG_CREDITS,
			self::OPTION_NARKIS_CHAT_CREDITS        => self::DEFAULT_NARKIS_CHAT_CREDITS,
			self::OPTION_NURIT_MSG_CREDITS          => self::DEFAULT_NURIT_MSG_CREDITS,
			self::OPTION_NURIT_CHAT_CREDITS         => self::DEFAULT_NURIT_CHAT_CREDITS,
			self::OPTION_NURIT_YEARLY_MSG_CREDITS   => self::DEFAULT_NURIT_YEARLY_MSG_CREDITS,
			self::OPTION_NURIT_YEARLY_CHAT_CREDITS  => self::DEFAULT_NURIT_YEARLY_CHAT_CREDITS,
		);

		foreach ( $int_options as $option_name => $default ) {
			register_setting( self::OPTION_GROUP, $option_name, array(
				'sanitize_callback' => $int_sanitizer,
				'default'           => $default,
			) );
		}

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

	public static function get_narkis_msg_credits() {
		return (int) get_option( self::OPTION_NARKIS_MSG_CREDITS, self::DEFAULT_NARKIS_MSG_CREDITS );
	}

	public static function get_narkis_chat_credits() {
		return (int) get_option( self::OPTION_NARKIS_CHAT_CREDITS, self::DEFAULT_NARKIS_CHAT_CREDITS );
	}

	public static function get_nurit_msg_credits() {
		return (int) get_option( self::OPTION_NURIT_MSG_CREDITS, self::DEFAULT_NURIT_MSG_CREDITS );
	}

	public static function get_nurit_chat_credits() {
		return (int) get_option( self::OPTION_NURIT_CHAT_CREDITS, self::DEFAULT_NURIT_CHAT_CREDITS );
	}

	public static function get_nurit_yearly_msg_credits() {
		return (int) get_option( self::OPTION_NURIT_YEARLY_MSG_CREDITS, self::DEFAULT_NURIT_YEARLY_MSG_CREDITS );
	}

	public static function get_nurit_yearly_chat_credits() {
		return (int) get_option( self::OPTION_NURIT_YEARLY_CHAT_CREDITS, self::DEFAULT_NURIT_YEARLY_CHAT_CREDITS );
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

	/**
	 * שורה אחת בטבלה - שם המסלול + שני שדות (מסר/שיחה מפתחת). אנונימי
	 * ו"חינמי" לא נאכפים/מתפרשים כ"ללא הגבלה" בכלל (הערך פשוט המספר
	 * שהוזן), לכן $allow_unlimited=false עבורם; מסלולי התשלום כן יכולים
	 * להיות "ללא הגבלה" (999999+), אז מוצג רמז לכך בתיאור.
	 */
	private static function render_plan_row( $label, $msg_option, $msg_default, $chat_option, $chat_default, $allow_unlimited ) {
		$msg_value  = get_option( $msg_option, $msg_default );
		$chat_value = get_option( $chat_option, $chat_default );
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<input type="number" min="0" step="1" id="<?php echo esc_attr( $msg_option ); ?>" name="<?php echo esc_attr( $msg_option ); ?>" value="<?php echo esc_attr( $msg_value ); ?>" class="small-text" />
			</td>
			<td>
				<input type="number" min="0" step="1" id="<?php echo esc_attr( $chat_option ); ?>" name="<?php echo esc_attr( $chat_option ); ?>" value="<?php echo esc_attr( $chat_value ); ?>" class="small-text" />
			</td>
			<td class="description">
				<?php echo $allow_unlimited ? '999999 ומעלה = ללא הגבלה בכל אחד מהשדות.' : ''; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * שורת כותרת-קבוצה (colspan על פני כל 4 העמודות) - מפרידה ויזואלית בין
	 * "לא משלמים" (אנונימי/חינמי, נאכפים בלי קשר למסלול תשלום) לבין
	 * "משלמים" (נרקיס/נורית/נורית שנתי, שדרכם עובר CARDCOM) - התבקש
	 * כ"שינוי קטן בטבלה שיהיה נוח יותר לקריאה".
	 */
	private static function render_group_header( $label ) {
		echo '<tr class="dcard-quotas-group-row"><th colspan="4" style="background:#f0f0f1;">' . esc_html( $label ) . '</th></tr>';
	}

	/**
	 * מערך-תצורה אחד לכל השורות, שמוזן ל-foreach (לא קריאה בודדת לכל שורה
	 * בגוף render_page()) - שינוי/הוספת מסלול עכשיו נוגע בשורה אחת כאן,
	 * לא בשני מקומות (גם תחזוקתית נוח יותר, כמו שהתבקש).
	 */
	private static function plan_rows_config() {
		return array(
			'לא משלמים' => array(
				array( 'אנונימי', self::OPTION_ANON_MSG_LIMIT, self::DEFAULT_ANON_MSG_LIMIT, self::OPTION_ANON_CHAT_LIMIT, self::DEFAULT_ANON_CHAT_LIMIT, false ),
				array( 'חינמי (רשום)', self::OPTION_REG_MSG_LIMIT, self::DEFAULT_REG_MSG_LIMIT, self::OPTION_REG_CHAT_LIMIT, self::DEFAULT_REG_CHAT_LIMIT, false ),
			),
			'משלמים'    => array(
				array( 'נרקיס (תשלום חודשי)', self::OPTION_NARKIS_MSG_CREDITS, self::DEFAULT_NARKIS_MSG_CREDITS, self::OPTION_NARKIS_CHAT_CREDITS, self::DEFAULT_NARKIS_CHAT_CREDITS, true ),
				array( 'נורית (תשלום חודשי)', self::OPTION_NURIT_MSG_CREDITS, self::DEFAULT_NURIT_MSG_CREDITS, self::OPTION_NURIT_CHAT_CREDITS, self::DEFAULT_NURIT_CHAT_CREDITS, true ),
				array( 'נורית (שנתי)', self::OPTION_NURIT_YEARLY_MSG_CREDITS, self::DEFAULT_NURIT_YEARLY_MSG_CREDITS, self::OPTION_NURIT_YEARLY_CHAT_CREDITS, self::DEFAULT_NURIT_YEARLY_CHAT_CREDITS, true ),
			),
		);
	}

	public static function render_page() {
		?>
		<div class="wrap" dir="rtl">
			<h1>קורא בקלפים - הגדרות מכסות (Freemium)</h1>
			<p>לכל מסלול - כמה <strong>סשנים</strong> (שאלה אחת, עד 3 קלפים + כל השיחה סביבם) מקבל לקוח במסלול "קבלת מסר" ובמסלול "שיחה פתוחה" בנפרד. ר' עמוד <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=projective_card&page=dcard-customers' ) ); ?>">לקוחות</a> לצפייה ביתרת הקרדיטים בפועל של כל לקוח. אנונימי נאכף בדפדפן (לא בשרת) - שאר השורות נאכפות בצד השרת.</p>
			<?php
			settings_errors();
			foreach ( self::take_notices() as $notice ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
			}
			?>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="wp-list-table widefat fixed striped" style="max-width: 820px;">
					<thead>
						<tr>
							<th scope="col">מסלול</th>
							<th scope="col">מקס' מסרים (קבלת מסר)</th>
							<th scope="col">מקס' שיחות מפתחות</th>
							<th scope="col">הערה</th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( self::plan_rows_config() as $group_label => $rows ) {
							self::render_group_header( $group_label );
							foreach ( $rows as $row ) {
								self::render_plan_row( $row[0], $row[1], $row[2], $row[3], $row[4], $row[5] );
							}
						}
						?>
					</tbody>
				</table>
				<p class="description" style="max-width: 820px;">
					איך קרדיט מתקזז בפועל בתוך סשן בודד: סשן ש<strong>נשאר</strong> "קבלת מסר" לכל אורכו מקזז קרדיט אחד מ"מסרים". סשן שמתחיל כ"שיחה פתוחה" מקזז קרדיט אחד מ"שיחות מפתחות". סשן ש<strong>עבר</strong> מ"קבלת מסר" ל"שיחה פתוחה" (לחיצה על "להמשיך ולהעמיק בשיחה", או המשך כתיבה חופשית אחרי מסר) מקזז קרדיט אחד מ"מסרים" <strong>וגם</strong> קרדיט אחד מ"שיחות מפתחות" - שני המונים יורדים על אותו סשן בודד.
				</p>
				<table class="form-table" role="presentation">
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
