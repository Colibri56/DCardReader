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
define( 'DCARD_DB_VERSION', '1.2.0' );

// *** אבטחה: ר' ההערה המלאה שהייתה ב-dcard-ai-reader-backend.php - מומלץ
// לעבור בהזדמנות לסוד המוגדר רק ב-wp-config.php, לא כאן. ***
if ( ! defined( 'DCARD_WEBHOOK_SECRET' ) ) {
	define( 'DCARD_WEBHOOK_SECRET', 'MySecretDcardKey2026' );
}

// 2. הפעלת מנגנון זיכרון (Session) עבור הקלפים
add_action( 'init', function() {
	if ( ! session_id() ) {
		session_start();
	}
}, 1 );

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
} );

add_action( 'admin_init', function () {
	if ( class_exists( 'DCard_Settings' ) ) {
		DCard_Settings::register_settings();
	}
	if ( class_exists( 'DCard_Quotas' ) ) {
		DCard_Quotas::register_settings();
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

// ניקוי יומי של סשנים ישנים (פרטיות).
register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( 'dcard_daily_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'dcard_daily_cleanup' );
	}
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'dcard_daily_cleanup' );
} );

add_action( 'dcard_daily_cleanup', function () {
	if ( class_exists( 'DCard_DB' ) ) {
		DCard_DB::cleanup_old_sessions();
	}
} );
