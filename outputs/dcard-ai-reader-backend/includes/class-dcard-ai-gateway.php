<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes a turn to whichever provider is active in Settings > DCard AI,
 * and enforces the JSON output contract defined in section 10 of
 * agent-brain-instructions.md (speak / show / next_action / memory_update / end_session).
 *
 * Per section 4 of that file, the model does NOT need the full chat
 * transcript on every call - only the current compact state object plus the
 * latest user_message. Continuity across turns comes from memory_update,
 * which the caller persists and feeds back in on the next call. The full
 * transcript is still kept server-side (DCard_DB) purely for the end-of-session email.
 *
 * Every public entry point here returns a plain array (ok/data or ok/error) -
 * it never lets an exception or PHP fatal escape to the caller, and every
 * failure path is written via DCard_Logger (WooCommerce > Status > Logs,
 * source "dcard-ai-reader") so a 502 can be diagnosed instead of guessed at.
 */
class DCard_AI_Gateway {

	// No more hardcoded model constants - the model (and, derived from it,
	// which provider/endpoint to use) now comes from Settings > DCard AI,
	// via DCard_Settings::get_model_for_mode() / get_provider_for_model() -
	// independently for 'direct' (קבלת מסר) and 'chat' (שיחה אישית). That's
	// what makes A/B testing between models a Settings-page change instead
	// of a code deploy. See DCard_Settings::MODEL_CHOICES for the list of
	// models this can be pointed at.

	// Was 28s on the theory that a proxy/CDN in front of the site would cut
	// the connection around there anyway, so it's better for our own code to
	// fail cleanly first. WooCommerce logs then showed the real failure mode:
	// "cURL error 28: Operation timed out after 28001 milliseconds with 0
	// bytes received" - that's PHP's OWN curl client giving up at exactly our
	// configured value, not a proxy killing the connection. Nothing external
	// was cutting us off early; our own timeout was the bottleneck, on
	// requests where Claude genuinely needed more than 28s to finish a longer
	// answer. Raised to 60s. If opaque 502s (instead of a clean WooCommerce
	// log entry) start showing up now, that means something in front of the
	// origin (Cloudflare, Nginx/PHP-FPM) has its own cutoff shorter than 60s -
	// this value would need to come back down, or that layer's timeout raised
	// to match. Override via the `dcard_request_timeout` filter if needed.
	const REQUEST_TIMEOUT = 60;

	private static $system_prompt = null;

	private static function system_prompt() {
		if ( null === self::$system_prompt ) {
			$path = DCARD_PLUGIN_DIR . 'agent-brain-instructions.md';
			self::$system_prompt = file_exists( $path ) ? file_get_contents( $path ) : '';

			if ( '' === self::$system_prompt ) {
				DCard_Logger::error( 'agent-brain-instructions.md is missing or empty', array( 'path' => $path ) );
			}
		}
		return self::$system_prompt;
	}

	/**
	 * @param array  $state_payload The state object per agent-brain-instructions.md section 4
	 *                               (session_id, address_gender, stage, ..., user_message).
	 * @param string $extra_system_instruction Optional text appended to the system
	 *                               prompt for this call only (e.g. "message mode").
	 *                               Placed after the cached prefix, so it never
	 *                               invalidates the Claude prompt cache.
	 * @param string $reading_mode  'direct' (קבלת מסר) | 'chat' (שיחה אישית) -
	 *                               which of the two independently configured
	 *                               models (Settings > DCard AI) this call uses.
	 * @return array {
	 *   @type bool  $ok
	 *   @type array $data  parsed contract object when ok=true
	 *   @type string $error  human-readable error when ok=false (safe to show/log, no secrets)
	 * }
	 */
	public static function run_turn( array $state_payload, $extra_system_instruction = '', $reading_mode = 'chat' ) {
		try {
			return self::run_turn_inner( $state_payload, $extra_system_instruction, $reading_mode );
		} catch ( \Throwable $e ) {
			// Last-resort net: whatever went wrong (network layer, unexpected
			// response shape, anything), never let it become a PHP fatal that
			// crashes the REST response mid-flight.
			DCard_Logger::error( 'Unhandled exception in DCard_AI_Gateway::run_turn', array(
				'message' => $e->getMessage(),
				'file'    => $e->getFile(),
				'line'    => $e->getLine(),
			) );
			return array( 'ok' => false, 'error' => 'שגיאה פנימית בפנייה ל-AI. נסו שוב בעוד רגע.' );
		}
	}

