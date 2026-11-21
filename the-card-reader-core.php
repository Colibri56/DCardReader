<?php
/**
 * Plugin Name: The Card Reader Core
 * Description: תוסף ליבה מאוחד: ניהול קלפים השלכתיים, API לדיאלוג AI מול Make וקליטת Webhooks.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: the-card-reader-core
 */

defined( 'ABSPATH' ) || exit;

// 1. נתיבים - גם בשמות הישנים (DCARD_PLUGIN_DIR/URL וכו'), כי קבצי
// class-dcard-*.php הישנים (DCard_AI_Gateway, DCard_Cards, DCard_Activator,
// class-dcard-rest-controller.php) מפנים אליהם במפורש - שינוי השם בכל קובץ
// היה שינוי גדול ומסוכן יותר מהחזקת שני שמות לאותו נתיב.
define( 'TCR_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'TCR_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'DCARD_PLUGIN_FILE', __FILE__ );
define( 'DCARD_PLUGIN_DIR', TCR_CORE_PATH );
define( 'DCARD_PLUGIN_URL', TCR_CORE_URL );
// עודכן 1.3.0 - עמודות current_credits_msg/current_credits_chat (פיצול
// מאגר הקרדיטים למסר/שיחה מפתחת, ר' class-dcard-users.php) - עדכון
// הגרסה הזה הוא מה שגורם ל-maybe_upgrade() למטה להריץ את ה-dbDelta
// שמוסיף אותן בפועל על אתר חי, לא רק על התקנה חדשה.
// עודכן 1.4.0 - עמודת next_credit_reset_at (ר' class-dcard-users.php) -
// *** כלל: כל שינוי במבנה טבלה (עמודה/אינדקס/טבלה חדשה) חייב להעלות את
// המספר הזה, אחרת dbDelta לא ירוץ שוב על אתר חי קיים (ר' maybe_upgrade()
// למטה - בודקת שוויון מול dcard_db_version השמור, לא מריצה dbDelta אם
// הוא כבר תואם) - תוקן בפועל אחרי שעמודה חדשה "נשכחה" כך על staging. ***
// עודכן 1.5.0 - עמודות cancelled_at/cancel_reason/cancel_requested_at
// (סעיף 6.11: מצב "מבוטל" ו-POST /account/cancel, ר' class-dcard-users.php).
// עודכן 1.6.0 (21.11) - עמודה cardcom_recurring_id (שמירת RecurringId
// מהתראת הוראת קבע, ר' DCard_Users::save_recurring_id).
define( 'DCARD_DB_VERSION', '1.6.0' );

// *** אבטחה (תוקן, ר' docs/go-live.md): DCARD_WEBHOOK_SECRET כבר לא
// מוגדר כאן בשום ערך - הוא חייב להיות מוגדר ב-wp-config.php (כלל 6
// ב-CLAUDE.md: סודות חיים רק שם). אם הוא לא מוגדר, ה-webhook עצמו
// (payment_webhook_inner, class-dcard-rest-controller.php) דוחה את
// הבקשה ורושם שגיאה - ר' הבדיקה שם, לא כאן. ***

// §6.11 (ביטול מנוי) + docs/cardcom-api/: קריאה ל-API של הוראות הקבע של
// קארדקום (Operation=Update, RecurringPayments.IsActive=false) דורשת כתובת
// API, UserName וסיסמה - אין להם ערך אמיתי כאן בכוונה (בניגוד ל-
// DCARD_WEBHOOK_SECRET למעלה שעוד לא עבר את אותו ניקוי). הערכים האמיתיים
// מוגדרים רק ב-wp-config.php של production (כלל 6 ב-CLAUDE.md); עד אז
// נשארים ריקים, וכל קוד שמשתמש בהם (עוד לא נכתב - ר' תוכנית Phase A/C
// ל"האזור שלי") צריך לבדוק שהם לא ריקים לפני קריאה בפועל ל-API.
if ( ! defined( 'DCARD_CARDCOM_API_URL' ) ) {
	define( 'DCARD_CARDCOM_API_URL', '' );
}
if ( ! defined( 'DCARD_CARDCOM_API_USERNAME' ) ) {
	define( 'DCARD_CARDCOM_API_USERNAME', '' );
}
if ( ! defined( 'DCARD_CARDCOM_API_PASSWORD' ) ) {
	define( 'DCARD_CARDCOM_API_PASSWORD', '' );
}

