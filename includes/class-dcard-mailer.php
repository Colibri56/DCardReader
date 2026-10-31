<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DCard_Mailer {

	public static function send_transcript( $to_email, $user_name, array $transcript, array $card_image_urls = array(), $is_message_mode = false, $session_token = '', $user_phone = '' ) {
		$subject = 'הקורא בקלפים - סיכום השיחה שלך';
		$body    = self::render_html( $user_name, $transcript, $card_image_urls, $is_message_mode, $session_token, $to_email, $user_phone );

		// Already HTML (not plain text) - wp_mail() defaults to text/plain otherwise.
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		return wp_mail( $to_email, $subject, $body, $headers );
	}

	private static function render_html( $user_name, array $transcript, array $card_image_urls = array(), $is_message_mode = false, $session_token = '', $user_email = '', $user_phone = '' ) {
		$greeting = $user_name ? esc_html( $user_name ) : '';
		$intro    = $is_message_mode ? 'מצורף המסר שהתקבל לשאלתך:' : 'מצורף תרשים השיחה שניהלת לאור שאלתך:';

		ob_start();
		?>
		<div dir="rtl" style="font-family: Arial, Helvetica, sans-serif; max-width: 600px; margin: 0 auto; color: #222;">
			<h2>הקורא בקלפים</h2>
			<?php if ( $card_image_urls ) : ?>
				<div style="text-align: center; margin: 10px 0 16px;">
					<?php foreach ( $card_image_urls as $url ) : ?>
						<img src="<?php echo esc_url( $url ); ?>" alt="קלף שנשלף" style="max-width: 200px; width: 100%; height: auto; border-radius: 8px; margin: 0 6px 10px; display: inline-block;" />
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( $greeting ) : ?>
				<p>שלום <?php echo $greeting; ?>, <?php echo esc_html( $intro ); ?></p>
			<?php else : ?>
				<p><?php echo esc_html( $intro ); ?></p>
			<?php endif; ?>
			<hr style="border: none; border-top: 1px solid #ddd; margin: 16px 0;" />
			<?php foreach ( $transcript as $entry ) :
				$role = isset( $entry['role'] ) ? $entry['role'] : 'user';
				$text = isset( $entry['text'] ) ? $entry['text'] : '';
				if ( '' === trim( (string) $text ) ) {
					continue;
				}
				// 'system' entries are internal bookkeeping (e.g. "קלף נבחר: #45")
				// appended by class-dcard-rest-controller.php for our own records -
				// never meant to be read by the user. The card image is already
				// shown above, so the number adds nothing.
				if ( 'system' === $role ) {
					continue;
				}
				// 'error' (class-dcard-rest-controller.php's AI_ERROR_ROLE) marks
				// a turn that failed - rendered distinctly (centered, amber
				// warning background) so it reads unmistakably as "the reading
				// was interrupted here", not as a normal reply from either side.
				if ( 'error' === $role ) :
					?>
					<div style="margin: 10px 0; text-align: center;">
						<div style="display: inline-block; padding: 8px 14px; border-radius: 10px; max-width: 80%; background: #fdecea; color: #8a4b00; border: 1px solid #f3c9a0;">
							⚠ <?php echo nl2br( esc_html( $text ) ); ?>
						</div>
					</div>
					<?php
					continue;
				endif;
				$is_user = ( 'user' === $role );
				?>
				<div style="margin: 10px 0; text-align: <?php echo $is_user ? 'left' : 'right'; ?>;">
					<div style="display: inline-block; padding: 8px 14px; border-radius: 10px; max-width: 80%; background: <?php echo $is_user ? '#eef2f7' : '#f6efe6'; ?>;">
						<?php echo nl2br( esc_html( $text ) ); ?>
					</div>
				</div>
			<?php endforeach; ?>
			<hr style="border: none; border-top: 1px solid #ddd; margin: 16px 0;" />
			<?php
			$marketing_text  = trim( (string) DCard_Settings::get_marketing_text() );
			$marketing_image = DCard_Settings::get_marketing_image_url();
			if ( $marketing_text || $marketing_image ) :
				?>
				<div style="background: #faf6f0; border-radius: 10px; padding: 16px 18px; margin: 0 0 16px;">
					<?php if ( $marketing_image ) : ?>
						<div style="text-align: center; margin-bottom: 12px;">
							<img src="<?php echo esc_url( $marketing_image ); ?>" alt="ערכת הקלפים" style="max-width: 180px; width: 100%; height: auto; border-radius: 8px;" />
						</div>
					<?php endif; ?>
					<?php if ( $marketing_text ) : ?>
						<p style="font-size: 13px; color: #555; line-height: 1.6; margin: 0;"><?php echo wp_kses_post( nl2br( $marketing_text ) ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<hr style="border: none; border-top: 1px solid #ddd; margin: 16px 0;" />
			<p style="font-size: 12px; color: #888;">התקבל אוטומטית מ"הקורא בקלפים".</p>
			<?php
			$feedback_url = DCard_Settings::get_feedback_form_url();
			if ( $feedback_url ) :
				// מעבירים name/email/phone/session_id כפרמטרים סמויים ב-URL
				// כדי ש-Fillout ימלא אותם מראש בשדות המתאימים, בלי שהמשתמש
				// יזין אותם שוב. זה עובד רק אם בטופס עצמו, בכל שדה (שם/
				// אימייל/טלפון), מגדירים ב-Fillout: "..." ליד השדה -> Prefill
				// via URL -> ומזינים בדיוק את אותו שם פרמטר (name/email/phone)
				// שמופיע כאן - אחרת Fillout פשוט מתעלם מהפרמטר. session_id
				// (not a separate numeric id - it's the one identifier this
				// plugin uses everywhere, including the 'session_token' field
				// on the analytics webhook's own session_completed event) is
				// for correlating feedback with the session, not for
				// prefilling a visible field. add_query_arg() handles both a
				// bare URL and one that already has its own query string.
				$feedback_url_with_session = $feedback_url;
				$feedback_params            = array(
					'session_id' => $session_token,
					'name'       => $user_name,
					'email'      => $user_email,
					'phone'      => $user_phone,
				);
				foreach ( $feedback_params as $param => $value ) {
					if ( '' !== trim( (string) $value ) ) {
						// add_query_arg() לא עושה urlencode לערך (ר' תיעוד WP) -
						// קריטי כאן כי שם בעברית/רווחים/+ בטלפון היו שוברים את
						// ה-query string בלי rawurlencode מפורש.
						$feedback_url_with_session = add_query_arg( $param, rawurlencode( (string) $value ), $feedback_url_with_session );
					}
				}
				?>
				<div style="text-align: center; background: #f6efe6; border-radius: 10px; padding: 20px 18px; margin: 16px 0 0;">
					<p style="font-size: 14px; color: #444; margin: 0 0 12px;">רוצה לשתף אותי בחוויה שלך?</p>
					<a href="<?php echo esc_url( $feedback_url_with_session ); ?>" style="display: inline-block; background: #8B1E3F; color: #fff; text-decoration: none; font-weight: bold; padding: 10px 24px; border-radius: 30px; font-size: 14px;">כן, אשמח לשתף</a>			
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
