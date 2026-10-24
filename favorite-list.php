<?php
/**
 * Plugin Name:       Favorite List
 * Description:       Lets logged-in users add or remove posts from their favorites list (AJAX, heart-beat animation, settings page, [favorite_list] shortcode).
 * Version:           1.2.0
 * Author:            A Soheili
 * Text Domain:       favorite-list
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Update URI:        https://github.com/ali-soheili/wp-favorite-list
 */

defined( 'ABSPATH' ) || exit;

define( 'FL_VERSION', '1.2.0' );
define( 'FL_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-fl-github-updater.php';
new FL_GitHub_Updater( __FILE__ );

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-fl-admin.php';
	FL_Admin::init();
}

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'favorite-list', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

final class FL_Favorite_List {

	const OPTION    = 'fl_settings';         // admin settings.
	const META_KEY  = '_fl_favorites';       // user meta: array of post IDs.
	const COUNT_KEY = '_fl_favorite_count';  // post meta: how many users favorited the post.
	const NONCE     = 'fl_favorite_nonce';
	const MAX_ITEMS = 1000;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_disable_cache' ) );

		add_action( 'wp_ajax_fl_set_favorite', array( __CLASS__, 'ajax_set_favorite' ) );
		add_action( 'wp_ajax_nopriv_fl_set_favorite', array( __CLASS__, 'ajax_login_required' ) );
		add_action( 'wp_ajax_fl_get_favorites', array( __CLASS__, 'ajax_get_favorites' ) );
		add_action( 'wp_ajax_nopriv_fl_get_favorites', array( __CLASS__, 'ajax_get_favorites' ) );

		add_shortcode( 'favorite_list', array( __CLASS__, 'shortcode_list' ) );
		add_shortcode( 'favorite_button', array( __CLASS__, 'shortcode_button' ) );
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * ---------------------------------------------------------------- */

