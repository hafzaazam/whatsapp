/**
 * Busistree - WhatsApp Order & Status Bot
 * Background Service Worker (Manifest V3)
 *
 * Automatically monitors Supabase bundle_orders for:
 * 1. Newly received orders (Order Confirmation message)
 * 2. Order status changes (Contacted, In Progress, Fulfilled, Cancelled)
 * And sends formatted WhatsApp messages via WhatsApp Web without Meta API.
 */

// Production Service Role Key allows reading orders with Row Level Security (RLS)
const DEFAULT_SUPABASE_URL = 'https://sherdswwpeeurzqkiapd.supabase.co';
const DEFAULT_SUPABASE_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InNoZXJkc3d3cGVldXJ6cWtpYXBkIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc4NDU0OTY1NywiZXhwIjoyMTAwMTI1NjU3fQ.1Sp8AZN6DEvc3tFpLXEVPw3WgnfDgSLmNq0r0DPvRPk';

// Default templates for Busistree service bundle order lifecycle
const DEFAULT_TEMPLATES = {
  received: `Assalam-o-Alaikum {customer_name}! 🚀
Thank you for choosing *Busistree*! We have received your order.

📦 *Order Ref:* #{order_number}
💼 *Service Bundle:* {bundle_name}
🏢 *Business Name:* {business_name}
💰 *Total Amount:* *{currency} {total}* ({payment_method})
📅 *Date:* {order_date}

Our onboarding team is reviewing your project details and will be in touch shortly to kick off your setup!

💬 Reply directly to this WhatsApp message if you have any questions or want to share additional details.
🌐 *Busistree* | Transforming Ideas Into Thriving Businesses`,

  contacted: `Assalam-o-Alaikum {customer_name}! 🚀
Our onboarding specialist at *Busistree* has reached out regarding your order *#{order_number}* ({bundle_name}).

Please reply to this chat or check your email ({customer_email}) so we can finalize your project briefing and begin development right away.
🌐 *Busistree* | Transforming Ideas Into Thriving Businesses`,

  in_progress: `Assalam-o-Alaikum {customer_name}! 🚀
Great news! Work on your *{bundle_name}* for *{business_name}* is now *Actively In Progress*! 💻✨

📦 *Order Ref:* #{order_number}
Our design and development team is crafting your deliverables according to your specifications. We will keep you updated on progress milestones.
🌐 *Busistree* | Transforming Ideas Into Thriving Businesses`,

  fulfilled: `Assalam-o-Alaikum {customer_name}! 🎉
Your Busistree order *#{order_number}* (*{bundle_name}*) has been *Successfully Completed and Delivered*! 🚀

Thank you for trusting Busistree as your digital growth partner.
⭐ We would love to hear your feedback on your experience!
🌐 *Busistree* | Transforming Ideas Into Thriving Businesses`,

  cancelled: `Assalam-o-Alaikum {customer_name}!
Your order *#{order_number}* for *{bundle_name}* has been marked as *Cancelled*.

If this was an error or you wish to resume your project, simply reply to this WhatsApp message and our support team will assist you immediately.
🌐 *Busistree* | Client Support`,
};

const DEFAULT_ENABLED_EVENTS = {
  received: true,
  contacted: true,
  in_progress: true,
  fulfilled: true,
  cancelled: true,
};

let isProcessingQueue = false;
let sendQueue = [];
let currentSendingJob = null;
let pollTimer = null;
const processedWpQueueIds = new Set();

