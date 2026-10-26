<?php
/**
 * Database Handler for Queue and Logs
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('BWA_DB')) {
class BWA_DB {

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'bwa_message_queue';
    }

    public static function init() {
        // Any runtime db hooks if needed
    }

    public static function create_tables() {
        global $wpdb;
        $table_name = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            event VARCHAR(50) NOT NULL,
            customer_name VARCHAR(191) NOT NULL DEFAULT '',
            phone VARCHAR(50) NOT NULL,
            message TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT(11) NOT NULL DEFAULT 0,
            error_message TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            sent_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        if (!function_exists('dbDelta')) {
            $upgrade_path = ABSPATH . 'wp-admin/includes/upgrade.php';
            if (file_exists($upgrade_path)) {
                require_once $upgrade_path;
            }
        }
        if (function_exists('dbDelta')) {
            dbDelta($sql);
        } else {
            $wpdb->query($sql);
        }
    }

    /**
     * Add a message to the send queue
     */
    public static function add_to_queue($order_id, $event, $customer_name, $phone, $message) {
        global $wpdb;

        // Check if an identical message is already pending (avoid duplicate order notifications)
        if ($event !== 'test' && $order_id > 0) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM " . self::table_name() . " 
                 WHERE order_id = %d AND event = %s AND status = 'pending'",
                $order_id,
                $event
            ));

            if ($existing) {
                return $existing; // Already queued
            }
        }

        $now = current_time('mysql');
        $wpdb->insert(
            self::table_name(),
            array(
                'order_id'      => $order_id,
                'event'         => $event,
                'customer_name' => $customer_name,
                'phone'         => $phone,
                'message'       => $message,
                'status'        => 'pending',
                'attempts'      => 0,
                'created_at'    => $now,
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
        );

        return $wpdb->insert_id;
    }

    /**
     * Fetch pending messages for Chrome Extension and Desktop Agent
     * Priority: Test messages are always delivered first ahead of standard orders
     */
    public static function get_pending($limit = 10) {
        global $wpdb;
        $limit = absint($limit);
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::table_name() . " 
                 WHERE status = 'pending' 
                 ORDER BY CASE WHEN event = 'test' THEN 0 ELSE 1 END, id ASC 
                 LIMIT %d",
                $limit
            )
        );
    }

    /**
     * Mark queue item as sent or failed
     */
    public static function mark_status($id, $status, $error = null) {
        global $wpdb;
        $now = current_time('mysql');

        $data = array(
            'status' => $status,
        );
        $format = array('%s');

        if ($status === 'sent') {
            $data['sent_at'] = $now;
            $format[] = '%s';
        }

        if ($error !== null) {
            $data['error_message'] = sanitize_text_field($error);
            $format[] = '%s';
        }

        return $wpdb->update(
            self::table_name(),
            $data,
            array('id' => absint($id)),
            $format,
            array('%d')
        );
    }

    /**
     * Get queue statistics
     */
    public static function get_stats() {
        global $wpdb;
        $table = self::table_name();
        
        $pending = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'pending'");
        $sent    = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'sent'");
        $failed  = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'failed'");
        $total   = $wpdb->get_var("SELECT COUNT(*) FROM $table");

        return array(
            'pending' => (int) $pending,
            'sent'    => (int) $sent,
            'failed'  => (int) $failed,
            'total'   => (int) $total,
        );
    }

    /**
     * Get recent queue messages with details for dashboard
     */
    public static function get_recent($limit = 10) {
        global $wpdb;
        $table = self::table_name();
        $limit = absint($limit);
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, order_id, event, customer_name, phone, status, error_message, created_at, sent_at 
                 FROM $table 
                 ORDER BY id DESC 
                 LIMIT %d",
                $limit
            )
        );
    }

    /**
     * Delete old logs (older than X days)
     */
    public static function cleanup_logs($days = 30) {
        global $wpdb;
        $table = self::table_name();
        $date = gmdate('Y-m-d H:i:s', strtotime("-$days days"));
        return $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE status != 'pending' AND created_at < %s", $date));
    }

    /**
     * Clear all logs
     */
    public static function clear_all() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->query("TRUNCATE TABLE $table");
    }
}
}
