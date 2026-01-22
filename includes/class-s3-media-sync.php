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
        add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 10, 2 );
        add_filter( 'wp_prepare_attachment_for_js', array( $this, 'filter_prepare_attachment_for_js' ), 10, 3 );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public static function activate() {
        // Placeholder for activation tasks (create tables, default options)
        if ( ! get_option( 's3_media_sync_options' ) ) {
            $defaults = array(
                'enabled' => 0,
                'access_key' => '',
                'secret_key' => '',
                'bucket' => '',
                'endpoint' => '',
            );
            add_option( 's3_media_sync_options', $defaults );
        }
    }

    public static function deactivate() {
        // Placeholder for deactivation tasks
    }

    public function register_settings() {
        register_setting( 's3_media_sync_options_group', 's3_media_sync_options' );
    }

    public function handle_add_attachment( $post_id ) {
        $file = get_attached_file( $post_id );
        if ( ! $file || ! file_exists( $file ) ) {
            return;
        }
        // TODO: queue upload to S3 or perform upload here
        error_log( '[s3-media-sync] New attachment added: ' . $file );
    }

    public function filter_attachment_url( $url, $post_id ) {
        $opts = get_option( 's3_media_sync_options', array() );
        if ( empty( $opts['enabled'] ) ) {
            return $url;
        }
        $file = get_attached_file( $post_id );
        if ( ! $file ) {
            return $url;
        }
        $s3 = $this->get_s3_url_from_file( $file );
        return $s3 ? $s3 : $url;
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
            foreach ( $response['sizes'] as $size => $data ) {
                if ( ! empty( $data['file'] ) ) {
                    // Attempt to compute size file path relative to uploads dir
                    $uploads = wp_get_upload_dir();
                    $basedir = untrailingslashit( $uploads['basedir'] );
                    $file_path = path_join( $basedir, dirname( $meta['file'] ) );
                    $file_path = path_join( $file_path, $data['file'] );
                    if ( file_exists( $file_path ) ) {
                        $s3size = $this->get_s3_url_from_file( $file_path );
                        if ( $s3size ) {
                            $response['sizes'][ $size ]['url'] = $s3size;
                        }
                    }
                }
            }
        }

        return $response;
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