// 2. תיקון (WordPress Site Health - "אותר סשן PHP פעיל"): session_start()
// גלובלי על *כל* בקשה (כולל REST API, cron, admin-ajax, ה-webhook מקארדקום)
// נעל קובץ PHP session לכל בקשה מאותו סשן, עד ש-session_write_close()
// נקרא - שלא קרה כאן אף פעם. כל בקשה עם ריצה ארוכה (למשל קריאת AI באורך
// כמה שניות ב-session_card_selected/session_turn) נעלה בפועל כל בקשה
// אחרת מאותו דפדפן, כולל בדיקת ה-REST API הפנימית של וורדפרס עצמו
// (loopback) - זו בדיוק הסיבה ל"cURL error 28: timed out" שראינו ב-
// Site Health. היחיד שבאמת צריך $_SESSION הוא
// \TheCardReaderCore\Cards_Manager::handle_card_draw_and_reset() (קוד
// ישן, לא ה-REST API) - שרץ רק ב-template_redirect (טעינת עמוד רגילה),
// שלעולם לא מופעל בבקשות REST API בכלל. ה-session_start() הוזז לשם
// עצמו, רק כשבאמת נדרש - ר' class-cards-manager.php.

// 3. מנהל הקלפים (החלק החדש)
require_once TCR_CORE_PATH . 'includes/core/class-cards-manager.php';
$cards_manager = new \TheCardReaderCore\Cards_Manager();
$cards_manager->init();

// 4. API הפידבק (החלק החדש)
require_once TCR_CORE_PATH . 'includes/api/class-api-feedback.php';
$api_feedback = new \TheCardReaderCore\API_Feedback();
$api_feedback->init();

// 5. טעינת כל קבצי ה-Backend הישן (מגדירות את המחלקות - עדיין בלי לחבר אף hook)
foreach ( glob( TCR_CORE_PATH . 'includes/*.php' ) as $file ) {
	require_once $file;
}

// 6. חיווט ה-Backend הישן - הועבר כאן בשלמותו מ-dcard-ai-reader-backend.php.
// זה היה חסר לגמרי קודם: הלולאה למעלה רק מגדירה את המחלקות, אף פעם לא
// קוראת להן - בלי החיווט הזה, טבלאות ה-DB לא נוצרות, שום REST route לא
// נרשם, תפריטי הניהול לא מופיעים, וה-nonce לא מוזרק לפרונטאנד.

register_activation_hook( __FILE__, array( 'DCard_Activator', 'activate' ) );

add_action( 'plugins_loaded', function () {
	if ( class_exists( 'DCard_Activator' ) ) {
		DCard_Activator::maybe_upgrade();
	}
} );

// Anti account-sharing - ר' ההערה המלאה בראש class-dcard-elementor-hooks.php.
add_action( 'plugins_loaded', function () {
	if ( class_exists( 'DCard_Elementor_Hooks' ) ) {
		DCard_Elementor_Hooks::register();
	}
} );

add_action( 'admin_menu', function () {
	if ( class_exists( 'DCard_Settings' ) ) {
		DCard_Settings::register_menu();
	}
	if ( class_exists( 'DCard_Quotas' ) ) {
		DCard_Quotas::register_menu();
	}
	if ( class_exists( 'DCard_Customers_Admin' ) ) {
		DCard_Customers_Admin::register_menu();
	}
} );

// "עדכון מסלול ידני" בעמוד הלקוחות (ר' class-dcard-customers-admin.php) -
// קוראת ל-set_plan() בדיוק כמו ה-webhook, לתיקון עסקה שנכשלה/לא שויכה.
add_action( 'admin_post_dcard_manual_set_plan', function () {
	if ( class_exists( 'DCard_Customers_Admin' ) ) {
		DCard_Customers_Admin::handle_manual_set_plan();
	}
} );

// §7.2ב: "החזרת קרדיט" בעמוד הלקוחות (ר' class-dcard-customers-admin.php).
add_action( 'admin_post_dcard_refund_credit', function () {
	if ( class_exists( 'DCard_Customers_Admin' ) ) {
		DCard_Customers_Admin::handle_refund_credit();
	}
} );

add_action( 'admin_init', function () {
	if ( class_exists( 'DCard_Settings' ) ) {
		DCard_Settings::register_settings();
	}
	if ( class_exists( 'DCard_Quotas' ) ) {
		DCard_Quotas::register_settings();
	}
} );

add_action( 'admin_notices', function () {
	if ( class_exists( 'DCard_Quotas' ) ) {
		DCard_Quotas::maybe_warn_active_plan_without_product_id();
	}
} );

