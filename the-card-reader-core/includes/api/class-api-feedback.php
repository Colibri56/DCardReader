<?php
namespace TheCardReaderCore;

defined( 'ABSPATH' ) || exit;

class API_Feedback {

    // כאן נגדיר את כתובת ה-Webhook של Make.com שאליה נשלח את הנתונים
    // (נחליף את זה לכתובת האמיתית כשתייצר את ה-Webhook ב-Make)
    private $make_webhook_url = 'https://hook.eu1.make.com/jsyanwpjjly2lgm6x7bhqu97hpn6kzxi';

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
        $session_id = sanitize_text_field( $params['session_id'] ?? '' );
        $message_id = sanitize_text_field( $params['message_id'] ?? '' );
        $feedback   = sanitize_text_field( $params['feedback'] ?? '' ); // "like" or "dislike"

        if ( empty( $session_id ) || empty( $message_id ) || empty( $feedback ) ) {
            return new \WP_Error( 'missing_data', 'Missing required feedback data', [ 'status' => 400 ] );
        }

        // שליחת הנתונים ל-Make.com
        $response = wp_remote_post( $this->make_webhook_url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode([
                'type'       => 'micro',
                'session_id' => $session_id,
                'message_id' => $message_id,
                'feedback'   => $feedback,
                'timestamp'  => current_time('mysql', 1)
            ]),
        ]);

        return rest_ensure_response( [ 'success' => true, 'message' => 'Micro feedback sent to Make' ] );
    }

    public function handle_macro_feedback( \WP_REST_Request $request ) {
        $params = $request->get_json_params();

        $session_id = sanitize_text_field( $params['session_id'] ?? '' );
        $text       = sanitize_textarea_field( $params['text'] ?? '' );

        if ( empty( $session_id ) || empty( $text ) ) {
            return new \WP_Error( 'missing_data', 'Missing session ID or text', [ 'status' => 400 ] );
        }

        $response = wp_remote_post( $this->make_webhook_url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode([
                'type'       => 'macro',
                'session_id' => $session_id,
                'text'       => $text,
                'timestamp'  => current_time('mysql', 1)
            ]),
        ]);

        return rest_ensure_response( [ 'success' => true, 'message' => 'Macro feedback sent to Make' ] );
    }
}