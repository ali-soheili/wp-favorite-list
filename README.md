# Favorite List

A WordPress plugin that lets logged-in users add posts to (or remove them from) their personal favorites list.

## Features

- Add / remove a post with one click (AJAX, no page reload)
- Comes with its own heart icons; use any icons you like from the settings
- Gentle heart-beat animation when a post is added, a small shrink when it is removed
- Logged-in users only: visitors are sent to the login page (or any address you set) and come back to the post afterwards
- `[favorite_list]` shortcode for a "My favorites" page
- Works with page caching (the state is re-checked after the page loads)
- Settings page with a step-by-step guide
- One-click updates from GitHub
- Translation-ready, with a Persian (fa_IR) translation

## Installation

1. Upload the plugin folder to `wp-content/plugins/` (or upload the zip under **Plugins → Add New → Upload Plugin**).
2. Activate **Favorite List**.
3. Go to **Settings → Favorite List → How to use** and follow the steps.

## Usage

Place this code in your theme where you want the button to appear (inside the post loop, usually in `single.php` or a template part such as `template-parts/content-single.php`):

```php
<?php if ( function_exists( 'fl_favorite_button' ) ) fl_favorite_button(); ?>
```

If your theme already has a favorite or bookmark icon, replace that link with this code. Without access to theme files, use the `[favorite_button]` shortcode inside a post or a Shortcode block.

Then create a page with the shortcode `[favorite_list]`.

| Code | What it does |
| --- | --- |
| `fl_favorite_button()` | The button for the current post (inside the loop) |
| `fl_favorite_button( null, array( 'label' => '' ) )` | Icon only, no text |
| `fl_favorite_button( null, array( 'label' => 'Save', 'class' => 'my-button' ) )` | Custom text and CSS class |
| `fl_favorite_button( 123 )` | The button for a specific post |
| `[favorite_button]` | The button inside post content |
| `[favorite_list]` | The current user's favorites page (`thumbnail="no"`, `empty="…"`) |
| `fl_favorite_count()` | How many users favorited the current post |
| `fl_is_favorite( $post_id )` | Whether a post is in the current user's favorites |
| `fl_get_favorites()` | Array of the current user's favorite post IDs |
| `fl_get_icon_url( $active )` | URL of the normal or active icon |

### Your own HTML

Any link or button with the class `fl-favorite-btn` and a `data-post-id` works. The plugin handles the click and swaps the image inside it:

```php
<a href="#" class="fl-favorite-btn" data-post-id="<?php the_ID(); ?>">
    <img src="<?php echo esc_url( fl_get_icon_url() ); ?>" alt="" width="22" height="22">
    Favorites
</a>
```

## Settings (Settings → Favorite List)

| Tab | Setting |
| --- | --- |
| General | **Button text**, **Icon size**, **Normal icon** and **Active icon** (empty = the plugin's heart icons). **Redirect to**: where visitors who are not logged in go. Empty = WordPress login page. `{current_url}` is replaced with the page they were on. |
| How to use | The code and where to put it. Shows where the button already is in your theme. |
| Updates | GitHub update settings (see below). |

Developers can also use the filters `fl_icon_urls`, `fl_guest_url`, `fl_post_types` (default `['post']`) and `fl_i18n` (front-end texts), and the action `fl_favorite_changed`.

### Upgrading from 1.1

Version 1.1 always used the theme icons at `images/post-info-box/favorite.svg` and `favorite-active.svg`. When 1.2 finds those files in the active theme, it saves them in the settings (with the old size and text), so the site looks the same after the update.

## Updates from GitHub

The plugin updates itself from its GitHub repository, just like plugins from wordpress.org: new versions appear under **Dashboard → Updates** and **Plugins**, with an **Update now** link and support for WordPress auto-updates.

### Publishing a new version

1. Make your changes.
2. Increase the `Version:` number in the header of `favorite-list.php` **and** the `FL_VERSION` constant below it (e.g. `1.2.0` → `1.3.0`). **WordPress only offers an update when this number goes up.**
3. Commit and push to `main`.

Sites check GitHub roughly every 12 hours. To check immediately use **Settings → Favorite List → Updates → Check for updates now**, the **Check for updates** link under the plugin on the Plugins screen, or **Dashboard → Updates → Check again**.

| Setting | Description |
| --- | --- |
| Update checks | On/off. Turn off on sites without internet access. |
| Repository | `owner/repository`. Default: `ali-soheili/wp-favorite-list`. |
| Update source | **Branch** (default) or **Releases** (tag `v1.3.0` / `1.3.0` is the version; an attached `.zip` is used if present). |
| Branch | Branch to follow in Branch mode. Default: `main`. |
| Access token | Only for private repositories: a fine-grained token with read-only **Contents** access. |

Requires WordPress 5.8 or newer (uses the `Update URI` plugin header).

## Offline / private network use

The plugin is fully self-contained: it loads only its own files and icons (or the icon URLs you set) and WordPress's built-in scripts. Nothing comes from a CDN or an outside service, so it works on a site with no internet access.

The only online feature is the optional GitHub update check:

- Turn it off under **Settings → Favorite List → Updates → Update checks**.
- If `WP_HTTP_BLOCK_EXTERNAL` is set in `wp-config.php` (and `api.github.com` isn't in `WP_ACCESSIBLE_HOSTS`), the check is skipped automatically.
- If left on without internet, a check gives up after 5 seconds and is retried at most every 6 hours.

To update a site in a private network, upload the new zip under **Plugins → Add New → Upload Plugin** and choose **Replace current with uploaded**.

## Project structure

```text
favorite-list/
├── favorite-list.php                  # Main plugin file (front end, AJAX, shortcodes)
├── includes/
│   ├── class-fl-admin.php             # Settings → Favorite List
│   └── class-fl-github-updater.php    # Updates from GitHub
├── assets/
│   ├── favorite-list.js / .css        # Front end
│   ├── icons/                         # Default heart icons
│   └── admin.js / .css                # Settings page
├── languages/                         # Translations (fa_IR)
└── uninstall.php                      # Removes all plugin data when deleted
```
