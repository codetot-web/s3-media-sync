<?php
/**
 * Plugin Name: S3 Media Sync
 * Description: Sync WordPress media to S3-compatible storage (initial skeleton).
 * Version: 0.1.0
 * Author: Auto-generated
 * Text Domain: s3-media-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'S3_MEDIA_SYNC_VERSION', '0.1.0' );
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
    $instance = S3_Media_Sync::instance();
    if ( is_admin() ) {
        S3_Media_Sync_Admin::instance();
    }
}

add_action( 'plugins_loaded', 's3_media_sync_init_plugin' );

register_activation_hook( __FILE__, array( 'S3_Media_Sync', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'S3_Media_Sync', 'deactivate' ) );

// End of file
