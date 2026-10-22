<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for the SPA frontend. All routes are public (no WP login -
 * the app is anonymous by design), so security relies on: the unguessable
 * session_token issued by /session/start, strict input sanitization,
 * per-IP rate limiting (DCard_Rate_Limiter), and an X-WP-Nonce header the
 * frontend sends on every call (see verify_nonce_if_present() below and
 * the wp_head hook in dcard-ai-reader-backend.php that injects it into
 * window.dcardRestNonce - the frontend itself is static HTML/JS inside an
 * Elementor widget, with no PHP execution of its own to create a nonce in).
 *
 * The nonce check is deliberately SOFT: present + invalid -> rejected
 * (403); absent entirely -> still allowed through. A hard requirement
 * would be stronger, but this site is served through page caching
 * (Cloudflare / a WP cache plugin) - a cached page bakes in whatever nonce
 * existed when it was generated, and WP nonces rotate roughly every 12h,
 * so a hard requirement would start silently rejecting every real visitor
 * on a stale cached page the moment the nonce window rolls over, until the
 * cache is purged. Rate limiting remains the primary abuse defense either
 * way; this is an additive layer on top of it, not a replacement.
 *
 * Response shape notes (see outputs/2026-09-19-ארכיטקטורת-סשן.md and the
 * chat discussion for why): the endpoints return the fields you specified
 * (session_token, success, aiReply) plus a couple of small additive fields
 * (endSession, showCardDraw, nextAction, closingMessage) that the frontend
 * can use if useful, or ignore entirely. Two deliberate deviations from the
 * literal spec, both required for the product to actually behave per
 * agent-brain-instructions.md:
 *   1. /session/card-selected also calls the AI and returns aiReply (the
 *      "what comes up for you when you look at the card?" opening line),
 *      not just a bare success boolean - otherwise that question never gets asked.
 *   2. /session/turn enforces the 15-turn hard cap server-side (the file
 *      requires the SITE to own that limit, not the model).
 *
 * Every registered callback below is a thin try/catch wrapper around an
 * "_inner" method. Nothing thrown inside an _inner method (network errors,
 * unexpected null/array access, anything) is allowed to become a raw PHP
 * fatal - it is logged via DCard_Logger and turned into a clean JSON error
 * response instead. This matters specifically because an uncaught PHP fatal
 * in a REST callback is what tends to show up to the browser as an opaque
 * proxy/CDN error (e.g. a Cloudflare 502) instead of a real HTTP error body.
 */
class DCard_REST_Controller {

	const NAMESPACE_ = 'dcard/v1';

	// Appended as an extra system instruction (DCard_AI_Gateway::run_turn's
	// $extra_system_instruction) when mode === 'message'. Placed after the
	// cached system prompt block, so it doesn't invalidate the cache.
	//
	// This has to explicitly override section 5's stage flow, not just add a
	// closing note - the base prompt spends its entire "תהליך השיחה" section
	// establishing an iterative, question-driven dialogue (starting with שלב ה:
	// always open by asking "מה עולה בך?"). A single tacked-on "don't end with
	// a question" sentence was competing against that whole flow and losing -
	// the model kept asking questions, because the rest of the instructions it
	// was reading described exactly that. This version names the conflicting
	// stage and states plainly that it's suspended for this one call, bans
	// question marks anywhere in the text (not just as a closing move), and
	// gives concrete forbidden phrases - the same pattern section 8 already
	// uses successfully for its other "don't do X" rules.
	const MESSAGE_MODE_INSTRUCTION = 'הנחיה זו גוברת על תהליך השיחה הרגיל שבסעיף 5, לקריאה הזו בלבד: המשתמש בחר "קבלת מסר אישי" - לא שיחה. אצלו תיבת הכתיבה מוסתרת לגמרי ואין לו שום דרך להגיב, גם לא באיטרציה הבאה. אל תפעל לפי שלב ה׳ (אל תפתח בשאלה "מה עולה בך") ואל תשאל שום שאלה - לא בהתחלה, לא באמצע ולא בסוף. אסור שיופיע בתשובה סימן שאלה (?) ולו אחד. אסור לכתוב ניסוחים כמו "ספר לי עוד", "מה אתה מרגיש לגבי זה", "רוצה להרחיב", "מה דעתך". כתוב מסר אחד שלם וסגור: קריאה קצרה, מעצימה ומעוררת השראה של הקלף ביחס לשאלה שלו, עם תובנה או כיוון ברור - משפט סיכום שעומד בפני עצמו, לא פתיחה לדיאלוג.'
		. ' CRITICAL RULE: You are providing a single, final, closed message. You are STRICTLY FORBIDDEN from asking any questions. DO NOT use question marks (?). DO NOT ask the user how they feel or what they see. Provide a complete, insightful, and conclusive answer to the user\'s question based on the drawn card, and then stop.';

	// Appended (same mechanism) when is_final_turn === true on /session/turn -
	// the 3rd message of Personal Chat mode, right before the frontend hides
	// the input for good (MAX_PERSONAL_CHAT_QUESTIONS in elementor-code.html).
	// Same reasoning as MESSAGE_MODE_INSTRUCTION above: it has to explicitly
	// suspend section 5's question-driven flow for this one call, not just
	// add a closing note, or the model keeps asking a follow-up question the
	// user has no way to answer.
	const FINAL_TURN_INSTRUCTION = 'הנחיה זו גוברת על תהליך השיחה הרגיל שבסעיף 5, לתשובה הזו בלבד: זו ההודעה האחרונה שהמשתמש יכול לשלוח בשיחה הזו - מיד אחרי התשובה שלך תיבת הכתיבה אצלו נסגרת, ואין לו שום דרך להגיב או להמשיך. אל תשאל שום שאלת המשך ואל תפתח כיוון חדש. אסור שיופיע בתשובה סימן שאלה (?) ולו אחד. תן סיכום חם, סוגר ומעצים: אסוף את מה שעלה בשיחה הזו ביחס לשאלה המקורית של המשתמש לכדי תובנה או כיוון ברור אחד, והבא את הקריאה לסיום נעים. זו חתימה, לא פתיחה להמשך.';

	// Hard server-side backstop for the "חוק עצירת הלולאה" rule in
	// agent-brain-instructions.md שלב ו' (max 2-4 reflection turns per card).
	// The model is asked to self-count that from a message window that's
	// capped at the last 6 entries (recent_messages() below) - not reliable
	// once a card's reflection runs long, so this doesn't rely on the model
	// counting correctly at all: card_turn_count is tracked server-side (see
	// DCard_DB::default_state()) and this instruction is forced in once it's
	// reached, same mechanism as FINAL_TURN_INSTRUCTION above. Deliberately
	// >= (not ==) CARD_TURN_CAP in session_turn_inner() - a real safety net
	// must keep firing on every turn from the cap onward, not just once,
	// otherwise a model that doesn't fully comply on the capping turn could
	// keep the loop going indefinitely on the next one.
	const CARD_TURN_CAP = 4;
	const CARD_TURN_CAP_INSTRUCTION = 'הנחיה זו גוברת על תהליך השיחה הרגיל שבסעיף 5, לתשובה הזו בלבד: הגעת למגבלת השרת של 4 תורות עיבוד סביב הקלף הנוכחי ("חוק עצירת הלולאה" בשלב ו׳). אסור להמשיך לשאול או להעמיק יותר סביב הקלף הזה. עבור ישר לשלב ט׳: תן מסר סיום החלטי, מעצים וברור, מבוסס על הקלף ועל מה שעלה בשיחה עד כה - בלי שום שאלה נוספת. אסור שיופיע בתשובה סימן שאלה (?) ולו אחד. זו חתימה, לא פתיחה להמשך.';

	// נשמר בתמליל (role: 'error', ר' AI_ERROR_ROLE למטה) בשני המקומות שבהם
	// קריאת AI יכולה להיכשל (card-selected ו-turn) - כך שמייל הסיכום תמיד
	// משקף את זה שהשיחה נקטעה, במקום להיגמר באמצע בלי שום סימן. נוסח אחיד
	// לשניהם, במתכוון לא מציע "לנסות שוב" (זה כבר לא רלוונטי בהקשר של מייל
	// סיכום שנקרא אחרי הסשן) - רק מציין בבירור שהיה כשל.
	const AI_ERROR_TRANSCRIPT_TEXT = 'אירעה תקלה טכנית בשלב הזה של השיחה, והיא לא הושלמה כרגיל.';

	// role ייעודי לתמליל - לא 'assistant' (שייראה כתשובה רגילה של הסוכן) ולא
	// 'system' (ש-DCard_Mailer מדלג עליו לגמרי). DCard_Mailer::render_html()
	// מרנדר role זה בעיצוב נבדל (רקע אזהרה), כדי שהפרעה תיראה ברור שונה
	// מתשובה תקינה כשקוראים את המייל.
	const AI_ERROR_ROLE = 'error';