// Normalizes Pakistani & international phone numbers to valid WhatsApp format (e.g. 923XXXXXXXXX)
function normalizePhone(raw) {
  if (!raw) return null;
  let digits = String(raw).replace(/\D/g, '');
  if (!digits) return null;

  // Strip international 00 prefix (e.g. 0092... -> 92...)
  if (digits.startsWith('00')) {
    digits = digits.slice(2);
  }

  // Pakistani numbers starting with 03 (e.g. 03001234567, 0337042337)
  if (digits.startsWith('03')) {
    digits = '92' + digits.slice(1);
  }
  // Pakistani numbers starting with 3 (e.g. 337042337 or 3001234567, 9 to 10 digits)
  else if (digits.startsWith('3') && digits.length >= 9 && digits.length <= 10) {
    digits = '92' + digits;
  }
  // Any other domestic single leading 0
  else if (digits.startsWith('0')) {
    digits = digits.replace(/^0+/, '');
    if (digits.startsWith('3') && digits.length >= 9 && digits.length <= 10) {
      digits = '92' + digits;
    }
  }

  // A valid WhatsApp number can NEVER start with 0
  if (digits.startsWith('0')) {
    return null;
  }

  // WhatsApp numbers must be between 10 and 15 digits
  if (digits.length < 10 || digits.length > 15) {
    return null;
  }

  return digits;
}

// Maps raw order status strings to normalized lifecycle event keys for Busistree
function mapStatusToEvent(status) {
  const s = String(status || 'new').toLowerCase().trim();
  if (['new', 'received', 'pending', 'placed'].includes(s)) return 'received';
  if (['contacted', 'consulting', 'confirmed', 'accepted'].includes(s)) return 'contacted';
  if (['in_progress', 'in-progress', 'processing', 'development'].includes(s)) return 'in_progress';
  if (['fulfilled', 'completed', 'delivered', 'done'].includes(s)) return 'fulfilled';
  if (['cancelled', 'canceled'].includes(s)) return 'cancelled';
  return null;
}

function formatPaymentMethod(method) {
  const m = String(method || '').toLowerCase().trim();
  if (m === 'bank_transfer') return 'Bank Transfer';
  if (m === 'cod') return 'Cash on Delivery';
  if (m === 'card_manual') return 'Credit / Debit Card';
  if (m === 'cheque') return 'Bank Cheque';
  if (m === 'wire') return 'Wire Transfer';
  if (m === 'other') return 'Online / Direct';
  return method || 'Direct';
}

// Builds the final WhatsApp text from order data and appropriate template
function formatMessage(order, eventKey, templates) {
  const template = templates?.[eventKey] || DEFAULT_TEMPLATES[eventKey] || DEFAULT_TEMPLATES.received;

  const name = order.name || order.customer_name || order.customer?.name || 'Valued Client';
  const orderNum = order.id ? ('BT-' + String(order.id).slice(0, 8).toUpperCase()) : (order.order_number || String(order.id || 'ORDER'));
  const bundleName = order.bundle || order.service_name || order.package_name || 'Business Growth Bundle';
  const businessName = order.business_name || 'Your Business';
  const businessType = order.business_type || 'N/A';
  const email = order.email || order.customer_email || 'your email';
  const currency = order.currency || 'PKR';
  const total = order.amount_cents != null 
    ? (order.amount_cents / 100).toLocaleString('en-PK') 
    : (Number(order.total || 0).toLocaleString('en-PK'));
  const subtotal = total;
  const paymentMethod = formatPaymentMethod(order.payment_method);
  const paymentStatus = (order.payment_status || 'unpaid').toUpperCase();
  const dateStr = new Date(order.created_at || Date.now()).toLocaleDateString('en-PK', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  });
  const itemsList = `• ${bundleName}`;
  const portalUrl = 'https://busistree.com';

  return template
    .replace(/{customer_name}/g, name)
    .replace(/{order_number}/g, orderNum)
    .replace(/{bundle_name}/g, bundleName)
    .replace(/{business_name}/g, businessName)
    .replace(/{business_type}/g, businessType)
    .replace(/{customer_email}/g, email)
    .replace(/{currency}/g, currency)
    .replace(/{total}/g, total)
    .replace(/{subtotal}/g, subtotal)
    .replace(/{payment_method}/g, paymentMethod)
    .replace(/{payment_status}/g, paymentStatus)
    .replace(/{order_date}/g, dateStr)
    .replace(/{items_list}/g, itemsList)
    .replace(/{portal_url}/g, portalUrl);
}

