<?php
/**
 * Admin Settings, Dashboard, and WooCommerce Meta Boxes
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('BWA_Admin')) {
class BWA_Admin {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('admin_init', array(__CLASS__, 'handle_form_actions'));

        // WooCommerce Order Screen integration
        add_filter('manage_edit-shop_order_columns', array(__CLASS__, 'add_order_column'));
        add_action('manage_shop_order_posts_custom_column', array(__CLASS__, 'render_order_column'), 10, 2);
        // HPOS support for custom column
        add_filter('manage_woocommerce_page_wc-orders_columns', array(__CLASS__, 'add_order_column'));
        add_action('manage_woocommerce_page_wc-orders_custom_column', array(__CLASS__, 'render_hpos_order_column'), 10, 2);

        // Order details meta box
        add_action('add_meta_boxes', array(__CLASS__, 'register_order_meta_box'));

        // App and Extension Package Downloader
        add_action('admin_post_bwa_download_app', array(__CLASS__, 'handle_download_app'));
    }

    public static function register_admin_menu() {
        add_menu_page(
            __('Busistree WhatsApp', 'busistree-whatsapp'),
            __('WhatsApp Bot', 'busistree-whatsapp'),
            'manage_options',
            'busistree-whatsapp',
            array(__CLASS__, 'render_admin_page'),
            'dashicons-share-alt',
            56
        );
    }

    public static function enqueue_assets($hook) {
        if (strpos($hook, 'busistree-whatsapp') !== false || strpos($hook, 'shop_order') !== false || strpos($hook, 'wc-orders') !== false) {
            wp_enqueue_style(
                'bwa-admin-css',
                BWA_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                BWA_VERSION
            );
            wp_enqueue_script(
                'bwa-admin-js',
                BWA_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                BWA_VERSION,
                true
            );
            wp_localize_script('bwa-admin-js', 'bwaAdmin', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('bwa_admin_nonce'),
            ));
        }
    }

    public static function handle_form_actions() {
        if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
            return;
        }

        // Save Settings
        if (isset($_POST['bwa_save_settings_nonce']) && wp_verify_nonce($_POST['bwa_save_settings_nonce'], 'bwa_save_settings')) {
            // Save enabled events
            $events = array();
            $allowed = array('pending', 'processing', 'completed', 'cancelled', 'on-hold', 'refunded');
            foreach ($allowed as $evt) {
                $events[$evt] = isset($_POST['bwa_event_' . $evt]) ? 'yes' : 'no';
            }
            update_option('bwa_enabled_events', $events);

            // Save Return Policy Window Days & Policy Pages
            if (isset($_POST['bwa_return_days'])) {
                update_option('bwa_return_days', max(1, absint($_POST['bwa_return_days'])));
                delete_transient('bwa_store_info_cache');
            }
            if (isset($_POST['bwa_page_return_policy'])) {
                update_option('bwa_page_return_policy', absint($_POST['bwa_page_return_policy']));
                delete_transient('bwa_store_info_cache');
            }
            if (isset($_POST['bwa_page_shipping_policy'])) {
                update_option('bwa_page_shipping_policy', absint($_POST['bwa_page_shipping_policy']));
                delete_transient('bwa_store_info_cache');
            }

            // Regenerate API Key
            if (!empty($_POST['bwa_regenerate_key'])) {
                update_option('bwa_api_key', 'bwa_' . wp_generate_password(32, false));
            }

            add_settings_error('bwa_messages', 'bwa_saved', __('Settings saved successfully.', 'busistree-whatsapp'), 'updated');
        }

        // Save Templates
        if (isset($_POST['bwa_save_templates_nonce']) && wp_verify_nonce($_POST['bwa_save_templates_nonce'], 'bwa_save_templates')) {
            $templates = get_option('bwa_templates', array());
            $statuses = array('pending', 'processing', 'completed', 'cancelled', 'on-hold', 'refunded');
            foreach ($statuses as $st) {
                if (isset($_POST['bwa_template_' . $st])) {
                    $templates[$st] = wp_kses_post(wp_unslash($_POST['bwa_template_' . $st]));
                }
            }
            update_option('bwa_templates', $templates);
            add_settings_error('bwa_messages', 'bwa_tpl_saved', __('WhatsApp message templates updated.', 'busistree-whatsapp'), 'updated');
        }

        // Reset Templates
        if (isset($_POST['bwa_reset_templates_nonce']) && wp_verify_nonce($_POST['bwa_reset_templates_nonce'], 'bwa_reset_templates')) {
            update_option('bwa_templates', BWA_Templates::get_default_templates());
            add_settings_error('bwa_messages', 'bwa_tpl_reset', __('Templates have been reset to default Busistree templates.', 'busistree-whatsapp'), 'updated');
        }

        // Clear Logs
        if (isset($_POST['bwa_clear_logs_nonce']) && wp_verify_nonce($_POST['bwa_clear_logs_nonce'], 'bwa_clear_logs')) {
            BWA_DB::clear_all();
            add_settings_error('bwa_messages', 'bwa_cleared', __('Message queue and logs cleared.', 'busistree-whatsapp'), 'updated');
        }

        // Manual Test Send to Queue
        if (isset($_POST['bwa_send_test_nonce']) && wp_verify_nonce($_POST['bwa_send_test_nonce'], 'bwa_send_test')) {
            $phone = sanitize_text_field($_POST['bwa_test_phone']);
            $msg   = sanitize_textarea_field($_POST['bwa_test_message']);
            $norm  = BWA_Templates::normalize_phone($phone);

            if ($norm) {
                BWA_DB::add_to_queue(0, 'test', 'Admin Test', $norm, $msg);
                add_settings_error('bwa_messages', 'bwa_test_queued', sprintf(__('Test message added to queue for +%s. Chrome Extension will send it shortly!', 'busistree-whatsapp'), $norm), 'updated');
            } else {
                add_settings_error('bwa_messages', 'bwa_test_err', __('Invalid phone number format.', 'busistree-whatsapp'), 'error');
            }
        }

        // Save WhatsApp Buttons & Checkout Settings
        if (isset($_POST['bwa_save_buttons_nonce']) && wp_verify_nonce($_POST['bwa_save_buttons_nonce'], 'bwa_save_buttons')) {
            update_option('bwa_enable_product_wa_button', isset($_POST['bwa_enable_product_wa_button']) ? 'yes' : 'no');
            update_option('bwa_enable_cart_wa_button', isset($_POST['bwa_enable_cart_wa_button']) ? 'yes' : 'no');
            update_option('bwa_enable_checkout_fields', isset($_POST['bwa_enable_checkout_fields']) ? 'yes' : 'no');
            update_option('bwa_require_optin', isset($_POST['bwa_require_optin']) ? 'yes' : 'no');
            update_option('bwa_enable_low_stock_alert', isset($_POST['bwa_enable_low_stock_alert']) ? 'yes' : 'no');
            if (isset($_POST['bwa_store_wa_phone'])) {
                update_option('bwa_store_wa_phone', sanitize_text_field($_POST['bwa_store_wa_phone']));
            }
            if (isset($_POST['bwa_wa_button_template'])) {
                update_option('bwa_wa_button_template', wp_kses_post(wp_unslash($_POST['bwa_wa_button_template'])));
            }
            add_settings_error('bwa_messages', 'bwa_btn_saved', __('WhatsApp 1-Click Buttons and Checkout settings saved.', 'busistree-whatsapp'), 'updated');
        }

        // Save Order Metabox Action
        if (isset($_POST['bwa_order_action_nonce']) && wp_verify_nonce($_POST['bwa_order_action_nonce'], 'bwa_order_action')) {
            $order_id = isset($_POST['bwa_order_id']) ? absint($_POST['bwa_order_id']) : 0;
            if ($order_id && function_exists('wc_get_order')) {
                $order = wc_get_order($order_id);
                if ($order) {
                    if (isset($_POST['bwa_confirmation_status'])) {
                        $c_status = sanitize_text_field($_POST['bwa_confirmation_status']);
                        $order->update_meta_data('_bwa_confirmation_status', $c_status);
                        $order->add_order_note(sprintf(__('[Busistree WhatsApp] Order confirmation status set to "%s"', 'busistree-whatsapp'), ucfirst($c_status)));
                        $order->save();
                    }
                    if (!empty($_POST['bwa_custom_message'])) {
                        $custom_msg = sanitize_textarea_field($_POST['bwa_custom_message']);
                        $raw_phone = $order->get_meta('_billing_whatsapp_phone') ?: $order->get_billing_phone();
                        $phone = BWA_Templates::normalize_phone($raw_phone);
                        $cust_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) ?: 'Customer';
                        if ($phone) {
                            BWA_DB::add_to_queue($order_id, 'manual_admin', $cust_name, $phone, $custom_msg);
                        }
                    }
                }
            }
        }
    }

    public static function render_admin_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'connection';
        $apiKey = get_option('bwa_api_key');
        $siteUrl = home_url();
        $stats = BWA_DB::get_stats();
        $enabled = get_option('bwa_enabled_events', array());
        $templates = get_option('bwa_templates', BWA_Templates::get_default_templates());

        settings_errors('bwa_messages');
        ?>
        <div class="wrap bwa-admin-wrap">
            <div class="bwa-header">
                <div class="bwa-brand">
                    <span class="bwa-icon">💬</span>
                    <div>
                        <h2>Busistree WhatsApp Order Bot</h2>
                        <p class="bwa-subtitle">Automated WhatsApp order notifications for WooCommerce via Chrome Extension</p>
                    </div>
                </div>
                <div class="bwa-stats-pill">
                    <span>Pending: <strong><?php echo $stats['pending']; ?></strong></span>
                    <span class="sep">|</span>
                    <span>Sent: <strong class="text-success"><?php echo $stats['sent']; ?></strong></span>
                    <span class="sep">|</span>
                    <span>Failed: <strong class="text-danger"><?php echo $stats['failed']; ?></strong></span>
                </div>
            </div>

            <nav class="nav-tab-wrapper bwa-nav-tabs">
                <a href="?page=busistree-whatsapp&tab=connection" class="nav-tab <?php echo $active_tab === 'connection' ? 'nav-tab-active' : ''; ?>">🔗 Extension Connection</a>
                <a href="?page=busistree-whatsapp&tab=downloads" class="nav-tab <?php echo $active_tab === 'downloads' ? 'nav-tab-active' : ''; ?>">📥 Download Apps & Agents</a>
                <a href="?page=busistree-whatsapp&tab=buttons" class="nav-tab <?php echo $active_tab === 'buttons' ? 'nav-tab-active' : ''; ?>">💬 1-Click Buttons & Checkout</a>
                <a href="?page=busistree-whatsapp&tab=triggers" class="nav-tab <?php echo $active_tab === 'triggers' ? 'nav-tab-active' : ''; ?>">⚡ Event Triggers</a>
                <a href="?page=busistree-whatsapp&tab=templates" class="nav-tab <?php echo $active_tab === 'templates' ? 'nav-tab-active' : ''; ?>">📝 Message Templates</a>
                <a href="?page=busistree-whatsapp&tab=queue" class="nav-tab <?php echo $active_tab === 'queue' ? 'nav-tab-active' : ''; ?>">📋 Queue & History (<?php echo $stats['pending']; ?>)</a>
                <a href="?page=busistree-whatsapp&tab=test" class="nav-tab <?php echo $active_tab === 'test' ? 'nav-tab-active' : ''; ?>">🧪 Quick Test</a>
            </nav>

            <div class="bwa-content">
                <?php if ($active_tab === 'connection') : ?>
                    <!-- TAB 1: CONNECTION -->
                    <div class="bwa-card">
                        <h3>🔑 Connect Chrome Extension to this WordPress Store</h3>
                        <p>Copy these two values into the <strong>Busistree Chrome Extension</strong> popup settings to automatically send WhatsApp messages through your logged-in WhatsApp Web tab without Meta API fees.</p>

                        <div class="bwa-field-group">
                            <label><strong>1. WordPress Store URL:</strong></label>
                            <div class="bwa-copy-input">
                                <input type="text" readonly value="<?php echo esc_url($siteUrl); ?>" id="bwaSiteUrl" class="regular-text" />
                                <button type="button" class="button" onclick="bwaCopy('bwaSiteUrl')">📋 Copy URL</button>
                            </div>
                        </div>

                        <div class="bwa-field-group">
                            <label><strong>2. Secret Plugin API Key:</strong></label>
                            <div class="bwa-copy-input">
                                <input type="text" readonly value="<?php echo esc_attr($apiKey); ?>" id="bwaApiKey" class="regular-text" />
                                <button type="button" class="button" onclick="bwaCopy('bwaApiKey')">📋 Copy Key</button>
                            </div>
                        </div>

                        <form method="post" style="margin-top: 20px;">
                            <?php wp_nonce_field('bwa_save_settings', 'bwa_save_settings_nonce'); ?>
                            <input type="hidden" name="bwa_regenerate_key" value="1" />
                            <button type="submit" class="button button-secondary" onclick="return confirm('Regenerating will disconnect active extensions until you update the key in the extension. Proceed?');">
                                🔄 Regenerate API Key
                            </button>
                        </form>

                        <hr style="margin: 25px 0;" />

                        <h3>🚀 How the Automation Works:</h3>
                        <ol class="bwa-step-list">
                            <li>Keep Google Chrome open on your computer with <strong>https://web.whatsapp.com</strong> logged into your business WhatsApp account.</li>
                            <li>The <strong>Busistree Chrome Extension</strong> polls your WordPress site every 15 seconds.</li>
                            <li>When a client places an order or you change order status in WooCommerce, WordPress queues the message.</li>
                            <li>The Chrome Extension automatically opens WhatsApp Web, sends the notification, and reports "Sent" back to WooCommerce.</li>
                            <li>100% free, official zero-cost automation with Pakistani phone formatting!</li>
                        </ol>
                    </div>

                <?php elseif ($active_tab === 'buttons') : ?>
                    <!-- TAB: 1-CLICK BUTTONS & CHECKOUT -->
                    <div class="bwa-card">
                        <h3>💬 1-Click WhatsApp Buttons & Checkout Integration</h3>
                        <p>Configure direct customer-to-WhatsApp order buttons on your product & cart pages, plus checkout phone fields.</p>

                        <form method="post">
                            <?php wp_nonce_field('bwa_save_buttons', 'bwa_save_buttons_nonce'); ?>

                            <div class="bwa-field-group">
                                <label for="bwa_store_wa_phone"><strong>Store WhatsApp Phone Number:</strong></label>
                                <input type="text" name="bwa_store_wa_phone" id="bwa_store_wa_phone" class="regular-text" value="<?php echo esc_attr(get_option('bwa_store_wa_phone', '')); ?>" placeholder="e.g. 03001234567 or +923001234567" />
                                <p class="description">Receives customer 1-Click WhatsApp orders, cart inquiries, and low-stock admin alerts.</p>
                            </div>

                            <hr style="margin: 20px 0;" />

                            <div class="bwa-checkbox-list">
                                <label class="bwa-checkbox-item">
                                    <input type="checkbox" name="bwa_enable_product_wa_button" value="yes" <?php checked(get_option('bwa_enable_product_wa_button', 'yes'), 'yes'); ?>>
                                    <div>
                                        <strong>🛍️ "Order via WhatsApp" on Single Product Pages</strong>
                                        <p class="description">Displays a prominent WhatsApp button next to the "Add to Cart" button, allowing visitors to order directly in WhatsApp.</p>
                                    </div>
                                </label>

                                <label class="bwa-checkbox-item">
                                    <input type="checkbox" name="bwa_enable_cart_wa_button" value="yes" <?php checked(get_option('bwa_enable_cart_wa_button', 'yes'), 'yes'); ?>>
                                    <div>
                                        <strong>🛒 "Instant Checkout via WhatsApp" on Cart Page</strong>
                                        <p class="description">Adds a WhatsApp button below the cart total with all cart items pre-populated for rapid ordering.</p>
                                    </div>
                                </label>

                                <label class="bwa-checkbox-item">
                                    <input type="checkbox" name="bwa_enable_checkout_fields" value="yes" <?php checked(get_option('bwa_enable_checkout_fields', 'yes'), 'yes'); ?>>
                                    <div>
                                        <strong>📱 Dedicated WhatsApp Field at Checkout</strong>
                                        <p class="description">Adds a specific WhatsApp phone input field and opt-in checkbox to the WooCommerce checkout form.</p>
                                    </div>
                                </label>

                                <label class="bwa-checkbox-item">
                                    <input type="checkbox" name="bwa_require_optin" value="yes" <?php checked(get_option('bwa_require_optin', 'no'), 'yes'); ?>>
                                    <div>
                                        <strong>🔒 Require Customer Opt-In Consent</strong>
                                        <p class="description">Only queue WhatsApp notifications if customer leaves the WhatsApp consent box checked at checkout.</p>
                                    </div>
                                </label>

                                <label class="bwa-checkbox-item">
                                    <input type="checkbox" name="bwa_enable_low_stock_alert" value="yes" <?php checked(get_option('bwa_enable_low_stock_alert', 'yes'), 'yes'); ?>>
                                    <div>
                                        <strong>⚠️ Low Stock & Out-of-Stock Admin Alerts</strong>
                                        <p class="description">Automatically send WhatsApp alert to Store WhatsApp Number when a product runs low or out of stock.</p>
                                    </div>
                                </label>
                            </div>

                            <hr style="margin: 20px 0;" />

                            <div class="bwa-field-group">
                                <label for="bwa_wa_button_template"><strong>Custom Product Button Message Template:</strong></label>
                                <textarea name="bwa_wa_button_template" id="bwa_wa_button_template" rows="4" class="large-text code" placeholder="Hi! I want to order {product_name} ({price}). Please confirm! {product_url}"><?php echo esc_textarea(get_option('bwa_wa_button_template', "Hello! I am interested in purchasing:\n*{product_name}*{sku}\nPrice: {price}\nProduct Link: {product_url}\nPlease let me know availability and delivery details!")); ?></textarea>
                                <p class="description">Available tags: <code>{product_name}</code>, <code>{sku}</code>, <code>{price}</code>, <code>{product_url}</code>, <code>{store_name}</code></p>
                            </div>

                            <p style="margin-top: 25px;">
                                <button type="submit" class="button button-primary button-hero">💾 Save Button & Checkout Settings</button>
                            </p>
                        </form>
                    </div>

                <?php elseif ($active_tab === 'triggers') : ?>
                    <!-- TAB 2: TRIGGERS -->
                    <div class="bwa-card">
                        <h3>⚡ Order Status Triggers</h3>
                        <p>Select which WooCommerce order events should automatically queue a WhatsApp message to the customer:</p>

                        <form method="post">
                            <?php wp_nonce_field('bwa_save_settings', 'bwa_save_settings_nonce'); ?>

                            <div class="bwa-checkbox-list">
                                <label class="bwa-checkbox-item">
                                    <input type="checkbox" name="bwa_event_pending" value="yes" <?php checked($enabled['pending'] ?? 'yes', 'yes'); ?>>
                                    <div>
                                        <strong>📥 Pending Payment / Order Placed (pending)</strong>
                                        <p class="description">Sends instant order receipt and payment instructions.</p>
                                    </div>
                                </label>

                                <label class="bwa-checkbox-item">
                                    <input type="checkbox" name="bwa_event_processing" value="yes" <?php checked($enabled['processing'] ?? 'yes', 'yes'); ?>>
                                    <div>
                                        <strong>⚙️ Processing / In Progress (processing)</strong>
                                        <p class="description">Notifies client that order payment is verified and preparation/development is underway.</p>
                                    </div>
                                </label>

                                <label class="bwa-checkbox-item">
                                    <input type="checkbox" name="bwa_event_completed" value="yes" <?php checked($enabled['completed'] ?? 'yes', 'yes'); ?>>
                                    <div>
                                        <strong>🚀 Completed / Delivered (completed)</strong>
                                        <p class="description">Celebrates fulfillment, congratulates the client, and invites reviews.</p>
                                    </div>
                                </label>

                                <label class="bwa-checkbox-item">
                                    <input type="checkbox" name="bwa_event_cancelled" value="yes" <?php checked($enabled['cancelled'] ?? 'yes', 'yes'); ?>>
                                    <div>
                                        <strong>❌ Cancelled (cancelled)</strong>
                                        <p class="description">Notifies the customer of cancellation and provides support contact.</p>
                                    </div>
                                </label>

                                <label class="bwa-checkbox-item">
                                    <input type="checkbox" name="bwa_event_on-hold" value="yes" <?php checked($enabled['on-hold'] ?? 'no', 'yes'); ?>>
                                    <div>
                                        <strong>⏸️ On Hold (on-hold)</strong>
                                        <p class="description">Alerts client that their order is awaiting payment confirmation.</p>
                                    </div>
                                </label>

                                <label class="bwa-checkbox-item">
                                    <input type="checkbox" name="bwa_event_refunded" value="yes" <?php checked($enabled['refunded'] ?? 'no', 'yes'); ?>>
                                    <div>
                                        <strong>💸 Refunded (refunded)</strong>
                                        <p class="description">Sends refund confirmation notice.</p>
                                    </div>
                                </label>
                            </div>

                            <hr style="margin: 25px 0;" />
                            <h3>📦 Store Policy & Return Rules (AI Knowledge Layer)</h3>
                            <p class="description">Configure policy settings accessed by the AI Sales & Support Assistant:</p>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><label for="bwa_return_days"><strong>Return Eligibility Window:</strong></label></th>
                                    <td>
                                        <input type="number" min="1" max="90" name="bwa_return_days" id="bwa_return_days" value="<?php echo esc_attr(get_option('bwa_return_days', 7)); ?>" class="small-text" /> days
                                        <p class="description">The maximum number of days after order delivery/completion within which a customer can request a return/refund. Default: 7 days.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="bwa_page_return_policy"><strong>Return Policy Page:</strong></label></th>
                                    <td>
                                        <?php
                                        wp_dropdown_pages(array(
                                            'name'              => 'bwa_page_return_policy',
                                            'id'                => 'bwa_page_return_policy',
                                            'selected'          => get_option('bwa_page_return_policy', 0),
                                            'show_option_none'  => __('— Default / Text Policy —', 'busistree-whatsapp'),
                                            'option_none_value' => '0',
                                        ));
                                        ?>
                                        <p class="description">Select WordPress page containing your return & exchange policy.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="bwa_page_shipping_policy"><strong>Shipping Policy Page:</strong></label></th>
                                    <td>
                                        <?php
                                        wp_dropdown_pages(array(
                                            'name'              => 'bwa_page_shipping_policy',
                                            'id'                => 'bwa_page_shipping_policy',
                                            'selected'          => get_option('bwa_page_shipping_policy', 0),
                                            'show_option_none'  => __('— Default / Text Policy —', 'busistree-whatsapp'),
                                            'option_none_value' => '0',
                                        ));
                                        ?>
                                        <p class="description">Select WordPress page containing your shipping & delivery policy.</p>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin-top: 25px;">
                                <button type="submit" class="button button-primary button-hero">💾 Save Trigger & Policy Settings</button>
                            </p>
                        </form>
                    </div>

                <?php elseif ($active_tab === 'templates') : ?>
                    <!-- TAB 3: TEMPLATES -->
                    <div class="bwa-card">
                        <h3>📝 WhatsApp Message Templates</h3>
                        <p>Customize the WhatsApp message sent for each order status. Use WhatsApp markdown (e.g. <code>*bold*</code>, <code>_italic_</code>) and dynamic tags.</p>

                        <div class="bwa-tags-container">
                            <strong>Dynamic Tags (Click to copy):</strong>
                            <div class="bwa-tag-chips">
                                <span class="bwa-chip" onclick="bwaInsertTag('{customer_name}')">{customer_name}</span>
                                <span class="bwa-chip" onclick="bwaInsertTag('{order_number}')">{order_number}</span>
                                <span class="bwa-chip" onclick="bwaInsertTag('{items_list}')">{items_list}</span>
                                <span class="bwa-chip" onclick="bwaInsertTag('{total}')">{total}</span>
                                <span class="bwa-chip" onclick="bwaInsertTag('{currency}')">{currency}</span>
                                <span class="bwa-chip" onclick="bwaInsertTag('{payment_method}')">{payment_method}</span>
                                <span class="bwa-chip" onclick="bwaInsertTag('{order_date}')">{order_date}</span>
                                <span class="bwa-chip" onclick="bwaInsertTag('{billing_phone}')">{billing_phone}</span>
                                <span class="bwa-chip" onclick="bwaInsertTag('{billing_address}')">{billing_address}</span>
                                <span class="bwa-chip" onclick="bwaInsertTag('{site_name}')">{site_name}</span>
                                <span class="bwa-chip" onclick="bwaInsertTag('{site_url}')">{site_url}</span>
                            </div>
                        </div>

                        <form method="post">
                            <?php wp_nonce_field('bwa_save_templates', 'bwa_save_templates_nonce'); ?>

                            <?php
                            $statuses = array(
                                'pending'    => '📥 Order Received / Pending',
                                'processing' => '⚙️ Order Processing / In Progress',
                                'completed'  => '🚀 Order Completed / Delivered',
                                'cancelled'  => '❌ Order Cancelled',
                                'on-hold'    => '⏸️ Order On Hold',
                                'refunded'   => '💸 Order Refunded',
                            );
                            foreach ($statuses as $slug => $label) :
                                $val = $templates[$slug] ?? '';
                            ?>
                                <div class="bwa-template-box">
                                    <h4><?php echo esc_html($label); ?></h4>
                                    <textarea name="bwa_template_<?php echo esc_attr($slug); ?>" id="tpl_<?php echo esc_attr($slug); ?>" rows="7" class="large-text code"><?php echo esc_textarea($val); ?></textarea>
                                </div>
                            <?php endforeach; ?>

                            <div style="margin-top: 25px; display: flex; gap: 12px;">
                                <button type="submit" class="button button-primary button-hero">💾 Save All Templates</button>
                            </div>
                        </form>

                        <form method="post" style="margin-top: 15px;">
                            <?php wp_nonce_field('bwa_reset_templates', 'bwa_reset_templates_nonce'); ?>
                            <button type="submit" class="button" onclick="return confirm('Reset all templates to default Busistree messages?');">
                                ↺ Reset to Default Templates
                            </button>
                        </form>
                    </div>

                <?php elseif ($active_tab === 'queue') : ?>
                    <!-- TAB 4: QUEUE & LOGS -->
                    <div class="bwa-card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <h3 style="margin: 0;">📋 Message Queue & Audit Log</h3>
                            <form method="post" style="margin: 0;">
                                <?php wp_nonce_field('bwa_clear_logs', 'bwa_clear_logs_nonce'); ?>
                                <button type="submit" class="button button-link-delete" onclick="return confirm('Clear all queue records and history?');">
                                    🗑️ Clear All Logs
                                </button>
                            </form>
                        </div>

                        <?php
                        global $wpdb;
                        $rows = $wpdb->get_results("SELECT * FROM " . BWA_DB::table_name() . " ORDER BY id DESC LIMIT 50");
                        if (empty($rows)) :
                        ?>
                            <div class="bwa-empty-notice">No messages in queue yet. As orders are placed or updated, they will appear here.</div>
                        <?php else : ?>
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">ID</th>
                                        <th style="width: 90px;">Order</th>
                                        <th style="width: 100px;">Event</th>
                                        <th style="width: 140px;">Customer / Phone</th>
                                        <th>Message Preview</th>
                                        <th style="width: 90px;">Status</th>
                                        <th style="width: 140px;">Queued At</th>
                                        <th style="width: 100px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $r) : 
                                        $waUrl = 'https://web.whatsapp.com/send?phone=' . urlencode($r->phone) . '&text=' . urlencode($r->message);
                                    ?>
                                        <tr>
                                            <td>#<?php echo esc_html($r->id); ?></td>
                                            <td>
                                                <?php if ($r->order_id) : ?>
                                                    <a href="<?php echo esc_url(get_edit_post_link($r->order_id)); ?>" target="_blank">#<?php echo esc_html($r->order_id); ?></a>
                                                <?php else : ?>
                                                    <em>Test</em>
                                                <?php endif; ?>
                                            </td>
                                            <td><code><?php echo esc_html($r->event); ?></code></td>
                                            <td>
                                                <strong><?php echo esc_html($r->customer_name); ?></strong><br>
                                                <code>+<?php echo esc_html($r->phone); ?></code>
                                            </td>
                                            <td>
                                                <div class="bwa-msg-cell" title="<?php echo esc_attr($r->message); ?>">
                                                    <?php echo esc_html(wp_trim_words($r->message, 15, '...')); ?>
                                                </div>
                                                <?php if ($r->error_message) : ?>
                                                    <div class="text-danger" style="font-size: 11px; margin-top: 3px;">
                                                        ⚠️ <?php echo esc_html($r->error_message); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($r->status === 'sent') : ?>
                                                    <span class="bwa-badge bwa-badge-success">Sent</span>
                                                <?php elseif ($r->status === 'failed') : ?>
                                                    <span class="bwa-badge bwa-badge-danger">Failed</span>
                                                <?php else : ?>
                                                    <span class="bwa-badge bwa-badge-pending">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo esc_html($r->created_at); ?></td>
                                            <td>
                                                <a href="<?php echo esc_url($waUrl); ?>" target="_blank" class="button button-small" title="Send directly via WhatsApp Web">
                                                    💬 Send
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                <?php elseif ($active_tab === 'test') : ?>
                    <!-- TAB 5: TEST RUNNER -->
                    <div class="bwa-card">
                        <h3>🧪 Test WhatsApp Message Runner</h3>
                        <p>Inject a live test notification into the queue to verify your Chrome Extension picks it up and sends via WhatsApp Web.</p>

                        <form method="post">
                            <?php wp_nonce_field('bwa_send_test', 'bwa_send_test_nonce'); ?>

                            <div class="bwa-field-group">
                                <label><strong>Recipient Phone Number:</strong></label>
                                <input type="text" name="bwa_test_phone" placeholder="03001234567 or 923001234567" class="regular-text" required />
                                <p class="description">Pakistani formats (0300..., 300...) are automatically converted to WhatsApp format (92300...).</p>
                            </div>

                            <div class="bwa-field-group" style="margin-top: 15px;">
                                <label><strong>Message Content:</strong></label>
                                <textarea name="bwa_test_message" rows="5" class="large-text code">Assalam-o-Alaikum! 🚀
This is a test notification from Busistree WhatsApp Bot for WooCommerce!
System is connected and operating normally.
🌐 <?php echo esc_html(get_bloginfo('name')); ?></textarea>
                            </div>

                            <p style="margin-top: 20px;">
                                <button type="submit" class="button button-primary button-hero">🚀 Queue Test Message</button>
                            </p>
                        </form>
                    </div>

                <?php elseif ($active_tab === 'downloads') : ?>
                    <!-- TAB 6: DOWNLOAD APPS & EXTENSIONS -->
                    <?php
                    $download_nonce = wp_create_nonce('bwa_download_action');
                    $dl_dir = trailingslashit(wp_normalize_path(BWA_PLUGIN_DIR)) . 'downloads/';
                    $dl_url = trailingslashit(BWA_PLUGIN_URL) . 'downloads/';

                    // Check file existence on server
                    $apk_file       = 'Busistree-WhatsApp-Agent.apk';
                    $exe_file       = 'Busistree-WhatsApp-Agent-Setup-1.0.0.exe';
                    $ext_file       = 'busistree-whatsapp-chrome-extension.zip';
                    $desktop_file   = 'busistree-whatsapp-desktop-software.zip';
                    $mobile_file    = 'busistree-whatsapp-android-app.zip';

                    $has_apk        = file_exists($dl_dir . $apk_file);
                    $has_exe        = file_exists($dl_dir . $exe_file);
                    $has_ext        = file_exists($dl_dir . $ext_file);
                    $has_desktop    = file_exists($dl_dir . $desktop_file);
                    $has_mobile     = file_exists($dl_dir . $mobile_file);

                    // URLs
                    $desktop_installer_url = $has_exe ? ($dl_url . $exe_file) : admin_url('admin-post.php?action=bwa_download_app&package=desktop_installer&bwa_nonce=' . $download_nonce);
                    $desktop_download_url  = $has_desktop ? ($dl_url . $desktop_file) : admin_url('admin-post.php?action=bwa_download_app&package=desktop&bwa_nonce=' . $download_nonce);
                    $apk_download_url      = $has_apk ? ($dl_url . $apk_file) : admin_url('admin-post.php?action=bwa_download_app&package=apk&bwa_nonce=' . $download_nonce);
                    $mobile_download_url   = $has_mobile ? ($dl_url . $mobile_file) : admin_url('admin-post.php?action=bwa_download_app&package=mobile&bwa_nonce=' . $download_nonce);
                    $extension_download_url= $has_ext ? ($dl_url . $ext_file) : admin_url('admin-post.php?action=bwa_download_app&package=extension&bwa_nonce=' . $download_nonce);
                    ?>
                    <div class="bwa-card">
                        <h3>📥 Download WhatsApp Automation Apps & Extension</h3>
                        <p>Choose your preferred platform to automate WhatsApp notifications for your WooCommerce orders. All apps link directly to this WordPress store using your API Key.</p>

                        <div class="bwa-download-grid">
                            <!-- CARD 1: WINDOWS DESKTOP APP -->
                            <div class="bwa-download-card">
                                <div>
                                    <span class="bwa-download-badge bwa-badge-desktop">🖥️ Windows Desktop Software</span>
                                    <h4>Busistree WhatsApp Desktop App</h4>
                                    <p class="description">Native Windows application with Dashboard, on-screen QR Code linking, interactive JSON config editor, and system tray background execution. Does not require browser tabs!</p>
                                    <ul class="bwa-feature-bullets">
                                        <li>✅ Native Windows Setup Installer & App</li>
                                        <li>✅ Interactive JSON Config Editor & Hot-Reload</li>
                                        <li>✅ System Tray Minimized Background Mode</li>
                                        <li>✅ Direct Multi-Device WebSocket Protocol</li>
                                    </ul>
                                </div>
                                <div style="margin-top: 20px; display: flex; flex-direction: column; gap: 8px;">
                                    <?php if ($has_exe) : ?>
                                        <a href="<?php echo esc_url($desktop_installer_url); ?>" download="<?php echo esc_attr($exe_file); ?>" class="button button-primary button-hero" style="width: 100%; text-align: center; display: block;">
                                            ⬇️ Download Windows Setup Installer (.exe) (80 MB)
                                        </a>
                                    <?php else : ?>
                                        <div style="background: #eff6ff; border: 1px solid #bfdbfe; padding: 12px; border-radius: 6px; font-size: 13px; color: #1e40af;">
                                            <strong>🖥️ Windows Setup Installer Ready:</strong><br/>
                                            The pre-compiled Windows installer is ready in your local directory at <code>release/installable.exe</code>. Run it directly to install and launch the bot.<br/>
                                            <em style="color:#64748b; font-size:12px;">(Optional: To host the 80 MB installer on your site, upload <code><?php echo esc_html($exe_file); ?></code> to <code>wp-content/plugins/busistree-whatsapp-notifier/downloads/</code>).</em>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($has_desktop) : ?>
                                        <a href="<?php echo esc_url($desktop_download_url); ?>" download="<?php echo esc_attr($desktop_file); ?>" class="button button-secondary" style="width: 100%; text-align: center; display: block;">
                                            📦 Download Portable Desktop Package (.zip)
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- CARD 2: ANDROID MOBILE APP -->
                            <div class="bwa-download-card">
                                <div>
                                    <span class="bwa-download-badge bwa-badge-mobile">📱 Android Mobile App (APK)</span>
                                    <h4>Busistree WhatsApp Mobile Bot</h4>
                                    <p class="description">Android application with 24/7 Foreground Background Service (WakeLock). Keeps running in background and sending order updates even when phone is locked or asleep!</p>
                                    <ul class="bwa-feature-bullets">
                                        <li>✅ 24/7 Background Running Service</li>
                                        <li>✅ Mobile Dashboard + Editable JSON Config</li>
                                        <li>✅ Auto-Start on Phone Reboot</li>
                                        <li>✅ Ready-to-Install Signed APK Package</li>
                                    </ul>
                                </div>
                                <div style="margin-top: 20px; display: flex; flex-direction: column; gap: 8px;">
                                    <a href="<?php echo esc_url($apk_download_url); ?>" download="<?php echo esc_attr($apk_file); ?>" class="button button-primary button-hero" style="width: 100%; text-align: center; display: block; background: #16a34a; border-color: #15803d;">
                                        ⬇️ Download Installable APK (.apk) (37 KB)
                                    </a>
                                    <a href="<?php echo esc_url($mobile_download_url); ?>" download="<?php echo esc_attr($mobile_file); ?>" class="button button-secondary" style="width: 100%; text-align: center; display: block;">
                                        📦 Download Android Source Project (.zip)
                                    </a>
                                </div>
                            </div>

                            <!-- CARD 3: CHROME EXTENSION -->
                            <div class="bwa-download-card">
                                <div>
                                    <span class="bwa-download-badge bwa-badge-extension">🧩 Google Chrome Extension</span>
                                    <h4>Busistree Chrome Extension</h4>
                                    <p class="description">Browser extension for Google Chrome that sends notifications via WhatsApp Web tabs without Meta Cloud API fees.</p>
                                    <ul class="bwa-feature-bullets">
                                        <li>✅ Zero Installation / Runs in Chrome</li>
                                        <li>✅ Quick Test Messenger</li>
                                        <li>✅ Auto-cleans WhatsApp Web crash loops</li>
                                        <li>✅ Instant Order Status Alerts</li>
                                    </ul>
                                </div>
                                <div style="margin-top: 20px;">
                                    <a href="<?php echo esc_url($extension_download_url); ?>" download="<?php echo esc_attr($ext_file); ?>" class="button button-secondary button-hero" style="width: 100%; text-align: center; display: block;">
                                        ⬇️ Download Chrome Extension (.zip) (186 KB)
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- 1-Click Auto Config Box -->
                        <div class="bwa-card" style="margin-top: 25px; background: #0f172a; color: #fff; border-color: #334155;">
                            <h4 style="color: #38bdf8; margin-top: 0;">⚡ 1-Click Auto Configuration (config.json)</h4>
                            <p style="color: #94a3b8; font-size: 13px;">Copy this pre-filled configuration and paste it directly into your <strong>Windows Desktop App (JSON Editor tab)</strong> or <strong>Mobile App (Edit JSON tab)</strong>:</p>

                            <?php
                            $auto_config = array(
                                'backendMode'               => 'wordpress',
                                'wpSiteUrl'                 => $siteUrl,
                                'wpApiKey'                  => $apiKey,
                                'pollIntervalSeconds'       => 15,
                                'delayBetweenSendsSeconds'  => 8,
                                'headless'                  => true,
                                'autoStart'                 => true,
                                'minimizeToTray'            => true,
                            );
                            $json_str = json_encode($auto_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            ?>
                            <textarea id="bwaAutoConfigJson" readonly rows="8" style="width: 100%; font-family: monospace; font-size: 12px; background: #020617; color: #38bdf8; border: 1px solid #1e293b; padding: 10px; border-radius: 6px;"><?php echo esc_textarea($json_str); ?></textarea>
                            <div style="margin-top: 10px; display: flex; gap: 10px;">
                                <button type="button" class="button button-primary" onclick="bwaCopy('bwaAutoConfigJson')">📋 Copy config.json to Clipboard</button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Download Handler for Desktop, Mobile, and Extension zip packages
     */
    public static function handle_download_app() {
        if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
            wp_die(__('Unauthorized access. You need administrative or store management privileges.', 'busistree-whatsapp'));
        }

        check_admin_referer('bwa_download_action', 'bwa_nonce');

        $pkg = isset($_GET['package']) ? sanitize_text_field($_GET['package']) : '';
        $allowed = array(
            'desktop_installer' => 'Busistree-WhatsApp-Agent-Setup-1.0.0.exe',
            'desktop'           => 'busistree-whatsapp-desktop-software.zip',
            'mobile'            => 'busistree-whatsapp-android-app.zip',
            'apk'               => 'Busistree-WhatsApp-Agent.apk',
            'extension'         => 'busistree-whatsapp-chrome-extension.zip',
        );

        if (!isset($allowed[$pkg])) {
            wp_die(__('Invalid package requested.', 'busistree-whatsapp'));
        }

        $filename = $allowed[$pkg];
        $dl_dir = trailingslashit(wp_normalize_path(BWA_PLUGIN_DIR)) . 'downloads/';
        $filepath = $dl_dir . $filename;

        // Fallback: Case-insensitive scan in downloads folder
        if (!file_exists($filepath) && is_dir($dl_dir)) {
            $scanned = scandir($dl_dir);
            if ($scanned) {
                foreach ($scanned as $f) {
                    if (strcasecmp($f, $filename) === 0) {
                        $filepath = $dl_dir . $f;
                        $filename = $f;
                        break;
                    }
                }
            }
        }

        if (!file_exists($filepath)) {
            $available_files = is_dir($dl_dir) ? array_diff(scandir($dl_dir), array('.', '..')) : array();
            $available_list = !empty($available_files) ? '<ul><li>' . implode('</li><li>', array_map('esc_html', $available_files)) . '</li></ul>' : '<p><em>No packages currently found in downloads directory.</em></p>';

            $msg = '<div style="font-family: sans-serif; max-width: 650px; margin: 50px auto; padding: 25px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff;">';
            $msg .= '<h2 style="color: #e11d48; margin-top:0;">📦 Package File Not Found</h2>';
            $msg .= '<p>The requested package <code>' . esc_html($filename) . '</code> was not found on your WordPress server.</p>';
            $msg .= '<p><strong>Checked location:</strong><br/><code>' . esc_html($filepath) . '</code></p>';
            $msg .= '<h3>Files currently on server:</h3>' . $available_list;
            $msg .= '<p style="margin-top: 25px;"><a href="' . esc_url(admin_url('admin.php?page=busistree-whatsapp&tab=downloads')) . '" style="background:#2563eb; color:#fff; text-decoration:none; padding:10px 18px; border-radius:6px; display:inline-block;">⬅️ Return to Downloads Page</a></p>';
            $msg .= '</div>';
            wp_die($msg, __('File Not Found', 'busistree-whatsapp'), array('response' => 404));
        }

        // Clean any output buffers before streaming
        while (ob_get_level()) {
            ob_end_clean();
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        header('Content-Description: File Transfer');
        if ($pkg === 'apk') {
            header('Content-Type: application/vnd.android.package-archive');
        } elseif ($pkg === 'desktop_installer') {
            header('Content-Type: application/octet-stream');
        } else {
            header('Content-Type: application/zip');
        }
        header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));

        // Stream file in 64KB chunks to prevent memory limit exhaustion
        $fp = fopen($filepath, 'rb');
        if ($fp) {
            while (!feof($fp) && connection_status() == 0) {
                print(fread($fp, 1024 * 64));
                flush();
            }
            fclose($fp);
        } else {
            readfile($filepath);
        }
        exit;
    }

    /**
     * Add WhatsApp column to WooCommerce orders list
     */
    public static function add_order_column($columns) {
        $new_columns = array();
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            if ($key === 'order_status' || $key === 'order_total') {
                $new_columns['bwa_whatsapp'] = __('WhatsApp', 'busistree-whatsapp');
            }
        }
        if (!isset($new_columns['bwa_whatsapp'])) {
            $new_columns['bwa_whatsapp'] = __('WhatsApp', 'busistree-whatsapp');
        }
        return $new_columns;
    }

    public static function render_order_column($column, $post_id) {
        if ($column === 'bwa_whatsapp') {
            $order = wc_get_order($post_id);
            self::render_whatsapp_order_button($order);
        }
    }

    public static function render_hpos_order_column($column, $order) {
        if ($column === 'bwa_whatsapp') {
            self::render_whatsapp_order_button($order);
        }
    }

    private static function render_whatsapp_order_button($order) {
        if (!$order) return;
        $raw_phone = $order->get_billing_phone();
        if (empty($raw_phone)) {
            echo '<span style="color:#999; font-size:12px;">No phone</span>';
            return;
        }

        $phone = BWA_Templates::normalize_phone($raw_phone);
        $message = BWA_Templates::render_message($order->get_status(), $order);
        $waUrl = 'https://web.whatsapp.com/send?phone=' . urlencode($phone) . '&text=' . urlencode($message);

        echo '<a href="' . esc_url($waUrl) . '" target="_blank" class="button button-small bwa-btn-wa" title="Open chat with customer in WhatsApp Web">💬 Chat</a>';
    }

    /**
     * Order Details Meta Box
     */
    public static function register_order_meta_box() {
        if (!class_exists('WooCommerce') && !function_exists('wc_get_order')) {
            return;
        }

        $screen = 'shop_order';
        if (class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') 
            && function_exists('wc_get_container') 
            && function_exists('wc_get_page_screen_id')) {
            try {
                if (wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()) {
                    $screen = wc_get_page_screen_id('shop-order');
                }
            } catch (\Throwable $e) {
                $screen = 'shop_order';
            }
        }

        add_meta_box(
            'bwa_order_metabox',
            __('Busistree WhatsApp Bot', 'busistree-whatsapp'),
            array(__CLASS__, 'render_order_metabox'),
            $screen,
            'side',
            'high'
        );
    }

    public static function render_order_metabox($post_or_order) {
        $order = ($post_or_order instanceof WC_Order) ? $post_or_order : wc_get_order($post_or_order->ID);
        if (!$order) return;

        $raw_phone = $order->get_meta('_billing_whatsapp_phone') ?: $order->get_billing_phone();
        $phone = BWA_Templates::normalize_phone($raw_phone);
        $status = $order->get_status();
        $message = BWA_Templates::render_message($status, $order);
        $waUrl = 'https://web.whatsapp.com/send?phone=' . urlencode($phone) . '&text=' . urlencode($message);

        $confirmation_status = $order->get_meta('_bwa_confirmation_status') ?: 'pending';
        $optin = $order->get_meta('_bwa_whatsapp_optin') ?: 'yes';

        $badge_color = ($confirmation_status === 'confirmed') ? '#16a34a' : (($confirmation_status === 'cancelled') ? '#dc2626' : '#f59e0b');
        ?>
        <div class="bwa-metabox">
            <div style="margin-bottom: 12px; font-size: 13px;">
                <p style="margin: 4px 0;"><strong>WhatsApp Phone:</strong> <code>+<?php echo esc_html($phone ? $phone : 'None'); ?></code></p>
                <p style="margin: 4px 0;"><strong>Customer Opt-in:</strong> <span style="font-weight: 600; color: <?php echo $optin === 'yes' ? '#16a34a' : '#94a3b8'; ?>"><?php echo $optin === 'yes' ? '✅ Opted In' : '❌ No Opt-In'; ?></span></p>
                <p style="margin: 4px 0;">
                    <strong>Confirmation:</strong> 
                    <span style="display:inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; color: #fff; background: <?php echo esc_attr($badge_color); ?>;">
                        <?php echo strtoupper(esc_html($confirmation_status)); ?>
                    </span>
                </p>
            </div>
            
            <a href="<?php echo esc_url($waUrl); ?>" target="_blank" class="button button-primary" style="width:100%; text-align:center; margin-bottom: 10px; background: #25D366; border-color: #20ba5a;">
                💬 Open WhatsApp Web Chat
            </a>

            <!-- Update Confirmation Status & Send Custom Note Form -->
            <form method="post" style="border-top: 1px solid #ddd; padding-top: 10px; margin-top: 10px;">
                <?php wp_nonce_field('bwa_order_action', 'bwa_order_action_nonce'); ?>
                <input type="hidden" name="bwa_order_id" value="<?php echo esc_attr($order->get_id()); ?>" />

                <label style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 4px;">Update Confirmation Status:</label>
                <div style="display: flex; gap: 6px; margin-bottom: 12px;">
                    <select name="bwa_confirmation_status" style="width: 100%;">
                        <option value="pending" <?php selected($confirmation_status, 'pending'); ?>>⏳ Pending Confirmation</option>
                        <option value="confirmed" <?php selected($confirmation_status, 'confirmed'); ?>>✅ Confirmed via WhatsApp</option>
                        <option value="cancelled" <?php selected($confirmation_status, 'cancelled'); ?>>❌ Cancelled by Customer</option>
                    </select>
                </div>

                <label style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 4px;">Quick Direct Message to Customer:</label>
                <textarea name="bwa_custom_message" rows="3" style="width: 100%; font-size: 12px; margin-bottom: 8px;" placeholder="Type custom message to queue for WhatsApp dispatch..."></textarea>

                <button type="submit" class="button button-secondary" style="width:100%; text-align:center;">
                    💾 Save Status & Queue Message
                </button>
            </form>
        </div>
        <?php
    }
}
}
