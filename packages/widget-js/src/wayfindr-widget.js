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

  function createClient(options) {
    options = options || {};

    var apiBaseUrl = normalizeApiBaseUrl(options.apiBaseUrl || '');
    var sitePublicKey = options.sitePublicKey;
    var anonymousId = options.anonymousId;
    var fetcher = options.fetch || (root && root.fetch ? root.fetch.bind(root) : null);
    var hasStorageOption = Object.prototype.hasOwnProperty.call(options, 'storage');
    var storage = hasStorageOption ? options.storage : null;
    var visitorToken = options.visitorToken || null;
    var realtime = resolveRealtime(options, fetcher);

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
      bootstrap: function (pageUrl) {
        return postJson(fetcher, apiBaseUrl + '/api/widget/bootstrap', {
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          page_url: pageUrl || null,
        }).then(function (result) {
          var token = result && result.visitor ? result.visitor.token : null;

          if (token) {
            visitorToken = token;
            storageSet(storage, visitorTokenStorageKey(sitePublicKey), token);
          }

          return result;
        });
      },
      startConversation: function (body, details) {
        details = details || {};

        return postJson(fetcher, apiBaseUrl + '/api/conversations', {
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          visitor_token: requireVisitorToken(visitorToken),
          subject: details.subject || summarize(body),
          page_url: details.pageUrl || null,
        });
      },
      sendMessage: function (supportCode, body) {
        return postJson(fetcher, apiBaseUrl + '/api/conversations/' + encodeURIComponent(supportCode) + '/messages', {
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          visitor_token: requireVisitorToken(visitorToken),
          body: body,
        });
      },
      fetchMessages: function (supportCode) {
        return getJson(fetcher, apiBaseUrl + '/api/conversations/' + encodeURIComponent(supportCode) + '/messages?' + toQueryString({
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
      subscribeToConversation: function (supportCode, onMessage) {
        if (!realtime) {
          return null;
        }

        return realtime.subscribe({
          supportCode: supportCode,
          channelName: conversationChannelName(supportCode),
          eventName: 'conversation.message.created',
          authEndpoint: apiBaseUrl + '/api/widget/broadcasting/auth',
          authPayload: {
            site_public_key: sitePublicKey,
            anonymous_id: anonymousId,
            visitor_token: requireVisitorToken(visitorToken),
          },
          onMessage: onMessage,
        });
      },
      sendFirstMessage: async function (body, details) {
        details = details || {};

        if (!visitorToken) {
          await this.bootstrap(details.pageUrl || null);
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
      '  <form class="wayfindr-widget__form">',
      '    <label class="wayfindr-widget__label" for="wayfindr-message">How can we help?</label>',
      '    <textarea id="wayfindr-message" class="wayfindr-widget__textarea" name="message" rows="4" required placeholder="' + escapeHtml(options.placeholder || 'Type your message...') + '"></textarea>',
      '    <div class="wayfindr-widget__actions">',
      '      <button class="wayfindr-widget__send" type="submit">Send message</button>',
      '      <button class="wayfindr-widget__refresh" type="button" hidden>Refresh</button>',
      '    </div>',
      '  </form>',
      '  <div class="wayfindr-widget__cobrowse" hidden>',
      '    <p class="wayfindr-widget__cobrowse-copy">Allow support to view this page with sensitive fields masked.</p>',
      '    <button class="wayfindr-widget__cobrowse-toggle" type="button">Allow cobrowse</button>',
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
    var textarea = rootEl.querySelector('.wayfindr-widget__textarea');
    var status = rootEl.querySelector('.wayfindr-widget__status');
    var send = rootEl.querySelector('.wayfindr-widget__send');
    var refresh = rootEl.querySelector('.wayfindr-widget__refresh');
    var cobrowse = rootEl.querySelector('.wayfindr-widget__cobrowse');
    var cobrowseToggle = rootEl.querySelector('.wayfindr-widget__cobrowse-toggle');
    var bootstrapped = false;
    var supportCode = null;
    var messages = [];
    var realtimeSubscription = null;
    var cobrowseGranted = false;

    function renderMessages(nextMessages) {
      messages = Array.isArray(nextMessages) ? nextMessages : messages;
      timeline.textContent = '';

      messages.forEach(function (message) {
        var sender = message.sender || {};
        var senderKind = sender.kind === 'agent' ? 'agent' : 'visitor';
        var item = doc.createElement('article');
        var name = doc.createElement('strong');
        var body = doc.createElement('p');

        item.className = 'wayfindr-widget__message wayfindr-widget__message--' + senderKind;
        name.className = 'wayfindr-widget__message-name';
        name.textContent = sender.name || (senderKind === 'agent' ? 'Support' : 'Visitor');
        body.className = 'wayfindr-widget__message-body';
        body.textContent = message.body || '';

        item.appendChild(name);
        item.appendChild(body);
        timeline.appendChild(item);
      });

      timeline.hidden = messages.length === 0;
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

    function connectRealtime() {
      if (!supportCode || realtimeSubscription) {
        return;
      }

      realtimeSubscription = client.subscribeToConversation(supportCode, function (event) {
        var eventSupportCode = event && event.conversation ? event.conversation.support_code : supportCode;

        if (eventSupportCode !== supportCode) {
          return;
        }

        appendMessage(event.message);
      });
    }

    function renderCobrowseConsent() {
      cobrowse.hidden = !supportCode;
      cobrowseToggle.textContent = cobrowseGranted ? 'Stop cobrowse' : 'Allow cobrowse';
    }

    function estimatePayloadBytes(payload) {
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

    async function toggleCobrowseConsent() {
      if (!supportCode) {
        return;
      }

      var nextGranted = !cobrowseGranted;
      var startedAt = Date.now();

      cobrowseToggle.disabled = true;
      status.textContent = nextGranted ? 'Granting cobrowse consent...' : 'Revoking cobrowse consent...';

      try {
        var result = await client.setCobrowseConsent(supportCode, nextGranted);
        var consent = result && result.cobrowse ? result.cobrowse.consent : null;

        cobrowseGranted = consent === 'granted';
        renderCobrowseConsent();

        if (cobrowseGranted) {
          try {
            await client.reportCobrowseTelemetry(supportCode, {
              rttMs: Date.now() - startedAt,
              payloadBytes: estimatePayloadBytes(result),
              droppedBatches: 0,
              reconnects: 0,
            });
          } catch (error) {
            // Telemetry should never undo a successful consent change.
          }
        }

        status.textContent = cobrowseGranted ? 'Cobrowse consent granted.' : 'Cobrowse consent revoked.';
      } catch (error) {
        status.textContent = error.message || 'Wayfindr could not update cobrowse consent.';
      } finally {
        cobrowseToggle.disabled = false;
      }
    }

    async function refreshMessages(options) {
      options = options || {};

      if (!supportCode) {
        return;
      }

      refresh.disabled = true;

      if (!options.silent) {
        status.textContent = 'Refreshing...';
      }

      try {
        var result = await client.fetchMessages(supportCode);
        renderMessages(result.messages || []);

        if (!options.silent) {
          status.textContent = 'Messages refreshed.';
        }
      } catch (error) {
        status.textContent = error.message || 'Wayfindr could not refresh messages.';
      } finally {
        refresh.disabled = false;
      }
    }

    function open() {
      panel.hidden = false;
      launcher.hidden = true;
      textarea.focus();
    }

    function closePanel() {
      panel.hidden = true;
      launcher.hidden = false;
      launcher.focus();
    }

    launcher.addEventListener('click', open);
    close.addEventListener('click', closePanel);
    refresh.addEventListener('click', function () {
      refreshMessages();
    });
    cobrowseToggle.addEventListener('click', function () {
      toggleCobrowseConsent();
    });
    form.addEventListener('submit', async function (event) {
      event.preventDefault();

      var body = textarea.value.trim();

      if (!body) {
        return;
      }

      send.disabled = true;
      status.textContent = 'Sending...';

      try {
        if (!bootstrapped) {
          await client.bootstrap(location ? location.href : null);
          bootstrapped = true;
        }

        if (supportCode) {
          await client.sendMessage(supportCode, body);
        } else {
          var result = await client.sendFirstMessage(body, {
            pageUrl: location ? location.href : null,
          });

          supportCode = result.conversation.support_code;
          connectRealtime();
          renderCobrowseConsent();
        }

        textarea.value = '';
        await refreshMessages({ silent: true });
        status.textContent = 'Message sent. Support code ' + supportCode + '.';
      } catch (error) {
        status.textContent = error.message || 'Wayfindr could not send that message.';
      } finally {
        send.disabled = false;
      }
    });

    return {
      anonymousId: client.anonymousId,
      client: client,
      root: rootEl,
      open: open,
      close: closePanel,
      destroy: function () {
        if (realtimeSubscription && typeof realtimeSubscription.unsubscribe === 'function') {
          realtimeSubscription.unsubscribe();
        }

        rootEl.remove();
      },
    };
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

        channel.bind(config.eventName, config.onMessage);

        return {
          unsubscribe: function () {
            channel.unbind(config.eventName, config.onMessage);
            pusher.unsubscribe(config.channelName);

            if (typeof pusher.disconnect === 'function') {
              pusher.disconnect();
            }
          },
        };
      },
    };
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
      '.wayfindr-widget__launcher,.wayfindr-widget__send{border:0;border-radius:999px;background:#0d6f68;color:#fff;box-shadow:0 12px 30px rgba(8,37,34,.18);cursor:pointer;font:700 14px/1 ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}',
      '.wayfindr-widget__launcher{min-height:48px;padding:0 18px}',
      '.wayfindr-widget__send{min-height:40px;padding:0 14px;border-radius:6px}',
      '.wayfindr-widget__launcher:hover,.wayfindr-widget__send:hover{background:#094f4b}',
      '.wayfindr-widget__send:disabled{cursor:wait;opacity:.7}',
      '.wayfindr-widget__panel{width:min(360px,calc(100vw - 32px));border:1px solid #d8dfdc;border-radius:8px;background:#fff;box-shadow:0 20px 55px rgba(8,37,34,.2);overflow:hidden}',
      '.wayfindr-widget__header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid #d8dfdc;background:#f7f7f3}',
      '.wayfindr-widget__close{border:0;background:transparent;color:#62706b;cursor:pointer;font:700 24px/1 ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:0}',
      '.wayfindr-widget__timeline{display:grid;gap:10px;max-height:280px;overflow:auto;padding:14px 16px;border-bottom:1px solid #eef1ef;background:#fbfbf8}',
      '.wayfindr-widget__message{display:grid;gap:4px;width:88%;border:1px solid #d8dfdc;border-radius:8px;padding:9px 10px;background:#fff}',
      '.wayfindr-widget__message--agent{justify-self:end;background:#eef6f3;border-color:#cfe1dc}',
      '.wayfindr-widget__message-name{color:#62706b;font-size:12px;line-height:1.2}',
      '.wayfindr-widget__message-body{margin:0;white-space:pre-wrap;color:#1d2523;font-size:14px;line-height:1.4}',
      '.wayfindr-widget__form{display:grid;gap:10px;padding:16px}',
      '.wayfindr-widget__label{font-size:13px;font-weight:700}',
      '.wayfindr-widget__textarea{width:100%;resize:vertical;border:1px solid #d8dfdc;border-radius:6px;padding:10px;color:#1d2523;font:14px/1.4 ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}',
      '.wayfindr-widget__textarea:focus{outline:3px solid rgba(13,111,104,.2);border-color:#0d6f68}',
      '.wayfindr-widget__actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}',
      '.wayfindr-widget__refresh{min-height:40px;border:1px solid #d8dfdc;border-radius:6px;background:#fff;color:#1d2523;cursor:pointer;padding:0 12px;font:700 14px/1 ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}',
      '.wayfindr-widget__refresh:hover{border-color:#0d6f68;color:#0d6f68}',
      '.wayfindr-widget__refresh:disabled{cursor:wait;opacity:.7}',
      '.wayfindr-widget__cobrowse{display:grid;gap:8px;padding:0 16px 16px}',
      '.wayfindr-widget__cobrowse-copy{margin:0;color:#62706b;font-size:13px;line-height:1.35}',
      '.wayfindr-widget__cobrowse-toggle{justify-self:start;min-height:36px;border:1px solid #d8dfdc;border-radius:6px;background:#fff;color:#1d2523;cursor:pointer;padding:0 12px;font:700 13px/1 ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}',
      '.wayfindr-widget__cobrowse-toggle:hover{border-color:#0d6f68;color:#0d6f68}',
      '.wayfindr-widget__cobrowse-toggle:disabled{cursor:wait;opacity:.7}',
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
    init: init,
    normalizeApiBaseUrl: normalizeApiBaseUrl,
    resolveAnonymousId: resolveAnonymousId,
  };

  if (typeof setTimeout === 'function') {
    setTimeout(autoInitFromCurrentScript, 0);
  }

  return api;
});