// Append an activity log entry
async function logActivity(entry) {
  const store = await chrome.storage.local.get(['activityLogs', 'stats']);
  const logs = store.activityLogs || [];
  const stats = store.stats || { sentCount: 0, failedCount: 0 };

  logs.unshift({
    timestamp: new Date().toISOString(),
    ...entry,
  });

  if (entry.status === 'sent') stats.sentCount = (stats.sentCount || 0) + 1;
  if (entry.status === 'failed') stats.failedCount = (stats.failedCount || 0) + 1;

  await chrome.storage.local.set({
    activityLogs: logs.slice(0, 100),
    stats,
  });
}

// Read from Supabase bundle_orders table with Service Role key (bypasses RLS)
async function pollSupabaseOrders() {
  const config = await chrome.storage.local.get([
    'enabled',
    'supabaseUrl',
    'supabaseKey',
    'orderTracking',
    'templates',
    'enabledEvents',
    'ordersTable',
  ]);

  if (config.enabled === false) return;

  const url = (config.supabaseUrl || DEFAULT_SUPABASE_URL).replace(/\/$/, '');
  
  // Use service role key to ensure RLS does not return empty array
  let key = config.supabaseKey || DEFAULT_SUPABASE_KEY;
  if (!key || key.includes('role":"anon"')) {
    key = DEFAULT_SUPABASE_KEY;
    await chrome.storage.local.set({ supabaseKey: DEFAULT_SUPABASE_KEY });
  }

  const orderTracking = config.orderTracking || {};
  const templates = config.templates || DEFAULT_TEMPLATES;
  const enabledEvents = config.enabledEvents || DEFAULT_ENABLED_EVENTS;
  const table = config.ordersTable || 'bundle_orders';

  try {
    const fields = [
      'id',
      'name',
      'phone',
      'email',
      'bundle',
      'business_name',
      'business_type',
      'business_website',
      'business_description',
      'amount_cents',
      'discount_cents',
      'currency',
      'payment_method',
      'payment_status',
      'status',
      'notes',
      'created_at',
      'updated_at',
      'status_updated_at',
    ].join(',');

    const endpoint = `${url}/rest/v1/${table}?select=${fields}&order=updated_at.desc.nullslast,created_at.desc&limit=30`;
    const res = await fetch(endpoint, {
      headers: {
        apikey: key,
        Authorization: `Bearer ${key}`,
        Accept: 'application/json',
      },
    });

    if (!res.ok) {
      console.warn('[Busistree Bot] Orders fetch failed HTTP', res.status);
      return;
    }

    const orders = await res.json();
    if (!Array.isArray(orders) || orders.length === 0) return;

    let queuedCount = 0;
    const now = Date.now();
    const maxAgeMs = 7 * 24 * 60 * 60 * 1000; // Notify orders from last 7 days

    for (const order of orders) {
      const orderNumber = order.id ? ('BT-' + String(order.id).slice(0, 8).toUpperCase()) : String(order.id || Date.now());
      const rawPhone = order.phone || order.customer_phone || order.customer?.phone;
      const phone = normalizePhone(rawPhone);

      const currentStatus = String(order.status || 'new').toLowerCase().trim();
      const eventKey = mapStatusToEvent(currentStatus);
      if (!eventKey) continue;

      const orderTime = new Date(order.created_at || now).getTime();
      const isRecent = now - orderTime < maxAgeMs;

      const tracking = orderTracking[orderNumber];

      let shouldSend = false;
      let reasonEvent = eventKey;

      if (!tracking) {
        // CASE 1: Brand new order received
        orderTracking[orderNumber] = {
          lastStatus: currentStatus,
          notifiedEvents: [eventKey],
          lastNotifiedAt: new Date().toISOString(),
          phone: rawPhone || '',
        };

        if (isRecent && enabledEvents[eventKey] !== false) {
          shouldSend = true;
          reasonEvent = eventKey;
        }
      } else if (tracking.lastStatus !== currentStatus) {
        // CASE 2: Order status has CHANGED
        tracking.notifiedEvents = tracking.notifiedEvents || [];

        if (!tracking.notifiedEvents.includes(eventKey)) {
          tracking.lastStatus = currentStatus;
          tracking.notifiedEvents.push(eventKey);
          tracking.lastNotifiedAt = new Date().toISOString();

          if (enabledEvents[eventKey] !== false) {
            shouldSend = true;
            reasonEvent = eventKey;
          }
        } else {
          tracking.lastStatus = currentStatus;
        }
      }

      if (shouldSend) {
        if (!phone) {
          console.warn(`[Busistree Bot] Skipping order #${orderNumber} (${reasonEvent}): invalid phone "${rawPhone}"`);
          await logActivity({
            orderNumber,
            event: reasonEvent,
            phone: rawPhone || 'N/A',
            status: 'failed',
            reason: 'Missing or invalid phone number',
          });
          continue;
        }

        // Avoid adding duplicate order + event to in-flight queue
        const inQueue = sendQueue.some(
          (q) => q.orderNumber === orderNumber && q.eventKey === reasonEvent
        );

        if (!inQueue) {
          const message = formatMessage(order, reasonEvent, templates);
          sendQueue.push({
            orderNumber,
            phone,
            eventKey: reasonEvent,
            statusLabel: currentStatus.toUpperCase(),
            message,
            order,
            retries: 0,
          });
          queuedCount++;

          // Display Chrome notification
          try {
            chrome.notifications.create(`notify-${orderNumber}-${reasonEvent}`, {
              type: 'basic',
              iconUrl: 'icons/icon48.png',
              title: `Order #${orderNumber}: ${reasonEvent.toUpperCase()}`,
              message: `Client: ${order.name || 'Client'}\nSending WhatsApp message to ${phone}...`,
              priority: 1,
            });
          } catch {}
        }
      }
    }

    await chrome.storage.local.set({ orderTracking });

    if (queuedCount > 0) {
      console.log(`[Busistree Bot] Queued ${queuedCount} WhatsApp message(s).`);
      updateBadge();
      processSendQueue();
    }
  } catch (err) {
    console.warn('[Busistree Bot] Error during orders check:', err);
  }
}

