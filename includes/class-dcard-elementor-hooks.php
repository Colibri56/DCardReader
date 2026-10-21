<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * *** למה הקובץ הזה קיים (חשוב לקרוא לפני לנסות להבין את הקוד): ***
 *
 * /register (class-dcard-rest-controller.php) הוא Webhook שאלמנטור שולחת
 * בעצמה, שרת-לשרת, מתוך ה-PHP של וורדפרס - *לא* קריאה שהדפדפן של המשתמש
 * שולח. כלומר התשובה (ה-JSON עם deviceToken) שה-route הזה מחזיר חוזרת
 * ל-PHP של אלמנטור עצמה, לא לדפדפן בשום צורה - אין שום דרך "להזריק"
 * אותה לתגובת ה-AJAX שהדפדפן מקבל, כי זו שתי בקשות HTTP נפרדות לגמרי,
 * שהדפדפן בכלל לא מודע לשנייה מהן.
 *
 * הדרך היחידה להחזיר משהו לדפדפן באמת היא להתערב *באותה בקשה* שהדפדפן
 * כן שולח - ה-AJAX של אלמנטור עצמו ששולח את הטופס. אלמנטור Pro חושף
 * בדיוק לצורך זה את ה-action 'elementor_pro/forms/new_record', שרץ
 * באותה בקשת AJAX (לפני שהתשובה נשלחת בחזרה לדפדפן), עם $ajax_handler
 * שיש לו מתודה בדיוק בשביל זה: add_response_data($key, $value) - כל
 * מה שמתווסף כך מגיע בפועל לדפדפן בתוך האובייקט response שמועבר
 * ל-event 'submit_success' (ר' ה-JS המתאים ב-elementor-code.html).
 *
 * לכן: ה-hook הזה עושה את ההרשמה/הנפקת הטוקן *בעצמו*, ישירות מתוך שדות
 * הטופס (לא קורא ל-/register בכלל) - ר' ההערה המלאה על race אפשרי אם
 * גם ה-Action "Webhook" של אותו טופס נשאר מוגדר באלמנטור, ב-
 * DCard_Users::register_and_issue_device_token().
 *
 * *** מה שעדיין לא ידוע בלי לראות את הטופס בפועל: שם השדות המדויקים
 * (id) שהוגדרו לו ב-Elementor Form Builder. הזיהוי למטה (וריאציה על
 * חיפוש substring ב-id של כל שדה) מניח שמות סבירים ("email"/"name"/
 * "phone"/"gender" בתוך ה-id) - אם ה-id בפועל שונה מהותית, צריך להתאים
 * את ההתאמה הזו, או פשוט לקרוא בלוג (WooCommerce > Status > Logs,
 * מקור dcard-ai-reader) את כל השדות שהתקבלו בפועל ולהתאים לפיהם. ***
 */
class DCard_Elementor_Hooks {

	// פופאפ 4753 - טופס תשלום סופי שאחריו המשתמש עובר לקארדקום. פופאפ
	// 4749 (Grace/Lead) וכל טופס אחר הם ה-else: לא קיים כאן "רשימת לא-
	// תשלום" סגורה - ברירת המחדל הבטוחה היא "לא תשלום" (ר' is_payment_
	// popup למטה), כדי שאי-וודאות בזיהוי הפופאפ לעולם לא תחסום בטעות
	// את הזרימה הרגילה (Grace/שליפת קלף) לכל שאר הטפסים.
	const PAYMENT_POPUP_ID = 4753;

	public static function register() {
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'on_new_record' ), 10, 2 );
	}

	/**
	 * *** לא מאומת live: $record->get('post_id') הוא הניחוש הסביר ביותר
	 * ל"מזהה הפוסט שהטופס הזה רץ בתוכו" לפי ה-API התיעודי של Elementor
	 * Pro Forms, ופופאפ של אלמנטור הוא בעצמו post מסוג elementor_library -
	 * כך שה-ID הזה צפוי להיות בדיוק 4749/4753. אם זה לא תואם בפועל (למשל
	 * מחזיר את ה-ID של העמוד שעליו מוטבע הפופאפ, לא של הפופאפ עצמו) -
	 * אפשר לבדוק ב-console.log של ה-JS (ר' elementor-code.html) אם
	 * dcardFormKind מגיע 'payment'/'lead' נכון, ולתקן כאן לפי מה שבאמת
	 * מזהה את 4753 (למשל $record->get_form_settings('form_name') אם
	 * לטופס התשלום יש שם מובחן בעורך). ***
	 */
	private static function is_payment_popup( $record ) {
		$post_id = method_exists( $record, 'get' ) ? (int) $record->get( 'post_id' ) : 0;
		return self::PAYMENT_POPUP_ID === $post_id;
	}

	public static function on_new_record( $record, $ajax_handler ) {
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}

		$raw_fields = $record->get( 'fields' );
		if ( ! is_array( $raw_fields ) ) {
			return;
		}

		$email = ''; $name = ''; $phone = ''; $gender = '';

		foreach ( $raw_fields as $field ) {
			$field_id    = isset( $field['id'] ) ? strtolower( (string) $field['id'] ) : '';
			$field_value = isset( $field['value'] ) ? $field['value'] : '';

			if ( '' === trim( (string) $field_value ) ) {
				continue;
			}

			if ( false !== strpos( $field_id, 'email' ) ) {
				$email = $field_value;
			} elseif ( false !== strpos( $field_id, 'phone' ) ) {
				$phone = $field_value;
			} elseif ( false !== strpos( $field_id, 'gender' ) ) {
				$gender = $field_value;
			} elseif ( false !== strpos( $field_id, 'name' ) ) {
				$name = $field_value;
			}
		}

		$email = $email ? sanitize_email( $email ) : '';
		if ( ! $email || ! is_email( $email ) ) {
			// לא טופס ההרשמה (אין בו שדה email בכלל - למשל טופס ה-onboarding
			// הנפרד, שם+מגדר בלבד) - מתעלמים בשקט, לא דגל שגיאה. ה-hook הזה
			// רץ על *כל* טופס אלמנטור באתר, לא רק טופס ההרשמה.
			//
			// חריג מכוון: פופאפ 4749 (Grace) *אמור* לאסוף email (ר' triggerRegistrationWall
			// ב-JS) - אם ההתעלמות השקטה הזו קרתה בכל זאת עבורו, זה תקוע,
			// לא תקין (הטופס לא נסגר בצד הלקוח - ר' submit_success guard).
			// לוג ממוקד, רק למקרה הזה, עם ה-id/value הגולמיים של כל שדה -
			// כדי לבדוק אם ה-"Field ID" שהוגדר בעורך אלמנטור לשדה האימייל
			// בפופאפ 4749 בפועל לא מכיל את המחרוזת "email" (למשל id שנוצר
			// אוטומטית כמו field_6a1b2c), בלי לנחש את זה בעיניים עצומות.
			$post_id = method_exists( $record, 'get' ) ? (int) $record->get( 'post_id' ) : 0;
			if ( 4749 === $post_id ) {
				DCard_Logger::warning( 'elementor_pro/forms/new_record (popup 4749) - no email field detected, registration/token issuance skipped', array(
					'raw_fields' => $raw_fields,
				) );
			}
			return;
		}

		$name   = sanitize_text_field( (string) $name );
		$phone  = sanitize_text_field( (string) $phone );
		$gender = in_array( $gender, array( 'male', 'female' ), true ) ? $gender : '';

		$result = DCard_Users::register_and_issue_device_token( $email, $name, $phone, $gender );
		if ( null === $result ) {
			DCard_Logger::error( 'elementor_pro/forms/new_record - register_and_issue_device_token failed', array( 'email' => $email ) );
			return;
		}

		if ( is_object( $ajax_handler ) && method_exists( $ajax_handler, 'add_response_data' ) ) {
			$ajax_handler->add_response_data( 'dcardUserId', $result['user_id'] );
			$ajax_handler->add_response_data( 'dcardDeviceToken', $result['device_token'] );
			$ajax_handler->add_response_data( 'dcardCurrentPlan', $result['current_plan'] );
			$ajax_handler->add_response_data( 'dcardCredits', $result['credits'] );
			// תיקון: פופאפ 4753 (תשלום) לא נדרש לאסוף email מחדש - הפתרון
			// הוא שה-JS ממלא את שדה ה-email שלו אוטומטית מ-localStorage לפני
			// שהמשתמש בכלל שולח (ר' submit_success + populateEmailField
			// ב-elementor-code.html) - כאן רק מחזירים את ה-email שבאמת
			// נקלט בטופס הזה, כדי שה-JS ישמור אותו בעצמו (ולא יצטרך לנחש
			// איזה שדה ב-DOM הוא שדה ה-email).
			$ajax_handler->add_response_data( 'dcardEmail', $email );
			// ר' הערת is_payment_popup() למעלה - כך ה-JS (submit_success)
			// יודע אם לעצור אחרי שמירת הטוקן (תשלום - אלמנטור עצמו מנווט
			// לקארדקום) או להמשיך בזרימה הרגילה (Grace/כל טופס אחר).
			$ajax_handler->add_response_data( 'dcardFormKind', self::is_payment_popup( $record ) ? 'payment' : 'lead' );
		}
	}
}