// תקלה בטעינת data/ai-models.json (קובץ חסר/JSON פגום/מודל לא תקין) -
// מוצגת כ-admin_notice גלובלי, בכל עמוד בניהול, לא רק בעמוד Settings >
// DCard AI עצמו - כדי שהבעיה תתגלה מיידית (ר' render_model_choices_errors_notice()).
add_action( 'admin_notices', function () {
	if ( class_exists( 'DCard_Settings' ) ) {
		DCard_Settings::render_model_choices_errors_notice();
	}
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( class_exists( 'DCard_Quotas' ) ) {
		DCard_Quotas::enqueue_frontend_settings();
	}
} );

add_action( 'rest_api_init', function () {
	if ( class_exists( 'DCard_REST_Controller' ) ) {
		$controller = new DCard_REST_Controller();
		$controller->register_routes();
	}
} );

// מזריק את ה-nonce לפרונטאנד הסטטי (ווידג'ט ה-HTML של אלמנטור).
add_action( 'wp_head', function () {
	echo '<script>window.dcardRestNonce = ' . wp_json_encode( wp_create_nonce( 'wp_rest' ) ) . ';</script>' . "\n";
} );

// רשת ביטחון מפני PHP fatal גולמי בבקשות dcard/v1 - ר' ההערה המקורית.
add_action( 'init', function () {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
	if ( false === strpos( $uri, 'dcard/v1' ) ) {
		return;
	}

	register_shutdown_function( function () {
		$error = error_get_last();
		if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			return;
		}

		if ( class_exists( 'DCard_Logger' ) ) {
			DCard_Logger::error( 'Fatal error during dcard/v1 request', array(
				'message' => $error['message'],
				'file'    => $error['file'],
				'line'    => $error['line'],
			) );
		}

		if ( ! headers_sent() ) {
			http_response_code( 500 );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( array( 'error' => 'קרתה תקלה בשרת. נסו שוב בעוד רגע.' ) );
		}
	} );
}, 0 );

// ניקוי סשנים ישנים (פרטיות) - 'hourly' ולא 'daily': הסף לסשן 'active'
// שננטש הוא DCard_DB::cleanup_old_sessions()'s 2 שעות חוסר פעילות (ר' שם) -
// עם ריצה פעם ביום בלבד תוקן QA (דווח בפועל: סשן נשאר נגיש ושמיש גם אחרי
// 8 שעות חוסר פעילות כי הטיק היומי הבא של wp-cron פשוט לא הגיע אליו עדיין),
// כך שההודעה הייעודית ב-frontend ל-404/"סשן לא נמצא" (ר' elementor-code.html)
// כמעט אף פעם לא הופעלה בפועל. 'hourly' (built-in ב-WP, לא custom interval)
// מבטיח שהניקוי בפועל יקרה תוך שעה לכל היותר אחרי שהסשן חצה את הסף.
register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( 'dcard_daily_cleanup' ) ) {
		wp_schedule_event( time(), 'hourly', 'dcard_daily_cleanup' );
	}
} );

// תיקון QA: אתרים שכבר הפעילו את הפלאגין לפני השינוי הנ"ל נשארים עם
// 'daily' קבוע ב-wp_options (register_activation_hook רץ רק בהפעלה, לא
// שוב בעדכון קוד רגיל) - בודקים ב-init (רץ בכל בקשה, זול) ומתקנים פעם
// אחת אם התדירות השמורה שונה מ-'hourly'.
add_action( 'init', function () {
	$next = wp_get_scheduled_event( 'dcard_daily_cleanup' );
	if ( $next && 'hourly' !== $next->schedule ) {
		wp_clear_scheduled_hook( 'dcard_daily_cleanup' );
		wp_schedule_event( time(), 'hourly', 'dcard_daily_cleanup' );
	}
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'dcard_daily_cleanup' );
} );

add_action( 'dcard_daily_cleanup', function () {
	// חייב לרוץ *לפני* DCard_DB::cleanup_old_sessions() - ר' ההערה המלאה
	// ב-DCard_REST_Controller::report_abandoned_sessions(): הדיווח חייב
	// לקרות לפני שהשורה נמחקת, לא אחרי.
	if ( class_exists( 'DCard_REST_Controller' ) ) {
		DCard_REST_Controller::report_abandoned_sessions();
	}
	if ( class_exists( 'DCard_DB' ) ) {
		DCard_DB::cleanup_old_sessions();
	}
	if ( class_exists( 'DCard_Users' ) ) {
		DCard_Users::run_renewal_and_expiry_check();
	}
} );
