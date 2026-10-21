<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fire-and-forget beta-testing analytics webhook (Settings > DCard AI >
 * Analytics Webhook URL). Every call is non-blocking - 'blocking' => false
 * is what actually makes this async, not the timeout value: PHP fires the
 * HTTP request and moves on immediately without waiting for, or even
 * seeing, the response. A slow or completely unreachable webhook endpoint
 * therefore never delays the user-facing REST response. Silently does
 * nothing if no URL is configured, so this is a true no-op outside of beta
 * testing - no behavior change, no extra request, until someone fills in
 * the setting.
 */
class DCard_Analytics {

	public static function track( $event, array $data ) {
		$url = DCard_Settings::get_analytics_webhook_url();
		if ( ! $url ) {
			return;
		}

		$payload = array_merge( array(
			'event'     => $event,
			'timestamp' => current_time( 'mysql', true ),
		), $data );

		$result = wp_remote_post( $url, array(
			'timeout'  => 5,
			'blocking' => false,
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'body'     => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
		) );

		// blocking=false still returns a WP_Error for failures that happen
		// before the request is even dispatched (malformed URL, blocked by
		// a filter, etc.) - worth logging since those are silent otherwise,
		// but never worth surfacing to the user or retrying.
		if ( is_wp_error( $result ) ) {
			DCard_Logger::warning( 'Analytics webhook dispatch failed', array(
				'event'   => $event,
				'message' => $result->get_error_message(),
			) );
		}
	}

	/**
	 * Human-readable label for the reading_mode state field, matching the
	 * frontend's own button copy (block-path-choice in elementor-code.html).
	 */
	public static function route_label( $reading_mode ) {
		if ( 'message' === $reading_mode ) {
			return 'Message';
		}
		if ( 'chat' === $reading_mode ) {
			return 'Intuition Development';
		}
		return null;
	}

	/**
	 * אותה לוגיקה כמו DCard_Mailer::ai_label() (שם היא private) - איזה
	 * ספק/מודל בפועל הפיק את התשובות בסשן הזה, נקרא מהתמליל (לא מה-
	 * Settings הנוכחי, שיכול להיות שונה אם המודל הוחלף באמצע) - נדרש
	 * כעמודת "LLM Model" ביעד ה-Google Sheets (ר' track() ל-session_completed).
	 */
	public static function ai_label( array $transcript ) {
		$labels = array(
			'claude' => 'Claude',
			'openai' => 'OpenAI',
			'gemini' => 'Gemini',
		);

		$used = array();
		foreach ( $transcript as $entry ) {
			if ( empty( $entry['provider'] ) ) {
				continue;
			}
			$name = isset( $labels[ $entry['provider'] ] ) ? $labels[ $entry['provider'] ] : $entry['provider'];
			if ( ! empty( $entry['model'] ) ) {
				$name .= ' (' . $entry['model'] . ')';
			}
			$used[ $name ] = true;
		}

		return $used ? implode( ', ', array_keys( $used ) ) : '';
	}

	/**
	 * Plain-text rendering of a session's transcript, for the webhook's
	 * chat_transcript field on session_completed (raw JSON, not HTML - this
	 * doesn't go in an email). Same filtering DCard_Mailer::render_html()
	 * applies: skips empty text and 'system' bookkeeping entries (e.g.
	 * "קלף נבחר: #45") that are internal records, not conversation content.
	 */
	public static function format_transcript_text( array $transcript ) {
		$lines = array();

		foreach ( $transcript as $entry ) {
			$role = isset( $entry['role'] ) ? $entry['role'] : 'user';
			$text = isset( $entry['text'] ) ? trim( (string) $entry['text'] ) : '';

			if ( '' === $text || 'system' === $role ) {
				continue;
			}

			$speaker = ( 'user' === $role ) ? 'משתמש' : 'הקורא בקלפים';
			$lines[] = $speaker . ': ' . $text;
		}

		return implode( "\n", $lines );
	}
}
