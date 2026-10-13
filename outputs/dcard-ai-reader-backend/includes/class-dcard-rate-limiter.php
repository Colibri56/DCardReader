<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple per-IP rate limiting via transients. The app is open to everyone
 * with no login, and every AI turn costs money, so this is the minimum
 * guard against a single visitor (or a stray script) hammering the endpoints.
 */
class DCard_Rate_Limiter {

	const MAX_SESSION_STARTS_PER_HOUR = 20;
	const MAX_TURNS_PER_HOUR          = 120;
	const MAX_REGISTRATIONS_PER_HOUR  = 30; // /register - ר' DCard_REST_Controller::session_register_inner()

	public static function check_session_start( $ip ) {
		return self::check( 'session_start', 'dcard_rl_start_' . md5( $ip ), self::MAX_SESSION_STARTS_PER_HOUR );
	}

	public static function check_turn( $ip ) {
		return self::check( 'turn', 'dcard_rl_turn_' . md5( $ip ), self::MAX_TURNS_PER_HOUR );
	}

	public static function check_register( $ip ) {
		return self::check( 'register', 'dcard_rl_register_' . md5( $ip ), self::MAX_REGISTRATIONS_PER_HOUR );
	}

	/**
	 * מתג חירום לסביבת בדיקות (Staging) - מוגדר רק דרך wp-config.php של אותה
	 * סביבה ספציפית (לעולם לא קוד שמשותף בין production/staging), כדי
	 * שבדיקות חוזרות (ריענון עמוד שוב ושוב, שכל פעם קורא ל-/session/start
	 * מחדש) לא יתקעו על 429 בלי שצריך לגעת בקוד הפלאגין בכלל. כש-true,
	 * כל הבדיקות למטה מאפשרות תמיד, בלי שום הגבלה.
	 * שימוש: define( 'DCARD_DISABLE_RATE_LIMITING', true ); ב-wp-config.php.
	 *
	 * גם ללא המתג הזה, כל מגבלה כאן ניתנת לכיוונון בלי deploy חדש של
	 * קבצי הפלאגין, דרך ה-filter הבא (ב-wp-config.php או functions.php):
	 * add_filter( 'dcard_rate_limit_max', function( $limit, $type ) {
	 *     return ( 'session_start' === $type ) ? 500 : $limit;
	 * }, 10, 2 );
	 * ($type הוא אחד מ-'session_start'/'turn'/'register' - לא ה-transient
	 * key שכולל md5(IP), כדי שה-filter יהיה בר-שימוש בלי לדעת כתובות IP.)
	 */
	// זיהוי אוטומטי של סביבת staging לפי שם המתחם (staging.nuriteldar.co.il,
	// ר' הכתובת שנבדקה בפועל בשיחה הזו) - בלי תלות ב-wp-config.php בכלל,
	// כך שהרף המוגדל חל מיד על כל קוד הפלאגין בסביבת ה-staging, ואף פעם לא
	// על production (שם HTTP_HOST לא מתחיל ב-"staging."). "1000" הוא בדיוק
	// הרף שהתבקש - ספציפית ל-/session/start (לא לשאר הנתיבים, שעדיין
	// שומרים על ברירת המחדל הרגילה שלהם גם ב-staging).
	private static function is_staging_host() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		return 0 === strpos( $host, 'staging.' );
	}

	private static function check( $type, $key, $limit ) {
		if ( defined( 'DCARD_DISABLE_RATE_LIMITING' ) && DCARD_DISABLE_RATE_LIMITING ) {
			return true;
		}

		if ( 'session_start' === $type && self::is_staging_host() ) {
			$limit = 1000;
		}

		$limit = (int) apply_filters( 'dcard_rate_limit_max', $limit, $type );

		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		if ( 0 === $count ) {
			set_transient( $key, 1, HOUR_IN_SECONDS );
		} else {
			set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		}

		return true;
	}

	public static function client_ip() {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) )[0];
				return trim( $ip );
			}
		}
		return '0.0.0.0';
	}
}
