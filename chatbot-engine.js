(function (root, factory) {
  const engine = factory();
  if (typeof module !== 'undefined' && module.exports) {
    module.exports = engine;
  } else {
    root.BusistreeChatbotEngine = engine;
  }
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  const MAX_PRODUCTS = 5;
  const YES = /^(yes|yeah|yep|ok|okay|confirm|place order|ji|jee|haan|han|جی|ہاں)$/i;
  const NO = /^(no|nope|cancel|stop|nahi|nahin|نہیں)$/i;
  const CURRENCY_SYMBOLS = { PKR: 'Rs.', INR: '₹', USD: '$', GBP: '£', EUR: '€' };

  function cleanPhone(value) {
    return String(value || '').replace(/\D/g, '').slice(0, 15);
  }

  function normalize(value) {
    return String(value || '')
      .toLowerCase()
      .replace(/[^\p{L}\p{N}\s]/gu, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function formatPrice(product, currency = 'PKR') {
    const price = Number(product?.sale_price || product?.price);
    if (!Number.isFinite(price)) return 'price unavailable';
    const formatted = price.toLocaleString('en', { maximumFractionDigits: 2 });
    return `${CURRENCY_SYMBOLS[currency] || currency} ${formatted}`;
  }

  function productStock(product) {
    const stock = product?.stock_status || product?.stock;
    if (stock === 'instock' || product?.in_stock === true) return 'In stock';
    if (stock === 'onbackorder') return 'Available on backorder';
    return 'Out of stock';
  }

  function summarizeProduct(product, index, currency) {
    const stock = productStock(product);
    const url = product?.permalink ? `\n   ${product.permalink}` : '';
    return `${index + 1}. *${product.name || 'Product'}* — ${formatPrice(product, product.currency || currency)} · ${stock}${url}`;
  }

  function extractOrderNumber(text) {
    const match = String(text || '').match(/(?:#|order\s*#?\s*)(\d{1,10})\b/i) || String(text || '').match(/^(\d{3,10})$/);
    return match ? match[1] : '';
  }

  function extractProductQuery(text) {
    const safeText = String(text || '')
      .replace(/\b[\w.+-]+@[\w.-]+\.[A-Z]{2,}\b/gi, ' ')
      .replace(/\+?\d[\d\s().-]{7,}\d/g, ' ');
    const ignored = new Set([
      'a', 'an', 'and', 'any', 'are', 'about', 'can', 'cheapest', 'cheaper', 'do', 'does', 'for',
      'find', 'get', 'give', 'have', 'i', 'in', 'is', 'it', 'me', 'my', 'of', 'on', 'please',
      'show', 'the', 'there', 'to', 'want', 'what', 'which', 'with', 'you', 'your',
      'how', 'much', 'price', 'prices', 'cost', 'costs', 'available', 'availability', 'stock',
      'this', 'that', 'product', 'products', 'item', 'items', 'tell', 'about',
      'buy', 'order', 'purchase', 'looking', 'need', 'sell', 'selling', 'best', 'popular',
      'sasta', 'sasti', 'sab', 'se', 'mujhe', 'chahiye', 'dikhao', 'batao', 'hai', 'hain',
    ]);
    return normalize(safeText)
      .split(' ')
      .filter(token => token.length > 1 && !ignored.has(token) && !/^\d+$/.test(token))
      .slice(0, 6)
      .join(' ')
      .slice(0, 80);
  }

  function classifyInsight(text) {
    if (/\b(cheapest|lowest price|most affordable|sasta|sasti|kam price|کم قیمت|سستا|سستی)\b/i.test(text)) return 'cheapest';
    if (/\b(best.?sellers?|top.?sellers?|popular|trending|most bought|most sold|mashhoor|مشہور)\b/i.test(text)) return 'top_seller';
    if (/\b(on sale|sale|discount|offer|deal|bachat|سیل|ڈسکاؤنٹ|آفر)\b/i.test(text)) return 'on_sale';
    if (/\b(new arrivals?|latest|new collection|naye|naya collection|نیا|نئے)\b/i.test(text)) return 'new_arrivals';
    if (/\b(most expensive|highest price|premium|mehnga|mehngi|مہنگا|مہنگی)\b/i.test(text)) return 'most_expensive';
    return null;
  }

  function isGreeting(text) {
    return /^(hi|hello|hey|salam|assalam|assalamu alaikum|assalam o alaikum|السلام علیکم|سلام)[!. ]*$/i.test(text);
  }

  function create(options = {}) {
    const api = options.api;
    const storage = options.storage || {
      get: async () => null,
      set: async () => {},
    };
    const botName = options.storeName || 'our store';

    if (typeof api !== 'function') {
      throw new TypeError('Chatbot requires a verified store API adapter.');
    }

    async function processMessage({ phone, customerName, text } = {}) {
      const clean = cleanPhone(phone);
      const message = String(text || '').trim();
      if (!clean || !message) return { handled: false, reason: 'missing_phone_or_text' };

      const state = (await storage.get(clean)) || {};
      const lowered = normalize(message);
      const name = String(customerName || state.order?.name || 'there').trim();
      const reply = async (replyText, updates = {}) => {
        await storage.set(clean, { ...state, ...updates, lastUpdated: Date.now() });
        return { handled: true, replyText };
      };

      if (state.paused) {
        if (/^(resume|resume bot|start bot|bot on|unpause)$/i.test(lowered)) {
          return reply('Automated replies are resumed for this chat. How can I help you?', { paused: false });
        }
        return { handled: false, dropped: true, reason: 'human_takeover' };
      }

      if (/\b(agent|human|person|representative|live support|speak to someone)\b|ایجنٹ|انسان/i.test(message)) {
        await storage.set(clean, { ...state, paused: true, lastUpdated: Date.now() });
        return {
          handled: true,
          triggerTakeover: true,
          replyText: 'I have paused automated replies for this chat and flagged it for a human representative. Reply *resume bot* if you want to restart automated help.',
        };
      }

      if (/^(resume|resume bot|start bot|bot on|unpause)$/i.test(lowered)) {
        return reply('Automated replies are resumed for this chat. How can I help you?', { paused: false });
      }

      if (/^(cancel|stop|quit|menu|help|cancel order|cancelar)$/i.test(lowered)) {
        await storage.set(clean, {});
        if (/\b(cancel|stop|quit|cancel order)\b/i.test(lowered)) {
          return { handled: true, replyText: 'Okay, I cancelled the current chat order. No order was placed. Send a product name or ask me to show categories whenever you are ready.' };
        }
        return { handled: true, replyText: `Welcome to *${botName}*! I can help you find products, compare best sellers and prices, explain store policies, check an order, or place a Cash on Delivery order. What are you looking for?` };
      }

      if (state.order?.step === 'awaiting_name') {
        if (message.length > 100) return reply('Please send the name for the order (up to 100 characters).');
        return reply(`Thanks. Please send your complete delivery address for *${state.order.product.name}*.`, {
          order: { ...state.order, step: 'awaiting_address', name: message },
        });
      }

      if (state.order?.step === 'awaiting_address') {
        if (message.length < 6 || message.length > 250) return reply('Please send a more complete delivery address (6 to 250 characters).');
        return reply('Please send your delivery *city*. No order will be placed until you review and confirm the summary.', {
          order: { ...state.order, step: 'awaiting_city', address: message },
        });
      }

      if (state.order?.step === 'awaiting_city') {
        if (message.length < 2 || message.length > 80) return reply('Please send a valid city name.');
        const order = { ...state.order, step: 'awaiting_confirmation', city: message };
        const store = await getStoreInfo();
        const currency = store.currency || order.product.currency || 'PKR';
        const unitPrice = Number(order.product.sale_price || order.product.price);
        const freeThreshold = Number(store.policies?.free_threshold || store.free_shipping_threshold || 0);
        const shippingFee = Number(store.policies?.standard_fee || 0);
        const shipping = freeThreshold > 0 && unitPrice * order.quantity >= freeThreshold ? 0 : shippingFee;
        order.shippingTotal = shipping;
        order.idempotencyKey = order.idempotencyKey || `wa_${clean}_${order.product.id}_${order.startedAt}`;
        const total = unitPrice * order.quantity + shipping;
        order.currency = currency;
        order.estimatedTotal = total;
        return reply(
          `Please review your order:\n\n*${order.product.name}* × ${order.quantity}\nItems: ${formatPrice({ price: unitPrice }, currency)} × ${order.quantity}\nDelivery: ${formatPrice({ price: shipping }, currency)}\n*Estimated total: ${formatPrice({ price: total }, currency)}*\nName: ${order.name}\nPhone: +${clean}\nAddress: ${order.address}, ${order.city}\n\nReply *YES* to place this COD order on the store website, or *NO* to cancel.`,
          { order },
        );
      }

      if (state.order?.step === 'awaiting_confirmation') {
        if (YES.test(lowered)) {
          const order = { ...state.order, step: 'submitting' };
          await storage.set(clean, { ...state, order, lastUpdated: Date.now() });
          try {
            const result = await api('/create-order', {
              method: 'POST',
              body: {
                source: 'whatsapp',
                idempotency_key: order.idempotencyKey,
                customer_name: order.name,
                phone: clean,
                wa_phone: clean,
                address: order.address,
                city: order.city,
                shipping_total: order.shippingTotal,
                currency: order.currency,
                estimated_total: order.estimatedTotal,
                payment_method: 'cod',
                items: [{
                  product_id: order.product.id,
                  product_name: order.product.name,
                  product_price: order.product.sale_price || order.product.price,
                  quantity: order.quantity,
                }],
              },
            });
            if (result?.cancelled) {
              await storage.set(clean, {});
              return { handled: true, replyText: 'No order was placed because the app user cancelled final approval.' };
            }
            if (!result?.success) throw new Error('Store did not confirm the order.');
            await storage.set(clean, {});
            return {
              handled: true,
              replyText: `Your order *#${result.order_number || result.order_id}* has been placed on the store website. ${order.product.name} × ${order.quantity}; total ${formatPrice({ price: result.total }, result.currency || 'PKR')} (Cash on Delivery). Thank you!`,
            };
          } catch (error) {
            await storage.set(clean, { ...state, order: { ...order, step: 'awaiting_confirmation' }, lastUpdated: Date.now() });
            return {
              handled: true,
              replyText: 'I could not confirm the order with the store right now, so I have not told you it was placed. Please try replying *YES* again in a moment, or reply *Agent* for help.',
              error: error.message,
            };
          }
        }
        if (NO.test(lowered)) {
          await storage.set(clean, {});
          return { handled: true, replyText: 'Order cancelled. No order was placed.' };
        }
        return reply('Please reply *YES* to place this order on the website, or *NO* to cancel it.');
      }

      if (state.order?.step === 'submitting') {
        return { handled: true, replyText: 'Your order request is still being checked by the store. Please wait a moment before sending another confirmation.' };
      }

      if (state.order?.step === 'awaiting_quantity') {
        const quantity = Number(lowered);
        if (!Number.isInteger(quantity) || quantity < 1 || quantity > 20) return reply('Please enter a whole-number quantity from 1 to 20.');
        const order = { ...state.order, step: 'awaiting_name', quantity };
        await storage.set(clean, { ...state, order, lastUpdated: Date.now() });
        return { handled: true, replyText: 'Who should I put this order under? Please send the customer’s full name.' };
      }

      if (isGreeting(lowered)) {
        return reply(`Assalam-o-Alaikum *${name}*! Welcome to *${botName}*. 👋\n\nAsk me to find a product, show categories, list best sellers or cheapest items, check delivery/return policies, track an order, or place an order.`);
      }

      const orderNumber = extractOrderNumber(message);
      if (orderNumber && /\b(order|track|status|delivery|where|mera|my|kahan|کہاں|آرڈر)\b/i.test(message)) {
        const result = await api(`/order-status?order_number=${encodeURIComponent(orderNumber)}&phone=${encodeURIComponent(clean)}`);
        if (!result?.success || !result.order) {
          return reply(result?.message || `I could not verify order #${orderNumber} for this WhatsApp number. Please check the order number or ask for a support agent.`);
        }
        const order = result.order;
        return reply(`Order *#${order.order_number || order.id}* is *${order.status_name || order.status}*.\nItems: ${order.items_summary || 'See your order details'}\nTotal: ${order.currency || 'PKR'} ${order.total}${order.tracking_url ? `\nTracking: ${order.tracking_url}` : ''}`);
      }

      if (/\b(track|status|my orders|my order|mera order|mere order|آرڈر اسٹیٹس)\b/i.test(message)) {
        const result = await api(`/customer-orders?phone=${encodeURIComponent(clean)}`);
        const orders = Array.isArray(result?.orders) ? result.orders : [];
        if (!orders.length) return reply('I could not find a recent order for this WhatsApp number. Send your order number (for example, #1234) and I will check it.');
        return reply(`I found these recent orders for this number:\n${orders.slice(0, 5).map((order, index) => `${index + 1}. *#${order.order_number || order.id}* — ${order.status_name || order.status}`).join('\n')}\n\nSend an order number for more details.`);
      }

      if (/\b(categories|category|collections|what do you sell|catalog|catalogue)\b|کیٹیگری|زمرے/i.test(message)) {
        const store = await getStoreInfo();
        const categories = await getCategories(store);
        if (!categories.length) return reply('I could not load the store categories just now. Please try again shortly.');
        return reply(`Here are the available categories:\n${categories.map((category, index) => `${index + 1}. ${category.name}`).join('\n')}\n\nSend a category name to see products.`);
      }

      const insightKind = classifyInsight(message);
      if (insightKind) {
        const store = await getStoreInfo();
        const category = await findCategory(message, store);
        const query = category ? `?category=${encodeURIComponent(category.slug)}` : '';
        const result = await api(`/insights${query}`);
        const resultKey = {
          cheapest: 'cheapest_products',
          top_seller: 'top_sellers',
          on_sale: 'on_sale_products',
          new_arrivals: 'new_arrivals',
          most_expensive: 'most_expensive_products',
        }[insightKind];
        const products = Array.isArray(result?.[resultKey]) ? result[resultKey] : [];
        if (!products.length) return reply(`I could not find verified ${insightKind.replace('_', ' ')} products${category ? ` in ${category.name}` : ''} right now.`);
        const label = insightKind.replace('_', ' ');
        const topProducts = products.slice(0, MAX_PRODUCTS);
        return reply(`Here are the ${category ? `${category.name} ` : ''}${label} products from the live store:\n${topProducts.map((product, index) => summarizeProduct(product, index, result.currency || 'PKR')).join('\n')}\n\nReply with a product name or number to see details, or say *order* to start.`, { products: topProducts });
      }

      if (state.products?.length) {
        const selection = resolveProduct(message, state.products);
        if (selection) {
          return reply(
            `${summarizeProduct(selection, 0, selection.currency || 'PKR')}${selection.short_description ? `\n${selection.short_description}` : ''}\n\nTo purchase it, reply *order ${selection.name}*.`,
            { selectedProduct: selection },
          );
        }
      }

      if (/^(order|buy|purchase|place order|i want to order|khareedna|خریدنا)$/i.test(lowered)) {
        const product = state.selectedProduct;
        if (!product) return reply('Which product would you like to order? Send its name or search for it first.');
        if (productStock(product) === 'Out of stock') return reply(`Sorry, *${product.name}* is out of stock. Choose another in-stock product and I will help you.`);
        const order = { step: 'awaiting_quantity', product, startedAt: Date.now() };
        await storage.set(clean, { ...state, order, lastUpdated: Date.now() });
        return { handled: true, replyText: `How many *${product.name}* would you like? Reply with a quantity from 1 to 20.` };
      }

      const policyReply = await getPolicyReply(message);
      if (policyReply) return reply(policyReply);

      const faqReply = await getFaqReply(message);
      if (faqReply) return reply(faqReply);

      const directBuy = message.match(/^(?:i want to buy|i want|buy|order|purchase|please order|mujhe chahiye|khareedna hai)\s+(.+)$/i);
      const searchText = directBuy ? directBuy[1].trim() : message;
      const safeSearchText = extractProductQuery(searchText);
      const store = await getStoreInfo();
      const category = await findCategory(safeSearchText, store);
      if (!category && !safeSearchText) {
        return reply('Which product or category would you like to see? Please send just the item name (for example, *kurta* or *shoes*).');
      }
      const query = category ? `?category=${encodeURIComponent(category.slug)}&limit=${MAX_PRODUCTS}` : `?query=${encodeURIComponent(safeSearchText)}&limit=${MAX_PRODUCTS}`;
      const search = await api(`/search-products${query}`);
      const products = (Array.isArray(search?.products) ? search.products : [])
        .filter(product => product && product.name)
        .slice(0, MAX_PRODUCTS);
      if (products.length) {
        const oneStrongMatch = products.length === 1 && directBuy;
        if (oneStrongMatch) {
          const product = products[0];
          if (productStock(product) === 'Out of stock') return reply(`Sorry, *${product.name}* is out of stock. Would you like to see similar products?`);
          await storage.set(clean, { ...state, selectedProduct: product, lastUpdated: Date.now() });
          return { handled: true, replyText: `${summarizeProduct(product, 0, store.currency || 'PKR')}\n\nWould you like to order it? Reply *order* to continue.` };
        }
        return reply(
          `Here are products matching *${searchText}* in the live catalog:\n${products.map((product, index) => summarizeProduct(product, index, store.currency || 'PKR')).join('\n')}\n\nReply with a number or product name to see details.`,
          { products },
        );
      }

      return reply(`I can help with live product searches, categories, best sellers, cheapest items, store policies, order tracking, and website orders. I don't have verified information for that question yet. Could you tell me the product or topic, or reply *Agent* for a person?`);
    }

    async function getStoreInfo() {
      return api('/store-info');
    }

    async function getCategories(store) {
      if (Array.isArray(store.top_categories) && store.top_categories.length) return store.top_categories;
      const insights = await api('/insights');
      return Array.isArray(insights.top_categories) ? insights.top_categories : [];
    }

    async function findCategory(query, store) {
      const categories = await getCategories(store);
      const normalizedQuery = normalize(query);
      return categories.find(category => {
        const name = normalize(category.name);
        const slug = normalize(String(category.slug || '').replace(/-/g, ' '));
        return name && (normalizedQuery.includes(name) || normalizedQuery.includes(slug));
      }) || null;
    }

    async function getPolicyReply(text) {
      let topic = '';
      if (/\b(return|refund|exchange|replace|damage|defect)\b|واپس|ریفنڈ/i.test(text)) topic = 'returns';
      else if (/\b(delivery|shipping|ship|deliver|courier|charges|fee)\b|ڈیلیوری|ترسیل/i.test(text)) topic = 'shipping';
      else if (/\b(payment|pay|cash|cod|bank transfer)\b|ادائیگی/i.test(text)) topic = 'payment';
      else if (/\b(hours|open|close|timing|when are you open)\b|اوقات/i.test(text)) topic = 'hours';
      else if (/\b(contact|phone number|email|address|location)\b|رابطہ/i.test(text)) topic = 'contact';
      if (!topic) return null;

      const info = await getStoreInfo();
      if (topic === 'returns') return info.policies?.return_refund || 'I could not load the verified return policy right now.';
      if (topic === 'shipping') return info.policies?.shipping || 'I could not load verified delivery information right now.';
      if (topic === 'payment') return info.policies?.payment_methods || 'I could not load verified payment methods right now.';
      if (topic === 'hours') return info.store_hours || 'Store hours are not available right now.';
      const contact = info.contact || {};
      const parts = [contact.phone && `Phone: ${contact.phone}`, contact.whatsapp && `WhatsApp: ${contact.whatsapp}`, contact.email && `Email: ${contact.email}`, contact.address && `Address: ${contact.address}`].filter(Boolean);
      return parts.length ? parts.join('\n') : 'Verified store contact information is not available right now.';
    }

    async function getFaqReply(text) {
      const info = await getStoreInfo();
      const faqs = Array.isArray(info.faqs) ? info.faqs : [];
      const queryTokens = normalize(text).split(' ').filter(token => token.length > 2);
      let best = null;
      let bestScore = 0;
      for (const faq of faqs) {
        const faqTokens = normalize(`${faq.question || ''} ${faq.answer || ''}`).split(' ').filter(token => token.length > 2);
        const score = queryTokens.filter(token => faqTokens.includes(token)).length;
        if (score > bestScore) {
          best = faq;
          bestScore = score;
        }
      }
      return bestScore >= 2 && best?.answer ? String(best.answer) : null;
    }

    return { processMessage };
  }

  function resolveProduct(text, products) {
    const match = String(text || '').match(/^(?:number\s*)?(\d{1,2})$/i);
    if (match) return products[Number(match[1]) - 1] || null;
    const normalized = normalize(text);
    return products.find(product => normalize(product.name) === normalized)
      || products.find(product => normalize(product.name).includes(normalized) || normalized.includes(normalize(product.name)))
      || null;
  }

  return { create, cleanPhone, formatPrice, classifyInsight, extractOrderNumber, extractProductQuery };
});
