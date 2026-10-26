=== Busistree WhatsApp Order Bot for WooCommerce ===
Contributors: busistree
Tags: woocommerce, whatsapp, whatsapp bot, notifications, order notification, whatsapp notifications
Requires at least: 5.6
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Automated WhatsApp order notifications for WooCommerce with zero API costs. Integrates seamlessly with the companion Chrome Extension to send messages via WhatsApp Web.

== Description ==

Busistree WhatsApp Order Bot connects your WooCommerce store directly to WhatsApp Web via our automated Chrome Extension or 1-Click WhatsApp buttons.

= Features =
* **Zero API Fees:** Operates through your logged-in WhatsApp Web account in Google Chrome.
* **Dual Event Triggers:** Automated messages on new orders and status changes (Pending, Processing, Completed, Cancelled, On-Hold, Refunded).
* **Pakistani Number Normalization:** Automatically converts formats like 0300-1234567 or 3001234567 into international WhatsApp format (923001234567).
* **Custom Message Templates:** Full support for dynamic placeholders ({customer_name}, {order_number}, {items_list}, {total}, {currency}, {site_name}, etc.).
* **1-Click WhatsApp Chat:** Direct WhatsApp chat button inside WooCommerce Orders list and Order Details edit screen.
* **REST API:** High-speed queue endpoint for automated Chrome extension background polling.

== Installation ==

1. Upload the `busistree-whatsapp-notifier` folder to your `/wp-content/plugins/` directory, or upload the zip file via **Plugins > Add New > Upload Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **WhatsApp Bot** in the WordPress admin menu.
4. Copy the **Store URL** and **API Key** into your Busistree Chrome Extension settings.
5. Keep a Chrome tab open at `https://web.whatsapp.com` logged in to your business WhatsApp account.
