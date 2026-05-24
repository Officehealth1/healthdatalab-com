<?php
/**
 * HDL V2 — WP_List_Table subclass for the Tokens tab (W13).
 *
 * Lives in its own file so HDLV2_Admin_Automation_Tier can lazy-require it
 * from render_tokens_tab() AFTER WordPress's admin bootstrap has loaded
 * `wp-admin/includes/class-wp-list-table.php`. If this subclass file were
 * required at plugins_loaded time alongside the main admin class, the
 * `extends WP_List_Table` clause would fatal because WP_List_Table is not
 * loaded yet (admin classes load in admin context only).
 *
 * @package HDL_Longevity_V2
 * @since 0.41.34
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    // Defensive — the caller should have required this already. Fail safe by
    // loading on demand so a future direct require of this file doesn't break.
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class HDLV2_Admin_Automation_Tokens_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct( array(
            'singular' => 'token',
            'plural'   => 'tokens',
            'ajax'     => false,
        ) );
    }

    public function get_columns() {
        return array(
            'email'         => 'Email',
            'client_name'   => 'Name',
            'programme'     => 'Programme',
            'tier'          => 'Tier',
            'status'        => 'Status',
            'issued_at'     => 'Issued',
            'last_activity' => 'Last activity',
            'actions'       => 'Actions',
        );
    }

    public function prepare_items() {
        global $wpdb;
        $per_page     = 25;
        $current_page = $this->get_pagenum();
        $offset       = ( $current_page - 1 ) * $per_page;

        $where  = array( '1=1' );
        $params = array();
        if ( ! empty( $_GET['status'] ) ) {
            $s = sanitize_text_field( wp_unslash( $_GET['status'] ) );
            if ( in_array( $s, array( 'issued', 'started', 'completed', 'revoked' ), true ) ) {
                $where[]  = 'status = %s';
                $params[] = $s;
            }
        }
        if ( ! empty( $_GET['email'] ) ) {
            $e        = sanitize_text_field( wp_unslash( $_GET['email'] ) );
            $where[]  = 'client_email LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $e ) . '%';
        }
        if ( ! empty( $_GET['from'] ) ) {
            $f = sanitize_text_field( wp_unslash( $_GET['from'] ) );
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f ) ) {
                $where[]  = 'issued_at >= %s';
                $params[] = $f . ' 00:00:00';
            }
        }
        if ( ! empty( $_GET['to'] ) ) {
            $t = sanitize_text_field( wp_unslash( $_GET['to'] ) );
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $t ) ) {
                $where[]  = 'issued_at <= %s';
                $params[] = $t . ' 23:59:59';
            }
        }

        $where_sql = implode( ' AND ', $where );
        $table     = $wpdb->prefix . 'hdlv2_automation_tokens';

        if ( empty( $params ) ) {
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
            $items = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, client_email, client_name, programme, tier, status, issued_at, started_at, completed_at, revoked_at
                 FROM {$table} ORDER BY issued_at DESC LIMIT %d OFFSET %d",
                $per_page, $offset
            ) );
        } else {
            $total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}",
                ...$params
            ) );
            $rows_params = array_merge( $params, array( $per_page, $offset ) );
            $items = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, client_email, client_name, programme, tier, status, issued_at, started_at, completed_at, revoked_at
                 FROM {$table} WHERE {$where_sql} ORDER BY issued_at DESC LIMIT %d OFFSET %d",
                ...$rows_params
            ) );
        }

        $this->items = $items ?: array();
        $this->set_pagination_args( array(
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
        ) );
        $this->_column_headers = array( $this->get_columns(), array(), array() );
    }

    public function column_default( $item, $column_name ) {
        if ( ! isset( $item->$column_name ) ) {
            return '—';
        }
        $v = (string) $item->$column_name;
        return $v !== '' ? esc_html( $v ) : '—';
    }

    public function column_email( $item ) {
        return '<strong>' . esc_html( $item->client_email ) . '</strong>';
    }

    public function column_status( $item ) {
        $palette = array(
            'issued'    => array( '#6b7280', '#f3f4f6' ),
            'started'   => array( '#3b82f6', '#eff6ff' ),
            'completed' => array( '#10b981', '#ecfdf5' ),
            'revoked'   => array( '#dc2626', '#fef2f2' ),
        );
        $colors = isset( $palette[ $item->status ] ) ? $palette[ $item->status ] : array( '#6b7280', '#f3f4f6' );
        return sprintf(
            '<span style="display:inline-block;padding:3px 10px;border-radius:24px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.03em;color:%s;background:%s;">%s</span>',
            esc_attr( $colors[0] ),
            esc_attr( $colors[1] ),
            esc_html( $item->status )
        );
    }

    public function column_issued_at( $item ) {
        if ( empty( $item->issued_at ) ) return '—';
        $ts = strtotime( $item->issued_at . ' UTC' );
        return '<abbr title="' . esc_attr( $item->issued_at ) . ' UTC">' . esc_html( wp_date( 'j M Y · H:i', $ts ) ) . '</abbr>';
    }

    public function column_last_activity( $item ) {
        $candidates = array_filter( array( $item->started_at, $item->completed_at, $item->revoked_at ) );
        if ( empty( $candidates ) ) return '—';
        $latest = max( $candidates );
        $ts     = strtotime( $latest . ' UTC' );
        return '<abbr title="' . esc_attr( $latest ) . ' UTC">' . esc_html( wp_date( 'j M Y · H:i', $ts ) ) . '</abbr>';
    }

    public function column_actions( $item ) {
        if ( in_array( $item->status, array( 'revoked', 'completed' ), true ) ) {
            return '<span style="color:#94a3b8;">—</span>';
        }
        return sprintf(
            '<button type="button" class="button button-small hdlv2-revoke-btn" data-token-id="%d" data-email="%s">Revoke</button>',
            (int) $item->id,
            esc_attr( $item->client_email )
        );
    }

    public function no_items() {
        echo 'No automation tokens yet. Tokens are created when Altituding\'s Stripe webhook fires <code>POST /wp-json/hdl/v1/paid-report-provision</code> for an automation-tier purchase.';
    }
}