// Report message delivery result back to WordPress REST API
async function markWordPressItemStatus(queueId, orderId, status, error = null) {
  try {
    const config = await chrome.storage.local.get(['wpSiteUrl', 'wpApiKey']);
    const siteUrl = (config.wpSiteUrl || '').replace(/\/$/, '');
    const apiKey = config.wpApiKey || '';
    if (!siteUrl || !apiKey || !queueId) return;

    await fetch(`${siteUrl}/wp-json/busistree-wa/v1/mark-sent`, {
      method: 'POST',
      headers: {
        'X-BWA-API-Key': apiKey,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        queue_id: queueId,
        order_id: orderId,
        status,
        error: error || null,
      }),
    });
    console.log(`[Busistree Bot] Reported queue #${queueId} status '${status}' to WordPress.`);
  } catch (err) {
    console.warn('[Busistree Bot] Failed to report status to WordPress:', err);
  }
}

// Read pending messages directly from WordPress WooCommerce Plugin Queue
async function pollWordPressQueue() {
  const config = await chrome.storage.local.get(['enabled', 'wpSiteUrl', 'wpApiKey']);
  if (config.enabled === false) return;

  const siteUrl = (config.wpSiteUrl || '').replace(/\/$/, '');
  const apiKey = config.wpApiKey || '';
  if (!siteUrl || !apiKey) {
    return; // WordPress not configured yet
  }

  try {
    const res = await fetch(`${siteUrl}/wp-json/busistree-wa/v1/queue?limit=10`, {
      headers: {
        'X-BWA-API-Key': apiKey,
        'Accept': 'application/json',
      },
    });

    if (!res.ok) {
      console.warn('[Busistree Bot] WordPress queue fetch HTTP status:', res.status);
      return;
    }

    const data = await res.json();
    if (!data.success || !Array.isArray(data.items) || data.items.length === 0) {
      return;
    }

    const store = await chrome.storage.local.get(['processedWpQueueIds']);
    const processedSet = new Set(store.processedWpQueueIds || []);

    let queuedCount = 0;
    for (const item of data.items) {
      if (processedSet.has(item.id)) {
        continue; // Already handled in this session
      }
      processedSet.add(item.id);

      const orderNumber = item.order_id ? String(item.order_id) : `Q-${item.id}`;
      const phone = normalizePhone(item.phone);

      if (!phone) {
        console.warn(`[Busistree Bot] Skipping queue #${item.id}: invalid phone "${item.phone}"`);
        await markWordPressItemStatus(item.id, item.order_id, 'failed', 'Invalid phone number format');
        continue;
      }

      // Check if already in queue or currently sending
      const inQueue = sendQueue.some((q) => q.queueId === item.id) || (currentSendingJob && currentSendingJob.queueId === item.id);
      if (!inQueue) {
        sendQueue.push({
          queueId: item.id,
          orderId: item.order_id,
          orderNumber,
          phone,
          eventKey: item.event || 'notification',
          statusLabel: (item.event || 'ORDER').toUpperCase(),
          message: item.message,
          backendSource: 'wordpress',
          retries: 0,
        });
        queuedCount++;

        try {
          chrome.notifications.create(`notify-wp-${item.id}`, {
            type: 'basic',
            iconUrl: 'icons/icon48.png',
            title: `Order #${orderNumber}: ${(item.event || 'New Order').toUpperCase()}`,
            message: `Customer: ${item.customer_name || 'Client'}\nSending WhatsApp message to ${phone}...`,
            priority: 1,
          });
        } catch {}
      }
    }

    await chrome.storage.local.set({ processedWpQueueIds: Array.from(processedSet).slice(-500) });

    if (queuedCount > 0) {
      console.log(`[Busistree Bot] Queued ${queuedCount} message(s) from WordPress.`);
      updateBadge();
      processSendQueue();
    }
  } catch (err) {
    console.warn('[Busistree Bot] Error polling WordPress queue:', err);
  }
}

