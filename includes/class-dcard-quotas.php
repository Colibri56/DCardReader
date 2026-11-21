<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Freemium PLG funnel, step 1: usage quotas for anonymous vs. registered
 * users. This class owns the admin settings page ("ניהול מכסות") and the
 * wp_options the limits/plan metadata live in, plus localizing the
 * ANONYMOUS limits + payment settings to the frontend. The actual
 * tracking/gatekeeping for anonymous users (localStorage counters +
 * triggerRegistrationWall()) lives in frontend/elementor-code.html; the
 * registered-user limits below ARE enforced server-side (ר' DCard_Users
 * ו-DCard_Plans).
 *
 * תיקון §6.10.2 ("ניהול · דף המכסות: מסלולים ומחירים") - כל מסלול בתשלום
 * (נרקיס/נורית/נורית שנתי - עדיין 3 שורות קבועות, לא "הוספת מסלול" גנרית)
 * מקבל עכשיו גם: פעיל, שם לתצוגה, מחיר, "להציג ללא הגבלה" (נפרד למסר/
 * דיאלוג, ר' DCard_Plans::is_unlimited), ו-ProductID בקארדקום.
 * כתובת התשלום המשותפת ("עמוד התשלום") עברה לכאן מקוד קשיח ב-JS.
 *
 * עדכון §6.10.2 (דווח בפועל: לקוח חויב ₪467 וקיבל מסלול שונה ממה שציפה) -
 * כל המסלולים מוצגים עכשיו כ"חודשיים" בלבד ("לחודש") - אין יותר שדה/
 * עמודת "תקופה" ואין חישוב חיסכון שנתי ("לשנה, במקום X, חיסכון Y%") - ר'
 * ההסבר המלא ב-render_preview_script(). זו רק הצגה (לא שינוי בפועל
 * ל-DCard_Plans::plan_duration_days/plan_period_days, שנשארים כפי שהיו).
 */
class DCard_Quotas {

	const OPTION_GROUP = 'dcard_quotas_settings';

	const OPTION_ANON_MSG_LIMIT  = 'dcard_anon_msg_limit';
	const OPTION_ANON_CHAT_LIMIT = 'dcard_anon_chat_limit';
	const OPTION_REG_MSG_LIMIT   = 'dcard_reg_msg_limit';
	const OPTION_REG_CHAT_LIMIT  = 'dcard_reg_chat_limit';

	const OPTION_DAILY_AI_CALL_LIMIT = 'dcard_daily_ai_call_limit';
	const OPTION_MONTHLY_AI_BUDGET   = 'dcard_monthly_ai_budget_usd';

	// סעיף 8 (design-spec): "חלון ההרשמה בתשלום מבוטל זמנית לנסיינים" - מתג
	// בעמוד הזה, לא קבוע בקוד. כבוי כברירת מחדל בתקופת הבדיקות.
	const OPTION_PAYWALL_ENABLED = 'dcard_paywall_enabled';
	const DEFAULT_PAYWALL_ENABLED = false;

	// §6.10.2: כתובת התשלום המשותפת - מחליפה את ה-CARDCOM_PAYMENT_URL
	// שהיה קשיח ב-JS. שדה אחד בלבד - בחירת המסלול נעשית בכפתורי רדיו
	// בעמוד התשלום של קארדקום עצמו, לא ב-URL.
	const OPTION_CARDCOM_PAYMENT_URL = 'dcard_cardcom_payment_url';
	const DEFAULT_CARDCOM_PAYMENT_URL = 'https://secure.cardcom.solutions/EA/EA5/fxGKTgwmtUyAFyQOvObXIA/PaymentSP';

	// קרדיטים לכל מסלול בתשלום, מפוצלים מסר/שיחה מפתחת (ר' הערת ה-class
	// למעלה).
	const OPTION_NARKIS_MSG_CREDITS        = 'dcard_narkis_msg_credits';
	const OPTION_NARKIS_CHAT_CREDITS       = 'dcard_narkis_chat_credits';
	const OPTION_NURIT_MSG_CREDITS         = 'dcard_nurit_msg_credits';
	const OPTION_NURIT_CHAT_CREDITS        = 'dcard_nurit_chat_credits';
	const OPTION_NURIT_YEARLY_MSG_CREDITS  = 'dcard_nurit_yearly_msg_credits';
	const OPTION_NURIT_YEARLY_CHAT_CREDITS = 'dcard_nurit_yearly_chat_credits';

	// §6.10.2 - שדות חדשים לכל מסלול בתשלום: פעיל / שם לתצוגה / מחיר /
	// "להציג ללא הגבלה" (מסר, דיאלוג בנפרד) / ProductID בקארדקום.
	const OPTION_NARKIS_ACTIVE              = 'dcard_narkis_active';
	const OPTION_NARKIS_LABEL               = 'dcard_narkis_label';
	const OPTION_NARKIS_PRICE               = 'dcard_narkis_price';
	const OPTION_NARKIS_UNLIMITED_MSG       = 'dcard_narkis_unlimited_msg';
	const OPTION_NARKIS_UNLIMITED_CHAT      = 'dcard_narkis_unlimited_chat';
	const OPTION_NARKIS_PRODUCT_ID          = 'dcard_narkis_product_id';

	const OPTION_NURIT_ACTIVE               = 'dcard_nurit_active';
	const OPTION_NURIT_LABEL                = 'dcard_nurit_label';
	const OPTION_NURIT_PRICE                = 'dcard_nurit_price';
	const OPTION_NURIT_UNLIMITED_MSG        = 'dcard_nurit_unlimited_msg';
	const OPTION_NURIT_UNLIMITED_CHAT       = 'dcard_nurit_unlimited_chat';
	const OPTION_NURIT_PRODUCT_ID           = 'dcard_nurit_product_id';

	const OPTION_NURIT_YEARLY_ACTIVE          = 'dcard_nurit_yearly_active';
	const OPTION_NURIT_YEARLY_LABEL           = 'dcard_nurit_yearly_label';
	const OPTION_NURIT_YEARLY_PRICE           = 'dcard_nurit_yearly_price';
	const OPTION_NURIT_YEARLY_UNLIMITED_MSG   = 'dcard_nurit_yearly_unlimited_msg';
	const OPTION_NURIT_YEARLY_UNLIMITED_CHAT  = 'dcard_nurit_yearly_unlimited_chat';
	const OPTION_NURIT_YEARLY_PRODUCT_ID      = 'dcard_nurit_yearly_product_id';

	// ברירות מחדל זמניות בלבד - אין שום משמעות "נכונה" ידועה מראש למגבלות
	// האלה, המנהל צריך לכוון אותן בעצמו בעמוד ההגדרות לפי מה שמתאים לעסק.
	const DEFAULT_ANON_MSG_LIMIT  = 1;
	const DEFAULT_ANON_CHAT_LIMIT = 5;
	const DEFAULT_REG_MSG_LIMIT   = 10;
	const DEFAULT_REG_CHAT_LIMIT  = 30;
	const DEFAULT_NARKIS_MSG_CREDITS        = 15;
	const DEFAULT_NARKIS_CHAT_CREDITS       = 15;
	// תיקון §6.10.2: 999999 לא תקף יותר כ"ערך קסם" - נשאר כברירת מחדל
	// מספרית זמנית (עד שהמנהל מזין מספר שימוש-הוגן אמיתי) אבל "ללא הגבלה"
	// עכשיו נקבע אך ורק ע"י ה-checkbox (DEFAULT_*_UNLIMITED_* למטה, true
	// לנורית/נורית-שנתי - משמר את ההתנהגות שהייתה בפועל לפני השינוי).
	// עמוד ההגדרות מציג אזהרה (ר' render_plan_row) כשמספר כלשהו > 1000,
	// כדי לתזכר להחליף את 999999 במספר שימוש-הוגן אמיתי.
	const DEFAULT_NURIT_MSG_CREDITS         = 999999;
	const DEFAULT_NURIT_CHAT_CREDITS        = 999999;
	const DEFAULT_NURIT_YEARLY_MSG_CREDITS  = 999999;
	const DEFAULT_NURIT_YEARLY_CHAT_CREDITS = 999999;
	const DEFAULT_DAILY_AI_CALL_LIMIT = 40;
	const DEFAULT_MONTHLY_AI_BUDGET   = 150;

	/**
	 * מפתח-יחיד להגדרת כל מסלול בתשלום - משמש גם את ה-settings/sanitizers
	 * וגם את render_page()/DCard_Plans (דרך ה-getters למטה), כדי שהוספת/
	 * שינוי שדה למסלול יהיה שינוי במקום אחד.
	 */
	private static function paid_plan_fields_config() {
		return array(
			DCard_Plans::NARKIS       => array(
				'default_label'      => 'נרקיס',
				'default_product_id' => '601',
				'default_unlimited'  => false,
				'active'        => self::OPTION_NARKIS_ACTIVE,
				'label'         => self::OPTION_NARKIS_LABEL,
				'price'         => self::OPTION_NARKIS_PRICE,
				'unlimited_msg' => self::OPTION_NARKIS_UNLIMITED_MSG,
				'unlimited_chat'=> self::OPTION_NARKIS_UNLIMITED_CHAT,
				'product_id'    => self::OPTION_NARKIS_PRODUCT_ID,
				'msg_credits'   => self::OPTION_NARKIS_MSG_CREDITS,
				'chat_credits'  => self::OPTION_NARKIS_CHAT_CREDITS,
			),
			DCard_Plans::NURIT        => array(
				'default_label'      => 'נורית',
				'default_product_id' => '602',
				'default_unlimited'  => true,
				'active'        => self::OPTION_NURIT_ACTIVE,
				'label'         => self::OPTION_NURIT_LABEL,
				'price'         => self::OPTION_NURIT_PRICE,
				'unlimited_msg' => self::OPTION_NURIT_UNLIMITED_MSG,
				'unlimited_chat'=> self::OPTION_NURIT_UNLIMITED_CHAT,
				'product_id'    => self::OPTION_NURIT_PRODUCT_ID,
				'msg_credits'   => self::OPTION_NURIT_MSG_CREDITS,
				'chat_credits'  => self::OPTION_NURIT_CHAT_CREDITS,
			),
			DCard_Plans::NURIT_YEARLY => array(
				// תיקון (21.11): היה "נורית (שנתי)" - מסלול שלישי אמיתי
				// וחודשי בפועל ("איריס" בקארדקום), לא גרסה שנתית של נורית.
				'default_label'      => 'איריס',
				'default_product_id' => '603',
				'default_unlimited'  => true,
				'active'        => self::OPTION_NURIT_YEARLY_ACTIVE,
				'label'         => self::OPTION_NURIT_YEARLY_LABEL,
				'price'         => self::OPTION_NURIT_YEARLY_PRICE,
				'unlimited_msg' => self::OPTION_NURIT_YEARLY_UNLIMITED_MSG,
				'unlimited_chat'=> self::OPTION_NURIT_YEARLY_UNLIMITED_CHAT,
				'product_id'    => self::OPTION_NURIT_YEARLY_PRODUCT_ID,
				'msg_credits'   => self::OPTION_NURIT_YEARLY_MSG_CREDITS,
				'chat_credits'  => self::OPTION_NURIT_YEARLY_CHAT_CREDITS,
			),
		);
	}

	// --- Getters בשימוש DCard_Plans (לא ניגש ל-wp_options ישירות, כדי
	// שיהיה רק מקום אחד שמכיר את שמות ה-options). מחזירים null למסלול לא
	// מוכר (free) - DCard_Plans כבר מטפל בכך.

	public static function active_option_name( $plan_id ) {
		$cfg = self::paid_plan_fields_config();
		return isset( $cfg[ $plan_id ] ) ? $cfg[ $plan_id ]['active'] : null;
	}

	public static function label_option_name( $plan_id ) {
		$cfg = self::paid_plan_fields_config();
		return isset( $cfg[ $plan_id ] ) ? $cfg[ $plan_id ]['label'] : null;
	}

	public static function default_label( $plan_id ) {
		$cfg = self::paid_plan_fields_config();
		return isset( $cfg[ $plan_id ] ) ? $cfg[ $plan_id ]['default_label'] : $plan_id;
	}

	public static function price_option_name( $plan_id ) {
		$cfg = self::paid_plan_fields_config();
		return isset( $cfg[ $plan_id ] ) ? $cfg[ $plan_id ]['price'] : null;
	}

	public static function product_id_option_name( $plan_id ) {
		$cfg = self::paid_plan_fields_config();
		return isset( $cfg[ $plan_id ] ) ? $cfg[ $plan_id ]['product_id'] : null;
	}

	public static function default_product_id( $plan_id ) {
		$cfg = self::paid_plan_fields_config();
		return isset( $cfg[ $plan_id ] ) ? $cfg[ $plan_id ]['default_product_id'] : '';
	}

	public static function unlimited_option_name( $plan_id, $type ) {
		$cfg = self::paid_plan_fields_config();
		if ( ! isset( $cfg[ $plan_id ] ) ) {
			return null;
		}
		return ( 'chat' === $type ) ? $cfg[ $plan_id ]['unlimited_chat'] : $cfg[ $plan_id ]['unlimited_msg'];
	}

	public static function default_unlimited( $plan_id, $type ) {
		$cfg = self::paid_plan_fields_config();
		return isset( $cfg[ $plan_id ] ) ? $cfg[ $plan_id ]['default_unlimited'] : false;
	}

	public static function get_cardcom_payment_url() {
		return (string) get_option( self::OPTION_CARDCOM_PAYMENT_URL, self::DEFAULT_CARDCOM_PAYMENT_URL );
	}

	// תיקון §6.10.2.5: "logged-in WordPress admin מקבל את חלון התשלום
	// האמיתי גם כש'חלון התשלום פעיל' כבוי" - כדי לבדוק את כל הזרימה (כולל
	// קארדקום בפועל) ב-staging בלי שהנסיינים הרגילים יראו תשלום חי.
	public static function admin_payment_testing_active() {
		return current_user_can( 'manage_options' ) && ! self::is_paywall_enabled();
	}

	public static function register_menu() {
		add_submenu_page(
			'edit.php?post_type=projective_card',
			'ניהול מכסות',
			'ניהול מכסות',
			'manage_options',
			'dcard-quotas',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings() {
		$int_sanitizer = function ( $value ) {
			return absint( $value ); // שולל/לא-מספרי -> 0, לא שומר ערך לא תקין בשקט
		};
		$price_sanitizer = function ( $value ) {
			$float = (float) $value;
			return $float > 0 ? round( $float, 2 ) : 0.0;
		};
		$bool_sanitizer = function ( $value ) {
			return $value ? 1 : 0;
		};
		$text_sanitizer = function ( $value ) {
			return sanitize_text_field( (string) $value );
		};

		$int_options = array(
			self::OPTION_ANON_MSG_LIMIT             => self::DEFAULT_ANON_MSG_LIMIT,
			self::OPTION_ANON_CHAT_LIMIT            => self::DEFAULT_ANON_CHAT_LIMIT,
			self::OPTION_REG_MSG_LIMIT              => self::DEFAULT_REG_MSG_LIMIT,
			self::OPTION_REG_CHAT_LIMIT             => self::DEFAULT_REG_CHAT_LIMIT,
			self::OPTION_DAILY_AI_CALL_LIMIT        => self::DEFAULT_DAILY_AI_CALL_LIMIT,
			self::OPTION_NARKIS_MSG_CREDITS         => self::DEFAULT_NARKIS_MSG_CREDITS,
			self::OPTION_NARKIS_CHAT_CREDITS        => self::DEFAULT_NARKIS_CHAT_CREDITS,
			self::OPTION_NURIT_MSG_CREDITS          => self::DEFAULT_NURIT_MSG_CREDITS,
			self::OPTION_NURIT_CHAT_CREDITS         => self::DEFAULT_NURIT_CHAT_CREDITS,
			self::OPTION_NURIT_YEARLY_MSG_CREDITS   => self::DEFAULT_NURIT_YEARLY_MSG_CREDITS,
			self::OPTION_NURIT_YEARLY_CHAT_CREDITS  => self::DEFAULT_NURIT_YEARLY_CHAT_CREDITS,
		);
		foreach ( $int_options as $option_name => $default ) {
			register_setting( self::OPTION_GROUP, $option_name, array(
				'sanitize_callback' => $int_sanitizer,
				'default'           => $default,
			) );
		}

		register_setting( self::OPTION_GROUP, self::OPTION_MONTHLY_AI_BUDGET, array(
			'sanitize_callback' => $price_sanitizer,
			'default'           => self::DEFAULT_MONTHLY_AI_BUDGET,
		) );

		// checkbox בודד - ר' render_page: hidden input עם value=0 מודבק
		// *לפני* ה-checkbox באותו name, כך שתיבה לא-מסומנת עדיין שולחת 0.
		register_setting( self::OPTION_GROUP, self::OPTION_PAYWALL_ENABLED, array(
			'sanitize_callback' => $bool_sanitizer,
			'default'           => self::DEFAULT_PAYWALL_ENABLED ? 1 : 0,
		) );

		register_setting( self::OPTION_GROUP, self::OPTION_CARDCOM_PAYMENT_URL, array(
			'sanitize_callback' => 'esc_url_raw',
			'default'           => self::DEFAULT_CARDCOM_PAYMENT_URL,
		) );

		foreach ( self::paid_plan_fields_config() as $cfg ) {
			register_setting( self::OPTION_GROUP, $cfg['active'], array(
				'sanitize_callback' => $bool_sanitizer,
				'default'           => 1,
			) );
			register_setting( self::OPTION_GROUP, $cfg['label'], array(
				'sanitize_callback' => $text_sanitizer,
				'default'           => $cfg['default_label'],
			) );
			register_setting( self::OPTION_GROUP, $cfg['price'], array(
				'sanitize_callback' => $price_sanitizer,
				'default'           => 0,
			) );
			register_setting( self::OPTION_GROUP, $cfg['unlimited_msg'], array(
				'sanitize_callback' => $bool_sanitizer,
				'default'           => $cfg['default_unlimited'] ? 1 : 0,
			) );
			register_setting( self::OPTION_GROUP, $cfg['unlimited_chat'], array(
				'sanitize_callback' => $bool_sanitizer,
				'default'           => $cfg['default_unlimited'] ? 1 : 0,
			) );
			register_setting( self::OPTION_GROUP, $cfg['product_id'], array(
				'sanitize_callback' => $text_sanitizer,
				'default'           => $cfg['default_product_id'],
			) );
		}
	}

	public static function is_paywall_enabled() {
		return (bool) get_option( self::OPTION_PAYWALL_ENABLED, self::DEFAULT_PAYWALL_ENABLED ? 1 : 0 );
	}

	public static function get_daily_ai_call_limit() {
		return (int) get_option( self::OPTION_DAILY_AI_CALL_LIMIT, self::DEFAULT_DAILY_AI_CALL_LIMIT );
	}

	public static function get_monthly_ai_budget_usd() {
		return (float) get_option( self::OPTION_MONTHLY_AI_BUDGET, self::DEFAULT_MONTHLY_AI_BUDGET );
	}

	/**
	 * ולידציה בשמירה (§6.10.2: "מסלול פעיל בלי ProductID מציג אזהרה") -
	 * רצה ב-admin_notices, אחרי שה-Settings API כבר שמר. לא חוסמת שמירה
	 * (אין sanitize_callback שדוחה ערך) - רק מתריעה.
	 */
	public static function maybe_warn_active_plan_without_product_id() {
		if ( ! isset( $_GET['page'] ) || 'dcard-quotas' !== $_GET['page'] ) {
			return;
		}
		foreach ( self::paid_plan_fields_config() as $plan_id => $cfg ) {
			$active     = (bool) get_option( $cfg['active'], 1 );
			$product_id = trim( (string) get_option( $cfg['product_id'], $cfg['default_product_id'] ) );
			if ( $active && '' === $product_id ) {
				$label = (string) get_option( $cfg['label'], $cfg['default_label'] );
				echo '<div class="notice notice-warning"><p>המסלול "' . esc_html( $label ) . '" מסומן כפעיל, אבל אין לו ProductID בקארדקום - תשלומים עבורו לא ישויכו אוטומטית למשתמש.</p></div>';
			}
		}
	}

	private static function notice_transient_key() {
		return 'dcard_quotas_notices_' . get_current_user_id();
	}

	private static function add_notice( $message ) {
		$notices   = get_transient( self::notice_transient_key() );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = $message;
		set_transient( self::notice_transient_key(), $notices, 60 );
	}

	private static function take_notices() {
		$notices = get_transient( self::notice_transient_key() );
		delete_transient( self::notice_transient_key() );
		return is_array( $notices ) ? $notices : array();
	}

	/**
	 * שורה לטבלת "לא משלמים" (אנונימי/חינמי) - ללא שדות המסלול בתשלום.
	 */
	private static function render_free_plan_row( $label, $msg_option, $msg_default, $chat_option, $chat_default ) {
		$msg_value  = get_option( $msg_option, $msg_default );
		$chat_value = get_option( $chat_option, $chat_default );
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td><input type="number" min="0" step="1" name="<?php echo esc_attr( $msg_option ); ?>" value="<?php echo esc_attr( $msg_value ); ?>" class="small-text" /></td>
			<td><input type="number" min="0" step="1" name="<?php echo esc_attr( $chat_option ); ?>" value="<?php echo esc_attr( $chat_value ); ?>" class="small-text" /></td>
			<td colspan="6" class="description"></td>
		</tr>
		<?php
	}

	/**
	 * שורה למסלול בתשלום - כל השדות מ-§6.10.2 (פעיל/שם/מחיר/מקס' מסרים
	 * ודיאלוגים + "ללא הגבלה" לכל אחד/ProductID), ועמודת "כך זה יוצג"
	 * שמתעדכנת ב-JS (ר' render_preview_script). class="dcard-plan-row"
	 * ו-data-plan תואמים לשם מפתח ה-JS בתחתית render_page().
	 */
	private static function render_paid_plan_row( $plan_id, $cfg ) {
		$active         = (bool) get_option( $cfg['active'], 1 );
		$label          = get_option( $cfg['label'], $cfg['default_label'] );
		$price          = get_option( $cfg['price'], 0 );
		$msg_value      = (int) get_option( $cfg['msg_credits'], 0 );
		$chat_value     = (int) get_option( $cfg['chat_credits'], 0 );
		$product_id     = get_option( $cfg['product_id'], $cfg['default_product_id'] );
		?>
		<tr class="dcard-plan-row" data-plan="<?php echo esc_attr( $plan_id ); ?>">
			<td><input type="checkbox" name="<?php echo esc_attr( $cfg['active'] ); ?>" value="1" <?php checked( $active ); ?> /></td>
			<td><input type="text" class="regular-text dcard-plan-label" name="<?php echo esc_attr( $cfg['label'] ); ?>" value="<?php echo esc_attr( $label ); ?>" style="width:120px;" /></td>
			<td><input type="number" min="0" step="1" class="small-text dcard-plan-price" name="<?php echo esc_attr( $cfg['price'] ); ?>" value="<?php echo esc_attr( $price ); ?>" /> ₪</td>
			<td>
				<input type="number" min="0" step="1" class="small-text dcard-plan-msg" name="<?php echo esc_attr( $cfg['msg_credits'] ); ?>" value="<?php echo esc_attr( $msg_value ); ?>" />
				<?php if ( $msg_value > 1000 ) : ?><br /><span style="color:#a3262a;">⚠ מספר גבוה מאוד - ייתכן שזה 999999 ישן, מתקופת "ללא הגבלה". §6.10.2 (19.11): אין יותר "ללא הגבלה" בכלל - חלון ההצטרפות מציג את המספר הזה כפשוטו. כדאי להזין מספר שימוש-הוגן אמיתי.</span><?php endif; ?>
			</td>
			<td>
				<input type="number" min="0" step="1" class="small-text dcard-plan-chat" name="<?php echo esc_attr( $cfg['chat_credits'] ); ?>" value="<?php echo esc_attr( $chat_value ); ?>" />
				<?php if ( $chat_value > 1000 ) : ?><br /><span style="color:#a3262a;">⚠ מספר גבוה מאוד - ייתכן שזה 999999 ישן, מתקופת "ללא הגבלה". §6.10.2 (19.11): אין יותר "ללא הגבלה" בכלל - חלון ההצטרפות מציג את המספר הזה כפשוטו. כדאי להזין מספר שימוש-הוגן אמיתי.</span><?php endif; ?>
			</td>
			<td><input type="text" class="small-text dcard-plan-product-id" name="<?php echo esc_attr( $cfg['product_id'] ); ?>" value="<?php echo esc_attr( $product_id ); ?>" /></td>
			<td class="dcard-plan-preview" style="max-width:220px;font-size:12px;color:#555;"></td>
		</tr>
		<?php
	}

	public static function render_page() {
		$paid_cfg = self::paid_plan_fields_config();
		?>
		<div class="wrap" dir="rtl">
			<h1>קורא בקלפים - הגדרות מכסות (Freemium)</h1>
			<p>לכל מסלול - כמה <strong>סשנים</strong> (שאלה אחת, עד 3 קלפים + כל השיחה סביבם) מקבל לקוח במסלול "קבלת מסר" ובמסלול "דיאלוג מכוון" בנפרד. ר' עמוד <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=projective_card&page=dcard-customers' ) ); ?>">לקוחות</a> לצפייה ביתרת הקרדיטים בפועל של כל לקוח. אנונימי נאכף בדפדפן (לא בשרת) - שאר השורות נאכפות בצד השרת.</p>
			<?php
			settings_errors();
			foreach ( self::take_notices() as $notice ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
			}
			?>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<h2>לא משלמים</h2>
				<table class="wp-list-table widefat fixed striped" style="max-width: 820px;">
					<thead>
						<tr>
							<th scope="col">מסלול</th>
							<th scope="col">מקס' מסרים (קבלת מסר)</th>
							<th scope="col">מקס' דיאלוגים</th>
							<th scope="col" colspan="6"></th>
						</tr>
					</thead>
					<tbody>
						<?php
						self::render_free_plan_row( 'אנונימי', self::OPTION_ANON_MSG_LIMIT, self::DEFAULT_ANON_MSG_LIMIT, self::OPTION_ANON_CHAT_LIMIT, self::DEFAULT_ANON_CHAT_LIMIT );
						self::render_free_plan_row( 'חינמי (רשום)', self::OPTION_REG_MSG_LIMIT, self::DEFAULT_REG_MSG_LIMIT, self::OPTION_REG_CHAT_LIMIT, self::DEFAULT_REG_CHAT_LIMIT );
						?>
					</tbody>
				</table>

				<h2 style="margin-top:28px;">מסלולים בתשלום</h2>
				<table class="wp-list-table widefat fixed striped" style="max-width: 1180px;">
					<thead>
						<tr>
							<th scope="col">פעיל</th>
							<th scope="col">שם לתצוגה</th>
							<th scope="col">מחיר (לחודש)</th>
							<th scope="col">מקס' קבלות מסר</th>
							<th scope="col">מקס' דיאלוגים</th>
							<th scope="col">ProductID בקארדקום</th>
							<th scope="col">כך זה יוצג</th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $paid_cfg as $plan_id => $cfg ) {
							self::render_paid_plan_row( $plan_id, $cfg );
						}
						?>
					</tbody>
				</table>
				<p class="description" style="max-width: 820px;">
					איך קרדיט מתקזז בפועל בתוך סשן בודד: סשן ש<strong>נשאר</strong> "קבלת מסר" לכל אורכו מקזז קרדיט אחד מ"מסרים". סשן שמתחיל כ"דיאלוג מכוון" מקזז קרדיט אחד מ"דיאלוגים". סשן ש<strong>עבר</strong> מ"קבלת מסר" ל"דיאלוג מכוון" מקזז קרדיט אחד מ"מסרים" <strong>וגם</strong> קרדיט אחד מ"דיאלוגים" - שני המונים יורדים על אותו סשן בודד. עדכון §6.10.2 (19.11): אין יותר "ללא הגבלה" כתווית תצוגה - המספר שמוזן כאן מוצג כפשוטו בחלון ההצטרפות.
				</p>

				<h2 style="margin-top:28px;">עמוד התשלום</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_CARDCOM_PAYMENT_URL ); ?>">כתובת קארדקום</label></th>
						<td>
							<input type="url" id="<?php echo esc_attr( self::OPTION_CARDCOM_PAYMENT_URL ); ?>" name="<?php echo esc_attr( self::OPTION_CARDCOM_PAYMENT_URL ); ?>" value="<?php echo esc_attr( self::get_cardcom_payment_url() ); ?>" class="large-text" dir="ltr" />
							<p class="description">כתובת תשלום אחת משותפת לכל המסלולים - בחירת המסלול נעשית בעמוד התשלום עצמו (כפתורי רדיו), והשרת מזהה מה נקנה לפי ה-ProductID שמגיע ב-IPN.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_PAYWALL_ENABLED ); ?>">חלון התשלום פעיל</label></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_PAYWALL_ENABLED ); ?>" value="0" />
							<input type="checkbox" id="<?php echo esc_attr( self::OPTION_PAYWALL_ENABLED ); ?>" name="<?php echo esc_attr( self::OPTION_PAYWALL_ENABLED ); ?>" value="1" <?php checked( self::is_paywall_enabled() ); ?> />
							<p class="description">כשכבוי (ברירת המחדל בתקופת הבדיקות): משתמש רשום שנגמרו לו הקרדיטים יראה "נגמרו השימושים לתקופת הניסיון" במקום חלון התשלום. כשפעיל: משתמש כזה רואה את חלון התשלום הרגיל. <strong>מנהל מחובר (manage_options) תמיד רואה את חלון התשלום האמיתי</strong> (עם תווית "מצב בדיקה"), גם כשהתיבה הזו כבויה - כדי לאפשר בדיקת הזרימה המלאה מול קארדקום בלי לחשוף תשלום לנסיינים.</p>
						</td>
					</tr>
				</table>

				<h2 style="margin-top:28px;">מגבלות AI</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_DAILY_AI_CALL_LIMIT ); ?>">מגבלת קריאות AI ביום - משתמש רשום בודד</label></th>
						<td>
							<input type="number" min="0" step="1" id="<?php echo esc_attr( self::OPTION_DAILY_AI_CALL_LIMIT ); ?>" name="<?php echo esc_attr( self::OPTION_DAILY_AI_CALL_LIMIT ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_DAILY_AI_CALL_LIMIT, self::DEFAULT_DAILY_AI_CALL_LIMIT ) ); ?>" class="small-text" />
							<p class="description">נאכף בצד השרת (ר' DCard_Usage_Logs::count_calls_today): כשמשתמש רשום בודד עובר את המספר הזה של קריאות AI בפועל (שליפת קלף + כל הודעה בשיחה) <strong>באותו יום</strong>, הוא מקבל הודעה מנומסת לחזור מחר - בלי לפתוח קיר תשלום. 0 = בלי מגבלה.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_MONTHLY_AI_BUDGET ); ?>">תקציב AI חודשי כולל ($)</label></th>
						<td>
							<input type="number" min="0" step="0.01" id="<?php echo esc_attr( self::OPTION_MONTHLY_AI_BUDGET ); ?>" name="<?php echo esc_attr( self::OPTION_MONTHLY_AI_BUDGET ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_MONTHLY_AI_BUDGET, self::DEFAULT_MONTHLY_AI_BUDGET ) ); ?>" class="small-text" />
							<p class="description">סכום כולל (כל המשתמשים וכל המודלים יחד) לחודש קלנדרי. בחציית 80% ו-100% נשלחת התראת מייל אחת לכתובת הניהול (admin_email) - <strong>בלי חסימה אוטומטית של שימוש</strong> (נבחר במכוון כצעד ראשון זהיר). 0 = בלי מעקב/התראה.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'שמירה' ); ?>
			</form>
		</div>
		<?php
		self::render_preview_script( $paid_cfg );
	}

	/**
	 * עמודת "כך זה יוצג" - מתעדכנת בזמן הקלדה, בלי AJAX (כל הנתונים כבר
	 * בדף).
	 *
	 * עדכון §6.10.2 (19.11): "אין 'ללא הגבלה' בכלל - תמיד המספר האמיתי" -
	 * הוסרו תיבות הסימון "להציג ללא הגבלה" והערת "בשימוש הוגן" לגמרי,
	 * גם מהתצוגה המקדימה כאן (לא רק מה-PHP שמעליה). אם עדיין יש בדף
	 * ערכים ישנים (999999) - האזהרה ב-render_paid_plan_row() מטפלת בזה.
	 */
	private static function render_preview_script( $paid_cfg ) {
		?>
		<script>
		(function () {
			function rowData(row) {
				return {
					plan: row.dataset.plan,
					label: row.querySelector('.dcard-plan-label').value || '',
					price: parseFloat(row.querySelector('.dcard-plan-price').value) || 0,
					msg: parseInt(row.querySelector('.dcard-plan-msg').value, 10) || 0,
					chat: parseInt(row.querySelector('.dcard-plan-chat').value, 10) || 0
				};
			}

			function render() {
				var rows = Array.prototype.slice.call(document.querySelectorAll('.dcard-plan-row'));
				rows.forEach(function (row) {
					var d = rowData(row);
					var lines = [d.label + ' - ' + d.price + ' ₪ לחודש'];
					lines.push('✓ קבלת מסר: עד ' + d.msg + ' בחודש');
					lines.push('✓ דיאלוג מכוון: עד ' + d.chat + ' בחודש');
					row.querySelector('.dcard-plan-preview').innerHTML = lines.join('<br>');
				});
			}

			document.querySelectorAll('.dcard-plan-row input').forEach(function (input) {
				input.addEventListener('input', render);
				input.addEventListener('change', render);
			});
			render();
		})();
		</script>
		<?php
	}

	/**
	 * §6.10.2: adminPaymentTesting/cardcomPaymentUrl נוספו כאן - משתמשים
	 * בהם ב-elementor-code.html (kbHandleCreditsExhausted/CARDCOM_PAYMENT_URL
	 * בהתאמה), ר' ההערות המלאות שם.
	 */
	public static function enqueue_frontend_settings() {
		wp_register_script( 'dcard-quotas-stub', '', array(), null, false );
		wp_enqueue_script( 'dcard-quotas-stub' );
		wp_localize_script( 'dcard-quotas-stub', 'dcardSettings', array(
			'anonMsgLimit'        => (int) get_option( self::OPTION_ANON_MSG_LIMIT, self::DEFAULT_ANON_MSG_LIMIT ),
			'anonChatLimit'       => (int) get_option( self::OPTION_ANON_CHAT_LIMIT, self::DEFAULT_ANON_CHAT_LIMIT ),
			'paywallEnabled'      => self::is_paywall_enabled(),
			'isAdminViewing'      => current_user_can( 'manage_options' ),
			'adminPaymentTesting' => self::admin_payment_testing_active(),
			'cardcomPaymentUrl'   => self::get_cardcom_payment_url(),
		) );
	}
}
