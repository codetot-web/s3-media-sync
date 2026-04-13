<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class S3_Media_Sync_Synced_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct( array(
            'singular' => 'attachment',
            'plural'   => 'attachments',
            'ajax'     => false,
        ) );
    }

    public function get_columns() {
        return array(
            'thumbnail'    => 'Preview',
            'title'        => 'File',
            'mime_type'    => 'Type',
            'file_size'    => 'Size',
            'synced_at'    => 'Synced At',
            'local_status' => 'Local File',
        );
    }

    public function get_sortable_columns() {
        return array(
            'title'     => array( 'title', false ),
            'synced_at' => array( 'synced_at', true ),
        );
    }

    protected function column_default( $item, $column_name ) {
        return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '—';
    }

    protected function column_thumbnail( $item ) {
        $thumb = wp_get_attachment_image( $item['ID'], array( 48, 48 ), true, array( 'style' => 'border-radius:3px;' ) );
        return $thumb ?: '<span style="color:#999;font-size:11px;">no preview</span>';
    }

    protected function column_title( $item ) {
        $edit_url = get_edit_post_link( $item['ID'] );
        $name     = esc_html( $item['title'] );
        $s3_url   = esc_url( $item['s3_url'] );
        $out      = '<strong><a href="' . esc_url( $edit_url ) . '">' . $name . '</a></strong>';
        if ( $s3_url ) {
            $out .= '<br><a href="' . $s3_url . '" target="_blank" style="font-size:11px;color:#2271b1;">View on S3</a>';
        }
        return $out;
    }

    protected function column_synced_at( $item ) {
        if ( empty( $item['synced_at'] ) ) {
            return '—';
        }
        return esc_html( date_i18n( 'Y-m-d H:i', (int) $item['synced_at'] ) );
    }

    protected function column_local_status( $item ) {
        if ( $item['local_removed'] ) {
            return '<span style="color:#d63638;">Deleted</span>';
        }
        return $item['local_exists']
            ? '<span style="color:#00a32a;">Exists</span>'
            : '<span style="color:#d63638;">Missing</span>';
    }

    protected function column_file_size( $item ) {
        return esc_html( $item['file_size'] );
    }

    public function prepare_items() {
        $per_page     = 30;
        $current_page = $this->get_pagenum();
        $search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
        $orderby      = isset( $_REQUEST['orderby'] ) && in_array( $_REQUEST['orderby'], array( 'title', 'synced_at' ), true )
            ? sanitize_key( $_REQUEST['orderby'] )
            : 'synced_at';
        $order        = isset( $_REQUEST['order'] ) && 'asc' === strtolower( $_REQUEST['order'] ) ? 'ASC' : 'DESC';

        global $wpdb;

        $where_search = '';
        if ( $search ) {
            $where_search = $wpdb->prepare( ' AND p.post_title LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
        }

        $order_sql = 'synced_at' === $orderby
            ? ( 'ASC' === $order ? 'pm_sync.meta_value+0 ASC' : 'pm_sync.meta_value+0 DESC' )
            : ( 'ASC' === $order ? 'p.post_title ASC' : 'p.post_title DESC' );

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_sync ON pm_sync.post_id = p.ID AND pm_sync.meta_key = 's3_media_sync_synced'
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'"
            . $where_search // already prepared above
        );

        $this->set_pagination_args( array(
            'total_items' => $total,
            'per_page'    => $per_page,
        ) );

        $offset = ( $current_page - 1 ) * $per_page;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_mime_type,
                    pm_sync.meta_value  AS synced_at,
                    pm_del.meta_value   AS local_removed,
                    pm_file.meta_value  AS attached_file
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_sync ON pm_sync.post_id = p.ID AND pm_sync.meta_key = 's3_media_sync_synced'
             LEFT  JOIN {$wpdb->postmeta} pm_del  ON pm_del.post_id  = p.ID AND pm_del.meta_key  = 's3_media_sync_local_removed'
             LEFT  JOIN {$wpdb->postmeta} pm_file ON pm_file.post_id = p.ID AND pm_file.meta_key  = '_wp_attached_file'
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
             {$where_search}
             ORDER BY {$order_sql}
             LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A );
        // phpcs:enable

        $uploads  = wp_get_upload_dir();
        $base_dir = untrailingslashit( $uploads['basedir'] );

        $s3_instance = S3_Media_Sync::instance();

        $this->items = array();
        foreach ( (array) $rows as $row ) {
            $file_path   = $row['attached_file'] ? $base_dir . '/' . $row['attached_file'] : '';
            $local_exists = $file_path && file_exists( $file_path );
            $file_size    = ( $local_exists ) ? size_format( filesize( $file_path ) ) : '—';
            $s3_url       = get_post_meta( (int) $row['ID'], '_wp_attachment_url', true );

            // Fallback: build S3 URL via plugin method (use wp_get_attachment_url which is filtered)
            if ( ! $s3_url ) {
                $s3_url = wp_get_attachment_url( (int) $row['ID'] );
            }

            $this->items[] = array(
                'ID'            => (int) $row['ID'],
                'title'         => $row['post_title'] ?: basename( (string) $row['attached_file'] ),
                'mime_type'     => $row['post_mime_type'],
                'synced_at'     => $row['synced_at'],
                'local_removed' => ! empty( $row['local_removed'] ),
                'local_exists'  => $local_exists,
                'file_size'     => $file_size,
                's3_url'        => $s3_url,
            );
        }

        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            $this->get_sortable_columns(),
        );
    }
}
