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
 * תיקון §6.10.2 (מסך "ניהול · דף המכסות"): "ללא הגבלה" הפך לתווית-תצוגה
 * בלבד (תיבת checkbox נפרדת, OPTION_X_UNLIMITED_MSG/CHAT), לא נגזר יותר
 * מהמספר (999999) - credits_for_plan()/has_credits()/deduct_credit()
 * ממשיכים תמיד לאכוף את המספר האמיתי, בלי קשר לתווית. גם שם התצוגה,
 * המחיר וה-ProductID בקארדקום עברו לשדות ב-DCard_Quotas (admin), לא קוד
 * קשיח - כך שינוי שלהם לא דורש deploy.
 *
 * הרחבה למסלול נוסף = שורה אחת כאן (plan_period_days) + שורה אחת ב-
 * DCard_Quotas (plan_fields_config), לא חיפוש-והחלפה בכמה קבצים.
 */
class DCard_Plans {

	const FREE         = 'free';
	const NARKIS       = 'narkis';
	const NURIT        = 'nurit';
	const NURIT_YEARLY = 'nurit_yearly'; // אותם קרדיטים כמו NURIT, רק תוקף 365 יום במקום 30
	// סעיף 6.11: מסלול בתשלום שפג/בוטל ולא חודש - עובר לכאן, לא ל-FREE.
	// 0 קרדיטים קבועים (כמו FREE), אבל לא מסלול לרכישה ולא ברשימת
	// paid_plan_ids() - "פסאודו-מסלול" של מצב חשבון, לא מוצר.
	const CANCELLED    = 'cancelled';

	/**
	 * משך תוקף בימים לכל מסלול בתשלום - קבוע לפי המסלול (§6.10.2: "תקופה
	 * (טקסט קבוע, לא שדה)"), לא נגזר מה-ProductID (שהוא עצמו עכשיו שדה
	 * ב-admin, ר' DCard_Quotas) ולא ניתן לעריכה.
	 */
	private static function plan_period_days() {
		return array(
			self::NARKIS       => 30,
			self::NURIT        => 30,
			self::NURIT_YEARLY => 365,
		);
	}

