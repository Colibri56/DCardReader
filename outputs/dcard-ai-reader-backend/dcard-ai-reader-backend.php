<?php
/**
 * Plugin Name: DCard AI Reader Backend
 * Description: REST API backend for the "Reader in the Cards" SPA - manages session state, drawn cards, AI dialogue (Claude / OpenAI) and email delivery.
 * Version: 1.0.0
 * Text Domain: dcard-ai-reader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // no direct access
}

define( 'DCARD_PLUGIN_FILE', __FILE__ );
define( 'DCARD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DCARD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DCARD_DB_VERSION', '1.2.0' ); // הועלה: Cardcom + anti account-sharing (users מורחב, model_pricing, usage_logs, transactions)

// *** אבטחה: הסוד הזה מזהה שבקשה ל-/webhook/payment באמת הגיעה מקארדקום.
// הוגדר כאן, בקובץ פלאגין רגיל (לא wp-config.php), בדיוק כפי שהתבקש -
// אבל זו בדיוק הסיבה שקבצי קוד אחרים בפרויקט הזה (class-dcard-settings.php)
// מעדיפים לתת למפתח constants ב-wp-config.php לגבור על ערך בקוד: קובץ
// הפלאגין הזה בדרך כלל חלק מ-repo/גיבויים/deploy logs, ו"סוד" ששם הוא
// לא סוד אמיתי לאורך זמן. מומלץ, כשנוח, לעבור לכלל הזה:
//   if ( ! defined( 'DCARD_WEBHOOK_SECRET' ) ) { define( 'DCARD_WEBHOOK_SECRET', '...' ); }
// עם הערך האמיתי מוגדר ב-wp-config.php בלבד (לא כאן) - כך שאפשר להחליף
// את הסוד בלי deploy קוד, ובלי שהוא יושב בגיבוי/git history כלל. ***
if ( ! defined( 'DCARD_WEBHOOK_SECRET' ) ) {
	define( 'DCARD_WEBHOOK_SECRET', 'MySecretDcardKey2026' );
}

require_once DCARD_PLUGIN_DIR . 'includes/class-dcard-logger.php';
require_once DCARD_PLUGIN_DIR . 'includes/class-dcard-plans.php';
require_once DCARD_PLUGIN_DIR . 'includes/class-dcard-activator.php';
require_once DCARD_PLUGIN_DIR . 'includes/class-dcard-db.php';
require_once DCARD_PLUGIN_DIR . 'includes/class-dcard-users.php';
require_once DCARD_PLUGIN_DIR . 'includes/class-dcard-model-pricing.php';
require_once DCARD_PLUGIN_DIR . 'includes/class-dcard-usage-logs.php';
require_once DCARD_PLUGIN_DIR . 'includes/class-dcard-transactions.php';
require_once DCARD_PLUGIN_DIR . 'includes/class-dcard-elementor-hooks.php';
require_once DCARD_PLUGIN_DIR . 'includes/class-dcard-settings.php';
require_once DCARD_PLUGIN_DIR . 'includes/class-dcard-quotas.php';
require_once DCARD_PLUGIN_DIR . 'includes/class-dcard-cards.php';
require_once DCARD_PLUGIN_DIR . 'includes/class-dcard-ai-gateway.php';
require_once DCARD_PLUGIN_DIR . 'includes/class-dcard-mailer.php';
require_once DCARD_PLUGIN_DIR . 'includes/class-dcard-rate-limiter.php';
require_once DCARD_PLUGIN_DIR . 'includes/class-dcard-analytics.php';
require_once DCARD_PLUGIN_DIR . 'includes/class-dcard-rest-controller.php';

register_activation_hook( __FILE__, array( 'DCard_Activator', 'activate' ) );

add_action( 'plugins_loaded', function () {
	DCard_Activator::maybe_upgrade();
} );

// Anti account-sharing (שלב 3, המשך): ר' ההערה המלאה בראש class-dcard-
// elementor-hooks.php - זו הדרך היחידה להחזיר deviceToken בפועל לדפדפן.
// 'elementor_pro/forms/new_record' הוא action שאלמנטור Pro עצמה מפעילה -
// אם אלמנטור Pro לא פעיל באתר, ה-add_action הפנימי כאן פשוט לא ירוץ
// לעולם (אין בעיה/שגיאה, רק feature לא-רלוונטי שלא מופעל).
add_action( 'plugins_loaded', function () {
	if ( class_exists( 'DCard_Elementor_Hooks' ) ) {
		DCard_Elementor_Hooks::register();
	}
} );

add_action( 'admin_menu', function () {
	DCard_Settings::register_menu();
	DCard_Quotas::register_menu();
} );

add_action( 'admin_init', function () {
	DCard_Settings::register_settings();
	DCard_Quotas::register_settings();
} );

// תיקון QA 4.3 (אותו דפוס) - ר' הערה ב-DCard_Quotas::enqueue_frontend_settings().
add_action( 'wp_enqueue_scripts', function () {
	DCard_Quotas::enqueue_frontend_settings();
} );

add_action( 'rest_api_init', function () {
	$controller = new DCard_REST_Controller();
	$controller->register_routes();
} );

/**
 * תיקון QA 4.3: מקור ה-nonce שהרחבת ה-REST מוודאת (ר' DCard_REST_Controller::
 * verify_nonce_if_present). הפרונטאנד הוא קוד HTML/JS סטטי בתוך ווידג'ט
 * Elementor (לא מתבצע עליו PHP), אז אי אפשר להטמיע wp_create_nonce() ישירות
 * שם - חייבים מנגנון אחר שמזריק את הערך לדף בזמן טעינתו.
 *
 * wp_head (לא wp_footer) בכוונה: רץ לפני תוכן הגוף, אז המשתנה הגלובלי כבר
 * קיים ברגע שה-<script> של הווידג'ט עצמו רץ, לא משנה היכן בעמוד הוא ממוקם.
 * רץ בכל טעינת עמוד באתר (לא רק בעמוד הווידג'ט הספציפי) - wp_create_nonce()
 * זול, ואין דרך פשוטה לדעת מראש על אילו עמודים הווידג'ט יושב.
 */
add_action( 'wp_head', function () {
	echo '<script>window.dcardRestNonce = ' . wp_json_encode( wp_create_nonce( 'wp_rest' ) ) . ';</script>' . "\n";
} );

/**
 * Last-resort safety net for a fatal error that happens outside the
 * REST controller's own try/catch (e.g. a parse error in a file it loads).
 * Scoped to dcard/v1 requests only so it never affects the rest of the site.
 * Without this, a fatal error here can end up as a bare/broken response
 * that a reverse proxy or CDN (e.g. Cloudflare) turns into an opaque 502.
 */
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

		DCard_Logger::error( 'Fatal error during dcard/v1 request', array(
			'message' => $error['message'],
			'file'    => $error['file'],
			'line'    => $error['line'],
		) );

		if ( ! headers_sent() ) {
			http_response_code( 500 );
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( array( 'error' => 'קרתה תקלה בשרת. נסו שוב בעוד רגע.' ) );
		}
	} );
}, 0 );

// Daily cleanup of old sessions (privacy: don't keep more than necessary).
register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( 'dcard_daily_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'dcard_daily_cleanup' );
	}
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'dcard_daily_cleanup' );
} );

add_action( 'dcard_daily_cleanup', function () {
	DCard_DB::cleanup_old_sessions();
} );
