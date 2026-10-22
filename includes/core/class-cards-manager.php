<?php
namespace TheCardReaderCore;

defined( 'ABSPATH' ) || exit;

/**
 * מנהל את סוג הפוסט המותאם אישית (CPT) של הקלפים ההשלכתיים,
 * את שדות המידע (Meta Boxes), ואת לוגיקת שליפת הקלפים האקראית.
 */
class Cards_Manager {

	public function init() {
		// חיבור פעולות (Hooks) של וורדפרס למתודות במחלקה
		add_action( 'after_setup_theme', [ $this, 'enable_card_thumbnails' ], 100 );
		add_action( 'init', [ $this, 'register_projective_card_post_type' ] );
		add_action( 'add_meta_boxes_projective_card', [ $this, 'add_card_details_meta_box' ] );
		add_action( 'save_post_projective_card', [ $this, 'save_card_details_meta_box' ] );
		add_action( 'template_redirect', [ $this, 'handle_card_draw_and_reset' ] );
	}

	public function enable_card_thumbnails() {
		$support = get_theme_support( 'post-thumbnails' );

		if ( false === $support ) {
			add_theme_support( 'post-thumbnails', array( 'projective_card' ) );
		} elseif ( is_array( $support ) && isset( $support[0] ) && is_array( $support[0] ) ) {
			$post_types = array_unique( array_merge( $support[0], array( 'projective_card' ) ) );
			add_theme_support( 'post-thumbnails', $post_types );
		}
	}

	public function register_projective_card_post_type() {
		$labels = array(
			'name'                  => __( 'קלפים השלכתיים', 'the-card-reader-core' ),
			'singular_name'         => __( 'קלף השלכתי', 'the-card-reader-core' ),
			'menu_name'             => __( 'הקורא בקלפים', 'the-card-reader-core' ),
			'name_admin_bar'        => __( 'קלף השלכתי', 'the-card-reader-core' ),
			'add_new'               => __( 'הוספת קלף', 'the-card-reader-core' ),
			'add_new_item'          => __( 'הוספת קלף חדש', 'the-card-reader-core' ),
			'edit_item'             => __( 'עריכת קלף', 'the-card-reader-core' ),
			'new_item'              => __( 'קלף חדש', 'the-card-reader-core' ),
			'view_item'             => __( 'הצגת קלף', 'the-card-reader-core' ),
			'view_items'            => __( 'הצגת קלפים', 'the-card-reader-core' ),
			'search_items'          => __( 'חיפוש קלפים', 'the-card-reader-core' ),
			'not_found'             => __( 'לא נמצאו קלפים.', 'the-card-reader-core' ),
			'not_found_in_trash'    => __( 'לא נמצאו קלפים בפח.', 'the-card-reader-core' ),
			'all_items'             => __( 'כל הקלפים', 'the-card-reader-core' ),
		);

		register_post_type(
			'projective_card',
			array(
				'labels'              => $labels,
				'public'              => true,
				'publicly_queryable'  => true,
				'exclude_from_search' => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_admin_bar'   => true,
				'show_in_nav_menus'   => true,
				'show_in_rest'        => true,
				'menu_icon'           => 'dashicons-format-gallery',
				'supports'            => array( 'title', 'thumbnail' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'has_archive'         => true,
				'rewrite'             => false,
				'query_var'           => false,
			)
		);
	}

	public function add_card_details_meta_box() {
		add_meta_box(
			'tcr_card_details',
			__( 'פרטי הקלף', 'the-card-reader-core' ),
			[ $this, 'render_card_details_meta_box' ],
			'projective_card',
			'normal',
			'default'
		);
	}

	public function render_card_details_meta_box( $post ) {
		$description = get_post_meta( $post->ID, '_tcr_visual_description', true );
		$themes      = get_post_meta( $post->ID, '_tcr_themes_keywords', true );

		wp_nonce_field( 'tcr_save_card_details_' . $post->ID, 'tcr_card_details_nonce' );
		?>
		<div dir="rtl" lang="he">
			<p>
				<label for="tcr_visual_description"><strong><?php esc_html_e( 'תיאור חזותי', 'the-card-reader-core' ); ?></strong></label>
			</p>
			<textarea id="tcr_visual_description" name="tcr_visual_description" rows="6" class="widefat" dir="rtl"><?php echo esc_textarea( is_string( $description ) ? $description : '' ); ?></textarea>
			<p>
				<label for="tcr_themes_keywords"><strong><?php esc_html_e( 'נושאים ומילות מפתח', 'the-card-reader-core' ); ?></strong></label>
			</p>
			<input type="text" id="tcr_themes_keywords" name="tcr_themes_keywords" class="widefat" dir="rtl" value="<?php echo esc_attr( is_string( $themes ) ? $themes : '' ); ?>">
		</div>
		<?php
	}

	public function save_card_details_meta_box( $post_id ) {
		if ( 'projective_card' !== get_post_type( $post_id ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['tcr_card_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tcr_card_details_nonce'] ) ), 'tcr_save_card_details_' . $post_id ) ) {
			return;
		}

		if ( isset( $_POST['tcr_visual_description'] ) ) {
			update_post_meta( $post_id, '_tcr_visual_description', wp_slash( sanitize_textarea_field( wp_unslash( $_POST['tcr_visual_description'] ) ) ) );
		}

		if ( isset( $_POST['tcr_themes_keywords'] ) ) {
			update_post_meta( $post_id, '_tcr_themes_keywords', wp_slash( sanitize_text_field( wp_unslash( $_POST['tcr_themes_keywords'] ) ) ) );
		}
	}

	public function handle_card_draw_and_reset() {
		// session_start() הועבר לכאן (לא גלובלי יותר - ר' ההערה ב-
		// the-card-reader-core.php) - רק כשבאמת צריך $_SESSION, ורק
		// לבקשת עמוד רגילה (template_redirect, לא REST API בכלל).
		if ( ! isset( $_GET['draw_card'] ) && ! isset( $_GET['reset_deck'] ) ) {
			return; // לא קשור לקלפים בכלל - לא פותחים session על לא-כלום
		}
		if ( ! session_id() ) {
			session_start();
		}

		// שליפת קלף חדש
		if ( isset( $_GET['draw_card'] ) && $_GET['draw_card'] == 'yes' ) {
			if ( ! isset( $_SESSION['drawn_cards'] ) ) {
				$_SESSION['drawn_cards'] = array();
			}

			// איפוס אוטומטי אם נמשכו 3 קלפים
			if ( count( $_SESSION['drawn_cards'] ) >= 3 ) {
				$_SESSION['drawn_cards'] = array(); 
			}

			$args = array(
				'post_type'      => 'projective_card', 
				'posts_per_page' => 1,
				'orderby'        => 'rand',
				'post_status'    => 'publish',
				'post__not_in'   => $_SESSION['drawn_cards'] 
			);
			
			$random_card = get_posts( $args );
			
			if ( $random_card ) {
				$card_id = $random_card[0]->ID;
				$_SESSION['drawn_cards'][] = $card_id;
				wp_redirect( get_permalink( $card_id ) );
				exit;
			}
		}
		
		// איפוס יזום
		if ( isset( $_GET['reset_deck'] ) && $_GET['reset_deck'] == 'yes' ) {
			$_SESSION['drawn_cards'] = array();
			wp_redirect( home_url( '/' ) ); 
			exit;
		}
	}
}