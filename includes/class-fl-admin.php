<?php
/**
 * Settings → Favorite List
 *
 * Tabs: General (icons, guest redirect), How to use (code snippets), Updates (GitHub).
 */
defined( 'ABSPATH' ) || exit;

final class FL_Admin {

	const PAGE = 'fl-settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'save_update_settings' ) );
		add_action( 'admin_post_fl_check_updates', array( __CLASS__, 'check_updates_now' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FL_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function url( $tab = 'general', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE, 'tab' => $tab ), $args ), admin_url( 'options-general.php' ) );
	}

	public static function menu() {
		add_options_page(
			__( 'Favorite List', 'favorite-list' ),
			__( 'Favorite List', 'favorite-list' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'favorite-list' ) . '</a>' );
		return $links;
	}

	public static function enqueue( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}
		$url = plugin_dir_url( FL_FILE ) . 'assets/';
		wp_enqueue_media(); // bundled with WordPress, works offline.
		wp_enqueue_style( 'fl-admin', $url . 'admin.css', array(), FL_VERSION );
		wp_enqueue_script( 'fl-admin', $url . 'admin.js', array( 'jquery' ), FL_VERSION, true );
		wp_localize_script(
			'fl-admin',
			'FLAdmin',
			array(
				'chooseIcon' => __( 'Choose icon', 'favorite-list' ),
				'useIcon'    => __( 'Use this icon', 'favorite-list' ),
				'copied'     => __( 'Copied!', 'favorite-list' ),
				'copy'       => __( 'Copy', 'favorite-list' ),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * General settings (Settings API)
	 * ---------------------------------------------------------------- */

	public static function register_settings() {
		register_setting(
			'fl_settings_group',
			FL_Favorite_List::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = array();

		foreach ( array( 'icon_inactive', 'icon_active' ) as $key ) {
			$out[ $key ] = isset( $input[ $key ] ) ? esc_url_raw( trim( wp_unslash( $input[ $key ] ) ) ) : '';
		}

		foreach ( array( 'icon_width', 'icon_height' ) as $key ) {
			$out[ $key ] = isset( $input[ $key ] ) && '' !== $input[ $key ] ? max( 8, min( 200, absint( $input[ $key ] ) ) ) : 22;
		}

		$out['label'] = isset( $input['label'] ) ? sanitize_text_field( wp_unslash( $input['label'] ) ) : '';

		// Keep the {current_url} placeholder intact while sanitizing.
		$guest = isset( $input['guest_url'] ) ? trim( wp_unslash( $input['guest_url'] ) ) : '';
		if ( '' !== $guest ) {
			$guest = str_replace( '{current_url}', 'FLCURRENTURL', $guest );
			$guest = esc_url_raw( $guest );
			$guest = str_replace( 'FLCURRENTURL', '{current_url}', $guest );
		}
		$out['guest_url'] = $guest;

		return $out;
	}

	/* ------------------------------------------------------------------
	 * Page
	 * ---------------------------------------------------------------- */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = array(
			'general' => __( 'General', 'favorite-list' ),
			'usage'   => __( 'How to use', 'favorite-list' ),
		);
		if ( current_user_can( 'update_plugins' ) ) {
			$tabs['updates'] = __( 'Updates', 'favorite-list' );
		}

		$active = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'general';
		}
		?>
		<div class="wrap fl-settings">
			<h1><?php esc_html_e( 'Favorite List', 'favorite-list' ); ?></h1>

			<?php self::notices(); ?>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="nav-tab <?php echo $active === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="fl-settings-panel">
				<?php
				if ( 'usage' === $active ) {
					self::tab_usage();
				} elseif ( 'updates' === $active ) {
					self::tab_updates();
				} else {
					self::tab_general();
				}
				?>
			</div>
		</div>
		<?php
	}

	private static function notices() {
		if ( empty( $_GET['fl-message'] ) ) {
			return;
		}
		$messages = array(
			'updates-saved'   => __( 'Update settings saved.', 'favorite-list' ),
			'updates-checked' => __( 'Checked GitHub for updates.', 'favorite-list' ),
		);
		$key = sanitize_key( $_GET['fl-message'] );
		if ( isset( $messages[ $key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
		}
	}

	/* ---------- General ---------- */

	private static function tab_general() {
		$settings = FL_Favorite_List::get_settings();
		$defaults = FL_Favorite_List::default_icons();
		$name     = FL_Favorite_List::OPTION;

		$icons = array(
			'icon_inactive' => array(
				'label'   => __( 'Normal icon', 'favorite-list' ),
				'desc'    => __( 'Shown when the post is not in the user’s favorites.', 'favorite-list' ),
				'default' => $defaults['inactive'],
			),
			'icon_active'   => array(
				'label'   => __( 'Active icon', 'favorite-list' ),
				'desc'    => __( 'Shown when the post is in the user’s favorites.', 'favorite-list' ),
				'default' => $defaults['active'],
			),
		);
		?>
		<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'favorite-list' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'fl_settings_group' ); ?>

			<h2><?php esc_html_e( 'Button', 'favorite-list' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fl-label"><?php esc_html_e( 'Button text', 'favorite-list' ); ?></label></th>
					<td>
						<input type="text" id="fl-label" class="regular-text"
							name="<?php echo esc_attr( $name . '[label]' ); ?>"
							value="<?php echo esc_attr( $settings['label'] ); ?>"
							placeholder="<?php esc_attr_e( 'Favorites', 'favorite-list' ); ?>">
						<p class="description"><?php esc_html_e( 'Text shown next to the icon. Leave empty for the default.', 'favorite-list' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Icon size', 'favorite-list' ); ?></th>
					<td>
						<label>
							<?php esc_html_e( 'Width', 'favorite-list' ); ?>
							<input type="number" min="8" max="200" class="small-text" name="<?php echo esc_attr( $name . '[icon_width]' ); ?>" value="<?php echo (int) $settings['icon_width']; ?>">
						</label>
						&nbsp;
						<label>
							<?php esc_html_e( 'Height', 'favorite-list' ); ?>
							<input type="number" min="8" max="200" class="small-text" name="<?php echo esc_attr( $name . '[icon_height]' ); ?>" value="<?php echo (int) $settings['icon_height']; ?>">
						</label>
						<?php esc_html_e( 'px', 'favorite-list' ); ?>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Icons', 'favorite-list' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php foreach ( $icons as $key => $icon ) : ?>
					<?php $current = $settings[ $key ] ? $settings[ $key ] : $icon['default']; ?>
					<tr>
						<th scope="row"><label for="fl-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $icon['label'] ); ?></label></th>
						<td>
							<div class="fl-icon-field">
								<span class="fl-icon-preview"><img src="<?php echo esc_url( $current ); ?>" alt="" data-default="<?php echo esc_url( $icon['default'] ); ?>"></span>
								<input type="url" id="fl-<?php echo esc_attr( $key ); ?>" class="large-text code fl-icon-input" dir="ltr"
									name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>"
									value="<?php echo esc_attr( $settings[ $key ] ); ?>"
									placeholder="<?php echo esc_attr( $icon['default'] ); ?>">
								<button type="button" class="button fl-pick-icon"><?php esc_html_e( 'Media Library', 'favorite-list' ); ?></button>
							</div>
							<p class="description">
								<?php echo esc_html( $icon['desc'] ); ?>
								<?php esc_html_e( 'Leave empty to use the plugin’s default heart icon.', 'favorite-list' ); ?>
							</p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h2><?php esc_html_e( 'Visitors who are not logged in', 'favorite-list' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fl-guest-url"><?php esc_html_e( 'Redirect to', 'favorite-list' ); ?></label></th>
					<td>
						<input type="text" id="fl-guest-url" class="large-text code" dir="ltr"
							name="<?php echo esc_attr( $name . '[guest_url]' ); ?>"
							value="<?php echo esc_attr( $settings['guest_url'] ); ?>"
							placeholder="<?php echo esc_attr( wp_login_url() . '?redirect_to={current_url}' ); ?>">
						<p class="description">
							<?php esc_html_e( 'Only logged-in users can use the favorites list. Visitors who click the icon are sent to this address.', 'favorite-list' ); ?><br>
							<?php esc_html_e( 'Leave empty to use the WordPress login page; after logging in they come back to the same post.', 'favorite-list' ); ?><br>
							<?php
							/* translators: %s: the {current_url} placeholder */
							printf( esc_html__( 'Use %s in the address to insert the page the visitor was on, e.g.', 'favorite-list' ), '<code>{current_url}</code>' );
							?>
							<code dir="ltr"><?php echo esc_html( home_url( '/my-account/?redirect={current_url}' ) ); ?></code>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/* ---------- How to use ---------- */

	/** Theme files that already contain the favorite button (template tag, class or shortcode). */
	private static function scan_theme() {
		$dirs    = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
		$needles = array( 'fl_favorite_button', 'fl-favorite-btn', '[favorite_button' );
		$found   = array();
		$count   = 0;

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$it = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
					function ( $file ) {
						return ! ( $file->isDir() && in_array( $file->getFilename(), array( 'node_modules', 'vendor', '.git' ), true ) );
					}
				)
			);
			foreach ( $it as $file ) {
				if ( ++$count > 800 ) {
					break 2;
				}
				if ( 'php' !== strtolower( $file->getExtension() ) || $file->getSize() > 1048576 ) {
					continue;
				}
				$lines = @file( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( ! $lines ) {
					continue;
				}
				foreach ( $lines as $i => $line ) {
					foreach ( $needles as $needle ) {
						if ( false !== strpos( $line, $needle ) ) {
							$found[] = array(
								'file' => self::short_path( $file->getPathname() ),
								'line' => $i + 1,
							);
							break;
						}
					}
				}
			}
		}

		return $found;
	}

	private static function short_path( $path ) {
		return str_replace( wp_normalize_path( WP_CONTENT_DIR ), 'wp-content', wp_normalize_path( $path ) );
	}

	private static function code_block( $code, $id ) {
		?>
		<div class="fl-code">
			<pre id="<?php echo esc_attr( $id ); ?>" dir="ltr"><code><?php echo esc_html( $code ); ?></code></pre>
			<button type="button" class="button fl-copy" data-target="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Copy', 'favorite-list' ); ?></button>
		</div>
		<?php
	}

	private static function tab_usage() {
		$found = self::scan_theme();
		$theme = self::short_path( get_stylesheet_directory() ) . '/';
		$size  = FL_Favorite_List::icon_size();

		$template_tag = "<?php if ( function_exists( 'fl_favorite_button' ) ) fl_favorite_button(); ?>";

		$custom_html = '<a href="#" class="fl-favorite-btn" data-post-id="<?php the_ID(); ?>">' . "\n" .
			'    <img src="<?php echo esc_url( fl_get_icon_url() ); ?>" alt="" width="' . (int) $size[0] . '" height="' . (int) $size[1] . '">' . "\n" .
			'    ' . FL_Favorite_List::i18n()['label'] . "\n" .
			'</a>';
		?>
		<div class="fl-status <?php echo $found ? 'is-ok' : ''; ?>">
			<?php if ( $found ) : ?>
				<p><strong><?php esc_html_e( 'The favorite button is in your theme:', 'favorite-list' ); ?></strong></p>
				<ul>
					<?php foreach ( $found as $hit ) : ?>
						<li>
							<code dir="ltr"><?php echo esc_html( $hit['file'] ); ?></code>
							<?php
							/* translators: %d: line number */
							printf( esc_html__( 'line %d', 'favorite-list' ), (int) $hit['line'] );
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'The favorite button hasn’t been added to your theme yet.', 'favorite-list' ); ?></strong>
				<?php esc_html_e( 'Follow step 1 below, or use the shortcode inside your posts.', 'favorite-list' ); ?></p>
			<?php endif; ?>
		</div>

		<h2><?php esc_html_e( '1. Show the favorite button', 'favorite-list' ); ?></h2>
		<p><?php esc_html_e( 'Place this code in your theme where you want the button to appear:', 'favorite-list' ); ?></p>
		<?php self::code_block( $template_tag, 'fl-code-tag' ); ?>
		<ul class="fl-notes">
			<li><?php esc_html_e( 'Put it inside the post loop. The usual place is the single post template: single.php, or a template part it loads such as template-parts/content-single.php. It also works in archive and blog templates, showing a button for each post.', 'favorite-list' ); ?></li>
			<li><?php esc_html_e( 'If your theme already has a favorite or bookmark icon, replace that link with this code.', 'favorite-list' ); ?></li>
			<li>
				<?php esc_html_e( 'Your active theme folder:', 'favorite-list' ); ?> <code dir="ltr"><?php echo esc_html( $theme ); ?></code>.
				<?php esc_html_e( 'Use a child theme if you can, so a theme update doesn’t remove the code.', 'favorite-list' ); ?>
			</li>
			<li><?php esc_html_e( 'The button uses the text, icons and size from the General tab, and shows the right state for the logged-in user.', 'favorite-list' ); ?></li>
		</ul>

		<p><strong><?php esc_html_e( 'No access to theme files?', 'favorite-list' ); ?></strong>
		<?php esc_html_e( 'Add this shortcode to a post, or to a widget or block template (Shortcode block):', 'favorite-list' ); ?></p>
		<?php self::code_block( '[favorite_button]', 'fl-code-button-sc' ); ?>

		<details class="fl-alt">
			<summary><?php esc_html_e( 'Customize the button', 'favorite-list' ); ?></summary>

			<p><?php esc_html_e( 'Icon only, without text:', 'favorite-list' ); ?></p>
			<?php self::code_block( "<?php fl_favorite_button( null, array( 'label' => '' ) ); ?>", 'fl-code-icon-only' ); ?>

			<p><?php esc_html_e( 'Different text and an extra CSS class:', 'favorite-list' ); ?></p>
			<?php self::code_block( "<?php fl_favorite_button( null, array( 'label' => 'Save', 'class' => 'my-button' ) ); ?>", 'fl-code-args' ); ?>

			<p><?php esc_html_e( 'A button for a specific post (outside the loop):', 'favorite-list' ); ?></p>
			<?php self::code_block( '<?php fl_favorite_button( 123 ); ?>', 'fl-code-id' ); ?>

			<p><?php esc_html_e( 'Your own HTML: give any link or button the class fl-favorite-btn and the post ID. The plugin handles the click and swaps the image inside it.', 'favorite-list' ); ?></p>
			<?php self::code_block( $custom_html, 'fl-code-custom' ); ?>
		</details>

		<h2><?php esc_html_e( '2. Create the favorites page', 'favorite-list' ); ?></h2>
		<p>
			<?php esc_html_e( 'Create a page (e.g. “My favorites”) and put this shortcode in it. It lists the user’s favorite posts, each with a heart to remove it.', 'favorite-list' ); ?>
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=page' ) ); ?>"><?php esc_html_e( 'Add a new page', 'favorite-list' ); ?></a>
		</p>
		<?php self::code_block( '[favorite_list]', 'fl-code-list' ); ?>
		<p class="description">
			<?php esc_html_e( 'Options:', 'favorite-list' ); ?>
			<code dir="ltr">[favorite_list thumbnail="no"]</code>
			<code dir="ltr">[favorite_list empty="…"]</code>
		</p>

		<h2><?php esc_html_e( 'For developers', 'favorite-list' ); ?></h2>
		<table class="widefat striped fl-ref">
			<tbody>
				<tr>
					<td><code dir="ltr">fl_favorite_count()</code></td>
					<td><?php esc_html_e( 'How many users added the current post to their favorites.', 'favorite-list' ); ?></td>
				</tr>
				<tr>
					<td><code dir="ltr">fl_is_favorite( $post_id )</code></td>
					<td><?php esc_html_e( 'Whether a post is in the current user’s favorites.', 'favorite-list' ); ?></td>
				</tr>
				<tr>
					<td><code dir="ltr">fl_get_favorites()</code></td>
					<td><?php esc_html_e( 'Array of the current user’s favorite post IDs, for custom templates.', 'favorite-list' ); ?></td>
				</tr>
				<tr>
					<td><code dir="ltr">fl_get_icon_url( $active )</code></td>
					<td><?php esc_html_e( 'URL of the normal or active icon.', 'favorite-list' ); ?></td>
				</tr>
				<tr>
					<td><code dir="ltr">fl_post_types</code></td>
					<td><?php esc_html_e( 'Filter: post types that can be favorited (default: posts).', 'favorite-list' ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/* ---------- Updates (same as Multi Post Adder) ---------- */

	private static function tab_updates() {
		$settings  = FL_GitHub_Updater::get_settings();
		$cached    = get_site_transient( FL_GitHub_Updater::CACHE_KEY );
		$has_new   = is_array( $cached ) && ! empty( $cached['version'] ) && version_compare( $cached['version'], FL_VERSION, '>' );
		$check_url = wp_nonce_url( admin_url( 'admin-post.php?action=fl_check_updates&return=settings' ), 'fl_check_updates' );
		?>
		<h2><?php esc_html_e( 'Update status', 'favorite-list' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Installed version', 'favorite-list' ); ?></th>
				<td><code><?php echo esc_html( FL_VERSION ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Latest version on GitHub', 'favorite-list' ); ?></th>
				<td>
					<?php if ( ! FL_GitHub_Updater::is_enabled() ) : ?>
						<em><?php esc_html_e( 'Update checks are turned off.', 'favorite-list' ); ?></em>
					<?php elseif ( FL_GitHub_Updater::is_blocked() ) : ?>
						<em><?php esc_html_e( 'Outgoing requests to GitHub are blocked on this site (WP_HTTP_BLOCK_EXTERNAL).', 'favorite-list' ); ?></em>
					<?php elseif ( is_array( $cached ) && ! empty( $cached['error'] ) ) : ?>
						<span class="fl-error"><?php echo esc_html( $cached['error'] ); ?></span>
					<?php elseif ( is_array( $cached ) && ! empty( $cached['version'] ) ) : ?>
						<code><?php echo esc_html( $cached['version'] ); ?></code>
						<?php if ( $has_new ) : ?>
							&nbsp;<a class="button button-primary" href="<?php echo esc_url( self_admin_url( 'update-core.php' ) ); ?>"><?php esc_html_e( 'Update now', 'favorite-list' ); ?></a>
						<?php else : ?>
							&nbsp;<span class="fl-ok"><?php esc_html_e( 'You are up to date.', 'favorite-list' ); ?></span>
						<?php endif; ?>
					<?php else : ?>
						<em><?php esc_html_e( 'Not checked yet.', 'favorite-list' ); ?></em>
					<?php endif; ?>
					<?php if ( is_array( $cached ) && ! empty( $cached['checked_at'] ) ) : ?>
						<p class="description">
							<?php
							/* translators: %s: human readable time difference, e.g. "5 mins" */
							printf( esc_html__( 'Last checked %s ago.', 'favorite-list' ), esc_html( human_time_diff( $cached['checked_at'] ) ) );
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php if ( FL_GitHub_Updater::is_enabled() && ! FL_GitHub_Updater::is_blocked() ) : ?>
			<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates now', 'favorite-list' ); ?></a></p>
		<?php endif; ?>

		<hr>

		<h2><?php esc_html_e( 'GitHub source', 'favorite-list' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'fl_save_updates', 'fl_updates_nonce' ); ?>
			<input type="hidden" name="fl-settings-action" value="save_updates">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Update checks', 'favorite-list' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="fl-update-enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
							<?php esc_html_e( 'Check GitHub for new versions', 'favorite-list' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Turn this off on sites without internet access (e.g. a private network). The plugin works fully without it.', 'favorite-list' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fl-update-repo"><?php esc_html_e( 'Repository', 'favorite-list' ); ?></label></th>
					<td>
						<input type="text" id="fl-update-repo" name="fl-update-repo" class="regular-text" dir="ltr" value="<?php echo esc_attr( $settings['repo'] ); ?>" placeholder="owner/repository">
						<p class="description"><?php esc_html_e( 'Format: owner/repository', 'favorite-list' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Update source', 'favorite-list' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="fl-update-source" value="branch" <?php checked( $settings['source'], 'branch' ); ?>>
								<?php esc_html_e( 'Branch: offer an update whenever the Version in the plugin file on the branch is higher', 'favorite-list' ); ?>
							</label><br>
							<label>
								<input type="radio" name="fl-update-source" value="release" <?php checked( $settings['source'], 'release' ); ?>>
								<?php esc_html_e( 'Releases: offer an update only when a new GitHub Release is published', 'favorite-list' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fl-update-branch"><?php esc_html_e( 'Branch', 'favorite-list' ); ?></label></th>
					<td><input type="text" id="fl-update-branch" name="fl-update-branch" class="regular-text" dir="ltr" value="<?php echo esc_attr( $settings['branch'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="fl-update-token"><?php esc_html_e( 'Access token', 'favorite-list' ); ?></label></th>
					<td>
						<input type="password" id="fl-update-token" name="fl-update-token" class="regular-text" dir="ltr" value="" autocomplete="new-password"
							placeholder="<?php echo $settings['token'] ? esc_attr__( 'Saved — leave empty to keep', 'favorite-list' ) : ''; ?>">
						<?php if ( $settings['token'] ) : ?>
							<br><label><input type="checkbox" name="fl-update-token-remove" value="1"> <?php esc_html_e( 'Remove saved token', 'favorite-list' ); ?></label>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Only needed if the repository is private. Use a fine-grained token with read-only “Contents” access to this repository.', 'favorite-list' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Changes', 'favorite-list' ) ); ?>
		</form>
		<?php
	}

	public static function save_update_settings() {
		if ( empty( $_POST['fl-settings-action'] ) || 'save_updates' !== $_POST['fl-settings-action'] ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( empty( $_POST['fl_updates_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fl_updates_nonce'] ) ), 'fl_save_updates' ) ) {
			return;
		}

		$current = FL_GitHub_Updater::get_settings();

		$repo = isset( $_POST['fl-update-repo'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['fl-update-repo'] ) ) ) : '';
		$repo = preg_replace( '#^https?://github\.com/#i', '', $repo );
		$repo = preg_replace( '#\.git$#i', '', trim( $repo, '/ ' ) );
		if ( ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ) {
			$repo = FL_GitHub_Updater::DEFAULT_REPO;
		}

		$branch = isset( $_POST['fl-update-branch'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['fl-update-branch'] ) ) ) : '';
		$source = ( isset( $_POST['fl-update-source'] ) && 'release' === $_POST['fl-update-source'] ) ? 'release' : 'branch';

		$token = $current['token'];
		if ( ! empty( $_POST['fl-update-token-remove'] ) ) {
			$token = '';
		}
		if ( ! empty( $_POST['fl-update-token'] ) ) {
			$token = trim( sanitize_text_field( wp_unslash( $_POST['fl-update-token'] ) ) );
		}

		FL_GitHub_Updater::save_settings(
			array(
				'repo'    => $repo,
				'branch'  => $branch ? $branch : FL_GitHub_Updater::DEFAULT_BRANCH,
				'source'  => $source,
				'token'   => $token,
				'enabled' => ! empty( $_POST['fl-update-enabled'] ),
			)
		);

		wp_safe_redirect( self::url( 'updates', array( 'fl-message' => 'updates-saved' ) ) );
		exit;
	}

	/** "Check for updates now" (settings tab and Plugins screen link). */
	public static function check_updates_now() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to update plugins.', 'favorite-list' ) );
		}
		check_admin_referer( 'fl_check_updates' );

		FL_GitHub_Updater::clear_cache();
		wp_update_plugins();

		if ( isset( $_GET['return'] ) && 'settings' === $_GET['return'] ) {
			wp_safe_redirect( self::url( 'updates', array( 'fl-message' => 'updates-checked' ) ) );
		} else {
			wp_safe_redirect( self_admin_url( 'plugins.php' ) );
		}
		exit;
	}
}
