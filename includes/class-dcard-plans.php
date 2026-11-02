<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * מקור האמת היחיד לחוקי המסלולים (credits/unlimited) - שום מקום אחר
 * בפלאגין רושם את המספרים האלה בעצמו. כל קוד שצריך לדעת "כמה קרדיטים
 * יש למסלול X" או "האם X ללא הגבלה" קורא לכאן (credits_for_plan()/
 * is_unlimited()), לא למספר קבוע.
 *
 * תיקון (התבקש: "לכל מסלול יש להגדיר כמות מקסימלית למסרים וכמות
 * מקסימלית לשיחה מפתחת... ולא רק למסלול נרקיס") - כל מסלול מחזיק עכשיו
 * *שני* מאגרי קרדיטים נפרדים ('msg'=קבלת מסר, 'chat'=שיחה מפתחת) במקום
 * מאגר יחיד. הסיבה: סשן יכול להתחיל כ"קבלת מסר" ו"להעמיק" לשיחה פתוחה -
 * ר' ההסבר המלא ב-DCard_Users::deduct_credit ואיך זה מתקזז בפועל
 * ב-session_card_selected_inner/session_turn_inner.
 *
 * הרחבה למסלול נוסף = שורה אחת כאן, לא חיפוש-והחלפה בכמה קבצים.
 */
class DCard_Plans {

	const FREE         = 'free';
	const NARKIS       = 'narkis';
	const NURIT        = 'nurit';
	const NURIT_YEARLY = 'nurit_yearly'; // מק"ט 603 - אותם קרדיטים כמו NURIT, רק תוקף 365 יום במקום 30

	// ערך "ללא הגבלה" מוסכם - לא Infinity/null, כדי שאפשר לשמור אותו
	// כ-INT רגיל בעמודות current_credits_msg/current_credits_chat (INT)
	// מבלי טיפול מיוחד ב-DB.
	const UNLIMITED_CREDITS = 999999;

	/**
	 * מק"טים (ExtReadInvoiceLines_ProductID) כפי שאומתו בפועל מתוך לוג
	 * ה-IPN האמיתי של קארדקום (לא ניחוש substring כמו קודם) - כל אחד
	 * ממופה למסלול ולמשך התוקף שלו (ימים) באותו מקום, כדי שלא יהיו שני
	 * מקורות אמת נפרדים (אחד למסלול, אחד למשך) שיכולים להתגלגל.
	 */
	private static function cardcom_sku_map() {
		return array(
			'601' => array( 'plan' => self::NARKIS,       'days' => 30 ),
			'602' => array( 'plan' => self::NURIT,        'days' => 30 ),
			'603' => array( 'plan' => self::NURIT_YEARLY, 'days' => 365 ),
		);
	}

	/**
	 * @return array<string, array{label: string, credits_msg: int, credits_chat: int}>
	 */
	public static function all() {
		return array(
			self::FREE         => array(
				'label'        => 'חינמי',
				'credits_msg'  => self::free_plan_msg_credits(),
				'credits_chat' => self::free_plan_chat_credits(),
			),
			self::NARKIS       => array(
				'label'        => 'נרקיס',
				'credits_msg'  => self::narkis_msg_credits(),
				'credits_chat' => self::narkis_chat_credits(),
			),
			self::NURIT        => array(
				'label'        => 'נורית',
				'credits_msg'  => self::nurit_msg_credits(),
				'credits_chat' => self::nurit_chat_credits(),
			),
			self::NURIT_YEARLY => array(
				'label'        => 'נורית (שנתי)',
				'credits_msg'  => self::nurit_yearly_msg_credits(),
				'credits_chat' => self::nurit_yearly_chat_credits(),
			),
		);
	}

	/**
	 * תיקון לולאה (דווח בפועל): משתמש שנרשם וקיבל מסלול free היה מקבל
	 * current_credits=0 (ערך קשיח), שגרם לכישלון חוזר בכל פעולה ול"לופ"
	 * של פתיחת פופאפ ההרשמה. עכשיו דינמי - נשלף מההגדרה הקיימת ב-
	 * Settings > ניהול מכסות ("מקס' מסרים" בשורת "חינמי").
	 *
	 * *** שם המפתח: לא dcard_registered_msg_limit (כפי שהוצע כ-placeholder
	 * אם לא ייזכר השם המדויק) - המפתח כבר קיים בפועל וכבר יש לו עמוד
	 * הגדרות: DCard_Quotas::OPTION_REG_MSG_LIMIT ('dcard_reg_msg_limit',
	 * ברירת מחדל 10 - DCard_Quotas::DEFAULT_REG_MSG_LIMIT). שימוש בשם
	 * חדש היה מפצל את ההגדרה לשני מקומות נפרדים בלי קשר אמיתי ביניהם -
	 * זה שכבר קיים הוא בדיוק ההגדרה שהתיאור מבקש. ***
	 */
	private static function free_plan_msg_credits() {
		return (int) get_option( DCard_Quotas::OPTION_REG_MSG_LIMIT, DCard_Quotas::DEFAULT_REG_MSG_LIMIT );
	}

	/**
	 * תיקון (אותו פיצול מסר/שיחה) - OPTION_REG_CHAT_LIMIT כבר היה קיים
	 * בעמוד ההגדרות (לשימוש עתידי, ר' ההיסטוריה בקוד) אבל שום קוד לא
	 * קרא לו בפועל עד כה - עכשיו זה המקור למאגר הקרדיטים של "שיחה מפתחת"
	 * במסלול החינמי.
	 */
	private static function free_plan_chat_credits() {
		return (int) get_option( DCard_Quotas::OPTION_REG_CHAT_LIMIT, DCard_Quotas::DEFAULT_REG_CHAT_LIMIT );
	}

	private static function narkis_msg_credits() {
		return (int) get_option( DCard_Quotas::OPTION_NARKIS_MSG_CREDITS, DCard_Quotas::DEFAULT_NARKIS_MSG_CREDITS );
	}

	private static function narkis_chat_credits() {
		return (int) get_option( DCard_Quotas::OPTION_NARKIS_CHAT_CREDITS, DCard_Quotas::DEFAULT_NARKIS_CHAT_CREDITS );
	}

	private static function nurit_msg_credits() {
		return (int) get_option( DCard_Quotas::OPTION_NURIT_MSG_CREDITS, DCard_Quotas::DEFAULT_NURIT_MSG_CREDITS );
	}

	private static function nurit_chat_credits() {
		return (int) get_option( DCard_Quotas::OPTION_NURIT_CHAT_CREDITS, DCard_Quotas::DEFAULT_NURIT_CHAT_CREDITS );
	}

	private static function nurit_yearly_msg_credits() {
		return (int) get_option( DCard_Quotas::OPTION_NURIT_YEARLY_MSG_CREDITS, DCard_Quotas::DEFAULT_NURIT_YEARLY_MSG_CREDITS );
	}

	private static function nurit_yearly_chat_credits() {
		return (int) get_option( DCard_Quotas::OPTION_NURIT_YEARLY_CHAT_CREDITS, DCard_Quotas::DEFAULT_NURIT_YEARLY_CHAT_CREDITS );
	}

	public static function is_valid_plan( $plan_id ) {
		return isset( self::all()[ $plan_id ] );
	}

	/**
	 * $type: 'msg' (קבלת מסר) או 'chat' (שיחה מפתחת) - ר' הערת ה-class
	 * למעלה. כל קלט אחר מתייחס כ-'msg' (אותה התנהגות כמו קודם, כש-$type
	 * לא היה קיים בכלל).
	 */
	public static function credits_for_plan( $plan_id, $type = 'msg' ) {
		$plans = self::all();
		$key   = ( 'chat' === $type ) ? 'credits_chat' : 'credits_msg';

		if ( isset( $plans[ $plan_id ] ) ) {
			return (int) $plans[ $plan_id ][ $key ];
		}
		return (int) $plans[ self::FREE ][ $key ];
	}

	public static function is_unlimited( $plan_id, $type = 'msg' ) {
		return self::credits_for_plan( $plan_id, $type ) >= self::UNLIMITED_CREDITS;
	}

	public static function label_for_plan( $plan_id ) {
		$plans = self::all();
		return isset( $plans[ $plan_id ] ) ? $plans[ $plan_id ]['label'] : $plan_id;
	}

	/**
	 * מיפוי מק"ט (Cardcom ExtReadInvoiceLines_ProductID) למסלול - מק"טים
	 * מספריים מדויקים שאומתו מתוך לוג ה-IPN האמיתי של קארדקום (601/602/
	 * 603), לא substring על שם מילולי כמו בניחוש הקודם. null אם המק"ט
	 * לא מוכר - עסקת תשלום בפועל עם מק"ט לא מוכר היא מצב שצריך להיבדק,
	 * לא להיפול אוטומטית למסלול החינמי.
	 */
	public static function map_product_id_to_plan( $product_id ) {
		$sku = self::cardcom_sku_map();
		$product_id = trim( (string) $product_id );
		return isset( $sku[ $product_id ] ) ? $sku[ $product_id ]['plan'] : null;
	}

	/**
	 * משך תוקף בימים - לפי אותו מיפוי מק"ט מדויק (לא ניחוש substring
	 * "שנתי"/"yearly" כמו קודם). 30 (ברירת מחדל סבירה) למק"ט לא מוכר -
	 * map_product_id_to_plan() כבר דוחה את העסקה לפני שמגיעים לכאן בכלל
	 * במקרה כזה, אז זה למעשה "לא אמור לקרות", רק הגנה.
	 */
	public static function plan_duration_days( $product_id ) {
		$sku = self::cardcom_sku_map();
		$product_id = trim( (string) $product_id );
		return isset( $sku[ $product_id ] ) ? (int) $sku[ $product_id ]['days'] : 30;
	}
}
