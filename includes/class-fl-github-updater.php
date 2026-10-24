<?php
/**
 * GitHub updater for Favorite List.
 *
 * Lets WordPress find, show and install new versions of this plugin straight
 * from its GitHub repository, exactly like updates from wordpress.org.
 *
 * Two sources are supported:
 *  - "branch"  : reads the "Version:" header of the main plugin file on a branch
 *                (default: main). Push a commit with a higher version number and
 *                the update shows up in the dashboard.
 *  - "release" : uses the latest GitHub Release. The tag (e.g. v1.6 or 1.6) is the
 *                version. If the release has a .zip asset attached it is used,
 *                otherwise GitHub's source zip of that tag is used.
 *
 * Works with public repositories out of the box. For private repositories add a
 * GitHub personal access token (read-only "Contents" permission is enough).
 */
if (!defined('ABSPATH')) exit;

class FL_GitHub_Updater {

    const OPTION          = 'fl_updater_settings';
    const CACHE_KEY       = 'fl_github_update_data';
    const CACHE_TTL       = 6 * HOUR_IN_SECONDS;
    const ERROR_TTL       = 6 * HOUR_IN_SECONDS;
    const DEFAULT_REPO    = 'ali-soheili/wp-favorite-list';
    const DEFAULT_BRANCH  = 'main';

    /** @var string Absolute path to the main plugin file. */
    private $plugin_file;

    /** @var string e.g. "favorite-list/favorite-list.php" */
    private $basename;

    /** @var string Plugin folder name, used as the slug. */
    private $slug;

