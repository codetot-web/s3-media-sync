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
        add_action( 'admin_init', array( $this, 'load_synced_table_class' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_s3_media_sync_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_s3_media_sync_sync_batch', array( $this, 'ajax_sync_batch' ) );
        add_action( 'wp_ajax_s3_media_sync_delete_local', array( $this, 'ajax_delete_local_batch' ) );
        add_action( 'wp_ajax_s3_media_sync_reset_status', array( $this, 'ajax_reset_sync_status' ) );
        add_action( 'wp_ajax_s3_media_sync_bg_start',        array( $this, 'ajax_bg_start' ) );
        add_action( 'wp_ajax_s3_media_sync_bg_stop',         array( $this, 'ajax_bg_stop' ) );
        add_action( 'wp_ajax_s3_media_sync_bg_status',       array( $this, 'ajax_bg_status' ) );
        add_action( 'wp_ajax_s3_media_sync_bg_reset_offset', array( $this, 'ajax_bg_reset_offset' ) );
        add_action( 'wp_ajax_s3_media_sync_bg_clear_all',   array( $this, 'ajax_bg_clear_all' ) );
    }

    public function load_synced_table_class() {
        require_once S3_MEDIA_SYNC_PATH . 'includes/admin/class-s3-media-sync-synced-table.php';
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
        // Synced Media list
        add_submenu_page(
            'upload.php',
            'S3 Synced Media',
            'S3 Synced Media',
            'manage_options',
            's3-media-sync-synced',
            array( $this, 'synced_media_page' )
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
                        <th scope="row">Region</th>
                        <td>
                            <input type="text" name="s3_media_sync_options[region]" value="<?php echo esc_attr( isset( $opts['region'] ) ? $opts['region'] : 'us-east-1' ); ?>" class="regular-text" />
                            <p class="description">AWS region (e.g. <code>us-east-1</code>, <code>ap-southeast-1</code>). For S3-compatible providers, try <code>us-east-1</code> if unsure.</p>
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
                        <th scope="row">Delete local after upload</th>
                        <td>
                            <input type="checkbox" name="s3_media_sync_options[delete_after_upload]" value="1" <?php checked( 1, isset( $opts['delete_after_upload'] ) ? $opts['delete_after_upload'] : 0 ); ?> />
                            <p class="description">Tự động xoá file local sau khi upload lên S3 thành công. Chỉ bật khi S3 đã hoạt động ổn định.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Disable SSL Verify</th>
                        <td>
                            <input type="checkbox" name="s3_media_sync_options[disable_ssl_verify]" value="1" <?php checked( 1, isset( $opts['disable_ssl_verify'] ) ? $opts['disable_ssl_verify'] : 0 ); ?> />
                            <p class="description">Tắt kiểm tra SSL certificate. Dùng khi endpoint dùng cert tự ký hoặc cert hết hạn.</p>
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

    public function synced_media_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        require_once S3_MEDIA_SYNC_PATH . 'includes/admin/class-s3-media-sync-synced-table.php';

        $table = new S3_Media_Sync_Synced_Table();
        $table->prepare_items();

        $search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">S3 Synced Media</h1>
            <hr class="wp-header-end">
            <form method="get">
                <input type="hidden" name="page" value="s3-media-sync-synced">
                <?php $table->search_box( 'Search files', 's3-media-sync-search' ); ?>
                <?php $table->display(); ?>
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
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'nonce_test'   => wp_create_nonce( 's3_media_sync_test' ),
            'nonce_sync'   => wp_create_nonce( 's3_media_sync_sync' ),
            'nonce_delete' => wp_create_nonce( 's3_media_sync_delete' ),
            'nonce_reset'  => wp_create_nonce( 's3_media_sync_reset' ),
            'nonce_bg'     => wp_create_nonce( 's3_media_sync_bg' ),
        ) );
        wp_enqueue_script( 's3-media-sync-admin' );
    }

    public function ajax_test_connection() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        check_ajax_referer( 's3_media_sync_test', 'nonce' );

        $posted = isset( $_POST['opts'] ) && is_array( $_POST['opts'] ) ? wp_unslash( $_POST['opts'] ) : array();
        $opts   = wp_parse_args( $posted, get_option( 's3_media_sync_options', array() ) );

        $bucket = isset( $opts['bucket'] ) ? $opts['bucket'] : '';

        if ( empty( $opts['access_key'] ) || empty( $opts['secret_key'] ) || empty( $bucket ) ) {
            wp_send_json_error( 'Please provide Access Key, Secret Key, and Bucket.' );
        }

        if ( ! class_exists( '\\Aws\\S3\\S3Client' ) ) {
            wp_send_json_error( 'AWS SDK for PHP not found. Please install aws/aws-sdk-php via Composer.' );
        }

        $client = S3_Media_Sync::get_s3_client( $opts );
        if ( ! $client ) {
            wp_send_json_error( 'Could not create S3 client. Check your credentials.' );
        }

        try {
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
	            <?php
            $bg_state = get_option( 's3_bg_sync_state', array() );
            if ( ! empty( $bg_state ) ) {
                $color = array( 'running' => '#0073aa', 'done' => '#00a32a', 'stopped' => '#888', 'error' => '#d63638' );
                $c = isset( $color[ $bg_state['status'] ] ) ? $color[ $bg_state['status'] ] : '#888';
                echo '<div style="background:#f0f0f0;border-left:4px solid ' . esc_attr( $c ) . ';padding:10px 14px;margin-bottom:16px;font-size:13px;">';
                echo '<strong>BG Sync State:</strong> <span style="color:' . esc_attr( $c ) . '">' . esc_html( strtoupper( $bg_state['status'] ) ) . '</span> &nbsp;|&nbsp; ';
                echo 'Total: <strong>' . (int) ( $bg_state['total'] ?? 0 ) . '</strong> &nbsp;|&nbsp; ';
                echo 'Processed: <strong>' . (int) ( $bg_state['processed'] ?? 0 ) . '</strong> &nbsp;|&nbsp; ';
                echo 'Uploaded: <strong style="color:#00a32a">' . (int) ( $bg_state['succeeded'] ?? 0 ) . '</strong> &nbsp;|&nbsp; ';
                echo 'Skipped: <strong>' . (int) ( $bg_state['skipped'] ?? 0 ) . '</strong> &nbsp;|&nbsp; ';
                echo 'Missing: <strong>' . (int) ( $bg_state['missing'] ?? 0 ) . '</strong> &nbsp;|&nbsp; ';
                echo 'Errors: <strong style="color:#d63638">' . (int) ( $bg_state['errors'] ?? 0 ) . '</strong>';
                if ( ! empty( $bg_state['last_error'] ) ) {
                    echo '<br><span style="color:#d63638">Last error: ' . esc_html( $bg_state['last_error'] ) . '</span>';
                }
                echo '</div>';
            }
            ?>
            <h2>Background Sync (Server-side)</h2>
            <p>Sync runs entirely on the server via WP-Cron — you can close this tab and it will continue.</p>
            <p>
                <button id="s3-bg-start" class="button button-primary">Start Background Sync</button>
                <button id="s3-bg-stop" class="button" style="display:none;">Stop</button>
                <button id="s3-bg-clear" class="button button-secondary" style="margin-left:8px;color:#d63638;border-color:#d63638;">Stop &amp; Clear All (reset)</button>
            </p>
            <div style="width:100%;max-width:700px;margin-top:12px;">
                <div style="background:#eee;border:1px solid #ddd;height:18px;position:relative;">
                    <div id="s3-bg-progress-bar" style="background:#46b450;height:100%;width:0%;transition:width .4s;"></div>
                </div>
                <div id="s3-bg-status" style="margin-top:8px;font-size:13px;color:#333;"></div>
            </div>
            <hr style="margin:24px 0;">
            <h2 style="margin-top:18px;">Manual Sync</h2>
            <p>Run a manual sync of attachments to your configured S3 bucket. This processes in batches to avoid timeouts.</p>
            <p>
                <button id="s3-media-sync-sync" class="button button-primary">Start Manual Sync to S3</button>
                <button id="s3-media-sync-stop" class="button">Stop</button>
                <button id="s3-media-sync-reset-offset" class="button" style="margin-left:8px;">Reset offset (sync từ đầu)</button>
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
            <h2 style="margin-top:18px;">Reset Sync Status</h2>
            <p>Xoá toàn bộ meta <code>s3_media_sync_synced</code> — tất cả file sẽ được coi là chưa sync và có thể chạy lại Manual Sync từ đầu.</p>
            <p>
                <button id="s3-media-sync-reset" class="button button-secondary">Reset Sync Status</button>
                <span id="s3-media-sync-reset-result" style="margin-left:12px;font-size:13px;"></span>
            </p>
        </div>
        <?php
    }

    public function ajax_sync_batch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        check_ajax_referer( 's3_media_sync_sync', 'nonce' );

        @ini_set( 'memory_limit', '512M' );
        @ini_set( 'max_execution_time', '300' );
        @set_time_limit( 300 );

        $last_id = isset( $_POST['last_id'] ) ? intval( $_POST['last_id'] ) : 0;
        $total   = isset( $_POST['total'] )   ? intval( $_POST['total'] )   : 0;
        $batch   = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 5;
        $batch   = max(1, min(50, $batch));

        global $wpdb;

        // Get total only on first batch (total=0), cache result in transient
        if ( $total === 0 ) {
            $total = (int) get_transient( 's3_sync_total' );
            if ( ! $total ) {
                $total = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
                     WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'"
                );
                set_transient( 's3_sync_total', $total, HOUR_IN_SECONDS );
            }
        }

        // Fast ID-based pagination using primary key
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
             AND p.ID > %d
             ORDER BY p.ID ASC
             LIMIT %d",
            $last_id, $batch
        ) );

        if ( empty( $ids ) ) {
            delete_transient( 's3_sync_total' );
            wp_send_json_success( array(
                'done'    => true,
                'last_id' => $last_id,
                'total'   => $total,
            ) );
        }

        $opts   = get_option( 's3_media_sync_options', array() );
        $bucket = isset( $opts['bucket'] ) ? $opts['bucket'] : '';

        if ( empty( $opts['access_key'] ) || empty( $opts['secret_key'] ) || empty( $bucket ) ) {
            wp_send_json_error( 'Please configure Access Key, Secret Key, and Bucket in settings.' );
        }

        if ( ! class_exists( '\\Aws\\S3\\S3Client' ) ) {
            wp_send_json_error( 'AWS SDK for PHP not found. Please install aws/aws-sdk-php via Composer.' );
        }

        $client = S3_Media_Sync::get_s3_client( $opts );
        if ( ! $client ) {
            wp_send_json_error( 'Could not create S3 client. Check your credentials.' );
        }

        $uploads = wp_get_upload_dir();
        $base = untrailingslashit( $uploads['basedir'] );

        $processed = 0;
        $succeeded = 0;
        $skipped   = 0;
        $missing   = 0;
        $errors    = array();

        foreach ( $ids as $id ) {
            $file = get_attached_file( $id );
            if ( ! $file || ! file_exists( $file ) ) {
                $processed++;
                $missing++;
                continue;
            }

            $relative = ltrim( str_replace( $base, '', $file ), '/\\' );
            $key = $relative;

            // Skip if already synced: check post meta first (fast), then verify on S3 (safe)
            if ( get_post_meta( $id, 's3_media_sync_synced', true ) ) {
                $processed++;
                $skipped++;
                continue;
            }


            $attachment_ok = false;

            try {
                $client->putObject( array(
                    'Bucket'     => $bucket,
                    'Key'        => $key,
                    'SourceFile' => $file,
                ) );
                $succeeded++;
                $attachment_ok = true;
            } catch ( Exception $e ) {
                $msg = sprintf( 'ID %d (original): %s', $id, $e->getMessage() );
                $errors[] = $msg;
                error_log( '[s3-media-sync] Sync error: ' . $msg );
            }

            // Upload intermediate sizes
            $meta = wp_get_attachment_metadata( $id );
            if ( $meta && ! empty( $meta['sizes'] ) && isset( $meta['file'] ) ) {
                $meta_dir = dirname( $meta['file'] );
                foreach ( $meta['sizes'] as $size_name => $size_meta ) {
                    if ( empty( $size_meta['file'] ) ) {
                        continue;
                    }
                    $size_rel  = $meta_dir === '.'
                        ? $size_meta['file']
                        : ltrim( $meta_dir . '/' . $size_meta['file'], '/\\' );
                    $size_full = $base . '/' . $size_rel;
                    if ( ! file_exists( $size_full ) ) {
                        continue;
                    }
                    try {
                        $client->putObject( array(
                            'Bucket'     => $bucket,
                            'Key'        => $size_rel,
                            'SourceFile' => $size_full,
                        ) );
                    } catch ( Exception $e ) {
                        $errors[] = sprintf( 'ID %d (size %s): %s', $id, $size_name, $e->getMessage() );
                    }
                }
            }

            if ( $attachment_ok ) {
                update_post_meta( $id, 's3_media_sync_synced', time() );
            }

            $processed++;
        }

        $new_last_id = max( $ids );

        wp_send_json_success( array(
            'done'      => false,
            'succeeded' => $succeeded,
            'skipped'   => $skipped,
            'missing'   => $missing,
            'errors'    => $errors,
            'last_id'   => $new_last_id,
            'total'     => $total,
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
                foreach ( $meta['sizes'] as $size_meta ) {
                    if ( empty( $size_meta['file'] ) ) {
                        continue;
                    }
                    $size_rel = ltrim( $meta_dir . '/' . $size_meta['file'], '/\\' );
                    $size_full = $base . '/' . $size_rel;
                    if ( file_exists( $size_full ) ) {
                        if ( @unlink( $size_full ) ) {
                            $deleted_any = true;
                        } else {
                            $errors[] = sprintf( 'ID %d: could not delete %s', $id, $size_full );
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

    public function ajax_bg_start() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        check_ajax_referer( 's3_media_sync_bg', 'nonce' );

        global $wpdb;

        $existing = get_option( 's3_bg_sync_state', array() );
        $resume   = ! empty( $existing ) && isset( $existing['last_id'] ) && (int) $existing['last_id'] > 0;

        if ( $resume ) {
            // Tiếp tục từ vị trí đã dừng
            $state               = $existing;
            $state['status']     = 'running';
            $state['updated_at'] = time();
        } else {
            // Bắt đầu mới hoàn toàn
            $total = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
                 WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'"
            );

            $state = array(
                'status'     => 'running',
                'last_id'    => 0,
                'total'      => $total,
                'processed'  => 0,
                'succeeded'  => 0,
                'skipped'    => 0,
                'missing'    => 0,
                'errors'     => 0,
                'last_error' => '',
                'started_at' => time(),
                'updated_at' => time(),
            );
        }

        update_option( 's3_bg_sync_state', $state );

        // Clear any previously scheduled event and schedule the first batch
        wp_clear_scheduled_hook( 's3_media_sync_bg_batch' );
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 's3_media_sync_bg_batch', array(), 's3-media-sync' );
            as_schedule_single_action( time() + 1, 's3_media_sync_bg_batch', array(), 's3-media-sync' );
        } else {
            wp_schedule_single_event( time() + 1, 's3_media_sync_bg_batch' );
        }

        wp_send_json_success( $state );
    }

    public function ajax_bg_stop() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        check_ajax_referer( 's3_media_sync_bg', 'nonce' );

        $state = get_option( 's3_bg_sync_state', array() );
        if ( ! empty( $state ) ) {
            $state['status']     = 'stopped';
            $state['updated_at'] = time();
            update_option( 's3_bg_sync_state', $state );
        }

        // Cancel WP-Cron events
        wp_clear_scheduled_hook( 's3_media_sync_bg_batch' );

        // Cancel Action Scheduler pending actions (WooCommerce)
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 's3_media_sync_bg_batch', array(), 's3-media-sync' );
        }

        wp_send_json_success( $state );
    }

    public function ajax_bg_clear_all() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        check_ajax_referer( 's3_media_sync_bg', 'nonce' );

        // Cancel all pending/running Action Scheduler actions
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 's3_media_sync_bg_batch', array(), 's3-media-sync' );
        }
        wp_clear_scheduled_hook( 's3_media_sync_bg_batch' );

        // Reset BG state
        delete_option( 's3_bg_sync_state' );

        // Delete meta via WordPress API so object cache (Redis/Memcached) is properly cleared
        $deleted = (int) delete_metadata( 'post', 0, 's3_media_sync_synced', '', true );
        delete_metadata( 'post', 0, 's3_media_sync_local_removed', '', true );
        wp_cache_flush();

        wp_send_json_success( array(
            'message' => 'Stopped & cleared. Reset ' . $deleted . ' synced files. Ready to start fresh.',
        ) );
    }

    public function ajax_bg_reset_offset() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        check_ajax_referer( 's3_media_sync_bg', 'nonce' );

        wp_clear_scheduled_hook( 's3_media_sync_bg_batch' );

        $state = get_option( 's3_bg_sync_state', array() );
        if ( ! empty( $state ) ) {
            $state['last_id']   = 0;
            $state['processed'] = 0;
            $state['succeeded'] = 0;
            $state['skipped']   = 0;
            $state['missing']   = 0;
            $state['errors']    = 0;
            $state['status']    = 'stopped';
            $state['updated_at'] = time();
            update_option( 's3_bg_sync_state', $state );
        }

        wp_send_json_success( 'Background sync offset reset. Click Start to sync from the beginning.' );
    }

    public function ajax_bg_status() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        check_ajax_referer( 's3_media_sync_bg', 'nonce' );

        $state = get_option( 's3_bg_sync_state', array() );
        if ( empty( $state ) ) {
            wp_send_json_success( array( 'status' => 'idle' ) );
        }
        wp_send_json_success( $state );
    }

    public function ajax_reset_sync_status() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions' );
        }
        check_ajax_referer( 's3_media_sync_reset', 'nonce' );

        global $wpdb;
        $deleted = $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => 's3_media_sync_synced' ) );
        $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => 's3_media_sync_local_removed' ) );

        // Also reset background sync state so BG re-syncs from the beginning
        wp_clear_scheduled_hook( 's3_media_sync_bg_batch' );
        delete_option( 's3_bg_sync_state' );

        wp_send_json_success( 'Reset xong. Đã xoá sync status của ' . intval( $deleted ) . ' file. Background sync cũng được reset.' );
    }
}
