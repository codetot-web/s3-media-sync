<?php
/**
 * Plugin Name: S3 Media Sync
 * Plugin URI:  https://github.com/codetot-huong/s3-media-sync
 * Description: Sync WordPress media library to any S3-compatible storage (AWS S3, iDrive E2, MinIO, Cloudflare R2, etc.). Supports auto-upload on add, manual bulk sync, CDN URL rewriting, and local file cleanup.
 * Version:     1.0.0
 * Author:      codetot-huong
 * Author URI:  https://github.com/codetot-huong
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: s3-media-sync
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'S3_MEDIA_SYNC_VERSION', '1.0.0' );
define( 'S3_MEDIA_SYNC_FILE', __FILE__ );
define( 'S3_MEDIA_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'S3_MEDIA_SYNC_URL', plugin_dir_url( __FILE__ ) );

// Load Composer autoload if present
if ( file_exists( S3_MEDIA_SYNC_PATH . 'vendor/autoload.php' ) ) {
    require_once S3_MEDIA_SYNC_PATH . 'vendor/autoload.php';
}

require_once S3_MEDIA_SYNC_PATH . 'includes/class-s3-media-sync.php';

if ( is_admin() ) {
    require_once S3_MEDIA_SYNC_PATH . 'includes/admin/class-s3-media-sync-admin.php';
}

function s3_media_sync_init_plugin() {
    load_plugin_textdomain( 's3-media-sync', false, dirname( plugin_basename( S3_MEDIA_SYNC_FILE ) ) . '/languages' );
    S3_Media_Sync::instance();
    if ( is_admin() ) {
        S3_Media_Sync_Admin::instance();
    }
}

add_action( 'plugins_loaded', 's3_media_sync_init_plugin' );

register_activation_hook( __FILE__, array( 'S3_Media_Sync', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'S3_Media_Sync', 'deactivate' ) );

// End of file
