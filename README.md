# Busistree — Automated WhatsApp Order & Status Notification Bot (WordPress & WooCommerce)

A full-featured WhatsApp automation suite connecting **WordPress / WooCommerce** directly to **WhatsApp Web** without any Meta Cloud API fees, Twilio costs, or monthly subscriptions.

---

## 🌟 Architecture & Key Features

1. **WordPress / WooCommerce Plugin (`busistree-whatsapp-notifier`)**:
   - **Order Event Listeners**: Listens to WooCommerce order hooks (`woocommerce_new_order`, `woocommerce_order_status_changed`).
   - **Supported Lifecycle Events**:
     - 📥 **Pending / Order Received (`pending`):** Immediate order confirmation with items list, order reference, payment method, and total amount.
     - ⚙️ **Processing / In Progress (`processing`):** Informs client that order preparation/development is underway.
     - 🚀 **Completed / Delivered (`completed`):** Congratulates client, delivers fulfillment update, and invites reviews.
     - ❌ **Cancelled (`cancelled`):** Cancellation notification with direct support contact.
     - ⏸️ **On Hold (`on-hold`):** Alerts client that order is awaiting payment receipt.
     - 💸 **Refunded (`refunded`):** Sends refund notification.
   - **Dynamic Tag Replacements**: Full support for `{customer_name}`, `{order_number}`, `{items_list}`, `{total}`, `{currency}`, `{payment_method}`, `{order_date}`, `{billing_phone}`, `{site_name}`, `{site_url}`.
   - **High-Performance Queue & Audit Log**: Custom table (`wp_bwa_message_queue`) prevents duplicate sends and keeps a history of sent notifications with retry capabilities.
   - **WooCommerce Admin Integration**:
     - Direct **"💬 Chat"** button on WooCommerce Orders list table.
     - Meta box on WooCommerce Order edit page for instant one-click messaging or re-queueing.
   - **Secure REST API**: Protected with a secret API key (`/wp-json/busistree-wa/v1/queue` and `/mark-sent`).

2. **Automated Chrome Extension (`e:\extension`)**:
   - Polling engine that syncs with your WordPress store queue every 15 seconds.
   - Automatically navigates WhatsApp Web (`https://web.whatsapp.com`), types the message, and clicks Send without manual intervention.
   - Reports back to WordPress so order notes are automatically updated ("Message sent successfully via WhatsApp Web").
   - Pakistani phone number normalization (`0300...` or `300...` -> `92300...`).

---

## 📦 How to Install the WordPress Plugin

The plugin has been packaged into a ready-to-upload zip file:
```
e:\extension\busistree-whatsapp-notifier.zip
```
(Or find the source folder in `e:\extension\wordpress-plugin\busistree-whatsapp-notifier`)

### Installation Steps:
1. Log in to your **WordPress Admin Dashboard**.
2. Go to **Plugins > Add New > Upload Plugin**.
3. Choose the file:
   ```
   e:\extension\busistree-whatsapp-notifier.zip
   ```
4. Click **Install Now** and then click **Activate Plugin**.
5. In your WordPress sidebar, click **WhatsApp Bot**.
6. You will see:
   - **Store URL** (e.g. `https://yoursite.com`)
   - **Secret API Key** (e.g. `bwa_xxxxxxxxxxxxxxxxxxxx`)
   - Click the **Copy** buttons for both.

---

## 🚀 How to Configure the Chrome Extension

1. Open **Google Chrome** and go to `chrome://extensions`.
2. Enable **Developer mode** (toggle in the top-right corner).
3. If already loaded, click **Reload (↻)**. Otherwise, click **Load unpacked** and select `e:\extension`.
4. Click the extension icon in your Chrome toolbar.
5. Go to the **Settings** tab:
   - **Backend Source:** Select `WordPress / WooCommerce (Recommended)`.
   - **WordPress Store URL:** Paste your Store URL (e.g. `https://yoursite.com`).
   - **Plugin API Key:** Paste your Plugin API Key.
   - Click **🔍 Test WordPress Connection** (you will see a green checkmark confirming connection).
   - Click **Save Settings**.
6. Open a Chrome tab to `https://web.whatsapp.com` and ensure your business WhatsApp account is logged in.

---

## ⚙️ How It Operates

```
[ Customer places order on WooCommerce Store ]
                       │
                       ▼
          [ WordPress Plugin Hooks ]
  • Detects order status (e.g., Pending, Processing)
  • Normalizes phone number (Pakistani / Intl)
  • Formats message with dynamic template
  • Adds to wp_bwa_message_queue
                       │
                       ▼
    [ Chrome Extension Background Worker ]
  • Polls /wp-json/busistree-wa/v1/queue every 15s
  • Retrieves pending messages
                       │
                       ▼
            [ Tab: web.whatsapp.com ]
  • Opens chat for customer's phone
  • Types message & clicks Send
  • Confirms delivery checkmark
                       │
                       ▼
         [ WordPress Status Callback ]
  • Marks item as "Sent" in queue
  • Adds WooCommerce order note: "Message sent via WhatsApp Web"
```
