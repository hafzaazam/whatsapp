/**
 * Busistree - WhatsApp Order & Status Bot
 * Popup Controller Logic (v1.1.0)
 */

document.addEventListener('DOMContentLoaded', async () => {
  // Elements
  const toggleEnabled = document.getElementById('toggleEnabled');
  const sysIndicator = document.getElementById('sysIndicator');
  const sysStatusText = document.getElementById('sysStatusText');
  const sentCount = document.getElementById('sentCount');
  const queueCount = document.getElementById('queueCount');

  // Tabs
  const tabButtons = document.querySelectorAll('.tab-btn');
  const tabContents = document.querySelectorAll('.tab-content');

  // Logs
  const logsContainer = document.getElementById('logsContainer');
  const btnCheckNow = document.getElementById('btnCheckNow');
  const btnClearQueue = document.getElementById('btnClearQueue');

  // Event Triggers
  const evtReceived = document.getElementById('evtReceived');
  const evtContacted = document.getElementById('evtContacted');
  const evtInProgress = document.getElementById('evtInProgress');
  const evtFulfilled = document.getElementById('evtFulfilled');
  const evtCancelled = document.getElementById('evtCancelled');
  const btnSaveEvents = document.getElementById('btnSaveEvents');

  // Templates
  const templateSelector = document.getElementById('templateSelector');
  const templateText = document.getElementById('templateText');
  const btnSaveTemplate = document.getElementById('btnSaveTemplate');
  const btnResetTemplate = document.getElementById('btnResetTemplate');
  const tagChips = document.querySelectorAll('.tag-chip');

  // Test
  const testEventSelector = document.getElementById('testEventSelector');
  const testPhone = document.getElementById('testPhone');
  const testOrderNumber = document.getElementById('testOrderNumber');
  const btnSendTest = document.getElementById('btnSendTest');
  const testFeedback = document.getElementById('testFeedback');

  // Settings
  const cfgBackendMode = document.getElementById('cfgBackendMode');
  const groupWordPress = document.getElementById('groupWordPress');
  const groupSupabase = document.getElementById('groupSupabase');
  const cfgWpUrl = document.getElementById('cfgWpUrl');
  const cfgWpKey = document.getElementById('cfgWpKey');
  const btnTestWp = document.getElementById('btnTestWp');
  const wpTestFeedback = document.getElementById('wpTestFeedback');
  const cfgSupabaseUrl = document.getElementById('cfgSupabaseUrl');
  const cfgSupabaseKey = document.getElementById('cfgSupabaseKey');
  const cfgOrdersTable = document.getElementById('cfgOrdersTable');
  const cfgDelay = document.getElementById('cfgDelay');
  const toggleAutoReply = document.getElementById('toggleAutoReply');
  const btnSaveSettings = document.getElementById('btnSaveSettings');
  const btnClearCache = document.getElementById('btnClearCache');

  // Default templates cache for Busistree
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

If this was an error or you wish to resume your project, simply reply to this WhatsApp message and our team will assist you immediately.
🌐 *Busistree* | Client Support`,
  };

  let loadedTemplates = { ...DEFAULT_TEMPLATES };

  // Tab switching
  tabButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      tabButtons.forEach((b) => b.classList.remove('active'));
      tabContents.forEach((c) => c.classList.remove('active'));
      btn.classList.add('active');
      const targetId = btn.getAttribute('data-tab');
      document.getElementById(targetId)?.classList.add('active');
    });
  });

  // Load initial settings and state
  async function loadState() {
    const store = await chrome.storage.local.get([
      'enabled',
      'backendMode',
      'wpSiteUrl',
      'wpApiKey',
      'supabaseUrl',
      'supabaseKey',
      'ordersTable',
      'delayBetweenSends',
      'templates',
      'enabledEvents',
      'autoReplyEnabled',
      'stats',
      'activityLogs',
    ]);

    const isEnabled = store.enabled !== false;
    toggleEnabled.checked = isEnabled;
    updateStatusIndicator(isEnabled);
    if (toggleAutoReply) toggleAutoReply.checked = store.autoReplyEnabled === true;

    sentCount.textContent = store.stats?.sentCount || 0;

    // Load templates
    if (store.templates) {
      loadedTemplates = { ...DEFAULT_TEMPLATES, ...store.templates };
    }
    const currentTabEvent = templateSelector.value || 'received';
    templateText.value = loadedTemplates[currentTabEvent] || DEFAULT_TEMPLATES[currentTabEvent];

    // Load event toggles
    const events = store.enabledEvents || {};
    if (evtReceived) evtReceived.checked = events.received !== false;
    if (evtContacted) evtContacted.checked = events.contacted !== false;
    if (evtInProgress) evtInProgress.checked = events.in_progress !== false;
    if (evtFulfilled) evtFulfilled.checked = events.fulfilled !== false;
    if (evtCancelled) evtCancelled.checked = events.cancelled !== false;

    // Settings
    const mode = store.backendMode || (store.wpSiteUrl ? 'wordpress' : 'wordpress');
    if (cfgBackendMode) cfgBackendMode.value = mode;
    updateBackendVisibility(mode);

    if (cfgWpUrl) cfgWpUrl.value = store.wpSiteUrl || '';
    if (cfgWpKey) cfgWpKey.value = store.wpApiKey || '';

    cfgSupabaseUrl.value = store.supabaseUrl || 'https://sherdswwpeeurzqkiapd.supabase.co';
    cfgSupabaseKey.value = store.supabaseKey || '';
    if (cfgOrdersTable) cfgOrdersTable.value = store.ordersTable || 'bundle_orders';
    cfgDelay.value = store.delayBetweenSends || 8;

    renderLogs(store.activityLogs || []);
    checkQueue();
    checkAiStatus();
  }

  async function checkAiStatus() {
    const aiStatusText = document.getElementById('aiStatusText');
    if (!aiStatusText) return;
    try {
      const res = await fetch('http://127.0.0.1:4100/api/ai-status');
      if (res.ok) {
        const data = await res.json();
        if (data.configured && data.status === 'active') {
          aiStatusText.textContent = `Active (${data.provider})`;
          aiStatusText.style.color = '#16a34a';
        } else if (data.status === 'rate_limited') {
          aiStatusText.textContent = 'Cap Reached';
          aiStatusText.style.color = '#ea580c';
        } else {
          aiStatusText.textContent = 'Basic Mode';
          aiStatusText.style.color = '#64748b';
        }
        return;
      }
    } catch (e) {
      // Desktop app not running or unreachable
    }
    aiStatusText.textContent = 'Basic Mode';
    aiStatusText.style.color = '#64748b';
  }

  function updateBackendVisibility(mode) {
    if (mode === 'supabase') {
      if (groupWordPress) groupWordPress.style.display = 'none';
      if (groupSupabase) groupSupabase.style.display = 'block';
    } else {
      if (groupWordPress) groupWordPress.style.display = 'block';
      if (groupSupabase) groupSupabase.style.display = 'none';
    }
  }

  if (cfgBackendMode) {
    cfgBackendMode.addEventListener('change', () => {
      updateBackendVisibility(cfgBackendMode.value);
    });
  }

  function updateStatusIndicator(enabled) {
    if (enabled) {
      sysIndicator.className = 'status-indicator active';
      sysStatusText.textContent = 'Active & Watching';
    } else {
      sysIndicator.className = 'status-indicator paused';
      sysStatusText.textContent = 'Paused';
    }
  }

  function checkQueue() {
    chrome.runtime.sendMessage({ action: 'getQueueStatus' }, (res) => {
      if (res) {
        const count = res.queueLength + (res.currentSendingJob ? 1 : 0);
        queueCount.textContent = count > 0 ? `${count} queued` : '0 pending';
      }
    });
  }

  function renderLogs(logs) {
    if (!logs || logs.length === 0) {
      logsContainer.innerHTML = '<div class="empty-state">No order notifications sent yet. When bundle orders are received or change status, they will be sent automatically.</div>';
      return;
    }

    logsContainer.innerHTML = logs.slice(0, 25).map((l) => {
      const timeStr = l.timestamp ? new Date(l.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
      const isSent = l.status === 'sent';
      const eventLabel = l.event ? l.event.replace(/_/g, ' ').toUpperCase() : 'NOTIFICATION';
      return `
        <div class="log-item">
          <div class="log-left">
            <span class="log-order">#${l.orderNumber} <span style="font-size:10px; color:#5E4B38; font-weight:600;">[${eventLabel}]</span></span>
            <span class="log-phone">${l.phone || 'Customer'} &bull; ${timeStr}</span>
          </div>
          <span class="log-status ${isSent ? 'sent' : 'failed'}">
            ${isSent ? 'Delivered' : (l.reason || 'Failed')}
          </span>
        </div>
      `;
    }).join('');
  }

  // Toggle Enable/Disable
  toggleEnabled.addEventListener('change', async () => {
    const enabled = toggleEnabled.checked;
    await chrome.storage.local.set({ enabled });
    updateStatusIndicator(enabled);
  });

  if (toggleAutoReply) {
    toggleAutoReply.addEventListener('change', async () => {
      await chrome.storage.local.set({ autoReplyEnabled: toggleAutoReply.checked });
    });
  }

  // Check now button
  btnCheckNow.addEventListener('click', () => {
    btnCheckNow.textContent = 'Checking...';
    btnCheckNow.disabled = true;

    chrome.runtime.sendMessage({ action: 'triggerCheck' }, () => {
      setTimeout(async () => {
        btnCheckNow.textContent = '↻ Check Now';
        btnCheckNow.disabled = false;
        await loadState();
      }, 1500);
    });
  });

  // Clear queue button
  if (btnClearQueue) {
    btnClearQueue.addEventListener('click', () => {
      btnClearQueue.textContent = 'Clearing...';
      btnClearQueue.disabled = true;

      chrome.runtime.sendMessage({ action: 'clearQueue' }, () => {
        setTimeout(async () => {
          btnClearQueue.textContent = '🛑 Clear Queue';
          btnClearQueue.disabled = false;
          checkQueue();
          await loadState();
        }, 600);
      });
    });
  }

  // Template dropdown change
  templateSelector.addEventListener('change', () => {
    const selectedEvent = templateSelector.value;
    templateText.value = loadedTemplates[selectedEvent] || DEFAULT_TEMPLATES[selectedEvent] || '';
  });

  // Insert tag into template
  tagChips.forEach((chip) => {
    chip.addEventListener('click', () => {
      const tag = chip.getAttribute('data-tag');
      const start = templateText.selectionStart;
      const end = templateText.selectionEnd;
      const val = templateText.value;
      templateText.value = val.substring(0, start) + tag + val.substring(end);
      templateText.focus();
      templateText.selectionStart = templateText.selectionEnd = start + tag.length;
    });
  });

  // Save template
  btnSaveTemplate.addEventListener('click', async () => {
    const selectedEvent = templateSelector.value;
    loadedTemplates[selectedEvent] = templateText.value.trim();

    await chrome.storage.local.set({ templates: loadedTemplates });

    btnSaveTemplate.textContent = '✓ Saved!';
    setTimeout(() => {
      btnSaveTemplate.textContent = 'Save Template';
    }, 1500);
  });

  // Reset template
  btnResetTemplate.addEventListener('click', () => {
    const selectedEvent = templateSelector.value;
    if (confirm(`Reset the "${selectedEvent}" message template to default?`)) {
      loadedTemplates[selectedEvent] = DEFAULT_TEMPLATES[selectedEvent];
      templateText.value = DEFAULT_TEMPLATES[selectedEvent];
      chrome.storage.local.set({ templates: loadedTemplates });
    }
  });

  // Save Event Triggers
  btnSaveEvents.addEventListener('click', async () => {
    const enabledEvents = {
      received: evtReceived ? evtReceived.checked : true,
      contacted: evtContacted ? evtContacted.checked : true,
      in_progress: evtInProgress ? evtInProgress.checked : true,
      fulfilled: evtFulfilled ? evtFulfilled.checked : true,
      cancelled: evtCancelled ? evtCancelled.checked : true,
    };

    await chrome.storage.local.set({ enabledEvents });

    btnSaveEvents.textContent = '✓ Triggers Saved!';
    setTimeout(() => {
      btnSaveEvents.textContent = 'Save Event Triggers';
    }, 1500);
  });

  // Helper to normalize phone numbers
  function normalizePhone(raw) {
    if (!raw) return null;
    let digits = String(raw).replace(/\D/g, '');
    if (!digits) return null;

    if (digits.startsWith('00')) {
      digits = digits.slice(2);
    }
    if (digits.startsWith('03')) {
      digits = '92' + digits.slice(1);
    } else if (digits.startsWith('3') && digits.length >= 9 && digits.length <= 10) {
      digits = '92' + digits;
    } else if (digits.startsWith('0')) {
      digits = digits.replace(/^0+/, '');
      if (digits.startsWith('3') && digits.length >= 9 && digits.length <= 10) {
        digits = '92' + digits;
      }
    }

    if (digits.startsWith('0')) return null;
    if (digits.length < 10 || digits.length > 15) return null;
    return digits;
  }

  // Quick Test send
  btnSendTest.addEventListener('click', () => {
    const rawPhone = testPhone.value.trim();
    const phone = normalizePhone(rawPhone);
    const orderNumber = testOrderNumber.value.trim() || 'BT-7A39F210';
    const selectedEvent = testEventSelector.value || 'received';

    testFeedback.className = 'feedback-msg';
    testFeedback.style.display = 'none';

    if (!phone) {
      testFeedback.textContent = 'Please enter a valid mobile number (e.g. 03001234567 or 923001234567).';
      testFeedback.className = 'feedback-msg error';
      testFeedback.style.display = 'block';
      return;
    }

    // Auto-update input with normalized format so user sees what was formatted
    testPhone.value = phone;

    const sampleOrder = {
      order_number: orderNumber,
      id: '7a39f210-9b4a-4a8d-b0a1-c8b5d9c1c607',
      name: 'Haris Khan',
      phone: phone,
      bundle: 'BizSite E-commerce Bundle',
      business_name: 'Khan Apparel Store',
      business_type: 'Fashion & E-Commerce',
      customer_email: 'haris@example.com',
      total: '45,000',
      currency: 'PKR',
      payment_method: 'Direct Bank Transfer',
      payment_status: 'CONFIRMED',
      created_at: new Date().toISOString(),
    };

    let msgTemplate = loadedTemplates[selectedEvent] || DEFAULT_TEMPLATES[selectedEvent] || '';
    
    // Simple mock formatting
    const formatted = msgTemplate
      .replace(/{customer_name}/g, sampleOrder.name)
      .replace(/{order_number}/g, sampleOrder.order_number)
      .replace(/{order_date}/g, new Date().toLocaleDateString('en-PK', { day: 'numeric', month: 'short', year: 'numeric' }))
      .replace(/{bundle_name}/g, sampleOrder.bundle)
      .replace(/{business_name}/g, sampleOrder.business_name)
      .replace(/{business_type}/g, sampleOrder.business_type)
      .replace(/{customer_email}/g, sampleOrder.customer_email)
      .replace(/{currency}/g, sampleOrder.currency)
      .replace(/{total}/g, sampleOrder.total)
      .replace(/{subtotal}/g, sampleOrder.total)
      .replace(/{payment_method}/g, sampleOrder.payment_method)
      .replace(/{payment_status}/g, sampleOrder.payment_status)
      .replace(/{items_list}/g, `• ${sampleOrder.bundle}`)
      .replace(/{portal_url}/g, 'https://busistree.com');

    btnSendTest.disabled = true;
    btnSendTest.textContent = 'Sending to WhatsApp Web...';

    chrome.runtime.sendMessage(
      {
        action: 'sendManualTest',
        testPhone: phone,
        testOrderNumber: orderNumber,
        eventKey: selectedEvent,
        customMessage: formatted,
      },
      (res) => {
        btnSendTest.disabled = false;
        btnSendTest.textContent = '🚀 Send Test via WhatsApp Web';

        if (res && res.success) {
          testFeedback.textContent = `Test message queued! WhatsApp Web will open and send the ${selectedEvent.toUpperCase()} message automatically.`;
          testFeedback.className = 'feedback-msg success';
          testFeedback.style.display = 'block';
          checkQueue();
        } else {
          testFeedback.textContent = res?.error || 'Failed to dispatch test message.';
          testFeedback.className = 'feedback-msg error';
          testFeedback.style.display = 'block';
        }
      }
    );
  });

  // Test WordPress Connection
  if (btnTestWp) {
    btnTestWp.addEventListener('click', async () => {
      const url = cfgWpUrl.value.trim().replace(/\/$/, '');
      const key = cfgWpKey.value.trim();

      wpTestFeedback.className = 'feedback-msg';
      wpTestFeedback.style.display = 'none';

      if (!url || !key) {
        wpTestFeedback.textContent = 'Please enter your WordPress Store URL and Plugin API Key.';
        wpTestFeedback.className = 'feedback-msg error';
        wpTestFeedback.style.display = 'block';
        return;
      }

      btnTestWp.disabled = true;
      btnTestWp.textContent = 'Testing connection...';

      try {
        const res = await fetch(`${url}/wp-json/busistree-wa/v1/ping`, {
          headers: {
            'X-BWA-API-Key': key,
            'Accept': 'application/json',
          },
        });

        const data = await res.json();
        if (res.ok && data.success) {
          wpTestFeedback.textContent = `✓ Connected to "${data.site_name}"! Pending queue: ${data.stats?.pending ?? 0} messages.`;
          wpTestFeedback.className = 'feedback-msg success';
          wpTestFeedback.style.display = 'block';
        } else {
          wpTestFeedback.textContent = `Connection failed: ${data.message || 'HTTP ' + res.status}`;
          wpTestFeedback.className = 'feedback-msg error';
          wpTestFeedback.style.display = 'block';
        }
      } catch (err) {
        wpTestFeedback.textContent = `Connection error: ${err.message}. Check URL & ensure plugin is active.`;
        wpTestFeedback.className = 'feedback-msg error';
        wpTestFeedback.style.display = 'block';
      } finally {
        btnTestWp.disabled = false;
        btnTestWp.textContent = '🔍 Test WordPress Connection';
      }
    });
  }

  // Save Settings
  btnSaveSettings.addEventListener('click', async () => {
    const backendMode = cfgBackendMode ? cfgBackendMode.value : 'wordpress';
    const wpSiteUrl = cfgWpUrl ? cfgWpUrl.value.trim().replace(/\/$/, '') : '';
    const wpApiKey = cfgWpKey ? cfgWpKey.value.trim() : '';
    const supabaseUrl = cfgSupabaseUrl.value.trim();
    const supabaseKey = cfgSupabaseKey.value.trim();
    const ordersTable = cfgOrdersTable ? cfgOrdersTable.value.trim() : 'bundle_orders';
    const delayBetweenSends = Number(cfgDelay.value) || 8;

    await chrome.storage.local.set({
      backendMode,
      wpSiteUrl,
      wpApiKey,
      supabaseUrl,
      supabaseKey,
      ordersTable,
      delayBetweenSends,
    });

    // Notify background to re-poll immediately
    chrome.runtime.sendMessage({ action: 'triggerCheck' });

    btnSaveSettings.textContent = '✓ Saved!';
    setTimeout(() => {
      btnSaveSettings.textContent = 'Save Settings';
    }, 1500);
  });

  // Clear cache / reset tracking state
  btnClearCache.addEventListener('click', () => {
    if (confirm('Reset tracking history? This will allow recent orders to be evaluated again for notifications.')) {
      chrome.runtime.sendMessage({ action: 'resetTracking' }, () => {
        alert('Tracking history reset. The bot will re-evaluate recent orders on the next poll.');
        loadState();
      });
    }
  });

  // Initial load
  await loadState();

  // Periodic state refresh
  setInterval(() => {
    checkQueue();
    checkAiStatus();
  }, 4000);
});
