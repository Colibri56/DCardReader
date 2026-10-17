<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logging for this plugin - routes through WooCommerce's own logger
 * (`wc_get_logger()`) instead of writing files or calling error_log(), so a
 * production WooCommerce site's main wp-content/debug.log stays clean.
 *
 * WooCommerce logs land in WooCommerce > Status > Logs (a plain text file
 * under wp-content/uploads/wc-logs/ that WooCommerce itself manages,
 * rotates and protects - not a directory this plugin creates or touches).
 *
 * If WooCommerce isn't active, logging is skipped entirely - no fallback to
 * error_log() or any file of our own. That means on a non-WooCommerce
 * install this plugin currently logs nothing; see the note on log() below
 * if that ever needs to change.
 *
 * Two sources are used so the two kinds of entries can be filtered
 * independently in WooCommerce > Status > Logs:
 *   - 'dcard-ai-usage'  - DCard_Logger::usage() - token/cache counts per call.
 *   - 'dcard-ai-reader' - everything else (errors, warnings, general info).
 */
class DCard_Logger {

	private static $logger = null;
	private static $checked = false;

	private static function wc_logger() {
		if ( self::$checked ) {
			return self::$logger;
		}
		self::$checked = true;

		if ( function_exists( 'wc_get_logger' ) ) {
			self::$logger = wc_get_logger();
		}

		return self::$logger;
	}

	public static function log( $level, $message, array $context = array() ) {
		$logger = self::wc_logger();
		if ( ! $logger ) {
			return; // WooCommerce not active - skip, no error_log() fallback.
		}

		$level = in_array( $level, array( 'error', 'warning', 'info' ), true ) ? $level : 'info';

		$logger->log( $level, self::format( $message, $context ), array( 'source' => 'dcard-ai-reader' ) );
	}

	public static function error( $message, array $context = array() ) {
		self::log( 'error', $message, $context );
	}

	public static function warning( $message, array $context = array() ) {
		self::log( 'warning', $message, $context );
	}

	public static function info( $message, array $context = array() ) {
		self::log( 'info', $message, $context );
	}

	/**
	 * Token usage / prompt-cache data from a provider response
	 * (Claude's usage.cache_read_input_tokens, OpenAI's
	 * usage.prompt_tokens_details.cached_tokens, etc.).
	 */
	public static function usage( $message, array $context = array() ) {
		$logger = self::wc_logger();
		if ( ! $logger ) {
			return;
		}

		$logger->info( self::format( $message, $context ), array( 'source' => 'dcard-ai-usage' ) );
	}

	private static function format( $message, array $context ) {
		return $message . ( $context ? ' | ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) : '' );
	}
}