	private static function run_turn_inner( array $state_payload, $extra_system_instruction, $reading_mode ) {
		$model    = DCard_Settings::get_model_for_mode( $reading_mode );
		$provider = DCard_Settings::get_provider_for_model( $model );
		$api_key  = DCard_Settings::get_api_key( $provider );

		if ( ! $api_key ) {
			DCard_Logger::error( 'No API key configured for selected model', array( 'reading_mode' => $reading_mode, 'model' => $model, 'provider' => $provider ) );
			return array(
				'ok'    => false,
				'error' => "לא מוגדר מפתח API עבור הספק של המודל הנבחר ({$provider}). יש להגדיר ב-Settings > DCard AI.",
			);
		}

		$user_json = wp_json_encode( $state_payload, JSON_UNESCAPED_UNICODE );

		$raw = self::call_provider( $provider, $model, $api_key, $user_json, $extra_system_instruction );

		if ( is_wp_error( $raw ) ) {
			DCard_Logger::error( 'AI provider call failed', array(
				'provider' => $provider,
				'code'     => $raw->get_error_code(),
				'message'  => $raw->get_error_message(),
			) );
			return array( 'ok' => false, 'error' => self::friendly_error_message( $raw ) );
		}

		$parsed = self::extract_json_contract( $raw );

		// An empty reply means the model produced no text at all (already logged
		// by call_claude). Repeating the identical request would just double the
		// wait past the proxy timeout, so only retry when there WAS text that
		// simply failed to parse.
		if ( null === $parsed && '' === trim( (string) $raw ) ) {
			return array( 'ok' => false, 'error' => 'המודל לא החזיר תשובה. נסו שוב.' );
		}

		if ( null === $parsed ) {
			DCard_Logger::warning( 'Model returned invalid JSON, retrying once', array(
				'provider'  => $provider,
				'raw_snippet' => mb_substr( (string) $raw, 0, 500 ),
			) );

			$retry_json = wp_json_encode(
				array_merge( $state_payload, array(
					'_system_note' => 'התגובה הקודמת שלך לא הייתה JSON תקין. החזר אך ורק אובייקט JSON תקין לפי החוזה שבסעיף 10.',
				) ),
				JSON_UNESCAPED_UNICODE
			);

			$raw = self::call_provider( $provider, $model, $api_key, $retry_json, $extra_system_instruction );

			if ( is_wp_error( $raw ) ) {
				DCard_Logger::error( 'AI provider call failed on retry', array(
					'provider' => $provider,
					'code'     => $raw->get_error_code(),
					'message'  => $raw->get_error_message(),
				) );
				return array( 'ok' => false, 'error' => self::friendly_error_message( $raw ) );
			}

			$parsed = self::extract_json_contract( $raw );
		}

		if ( null === $parsed ) {
			DCard_Logger::error( 'Model returned invalid JSON twice, giving up', array(
				'provider'    => $provider,
				'raw_snippet' => mb_substr( (string) $raw, 0, 500 ),
			) );
			return array( 'ok' => false, 'error' => 'המודל לא החזיר JSON תקין גם אחרי ניסיון חוזר.' );
		}

		// agent-brain-instructions.md סעיף 8, כלל מחייב 3: אסורה אפילו מילה
		// אחת באנגלית בתוך speak - נאכף בפרומפט, אבל נצפה בפועל שהמודל מחליק
		// מילת סלנג אנגלית (כמו "excited") כשהוא מנסה להישמע חם וטבעי (כלל
		// מחייב 2). אותו דפוס retry-פעם-אחת כמו JSON לא תקין למעלה - לא
		// עוצרים את השיחה בשביל זה, רק מנסים לתקן פעם אחת ומקבלים את מה
		// שמתקבל, גם אם זה עדיין לא מושלם בניסיון השני.
		if ( isset( $parsed['speak'] ) && self::has_english_letters( $parsed['speak'] ) ) {
			DCard_Logger::warning( 'Model speak field contains English letters, retrying once', array(
				'provider' => $provider,
				'speak'    => $parsed['speak'],
			) );

			$retry_json = wp_json_encode(
				array_merge( $state_payload, array(
					'_system_note' => 'התגובה הקודמת שלך הכילה מילה או תו באנגלית בתוך speak - זה אסור בהחלט (סעיף 8, כלל מחייב 3). כתוב את אותה תשובה מחדש, בעברית טבעית ומלאה בלבד, בלי אף תו לועזי.',
				) ),
				JSON_UNESCAPED_UNICODE
			);

			$retry_raw = self::call_provider( $provider, $model, $api_key, $retry_json, $extra_system_instruction );

			if ( ! is_wp_error( $retry_raw ) ) {
				$retry_parsed = self::extract_json_contract( $retry_raw );
				if ( null !== $retry_parsed ) {
					$parsed = $retry_parsed;
					if ( isset( $parsed['speak'] ) && self::has_english_letters( $parsed['speak'] ) ) {
						DCard_Logger::warning( 'Model speak field still contains English letters after retry - accepting as-is', array(
							'provider' => $provider,
							'speak'    => $parsed['speak'],
						) );
					}
				}
				// $retry_parsed === null (JSON לא תקין בניסיון התיקון) - משאירים
				// את $parsed המקורי כפי שהיה; לא שווה להסתכן באיבוד תשובה תקינה
				// (גם אם עם מילה אנגלית) בשביל ניקיון שפה.
			}
		}

		return array(
			'ok'       => true,
			'data'     => $parsed,
			'provider' => $provider,
			'model'    => $model,
		);
	}