	/**
	 * שלושת המסלולים בתשלום שה-admin מציג/עורך (ר' DCard_Quotas::
	 * paid_plan_ids_config) - רשימה קבועה (לא "הוספת מסלול" גנרית, ר'
	 * ההערה ב-DCard_Quotas), אבל כל השדות שלהם (שם לתצוגה/מחיר/ProductID/
	 * ללא-הגבלה) כן ניתנים לעריכה.
	 */
	public static function paid_plan_ids() {
		return array( self::NARKIS, self::NURIT, self::NURIT_YEARLY );
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
				'label'        => self::label_for_plan( self::NARKIS ),
				'credits_msg'  => self::narkis_msg_credits(),
				'credits_chat' => self::narkis_chat_credits(),
			),
			self::NURIT        => array(
				'label'        => self::label_for_plan( self::NURIT ),
				'credits_msg'  => self::nurit_msg_credits(),
				'credits_chat' => self::nurit_chat_credits(),
			),
			self::NURIT_YEARLY => array(
				'label'        => self::label_for_plan( self::NURIT_YEARLY ),
				'credits_msg'  => self::nurit_yearly_msg_credits(),
				'credits_chat' => self::nurit_yearly_chat_credits(),
			),
			self::CANCELLED    => array(
				'label'        => self::label_for_plan( self::CANCELLED ),
				'credits_msg'  => 0,
				'credits_chat' => 0,
			),
		);
	}

	/**
	 * תיקון לולאה (דווח בפועל): משתמש שנרשם וקיבל מסלול free היה מקבל
	 * current_credits=0 (ערך קשיח), שגרם לכישלון חוזר בכל פעולה ול"לופ"
	 * של פתיחת פופאפ ההרשמה. עכשיו דינמי - נשלף מההגדרה הקיימת ב-
	 * Settings > ניהול מכסות ("מקס' מסרים" בשורת "חינמי").
	 */
	private static function free_plan_msg_credits() {
		return (int) get_option( DCard_Quotas::OPTION_REG_MSG_LIMIT, DCard_Quotas::DEFAULT_REG_MSG_LIMIT );
	}

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

	/**
	 * תיקון §6.10.2: "ללא הגבלה" הוא תווית-תצוגה בלבד, נקבע עכשיו ע"י
	 * תיבת checkbox ייעודית לכל מסלול בתשלום (לא עוד "credits >= 999999") -
	 * כך שהמספר האמיתי יכול להיות קטן (למשל 100, מכסת שימוש הוגן) בלי
	 * להיחשב בטעות "לא ללא הגבלה". free/narkis תמיד false (אין להם תיבה).
	 */
	public static function is_unlimited( $plan_id, $type = 'msg' ) {
		$option = DCard_Quotas::unlimited_option_name( $plan_id, $type );
		if ( ! $option ) {
			return false;
		}
		return (bool) get_option( $option, DCard_Quotas::default_unlimited( $plan_id, $type ) );
	}

	public static function label_for_plan( $plan_id ) {
		$option = DCard_Quotas::label_option_name( $plan_id );
		if ( $option ) {
			$default = DCard_Quotas::default_label( $plan_id );
			$label   = (string) get_option( $option, $default );
			return '' !== trim( $label ) ? $label : $default;
		}
		$fallback = array(
			self::FREE      => 'חינמי',
			self::CANCELLED => 'מבוטל',
		);
		return isset( $fallback[ $plan_id ] ) ? $fallback[ $plan_id ] : $plan_id;
	}

	public static function price_for_plan( $plan_id ) {
		$option = DCard_Quotas::price_option_name( $plan_id );
		return $option ? (float) get_option( $option, 0 ) : 0.0;
	}

	public static function product_id_for_plan( $plan_id ) {
		$option = DCard_Quotas::product_id_option_name( $plan_id );
		return $option ? trim( (string) get_option( $option, DCard_Quotas::default_product_id( $plan_id ) ) ) : '';
	}

	public static function is_plan_active( $plan_id ) {
		$option = DCard_Quotas::active_option_name( $plan_id );
		return $option ? (bool) get_option( $option, true ) : true;
	}

	/**
	 * מיפוי מק"ט (Cardcom ExtReadInvoiceLines_ProductID) למסלול - דינמי
	 * עכשיו (נקרא מהשדה "ProductID בקארדקום" שה-admin מכניס בדף המכסות,
	 * ר' DCard_Quotas), לא קבוע קשיח בקוד. null אם המק"ט לא תואם אף מסלול
	 * (בתשלום) מוכר - עסקת תשלום בפועל עם מק"ט לא מוכר היא מצב שצריך
	 * להיבדק, לא להיפול אוטומטית למסלול החינמי.
	 */
	public static function map_product_id_to_plan( $product_id ) {
		$product_id = trim( (string) $product_id );
		if ( '' === $product_id ) {
			return null;
		}
		foreach ( self::paid_plan_ids() as $plan_id ) {
			if ( self::product_id_for_plan( $plan_id ) === $product_id ) {
				return $plan_id;
			}
		}
		return null;
	}

	/**
	 * משך תוקף בימים - לפי המסלול עצמו (לא עוד לפי ה-ProductID, ר' ההערה
	 * ב-plan_period_days). 30 (ברירת מחדל סבירה) למסלול לא מוכר - "לא
	 * אמור לקרות" בפועל, רק הגנה.
	 */
	public static function plan_duration_days( $plan_id ) {
		$days = self::plan_period_days();
		return isset( $days[ $plan_id ] ) ? (int) $days[ $plan_id ] : 30;
	}

	/**
	 * נתוני מסלול בתשלום מלאים, לשימוש GET /plans (ר' class-dcard-rest-
	 * controller.php) ולדף המכסות - מקור אחד לכל השדות, לא איסוף ידני בכל
	 * caller. ProductID לא נכלל בכוונה (לא רלוונטי ללקוח - קארדקום מזהה
	 * מסלול לפי כפתורי הרדיו בעמוד שלהם עצמם, לא מה-URL שלנו).
	 *
	 * עדכון §6.10.2: אין יותר 'period' - כל המסלולים מוצגים כ"לחודש"
	 * בלבד (ר' ההערה המלאה ב-class-dcard-quotas.php). לא משנה את משך
	 * התוקף בפועל (plan_duration_days) - זו רק הצגה.
	 */
	public static function public_plan_data( $plan_id ) {
		return array(
			'id'            => $plan_id,
			'label'         => self::label_for_plan( $plan_id ),
			'price'         => self::price_for_plan( $plan_id ),
			'credits_msg'   => self::credits_for_plan( $plan_id, 'msg' ),
			'credits_chat'  => self::credits_for_plan( $plan_id, 'chat' ),
			'unlimited_msg' => self::is_unlimited( $plan_id, 'msg' ),
			'unlimited_chat'=> self::is_unlimited( $plan_id, 'chat' ),
		);
	}

	/**
	 * @return array<int, array> רק מסלולים פעילים, לפי סדר paid_plan_ids().
	 */
	public static function active_public_plans() {
		$out = array();
		foreach ( self::paid_plan_ids() as $plan_id ) {
			if ( self::is_plan_active( $plan_id ) ) {
				$out[] = self::public_plan_data( $plan_id );
			}
		}
		return $out;
	}
}