    public function __construct($plugin_file) {
        $this->plugin_file = $plugin_file;
        $this->basename    = plugin_basename($plugin_file);
        $this->slug        = dirname($this->basename);

        // WordPress 5.8+: plugins with "Update URI: https://github.com/..." are
        // checked through this filter instead of wordpress.org.
        add_filter('update_plugins_github.com', [$this, 'filter_update_check'], 10, 4);

        add_filter('plugins_api', [$this, 'filter_plugin_info'], 20, 3);
        add_filter('http_request_args', [$this, 'filter_http_args'], 10, 2);
        add_filter('upgrader_pre_download', [$this, 'pre_download'], 10, 4);
        add_filter('upgrader_source_selection', [$this, 'fix_source_folder'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'after_update'], 10, 2);
        add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);
    }

    /* ---------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------- */

    public static function get_settings() {
        $saved = get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];

        return wp_parse_args($saved, [
            'repo'   => self::DEFAULT_REPO,
            'branch' => self::DEFAULT_BRANCH,
            'source' => 'branch',
            'token'  => '',
            'enabled' => true,
        ]);
    }

    public static function save_settings(array $settings) {
        update_option(self::OPTION, $settings, false);
        self::clear_cache();
    }

    public static function clear_cache() {
        delete_site_transient(self::CACHE_KEY);
        delete_site_transient('update_plugins');
    }

    /* ---------------------------------------------------------------------
     * Talking to GitHub
     * ------------------------------------------------------------------- */

    private function api_base() {
        $settings = self::get_settings();
        return 'https://api.github.com/repos/' . trim($settings['repo'], '/ ');
    }

    private function api_get($path, $accept = 'application/vnd.github+json') {
        $response = wp_remote_get($this->api_base() . $path, [
            'timeout' => 5, // fail fast on networks without internet access.
            'headers' => ['Accept' => $accept],
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $message = '';
            $json = json_decode($body, true);
            if (is_array($json) && !empty($json['message'])) $message = $json['message'];

            if ($code === 404) {
                $message = __('Repository, branch or release not found. If the repository is private, add an access token.', 'favorite-list');
            } elseif ($code === 401) {
                $message = __('The GitHub access token is invalid or expired.', 'favorite-list');
            } elseif ($code === 403 && stripos($message, 'rate limit') !== false) {
                $message = __('GitHub API rate limit reached. Try again later or add an access token.', 'favorite-list');
            }

            /* translators: 1: HTTP status code, 2: error message */
            return new WP_Error('fl_github_http', sprintf(__('GitHub returned HTTP %1$d. %2$s', 'favorite-list'), $code, $message));
        }

        return $body;
    }

    /**
     * Latest version info from GitHub, cached.
     *
     * @param bool $force Skip the cache.
     * @return array|WP_Error
     */
    /** Update checks can be switched off, e.g. on sites without internet access. */
    public static function is_enabled() {
        $settings = self::get_settings();
        return !empty($settings['enabled']);
    }

    public static function is_blocked() {
        if (!defined('WP_HTTP_BLOCK_EXTERNAL') || !WP_HTTP_BLOCK_EXTERNAL) return false;
        if (!class_exists('WP_Http')) require_once ABSPATH . WPINC . '/class-wp-http.php';
        $http = new WP_Http();
        return $http->block_request('https://api.github.com/');
    }

    public function get_remote_data($force = false) {
        if (!self::is_enabled()) {
            return new WP_Error('fl_updates_disabled', __('Checking GitHub for updates is turned off.', 'favorite-list'));
        }

        // Private networks: if wp-config.php blocks outgoing requests
        // (WP_HTTP_BLOCK_EXTERNAL) and GitHub isn't allowed, don't even try.
        if (self::is_blocked()) {
            return new WP_Error('fl_updates_blocked', __('Outgoing requests to GitHub are blocked on this site (WP_HTTP_BLOCK_EXTERNAL).', 'favorite-list'));
        }

        // The "Check again" button on Dashboard → Updates should always hit GitHub.
        if (!$force && is_admin() && !empty($_GET['force-check'])) $force = true;

        if (!$force) {
            $cached = get_site_transient(self::CACHE_KEY);
            if (is_array($cached)) {
                return !empty($cached['error']) ? new WP_Error('fl_github_cached', $cached['error']) : $cached;
            }
        }

        $settings = self::get_settings();
        $data = $settings['source'] === 'release'
            ? $this->fetch_from_release()
            : $this->fetch_from_branch($settings['branch']);

        if (is_wp_error($data)) {
            set_site_transient(self::CACHE_KEY, [
                'error'      => $data->get_error_message(),
                'checked_at' => time(),
            ], self::ERROR_TTL);
            return $data;
        }

        $data['checked_at'] = time();
        set_site_transient(self::CACHE_KEY, $data, self::CACHE_TTL);
        return $data;
    }

    private function fetch_from_branch($branch) {
        $branch = $branch ?: self::DEFAULT_BRANCH;
        $file   = basename($this->plugin_file);

        $raw = $this->api_get('/contents/' . rawurlencode($file) . '?ref=' . rawurlencode($branch), 'application/vnd.github.raw');
        if (is_wp_error($raw)) return $raw;

        $headers = $this->parse_plugin_headers($raw);
        if (empty($headers['version'])) {
            return new WP_Error('fl_github_no_version', __('Could not read the Version header from the plugin file on GitHub.', 'favorite-list'));
        }

        // Recent commits make a useful changelog in branch mode.
        $changelog = '';
        $last_updated = '';
        $commits = $this->api_get('/commits?per_page=15&sha=' . rawurlencode($branch));
        if (!is_wp_error($commits)) {
            $commits = json_decode($commits, true);
            if (is_array($commits) && $commits) {
                $last_updated = $commits[0]['commit']['committer']['date'] ?? '';
                $items = '';
                foreach ($commits as $commit) {
                    $message = strtok((string) ($commit['commit']['message'] ?? ''), "\n");
                    $date    = substr((string) ($commit['commit']['committer']['date'] ?? ''), 0, 10);
                    $items  .= '<li><code>' . esc_html(substr((string) $commit['sha'], 0, 7)) . '</code> ' . esc_html($message) . ' <em>(' . esc_html($date) . ')</em></li>';
                }
                $changelog = '<h4>' . esc_html__('Recent changes', 'favorite-list') . '</h4><ul>' . $items . '</ul>';
            }
        }

        return [
            'version'      => $headers['version'],
            'requires'     => $headers['requires'],
            'requires_php' => $headers['requires_php'],
            'tested'       => $headers['tested'],
            'package'      => $this->api_base() . '/zipball/' . rawurlencode($branch),
            'changelog'    => $changelog,
            'last_updated' => $last_updated,
        ];
    }

    private function fetch_from_release() {
        $body = $this->api_get('/releases/latest');
        if (is_wp_error($body)) return $body;

        $release = json_decode($body, true);
        if (!is_array($release) || empty($release['tag_name'])) {
            return new WP_Error('fl_github_no_release', __('No published release found on GitHub.', 'favorite-list'));
        }

        $version = ltrim($release['tag_name'], 'vV');
        $package = $release['zipball_url'];

        // Prefer an attached .zip (e.g. a clean build) over the source archive.
        if (!empty($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (substr(strtolower($asset['name']), -4) === '.zip') {
                    // API URL works for private repos too (see filter_http_args).
                    $package = $asset['url'];
                    break;
                }
            }
        }

        return [
            'version'      => $version,
            'requires'     => '',
            'requires_php' => '',
            'tested'       => '',
            'package'      => $package,
            'changelog'    => $this->markdown_to_html((string) ($release['body'] ?? '')),
            'last_updated' => $release['published_at'] ?? '',
            'release_url'  => $release['html_url'] ?? '',
        ];
    }

    private function parse_plugin_headers($contents) {
        $map = [
            'version'      => 'Version',
            'requires'     => 'Requires at least',
            'requires_php' => 'Requires PHP',
            'tested'       => 'Tested up to',
        ];
        $out = [];
        $head = substr($contents, 0, 8192);
        foreach ($map as $key => $label) {
            $out[$key] = preg_match('/^[ \t\/*#@]*' . preg_quote($label, '/') . ':(.*)$/mi', $head, $m)
                ? trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $m[1]))
                : '';
        }
        return $out;
    }

    /** Very small Markdown → HTML for release notes (headings, lists, paragraphs). */
    private function markdown_to_html($md) {
        $md = trim(str_replace("\r\n", "\n", $md));
        if ($md === '') return '';

        $html = '';
        $in_list = false;
        foreach (explode("\n", $md) as $line) {
            $line = rtrim($line);
            if (preg_match('/^\s*[-*+]\s+(.*)$/', $line, $m)) {
                if (!$in_list) { $html .= '<ul>'; $in_list = true; }
                $html .= '<li>' . esc_html($m[1]) . '</li>';
                continue;
            }
            if ($in_list) { $html .= '</ul>'; $in_list = false; }
            if ($line === '') continue;
            if (preg_match('/^#{1,6}\s+(.*)$/', $line, $m)) {
                $html .= '<h4>' . esc_html($m[1]) . '</h4>';
            } else {
                $html .= '<p>' . esc_html($line) . '</p>';
            }
        }
        if ($in_list) $html .= '</ul>';

        return $html;
    }

    /* ---------------------------------------------------------------------
     * WordPress hooks
     * ------------------------------------------------------------------- */

    /**
     * Called by wp_update_plugins() for plugins whose Update URI host is github.com.
     */
    public function filter_update_check($update, $plugin_data, $plugin_file, $locales) {
        if ($plugin_file !== $this->basename) return $update;

        $remote = $this->get_remote_data();
        if (is_wp_error($remote)) return $update;

        $settings = self::get_settings();

        // WordPress compares this version with the installed one and shows
        // the update only when it is higher.
        return [
            'id'           => 'https://github.com/' . $settings['repo'],
            'slug'         => $this->slug,
            'plugin'       => $this->basename,
            'version'      => $remote['version'],
            'url'          => 'https://github.com/' . $settings['repo'],
            'package'      => $remote['package'],
            'tested'       => $remote['tested'],
            'requires_php' => $remote['requires_php'],
        ];
    }

    /**
     * Fills the "View details" popup on the Plugins and Updates screens.
     */
    public function filter_plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $remote = $this->get_remote_data();
        if (is_wp_error($remote)) return $result;

        if (!function_exists('get_plugin_data')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $local    = get_plugin_data($this->plugin_file, false, false);
        $settings = self::get_settings();
        $repo_url = 'https://github.com/' . $settings['repo'];

        $info = new stdClass();
        $info->name          = $local['Name'];
        $info->slug          = $this->slug;
        $info->version       = $remote['version'];
        $info->author        = $local['Author'];
        $info->homepage      = $repo_url;
        $info->requires      = $remote['requires'];
        $info->requires_php  = $remote['requires_php'];
        $info->tested        = $remote['tested'];
        $info->last_updated  = $remote['last_updated'];
        $info->download_link = $remote['package'];
        $info->external      = true;
        $info->sections      = [
            'description' => '<p>' . esc_html($local['Description']) . '</p><p><a href="' . esc_url($repo_url) . '" target="_blank" rel="noopener">' . esc_html__('View on GitHub', 'favorite-list') . '</a></p>',
            'changelog'   => $remote['changelog'] ?: '<p>' . esc_html__('See the GitHub repository for changes.', 'favorite-list') . '</p>',
        ];

        return $info;
    }

    /**
     * Adds the token to requests to this repository (needed for private repos and
     * to raise the API rate limit) and asks for the binary when downloading a
     * release asset.
     */
    public function filter_http_args($args, $url) {
        if (strpos($url, $this->api_base() . '/') !== 0) return $args;

        $settings = self::get_settings();
        if (!isset($args['headers']) || !is_array($args['headers'])) $args['headers'] = [];

        if (!empty($settings['token'])) {
            $args['headers']['Authorization'] = 'Bearer ' . $settings['token'];
        }
        if (strpos($url, '/releases/assets/') !== false) {
            $args['headers']['Accept'] = 'application/octet-stream';
        }
        if (empty($args['headers']['User-Agent']) && empty($args['user-agent'])) {
            $args['user-agent'] = 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/');
        }

        return $args;
    }

    /**
     * Private repos: GitHub answers the authenticated download with a redirect
     * to a short-lived signed URL. Resolve that redirect ourselves so the token
     * is never sent to the storage host (which would reject it).
     */
    public function pre_download($reply, $package, $upgrader = null, $hook_extra = []) {
        if ($reply !== false || !is_string($package) || strpos($package, $this->api_base() . '/') !== 0) return $reply;

        $settings = self::get_settings();
        if (empty($settings['token'])) return $reply; // Public repo: default download works.

        $response = wp_remote_get($package, ['timeout' => 30, 'redirection' => 0]);
        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);

        if (in_array($code, [301, 302, 303, 307, 308], true)) {
            $location = wp_remote_retrieve_header($response, 'location');
            if (!$location) return new WP_Error('fl_download', __('GitHub did not return a download location.', 'favorite-list'));
            return download_url($location, 300);
        }

        if ($code === 200) {
            $tmp = wp_tempnam($package);
            if (!$tmp || file_put_contents($tmp, wp_remote_retrieve_body($response)) === false) {
                return new WP_Error('fl_download', __('Could not save the downloaded update.', 'favorite-list'));
            }
            return $tmp;
        }

        /* translators: %d: HTTP status code */
        return new WP_Error('fl_download', sprintf(__('Download from GitHub failed (HTTP %d).', 'favorite-list'), $code));
    }

    /**
     * GitHub zips extract to "owner-repo-<hash>/". Rename that folder to the
     * plugin's current folder so WordPress replaces the plugin instead of
     * installing a second copy.
     */
    public function fix_source_folder($source, $remote_source, $upgrader, $hook_extra = []) {
        global $wp_filesystem;

        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->basename) return $source;

        $desired = trailingslashit($remote_source) . $this->slug . '/';
        if (untrailingslashit($source) === untrailingslashit($desired)) return $source;

        // If the archive has the plugin file at its root level, use that folder.
        if (!$wp_filesystem->exists(trailingslashit($source) . basename($this->plugin_file))) {
            return new WP_Error('fl_bad_package', __('The downloaded package does not contain the Favorite List plugin file.', 'favorite-list'));
        }

        if ($wp_filesystem->exists($desired)) $wp_filesystem->delete($desired, true);

        if (!$wp_filesystem->move(untrailingslashit($source), untrailingslashit($desired), true)) {
            return new WP_Error('fl_rename_failed', __('Could not rename the update folder.', 'favorite-list'));
        }

        return $desired;
    }

    public function after_update($upgrader, $options) {
        if (($options['type'] ?? '') !== 'plugin') return;
        $plugins = $options['plugins'] ?? (isset($options['plugin']) ? [$options['plugin']] : []);
        if (in_array($this->basename, (array) $plugins, true)) {
            delete_site_transient(self::CACHE_KEY);
        }
    }

    public function plugin_row_meta($links, $file) {
        if ($file !== $this->basename) return $links;

        if (current_user_can('update_plugins') && self::is_enabled()) {
            $links[] = '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=fl_check_updates'), 'fl_check_updates')) . '">' . esc_html__('Check for updates', 'favorite-list') . '</a>';
        }
        return $links;
    }
}