	public function register_routes() {
		register_rest_route( self::NAMESPACE_, '/session/start', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'session_start' ),
			'permission_callback' => array( $this, 'verify_nonce_if_present' ),
			'args'                => array(
				'userName'   => array( 'required' => false ),
				'userGender' => array( 'required' => true ),
				// Anti account-sharing (שלב 3) - אופציונלי: משתמש אנונימי
				// (אין dcard_userId ב-localStorage) פשוט לא שולח את זה
				// בכלל, וממשיך להיות משתמש אנונימי לגמרי כמו קודם - ר'
				// device_token_mismatch() למטה. רק משתמש שבאמת נרשם
				// (ויש לו user_id) חייב גם לשלוח כותרת X-Dcard-Device-Token
				// תואמת כדי להתקדם.
				'user_id'    => array( 'required' => false ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/session/card-selected', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'session_card_selected' ),
			'permission_callback' => array( $this, 'verify_nonce_if_present' ),
			'args'                => array(
				'session_token' => array( 'required' => true ),
				'card_id'       => array( 'required' => true ),
				// Optional: the user's original question, sent on the FIRST
				// card of a session so this single call can ground the AI's
				// reflection in it. See the comment on session_card_selected_inner().
				'question'      => array( 'required' => false ),
				// 'chat' (default) or 'message' - see MESSAGE_MODE_INSTRUCTION below.
				'mode'          => array( 'required' => false ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/session/turn', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'session_turn' ),
			'permission_callback' => array( $this, 'verify_nonce_if_present' ),
			'args'                => array(
				'session_token' => array( 'required' => true ),
				'message'       => array( 'required' => true ),
				// true on the 3rd/last message the frontend allows in Personal
				// Chat mode - see FINAL_TURN_INSTRUCTION below.
				'is_final_turn' => array( 'required' => false ),
			),
		) );

		// Freemium PLG, שלב 2: Webhook מטופס ההרשמה של אלמנטור - לא קריאה
		// מהפרונטאנד שלנו עצמו, אז verify_nonce_if_present (כמו שאר ה-
		// routes) לא רלוונטי כאן: ה-Webhook של אלמנטור הוא קריאת HTTP
		// שרת-לשרת, לא קוד שרץ בדפדפן המשתמש, ולכן לעולם לא ישלח X-WP-Nonce
		// משלנו - "רך" (soft, מאפשר בלי nonce) היה שווה ערך בפועל ל-
		// __return_true, אז אין טעם בבדיקה הנוספת הזו כאן. ההגנה היחידה
		// כרגע היא rate limiting (DCard_Rate_Limiter::check_register, ר'
		// session_register_inner) - אין עדיין secret/חתימה משותפת לאימות
		// שהבקשה באמת הגיעה מאלמנטור ולא מכל מי שמנחש את ה-URL; ר' הערה
		// על זה בתשובה שאחרי הקוד.
		register_rest_route( self::NAMESPACE_, '/register', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'session_register' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'email'  => array( 'required' => false ),
				'name'   => array( 'required' => false ),
				'phone'  => array( 'required' => false ),
				'gender' => array( 'required' => false ),
			),
		) );

		// Webhook (IPN) מקארדקום. תיקון: הנתיב הקודם - /webhook/payment -
		// חזר 404 לקארדקום גם אחרי רענון Permalinks. המילה "payment"
		// בנתיב עצמו כנראה מזוהה ע"י WAF/תוסף אבטחה בשרת כטריגר חסימה
		// לבקשות POST חיצוניות (לפני שהבקשה מגיעה בכלל לוורדפרס/לקוד
		// הזה - אין שום דרך לתקן את זה מתוך PHP). שם הנתיב הוחלף לניטרלי -
		// /webhook/ipn-update - בלי לשנות שום דבר בלוגיקה/בשמות הפונקציות
		// עצמן. *** יש לעדכן את הכתובת שמוגדרת בממשק קארדקום בהתאם (ר'
		// התשובה המלאה). ***
		// permission_callback פתוח - אותה סיבה כמו ב-/register: קריאת
		// webhook שרת-לשרת לעולם לא תישא X-WP-Nonce. האבטחה כאן היא
		// secret (ר' payment_webhook_inner) - נבדק בתוך ה-handler, לא
		// ב-permission_callback, כדי שהודעת השגיאה תהיה אחידה עם שאר
		// הפלאגין (JSON דרך safely(), לא WP_Error גולמי).
		register_rest_route( self::NAMESPACE_, '/webhook/ipn-update', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'payment_webhook' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'secret'                               => array( 'required' => false ),
				'ResponseCode'                          => array( 'required' => false ),
				'ExtReadInvoiceHead_ExternalId'         => array( 'required' => false ),
				'ExtReadInvoiceLines_ProductID'         => array( 'required' => false ),
				'ExtReadInvoiceHead_InvoiceNumber'      => array( 'required' => false ),
				'ExtReadInvoiceHead_TotalIncludeVAT'    => array( 'required' => false ),
				'ExtReadInvoiceHead_InvoiceDate'        => array( 'required' => false ),
				'ExtReadInvoiceHead_CustMobilePH'       => array( 'required' => false ),
			),
		) );

		// אבחון Hostinger/WAF (ר' התשובה המלאה בשיחה): כלי קצר-טווח כדי
		// להבדיל בין "כל הנתיב הזה חסום" (WAF שמזהה לפי ה-URL/path עצמו)
		// לבין "רק ה-POST עם תוכן התשלום של קארדקום נחסם" (WAF שבודק את
		// גוף הבקשה, למשל Imunify360 שמותקן כברירת מחדל ב-Hostinger). GET
		// פשוט, בלי secret, בלי שום לוגיקה - אפשר לבדוק פשוט בדפדפן/בנייד
		// בלי curl: ...dcard/v1/webhook/ping. אם גם זה לא עובד - החסימה
		// היא על ה-path עצמו, לא על תוכן ה-POST. להסיר כשהבעיה נפתרת
		// (לא מיועד לשימוש קבוע, זה route אבחון בלבד).
		register_rest_route( self::NAMESPACE_, '/webhook/ping', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'webhook_ping' ),
			'permission_callback' => '__return_true',
		) );

		// Freemium PLG: סנכרון מצב מנוי (plan/credits) בדפדפן - נקרא
		// בטעינת עמוד אחרי חזרה מקארדקום (ר' elementor-code.html), כדי
		// שה-UI יוכל לדעת שהתשלום הצליח בלי לחכות לפעולה נוספת מהמשתמש.
		// GET, לא POST - קריאת מידע בלבד, שום דבר לא משתנה בשרת. מזהה
		// את המשתמש רק דרך X-Dcard-Device-Token (אותו header שכל קריאה
		// אחרת כבר שולחת) - בלי Device Token תקף, 404 (לא 200 עם נתונים
		// ריקים - "אין משתמש כזה" הוא באמת false, לא "אין קרדיטים").
		register_rest_route( self::NAMESPACE_, '/account/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'account_status' ),
			'permission_callback' => array( $this, 'verify_nonce_if_present' ),
		) );

		register_rest_route( self::NAMESPACE_, '/session/end', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'session_end' ),
			'permission_callback' => array( $this, 'verify_nonce_if_present' ),
			'args'                => array(
				'session_token' => array( 'required' => true ),
				'email'         => array( 'required' => false ),
				// Card image URLs from the frontend DOM (img.src of the drawn cards),
				// embedded at the top of the summary email. See session_end_inner().
				'cardImages'    => array( 'required' => false ),
			),
		) );
	}

	/**
	 * תיקון QA 4.3: permission_callback לכל ה-routes. ר' הערת ה-class למעלה
	 * להסבר המלא על למה זו בדיקה "רכה" (soft) ולא דרישה מוחלטת.
	 */
	public function verify_nonce_if_present( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce ) {
			// לא נשלח בכלל (build ישן של הפרונטאנד שעדיין בקאש, כלי בדיקה
			// חיצוני וכו') - מאפשרים, בדיוק כמו לפני שהתכונה הזו נוספה.
			return true;
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			DCard_Logger::warning( 'Invalid X-WP-Nonce on REST request - rejecting', array(
				'route' => $request->get_route(),
			) );
			return new WP_Error( 'dcard_invalid_nonce', 'בקשה לא תקינה.', array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Runs $fn (one of the *_inner methods below) and guarantees a
	 * WP_REST_Response comes back no matter what happens inside it.
	 */
	private function safely( $endpoint_label, callable $fn ) {
		try {
			return $fn();
		} catch ( \Throwable $e ) {
			DCard_Logger::error( "Unhandled exception in {$endpoint_label}", array(
				'message' => $e->getMessage(),
				'file'    => $e->getFile(),
				'line'    => $e->getLine(),
				'trace'   => $e->getTraceAsString(),
			) );
			return new WP_REST_Response( array(
				'error' => 'קרתה תקלה בשרת. נסו שוב בעוד רגע.',
			), 500 );
		}
	}

	// ---------------------------------------------------------------
	// POST /session/start
	// ---------------------------------------------------------------
	public function session_start( WP_REST_Request $request ) {
		return $this->safely( 'session_start', function () use ( $request ) {
			return $this->session_start_inner( $request );
		} );
	}

	private function session_start_inner( WP_REST_Request $request ) {
		$ip = DCard_Rate_Limiter::client_ip();
		if ( ! DCard_Rate_Limiter::check_session_start( $ip ) ) {
			return new WP_REST_Response( array( 'error' => 'יותר מדי בקשות. נסו שוב בעוד קצת.' ), 429 );
		}

		// Anti account-sharing (שלב 3): נבדק *רק* אם הבקשה בכלל טוענת
		// להיות של משתמש רשום (user_id > 0 - מגיע מ-dcard_userId שנשמר
		// ב-localStorage, ר' elementor-code.html). משתמש אנונימי לא שולח
		// user_id בכלל ועובר בלי שום שינוי בהתנהגות הקיימת - הבדיקה הזו
		// אף פעם לא חוסמת שימוש אנונימי/חינמי.
		//
		// כש-user_id כן נשלח, חייבת להגיע גם כותרת X-Dcard-Device-Token
		// שתואמת *בדיוק* לטוקן השמור על אותו user_id כרגע (ר'
		// DCard_Users::verify_device_token) - אם היא חסרה, או שהתחברות
		// מאוחרת יותר ממכשיר אחר כבר הנפיקה טוקן חדש (ר' issue_device_
		// token - כל הנפקה מחליפה את הקודמת), הבקשה הזו נדחית ב-403.
		// זה בדיוק מנגנון מניעת שיתוף החשבון: שני מכשירים לא יכולים
		// להיות "מחוברים" בתור אותו משתמש רשום בו-זמנית.
		$claimed_user_id = absint( $request->get_param( 'user_id' ) );
		if ( $claimed_user_id > 0 ) {
			$device_token = $request->get_header( 'X-Dcard-Device-Token' );
			if ( ! DCard_Users::verify_device_token( $claimed_user_id, $device_token ) ) {
				DCard_Logger::warning( 'Device token missing/mismatched on /session/start - rejecting (possible account sharing)', array(
					'claimed_user_id' => $claimed_user_id,
				) );
				return new WP_REST_Response( array(
					'error' => 'החשבון הזה מחובר כרגע ממכשיר אחר. התחבר/י מחדש כדי להמשיך כאן.',
					'code'  => 'device_mismatch',
				), 403 );
			}
		}

		$user_name   = sanitize_text_field( (string) $request->get_param( 'userName' ) );
		$user_gender = sanitize_text_field( (string) $request->get_param( 'userGender' ) );

		// Settings > DCard AI > Test User Name/Gender (same idea as Force Card
		// ID for Testing) - when set, override whatever the frontend's popup
		// sent, so QA doesn't have to fill it in on every test run. Empty =
		// no override, the client-supplied value is used as before.
		$test_name = DCard_Settings::get_test_user_name();
		if ( '' !== $test_name ) {
			$user_name = $test_name;
		}
		$test_gender = DCard_Settings::get_test_user_gender();
		if ( '' !== $test_gender ) {
			$user_gender = $test_gender;
		}

		if ( ! in_array( $user_gender, array( 'male', 'female' ), true ) ) {
			$user_gender = 'male'; // ברירת מחדל בטוחה, אם גם הלקוח וגם ה-override לא סיפקו ערך תקין
		}

		$token = DCard_DB::create_session( $user_name ? $user_name : null, $user_gender );

		$greeting = 'שאלת:';

		DCard_DB::append_transcript_entry( $token, array(
			'role' => 'assistant',
			'text' => $greeting,
		) );

		return new WP_REST_Response( array(
			'session_token' => $token,
			'aiReply'       => $greeting,
			// Testing mode (Settings > DCard AI > Force Card ID). null = normal random draw.
			'forceCardId'   => DCard_Settings::get_forced_card_id(),
			// Resolved name/gender - after the Test User Name/Gender override above,
			// if any. The frontend uses userGender for gender-dependent UI text
			// (question placeholder, deck button) since it may differ from what it sent.
			'userName'      => $user_name,
			'userGender'    => $user_gender,
		), 200 );
	}

	// ---------------------------------------------------------------
	// POST /session/card-selected
	// ---------------------------------------------------------------
	public function session_card_selected( WP_REST_Request $request ) {
		return $this->safely( 'session_card_selected', function () use ( $request ) {
			return $this->session_card_selected_inner( $request );
		} );
	}

	private function session_card_selected_inner( WP_REST_Request $request ) {
		// ר' ההערה המלאה ב-DCard_Rate_Limiter::MAX_CARD_SELECTIONS_PER_HOUR -
		// זה ה-route היחיד שקורא ל-AI ולא היה מוגבל בקצב בכלל עד כה (אותה
		// בדיקה ובדיוק אותו מקום ביחס לסניטציה כמו ב-session_turn_inner למטה).
		$ip = DCard_Rate_Limiter::client_ip();
		if ( ! DCard_Rate_Limiter::check_card_selected( $ip ) ) {
			return new WP_REST_Response( array( 'error' => 'יותר מדי בקשות. נסו שוב בעוד קצת.' ), 429 );
		}

		$session_token = sanitize_text_field( (string) $request->get_param( 'session_token' ) );
		$card_id       = absint( $request->get_param( 'card_id' ) );
		$question      = sanitize_textarea_field( (string) $request->get_param( 'question' ) );
		$mode          = sanitize_text_field( (string) $request->get_param( 'mode' ) );
		$mode          = in_array( $mode, array( 'chat', 'message' ), true ) ? $mode : 'chat';

		$session = DCard_DB::get_session( $session_token );
		if ( ! $session || 'active' !== $session['status'] ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'סשן לא נמצא או שאינו פעיל' ), 404 );
		}

		// Freemium PLG, אכיפת קרדיטים - תיקון יחידת המדידה: קרדיט אחד =
		// סשן אחד (שאלה אחת, עד 3 קלפים + כל השיחה סביבם), לא הודעה/קלף
		// בודד. לכן הבדיקה (ולא רק ה-הורדה, ר' deduct_credit למטה) חלה
		// *רק* על הקלף הראשון בסשן (drawn_cards עדיין ריק) - קלף שני/
		// שלישי באותו סשן לא נבדק ולא מוריד קרדיט נוסף, כי הסשן כבר שולם
		// עליו בקלף הראשון. מזוהה כאן (לא דרך פרמטר נפרד) - X-Dcard-Device-
		// Token כבר מצורף אוטומטית לכל קריאה מה-frontend. משתמש אנונימי
		// (אין header כזה / לא תואם) - $registered_user נשאר null, בלי
		// שום בדיקת קרדיטים (המכסה האנונימית עדיין בצד הלקוח, ברמת סשן גם
		// היא - ר' התיקון המקביל ב-elementor-code.html).
		$device_token      = $request->get_header( 'X-Dcard-Device-Token' );
		$registered_user   = $device_token ? DCard_Users::get_by_device_token( $device_token ) : null;
		$is_first_card_draw = empty( $session['drawn_cards'] );

		if ( $registered_user && $is_first_card_draw && ! DCard_Users::has_credits( $registered_user['id'] ) ) {
			DCard_Logger::info( 'session_card_selected - registered user out of credits, rejecting with 403', array( 'user_id' => $registered_user['id'] ) );
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => 'נגמרו הקרדיטים שלך במסלול הנוכחי. אפשר לשדרג כדי להמשיך.',
				'code'    => 'credits_exhausted',
			), 403 );
		}

		// Testing mode: the first card of a session is always the forced one,
		// whatever the client sent, so the AI is guaranteed to see that card.
		$forced_card_id = DCard_Settings::get_forced_card_id();
		if ( $forced_card_id && empty( $session['drawn_cards'] ) ) {
			$card_id = $forced_card_id;
		}

		if ( $card_id < 1 || $card_id > 72 || ! DCard_Cards::exists( $card_id ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'card_id לא תקין' ), 400 );
		}

		if ( in_array( $card_id, $session['drawn_cards'], true ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'הקלף הזה כבר נבחר בסשן הזה' ), 409 );
		}

		if ( count( $session['drawn_cards'] ) >= 3 ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'הגעת למקסימום 3 קלפים לשאלה' ), 409 );
		}

		// The frontend flow here skips a separate clarifying chat turn - the
		// user types their question and draws a card in one step. So the
		// question is carried in on THIS call (first card only) instead of
		// via /session/turn, and folded into the state before we call the AI,
		// so a single call can already ground the reflection in it. Without
		// this, the frontend previously had to fire a second /session/turn
		// call right after this one just to tell the model the question -
		// two sequential AI calls for one card draw, which is what was
		// pushing total wait past the request timeout.
		if ( $question && empty( $session['state']['original_question'] ) ) {
			DCard_DB::update_state( $session_token, array(
				'original_question' => $question,
				'refined_question'  => $question,
			) );
			$session['state']['original_question'] = $question;
			$session['state']['refined_question']  = $question;

			DCard_DB::append_transcript_entry( $session_token, array(
				'role' => 'user',
				'text' => $question,
			) );
		}

		$card          = DCard_Cards::get( $card_id );
		$candidate_ids = array_merge( $session['drawn_cards'], array( $card_id ) );
		$is_additional_card = count( $candidate_ids ) > 1;

		// The card is already on screen, so the model must be in the card
		// reflection stage. Left at "clarify_question", it follows the
		// clarification flow and tells the user to go pick a card instead of
		// reacting to the one they just drew.
		$session['state']['stage'] = $this->stage_after_draw( $session['state']['stage'], count( $candidate_ids ) );

		// The opening line for ANY card drawn in chat mode (1st, 2nd or 3rd) is
		// a fixed template per agent-brain-instructions.md שלב ה'/ח' - "מה עולה
		// בך כשאת/ה מסתכל/ת על הקלף?" (first card) or "שלפת קלף נוסף. מה עולה
		// בך כשאת/ה מסתכל/ת עליו?" (2nd/3rd). The prompt itself forbids the
		// model from opening with any interpretation, summary or symbol list
		// before asking this - so even when the AI *was* called here, its
		// answer was already constrained to be exactly this sentence. There is
		// nothing for the model to actually decide, so calling it just adds a
		// full API round-trip (seconds) for a line that never varies with the
		// question, the prior conversation or the card's content. This used to
		// be fast-pathed for the 2nd/3rd card only; extended here to the first
		// card too, since the same reasoning applies identically - now the
		// very first turn of every reading is instant, with no AI call and no
		// loading UI to show for it. Message mode still goes through the AI:
		// its single reply is the substantive content, not a transition line.
		if ( 'chat' === $mode ) {
			$data = array(
				'speak'         => $is_additional_card
					? ( 'male' === $session['user_gender']
						? 'שלפת קלף נוסף. מה עולה בך כשאתה מסתכל עליו?'
						: 'שלפת קלף נוסף. מה עולה בך כשאת מסתכלת עליו?' )
					: ( 'male' === $session['user_gender']
						? 'מה עולה בך כשאתה מסתכל על הקלף?'
						: 'מה עולה בך כשאת מסתכלת על הקלף?' ),
				'next_action'   => 'wait_for_user',
				'end_session'   => false,
				'memory_update' => array(), // הסטייג' כבר עודכן למעלה ב-$session['state'] - אין עוד מה למזג.
			);
			$provider = null;
			$model    = null;
		} else {
			$state_payload = $this->build_state_payload( $session, array(
				// Text only, and only the card just drawn in full. Earlier cards as short
				// summaries - never image data, never the card database.
				'current_card'   => DCard_Cards::describe( $card ),
				'selected_cards' => DCard_Cards::summaries( $session['drawn_cards'] ),
				'user_message'   => '',
				'site_event'     => $is_additional_card ? 'additional_card_shown' : 'first_card_shown',
			), $candidate_ids );

			$extra_instruction = ( 'message' === $mode ) ? self::MESSAGE_MODE_INSTRUCTION : '';
			// 'message' mode uses the "מודל לקבלת מסר" (Settings > DCard AI); the
			// first-card 'chat' path uses "מודל לשיחה אישית" - same mapping as
			// /session/turn below, so the two site "reading modes" line up with
			// the two independently configured models.
			$reading_mode      = ( 'message' === $mode ) ? 'direct' : 'chat';
			$result            = DCard_AI_Gateway::run_turn( $state_payload, $extra_instruction, $reading_mode );

			if ( ! $result['ok'] ) {
				// DCard_AI_Gateway already logged the low-level cause - this is
				// a legitimate upstream failure, not a PHP fatal, so a normal
				// error response (not a crash) is correct here.
				// HTTP 200 on purpose: Cloudflare replaces an origin 502/504 body with
				// its own HTML error page, which the SPA reports as a "connection
				// error" and hides the real reason. The failure is in `success`/`error`.

				// תיקון: עד כה לא נשמר כאן כלום בתמליל בכשל - בניגוד ל-/session/turn
				// (ר' למטה), שם אותו תיקון כבר קיים. כשל כאן (בקלף הראשון) היה
				// משאיר את מייל הסיכום שקט לגמרי, בלי שום עדות שהשיחה בכלל
				// ניסתה להתחיל.
				DCard_DB::append_transcript_entry( $session_token, array(
					'role' => self::AI_ERROR_ROLE,
					'text' => self::AI_ERROR_TRANSCRIPT_TEXT,
				) );

				// תיקון: דיווח לנו על כשל AI דרך אותו Analytics Webhook שכבר
				// קיים (Settings > DCard AI) - no-op אם לא מוגדר URL. עד כה
				// הדרך היחידה לדעת שזה קרה הייתה לחפש ידנית ב-WooCommerce Logs.
				DCard_Analytics::track( 'ai_error', array(
					'session_token' => $session_token,
					'user_name'     => $session['user_name'],
					'endpoint'      => 'card-selected',
					'route'         => DCard_Analytics::route_label( $mode ),
					'error'         => $result['error'],
				) );

				return new WP_REST_Response( array( 'success' => false, 'error' => $result['error'] ), 200 );
			}

			$data     = $this->enforce_end_session_consistency( $result['data'] );
			$provider = $result['provider'];
			$model    = $result['model'];
		}

		// Captured before the reset two lines down - the final tally for
		// whichever card was active up to this point (0 on the very first
		// draw of the session, since there is no "previous card" yet).
		$turns_on_previous_card = isset( $session['state']['card_turn_count'] ) ? (int) $session['state']['card_turn_count'] : 0;

		// Only commit the card + state once we have a usable AI response.
		DCard_DB::add_drawn_card( $session_token, $card_id );

		// ר' הבדיקה למעלה - מורידים קרדיט רק עכשיו, אחרי הצלחה בפועל, רק
		// אם זוהה משתמש רשום, ו*רק אם זה הקלף הראשון בסשן* ($is_first_card_
		// draw חושב *לפני* add_drawn_card() - לכן עדיין תקף כאן, לא השתנה
		// בינתיים). קלף שני/שלישי באותו סשן לא מוריד קרדיט נוסף - הסשן
		// כולו (עד 3 קלפים + כל השיחה) הוא יחידת החיוב, לא כל קלף בנפרד.
		if ( $registered_user && $is_first_card_draw ) {
			DCard_Users::deduct_credit( $registered_user['id'] );
		}
		$this->persist_memory_update( $session_token, $session['state'], $data['memory_update'], count( $candidate_ids ) );
		// Recorded so /session/end knows which email intro text to use (DCard_Mailer).
		// MUST run after persist_memory_update(): that call writes a full state
		// snapshot built from $session['state'] (captured before this request),
		// which still has the OLD reading_mode/card_turn_count - writing this
		// first would just get silently overwritten back to the stale values by
		// that call. card_turn_count resets to 0 on every card draw (1st/2nd/3rd,
		// any mode) - see CARD_TURN_CAP above for what consumes it.
		DCard_DB::update_state( $session_token, array( 'reading_mode' => $mode, 'card_turn_count' => 0 ) );

		DCard_DB::append_transcript_entry( $session_token, array(
			'role'    => 'system',
			'text'    => 'קלף נבחר: #' . $card_id,
			'card_id' => $card_id,
		) );
		// provider/model are null for the fast-path template (no AI call was made) -
		// DCard_Mailer::ai_label() already skips transcript entries with no provider.
		DCard_DB::append_transcript_entry( $session_token, array(
			'role'     => 'assistant',
			'text'     => $data['speak'],
			'provider' => $provider,
			'model'    => $model,
		) );

		// Beta-testing analytics (Settings > DCard AI > Analytics Webhook URL) -
		// fire-and-forget, no-op if no URL is configured. "Turns on previous
		// card" rather than the just-drawn one: a brand-new card always has 0
		// turns at this exact instant, so that number only becomes meaningful
		// once reported for the card that just finished.
		DCard_Analytics::track( 'card_drawn', array(
			'session_token'          => $session_token,
			'user_name'              => $session['user_name'],
			'user_email'             => null, // לא ידוע בשלב הזה - נאסף רק ב-/session/end
			'route'                  => DCard_Analytics::route_label( $mode ),
			'card_id'                => $card_id,
			'cards_drawn_so_far'     => count( $candidate_ids ),
			'turns_on_previous_card' => $turns_on_previous_card,
		) );

		return new WP_REST_Response( array(
			'success'     => true,
			'cardId'      => $card_id,
			'aiReply'     => $data['speak'],
			'nextAction'  => $data['next_action'],
			'endSession'  => (bool) $data['end_session'],
		), 200 );
	}

	// ---------------------------------------------------------------
	// POST /session/turn
	// ---------------------------------------------------------------
	public function session_turn( WP_REST_Request $request ) {
		return $this->safely( 'session_turn', function () use ( $request ) {
			return $this->session_turn_inner( $request );
		} );
	}

	private function session_turn_inner( WP_REST_Request $request ) {
		$ip = DCard_Rate_Limiter::client_ip();
		if ( ! DCard_Rate_Limiter::check_turn( $ip ) ) {
			return new WP_REST_Response( array( 'error' => 'יותר מדי בקשות. נסו שוב בעוד קצת.' ), 429 );
		}

		$session_token  = sanitize_text_field( (string) $request->get_param( 'session_token' ) );
		$message        = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		$is_final_turn  = filter_var( $request->get_param( 'is_final_turn' ), FILTER_VALIDATE_BOOLEAN );

		$session = DCard_DB::get_session( $session_token );
		if ( ! $session || 'active' !== $session['status'] ) {
			return new WP_REST_Response( array( 'error' => 'סשן לא נמצא או שאינו פעיל' ), 404 );
		}

		// תיקון יחידת מדידה: קרדיט אחד = סשן אחד (ר' ההערה המלאה ב-
		// session_card_selected_inner), לא הודעה בודדת. /session/turn הוא
		// *תוך* סשן שכבר התחיל (ושהקרדיט שלו כבר נבדק/נגבה על הקלף
		// הראשון) - הודעות שיחה פתוחה לא נבדקות ולא גובות קרדיט נוסף כאן
		// בכלל יותר. (הוסר - קודם היה כאן בדיקת has_credits/דחיית 403
		// זהה ל-session_card_selected_inner, שגרמה בפועל לחיוב כפול: קרדיט
		// אחד על הקלף הראשון ועוד "בדיקה" על כל הודעת מעקב באותו סשן.)

		if ( ! empty( $session['drawn_cards'] ) ) {
			$session['state']['stage'] = $this->stage_after_draw( $session['state']['stage'], count( $session['drawn_cards'] ) );
		}
		$state = $session['state'];

		// The site is the source of truth for turn_count (per agent-brain-instructions.md
		// section 10: "אין להגדיל מונים על סמך ניחוש. האתר הוא מקור האמת למונים").
		if ( (int) $state['turn_count'] >= 15 ) {
			// עודכן: הנוסח הקודם ("לא הצלחנו למצות את השאלה...") הניח שהשיחה
			// נקטעת באמצע דיון פעיל. עם התיקונים בשלבים ז'/ט' בהוראות הסוכן,
			// הגעה בפועל ל-15 סימנים שהשיחה כבר נעה לכיוון סיום, לא שנקטעה.
			$closing = 'אני מקווה שהשיחה בינינו הועילה לך ואשמח להיפגש שוב.';
			DCard_DB::append_transcript_entry( $session_token, array( 'role' => 'user', 'text' => $message ) );
			DCard_DB::append_transcript_entry( $session_token, array( 'role' => 'assistant', 'text' => $closing ) );
			return new WP_REST_Response( array(
				'aiReply'    => $closing,
				'endSession' => true,
				'nextAction' => 'offer_email',
			), 200 );
		}

		$new_turn_count      = (int) $state['turn_count'] + 1;
		// See CARD_TURN_CAP above - server-owned count of turns since the
		// current card was drawn, independent of the overall 15-turn cap.
		$new_card_turn_count = (int) $state['card_turn_count'] + 1;

		$state_payload = $this->build_state_payload( $session, array(
			'current_card'   => null,
			'selected_cards' => DCard_Cards::for_turn( $session['drawn_cards'] ),
			'user_message'   => $message,
			'turn_count'     => $new_turn_count,
		), $session['drawn_cards'] );

		// The whole-session 15-turn cap (is_final_turn) takes priority over the
		// per-card cap when both apply - it ends the session entirely, not just
		// this card's reflection loop.
		if ( $is_final_turn ) {
			$extra_instruction = self::FINAL_TURN_INSTRUCTION;
		} elseif ( $new_card_turn_count >= self::CARD_TURN_CAP ) {
			$extra_instruction = self::CARD_TURN_CAP_INSTRUCTION;
		} else {
			$extra_instruction = '';
		}
		// /session/turn only exists within the Personal Chat flow (Direct Message
		// mode never has a follow-up text turn) - always the "מודל לשיחה אישית".
		$result            = DCard_AI_Gateway::run_turn( $state_payload, $extra_instruction, 'chat' );

		if ( ! $result['ok'] ) {
			// Previously nothing was written to the transcript on failure, so a
			// call that failed mid-conversation (timeout, truncated/invalid JSON
			// from the model, etc.) just vanished - the user's own message was
			// never saved, so the summary email silently stopped right where the
			// failure happened with no trace of it. Now the user's message and a
			// clearly-marked error entry (role AI_ERROR_ROLE, not 'assistant' -
			// see DCard_Mailer::render_html()) are recorded either way, so the
			// email reflects what actually happened instead of just cutting off.
			DCard_DB::append_transcript_entry( $session_token, array( 'role' => 'user', 'text' => $message ) );
			DCard_DB::append_transcript_entry( $session_token, array(
				'role' => self::AI_ERROR_ROLE,
				'text' => self::AI_ERROR_TRANSCRIPT_TEXT,
			) );

			// תיקון: דיווח לנו על כשל AI דרך אותו Analytics Webhook שכבר קיים.
			DCard_Analytics::track( 'ai_error', array(
				'session_token' => $session_token,
				'user_name'     => $session['user_name'],
				'endpoint'      => 'turn',
				'route'         => DCard_Analytics::route_label( isset( $session['state']['reading_mode'] ) ? $session['state']['reading_mode'] : null ),
				'error'         => $result['error'],
			) );

			// HTTP 200 on purpose - see the note in session_card_selected_inner().
			return new WP_REST_Response( array( 'success' => false, 'error' => $result['error'] ), 200 );
		}

		$data = $this->enforce_end_session_consistency( $result['data'] );
		$data = $this->enforce_card_cap_consistency( $data, count( $session['drawn_cards'] ), $session['user_gender'] );

		// turn_count and card_turn_count are server-owned; everything else in
		// memory_update is trusted.
		$this->persist_memory_update( $session_token, $state, array_merge(
			$data['memory_update'],
			array( 'turn_count' => $new_turn_count, 'card_turn_count' => $new_card_turn_count )
		), count( $session['drawn_cards'] ) );

		DCard_DB::append_transcript_entry( $session_token, array( 'role' => 'user', 'text' => $message ) );
		DCard_DB::append_transcript_entry( $session_token, array(
			'role'     => 'assistant',
			'text'     => $data['speak'],
			'provider' => $result['provider'],
			'model'    => $result['model'],
		) );

		// ר' ההערה למעלה - קרדיט אחד = סשן אחד, כבר נגבה (אם בכלל) על
		// הקלף הראשון ב-session_card_selected_inner. אין עוד גביה כאן.

		return new WP_REST_Response( array(
			'aiReply'      => $data['speak'],
			'nextAction'   => $data['next_action'],
			'showCardDraw' => ( 'request_card_draw' === $data['next_action'] ),
			'endSession'   => (bool) $data['end_session'],
		), 200 );
	}

	// ---------------------------------------------------------------
	// POST /session/end
	// ---------------------------------------------------------------
	public function session_end( WP_REST_Request $request ) {
		return $this->safely( 'session_end', function () use ( $request ) {
			return $this->session_end_inner( $request );
		} );
	}

	private function session_end_inner( WP_REST_Request $request ) {
		$session_token = sanitize_text_field( (string) $request->get_param( 'session_token' ) );
		$email_raw     = (string) $request->get_param( 'email' );
		$email         = $email_raw ? sanitize_email( $email_raw ) : '';
		$card_images   = $this->sanitize_card_image_urls( $request->get_param( 'cardImages' ) );

		$session = DCard_DB::get_session( $session_token );
		if ( ! $session ) {
			return new WP_REST_Response( array( 'error' => 'סשן לא נמצא' ), 404 );
		}

		$email_sent    = false;
		$is_message_mode = 'message' === ( isset( $session['state']['reading_mode'] ) ? $session['state']['reading_mode'] : null );

		if ( $email && is_email( $email ) ) {
			$email_sent = DCard_Mailer::send_transcript( $email, $session['user_name'], $session['transcript'], $card_images, $is_message_mode, $session_token );
			if ( ! $email_sent ) {
				DCard_Logger::warning( 'wp_mail() returned false for session end email', array(
					'session_token' => $session_token,
				) );
			} else {
				DCard_DB::set_email_sent_to( $session_token, $email );
			}
		}

		DCard_DB::set_status( $session_token, 'completed' );

		// Google Sheets logging (ר' Google Apps Script בתשובה): user_id/
		// user_email מזוהים כאן גם דרך X-Dcard-Device-Token (לא רק מה-
		// email שהוזן ב-/session/end עצמו, שלרוב לא נשלח כלל אם המשתמש
		// לא בחר "שליחה במייל") - כך שמשתמש רשום מקבל שורה מלאה גם אם
		// לא לחץ על שליחת מייל באותו סשן ספציפי.
		$device_token    = $request->get_header( 'X-Dcard-Device-Token' );
		$registered_user = $device_token ? DCard_Users::get_by_device_token( $device_token ) : null;
		$resolved_email  = ( $email && is_email( $email ) ) ? $email : ( $registered_user ? $registered_user['email'] : null );

		// Beta-testing analytics (Settings > DCard AI > Analytics Webhook URL) -
		// fire-and-forget, no-op if no URL is configured.
		DCard_Analytics::track( 'session_completed', array(
			'session_token'      => $session_token,
			'user_id'            => $registered_user ? (int) $registered_user['id'] : null,
			'user_name'          => $session['user_name'],
			'user_email'         => $resolved_email,
			'route'              => DCard_Analytics::route_label( isset( $session['state']['reading_mode'] ) ? $session['state']['reading_mode'] : null ),
			'cards_drawn'        => count( $session['drawn_cards'] ),
			'card_ids'           => implode( ', ', array_map( 'intval', $session['drawn_cards'] ) ),
			'turns_on_last_card' => isset( $session['state']['card_turn_count'] ) ? (int) $session['state']['card_turn_count'] : 0,
			'llm_model'          => DCard_Analytics::ai_label( $session['transcript'] ),
			'original_question'  => isset( $session['state']['original_question'] ) ? $session['state']['original_question'] : '',
			'chat_transcript'    => DCard_Analytics::format_transcript_text( $session['transcript'] ),
		) );

		// user_is_registered is not implemented yet in this beta (no accounts) - always the "new user" text.
		$closing_message = DCard_Settings::get_closing_text( false );

		return new WP_REST_Response( array(
			'emailSent'      => $email_sent,
			'closingMessage' => $closing_message,
		), 200 );
	}

	// ---------------------------------------------------------------
	// POST /register  (Freemium PLG, שלב 2 - Webhook מטופס ההרשמה באלמנטור)
	// ---------------------------------------------------------------
	public function session_register( WP_REST_Request $request ) {
		return $this->safely( 'session_register', function () use ( $request ) {
			return $this->session_register_inner( $request );
		} );
	}

	private function session_register_inner( WP_REST_Request $request ) {
		$ip = DCard_Rate_Limiter::client_ip();
		if ( ! DCard_Rate_Limiter::check_register( $ip ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'יותר מדי בקשות. נסו שוב בעוד קצת.' ), 429 );
		}

		// שמות השדות כפי שאלמנטור בפועל שולח אותם ב-Webhook לא ידועים
		// מראש בלי לראות את תצורת הטופס - נבדקים כמה חלופות סבירות (גם
		// 'email'/'name' הפשוטים, גם form_fields[email] כמו שראינו בטופס
		// ה-onboarding הקיים ב-elementor-code.html) במקום לנחש שם אחד בלבד
		// ולהיכשל בשקט אם הוא לא תואם.
		$email_raw  = $this->first_nonempty_param( $request, array( 'email', 'user_email', 'form_fields[email]' ) );
		$name_raw   = $this->first_nonempty_param( $request, array( 'name', 'user_name', 'full_name', 'form_fields[name]' ) );
		$phone_raw  = $this->first_nonempty_param( $request, array( 'phone', 'user_phone', 'form_fields[phone]' ) );
		$gender_raw = $this->first_nonempty_param( $request, array( 'gender', 'user_gender', 'form_fields[gender]' ) );

		$email  = $email_raw ? sanitize_email( $email_raw ) : '';
		$name   = sanitize_text_field( (string) $name_raw );
		$phone  = sanitize_text_field( (string) $phone_raw );
		$gender = in_array( $gender_raw, array( 'male', 'female' ), true ) ? $gender_raw : '';

		if ( ! $email || ! is_email( $email ) ) {
			DCard_Logger::warning( 'POST /register without a valid email - check the Elementor webhook field names/mapping', array(
				'received_keys' => array_keys( $request->get_params() ),
			) );
			return new WP_REST_Response( array( 'success' => false, 'error' => 'כתובת אימייל לא תקינה או חסרה' ), 400 );
		}

		// Anti account-sharing (שלב 3): register_and_issue_device_token
		// עושה upsert + הנפקת device_token חדש באותה קריאה - ר' ההערה
		// המלאה עליה ב-class-dcard-users.php למה זו נקודת הכניסה
		// המשותפת (גם ל-route הזה, גם ל-hook על elementor_pro/forms/
		// new_record ב-class-dcard-elementor-hooks.php), ולמה מומלץ
		// שרק אחת מהשתיים תרוץ בפועל על אותה שליחת טופס.
		$result = DCard_Users::register_and_issue_device_token( $email, $name, $phone, $gender );
		if ( null === $result ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'שגיאה בשמירת המשתמש' ), 500 );
		}

		// המכסות המוגדלות עצמן (Settings > DCard Quotas) - מוחזרות כאן כך
		// שהפרונטאנד יוכל לשמור אותן ליד user_id ב-localStorage. האכיפה
		// בפועל (להשתמש בהן במקום anonMsgLimit/anonChatLimit) היא עדיין
		// עבודה עתידית בצד ה-JS - ר' הערה ב-class-dcard-quotas.php וב-
		// class-dcard-users.php; ה-route הזה רק יוצר/מעדכן את המשתמש
		// ומחזיר את המספרים, לא משנה את לוגיקת הבדיקה הקיימת.
		return new WP_REST_Response( array(
			'success'      => true,
			'user_id'      => $result['user_id'],
			'deviceToken'  => $result['device_token'], // ר' הערת השם ב-class-dcard-users.php - לא אותו דבר כמו session_token של קריאת קלפים
			'currentPlan'  => $result['current_plan'],
			'credits'      => $result['credits'],
			'regMsgLimit'  => (int) get_option( DCard_Quotas::OPTION_REG_MSG_LIMIT, DCard_Quotas::DEFAULT_REG_MSG_LIMIT ),
			'regChatLimit' => (int) get_option( DCard_Quotas::OPTION_REG_CHAT_LIMIT, DCard_Quotas::DEFAULT_REG_CHAT_LIMIT ),
		), 200 );
	}

	// ---------------------------------------------------------------
	// POST /webhook/ipn-update  (שלד בלבד - שלב 3, הכנה ל-Cardcom)
	// ---------------------------------------------------------------
	public function payment_webhook( WP_REST_Request $request ) {
		return $this->safely( 'payment_webhook', function () use ( $request ) {
			return $this->payment_webhook_inner( $request );
		} );
	}

	/**
	 * ר' ההערה המלאה ליד register_rest_route('/webhook/ping') - route
	 * אבחון זמני, לא קשור ללוגיקת העסק. error_log בכוונה (לא DCard_Logger) -
	 * אותה סיבה כמו ב-payment_webhook_inner: רוצים שורה ביומן השרת הגולמי
	 * שלא תלויה בשום דבר פנימי של הפלאגין, כדי לדעת בוודאות שהבקשה בכלל
	 * הגיעה לקוד PHP (לא נחסמה קודם ע"י Hostinger/WAF).
	 */
	public function webhook_ping( WP_REST_Request $request ) {
		error_log( '[DCard Webhook] GET /webhook/ping reached PHP successfully.' );
		return new WP_REST_Response( array(
			'success' => true,
			'time'    => gmdate( 'Y-m-d H:i:s' ),
		), 200 );
	}

	/**
	 * IPN מקארדקום. *** עדיין לא מאומת מול payload אמיתי - שם השדות
	 * וההנחות למטה (ProductID כ-substring, ExternalId = dcardUserId,
	 * אין הבדל מתועד בין SKU חודשי/שנתי) מבוססים על התיאור שסופק, לא על
	 * בקשה אמיתית שראינו. יש להריץ עסקת בדיקה אמיתית מקארדקום ולהשוות
	 * ל-WooCommerce > Status > Logs (מקור dcard-ai-reader) לפני production. ***
	 */
	private function payment_webhook_inner( WP_REST_Request $request ) {
		// 0a. אבחון ממוקד (זמני - להסיר אחרי שמאמתים): האם ה-secret אכן
		// עבר ל-POST body (כמו שהוגדר כרגע בממשק קארדקום, "הוסף את פרטי
		// המסמך ב-POST") או שהוא עדיין מגיע כ-query string ב-URL עצמו
		// (ואז עדיין ייכתב בלוג הגישה הגולמי של השרת, ללא קשר לקוד כאן).
		// ממסך את ה-secret אם בכלל מופיע ב-REQUEST_URI - רק מראה *אם* הוא
		// שם, לא את הערך עצמו.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		error_log( '[DCard Webhook] /webhook/ipn-update REQUEST_URI (secret redacted if present): ' . preg_replace( '/secret=[^&\s]+/i', 'secret=[REDACTED]', $request_uri ) . ' | secret-in-URL=' . ( false !== stripos( $request_uri, 'secret=' ) ? 'YES' : 'no' ) );

		// 0. לוג גולמי, לפני כל בדיקה (גם secret) - בכוונה error_log() ולא
		// DCard_Logger: זה כותב ל-error log הרגיל של PHP/וורדפרס (debug.log
		// אם WP_DEBUG_LOG מופעל, או error log השרת), בלי תלות ב-WooCommerce
		// פעיל. המטרה: לדעת *בוודאות* אם קארדקום בכלל פגעה ב-endpoint הזה,
		// ובאיזו צורה בדיוק - לפני שמשהו כאן מספיק לדחות/לפרש את הבקשה.
		// אם אין שום שורה כזו ביומן אחרי ניסיון תשלום אמיתי - ה-URL/ה-
		// secret שהוזנו בממשק קארדקום שגויים, זו לא בעיית parsing בקוד.
		//
		// תיקון אבטחה (דווח ע"י תמיכת Hostinger): error log של השרת הוא
		// טקסט גלוי שבני אדם (צוות תמיכה, כל מי שיש לו גישת hPanel/SSH)
		// יכולים לקרוא - הדאמפ הגולמי היה חושף את ה-secret האמיתי של ה-
		// webhook וגם PII מלא של לקוחות (מייל/טלפון/סכום) בכל קריאה. כאן
		// ממסכים במפורש רק את ה-secret (השדה היחיד שהוא *credential*, לא
		// רק מידע תפעולי) - שאר השדות (headers/payload) נשארים גלויים
		// כי זה בדיוק מה שדרוש לאבחון, אבל ר' ההנחיה בתשובה המלאה: יש
		// להחליף (rotate) את ה-secret בפועל אחרי שנחשף כך בטקסט גלוי
		// לצוות Hostinger, ולהוריד/להקטין את רמת הלוג הזו לאחר שהתקלה
		// נפתרת - לא מיועד להישאר ברמת הפירוט הזו לצמיתות.
		$redacted_params = $request->get_params();
		if ( isset( $redacted_params['secret'] ) ) {
			$redacted_params['secret'] = '[REDACTED]';
		}
		error_log( '[DCard Webhook] /webhook/ipn-update RAW payload (secret redacted): ' . wp_json_encode( $redacted_params ) );
		error_log( '[DCard Webhook] /webhook/ipn-update RAW headers: ' . wp_json_encode( $request->get_headers() ) );
		error_log( '[DCard Webhook] /webhook/ipn-update HTTP method: ' . $request->get_method() );
		// תיקון אבחון Hostinger/WAF: אם get_params() חזר ריק למרות שקארדקום
		// בוודאות שולחת שדות - זה יבדיל בין "הבקשה הגיעה ל-PHP אבל וורדפרס
		// לא פענח אותה" (get_body() כאן יכיל טקסט) לבין "הגוף עצמו הגיע ריק
		// ל-PHP" (get_body() גם הוא ריק - מצביע על WAF/פרוקסי שמנקה את
		// הבקשה *לפני* שהיא מגיעה לכאן, לא על בעיית פענוח בקוד שלנו).
		// secret ממוסך גם כאן (regex גולמי על ה-body, לא תלוי בפרמטר
		// ששמו בדיוק "secret" - חוסך מקרה קצה שבו ה-body עצמו מכיל את
		// המחרוזת גם אם get_params() כבר לא הראה אותה).
		$raw_body = (string) $request->get_body();
		$raw_body_redacted = preg_replace( '/secret=[^&\s]+/i', 'secret=[REDACTED]', $raw_body );
		error_log( '[DCard Webhook] /webhook/ipn-update RAW body (unparsed, secret redacted, length=' . strlen( $raw_body ) . '): ' . $raw_body_redacted );

		// 0b. אבחון נוסף (לא חוסם - log-only): כתובת ה-IP הקבועה שקארדקום
		// שולחת ממנה IPN אומתה בפועל (82.80.227.18, ר' הלוג/הדיווח של
		// Hostinger). אם בעתיד נראה כאן IP אחר כש-secret כן תקין - זה סימן
		// שקארדקום שינתה שרת/IP מבלי שידענו. לא הופך לבדיקת חובה עדיין
		// (לא לסכן לחסום תשלומים אמיתיים על בדיקה לא מאומתת מספיק), רק log.
		$cardcom_known_ip = '82.80.227.18';
		$actual_ip        = DCard_Rate_Limiter::client_ip();
		if ( $actual_ip !== $cardcom_known_ip ) {
			DCard_Logger::info( 'POST /webhook/ipn-update - source IP differs from known Cardcom IP (log-only, not blocking)', array(
				'actual_ip'   => $actual_ip,
				'expected_ip' => $cardcom_known_ip,
			) );
		}

		// 1. אבטחה: secret יכול להגיע ב-query string (למשל webhook/ipn-update?secret=...)
		// או בגוף ה-POST - שניהם נבדקים דרך get_param() (WP_REST_Request
		// ממזג את שניהם). ללא secret תואם - 403, לא מידע נוסף על הסיבה
		// (לא לעזור לניסיונות ניחוש).
		//
		// *** אבטחה - בעיה ידועה, לא פתורה: ה-secret מגיע כ-query string
		// (כפי שהוגדר בפועל בממשק קארדקום), ולכן הוא נכתב בטקסט גלוי בלוג
		// הגישה הסטנדרטי (access log) של השרת - לא קשור לקוד הזה בכלל
		// (כל שרת web רושם את ה-URL המלא כולל query string, זו התנהגות
		// ברירת מחדל). אם קארדקום תומכת בשליחת secret כפרמטר ב-body (POST
		// field) במקום ב-URL - זה היה פותר את זה. לא ידוע אם זו אפשרות
		// נתמכת בממשק שלהם - יש לבדוק. עד אז, ה-secret ימשיך להופיע בלוגי
		// שרת גולמיים (ולא בלוגים שהקוד שלנו כותב - אלה כבר ממוסכים). ***
		$secret = (string) $request->get_param( 'secret' );
		if ( ! $secret || ! hash_equals( DCARD_WEBHOOK_SECRET, $secret ) ) {
			// תיקון אבחון (דווח בפועל: secret לא תואם אחרי rotation) - בלי
			// לחשוף אף אחד משני ה-secret-ים בטקסט גלוי (אותה מדיניות כמו
			// קודם, הפעם מול לוג וורדפרס/WooCommerce שהמנהל קורא, לא רק
			// תמיכת Hostinger - עדיין לא ראוי לכתוב secret אמיתי ללוג).
			// מה שכן מספיק כדי לאבחן רווח מיותר/גרשיים שהועתקו בטעות/
			// secret שעודכן רק בצד אחד: אורך כל ערך, ותחילית+סופית קצרות
			// וממוסכות (לא כל הערך) של כל אחד מהם בנפרד.
			$mask = function ( $value ) {
				$value = (string) $value;
				$len   = strlen( $value );
				if ( $len <= 4 ) {
					return str_repeat( '*', $len );
				}
				return substr( $value, 0, 2 ) . str_repeat( '*', $len - 4 ) . substr( $value, -2 );
			};
			DCard_Logger::warning( 'POST /webhook/ipn-update - missing/invalid secret, rejecting', array(
				'received_keys'          => array_keys( $request->get_params() ),
				'received_secret_len'    => strlen( $secret ),
				'received_secret_masked' => $mask( $secret ),
				'expected_secret_len'    => strlen( (string) DCARD_WEBHOOK_SECRET ),
				'expected_secret_masked' => $mask( (string) DCARD_WEBHOOK_SECRET ),
			) );
			return new WP_REST_Response( array( 'success' => false, 'error' => 'Unauthorized' ), 403 );
		}

		// 2. סטטוס העסקה - '0' = הצלחה (לפי התיעוד). השוואת מחרוזות בכוונה
		// (לא ==) כי ResponseCode יכול להגיע כ-int 0 או כמחרוזת "0" תלוי
		// בפורמט השליחה - (string) מנרמל את שניהם לפני ההשוואה.
		$response_code = (string) $request->get_param( 'ResponseCode' );
		if ( '0' !== $response_code ) {
			DCard_Logger::info( 'POST /webhook/ipn-update - ResponseCode != 0, ignoring (not a successful transaction)', array( 'ResponseCode' => $response_code ) );
			return new WP_REST_Response( array( 'success' => false, 'error' => 'Transaction not successful' ), 200 ); // 200 בכוונה - קארדקום לא צריכה לנסות שוב, זו לא "שגיאה" מהצד שלנו
		}

		// 3. זיהוי המשתמש - שכבה ראשונה: ReturnValue (ר' הערה ב-elementor-
		// code.html). שכבה שנייה (Fallback): לוג IPN אמיתי מקארדקום (re-
		// transmit ידני) הראה ש-ReturnValue *לא קיים בכלל* ב-payload של
		// המוצר הספציפי הזה - כלומר דף התשלום הזה לא מהדהד פרמטרים
		// חיצוניים מה-URL בחזרה ב-IPN. חוזרים לתוכנית המקורית: אימייל
		// (ExtReadInvoiceHead_Email, דרך get_nested_param - תומך גם
		// מפתח שטוח עם קו תחתון וגם מבנה מקונן). הסיכון הידוע (אימייל
		// שהלקוח שינה בקארדקום לא יתאים לשורה הקיימת) מתועד, לא נפתר -
		// אין כרגע חלופה אחרת שקארדקום בפועל מחזירה עבור המוצר הזה.
		$return_value = $request->get_param( 'ReturnValue' );
		$user_id      = absint( $return_value );
		error_log( '[DCard Webhook] parsed ReturnValue=' . wp_json_encode( $return_value ) . ' -> user_id=' . $user_id );

		$user = $user_id > 0 ? DCard_Users::get_by_id( $user_id ) : null;

		$email_from_ipn = null;
		$email_decoded  = null;

		if ( ! $user ) {
			$email_from_ipn = $this->get_nested_param( $request, 'ExtReadInvoiceHead', 'Email' );

			// תיקון אומת מתוך לוג IPN אמיתי: האימייל מגיע עדיין מקודד-URL
			// (%2b במקום +, %40 במקום @) - כלומר לא עבר את הפענוח הרגיל
			// שפרסור POST/GET סטנדרטי כבר אמור לעשות לבד. urldecode()
			// מפורש כאן על ערך שכבר *לא* מקודד הוא no-op בטוח (אין %XX
			// לפענח) - אז זה לא מסוכן גם אם ההנחה הזו שגויה במקרה אחר.
			$email_decoded = $email_from_ipn ? urldecode( $email_from_ipn ) : null;

			error_log( '[DCard Webhook] ReturnValue missing/invalid - falling back to email lookup. raw email=' . wp_json_encode( $email_from_ipn ) . ', urldecode()-ed email=' . wp_json_encode( $email_decoded ) );

			if ( $email_decoded && is_email( sanitize_email( $email_decoded ) ) ) {
				$user = DCard_Users::get_by_email( sanitize_email( $email_decoded ) );
			}
		}

		if ( ! $user ) {
			// תיקון אבחון (דווח בפועל: קבוצת בקשות עם מיילים לא-קשורים שהגיעו
			// תוך שניות ספורות - לא ברור אם זה IPN אמיתי של מוצרים *אחרים*
			// של אותו חשבון קארדקום (אם ה-webhook מוגדר ברמת החשבון, לא רק
			// למוצר הזה) או משהו אחר). מוסיפים את ה-ProductID כאן, לפני
			// שבודקים user - כדי לדעת מיד אם זה בכלל שייך למוצר "הקורא
			// בקלפים" (מק"ט 601, ר' DCard_Plans) בלי לפנות לקארדקום לשאול.
			$diag_product_id = (string) $request->get_param( 'ExtReadInvoiceLines_ProductID' );
			if ( ! $diag_product_id ) {
				$diag_lines = $request->get_param( 'ExtReadInvoiceLines' );
				if ( is_array( $diag_lines ) && isset( $diag_lines[0]['ProductID'] ) ) {
					$diag_product_id = (string) $diag_lines[0]['ProductID'];
				}
			}
			DCard_Logger::warning( 'POST /webhook/ipn-update - no user found via ReturnValue or email fallback', array(
				'return_value'   => $return_value,
				'email_raw'      => $email_from_ipn,
				'email_decoded'  => $email_decoded,
				'product_id'     => $diag_product_id,
				'invoice_number' => (string) $request->get_param( 'ExtReadInvoiceHead_InvoiceNumber' ),
			) );
			return new WP_REST_Response( array( 'success' => false, 'error' => 'משתמש לא נמצא (ReturnValue ואימייל לא תואמים)' ), 404 );
		}

		// 4. זיהוי מסלול מהמק"ט - ExtReadInvoiceLines יכול להיות שדה שטוח
		// (שורת חשבונית בודדת) או מערך (כמה שורות) - נבדקות שתי החלופות.
		$product_id = (string) $request->get_param( 'ExtReadInvoiceLines_ProductID' );
		if ( ! $product_id ) {
			$lines = $request->get_param( 'ExtReadInvoiceLines' );
			if ( is_array( $lines ) && isset( $lines[0]['ProductID'] ) ) {
				$product_id = (string) $lines[0]['ProductID'];
			}
		}

		error_log( '[DCard Webhook] parsed ProductID=' . wp_json_encode( $product_id ) );

		$plan_id = DCard_Plans::map_product_id_to_plan( $product_id );
		if ( ! $plan_id ) {
			DCard_Logger::warning( 'POST /webhook/ipn-update - could not map ProductID to a known plan', array( 'product_id' => $product_id ) );
			return new WP_REST_Response( array( 'success' => false, 'error' => 'מק"ט לא מוכר' ), 400 );
		}

		// 5. פרטי העסקה לטבלת ה-transactions.
		$cardcom_transaction_id = (string) $request->get_param( 'ExtReadInvoiceHead_InvoiceNumber' );
		$amount                 = (float) $request->get_param( 'ExtReadInvoiceHead_TotalIncludeVAT' );
		$transaction_date_raw   = (string) $request->get_param( 'ExtReadInvoiceHead_InvoiceDate' );
		$phone                  = sanitize_text_field( (string) $request->get_param( 'ExtReadInvoiceHead_CustMobilePH' ) );

		if ( ! $cardcom_transaction_id ) {
			DCard_Logger::warning( 'POST /webhook/ipn-update - missing ExtReadInvoiceHead_InvoiceNumber (dedup key)', array() );
			return new WP_REST_Response( array( 'success' => false, 'error' => 'InvoiceNumber חסר' ), 400 );
		}

		// strtotime בעדינות: תאריך לא תקין/בפורמט לא מוכר -> null (לא
		// '1970-01-01' מטעה) - record_if_new() עצמה כבר יודעת להשתמש ב-
		// "עכשיו" כברירת מחדל כש-null מועבר לה.
		$transaction_date = null;
		if ( $transaction_date_raw ) {
			$ts = strtotime( $transaction_date_raw );
			$transaction_date = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
		}

		$transaction_result = DCard_Transactions::record_if_new(
			$user['id'], $cardcom_transaction_id, $plan_id, $amount, 'ILS', $transaction_date
		);

		// כפילות (אותה התראת webhook נשלחה פעמיים - קארדקום, כמו כל
		// webhook פיננסי, עשויה לשלוח retry) - לא כשל, אבל גם לא מפעילים
		// set_plan()/update_phone_if_empty() שוב על עסקה שכבר טופלה.
		if ( 'duplicate' === $transaction_result ) {
			return new WP_REST_Response( array( 'success' => true, 'duplicate' => true ), 200 );
		}

		if ( null === $transaction_result ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'שגיאה בשמירת העסקה' ), 500 );
		}

		// 6. עדכון בפועל: מסלול+קרדיטים (DCard_Users::set_plan - קורא ל-
		// DCard_Plans::credits_for_plan, לא קשיח), תפוגה (30/365 יום לפי
		// המק"ט - ר' DCard_Plans::plan_duration_days), וטלפון (רק אם ריק).
		$expires_at = gmdate( 'Y-m-d H:i:s', strtotime( '+' . DCard_Plans::plan_duration_days( $product_id ) . ' days' ) );
		DCard_Users::set_plan( $user['id'], $plan_id, $expires_at );
		DCard_Users::update_phone_if_empty( $user['id'], $phone );

		// תיקון: לפני כן לא היה שום לוג על הצלחה - רק על כשלים. בלי זה,
		// "האם ה-webhook בכלל התקבל והעודכן?" דרש בדיקת DB ישירה כל פעם.
		DCard_Logger::info( 'POST /webhook/ipn-update - plan updated successfully', array(
			'user_id'                => $user['id'],
			'plan_id'                => $plan_id,
			'cardcom_transaction_id' => $cardcom_transaction_id,
			'credits'                => DCard_Plans::credits_for_plan( $plan_id ),
			'expires_at'             => $expires_at,
		) );

		return new WP_REST_Response( array(
			'success'  => true,
			'user_id'  => $user['id'],
			'plan_id'  => $plan_id,
			'credits'  => DCard_Plans::credits_for_plan( $plan_id ),
			'expires_at' => $expires_at,
		), 200 );
	}

	/**
	 * קוראת גם מפתח "שטוח" עם קו תחתון (ExtReadInvoiceHead_ExternalId -
	 * כך PHP ממיר נקודות בשמות פרמטרים ב-form/query בפועל) וגם מבנה JSON
	 * מקונן (ExtReadInvoiceHead: { ExternalId: ... }, אם קארדקום שולחת
	 * JSON גולמי שלא עובר את ההמרה האוטומטית הזו) - לא ידוע מראש איזה
	 * משני הפורמטים קארדקום באמת שולחת, אז שניהם נבדקים.
	 */
	private function get_nested_param( WP_REST_Request $request, $group, $key ) {
		$flat = $request->get_param( $group . '_' . $key );
		if ( $flat ) {
			return $flat;
		}

		$nested = $request->get_param( $group );
		if ( is_array( $nested ) && isset( $nested[ $key ] ) ) {
			return $nested[ $key ];
		}

		return null;
	}

	// ---------------------------------------------------------------
	// GET /account/status
	// ---------------------------------------------------------------
	public function account_status( WP_REST_Request $request ) {
		return $this->safely( 'account_status', function () use ( $request ) {
			return $this->account_status_inner( $request );
		} );
	}

	private function account_status_inner( WP_REST_Request $request ) {
		$device_token = $request->get_header( 'X-Dcard-Device-Token' );
		$user          = $device_token ? DCard_Users::get_by_device_token( $device_token ) : null;

		if ( ! $user ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'משתמש לא נמצא' ), 404 );
		}

		return new WP_REST_Response( array(
			'success'        => true,
			'user_id'        => (int) $user['id'],
			'current_plan'   => $user['current_plan'],
			'current_credits'=> (int) $user['current_credits'],
			'plan_expires_at'=> $user['plan_expires_at'],
		), 200 );
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	/**
	 * Card image URLs sent from the frontend (img.src of the cards drawn in
	 * this session) for the summary email. Untrusted client input - only
	 * well-formed http(s) URLs survive, capped at 3 (MAX_CARDS).
	 */
	/**
	 * Defense against the model setting `end_session: true` while `speak`
	 * still contains a real question (seen live: it asked a genuine
	 * follow-up question and closed the conversation in the same turn -
	 * two fields of the same JSON object contradicting each other). A
	 * message that actually ends the reading, per every closing instruction
	 * in agent-brain-instructions.md (שלב ט/י, MESSAGE_MODE_INSTRUCTION,
	 * FINAL_TURN_INSTRUCTION), must not contain a question mark. If it does,
	 * this was not really a closing turn - the model just wrote `end_session`
	 * wrong - so the site keeps the conversation open rather than trusting it
	 * and locking the user out mid-dialogue.
	 */
	private function enforce_end_session_consistency( array $data ) {
		if ( ! empty( $data['end_session'] ) && false !== strpos( (string) $data['speak'], '?' ) ) {
			DCard_Logger::warning( 'Model set end_session=true but speak still contains a question mark - overriding to false', array(
				'speak' => mb_substr( (string) $data['speak'], 0, 300 ),
			) );
			$data['end_session'] = false;
		}
		return $data;
	}

	/**
	 * Defense against the model agreeing to "another card" (next_action
	 * offer_another_card/request_card_draw) when 3 are already drawn - the
	 * hard cap per agent-brain-instructions.md שלב ח (seen live: user asked
	 * for a 4th card, model wrote "לחצי על הכפתור לבחירת קלף" and offered
	 * one anyway). The frontend already refuses to show the deck past
	 * MAX_CARDS regardless (maybeOfferAnotherCard() in elementor-code.html),
	 * so nothing actually breaks functionally - but the model's own `speak`
	 * text then points the user at a button that will never appear, which
	 * reads as broken. Overrides both the text and next_action with a
	 * graceful, pre-written decline that redirects toward synthesizing what
	 * already came up, instead of trusting the model to have handled the
	 * cap correctly in its own wording.
	 */
	private function enforce_card_cap_consistency( array $data, $cards_drawn, $user_gender ) {
		$offering_another_card = in_array( $data['next_action'], array( 'offer_another_card', 'request_card_draw' ), true );

		if ( $offering_another_card && $cards_drawn >= 3 ) {
			DCard_Logger::warning( 'Model offered another card despite the 3-card cap already reached - overriding', array(
				'cards_drawn' => $cards_drawn,
				'next_action' => $data['next_action'],
			) );

			$data['speak'] = ( 'male' === $user_gender )
				? 'שלפת כבר שלושה קלפים לשאלה הזו, וזה המספר המרבי לקריאה אחת. בוא נסתכל יחד על מה שכבר עלה משלושתם ונראה איזה כיוון מצטייר.'
				: 'שלפת כבר שלושה קלפים לשאלה הזו, וזה המספר המרבי לקריאה אחת. בואי נסתכל יחד על מה שכבר עלה משלושתם ונראה איזה כיוון מצטייר.';
			$data['next_action'] = 'continue_reflection';
		}

		return $data;
	}

	/**
	 * ר' session_register_inner() - שם שדה גמיש כי תצורת ה-Webhook של
	 * אלמנטור לא ידועה מראש. מחזיר את הערך הראשון שבאמת לא ריק מבין
	 * השמות המועמדים, או מחרוזת ריקה אם אין אף אחד.
	 */
	private function first_nonempty_param( WP_REST_Request $request, array $param_names ) {
		foreach ( $param_names as $param_name ) {
			$value = $request->get_param( $param_name );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}
		return '';
	}

	private function sanitize_card_image_urls( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$urls = array();
		foreach ( array_slice( $raw, 0, 3 ) as $url ) {
			$clean = esc_url_raw( (string) $url );
			if ( $clean && wp_http_validate_url( $clean ) ) {
				$urls[] = $clean;
			}
		}

		return $urls;
	}

	private function build_state_payload( array $session, array $overrides, array $drawn_cards ) {
		$state = $session['state'];

		$payload = array(
			'session_id'           => $session['session_token'],
			'address_gender'       => $session['user_gender'],
			'display_name'         => $session['user_name'],
			'stage'                => $state['stage'],
			'original_question'    => $state['original_question'],
			'refined_question'     => $state['refined_question'],
			'clarification_count'  => (int) $state['clarification_count'],
			'turn_count'           => (int) $state['turn_count'],
			'selected_card_ids'    => array_map( 'intval', $drawn_cards ),
			'current_card'         => null,
			'selected_cards'       => array(),
			'user_is_registered'   => false,
			'recent_messages'      => $this->recent_messages( $session['transcript'] ),
			'user_message'         => '',
		);

		return array_merge( $payload, $overrides );
	}

	/**
	 * The model gets no chat history, so on its own it can't tell what it
	 * already asked (it kept repeating "what comes up for you?" after the
	 * user had answered). The last few messages are enough for it to move on.
	 */
	private function recent_messages( array $transcript, $limit = 6 ) {
		$out = array();

		foreach ( $transcript as $entry ) {
			if ( empty( $entry['role'] ) || empty( $entry['text'] ) || ! in_array( $entry['role'], array( 'user', 'assistant' ), true ) ) {
				continue;
			}
			$out[] = array(
				'role' => $entry['role'],
				'text' => mb_substr( (string) $entry['text'], 0, 600 ),
			);
		}

		return array_slice( $out, -$limit );
	}

	private function persist_memory_update( $session_token, array $old_state, array $memory_update, $cards_drawn = 0 ) {
		$next_state = array_merge( $old_state, array_intersect_key( $memory_update, $old_state ) );

		// Once a card is on the table the stage can't go back to a pre-card
		// stage, even if the model's memory_update says so.
		if ( $cards_drawn > 0 ) {
			$next_state['stage'] = $this->stage_after_draw( $next_state['stage'], $cards_drawn );
		}

		DCard_DB::update_state( $session_token, $next_state );
	}

	private function stage_after_draw( $stage, $cards_drawn ) {
		$pre_card_stages = array( 'identify', 'clarify_question', 'ready_for_card' );

		if ( in_array( $stage, $pre_card_stages, true ) ) {
			return $cards_drawn > 1 ? 'integrate_cards' : 'reflect_card';
		}

		if ( 'reflect_card' === $stage && $cards_drawn > 1 ) {
			return 'integrate_cards';
		}

		return $stage;
	}
}