	/**
	 * Dispatches to the right endpoint/payload shape for $model's brand.
	 * $provider is passed in (rather than re-derived) because the caller
	 * already has it and this keeps the two always in sync.
	 */
	private static function call_provider( $provider, $model, $api_key, $user_json, $extra_system_instruction = '' ) {
		return ( 'claude' === $provider )
			? self::call_claude( $model, $api_key, $user_json, $extra_system_instruction )
			: self::call_openai( $model, $api_key, $user_json, $extra_system_instruction );
	}

	/**
	 * Turns low-level errors into a message that's safe to bubble up to the
	 * REST response without leaking API keys or internal details.
	 */
	private static function friendly_error_message( WP_Error $error ) {
		if ( 'http_request_failed' === $error->get_error_code() ) {
			return 'לא הצלחנו להתחבר לשירות ה-AI (timeout או בעיית רשת). נסו שוב.';
		}
		return $error->get_error_message();
	}

	private static function request_timeout() {
		return (int) apply_filters( 'dcard_request_timeout', self::REQUEST_TIMEOUT );
	}

	private static function call_claude( $model, $api_key, $user_json, $extra_system_instruction = '' ) {
		// The base instructions are identical on every call (~10K tokens), so
		// mark them cacheable: repeat calls read them from Anthropic's cache
		// instead of paying full input price. A per-call addition (e.g. message
		// mode) goes in a second block AFTER the cache breakpoint, so it varies
		// freely without invalidating the cached prefix.
		$system = array(
			array(
				'type'          => 'text',
				'text'          => self::system_prompt(),
				'cache_control' => array( 'type' => 'ephemeral' ),
			),
		);
		if ( '' !== $extra_system_instruction ) {
			$system[] = array( 'type' => 'text', 'text' => $extra_system_instruction );
		}

		$body = array(
			'model'      => $model,
			// Raised from 2048: a substantive answer to a direct
			// information/help question can run long, and hitting the
			// ceiling mid-JSON produces truncated, unparseable output -
			// which looked like a silent timeout (see the "raw_snippet"
			// logged by extract_json_contract's failure path). This
			// doesn't cost more unless the model actually uses it -
			// Claude is billed for tokens generated, not the ceiling.
			'max_tokens' => 4096,
			// No `thinking` param by default: Haiku 4-5 and claude-sonnet-5
			// both run fine without one (Haiku: no thinking; Sonnet 5: adaptive
			// either way). claude-sonnet-5-5 is the one exception - see below.
			'system'     => $system,
			'messages'   => array(
				array( 'role' => 'user', 'content' => $user_json ),
			),
		);

		// claude-sonnet-5-5 can't go thinking-off the old way: Anthropic
		// changed this model specifically so `thinking: {"type": "disabled"}`
		// returns a 400 (confirmed against the current API reference, not
		// just recalled). Omitting `thinking` entirely isn't a fix either -
		// that runs ADAPTIVE (thinking on) on this model, which is slower/
		// costlier than this chat UI wants. `{"type": "between_tools"}` is
		// the real replacement for "thinking off" on claude-sonnet-5-5 only -
		// takes no other field (display/budget_tokens/block_binding alongside
		// it is also a 400) and only works at effort "high" or below, which
		// is the default here since we never set output_config.effort.
		// claude-sonnet-5 and claude-haiku-4-5 are unaffected - left as-is.
		if ( 'claude-sonnet-5-5' === $model ) {
			$body['thinking'] = array( 'type' => 'between_tools' );
		}

		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => self::request_timeout(),
			'headers' => array(
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			),
			'body' => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code      = wp_remote_retrieve_response_code( $response );
		$raw_body  = wp_remote_retrieve_body( $response );
		$body      = json_decode( $raw_body, true );

		if ( $code < 200 || $code >= 300 ) {
			// The raw reason (can literally be the string "model: <id>" for an
			// unknown/retired model - see DCard_Settings::MODEL_CHOICES if that's
			// what's happening) goes to the WooCommerce log only, never to the client.
			DCard_Logger::error( 'Claude API returned non-2xx', array(
				'http_code'    => $code,
				'model'        => $model,
				'body_snippet' => mb_substr( (string) $raw_body, 0, 800 ),
			) );

			$error_type = isset( $body['error']['type'] ) ? $body['error']['type'] : 'unknown';
			$message    = sprintf(
				'Claude דחה את הבקשה (HTTP %d, %s). הפרטים המלאים ב-WooCommerce > Status > Logs.',
				$code,
				$error_type
			);
			return new WP_Error( 'dcard_claude_error', $message );
		}

		if ( isset( $body['usage'] ) && is_array( $body['usage'] ) ) {
			// input_tokens = uncached input; cache_read_input_tokens = served from cache.
			DCard_Logger::usage( 'Claude', $body['usage'] );
		}

		// Claude's `content` is an array of blocks - when the model reasons
		// before answering (or in general with newer models) there can be a
		// leading `thinking` block ahead of the `text` block, so the reply is
		// NOT reliably content[0]. Take the first block whose type is "text".
		$text = null;
		if ( isset( $body['content'] ) && is_array( $body['content'] ) ) {
			foreach ( $body['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text = $block['text'];
					break;
				}
			}
		}

		if ( null === $text ) {
			DCard_Logger::warning( 'Claude API 2xx but no text block found', array(
				'stop_reason'  => isset( $body['stop_reason'] ) ? $body['stop_reason'] : null,
				'block_types'  => isset( $body['content'] ) && is_array( $body['content'] )
					? array_column( $body['content'], 'type' )
					: null,
				'body_snippet' => mb_substr( (string) $raw_body, 0, 800 ),
			) );
			return '';
		}

		return $text;
	}

