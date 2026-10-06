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

	public static function check_session_start( $ip ) {
		return self::check( 'dcard_rl_start_' . md5( $ip ), self::MAX_SESSION_STARTS_PER_HOUR );
	}

	public static function check_turn( $ip ) {
		return self::check( 'dcard_rl_turn_' . md5( $ip ), self::MAX_TURNS_PER_HOUR );
	}

	private static function check( $key, $limit ) {
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
