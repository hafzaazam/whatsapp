/**
 * Busistree - WhatsApp Web Automation Content Script
 * Injected into https://web.whatsapp.com/*
 */

(function () {
  const url = new URL(window.location.href);
  const isSendUrl = url.pathname.includes('/send') || url.searchParams.has('phone');
  const contactTags = new Map();
  const pausedChats = new Set();

  if (!isSendUrl) {
    setTimeout(() => {
      initCRMDock();
      initChatbot();
    }, 2500);
    return;
  }

  const phone = url.searchParams.get('phone');
  const targetText = url.searchParams.get('text') || '';
  console.log(`[Busistree Bot] Content script active for phone: ${phone}`);

  // Catch unhandled WhatsApp Web React errors (e.g. invalid WID)
  window.addEventListener('error', (event) => {
    const msg = event?.message || event?.error?.message || '';
    if (/invalid wid|wid error|CometSSRMultipassBoundary/i.test(msg)) {
      console.error('[Busistree Bot] WhatsApp Web crashed on phone format:', msg);
      reportResult(false, 'WhatsApp Web rejected phone number (Invalid WID).');
      window.location.replace('https://web.whatsapp.com/');
    }
  });

  // Pre-validate phone: WhatsApp numbers can NEVER start with 0
  if (!phone || phone.startsWith('0') || phone.length < 10) {
    console.error(`[Busistree Bot] Fatal: Phone "${phone}" is invalid (must not start with 0). Redirecting to clean WhatsApp Web...`);
    reportResult(false, `Invalid phone "${phone}". Must be international format without leading 0 (e.g. 92300...).`);
    window.location.replace('https://web.whatsapp.com/');
    return;
  }

  let attempts = 0;
  const maxAttempts = 45; // 45 * 1000ms = 45 seconds
  let isHandled = false;
  let textInjected = false;

  function reportResult(success, error = null) {
    if (isHandled) return;
    isHandled = true;
    clearInterval(checkInterval);
    console.log(`[Busistree Bot] Send result: success=${success}, error=${error}`);
    chrome.runtime.sendMessage({
      type: 'WHATSAPP_SEND_RESULT',
      success,
      error,
      phone,
    });
    // Clean up URL so tab stays in normal view
    try {
      window.history.replaceState({}, '', '/');
    } catch(e) {}
  }

  // Cancel any accidental voice recording if one started
  function cancelVoiceRecordingIfActive() {
    const cancelSelectors = [
      'span[data-icon="trash"]',
      'span[data-icon="delete"]',
      'button[aria-label="Cancel voice message"]',
      'button[aria-label="Delete voice message"]',
      'button[aria-label="Delete"]',
      'button[aria-label="Cancel"]',
      'button[aria-label="Discard"]',
      'div[data-testid="ptt-draft"] button',
      'button[data-testid="ptt-cancel"]',
      'button[data-testid="audio-cancel"]',
      'footer button:has(span[data-icon="trash"])',
      'footer button:has(span[data-icon="delete"])',
    ];

    for (const sel of cancelSelectors) {
      try {
        const el = document.querySelector(sel);
        if (el) {
          console.log(`[Busistree Bot] Active voice recording detected! Cancelling via "${sel}"...`);
          const btn = el.closest('button') || el;
          btn.click();
          return true;
        }
      } catch (e) {}
    }
    return false;
  }

  // Look for error dialogs or intermediate landing screens
  function checkDialogsAndIntermediates() {
    // 1. "Use WhatsApp Web" or "Continue to Chat" landing button
    const actionLinks = Array.from(document.querySelectorAll('a, button, div[role="button"]'));
    const continueBtn = actionLinks.find(
      (el) => /use whatsapp web|continue to chat/i.test(el.textContent || '')
    );
    if (continueBtn) {
      console.log('[Busistree Bot] Clicking intermediate "Continue to Chat" button...');
      continueBtn.click();
      return true;
    }

    // 2. QR Code login screen (WhatsApp Web not logged in)
    const qrCanvas =
      document.querySelector('canvas[aria-label="Scan this QR code"]') ||
      document.querySelector('div[data-ref]') ||
      document.querySelector('canvas');
    if (qrCanvas && document.body.textContent.includes('To use WhatsApp on your computer')) {
      reportResult(false, 'WhatsApp Web is not logged in. Please scan the QR code first.');
      return true;
    }

    // 3. "Phone number shared via url is invalid" dialog
    const popupDialogs = document.querySelectorAll('div[role="dialog"]');
    for (const dialog of popupDialogs) {
      const text = dialog.textContent || '';
      if (/invalid|not on WhatsApp|couldn't open|chat not found/i.test(text)) {
        const okBtn = dialog.querySelector('button, div[role="button"]');
        if (okBtn) okBtn.click();
        reportResult(false, 'Phone number is invalid or not registered on WhatsApp.');
        return true;
      }
    }

    return false;
  }

  // Find the message compose box
  function getMessageBox() {
    return (
      document.querySelector('footer div[contenteditable="true"][role="textbox"]') ||
      document.querySelector('footer div[contenteditable="true"]') ||
      document.querySelector('div[contenteditable="true"][data-tab="10"]') ||
      document.querySelector('div[contenteditable="true"][data-tab="6"]')
    );
  }

  // Find the TRUE Send button (STRICT: Never match mic or voice buttons!)
  function getSendButton() {
    // 1. Look for explicit SEND icons
    const sendIcon =
      document.querySelector('footer span[data-icon="send"]') ||
      document.querySelector('footer span[data-icon="wds-ic-send-filled"]') ||
      document.querySelector('span[data-icon="send"]') ||
      document.querySelector('span[data-icon="wds-ic-send-filled"]');

    if (sendIcon) {
      const btn = sendIcon.closest('button') || sendIcon.parentElement;
      if (btn && !isVoiceButton(btn)) {
        return btn;
      }
    }

    // 2. Look for button with aria-label="Send"
    const ariaSend = document.querySelector('footer button[aria-label="Send"], button[aria-label="Send"]');
    if (ariaSend && !isVoiceButton(ariaSend)) {
      return ariaSend;
    }

    // 3. Look for compose-btn-send test id
    const testIdSend = document.querySelector('button[data-testid="compose-btn-send"], button[data-testid="send"]');
    if (testIdSend && !isVoiceButton(testIdSend)) {
      return testIdSend;
    }

    return null;
  }

  // Safety check: is this a microphone / voice recording button?
  function isVoiceButton(el) {
    if (!el) return true;
    if (el.querySelector('span[data-icon="mic"], span[data-icon="ptt"], span[data-icon="audio"]')) return true;
    const label = (el.getAttribute('aria-label') || '').toLowerCase();
    const testid = (el.getAttribute('data-testid') || '').toLowerCase();
    const dataIcon = (el.getAttribute('data-icon') || '').toLowerCase();

    if (
      label.includes('voice') ||
      label.includes('record') ||
      label.includes('ptt') ||
      label.includes('audio') ||
      label.includes('mic') ||
      testid.includes('ptt') ||
      testid.includes('voice') ||
      testid.includes('mic') ||
      dataIcon.includes('mic') ||
      dataIcon.includes('ptt')
    ) {
      return true;
    }
    return false;
  }

  function getPhoneFromMessage(node) {
    const idNode = node?.matches?.('[data-id]') ? node : node?.closest?.('[data-id]') || node?.querySelector?.('[data-id]');
    const messageId = idNode?.getAttribute('data-id') || '';
    if (/@g\.us/i.test(messageId)) return '';
    const match = messageId.match(/(?:^|_)(\d{8,15})@c\.us(?:_|$)/i);
    if (match) return match[1];

    const headerTitle = document.querySelector('#main header span[title]')?.getAttribute('title') || '';
    const digits = headerTitle.replace(/\D/g, '');
    return digits.length >= 10 && digits.length <= 15 ? digits : '';
  }

  function getCurrentChatPhone() {
    const inbound = Array.from(document.querySelectorAll('#main .message-in')).pop();
    return getPhoneFromMessage(inbound);
  }

  function getInboundText(node) {
    const textNode = node.querySelector('.selectable-text, [data-testid="selectable-text"]');
    const text = (textNode?.innerText || textNode?.textContent || '').trim();
    return text.replace(/\n{3,}/g, '\n\n');
  }

  function sendBotApiRequest(route, options = {}) {
    return new Promise((resolve, reject) => {
      chrome.runtime.sendMessage({
        action: 'BOT_STORE_API',
        route,
        method: options.method || 'GET',
        body: options.body,
      }, response => {
        if (chrome.runtime.lastError) {
          reject(new Error(chrome.runtime.lastError.message));
        } else if (!response?.success) {
          reject(new Error(response?.error || 'Store request failed.'));
        } else {
          resolve(response.data);
        }
      });
    });
  }

  function initChatbot() {
    if (!window.BusistreeChatbotEngine || !chrome?.storage?.local) {
      console.warn('[Busistree Bot] Chatbot engine is unavailable.');
      return;
    }

    const seenIds = new Set();
    const chatbot = window.BusistreeChatbotEngine.create({
      api: sendBotApiRequest,
      storeName: 'our store',
      storage: {
        get: async phone => window.__bwaChatStates?.get(phone) || null,
        set: async (phone, state) => {
          if (!window.__bwaChatStates) window.__bwaChatStates = new Map();
          window.__bwaChatStates.set(phone, state);
        },
      },
    });

    function getMessageId(node) {
      const idNode = node.matches('[data-id]') ? node : node.closest('[data-id]') || node.querySelector('[data-id]');
      return idNode?.getAttribute('data-id') || '';
    }

    async function processInboundMessage(node) {
      if (!node?.isConnected || !node.classList.contains('message-in')) return;
      const id = getMessageId(node);
      if (!id || seenIds.has(id) || id.startsWith('true_')) return;
      seenIds.add(id);

      const messageText = getInboundText(node);
      const phone = getPhoneFromMessage(node);
      if (!messageText || !phone || /@g\.us/i.test(id)) return;

      const config = await chrome.storage.local.get(['enabled', 'autoReplyEnabled']);
      if (config.enabled === false || config.autoReplyEnabled !== true) return;
      const tag = contactTags.get(phone);
      if (['Family', 'Friends', 'Team', 'Blocked'].includes(tag)) return;
      if (pausedChats.has(phone)) return;

      try {
        const result = await chatbot.processMessage({
          phone,
          customerName: document.querySelector('#main header span[title]')?.getAttribute('title') || 'Customer',
          text: messageText,
        });
        if (!result?.replyText) return;
        await sendBotReply(phone, result.replyText);
      } catch (error) {
        console.error('[Busistree Bot] Could not process incoming chat:', error.message);
      }
    }

    async function sendBotReply(phone, text) {
      const jitter = 1500 + Math.floor(Math.random() * 2000);
      await new Promise(resolve => setTimeout(resolve, jitter));
      const controls = await chrome.storage.local.get(['enabled', 'autoReplyEnabled']);
      if (controls.enabled === false || controls.autoReplyEnabled !== true) return;
      if (pausedChats.has(phone)) return;
      if (getCurrentChatPhone() !== phone) {
        console.info('[Busistree Bot] Skipping auto-reply because this is no longer the active chat.');
        return;
      }

      const messageBox = getMessageBox();
      if (!messageBox || (messageBox.innerText || messageBox.textContent || '').trim()) {
        console.info('[Busistree Bot] Skipping auto-reply because the chat composer is unavailable or has a draft.');
        return;
      }

      const outgoingBefore = document.querySelectorAll('#main .message-out').length;
      messageBox.focus();
      document.execCommand('insertText', false, text);
      messageBox.dispatchEvent(new InputEvent('input', { bubbles: true, cancelable: true }));

      const sendButton = getSendButton();
      if (sendButton) {
        sendButton.click();
      } else {
        messageBox.dispatchEvent(new KeyboardEvent('keydown', {
          key: 'Enter', code: 'Enter', keyCode: 13, which: 13, bubbles: true, cancelable: true,
        }));
      }

      for (let attempt = 0; attempt < 10; attempt++) {
        await new Promise(resolve => setTimeout(resolve, 500));
        const sentBubbles = Array.from(document.querySelectorAll('#main .message-out'));
        const sent = sentBubbles.length > outgoingBefore && sentBubbles.some(bubble => {
          const content = bubble.querySelector('.selectable-text')?.innerText || bubble.innerText || '';
          return content.trim() === text.trim();
        });
        if (sent) {
          console.info('[Busistree Bot] Auto-reply sent.');
          return;
        }
      }
      console.error('[Busistree Bot] WhatsApp did not confirm delivery of the auto-reply.');
    }

    const initialMessages = document.querySelectorAll('#main .message-in');
    initialMessages.forEach(node => {
      const id = getMessageId(node);
      if (id) seenIds.add(id);
    });

    const observer = new MutationObserver(records => {
      for (const record of records) {
        for (const addedNode of record.addedNodes) {
          if (addedNode.nodeType !== Node.ELEMENT_NODE) continue;
          const inboundNodes = [];
          if (addedNode.matches?.('#main .message-in')) inboundNodes.push(addedNode);
          addedNode.querySelectorAll?.('#main .message-in, .message-in').forEach(node => inboundNodes.push(node));
          for (const node of inboundNodes) setTimeout(() => processInboundMessage(node), 300);
        }
      }
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  // Attempt send action
  function attemptSend() {
    if (isHandled) return;

    // First cancel any voice recording draft that might be active
    if (cancelVoiceRecordingIfActive()) {
      console.log('[Busistree Bot] Cancelling voice note draft, pausing 1s...');
      return;
    }

    // Check for invalid phone or error popups
    if (checkDialogsAndIntermediates()) return;

    const messageBox = getMessageBox();
    if (!messageBox) {
      attempts++;
      if (attempts >= maxAttempts) {
        reportResult(false, 'Timeout waiting for WhatsApp Web chat to load. Ensure WhatsApp is logged in.');
      }
      return;
    }

    const currentBoxText = (messageBox.innerText || messageBox.textContent || '').trim();

    // If message box is empty but we have targetText from URL, inject it!
    if (!currentBoxText && targetText && !textInjected) {
      textInjected = true;
      console.log('[Busistree Bot] Injecting message text into compose box...');
      messageBox.focus();
      document.execCommand('insertText', false, targetText);
      messageBox.dispatchEvent(new InputEvent('input', { bubbles: true, cancelable: true }));
      messageBox.dispatchEvent(new Event('change', { bubbles: true }));
      return; // Give React a moment to render the send button
    }

    // Find the send button (strictly verified not to be voice button)
    const sendBtn = getSendButton();

    if (sendBtn) {
      console.log('[Busistree Bot] Real Send button found! Clicking send...');
      messageBox.focus();

      // Click the Send button
      sendBtn.click();

      // Also dispatch Enter key as companion
      messageBox.dispatchEvent(
        new KeyboardEvent('keydown', {
          key: 'Enter',
          code: 'Enter',
          keyCode: 13,
          which: 13,
          bubbles: true,
          cancelable: true,
        })
      );

      // Verify the message was dispatched by confirming compose box cleared
      let verifyCount = 0;
      const verifyTimer = setInterval(() => {
        verifyCount++;
        const remainingText = (messageBox.innerText || messageBox.textContent || '').trim();
        const sendBtnStillThere = getSendButton();

        // When text clears and send button disappears, message was sent!
        if (!remainingText || !sendBtnStillThere || verifyCount >= 5) {
          clearInterval(verifyTimer);
          console.log('[Busistree Bot] Message dispatched successfully.');
          reportResult(true);
        }
      }, 500);

      return;
    }

    // Fallback: If text is in message box and no send button appeared yet, press Enter
    if (currentBoxText.length > 0) {
      console.log('[Busistree Bot] Text present in compose box. Dispatching Enter key...');
      messageBox.focus();
      messageBox.dispatchEvent(
        new KeyboardEvent('keydown', {
          key: 'Enter',
          code: 'Enter',
          keyCode: 13,
          which: 13,
          bubbles: true,
          cancelable: true,
        })
      );

      setTimeout(() => {
        const remainingText = (messageBox.innerText || messageBox.textContent || '').trim();
        if (!remainingText) {
          reportResult(true);
        }
      }, 1500);
      return;
    }

    attempts++;
    if (attempts >= maxAttempts) {
      reportResult(false, 'Timeout waiting for send button. Ensure chat is loaded.');
    }
  }

  const checkInterval = setInterval(attemptSend, 1000);
  setTimeout(attemptSend, 1000);

  // --------------------------------------------------------------------------
  // Floating WhatsApp Web CRM Dock
  // --------------------------------------------------------------------------
  function initCRMDock() {
    console.log('[Busistree CRM] Initializing WhatsApp Web Companion Dock...');
    if (document.getElementById('busistree-crm-dock')) return;

    const dock = document.createElement('div');
    dock.id = 'busistree-crm-dock';
    dock.style.cssText = `
      position: fixed;
      top: 60px;
      right: 16px;
      width: 280px;
      background: #111827;
      border: 1px solid #374151;
      border-radius: 12px;
      box-shadow: 0 10px 25px rgba(0,0,0,0.5);
      z-index: 999999;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      color: #f3f4f6;
      font-size: 13px;
      overflow: hidden;
      transition: transform 0.2s ease, opacity 0.2s ease;
    `;

    dock.innerHTML = `
      <div style="background: #1f2937; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #374151; cursor: pointer;" id="bwa-crm-header">
        <div style="display: flex; align-items: center; gap: 8px;">
          <span style="font-size: 16px;">🤖</span>
          <strong style="color: #25d366; font-size: 13px;">Busistree CRM</strong>
        </div>
        <span id="bwa-dock-toggle" style="color: #9ca3af; font-size: 11px;">▼ Minimize</span>
      </div>

      <div id="bwa-crm-body" style="padding: 14px;">
        <div style="margin-bottom: 10px;">
          <div style="font-size: 11px; color: #9ca3af; text-transform: uppercase;">Active Customer</div>
          <div id="bwa-crm-contact-name" style="font-size: 14px; font-weight: 700; color: #fff;">Open a chat</div>
          <div id="bwa-crm-contact-phone" style="font-size: 11px; color: #38bdf8; font-family: monospace;">---</div>
        </div>

        <!-- Takeover / Pause Button -->
        <div style="margin-bottom: 12px;">
          <button id="bwa-btn-takeover" style="width: 100%; padding: 7px; background: #374151; border: 1px solid #4b5563; color: #f3f4f6; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; transition: background 0.2s;">
            ⏸️ Takeover / Pause Bot
          </button>
        </div>

        <!-- Audience Tagging -->
        <div style="margin-bottom: 12px;">
          <div style="font-size: 11px; color: #9ca3af; margin-bottom: 6px;">Audience Tag:</div>
          <div style="display: flex; gap: 4px; flex-wrap: wrap;">
            <button class="bwa-tag-pill" data-tag="Customer" style="padding: 3px 8px; border-radius: 4px; font-size: 10px; font-weight: 600; border: 1px solid #22c55e; background: rgba(34,197,94,0.15); color: #22c55e; cursor: pointer;">Customer</button>
            <button class="bwa-tag-pill" data-tag="VIP" style="padding: 3px 8px; border-radius: 4px; font-size: 10px; font-weight: 600; border: 1px solid #f59e0b; background: rgba(245,158,11,0.15); color: #f59e0b; cursor: pointer;">VIP</button>
            <button class="bwa-tag-pill" data-tag="Family" style="padding: 3px 8px; border-radius: 4px; font-size: 10px; font-weight: 600; border: 1px solid #ec4899; background: rgba(236,72,153,0.15); color: #ec4899; cursor: pointer;">Family</button>
            <button class="bwa-tag-pill" data-tag="Blocked" style="padding: 3px 8px; border-radius: 4px; font-size: 10px; font-weight: 600; border: 1px solid #ef4444; background: rgba(239,68,68,0.15); color: #ef4444; cursor: pointer;">Blocked</button>
          </div>
        </div>

        <!-- Quick Reply Insert -->
        <div style="border-top: 1px solid #374151; padding-top: 10px;">
          <div style="font-size: 11px; color: #9ca3af; margin-bottom: 6px;">Insert Quick Reply:</div>
          <div style="display: flex; flex-direction: column; gap: 6px;">
            <button class="bwa-btn-reply" data-reply="Assalam-o-Alaikum! Your order has been confirmed and is being packed. 🚀" style="text-align: left; background: #1f2937; border: 1px solid #374151; color: #cbd5e1; border-radius: 6px; padding: 6px 8px; font-size: 11px; cursor: pointer;">
              ✅ Confirm Order
            </button>
            <button class="bwa-btn-reply" data-reply="Assalam-o-Alaikum! Your parcel is on the way. Tracking details: " style="text-align: left; background: #1f2937; border: 1px solid #374151; color: #cbd5e1; border-radius: 6px; padding: 6px 8px; font-size: 11px; cursor: pointer;">
              📦 Tracking Details
            </button>
            <button class="bwa-btn-reply" data-reply="Assalam-o-Alaikum! Please share your delivery address and contact number to proceed. 📍" style="text-align: left; background: #1f2937; border: 1px solid #374151; color: #cbd5e1; border-radius: 6px; padding: 6px 8px; font-size: 11px; cursor: pointer;">
              📍 Request Address
            </button>
          </div>
        </div>
      </div>
    `;

    document.body.appendChild(dock);

    // Toggle minimize
    const header = document.getElementById('bwa-crm-header');
    const body = document.getElementById('bwa-crm-body');
    const toggleLabel = document.getElementById('bwa-dock-toggle');
    let isMinimized = false;

    header.addEventListener('click', () => {
      isMinimized = !isMinimized;
      body.style.display = isMinimized ? 'none' : 'block';
      toggleLabel.textContent = isMinimized ? '▲ Expand' : '▼ Minimize';
    });

    // Detect active chat
    setInterval(() => {
      const headerTitle = document.querySelector('#main header span[title]') || document.querySelector('header span[title]');
      if (headerTitle) {
        const title = headerTitle.getAttribute('title') || headerTitle.innerText || '';
        const nameEl = document.getElementById('bwa-crm-contact-name');
        const phoneEl = document.getElementById('bwa-crm-contact-phone');
        if (nameEl && nameEl.innerText !== title) {
          nameEl.innerText = title;
          let digits = title.replace(/\D/g, '');
          phoneEl.innerText = digits.length >= 10 ? `+${digits}` : 'Contact';
        }
      }
    }, 2000);

    // Insert quick reply safely
    dock.querySelectorAll('.bwa-btn-reply').forEach(btn => {
      btn.addEventListener('click', () => {
        const text = btn.getAttribute('data-reply');
        const box = getMessageBox();
        if (box) {
          box.focus();
          document.execCommand('insertText', false, text);
          box.dispatchEvent(new InputEvent('input', { bubbles: true, cancelable: true }));
        }
      });
    });

    // Takeover toggle
    const btnTakeover = document.getElementById('bwa-btn-takeover');
    let isPausedThisChat = false;
    btnTakeover.addEventListener('click', () => {
      const phone = getCurrentChatPhone();
      if (!phone) {
        console.warn('[Busistree CRM] Open a direct chat with a phone number to change its bot status.');
        return;
      }
      isPausedThisChat = !pausedChats.has(phone);
      if (isPausedThisChat) pausedChats.add(phone);
      else pausedChats.delete(phone);
      if (isPausedThisChat) {
        btnTakeover.innerText = '🟢 Resume Bot';
        btnTakeover.style.background = '#065f46';
        btnTakeover.style.borderColor = '#059669';
      } else {
        btnTakeover.innerText = '⏸️ Takeover / Pause Bot';
        btnTakeover.style.background = '#374151';
        btnTakeover.style.borderColor = '#4b5563';
      }
    });

    // Tag pills
    dock.querySelectorAll('.bwa-tag-pill').forEach(pill => {
      pill.addEventListener('click', () => {
        const tag = pill.getAttribute('data-tag');
        const phone = getCurrentChatPhone();
        if (!phone) {
          console.warn('[Busistree CRM] Open a direct chat with a phone number before assigning a tag.');
          return;
        }
        dock.querySelectorAll('.bwa-tag-pill').forEach(p => p.style.opacity = '0.5');
        pill.style.opacity = '1';
        contactTags.set(phone, tag);
        console.log(`[Busistree CRM] Tagged contact as ${tag}`);
      });
    });
  }
})();
