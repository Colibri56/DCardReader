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

	/**
	 * סעיף 6.10.4: תוכן חלון "כתבו לנו" - אל doar@nuriteldar.co.il, עם
	 * Reply-To של המשתמש כך שלחיצה על "השב" עונה לו ישירות.
	 */
	public static function send_contact_message( $user_email, $user_name, $message, $gender, $plan_label, $user_id, $platform, $subject_suffix = '' ) {
		// §6.10.2: קישור "אם כתובת המייל שגויה" בחלון ההצטרפות שולח לכאן
		// עם subject_suffix='– תיקון מייל', כדי שהפנייה תיראה שונה מתוך
		// הקישור הכללי "כתבו לנו" כבר בשורת הנושא, בלי לפתוח endpoint נפרד.
		$subject = '** כתבו לנו - הקורא בקלפים ' . ( $subject_suffix ? $subject_suffix . ' ' : '' ) . '**';
		$body    = self::render_contact_html( $user_email, $user_name, $message, $gender, $plan_label, $user_id, $platform );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'Reply-To: ' . $user_email,
		);

		return wp_mail( 'doar@nuriteldar.co.il', $subject, $body, $headers );
	}

	/**
	 * §6.12 "כללי כל המיילים": "תאריכים בפורמט 21.12.2026, בלי שעה."
	 * $utc_datetime מגיע כפי שנשמר ב-DB (current_time('mysql', true), UTC) -
	 * מומר לאזור הזמן של האתר (date_i18n) לפני התצוגה.
	 */
	private static function format_date_only( $utc_datetime ) {
		if ( ! $utc_datetime ) {
			return '';
		}
		return date_i18n( 'd.m.Y', strtotime( $utc_datetime . ' UTC' ) );
	}

	/**
	 * §6.12 "מייל למנהל: ביטול מנוי" - גם למסלול האמיתי (קארדקום אישרה,
	 * $manual_mode=false) וגם למצב הביניים (אין עדיין terminal/API user/
	 * RecurringId שמור, $manual_mode=true - ר' account_cancel_inner
	 * ב-class-dcard-rest-controller.php). בהבדל יחיד: השורה הראשונה,
	 * ושורת "הוראת קבע" (במצב ביניים אין עדיין אישור שהיא נעצרה בפועל).
	 */
	public static function send_cancellation_admin_email( array $user, $recurring_id, $reason, $manual_mode ) {
		$admin_email = get_option( 'admin_email' );
		if ( ! $admin_email ) {
			return false;
		}

		$name       = $user['name'] ? $user['name'] : '(ללא שם)';
		$plan_label = DCard_Plans::label_for_plan( $user['current_plan'] );
		$subject    = 'הקורא בקלפים – ביטול מנוי: ' . ( $user['name'] ? $user['name'] : $user['email'] ) . ' (' . $plan_label . ')';

		$first_line = $manual_mode
			? 'לבצע ידנית: לבטל בקארדקום את הוראת הקבע של ' . $user['email'] . '.'
			: 'ביטול מנוי בוצע אוטומטית בקארדקום.';

		$recurring_line = $manual_mode
			? 'הוראת קבע: ' . ( $recurring_id ? esc_html( $recurring_id ) : 'אין מזהה שמור' )
			: 'הוראת קבע: ' . esc_html( $recurring_id ) . ' (נעצרה)';

		ob_start();
		?>
		<div dir="rtl" style="font-family: Arial, Helvetica, sans-serif; max-width: 600px; margin: 0 auto; color: #222;">
			<p><?php echo esc_html( $first_line ); ?></p>
			<p style="line-height: 1.8;">
				שם: <?php echo esc_html( $name ); ?><br />
				מייל: <?php echo esc_html( $user['email'] ); ?><br />
				מסלול: <?php echo esc_html( $plan_label ); ?><br />
				<?php echo $recurring_line; ?><br />
				פעיל עד: <?php echo esc_html( self::format_date_only( $user['plan_expires_at'] ) ); ?><br />
				סיבה: <?php echo esc_html( $reason ? $reason : 'לא צוינה' ); ?>
			</p>
		</div>
		<?php
		$body    = ob_get_clean();
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		return wp_mail( $admin_email, $subject, $body, $headers );
	}

	/**
	 * §6.12 "מייל ללקוח: ביטול מנוי" - אותה תבנית בשני המסלולים (אמיתי/
	 * מצב ביניים), כי מנקודת המבט של הלקוח התוצאה זהה: הבקשה התקבלה,
	 * הגישה ממשיכה עד התאריך.
	 */
	public static function send_cancellation_customer_email( array $user ) {
		if ( ! $user['email'] ) {
			return false;
		}

		$subject    = 'הקורא בקלפים – המנוי שלך בוטל';
		$expires_at = self::format_date_only( $user['plan_expires_at'] );

		ob_start();
		?>
		<div dir="rtl" style="font-family: Arial, Helvetica, sans-serif; max-width: 600px; margin: 0 auto; color: #222;">
			<p>שלום <?php echo esc_html( $user['name'] ? $user['name'] : '' ); ?>,</p>
			<p>לבקשתך, המנוי שלך בוטל.</p>
			<p>אפשר להמשיך להשתמש באפליקציה עד <?php echo esc_html( $expires_at ); ?>, ולא יהיו חיובים נוספים.</p>
			<p>אם תרצה/י לחזור, אפשר להצטרף מחדש בכל עת מתוך האפליקציה.</p>
			<p>תודה שהיית/ת איתנו,<br />הקורא בקלפים</p>
		</div>
		<?php
		$body    = ob_get_clean();
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		return wp_mail( $user['email'], $subject, $body, $headers );
	}

	/**
	 * §6.12 "מייל ללקוח: מחיקת חשבון". $had_active_plan - האם היה מנוי
	 * פעיל שבוטל כחלק מהמחיקה (קובע את המשפט השני המותנה).
	 */
	public static function send_deletion_customer_email( $email, $name, $had_active_plan ) {
		if ( ! $email ) {
			return false;
		}

		$subject = 'הקורא בקלפים – החשבון שלך נמחק';

		ob_start();
		?>
		<div dir="rtl" style="font-family: Arial, Helvetica, sans-serif; max-width: 600px; margin: 0 auto; color: #222;">
			<p>שלום <?php echo esc_html( $name ? $name : '' ); ?>,</p>
			<p>
				לבקשתך, החשבון שלך נמחק מהאפליקציה.
				<?php if ( $had_active_plan ) : ?>המנוי בוטל, ולא יהיו חיובים נוספים.<?php endif; ?>
			</p>
			<p>פרטי התשלומים נשמרים כנדרש בחוק, לצורך החשבוניות בלבד.</p>
			<p>תודה שהיית/ת איתנו,<br />הקורא בקלפים</p>
		</div>
		<?php
		$body    = ob_get_clean();
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		return wp_mail( $email, $subject, $body, $headers );
	}

	/**
	 * §6.12 "מייל למנהל: מחיקת חשבון".
	 */
	public static function send_deletion_admin_email( $email, $last_plan_label, $recurring_id_cancelled ) {
		$admin_email = get_option( 'admin_email' );
		if ( ! $admin_email ) {
			return false;
		}

		$subject = 'הקורא בקלפים – מחיקת חשבון: ' . $email;
		$date    = self::format_date_only( current_time( 'mysql', true ) );

		ob_start();
		?>
		<div dir="rtl" style="font-family: Arial, Helvetica, sans-serif; max-width: 600px; margin: 0 auto; color: #222;">
			<p style="line-height: 1.8;">
				מייל: <?php echo esc_html( $email ); ?><br />
				מסלול אחרון: <?php echo esc_html( $last_plan_label ); ?><br />
				בוטל מנוי: <?php echo $recurring_id_cancelled ? 'כן (RecurringId: ' . esc_html( $recurring_id_cancelled ) . ')' : 'לא (לא היה מנוי פעיל)'; ?><br />
				תאריך: <?php echo esc_html( $date ); ?>
			</p>
		</div>
		<?php
		$body    = ob_get_clean();
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		return wp_mail( $admin_email, $subject, $body, $headers );
	}

	private static function render_contact_html( $user_email, $user_name, $message, $gender, $plan_label, $user_id, $platform ) {
		$gender_label = ( 'female' === $gender ) ? 'נקבה' : ( ( 'male' === $gender ) ? 'זכר' : '-' );
		ob_start();
		?>
		<div dir="rtl" style="font-family: Arial, Helvetica, sans-serif; max-width: 600px; margin: 0 auto; color: #222;">
			<h2>כתבו לנו</h2>
			<p><?php echo nl2br( esc_html( $message ) ); ?></p>
			<hr style="border: none; border-top: 1px solid #ddd; margin: 16px 0;" />
			<p style="font-size: 13px; color: #555; line-height: 1.6;">
				שם: <?php echo esc_html( $user_name ? $user_name : '-' ); ?><br />
				מייל: <?php echo esc_html( $user_email ); ?><br />
				לשון פנייה: <?php echo esc_html( $gender_label ); ?><br />
				מסלול: <?php echo esc_html( $plan_label ? $plan_label : '-' ); ?><br />
				מזהה משתמש: <?php echo esc_html( $user_id ? (string) $user_id : '-' ); ?><br />
				דפדפן/מכשיר: <?php echo esc_html( $platform ? $platform : '-' ); ?>
			</p>
		</div>
		<?php
		return ob_get_clean();
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
