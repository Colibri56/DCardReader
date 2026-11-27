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

	// סעיף 6.2: אותו תבנית בדיוק כמו DCARD_NAME_PATTERN בצד הלקוח
	// (elementor-code.html) - אותיות עברית/לועזית, רווח, מקף וגרש ('/׳/’).
	const NAME_PATTERN = '/^[A-Za-z\x{05D0}-\x{05EA}\s\-\x{2010}-\x{2015}\x{05BE}\'\x{05F3}\x{2019}]+$/u';

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
	// תיקון (דווח בפועל: "משפט הסיום נעלם לחלוטין") - הניסוח הקודם קבר את
	// דרישת משפט הסיום באמצע הנחיה ארוכה, אחרי כל ההנחיות על "אל תשאל
	// שאלות" שקיבלו את כל הדגש (גם ב-CRITICAL RULE). מודל נוטה לציית יותר
	// להנחיה שמופיעה *אחרונה* ובבידוד משלה - אז עכשיו משפט הסיום מופיע
	// פעמיים: פעם בגוף ההנחיה (עברית), ופעם כמשפט נפרד, אחרון, מודגש
	// לבדו ב-CRITICAL RULE (אנגלית) - בדיוק כמו שכל שאר ה"חוק" הזה כבר
	// מודגש שם. גם הטקסט עצמו עודכן (תוכן חדש שהתבקש, לא רק מיקום).
	// תיקון (דווח בפועל, שוב: גם אחרי שהודעת ה-system אוחדה ב-call_openai -
	// ר' class-dcard-ai-gateway.php - GPT-4o-mini עדיין לפעמים שאל שאלה
	// וגם דילג על משפט הסיום, במלואו או בחלקו. לא בעיית קוד נוספת - פשוט
	// מודל לא מציית ב-100% מהמקרים, בדיוק כמו ש"חוק עצירת הלולאה" (ר'
	// CARD_TURN_CAP למעלה) כבר לא סומך רק על ציות המודל. הפתרון המקביל
	// כאן: המשפט הקבוע עכשיו קבוע נפרד (MESSAGE_MODE_CLOSING_LINE), ו-
	// session_card_selected_inner אוכף אותו בקוד אחרי קבלת התשובה (ר'
	// שם) - לא רק מבקש יפה מהמודל. עדיין נשאר גם בהוראה למודל (ניסיון
	// ראשון, חוסך את ה"תפירה" הגסה של הוספה בקוד ברוב המקרים שבהם המודל
	// כן מצליח לבד), אבל האכיפה בקוד היא הערובה האמיתית.)
	const MESSAGE_MODE_CLOSING_LINE = 'אני מזמין אותך להמשיך ולהעמיק בלחיצה על "להמשיך ולהעמיק בשיחה" או לשאלה נוספת בכפתור "שאלה חדשה"';

	const MESSAGE_MODE_INSTRUCTION = 'הנחיה זו גוברת על תהליך השיחה הרגיל שבסעיף 5, לקריאה הזו בלבד: המשתמש בחר "קבלת מסר אישי" - לא שיחה. אצלו תיבת הכתיבה מוסתרת לגמרי ואין לו שום דרך להגיב, גם לא באיטרציה הבאה. אל תפעל לפי שלב ה׳ (אל תפתח בשאלה "מה עולה בך") ואל תשאל שום שאלה - לא בהתחלה, לא באמצע ולא בסוף. אסור שיופיע בתשובה סימן שאלה (?) ולו אחד. אסור לכתוב ניסוחים כמו "ספר לי עוד", "מה אתה מרגיש לגבי זה", "רוצה להרחיב", "מה דעתך". כתוב מסר אחד שלם וסגור: קריאה קצרה, מעצימה ומעוררת השראה של הקלף ביחס לשאלה שלו, עם תובנה או כיוון ברור - משפט סיכום שעומד בפני עצמו, לא פתיחה לדיאלוג. בסוף המסר, בשורה נפרדת (שורה ריקה לפניה), חובה להוסיף בדיוק את המשפט הבא, בלי לשנות, לקצר או להתאים למגדר: "' . self::MESSAGE_MODE_CLOSING_LINE . '"'
		. ' CRITICAL RULE: You are providing a single, final, closed message. You are STRICTLY FORBIDDEN from asking any questions. DO NOT use question marks (?). DO NOT ask the user how they feel or what they see. Provide a complete, insightful, and conclusive answer to the user\'s question based on the drawn card, and then stop.'
		. ' MANDATORY CLOSING LINE: the very last thing in your response, on its own line with a blank line before it, must be this exact Hebrew sentence, unchanged and not translated: "' . self::MESSAGE_MODE_CLOSING_LINE . '" Do not omit this line under any circumstances.'
		. ' MINIMUM LENGTH: the message itself (before the mandatory closing line) must be at least 3 full sentences - a single short sentence is not a reading.';

	// תיקון (דווח בפועל: "בתמונה הראשונה יש משפט אחד של מסר כשתמיד היו מספר
	// משפטים. זה לא מסר." / "מה עולה בך כשאתה מסתכל על הקלף?" חזר כפי שהוא)
	// - strip_question_sentences() למטה מוריד משפטי-שאלה, אבל אם המודל כתב
	// בעיקר שאלה (או רק שאלה), אין טקסט מהותי שנשאר להרכיב ממנו - תיקון
	// טקסטואלי אחרי העובדה לא יכול להמציא תוכן. אז: ניסיון שני עם הנחיה
	// מחוזקת לפני שנופלים לטקסט גנרי קבוע.
	const MESSAGE_MODE_RETRY_EMPHASIS = 'הניסיון הקודם שלך לא היה תקין - הוא היה קצר מדי (פחות משלושה משפטים) ו/או הכיל סימן שאלה. כתוב עכשיו ניסיון חדש: מסר שלם בן 3-4 משפטים לפחות, עשיר ומעמיק, ללא שום סימן שאלה בשום מקום בטקסט.';

	const MESSAGE_MODE_FALLBACK_TEXT = 'הקלף שעלה מביא איתו תחושה של כיוון שמתחיל להתבהר, גם אם עוד לא במילים מדויקות. יש בו הזמנה לעצור לרגע, להקשיב לתחושה הראשונה שעלתה למולו, ולתת לה להוביל הלאה ביחס לשאלה שנשאלה.';

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

	// תיקון (דווח בפועל: "חותך את השיחה בצורה לא טבעית... גם לא הייתה
	// אינדיקציה מצד המשתמש לסיום") - שלב ו׳ בהוראות הסוכן קובע תקרה של 2-4
	// תורות עיבוד לכל קלף (ר' CARD_TURN_CAP למעלה), אבל עד כה לא היה שום
	// *רצפה* מקבילה - שלב ט׳ מאפשר למודל לזהות "סימן הבנה" (ואפילו תשובה
	// קצרה/מהוססת יכולה להיחשב ככזה) ולסגור כבר בתגובת ההעמקה הראשונה סביב
	// הקלף (turn 1), בלי שום בדיקה מול המשתמש ובלי שום אינדיקציה אמיתית
	// ממנו שהוא מוכן לסיים - בדיוק המקרה שדווח. אותו מנגנון בדיוק כמו
	// CARD_TURN_CAP למעלה, רק בכיוון ההפוך: נאכף בשרת (לא רק מבוקש מהמודל),
	// כי אי אפשר לסמוך על המודל "לא לסגור מוקדם מדי" יותר מאשר אפשר לסמוך
	// עליו "לעצור בזמן" - שתי הבעיות דורשות את אותו סוג רשת ביטחון.
	const CARD_TURN_FLOOR = 2;
	const CARD_TURN_FLOOR_INSTRUCTION = 'הנחיה זו גוברת על תהליך השיחה הרגיל שבסעיף 5, לתשובה הזו בלבד: זו תגובת ההעמקה הראשונה שלך סביב הקלף הנוכחי. אסור לעבור לשלב ט׳ (זיהוי תשובה וסיכום) או לתת מסר סיום/סיכום בתשובה הזו, גם אם נדמה לך שיש כבר סימן הבנה ברור מהמשתמש - "חוק עצירת הלולאה" בשלב ו׳ קובע מינימום של 2 תורות עיבוד סביב כל קלף לפני שמותר לעבור לסיכום, בלי קשר לסימני הבנה. המשך בשלב ו׳ הרגיל: גשר משמעות וחיזוק, הארה וזווית חדשה, ושאלת המשך פתוחה אחת. אסור ש-end_session יהיה true בתשובה הזו.';

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

		// Design-spec §7.2 (חובה: הקלף נבחר בשרת) + דווח בפועל ("ההתהפכות
		// מחכה לתשובת ה-AI, שיכולה לקחת כמה שניות") - פוצל משני ל-/session/
		// card-selected: הבקשה הזו רק בוחרת את הקלף (random_int, בלי AI)
		// ומחזירה תמונה, כדי שהאנימציה (ההתהפכות) לא תחכה לקריאת ה-AI. ר'
		// session_draw_card_inner למטה.
		register_rest_route( self::NAMESPACE_, '/session/draw-card', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'session_draw_card' ),
			'permission_callback' => array( $this, 'verify_nonce_if_present' ),
			'args'                => array(
				'session_token' => array( 'required' => true ),
				// 'chat' (default) או 'message' - קובע את מאגר הקרדיט שנבדק
				// (msg/chat), ר' ההערה המלאה ב-session_draw_card_inner.
				'mode'          => array( 'required' => false ),
				// אותו דגם כמו קודם: לא "בוחר" קלף מטעם הלקוח, רק ids
				// שהלקוח כבר יודע שהם "תפוסים" (היסטוריה אחרונה מאותו יום,
				// client-side בלבד - ר' recentCardExclusionSet ב-elementor-code.html).
				// הקלפים של הסשן הנוכחי נחסמים בשרת בלי קשר לרשימה הזו.
				'exclude_ids'   => array( 'required' => false ),
			),
		) );

		register_rest_route( self::NAMESPACE_, '/session/card-selected', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'session_card_selected' ),
			'permission_callback' => array( $this, 'verify_nonce_if_present' ),
			'args'                => array(
				'session_token' => array( 'required' => true ),
				// הקלף כבר נבחר ונקבע (session_draw_card_inner) - כאן רק
				// מריצים עליו את ה-AI. $card_id חייב להיות הקלף האחרון
				// שנקבע בפועל בסשן הזה (נבדק ב-session_card_selected_inner),
				// אחרת 409 - אין כאן שום בחירה חדשה מטעם הלקוח.
				'card_id'       => array( 'required' => true ),
				// Optional: the user's original question, sent on the FIRST
				// card of a session so this single call can ground the AI's
				// reflection in it. See the comment on session_card_selected_inner().
				'question'      => array( 'required' => false ),
				// 'chat' (default) or 'message' - see MESSAGE_MODE_INSTRUCTION below.
				'mode'          => array( 'required' => false ),
				// §7.2ב: מזהה קבוע לכל "ניסיון תגובה" אחד - אותו מזהה בדיוק
				// נשלח גם ב"ניסיון חוזר" אחרי timeout/ניתוק בצד הלקוח, כדי
				// שהשרת יזהה שזו אותה בקשה ולא יחייב קרדיט/יקרא ל-AI פעמיים
				// (ר' session_card_selected_inner).
				'request_id'    => array( 'required' => false ),
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

		// סעיף 6.10.4: "כתבו לנו" - קריאה מהפרונטאנד שלנו עצמו (לא webhook
		// חוץ), אז verify_nonce_if_present הרגיל מתאים כאן (בניגוד ל-/register/
		// /webhook/ipn-update למעלה).
		register_rest_route( self::NAMESPACE_, '/contact', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'contact_message' ),
			'permission_callback' => array( $this, 'verify_nonce_if_present' ),
			'args'                => array(
				'email'          => array( 'required' => true ),
				'message'        => array( 'required' => true ),
				'gender'         => array( 'required' => false ),
				'platform'       => array( 'required' => false ),
				// §6.10.2: "– תיקון מייל" מהקישור בחלון ההצטרפות.
				'subject_suffix' => array( 'required' => false ),
			),
		) );

		// §6.10.2: מסלולי התשלום הפעילים, לבניית כרטיסי המידע בחלון
		// ההצטרפות (kb-payment-modal) - ציבורי (לא PII, רק מחיר/תכולה),
		// בלי nonce נדרש. GET, לא משנה כלום בשרת.
		register_rest_route( self::NAMESPACE_, '/plans', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'plans' ),
			'permission_callback' => '__return_true',
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

		// §6.11 ("האזור שלי", Phase B) - גרסה מורחבת של /account/status:
		// שם/מייל/לשון פנייה/טלפון, תווית ומחיר המסלול, next_credit_reset_at
		// והיסטוריית תשלומים. /account/status עצמה נשארת כמו שהיא (נקראת
		// בכל טעינת עמוד, ר' ההערה עליה) - לא מעמיסים עליה שדות שרק מסך
		// "האזור שלי" צריך.
		register_rest_route( self::NAMESPACE_, '/account/details', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'account_details' ),
			'permission_callback' => array( $this, 'verify_nonce_if_present' ),
		) );

		// §6.11 ("הפרטים שלי", Phase B) - עדכון שם/לשון פנייה/טלפון ע"י
		// המשתמש עצמו. מייל נשאר לקריאה בלבד בשלב הזה (ר' ההערה בעיצוב).
		register_rest_route( self::NAMESPACE_, '/account/update-details', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'account_update_details' ),
			'permission_callback' => array( $this, 'verify_nonce_if_present' ),
			'args'                => array(
				'name'   => array( 'required' => false ),
				'gender' => array( 'required' => false ),
				'phone'  => array( 'required' => false ),
			),
		) );

		// §6.11 "ביטול המנוי" - מצב ביניים (אין עדיין פרטי ה-API של הוראות
		// הקבע בקארדקום, ר' ההערה המלאה ב-account_cancel_inner): שומר
		// cancel_requested_at+reason ושולח מייל למנהל לביטול ידני. לא
		// נוגע ב-current_plan/plan_expires_at - המעבר בפועל ל-CANCELLED
		// עדיין קורה רק דרך run_renewal_and_expiry_check בתפוגה.
		register_rest_route( self::NAMESPACE_, '/account/cancel', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'account_cancel' ),
			'permission_callback' => array( $this, 'verify_nonce_if_present' ),
			'args'                => array(
				'reason' => array( 'required' => false ),
			),
		) );

		// §6.10.4 "תיקון כתובת המייל" (21.11) - גרסה ייעודית ל"אם הכתובת
		// שגויה" בחלון ההצטרפות. ר' account_update_email_inner לשלוש
		// ההתנהגויות (עדכון עצמי מיידי / פנייה למנהל / כבר תפוס).
		register_rest_route( self::NAMESPACE_, '/account/update-email', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'account_update_email' ),
			'permission_callback' => array( $this, 'verify_nonce_if_present' ),
			'args'                => array(
				'new_email' => array( 'required' => true ),
			),
		) );

		// §7.2ג (21.11) - "לא התעדכן אחרי 30 שניות... ומייל מיידי למנהל".
		// נקרא מהקליינט מ-kbNotifyAdminPaymentDelay, פעם אחת לכל המתנה.
		register_rest_route( self::NAMESPACE_, '/account/payment-delay-alert', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'account_payment_delay_alert' ),
			'permission_callback' => array( $this, 'verify_nonce_if_present' ),
		) );

		// §(21.11): נקבע ממש לפני ניווט לקארדקום (ר' kbPaymentSubmit) - "שיחה
		// אחת חינם" בזמן שהתשלום בטיפול (has_credits_or_pending_pass) ו"אסור
		// לפתוח שוב את חלון התשלום" (kbPaymentPendingActive בפרונט, נגזר
		// מ-payment_pending בתגובת account/status|account/details).
		register_rest_route( self::NAMESPACE_, '/account/payment-pending-start', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'account_payment_pending_start' ),
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
				// תיקון (התבקש: לתעד גם "נטישה שקטה" - משתמש שסגר טאב/ניווט
				// הלאה בלי ללחוץ על אף כפתור סיום) - 'explicit' (ברירת מחדל)
				// מהכפתורים הרגילים, 'abandoned' מה-pagehide listener
				// (sendBeacon) ב-elementor-code.html. לא מבדיל *למה* -
				// רק *איך* הסשן נסגר, זה כל מה שאפשר לדעת בלי לקרוא מחשבות.
				'exit_type'     => array( 'required' => false ),
			),
		) );

		// סעיף 6.7: שליחת העתק השיחה במייל בלי לסיים את הסשן - בניגוד
		// ל-/session/end, שתמיד מסמן את הסשן כ-completed. המודל החדש
		// ("חזרה לשיחה" אחרי הצלחה) מחייב שהסשן נשאר active אחרי השליחה.
		register_rest_route( self::NAMESPACE_, '/session/email-transcript', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'session_email_transcript' ),
			'permission_callback' => array( $this, 'verify_nonce_if_present' ),
			'args'                => array(
				'session_token' => array( 'required' => true ),
				'email'         => array( 'required' => true ),
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

		// תיקון (התבקש: "האם כדאי להוסיף שדה שמציג את פלטפורמת השימוש -
		// מובייל/דסקטופ") - נקלט פעם אחת כאן (X-Dcard-Platform, ר'
		// dcardHeaders() ב-elementor-code.html) ונשמר ב-state של הסשן, כדי
		// שכל אירועי ה-Analytics של הסשן הזה (card_drawn/ai_error/
		// session_completed) יוכלו לקרוא אותו משם בלי לשלוח אותו מחדש בכל
		// קריאה - כולל קריאות sendBeacon (/session/end על נטישה שקטה)
		// שלא תומכות בכותרות מותאמות אישית בכלל.
		$platform = sanitize_text_field( (string) $request->get_header( 'X-Dcard-Platform' ) );
		// תיקון (התבקש: "להוסיף לדשבורד כמה משתמשים בדסקטופ, טאבלט וכמה
		// במובייל") - 'Tablet' נוסף כערך שלישי אפשרי (ר' DCARD_PLATFORM
		// ב-elementor-code.html, שעכשיו גם מזהה טאבלטים בנפרד, לא רק
		// Mobile/Desktop כמו קודם).
		if ( ! in_array( $platform, array( 'Mobile', 'Desktop', 'Tablet' ), true ) ) {
			$platform = '';
		}
		DCard_DB::update_state( $token, array(
			'platform' => $platform,
			// נשמר כאן (לא רק נגזר מה-header בכל בקשה) כדי ש-
			// report_abandoned_sessions() יוכל לזהות משתמש רשום גם כש-
			// ה-cron סוגר סשן נטוש בלי בקשת HTTP חיה - ר' ההערה המלאה
			// ב-default_state() ב-class-dcard-db.php.
			'user_id'  => $claimed_user_id > 0 ? $claimed_user_id : null,
		) );

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
	// POST /session/draw-card
	// ---------------------------------------------------------------
	public function session_draw_card( WP_REST_Request $request ) {
		return $this->safely( 'session_draw_card', function () use ( $request ) {
			return $this->session_draw_card_inner( $request );
		} );
	}

	/**
	 * דווח בפועל: "הקלף עומד קפוא זמן רב לפני שהוא מתהפך" - כי /session/
	 * card-selected משלב בבקשה אחת גם את בחירת הקלף וגם קריאת AI מלאה
	 * (לפעמים כמה שניות, בעיקר במסלול 'message'). ה-route הזה רק בוחר קלף
	 * (random_int, בלי שום קריאת AI) ומחזיר תמונה - זו הבקשה שמפעילה את
	 * ההתהפכות באנימציה (ר' kbFetchCard ב-elementor-code.html), בלי לחכות
	 * לתשובת ה-AI בכלל. /session/card-selected (למטה) ממשיך לרוץ בנפרד,
	 * ורק מעדכן את הצ'אט כשהוא מוכן.
	 *
	 * הקלף *מחויב/נרשם* (add_drawn_card) כבר כאן, לא מחכה להצלחת ה-AI -
	 * בדיוק כמו הקוד המקורי (מלפני סעיף 7), ששם בחר קלף לגמרי בצד הלקוח
	 * ורשם אותו מיד, לפני שבכלל פנה ל-AI. אם קריאת ה-AI בהמשך (card-selected)
	 * נכשלת, המשתמש מקבל הודעת שגיאה בצ'אט, אבל הקלף שכבר מוצג על המסך
	 * לא "מתחלף" - התנהגות זהה למה שהיה לפני סעיף 7.
	 */
	private function session_draw_card_inner( WP_REST_Request $request ) {
		$ip = DCard_Rate_Limiter::client_ip();
		if ( ! DCard_Rate_Limiter::check_card_selected( $ip ) ) {
			return new WP_REST_Response( array( 'error' => 'יותר מדי בקשות. נסו שוב בעוד קצת.' ), 429 );
		}

		$session_token      = sanitize_text_field( (string) $request->get_param( 'session_token' ) );
		$mode               = sanitize_text_field( (string) $request->get_param( 'mode' ) );
		$mode               = in_array( $mode, array( 'chat', 'message' ), true ) ? $mode : 'chat';
		$raw_exclude_ids    = $request->get_param( 'exclude_ids' );
		$raw_exclude_ids    = is_array( $raw_exclude_ids ) ? $raw_exclude_ids : array();
		$client_exclude_ids = array_map( 'absint', $raw_exclude_ids );

		$session = DCard_DB::get_session( $session_token );
		if ( ! $session || 'active' !== $session['status'] ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'סשן לא נמצא או שאינו פעיל' ), 404 );
		}

		if ( count( $session['drawn_cards'] ) >= 3 ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'הגעת למקסימום 3 קלפים לשאלה' ), 409 );
		}

		// אותה אכיפת קרדיטים/מגבלה יומית שהייתה קודם ב-session_card_selected_inner,
		// רק מוקדמת יותר עכשיו - כאן, *לפני* שמציגים קלף בכלל, לא אחרי
		// שהאנימציה כבר רצה. $is_first_card_draw נבדק *לפני* add_drawn_card
		// למטה, אז עדיין משקף נכון "האם זה הקלף הראשון בסשן".
		$device_token       = $request->get_header( 'X-Dcard-Device-Token' );
		$registered_user    = $device_token ? DCard_Users::get_by_device_token( $device_token ) : null;
		$is_first_card_draw = empty( $session['drawn_cards'] );
		$credit_type        = ( 'message' === $mode ) ? 'msg' : 'chat';

		// §(21.11): has_credits_or_pending_pass, לא has_credits הרגילה -
		// השער החוסם היחיד בפועל בכל הפלאגין (שני has_credits() האחרים,
		// ב-session_card_selected/session_turn, מידע בלבד - "לא עוצרים
		// שיחה באמצע"), ולכן המקום היחיד שבו "שיחה אחת חינם" בזמן תשלום
		// ממתין אמורה לחול. ר' ההערה המלאה על DCard_Users::
		// has_credits_or_pending_pass.
		if ( $registered_user && $is_first_card_draw && ! DCard_Users::has_credits_or_pending_pass( $registered_user['id'], $credit_type ) ) {
			DCard_Logger::info( 'session_draw_card - registered user out of credits, rejecting with 403', array( 'user_id' => $registered_user['id'], 'credit_type' => $credit_type ) );
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => 'נגמרו הקרדיטים שלך במסלול הנוכחי. אפשר לשדרג כדי להמשיך.',
				'code'    => 'credits_exhausted',
			), 403 );
		}

		if ( $registered_user && DCard_Usage_Logs::count_calls_today( $registered_user['id'] ) >= DCard_Quotas::get_daily_ai_call_limit() ) {
			DCard_Logger::info( 'session_draw_card - registered user hit daily AI call cap', array( 'user_id' => $registered_user['id'] ) );
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => 'הגעת להיום למספר הפניות המרבי לקורא בקלפים. נשמח לפגוש אותך שוב מחר.',
				'code'    => 'daily_limit_reached',
			), 403 );
		}

		// Testing mode: the first card of a session is always the forced one -
		// decided server-side, never by the client.
		$forced_card_id = DCard_Settings::get_forced_card_id();
		if ( $forced_card_id && empty( $session['drawn_cards'] ) && DCard_Cards::exists( $forced_card_id ) ) {
			$card_id = $forced_card_id;
		} else {
			// Design-spec §7.2 (חובה): הבחירה עצמה - מי מ-72 הקלפים יוצא -
			// נקבעת כאן, עם random_int(), אף פעם לא אצל הלקוח.
			$excluded = array_unique( array_merge( $session['drawn_cards'], $client_exclude_ids ) );
			$card_id  = $this->pick_random_card_id( $excluded );
		}

		DCard_DB::add_drawn_card( $session_token, $card_id );

		return new WP_REST_Response( array(
			'success'  => true,
			'cardId'   => $card_id,
			'imageUrl' => $this->card_image_url( $card_id ),
			'cardAlt'  => 'קלף מספר ' . $card_id,
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
		$request_id    = sanitize_text_field( (string) $request->get_param( 'request_id' ) );

		$session = DCard_DB::get_session( $session_token );
		if ( ! $session || 'active' !== $session['status'] ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'סשן לא נמצא או שאינו פעיל' ), 404 );
		}

		// §7.2ב: "ניסיון חוזר לא מחייב שוב" - אם זו בדיוק אותה בקשה שכבר
		// טופלה (request_id זהה לזה שנשמר אחרי ריצה מוצלחת קודמת על אותו
		// קלף, למשל הדפדפן ויתר על התשובה הראשונה ב-timeout אבל השרת כבר
		// סיים אותה וכבר חייב קרדיט) - מחזירים את אותה תגובה בדיוק, בלי
		// עוד קריאת AI ובלי חיוב קרדיט נוסף.
		if ( $request_id && isset( $session['state']['last_card_selected_request_id'], $session['state']['last_card_selected_response'] )
			&& $session['state']['last_card_selected_request_id'] === $request_id ) {
			DCard_Logger::info( 'session_card_selected - duplicate request_id, returning cached response (no AI call, no credit charge)', array(
				'session_token' => $session_token,
				'request_id'    => $request_id,
			) );
			return new WP_REST_Response( $session['state']['last_card_selected_response'], 200 );
		}

		// הקלף כבר נבחר ונרשם ב-/session/draw-card (session_draw_card_inner) -
		// כאן רק מריצים עליו AI. חייב להיות בדיוק הקלף *האחרון* שנרשם בסשן
		// הזה - לא שום card_id אחר שהלקוח יכול לשלוח - כדי ש"השרת בוחר את
		// הקלף" (סעיף 7.2) יישאר נכון גם אחרי הפיצול לשתי בקשות.
		if ( empty( $session['drawn_cards'] ) || end( $session['drawn_cards'] ) !== $card_id ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'הקלף הזה לא נבחר בסשן הזה' ), 409 );
		}

		$device_token    = $request->get_header( 'X-Dcard-Device-Token' );
		$registered_user = $device_token ? DCard_Users::get_by_device_token( $device_token ) : null;
		// drawn_cards כבר כולל את הקלף הנוכחי (נרשם ב-draw-card) - "הקלף
		// הראשון בסשן" הוא כאן מערך באורך 1, לא ריק (בניגוד למה שזה היה
		// לפני הפיצול - ר' ההערה המלאה ב-session_draw_card_inner).
		$is_first_card_draw = ( 1 === count( $session['drawn_cards'] ) );
		// תיקון (פיצול קרדיטים מסר/שיחה מפתחת, ר' ההערה המלאה ב-DCard_Plans) -
		// $mode כאן הוא המצב שהסשן *נפתח* בו (קבלת מסר/שיחה פתוחה); זה
		// המאגר שמקוזז על הקלף הראשון (הבדיקה עצמה כבר קרתה ב-draw-card).
		// אם הסשן "יעמיק" לשיחה פתוחה בהמשך (ר' $new_state_fields['converted_
		// from_message'] למטה) - יקוזז אז קרדיט *נוסף* ממאגר ה-chat, בנוסף
		// למה שכבר קוזז כאן.
		$credit_type = ( 'message' === $mode ) ? 'msg' : 'chat';

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

		// $session['drawn_cards'] כבר כולל את הקלף הנוכחי (נרשם ב-draw-card,
		// ר' ההערה המלאה למעלה) - אין יותר merge של $card_id אליו כאן; אבל
		// לסיכומי "קלפים קודמים" (selected_cards למטה) צריך להוציא אותו שוב.
		$card               = DCard_Cards::get( $card_id );
		$candidate_ids      = $session['drawn_cards'];
		$previous_card_ids  = array_slice( $candidate_ids, 0, -1 );
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
		// $tokens_in/$tokens_out default to 0 here (not declared at all on the
		// 'chat' fast-path below, which never calls the AI) - set for real only
		// inside the 'message' branch, read again after persist_memory_update()
		// further down (ר' ההערה שם - אותה בעיה בדיוק עם reading_mode/card_turn_count).
		$tokens_in  = 0;
		$tokens_out = 0;
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
				'selected_cards' => DCard_Cards::summaries( $previous_card_ids ),
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
					'platform'      => self::session_platform( $session ),
				) );

				return new WP_REST_Response( array( 'success' => false, 'error' => $result['error'] ), 200 );
			}

			$data     = $this->enforce_end_session_consistency( $result['data'] );
			$provider = $result['provider'];
			$model    = $result['model'];
			// תיקון (התבקש: "מספר הטוקנים שנצרכו לסשן, קלט ופלט") - usage כבר
			// חוזר מ-run_turn() (ר' DCard_AI_Gateway) - מצטבר כאן לכל קריאות
			// ה-AI בפועל שקרו בתוך ה-turn הזה (כולל retry למטה, אם קרה - גם
			// הוא קריאה אמיתית שצרכה טוקנים, בלי קשר אם תוכנו בסוף נבחר).
			$tokens_in  = isset( $result['usage']['input'] ) ? (int) $result['usage']['input'] : 0;
			$tokens_out = isset( $result['usage']['output'] ) ? (int) $result['usage']['output'] : 0;

			// תיקון (ר' ההערה המלאה ליד MESSAGE_MODE_CLOSING_LINE למעלה):
			// אכיפה בקוד, לא רק הוראה למודל. ה-else הזה (שורה 514) רץ רק
			// עבור mode === 'message' בפועל (chat תמיד fast-path, ר' למעלה) -
			// ה-if כאן בכל זאת מפורש, לא מסתמך על זה בשקט.
			//
			// תיקון נוסף (דווח בפועל, שוב): משפט הסיום עכשיו כן מופיע תמיד
			// (ר' למעלה), אבל המודל עדיין לפעמים שואל שאלה באמצע המסר -
			// ההוראה "אל תשאל שאלות" לא נאכפה עד כה, רק משפט הסיום. אותו
			// עיקרון: מורידים בקוד כל משפט שמכיל "?", לא רק מבקשים יפה.
			if ( 'message' === $mode && isset( $data['speak'] ) && is_string( $data['speak'] ) ) {
				$cleaned = self::strip_question_sentences( $data['speak'] );

				// תיקון (דווח בפועל: "בתמונה הראשונה יש משפט אחד של מסר כשתמיד
				// היו מספר משפטים" / גם מקרה שבו כל המסר היה שאלה בודדת) -
				// הורדת משפטי-שאלה לבדה לא מספיקה כשלא נשאר ממנה תוכן מהותי.
				// ניסיון שני, עם הנחיה מחוזקת, לפני שנופלים לטקסט גנרי קבוע.
				if ( ! self::is_valid_message_mode_speak( $cleaned ) ) {
					$retry_result = DCard_AI_Gateway::run_turn(
						$state_payload,
						self::MESSAGE_MODE_INSTRUCTION . ' ' . self::MESSAGE_MODE_RETRY_EMPHASIS,
						$reading_mode
					);
					$tokens_in  += isset( $retry_result['usage']['input'] ) ? (int) $retry_result['usage']['input'] : 0;
					$tokens_out += isset( $retry_result['usage']['output'] ) ? (int) $retry_result['usage']['output'] : 0;
					if ( $retry_result['ok'] ) {
						$retry_data = $this->enforce_end_session_consistency( $retry_result['data'] );
						if ( isset( $retry_data['speak'] ) && is_string( $retry_data['speak'] ) ) {
							$retry_cleaned = self::strip_question_sentences( $retry_data['speak'] );
							if ( self::is_valid_message_mode_speak( $retry_cleaned ) ) {
								$cleaned  = $retry_cleaned;
								$provider = $retry_result['provider'];
								$model    = $retry_result['model'];
							}
						}
					}
				}

				$data['speak'] = self::is_valid_message_mode_speak( $cleaned ) ? $cleaned : self::MESSAGE_MODE_FALLBACK_TEXT;

				if ( false === strpos( $data['speak'], self::MESSAGE_MODE_CLOSING_LINE ) ) {
					$data['speak'] = rtrim( $data['speak'] ) . "\n\n" . self::MESSAGE_MODE_CLOSING_LINE;
				}
			}
		}

		// Captured before the reset two lines down - the final tally for
		// whichever card was active up to this point (0 on the very first
		// draw of the session, since there is no "previous card" yet).
		$turns_on_previous_card = isset( $session['state']['card_turn_count'] ) ? (int) $session['state']['card_turn_count'] : 0;

		// הקלף כבר נרשם (add_drawn_card) ב-/session/draw-card - לא כאן יותר
		// (ר' ההערה המלאה בתחילת הפונקציה ובתחילת session_draw_card_inner).
		//
		// מורידים קרדיט רק אם זוהה משתמש רשום, ו*רק אם זה הקלף הראשון
		// בסשן* ($is_first_card_draw, ר' למעלה). קלף שני/שלישי באותו סשן
		// לא מוריד קרדיט נוסף - הסשן כולו (עד 3 קלפים + כל השיחה) הוא
		// יחידת החיוב, לא כל קלף בנפרד.
		if ( $registered_user && $is_first_card_draw ) {
			DCard_Users::deduct_credit( $registered_user['id'], $credit_type );
		}
		$this->persist_memory_update( $session_token, $session['state'], $data['memory_update'], count( $candidate_ids ) );
		// Recorded so /session/end knows which email intro text to use (DCard_Mailer).
		// MUST run after persist_memory_update(): that call writes a full state
		// snapshot built from $session['state'] (captured before this request),
		// which still has the OLD reading_mode/card_turn_count - writing this
		// first would just get silently overwritten back to the stale values by
		// that call. card_turn_count resets to 0 on every card draw (1st/2nd/3rd,
		// any mode) - see CARD_TURN_CAP above for what consumes it.
		$new_state_fields = array( 'reading_mode' => $mode, 'card_turn_count' => 0 );
		// תיקון (התבקש: "לדעת כמה שיחות עברו ממסר לשיחה מפתחת") - $session['state']
		// כאן עדיין ה-state *הישן* (מלפני הבקשה הזו, ר' ההערה למעלה) - בדיוק
		// הרגע הנכון לבדוק אם המצב הקודם היה 'message' ועכשיו עובר ל-'chat'.
		// נכתב רק כש-true (אף פעם לא דורס בחזרה ל-false) - ברגע שסשן "המיר",
		// זה נשאר true עד סוף הסשן גם אם ימשיך לצייר עוד קלפים ב-chat.
		if ( 'chat' === $mode && isset( $session['state']['reading_mode'] ) && 'message' === $session['state']['reading_mode'] ) {
			$new_state_fields['converted_from_message'] = true;
			// תיקון (דווח בפועל: "מספיק שהמשתמש ענה לשאלה של ה-AI, זה אומר
			// שהוא כבר נכנס לתוך השיחה... זה שהוא לא סגר את הסשן לפי הספר -
			// פחות חשוב לגבי המונה הזה") - עד כה ה-flag הזה נחשף רק בתוך
			// session_completed, כלומר רק אם/כש-הסשן נסגר אי-פעם. ההמרה
			// עצמה היא האירוע המשמעותי, לא תלויה בסגירה מסודרת - אירוע
			// נפרד *ברגע שזה קורה בפועל*, באותו דפוס כמו card_drawn/ai_usage.
			DCard_Analytics::track( 'session_converted', array(
				'session_token' => $session_token,
				'route'         => DCard_Analytics::route_label( 'chat' ),
				'platform'      => self::session_platform( $session ),
			) );
			// תיקון (התבקש: "אם זה מעבר ממסר לשיחה מפתחת - יקוזז 1 ממסר
			// ואחד משיחה מפתחת") - קרדיט ה-msg כבר קוזז על הקלף הראשון של
			// הסשן (שנפתח כ'message'); הסשן הזה "העמיק" לשיחה פתוחה, אז
			// מקוזז עליו עכשיו *בנוסף* קרדיט אחד ממאגר ה-chat - זה הרגע
			// שבו ההעמקה קורית בפועל (קלף נוסף שנשלף תחת mode='chat' אחרי
			// שהסשן התחיל כ-'message').
			//
			// עדכון §7.2 (19.11): "לא עוצרים שיחה באמצע" - ההעמקה ממשיכה
			// גם אם אין למשתמש קרדיט chat פנוי (deduct_credit לא חוסם,
			// רק GREATEST(...,0) - נשאר על 0, לא שלילי). נרשם ביומן כשזה
			// קורה, כדי שהמנהל יוכל לראות כמה פעמים זה קרה בפועל.
			if ( $registered_user ) {
				if ( ! DCard_Users::has_credits( $registered_user['id'], 'chat' ) ) {
					DCard_Logger::info( 'session_card_selected - converted message->chat without an available chat credit (allowed, not blocked)', array(
						'user_id'       => $registered_user['id'],
						'session_token' => $session_token,
					) );
				}
				DCard_Users::deduct_credit( $registered_user['id'], 'chat' );
			}
		}
		DCard_DB::update_state( $session_token, $new_state_fields );
		// תיקון (דווח בפועל: tokens_in/tokens_out נשארו 0 תמיד) - אותה בעיה
		// בדיוק כמו ההערה למעלה: היה קורא ל-add_token_usage *לפני*
		// persist_memory_update, שדורס אותו בחזרה לערך הישן כי הוא כותב
		// עותק מלא של $session['state'] הישן (מלפני הבקשה הזו) על גבי מה
		// שכבר נכתב ל-DB. חייב לרוץ אחרי, בדיוק כמו reading_mode/card_turn_count.
		$call_cost_usd = self::estimate_cost( $model, $tokens_in, $tokens_out );
		DCard_DB::add_token_usage( $session_token, $tokens_in, $tokens_out, $call_cost_usd );
		// תיקון (התבקש: מגבלת קריאות יומית + תקרת תקציב חודשית) - רישום
		// קבוע ל-wp_dcard_usage_logs, שעד כה היה מוכן אבל אף פעם לא נקרא
		// בפועל (ר' ההערה בראש class-dcard-usage-logs.php). $tokens_in/out
		// כאן כבר כוללים retry אם קרה (ר' ההערה המלאה למעלה) - שורה אחת
		// לכל קריאת API *חיצונית* (card-selected/turn), לא לכל retry בנפרד.
		if ( $tokens_in || $tokens_out ) {
			DCard_Usage_Logs::log( $registered_user ? $registered_user['id'] : null, $model, $tokens_in, $tokens_out, $call_cost_usd );
		}

		// תיקון (התבקש: "טוקנים אמיתיים לפי מודל LLM") - session_completed
		// מצטבר לכל הסשן, ולא מבדיל אם הוחלף מודל באמצע. אירוע נפרד לכל
		// קריאת AI *בפועל* (לא ל-fast-path של chat, ששווה 0 ממילא) - מודל
		// מדויק + הטוקנים שהוא בדיוק צרך, לא קירוב. משתמש מחדש בעמודות
		// הקיימות (LLM Model/tokens_in/tokens_out/Route) - אין צורך במיפוי
		// Make חדש, רק ב-"Detect new values" כדי לראות את ה-event החדש.
		if ( $tokens_in || $tokens_out ) {
			DCard_Analytics::track( 'ai_usage', array(
				'session_token' => $session_token,
				'route'         => DCard_Analytics::route_label( $mode ),
				'llm_model'     => $provider ? DCard_Analytics::ai_label( array( array( 'provider' => $provider, 'model' => $model ) ) ) : '',
				'tokens_in'     => $tokens_in,
				'tokens_out'    => $tokens_out,
				'cost_usd'      => $call_cost_usd,
				'platform'      => self::session_platform( $session ),
			) );
		}

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
		// תיקון (דווח בפועל: עמודת "LLM Model" ריקה תמיד בגיליון) - provider/
		// model כבר ידועים כאן בפועל במצב 'message' (null רק במצב 'chat',
		// שם זה תבנית קבועה בלי קריאת AI בכלל - ר' למעלה) - פשוט לא נשלחו.
		DCard_Analytics::track( 'card_drawn', array(
			'session_token'          => $session_token,
			'user_name'              => $session['user_name'],
			'user_email'             => null, // לא ידוע בשלב הזה - נאסף רק ב-/session/end
			'route'                  => DCard_Analytics::route_label( $mode ),
			'card_id'                => $card_id,
			'cards_drawn_so_far'     => count( $candidate_ids ),
			'turns_on_previous_card' => $turns_on_previous_card,
			'llm_model'              => $provider ? DCard_Analytics::ai_label( array( array( 'provider' => $provider, 'model' => $model ) ) ) : '',
			'platform'               => self::session_platform( $session ),
		) );

		// imageUrl/cardAlt כבר הגיעו ללקוח מ-/session/draw-card - אין צורך
		// לשלוח אותם שוב כאן.
		$response_payload = array(
			'success'     => true,
			'cardId'      => $card_id,
			'aiReply'     => $data['speak'],
			'nextAction'  => $data['next_action'],
			'endSession'  => (bool) $data['end_session'],
		);

		// §7.2ב: שומרים את התגובה הזו + ה-request_id שלה, כדי ש"ניסיון
		// חוזר" עם אותו request_id (ר' למעלה) יחזיר אותה בלי לחייב/לקרוא
		// ל-AI שוב. נשמר רק כש-request_id נשלח בכלל (לקוח ישן בלי התמיכה
		// הזו פשוט לא נהנה מההגנה, לא נשבר).
		if ( $request_id ) {
			DCard_DB::update_state( $session_token, array(
				'last_card_selected_request_id' => $request_id,
				'last_card_selected_response'   => $response_payload,
			) );
		}

		return new WP_REST_Response( $response_payload, 200 );
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

		// הוזז לכאן (היה קודם אחרי בדיקת ההמרה למטה) - תיקון (התבקש:
		// "מעבר ממסר לשיחה מפתחת מקזז קרדיט נוסף מהמאגר של שיחה מפתחת") -
		// $registered_user נדרש כבר בבדיקת ההמרה שממש אחרי זה, לא רק
		// למגבלת הקריאות היומית שהייתה כאן קודם.
		$device_token    = $request->get_header( 'X-Dcard-Device-Token' );
		$registered_user = $device_token ? DCard_Users::get_by_device_token( $device_token ) : null;

		// תיקון (דווח בפועל: "לספור את אלה שמתחילים בקבלת מסר ולוחצים
		// 'להמשיך ולהעמיק' - בפועל הם לא נספרים") - /session/turn קיים רק
		// בתוך שיחה אישית (ר' ההערה למטה), אבל עד כה הוא מעולם לא עדכן את
		// reading_mode בעצמו - רק שליפת קלף עושה זאת. משתמש שהעמיק ממסר
		// לשיחה ופשוט *כתב* ישר בתיבה (בלי לשלוף עוד קלף קודם) היה נשאר
		// עם reading_mode='message' ב-DB לנצח - לא רק ש-converted_from_message
		// לא נדלק, גם ה-Route הסופי ב-session_completed היה שגוי (מראה
		// "Message" לשיחה אמיתית). אם reading_mode עדיין 'message' ברגע
		// הזה, זו בדיוק נקודת ה-turn הראשונה שאחרי ההעמקה - מתקנים את שניהם.
		if ( 'message' === ( isset( $session['state']['reading_mode'] ) ? $session['state']['reading_mode'] : null ) ) {
			DCard_DB::update_state( $session_token, array( 'reading_mode' => 'chat', 'converted_from_message' => true ) );
			$session['state']['reading_mode'] = 'chat';
			// ר' ההערה המלאה ב-session_card_selected_inner - אותו עיקרון:
			// ההמרה עצמה היא האירוע, לא תלויה בסגירה מסודרת של הסשן אי-פעם.
			DCard_Analytics::track( 'session_converted', array(
				'session_token' => $session_token,
				'route'         => DCard_Analytics::route_label( 'chat' ),
				'platform'      => self::session_platform( $session ),
			) );
			// אותו תיקון בדיוק כמו ב-session_card_selected_inner - המרה
			// שקורית *בלי* שליפת קלף נוספת (המשתמש פשוט כתב הודעה חופשית
			// אחרי שלחץ "להמשיך ולהעמיק") עדיין מקזזת קרדיט chat נוסף.
			// עדכון §7.2 (19.11) - ר' ההערה המלאה ב-session_card_selected_inner:
			// ממשיכים גם בלי קרדיט chat פנוי, רק רושמים ביומן.
			if ( $registered_user ) {
				if ( ! DCard_Users::has_credits( $registered_user['id'], 'chat' ) ) {
					DCard_Logger::info( 'session_turn - converted message->chat without an available chat credit (allowed, not blocked)', array(
						'user_id'       => $registered_user['id'],
						'session_token' => $session_token,
					) );
				}
				DCard_Users::deduct_credit( $registered_user['id'], 'chat' );
			}
		}

		// תיקון יחידת מדידה: קרדיט אחד = סשן אחד (ר' ההערה המלאה ב-
		// session_card_selected_inner), לא הודעה בודדת. /session/turn הוא
		// *תוך* סשן שכבר התחיל (ושהקרדיט שלו כבר נבדק/נגבה על הקלף
		// הראשון) - הודעות שיחה פתוחה לא נבדקות ולא גובות קרדיט נוסף כאן
		// בכלל יותר. (הוסר - קודם היה כאן בדיקת has_credits/דחיית 403
		// זהה ל-session_card_selected_inner, שגרמה בפועל לחיוב כפול: קרדיט
		// אחד על הקלף הראשון ועוד "בדיקה" על כל הודעת מעקב באותו סשן.)
		//
		// תיקון (התבקש: מגבלת קריאות AI יומית) - ציר נפרד מהקרדיטים (ר'
		// ההערה המקבילה והמלאה ב-session_card_selected_inner): כל הודעת
		// המשך בשיחה פתוחה היא קריאת AI בפני עצמה, אז נבדקת כאן גם כן,
		// לא רק בשליפת הקלף. $registered_user נשלף כעת כבר למעלה (נדרש
		// לבדיקת ההמרה ממסר לשיחה), נשתמש בו כאן בלי לשלוף אותו שוב.
		if ( $registered_user && DCard_Usage_Logs::count_calls_today( $registered_user['id'] ) >= DCard_Quotas::get_daily_ai_call_limit() ) {
			DCard_Logger::info( 'session_turn - registered user hit daily AI call cap', array( 'user_id' => $registered_user['id'] ) );
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => 'הגעת להיום למספר הפניות המרבי לקורא בקלפים. נשמח לפגוש אותך שוב מחר.',
				'code'    => 'daily_limit_reached',
			), 403 );
		}

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
		} elseif ( $new_card_turn_count < self::CARD_TURN_FLOOR ) {
			$extra_instruction = self::CARD_TURN_FLOOR_INSTRUCTION;
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
				'platform'      => self::session_platform( $session ),
			) );

			// HTTP 200 on purpose - see the note in session_card_selected_inner().
			return new WP_REST_Response( array( 'success' => false, 'error' => $result['error'] ), 200 );
		}

		$data = $this->enforce_end_session_consistency( $result['data'] );
		$data = $this->enforce_card_cap_consistency( $data, count( $session['drawn_cards'] ), $session['user_gender'] );
		$data = $this->enforce_card_turn_floor( $data, $new_card_turn_count, $is_final_turn );

		// turn_count and card_turn_count are server-owned; everything else in
		// memory_update is trusted.
		$this->persist_memory_update( $session_token, $state, array_merge(
			$data['memory_update'],
			array( 'turn_count' => $new_turn_count, 'card_turn_count' => $new_card_turn_count )
		), count( $session['drawn_cards'] ) );
		// תיקון (דווח בפועל: tokens_in/tokens_out נשארו 0 תמיד) - חייב לרוץ
		// *אחרי* persist_memory_update, לא לפני - ר' ההערה המקבילה והמפורטת
		// ב-session_card_selected_inner. persist_memory_update כותב עותק מלא
		// של ה-state הישן (מלפני הבקשה), שהיה דורס בחזרה כל עדכון לטוקנים
		// שנכתב לפניו.
		$turn_tokens_in  = isset( $result['usage']['input'] ) ? (int) $result['usage']['input'] : 0;
		$turn_tokens_out = isset( $result['usage']['output'] ) ? (int) $result['usage']['output'] : 0;
		$turn_cost_usd   = self::estimate_cost( $result['model'], $turn_tokens_in, $turn_tokens_out );
		DCard_DB::add_token_usage( $session_token, $turn_tokens_in, $turn_tokens_out, $turn_cost_usd );
		// ר' ההערה המקבילה ב-session_card_selected_inner - אותו רישום ל-usage_logs.
		if ( $turn_tokens_in || $turn_tokens_out ) {
			DCard_Usage_Logs::log( $registered_user ? $registered_user['id'] : null, $result['model'], $turn_tokens_in, $turn_tokens_out, $turn_cost_usd );
		}

		// ר' ההערה המקבילה ב-session_card_selected_inner - /session/turn
		// הוא תמיד 'chat' (ר' ההערה למעלה), אז route קבוע כאן.
		if ( $turn_tokens_in || $turn_tokens_out ) {
			DCard_Analytics::track( 'ai_usage', array(
				'session_token' => $session_token,
				'route'         => DCard_Analytics::route_label( 'chat' ),
				'llm_model'     => DCard_Analytics::ai_label( array( array( 'provider' => $result['provider'], 'model' => $result['model'] ) ) ),
				'tokens_in'     => $turn_tokens_in,
				'tokens_out'    => $turn_tokens_out,
				'cost_usd'      => $turn_cost_usd,
				'platform'      => self::session_platform( $session ),
			) );
		}

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
		$exit_type     = sanitize_text_field( (string) $request->get_param( 'exit_type' ) );
		if ( ! in_array( $exit_type, array( 'explicit', 'abandoned' ), true ) ) {
			$exit_type = 'explicit';
		}

		$session = DCard_DB::get_session( $session_token );
		if ( ! $session ) {
			return new WP_REST_Response( array( 'error' => 'סשן לא נמצא' ), 404 );
		}

		// Google Sheets logging (ר' Google Apps Script בתשובה): user_id/
		// user_email מזוהים כאן גם דרך X-Dcard-Device-Token (לא רק מה-
		// email שהוזן ב-/session/end עצמו, שלרוב לא נשלח כלל אם המשתמש
		// לא בחר "שליחה במייל") - כך שמשתמש רשום מקבל שורה מלאה גם אם
		// לא לחץ על שליחת מייל באותו סשן ספציפי.
		$device_token = $request->get_header( 'X-Dcard-Device-Token' );
		$email_sent   = self::finalize_session( $session, $session_token, $exit_type, $email, $card_images, $device_token );

		// user_is_registered is not implemented yet in this beta (no accounts) - always the "new user" text.
		$closing_message = DCard_Settings::get_closing_text( false );

		return new WP_REST_Response( array(
			'emailSent'      => $email_sent,
			'closingMessage' => $closing_message,
		), 200 );
	}

	// ---------------------------------------------------------------
	// POST /session/email-transcript
	// ---------------------------------------------------------------
	public function session_email_transcript( WP_REST_Request $request ) {
		return $this->safely( 'session_email_transcript', function () use ( $request ) {
			return $this->session_email_transcript_inner( $request );
		} );
	}

	/**
	 * סעיף 6.7: שולחת את אותו מייל תמלול שגם /session/end שולח (DCard_Mailer::
	 * send_transcript, אותם פרמטרים), אבל בלי finalize_session() - הסשן
	 * נשאר 'active', כדי שאפשר יהיה לחזור לשיחה (כפתור "חזרה לשיחה") אחרי
	 * מצב ההצלחה בחלון. לא נרשם session_completed - רק אירוע ייעודי קל.
	 */
	private function session_email_transcript_inner( WP_REST_Request $request ) {
		$session_token = sanitize_text_field( (string) $request->get_param( 'session_token' ) );
		$email_raw     = (string) $request->get_param( 'email' );
		$email         = $email_raw ? sanitize_email( $email_raw ) : '';
		$card_images   = $this->sanitize_card_image_urls( $request->get_param( 'cardImages' ) );

		if ( ! $email || ! is_email( $email ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'הכתובת לא נראית תקינה' ), 400 );
		}

		$session = DCard_DB::get_session( $session_token );
		if ( ! $session || 'active' !== $session['status'] ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'סשן לא נמצא או שאינו פעיל' ), 404 );
		}

		$device_token    = $request->get_header( 'X-Dcard-Device-Token' );
		$registered_user = $device_token ? DCard_Users::get_by_device_token( $device_token ) : null;
		$is_message_mode = 'message' === ( isset( $session['state']['reading_mode'] ) ? $session['state']['reading_mode'] : null );
		$resolved_phone  = $registered_user ? $registered_user['phone'] : null;

		$email_sent = DCard_Mailer::send_transcript( $email, $session['user_name'], $session['transcript'], $card_images, $is_message_mode, $session_token, $resolved_phone );
		if ( $email_sent ) {
			DCard_DB::set_email_sent_to( $session_token, $email );
		} else {
			DCard_Logger::warning( 'wp_mail() returned false for mid-session email-transcript', array( 'session_token' => $session_token ) );
		}

		DCard_Analytics::track( 'email_requested_mid_session', array(
			'session_token' => $session_token,
			'user_name'     => $session['user_name'],
			'email_sent'    => $email_sent ? 'Yes' : '',
			'platform'      => self::session_platform( $session ),
		) );

		return new WP_REST_Response( array( 'success' => true, 'emailSent' => $email_sent ), 200 );
	}

	/**
	 * מרכז בקוד אחד את מה שהיה קודם מפוזר בתוך session_end_inner בלבד -
	 * כדי ש-report_abandoned_sessions() (ר' למטה) יוכל "לסגור" סשן נטוש
	 * בדיוק באותו אופן (status='completed' + אירוע session_completed),
	 * גם כש-ה-cron מפעיל אותו בלי שום בקשת HTTP חיה ברקע (אין $request,
	 * אין device-token header - לכן $device_token מגיע כפרמטר, לא נקרא
	 * כאן מתוך header; ר' $claimed_user_id שנשמר ב-state ב-session_start_inner
	 * לשימוש בדיוק במקרה הזה).
	 *
	 * מחזיר bool - האם נשלח בפועל מייל עם תמלול השיחה (email_sent).
	 */
	private static function finalize_session( array $session, $session_token, $exit_type, $email, array $card_images = array(), $device_token = null ) {
		$email_sent       = false;
		$is_message_mode  = 'message' === ( isset( $session['state']['reading_mode'] ) ? $session['state']['reading_mode'] : null );

		$registered_user = $device_token ? DCard_Users::get_by_device_token( $device_token ) : null;
		if ( ! $registered_user && ! empty( $session['state']['user_id'] ) ) {
			$registered_user = DCard_Users::get_by_id( (int) $session['state']['user_id'] );
		}
		$resolved_email  = ( $email && is_email( $email ) ) ? $email : ( $registered_user ? $registered_user['email'] : null );
		// תיקון (התבקש: "מספר טלפון - יאפשר לשלוח הודעות וואטסאפ בהמשך") -
		// כבר נאסף ונשמר היום (DCard_Users, טופס ההרשמה/Cardcom webhook) -
		// רק לא נחשף עד כה לתוך ה-Analytics. קיים רק למשתמשים *רשומים*
		// (יש device token שמזהה אותם) - משתמש אנונימי אין לו טלפון לשלוף.
		$resolved_phone = $registered_user ? $registered_user['phone'] : null;

		if ( $email && is_email( $email ) ) {
			$email_sent = DCard_Mailer::send_transcript( $email, $session['user_name'], $session['transcript'], $card_images, $is_message_mode, $session_token, $resolved_phone );
			if ( ! $email_sent ) {
				DCard_Logger::warning( 'wp_mail() returned false for session end email', array(
					'session_token' => $session_token,
				) );
			} else {
				DCard_DB::set_email_sent_to( $session_token, $email );
			}
		}

		DCard_DB::set_status( $session_token, 'completed' );

		// Beta-testing analytics (Settings > DCard AI > Analytics Webhook URL) -
		// fire-and-forget, no-op if no URL is configured.

		DCard_Analytics::track( 'session_completed', array(
			'session_token'      => $session_token,
			'user_id'            => $registered_user ? (int) $registered_user['id'] : null,
			'user_name'          => $session['user_name'],
			'user_email'         => $resolved_email,
			'user_phone'         => $resolved_phone,
			'route'              => DCard_Analytics::route_label( isset( $session['state']['reading_mode'] ) ? $session['state']['reading_mode'] : null ),
			// תיקון (נמצא תוך כדי בניית דוחות): השם כאן לא תאם את 'cards_drawn_so_far'
			// ש-card_drawn שולח (ר' למעלה) - עמודת TotalCards ב-Make ממופה
			// ספציפית ל-{{1.cards_drawn_so_far}}, אז שורות session_completed
			// פשוט לא מילאו את העמודה הזו בכלל עד עכשיו. אותו שם שדה כאן,
			// כדי ששני סוגי האירועים יזינו את אותה עמודה.
			'cards_drawn_so_far' => count( $session['drawn_cards'] ),
			'card_ids'           => implode( ', ', array_map( 'intval', $session['drawn_cards'] ) ),
			'turns_on_last_card' => isset( $session['state']['card_turn_count'] ) ? (int) $session['state']['card_turn_count'] : 0,
			'llm_model'          => DCard_Analytics::ai_label( $session['transcript'] ),
			'original_question'  => isset( $session['state']['original_question'] ) ? $session['state']['original_question'] : '',
			'chat_transcript'    => DCard_Analytics::format_transcript_text( $session['transcript'] ),
			'platform'           => self::session_platform( $session ),
			'exit_type'          => $exit_type,
			'tokens_in'          => isset( $session['state']['tokens_in'] ) ? (int) $session['state']['tokens_in'] : 0,
			'tokens_out'         => isset( $session['state']['tokens_out'] ) ? (int) $session['state']['tokens_out'] : 0,
			// תיקון (התבקש: "עלות הסשן") - סך כל עלויות ה-ai_usage של הסשן
			// הזה יחד (ר' DCard_DB::add_token_usage) - אותה עמודת Cost כמו
			// ב-ai_usage, רק מצטבר. 0 כאן (לא '') הוא ערך תקין ואמיתי -
			// מתאים לסשן שבו כל הקריאות היו במודלים בלי תעריף מוגדר.
			'cost_usd'           => isset( $session['state']['cost_usd'] ) ? (float) $session['state']['cost_usd'] : 0,
			// תיקון (התבקש: "כמה שיחות עברו ממסר לשיחה מפתחת") - ר' ההערה
			// המלאה ליד המקום שבו זה נקבע (session_card_selected_inner).
			'converted_from_message' => ! empty( $session['state']['converted_from_message'] ) ? 'Yes' : '',
			// תיקון (התבקש: "כמה מיילים התבקשו") - email (הפרמטר שהתקבל כאן
			// בפועל, לא user_email/resolved_email למעלה - זה יכול להגיע גם
			// ממשתמש רשום בלי שהוא ביקש כלום הפעם) מבדיל במפורש בין "המשתמש
			// לחץ 'שליחת הדיאלוג במייל' והזין כתובת" לבין "יש לנו אימייל
			// שלו מהרשמה קודמת". email_sent - האם wp_mail() גם הצליח בפועל.
			'email_requested'    => ( $email && is_email( $email ) ) ? 'Yes' : '',
			'email_sent'         => $email_sent ? 'Yes' : '',
		) );

		return $email_sent;
	}

	/**
	 * תיקון (דווח בפועל: "משתמש שמשאיר את האפליקציה פתוחה על תשובה שקיבל
	 * ועובר הלאה - זו נטישה, צריך למצוא לזה פתרון") - עד כה סשן 'active'
	 * שלא התעדכן שעתיים פשוט *נמחק* ב-DCard_DB::cleanup_old_sessions(),
	 * בלי שום דיווח - אם ה-pagehide/sendBeacon בצד הלקוח (ר' elementor-
	 * code.html) לא הספיק להישלח/להתקבל *לפני* שה-cron השעתי הגיע לאותו
	 * סשן (או בכלל לא נורה - קריסת דפדפן, נייד שנסגר באגרסיביות ברקע,
	 * אין רשת ברגע הסגירה), השורה נעלמת מה-DB לצמיתות בלי זכר בגיליון.
	 * נקרא מ-dcard_daily_cleanup *לפני* DCard_DB::cleanup_old_sessions(),
	 * כדי שכל סשן נטוש כזה "ייסגר" רשמית (status='completed' + אירוע
	 * session_completed עם exit_type='abandoned') כאן, בצד השרת, לפני
	 * שהוא נמחק - בלי תלות באמינות של pagehide בצד הלקוח בכלל.
	 */
	public static function report_abandoned_sessions() {
		if ( ! class_exists( 'DCard_DB' ) ) {
			return;
		}

		$stale_sessions = DCard_DB::get_stale_active_sessions( 2 );
		foreach ( $stale_sessions as $session ) {
			self::finalize_session( $session, $session['session_token'], 'abandoned', '' );
		}
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

		// סעיף 6.2: אותו כלל בדיוק כמו ה-DCARD_NAME_PATTERN בצד הלקוח -
		// אותיות (עברית/לועזית), רווח, מקף וגרש. שם ריק מותר (חלק מקריאות
		// ה-webhook הישנות של אלמנטור לא שולחות שם בכלל).
		if ( '' !== $name && ! preg_match( self::NAME_PATTERN, $name ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'השם יכול לכלול רק אותיות, רווחים, מקפים וגרש.' ), 400 );
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
			// סעיף 6.10.1: מוחזר כדי שה-JS (kb-register-modal) ישמור אותו
			// ב-localStorage - בדיוק כמו dcardEmail שה-hook של אלמנטור
			// (on_new_record) מחזיר היום לטופס ה-webhook הישן.
			'email'        => $email,
			'regMsgLimit'  => (int) get_option( DCard_Quotas::OPTION_REG_MSG_LIMIT, DCard_Quotas::DEFAULT_REG_MSG_LIMIT ),
			'regChatLimit' => (int) get_option( DCard_Quotas::OPTION_REG_CHAT_LIMIT, DCard_Quotas::DEFAULT_REG_CHAT_LIMIT ),
		), 200 );
	}

	// ---------------------------------------------------------------
	// POST /contact
	// ---------------------------------------------------------------
	public function contact_message( WP_REST_Request $request ) {
		return $this->safely( 'contact_message', function () use ( $request ) {
			return $this->contact_message_inner( $request );
		} );
	}

	/**
	 * סעיף 6.10.4: שולחת את תוכן חלון "כתבו לנו" (DCard_Mailer::
	 * send_contact_message) - עד 5 הודעות בשעה לפי IP (ר' DCard_Rate_Limiter).
	 * device token אופציונלי - גם מבקר אנונימי יכול לפתוח את החלון הזה.
	 */
	private function contact_message_inner( WP_REST_Request $request ) {
		$ip = DCard_Rate_Limiter::client_ip();
		if ( ! DCard_Rate_Limiter::check_contact( $ip ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'יותר מדי בקשות. נסו שוב בעוד קצת.' ), 429 );
		}

		$email_raw      = (string) $request->get_param( 'email' );
		$email          = $email_raw ? sanitize_email( $email_raw ) : '';
		$message        = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		$gender         = (string) $request->get_param( 'gender' );
		$platform       = sanitize_text_field( (string) $request->get_param( 'platform' ) );
		$subject_suffix = sanitize_text_field( (string) $request->get_param( 'subject_suffix' ) );

		if ( ! $email || ! is_email( $email ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'הכתובת לא נראית תקינה' ), 400 );
		}
		if ( '' === trim( $message ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'ההודעה לא יכולה להיות ריקה' ), 400 );
		}

		$device_token    = $request->get_header( 'X-Dcard-Device-Token' );
		$registered_user = $device_token ? DCard_Users::get_by_device_token( $device_token ) : null;
		$user_name       = $registered_user ? $registered_user['name'] : '';
		$plan_label      = $registered_user ? DCard_Plans::label_for_plan( $registered_user['current_plan'] ) : '';
		$user_id         = $registered_user ? $registered_user['id'] : null;

		$sent = DCard_Mailer::send_contact_message( $email, $user_name, $message, $gender, $plan_label, $user_id, $platform, $subject_suffix );
		if ( ! $sent ) {
			DCard_Logger::warning( 'wp_mail() returned false for /contact', array( 'email' => $email ) );
		}

		return new WP_REST_Response( array( 'success' => (bool) $sent ), $sent ? 200 : 500 );
	}

	// ---------------------------------------------------------------
	// GET /plans
	// ---------------------------------------------------------------
	public function plans( WP_REST_Request $request ) {
		return $this->safely( 'plans', function () {
			return new WP_REST_Response( array(
				'success' => true,
				'plans'   => DCard_Plans::active_public_plans(),
			), 200 );
		} );
	}

	/**
	 * תיקון (התבקש: "כל התראת תשלום שנדחית מכל סיבה שתהיה - שולחת מייל
	 * ל-admin_email מיד") - דווח בפועל ש-16:16 התקבל secret שגוי (מפתח
	 * שעודכן רק בצד אחד), ואף אחד לא ידע עד שמישהו חיפש בלוג. מרוסן
	 * (rate-limited) ל-10 דקות **לכל סיבה בנפרד** (transient, לא טבלה -
	 * לא צריך להישרד restart, רק למנוע הצפה אם קארדקום מנסה שוב ושוב),
	 * כדי שתקלה שחוזרת על עצמה (למשל secret שגוי שמגיע כל כמה דקות) לא
	 * תשלח עשרות מיילים זהים.
	 */
	private static function notify_admin_of_webhook_rejection( $reason, $message ) {
		$transient_key = 'dcard_webhook_reject_' . md5( $reason );
		if ( get_transient( $transient_key ) ) {
			return;
		}
		set_transient( $transient_key, 1, 10 * MINUTE_IN_SECONDS );

		$admin_email = get_option( 'admin_email' );
		if ( ! $admin_email ) {
			return;
		}
		wp_mail(
			$admin_email,
			'הקורא בקלפים - התראת תשלום נדחתה (' . $reason . ')',
			self::wrap_admin_alert_html( $message . "\n\n(התראה נוספת על אותה סיבה לא תישלח ב-10 הדקות הקרובות.)" ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	/**
	 * עוטף הודעת התראה פנימית (טקסט רגיל, כולל שורות "תווית: ערך") ב-HTML
	 * פשוט - תוקן §6.12 (תגית <img> גלויה כטקסט): תוסף מעקב הפתיחה (Check
	 * & Log Email) מניח מייל HTML, ומזריק תגית <img> שרק ב-HTML מוצגת
	 * כתמונה. nl2br שומר על שורות ה"תווית: ערך" הקיימות כמו שהן (כל קריאה
	 * שכותבת הודעה כזו ממשיכה לעבוד בלי שינוי בפורמט המחרוזת עצמה).
	 */
	public static function wrap_admin_alert_html( $plain_message ) {
		return '<div dir="rtl" style="font-family: Arial, Helvetica, sans-serif; max-width: 600px; margin: 0 auto; color: #222; line-height: 1.8;">'
			. '<p>' . nl2br( esc_html( $plain_message ) ) . '</p>'
			. '</div>';
	}

	/**
	 * קצב התראה ייעודי ל-invalid_secret (24.11, דווח בפועל: הצפה מתמשכת
	 * של notification-ים עם secret ישן/שגוי, כנראה re-transmit של קארדקום
	 * מלפני rotation) - שונה בכוונה מ-notify_admin_of_webhook_rejection
	 * (10 דקות, בלי צבירה): כאן "חלון" לכל סיבה - מייל מיידי בפעימה
	 * הראשונה של חלון חדש (option עדיין לא קיים), ואז עד מייל סיכום אחד
	 * נוסף לכל חלון (עד 24 שעות מהמייל הראשון, ר' flush_invalid_secret_
	 * alert_window) עם מספר הפעמים ורשימת מזהי העסקה/RecurringId שנצברו.
	 * wp_option ולא transient - חייב להישרד עד שה-cron השעתי הקיים
	 * (dcard_daily_cleanup) יסגור את החלון, גם אם זה יותר מ-24-48 שעות
	 * שה-transient עדיין ישמור על הערך (לא רלוונטי כאן, אבל transient על
	 * WordPress לא מובטח להישרד יותר מהזמן שהוגדר - ולא רוצים לאבד את
	 * הצבירה אם המזבלה מתרוקנת מוקדם).
	 */
	private static function record_invalid_secret_rejection( array $identifiers ) {
		$option_name = 'dcard_webhook_alert_window_invalid_secret';
		$window       = get_option( $option_name );

		if ( ! is_array( $window ) || empty( $window['first_at'] ) ) {
			$window = array(
				'first_at' => time(),
				'count'    => 0,
				'deals'    => array(),
			);

			$admin_email = get_option( 'admin_email' );
			if ( $admin_email ) {
				$deal_label = $identifiers['deal_number'] ? $identifiers['deal_number'] : '(לא ידוע)';
				wp_mail(
					$admin_email,
					'הקורא בקלפים - התראת תשלום נדחתה (invalid_secret)',
					self::wrap_admin_alert_html(
						"התקבל webhook מקארדקום עם secret לא תואם או חסר.\n\n"
						. "מספר עסקה/RecurringId: {$deal_label}\nסוג רשומה: {$identifiers['record_type']}\n"
						. "תאריך חשבונית: {$identifiers['invoice_date']}\nמייל (ממוסך): {$identifiers['email_masked']}\n\n"
						. "אם זה חוזר על עצמו - יישלח עד מייל סיכום אחד נוסף בתוך 24 שעות, עם מספר הפעמים ורשימת העסקאות שנצברו."
					),
					array( 'Content-Type: text/html; charset=UTF-8' )
				);
			}
		}

		++$window['count'];
		if ( count( $window['deals'] ) < 200 ) {
			$window['deals'][] = $identifiers['deal_number'] ? $identifiers['deal_number'] : '(לא ידוע)';
		}

		update_option( $option_name, $window, false );
	}

	/**
	 * נקרא מה-cron השעתי הקיים (dcard_daily_cleanup) - לא cron נפרד. סוגר
	 * חלון invalid_secret פתוח שעברו עליו 24 שעות מהמייל הראשון: אם הצטברו
	 * יותר מהתרחשות אחת (כלומר היה מה להוסיף על המייל המיידי) - שולח מייל
	 * סיכום אחד עם הכמות ורשימת מזהי העסקה/RecurringId, ואז מוחק את ה-
	 * option כדי שההתרחשות הבאה תפתח חלון חדש.
	 */
	public static function flush_invalid_secret_alert_window() {
		$option_name = 'dcard_webhook_alert_window_invalid_secret';
		$window       = get_option( $option_name );

		if ( ! is_array( $window ) || empty( $window['first_at'] ) ) {
			return;
		}

		if ( time() - $window['first_at'] < DAY_IN_SECONDS ) {
			return;
		}

		if ( $window['count'] > 1 ) {
			$admin_email = get_option( 'admin_email' );
			if ( $admin_email ) {
				$deals_list = implode( ', ', $window['deals'] );
				wp_mail(
					$admin_email,
					'הקורא בקלפים - סיכום יומי: תשלומים נדחו (invalid_secret)',
					self::wrap_admin_alert_html(
						"ב-24 השעות האחרונות נדחו {$window['count']} notification-ים מקארדקום עם secret לא תואם או חסר.\n\n"
						. "מספרי עסקה/RecurringId: {$deals_list}"
					),
					array( 'Content-Type: text/html; charset=UTF-8' )
				);
			}
		}

		delete_option( $option_name );
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

		// 0c. סעיף 6.11 (הכנה ל-Phase A - שמירת RecurringId): התראות ממודול
		// הוראת הקבע של קארדקום (docs/cardcom-api/README.md) נדחות היום ב-1.
		// למטה כי הן מגיעות עם "Secret" (אות גדולה) ולא "secret" כמו ב-IPN
		// הרגיל - עד כה רק המפתחות נרשמו ביומן (ר' 'received_keys' ב-1.),
		// לא הערכים, ולכן אי אפשר לדעת מה RecurringId/IsActive/וכו' האמיתיים
		// בחיוב הבא בלי לרשום את התוכן המלא פעם אחת. מזוהה לפי RecurringId -
		// מפתח שקיים רק בפורמט הזה, לא ב-IPN של עסקה רגילה. ממוסך: כל ערך
		// של מפתח שם/תוכן שלו מרמז על רגישות (Secret, כרטיס אשראי, מייל,
		// טלפון) - לא חושפים רגיש, אבל כל שאר הערכים - כולל RecurringId,
		// RecordType, IsActive עצמם - נשארים גלויים כי בשבילם זו בדיוק
		// המטרה של הלוג הזה.
		if ( null !== $request->get_param( 'RecurringId' ) ) {
			$mask_sensitive_ipn_value = function ( $value ) {
				$value = (string) $value;
				$len   = strlen( $value );
				if ( $len <= 4 ) {
					return str_repeat( '*', $len );
				}
				return substr( $value, 0, 2 ) . str_repeat( '*', $len - 4 ) . substr( $value, -2 );
			};
			$sensitive_key_pattern = '/secret|card|cvv|email|mobile|phone|password/i';
			$masked_params         = array();
			foreach ( $request->get_params() as $param_key => $param_value ) {
				$masked_params[ $param_key ] = preg_match( $sensitive_key_pattern, $param_key )
					? $mask_sensitive_ipn_value( $param_value )
					: $param_value;
			}
			DCard_Logger::info( 'POST /webhook/ipn-update - standing-order (recurring) notification received (full content, sensitive fields masked)', $masked_params );
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
		// *** אבטחה (תוקן): DCARD_WEBHOOK_SECRET כבר לא מוגדר עם ערך קשיח
		// ב-the-card-reader-core.php - הוא חייב להיות מוגדר ב-wp-config.php
		// של production (ר' docs/go-live.md). אם הוא לא מוגדר בכלל, זו
		// תקלת הגדרה (לא "secret שגוי") - נדחה ונרשם כשגיאה, לא כאזהרה. ***
		if ( ! defined( 'DCARD_WEBHOOK_SECRET' ) ) {
			DCard_Logger::error( 'POST /webhook/ipn-update - DCARD_WEBHOOK_SECRET is not defined in wp-config.php, rejecting all webhook calls', array() );
			self::notify_admin_of_webhook_rejection( 'missing_secret_constant', 'ה-webhook דוחה את כל הבקשות כי DCARD_WEBHOOK_SECRET לא מוגדר ב-wp-config.php.' );
			return new WP_REST_Response( array( 'success' => false, 'error' => 'Unauthorized' ), 403 );
		}

		// תיקון (מודול הוראת הקבע של קארדקום שולח "Secret" עם אות גדולה,
		// לא "secret" כמו ה-IPN הרגיל - ר' docs/cardcom-api/README.md וה-
		// לוג האמיתי שמתועד שם) - נבדקים שני השמות, hash_equals לכל אחד
		// בנפרד (לא concatenation/OR על ערכים לא ממוסכים לפני ההשוואה).
		//
		// תיקון (דווח בפועל 19.11 16:16: "התקבלו 23 תווים עם 'secret=',
		// צפויים 29" - מישהו הדביק את כל המחרוזת "secret=הערך" כערך השדה
		// בממשק קארדקום, לא רק הערך עצמו) - אם הערך שמתקבל מתחיל ב-
		// "secret=" (אותיות גדולות/קטנות), מסירים את התחילית הזו לפני
		// ההשוואה. לא פוגע בערך תקין רגיל (שלעולם לא יתחיל כך בעצמו).
		$strip_secret_prefix = function ( $value ) {
			return preg_replace( '/^secret=/i', '', (string) $value );
		};
		$secret_lower = $strip_secret_prefix( $request->get_param( 'secret' ) );
		$secret_upper = $strip_secret_prefix( $request->get_param( 'Secret' ) );
		$secret_valid = ( $secret_lower && hash_equals( DCARD_WEBHOOK_SECRET, $secret_lower ) )
			|| ( $secret_upper && hash_equals( DCARD_WEBHOOK_SECRET, $secret_upper ) );
		if ( ! $secret_valid ) {
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
			$received_secret = $secret_lower ? $secret_lower : $secret_upper;

			// תיקון (24.11, "invalid_secret סביב השעון אחרי rotation" -
			// כנראה re-transmit של התראות ישנות מלפני שינוי המפתח): כדי
			// שיהיה אפשר בעתיד לדעת לאיזו עסקה/הוראת קבע כל דחייה שייכת,
			// בלי "לעבד" את ההתראה בפועל (אין חיפוש משתמש/DB - רק קריאת
			// שדות גולמיים מה-payload) - מספר עסקה לתשלום רגיל, RecurringId
			// להוראת קבע, RecordType, תאריך החשבונית (אם יש), ומייל ממוסך.
			$deal_number = (string) $request->get_param( 'ExtReadInvoiceHead_InvoiceNumber' );
			if ( '' === $deal_number ) {
				$deal_number = (string) $request->get_param( 'RecurringId' );
			}
			$record_type  = (string) $request->get_param( 'RecordType' );
			$invoice_date = (string) $request->get_param( 'ExtReadInvoiceHead_InvoiceDate' );
			$email_raw    = $this->get_nested_param( $request, 'ExtReadInvoiceHead', 'Email' );
			if ( ! $email_raw ) {
				$email_raw = $this->get_nested_param( $request, 'acc', 'Email' );
			}

			DCard_Logger::warning( 'POST /webhook/ipn-update - missing/invalid secret, rejecting', array(
				'received_keys'          => array_keys( $request->get_params() ),
				'received_secret_len'    => strlen( $received_secret ),
				'received_secret_masked' => $mask( $received_secret ),
				'expected_secret_len'    => strlen( (string) DCARD_WEBHOOK_SECRET ),
				'expected_secret_masked' => $mask( (string) DCARD_WEBHOOK_SECRET ),
				'deal_number'            => $deal_number,
				'record_type'            => $record_type,
				'invoice_date'           => $invoice_date,
				'email_masked'           => $email_raw ? $mask( (string) $email_raw ) : null,
				'received_at'            => current_time( 'mysql', true ),
			) );

			self::record_invalid_secret_rejection( array(
				'deal_number'  => $deal_number,
				'record_type'  => $record_type,
				'invoice_date' => $invoice_date,
				'email_masked' => $email_raw ? $mask( (string) $email_raw ) : '',
			) );

			// 200 בכוונה (לא 403) - לא רוצים שקארדקום תמשיך לנסות שוב
			// notification עם secret ישן/שגוי עד אינסוף (ר' הבקשה בפועל:
			// "invalid_secret סביב השעון"). אין שום עיבוד נוסף של הבקשה
			// הזו מעבר ללוג ולספירה לצורך ההתראה - בלי secret תקין, לא
			// סומכים על שום שדה אחר ב-payload.
			return new WP_REST_Response( array( 'success' => false, 'error' => 'ignored' ), 200 );
		}

		// 1d. תיקון (21.11, "MasterRecurring עדיין מתעלם בגלל בדיקת
		// ResponseCode"): התראת מצב ממודול הוראת הקבע (RecordType=
		// MasterRecurring, ר' docs/cardcom-api/README.md) נבדקת *לפני*
		// בדיקת ResponseCode בשלב 2 למטה - ההתראה הזו לא מתועדת כמכילה
		// ResponseCode תקין בכלל, ולכן הייתה נדחית שם תמיד כ"לא הצלחה"
		// ו-RecurringId אף פעם לא נשמר. כאן רק שומרים את ה-RecurringId
		// (דרוש בעתיד לביטול אמיתי בקארדקום - ר' ההערה המלאה ב-account_
		// cancel_inner) - *לא* מריצים set_plan/transaction כאן: המק"ט/
		// המייל בהתראת מצב כזו לא מתועדים כזהים לשדות ExtReadInvoiceLines_
		// ProductID/ExtReadInvoiceHead_Email של תשלום רגיל (ר' README -
		// שם מופיע FlexItem_ProductId, שדה אחר) - בלי לוג אמיתי שמאשר את
		// זה, זיהוי מסלול/משתמש שגוי כאן יכול להקצות קרדיטים למשתמש הלא
		// נכון. אם ה-RecurringId בפועל גם מחייב עדכון מסלול/קרדיטים ולא
		// רק רישום ה-ID - נדרש לוג אמיתי של ההתראה הזו כדי לדעת אילו שדות
		// זיהוי באמת מגיעים בה, לפני שממשיכים לשם.
		$record_type = (string) $request->get_param( 'RecordType' );
		if ( 'MasterRecurring' === $record_type ) {
			$recurring_id = (string) $request->get_param( 'RecurringId' );
			// 'acc' - ר' ההערה המלאה מעל find_user_from_ipn: שדה האימייל
			// בהתראת MasterRecurring הוא acc.Email (-> acc_Email), לא
			// ExtReadInvoiceHead_Email כמו בתשלום הרגיל.
			list( $recurring_user, $recurring_email ) = $this->find_user_from_ipn( $request, 'acc' );

			if ( $recurring_user && $recurring_id ) {
				DCard_Users::save_recurring_id( (int) $recurring_user['id'], $recurring_id );
				DCard_Logger::info( 'POST /webhook/ipn-update - MasterRecurring notification: RecurringId saved', array(
					'user_id'      => (int) $recurring_user['id'],
					'recurring_id' => $recurring_id,
				) );
			} else {
				DCard_Logger::warning( 'POST /webhook/ipn-update - MasterRecurring notification but user not found or RecurringId missing, not saved', array(
					'recurring_id' => $recurring_id,
					'email'        => $recurring_email,
				) );
			}

			return new WP_REST_Response( array( 'success' => true ), 200 );
		}

		// 2. סטטוס העסקה - '0' = הצלחה (לפי התיעוד). השוואת מחרוזות בכוונה
		// (לא ==) כי ResponseCode יכול להגיע כ-int 0 או כמחרוזת "0" תלוי
		// בפורמט השליחה - (string) מנרמל את שניהם לפני ההשוואה.
		$response_code = (string) $request->get_param( 'ResponseCode' );
		if ( '0' !== $response_code ) {
			DCard_Logger::info( 'POST /webhook/ipn-update - ResponseCode != 0, ignoring (not a successful transaction)', array( 'ResponseCode' => $response_code ) );
			return new WP_REST_Response( array( 'success' => false, 'error' => 'Transaction not successful' ), 200 ); // 200 בכוונה - קארדקום לא צריכה לנסות שוב, זו לא "שגיאה" מהצד שלנו
		}

		// 3. זיהוי מסלול מהמק"ט - *לפני* חיפוש המשתמש (תיקון אחרי שהתברר
		// בפועל, 1.11.26: ה-webhook ב-Cardcom מוגדר ברמת כל החשבון, לא רק
		// למוצר "הקורא בקלפים" - כל עסקה בכל מוצר אחר של נוריתה דר (ר'
		// product_id 700/930 בלוג האמיתי) מפעילה את ה-endpoint הזה גם כן.
		// קודם זה נבדק *אחרי* חיפוש המשתמש, אז כל עסקה כזו הייתה נכשלת ב-
		// "משתמש לא נמצא" ומוחזרת כ-404 - מה שגרם לקארדקום לדווח על "כשל"
		// על כל עסקה בעסק, גם אלה שלא קשורות בכלל למוצר שלנו, ובדיוק זה מה
		// שנראה כ"404 לסירוגין" מול Hostinger (זו לא הייתה בעיית WAF/
		// hosting בכלל). עכשיו: אם המק"ט לא שלנו - לא בודקים user בכלל,
		// ומחזירים 200 מיידית (לא שגיאה מבחינתנו, קארדקום לא צריכה לנסות
		// שוב) - ExtReadInvoiceLines יכול להיות שדה שטוח (שורת חשבונית
		// בודדת) או מערך (כמה שורות), נבדקות שתי החלופות.
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
			DCard_Logger::info( 'POST /webhook/ipn-update - ProductID not ours (other product on the same Cardcom account), ignoring', array( 'product_id' => $product_id ) );
			return new WP_REST_Response( array( 'success' => true, 'error' => 'מק"ט לא שייך למוצר הזה - התעלמות' ), 200 );
		}

		// 4. זיהוי המשתמש - ר' find_user_from_ipn (מחולץ מכאן ב-21.11 כדי
		// שגם ענף MasterRecurring למעלה יוכל להשתמש באותו היגיון זיהוי).
		list( $user, $email_from_ipn ) = $this->find_user_from_ipn( $request );

		if ( ! $user ) {
			// מגיעים לכאן רק עבור עסקה שכבר אושרה כשייכת למוצר שלנו (מק"ט
			// 601/602/603, ר' הבדיקה למעלה) - זו כבר לא רעש מעסקאות של
			// מוצרים אחרים, אלא בעיה אמיתית שדורשת טיפול ידני (לקוח ששילם
			// על "הקורא בקלפים" ולא מקבל קרדיט).
			$unmatched_invoice = (string) $request->get_param( 'ExtReadInvoiceHead_InvoiceNumber' );
			$unmatched_amount  = (string) $request->get_param( 'ExtReadInvoiceHead_TotalIncludeVAT' );
			DCard_Logger::warning( 'POST /webhook/ipn-update - no user found via ReturnValue or email fallback (for OUR product)', array(
				'return_value'   => $return_value,
				'email'          => $email_from_ipn,
				'product_id'     => $product_id,
				'invoice_number' => $unmatched_invoice,
			) );

			// תוספת (התבקש): תשלום אמיתי שהתקבל (ProductID שלנו, ResponseCode=0)
			// אבל לא השתייך לאף משתמש - בלי מייל ל-admin_email הדבר היחיד
			// שמתעד את זה הוא לוג WooCommerce (לא נבדק באופן שוטף). אותו
			// דפוס מדויק כמו DCard_Usage_Logs::maybe_send_monthly_budget_alert -
			// wp_mail() פשוט ל-admin_email, בלי חסימה/טיפול אוטומטי.
			self::notify_admin_of_webhook_rejection( 'user_not_found', sprintf(
				"התקבל תשלום בקארדקום (מק\"ט %s, חשבונית %s, סכום %s ₪) שלא ניתן היה לשייך לאף משתמש - לא לפי ReturnValue ולא לפי אימייל.\n\nמייל מהחשבונית: %s\n\nיש לשייך ידנית: עמוד לקוחות (Settings > קורא בקלפים > לקוחות), או ליצור קשר עם הלקוח לפי המייל/הטלפון שבחשבונית בקארדקום.",
				$product_id,
				$unmatched_invoice,
				$unmatched_amount,
				$email_from_ipn ? $email_from_ipn : '(לא התקבל)'
			) );

			return new WP_REST_Response( array( 'success' => false, 'error' => 'משתמש לא נמצא (ReturnValue ואימייל לא תואמים)' ), 404 );
		}

		// 5. פרטי העסקה לטבלת ה-transactions.
		$cardcom_transaction_id = (string) $request->get_param( 'ExtReadInvoiceHead_InvoiceNumber' );
		$amount                 = (float) $request->get_param( 'ExtReadInvoiceHead_TotalIncludeVAT' );
		$transaction_date_raw   = (string) $request->get_param( 'ExtReadInvoiceHead_InvoiceDate' );
		$phone                  = sanitize_text_field( (string) $request->get_param( 'ExtReadInvoiceHead_CustMobilePH' ) );

		if ( ! $cardcom_transaction_id ) {
			DCard_Logger::warning( 'POST /webhook/ipn-update - missing ExtReadInvoiceHead_InvoiceNumber (dedup key)', array() );
			self::notify_admin_of_webhook_rejection( 'invoice_number_missing', 'התקבל webhook מקארדקום בלי ExtReadInvoiceHead_InvoiceNumber (מק"ט החשבונית) - לא ניתן לרשום את העסקה.' );
			return new WP_REST_Response( array( 'success' => false, 'error' => 'InvoiceNumber חסר' ), 400 );
		}

		// תיקון (§6.10.2, דווח בפועל: לקוח חויב ₪467 וקיבל מסלול נרקיס):
		// הסכום והמסלול נקבעים אצלנו רק לפי ה-ProductID שמגיע ב-IPN - קארדקום
		// עצמה היא זו שבאמת גובה (ר' ההערה ב-DCard_Plans::public_plan_data),
		// כך ששום דבר כאן לא "בוחר" מחיר. נרשם ביומן בכל IPN מוצלח, ואם
		// הסכום שונה מהמחיר שמוגדר בדף המכסות למסלול הזה - גם התראה ביומן
		// וגם מייל ל-admin_email, כדי לתפוס פער כזה מיד ולא רק כש-נוריתה
		// שמה לב בעצמה בעמוד הלקוחות.
		$expected_price = DCard_Plans::price_for_plan( $plan_id );
		DCard_Logger::info( 'POST /webhook/ipn-update - IPN accepted for our product', array(
			'product_id'      => $product_id,
			'amount'          => $amount,
			'plan_id'         => $plan_id,
			'expected_price'  => $expected_price,
		) );
		if ( $expected_price > 0 && abs( $amount - $expected_price ) > 0.01 ) {
			DCard_Logger::warning( 'POST /webhook/ipn-update - charged amount does not match plan price in דף המכסות', array(
				'product_id'     => $product_id,
				'plan_id'        => $plan_id,
				'amount'         => $amount,
				'expected_price' => $expected_price,
			) );

			$admin_email = get_option( 'admin_email' );
			if ( $admin_email ) {
				wp_mail(
					$admin_email,
					'הקורא בקלפים - אי-התאמה בין הסכום שחויב למחיר המוגדר',
					self::wrap_admin_alert_html( sprintf(
						"התקבל תשלום בקארדקום שסכומו לא תואם למחיר המוגדר בדף המכסות.\n\n"
						. "חשבונית: %s\nמק\"ט: %s\nמסלול: %s\nסכום שחויב: %s ₪\nמחיר מוגדר: %s ₪\n\n"
						. "כדאי לבדוק: האם המחיר בדף המכסות לא עודכן, או שהמוצר/המחיר בקארדקום עצמו לא תואם למה שמוגדר אצלנו.",
						$cardcom_transaction_id,
						$product_id,
						$plan_id,
						$amount,
						$expected_price
					) ),
					array( 'Content-Type: text/html; charset=UTF-8' )
				);
			}
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
			self::notify_admin_of_webhook_rejection( 'transaction_save_failed', sprintf(
				"קארדקום אישרה תשלום (חשבונית %s, מק\"ט %s, סכום %s ₪) אבל שמירת העסקה בטבלה נכשלה (שגיאת DB) - המסלול/קרדיטים לא עודכנו. יש לבדוק את הלוג ולעדכן ידנית אם צריך (עמוד לקוחות > עדכון מסלול ידני).",
				$cardcom_transaction_id,
				$product_id,
				$amount
			) );
			return new WP_REST_Response( array( 'success' => false, 'error' => 'שגיאה בשמירת העסקה' ), 500 );
		}

		// 6. עדכון בפועל: מסלול+קרדיטים (DCard_Users::set_plan - קורא ל-
		// DCard_Plans::credits_for_plan, לא קשיח), תפוגה (30/365 יום לפי
		// המק"ט - ר' DCard_Plans::plan_duration_days), וטלפון (רק אם ריק).
		$expires_at   = gmdate( 'Y-m-d H:i:s', strtotime( '+' . DCard_Plans::plan_duration_days( $plan_id ) . ' days' ) );
		$plan_updated = DCard_Users::set_plan( $user['id'], $plan_id, $expires_at );
		DCard_Users::update_phone_if_empty( $user['id'], $phone );

		// תיקון (דווח בפועל, ר' set_plan() לפרטים מלאים): עסקה אושרה
		// ונרשמה ב-transactions, אבל set_plan() נכשל (למשל DB error) בלי
		// שאף אחד ידע - הלוג כתב "הצלחה" בכל מקרה. עכשיו: אם set_plan()
		// נכשל בפועל, לא מדווחים "הצלחה" לא ללוג ולא לקארדקום, ונשלח מייל
		// ל-admin_email (אותו דפוס כמו "תשלום בלי שיוך למשתמש" למעלה) -
		// זה בדיוק המקרה שה-"עדכון מסלול ידני" בעמוד הלקוחות קיים לתקן.
		if ( ! $plan_updated ) {
			DCard_Logger::error( 'POST /webhook/ipn-update - set_plan failed, user plan NOT actually updated despite successful payment', array(
				'user_id'                => $user['id'],
				'plan_id'                => $plan_id,
				'cardcom_transaction_id' => $cardcom_transaction_id,
			) );

			self::notify_admin_of_webhook_rejection( 'plan_update_failed', sprintf(
				"התקבל תשלום בקארדקום (חשבונית %s, משתמש #%d, מסלול %s) אבל עדכון המסלול בבסיס הנתונים נכשל (ר' הלוג לפרטים).\n\nיש לתקן ידנית: עמוד לקוחות (Settings > קורא בקלפים > לקוחות) > \"עדכון מסלול ידני\" למשתמש הזה.",
				$cardcom_transaction_id,
				$user['id'],
				$plan_id
			) );

			return new WP_REST_Response( array( 'success' => false, 'error' => 'עדכון מסלול נכשל' ), 500 );
		}

		// §(21.11): "כשה-IPN מגיע, הדגל מתנקה" - מכאן ה-kbQuietPlanPoll
		// בפרונט יודע שהתשלום הושלם (payment_pending הופך false), ו"שיחה
		// אחת חינם" (has_credits_or_pending_pass) כבר לא רלוונטית - יש
		// קרדיטים אמיתיים עכשיו. לא מותנה בכך שה-payment_pending בכלל
		// היה פתוח (למשל תשלום שני לפני שה-IPN של הראשון הגיע) - ניקוי
		// no-op בטוח במקרה כזה.
		DCard_Users::clear_payment_pending( $user['id'] );

		// תיקון: לפני כן לא היה שום לוג על הצלחה - רק על כשלים. בלי זה,
		// "האם ה-webhook בכלל התקבל והעודכן?" דרש בדיקת DB ישירה כל פעם.
		DCard_Logger::info( 'POST /webhook/ipn-update - plan updated successfully', array(
			'user_id'                => $user['id'],
			'plan_id'                => $plan_id,
			'cardcom_transaction_id' => $cardcom_transaction_id,
			'credits_msg'            => DCard_Plans::credits_for_plan( $plan_id, 'msg' ),
			'credits_chat'           => DCard_Plans::credits_for_plan( $plan_id, 'chat' ),
			'expires_at'             => $expires_at,
		) );

		return new WP_REST_Response( array(
			'success'      => true,
			'user_id'      => $user['id'],
			'plan_id'      => $plan_id,
			'credits'      => DCard_Plans::credits_for_plan( $plan_id, 'msg' ),
			'credits_msg'  => DCard_Plans::credits_for_plan( $plan_id, 'msg' ),
			'credits_chat' => DCard_Plans::credits_for_plan( $plan_id, 'chat' ),
			'expires_at'   => $expires_at,
		), 200 );
	}

	/**
	 * זיהוי המשתמש מהתראת IPN - שכבה ראשונה: ReturnValue (ר' הערה ב-
	 * elementor-code.html). שכבה שנייה (Fallback): לוג IPN אמיתי מקארדקום
	 * (re-transmit ידני) הראה ש-ReturnValue *לא קיים בכלל* ב-payload של
	 * המוצר הספציפי הזה - כלומר דף התשלום הזה לא מהדהד פרמטרים חיצוניים
	 * מה-URL בחזרה ב-IPN. חוזרים לתוכנית המקורית: אימייל (ExtReadInvoiceHead_
	 * Email, דרך get_nested_param - תומך גם מפתח שטוח עם קו תחתון וגם
	 * מבנה מקונן). הסיכון הידוע (אימייל שהלקוח שינה בקארדקום לא יתאים
	 * לשורה הקיימת) מתועד, לא נפתר - אין כרגע חלופה אחרת שקארדקום בפועל
	 * מחזירה עבור המוצר הזה. מחולץ (21.11) מ-payment_webhook_inner כדי
	 * שגם ענף MasterRecurring ישתמש באותו היגיון זיהוי, לא רק התשלום הרגיל.
	 *
	 * $email_group (21.11, דווח בפועל - לוג MasterRecurring אמיתי: "user not
	 * found, email: null"): תשלום רגיל שולח את האימייל תחת ExtReadInvoiceHead.
	 * Email (ברירת המחדל כאן), אבל התראת MasterRecurring שולחת אותו תחת
	 * acc.Email - קבוצה אחרת לגמרי (לא מתועדת ב-README, התגלתה מהלוג בפועל).
	 * PHP/WordPress הופכים את הנקודה בשם הפרמטר לקו תחתון אוטומטית (כמו כל
	 * שאר שדות ה-group_key בקובץ הזה), כך ש-get_nested_param עם group='acc'
	 * כבר קוראת את acc_Email נכון בלי שינוי נוסף.
	 *
	 * @return array{0: array|null, 1: string|null} [$user, $email_from_ipn]
	 */
	private function find_user_from_ipn( WP_REST_Request $request, $email_group = 'ExtReadInvoiceHead' ) {
		$return_value = $request->get_param( 'ReturnValue' );
		$user_id      = absint( $return_value );
		error_log( '[DCard Webhook] parsed ReturnValue=' . wp_json_encode( $return_value ) . ' -> user_id=' . $user_id );

		$user = $user_id > 0 ? DCard_Users::get_by_id( $user_id ) : null;

		$email_from_ipn = null;

		if ( ! $user ) {
			$email_from_ipn = $this->get_nested_param( $request, $email_group, 'Email' );

			// תיקון באג אמיתי (אומת 1.11.26 מתוך לוג IPN אמיתי עם אימייל
			// שמכיל "+"): ההנחה הקודמת כאן הייתה שגויה - PHP/WordPress כבר
			// מפענחים את ה-POST body הסטנדרטי (application/x-www-form-
			// urlencoded) לבד, אז $email_from_ipn שמתקבל מ-get_nested_param
			// כבר מכיל "heddyeldar+eden@gmail.com" נכון לגמרי (לא %2b/%40
			// גולמיים). ה-urldecode() הנוסף שהיה כאן לא היה "no-op בטוח" -
			// urldecode() הופך "+" ל-רווח (כך הוא מפרש טפסים), אז הוא פגע
			// בכל אימייל עם "+" (plus-addressing, נפוץ אצל לקוחות) והפך
			// אותו ל-"heddyeldar eden@gmail.com" (עם רווח, לא תקין) - בדיוק
			// הסיבה לכל ה"משתמש לא נמצא" שדווחו. הוסר לגמרי - $email_from_ipn
			// כבר השדה הנכון, בלי פענוח נוסף.
			error_log( '[DCard Webhook] ReturnValue missing/invalid - falling back to email lookup. email=' . wp_json_encode( $email_from_ipn ) );

			if ( $email_from_ipn && is_email( sanitize_email( $email_from_ipn ) ) ) {
				$user = DCard_Users::get_by_email( sanitize_email( $email_from_ipn ) );
			}
		}

		return array( $user, $email_from_ipn );
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

		// תיקון (פיצול קרדיטים מסר/שיחה מפתחת - ר' DCard_Plans) -
		// current_credits (יחיד) הוחלף בשני המאגרים; נשאר גם בתגובה
		// בשם הישן current_credits (= credits_msg) בשביל הלוג הקיים ב-
		// elementor-code.html שעדיין קורא אותו, לצד שני המפתחות החדשים.
		$response = new WP_REST_Response( array(
			'success'             => true,
			'user_id'             => (int) $user['id'],
			'current_plan'        => $user['current_plan'],
			// §(21.11): עבור "מסלול X פעיל" ב-kbQuietPlanPoll - בלי לדרוש
			// עוד קריאת רשת נפרדת רק בשביל השם לתצוגה.
			'plan_label'          => DCard_Plans::label_for_plan( $user['current_plan'] ),
			'current_credits'     => (int) $user['current_credits_msg'],
			'current_credits_msg' => (int) $user['current_credits_msg'],
			'current_credits_chat'=> (int) $user['current_credits_chat'],
			'plan_expires_at'     => $user['plan_expires_at'],
			// §(21.11): ר' DCard_Users::is_payment_pending_fresh - true כל
			// עוד payment_pending_at טרי (עד 30 דקות), בלי קשר ל"שיחה אחת
			// חינם" שכבר נוצלה. הלקוח (kbHasCreditsOrHandleExhausted/
			// kbOpenPaymentModal/kbQuietPlanPoll) נשען אך ורק על השדה הזה.
			'payment_pending'     => DCard_Users::is_payment_pending_fresh( $user ),
		), 200 );

		// תיקון (21.11, נבדק בפועל ב-Cloudflare עם curl/fetch - cf-cache-
		// status: DYNAMIC, אין קאש בפועל בקצה): לא זה הגורם שדווח כ"נתקע
		// אחרי תשלום" (ר' הגורם האמיתי ב-DCard_Quotas::render_paid_plan_row),
		// אבל ה-header הזה נוסף כהגנה - אנדפוינט פולינג כזה לא אמור להיות
		// תלוי בברירת המחדל "DYNAMIC" של Cloudflare, ובכל מקרה לא תקף
		// לקאש בדפדפן/פרוקסי כלשהו בדרך.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );

		return $response;
	}

	// ---------------------------------------------------------------
	// GET /account/details (§6.11, Phase B)
	// ---------------------------------------------------------------
	public function account_details( WP_REST_Request $request ) {
		return $this->safely( 'account_details', function () use ( $request ) {
			return $this->account_details_inner( $request );
		} );
	}

	private function account_details_inner( WP_REST_Request $request ) {
		$device_token = $request->get_header( 'X-Dcard-Device-Token' );
		$user         = $device_token ? DCard_Users::get_by_device_token( $device_token ) : null;

		if ( ! $user ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'משתמש לא נמצא' ), 404 );
		}

		$plan_id        = $user['current_plan'];
		$transactions   = DCard_Transactions::list_for_user( (int) $user['id'] );
		$transactions   = array_map( function ( $row ) {
			return array(
				'plan_id'          => $row['plan_id'],
				'plan_label'       => DCard_Plans::label_for_plan( $row['plan_id'] ),
				'amount'           => (float) $row['amount'],
				'currency'         => $row['currency'],
				'transaction_date' => $row['transaction_date'],
			);
		}, $transactions ? $transactions : array() );

		return new WP_REST_Response( array(
			'success'              => true,
			'user_id'              => (int) $user['id'],
			'email'                => $user['email'],
			'name'                 => $user['name'],
			'gender'               => $user['gender'],
			'phone'                => $user['phone'],
			'current_plan'         => $plan_id,
			'plan_label'           => DCard_Plans::label_for_plan( $plan_id ),
			'plan_price'           => DCard_Plans::price_for_plan( $plan_id ),
			'current_credits_msg'  => (int) $user['current_credits_msg'],
			'current_credits_chat' => (int) $user['current_credits_chat'],
			// §6.11: "קבלות מסר: 12 מתוך 80" - הפס צריך גם את המקסימום של
			// המסלול, לא רק את מה שנשאר (current_credits_* זה מה שנשאר).
			'credits_msg_max'      => DCard_Plans::credits_for_plan( $plan_id, 'msg' ),
			'credits_chat_max'     => DCard_Plans::credits_for_plan( $plan_id, 'chat' ),
			'unlimited_msg'        => DCard_Plans::is_unlimited( $plan_id, 'msg' ),
			'unlimited_chat'       => DCard_Plans::is_unlimited( $plan_id, 'chat' ),
			'plan_expires_at'      => $user['plan_expires_at'],
			'next_credit_reset_at' => $user['next_credit_reset_at'],
			'cancel_requested_at'  => $user['cancel_requested_at'],
			'cancelled_at'         => $user['cancelled_at'],
			'cancel_reason'        => $user['cancel_reason'],
			'transactions'         => $transactions,
			'payment_pending'      => DCard_Users::is_payment_pending_fresh( $user ),
		), 200 );
	}

	// ---------------------------------------------------------------
	// POST /account/update-details (§6.11 "הפרטים שלי", Phase B)
	// ---------------------------------------------------------------
	public function account_update_details( WP_REST_Request $request ) {
		return $this->safely( 'account_update_details', function () use ( $request ) {
			return $this->account_update_details_inner( $request );
		} );
	}

	private function account_update_details_inner( WP_REST_Request $request ) {
		$device_token = $request->get_header( 'X-Dcard-Device-Token' );
		$user         = $device_token ? DCard_Users::get_by_device_token( $device_token ) : null;

		if ( ! $user ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'משתמש לא נמצא' ), 404 );
		}

		$name   = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$gender = (string) $request->get_param( 'gender' );
		$phone  = sanitize_text_field( (string) $request->get_param( 'phone' ) );

		// אותה ולידציית שם בדיוק כמו ב-/register (סעיף 6.2, DCARD_NAME_PATTERN).
		if ( '' !== $name && ! preg_match( self::NAME_PATTERN, $name ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'השם יכול לכלול רק אותיות, רווחים, מקפים וגרש.' ), 400 );
		}

		$updated = DCard_Users::update_profile( (int) $user['id'], $name, $gender, $phone );
		if ( ! $updated ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'שגיאה בשמירת הפרטים' ), 500 );
		}

		return new WP_REST_Response( array( 'success' => true, 'name' => $name, 'gender' => $gender, 'phone' => $phone ), 200 );
	}

	// ---------------------------------------------------------------
	// POST /account/cancel (§6.11 "ביטול המנוי")
	// ---------------------------------------------------------------
	public function account_cancel( WP_REST_Request $request ) {
		return $this->safely( 'account_cancel', function () use ( $request ) {
			return $this->account_cancel_inner( $request );
		} );
	}

	/**
	 * §6.11: שני מסלולים.
	 * 1) קריאה אמיתית ל-API של הוראות הקבע בקארדקום (cardcom_cancel_
	 *    recurring_order) - כשיש לנו terminal/API user/כתובת API (ר'
	 *    DCARD_CARDCOM_* ב-the-card-reader-core.php) וגם RecurringId שמור
	 *    למשתמש (cardcom_recurring_id, נשמר מה-IPN). הצלחה (ResponseCode=0)
	 *    => confirm_cancellation (cancelled_at+reason, לא נוגע ב-plan).
	 *    כישלון => לא נשמר כלום, מייל דחוף למנהל, ולמשתמש הודעת שגיאה -
	 *    "אסור להציג 'בוטל' אם החיוב לא באמת הופסק" (הנוסח המדויק מהספק).
	 * 2) מצב ביניים (אין terminal/API user, או שאין RecurringId שמור,
	 *    למשל משתמש ששילם לפני שההתראה הזו חוברה) - ההתנהגות הקודמת:
	 *    נשמרת רק הבקשה (cancel_requested_at+reason), ולמנהל נשלח מייל
	 *    "לבטל ידנית בקארדקום". ה-current_plan עצמו תמיד "נופל" ל-CANCELLED
	 *    רק כש-plan_expires_at עובר (ר' run_renewal_and_expiry_check).
	 */
	private function account_cancel_inner( WP_REST_Request $request ) {
		$device_token = $request->get_header( 'X-Dcard-Device-Token' );
		$user         = $device_token ? DCard_Users::get_by_device_token( $device_token ) : null;

		if ( ! $user ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'משתמש לא נמצא' ), 404 );
		}

		if ( DCard_Plans::FREE === $user['current_plan'] || DCard_Plans::CANCELLED === $user['current_plan'] ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'אין מנוי פעיל לביטול' ), 400 );
		}

		$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );

		$api_configured = DCARD_CARDCOM_API_URL && DCARD_CARDCOM_TERMINAL && DCARD_CARDCOM_API_USER;
		$recurring_id   = $user['cardcom_recurring_id'];

		if ( $api_configured && $recurring_id ) {
			$result = $this->cardcom_cancel_recurring_order( $recurring_id );

			if ( ! $result['success'] ) {
				// תוספת (אושרה): לעולם לא לרשום UserName ללוג - רק
				// ResponseCode ו-Description, שזה בדיוק מה ש-$result מכיל
				// (cardcom_cancel_recurring_order לא מחזירה כלום אחר).
				DCard_Logger::error( 'POST /account/cancel - Cardcom RecurringPayment cancel - non-success response', array(
					'user_id'       => (int) $user['id'],
					'recurring_id'  => $recurring_id,
					'response_code' => $result['response_code'],
					'description'   => $result['description'],
				) );

				// תוספת (אושרה): אם ResponseCode לא 0 כי ההוראה כבר לא
				// פעילה/כבר בוטלה אצל קארדקום (למשל ביטול כפול, או ביטול
				// שנעשה כבר ידנית), זה לא בהכרח כישלון אמיתי - אבל אי אפשר
				// לדעת את הניסוח המדויק של קארדקום בלי לראות תגובה אמיתית,
				// ולכן לא מנחשים כאן. מדווחים למנהל את ה-ResponseCode/
				// Description המדויקים כפי שחזרו, בלי לקבוע מראש "נכשל" -
				// ההחלטה אם זו בעצם הצלחה נשארת ידנית. למשתמש עדיין לא
				// מוצג "בוטל" (אסור להציג זאת בלי אישור), עד שיוחלט אחרת.
				$admin_email = get_option( 'admin_email' );
				if ( $admin_email ) {
					$body = '<div dir="rtl" style="font-family: Arial, Helvetica, sans-serif; max-width: 600px; margin: 0 auto; color: #222;">'
						. '<p>ניסיון ביטול אצל קארדקום עבור המשתמש ' . esc_html( $user['name'] ? $user['name'] : '(ללא שם)' ) . ' (' . esc_html( $user['email'] ) . '), RecurringId: ' . esc_html( $recurring_id ) . ', החזיר ResponseCode שאינו 0.</p>'
						. '<p style="line-height:1.8;">ResponseCode: ' . esc_html( $result['response_code'] ) . '<br />Description: ' . esc_html( $result['description'] ) . '</p>'
						. '<p>יכול להיות כישלון אמיתי, או שהוראת הקבע כבר לא פעילה/כבר בוטלה אצל קארדקום (ואז הביטול בעצם הושלם) - יש לבדוק בדשבורד קארדקום ולהחליט. אם ההוראה כבר לא פעילה שם, אפשר לסמן ידנית את הביטול אצלנו.</p>'
						. '</div>';
					wp_mail( $admin_email, 'הקורא בקלפים - לבדוק: תשובה לא-0 מקארדקום לביטול מנוי', $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
				}

				return new WP_REST_Response( array(
					'success' => false,
					'error'   => 'לא הצלחנו לבטל כרגע. אפשר לנסות שוב, או לכתוב לנו.',
				), 502 );
			}

			$saved = DCard_Users::confirm_cancellation( (int) $user['id'], $reason );
			if ( ! $saved ) {
				return new WP_REST_Response( array( 'success' => false, 'error' => 'שגיאה בשמירת הביטול' ), 500 );
			}

			DCard_Mailer::send_cancellation_admin_email( $user, $recurring_id, $reason, false );
			DCard_Mailer::send_cancellation_customer_email( $user );

			return new WP_REST_Response( array( 'success' => true, 'plan_expires_at' => $user['plan_expires_at'] ), 200 );
		}

		// מצב ביניים - אין terminal/API user מוגדרים, או שאין RecurringId
		// שמור למשתמש הזה.
		$saved = DCard_Users::request_cancellation( (int) $user['id'], $reason );
		if ( ! $saved ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'שגיאה בשמירת הבקשה' ), 500 );
		}

		DCard_Mailer::send_cancellation_admin_email( $user, $recurring_id, $reason, true );
		DCard_Mailer::send_cancellation_customer_email( $user );

		return new WP_REST_Response( array( 'success' => true, 'plan_expires_at' => $user['plan_expires_at'] ), 200 );
	}

	/**
	 * קריאה אמיתית ל-API של הוראות הקבע בקארדקום (docs/cardcom-api/):
	 * POST x-www-form-urlencoded ל-DCARD_CARDCOM_API_URL, תשובה בפורמט
	 * Name=Value&Name2=Value2 (לא JSON). נבדקו כל 44 הפרמטרים המתועדים -
	 * אין שם שדה סיסמה ב-API הזה בכלל, האימות הוא רק TerminalNumber+
	 * UserName - ולכן לא נשלח כאן DCARD_CARDCOM_API_PASS (שמור לשימוש
	 * עתידי אם יתברר אחרת). Operation=Update + RecurringId בלבד (בלי
	 * TotalNumOfBills או שדות אחרים) כי קארדקום מעדכנת רק את מה שנשלח.
	 */
	private function cardcom_cancel_recurring_order( $recurring_id ) {
		$response = wp_remote_post( DCARD_CARDCOM_API_URL, array(
			'timeout' => 20,
			'body'    => array(
				'TerminalNumber'                 => DCARD_CARDCOM_TERMINAL,
				'UserName'                       => DCARD_CARDCOM_API_USER,
				'codepage'                       => '65001',
				'Operation'                      => 'Update',
				'RecurringPayments.RecurringId'  => $recurring_id,
				'RecurringPayments.IsActive'     => 'false',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'response_code' => null, 'description' => $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$params = array();
		parse_str( $body, $params );

		$response_code = isset( $params['ResponseCode'] ) ? (string) $params['ResponseCode'] : null;
		$description   = isset( $params['Description'] ) ? $params['Description'] : '';

		// תוספת (אושרה): לכוון, ולא בטעות - ה-caller (account_cancel_inner)
		// רושם ללוג/למייל רק את מה שחוזר כאן, ולכן השומר הוא כאן: רק
		// ResponseCode ו-Description מוחזרים, לעולם לא $params המלא (שעלול
		// להכיל UserName אם קארדקום מהדהדת את הבקשה בתגובה) ולא TerminalNumber.
		return array(
			'success'       => ( '0' === $response_code ),
			'response_code' => $response_code,
			'description'   => $description,
		);
	}

	// ---------------------------------------------------------------
	// POST /account/update-email (§6.10.4 "תיקון כתובת המייל", 21.11)
	// ---------------------------------------------------------------
	public function account_update_email( WP_REST_Request $request ) {
		return $this->safely( 'account_update_email', function () use ( $request ) {
			return $this->account_update_email_inner( $request );
		} );
	}

	/**
	 * שלוש התנהגויות לפי הספק:
	 * - משתמש שעוד לא שילם אף פעם (אין עסקאות): עדכון מיידי בשרת, בלי
	 *   עבודה ידנית - אחרי בדיקה שהכתובת תקינה ולא שייכת למשתמש אחר.
	 * - משתמש ששילם בעבר: לא מעדכנים לבד (צריך לעדכן גם בקארדקום) -
	 *   נשלחת בקשה למנהל (כתובת ישנה+חדשה, מזהה משתמש), והמשתמש מקבל
	 *   "קיבלנו, נחזור אליך".
	 * - הכתובת החדשה כבר שייכת למשתמש אחר: נדחה, מופנה ל"כתבו לנו".
	 */
	private function account_update_email_inner( WP_REST_Request $request ) {
		$device_token = $request->get_header( 'X-Dcard-Device-Token' );
		$user         = $device_token ? DCard_Users::get_by_device_token( $device_token ) : null;

		if ( ! $user ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'משתמש לא נמצא' ), 404 );
		}

		$new_email = sanitize_email( (string) $request->get_param( 'new_email' ) );
		if ( ! $new_email || ! is_email( $new_email ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'כתובת מייל לא תקינה' ), 400 );
		}

		if ( strtolower( $new_email ) === strtolower( (string) $user['email'] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'זו כבר הכתובת הרשומה' ), 400 );
		}

		$existing = DCard_Users::get_by_email( $new_email );
		if ( $existing && (int) $existing['id'] !== (int) $user['id'] ) {
			return new WP_REST_Response( array(
				'success' => false,
				'error'   => 'already_registered',
				'message' => 'הכתובת הזו כבר רשומה. אם היא שלך, כתבו לנו.',
			), 409 );
		}

		$has_paid = DCard_Transactions::count_for_user( (int) $user['id'] ) > 0;

		if ( ! $has_paid ) {
			$updated = DCard_Users::update_email( (int) $user['id'], $new_email );
			if ( ! $updated ) {
				return new WP_REST_Response( array( 'success' => false, 'error' => 'שגיאה בעדכון הכתובת' ), 500 );
			}
			return new WP_REST_Response( array(
				'success'  => true,
				'mode'     => 'updated',
				'new_email' => $new_email,
				'message'  => 'הכתובת עודכנה ל־' . $new_email . '.',
			), 200 );
		}

		// שילם בעבר - צריך עדכון ידני גם בקארדקום, לא נוגעים ב-DB כאן.
		$admin_email = get_option( 'admin_email' );
		if ( $admin_email ) {
			wp_mail(
				$admin_email,
				'הקורא בקלפים - בקשה לתיקון כתובת מייל (משתמש ששילם בעבר)',
				self::wrap_admin_alert_html( sprintf(
					"המשתמש ביקש לתקן את כתובת המייל שלו. צריך לעדכן ידנית גם בקארדקום (הוא כבר שילם בעבר, לכן לא עודכן אוטומטית).\n\n"
					. "שם: %s\nמזהה: %d\nכתובת ישנה: %s\nכתובת חדשה מבוקשת: %s",
					$user['name'] ? $user['name'] : '(ללא שם)',
					(int) $user['id'],
					$user['email'],
					$new_email
				) ),
				array( 'Content-Type: text/html; charset=UTF-8' )
			);
		}

		return new WP_REST_Response( array(
			'success' => true,
			'mode'    => 'pending_admin',
			'message' => 'קיבלנו. נעדכן את הכתובת ונחזור אלייך/אליך.',
		), 200 );
	}

	// ---------------------------------------------------------------
	// POST /account/payment-delay-alert (§7.2ג, 21.11)
	// ---------------------------------------------------------------
	public function account_payment_delay_alert( WP_REST_Request $request ) {
		return $this->safely( 'account_payment_delay_alert', function () use ( $request ) {
			return $this->account_payment_delay_alert_inner( $request );
		} );
	}

	/**
	 * תיקון (21.11, החלפת §7.2ג): נשלח עכשיו ע"י kbQuietPlanPoll (פולינג שקט
	 * ברקע, לא חוסם) אחרי 5 דקות בלי payment_pending==false, לא ע"י חלון
	 * המתנה חוסם שבוטל. נשלח פעם אחת לכל המתנה (הקליינט כבר דואג לכך,
	 * kbPaymentDelayEmailSent), אז אין כאן rate-limiting נוסף כמו ב-
	 * notify_admin_of_webhook_rejection.
	 */
	private function account_payment_delay_alert_inner( WP_REST_Request $request ) {
		$device_token = $request->get_header( 'X-Dcard-Device-Token' );
		$user         = $device_token ? DCard_Users::get_by_device_token( $device_token ) : null;

		if ( ! $user ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'משתמש לא נמצא' ), 404 );
		}

		$admin_email = get_option( 'admin_email' );
		if ( $admin_email ) {
			wp_mail(
				$admin_email,
				'הקורא בקלפים - תשלום לא התעדכן אחרי 5 דקות',
				self::wrap_admin_alert_html( sprintf(
					"המשתמש חזר מתשלום בקארדקום, אבל המסלול שלו עדיין לא התעדכן אחרי 5 דקות המתנה ברקע. כדאי לבדוק את ה-IPN/הלוג של קארדקום למשתמש הזה. בינתיים המשתמש לא נחסם - יש לו מעבר חד-פעמי לשיחה אחת (ר' DCard_Users::has_credits_or_pending_pass).\n\n"
					. "שם: %s\nמייל: %s\nמזהה: %d",
					$user['name'] ? $user['name'] : '(ללא שם)',
					$user['email'],
					(int) $user['id']
				) ),
				array( 'Content-Type: text/html; charset=UTF-8' )
			);
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	// ---------------------------------------------------------------
	// POST /account/payment-pending-start (21.11)
	// ---------------------------------------------------------------
	public function account_payment_pending_start( WP_REST_Request $request ) {
		return $this->safely( 'account_payment_pending_start', function () use ( $request ) {
			return $this->account_payment_pending_start_inner( $request );
		} );
	}

	/**
	 * נקרא מ-kbPaymentSubmit ממש לפני הניווט לקארדקום - שרת, לא רק
	 * localStorage, כדי ש"שיחה אחת חינם"/"אל תפתח שוב את חלון התשלום"
	 * יהיו תקפים גם אם ה-IPN יגיע לפני שהמשתמש בכלל חוזר לדף הזה.
	 */
	private function account_payment_pending_start_inner( WP_REST_Request $request ) {
		$device_token = $request->get_header( 'X-Dcard-Device-Token' );
		$user         = $device_token ? DCard_Users::get_by_device_token( $device_token ) : null;

		if ( ! $user ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'משתמש לא נמצא' ), 404 );
		}

		DCard_Users::start_payment_pending( (int) $user['id'] );

		return new WP_REST_Response( array( 'success' => true ), 200 );
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
	 * תיקון (דווח בפועל: "חותך את השיחה בצורה לא טבעית... גם לא הייתה
	 * אינדיקציה מצד המשתמש לסיום") - CARD_TURN_FLOOR_INSTRUCTION (ר' למעלה)
	 * מבקשת יפה מהמודל לא לסגור בתגובת ההעמקה הראשונה, אבל בקשה בפרומפט
	 * בלבד היא בדיוק מה שנכשל כאן מלכתחילה (שלב ט' נתן למודל שיקול דעת
	 * רחב מדי לזהות "סימן הבנה" גם מתשובה קצרה/מהוססת אחת). אותו עיקרון
	 * בדיוק כמו enforce_end_session_consistency למעלה: רשת ביטחון בשרת,
	 * לא תלויה בציות המודל. לא נוגעים בטקסט של speak עצמו (גם אם הוא נשמע
	 * כמו סיכום) - רק מוודאים ש-end_session נשאר false כדי שתיבת הכתיבה לא
	 * תיסגר ולמשתמש תישאר דרך אמיתית להמשיך.
	 */
	private function enforce_card_turn_floor( array $data, $new_card_turn_count, $is_final_turn ) {
		if ( $is_final_turn || $new_card_turn_count >= self::CARD_TURN_FLOOR ) {
			return $data;
		}
		if ( ! empty( $data['end_session'] ) ) {
			DCard_Logger::warning( 'Model set end_session=true before CARD_TURN_FLOOR reflection turns - overriding to false', array(
				'new_card_turn_count' => $new_card_turn_count,
				'speak'               => mb_substr( (string) $data['speak'], 0, 300 ),
			) );
			$data['end_session'] = false;
		}
		return $data;
	}

	/**
	 * Server-side enforcement of the MESSAGE_MODE_INSTRUCTION "no questions"
	 * rule (seen live: the prompt alone wasn't enough - the model still
	 * sometimes opened with "מה עולה בך כשאת מסתכלת על הקלף?" even though
	 * the closing-line enforcement above already fixed the missing summary
	 * sentence separately). Splits on sentence-ending punctuation/newlines
	 * and drops any sentence that contains a "?", rather than just stripping
	 * the mark itself - leaving the mark out but the question's wording in
	 * would still read as a question.
	 */
	private static function strip_question_sentences( $text ) {
		preg_match_all( '/[^.!?\n]+[.!?\n]*/u', $text, $matches );
		$kept = array();
		foreach ( $matches[0] as $sentence ) {
			if ( false === strpos( $sentence, '?' ) ) {
				$kept[] = $sentence;
			}
		}
		$result = trim( implode( '', $kept ) );
		return preg_replace( '/\n{3,}/u', "\n\n", $result );
	}

	/**
	 * "Real reading" threshold for message mode, used to decide whether a
	 * post-stripping result is worth keeping or needs a retry (ר' ההערה ליד
	 * MESSAGE_MODE_RETRY_EMPHASIS). A loose length check, not real sentence
	 * parsing - just enough to catch "one leftover fragment" / "empty".
	 */
	private static function is_valid_message_mode_speak( $text ) {
		return is_string( $text ) && mb_strlen( trim( $text ) ) >= 80;
	}

	/**
	 * עלות משוערת (USD) לקריאת AI בודדת, לפי תעריפי data/ai-models.json
	 * (ר' DCard_Settings::get_model_price()). מחושב ונשמר *בזמן הקריאה
	 * עצמה* (snapshot) - לא בזמן הפקת דוח - כדי שעלויות היסטוריות יישארו
	 * נכונות גם אם תעריפים ישתנו בעתיד. '' (לא 0) כשאין תעריף למודל הזה -
	 * מבדיל בין "עלה 0$" לבין "לא נמדד", חשוב כדי לא להטעות בסיכומים.
	 */
	private static function estimate_cost( $model_id, $tokens_in, $tokens_out ) {
		$price = DCard_Settings::get_model_price( $model_id );
		if ( null === $price ) {
			// תיקון אבחון (דווח בפועל: "Cost $" ריק ב-Google Sheets ל-gpt-6.1-sol
			// למרות ש-data/ai-models.json על השרת החי מכיל מחיר תקין לו") - עד
			// כה הנתיב הזה היה שקט לגמרי, בלי שום עקבה בלוג. רושם כאן את
			// המזהה המדויק שהגיע לכאן (מרכאות כדי לחשוף רווח/תו נסתר) מול כל
			// המפתחות שבאמת נטענו מהקובץ ברגע הזה - אם המודל שנבחר בפועל לא
			// מופיע ברשימה הזו, זה מוכיח אי-התאמה במזהה (לא בעיית תעריף).
			DCard_Logger::warning( 'No price found for model - cost_usd will be blank', array(
				'model_id'            => '"' . $model_id . '"',
				'loaded_model_ids'    => array_keys( DCard_Settings::get_model_choices() ),
				'model_choices_errors'=> DCard_Settings::get_model_choices_errors(),
			) );
			return '';
		}
		$cost = ( $tokens_in / 1000000 ) * $price['input'] + ( $tokens_out / 1000000 ) * $price['output'];
		return round( $cost, 6 );
	}

	/** נשמר פעם אחת ב-/session/start (ר' ההערה שם) - קריאה בלבד מכאן. */
	private static function session_platform( array $session ) {
		return isset( $session['state']['platform'] ) && is_string( $session['state']['platform'] )
			? $session['state']['platform']
			: '';
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

	/**
	 * Design-spec §7.2: picks the drawn card itself, server-side. $excluded
	 * holds ids that must not come up (this session's own cards, plus the
	 * client's same-day recent-history hint - see session_card_selected_inner()).
	 * Falls back to ignoring the exclusion list only if it somehow covers
	 * every existing card (never happens in practice: at most ~3 session ids
	 * + ~15 recent-history ids against 72 cards), so a draw can never dead-end.
	 */
	private function pick_random_card_id( array $excluded ) {
		$available = array();
		for ( $i = 1; $i <= 72; $i++ ) {
			if ( DCard_Cards::exists( $i ) && ! in_array( $i, $excluded, true ) ) {
				$available[] = $i;
			}
		}
		if ( empty( $available ) ) {
			for ( $i = 1; $i <= 72; $i++ ) {
				if ( DCard_Cards::exists( $i ) ) {
					$available[] = $i;
				}
			}
		}
		return $available[ random_int( 0, count( $available ) - 1 ) ];
	}

	/**
	 * The frontend never builds this path itself (design-spec §7.2: it only
	 * ever receives { id, image_url, alt } from the server) - same filename
	 * convention the old client-side draw used (im_01.jpg..im_72.jpg),
	 * just built here now that the id is picked here.
	 */
	private function card_image_url( $card_id ) {
		return home_url( '/wp-content/uploads/im_' . str_pad( (string) $card_id, 2, '0', STR_PAD_LEFT ) . '.jpg' );
	}
}
