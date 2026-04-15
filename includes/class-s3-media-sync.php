<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class S3_Media_Sync {
    /** @var S3_Media_Sync */
    private static $instance;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'add_attachment', array( $this, 'handle_add_attachment' ) );
        add_filter( 'wp_generate_attachment_metadata', array( $this, 'handle_attachment_sizes' ), 10, 2 );
        add_action( 'delete_attachment', array( $this, 'handle_delete_attachment' ) );
        add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 10, 2 );
        add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_attachment_image_src' ), 10, 4 );
        add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_image_srcset' ), 10, 5 );
        add_filter( 'wp_prepare_attachment_for_js', array( $this, 'filter_prepare_attachment_for_js' ), 10, 3 );
        add_filter( 'the_content', array( $this, 'filter_content_urls' ), 20 );
        add_filter( 'wpseo_opengraph_image', array( $this, 'filter_generic_upload_url' ) );
        add_filter( 'wpseo_twitter_image', array( $this, 'filter_generic_upload_url' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 's3_media_sync_bg_batch', array( $this, 'run_bg_batch' ) );
    }

    public static function activate() {
        if ( ! get_option( 's3_media_sync_options' ) ) {
            $defaults = array(
                'enabled'    => 0,
                'access_key' => '',
                'secret_key' => '',
                'region'     => 'us-east-1',
                'bucket'     => '',
                'endpoint'   => '',
                'public_url' => '',
            );
            add_option( 's3_media_sync_options', $defaults );
        }
    }

    /**
     * Build and return an S3Client from saved options, or null on failure.
     *
     * @param array|null $opts Pass opts array directly, or null to load from DB.
     * @return \Aws\S3\S3Client|null
     */
    public static function get_s3_client( $opts = null ) {
        if ( null === $opts ) {
            $opts = get_option( 's3_media_sync_options', array() );
        }
        $access         = isset( $opts['access_key'] ) ? $opts['access_key'] : '';
        $secret         = isset( $opts['secret_key'] ) ? $opts['secret_key'] : '';
        $bucket         = isset( $opts['bucket'] ) ? $opts['bucket'] : '';
        $endpoint       = isset( $opts['endpoint'] ) ? trim( $opts['endpoint'] ) : '';
        $region         = ( isset( $opts['region'] ) && $opts['region'] ) ? $opts['region'] : 'us-east-1';
        $disable_ssl    = ! empty( $opts['disable_ssl_verify'] );

        if ( empty( $access ) || empty( $secret ) || empty( $bucket ) ) {
            return null;
        }
        if ( ! class_exists( '\\Aws\\S3\\S3Client' ) ) {
            return null;
        }
        if ( ! empty( $endpoint ) && ! preg_match( '#^https?://#i', $endpoint ) ) {
            $endpoint = 'https://' . $endpoint;
        }

        $args = array_filter( array(
            'version'                 => 'latest',
            'region'                  => $region,
            'credentials'             => array(
                'key'    => $access,
                'secret' => $secret,
            ),
            'endpoint'                => $endpoint ?: null,
            'use_path_style_endpoint' => true,
        ) );

        $args['http'] = array(
            'timeout'         => 60,
            'connect_timeout' => 10,
        );

        if ( $disable_ssl ) {
            $args['http']['verify'] = false;
        }

        return new \Aws\S3\S3Client( $args );
    }

    public static function deactivate() {
        // Placeholder for deactivation tasks
    }

    public function register_settings() {
        register_setting( 's3_media_sync_options_group', 's3_media_sync_options' );
    }

    public function handle_add_attachment( $post_id ) {
        $opts = get_option( 's3_media_sync_options', array() );
        if ( empty( $opts['enabled'] ) ) {
            return;
        }
        $file = get_attached_file( $post_id );
        if ( ! $file || ! file_exists( $file ) ) {
            return;
        }

        $client = self::get_s3_client( $opts );
        if ( ! $client ) {
            return;
        }

        $bucket   = isset( $opts['bucket'] ) ? $opts['bucket'] : '';
        $uploads  = wp_get_upload_dir();
        $base     = untrailingslashit( $uploads['basedir'] );
        $relative = ltrim( str_replace( $base, '', $file ), '/\\' );

        try {
            $client->putObject( array(
                'Bucket'     => $bucket,
                'Key'        => $relative,
                'SourceFile' => $file,
            ) );
            update_post_meta( $post_id, 's3_media_sync_synced', time() );
        } catch ( Exception $e ) {
            error_log( '[s3-media-sync] Upload failed for ' . $file . ': ' . $e->getMessage() );
        }
    }

    /**
     * Upload resized image sizes to S3 after WP generates them.
     */
    public function handle_attachment_sizes( $metadata, $post_id ) {
        $opts = get_option( 's3_media_sync_options', array() );
        if ( empty( $opts['enabled'] ) ) {
            return $metadata;
        }
        if ( empty( $metadata['sizes'] ) || empty( $metadata['file'] ) ) {
            return $metadata;
        }

        $client = self::get_s3_client( $opts );
        if ( ! $client ) {
            return $metadata;
        }

        $bucket   = isset( $opts['bucket'] ) ? $opts['bucket'] : '';
        $uploads  = wp_get_upload_dir();
        $base     = untrailingslashit( $uploads['basedir'] );
        $meta_dir = dirname( $metadata['file'] );

        $delete_local = ! empty( $opts['delete_after_upload'] );
        $sizes_ok     = true;

        foreach ( $metadata['sizes'] as $size_meta ) {
            if ( empty( $size_meta['file'] ) ) {
                continue;
            }
            $size_rel  = ltrim( $meta_dir . '/' . $size_meta['file'], '/\\' );
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
                if ( $delete_local ) {
                    @unlink( $size_full );
                }
            } catch ( Exception $e ) {
                $sizes_ok = false;
                error_log( '[s3-media-sync] Upload size failed ' . $size_full . ': ' . $e->getMessage() );
            }
        }

        // Xoá file gốc local chỉ khi tất cả sizes đã upload thành công
        if ( $delete_local && $sizes_ok ) {
            $original = get_attached_file( $post_id );
            if ( $original && file_exists( $original ) ) {
                @unlink( $original );
                update_post_meta( $post_id, 's3_media_sync_local_removed', time() );
            }
        }

        return $metadata;
    }

    /**
     * Delete all S3 objects for an attachment when it is deleted from WordPress.
     */
    public function handle_delete_attachment( $post_id ) {
        $opts = get_option( 's3_media_sync_options', array() );
        if ( empty( $opts['enabled'] ) ) {
            return;
        }

        $client = self::get_s3_client( $opts );
        if ( ! $client ) {
            return;
        }

        $bucket  = isset( $opts['bucket'] ) ? $opts['bucket'] : '';
        $uploads = wp_get_upload_dir();
        $base    = untrailingslashit( $uploads['basedir'] );
        $file    = get_attached_file( $post_id );

        $keys = array();

        if ( $file && strpos( $file, $base ) === 0 ) {
            $keys[] = ltrim( str_replace( $base, '', $file ), '/\\' );
        }

        $meta = wp_get_attachment_metadata( $post_id );
        if ( $meta && ! empty( $meta['sizes'] ) && isset( $meta['file'] ) ) {
            $meta_dir = dirname( $meta['file'] );
            foreach ( $meta['sizes'] as $size_meta ) {
                if ( empty( $size_meta['file'] ) ) {
                    continue;
                }
                $keys[] = $meta_dir === '.'
                    ? $size_meta['file']
                    : ltrim( $meta_dir . '/' . $size_meta['file'], '/\\' );
            }
        }

        foreach ( array_unique( $keys ) as $key ) {
            try {
                $client->deleteObject( array(
                    'Bucket' => $bucket,
                    'Key'    => $key,
                ) );
            } catch ( Exception $e ) {
                error_log( '[s3-media-sync] Delete from S3 failed for ' . $key . ': ' . $e->getMessage() );
            }
        }
    }

    public function filter_attachment_url( $url, $post_id ) {
        $opts = get_option( 's3_media_sync_options', array() );
        if ( empty( $opts['enabled'] ) ) {
            return $url;
        }
        $file = get_attached_file( $post_id, true );
        if ( ! $file ) {
            return $url;
        }
        $s3 = $this->get_s3_url_from_file( $file );
        return $s3 ? $s3 : $url;
    }

    /**
     * Filter wp_get_attachment_image_src — used by Elementor and most image rendering.
     *
     * @param array|false  $image  Array [url, w, h, is_intermediate] or false.
     * @param int          $post_id
     * @param string|array $size
     * @param bool         $icon
     * @return array|false
     */
    public function filter_attachment_image_src( $image, $post_id, $size, $icon ) {
        if ( ! $image || empty( $image[0] ) ) {
            return $image;
        }
        $opts = get_option( 's3_media_sync_options', array() );
        if ( empty( $opts['enabled'] ) ) {
            return $image;
        }
        $uploads  = wp_get_upload_dir();
        $base_url = untrailingslashit( $uploads['baseurl'] );
        $url      = $image[0];

        // Only rewrite URLs that belong to this site's uploads directory
        if ( 0 !== strpos( $url, $base_url ) ) {
            return $image;
        }

        $relative = ltrim( str_replace( $base_url, '', $url ), '/\\' );
        $basedir  = untrailingslashit( $uploads['basedir'] );
        $file     = $basedir . '/' . $relative;
        $s3       = $this->get_s3_url_from_file( $file );
        if ( $s3 ) {
            $image[0] = $s3;
        }
        return $image;
    }

    /**
     * Filter srcset URLs so responsive images also point to S3.
     */
    public function filter_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
        $opts = get_option( 's3_media_sync_options', array() );
        if ( empty( $opts['enabled'] ) || empty( $sources ) ) {
            return $sources;
        }
        $uploads  = wp_get_upload_dir();
        $base_url = untrailingslashit( $uploads['baseurl'] );
        $basedir  = untrailingslashit( $uploads['basedir'] );

        foreach ( $sources as $w => $data ) {
            $url = $data['url'];
            if ( 0 !== strpos( $url, $base_url ) ) {
                continue;
            }
            $relative          = ltrim( str_replace( $base_url, '', $url ), '/\\' );
            $file              = $basedir . '/' . $relative;
            $s3                = $this->get_s3_url_from_file( $file );
            if ( $s3 ) {
                $sources[ $w ]['url'] = $s3;
            }
        }
        return $sources;
    }

    public function filter_prepare_attachment_for_js( $response, $attachment, $meta ) {
        $opts = get_option( 's3_media_sync_options', array() );
        if ( empty( $opts['enabled'] ) ) {
            return $response;
        }

        if ( ! empty( $response['url'] ) ) {
            $file = get_attached_file( $attachment->ID );
            $s3 = $file ? $this->get_s3_url_from_file( $file ) : false;
            if ( $s3 ) {
                $response['url'] = $s3;
            }
        }

        if ( ! empty( $response['sizes'] ) && is_array( $response['sizes'] ) ) {
            $uploads = wp_get_upload_dir();
            $basedir = untrailingslashit( $uploads['basedir'] );
            foreach ( $response['sizes'] as $size => $data ) {
                if ( ! empty( $data['file'] ) && isset( $meta['file'] ) ) {
                    $file_path = $basedir . '/' . dirname( $meta['file'] ) . '/' . $data['file'];
                    $s3size = $this->get_s3_url_from_file( $file_path );
                    if ( $s3size ) {
                        $response['sizes'][ $size ]['url'] = $s3size;
                    }
                }
            }
        }

        return $response;
    }

    /**
     * Replace all local upload URLs in a string with S3 URLs.
     * Used for post content, OG image, etc.
     */
    public function filter_content_urls( $content ) {
        $opts = get_option( 's3_media_sync_options', array() );
        if ( empty( $opts['enabled'] ) || empty( $content ) ) {
            return $content;
        }
        return $this->replace_upload_urls_in_string( $content );
    }

    /**
     * Replace a single local upload URL with its S3 equivalent.
     * Used for Yoast og:image, twitter:image, etc.
     */
    public function filter_generic_upload_url( $url ) {
        $opts = get_option( 's3_media_sync_options', array() );
        if ( empty( $opts['enabled'] ) || empty( $url ) ) {
            return $url;
        }
        $uploads  = wp_get_upload_dir();
        $base_url = untrailingslashit( $uploads['baseurl'] );
        if ( 0 !== strpos( $url, $base_url ) ) {
            return $url;
        }
        $relative = ltrim( str_replace( $base_url, '', $url ), '/\\' );
        $basedir  = untrailingslashit( $uploads['basedir'] );
        $s3       = $this->get_s3_url_from_file( $basedir . '/' . $relative );
        return $s3 ? $s3 : $url;
    }

    /**
     * Replace all local upload URLs inside an arbitrary string with S3 URLs.
     */
    private function replace_upload_urls_in_string( $str ) {
        $uploads  = wp_get_upload_dir();
        $base_url = untrailingslashit( $uploads['baseurl'] );
        $basedir  = untrailingslashit( $uploads['basedir'] );

        return preg_replace_callback(
            '#' . preg_quote( $base_url, '#' ) . '/([^\s"\'<>]+)#',
            function( $matches ) use ( $basedir ) {
                $relative = $matches[1];
                $file     = $basedir . '/' . $relative;
                $s3       = $this->get_s3_url_from_file( $file );
                return $s3 ? $s3 : $matches[0];
            },
            $str
        );
    }

    /**
     * WP-Cron callback: process one batch of attachments in the background.
     * Schedules the next batch automatically until all attachments are synced.
     *
     * State is stored in option 's3_bg_sync_state':
     *   status     running|stopped|done|error
     *   last_id    highest attachment ID processed so far
     *   total      total attachments (counted once on start)
     *   processed  attachments attempted so far
     *   succeeded  uploads OK
     *   skipped    already synced (has meta)
     *   missing    file not found on disk
     *   errors     upload failures
     *   started_at unix timestamp
     *   updated_at unix timestamp
     */
    public function run_bg_batch() {
        $state = get_option( 's3_bg_sync_state', array() );

        if ( empty( $state ) || in_array( $state['status'], array( 'stopped', 'done', 'error' ), true ) ) {
            return;
        }

        @ini_set( 'memory_limit', '512M' );
        @set_time_limit( 300 );

        // Schedule batch tiếp theo NGAY BÂY GIỜ trước khi làm bất cứ điều gì.
        // Đảm bảo chuỗi batch không bao giờ bị đứt dù PHP fatal/timeout.
        $this->schedule_next_bg_batch();

        try {
            $this->process_bg_batch( $state );
        } catch ( Exception $e ) {
            error_log( '[s3-media-sync bg] Unexpected error in batch: ' . $e->getMessage() );
            $state = get_option( 's3_bg_sync_state', $state );
            if ( ! empty( $state ) && $state['status'] === 'running' ) {
                $state['errors']++;
                $state['last_error'] = $e->getMessage();
                $state['updated_at'] = time();
                update_option( 's3_bg_sync_state', $state );
            }
        }
    }

    private function process_bg_batch( $state ) {
        $opts   = get_option( 's3_media_sync_options', array() );
        $bucket = isset( $opts['bucket'] ) ? $opts['bucket'] : '';
        $client = self::get_s3_client( $opts );

        if ( ! $client || ! $bucket ) {
            $state['status']     = 'error';
            $state['last_error'] = 'S3 client not configured — check credentials and bucket.';
            $state['updated_at'] = time();
            update_option( 's3_bg_sync_state', $state );
            $this->cancel_pending_bg_batches();
            return;
        }

        global $wpdb;

        $last_id    = isset( $state['last_id'] ) ? (int) $state['last_id'] : 0;
        $batch_size = 3;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
             AND p.ID > %d
             ORDER BY p.ID ASC
             LIMIT %d",
            $last_id, $batch_size
        ) );

        if ( empty( $ids ) ) {
            $state['status']      = 'done';
            $state['updated_at']  = time();
            update_option( 's3_bg_sync_state', $state );
            $this->cancel_pending_bg_batches();
            return;
        }

        // Lưu last_id trước khi xử lý — nếu PHP timeout, lần sau bỏ qua batch này.
        $state['last_id']    = (int) max( $ids );
        $state['updated_at'] = time();
        update_option( 's3_bg_sync_state', $state );

        $uploads = wp_get_upload_dir();
        $base    = untrailingslashit( $uploads['basedir'] );

        foreach ( $ids as $id ) {
            if ( get_post_meta( $id, 's3_media_sync_synced', true ) ) {
                $state['skipped']++;
                $state['processed']++;
                continue;
            }

            $file = get_attached_file( $id );
            if ( ! $file || ! file_exists( $file ) ) {
                $state['missing']++;
                $state['processed']++;
                continue;
            }

            $relative = ltrim( str_replace( $base, '', $file ), '/\\' );
            $ok       = false;

            try {
                $client->putObject( array(
                    'Bucket'     => $bucket,
                    'Key'        => $relative,
                    'SourceFile' => $file,
                ) );
                $ok = true;
                $state['succeeded']++;
            } catch ( Exception $e ) {
                $state['errors']++;
                $state['last_error'] = sprintf( 'ID %d: %s', $id, $e->getMessage() );
                error_log( '[s3-media-sync bg] ' . $e->getMessage() );
            }

            // Upload resized sizes
            $meta = wp_get_attachment_metadata( $id );
            if ( $meta && ! empty( $meta['sizes'] ) && isset( $meta['file'] ) ) {
                $meta_dir = dirname( $meta['file'] );
                foreach ( $meta['sizes'] as $size_name => $size_meta ) {
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
                        error_log( '[s3-media-sync bg] size ' . $size_name . ': ' . $e->getMessage() );
                    }
                }
            }

            if ( $ok ) {
                update_post_meta( $id, 's3_media_sync_synced', time() );
            }

            $state['processed']++;
        }

        $state['updated_at'] = time();
        update_option( 's3_bg_sync_state', $state );
    }

    private function schedule_next_bg_batch() {
        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time() + 5, 's3_media_sync_bg_batch', array(), 's3-media-sync' );
        } else {
            wp_schedule_single_event( time() + 5, 's3_media_sync_bg_batch' );
        }
    }

    private function cancel_pending_bg_batches() {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 's3_media_sync_bg_batch', array(), 's3-media-sync' );
        }
        wp_clear_scheduled_hook( 's3_media_sync_bg_batch' );
    }

    private function get_s3_url_from_file( $file ) {
        $opts = get_option( 's3_media_sync_options', array() );
        $bucket = isset( $opts['bucket'] ) ? $opts['bucket'] : '';
        $endpoint = isset( $opts['endpoint'] ) ? trim( $opts['endpoint'] ) : '';
        $public = isset( $opts['public_url'] ) ? trim( $opts['public_url'] ) : '';
        if ( ! $bucket ) {
            return false;
        }

        if ( ! empty( $endpoint ) && ! preg_match( '#^https?://#i', $endpoint ) ) {
            $endpoint = 'https://' . $endpoint;
        }

        $uploads = wp_get_upload_dir();
        $basedir = untrailingslashit( $uploads['basedir'] );

        // Ensure file path is inside uploads dir
        if ( 0 !== strpos( $file, $basedir ) ) {
            // not in uploads
            return false;
        }

        $relative = ltrim( str_replace( $basedir, '', $file ), '/\\' );
        $parts = array_map( 'rawurlencode', explode( '/', $relative ) );
        $key = implode( '/', $parts );

        // If a public base URL is configured, use it as-is and append the key.
        if ( ! empty( $public ) ) {
            // Normalize public URL: if it already contains the bucket as trailing path, use it as base.
            $public = trim( $public );
            $public_noslash = rtrim( $public, '/' );
            $parsed = wp_parse_url( $public_noslash );
            if ( $parsed && ! empty( $parsed['host'] ) ) {
                $path = isset( $parsed['path'] ) ? rtrim( $parsed['path'], '/' ) : '';
                $last = $path ? basename( $path ) : '';

                if ( $last === $bucket ) {
                    // public URL already ends with bucket, use it directly
                    return $public_noslash . '/' . $key;
                }

                // public URL does not include bucket — append bucket then key
                return $public_noslash . '/' . rawurlencode( $bucket ) . '/' . $key;
            }

            // fallback
            return untrailingslashit( $public ) . '/' . rawurlencode( $bucket ) . '/' . $key;
        }

        if ( ! empty( $endpoint ) ) {
            return untrailingslashit( $endpoint ) . '/' . rawurlencode( $bucket ) . '/' . $key;
        }

        return 'https://' . rawurlencode( $bucket ) . '.s3.amazonaws.com/' . $key;
    }
}