// Unified Polling Router
async function pollActiveOrders() {
  const config = await chrome.storage.local.get(['enabled', 'backendMode', 'wpSiteUrl']);
  if (config.enabled === false) return;

  const mode = config.backendMode || (config.wpSiteUrl ? 'wordpress' : 'wordpress');
  if (mode === 'supabase') {
    return pollSupabaseOrders();
  } else {
    return pollWordPressQueue();
  }
}

// Sequential WhatsApp Web queue worker
async function processSendQueue() {
  if (isProcessingQueue) return;
  if (sendQueue.length === 0) {
    updateBadge();
    return;
  }

  const config = await chrome.storage.local.get(['enabled', 'delayBetweenSends']);
  if (config.enabled === false) {
    isProcessingQueue = false;
    currentSendingJob = null;
    return;
  }

  isProcessingQueue = true;
  currentSendingJob = sendQueue.shift();
  updateBadge();

  const { orderNumber, phone, message, eventKey, statusLabel } = currentSendingJob;
  const cleanPhone = normalizePhone(phone);

  // STRICT GUARD: If phone is invalid or starts with 0, DO NOT navigate or touch WhatsApp tab!
  if (!cleanPhone || cleanPhone.startsWith('0') || cleanPhone.length < 10) {
    console.warn(`[Busistree Bot] Skipping #${orderNumber} (${eventKey}): Invalid phone "${phone}". Refusing to touch WhatsApp tab.`);
    await logActivity({
      orderNumber,
      event: eventKey || 'notification',
      phone: phone || 'N/A',
      status: 'failed',
      reason: `Invalid phone "${phone}". Must be international format without leading zero.`,
    });

    if (currentSendingJob.backendSource === 'wordpress' && currentSendingJob.queueId) {
      await markWordPressItemStatus(currentSendingJob.queueId, currentSendingJob.orderId, 'failed', 'Invalid phone number format');
    }

    currentSendingJob = null;
    isProcessingQueue = false;
    updateBadge();

    if (sendQueue.length > 0) {
      setTimeout(processSendQueue, 2000);
    }
    return;
  }

  console.log(`[Busistree Bot] Processing #${orderNumber} (${eventKey}) for ${cleanPhone}...`);

  try {
    // 1. Locate or create WhatsApp Web tab
    const tabs = await chrome.tabs.query({ url: '*://web.whatsapp.com/*' });
    let targetTab = tabs[0];
    const sendUrl = `https://web.whatsapp.com/send?phone=${cleanPhone}&text=${encodeURIComponent(message)}`;

    if (targetTab) {
      await chrome.tabs.update(targetTab.id, { url: sendUrl, active: false });
    } else {
      targetTab = await chrome.tabs.create({ url: sendUrl, active: false });
    }

    // 2. Wait for confirmation from content script via Promise with timeout (50 seconds)
    const result = await waitForTabConfirmation(targetTab.id, orderNumber, 50000);

    if (result && result.success) {
      console.log(`[Busistree Bot] Successfully sent #${orderNumber} (${eventKey}) to ${cleanPhone}!`);
      await logActivity({
        orderNumber,
        event: eventKey || 'notification',
        phone: cleanPhone,
        status: 'sent',
      });

      if (currentSendingJob.backendSource === 'wordpress' && currentSendingJob.queueId) {
        await markWordPressItemStatus(currentSendingJob.queueId, currentSendingJob.orderId, 'sent', null);
      }
    } else {
      const errMsg = result?.error || 'Send failure or timeout on WhatsApp Web';
      console.warn(`[Busistree Bot] Failed #${orderNumber} to ${cleanPhone}:`, errMsg);

      await logActivity({
        orderNumber,
        event: eventKey || 'notification',
        phone: cleanPhone,
        status: 'failed',
        reason: errMsg,
      });

      if (currentSendingJob.backendSource === 'wordpress' && currentSendingJob.queueId) {
        await markWordPressItemStatus(currentSendingJob.queueId, currentSendingJob.orderId, 'failed', errMsg);
      }
    }
  } catch (err) {
    console.error(`[Busistree Bot] Exception processing #${orderNumber}:`, err);
    await logActivity({
      orderNumber,
      event: eventKey || 'notification',
      phone: cleanPhone,
      status: 'failed',
      reason: err.message || 'Browser exception',
    });
  } finally {
    currentSendingJob = null;
    isProcessingQueue = false;
    updateBadge();

    // Natural pacing delay between consecutive sends (minimum 15 seconds to allow tab to rest)
    if (sendQueue.length > 0) {
      const delay = Math.max(15, (Number(config.delayBetweenSends) || 15)) * 1000;
      setTimeout(() => {
        if (sendQueue.length > 0) {
          processSendQueue();
        }
      }, delay);
    }
  }
}

