<?php
/**
 * WhatsApp Message Templates and Dynamic Variable Replacer
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('BWA_Templates')) {
class BWA_Templates {

    public static function init() {
        // Initialization if needed
    }

    /**
     * Default template texts
     */
    public static function get_default_templates() {
        return array(
            'pending' => "Assalam-o-Alaikum {customer_name}! 🚀\nThank you for choosing *{site_name}*! We have received your order.\n\n📦 *Order Ref:* #{order_number}\n🛍️ *Items:* {items_list}\n💰 *Total Amount:* *{currency} {total}* ({payment_method})\n📍 *Delivery Address:* {billing_address}\n📅 *Date:* {order_date}\n\n⚠️ *Order Confirmation:*\nPlease reply *'YES'* to confirm your order or *'NO'* to cancel.\n\n🌐 *{site_name}* | {site_url}",

            'processing' => "Assalam-o-Alaikum {customer_name}! 🚀\nGreat news! Your order *#{order_number}* is now *Actively In Progress*! 💻✨\n\n📦 *Order Ref:* #{order_number}\n🛍️ *Items:* {items_list}\n💰 *Total:* {currency} {total}\n\nOur team is currently preparing your order. We will notify you as soon as it is completed/shipped.\n\n🌐 *{site_name}* | {site_url}",

            'completed' => "Assalam-o-Alaikum {customer_name}! 🎉\nYour order *#{order_number}* has been *Successfully Completed and Delivered*! 🚀\n\n🛍️ *Items:* {items_list}\n💰 *Total Paid:* {currency} {total}\n\nThank you for trusting *{site_name}* as your partner.\n⭐ We would love to hear your feedback on your experience!\n🌐 *{site_name}* | {site_url}",

            'cancelled' => "Assalam-o-Alaikum {customer_name}!\nYour order *#{order_number}* has been marked as *Cancelled*.\n\nIf this was an error or you wish to resume your order, simply reply to this WhatsApp message and our support team will assist you immediately.\n🌐 *{site_name}* Support",

            'on-hold' => "Assalam-o-Alaikum {customer_name}!\nYour order *#{order_number}* is currently *On Hold* awaiting your confirmation.\n\n📦 *Order Ref:* #{order_number}\n🛍️ *Items:* {items_list}\n💰 *Total Due:* *{currency} {total}* ({payment_method})\n📍 *Delivery Address:* {billing_address}\n\n⚠️ *Order Confirmation:*\nPlease reply *'YES'* to confirm your order or *'NO'* to cancel.\n\n🌐 *{site_name}*",

            'refunded' => "Assalam-o-Alaikum {customer_name}!\nYour order *#{order_number}* has been *Refunded* for the amount of *{currency} {total}*.\n\nIf you have any questions regarding your refund, please feel free to reply to this message.\n🌐 *{site_name}*",
        );
    }

    /**
     * Store default templates into WP options if not already set
     */
    public static function set_default_templates() {
        $defaults = self::get_default_templates();
        $stored = get_option('bwa_templates', array());
        foreach ($defaults as $status => $template) {
            if (!isset($stored[$status]) || empty($stored[$status])) {
                $stored[$status] = $template;
            }
        }
        update_option('bwa_templates', $stored);
    }

    /**
     * Get a template for a given status
     */
    public static function get_template($status) {
        $templates = get_option('bwa_templates', array());
        if (isset($templates[$status]) && !empty(trim($templates[$status]))) {
            return $templates[$status];
        }
        $defaults = self::get_default_templates();
        return isset($defaults[$status]) ? $defaults[$status] : '';
    }

    /**
     * Normalize phone numbers (Pakistani and international)
     * e.g. 0303-4448822 -> 923034448822
     *      0300 1234567 -> 923001234567
     *      +92 300 1234567 -> 923001234567
     */
    public static function normalize_phone($raw, $default_country_code = '92') {
        if (empty($raw)) {
            return '';
        }

        // Keep only digits
        $digits = preg_replace('/\D/', '', (string) $raw);
        if (empty($digits)) {
            return '';
        }

        // Strip 00 international prefix
        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }

        // Pakistani mobile starting with 03 (e.g. 03001234567 or 0337042337)
        if (strpos($digits, '03') === 0) {
            $digits = '92' . substr($digits, 1);
        }
        // Starting with 3 (9 or 10 digits): 337042337 or 3001234567 -> 92...
        elseif (strpos($digits, '3') === 0 && strlen($digits) >= 9 && strlen($digits) <= 10) {
            $digits = '92' . $digits;
        }
        // Any other domestic leading zero
        elseif (strpos($digits, '0') === 0) {
            $digits = ltrim($digits, '0');
            if (strpos($digits, '3') === 0 && strlen($digits) >= 9 && strlen($digits) <= 10) {
                $digits = '92' . $digits;
            }
        }

        // A valid WhatsApp number can never start with 0
        if (strpos($digits, '0') === 0) {
            return '';
        }

        // Valid international format (between 10 and 15 digits)
        if (strlen($digits) < 10 || strlen($digits) > 15) {
            return '';
        }

        return $digits;
    }

    /**
     * Build items list string from a WooCommerce order
     */
    public static function get_items_summary($order) {
        $items = $order->get_items();
        if (empty($items)) {
            return 'Standard Service / Product';
        }

        $item_names = array();
        foreach ($items as $item) {
            $qty = $item->get_quantity();
            $name = $item->get_name();
            $item_names[] = ($qty > 1) ? "{$name} (x{$qty})" : $name;
        }

        return implode(', ', $item_names);
    }

    /**
     * Render the template by replacing dynamic tags with actual WooCommerce order data
     */
    public static function render_message($status, $order) {
        $template = self::get_template($status);
        if (empty($template)) {
            return '';
        }

        $customer_first_name = $order->get_billing_first_name();
        $customer_last_name  = $order->get_billing_last_name();
        $customer_full_name  = trim($customer_first_name . ' ' . $customer_last_name);
        if (empty($customer_full_name)) {
            $customer_full_name = 'Valued Customer';
        }

        $order_number   = $order->get_order_number();
        $order_id       = $order->get_id();
        $order_total    = number_format((float) $order->get_total(), 2);
        $currency       = $order->get_currency();
        $order_date     = $order->get_date_created() ? $order->get_date_created()->date_i18n(get_option('date_format', 'F j, Y')) : current_time('F j, Y');
        $payment_method = $order->get_payment_method_title() ? $order->get_payment_method_title() : 'Direct';
        $billing_phone  = $order->get_billing_phone();
        $billing_email  = $order->get_billing_email();
        $items_list     = self::get_items_summary($order);
        $site_name      = get_bloginfo('name');
        $site_url       = home_url();

        // Ensure unique internal confirmation token is stored for pending/on-hold orders
        $token = $order->get_meta('_bwa_confirmation_token');
        if (empty($token) && ($status === 'pending' || $status === 'on-hold')) {
            $token = 'bwa_tok_' . (function_exists('wp_generate_password') ? wp_generate_password(16, false) : md5(uniqid((string) $order_id, true)));
            $order->update_meta_data('_bwa_confirmation_token', $token);
            $order->save();
        }

        // Customer address line
        $address = trim($order->get_billing_address_1() . ', ' . $order->get_billing_city());

        $replacements = array(
            '{customer_name}'  => $customer_full_name,
            '{first_name}'     => !empty($customer_first_name) ? $customer_first_name : 'Valued Customer',
            '{last_name}'      => $customer_last_name,
            '{order_number}'   => $order_number,
            '{order_id}'       => $order_id,
            '{items_list}'     => $items_list,
            '{bundle_name}'    => $items_list, // Compatibility with Busistree bundle tag
            '{business_name}'  => $order->get_billing_company() ? $order->get_billing_company() : $customer_full_name,
            '{total}'          => $order_total,
            '{order_total}'    => $order_total,
            '{currency}'       => $currency,
            '{payment_method}' => $payment_method,
            '{order_date}'     => $order_date,
            '{billing_phone}'  => $billing_phone,
            '{customer_email}' => $billing_email,
            '{billing_email}'  => $billing_email,
            '{billing_address}'=> $address,
            '{site_name}'      => $site_name,
            '{site_url}'       => $site_url,
            '{order_status}'   => function_exists('wc_get_order_status_name') ? wc_get_order_status_name($status) : ucfirst((string) $status),
        );

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}
}
