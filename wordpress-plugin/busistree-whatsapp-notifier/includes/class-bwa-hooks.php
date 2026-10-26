<?php
/**
 * WooCommerce Order Hooks, Checkout Fields, WhatsApp 1-Click Buttons & Event Listeners
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('BWA_Hooks')) {
class BWA_Hooks {

    public static function init() {
        // Listen to WooCommerce order status transitions
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'on_order_status_changed'), 10, 4);

        // Listen for newly created orders via checkout
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'on_new_order_placed'), 20, 3);

        // Checkout: Add WhatsApp field & Opt-in consent checkbox
        add_filter('woocommerce_checkout_fields', array(__CLASS__, 'add_checkout_whatsapp_fields'), 20);
        add_action('woocommerce_checkout_update_order_meta', array(__CLASS__, 'save_checkout_whatsapp_fields'), 20, 2);

        // "Order via WhatsApp" button on Single Product & Cart
        add_action('woocommerce_after_add_to_cart_button', array(__CLASS__, 'render_product_whatsapp_button'), 25);
        add_action('woocommerce_after_cart_totals', array(__CLASS__, 'render_cart_whatsapp_button'), 20);
        add_shortcode('busistree_order_whatsapp', array(__CLASS__, 'whatsapp_button_shortcode'));

        // Low stock notifications to Store Admin
        add_action('woocommerce_low_stock', array(__CLASS__, 'on_low_stock'), 10, 1);
        add_action('woocommerce_no_stock', array(__CLASS__, 'on_low_stock'), 10, 1);
    }

    /**
     * Add WhatsApp specific fields to Checkout
     */
    public static function add_checkout_whatsapp_fields($fields) {
        $enabled = get_option('bwa_enable_checkout_fields', 'yes');
        if ($enabled !== 'yes') {
            return $fields;
        }

        $fields['billing']['billing_whatsapp_phone'] = array(
            'type'        => 'tel',
            'label'       => __('WhatsApp Phone Number', 'busistree-whatsapp'),
            'placeholder' => __('e.g. 03001234567 or +923001234567', 'busistree-whatsapp'),
            'required'    => false,
            'class'       => array('form-row-wide'),
            'clear'       => true,
            'description' => __('Leave blank if same as billing phone above.', 'busistree-whatsapp'),
            'priority'    => 105,
        );

        $fields['order']['bwa_whatsapp_optin'] = array(
            'type'        => 'checkbox',
            'label'       => __('Send me order tracking updates, invoice, and delivery alerts on WhatsApp', 'busistree-whatsapp'),
            'default'     => 1,
            'required'    => false,
            'class'       => array('form-row-wide', 'bwa-optin-checkbox'),
            'priority'    => 110,
        );

        return $fields;
    }

    /**
     * Save WhatsApp checkout fields to order meta
     */
    public static function save_checkout_whatsapp_fields($order_id, $posted_data) {
        if (!empty($_POST['billing_whatsapp_phone'])) {
            $raw = sanitize_text_field($_POST['billing_whatsapp_phone']);
            update_post_meta($order_id, '_billing_whatsapp_phone', $raw);
            if (function_exists('wc_get_order')) {
                $order = wc_get_order($order_id);
                if ($order && method_exists($order, 'update_meta_data')) {
                    $order->update_meta_data('_billing_whatsapp_phone', $raw);
                    $order->save();
                }
            }
        }

        $optin = isset($_POST['bwa_whatsapp_optin']) ? 'yes' : 'no';
        update_post_meta($order_id, '_bwa_whatsapp_optin', $optin);
    }

    /**
     * Triggered when order status changes (e.g. pending -> processing, completed, cancelled, etc.)
     */
    public static function on_order_status_changed($order_id, $old_status, $new_status, $order = null) {
        if (!function_exists('wc_get_order')) {
            return;
        }
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }

        self::maybe_queue_notification($order, $new_status);
    }

    /**
     * Triggered upon checkout completion
     */
    public static function on_new_order_placed($order_id, $posted_data = array(), $order = null) {
        if (!function_exists('wc_get_order')) {
            return;
        }
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }

        $current_status = $order->get_status();
        // If order starts as 'pending' or 'processing', queue the initial confirmation if enabled
        self::maybe_queue_notification($order, $current_status);
    }

    /**
     * Verify if event is enabled and queue the WhatsApp notification
     */
    public static function maybe_queue_notification($order, $status) {
        $enabled_events = get_option('bwa_enabled_events', array());

        // Check if notifications are enabled for this specific status
        if (empty($enabled_events[$status]) || $enabled_events[$status] !== 'yes') {
            return;
        }

        // Check opt-in if required
        $require_optin = get_option('bwa_require_optin', 'no');
        if ($require_optin === 'yes') {
            $optin = $order->get_meta('_bwa_whatsapp_optin');
            if ($optin === 'no') {
                return;
            }
        }

        // Determine phone: prefer explicit WhatsApp phone, fallback to billing phone
        $raw_phone = $order->get_meta('_billing_whatsapp_phone');
        if (empty($raw_phone)) {
            $raw_phone = $order->get_billing_phone();
        }
        if (empty($raw_phone)) {
            return;
        }

        $normalized_phone = BWA_Templates::normalize_phone($raw_phone);
        if (empty($normalized_phone)) {
            return;
        }

        $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        if (empty($customer_name)) {
            $customer_name = 'Customer';
        }

        // Render message
        $message = BWA_Templates::render_message($status, $order);
        if (empty($message)) {
            return;
        }

        // Add to queue
        $queue_id = BWA_DB::add_to_queue(
            $order->get_id(),
            $status,
            $customer_name,
            $normalized_phone,
            $message
        );

        if ($queue_id) {
            $order->add_order_note(
                sprintf(
                    __('[Busistree WhatsApp] Queued "%s" notification to +%s (Queue #%d)', 'busistree-whatsapp'),
                    function_exists('wc_get_order_status_name') ? wc_get_order_status_name($status) : ucfirst((string) $status),
                    $normalized_phone,
                    $queue_id
                )
            );
        }
    }

    /**
     * Render "Order via WhatsApp" button on Single Product page
     */
    public static function render_product_whatsapp_button() {
        $enabled = get_option('bwa_enable_product_wa_button', 'yes');
        if ($enabled !== 'yes') {
            return;
        }

        global $product;
        if (!$product) {
            return;
        }

        $store_phone = get_option('bwa_store_wa_phone', '');
        if (empty($store_phone)) {
            return;
        }

        $clean_phone = BWA_Templates::normalize_phone($store_phone);
        $title = $product->get_name();
        $sku = $product->get_sku() ? ' (SKU: ' . $product->get_sku() . ')' : '';
        $price = html_entity_decode(strip_tags(wc_price($product->get_price())));
        $permalink = get_permalink($product->get_id());

        $default_msg = sprintf(
            "Hello! I am interested in purchasing:\n*%s*%s\nPrice: %s\nProduct Link: %s\nPlease let me know availability and delivery details!",
            $title,
            $sku,
            $price,
            $permalink
        );

        $custom_tpl = get_option('bwa_wa_button_template', '');
        if (!empty($custom_tpl)) {
            $msg = str_replace(
                array('{product_name}', '{sku}', '{price}', '{product_url}', '{store_name}'),
                array($title, $sku, $price, $permalink, get_bloginfo('name')),
                $custom_tpl
            );
        } else {
            $msg = $default_msg;
        }

        $wa_url = 'https://wa.me/' . $clean_phone . '?text=' . rawurlencode($msg);

        echo '<div class="bwa-product-button-wrap" style="margin: 12px 0;">';
        echo '<a href="' . esc_url($wa_url) . '" target="_blank" rel="noopener noreferrer" class="button alt bwa-btn-order-wa" style="background-color: #25D366; color: #fff; border-color: #25D366; display: inline-flex; align-items: center; gap: 8px; font-weight: 600; padding: 10px 18px; border-radius: 6px; text-decoration: none;">';
        echo '<span style="font-size: 18px; line-height: 1;">💬</span> ' . esc_html__('Order via WhatsApp', 'busistree-whatsapp');
        echo '</a>';
        echo '</div>';
    }

    /**
     * Render "Order via WhatsApp" button on Cart page
     */
    public static function render_cart_whatsapp_button() {
        $enabled = get_option('bwa_enable_cart_wa_button', 'yes');
        if ($enabled !== 'yes' || !function_exists('WC') || !WC()->cart) {
            return;
        }

        $store_phone = get_option('bwa_store_wa_phone', '');
        if (empty($store_phone)) {
            return;
        }

        $clean_phone = BWA_Templates::normalize_phone($store_phone);
        $cart = WC()->cart;
        if ($cart->is_empty()) {
            return;
        }

        $items = array();
        foreach ($cart->get_cart() as $cart_item) {
            $prod = $cart_item['data'];
            $items[] = '• ' . $prod->get_name() . ' x ' . $cart_item['quantity'];
        }
        $total = html_entity_decode(strip_tags($cart->get_total()));

        $msg = sprintf(
            "Hello %s! I would like to place an order for my cart:\n\n%s\n\nTotal: %s\nPlease assist me with checkout!",
            get_bloginfo('name'),
            implode("\n", $items),
            $total
        );

        $wa_url = 'https://wa.me/' . $clean_phone . '?text=' . rawurlencode($msg);

        echo '<div class="bwa-cart-button-wrap" style="margin-top: 15px; text-align: right;">';
        echo '<a href="' . esc_url($wa_url) . '" target="_blank" rel="noopener noreferrer" class="button bwa-btn-cart-wa" style="background-color: #25D366; color: #fff; border-color: #25D366; display: inline-flex; align-items: center; gap: 8px; font-weight: 600; padding: 12px 20px; border-radius: 6px; text-decoration: none;">';
        echo '<span style="font-size: 18px; line-height: 1;">💬</span> ' . esc_html__('Instant Checkout via WhatsApp', 'busistree-whatsapp');
        echo '</a>';
        echo '</div>';
    }

    /**
     * Shortcode [busistree_order_whatsapp]
     */
    public static function whatsapp_button_shortcode($atts) {
        $store_phone = get_option('bwa_store_wa_phone', '');
        if (empty($store_phone)) {
            return '';
        }
        $clean_phone = BWA_Templates::normalize_phone($store_phone);
        $url = 'https://wa.me/' . $clean_phone;
        return '<a href="' . esc_url($url) . '" target="_blank" class="button bwa-btn-order-wa" style="background:#25D366; color:#fff; border-radius:6px; padding:10px 16px; font-weight:600; text-decoration:none;">💬 ' . esc_html__('Chat on WhatsApp', 'busistree-whatsapp') . '</a>';
    }

    /**
     * Trigger alert to store manager on Low Stock
     */
    public static function on_low_stock($product) {
        $enabled = get_option('bwa_enable_low_stock_alert', 'yes');
        $admin_phone = get_option('bwa_store_wa_phone', '');
        if ($enabled !== 'yes' || empty($admin_phone) || !$product) {
            return;
        }

        $clean_phone = BWA_Templates::normalize_phone($admin_phone);
        $name = $product->get_name();
        $stock = $product->get_stock_quantity();
        $sku = $product->get_sku() ? ' (SKU: ' . $product->get_sku() . ')' : '';

        $msg = sprintf(
            "⚠️ *[Low Stock Alert - %s]*\nProduct: *%s*%s\nRemaining Stock: %s\nPlease restock soon!",
            get_bloginfo('name'),
            $name,
            $sku,
            $stock !== null ? $stock : 'Out of Stock'
        );

        BWA_DB::add_to_queue(0, 'low_stock_admin', 'Store Admin', $clean_phone, $msg);
    }
}
}
