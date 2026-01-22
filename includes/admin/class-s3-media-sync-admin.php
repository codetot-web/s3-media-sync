<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class S3_Media_Sync_Admin {
    private static $instance;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_s3_media_sync_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_s3_media_sync_sync_batch', array( $this, 'ajax_sync_batch' ) );
        add_action( 'wp_ajax_s3_media_sync_delete_local', array( $this, 'ajax_delete_local_batch' ) );
    }

    public function add_admin_menu() {
        add_options_page(
            'S3 Media Sync',
            'S3 Media Sync',
            'manage_options',
            's3-media-sync',
            array( $this, 'settings_page' )
        );
        // Tools menu
        add_submenu_page(
            'tools.php',
            'S3 Media Sync',
            'S3 Media Sync',
            'manage_options',
            's3-media-sync-tools',
            array( $this, 'tools_page' )
        );
    }

    public function register_settings() {
        register_setting( 's3_media_sync_options_group', 's3_media_sync_options' );
    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $opts = get_option( 's3_media_sync_options', array() );
        $nonce = wp_create_nonce( 's3_media_sync_test' );
            ?>
            <div class="wrap">
                <h1>S3 Media Sync</h1>
                <form method="post" action="options.php">
                    <?php settings_fields( 's3_media_sync_options_group' ); ?>
                    <?php do_settings_sections( 's3_media_sync_options_group' ); ?>
                    <table class="form-table">
                    <tr>
                        <th scope="row">Enable sync</th>
                        <td>
                            <input type="checkbox" name="s3_media_sync_options[enabled]" value="1" <?php checked( 1, isset( $opts['enabled'] ) ? $opts['enabled'] : 0 ); ?> />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Access Key</th>
                        <td>
                            <input type="text" name="s3_media_sync_options[access_key]" value="<?php echo esc_attr( isset( $opts['access_key'] ) ? $opts['access_key'] : '' ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Secret Key</th>
                        <td>
                            <input type="password" name="s3_media_sync_options[secret_key]" value="<?php echo esc_attr( isset( $opts['secret_key'] ) ? $opts['secret_key'] : '' ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Bucket</th>
                        <td>
                            <input type="text" name="s3_media_sync_options[bucket]" value="<?php echo esc_attr( isset( $opts['bucket'] ) ? $opts['bucket'] : '' ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Endpoint</th>
                        <td>
                            <input type="text" name="s3_media_sync_options[endpoint]" value="<?php echo esc_attr( isset( $opts['endpoint'] ) ? $opts['endpoint'] : '' ); ?>" class="regular-text" />
                            <p class="description">Optional — enter hostname (e.g. <code>o7s1.sg.idrivee2-43.com</code>) or full URL (<code>https://...</code>).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Public Bucket URL</th>
                        <td>
                            <input type="text" name="s3_media_sync_options[public_url]" value="<?php echo esc_attr( isset( $opts['public_url'] ) ? $opts['public_url'] : '' ); ?>" class="regular-text" />
                            <p class="description">Optional — public base URL for your bucket (e.g. <code>https://o1o3.c15.e2-4.dev</code>). Do not include the bucket name in this value; the plugin will append the file path automatically. If you include the bucket, it will be detected and removed.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Test</th>
                        <td>
                            <button type="button" id="s3-media-sync-test" class="button">Test Connection</button>
                            <span id="s3-media-sync-test-result" style="margin-left:12px;"></span>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function enqueue_assets( $hook ) {
        $allowed = array( 'settings_page_s3-media-sync', 'tools_page_s3-media-sync-tools' );
        if ( ! in_array( $hook, $allowed, true ) ) {
            return;
        }
        wp_register_script( 's3-media-sync-admin', S3_MEDIA_SYNC_URL . 'assets/js/admin.js', array( 'jquery' ), S3_MEDIA_SYNC_VERSION, true );
        wp_localize_script( 's3-media-sync-admin', 'S3MediaSync', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce_test'    => wp_create_nonce( 's3_media_sync_test' ),
            'nonce_sync'    => wp_create_nonce( 's3_media_sync_sync' ),
            'nonce_delete'  => wp_create_nonce( 's3_media_sync_delete' ),
        ) );
        wp_enqueue_script( 's3-media-sync-admin' );
    }

    public function ajax_test_connection() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        check_ajax_referer( 's3_media_sync_test', 'nonce' );

        $posted = isset( $_POST['opts'] ) && is_array( $_POST['opts'] ) ? wp_unslash( $_POST['opts'] ) : array();
        $opts = wp_parse_args( $posted, get_option( 's3_media_sync_options', array() ) );

        $access = isset( $opts['access_key'] ) ? $opts['access_key'] : '';
        $secret = isset( $opts['secret_key'] ) ? $opts['secret_key'] : '';
        $bucket = isset( $opts['bucket'] ) ? $opts['bucket'] : '';
        $endpoint = isset( $opts['endpoint'] ) ? $opts['endpoint'] : '';

        if ( empty( $access ) || empty( $secret ) || empty( $bucket ) ) {
            wp_send_json_error( 'Please provide Access Key, Secret Key, and Bucket.' );
        }

        // Normalize endpoint: allow users to enter bare hostnames (no scheme)
        $endpoint = trim( $endpoint );
        if ( ! empty( $endpoint ) && ! preg_match( '#^https?://#i', $endpoint ) ) {
            $endpoint = 'https://' . $endpoint;
        }

        if ( ! class_exists( '\\Aws\\S3\\S3Client' ) ) {
            wp_send_json_error( 'AWS SDK for PHP not found. Please install aws/aws-sdk-php via Composer.' );
        }

        try {
            $client = new \Aws\S3\S3Client( array_filter( array(
                'version' => 'latest',
                'region'  => 'us-east-1',
                'credentials' => array(
                    'key'    => $access,
                    'secret' => $secret,
                ),
                'endpoint' => $endpoint ?: null,
                'use_path_style_endpoint' => true,
            ) ) );

            // Attempt to head the bucket
            $client->headBucket( array( 'Bucket' => $bucket ) );
            wp_send_json_success( 'Connection successful (bucket exists / accessible).' );
        } catch ( Exception $e ) {
            wp_send_json_error( 'Connection failed: ' . $e->getMessage() );
        }
    }

    public function tools_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>S3 Media Sync Tools</h1>
			<h2 style="margin-top:18px;">Manual Sync</h2>
            <p>Run a manual sync of attachments to your configured S3 bucket. This processes in batches to avoid timeouts.</p>
            <p>
                <button id="s3-media-sync-sync" class="button button-primary">Start Manual Sync to S3</button>
                <button id="s3-media-sync-stop" class="button">Stop</button>
            </p>
            <div style="width:100%;max-width:700px;margin-top:12px;">
                <div id="s3-media-sync-progress" style="background:#eee;border:1px solid #ddd;height:18px;position:relative;">
                    <div id="s3-media-sync-progress-bar" style="background:#0073aa;height:100%;width:0%;transition:width .3s;"></div>
                </div>
                <div id="s3-media-sync-status" style="margin-top:8px;font-size:13px;color:#333;"></div>
            </div>
            <h2 style="margin-top:18px;">Delete Local Media</h2>
            <p>Remove local copies of media that have already been synced to S3. This will delete original files and resized variants.</p>
            <p>
                <button id="s3-media-sync-delete-local" class="button button-primary">Delete Local Media (synced only)</button>
                <button id="s3-media-sync-stop-delete" class="button" style="display:none;">Stop Delete</button>
            </p>
        </div>
        <?php
    }

    public function ajax_sync_batch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        check_ajax_referer( 's3_media_sync_sync', 'nonce' );

        $offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
        $batch  = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 10;
        $batch  = max(1, min(50, $batch));

        // Query attachments with pagination and get total count
        $q = new WP_Query( array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $batch,
            'offset' => $offset,
            'fields' => 'ids',
            'no_found_rows' => false,
        ) );

        $total = (int) $q->found_posts;
        $ids = (array) $q->posts;

        if ( $total === 0 ) {
            wp_send_json_success( array(
                'message' => 'No attachments found to sync.',
                'offset' => 0,
                'total' => 0,
                'percent' => 100,
            ) );
        }

        $opts = get_option( 's3_media_sync_options', array() );
        $access = isset( $opts['access_key'] ) ? $opts['access_key'] : '';
        $secret = isset( $opts['secret_key'] ) ? $opts['secret_key'] : '';
        $bucket = isset( $opts['bucket'] ) ? $opts['bucket'] : '';
        $endpoint = isset( $opts['endpoint'] ) ? trim( $opts['endpoint'] ) : '';
        if ( ! empty( $endpoint ) && ! preg_match( '#^https?://#i', $endpoint ) ) {
            $endpoint = 'https://' . $endpoint;
        }

        if ( empty( $access ) || empty( $secret ) || empty( $bucket ) ) {
            wp_send_json_error( 'Please configure Access Key, Secret Key, and Bucket in settings.' );
        }

        if ( ! class_exists( '\\Aws\\S3\\S3Client' ) ) {
            wp_send_json_error( 'AWS SDK for PHP not found. Please install aws/aws-sdk-php via Composer.' );
        }

        try {
            $client = new \Aws\S3\S3Client( array_filter( array(
                'version' => 'latest',
                'region'  => 'us-east-1',
                'credentials' => array(
                    'key'    => $access,
                    'secret' => $secret,
                ),
                'endpoint' => $endpoint ?: null,
                'use_path_style_endpoint' => true,
            ) ) );
        } catch ( Exception $e ) {
            wp_send_json_error( 'Could not create S3 client: ' . $e->getMessage() );
        }

        $uploads = wp_get_upload_dir();
        $base = untrailingslashit( $uploads['basedir'] );

        $processed = 0;
        $succeeded = 0;
        $errors = array();

        foreach ( $ids as $id ) {
            $file = get_attached_file( $id );
            if ( ! $file || ! file_exists( $file ) ) {
                $processed++;
                continue;
            }

            $relative = ltrim( str_replace( $base, '', $file ), '/\\' );
            $key = $relative;

            $attachment_ok = false;

            try {
                $client->putObject( array(
                    'Bucket' => $bucket,
                    'Key'    => $key,
                    'SourceFile' => $file,
                ) );
                $succeeded++;
                $attachment_ok = true;
            } catch ( Exception $e ) {
                $errors[] = sprintf( 'ID %d (original): %s', $id, $e->getMessage() );
            }

            // Try to upload intermediate sizes if metadata exists
            $meta = wp_get_attachment_metadata( $id );
            if ( $meta && ! empty( $meta['sizes'] ) && isset( $meta['file'] ) ) {
                $meta_dir = dirname( $meta['file'] );
                foreach ( $meta['sizes'] as $size_name => $size_meta ) {
                    if ( empty( $size_meta['file'] ) ) {
                        continue;
                    }
                    $size_rel = ltrim( $meta_dir . '/' . $size_meta['file'], '/\\' );
                    $size_full = $base . '/' . $size_rel;
                    if ( ! file_exists( $size_full ) ) {
                        continue;
                    }
                    try {
                        $client->putObject( array(
                            'Bucket' => $bucket,
                            'Key'    => $size_rel,
                            'SourceFile' => $size_full,
                        ) );
                    } catch ( Exception $e ) {
                        $errors[] = sprintf( 'ID %d (size %s): %s', $id, $size_name, $e->getMessage() );
                    }
                }
            }

            // Mark attachment as synced if original uploaded successfully
            if ( $attachment_ok ) {
                update_post_meta( $id, 's3_media_sync_synced', time() );
            }

            $processed++;
        }

        $next_offset = $offset + count( $ids );
        $percent = $total > 0 ? round( ( $next_offset / $total ) * 100 ) : 100;

        wp_send_json_success( array(
            'processed' => $processed,
            'succeeded' => $succeeded,
            'errors' => $errors,
            'offset' => $next_offset,
            'total' => $total,
            'percent' => $percent,
        ) );
    }

    public function ajax_delete_local_batch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        check_ajax_referer( 's3_media_sync_delete', 'nonce' );

        $offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
        $batch  = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 10;
        $batch  = max(1, min(50, $batch));

        $q = new WP_Query( array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $batch,
            'offset' => $offset,
            'fields' => 'ids',
            'meta_key' => 's3_media_sync_synced',
            'no_found_rows' => false,
        ) );

        $total = (int) $q->found_posts;
        $ids = (array) $q->posts;

        if ( $total === 0 ) {
            wp_send_json_success( array(
                'message' => 'No synced attachments found to delete locally.',
                'offset' => 0,
                'total' => 0,
                'percent' => 100,
            ) );
        }

        $uploads = wp_get_upload_dir();
        $base = untrailingslashit( $uploads['basedir'] );

        $processed = 0;
        $succeeded = 0;
        $errors = array();

        foreach ( $ids as $id ) {
            // skip if already removed locally
            if ( get_post_meta( $id, 's3_media_sync_local_removed', true ) ) {
                $processed++;
                continue;
            }

            $file = get_attached_file( $id );
            $deleted_any = false;

            if ( $file && strpos( $file, $base ) === 0 && file_exists( $file ) ) {
                if ( @unlink( $file ) ) {
                    $deleted_any = true;
                } else {
                    $errors[] = sprintf( 'ID %d: could not delete %s', $id, $file );
                }
            }

            $meta = wp_get_attachment_metadata( $id );
            if ( $meta && ! empty( $meta['sizes'] ) && isset( $meta['file'] ) ) {
                $meta_dir = dirname( $meta['file'] );
                foreach ( $meta['sizes'] as $size_name => $size_meta ) {
                    if ( empty( $size_meta['file'] ) ) {
                        continue;
                    }
                    $size_rel = ltrim( $meta_dir . '/' . $size_meta['file'], '/\\' );
                    $size_full = $base . '/' . $size_rel;
                    if ( file_exists( $size_full ) ) {
                        if ( @unlink( $size_full ) ) {
                            $deleted_any = true;
                        } else {
                            $errors[] = sprintf( 'ID %d: could not delete size %s', $id, $size_full );
                        }
                    }
                }
            }

            if ( $deleted_any ) {
                update_post_meta( $id, 's3_media_sync_local_removed', time() );
                $succeeded++;
            }

            $processed++;
        }

        $next_offset = $offset + count( $ids );
        $percent = $total > 0 ? round( ( $next_offset / $total ) * 100 ) : 100;

        wp_send_json_success( array(
            'processed' => $processed,
            'succeeded' => $succeeded,
            'errors' => $errors,
            'offset' => $next_offset,
            'total' => $total,
            'percent' => $percent,
        ) );
    }
}
