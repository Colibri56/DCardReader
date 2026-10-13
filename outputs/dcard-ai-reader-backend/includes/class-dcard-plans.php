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

	const FREE   = 'free';
	const NARKIS = 'narkis';
	const NURIT  = 'nurit';

	// ערך "ללא הגבלה" מוסכם - לא Infinity/null, כדי שאפשר לשמור אותו
	// כ-INT רגיל בעמודת current_credits (INT) מבלי טיפול מיוחד ב-DB.
	const UNLIMITED_CREDITS = 999999;

	/**
	 * @return array<string, array{label: string, credits: int}>
	 */
	public static function all() {
		return array(
			self::FREE   => array( 'label' => 'חינמי',    'credits' => self::free_plan_credits() ),
			self::NARKIS => array( 'label' => 'נרקיס',    'credits' => 15 ),
			self::NURIT  => array( 'label' => 'נורית',    'credits' => self::UNLIMITED_CREDITS ),
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
	 * מיפוי מק"ט (Cardcom ExtReadInvoiceLines_ProductID) למסלול - בדיוק
	 * כפי שהתבקש (substring, לא התאמה מדויקת): מכיל 'narkis' -> narkis,
	 * מכיל 'nurit' -> nurit. null אם אין התאמה (ולא "free" בשקט - עסקת
	 * תשלום בפועל עם מק"ט לא מוכר היא מצב שצריך להיבדק, לא להיפול
	 * אוטומטית למסלול החינמי).
	 */
	public static function map_product_id_to_plan( $product_id ) {
		$product_id = strtolower( (string) $product_id );
		if ( false !== strpos( $product_id, 'narkis' ) ) {
			return self::NARKIS;
		}
		if ( false !== strpos( $product_id, 'nurit' ) ) {
			return self::NURIT;
		}
		return null;
	}

	/**
	 * משך תוקף המסלול בימים - 365 רק אם המק"ט מכיל סימן מפורש לשנתי
	 * (כמה חלופות סבירות, לא ידוע בלי לראות מק"טים אמיתיים של קארדקום),
	 * אחרת 30 (חודשי, ברירת המחדל הסבירה). *** לא מאומת - אם למק"טים
	 * השנתיים בפועל יש תבנית אחרת, להוסיף אותה כאן. ***
	 */
	public static function plan_duration_days( $product_id ) {
		$product_id = strtolower( (string) $product_id );
		foreach ( array( 'yearly', 'annual', 'year', 'שנתי' ) as $yearly_hint ) {
			if ( false !== strpos( $product_id, $yearly_hint ) ) {
				return 365;
			}
		}
		return 30;
	}
}
