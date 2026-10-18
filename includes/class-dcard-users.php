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

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL,
			name VARCHAR(100) NULL,
			phone VARCHAR(30) NULL,
			gender VARCHAR(10) NULL,
			current_plan VARCHAR(20) NOT NULL DEFAULT 'free',
			current_credits INT NOT NULL DEFAULT 0,
			plan_expires_at DATETIME NULL,
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
				'email'            => $email,
				'name'             => $name,
				'phone'            => $phone,
				'gender'           => $gender,
				'current_plan'     => $plan_id,
				'current_credits'  => DCard_Plans::credits_for_plan( $plan_id ),
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
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
			'credits'      => $user ? (int) $user['current_credits'] : 0,
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

		return false !== $wpdb->update(
			self::table(),
			array(
				'current_plan'    => $plan_id,
				'current_credits' => DCard_Plans::credits_for_plan( $plan_id ),
				'plan_expires_at' => $expires_at,
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => $user_id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
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
	 * בדיקה לפני פריסת קלף: יש קרדיט פנוי? מסלולים ללא הגבלה (ר'
	 * DCard_Plans::is_unlimited) תמיד "true" בלי לבדוק את המספר בפועל -
	 * כדי שלעולם לא יתקעו על מירוץ עדכון-קרדיט, גם אם current_credits
	 * בטבלה פיגר מאחור מאיזושהי סיבה.
	 */
	public static function has_credits( $user_id ) {
		$user = self::get_by_id( $user_id );
		if ( ! $user ) {
			return false;
		}
		if ( DCard_Plans::is_unlimited( $user['current_plan'] ) ) {
			return true;
		}
		return (int) $user['current_credits'] > 0;
	}

	/**
	 * מורידה קרדיט אחד בפועל (אחרי פריסת קלף שהצליחה) - no-op (ולא
	 * שגיאה) למסלולים ללא הגבלה, כדי שלא "יתרוקנו" בטעות עם השימוש.
	 */
	public static function deduct_credit( $user_id ) {
		global $wpdb;
		self::ensure_table_exists();

		$user = self::get_by_id( $user_id );
		if ( ! $user || DCard_Plans::is_unlimited( $user['current_plan'] ) ) {
			return true;
		}

		return false !== $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " SET current_credits = GREATEST(current_credits - 1, 0), updated_at = %s WHERE id = %d",
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
