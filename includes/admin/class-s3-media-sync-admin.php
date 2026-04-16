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
        add_action( 'add_meta_boxes', array( $this, 'add_attachment_meta_box' ) );
        add_filter( 'attachment_fields_to_edit', array( $this, 'add_attachment_field' ), 10, 2 );
        add_action( 'wp_ajax_s3_media_sync_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_s3_media_sync_sync_batch', array( $this, 'ajax_sync_batch' ) );
        add_action( 'wp_ajax_s3_media_sync_delete_local', array( $this, 'ajax_delete_local_batch' ) );
        add_action( 'wp_ajax_s3_media_sync_reset_status', array( $this, 'ajax_reset_sync_status' ) );
        add_action( 'wp_ajax_s3_media_sync_bg_start',        array( $this, 'ajax_bg_start' ) );
        add_action( 'wp_ajax_s3_media_sync_bg_stop',         array( $this, 'ajax_bg_stop' ) );
        add_action( 'wp_ajax_s3_media_sync_bg_status',       array( $this, 'ajax_bg_status' ) );
        add_action( 'wp_ajax_s3_media_sync_bg_reset_offset', array( $this, 'ajax_bg_reset_offset' ) );
        add_action( 'wp_ajax_s3_media_sync_bg_clear_all',   array( $this, 'ajax_bg_clear_all' ) );
        add_action( 'wp_ajax_s3_media_sync_single',          array( $this, 'ajax_sync_single' ) );
        add_action( 'wp_ajax_s3_media_sync_mark_all_synced',  array( $this, 'ajax_mark_all_synced' ) );
        add_action( 'wp_ajax_s3_media_sync_find_missing',     array( $this, 'ajax_find_missing' ) );
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
                        <th scope="row"><?php esc_html_e( 'Enable sync', 's3-media-sync' ); ?></th>
                        <td>
                            <input type="checkbox" name="s3_media_sync_options[enabled]" value="1" <?php checked( 1, isset( $opts['enabled'] ) ? $opts['enabled'] : 0 ); ?> />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Access Key', 's3-media-sync' ); ?></th>
                        <td>
                            <input type="text" name="s3_media_sync_options[access_key]" value="<?php echo esc_attr( isset( $opts['access_key'] ) ? $opts['access_key'] : '' ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Secret Key', 's3-media-sync' ); ?></th>
                        <td>
                            <input type="password" name="s3_media_sync_options[secret_key]" value="<?php echo esc_attr( isset( $opts['secret_key'] ) ? $opts['secret_key'] : '' ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Bucket', 's3-media-sync' ); ?></th>
                        <td>
                            <input type="text" name="s3_media_sync_options[bucket]" value="<?php echo esc_attr( isset( $opts['bucket'] ) ? $opts['bucket'] : '' ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Region', 's3-media-sync' ); ?></th>
                        <td>
                            <input type="text" name="s3_media_sync_options[region]" value="<?php echo esc_attr( isset( $opts['region'] ) ? $opts['region'] : 'us-east-1' ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'AWS region (e.g. us-east-1, ap-southeast-1). For S3-compatible providers, try us-east-1 if unsure.', 's3-media-sync' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Endpoint', 's3-media-sync' ); ?></th>
                        <td>
                            <input type="text" name="s3_media_sync_options[endpoint]" value="<?php echo esc_attr( isset( $opts['endpoint'] ) ? $opts['endpoint'] : '' ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Optional — enter hostname or full URL (https://...).', 's3-media-sync' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Public Bucket URL', 's3-media-sync' ); ?></th>
                        <td>
                            <input type="text" name="s3_media_sync_options[public_url]" value="<?php echo esc_attr( isset( $opts['public_url'] ) ? $opts['public_url'] : '' ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Optional — public base URL for your bucket. Do not include the bucket name; the plugin will append the file path automatically. If you include the bucket, it will be detected and removed.', 's3-media-sync' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Delete local after upload', 's3-media-sync' ); ?></th>
                        <td>
                            <input type="checkbox" name="s3_media_sync_options[delete_after_upload]" value="1" <?php checked( 1, isset( $opts['delete_after_upload'] ) ? $opts['delete_after_upload'] : 0 ); ?> />
                            <p class="description"><?php esc_html_e( 'Automatically delete local files after a successful upload to S3. Only enable when S3 is working reliably.', 's3-media-sync' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Disable SSL Verify', 's3-media-sync' ); ?></th>
                        <td>
                            <input type="checkbox" name="s3_media_sync_options[disable_ssl_verify]" value="1" <?php checked( 1, isset( $opts['disable_ssl_verify'] ) ? $opts['disable_ssl_verify'] : 0 ); ?> />
                            <p class="description"><?php esc_html_e( 'Disable SSL certificate verification. Use when the endpoint uses a self-signed or expired certificate.', 's3-media-sync' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Test', 's3-media-sync' ); ?></th>
                        <td>
                            <button type="button" id="s3-media-sync-test" class="button"><?php esc_html_e( 'Test Connection', 's3-media-sync' ); ?></button>
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
            <h1 class="wp-heading-inline"><?php esc_html_e( 'S3 Synced Media', 's3-media-sync' ); ?></h1>
            <hr class="wp-header-end">
            <form method="get">
                <input type="hidden" name="page" value="s3-media-sync-synced">
                <?php $table->search_box( __( 'Search files', 's3-media-sync' ), 's3-media-sync-search' ); ?>
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
            'ajax_url'       => admin_url( 'admin-ajax.php' ),
            'nonce_test'     => wp_create_nonce( 's3_media_sync_test' ),
            'nonce_sync'     => wp_create_nonce( 's3_media_sync_sync' ),
            'nonce_delete'   => wp_create_nonce( 's3_media_sync_delete' ),
            'nonce_reset'    => wp_create_nonce( 's3_media_sync_reset' ),
            'nonce_bg'       => wp_create_nonce( 's3_media_sync_bg' ),
            'nonce_mark_all'    => wp_create_nonce( 's3_media_sync_mark_all' ),
            'nonce_find_missing'=> wp_create_nonce( 's3_media_sync_find_missing' ),
            'i18n'           => array(
                'testing'                 => __( 'Testing...', 's3-media-sync' ),
                'ajax_error'              => __( 'AJAX error', 's3-media-sync' ),
                'starting'                => __( 'Starting...', 's3-media-sync' ),
                'stopped_click_start'     => __( 'Stopped. Click Start to continue.', 's3-media-sync' ),
                'sync_complete'           => __( 'Sync complete!', 's3-media-sync' ),
                'server_error_auto_retry' => __( 'Server error — Auto-retry', 's3-media-sync' ),
                'batch_error_skipped'     => __( 'Batch error — skipped, continuing...', 's3-media-sync' ),
                'syncing_percent'         => __( 'Syncing —', 's3-media-sync' ),
                'deleting_percent'        => __( 'Deleting —', 's3-media-sync' ),
                'starting_deletion'       => __( 'Starting deletion...', 's3-media-sync' ),
                'deletion_stopped'        => __( 'Deletion stopped. Removed:', 's3-media-sync' ),
                'done_deleted'            => __( 'Done! Deleted local files:', 's3-media-sync' ),
                'error_auto_retry'        => __( 'Error — Auto-retry', 's3-media-sync' ),
                'failed_deleted_so_far'   => __( 'Failed. Deleted so far:', 's3-media-sync' ),
                'refresh_and_retry'       => __( 'Refresh and click again to continue.', 's3-media-sync' ),
                'no_bg_sync_running'      => __( 'No background sync running.', 's3-media-sync' ),
                'bg_done'                 => __( 'Done!', 's3-media-sync' ),
                'bg_stopped'              => __( 'Stopped.', 's3-media-sync' ),
                'bg_error'                => __( 'Error:', 's3-media-sync' ),
                'running'                 => __( 'Running...', 's3-media-sync' ),
                'start_background_sync'   => __( 'Start Background Sync', 's3-media-sync' ),
                'starting_bg'             => __( 'Starting...', 's3-media-sync' ),
                'error_starting_bg'       => __( 'Error starting background sync.', 's3-media-sync' ),
                'stop_clear_all'          => __( 'Stop & Clear All (reset)', 's3-media-sync' ),
                'clearing'                => __( 'Clearing...', 's3-media-sync' ),
                'mark_all_processing'     => __( 'Processing...', 's3-media-sync' ),
                'mark_all_synced_btn'     => __( 'Mark All as Synced', 's3-media-sync' ),
                'resetting'               => __( 'Deleting...', 's3-media-sync' ),
                'manual_sync_offset_reset'=> __( 'Manual sync offset reset.', 's3-media-sync' ),
                'resume_from_id'          => __( 'resume from ID', 's3-media-sync' ),
                'unfinished_progress'     => __( 'There is unfinished progress (last ID:', 's3-media-sync' ),
                'click_start_to_continue' => __( 'Click Start to continue.', 's3-media-sync' ),
                'stopped_status'          => __( 'Stopped.', 's3-media-sync' ),
                'processed'               => __( 'Processed', 's3-media-sync' ),
                'deleted'                 => __( 'Deleted:', 's3-media-sync' ),
                'errors'                  => __( 'Errors:', 's3-media-sync' ),
                'error_unknown'           => __( 'Unknown', 's3-media-sync' ),
                'uploaded'                => __( 'Uploaded:', 's3-media-sync' ),
                'skipped'                 => __( 'Skipped:', 's3-media-sync' ),
                'missing'                 => __( 'Missing:', 's3-media-sync' ),
                'confirm_delete_local'    => __( 'Delete local files of media already synced to S3? This action cannot be undone.', 's3-media-sync' ),
                'confirm_start_bg'        => __( 'Start background sync? All unsynced attachments will be uploaded to S3 on the server.', 's3-media-sync' ),
                'confirm_clear_all'       => __( 'Stop all running actions, clear sync status, and reset everything? This cannot be undone.', 's3-media-sync' ),
                'confirm_mark_all'        => __( 'Mark all media as synced without uploading to S3? Use this when images were already imported to S3 via another tool.', 's3-media-sync' ),
                'confirm_reset_status'    => __( 'Delete all sync status? All files will be treated as not yet synced.', 's3-media-sync' ),
                'last_update'             => __( 'last update:', 's3-media-sync' ),
                'in_seconds'             => __( 'in', 's3-media-sync' ),
                'scanning'               => __( 'Scanning...', 's3-media-sync' ),
                'find_missing'           => __( 'Find Missing Files', 's3-media-sync' ),
                'no_missing_files'       => __( 'No missing files found.', 's3-media-sync' ),
                'missing_files_found'    => __( 'missing file(s) found.', 's3-media-sync' ),
                'edit'                   => __( 'Edit', 's3-media-sync' ),
            ),
        ) );
        wp_enqueue_script( 's3-media-sync-admin' );
    }

    public function ajax_test_connection() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions', 's3-media-sync' ) );
        }
        check_ajax_referer( 's3_media_sync_test', 'nonce' );

        $posted = isset( $_POST['opts'] ) && is_array( $_POST['opts'] ) ? wp_unslash( $_POST['opts'] ) : array();
        $opts   = wp_parse_args( $posted, get_option( 's3_media_sync_options', array() ) );

        $bucket = isset( $opts['bucket'] ) ? $opts['bucket'] : '';

        if ( empty( $opts['access_key'] ) || empty( $opts['secret_key'] ) || empty( $bucket ) ) {
            wp_send_json_error( __( 'Please provide Access Key, Secret Key, and Bucket.', 's3-media-sync' ) );
        }

        if ( ! class_exists( '\\Aws\\S3\\S3Client' ) ) {
            wp_send_json_error( __( 'AWS SDK for PHP not found. Please install aws/aws-sdk-php via Composer.', 's3-media-sync' ) );
        }

        $client = S3_Media_Sync::get_s3_client( $opts );
        if ( ! $client ) {
            wp_send_json_error( __( 'Could not create S3 client. Check your credentials.', 's3-media-sync' ) );
        }

        try {
            $client->headBucket( array( 'Bucket' => $bucket ) );
            wp_send_json_success( __( 'Connection successful (bucket exists / accessible).', 's3-media-sync' ) );
        } catch ( Exception $e ) {
            /* translators: %s: error message from the S3 SDK */
            wp_send_json_error( sprintf( __( 'Connection failed: %s', 's3-media-sync' ), $e->getMessage() ) );
        }
    }

    public function tools_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'S3 Media Sync Tools', 's3-media-sync' ); ?></h1>
	            <?php
            $bg_state = get_option( 's3_bg_sync_state', array() );
            if ( ! empty( $bg_state ) ) {
                $color = array( 'running' => '#0073aa', 'done' => '#00a32a', 'stopped' => '#888', 'error' => '#d63638' );
                $c = isset( $color[ $bg_state['status'] ] ) ? $color[ $bg_state['status'] ] : '#888';
                echo '<div style="background:#f0f0f0;border-left:4px solid ' . esc_attr( $c ) . ';padding:10px 14px;margin-bottom:16px;font-size:13px;">';
                /* translators: %s: background sync status label (e.g. RUNNING, DONE) */
                echo '<strong>' . esc_html__( 'BG Sync State:', 's3-media-sync' ) . '</strong> <span style="color:' . esc_attr( $c ) . '">' . esc_html( strtoupper( $bg_state['status'] ) ) . '</span> &nbsp;|&nbsp; ';
                echo esc_html__( 'Total:', 's3-media-sync' ) . ' <strong>' . (int) ( $bg_state['total'] ?? 0 ) . '</strong> &nbsp;|&nbsp; ';
                echo esc_html__( 'Processed:', 's3-media-sync' ) . ' <strong>' . (int) ( $bg_state['processed'] ?? 0 ) . '</strong> &nbsp;|&nbsp; ';
                echo esc_html__( 'Uploaded:', 's3-media-sync' ) . ' <strong style="color:#00a32a">' . (int) ( $bg_state['succeeded'] ?? 0 ) . '</strong> &nbsp;|&nbsp; ';
                echo esc_html__( 'Skipped:', 's3-media-sync' ) . ' <strong>' . (int) ( $bg_state['skipped'] ?? 0 ) . '</strong> &nbsp;|&nbsp; ';
                echo esc_html__( 'Missing:', 's3-media-sync' ) . ' <strong>' . (int) ( $bg_state['missing'] ?? 0 ) . '</strong> &nbsp;|&nbsp; ';
                echo esc_html__( 'Errors:', 's3-media-sync' ) . ' <strong style="color:#d63638">' . (int) ( $bg_state['errors'] ?? 0 ) . '</strong>';
                if ( ! empty( $bg_state['last_error'] ) ) {
                    /* translators: %s: last error message */
                    echo '<br><span style="color:#d63638">' . esc_html__( 'Last error:', 's3-media-sync' ) . ' ' . esc_html( $bg_state['last_error'] ) . '</span>';
                }
                echo '</div>';
            }
            ?>
            <h2><?php esc_html_e( 'Background Sync (Server-side)', 's3-media-sync' ); ?></h2>
            <p><?php esc_html_e( 'Sync runs entirely on the server via WP-Cron — you can close this tab and it will continue.', 's3-media-sync' ); ?></p>
            <p>
                <button id="s3-bg-start" class="button button-primary"><?php esc_html_e( 'Start Background Sync', 's3-media-sync' ); ?></button>
                <button id="s3-bg-stop" class="button" style="display:none;"><?php esc_html_e( 'Stop', 's3-media-sync' ); ?></button>
                <button id="s3-bg-clear" class="button button-secondary" style="margin-left:8px;color:#d63638;border-color:#d63638;"><?php esc_html_e( 'Stop & Clear All (reset)', 's3-media-sync' ); ?></button>
            </p>
            <div style="width:100%;max-width:700px;margin-top:12px;">
                <div style="background:#eee;border:1px solid #ddd;height:18px;position:relative;">
                    <div id="s3-bg-progress-bar" style="background:#46b450;height:100%;width:0%;transition:width .4s;"></div>
                </div>
                <div id="s3-bg-status" style="margin-top:8px;font-size:13px;color:#333;"></div>
            </div>
            <hr style="margin:24px 0;">
            <h2 style="margin-top:18px;"><?php esc_html_e( 'Manual Sync', 's3-media-sync' ); ?></h2>
            <p><?php esc_html_e( 'Run a manual sync of attachments to your configured S3 bucket. This processes in batches to avoid timeouts.', 's3-media-sync' ); ?></p>
            <p>
                <button id="s3-media-sync-sync" class="button button-primary"><?php esc_html_e( 'Start Manual Sync to S3', 's3-media-sync' ); ?></button>
                <button id="s3-media-sync-stop" class="button"><?php esc_html_e( 'Stop', 's3-media-sync' ); ?></button>
                <button id="s3-media-sync-reset-offset" class="button" style="margin-left:8px;"><?php esc_html_e( 'Reset offset (sync from beginning)', 's3-media-sync' ); ?></button>
            </p>
            <div style="width:100%;max-width:700px;margin-top:12px;">
                <div id="s3-media-sync-progress" style="background:#eee;border:1px solid #ddd;height:18px;position:relative;">
                    <div id="s3-media-sync-progress-bar" style="background:#0073aa;height:100%;width:0%;transition:width .3s;"></div>
                </div>
                <div id="s3-media-sync-status" style="margin-top:8px;font-size:13px;color:#333;"></div>
            </div>
            <h2 style="margin-top:18px;"><?php esc_html_e( 'Delete Local Media', 's3-media-sync' ); ?></h2>
            <p><?php esc_html_e( 'Remove local copies of media that have already been synced to S3. This will delete original files and resized variants.', 's3-media-sync' ); ?></p>
            <p>
                <button id="s3-media-sync-delete-local" class="button button-primary"><?php esc_html_e( 'Delete Local Media (synced only)', 's3-media-sync' ); ?></button>
                <button id="s3-media-sync-stop-delete" class="button" style="display:none;"><?php esc_html_e( 'Stop Delete', 's3-media-sync' ); ?></button>
            </p>
            <h2 style="margin-top:18px;"><?php esc_html_e( 'Mark All as Synced', 's3-media-sync' ); ?></h2>
            <p><?php esc_html_e( 'Mark all media as synced without uploading to S3. Use this when images were already imported to S3 via another tool (rclone, aws cli, etc.) before activating the plugin.', 's3-media-sync' ); ?></p>
            <p>
                <button id="s3-media-sync-mark-all" class="button button-primary"><?php esc_html_e( 'Mark All as Synced', 's3-media-sync' ); ?></button>
                <span id="s3-media-sync-mark-all-result" style="margin-left:12px;font-size:13px;"></span>
            </p>
            <h2 style="margin-top:18px;"><?php esc_html_e( 'Missing Local Files', 's3-media-sync' ); ?></h2>
            <p><?php esc_html_e( 'Find attachments that exist in the database but whose file is missing on the server (cannot be uploaded to S3).', 's3-media-sync' ); ?></p>
            <p>
                <button id="s3-find-missing" class="button"><?php esc_html_e( 'Find Missing Files', 's3-media-sync' ); ?></button>
                <span id="s3-find-missing-status" style="margin-left:10px;font-size:13px;"></span>
            </p>
            <div id="s3-find-missing-results" style="margin-top:10px;display:none;">
                <table class="widefat striped" style="max-width:900px;">
                    <thead><tr>
                        <th><?php esc_html_e( 'ID', 's3-media-sync' ); ?></th>
                        <th><?php esc_html_e( 'Title', 's3-media-sync' ); ?></th>
                        <th><?php esc_html_e( 'Expected path', 's3-media-sync' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 's3-media-sync' ); ?></th>
                    </tr></thead>
                    <tbody id="s3-find-missing-tbody"></tbody>
                </table>
            </div>
            <h2 style="margin-top:18px;"><?php esc_html_e( 'Reset Sync Status', 's3-media-sync' ); ?></h2>
            <p><?php esc_html_e( 'Delete all s3_media_sync_synced meta — all files will be treated as not yet synced and Manual Sync can be run from the beginning.', 's3-media-sync' ); ?></p>
            <p>
                <button id="s3-media-sync-reset" class="button button-secondary"><?php esc_html_e( 'Reset Sync Status', 's3-media-sync' ); ?></button>
                <span id="s3-media-sync-reset-result" style="margin-left:12px;font-size:13px;"></span>
            </p>
        </div>
        <?php
    }

    public function ajax_sync_batch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions', 's3-media-sync' ) );
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
            wp_send_json_error( __( 'Please configure Access Key, Secret Key, and Bucket in settings.', 's3-media-sync' ) );
        }

        if ( ! class_exists( '\\Aws\\S3\\S3Client' ) ) {
            wp_send_json_error( __( 'AWS SDK for PHP not found. Please install aws/aws-sdk-php via Composer.', 's3-media-sync' ) );
        }

        $client = S3_Media_Sync::get_s3_client( $opts );
        if ( ! $client ) {
            wp_send_json_error( __( 'Could not create S3 client. Check your credentials.', 's3-media-sync' ) );
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
            wp_send_json_error( __( 'Insufficient permissions', 's3-media-sync' ) );
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
                'message' => __( 'No synced attachments found to delete locally.', 's3-media-sync' ),
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
            wp_send_json_error( __( 'Insufficient permissions', 's3-media-sync' ) );
        }
        check_ajax_referer( 's3_media_sync_bg', 'nonce' );

        global $wpdb;

        $existing = get_option( 's3_bg_sync_state', array() );
        $resume   = ! empty( $existing ) && isset( $existing['last_id'] ) && (int) $existing['last_id'] > 0;

        if ( $resume ) {
            $state               = $existing;
            $state['status']     = 'running';
            $state['updated_at'] = time();
        } else {
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
            wp_send_json_error( __( 'Insufficient permissions', 's3-media-sync' ) );
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
            wp_send_json_error( __( 'Insufficient permissions', 's3-media-sync' ) );
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
            /* translators: %d: number of synced files that were reset */
            'message' => sprintf( __( 'Stopped & cleared. Reset %d synced files. Ready to start fresh.', 's3-media-sync' ), $deleted ),
        ) );
    }

    public function ajax_bg_reset_offset() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions', 's3-media-sync' ) );
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

        wp_send_json_success( __( 'Background sync offset reset. Click Start to sync from the beginning.', 's3-media-sync' ) );
    }

    public function ajax_bg_status() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions', 's3-media-sync' ) );
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
            wp_send_json_error( __( 'Insufficient permissions', 's3-media-sync' ) );
        }
        check_ajax_referer( 's3_media_sync_reset', 'nonce' );

        global $wpdb;
        $deleted = $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => 's3_media_sync_synced' ) );
        $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => 's3_media_sync_local_removed' ) );

        // Also reset background sync state so BG re-syncs from the beginning
        wp_clear_scheduled_hook( 's3_media_sync_bg_batch' );
        delete_option( 's3_bg_sync_state' );

        /* translators: %d: number of files whose sync status was deleted */
        wp_send_json_success( sprintf( __( 'Reset complete. Deleted sync status for %d files. Background sync has also been reset.', 's3-media-sync' ), intval( $deleted ) ) );
    }

    public function add_attachment_field( $form_fields, $post ) {
        $synced_at = get_post_meta( $post->ID, 's3_media_sync_synced', true );
        $opts      = get_option( 's3_media_sync_options', array() );
        $enabled   = ! empty( $opts['enabled'] );

        $nonce = wp_create_nonce( 's3_sync_single_' . $post->ID );

        if ( $synced_at ) {
            /* translators: %s: date and time the file was synced */
            $status = '<span style="color:#00a32a">&#10003; ' . sprintf( esc_html__( 'Synced at %s', 's3-media-sync' ), esc_html( date_i18n( 'd/m/Y H:i', $synced_at ) ) ) . '</span>';
        } else {
            $status = '<span style="color:#888">' . esc_html__( 'Not yet synced to S3', 's3-media-sync' ) . '</span>';
        }

        $button = '';
        if ( $enabled ) {
            $btn_label     = $synced_at ? esc_html__( 'Re-sync', 's3-media-sync' ) : esc_html__( 'Sync to S3', 's3-media-sync' );
            $syncing_label = esc_html__( 'Syncing...', 's3-media-sync' );
            $resynced_label = esc_html__( 'Re-sync', 's3-media-sync' );
            $button = ' &nbsp;<button type="button" class="button s3-sync-single-btn"
                data-id="' . esc_attr( $post->ID ) . '"
                data-nonce="' . esc_attr( $nonce ) . '"
                data-label-syncing="' . esc_attr( $syncing_label ) . '"
                data-label-resynced="' . esc_attr( $resynced_label ) . '">' .
                $btn_label .
            '</button>
            <span class="s3-sync-single-result" style="margin-left:6px;font-size:12px;"></span>
            <script>
            (function($){
                $(document).off("click",".s3-sync-single-btn").on("click",".s3-sync-single-btn",function(){
                    var $btn=$(this);
                    var syncingLabel=$btn.data("label-syncing")||"Syncing...";
                    var resyncedLabel=$btn.data("label-resynced")||"Re-sync";
                    $btn.prop("disabled",true).text(syncingLabel);
                    var $r=$btn.siblings(".s3-sync-single-result").text("");
                    $.post(ajaxurl,{action:"s3_media_sync_single",id:$btn.data("id"),nonce:$btn.data("nonce")},function(resp){
                        $btn.prop("disabled",false).text(resyncedLabel);
                        if(resp.success){$r.css("color","#00a32a").text("\u2713 "+resp.data);}
                        else{$r.css("color","#d63638").text("\u2717 "+(resp.data||"Error"));}
                    }).fail(function(){$btn.prop("disabled",false);$r.css("color","#d63638").text("\u2717 AJAX error");});
                });
            })(jQuery);
            </script>';
        }

        $form_fields['s3_media_sync'] = array(
            'label' => 'S3 Sync',
            'input' => 'html',
            'html'  => $status . $button,
        );

        return $form_fields;
    }

    public function ajax_find_missing() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions', 's3-media-sync' ) );
        }
        check_ajax_referer( 's3_media_sync_find_missing', 'nonce' );

        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
             ORDER BY p.ID ASC"
        );

        $uploads  = wp_get_upload_dir();
        $base     = untrailingslashit( $uploads['basedir'] );
        $missing  = array();

        foreach ( $ids as $id ) {
            $file = get_attached_file( (int) $id );
            if ( ! $file || ! file_exists( $file ) ) {
                $missing[] = array(
                    'id'    => (int) $id,
                    'title' => get_the_title( (int) $id ),
                    'path'  => $file ? str_replace( $base . '/', '', $file ) : __( '(no path)', 's3-media-sync' ),
                    'edit'  => get_edit_post_link( (int) $id, 'raw' ),
                );
            }
        }

        wp_send_json_success( array(
            'count'   => count( $missing ),
            'missing' => $missing,
        ) );
    }

    public function ajax_mark_all_synced() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions', 's3-media-sync' ) );
        }
        check_ajax_referer( 's3_media_sync_mark_all', 'nonce' );

        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'"
        );

        $now   = time();
        $count = 0;
        foreach ( $ids as $id ) {
            update_post_meta( (int) $id, 's3_media_sync_synced', $now );
            $count++;
        }

        /* translators: %d: number of files marked as synced */
        wp_send_json_success( sprintf( __( 'Marked %d files as synced.', 's3-media-sync' ), $count ) );
    }

    public function add_attachment_meta_box() {
        add_meta_box(
            's3-media-sync-attachment',
            'S3 Media Sync',
            array( $this, 'render_attachment_meta_box' ),
            'attachment',
            'side',
            'default'
        );
    }

    public function render_attachment_meta_box( $post ) {
        $synced_at = get_post_meta( $post->ID, 's3_media_sync_synced', true );
        $opts      = get_option( 's3_media_sync_options', array() );
        $enabled   = ! empty( $opts['enabled'] );
        ?>
        <div id="s3-single-sync-wrap">
            <?php if ( $synced_at ) : ?>
                <p style="color:#00a32a;margin:0 0 8px;">
                    &#10003; <?php
                    /* translators: %s: date and time the file was synced */
                    printf( esc_html__( 'Synced at %s', 's3-media-sync' ), esc_html( date_i18n( 'd/m/Y H:i', $synced_at ) ) ); ?>
                </p>
            <?php else : ?>
                <p style="color:#888;margin:0 0 8px;"><?php esc_html_e( 'Not yet synced to S3', 's3-media-sync' ); ?></p>
            <?php endif; ?>

            <?php if ( $enabled ) : ?>
                <button type="button" id="s3-sync-single-btn" class="button"
                        data-id="<?php echo esc_attr( $post->ID ); ?>"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 's3_sync_single_' . $post->ID ) ); ?>"
                        data-label-syncing="<?php esc_attr_e( 'Syncing...', 's3-media-sync' ); ?>"
                        data-label-sync="<?php esc_attr_e( 'Sync to S3', 's3-media-sync' ); ?>"
                        data-label-resync="<?php esc_attr_e( 'Re-sync to S3', 's3-media-sync' ); ?>">
                    <?php echo $synced_at ? esc_html__( 'Re-sync to S3', 's3-media-sync' ) : esc_html__( 'Sync to S3', 's3-media-sync' ); ?>
                </button>
                <span id="s3-sync-single-result" style="display:block;margin-top:6px;font-size:12px;"></span>
                <script>
                (function($){
                    $('#s3-sync-single-btn').on('click', function(){
                        var $btn    = $(this);
                        var syncingLabel = $btn.data('label-syncing') || 'Syncing...';
                        var resyncLabel  = $btn.data('label-resync')  || 'Re-sync to S3';
                        var syncLabel    = $btn.data('label-sync')    || 'Sync to S3';
                        $btn.prop('disabled', true).text(syncingLabel);
                        var $result = $('#s3-sync-single-result').css('color','').text('');
                        $.post(ajaxurl, {
                            action: 's3_media_sync_single',
                            id:     $btn.data('id'),
                            nonce:  $btn.data('nonce')
                        }, function(resp){
                            $btn.prop('disabled', false);
                            if (resp.success) {
                                $btn.text(resyncLabel);
                                $result.css('color','#00a32a').text('\u2713 ' + resp.data);
                            } else {
                                $btn.text(syncLabel);
                                $result.css('color','#d63638').text('\u2717 ' + (resp.data || 'Error'));
                            }
                        }).fail(function(){
                            $btn.prop('disabled', false).text(syncLabel);
                            $result.css('color','#d63638').text('\u2717 AJAX error');
                        });
                    });
                })(jQuery);
                </script>
            <?php else : ?>
                <p style="color:#888;font-size:12px;margin:0;"><?php esc_html_e( 'Plugin is not enabled. Go to Settings > S3 Media Sync to configure.', 's3-media-sync' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function ajax_sync_single() {
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( __( 'Insufficient permissions', 's3-media-sync' ) );
        }

        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) {
            wp_send_json_error( __( 'Invalid attachment ID', 's3-media-sync' ) );
        }

        check_ajax_referer( 's3_sync_single_' . $id, 'nonce' );

        $opts   = get_option( 's3_media_sync_options', array() );
        $bucket = isset( $opts['bucket'] ) ? $opts['bucket'] : '';
        $client = S3_Media_Sync::get_s3_client( $opts );

        if ( ! $client || ! $bucket ) {
            wp_send_json_error( __( 'S3 is not configured. Check Settings > S3 Media Sync.', 's3-media-sync' ) );
        }

        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) {
            wp_send_json_error( __( 'File does not exist on the server.', 's3-media-sync' ) );
        }

        $uploads  = wp_get_upload_dir();
        $base     = untrailingslashit( $uploads['basedir'] );
        $relative = ltrim( str_replace( $base, '', $file ), '/\\' );

        try {
            $client->putObject( array(
                'Bucket'     => $bucket,
                'Key'        => $relative,
                'SourceFile' => $file,
            ) );
        } catch ( Exception $e ) {
            wp_send_json_error( $e->getMessage() );
        }

        // Upload thumbnail sizes
        $meta = wp_get_attachment_metadata( $id );
        if ( $meta && ! empty( $meta['sizes'] ) && isset( $meta['file'] ) ) {
            $meta_dir = dirname( $meta['file'] );
            foreach ( $meta['sizes'] as $size_meta ) {
                if ( empty( $size_meta['file'] ) ) {
                    continue;
                }
                $size_rel  = $meta_dir === '.' ? $size_meta['file'] : ltrim( $meta_dir . '/' . $size_meta['file'], '/\\' );
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
                    error_log( '[s3-media-sync] Single sync size failed: ' . $e->getMessage() );
                }
            }
        }

        update_post_meta( $id, 's3_media_sync_synced', time() );

        /* translators: %s: date and time of successful sync */
        wp_send_json_success( sprintf( __( 'Synced successfully at %s', 's3-media-sync' ), date_i18n( 'd/m/Y H:i' ) ) );
    }
}
