<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * מקור האמת היחיד לחוקי המסלולים (credits/unlimited) - שום מקום אחר
 * בפלאגין רושם את המספרים האלה (15, 999999 וכו') בעצמו. כל קוד שצריך
 * לדעת "כמה קרדיטים יש למסלול X" או "האם X ללא הגבלה" קורא לכאן
 * (credits_for_plan()/is_unlimited()), לא למספר קבוע.
 *
 * הרחבה למסלול נוסף = שורה אחת כאן, לא חיפוש-והחלפה בכמה קבצים.
 */
class DCard_Plans {

	const FREE         = 'free';
	const NARKIS       = 'narkis';
	const NURIT        = 'nurit';
	const NURIT_YEARLY = 'nurit_yearly'; // מק"ט 603 - אותם קרדיטים כמו NURIT, רק תוקף 365 יום במקום 30

	// ערך "ללא הגבלה" מוסכם - לא Infinity/null, כדי שאפשר לשמור אותו
	// כ-INT רגיל בעמודת current_credits (INT) מבלי טיפול מיוחד ב-DB.
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
	 * @return array<string, array{label: string, credits: int}>
	 */
	public static function all() {
		return array(
			self::FREE         => array( 'label' => 'חינמי',         'credits' => self::free_plan_credits() ),
			self::NARKIS       => array( 'label' => 'נרקיס',         'credits' => 15 ),
			self::NURIT        => array( 'label' => 'נורית',         'credits' => self::UNLIMITED_CREDITS ),
			self::NURIT_YEARLY => array( 'label' => 'נורית (שנתי)',  'credits' => self::UNLIMITED_CREDITS ),
		);
	}

	/**
	 * תיקון לולאה (דווח בפועל): משתמש שנרשם וקיבל מסלול free היה מקבל
	 * current_credits=0 (ערך קשיח), שגרם לכישלון חוזר בכל פעולה ול"לופ"
	 * של פתיחת פופאפ ההרשמה. עכשיו דינמי - נשלף מההגדרה הקיימת ב-
	 * Settings > DCard Quotas ("מגבלת הודעות (קבלת מסר) - רשום").
	 *
	 * *** שם המפתח: לא dcard_registered_msg_limit (כפי שהוצע כ-placeholder
	 * אם לא ייזכר השם המדויק) - המפתח כבר קיים בפועל וכבר יש לו עמוד
	 * הגדרות: DCard_Quotas::OPTION_REG_MSG_LIMIT ('dcard_reg_msg_limit',
	 * ברירת מחדל 10 - DCard_Quotas::DEFAULT_REG_MSG_LIMIT). שימוש בשם
	 * חדש היה מפצל את ההגדרה לשני מקומות נפרדים בלי קשר אמיתי ביניהם -
	 * זה שכבר קיים הוא בדיוק ההגדרה שהתיאור מבקש. ***
	 */
	private static function free_plan_credits() {
		return (int) get_option( DCard_Quotas::OPTION_REG_MSG_LIMIT, DCard_Quotas::DEFAULT_REG_MSG_LIMIT );
	}

	public static function is_valid_plan( $plan_id ) {
		return isset( self::all()[ $plan_id ] );
	}

	public static function credits_for_plan( $plan_id ) {
		$plans = self::all();
		return isset( $plans[ $plan_id ] ) ? (int) $plans[ $plan_id ]['credits'] : (int) $plans[ self::FREE ]['credits'];
	}

	public static function is_unlimited( $plan_id ) {
		return self::credits_for_plan( $plan_id ) >= self::UNLIMITED_CREDITS;
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
