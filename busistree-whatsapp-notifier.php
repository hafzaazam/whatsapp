<?php
/**
 * Plugin Name: Busistree WhatsApp Order & Status Bot
 * Plugin URI:  https://busistree.com
 * Description: Automated WhatsApp order notifications for WooCommerce with zero API fees. Integrates seamlessly with the Busistree Chrome Extension for automated WhatsApp Web sending, plus 1-Click WhatsApp buttons.
 * Version:     1.2.0
 * Author:      Busistree
 * Author URI:  https://busistree.com
 * Text Domain: busistree-whatsapp
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 4.0
 * WC tested up to: 9.3
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define('BWA_VERSION', '1.2.0');
define('BWA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BWA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BWA_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Autoload classes
require_once BWA_PLUGIN_DIR . 'includes/class-bwa-db.php';
require_once BWA_PLUGIN_DIR . 'includes/class-bwa-templates.php';
require_once BWA_PLUGIN_DIR . 'includes/class-bwa-hooks.php';
require_once BWA_PLUGIN_DIR . 'includes/class-bwa-api.php';
require_once BWA_PLUGIN_DIR . 'includes/class-bwa-admin.php';

// Declare HPOS (High-Performance Order Storage) compatibility early
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Main Plugin Class
 */
class Busistree_WhatsApp_Bot {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function is_woocommerce_active() {
        return class_exists('WooCommerce') || in_array('woocommerce/woocommerce.php', (array) apply_filters('active_plugins', get_option('active_plugins', array())));
    }

    private function __construct() {
        // Activation & Deactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Initialize components
        add_action('plugins_loaded', array($this, 'init'));
    }

    public function init() {
        // Initialize Subsystems
        BWA_DB::init();
        BWA_Templates::init();
        BWA_Hooks::init();
        BWA_API::init();
        BWA_Admin::init();

        // Admin notice if WooCommerce is inactive
        if (is_admin() && !self::is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_inactive_notice'));
        }
    }

    public function woocommerce_inactive_notice() {
        echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__('Busistree WhatsApp Bot', 'busistree-whatsapp') . ':</strong> ' . esc_html__('WooCommerce is not currently active. Please activate WooCommerce to enable automated order notifications.', 'busistree-whatsapp') . '</p></div>';
    }

    public function activate() {
        BWA_DB::create_tables();

        // Generate default API key if not set
        if (!get_option('bwa_api_key')) {
            update_option('bwa_api_key', 'bwa_' . wp_generate_password(32, false));
        }

        // Set default enabled statuses
        if (!get_option('bwa_enabled_events')) {
            update_option('bwa_enabled_events', array(
                'pending'    => 'yes',
                'processing' => 'yes',
                'completed'  => 'yes',
                'cancelled'  => 'yes',
                'on-hold'    => 'no',
                'refunded'   => 'no',
            ));
        }

        // Default templates setup
        BWA_Templates::set_default_templates();
    }

    public function deactivate() {
        // Clean up any scheduled cron if used
    }
}

// Instantiate
Busistree_WhatsApp_Bot::instance();