	private static function call_openai( $model, $api_key, $user_json, $extra_system_instruction = '' ) {
		$messages = array(
			array( 'role' => 'system', 'content' => self::system_prompt() ),
		);
		// Appended after the base instructions, so OpenAI's automatic prefix
		// caching still matches on the unchanged leading portion.
		if ( '' !== $extra_system_instruction ) {
			$messages[] = array( 'role' => 'system', 'content' => $extra_system_instruction );
		}
		$messages[] = array( 'role' => 'user', 'content' => $user_json );

		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'timeout' => self::request_timeout(),
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'content-type'  => 'application/json',
			),
			'body' => wp_json_encode( array(
				'model'           => $model,
				'response_format' => array( 'type' => 'json_object' ),
				'messages'        => $messages,
			), JSON_UNESCAPED_UNICODE ),
		) );

		if ( is_wp_error( $response ) ) {
			// Transport-level failure (DNS, TLS, timeout, blocked outbound) - no HTTP response at all.
			DCard_Logger::error( 'OpenAI wp_remote_post failed', array(
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
			) );
			return $response;
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$raw_body = wp_remote_retrieve_body( $response );
		$body     = json_decode( $raw_body, true );

		if ( $code < 200 || $code >= 300 ) {
			// The exact API rejection reason (never the API key) goes to the WooCommerce log only.
			DCard_Logger::error( 'OpenAI API returned non-2xx', array(
				'http_code'    => $code,
				'body_snippet' => mb_substr( (string) $raw_body, 0, 2000 ),
			) );

			// Only the HTTP status and OpenAI's error type/code go back to the client.
			// error.message is left out on purpose: for a bad key OpenAI echoes part of the key in it.
			$error_type = isset( $body['error']['type'] ) ? $body['error']['type'] : 'unknown';
			$error_code = isset( $body['error']['code'] ) ? $body['error']['code'] : '';
			$message    = sprintf(
				'OpenAI דחה את הבקשה (HTTP %d, %s%s). הפרטים המלאים ב-WooCommerce > Status > Logs.',
				$code,
				$error_type,
				$error_code ? ', ' . $error_code : ''
			);
			return new WP_Error( 'dcard_openai_error', $message );
		}

		if ( isset( $body['usage'] ) && is_array( $body['usage'] ) ) {
			// prompt_tokens_details.cached_tokens = part of the prompt served from OpenAI's automatic cache.
			DCard_Logger::usage( 'OpenAI', $body['usage'] );
		}

		if ( ! isset( $body['choices'][0]['message']['content'] ) ) {
			DCard_Logger::warning( 'OpenAI API 2xx but unexpected body shape', array(
				'body_snippet' => mb_substr( (string) $raw_body, 0, 800 ),
			) );
			return '';
		}

		return $body['choices'][0]['message']['content'];
	}

	/**
	 * agent-brain-instructions.md section 8, כלל מחייב 3: not even a single
	 * Latin character is allowed in speak. Plain [A-Za-z] match - any English
	 * letter at all is a violation, regardless of word boundaries.
	 */
	private static function has_english_letters( $text ) {
		return (bool) preg_match( '/[A-Za-z]/', (string) $text );
	}

	/**
	 * The model is instructed (agent-brain-instructions.md section 10) to
	 * return JSON-only, but in practice providers/models still sometimes
	 * wrap it in a ```json fence, or add a stray sentence before/after it.
	 * This pulls the JSON object out regardless of what surrounds it:
	 *   1. Prefer the contents of a ```json ... ``` fence if one exists,
	 *      anywhere in the text (not just at position 0).
	 *   2. Otherwise, scan for the first "{" and walk forward counting
	 *      brace depth (aware of quoted strings, so braces inside string
	 *      values don't throw off the count) to find its matching "}".
	 *   3. json_decode only that isolated substring.
	 */
	private static function extract_json_contract( $raw_text ) {
		$raw_text = trim( (string) $raw_text );

		if ( '' === $raw_text ) {
			return null;
		}

		$candidate = self::pull_fenced_json( $raw_text );

		if ( null === $candidate ) {
			$candidate = self::pull_balanced_json_object( $raw_text );
		}

		if ( null === $candidate ) {
			return null;
		}

		$decoded = json_decode( $candidate, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return null;
		}

		if ( ! is_array( $decoded ) || ! isset( $decoded['speak'], $decoded['next_action'], $decoded['memory_update'] ) ) {
			return null;
		}

		// Defaults so callers never have to guard against missing optional keys.
		if ( ! isset( $decoded['end_session'] ) ) {
			$decoded['end_session'] = false;
		}
		if ( ! isset( $decoded['show'] ) || ! is_array( $decoded['show'] ) ) {
			$decoded['show'] = array( 'type' => 'none', 'card_id' => null );
		}
		if ( ! is_array( $decoded['memory_update'] ) ) {
			$decoded['memory_update'] = array();
		}

		return $decoded;
	}

	/**
	 * Extracts the contents of a ```json ... ``` (or bare ``` ... ```)
	 * fence, wherever it appears in the text. Returns null if there is no fence.
	 */
	private static function pull_fenced_json( $text ) {
		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/i', $text, $matches ) ) {
			return trim( $matches[1] );
		}
		return null;
	}

	/**
	 * Finds the first "{" in the text and walks forward tracking brace
	 * depth to find its matching "}", ignoring braces that appear inside
	 * quoted strings. This isolates a single JSON object even when the
	 * model prefixes it with a sentence, a "thinking" aside, or trailing
	 * commentary after the closing brace.
	 */
	private static function pull_balanced_json_object( $text ) {
		$start = strpos( $text, '{' );
		if ( false === $start ) {
			return null;
		}

		$depth      = 0;
		$in_string  = false;
		$escape_next = false;
		$length     = strlen( $text );

		for ( $i = $start; $i < $length; $i++ ) {
			$char = $text[ $i ];

			if ( $escape_next ) {
				$escape_next = false;
				continue;
			}

			if ( '\\' === $char ) {
				$escape_next = true;
				continue;
			}

			if ( '"' === $char ) {
				$in_string = ! $in_string;
				continue;
			}

			if ( $in_string ) {
				continue;
			}

			if ( '{' === $char ) {
				$depth++;
			} elseif ( '}' === $char ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $text, $start, $i - $start + 1 );
				}
			}
		}

		return null; // unbalanced - truncated response
	}
}
