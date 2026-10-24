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
								<?php esc_html_e( 'Leave empty to use the theme icon shown as placeholder.', 'favorite-list' ); ?>
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

	/** Finds theme files that contain the favorite icon, so the admin knows where to paste the code. */
	private static function scan_theme() {
		$dirs   = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
		$found  = array();
		$done   = false;
		$count  = 0;

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
					$is_icon = false !== strpos( $line, 'post-info-box/favorite.svg' );
					$is_done = false !== strpos( $line, 'fl_favorite_button' ) || false !== strpos( $line, 'fl-favorite-btn' );
					if ( $is_done ) {
						$done = true;
					}
					if ( $is_icon || $is_done ) {
						$found[] = array(
							'file' => str_replace( wp_normalize_path( WP_CONTENT_DIR ), 'wp-content', wp_normalize_path( $file->getPathname() ) ),
							'line' => $i + 1,
							'done' => $is_done,
						);
					}
				}
			}
		}

		return array(
			'found' => $found,
			'done'  => $done,
		);
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
		$scan   = self::scan_theme();
		$single = str_replace( wp_normalize_path( WP_CONTENT_DIR ), 'wp-content', wp_normalize_path( get_template_directory() ) ) . '/single.php';

		$original = '<a href="#">' . "\n" .
			'    <img src="<?php bloginfo(\'template_url\'); ?>/images/post-info-box/favorite.svg" alt="favorite" width="23px" height="22px" >' . "\n" .
			'    لیست علاقه مندی' . "\n" .
			'</a>';

		$recommended = "<?php if ( function_exists( 'fl_favorite_button' ) ) fl_favorite_button(); ?>";

		$manual = '<a href="#" class="fl-favorite-btn" data-post-id="<?php the_ID(); ?>">' . "\n" .
			'    <img src="<?php bloginfo(\'template_url\'); ?>/images/post-info-box/favorite.svg" alt="favorite" width="23" height="22">' . "\n" .
			'    لیست علاقه مندی' . "\n" .
			'</a>';
		?>
		<h2><?php esc_html_e( '1. Add the favorite button to the single post page', 'favorite-list' ); ?></h2>

		<div class="fl-status <?php echo $scan['done'] ? 'is-ok' : ''; ?>">
			<?php if ( $scan['done'] ) : ?>
				<p><strong><?php esc_html_e( 'The favorite button is already in your theme.', 'favorite-list' ); ?></strong></p>
			<?php elseif ( $scan['found'] ) : ?>
				<p><strong><?php esc_html_e( 'The favorite icon was found in your theme here. This is where the code goes:', 'favorite-list' ); ?></strong></p>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'The favorite icon was not found in the active theme. Put the code in the single post template:', 'favorite-list' ); ?></strong> <code dir="ltr"><?php echo esc_html( $single ); ?></code></p>
			<?php endif; ?>

			<?php if ( $scan['found'] ) : ?>
				<ul>
					<?php foreach ( $scan['found'] as $hit ) : ?>
						<li>
							<code dir="ltr"><?php echo esc_html( $hit['file'] ); ?></code>
							<?php
							/* translators: %d: line number */
							printf( esc_html__( 'line %d', 'favorite-list' ), (int) $hit['line'] );
							?>
							<?php if ( $hit['done'] ) : ?>
								— <span class="fl-ok"><?php esc_html_e( 'done', 'favorite-list' ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<ol class="fl-steps">
			<li>
				<?php esc_html_e( 'Open the single post template of your theme (usually single.php, or the template part it includes):', 'favorite-list' ); ?>
				<code dir="ltr"><?php echo esc_html( $single ); ?></code>
			</li>
			<li>
				<?php esc_html_e( 'Find the current favorite link. It looks like this:', 'favorite-list' ); ?>
				<?php self::code_block( $original, 'fl-code-original' ); ?>
			</li>
			<li>
				<?php esc_html_e( 'Replace the whole link with this one line:', 'favorite-list' ); ?>
				<?php self::code_block( $recommended, 'fl-code-recommended' ); ?>
				<p class="description"><?php esc_html_e( 'It prints the same link and icon, already showing the right state, and uses the icons from the General tab. It must be inside the post loop (the single post page is fine).', 'favorite-list' ); ?></p>
			</li>
		</ol>

		<details class="fl-alt">
			<summary><?php esc_html_e( 'Prefer to keep your own HTML?', 'favorite-list' ); ?></summary>
			<p><?php esc_html_e( 'Add the class fl-favorite-btn and the post ID to your existing link. The plugin swaps the icon by itself.', 'favorite-list' ); ?></p>
			<?php self::code_block( $manual, 'fl-code-manual' ); ?>
		</details>

		<h2><?php esc_html_e( '2. Create the favorites page', 'favorite-list' ); ?></h2>
		<p>
			<?php esc_html_e( 'Create a page (e.g. “My favorites”) and put this shortcode in it. It lists the user’s favorite posts, each with a heart to remove it.', 'favorite-list' ); ?>
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=page' ) ); ?>"><?php esc_html_e( 'Add a new page', 'favorite-list' ); ?></a>
		</p>
		<?php self::code_block( '[favorite_list]', 'fl-code-list' ); ?>

		<h2><?php esc_html_e( 'Other options', 'favorite-list' ); ?></h2>
		<table class="widefat striped fl-ref">
			<tbody>
				<tr>
					<td><code dir="ltr">[favorite_button]</code></td>
					<td><?php esc_html_e( 'The button inside post content or a widget.', 'favorite-list' ); ?></td>
				</tr>
				<tr>
					<td><code dir="ltr">&lt;?php fl_favorite_button( 123 ); ?&gt;</code></td>
					<td><?php esc_html_e( 'The button for a specific post ID (outside the loop).', 'favorite-list' ); ?></td>
				</tr>
				<tr>
					<td><code dir="ltr">&lt;?php echo fl_favorite_count(); ?&gt;</code></td>
					<td><?php esc_html_e( 'How many users added the current post to their favorites.', 'favorite-list' ); ?></td>
				</tr>
				<tr>
					<td><code dir="ltr">fl_get_favorites()</code></td>
					<td><?php esc_html_e( 'Array of the current user’s favorite post IDs, for custom templates.', 'favorite-list' ); ?></td>
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
