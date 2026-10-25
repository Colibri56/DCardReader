<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All reads/writes to wp_dcard_sessions live here - nothing else in the
 * plugin talks to $wpdb directly.
 */
class DCard_DB {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dcard_sessions';
	}

	public static function default_state() {
		return array(
			'stage'                => 'clarify_question', // gender already collected client-side before start
			'original_question'    => null,
			'refined_question'     => null,
			'clarification_count'  => 0,
			'turn_count'           => 0,
			'insights'             => array(),
			'possible_next_step'   => null,
			// 'chat' | 'message' | null - which path (שיחה אישית / קבלת מסר) this
			// reading is on, set from /session/card-selected's `mode` param. Read
			// at /session/end to pick the right email intro text (DCard_Mailer).
			'reading_mode'         => null,
			// Turns (user messages) since the CURRENT card was drawn - reset to 0
			// every time a card is drawn (session_card_selected_inner), incremented
			// once per /session/turn call. Server-owned, like turn_count - the model
			// never sets this itself (it's not part of the documented memory_update
			// contract). Backs the "חוק עצירת הלולאה" (max 2-4 reflection turns per
			// card) from agent-brain-instructions.md שלב ו' with a real server-side
			// cap, instead of relying only on the model counting correctly.
			'card_turn_count'      => 0,
			// 'Mobile' | 'Desktop' | '' - נקבע פעם אחת ב-/session/start (ר' שם),
			// לקריאה בלבד אחר כך בכל אירועי ה-Analytics של הסשן הזה.
			'platform'             => '',
			// סך טוקני קלט/פלט שנצרכו בכל קריאות ה-AI של הסשן הזה (כל הקלפים
			// וכל התורות יחד) - ר' DCard_DB::add_token_usage(), נקרא מ-
			// run_turn()/DCard_AI_Gateway אחרי כל קריאה בפועל לספק. מיועד
			// לניתוח עלויות מאוחר יותר (ר' גם data/ai-models.json לתעריפים).
			'tokens_in'            => 0,
			'tokens_out'           => 0,
			// true ברגע שסשן עבר בפועל מ-'message' ל-'chat' ("רוצה להמשיך
			// במסר") - ר' session_card_selected_inner, שם זה נקבע. נשאר
			// true עד סוף הסשן, גם אם ימשיך לצייר עוד קלפים ב-chat.
			'converted_from_message' => false,
		);
	}

	public static function create_session( $user_name, $user_gender ) {
		global $wpdb;

		$token = wp_generate_uuid4();
		$now   = current_time( 'mysql', true );

		$wpdb->insert(
			self::table(),
			array(
				'session_token' => $token,
				'status'        => 'active',
				'user_name'     => $user_name,
				'user_gender'   => $user_gender,
				'drawn_cards'   => wp_json_encode( array() ),
				'transcript'    => wp_json_encode( array() ),
				'state_json'    => wp_json_encode( self::default_state() ),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $token;
	}

	public static function get_session( $session_token ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::table() . " WHERE session_token = %s",
				$session_token
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['drawn_cards'] = json_decode( $row['drawn_cards'], true );
		if ( ! is_array( $row['drawn_cards'] ) ) {
			$row['drawn_cards'] = array();
		}

		$row['transcript'] = json_decode( $row['transcript'], true );
		if ( ! is_array( $row['transcript'] ) ) {
			$row['transcript'] = array();
		}

		$row['state'] = json_decode( $row['state_json'], true );
		if ( ! is_array( $row['state'] ) ) {
			$row['state'] = self::default_state();
		}

		return $row;
	}

	public static function append_transcript_entry( $session_token, $entry ) {
		$session = self::get_session( $session_token );
		if ( ! $session ) {
			return false;
		}

		$transcript   = $session['transcript'];
		$entry['ts']  = current_time( 'mysql', true );
		$transcript[] = $entry;

		return self::update_session( $session_token, array(
			'transcript' => wp_json_encode( $transcript ),
		) );
	}

	public static function add_drawn_card( $session_token, $card_id ) {
		$session = self::get_session( $session_token );
		if ( ! $session ) {
			return false;
		}

		$drawn   = $session['drawn_cards'];
		$drawn[] = (int) $card_id;

		return self::update_session( $session_token, array(
			'drawn_cards' => wp_json_encode( array_values( array_unique( $drawn ) ) ),
		) );
	}

	public static function update_state( $session_token, array $partial_state ) {
		$session = self::get_session( $session_token );
		if ( ! $session ) {
			return false;
		}

		$state = array_merge( $session['state'], $partial_state );

		return self::update_session( $session_token, array(
			'state_json' => wp_json_encode( $state ),
		) );
	}

	/**
	 * מוסיף (לא דורס) לסך טוקני הקלט/פלט שנצברו בסשן - ר' ההערה ליד
	 * tokens_in/tokens_out ב-default_state(). נקרא פעם אחת לכל קריאה
	 * בפועל לספק AI (לא לכל turn - turn יכול לכלול יותר מקריאה אחת, למשל
	 * retry על JSON לא תקין). get_session נקרא כאן בנפרד (לא update_state)
	 * כדי לקרוא ולעדכן באותה פעולה בלי round-trip כפול.
	 */
	public static function add_token_usage( $session_token, $input_tokens, $output_tokens ) {
		if ( ! $input_tokens && ! $output_tokens ) {
			return;
		}

		$session = self::get_session( $session_token );
		if ( ! $session ) {
			return;
		}

		$state                = $session['state'];
		$state['tokens_in']   = ( isset( $state['tokens_in'] ) ? (int) $state['tokens_in'] : 0 ) + (int) $input_tokens;
		$state['tokens_out']  = ( isset( $state['tokens_out'] ) ? (int) $state['tokens_out'] : 0 ) + (int) $output_tokens;

		self::update_session( $session_token, array(
			'state_json' => wp_json_encode( $state ),
		) );
	}

	public static function set_status( $session_token, $status ) {
		return self::update_session( $session_token, array( 'status' => $status ) );
	}

	public static function set_email_sent_to( $session_token, $email ) {
		return self::update_session( $session_token, array( 'email_sent_to' => $email ) );
	}

	private static function update_session( $session_token, array $fields ) {
		global $wpdb;

		$fields['updated_at'] = current_time( 'mysql', true );

		$formats = array();
		foreach ( $fields as $key => $value ) {
			$formats[] = '%s';
		}

		return false !== $wpdb->update(
			self::table(),
			$fields,
			array( 'session_token' => $session_token ),
			$formats,
			array( '%s' )
		);
	}

	/**
	 * Privacy: sessions are ephemeral. Completed sessions are purged after
	 * 48h, sessions that never progressed ("abandoned") after 2h of
	 * inactivity. Runs daily via wp-cron.
	 */
	public static function cleanup_old_sessions() {
		global $wpdb;
		$table = self::table();

		$wpdb->query(
			"DELETE FROM {$table} WHERE status = 'completed' AND updated_at < UTC_TIMESTAMP() - INTERVAL 48 HOUR"
		);
		$wpdb->query(
			"DELETE FROM {$table} WHERE status = 'active' AND updated_at < UTC_TIMESTAMP() - INTERVAL 2 HOUR"
		);
	}
}