// Listens for whatsapp-content.js confirmation response with timeout and tab close listener
function waitForTabConfirmation(tabId, expectedOrderNumber, timeoutMs = 50000) {
  return new Promise((resolve) => {
    let settled = false;

    const timer = setTimeout(() => {
      cleanup();
      resolve({
        success: false,
        error: 'Timeout: WhatsApp Web did not finish within 50s. Make sure WhatsApp Web is open and logged in.',
      });
    }, timeoutMs);

    const listener = (msg, sender) => {
      if (sender.tab && sender.tab.id === tabId && msg && msg.type === 'WHATSAPP_SEND_RESULT') {
        cleanup();
        resolve(msg);
      }
    };

    const tabRemovedListener = (closedTabId) => {
      if (closedTabId === tabId) {
        cleanup();
        resolve({
          success: false,
          error: 'WhatsApp Web tab was closed before message could be sent.',
        });
      }
    };

    function cleanup() {
      if (!settled) {
        settled = true;
        clearTimeout(timer);
        chrome.runtime.onMessage.removeListener(listener);
        chrome.tabs.onRemoved.removeListener(tabRemovedListener);
      }
    }

    chrome.runtime.onMessage.addListener(listener);
    chrome.tabs.onRemoved.addListener(tabRemovedListener);
  });
}

