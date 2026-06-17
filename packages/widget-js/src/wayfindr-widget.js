(function (root, factory) {
  var api = factory(root);

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  }

  if (root) {
    root.Wayfindr = api;
  }
})(typeof window !== 'undefined' ? window : globalThis, function (root) {
  'use strict';

  var VERSION = '0.0.0';
  var STYLE_ID = 'wayfindr-widget-styles';
  var MESSAGE_SEND_ERROR = 'Message could not be sent. Your text is still here so you can try again.';
  var MESSAGE_REFRESH_ERROR = 'Messages could not be refreshed. Your current chat is still visible.';
  var DEFAULT_COBROWSE_PAYLOAD_BUDGET = {
    mutationBatchMaxBytes: 60000,
    mutationQueueMaxRecords: 250,
  };
  var MESSAGE_GROUP_WINDOW_MS = 5 * 60 * 1000;
  var AGENT_TYPING_FRESH_MS = 20 * 1000;
  var DEFAULT_MASK_SELECTORS = [
    'input[type="password"]',
    'input[type="hidden"]',
    '[data-wayfindr-mask]',
    '[data-wayfindr-private]',
    '[data-secret]',
  ];
  var DEFAULT_REMOVE_SELECTORS = [
    'script',
    'style',
    'noscript',
    'iframe',
    'canvas',
    'svg',
    '.wayfindr-widget',
  ];
  var SAFE_MUTATION_ATTRIBUTES = [
    'aria-current',
    'aria-expanded',
    'aria-hidden',
    'checked',
    'class',
    'disabled',
    'hidden',
    'selected',
  ];
  var SENSITIVE_FIELD_TERMS = [
    'password',
    'passwd',
    'pwd',
    'passcode',
    'secret',
    'token',
    'api key',
    'apikey',
    'auth',
    'authorization',
    'one time code',
    'otp',
    'ssn',
    'social security',
    'tax id',
    'ein',
    'sin',
    'national id',
    'credit card',
    'card number',
    'cardnumber',
    'cc number',
    'ccnumber',
    'cvc',
    'cvv',
    'security code',
    'expiration',
    'expiry',
    'routing number',
    'account number',
    'bank account',
    'iban',
    'sort code',
    'username',
    'user name',
    'login',
    'email',
    'e mail',
    'phone',
    'telephone',
    'address',
    'postal code',
    'zip',
    'birthdate',
    'date of birth',
    'dob',
  ];
  var SENSITIVE_FIELD_ATTRIBUTES = [
    'id',
    'name',
    'autocomplete',
    'aria-label',
    'placeholder',
    'data-field',
    'data-wayfindr-field',
    'data-testid',
    'data-test',
    'data-cy',
  ];

  function createClient(options) {
    options = options || {};

    var apiBaseUrl = normalizeApiBaseUrl(options.apiBaseUrl || '');
    var sitePublicKey = options.sitePublicKey;
    var anonymousId = options.anonymousId;
    var visitorExternalId = normalizeVisitorExternalId(options.visitorExternalId);
    var fetcher = options.fetch || (root && root.fetch ? root.fetch.bind(root) : null);
    var hasStorageOption = Object.prototype.hasOwnProperty.call(options, 'storage');
    var storage = hasStorageOption ? options.storage : null;
    var visitorToken = options.visitorToken || null;
    var realtime = resolveRealtime(options, fetcher);
    var maskSelectors = [];

    if (!apiBaseUrl) {
      throw new Error('Wayfindr requires an apiBaseUrl option.');
    }

    if (!sitePublicKey) {
      throw new Error('Wayfindr requires a sitePublicKey option.');
    }

    if (!hasStorageOption) {
      storage = defaultStorage();
    }

    if (!anonymousId) {
      anonymousId = resolveAnonymousId({
        sitePublicKey: sitePublicKey,
        storage: storage,
      });
    }

    if (!visitorToken) {
      visitorToken = storageGet(storage, visitorTokenStorageKey(sitePublicKey));
    }

    if (!fetcher) {
      throw new Error('Wayfindr requires fetch support.');
    }

    return {
      anonymousId: anonymousId,
      sitePublicKey: sitePublicKey,
      bootstrap: function (pageUrl, context) {
        return postJson(fetcher, apiBaseUrl + '/api/widget/bootstrap', withVisitorContext({
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          page_url: pageUrl || null,
        }, context, visitorExternalId)).then(function (result) {
          var token = result && result.visitor ? result.visitor.token : null;

          if (token) {
            visitorToken = token;
            storageSet(storage, visitorTokenStorageKey(sitePublicKey), token);
          }

          maskSelectors = siteMaskSelectors(result);

          return result;
        });
      },
      startConversation: function (body, details) {
        details = details || {};
        var externalId = normalizeVisitorExternalId(details.visitorExternalId) || visitorExternalId;

        return postJson(fetcher, apiBaseUrl + '/api/conversations', withVisitorContext({
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          visitor_token: requireVisitorToken(visitorToken),
          subject: details.subject || summarize(body),
          page_url: details.pageUrl || null,
        }, details.context, externalId));
      },
      sendMessage: function (supportCode, body) {
        return postJson(fetcher, apiBaseUrl + '/api/conversations/' + encodeURIComponent(supportCode) + '/messages', {
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          visitor_token: requireVisitorToken(visitorToken),
          body: body,
        });
      },
      reportTyping: function (supportCode, isTyping) {
        return postJson(fetcher, apiBaseUrl + '/api/conversations/' + encodeURIComponent(supportCode) + '/typing', {
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          visitor_token: requireVisitorToken(visitorToken),
          is_typing: Boolean(isTyping),
        });
      },
      fetchMessages: function (supportCode, details) {
        details = details || {};
        var params = {
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          visitor_token: requireVisitorToken(visitorToken),
        };

        if (details.markSeen) {
          params.mark_seen = '1';
        }

        return getJson(fetcher, apiBaseUrl + '/api/conversations/' + encodeURIComponent(supportCode) + '/messages?' + toQueryString(params));
      },
      fetchCobrowseStatus: function (supportCode) {
        return getJson(fetcher, apiBaseUrl + '/api/conversations/' + encodeURIComponent(supportCode) + '/cobrowse?' + toQueryString({
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          visitor_token: requireVisitorToken(visitorToken),
        }));
      },
      setCobrowseConsent: function (supportCode, granted) {
        return postJson(fetcher, apiBaseUrl + '/api/conversations/' + encodeURIComponent(supportCode) + '/cobrowse-consent', {
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          visitor_token: requireVisitorToken(visitorToken),
          granted: Boolean(granted),
        });
      },
      reportCobrowseTelemetry: function (supportCode, telemetry) {
        telemetry = telemetry || {};

        return postJson(fetcher, apiBaseUrl + '/api/conversations/' + encodeURIComponent(supportCode) + '/cobrowse-telemetry', {
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          visitor_token: requireVisitorToken(visitorToken),
          rtt_ms: telemetry.rttMs,
          payload_bytes: telemetry.payloadBytes,
          dropped_batches: telemetry.droppedBatches,
          reconnects: telemetry.reconnects,
        });
      },
      reportCobrowsePageState: function (supportCode, pageState) {
        pageState = pageState || {};

        return postJson(fetcher, apiBaseUrl + '/api/conversations/' + encodeURIComponent(supportCode) + '/cobrowse-page-state', {
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          visitor_token: requireVisitorToken(visitorToken),
          page_url: pageState.pageUrl,
          title: pageState.title,
          viewport_width: pageState.viewportWidth,
          viewport_height: pageState.viewportHeight,
          scroll_x: pageState.scrollX,
          scroll_y: pageState.scrollY,
          visibility_state: pageState.visibilityState,
          focused: pageState.focused,
        });
      },
      reportCobrowseSnapshot: function (supportCode, snapshot) {
        snapshot = snapshot || {};

        return postJson(fetcher, apiBaseUrl + '/api/conversations/' + encodeURIComponent(supportCode) + '/cobrowse-snapshot', {
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          visitor_token: requireVisitorToken(visitorToken),
          page_url: snapshot.pageUrl,
          title: snapshot.title,
          html: snapshot.html,
          text: snapshot.text,
          node_count: snapshot.nodeCount,
          masked_count: snapshot.maskedCount,
        });
      },
      reportCobrowseMutations: function (supportCode, batch) {
        batch = batch || {};

        return postJson(fetcher, apiBaseUrl + '/api/conversations/' + encodeURIComponent(supportCode) + '/cobrowse-mutations', {
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          visitor_token: requireVisitorToken(visitorToken),
          page_url: batch.pageUrl,
          sequence: batch.sequence,
          dropped_count: batch.droppedCount || 0,
          skipped_count: batch.skippedCount || 0,
          mutations: (batch.mutations || []).map(mutationPayload),
        });
      },
      getMaskSelectors: function () {
        return maskSelectors.slice();
      },
      subscribeToConversation: function (supportCode, onMessage, onConnectionState, onTyping) {
        if (!realtime) {
          return null;
        }

        var events = {
          'conversation.message.created': onMessage,
        };

        if (typeof onTyping === 'function') {
          events['conversation.typing.updated'] = onTyping;
        }

        return realtime.subscribe({
          supportCode: supportCode,
          channelName: conversationChannelName(supportCode),
          eventName: 'conversation.message.created',
          events: events,
          authEndpoint: apiBaseUrl + '/api/widget/broadcasting/auth',
          authPayload: {
            site_public_key: sitePublicKey,
            anonymous_id: anonymousId,
            visitor_token: requireVisitorToken(visitorToken),
          },
          onMessage: onMessage,
          onConnectionState: onConnectionState,
        });
      },
      sendFirstMessage: async function (body, details) {
        details = details || {};

        if (!visitorToken) {
          await this.bootstrap(details.pageUrl || null, details.context);
        }

        var conversation = await this.startConversation(body, details);
        var message = await this.sendMessage(conversation.support_code, body);

        return {
          conversation: conversation,
          message: message.message,
        };
      },
    };
  }

  function init(options) {
    options = options || {};

    var doc = options.document || (root ? root.document : null);
    var location = options.location || (root ? root.location : null);

    if (!doc) {
      throw new Error('Wayfindr requires a browser document.');
    }

    var client = createClient({
      apiBaseUrl: options.apiBaseUrl,
      sitePublicKey: options.sitePublicKey,
      anonymousId: options.anonymousId,
      visitorExternalId: options.visitorExternalId,
      fetch: options.fetch,
      storage: options.storage,
      visitorToken: options.visitorToken,
      realtime: options.realtime,
      reverb: options.reverb,
      Pusher: options.Pusher,
    });

    injectStyles(doc);

    var mount = resolveMount(doc, options.mount);
    var rootEl = doc.createElement('div');
    rootEl.className = 'wayfindr-widget';
    rootEl.innerHTML = [
      '<button class="wayfindr-widget__launcher" type="button">' + escapeHtml(options.launcherLabel || 'Chat with support') + '</button>',
      '<section class="wayfindr-widget__panel" aria-label="Support chat" hidden>',
      '  <header class="wayfindr-widget__header">',
      '    <strong>' + escapeHtml(options.title || 'Wayfindr Support') + '</strong>',
      '    <button class="wayfindr-widget__close" type="button" aria-label="Close support chat">&times;</button>',
      '  </header>',
      '  <div class="wayfindr-widget__timeline" role="log" aria-live="polite" aria-label="Conversation messages" hidden></div>',
      '  <div class="wayfindr-widget__notice" data-state="empty" aria-live="polite">',
      '    <p class="wayfindr-widget__notice-copy">No messages yet. Send a message and support will see it here.</p>',
      '    <button class="wayfindr-widget__notice-retry" type="button" hidden>Try again</button>',
      '  </div>',
      '  <p class="wayfindr-widget__typing" aria-live="polite" hidden></p>',
      '  <p class="wayfindr-widget__connection" hidden></p>',
      '  <form class="wayfindr-widget__form">',
      '    <label class="wayfindr-widget__label" for="wayfindr-message">How can we help?</label>',
      '    <textarea id="wayfindr-message" class="wayfindr-widget__textarea" name="message" rows="4" required placeholder="' + escapeHtml(options.placeholder || 'Type your message...') + '"></textarea>',
      '    <div class="wayfindr-widget__actions">',
      '      <button class="wayfindr-widget__send" type="submit">Send message</button>',
      '      <button class="wayfindr-widget__refresh" type="button" hidden>Refresh</button>',
      '    </div>',
      '  </form>',
      '  <div class="wayfindr-widget__cobrowse" hidden>',
      '    <p class="wayfindr-widget__cobrowse-copy">Support wants to view this page with sensitive fields masked.</p>',
      '    <div class="wayfindr-widget__cobrowse-actions">',
      '      <button class="wayfindr-widget__cobrowse-allow" type="button">Allow cobrowse</button>',
      '      <button class="wayfindr-widget__cobrowse-decline" type="button">Decline</button>',
      '    </div>',
      '  </div>',
      '  <p class="wayfindr-widget__status" role="status"></p>',
      '</section>',
    ].join('');

    mount.appendChild(rootEl);

    var launcher = rootEl.querySelector('.wayfindr-widget__launcher');
    var panel = rootEl.querySelector('.wayfindr-widget__panel');
    var close = rootEl.querySelector('.wayfindr-widget__close');
    var form = rootEl.querySelector('.wayfindr-widget__form');
    var timeline = rootEl.querySelector('.wayfindr-widget__timeline');
    var notice = rootEl.querySelector('.wayfindr-widget__notice');
    var noticeCopy = rootEl.querySelector('.wayfindr-widget__notice-copy');
    var noticeRetry = rootEl.querySelector('.wayfindr-widget__notice-retry');
    var typing = rootEl.querySelector('.wayfindr-widget__typing');
    var connection = rootEl.querySelector('.wayfindr-widget__connection');
    var textarea = rootEl.querySelector('.wayfindr-widget__textarea');
    var status = rootEl.querySelector('.wayfindr-widget__status');
    var send = rootEl.querySelector('.wayfindr-widget__send');
    var refresh = rootEl.querySelector('.wayfindr-widget__refresh');
    var cobrowse = rootEl.querySelector('.wayfindr-widget__cobrowse');
    var cobrowseCopy = rootEl.querySelector('.wayfindr-widget__cobrowse-copy');
    var cobrowseAllow = rootEl.querySelector('.wayfindr-widget__cobrowse-allow');
    var cobrowseDecline = rootEl.querySelector('.wayfindr-widget__cobrowse-decline');
    var bootstrapped = false;
    var supportCode = null;
    var conversationActivated = false;
    var messages = [];
    var realtimeSubscription = null;
    var cobrowseGranted = false;
    var cobrowseState = 'unavailable';
    var cobrowseRequestedBy = null;
    var mutationObserver = null;
    var pendingMutationRecords = [];
    var skippedMutationRecords = 0;
    var mutationFlushTimer = null;
    var mutationSequence = 0;
    var droppedMutationBatches = 0;
    var mutationFlushMs = typeof options.mutationFlushMs === 'number' ? options.mutationFlushMs : 50;
    var mutationPayloadMaxBytes = typeof options.mutationPayloadMaxBytes === 'number'
      ? Math.max(0, options.mutationPayloadMaxBytes)
      : DEFAULT_COBROWSE_PAYLOAD_BUDGET.mutationBatchMaxBytes;
    var mutationQueueMaxRecords = typeof options.mutationQueueMaxRecords === 'number'
      ? Math.max(0, options.mutationQueueMaxRecords)
      : DEFAULT_COBROWSE_PAYLOAD_BUDGET.mutationQueueMaxRecords;
    var messagePollMs = typeof options.messagePollMs === 'number' ? Math.max(0, options.messagePollMs) : 5000;
    var messagePollTimer = null;
    var cobrowseStatusPollMs = typeof options.cobrowseStatusPollMs === 'number' ? Math.max(0, options.cobrowseStatusPollMs) : 5000;
    var cobrowseStatusTimer = null;
    var typingSignalThrottleMs = typeof options.typingSignalThrottleMs === 'number' ? Math.max(0, options.typingSignalThrottleMs) : 5000;
    var lastTypingSignalAt = 0;
    var agentTypingExpiryTimer = null;
    var visitorContext = options.visitorContext || null;
    var composerBusy = false;
    var refreshBusy = false;
    var sendLabel = send.textContent;
    var refreshLabel = refresh.textContent;

    function showNotice(state, copy, options) {
      options = options || {};

      notice.hidden = false;
      notice.setAttribute('data-state', state || 'info');
      noticeCopy.textContent = copy;
      noticeRetry.hidden = !options.retry;
      noticeRetry.disabled = false;
    }

    function hideNotice() {
      notice.hidden = true;
      noticeRetry.hidden = true;
      noticeRetry.disabled = false;
    }

    function renderMessages(nextMessages) {
      messages = Array.isArray(nextMessages) ? nextMessages : messages;
      timeline.textContent = '';

      messages.forEach(function (message, index) {
        var sender = message.sender || {};
        var senderKind = sender.kind === 'agent' ? 'agent' : 'visitor';
        var item = doc.createElement('article');
        var meta = doc.createElement('div');
        var name = doc.createElement('strong');
        var body = doc.createElement('p');
        var time = createMessageTime(doc, message.created_at);
        var delivery = createMessageDelivery(doc, senderKind);
        var grouped = shouldGroupMessage(message, messages[index - 1]);

        item.className = 'wayfindr-widget__message wayfindr-widget__message--' + senderKind;

        if (grouped) {
          item.className += ' wayfindr-widget__message--grouped';
        }

        meta.className = 'wayfindr-widget__message-meta';
        name.className = 'wayfindr-widget__message-name';
        name.textContent = sender.name || (senderKind === 'agent' ? 'Support' : 'Visitor');
        body.className = 'wayfindr-widget__message-body';
        body.textContent = message.body || '';

        meta.appendChild(name);

        if (time) {
          meta.appendChild(time);
        }

        item.appendChild(meta);
        item.appendChild(body);

        if (delivery) {
          item.appendChild(delivery);
        }

        timeline.appendChild(item);
      });

      timeline.hidden = messages.length === 0;

      if (messages.length === 0) {
        showNotice('empty', supportCode
          ? 'No messages yet. Replies will show up here.'
          : 'No messages yet. Send a message and support will see it here.');
      } else {
        hideNotice();
      }

      refresh.hidden = false;
    }

    function appendMessage(message) {
      if (!message) {
        return;
      }

      if (message.id && messages.some(function (existing) {
        return String(existing.id) === String(message.id);
      })) {
        return;
      }

      renderMessages(messages.concat([message]));
    }

    function renderAgentTyping(agentTyping) {
      var isTyping = agentTyping && agentTyping.state === 'typing' && agentTyping.label;

      clearAgentTypingExpiry();
      typing.hidden = !isTyping;
      typing.textContent = isTyping ? agentTyping.label : '';

      if (!isTyping) {
        return;
      }

      var typingAt = Date.parse(agentTyping.updated_at || '');
      var ageMs = Number.isNaN(typingAt) ? 0 : Date.now() - typingAt;
      var remainingMs = Math.max(0, AGENT_TYPING_FRESH_MS - ageMs);

      agentTypingExpiryTimer = setTimeout(function () {
        typing.hidden = true;
        typing.textContent = '';
        agentTypingExpiryTimer = null;
      }, remainingMs);
    }

    function clearAgentTypingExpiry() {
      if (!agentTypingExpiryTimer) {
        return;
      }

      clearTimeout(agentTypingExpiryTimer);
      agentTypingExpiryTimer = null;
    }

    function connectRealtime() {
      if (!supportCode || realtimeSubscription) {
        return;
      }

      var sawConnectionState = false;
      realtimeSubscription = client.subscribeToConversation(supportCode, function (event) {
        var eventSupportCode = event && event.conversation ? event.conversation.support_code : supportCode;

        if (eventSupportCode !== supportCode) {
          return;
        }

        appendMessage(event.message);
      }, function (state) {
        sawConnectionState = true;
        renderConnectionState(state);
      }, function (event) {
        var eventSupportCode = event && event.conversation ? event.conversation.support_code : supportCode;

        if (eventSupportCode !== supportCode) {
          return;
        }

        renderAgentTyping(event ? event.agent_typing : null);
      });

      if (!realtimeSubscription) {
        renderConnectionState('polling');

        return;
      }

      if (!sawConnectionState) {
        renderConnectionState('connected');
      }
    }

    function renderConnectionState(state) {
      if (!supportCode) {
        connection.hidden = true;
        connection.textContent = '';

        return;
      }

      var normalized = String(state || '').toLowerCase();

      if (normalized === 'connected' || normalized === 'live') {
        connection.textContent = 'Live updates connected.';
      } else if (normalized === 'connecting' || normalized === 'reconnecting') {
        connection.textContent = 'Live updates reconnecting. Refresh still works.';
      } else {
        connection.textContent = 'Using periodic refresh for updates.';
      }

      connection.hidden = false;
    }

    function scheduleMessagePoll() {
      if (!supportCode || messagePollMs <= 0 || messagePollTimer) {
        return;
      }

      messagePollTimer = setTimeout(async function () {
        messagePollTimer = null;
        await refreshMessages({ silent: true });
        scheduleMessagePoll();
      }, messagePollMs);

      if (typeof messagePollTimer.unref === 'function') {
        messagePollTimer.unref();
      }
    }

    function stopMessagePoll() {
      if (!messagePollTimer) {
        return;
      }

      clearTimeout(messagePollTimer);
      messagePollTimer = null;
    }

    function renderCobrowseConsent() {
      var requested = cobrowseState === 'requested';
      var granted = cobrowseState === 'granted';
      var requester = cobrowseRequestedBy || 'Support';

      cobrowse.hidden = !supportCode || (!requested && !granted);
      cobrowseAllow.textContent = granted ? 'Stop cobrowse' : 'Allow cobrowse';
      cobrowseDecline.hidden = granted;
      cobrowseCopy.textContent = granted
        ? 'Cobrowse is active. Sensitive fields stay masked.'
        : requester + ' wants to view this page with sensitive fields masked.';
    }

    function applyCobrowseStatus(nextCobrowse) {
      nextCobrowse = nextCobrowse || {};

      var previousState = cobrowseState;
      var previousGranted = cobrowseGranted;

      cobrowseState = nextCobrowse.status || nextCobrowse.consent || 'unavailable';
      cobrowseRequestedBy = nextCobrowse.requested_by && nextCobrowse.requested_by.name
        ? nextCobrowse.requested_by.name
        : null;
      cobrowseGranted = cobrowseState === 'granted' || nextCobrowse.consent === 'granted';

      if (!cobrowseGranted) {
        stopMutationStream();
      }

      renderCobrowseConsent();

      if ((previousGranted || previousState === 'requested') && cobrowseState === 'ended') {
        status.textContent = 'Cobrowse stopped by support.';
      } else if ((previousGranted || previousState === 'requested') && cobrowseState === 'revoked') {
        status.textContent = previousGranted ? 'Cobrowse stopped.' : 'Cobrowse request declined.';
      }
    }

    function scheduleCobrowseStatusPoll() {
      if (!supportCode || cobrowseStatusPollMs <= 0 || cobrowseStatusTimer) {
        return;
      }

      cobrowseStatusTimer = setTimeout(async function () {
        cobrowseStatusTimer = null;
        await refreshCobrowseStatus({ silent: true });
        scheduleCobrowseStatusPoll();
      }, cobrowseStatusPollMs);

      if (typeof cobrowseStatusTimer.unref === 'function') {
        cobrowseStatusTimer.unref();
      }
    }

    function stopCobrowseStatusPoll() {
      if (!cobrowseStatusTimer) {
        return;
      }

      clearTimeout(cobrowseStatusTimer);
      cobrowseStatusTimer = null;
    }

    function activateConversation() {
      if (!supportCode || conversationActivated) {
        return;
      }

      conversationActivated = true;
      connectRealtime();
      renderCobrowseConsent();
      refresh.hidden = false;
      scheduleMessagePoll();
      scheduleCobrowseStatusPoll();
    }

    function collectPageState() {
      var view = doc.defaultView || root;
      var docElement = doc.documentElement || {};
      var body = doc.body || {};

      return {
        pageUrl: location ? String(location.href || '') : '',
        title: doc.title || '',
        viewportWidth: Number(view && view.innerWidth ? view.innerWidth : docElement.clientWidth || body.clientWidth || 0),
        viewportHeight: Number(view && view.innerHeight ? view.innerHeight : docElement.clientHeight || body.clientHeight || 0),
        scrollX: Number(view && typeof view.scrollX === 'number' ? view.scrollX : view && typeof view.pageXOffset === 'number' ? view.pageXOffset : 0),
        scrollY: Number(view && typeof view.scrollY === 'number' ? view.scrollY : view && typeof view.pageYOffset === 'number' ? view.pageYOffset : 0),
        visibilityState: doc.visibilityState || 'visible',
        focused: typeof doc.hasFocus === 'function' ? doc.hasFocus() : true,
      };
    }

    function startMutationStream() {
      var view = doc.defaultView || root;
      var Observer = view && view.MutationObserver;

      if (!Observer || mutationObserver || !doc.body || !supportCode) {
        return;
      }

      mutationObserver = new Observer(function (records) {
        pendingMutationRecords = pendingMutationRecords.concat(Array.prototype.slice.call(records || []));

        if (pendingMutationRecords.length > mutationQueueMaxRecords) {
          var overflow = pendingMutationRecords.length - mutationQueueMaxRecords;
          pendingMutationRecords = pendingMutationRecords.slice(overflow);
          skippedMutationRecords += overflow;
        }

        scheduleMutationFlush();
      });

      mutationObserver.observe(doc.body, {
        attributes: true,
        characterData: true,
        childList: true,
        subtree: true,
      });
    }

    function stopMutationStream() {
      if (mutationObserver) {
        mutationObserver.disconnect();
        mutationObserver = null;
      }

      if (mutationFlushTimer) {
        clearTimeout(mutationFlushTimer);
        mutationFlushTimer = null;
      }

      pendingMutationRecords = [];
      skippedMutationRecords = 0;
    }

    function scheduleMutationFlush() {
      if (mutationFlushTimer) {
        return;
      }

      mutationFlushTimer = setTimeout(function () {
        mutationFlushTimer = null;
        flushMutationRecords();
      }, mutationFlushMs);
    }

    async function flushMutationRecords() {
      var records = pendingMutationRecords;
      pendingMutationRecords = [];

      if (!supportCode || (records.length === 0 && skippedMutationRecords === 0 && droppedMutationBatches === 0)) {
        return;
      }

      var queuedSkippedCount = skippedMutationRecords;
      var batch = createCobrowseMutationBatch(records, {
        document: doc,
        location: location,
        maskSelectors: client.getMaskSelectors(),
        sequence: mutationSequence + 1,
        droppedCount: droppedMutationBatches,
        skippedCount: queuedSkippedCount,
        maxPayloadBytes: mutationPayloadMaxBytes,
      });

      if (batch.mutations.length === 0 && batch.droppedCount === 0 && batch.skippedCount === 0) {
        return;
      }

      mutationSequence = batch.sequence;

      try {
        await client.reportCobrowseMutations(supportCode, batch);
        droppedMutationBatches = 0;
        skippedMutationRecords = Math.max(0, skippedMutationRecords - queuedSkippedCount);
      } catch (error) {
        droppedMutationBatches += 1;
      }
    }

    async function updateCobrowseConsent(nextGranted) {
      if (!supportCode) {
        return;
      }

      var startedAt = Date.now();

      cobrowseAllow.disabled = true;
      cobrowseDecline.disabled = true;
      status.textContent = nextGranted ? 'Granting cobrowse consent...' : 'Revoking cobrowse consent...';

      try {
        var result = await client.setCobrowseConsent(supportCode, nextGranted);
        var consent = result && result.cobrowse ? result.cobrowse.consent : null;

        applyCobrowseStatus(result && result.cobrowse ? result.cobrowse : {
          status: consent,
          consent: consent,
        });

        if (cobrowseGranted) {
          try {
            await client.reportCobrowseTelemetry(supportCode, {
              rttMs: Date.now() - startedAt,
              payloadBytes: estimateJsonBytes(result),
              droppedBatches: 0,
              reconnects: 0,
            });
          } catch (error) {
            // Telemetry should never undo a successful consent change.
          }

          try {
            await client.reportCobrowsePageState(supportCode, collectPageState());
          } catch (error) {
            // Page-state reporting should never undo a successful consent change.
          }

          try {
            await client.reportCobrowseSnapshot(supportCode, createCobrowseSnapshot(doc, {
              location: location,
              maskSelectors: client.getMaskSelectors(),
            }));
          } catch (error) {
            // Snapshot reporting should never undo a successful consent change.
          }

          startMutationStream();
        } else {
          stopMutationStream();
        }

        status.textContent = cobrowseGranted ? 'Cobrowse consent granted.' : 'Cobrowse consent revoked.';
      } catch (error) {
        status.textContent = error.message || 'Wayfindr could not update cobrowse consent.';
      } finally {
        cobrowseAllow.disabled = false;
        cobrowseDecline.disabled = false;
      }
    }

    async function refreshCobrowseStatus(options) {
      options = options || {};

      if (!supportCode) {
        return null;
      }

      try {
        var result = await client.fetchCobrowseStatus(supportCode);

        applyCobrowseStatus(result && result.cobrowse ? result.cobrowse : null);

        return result;
      } catch (error) {
        if (!options.silent) {
          status.textContent = error.message || 'Wayfindr could not refresh cobrowse status.';
        }

        return null;
      }
    }

    async function refreshMessages(options) {
      options = options || {};

      if (!supportCode || refreshBusy) {
        return;
      }

      if (!options.silent) {
        setRefreshBusy(true);
      }

      if (!options.silent) {
        status.textContent = 'Refreshing...';
      }

      try {
        var result = await client.fetchMessages(supportCode, {
          markSeen: shouldMarkMessagesSeen({
            markSeen: options.markSeen,
            panel: panel,
            document: doc,
          }),
        });
        renderMessages(result.messages || []);
        renderAgentTyping(result.agent_typing);

        if (!options.silent) {
          status.textContent = 'Messages refreshed.';
        }
      } catch (error) {
        if (!options.silent) {
          status.textContent = MESSAGE_REFRESH_ERROR;
          showNotice('warning', MESSAGE_REFRESH_ERROR, {
            retry: true,
          });
        }
      } finally {
        if (!options.silent) {
          setRefreshBusy(false);
        }
      }
    }

    function setComposerBusy(nextBusy) {
      composerBusy = Boolean(nextBusy);
      form.setAttribute('aria-busy', composerBusy ? 'true' : 'false');
      textarea.disabled = composerBusy;
      send.disabled = composerBusy;
      send.textContent = composerBusy ? 'Sending...' : sendLabel;
    }

    function setRefreshBusy(nextBusy) {
      refreshBusy = Boolean(nextBusy);
      refresh.setAttribute('aria-busy', refreshBusy ? 'true' : 'false');
      refresh.disabled = refreshBusy;
      refresh.textContent = refreshBusy ? 'Refreshing...' : refreshLabel;
      noticeRetry.disabled = refreshBusy;
    }

    function open() {
      var wasHidden = panel.hidden;

      panel.hidden = false;
      launcher.hidden = true;
      textarea.focus();

      if (wasHidden && supportCode) {
        refreshMessages({ silent: true, markSeen: true });
      }
    }

    function closePanel() {
      panel.hidden = true;
      launcher.hidden = false;
      launcher.focus();
    }

    async function reportTyping(isTyping, options) {
      options = options || {};

      if (!supportCode) {
        return;
      }

      var nowMs = Date.now();

      if (isTyping) {
        if (!options.force && typingSignalThrottleMs > 0 && nowMs - lastTypingSignalAt < typingSignalThrottleMs) {
          return;
        }

        lastTypingSignalAt = nowMs;
      } else {
        lastTypingSignalAt = 0;
      }

      try {
        await client.reportTyping(supportCode, isTyping);
      } catch (error) {
        // Typing is a transient hint. Message send/refresh should remain the real path.
      }
    }

    launcher.addEventListener('click', open);
    close.addEventListener('click', closePanel);
    refresh.addEventListener('click', function () {
      refreshMessages();
    });
    noticeRetry.addEventListener('click', function () {
      refreshMessages();
    });
    cobrowseAllow.addEventListener('click', function () {
      updateCobrowseConsent(!cobrowseGranted);
    });
    cobrowseDecline.addEventListener('click', function () {
      updateCobrowseConsent(false);
    });
    textarea.addEventListener('input', function () {
      if (textarea.value.trim()) {
        reportTyping(true);
      }
    });
    form.addEventListener('submit', async function (event) {
      event.preventDefault();

      if (composerBusy) {
        return;
      }

      var body = textarea.value.trim();

      if (!body) {
        return;
      }

      setComposerBusy(true);
      status.textContent = 'Sending...';

      try {
        if (!bootstrapped) {
          await client.bootstrap(location ? location.href : null, visitorContext);
          bootstrapped = true;
        }

        if (supportCode) {
          await client.sendMessage(supportCode, body);
          activateConversation();
        } else {
          var conversation = await client.startConversation(body, {
            pageUrl: location ? location.href : null,
            context: visitorContext,
          });

          supportCode = conversation.support_code;
          await client.sendMessage(supportCode, body);
          activateConversation();
        }

        textarea.value = '';
        await refreshMessages({ silent: true });
        await refreshCobrowseStatus({ silent: true });
        status.textContent = 'Message sent. Support code ' + supportCode + '.';
      } catch (error) {
        status.textContent = MESSAGE_SEND_ERROR;
        showNotice('warning', MESSAGE_SEND_ERROR);
      } finally {
        setComposerBusy(false);
      }
    });

    return {
      anonymousId: client.anonymousId,
      client: client,
      root: rootEl,
      open: open,
      close: closePanel,
      refreshCobrowseStatus: refreshCobrowseStatus,
      destroy: function () {
        if (realtimeSubscription && typeof realtimeSubscription.unsubscribe === 'function') {
          realtimeSubscription.unsubscribe();
        }

        stopCobrowseStatusPoll();
        stopMessagePoll();
        clearAgentTypingExpiry();
        stopMutationStream();
        rootEl.remove();
      },
    };
  }

  function shouldMarkMessagesSeen(options) {
    options = options || {};

    if (options.markSeen === true) {
      return true;
    }

    if (options.markSeen === false) {
      return false;
    }

    return isPanelReadable(options);
  }

  function isPanelReadable(options) {
    var panel = options && options.panel;
    var doc = options && options.document;

    if (!panel || panel.hidden) {
      return false;
    }

    return !doc || !doc.visibilityState || doc.visibilityState !== 'hidden';
  }

  function resolveRealtime(options, fetcher) {
    if (options.realtime === false) {
      return null;
    }

    if (options.realtime && typeof options.realtime.subscribe === 'function') {
      return options.realtime;
    }

    if (!options.reverb) {
      return null;
    }

    var Pusher = options.Pusher || (root && root.Pusher);

    if (!Pusher || !options.reverb.appKey) {
      return null;
    }

    return createPusherRealtime(options.reverb, Pusher, fetcher);
  }

  function createPusherRealtime(reverb, Pusher, fetcher) {
    return {
      subscribe: function (config) {
        var scheme = reverb.scheme || 'https';
        var port = reverb.port || (scheme === 'https' ? 443 : 80);
        var pusher = new Pusher(reverb.appKey, {
          wsHost: reverb.host,
          wsPort: reverb.wsPort || port,
          wssPort: reverb.wssPort || port,
          forceTLS: scheme === 'https',
          enabledTransports: reverb.enabledTransports || ['ws', 'wss'],
          channelAuthorization: {
            customHandler: function (params, callback) {
              postJsonRaw(fetcher, config.authEndpoint, Object.assign({}, config.authPayload, {
                socket_id: params.socketId,
                channel_name: params.channelName,
              })).then(function (payload) {
                callback(null, payload);
              }).catch(function (error) {
                callback(error, null);
              });
            },
          },
        });
        var channel = pusher.subscribe(config.channelName);
        var eventHandlers = config.events || {};
        var eventBindings = [];
        var connectionBindings = [];

        if (Object.keys(eventHandlers).length === 0 && config.eventName && config.onMessage) {
          eventHandlers[config.eventName] = config.onMessage;
        }

        Object.keys(eventHandlers).forEach(function (baseEventName) {
          var handler = eventHandlers[baseEventName];

          if (typeof handler !== 'function') {
            return;
          }

          pusherBroadcastEventNames(baseEventName).forEach(function (eventName) {
            channel.bind(eventName, handler);
            eventBindings.push({
              eventName: eventName,
              handler: handler,
            });
          });
        });

        if (config.onConnectionState && pusher.connection && typeof pusher.connection.bind === 'function') {
          bindPusherConnectionState(pusher.connection, 'state_change', function (change) {
            config.onConnectionState((change && (change.current || change.state)) || 'connecting');
          });
          bindPusherConnectionState(pusher.connection, 'error', function () {
            config.onConnectionState('unavailable');
          });
        }

        function bindPusherConnectionState(connection, eventName, handler) {
          connection.bind(eventName, handler);
          connectionBindings.push({
            eventName: eventName,
            handler: handler,
          });
        }

        return {
          unsubscribe: function () {
            eventBindings.forEach(function (binding) {
              channel.unbind(binding.eventName, binding.handler);
            });
            connectionBindings.forEach(function (binding) {
              if (pusher.connection && typeof pusher.connection.unbind === 'function') {
                pusher.connection.unbind(binding.eventName, binding.handler);
              }
            });
            pusher.unsubscribe(config.channelName);

            if (typeof pusher.disconnect === 'function') {
              pusher.disconnect();
            }
          },
        };
      },
    };
  }

  function pusherBroadcastEventNames(eventName) {
    if (!eventName || eventName.charAt(0) === '.') {
      return [eventName];
    }

    return [eventName, '.' + eventName];
  }

  function createMessageTime(doc, value) {
    if (!value) {
      return null;
    }

    var date = new Date(String(value));

    if (Number.isNaN(date.getTime())) {
      return null;
    }

    var time = doc.createElement('time');
    time.className = 'wayfindr-widget__message-time';
    time.dateTime = String(value);
    time.title = date.toISOString();
    time.textContent = formatMessageTime(date);

    return time;
  }

  function createMessageDelivery(doc, senderKind) {
    if (senderKind !== 'visitor') {
      return null;
    }

    var delivery = doc.createElement('span');
    delivery.className = 'wayfindr-widget__message-delivery';
    delivery.setAttribute('aria-label', 'Message delivery status');
    delivery.textContent = 'Sent';

    return delivery;
  }

  function shouldGroupMessage(message, previousMessage) {
    if (!previousMessage || !sameMessageSender(message, previousMessage)) {
      return false;
    }

    var currentTime = parseMessageTime(message && message.created_at);
    var previousTime = parseMessageTime(previousMessage && previousMessage.created_at);

    if (currentTime === null || previousTime === null) {
      return true;
    }

    var delta = currentTime - previousTime;

    return delta >= 0 && delta <= MESSAGE_GROUP_WINDOW_MS;
  }

  function sameMessageSender(message, previousMessage) {
    var sender = (message && message.sender) || {};
    var previousSender = (previousMessage && previousMessage.sender) || {};
    var senderKind = sender.kind === 'agent' ? 'agent' : 'visitor';
    var previousSenderKind = previousSender.kind === 'agent' ? 'agent' : 'visitor';
    var senderName = sender.name || (senderKind === 'agent' ? 'Support' : 'Visitor');
    var previousSenderName = previousSender.name || (previousSenderKind === 'agent' ? 'Support' : 'Visitor');

    return senderKind === previousSenderKind && senderName === previousSenderName;
  }

  function parseMessageTime(value) {
    if (!value) {
      return null;
    }

    var date = new Date(String(value));

    if (Number.isNaN(date.getTime())) {
      return null;
    }

    return date.getTime();
  }

  function formatMessageTime(date) {
    try {
      return date.toLocaleTimeString([], {
        hour: 'numeric',
        minute: '2-digit',
      });
    } catch (error) {
      return date.toISOString().slice(11, 16);
    }
  }

  function resolveAnonymousId(options) {
    options = options || {};

    if (options.anonymousId) {
      return options.anonymousId;
    }

    var storage = options.storage;
    var key = 'wayfindr:' + options.sitePublicKey + ':anonymous-id';
    var existing = storageGet(storage, key);

    if (existing) {
      return existing;
    }

    var anonymousId = 'anon_' + randomToken();
    storageSet(storage, key, anonymousId);

    return anonymousId;
  }

  function siteMaskSelectors(result) {
    var settings = result && result.site ? result.site.settings || {} : {};
    var selectors = settings.mask_selectors;

    return Array.isArray(selectors) ? selectors.filter(function (selector) {
      return typeof selector === 'string' && selector.trim();
    }) : [];
  }

  function createCobrowseSnapshot(doc, options) {
    options = options || {};

    var location = options.location || (doc && doc.location) || null;
    var source = doc && doc.body ? doc.body.cloneNode(true) : null;

    if (!source) {
      return {
        pageUrl: location ? String(location.href || '') : '',
        title: doc && doc.title ? doc.title : '',
        html: '',
        text: '',
        nodeCount: 0,
        maskedCount: 0,
      };
    }

    removeMatching(source, DEFAULT_REMOVE_SELECTORS);

    var maskedCount = maskMatching(source, DEFAULT_MASK_SELECTORS.concat(options.maskSelectors || []));
    maskedCount += maskInferredSensitiveElements(source);
    clearFormControlValues(source);

    return {
      pageUrl: location ? String(location.href || '') : '',
      title: doc.title || '',
      html: truncateString(source.innerHTML || '', options.maxHtmlLength || 60000),
      text: truncateString(normalizeWhitespace(source.textContent || ''), options.maxTextLength || 10000),
      nodeCount: source.querySelectorAll ? source.querySelectorAll('*').length : 0,
      maskedCount: maskedCount,
    };
  }

  function createCobrowseMutationBatch(records, options) {
    options = options || {};

    var doc = options.document || null;
    var location = options.location || (doc && doc.location) || null;
    var maskSelectors = DEFAULT_MASK_SELECTORS.concat(options.maskSelectors || []);
    var maxMutations = options.maxMutations || 50;
    var mutations = [];
    var skippedCount = Number(options.skippedCount || 0);

    Array.prototype.slice.call(records || []).forEach(function (record) {
      if (mutations.length >= maxMutations) {
        skippedCount += 1;

        return;
      }

      var mutation = mutationFromRecord(record, {
        maskSelectors: maskSelectors,
      });

      if (!mutation) {
        skippedCount += 1;

        return;
      }

      mutations.push(mutation);
    });

    return fitMutationBatchToBudget({
      pageUrl: location ? String(location.href || '') : '',
      sequence: Number(options.sequence || 1),
      droppedCount: Number(options.droppedCount || 0),
      skippedCount: skippedCount,
      mutations: mutations,
    }, options.maxPayloadBytes);
  }

  function fitMutationBatchToBudget(batch, maxPayloadBytes) {
    if (typeof maxPayloadBytes !== 'number' || maxPayloadBytes <= 0) {
      return batch;
    }

    while (batch.mutations.length > 0 && estimateJsonBytes(batch) > maxPayloadBytes) {
      batch.mutations.pop();
      batch.skippedCount += 1;
    }

    return batch;
  }

  function mutationPayload(mutation) {
    return withoutNullValues({
      type: mutation.type,
      path: mutation.path,
      text: mutation.text,
      html: mutation.html,
      attribute_name: mutation.attributeName,
      attribute_value: mutation.attributeValue,
      node_name: mutation.nodeName,
      node_count: mutation.nodeCount,
      masked_count: mutation.maskedCount,
    });
  }

  function mutationFromRecord(record, options) {
    if (!record || !record.type) {
      return null;
    }

    if (record.type === 'characterData') {
      return textMutationFromRecord(record, options);
    }

    if (record.type === 'attributes') {
      return attributeMutationFromRecord(record, options);
    }

    if (record.type === 'childList') {
      return childMutationFromRecord(record, options);
    }

    return null;
  }

  function textMutationFromRecord(record, options) {
    var target = record.target || null;
    var element = target && target.parentElement ? target.parentElement : null;

    if (!element || shouldIgnoreElement(element)) {
      return null;
    }

    return {
      type: 'text',
      path: elementPath(element),
      text: isMaskedElement(element, options.maskSelectors)
        ? '[masked]'
        : truncateString(normalizeWhitespace(target.data || target.textContent || ''), 5000),
    };
  }

  function attributeMutationFromRecord(record, options) {
    var element = record.target || null;
    var attributeName = String(record.attributeName || '').toLowerCase();

    if (!element || shouldIgnoreElement(element) || !isSafeMutationAttribute(attributeName)) {
      return null;
    }

    return {
      type: 'attribute',
      path: elementPath(element),
      attributeName: attributeName,
      attributeValue: isMaskedElement(element, options.maskSelectors)
        ? '[masked]'
        : truncateString(String(element.getAttribute(attributeName) || ''), 2048),
    };
  }

  function childMutationFromRecord(record, options) {
    var addedNodes = Array.prototype.slice.call(record.addedNodes || []);
    var removedNodes = Array.prototype.slice.call(record.removedNodes || []);
    var added = addedNodes.find(function (node) {
      return node && node.nodeType === 1 && !shouldIgnoreElement(node);
    });
    var addedText = addedNodes.find(function (node) {
      return node && node.nodeType === 3 && normalizeWhitespace(node.textContent || '');
    });
    var removed = removedNodes.find(function (node) {
      return node && node.nodeType === 1;
    });

    if (added) {
      return addedMutation(record, added, options);
    }

    if (addedText) {
      return textMutationFromNode(record.target, addedText, options);
    }

    if (removed) {
      return removedMutation(record, removed);
    }

    return null;
  }

  function addedMutation(record, element, options) {
    var clone = element.cloneNode(true);
    var maskedCount;

    removeMatching(clone, DEFAULT_REMOVE_SELECTORS);
    maskedCount = isMaskedElement(element, options.maskSelectors)
      ? maskWholeElement(clone)
      : maskMatching(clone, options.maskSelectors);
    maskedCount += maskInferredSensitiveElements(clone);
    clearFormControlValues(clone);

    return {
      type: 'added',
      path: elementPath(record.target || element.parentElement),
      html: truncateString(clone.outerHTML || '', 10000),
      text: truncateString(normalizeWhitespace(clone.textContent || ''), 5000),
      nodeCount: clone.querySelectorAll ? clone.querySelectorAll('*').length + 1 : 1,
      maskedCount: maskedCount,
    };
  }

  function textMutationFromNode(element, node, options) {
    if (!element || shouldIgnoreElement(element)) {
      return null;
    }

    return {
      type: 'text',
      path: elementPath(element),
      text: isMaskedElement(element, options.maskSelectors)
        ? '[masked]'
        : truncateString(normalizeWhitespace(node.textContent || ''), 5000),
    };
  }

  function removedMutation(record, element) {
    var target = record.target || element.parentElement;

    if (!target || shouldIgnoreElement(target)) {
      return null;
    }

    return {
      type: 'removed',
      path: elementPath(target),
      nodeName: String(element.tagName || element.nodeName || '').toLowerCase(),
    };
  }

  function removeMatching(source, selectors) {
    selectors.forEach(function (selector) {
      queryAll(source, selector).forEach(function (element) {
        if (element.parentNode) {
          element.parentNode.removeChild(element);
        }
      });
    });
  }

  function maskMatching(source, selectors) {
    var masked = [];

    selectors.forEach(function (selector) {
      queryAll(source, selector).forEach(function (element) {
        if (masked.indexOf(element) !== -1) {
          return;
        }

        masked.push(element);
        maskElement(element);
      });
    });

    return masked.length;
  }

  function maskInferredSensitiveElements(source) {
    var masked = [];

    allElements(source).forEach(function (element) {
      if (
        masked.indexOf(element) !== -1
        || isAlreadyMaskedElement(element)
        || !isInferredSensitiveElement(element, source)
      ) {
        return;
      }

      masked.push(element);
      maskElement(element);
    });

    return masked.length;
  }

  function allElements(source) {
    var elements = source && source.nodeType === 1 ? [source] : [];

    return elements.concat(queryAll(source, '*'));
  }

  function queryAll(source, selector) {
    try {
      return Array.prototype.slice.call(source.querySelectorAll(selector));
    } catch (error) {
      return [];
    }
  }

  function maskElement(element) {
    var tagName = String(element.tagName || '').toLowerCase();

    if (tagName === 'input' || tagName === 'textarea' || tagName === 'select') {
      element.setAttribute('value', '[masked]');

      if (element.hasAttribute('placeholder')) {
        element.setAttribute('placeholder', '[masked]');
      }

      if (tagName === 'textarea' || tagName === 'select') {
        element.textContent = '[masked]';
      }

      return;
    }

    element.textContent = '[masked]';
  }

  function isAlreadyMaskedElement(element) {
    var tagName = String(element.tagName || '').toLowerCase();

    if (tagName === 'input') {
      return element.getAttribute('value') === '[masked]';
    }

    return normalizeWhitespace(element.textContent || '') === '[masked]';
  }

  function maskWholeElement(element) {
    maskElement(element);

    return 1;
  }

  function shouldIgnoreElement(element) {
    return elementMatchesOrClosest(element, DEFAULT_REMOVE_SELECTORS);
  }

  function isMaskedElement(element, selectors) {
    return elementMatchesOrClosest(element, selectors || DEFAULT_MASK_SELECTORS)
      || isInferredSensitiveElement(element, element ? element.ownerDocument : null);
  }

  function isInferredSensitiveElement(element, source) {
    if (!element || elementMatchesOrClosest(element, ['[data-wayfindr-allow]'])) {
      return false;
    }

    if (hasSensitiveAttribute(element)) {
      return true;
    }

    return isFormControl(element) && hasSensitiveLabel(element, source || element.ownerDocument);
  }

  function hasSensitiveAttribute(element) {
    return SENSITIVE_FIELD_ATTRIBUTES.some(function (attributeName) {
      return hasSensitiveTerm(element.getAttribute(attributeName));
    });
  }

  function hasSensitiveLabel(element, source) {
    var id = element.getAttribute('id');
    var labels = [];

    if (element.labels) {
      labels = labels.concat(Array.prototype.slice.call(element.labels));
    }

    if (id) {
      queryAll(source, 'label').forEach(function (label) {
        if (label.getAttribute('for') === id && labels.indexOf(label) === -1) {
          labels.push(label);
        }
      });
    }

    if (typeof element.closest === 'function') {
      var wrappingLabel = element.closest('label');

      if (wrappingLabel && labels.indexOf(wrappingLabel) === -1) {
        labels.push(wrappingLabel);
      }
    }

    return labels.some(function (label) {
      return hasSensitiveTerm(label.textContent || '');
    });
  }

  function hasSensitiveTerm(value) {
    var normalized = normalizeSensitiveToken(value);

    if (!normalized) {
      return false;
    }

    return SENSITIVE_FIELD_TERMS.some(function (term) {
      return normalized.indexOf(term) !== -1;
    });
  }

  function normalizeSensitiveToken(value) {
    return String(value || '')
      .replace(/([a-z])([A-Z])/g, '$1 $2')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function isFormControl(element) {
    var tagName = String(element.tagName || '').toLowerCase();

    return tagName === 'input' || tagName === 'textarea' || tagName === 'select';
  }

  function elementMatchesOrClosest(element, selectors) {
    if (!element || !selectors) {
      return false;
    }

    return selectors.some(function (selector) {
      try {
        return (typeof element.matches === 'function' && element.matches(selector))
          || (typeof element.closest === 'function' && Boolean(element.closest(selector)));
      } catch (error) {
        return false;
      }
    });
  }

  function isSafeMutationAttribute(attributeName) {
    return SAFE_MUTATION_ATTRIBUTES.indexOf(attributeName) !== -1 || attributeName.indexOf('aria-') === 0;
  }

  function elementPath(element) {
    var parts = [];
    var current = element && element.nodeType === 1 ? element : null;

    while (current && current.tagName) {
      var tag = String(current.tagName).toLowerCase();

      if (tag === 'html') {
        break;
      }

      parts.unshift(tag + ':nth-of-type(' + elementIndex(current) + ')');

      if (tag === 'body') {
        break;
      }

      current = current.parentElement;
    }

    return parts.join(' > ') || 'document';
  }

  function elementIndex(element) {
    var index = 1;
    var sibling = element.previousElementSibling;
    var tag = element.tagName;

    while (sibling) {
      if (sibling.tagName === tag) {
        index += 1;
      }

      sibling = sibling.previousElementSibling;
    }

    return index;
  }

  function clearFormControlValues(source) {
    queryAll(source, 'input, textarea, select').forEach(function (element) {
      if (element.getAttribute('value') === '[masked]' || element.textContent === '[masked]') {
        return;
      }

      if (element.hasAttribute('value')) {
        element.setAttribute('value', '');
      }

      if (String(element.tagName || '').toLowerCase() !== 'input') {
        element.textContent = '';
      }
    });
  }

  function normalizeWhitespace(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
  }

  function truncateString(value, maxLength) {
    value = String(value || '');

    if (value.length <= maxLength) {
      return value;
    }

    return value.slice(0, maxLength);
  }

  function estimateJsonBytes(payload) {
    try {
      var serialized = JSON.stringify(payload || {});

      if (root.TextEncoder) {
        return new root.TextEncoder().encode(serialized).length;
      }

      return serialized.length;
    } catch (error) {
      return 0;
    }
  }

  function withoutNullValues(values) {
    var result = {};

    Object.keys(values).forEach(function (key) {
      if (values[key] !== null && typeof values[key] !== 'undefined') {
        result[key] = values[key];
      }
    });

    return result;
  }

  function visitorTokenStorageKey(sitePublicKey) {
    return 'wayfindr:' + sitePublicKey + ':visitor-token';
  }

  function requireVisitorToken(visitorToken) {
    if (!visitorToken) {
      throw new Error('Wayfindr visitor session is not bootstrapped.');
    }

    return visitorToken;
  }

  function getJson(fetcher, url) {
    return fetcher(url, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
      },
    }).then(readJsonResponse);
  }

  function postJson(fetcher, url, payload) {
    return fetcher(url, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
    }).then(readJsonResponse);
  }

  function postJsonRaw(fetcher, url, payload) {
    return fetcher(url, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
    }).then(readRawJsonResponse);
  }

  async function readJsonResponse(response) {
    var data = await response.json().catch(function () {
      return {};
    });

    if (!response.ok) {
      throw new Error(data.message || 'Wayfindr request failed with status ' + response.status + '.');
    }

    return data.data;
  }

  async function readRawJsonResponse(response) {
    var data = await response.json().catch(function () {
      return {};
    });

    if (!response.ok) {
      throw new Error(data.message || 'Wayfindr request failed with status ' + response.status + '.');
    }

    return data;
  }

  function toQueryString(values) {
    return Object.keys(values).map(function (key) {
      return encodeURIComponent(key) + '=' + encodeURIComponent(values[key]);
    }).join('&');
  }

  function normalizeApiBaseUrl(value) {
    return String(value || '').replace(/\/+$/, '');
  }

  function conversationChannelName(supportCode) {
    return 'private-conversations.' + supportCode;
  }

  function summarize(body) {
    return String(body || '').replace(/\s+/g, ' ').trim().slice(0, 255) || null;
  }

  function normalizeVisitorExternalId(value) {
    if (typeof value !== 'string' && typeof value !== 'number') {
      return null;
    }

    value = String(value).trim();

    return value ? value : null;
  }

  function withVisitorContext(payload, context, visitorExternalId) {
    if (visitorExternalId) {
      payload.external_id = visitorExternalId;
    }

    if (context && typeof context === 'object' && !Array.isArray(context)) {
      payload.context = context;
    }

    return payload;
  }

  function randomToken() {
    var crypto = root && root.crypto;

    if (crypto && typeof crypto.randomUUID === 'function') {
      return crypto.randomUUID().replace(/-/g, '');
    }

    return Math.random().toString(36).slice(2) + Date.now().toString(36);
  }

  function storageGet(storage, key) {
    try {
      return storage ? storage.getItem(key) : null;
    } catch (error) {
      return null;
    }
  }

  function storageSet(storage, key, value) {
    try {
      if (storage) {
        storage.setItem(key, value);
      }
    } catch (error) {
      // Private browsing and locked-down embeds can reject storage writes.
    }
  }

  function defaultStorage() {
    try {
      return root && root.document ? root.localStorage : null;
    } catch (error) {
      return null;
    }
  }

  function resolveMount(doc, mount) {
    if (!mount) {
      return doc.body;
    }

    if (typeof mount === 'string') {
      var target = doc.querySelector(mount);

      if (!target) {
        throw new Error('Wayfindr mount target was not found.');
      }

      return target;
    }

    return mount;
  }

  function injectStyles(doc) {
    if (doc.getElementById(STYLE_ID)) {
      return;
    }

    var style = doc.createElement('style');
    style.id = STYLE_ID;
    style.textContent = [
      '.wayfindr-widget{position:fixed;right:20px;bottom:20px;z-index:2147483000;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1d2523}',
      '.wayfindr-widget *{box-sizing:border-box}',
      '.wayfindr-widget [hidden]{display:none!important}',
      '.wayfindr-widget__launcher,.wayfindr-widget__send{border:0;border-radius:999px;background:#0d6f68;color:#fff;box-shadow:0 12px 30px rgba(8,37,34,.18);cursor:pointer;font:700 14px/1 ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}',
      '.wayfindr-widget__launcher{min-height:48px;padding:0 18px}',
      '.wayfindr-widget__send{min-height:40px;padding:0 14px;border-radius:6px}',
      '.wayfindr-widget__launcher:hover,.wayfindr-widget__send:hover{background:#094f4b}',
      '.wayfindr-widget__send:disabled{cursor:wait;opacity:.7}',
      '.wayfindr-widget__panel{width:min(360px,calc(100vw - 32px));border:1px solid #d8dfdc;border-radius:8px;background:#fff;box-shadow:0 20px 55px rgba(8,37,34,.2);overflow:hidden}',
      '.wayfindr-widget__header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid #d8dfdc;background:#f7f7f3}',
      '.wayfindr-widget__close{border:0;background:transparent;color:#62706b;cursor:pointer;font:700 24px/1 ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:0}',
      '.wayfindr-widget__timeline{display:grid;gap:10px;max-height:280px;overflow:auto;padding:14px 16px;border-bottom:1px solid #eef1ef;background:#fbfbf8}',
      '.wayfindr-widget__notice{display:grid;gap:10px;margin:0;padding:14px 16px;border-bottom:1px solid #eef1ef;background:#fbfbf8;color:#62706b;font-size:13px;line-height:1.4}',
      '.wayfindr-widget__notice[data-state="warning"]{background:#fffaf0;color:#5f4b20}',
      '.wayfindr-widget__notice-copy{margin:0}',
      '.wayfindr-widget__notice-retry{justify-self:start;min-height:34px;border:1px solid #d8dfdc;border-radius:6px;background:#fff;color:#1d2523;cursor:pointer;padding:0 12px;font:700 13px/1 ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}',
      '.wayfindr-widget__notice-retry:hover{border-color:#0d6f68;color:#0d6f68}',
      '.wayfindr-widget__notice-retry:disabled{cursor:wait;opacity:.7}',
      '.wayfindr-widget__typing{margin:0;padding:9px 16px;border-bottom:1px solid #eef1ef;background:#fbfbf8;color:#62706b;font-size:13px;line-height:1.35}',
      '.wayfindr-widget__connection{margin:0;padding:10px 16px 0;color:#62706b;font-size:12px;line-height:1.35}',
      '.wayfindr-widget__message{display:grid;gap:4px;width:88%;border:1px solid #d8dfdc;border-radius:8px;padding:9px 10px;background:#fff}',
      '.wayfindr-widget__message--agent{justify-self:end;background:#eef6f3;border-color:#cfe1dc}',
      '.wayfindr-widget__message--grouped{margin-top:-6px}',
      '.wayfindr-widget__message-meta{display:flex;align-items:center;justify-content:space-between;gap:10px}',
      '.wayfindr-widget__message-name{color:#62706b;font-size:12px;line-height:1.2}',
      '.wayfindr-widget__message--grouped .wayfindr-widget__message-name{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}',
      '.wayfindr-widget__message--grouped .wayfindr-widget__message-meta{justify-content:flex-end}',
      '.wayfindr-widget__message-time{color:#7d8a85;font-size:11px;line-height:1.2;white-space:nowrap}',
      '.wayfindr-widget__message-body{margin:0;white-space:pre-wrap;color:#1d2523;font-size:14px;line-height:1.4}',
      '.wayfindr-widget__message-delivery{justify-self:end;color:#7d8a85;font-size:11px;line-height:1.2}',
      '.wayfindr-widget__form{display:grid;gap:10px;padding:16px}',
      '.wayfindr-widget__label{font-size:13px;font-weight:700}',
      '.wayfindr-widget__textarea{width:100%;resize:vertical;border:1px solid #d8dfdc;border-radius:6px;padding:10px;color:#1d2523;font:14px/1.4 ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}',
      '.wayfindr-widget__textarea:focus{outline:3px solid rgba(13,111,104,.2);border-color:#0d6f68}',
      '.wayfindr-widget__textarea:disabled{background:#f7f7f3;color:#62706b;cursor:wait}',
      '.wayfindr-widget__actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}',
      '.wayfindr-widget__refresh{min-height:40px;border:1px solid #d8dfdc;border-radius:6px;background:#fff;color:#1d2523;cursor:pointer;padding:0 12px;font:700 14px/1 ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}',
      '.wayfindr-widget__refresh:hover{border-color:#0d6f68;color:#0d6f68}',
      '.wayfindr-widget__refresh:disabled{cursor:wait;opacity:.7}',
      '.wayfindr-widget__cobrowse{display:grid;gap:8px;padding:0 16px 16px}',
      '.wayfindr-widget__cobrowse-copy{margin:0;color:#62706b;font-size:13px;line-height:1.35}',
      '.wayfindr-widget__cobrowse-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}',
      '.wayfindr-widget__cobrowse-allow,.wayfindr-widget__cobrowse-decline{min-height:36px;border:1px solid #d8dfdc;border-radius:6px;background:#fff;color:#1d2523;cursor:pointer;padding:0 12px;font:700 13px/1 ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}',
      '.wayfindr-widget__cobrowse-allow{background:#0d6f68;border-color:#0d6f68;color:#fff}',
      '.wayfindr-widget__cobrowse-allow:hover{background:#094f4b;border-color:#094f4b;color:#fff}',
      '.wayfindr-widget__cobrowse-decline:hover{border-color:#0d6f68;color:#0d6f68}',
      '.wayfindr-widget__cobrowse-allow:disabled,.wayfindr-widget__cobrowse-decline:disabled{cursor:wait;opacity:.7}',
      '.wayfindr-widget__status{min-height:20px;margin:0;padding:0 16px 16px;color:#62706b;font-size:13px}',
      '@media (max-width:480px){.wayfindr-widget{right:12px;bottom:12px}.wayfindr-widget__panel{width:calc(100vw - 24px)}}',
    ].join('');

    doc.head.appendChild(style);
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function autoInitFromCurrentScript() {
    var doc = root && root.document;
    var script = doc && doc.currentScript;

    if (!script || !script.dataset || !script.dataset.wayfindrSiteKey) {
      return;
    }

    init({
      apiBaseUrl: script.dataset.wayfindrApiBaseUrl || root.location.origin,
      sitePublicKey: script.dataset.wayfindrSiteKey,
      visitorExternalId: script.dataset.wayfindrVisitorExternalId,
      launcherLabel: script.dataset.wayfindrLauncherLabel,
      title: script.dataset.wayfindrTitle,
      reverb: reverbOptionsFromScript(script),
    });
  }

  function reverbOptionsFromScript(script) {
    if (!script.dataset.wayfindrReverbAppKey) {
      return null;
    }

    return {
      appKey: script.dataset.wayfindrReverbAppKey,
      host: script.dataset.wayfindrReverbHost || root.location.hostname,
      port: script.dataset.wayfindrReverbPort ? Number(script.dataset.wayfindrReverbPort) : undefined,
      scheme: script.dataset.wayfindrReverbScheme || root.location.protocol.replace(':', ''),
    };
  }

  var api = {
    version: VERSION,
    createClient: createClient,
    createCobrowseSnapshot: createCobrowseSnapshot,
    createCobrowseMutationBatch: createCobrowseMutationBatch,
    init: init,
    normalizeApiBaseUrl: normalizeApiBaseUrl,
    resolveAnonymousId: resolveAnonymousId,
  };

  if (typeof setTimeout === 'function') {
    setTimeout(autoInitFromCurrentScript, 0);
  }

  return api;
});
