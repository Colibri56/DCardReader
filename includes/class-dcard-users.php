<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp_dcard_users - פרופיל משתמש + מצב מנוי. הורחב (שלב 3 - Cardcom +
 * anti account-sharing) ממודל "משתמש רשום לצורכי מכסה" פשוט לפרופיל מנוי
 * מלא: current_plan/current_credits/plan_expires_at (ר' DCard_Plans
 * להגדרת המסלולים עצמם - אין כאן שום מספר קשיח) ו-session_token לאכיפת
 * מכשיר יחיד.
 *
 * *** הערת שם קריטית, כדי לא להתבלבל בהמשך: עמודת session_token כאן היא
 * "טוקן מכשיר/התחברות" לכל המנוי - לא אותו דבר כמו session_token שכבר
 * קיים בפלאגין הזה בטבלת wp_dcard_sessions (שם זה ה-UUID של קריאת קלפים
 * בודדת, נפרד ואנונימי). שני השדות חולקים שם, אבל שום קשר אחר. כדי למנוע
 * בלבול בקוד, כל המתודות כאן קוראות לו "device_token" (גם אם עמודת ה-DB
 * עצמה, לפי הבקשה המדויקת, נקראת session_token). ***
 *
 * עדיין NOT a real WP user account (wp_users) - ר' ההערה המקורית שנשארה
 * רלוונטית: זה "is this browser/device a known paying identity", לא
 * מנגנון login מלא.
 */