// Update extension icon badge
function updateBadge() {
  const pendingCount = sendQueue.length + (currentSendingJob ? 1 : 0);
  if (pendingCount > 0) {
    chrome.action.setBadgeText({ text: String(pendingCount) });
    chrome.action.setBadgeBackgroundColor({ color: '#E59A18' });
  } else {
    chrome.action.setBadgeText({ text: 'ON' });
    chrome.action.setBadgeBackgroundColor({ color: '#25D366' });
  }
}

// Polling interval (25 seconds) + Chrome Alarm as watchdog
function startActivePolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(async () => {
    const config = await chrome.storage.local.get(['enabled']);
    if (config.enabled !== false) {
      pollActiveOrders();
    }
  }, 25000);
}

chrome.alarms.create('checkOrdersAlarm', {
  periodInMinutes: 2, // 2 minute watchdog
});

chrome.alarms.onAlarm.addListener(async (alarm) => {
  if (alarm.name === 'checkOrdersAlarm') {
    const config = await chrome.storage.local.get(['enabled']);
    if (config.enabled !== false) {
      pollActiveOrders();
    }
  }
});

// Runtime messages from popup and options
chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  if (msg.action === 'BOT_STORE_API') {
    (async () => {
      const route = typeof msg.route === 'string' ? msg.route : '';
      const method = msg.method === 'POST' ? 'POST' : 'GET';
      const allowedRoutes = ['/search-products', '/insights', '/store-info', '/customer-orders', '/order-status', '/create-order'];
      const routePath = route.split('?')[0];
      const config = await chrome.storage.local.get(['wpSiteUrl', 'wpApiKey', 'enabled', 'autoReplyEnabled']);

      if (config.enabled === false || config.autoReplyEnabled !== true) {
        throw new Error('Chat auto-replies are disabled.');
      }
      if (!allowedRoutes.includes(routePath) || (routePath === '/create-order' && method !== 'POST') || (routePath !== '/create-order' && method !== 'GET')) {
        throw new Error('The requested store operation is not allowed.');
      }
      if (!config.wpSiteUrl || !config.wpApiKey) {
        throw new Error('Configure the WordPress store URL and plugin API key first.');
      }

      const site = new URL(config.wpSiteUrl);
      if (site.protocol !== 'https:' && site.hostname !== 'localhost' && site.hostname !== '127.0.0.1') {
        throw new Error('The store URL must use HTTPS.');
      }

      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 10000);
      let response;
      try {
        response = await fetch(`${site.origin}${site.pathname.replace(/\/+$/, '')}/wp-json/busistree-wa/v1${route}`, {
          method,
          headers: {
            'Accept': 'application/json',
            'X-BWA-API-Key': config.wpApiKey,
            ...(method === 'POST' ? { 'Content-Type': 'application/json' } : {}),
          },
          ...(method === 'POST' ? { body: JSON.stringify(msg.body || {}) } : {}),
          signal: controller.signal,
        });
      } finally {
        clearTimeout(timeout);
      }

      const data = await response.json();
      if (!response.ok || data?.success === false) {
        throw new Error(data?.message || `Store API returned HTTP ${response.status}.`);
      }
      sendResponse({ success: true, data });
    })().catch(error => sendResponse({ success: false, error: error.message }));
    return true;
  }

  if (msg.action === 'triggerCheck') {
    pollActiveOrders().then(() => sendResponse({ success: true }));
    return true;
  }

  if (msg.action === 'getQueueStatus') {
    sendResponse({
      queueLength: sendQueue.length,
      currentSendingJob: currentSendingJob ? { orderNumber: currentSendingJob.orderNumber, event: currentSendingJob.eventKey } : null,
    });
    return true;
  }

  if (msg.action === 'sendManualTest') {
    const { testPhone, testOrderNumber, customMessage, eventKey } = msg;
    const phone = normalizePhone(testPhone);
    if (!phone) {
      sendResponse({ success: false, error: 'Invalid phone number format. Must not start with 0.' });
      return;
    }

    sendQueue.unshift({
      orderNumber: testOrderNumber || `BT-TEST-${Date.now().toString().slice(-4)}`,
      phone,
      eventKey: eventKey || 'test',
      statusLabel: 'TEST',
      message: customMessage || 'This is a test notification from Busistree WhatsApp Bot! 🚀',
      retries: 0,
    });

    updateBadge();
    processSendQueue();
    sendResponse({ success: true });
    return true;
  }

  if (msg.action === 'resetTracking') {
    chrome.storage.local.set({ orderTracking: {} }, () => {
      sendResponse({ success: true });
    });
    return true;
  }

  if (msg.action === 'clearQueue') {
    sendQueue = [];
    currentSendingJob = null;
    isProcessingQueue = false;
    chrome.storage.local.set({ processedWpQueueIds: [] });
    updateBadge();

    // Reset any WhatsApp Web tab currently stuck on /send URL back to clean state
    (async () => {
      try {
        const waTabs = await chrome.tabs.query({ url: '*://web.whatsapp.com/*' });
        for (const t of waTabs) {
          if (t.url && (t.url.includes('/send') || t.url.includes('phone='))) {
            await chrome.tabs.update(t.id, { url: 'https://web.whatsapp.com/' });
          }
        }
      } catch (e) {}
    })();

    sendResponse({ success: true, message: 'Queue cleared, sending stopped, and WhatsApp Web restored to clean state.' });
    return true;
  }
});

