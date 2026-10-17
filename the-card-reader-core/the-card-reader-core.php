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

// 1. הגדרת נתיבים
define( 'TCR_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'TCR_CORE_URL', plugin_dir_url( __FILE__ ) );

// 2. הפעלת מנגנון זיכרון (Session) עבור הקלפים
add_action( 'init', function() {
    if ( ! session_id() ) {
        session_start();
    }
}, 1 );

// 3. טעינת מנהל הקלפים החדש שיצרנו (לוגיקת התוסף הקטן)
require_once TCR_CORE_PATH . 'includes/core/class-cards-manager.php';
$cards_manager = new \TheCardReaderCore\Cards_Manager();
$cards_manager->init();

// 4. טעינת ה-API החדש של הפידבק
require_once TCR_CORE_PATH . 'includes/api/class-api-feedback.php';
$api_feedback = new \TheCardReaderCore\API_Feedback();
$api_feedback->init();

// 5. טעינה אוטומטית של כל קבצי התוסף הישן (ה-Backend)
foreach ( glob( TCR_CORE_PATH . 'includes/*.php' ) as $file ) {
    require_once $file;
}