<?php
namespace TheCardReaderCore;

defined( 'ABSPATH' ) || exit;

class API_Feedback {

    public function init() {
        add_action( 'rest_api_init', [ $this, 'register_feedback_routes' ] );
    }

    public function register_feedback_routes() {
        // ראוט ל-Micro Feedback (לייק/דיסלייק על הודעה ספציפית)
        register_rest_route( 'dcard/v1', '/feedback/micro', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_micro_feedback' ],
            'permission_callback' => '__return_true', // בהמשך נוסיף ולידציה של Nonce לביטחון
        ]);

        // ראוט ל-Macro Feedback (משוב כללי / "חשוב לי לדעת")
        register_rest_route( 'dcard/v1', '/feedback/macro', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_macro_feedback' ],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_micro_feedback( \WP_REST_Request $request ) {
        $params = $request->get_json_params();

        // איסוף הנתונים מהבקשה
        $session_token = sanitize_text_field( $params['session_id'] ?? '' );
        $message_id    = sanitize_text_field( $params['message_id'] ?? '' );
        $feedback      = sanitize_text_field( $params['feedback'] ?? '' ); // "like" or "dislike"

        if ( empty( $session_token ) || empty( $message_id ) || empty( $feedback ) ) {
            return new \WP_Error( 'missing_data', 'Missing required feedback data', [ 'status' => 400 ] );
        }

        // תיקון (התבקש: "הייתי רוצה מקום אחד שבו יש לי את הנתונים ולא לדלג
        // בין קבצים שונים") - עד כה המשוב (מיקרו וגם מאקרו למטה) נשלח
        // ל-Make webhook נפרד, מקודד בקוד (\DCard_Settings::get_analytics_
        // webhook_url() לא הייתה מעורבת כלל) - לא ברור אם התרחיש שם בכלל
        // עדיין פעיל, ובכל מקרה זה פיצל את הנתונים לגיליון שונה מכל שאר
        // ה-Analytics. עכשיו הולך דרך אותו \DCard_Analytics::track() כמו
        // card_drawn/session_completed - אותו Webhook URL (Settings > DCard
        // AI), אותו גיליון, מזוהה ב-Event כ-feedback_micro/feedback_macro.
        $session = \DCard_DB::get_session( $session_token );
        \DCard_Analytics::track( 'feedback_micro', array(
            'session_token' => $session_token,
            'user_name'     => $session ? $session['user_name'] : null,
            'message_id'    => $message_id,
            'feedback'      => $feedback,
        ) );

        return rest_ensure_response( [ 'success' => true ] );
    }

    public function handle_macro_feedback( \WP_REST_Request $request ) {
        $params = $request->get_json_params();

        $session_token = sanitize_text_field( $params['session_id'] ?? '' );
        $text          = sanitize_textarea_field( $params['text'] ?? '' );

        if ( empty( $session_token ) || empty( $text ) ) {
            return new \WP_Error( 'missing_data', 'Missing session ID or text', [ 'status' => 400 ] );
        }

        // ר' ההערה המלאה ב-handle_micro_feedback למעלה.
        $session = \DCard_DB::get_session( $session_token );
        \DCard_Analytics::track( 'feedback_macro', array(
            'session_token' => $session_token,
            'user_name'     => $session ? $session['user_name'] : null,
            'feedback_text' => $text,
        ) );

        return rest_ensure_response( [ 'success' => true ] );
    }
}