// Startup initialization
chrome.runtime.onInstalled.addListener(async () => {
  console.log('[Busistree Bot] Extension installed/updated.');
  const existing = await chrome.storage.local.get([
    'backendMode',
    'wpSiteUrl',
    'wpApiKey',
    'supabaseUrl',
    'supabaseKey',
    'templates',
    'enabledEvents',
    'enabled',
    'ordersTable',
  ]);
  
  await chrome.storage.local.set({
    enabled: existing.enabled !== false,
    backendMode: existing.backendMode || (existing.wpSiteUrl ? 'wordpress' : 'wordpress'),
    wpSiteUrl: existing.wpSiteUrl || '',
    wpApiKey: existing.wpApiKey || '',
    supabaseUrl: existing.supabaseUrl || DEFAULT_SUPABASE_URL,
    supabaseKey: DEFAULT_SUPABASE_KEY,
    ordersTable: existing.ordersTable || 'bundle_orders',
    templates: existing.templates || DEFAULT_TEMPLATES,
    enabledEvents: existing.enabledEvents || DEFAULT_ENABLED_EVENTS,
    autoReplyEnabled: existing.autoReplyEnabled === true,
  });

  updateBadge();
  if (existing.enabled !== false) {
    startActivePolling();
  }
});

chrome.runtime.onStartup.addListener(async () => {
  const config = await chrome.storage.local.get(['enabled']);
  if (config.enabled !== false) {
    startActivePolling();
  }
});

// Awaken service worker check
chrome.storage.local.get(['enabled'], (res) => {
  if (res.enabled !== false) {
    startActivePolling();
  }
});