	/** The plugin's own heart icons, used when no icon is set in the settings. */
	public static function default_icons() {
		$base = plugin_dir_url( FL_FILE ) . 'assets/icons/';
		return array(
			'inactive' => $base . 'favorite.svg',
			'active'   => $base . 'favorite-active.svg',
		);
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'icon_inactive' => '',
				'icon_active'   => '',
				'icon_width'    => 22,
				'icon_height'   => 22,
				'label'         => '',
				'guest_url'     => '',
			)
		);
	}

	/**
	 * One-time upgrade from 1.1.x, which used the theme's
	 * images/post-info-box/favorite(-active).svg icons and a fixed Persian label.
	 * If those theme icons exist, keep using them so nothing changes on that site.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'fl_db_version' ) ) {
			return;
		}
		update_option( 'fl_db_version', FL_VERSION );

		$settings = self::get_settings();
		$theme    = '/images/post-info-box/';
		if ( ! $settings['icon_inactive'] && ! $settings['icon_active'] && file_exists( get_template_directory() . $theme . 'favorite.svg' ) ) {
			$settings['icon_inactive'] = get_template_directory_uri() . $theme . 'favorite.svg';
			$settings['icon_active']   = get_template_directory_uri() . $theme . 'favorite-active.svg';
			$settings['icon_width']    = 23;
			$settings['icon_height']   = 22;
			$settings['label']         = $settings['label'] ? $settings['label'] : 'لیست علاقه مندی';
			update_option( self::OPTION, $settings );
		}
	}

	/** Icon URLs: admin setting, or the plugin's own icons when left empty. */
	public static function icons() {
		$settings = self::get_settings();
		$defaults = self::default_icons();
		return apply_filters(
			'fl_icon_urls',
			array(
				'inactive' => $settings['icon_inactive'] ? $settings['icon_inactive'] : $defaults['inactive'],
				'active'   => $settings['icon_active'] ? $settings['icon_active'] : $defaults['active'],
			)
		);
	}

	/**
	 * Where guests are sent. "{current_url}" is replaced with the page they were on.
	 * Default: the WordPress login page, which sends them back after logging in.
	 */
	public static function guest_url_template() {
		$settings = self::get_settings();
		$url      = trim( (string) $settings['guest_url'] );
		if ( '' === $url ) {
			$url = add_query_arg( 'redirect_to', '{current_url}', wp_login_url() );
		}
		return apply_filters( 'fl_guest_url', $url );
	}

	public static function post_types() {
		return (array) apply_filters( 'fl_post_types', array( 'post' ) );
	}

	/** Icon size in px (width, height). */
	public static function icon_size() {
		$settings = self::get_settings();
		return array(
			max( 8, min( 200, (int) $settings['icon_width'] ) ),
			max( 8, min( 200, (int) $settings['icon_height'] ) ),
		);
	}

	/** Front-end texts. Translatable, and changeable with the fl_i18n filter. */
	public static function i18n() {
		$settings = self::get_settings();
		return apply_filters(
			'fl_i18n',
			array(
				'label'      => '' !== trim( $settings['label'] ) ? $settings['label'] : __( 'Favorites', 'favorite-list' ),
				'add'        => __( 'Add to favorites', 'favorite-list' ),
				'remove'     => __( 'Remove from favorites', 'favorite-list' ),
				'login'      => __( 'Log in to use your favorites list', 'favorite-list' ),
				'empty'      => __( 'You haven’t added anything to your favorites yet.', 'favorite-list' ),
				'need_login' => __( 'Log in to see your favorites list.', 'favorite-list' ),
				'login_link' => __( 'Log in', 'favorite-list' ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Storage (logged-in users only)
	 * ---------------------------------------------------------------- */

	public static function get_favorites( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}
		$ids = get_user_meta( $user_id, self::META_KEY, true );
		return self::clean_ids( is_array( $ids ) ? $ids : array() );
	}

	public static function is_favorite( $post_id = null, $user_id = null ) {
		$post_id = $post_id ? (int) $post_id : get_the_ID();
		return in_array( $post_id, self::get_favorites( $user_id ), true );
	}

	public static function get_count( $post_id ) {
		return max( 0, (int) get_post_meta( $post_id, self::COUNT_KEY, true ) );
	}

	private static function clean_ids( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		return array_slice( $ids, 0, self::MAX_ITEMS );
	}

	private static function bump_count( $post_id, $delta ) {
		update_post_meta( $post_id, self::COUNT_KEY, max( 0, self::get_count( $post_id ) + $delta ) );
	}

	/* ------------------------------------------------------------------
	 * AJAX
	 * ---------------------------------------------------------------- */

	/** Current user's favorites + fresh nonce, so cached pages still show the right state. */
	public static function ajax_get_favorites() {
		nocache_headers();
		wp_send_json_success(
			array(
				'loggedIn' => is_user_logged_in(),
				'ids'      => self::get_favorites(),
				'nonce'    => wp_create_nonce( self::NONCE ),
			)
		);
	}

	public static function ajax_login_required() {
		nocache_headers();
		wp_send_json_error(
			array(
				'code'     => 'login_required',
				'loginUrl' => self::guest_url_template(),
			),
			401
		);
	}

	/** Sets a post as favorite (active=1) or not (active=0). Idempotent, so double clicks are safe. */
	public static function ajax_set_favorite() {
		nocache_headers();

		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'code' => 'bad_nonce' ), 403 );
		}

		$user_id = get_current_user_id();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$want    = ! empty( $_POST['active'] ) && '0' !== $_POST['active'];
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, self::post_types(), true ) ) {
			wp_send_json_error( array( 'code' => 'invalid_post' ), 400 );
		}

		$ids    = self::get_favorites( $user_id );
		$exists = in_array( $post_id, $ids, true );

		if ( $want && ! $exists ) {
			array_unshift( $ids, $post_id ); // newest first.
			update_user_meta( $user_id, self::META_KEY, self::clean_ids( $ids ) );
			self::bump_count( $post_id, 1 );
		} elseif ( ! $want && $exists ) {
			update_user_meta( $user_id, self::META_KEY, self::clean_ids( array_diff( $ids, array( $post_id ) ) ) );
			self::bump_count( $post_id, -1 );
		}

		do_action( 'fl_favorite_changed', $post_id, $want, $user_id );

		wp_send_json_success(
			array(
				'post_id' => $post_id,
				'active'  => $want,
				'count'   => self::get_count( $post_id ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Front end
	 * ---------------------------------------------------------------- */

	public static function enqueue() {
		$url = plugin_dir_url( __FILE__ ) . 'assets/';

		wp_enqueue_style( 'favorite-list', $url . 'favorite-list.css', array(), FL_VERSION );
		wp_enqueue_script( 'favorite-list', $url . 'favorite-list.js', array(), FL_VERSION, true );

		wp_localize_script(
			'favorite-list',
			'FavoriteList',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'loggedIn'  => is_user_logged_in(),
				'loginUrl'  => self::guest_url_template(),
				'favorites' => self::get_favorites(),
				'icons'     => self::icons(),
				'i18n'      => self::i18n(),
			)
		);
	}

	/** Keep page caches from serving one user's favorites page to another. */
	public static function maybe_disable_cache() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( $post && has_shortcode( $post->post_content, 'favorite_list' ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
	}

	/** HTML for the toggle button. */
	public static function button_html( $post_id = null, $args = array() ) {
		$post_id = $post_id ? (int) $post_id : get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		$i18n  = self::i18n();
		$icons = self::icons();
		$size  = self::icon_size();
		$args  = wp_parse_args(
			$args,
			array(
				'label'  => $i18n['label'],
				'class'  => '',
				'width'  => $size[0],
				'height' => $size[1],
			)
		);

		$logged = is_user_logged_in();
		$active = $logged && self::is_favorite( $post_id );
		$title  = ! $logged ? $i18n['login'] : ( $active ? $i18n['remove'] : $i18n['add'] );
		$class  = trim( 'fl-favorite-btn ' . ( $active ? 'is-active ' : '' ) . $args['class'] );

		return sprintf(
			'<a href="#" class="%1$s" data-post-id="%2$d" role="button" aria-pressed="%3$s" title="%4$s">' .
				'<span class="fl-icon-wrap"><img class="fl-favorite-icon" src="%5$s" alt="" width="%6$d" height="%7$d"></span>' .
				'%8$s' .
			'</a>',
			esc_attr( $class ),
			$post_id,
			$active ? 'true' : 'false',
			esc_attr( $title ),
			esc_url( $active ? $icons['active'] : $icons['inactive'] ),
			(int) $args['width'],
			(int) $args['height'],
			'' !== $args['label'] ? ' <span class="fl-favorite-label">' . esc_html( $args['label'] ) . '</span>' : ''
		);
	}

	/** [favorite_button] or [favorite_button id="123" label="..."] */
	public static function shortcode_button( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'label' => self::i18n()['label'],
			),
			$atts,
			'favorite_button'
		);
		return self::button_html( (int) $atts['id'], array( 'label' => $atts['label'] ) );
	}

	/** [favorite_list] — the user's favorites page. */
	public static function shortcode_list( $atts ) {
		$i18n = self::i18n();
		$atts = shortcode_atts(
			array(
				'empty'     => $i18n['empty'],
				'thumbnail' => 'yes',
			),
			$atts,
			'favorite_list'
		);

		if ( ! is_user_logged_in() ) {
			$current = home_url( add_query_arg( array() ) );
			$url     = str_replace( '{current_url}', rawurlencode( $current ), self::guest_url_template() );
			return sprintf(
				'<div class="fl-list-wrap"><p class="fl-need-login">%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html( $i18n['need_login'] ),
				esc_url( $url ),
				esc_html( $i18n['login_link'] )
			);
		}

		$ids   = self::get_favorites();
		$posts = array();

		if ( $ids ) {
			$posts = get_posts(
				array(
					'post__in'            => $ids,
					'orderby'             => 'post__in',
					'post_type'           => self::post_types(),
					'post_status'         => 'publish',
					'posts_per_page'      => count( $ids ),
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
				)
			);
		}

		$icons = self::icons();
		$size  = self::icon_size();

		ob_start();
		?>
		<div class="fl-list-wrap">
			<p class="fl-empty"<?php echo $posts ? ' hidden' : ''; ?>><?php echo esc_html( $atts['empty'] ); ?></p>
			<?php if ( $posts ) : ?>
				<ul class="fl-list">
					<?php foreach ( $posts as $p ) : ?>
						<li class="fl-item" data-post-id="<?php echo (int) $p->ID; ?>">
							<?php if ( 'yes' === $atts['thumbnail'] && has_post_thumbnail( $p ) ) : ?>
								<a class="fl-item-thumb" href="<?php echo esc_url( get_permalink( $p ) ); ?>">
									<?php echo get_the_post_thumbnail( $p, 'thumbnail' ); ?>
								</a>
							<?php endif; ?>
							<div class="fl-item-body">
								<a class="fl-item-title" href="<?php echo esc_url( get_permalink( $p ) ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></a>
								<span class="fl-item-date"><?php echo esc_html( get_the_date( '', $p ) ); ?></span>
							</div>
							<button type="button" class="fl-favorite-btn fl-item-remove is-active" data-post-id="<?php echo (int) $p->ID; ?>" aria-pressed="true" title="<?php echo esc_attr( $i18n['remove'] ); ?>" aria-label="<?php echo esc_attr( $i18n['remove'] ); ?>">
								<span class="fl-icon-wrap"><img class="fl-favorite-icon" src="<?php echo esc_url( $icons['active'] ); ?>" alt="" width="<?php echo (int) $size[0]; ?>" height="<?php echo (int) $size[1]; ?>"></span>
							</button>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}

FL_Favorite_List::init();

/* ----------------------------------------------------------------------
 * Template helpers for your theme
 * -------------------------------------------------------------------- */

/** Prints the favorite button. Use inside the loop / single post template, or pass a post ID. */
function fl_favorite_button( $post_id = null, $args = array() ) {
	echo FL_Favorite_List::button_html( $post_id, $args ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in button_html().
}

/** Array of favorited post IDs for the current user (or a given user). */
function fl_get_favorites( $user_id = null ) {
	return FL_Favorite_List::get_favorites( $user_id );
}

/** Whether a post is in the current user's favorites. */
function fl_is_favorite( $post_id = null ) {
	return FL_Favorite_List::is_favorite( $post_id );
}

/** URL of the normal (or active) icon, for hand-written buttons. */
function fl_get_icon_url( $active = false ) {
	$icons = FL_Favorite_List::icons();
	return $active ? $icons['active'] : $icons['inactive'];
}

/** How many users have favorited a post. */
function fl_favorite_count( $post_id = null ) {
	return FL_Favorite_List::get_count( $post_id ? $post_id : get_the_ID() );
}
