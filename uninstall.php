<?php
// Removes the plugin's data when it is deleted from the Plugins screen.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_metadata( 'user', 0, '_fl_favorites', '', true );
delete_metadata( 'post', 0, '_fl_favorite_count', '', true );
delete_option( 'fl_settings' );
delete_option( 'fl_updater_settings' );
delete_site_transient( 'fl_github_update_data' );