class DCard_Users {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dcard_users';
	}

	public static function create_table() {
		global $wpdb;

		$table_name      = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		// current_credits (יחיד) הוחלף ב-current_credits_msg/current_credits_
		// chat (ר' הערת ה-class למעלה) - נשאר כאן, לא נמחק, כעמודה legacy
		// שרק migrate_split_credits_if_needed() למטה עדיין קורא ממנה (ל-
		// אתר שכבר היה חי עם מאגר יחיד); dbDelta לעולם לא מוחק עמודות, אז
		// אין טעם/צורך להסיר אותה מההגדרה כאן.
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL,
			name VARCHAR(100) NULL,
			phone VARCHAR(30) NULL,
			gender VARCHAR(10) NULL,
			current_plan VARCHAR(20) NOT NULL DEFAULT 'free',
			current_credits INT NOT NULL DEFAULT 0,
			current_credits_msg INT NOT NULL DEFAULT 0,
			current_credits_chat INT NOT NULL DEFAULT 0,
			plan_expires_at DATETIME NULL,
			next_credit_reset_at DATETIME NULL,
			cancelled_at DATETIME NULL,
			cancel_reason VARCHAR(190) NULL,
			cancel_requested_at DATETIME NULL,
			cardcom_recurring_id VARCHAR(40) NULL,
			payment_pending_at DATETIME NULL,
			payment_pending_used TINYINT(1) NOT NULL DEFAULT 0,
			session_token VARCHAR(64) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY session_token (session_token)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * מיגרציה חד-פעמית (לא תלויה ב-dcard_db_version - ר' ההסבר המלא
	 * ב-DCard_Activator) - אתר שכבר רץ עם מאגר קרדיטים יחיד (current_
	 * credits) מקבל את אותו מספר גם למסרים וגם לשיחות מפתחות, כדי שאף
	 * לקוח לא "יאבד" קרדיטים קיימים ברגע השדרוג. מוגן ב-wp_option ייעודי
	 * (לא ב-current_credits_msg/chat=0, כדי שלא יוחל בטעות שוב בעתיד על
	 * משתמש שבאמת מיצה את שני המאגרים שלו לגמרי בשימוש רגיל).
	 */
	public static function migrate_split_credits_if_needed() {
		if ( get_option( 'dcard_credits_split_migrated' ) ) {
			return;
		}

		global $wpdb;
		self::ensure_table_exists();

		$wpdb->query(
			"UPDATE " . self::table() . " SET current_credits_msg = current_credits, current_credits_chat = current_credits WHERE current_credits_msg = 0 AND current_credits_chat = 0 AND current_credits > 0"
		);

		update_option( 'dcard_credits_split_migrated', 1, false );
	}

	/**
	 * תיקון עמידות (ר' class-dcard-users.php הקודם): לא תלוי ב-dcard_db_version/
	 * DCard_Activator בכלל - בודק ישירות אם הטבלה קיימת, ויוצר אותה על
	 * המקום אם לא. נשאר כך גם אחרי שינוי המבנה: אם deploy חלקי משאיר
	 * עמודות חדשות (phone/gender/current_plan/...) חסרות על טבלה ישנה -
	 * dbDelta בעצמו יודע להוסיף עמודות חסרות לטבלה קיימת (ALTER, לא רק
	 * CREATE), אז קריאה חזרה ל-create_table() כאן מתקנת גם את המקרה הזה.
	 */
	public static function ensure_table_exists() {
		global $wpdb;
		$table = self::table();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $found !== $table ) {
			self::create_table();
		}
	}

	/**
	 * יצירה או עדכון לפי email - כמו קודם, אבל כעת גם מאתחל את המסלול
	 * (ברירת מחדל: DCard_Plans::FREE, אם לא סופק אחר) והקרדיטים המתאימים
	 * לו, במקום לקבוע is_registered=1 בלבד. $plan_id לא מאומת כאן נגד
	 * DCard_Plans::is_valid_plan() לשם ה-fallback הבטוח ל-FREE (לא זורק,
	 * לא שומר ערך לא מוכר).
	 */
	public static function upsert_by_email( $email, $name, $phone = '', $gender = '', $plan_id = null ) {
		global $wpdb;

		self::ensure_table_exists();

		$plan_id = ( $plan_id && DCard_Plans::is_valid_plan( $plan_id ) ) ? $plan_id : DCard_Plans::FREE;
		$now     = current_time( 'mysql', true );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM " . self::table() . " WHERE email = %s", $email )
		);

		if ( $existing_id ) {
			// עדכון פרופיל בלבד (שם/טלפון/מגדר) - לא דורס מסלול/קרדיטים
			// קיימים של משתמש שחוזר ונרשם בטופס שוב; שינוי מסלול הוא
			// תפקיד set_plan() (למשל מתוך ה-webhook הפיננסי), לא כאן.
			$wpdb->update(
				self::table(),
				array( 'name' => $name, 'phone' => $phone, 'gender' => $gender, 'updated_at' => $now ),
				array( 'id' => $existing_id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return (int) $existing_id;
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'email'                 => $email,
				'name'                  => $name,
				'phone'                 => $phone,
				'gender'                => $gender,
				'current_plan'          => $plan_id,
				'current_credits_msg'   => DCard_Plans::credits_for_plan( $plan_id, 'msg' ),
				'current_credits_chat'  => DCard_Plans::credits_for_plan( $plan_id, 'chat' ),
				'created_at'            => $now,
				'updated_at'            => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			DCard_Logger::error( 'Failed to insert dcard_users row', array( 'email' => $email, 'db_error' => $wpdb->last_error ) );
			return null;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * נקודת כניסה משותפת ל"הרשמה/התחברות" - upsert + הנפקת device_token
	 * חדש + שליפת השורה המלאה, בקריאה אחת. שתי דרכים נכנסות לכאן: ה-REST
	 * route הקיים (/register, לקריאות webhook ישירות/חיצוניות) וה-hook
	 * החדש על elementor_pro/forms/new_record (ר' class-dcard-elementor-
	 * hooks.php) - **בכוונה לא משולבות זו עם זו באותה שליחת טופס אחת**:
	 * אם שתיהן היו רצות על אותה שליחה, השנייה הייתה מנפיקה device_token
	 * חדש ומבטלת בכך את זה שהראשונה כבר החזירה לדפדפן (ר' issue_device_
	 * token - כל קריאה מחליפה את הטוקן הקודם). הפתרון שנבחר: ה-hook הוא
	 * הדרך היחידה שבאמת מזריקה את הטוקן לדפדפן (ר' ההסבר המלא אחרי
	 * הקוד), אז מומלץ להסיר את ה-Action "Webhook" מטופס ההרשמה
	 * באלמנטור לגמרי - /register נשאר קיים ותקין לשימושים אחרים, רק לא
	 * מופעל יותר על ידי הטופס הזה.
	 */
	public static function register_and_issue_device_token( $email, $name, $phone = '', $gender = '' ) {
		$user_id = self::upsert_by_email( $email, $name, $phone, $gender );
		if ( null === $user_id ) {
			return null;
		}

		$device_token = self::issue_device_token( $user_id );
		$user         = self::get_by_id( $user_id );

		return array(
			'user_id'      => $user_id,
			'device_token' => $device_token,
			'current_plan' => $user ? $user['current_plan'] : DCard_Plans::FREE,
			// 'credits' (יחיד) נשאר = מאגר המסרים, לתאימות לאחור עם קוד
			// קורא קיים שמציג "קרדיטים" כמספר יחיד - ר' ההסבר המלא ב-
			// account_status_inner ב-class-dcard-rest-controller.php.
			'credits'      => $user ? (int) $user['current_credits_msg'] : 0,
			'credits_msg'  => $user ? (int) $user['current_credits_msg'] : 0,
			'credits_chat' => $user ? (int) $user['current_credits_chat'] : 0,
		);
	}

	public static function get_by_id( $user_id ) {
		global $wpdb;
		self::ensure_table_exists();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", $user_id ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * מזהה משתמש רשום לפי X-Dcard-Device-Token בלבד - בלי שום צורך
	 * ב-user_id נפרד בכל endpoint (ה-header הזה כבר מצורף אוטומטית לכל
	 * קריאה מה-frontend - ר' dcardHeaders() ב-elementor-code.html). ר'
	 * session_card_selected_inner ב-class-dcard-rest-controller.php -
	 * זו הדרך שהמכסה (credits) נבדקת ונאכפת שם. null גם אם $device_token
	 * ריק (לא "מתאים" לכל שורה בלי טוקן) - בדיוק כמו verify_device_token.
	 */
	public static function get_by_device_token( $device_token ) {
		global $wpdb;
		self::ensure_table_exists();

		if ( ! $device_token ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE session_token = %s", $device_token ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	public static function get_by_email( $email ) {
		global $wpdb;
		self::ensure_table_exists();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE email = %s", $email ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	// -----------------------------------------------------------------
	// מסלול/קרדיטים/תפוגה - קורא ל-DCard_Plans לכל מספר, לא קשיח כאן.
	// -----------------------------------------------------------------

	/**
	 * מפעיל מסלול על משתמש (הרשמה ראשונית בתשלום, חידוש, או שינוי מסלול) -
	 * מאפס את current_credits למקסימום של המסלול החדש (לא מצטבר/שומר
	 * שארית מהמסלול הקודם) וקובע plan_expires_at. $expires_at מתקבל
	 * מוכן (DATETIME, UTC) מהקורא - החלטת "חודש/שנה מהיום" היא עניין של
	 * ה-webhook הפיננסי (תלוי מה קארדקום בפועל שולח), לא משהו שה-DB
	 * layer צריך לנחש.
	 */
	public static function set_plan( $user_id, $plan_id, $expires_at = null ) {
		global $wpdb;
		self::ensure_table_exists();

		if ( ! DCard_Plans::is_valid_plan( $plan_id ) ) {
			DCard_Logger::warning( 'DCard_Users::set_plan called with unknown plan_id - ignoring', array( 'user_id' => $user_id, 'plan_id' => $plan_id ) );
			return false;
		}

		// תיקון (todo.md, "חידוש וחליפת מסלול"): next_credit_reset_at
		// נקבע ל-now + 30 יום בכל קריאה ל-set_plan() - כולל בכל IPN מוצלח,
		// לא רק בהרשמה ראשונה - כך שהחידוש החודשי של מסלול שנתי נספר
		// מתאריך התשלום בפועל, לא מתאריך קבוע. ר' run_renewal_and_expiry_
		// check() למטה, שמשתמשת בשדה הזה בעיקר עבור מסלול שנתי (מסלול
		// חודשי "מתאפס לבד" בכל IPN דרך קריאה זו).
		//
		// תיקון (דווח בפועל: עסקה אושרה בקארדקום אבל המסלול לא עודכן, בלי
		// שום שגיאה בלוג): $wpdb->update() מחזיר false *רק* על שגיאת SQL
		// בפועל (למשל עמודה חסרה אחרי deploy שלא העלה את DCARD_DB_VERSION,
		// ר' ההערה המלאה שם) - לא על "0 שורות השתנו". בעבר הערך הזה נזרק
		// לגמרי, אז הקריאה כאן "הצליחה" כלפי כל הקוראים גם כששום UPDATE לא
		// קרה בפועל. עכשיו נבדק ונרשם במפורש - ר' גם payment_webhook_inner
		// שמשתמש בערך המוחזר כדי להחליט מה לדווח לקארדקום ולמנהל.
		$row = array(
			'current_plan'         => $plan_id,
			'current_credits_msg'  => DCard_Plans::credits_for_plan( $plan_id, 'msg' ),
			'current_credits_chat' => DCard_Plans::credits_for_plan( $plan_id, 'chat' ),
			'plan_expires_at'      => $expires_at,
			'next_credit_reset_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) ),
			'updated_at'           => current_time( 'mysql', true ),
		);
		$formats = array( '%s', '%d', '%d', '%s', '%s', '%s' );

		// סעיף 6.11: מעבר לכל מסלול אמיתי (לא CANCELLED עצמו, ר' הקריאה
		// מתוך run_renewal_and_expiry_check למעלה) מנקה סימוני ביטול
		// ישנים - למשל הרשמה מחדש אחרי ביטול, או שינוי ידני של admin.
		if ( DCard_Plans::CANCELLED !== $plan_id ) {
			$row['cancelled_at']         = null;
			$row['cancel_reason']        = null;
			$row['cancel_requested_at']  = null;
			$formats[]                   = '%s';
			$formats[]                   = '%s';
			$formats[]                   = '%s';
		}

		$result = $wpdb->update(
			self::table(),
			$row,
			array( 'id' => $user_id ),
			$formats,
			array( '%d' )
		);

		if ( false === $result ) {
			DCard_Logger::error( 'DCard_Users::set_plan - DB update failed, plan NOT actually changed', array(
				'user_id'  => $user_id,
				'plan_id'  => $plan_id,
				'db_error' => $wpdb->last_error,
			) );
			return false;
		}

		return true;
	}

	/**
	 * נקראת מ-dcard_daily_cleanup (ר' the-card-reader-core.php) - ההרחבה
	 * שהתבקשה ב-todo.md ("חידוש וחליפת מסלול"). סדר מכוון: קודם תפוגה
	 * (חליפה לחינמי), אחר כך איפוס חודשי - כדי שמשתמש שגם פג תוקפו וגם
	 * הגיע תאריך איפוס לא "יתאפס" לרגע לפני שהוא יורד לחינמי.
	 */
	public static function run_renewal_and_expiry_check() {
		global $wpdb;
		self::ensure_table_exists();
		$now = current_time( 'mysql', true );

		// 1. תפוגה: plan_expires_at + 3 ימי חסד עבר ועדיין לא שולם מחדש
		// (אחרת set_plan() מה-IPN כבר היה מעדכן plan_expires_at קדימה).
		// עדכון §6.11: עוברים ל-CANCELLED, לא ל-FREE - "משתמש שמנוי שלו
		// בוטל (או לא חודש) עובר למצב cancelled עם 0 שימושים, לא למסלול
		// החינמי". אותה התנהגות בלי קשר אם הביטול בוקש במפורש
		// (cancel_requested_at) או שהחיוב פשוט נכשל/לא חודש - שני המקרים
		// "פגו" מבחינת plan_expires_at, וזה כל מה שהשאילתה הזו בודקת.
		// cancelled_at נקבע כאן (לא ב-account/cancel, שעדיין רק "מבקש" -
		// ר' ההערה המלאה שם) כי זה הרגע שבו המנוי *בפועל* מפסיק.
		$expired_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM " . self::table() . " WHERE current_plan NOT IN (%s, %s) AND plan_expires_at IS NOT NULL AND DATE_ADD(plan_expires_at, INTERVAL 3 DAY) <= %s",
			DCard_Plans::FREE,
			DCard_Plans::CANCELLED,
			$now
		) );
		foreach ( $expired_ids as $user_id ) {
			// set_plan() עצמה כבר רושמת error אם ה-UPDATE נכשל - הלוג הזה
			// נרשם רק על הצלחה אמיתית, לא "בכל מקרה" (ר' ההערה המלאה ב-
			// set_plan() על התיקון הזה).
			if ( self::set_plan( (int) $user_id, DCard_Plans::CANCELLED, null ) ) {
				$wpdb->update( self::table(), array( 'cancelled_at' => $now ), array( 'id' => (int) $user_id ), array( '%s' ), array( '%d' ) );
				DCard_Logger::info( 'dcard_daily_cleanup - plan expired (3 days grace passed), moved to cancelled', array( 'user_id' => (int) $user_id ) );
			}
		}

		// 2. איפוס חודשי: רק משתמשים שעדיין בתשלום אחרי שלב 1 (מסלול חודשי
		// כבר "מתאפס לבד" בכל IPN דרך set_plan() - זה בעיקר רלוונטי
		// למסלול שנתי, ר' ההערה המלאה ב-todo.md).
		$due = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, current_plan FROM " . self::table() . " WHERE current_plan NOT IN (%s, %s) AND next_credit_reset_at IS NOT NULL AND next_credit_reset_at <= %s",
			DCard_Plans::FREE,
			DCard_Plans::CANCELLED,
			$now
		), ARRAY_A );
		foreach ( $due as $row ) {
			$plan_id = $row['current_plan'];
			$result  = $wpdb->update(
				self::table(),
				array(
					'current_credits_msg'  => DCard_Plans::credits_for_plan( $plan_id, 'msg' ),
					'current_credits_chat' => DCard_Plans::credits_for_plan( $plan_id, 'chat' ),
					'next_credit_reset_at' => gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +30 days' ) ),
					'updated_at'           => $now,
				),
				array( 'id' => (int) $row['id'] ),
				array( '%d', '%d', '%s', '%s' ),
				array( '%d' )
			);

			// תיקון (אותו דפוס בדיוק כמו set_plan()): לא עוד לוג "הצלחה"
			// בלי לבדוק את $result בפועל.
			if ( false === $result ) {
				DCard_Logger::error( 'dcard_daily_cleanup - monthly credit reset DB update failed', array(
					'user_id'  => (int) $row['id'],
					'plan_id'  => $plan_id,
					'db_error' => $wpdb->last_error,
				) );
				continue;
			}

			DCard_Logger::info( 'dcard_daily_cleanup - monthly credit reset', array( 'user_id' => (int) $row['id'], 'plan_id' => $plan_id ) );
		}
	}

	/**
	 * עדכון טלפון רק אם עדיין ריק אצל המשתמש - לא דורס טלפון שכבר הוזן
	 * (למשל בטופס ההרשמה) עם מה שקארדקום מחזירה בעסקה. ר' payment_
	 * webhook_inner ב-class-dcard-rest-controller.php.
	 */
	public static function update_phone_if_empty( $user_id, $phone ) {
		global $wpdb;
		self::ensure_table_exists();

		if ( ! $phone ) {
			return false;
		}

		$user = self::get_by_id( $user_id );
		if ( ! $user || ! empty( $user['phone'] ) ) {
			return false;
		}

		return false !== $wpdb->update(
			self::table(),
			array( 'phone' => $phone, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * §6.11 ("הפרטים שלי", Phase B) - עדכון שם/לשון פנייה/טלפון ע"י המשתמש
	 * עצמו (לא דרך webhook). ולידציית השם (DCARD_NAME_PATTERN) כבר נעשית
	 * ב-caller (class-dcard-rest-controller.php), כמו בכל שאר נקודות הכניסה
	 * שמקבלות שם - כאן רק כתיבה ל-DB, כמו update_phone_if_empty למעלה.
	 * טלפון ריק מותר (השדה "לא חובה"), בניגוד ל-update_phone_if_empty
	 * שלא דורס טלפון קיים - כאן זו עריכה מפורשת של המשתמש, אז דורסים
	 * תמיד למה שנשלח (כולל ריקון השדה).
	 */
	public static function update_profile( $user_id, $name, $gender, $phone ) {
		global $wpdb;
		self::ensure_table_exists();

		return false !== $wpdb->update(
			self::table(),
			array(
				'name'       => $name,
				'gender'     => in_array( $gender, array( 'male', 'female' ), true ) ? $gender : null,
				'phone'      => $phone,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $user_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * §webhook (21.11) - שומר את RecurringId של הוראת הקבע בקארדקום
	 * (מגיע בהתראת RecordType=MasterRecurring, ר' payment_webhook_inner).
	 * דרוש בעתיד כדי לבטל בפועל הוראת קבע מול ה-API של קארדקום (ר' ההערה
	 * המלאה ב-account_cancel_inner) - עד אז רק נשמר, לא נקרא משום מקום.
	 */
	public static function save_recurring_id( $user_id, $recurring_id ) {
		global $wpdb;
		self::ensure_table_exists();

		return false !== $wpdb->update(
			self::table(),
			array(
				'cardcom_recurring_id' => $recurring_id,
				'updated_at'           => current_time( 'mysql', true ),
			),
			array( 'id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * §6.10.4 "תיקון כתובת המייל" - עדכון עצמי (למשתמש שעוד לא שילם אף
	 * פעם בלבד; למי ששילם כבר, מעדכן ידנית המנהל, ר' account_update_
	 * email_inner ב-class-dcard-rest-controller.php).
	 */
	public static function update_email( $user_id, $new_email ) {
		global $wpdb;
		self::ensure_table_exists();

		return false !== $wpdb->update(
			self::table(),
			array(
				'email'      => $new_email,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * §6.11 "ביטול המנוי" - מצב ביניים (ר' ההערה המלאה ב-account_cancel_
	 * inner ב-class-dcard-rest-controller.php): שומר רק את הבקשה עצמה,
	 * לא משנה current_plan/plan_expires_at. המעבר בפועל ל-CANCELLED קורה
	 * רק דרך run_renewal_and_expiry_check בתפוגה.
	 */
	public static function request_cancellation( $user_id, $reason ) {
		global $wpdb;
		self::ensure_table_exists();

		return false !== $wpdb->update(
			self::table(),
			array(
				'cancel_requested_at' => current_time( 'mysql', true ),
				'cancel_reason'       => $reason ? $reason : null,
				'updated_at'          => current_time( 'mysql', true ),
			),
			array( 'id' => $user_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * §6.11 "ביטול המנוי" - מצב אמיתי (ר' cardcom_cancel_recurring_order
	 * ב-class-dcard-rest-controller.php): נקראת רק אחרי שקארדקום אישרה
	 * בפועל את עצירת הוראת הקבע (ResponseCode=0). שומרת cancelled_at +
	 * cancel_reason בלבד - לא current_plan ולא plan_expires_at, כי המנוי
	 * נשאר פעיל עד התאריך (run_renewal_and_expiry_check היא זו שמעבירה
	 * בפועל ל-CANCELLED כש-plan_expires_at עובר, בדיוק כמו היום).
	 */
	public static function confirm_cancellation( $user_id, $reason ) {
		global $wpdb;
		self::ensure_table_exists();

		return false !== $wpdb->update(
			self::table(),
			array(
				'cancelled_at' => current_time( 'mysql', true ),
				'cancel_reason' => $reason ? $reason : null,
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => $user_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * בדיקה לפני פריסת קלף: יש קרדיט פנוי *במאגר הרלוונטי*? $type: 'msg'
	 * (קבלת מסר) או 'chat' (שיחה מפתחת) - ר' הערת ה-class המלאה ב-
	 * DCard_Plans על הפיצול בין שני המאגרים ואיך הם מתקזזים. מסלולים ללא
	 * הגבלה *באותו $type* (ר' DCard_Plans::is_unlimited) תמיד "true" בלי
	 * לבדוק את המספר בפועל - כדי שלעולם לא יתקעו על מירוץ עדכון-קרדיט,
	 * גם אם העמודה בטבלה פיגרה מאחור מאיזושהי סיבה.
	 */
	public static function has_credits( $user_id, $type = 'msg' ) {
		$user = self::get_by_id( $user_id );
		if ( ! $user ) {
			return false;
		}
		if ( DCard_Plans::is_unlimited( $user['current_plan'], $type ) ) {
			return true;
		}
		$column = ( 'chat' === $type ) ? 'current_credits_chat' : 'current_credits_msg';
		return (int) $user[ $column ] > 0;
	}

	/**
	 * §(21.11, "אם המשתמש מתחיל שיחה לפני העדכון - לאפשר שיחה אחת"): עוטפת
	 * has_credits עם מעבר חד-פעמי בזמן שתשלום בטיפול (payment_pending_at,
	 * ר' start_payment_pending). *זה* השער החוסם היחיד בפועל בכל הפלאגין
	 * (ר' session_draw_card_inner) - שני has_credits() האחרים הם מידע
	 * בלבד, לא חוסמים (§7.2: "לא עוצרים שיחה באמצע"), ולכן רק כאן נכון
	 * לצרוך את המעבר החד-פעמי. "פעם אחת בלבד" נאכף ע"י payment_pending_used -
	 * מסומן ברגע שהמעבר אושר, לא מתאפס עד שהתשלום הבא מתחיל (start_
	 * payment_pending) או שה-IPN מגיע (clear_payment_pending).
	 */
	public static function has_credits_or_pending_pass( $user_id, $type = 'msg' ) {
		if ( self::has_credits( $user_id, $type ) ) {
			return true;
		}

		$user = self::get_by_id( $user_id );
		if ( ! $user || ! self::is_payment_pending_fresh( $user ) || ! empty( $user['payment_pending_used'] ) ) {
			return false;
		}

		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'payment_pending_used' => 1,
				'updated_at'           => current_time( 'mysql', true ),
			),
			array( 'id' => $user_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		DCard_Logger::info( 'has_credits_or_pending_pass - granted one free conversation while payment is pending', array(
			'user_id' => $user_id,
			'type'    => $type,
		) );

		return true;
	}

	/**
	 * §(21.11): נקרא ע"י POST /account/payment-pending-start, ממש לפני
	 * ניווט לקארדקום (ר' kbPaymentSubmit). payment_pending_used מתאפס כאן -
	 * כל מחזור תשלום חדש מקבל שיחת-חינם אחת משלו.
	 */
	public static function start_payment_pending( $user_id ) {
		global $wpdb;
		self::ensure_table_exists();

		return false !== $wpdb->update(
			self::table(),
			array(
				'payment_pending_at'   => current_time( 'mysql', true ),
				'payment_pending_used' => 0,
				'updated_at'           => current_time( 'mysql', true ),
			),
			array( 'id' => $user_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * §(21.11): נקרא מ-payment_webhook_inner ברגע שתשלום רגיל עדכן בהצלחה
	 * את המסלול (set_plan הצליח) - "כשה-IPN מגיע, הדגל מתנקה".
	 */
	public static function clear_payment_pending( $user_id ) {
		global $wpdb;
		self::ensure_table_exists();

		return false !== $wpdb->update(
			self::table(),
			array(
				'payment_pending_at'   => null,
				'payment_pending_used' => 0,
				'updated_at'           => current_time( 'mysql', true ),
			),
			array( 'id' => $user_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * §(21.11): "תוקף 30 דקות" - אותו חלון כמו AWAITING_PAYMENT_MAX_AGE_MS
	 * בפרונט (dcardIsAwaitingPaymentFresh), כדי שלא "ישאר תקוע" פתוח
	 * בשרת הרבה אחרי שהמשתמש כבר שכח מהתשלום. מקבל את שורת ה-$user
	 * המלאה (לא user_id) כי כל קוראיה כבר מחזיקים אותה ביד.
	 */
	public static function is_payment_pending_fresh( $user ) {
		if ( empty( $user['payment_pending_at'] ) ) {
			return false;
		}
		$age_seconds = time() - strtotime( $user['payment_pending_at'] );
		return $age_seconds >= 0 && $age_seconds <= 30 * MINUTE_IN_SECONDS;
	}

	/**
	 * מורידה קרדיט אחד בפועל *מהמאגר $type* (אחרי פריסת קלף שהצליחה, או
	 * ברגע שסשן "העמיק" ממסר לשיחה - ר' session_card_selected_inner/
	 * session_turn_inner ב-class-dcard-rest-controller.php) - no-op (ולא
	 * שגיאה) למסלולים ללא הגבלה *באותו $type*, כדי שלא "יתרוקנו" בטעות
	 * עם השימוש.
	 */
	public static function deduct_credit( $user_id, $type = 'msg' ) {
		global $wpdb;
		self::ensure_table_exists();

		$user = self::get_by_id( $user_id );
		if ( ! $user || DCard_Plans::is_unlimited( $user['current_plan'], $type ) ) {
			return true;
		}

		$column = ( 'chat' === $type ) ? 'current_credits_chat' : 'current_credits_msg';

		return false !== $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " SET {$column} = GREATEST({$column} - 1, 0), updated_at = %s WHERE id = %d",
				current_time( 'mysql', true ),
				$user_id
			)
		);
	}

	/**
	 * §7.2ב: "אדמין: בטבלת הלקוחות, פעולה 'החזרת קרדיט'" - ההפך מ-
	 * deduct_credit, לשימוש ידני כש-admin מחליט שקרדיט נגבה בלי שהשירות
	 * באמת ניתן (ר' class-dcard-customers-admin.php, handle_refund_credit).
	 * לא עובר דרך is_unlimited כמו deduct_credit - גם למסלול "ללא הגבלה"
	 * יש מספר אמיתי בעמודה, והחזרה אמורה להעלות אותו בפועל.
	 */
	public static function add_credit( $user_id, $type = 'msg', $amount = 1 ) {
		global $wpdb;
		self::ensure_table_exists();

		$column = ( 'chat' === $type ) ? 'current_credits_chat' : 'current_credits_msg';

		return false !== $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " SET {$column} = {$column} + %d, updated_at = %s WHERE id = %d",
				(int) $amount,
				current_time( 'mysql', true ),
				$user_id
			)
		);
	}

	// -----------------------------------------------------------------
	// device_token (עמודת ה-DB נקראת session_token - ר' הערת השם למעלה).
	// מנגנון אנטי-שיתוף-חשבון: התחברות חדשה מייצרת טוקן שמחליף את הקודם,
	// כך שמכשיר שהחזיק את הטוקן הישן מפסיק להיות מאומת באופן אוטומטי
	// (לא צריך "לנתק" אותו באופן יזום - הטוקן שלו פשוט כבר לא תואם).
	// -----------------------------------------------------------------

	/**
	 * מייצר device_token חדש (אקראי, לא ניחושי) ושומר אותו על המשתמש -
	 * מחליף כל טוקן קודם, שמפסיק בכך להיות תקף. מוחזר למי שהתחבר/נרשם
	 * כרגע (להישמר ב-localStorage בצד הלקוח ולהישלח חזרה בכל בקשה).
	 */
	public static function issue_device_token( $user_id ) {
		global $wpdb;
		self::ensure_table_exists();

		$token = wp_generate_password( 48, false, false ); // אקראי, לא תלוי ב-uniqid/time

		$updated = $wpdb->update(
			self::table(),
			array( 'session_token' => $token, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return ( false !== $updated ) ? $token : null;
	}

	/**
	 * true רק אם $device_token תואם *בדיוק* לטוקן השמור כרגע על המשתמש
	 * הזה - לא רק "קיים משהו". משתמש בלי טוקן שמור כלל (למשל נרשם לפני
	 * שהתכונה הזו נוספה, או עדיין לא התחבר פעם אחת) נכשל כאן גם אם נשלח
	 * device_token ריק/null - בכוונה, כדי לא "לאשר" התאמה כפולה של כלום.
	 */
	public static function verify_device_token( $user_id, $device_token ) {
		if ( ! $device_token ) {
			return false;
		}
		$user = self::get_by_id( $user_id );
		if ( ! $user || empty( $user['session_token'] ) ) {
			return false;
		}
		return hash_equals( (string) $user['session_token'], (string) $device_token );
	}
}
