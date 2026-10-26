<?php
/**
 * REST API Endpoints for Busistree Chrome Extension, Desktop Agent, and Mobile App
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('BWA_API')) {
class BWA_API {

    const NAMESPACE = 'busistree-wa/v1';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        // Ping / Health check
        register_rest_route(self::NAMESPACE, '/ping', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'endpoint_ping'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
        ));

        // Get Pending Queue
        register_rest_route(self::NAMESPACE, '/queue', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'endpoint_get_queue'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
            'args'                => array(
                'limit' => array(
                    'default'           => 10,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));

        // Mark Item Sent / Failed
        register_rest_route(self::NAMESPACE, '/mark-sent', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'endpoint_mark_sent'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
        ));

        // Trigger manual test message into queue
        register_rest_route(self::NAMESPACE, '/test', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'endpoint_queue_test'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
        ));

        // Customer Lookup by Phone (Used by Chrome Extension Side-Panel & Mobile Companion)
        register_rest_route(self::NAMESPACE, '/customer-orders', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'endpoint_customer_orders'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
        ));

        // Update Order Status & Confirmation Flag
        register_rest_route(self::NAMESPACE, '/update-order-status', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'endpoint_update_order_status'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
        ));

        // Manual WhatsApp Message Dispatch
        register_rest_route(self::NAMESPACE, '/send-manual-message', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'endpoint_send_manual_message'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
        ));

        // Search & Retrieve Products (id, name, price, sale_price, stock, image, permalink, variations, categories, total_sales)
        register_rest_route(self::NAMESPACE, '/search-products', array(
            'methods'             => array('GET', 'POST'),
            'callback'            => array(__CLASS__, 'endpoint_search_products'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
            'args'                => array(
                'query' => array(
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'category' => array(
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'limit' => array(
                    'default'           => 50,
                    'sanitize_callback' => 'absint',
                ),
                'max_price' => array(
                    'default'           => 0,
                    'sanitize_callback' => 'floatval',
                ),
                'sort' => array(
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // Get Single Product by ID (Sanitized, No Cost or Exact Stock Count)
        register_rest_route(self::NAMESPACE, '/product(?:/(?P<id>\d+))?', array(
            'methods'             => array('GET', 'POST'),
            'callback'            => array(__CLASS__, 'endpoint_get_product'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
            'args'                => array(
                'id' => array(
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));

        // Order Status with Phone Ownership Verification
        register_rest_route(self::NAMESPACE, '/order-status', array(
            'methods'             => array('GET', 'POST'),
            'callback'            => array(__CLASS__, 'endpoint_order_status'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
            'args'                => array(
                'order_number' => array(
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'order_id' => array(
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'phone' => array(
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // Create Order via WhatsApp In-Chat Ordering
        register_rest_route(self::NAMESPACE, '/create-order', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'endpoint_create_order'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
        ));

        // Store Information & Policies
        register_rest_route(self::NAMESPACE, '/store-info', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'endpoint_store_info'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
        ));

        // Store Insights (Cheapest, Best Sellers, Sale, New Arrivals, Public Coupons)
        register_rest_route(self::NAMESPACE, '/insights', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'endpoint_insights'),
            'permission_callback' => array(__CLASS__, 'check_permission'),
            'args'                => array(
                'category' => array(
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
    }

    /**
     * Check API Key permission
     */
    public static function check_permission($request) {
        $saved_key = get_option('bwa_api_key');
        if (empty($saved_key)) {
            return new WP_Error('bwa_no_key', 'API Key is not configured on WordPress server.', array('status' => 403));
        }

        // Check Header: X-BWA-API-Key
        $req_key = $request->get_header('x-bwa-api-key');

        // Check Authorization: Bearer <key>
        if (empty($req_key)) {
            $auth_header = $request->get_header('authorization');
            if ($auth_header && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
                $req_key = trim($matches[1]);
            }
        }

        // Check Query param: ?api_key=...
        if (empty($req_key)) {
            $req_key = $request->get_param('api_key');
        }

        if (empty($req_key) || !hash_equals($saved_key, $req_key)) {
            return new WP_Error('bwa_unauthorized', 'Invalid or missing API Key.', array('status' => 401));
        }

        return true;
    }

    /**
     * Ping endpoint
     */
    public static function endpoint_ping($request) {
        $stats = BWA_DB::get_stats();
        $recent = BWA_DB::get_recent(10);
        return rest_ensure_response(array(
            'success'     => true,
            'site_name'   => get_bloginfo('name'),
            'site_url'    => home_url(),
            'version'     => BWA_VERSION,
            'stats'       => $stats,
            'recent'      => $recent,
            'server_time' => current_time('mysql'),
        ));
    }

    /**
     * Get pending messages
     */
    public static function endpoint_get_queue($request) {
        $limit = $request->get_param('limit');
        $queue = BWA_DB::get_pending($limit);

        // Sanitize & normalize phone numbers, prune invalid ones
        $valid_queue = array();
        foreach ($queue as $item) {
            $norm = BWA_Templates::normalize_phone($item->phone);
            if (empty($norm)) {
                BWA_DB::mark_status($item->id, 'failed', 'Invalid phone number format (must be international format without leading 0)');
                continue;
            }
            $item->phone = $norm;
            $valid_queue[] = $item;
        }

        return rest_ensure_response(array(
            'success' => true,
            'count'   => count($valid_queue),
            'items'   => $valid_queue,
        ));
    }

    /**
     * Mark message status after extension sends it
     */
    public static function endpoint_mark_sent($request) {
        $params   = $request->get_json_params();
        $queue_id = isset($params['queue_id']) ? absint($params['queue_id']) : 0;
        $status   = isset($params['status']) ? sanitize_text_field($params['status']) : 'sent';
        $error    = isset($params['error']) ? sanitize_text_field($params['error']) : null;

        if (!$queue_id) {
            return new WP_Error('bwa_invalid_id', 'Queue ID is required.', array('status' => 400));
        }

        BWA_DB::mark_status($queue_id, $status, $error);

        // Fetch queue item to update WooCommerce order notes
        global $wpdb;
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . BWA_DB::table_name() . " WHERE id = %d",
            $queue_id
        ));

        if ($item && $item->order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($item->order_id);
            if ($order) {
                if ($status === 'sent') {
                    $order->add_order_note(
                        sprintf(
                            __('[Busistree WhatsApp] Message sent successfully to +%s via WhatsApp Web.', 'busistree-whatsapp'),
                            $item->phone
                        )
                    );
                } else {
                    $order->add_order_note(
                        sprintf(
                            __('[Busistree WhatsApp] Message delivery failed for +%s: %s', 'busistree-whatsapp'),
                            $item->phone,
                            $error ? $error : 'Unknown error'
                        )
                    );
                }
            }
        }

        return rest_ensure_response(array(
            'success'  => true,
            'queue_id' => $queue_id,
            'status'   => $status,
        ));
    }

    /**
     * Inject a test message into the queue
     */
    public static function endpoint_queue_test($request) {
        $params = $request->get_json_params();
        $phone  = isset($params['phone']) ? sanitize_text_field($params['phone']) : '';
        $msg    = isset($params['message']) ? sanitize_textarea_field($params['message']) : '';

        if (empty($phone)) {
            return new WP_Error('bwa_no_phone', 'Phone number is required.', array('status' => 400));
        }

        $normalized = BWA_Templates::normalize_phone($phone);
        if (empty($normalized)) {
            return new WP_Error('bwa_invalid_phone', 'Invalid phone number format.', array('status' => 400));
        }

        if (empty($msg)) {
            $msg = "Assalam-o-Alaikum! 🚀\nThis is a test notification from Busistree WhatsApp Bot for WooCommerce!\nSystem is operating normally.\n🌐 " . get_bloginfo('name');
        }

        $id = BWA_DB::add_to_queue(
            0,
            'test',
            'Test Recipient',
            $normalized,
            $msg
        );

        return rest_ensure_response(array(
            'success'  => true,
            'queue_id' => $id,
            'phone'    => $normalized,
        ));
    }

    /**
     * Customer Orders Lookup by Phone Number
     */
    public static function endpoint_customer_orders($request) {
        if (!function_exists('wc_get_orders')) {
            return new WP_Error('bwa_no_wc', 'WooCommerce is not active.', array('status' => 500));
        }

        $phone = $request->get_param('phone');
        if (empty($phone)) {
            return new WP_Error('bwa_no_phone', 'Phone parameter is required.', array('status' => 400));
        }

        $norm = BWA_Templates::normalize_phone($phone);
        // Find orders matching phone
        $orders = wc_get_orders(array(
            'limit'   => 10,
            'orderby' => 'date',
            'order'   => 'DESC',
            'billing_phone' => $phone,
        ));

        // If no direct billing_phone match, query with domestic variations
        if (empty($orders) && !empty($norm)) {
            $variations = array($norm, '0' . substr($norm, 2), '+' . $norm);
            foreach ($variations as $p) {
                $orders = wc_get_orders(array('limit' => 10, 'orderby' => 'date', 'order' => 'DESC', 'billing_phone' => $p));
                if (!empty($orders)) break;
            }
        }

        $result_orders = array();
        $total_spent = 0;
        $customer_name = 'Customer';

        foreach ($orders as $o) {
            $customer_name = trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()) ?: $customer_name;
            $total_spent += (float) $o->get_total();
            $result_orders[] = array(
                'id'                  => $o->get_id(),
                'order_number'        => $o->get_order_number(),
                'status'              => $o->get_status(),
                'status_name'         => function_exists('wc_get_order_status_name') ? wc_get_order_status_name($o->get_status()) : ucfirst($o->get_status()),
                'total'               => number_format((float) $o->get_total(), 2),
                'currency'            => $o->get_currency(),
                'date'                => $o->get_date_created() ? $o->get_date_created()->date('Y-m-d H:i') : '',
                'items_summary'       => BWA_Templates::get_items_summary($o),
                'confirmation_status' => $o->get_meta('_bwa_confirmation_status') ?: 'pending',
                'wa_confirmed'        => $o->get_meta('_wa_confirmed') ?: 'no',
                'billing_phone'       => $o->get_billing_phone(),
                'billing_address'     => trim($o->get_billing_address_1() . ', ' . $o->get_billing_city()),
            );
        }

        return rest_ensure_response(array(
            'success'       => true,
            'phone'         => $norm ?: $phone,
            'customer_name' => $customer_name,
            'total_spent'   => number_format($total_spent, 2),
            'order_count'   => count($result_orders),
            'orders'        => $result_orders,
        ));
    }

    /**
     * Update WooCommerce Order Status & WhatsApp Confirmation Flag
     */
    public static function endpoint_update_order_status($request) {
        if (!function_exists('wc_get_order')) {
            return new WP_Error('bwa_no_wc', 'WooCommerce is not active.', array('status' => 500));
        }

        $params   = $request->get_json_params();
        $order_id = isset($params['order_id']) ? absint($params['order_id']) : 0;
        $status   = isset($params['status']) ? sanitize_text_field($params['status']) : '';
        $confirm  = isset($params['confirmation_status']) ? sanitize_text_field($params['confirmation_status']) : '';
        $note     = isset($params['note']) ? sanitize_textarea_field($params['note']) : '';

        if (!$order_id) {
            return new WP_Error('bwa_invalid_order', 'Order ID is required.', array('status' => 400));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('bwa_not_found', 'Order not found.', array('status' => 404));
        }

        if (!empty($status) && in_array($status, array_keys(wc_get_order_statuses()))) {
            $order->update_status($status, $note ? '[Busistree Remote] ' . $note : '[Busistree Remote Action]');
        }

        if (!empty($confirm)) {
            $order->update_meta_data('_bwa_confirmation_status', $confirm);
            if ($confirm === 'confirmed') {
                $order->update_meta_data('_wa_confirmed', 'yes');
                $order->add_order_note($note ? $note : __('Customer confirmed via WhatsApp', 'busistree-whatsapp'));
            } elseif ($confirm === 'cancelled') {
                $order->update_meta_data('_wa_confirmed', 'cancelled');
                $order->add_order_note($note ? $note : __('Cancelled by customer via WhatsApp', 'busistree-whatsapp'));
            } elseif ($confirm === 'needs_admin') {
                $order->update_meta_data('_wa_confirmed', 'needs_admin');
                $order->add_order_note($note ? $note : __('Customer sent ambiguous confirmation reply - flagged for admin', 'busistree-whatsapp'));
            } else {
                $order->add_order_note(sprintf(__('[Busistree WhatsApp] Order confirmation status updated to "%s"', 'busistree-whatsapp'), ucfirst($confirm)));
            }
            $order->save();
        }

        return rest_ensure_response(array(
            'success'             => true,
            'order_id'            => $order_id,
            'new_status'          => $order->get_status(),
            'confirmation_status' => $order->get_meta('_bwa_confirmation_status'),
        ));
    }

    /**
     * Dispatch Manual Message via Queue
     */
    public static function endpoint_send_manual_message($request) {
        $params   = $request->get_json_params();
        $phone    = isset($params['phone']) ? sanitize_text_field($params['phone']) : '';
        $message  = isset($params['message']) ? sanitize_textarea_field($params['message']) : '';
        $order_id = isset($params['order_id']) ? absint($params['order_id']) : 0;
        $name     = isset($params['customer_name']) ? sanitize_text_field($params['customer_name']) : 'Customer';

        if (empty($phone) || empty($message)) {
            return new WP_Error('bwa_missing_fields', 'Phone and message are required.', array('status' => 400));
        }

        $norm = BWA_Templates::normalize_phone($phone);
        if (empty($norm)) {
            return new WP_Error('bwa_invalid_phone', 'Invalid phone number format.', array('status' => 400));
        }

        $qid = BWA_DB::add_to_queue($order_id, 'manual_admin', $name, $norm, $message);

        return rest_ensure_response(array(
            'success'  => true,
            'queue_id' => $qid,
            'phone'    => $norm,
        ));
    }

    /**
     * Search & Retrieve WooCommerce Products
     * Returns: id, name, price, sale_price, stock, image, permalink, variations, categories, total_sales
     */
    public static function endpoint_search_products($request) {
        if (!function_exists('wc_get_products')) {
            return new WP_Error('bwa_no_wc', 'WooCommerce is not active.', array('status' => 500));
        }

        $query     = sanitize_text_field($request->get_param('query'));
        $category  = sanitize_text_field($request->get_param('category'));
        $max_price = floatval($request->get_param('max_price'));
        $sort      = sanitize_text_field($request->get_param('sort'));
        $limit     = absint($request->get_param('limit'));
        if ($limit <= 0 || $limit > 100) {
            $limit = 50;
        }

        $args = array(
            'status'  => 'publish',
            'limit'   => $limit,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'objects',
        );

        if (!empty($query)) {
            $args['s'] = $query;
        }

        if (!empty($category)) {
            $args['category'] = array($category);
        }

        $products = wc_get_products($args);
        $results  = array();
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'PKR';
        $curr_sym = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : 'Rs.';

        if (!empty($products)) {
            foreach ($products as $prod) {
                if (!$prod instanceof WC_Product) {
                    continue;
                }

                $price_val = floatval($prod->get_price());
                if ($max_price > 0 && $price_val > $max_price) {
                    continue;
                }

                $pid        = $prod->get_id();
                $image_id   = $prod->get_image_id();
                $image_url  = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : '';
                $cat_terms  = wp_get_post_terms($pid, 'product_cat', array('fields' => 'names'));
                $categories = is_wp_error($cat_terms) ? array() : $cat_terms;

                // Variations for variable products
                $variations = array();
                if ($prod->is_type('variable')) {
                    $children = $prod->get_children();
                    if (!empty($children)) {
                        foreach ($children as $cid) {
                            $child = wc_get_product($cid);
                            if ($child instanceof WC_Product_Variation) {
                                $variations[] = array(
                                    'id'             => $cid,
                                    'name'           => $child->get_name(),
                                    'attributes'     => $child->get_attributes(),
                                    'price'          => $child->get_price(),
                                    'sale_price'     => $child->get_sale_price(),
                                    'stock'          => $child->get_stock_status(),
                                    'stock_status'   => $child->get_stock_status(),
                                );
                            }
                        }
                    }
                }

                $results[] = array(
                    'id'                => $pid,
                    'name'              => $prod->get_name(),
                    'price'             => $prod->get_price(),
                    'regular_price'     => $prod->get_regular_price(),
                    'sale_price'        => $prod->get_sale_price(),
                    'currency'          => $currency,
                    'currency_symbol'   => $curr_sym,
                    'stock'             => $prod->get_stock_status(), // 'instock', 'outofstock', 'onbackorder'
                    'stock_status'      => $prod->get_stock_status(),
                    'short_description' => wp_strip_all_tags($prod->get_short_description()),
                    'image'             => $image_url ? $image_url : '',
                    'permalink'         => $prod->get_permalink(),
                    'variations'        => $variations,
                    'categories'        => $categories,
                    'total_sales'       => absint($prod->get_total_sales()),
                );
            }
        }

        // Apply custom sorting if requested
        if ($sort === 'price_asc') {
            usort($results, function($a, $b) { return floatval($a['price']) <=> floatval($b['price']); });
        } elseif ($sort === 'price_desc') {
            usort($results, function($a, $b) { return floatval($b['price']) <=> floatval($a['price']); });
        } elseif ($sort === 'popularity' || $sort === 'top_seller') {
            usort($results, function($a, $b) { return intval($b['total_sales']) <=> intval($a['total_sales']); });
        }

        return rest_ensure_response(array(
            'success'  => true,
            'count'    => count($results),
            'query'    => $query,
            'products' => array_slice($results, 0, $limit),
        ));
    }

    /**
     * Get Single Product by ID (Safe, No Cost or Exact Stock Count)
     */
    public static function endpoint_get_product($request) {
        if (!function_exists('wc_get_product')) {
            return new WP_Error('bwa_no_wc', 'WooCommerce is not active.', array('status' => 500));
        }

        $id = absint($request->get_param('id'));
        if (!$id) {
            return new WP_Error('bwa_invalid_id', 'Product ID is required.', array('status' => 400));
        }

        $prod = wc_get_product($id);
        if (!$prod || !$prod->exists()) {
            return new WP_Error('bwa_not_found', 'Product not found.', array('status' => 404));
        }

        $pid        = $prod->get_id();
        $image_id   = $prod->get_image_id();
        $image_url  = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : '';
        $cat_terms  = wp_get_post_terms($pid, 'product_cat', array('fields' => 'names'));
        $categories = is_wp_error($cat_terms) ? array() : $cat_terms;

        $variations = array();
        if ($prod->is_type('variable')) {
            $children = $prod->get_children();
            if (!empty($children)) {
                foreach ($children as $cid) {
                    $child = wc_get_product($cid);
                    if ($child instanceof WC_Product_Variation) {
                        $variations[] = array(
                            'id'           => $cid,
                            'name'         => $child->get_name(),
                            'attributes'   => $child->get_attributes(),
                            'price'        => $child->get_price(),
                            'sale_price'   => $child->get_sale_price(),
                            'stock_status' => $child->get_stock_status(),
                        );
                    }
                }
            }
        }

        return rest_ensure_response(array(
            'success' => true,
            'product' => array(
                'id'                => $pid,
                'name'              => $prod->get_name(),
                'price'             => $prod->get_price(),
                'regular_price'     => $prod->get_regular_price(),
                'sale_price'        => $prod->get_sale_price(),
                'currency'          => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'PKR',
                'currency_symbol'   => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : 'Rs.',
                'stock_status'      => $prod->get_stock_status(),
                'short_description' => wp_strip_all_tags($prod->get_short_description()),
                'image'             => $image_url ? $image_url : '',
                'permalink'         => $prod->get_permalink(),
                'variations'        => $variations,
                'categories'        => $categories,
            ),
        ));
    }

    /**
     * Get Order Status with Customer Phone Ownership Verification
     */
    public static function endpoint_order_status($request) {
        if (!function_exists('wc_get_order')) {
            return new WP_Error('bwa_no_wc', 'WooCommerce is not active.', array('status' => 500));
        }

        $order_id_param = sanitize_text_field($request->get_param('order_number') ?: $request->get_param('order_id'));
        $req_phone      = sanitize_text_field($request->get_param('phone'));
        $norm_req_phone = BWA_Templates::normalize_phone($req_phone);

        $order_num = preg_replace('/\D/', '', $order_id_param);
        if (empty($order_num)) {
            return new WP_Error('bwa_invalid_order_number', 'Order number is required.', array('status' => 400));
        }

        $order = wc_get_order(absint($order_num));
        if (!$order) {
            $matching_orders = wc_get_orders(array('limit' => 1, 'order_number' => $order_num));
            if (!empty($matching_orders)) {
                $order = $matching_orders[0];
            }
        }

        if (!$order) {
            return rest_ensure_response(array(
                'success' => false,
                'found'   => false,
                'message' => "Order #{$order_num} not found.",
            ));
        }

        // Phone Ownership Verification
        $order_billing_phone = $order->get_billing_phone();
        $order_wa_phone      = $order->get_meta('_wa_phone');
        $norm_billing        = BWA_Templates::normalize_phone($order_billing_phone);
        $norm_wa             = BWA_Templates::normalize_phone($order_wa_phone);

        $phone_matches = false;
        if (!empty($norm_req_phone)) {
            if ($norm_req_phone === $norm_billing || $norm_req_phone === $norm_wa) {
                $phone_matches = true;
            } elseif (substr($norm_billing, -7) === substr($norm_req_phone, -7) || substr($norm_wa, -7) === substr($norm_req_phone, -7)) {
                $phone_matches = true;
            }
        }

        if (!empty($req_phone) && !$phone_matches) {
            return rest_ensure_response(array(
                'success'       => false,
                'found'         => false,
                'phone_matched' => false,
                'message'       => "I couldn't find that order on this number.",
                'offer_agent'   => true,
            ));
        }

        $items_summary = BWA_Templates::get_items_summary($order);
        $date_created = $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i') : '';
        $date_completed = $order->get_date_completed() ? $order->get_date_completed()->date('Y-m-d H:i') : null;

        return rest_ensure_response(array(
            'success'       => true,
            'found'         => true,
            'phone_matched' => true,
            'order'         => array(
                'id'                  => $order->get_id(),
                'order_number'        => $order->get_order_number(),
                'status'              => $order->get_status(),
                'status_name'         => function_exists('wc_get_order_status_name') ? wc_get_order_status_name($order->get_status()) : ucfirst($order->get_status()),
                'total'               => number_format((float) $order->get_total(), 2),
                'currency'            => $order->get_currency(),
                'date_created'        => $date_created,
                'date_completed'      => $date_completed,
                'items_summary'       => $items_summary,
                'shipping_method'     => $order->get_shipping_method() ?: 'Standard Delivery',
                'tracking_url'        => $order->get_meta('_tracking_url') ?: '',
            ),
        ));
    }

    /**
     * Create WooCommerce Order via WhatsApp In-Chat Ordering
     */
    public static function endpoint_create_order($request) {
        if (!function_exists('wc_create_order')) {
            return new WP_Error('bwa_no_wc', 'WooCommerce is not active.', array('status' => 500));
        }

        $params          = $request->get_json_params();
        $items           = isset($params['items']) && is_array($params['items']) ? $params['items'] : array();
        $customer_name   = isset($params['customer_name']) ? sanitize_text_field($params['customer_name']) : 'Customer';
        $phone           = isset($params['phone']) ? sanitize_text_field($params['phone']) : '';
        $wa_phone        = isset($params['wa_phone']) ? sanitize_text_field($params['wa_phone']) : $phone;
        $address         = isset($params['address']) ? sanitize_textarea_field($params['address']) : '';
        $city            = isset($params['city']) ? sanitize_text_field($params['city']) : '';
        $email           = isset($params['email']) ? sanitize_email($params['email']) : '';
        $order_notes     = isset($params['notes']) ? sanitize_textarea_field($params['notes']) : '';
        $shipping_total  = isset($params['shipping_total']) ? floatval($params['shipping_total']) : 0;
        $idempotency_key = isset($params['idempotency_key']) ? sanitize_text_field($params['idempotency_key']) : '';

        if (empty($items)) {
            return new WP_Error('bwa_missing_items', 'Order must contain at least one item.', array('status' => 400));
        }
        if (empty($phone) || empty($address) || empty($city)) {
            return new WP_Error('bwa_missing_customer_info', 'Customer phone, address, and city are required.', array('status' => 400));
        }

        // 1. Idempotency Guard: prevent duplicate orders
        if (!empty($idempotency_key)) {
            $existing = wc_get_orders(array(
                'limit'      => 1,
                'meta_key'   => '_bwa_idempotency_key',
                'meta_value' => $idempotency_key,
            ));
            if (!empty($existing)) {
                $order = $existing[0];
                return rest_ensure_response(array(
                    'success'      => true,
                    'is_duplicate' => true,
                    'order_id'     => $order->get_id(),
                    'order_number' => $order->get_order_number(),
                    'total'        => $order->get_total(),
                    'currency'     => $order->get_currency(),
                    'status'       => $order->get_status(),
                ));
            }
        }

        // 2. Pre-flight Live Stock Verification
        foreach ($items as $item) {
            $pid = absint($item['product_id']);
            $vid = isset($item['variation_id']) ? absint($item['variation_id']) : 0;
            $qty = isset($item['quantity']) ? max(1, absint($item['quantity'])) : 1;

            $prod_to_check = $vid > 0 ? wc_get_product($vid) : wc_get_product($pid);
            if (!$prod_to_check) {
                return new WP_Error('bwa_product_not_found', "Product #{$pid} not found.", array('status' => 404));
            }
            if (!$prod_to_check->is_in_stock()) {
                return new WP_Error('bwa_out_of_stock', "Product '{$prod_to_check->get_name()}' is out of stock.", array('status' => 409));
            }
            if ($prod_to_check->managing_stock() && !$prod_to_check->backorders_allowed() && $prod_to_check->get_stock_quantity() < $qty) {
                return new WP_Error('bwa_insufficient_stock', "Insufficient stock for '{$prod_to_check->get_name()}'.", array('status' => 409));
            }
        }

        // 3. Create Order
        $order = wc_create_order();
        if (is_wp_error($order) || !$order) {
            return new WP_Error('bwa_create_failed', 'Failed to create WooCommerce order.', array('status' => 500));
        }

        // Add line items
        foreach ($items as $item) {
            $pid = absint($item['product_id']);
            $vid = isset($item['variation_id']) ? absint($item['variation_id']) : 0;
            $qty = isset($item['quantity']) ? max(1, absint($item['quantity'])) : 1;

            $product = $vid > 0 ? wc_get_product($vid) : wc_get_product($pid);
            if ($product) {
                $item_args = array();
                if ($vid > 0 && isset($item['variation_attributes']) && is_array($item['variation_attributes'])) {
                    $item_args['variation'] = $item['variation_attributes'];
                }
                $order->add_product($product, $qty, $item_args);
            }
        }

        // Set customer address
        $address_data = array(
            'first_name' => $customer_name,
            'last_name'  => '',
            'phone'      => $phone,
            'address_1'  => $address,
            'city'       => $city,
            'country'    => 'PK',
            'email'      => !empty($email) ? $email : '',
        );
        $order->set_address($address_data, 'billing');
        $order->set_address($address_data, 'shipping');

        // Set payment method to COD
        $order->set_payment_method('cod');
        $order->set_payment_method_title('Cash on Delivery');

        // Shipping line
        if ($shipping_total > 0) {
            $ship_item = new WC_Order_Item_Shipping();
            $ship_item->set_method_title('Standard Delivery');
            $ship_item->set_method_id('flat_rate');
            $ship_item->set_total($shipping_total);
            $order->add_item($ship_item);
        }

        // Calculate totals
        $order->calculate_totals();

        // Metadata & Notes
        $order->update_meta_data('_created_via', 'whatsapp');
        $order->update_meta_data('_wa_phone', $wa_phone);
        if (!empty($idempotency_key)) {
            $order->update_meta_data('_bwa_idempotency_key', $idempotency_key);
        }
        if (!empty($order_notes)) {
            $order->set_customer_note($order_notes);
        }
        $order->add_order_note(__('Order created via WhatsApp In-Chat Ordering', 'busistree-whatsapp'));
        $order->set_status('pending');
        $order->save();

        return rest_ensure_response(array(
            'success'      => true,
            'order_id'     => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'total'        => $order->get_total(),
            'currency'     => $order->get_currency(),
            'status'       => $order->get_status(),
        ));
    }

    /**
     * Retrieve Store Policy Information & Operating Hours
     * Cached via WordPress Transients (5-10 minutes)
     */
    public static function endpoint_store_info($request) {
        $cached = get_transient('bwa_store_info_cache');
        if (!empty($cached) && is_array($cached)) {
            return rest_ensure_response($cached);
        }

        // Return Policy
        $return_page_id = get_option('bwa_page_return_policy');
        $return_text    = '';
        if ($return_page_id && ($page = get_post($return_page_id))) {
            $return_text = wp_strip_all_tags($page->post_content);
        }
        if (empty($return_text)) {
            $return_text = get_option('bwa_return_policy_text', "We offer a 7-day hassle-free return and exchange policy for unworn, unused items with original tags and packaging intact. Refunds for returned items are processed within 5-7 business days via original payment method or bank transfer. Defective or incorrect items are replaced free of charge.");
        }

        // Shipping Policy
        $shipping_page_id = get_option('bwa_page_shipping_policy');
        $shipping_text    = '';
        if ($shipping_page_id && ($page = get_post($shipping_page_id))) {
            $shipping_text = wp_strip_all_tags($page->post_content);
        }
        if (empty($shipping_text)) {
            $shipping_text = get_option('bwa_shipping_policy_text', "Standard delivery fee is PKR 199 across Pakistan. Free shipping on all orders over PKR 2,500. Delivery time is 2 to 4 business days for major cities, and 3 to 5 business days for other areas.");
        }

        // Payment Methods
        $payment_text = get_option('bwa_payment_methods_text', "We accept Cash on Delivery (COD) across Pakistan and Online Bank Transfer. Payment is collected in cash at your doorstep when your parcel is delivered.");

        // Contact Information
        $contact_info = array(
            'phone'    => get_option('bwa_contact_phone', get_option('admin_email')),
            'whatsapp' => get_option('bwa_contact_whatsapp', ''),
            'email'    => get_option('bwa_contact_email', get_option('admin_email')),
            'address'  => get_option('bwa_store_address', 'Lahore, Pakistan'),
        );

        // Store Hours
        $store_hours = get_option('bwa_store_hours', "Monday to Saturday: 10:00 AM – 8:00 PM PKT. Sunday: Closed.");

        // Currency
        $currency        = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'PKR';
        $currency_symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : 'Rs.';

        $return_days = absint(get_option('bwa_return_days', 7));
        if ($return_days <= 0) {
            $return_days = 7;
        }

        $data = array(
            'success'         => true,
            'site_name'       => get_bloginfo('name'),
            'site_url'        => home_url(),
            'currency'        => $currency,
            'currency_symbol' => $currency_symbol,
            'store_hours'     => $store_hours,
            'policies'        => array(
                'return_refund'   => $return_text,
                'return_days'     => $return_days,
                'shipping'        => $shipping_text,
                'standard_fee'    => 199,
                'free_threshold'  => 2500,
                'delivery_time'   => "2 to 4 business days",
                'payment_methods' => $payment_text,
                'cod_available'   => true,
                'bank_transfer'   => true,
            ),
            'contact'         => $contact_info,
            'faqs'            => array(
                array(
                    'question' => 'How can I track my order?',
                    'answer'   => 'You can track your order by sending your Order # (e.g. #1024) right here on WhatsApp.',
                ),
                array(
                    'question' => 'Do you deliver all over Pakistan?',
                    'answer'   => 'Yes, we deliver nationwide via trusted courier partners within 2-4 business days.',
                ),
            ),
        );

        set_transient('bwa_store_info_cache', $data, 300); // 5 minutes cache
        return rest_ensure_response($data);
    }

    /**
     * Retrieve Store Insights (Cheapest, Most Expensive, Top Sellers, Sale, New Arrivals)
     * Cached via WordPress Transients (5-10 minutes)
     */
    public static function endpoint_insights($request) {
        $category = sanitize_text_field($request->get_param('category'));
        $cache_key = 'bwa_insights_cache_' . md5($category);
        $cached    = get_transient($cache_key);

        if (!empty($cached) && is_array($cached)) {
            return rest_ensure_response($cached);
        }

        if (!function_exists('wc_get_products')) {
            return new WP_Error('bwa_no_wc', 'WooCommerce is not active.', array('status' => 500));
        }

        $base_args = array(
            'status' => 'publish',
            'limit'  => 5,
            'return' => 'objects',
        );

        if (!empty($category)) {
            $base_args['category'] = array($category);
        }

        // Helper to format product insight item safely (never exposes cost or sensitive admin data)
        $format_product = function($prod) {
            if (!$prod instanceof WC_Product) return null;
            $stock_status = $prod->get_stock_status();
            $stock_display = $stock_status === 'instock' ? 'In Stock' : 'Out of Stock';
            if ($stock_status === 'instock' && $prod->managing_stock() && $prod->get_stock_quantity() <= 3 && $prod->get_stock_quantity() > 0) {
                $stock_display = 'Only few left';
            }

            return array(
                'id'          => $prod->get_id(),
                'name'        => $prod->get_name(),
                'price'       => $prod->get_price(),
                'sale_price'  => $prod->get_sale_price(),
                'stock'       => $stock_status,
                'stock_label' => $stock_display,
                'image'       => wp_get_attachment_image_url($prod->get_image_id(), 'medium') ?: '',
                'permalink'   => $prod->get_permalink(),
                'categories'  => wp_get_post_terms($prod->get_id(), 'product_cat', array('fields' => 'names')),
                'total_sales' => absint($prod->get_total_sales()),
            );
        };

        // 1. Cheapest products
        $cheapest_args = array_merge($base_args, array('orderby' => 'price', 'order' => 'ASC'));
        $cheapest_raw  = wc_get_products($cheapest_args);
        $cheapest      = array_values(array_filter(array_map($format_product, $cheapest_raw)));

        // 2. Most expensive products
        $expensive_args = array_merge($base_args, array('orderby' => 'price', 'order' => 'DESC'));
        $expensive_raw  = wc_get_products($expensive_args);
        $most_expensive = array_values(array_filter(array_map($format_product, $expensive_raw)));

        // 3. Top sellers (by total_sales)
        $top_seller_args = array_merge($base_args, array('meta_key' => 'total_sales', 'orderby' => 'meta_value_num', 'order' => 'DESC'));
        $top_raw         = wc_get_products($top_seller_args);
        $top_sellers     = array_values(array_filter(array_map($format_product, $top_raw)));

        // 4. New arrivals
        $new_args   = array_merge($base_args, array('orderby' => 'date', 'order' => 'DESC'));
        $new_raw    = wc_get_products($new_args);
        $new_arrivals = array_values(array_filter(array_map($format_product, $new_raw)));

        // 5. Products on Sale
        $sale_ids = function_exists('wc_get_product_ids_on_sale') ? wc_get_product_ids_on_sale() : array();
        $on_sale  = array();
        if (!empty($sale_ids)) {
            $sale_count = 0;
            foreach ($sale_ids as $sid) {
                if ($sale_count >= 5) break;
                $sp = wc_get_product($sid);
                if ($sp && $sp->is_visible()) {
                    $formatted = $format_product($sp);
                    if ($formatted) {
                        $date_to = $sp->get_date_on_sale_to();
                        $formatted['sale_end_date'] = $date_to ? $date_to->date('Y-m-d') : null;
                        $on_sale[] = $formatted;
                        $sale_count++;
                    }
                }
            }
        }

        // 6. Top Categories
        $top_categories = array();
        $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 8));
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $t) {
                $top_categories[] = array(
                    'name'  => $t->name,
                    'slug'  => $t->slug,
                    'count' => $t->count,
                );
            }
        }

        // 7. Public Coupons ONLY (never expose private, single-use, or admin coupons)
        $public_codes = get_option('bwa_public_coupons', array('WELCOME10', 'SALE20'));
        if (is_string($public_codes)) {
            $public_codes = array_map('trim', explode(',', $public_codes));
        }
        $public_coupons = array();
        if (!empty($public_codes) && is_array($public_codes)) {
            foreach ($public_codes as $code) {
                if (empty($code)) continue;
                $coupon = new WC_Coupon($code);
                if ($coupon->get_id() > 0) {
                    $public_coupons[] = array(
                        'code'        => strtoupper($code),
                        'discount'    => $coupon->get_amount(),
                        'type'        => $coupon->get_discount_type(),
                        'description' => $coupon->get_description() ?: 'Special Store Discount',
                    );
                }
            }
        }

        $data = array(
            'success'                 => true,
            'category_scope'          => $category,
            'cheapest_products'       => $cheapest,
            'most_expensive_products' => $most_expensive,
            'top_sellers'             => $top_sellers,
            'new_arrivals'            => $new_arrivals,
            'on_sale_products'        => $on_sale,
            'top_categories'          => $top_categories,
            'free_shipping_threshold' => 2500,
            'public_coupons'          => $public_coupons,
        );

        set_transient($cache_key, $data, 300); // 5 minutes cache
        return rest_ensure_response($data);
    }
}
}
