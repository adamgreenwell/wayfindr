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
  // Everything a visitor can read, in every language this widget speaks.
  //
  // Catalogues are inlined rather than fetched or bundled per locale, and that
  // is deliberate. The widget has no build step and is served from source by a
  // Laravel controller, so a separate locale file would be one more artifact
  // that has to reach the Docker image -- exactly how the vendored realtime
  // client once went missing and left the widget silently degraded. A string
  // that ships inside the only file being served cannot fail to arrive.
  //
  // The cost is size, and it is bounded: a catalogue is roughly 3 KB. If this
  // ever grows past a handful of languages, `WidgetScriptController` already
  // splices content into the response and can splice one catalogue instead --
  // the seam is this object and nothing else.
  //
  // `en` is the source of truth. Every other catalogue must carry exactly the
  // same keys, which a test enforces rather than trusting review to notice.
  var MESSAGES = {
    en: {
      'launcher.label': 'Chat with support',
      'launcher.sharingAria': '{label} — sharing this page with support',
      'panel.aria': 'Support chat',
      'header.title': 'Wayfindr Support',
      'header.close': 'Close support chat',
      'timeline.aria': 'Conversation messages',
      'timeline.jump': 'New messages ↓',
      'notice.emptyVisitor': 'No messages yet. Send a message and support will see it here.',
      'notice.emptyAgent': 'No messages yet. Replies will show up here.',
      'notice.closed': 'This conversation was closed. Send a new message to reopen it.',
      'presence.disclosure': 'This site can see which of its pages you are on while this widget is loaded.',
      'presence.disclosureNoPage': 'This site can see that you are here while this widget is loaded. It is not told which page you are on.',
      'presence.decline': 'Stop sharing',
      'presence.declined': 'Not sharing which pages you visit.',
      'notice.retry': 'Try again',
      'form.label': 'How can we help?',
      'form.placeholder': 'Type your message...',
      'form.send': 'Send message',
      'form.refresh': 'Refresh',
      'attachments.aria': 'Files to send',
      'attachment.attachAria': 'Attach a file',
      'attachment.attach': 'Attach',
      'attachment.uploading': 'Uploading…',
      'attachment.remove': 'Remove {filename}',
      'attachment.fallbackName': 'Attachment',
      'sender.support': 'Support',
      'sender.visitor': 'Visitor',
      'receipt.aria': 'Visitor message sent to support',
      'receipt.label': 'Sent to support',
      'connection.connected': 'Live updates connected.',
      'connection.reconnecting': 'Live updates reconnecting. Refresh still works.',
      'connection.polling': 'Using periodic refresh for updates.',
      'connection.trouble': 'Having trouble reaching support. Your chat is still here; refresh will try again.',
      'status.sending': 'Sending...',
      'status.refreshing': 'Refreshing...',
      'status.refreshed': 'Messages refreshed.',
      'status.waitingUploads': 'Waiting for uploads to finish…',
      'status.messageSent': 'Message sent. Support code {code}.',
      'status.conversationRestored': 'Conversation restored. Support code {code}.',
      'error.send': 'Message could not be sent. Your text is still here so you can try again.',
      'error.refresh': 'Messages could not be refreshed. Your current chat is still visible.',
      'error.attachment': 'That file could not be attached.',
      'error.requestFailed': 'Wayfindr request failed with status {status}.',
      'intake.pending': 'Please answer the questions above first. Your message is still here.',
      'intake.submit': 'Continue',
      'help.label': 'Find an answer',
      'help.placeholder': 'Search help',
      'help.searching': 'Searching…',
      'help.none': 'Nothing matches that yet. Send a message and support will answer.',
      'help.back': 'Back to results',
      'help.failed': 'Could not search just now.',
      'rating.intro': 'How did that go?',
      'rating.good': 'Good',
      'rating.ok': 'Okay',
      'rating.bad': 'Badly',
      'rating.comment': 'Anything you would like to add? (optional)',
      'rating.send': 'Send',
      'rating.thanks': 'Thank you — that helps.',
      'rating.failed': 'That could not be sent.',
      'intake.optional': '{label} (optional)',
      'intake.checkDetails': 'Please check the details above.',
      'intake.required': 'Please fill in the required fields.',
      'intake.field.name': 'Your name',
      'intake.field.email': 'Your email',
      'intake.field.reason': 'What is this about?',
      'cobrowse.aria': 'Cobrowse request',
      'cobrowse.request': 'Support wants to view this page with sensitive fields masked.',
      'cobrowse.requestFrom': '{requester} wants to view this page with sensitive fields masked.',
      'cobrowse.allow': 'Allow cobrowse',
      'cobrowse.decline': 'Decline',
      'cobrowse.stop': 'Stop cobrowse',
      'cobrowse.active': 'Cobrowse is active. Sensitive fields stay masked.',
      'cobrowse.catchingUp': 'Wayfindr is catching up with recent page changes. Sensitive fields stay masked.',
      'cobrowse.granting': 'Granting cobrowse consent...',
      'cobrowse.revoking': 'Revoking cobrowse consent...',
      'cobrowse.granted': 'Cobrowse consent granted.',
      'cobrowse.revoked': 'Cobrowse consent revoked.',
      'cobrowse.stoppedBySupport': 'Cobrowse stopped by support.',
      'cobrowse.stopped': 'Cobrowse stopped.',
      'cobrowse.declined': 'Cobrowse request declined.',
      'cobrowse.consentFailed': 'Wayfindr could not update cobrowse consent.',
      'cobrowse.statusFailed': 'Wayfindr could not refresh cobrowse status.',
      'date.today': 'Today',
      'date.yesterday': 'Yesterday',
      'away.default': 'Support is away right now. Leave a message and we will reply when we are back.',
      'away.back': 'Back {when}.',
    },
    de: {
      'launcher.label': 'Chat mit dem Support',
      'launcher.sharingAria': '{label} – diese Seite wird mit dem Support geteilt',
      'panel.aria': 'Support-Chat',
      'header.title': 'Wayfindr Support',
      'header.close': 'Support-Chat schließen',
      'timeline.aria': 'Nachrichtenverlauf',
      'timeline.jump': 'Neue Nachrichten ↓',
      'notice.emptyVisitor': 'Noch keine Nachrichten. Schreiben Sie uns, der Support sieht Ihre Nachricht hier.',
      'notice.emptyAgent': 'Noch keine Nachrichten. Antworten erscheinen hier.',
      'notice.closed': 'Diese Unterhaltung wurde geschlossen. Senden Sie eine neue Nachricht, um sie wieder zu öffnen.',
      'presence.disclosure': 'Diese Website kann sehen, auf welchen ihrer Seiten Sie sich befinden, solange dieses Widget geladen ist.',
      'presence.disclosureNoPage': 'Diese Website kann sehen, dass Sie hier sind, solange dieses Widget geladen ist. Welche Seite Sie ansehen, erfährt sie nicht.',
      'presence.decline': 'Nicht mehr teilen',
      'presence.declined': 'Es wird nicht geteilt, welche Seiten Sie besuchen.',
      'notice.retry': 'Erneut versuchen',
      'form.label': 'Wie können wir helfen?',
      'form.placeholder': 'Nachricht eingeben …',
      'form.send': 'Nachricht senden',
      'form.refresh': 'Aktualisieren',
      'attachments.aria': 'Dateien zum Senden',
      'attachment.attachAria': 'Datei anhängen',
      'attachment.attach': 'Anhängen',
      'attachment.uploading': 'Wird hochgeladen …',
      'attachment.remove': '{filename} entfernen',
      'attachment.fallbackName': 'Anhang',
      'sender.support': 'Support',
      'sender.visitor': 'Besucher',
      'receipt.aria': 'Besuchernachricht an den Support gesendet',
      'receipt.label': 'An den Support gesendet',
      'connection.connected': 'Live-Aktualisierung verbunden.',
      'connection.reconnecting': 'Live-Aktualisierung verbindet neu. Aktualisieren funktioniert weiterhin.',
      'connection.polling': 'Aktualisierung erfolgt in regelmäßigen Abständen.',
      'connection.trouble': 'Der Support ist gerade schwer erreichbar. Ihr Chat bleibt erhalten; beim Aktualisieren versuchen wir es erneut.',
      'status.sending': 'Wird gesendet …',
      'status.refreshing': 'Wird aktualisiert …',
      'status.refreshed': 'Nachrichten aktualisiert.',
      'status.waitingUploads': 'Warten auf den Abschluss der Uploads …',
      'status.messageSent': 'Nachricht gesendet. Support-Code {code}.',
      'status.conversationRestored': 'Unterhaltung wiederhergestellt. Support-Code {code}.',
      'error.send': 'Die Nachricht konnte nicht gesendet werden. Ihr Text ist noch da, Sie können es erneut versuchen.',
      'error.refresh': 'Die Nachrichten konnten nicht aktualisiert werden. Ihr Chat ist weiterhin sichtbar.',
      'error.attachment': 'Diese Datei konnte nicht angehängt werden.',
      'error.requestFailed': 'Wayfindr-Anfrage fehlgeschlagen mit Status {status}.',
      'intake.pending': 'Bitte beantworten Sie zuerst die Fragen oben. Ihre Nachricht bleibt erhalten.',
      'intake.submit': 'Weiter',
      'help.label': 'Antwort finden',
      'help.placeholder': 'Hilfe durchsuchen',
      'help.searching': 'Wird gesucht …',
      'help.none': 'Dazu gibt es noch nichts. Schreiben Sie uns, der Support antwortet.',
      'help.back': 'Zurück zu den Ergebnissen',
      'help.failed': 'Suche gerade nicht möglich.',
      'rating.intro': 'Wie ist es gelaufen?',
      'rating.good': 'Gut',
      'rating.ok': 'Ganz okay',
      'rating.bad': 'Schlecht',
      'rating.comment': 'Möchten Sie noch etwas ergänzen? (optional)',
      'rating.send': 'Senden',
      'rating.thanks': 'Vielen Dank — das hilft uns.',
      'rating.failed': 'Das konnte nicht gesendet werden.',
      'intake.optional': '{label} (optional)',
      'intake.checkDetails': 'Bitte prüfen Sie die Angaben oben.',
      'intake.required': 'Bitte füllen Sie die Pflichtfelder aus.',
      'intake.field.name': 'Ihr Name',
      'intake.field.email': 'Ihre E-Mail-Adresse',
      'intake.field.reason': 'Worum geht es?',
      'cobrowse.aria': 'Cobrowsing-Anfrage',
      'cobrowse.request': 'Der Support möchte diese Seite ansehen. Sensible Felder bleiben maskiert.',
      'cobrowse.requestFrom': '{requester} möchte diese Seite ansehen. Sensible Felder bleiben maskiert.',
      'cobrowse.allow': 'Cobrowsing erlauben',
      'cobrowse.decline': 'Ablehnen',
      'cobrowse.stop': 'Cobrowsing beenden',
      'cobrowse.active': 'Cobrowsing ist aktiv. Sensible Felder bleiben maskiert.',
      'cobrowse.catchingUp': 'Wayfindr holt die letzten Seitenänderungen nach. Sensible Felder bleiben maskiert.',
      'cobrowse.granting': 'Einwilligung wird erteilt …',
      'cobrowse.revoking': 'Einwilligung wird widerrufen …',
      'cobrowse.granted': 'Einwilligung zum Cobrowsing erteilt.',
      'cobrowse.revoked': 'Einwilligung zum Cobrowsing widerrufen.',
      'cobrowse.stoppedBySupport': 'Cobrowsing wurde vom Support beendet.',
      'cobrowse.stopped': 'Cobrowsing beendet.',
      'cobrowse.declined': 'Cobrowsing-Anfrage abgelehnt.',
      'cobrowse.consentFailed': 'Wayfindr konnte die Cobrowsing-Einwilligung nicht aktualisieren.',
      'cobrowse.statusFailed': 'Wayfindr konnte den Cobrowsing-Status nicht aktualisieren.',
      'date.today': 'Heute',
      'date.yesterday': 'Gestern',
      'away.default': 'Der Support ist gerade nicht erreichbar. Hinterlassen Sie eine Nachricht, wir melden uns, sobald wir zurück sind.',
      'away.back': 'Zurück {when}.',
    },
  };

  var DEFAULT_LOCALE = 'en';

  // Scripts whose languages run right to left. Kept as a list because the
  // browser will not tell us: `Intl.Locale.textInfo` is recent enough that the
  // browsers this widget still supports do not all have it, and a widget that
  // renders backwards is worse than one carrying a short list.
  var RTL_LANGUAGES = ['ar', 'ckb', 'dv', 'fa', 'he', 'ps', 'sd', 'ug', 'ur', 'yi'];

  function normaliseLocale(value) {
    return typeof value === 'string' ? value.trim().toLowerCase().replace(/_/g, '-') : '';
  }

  function baseLanguage(locale) {
    return normaliseLocale(locale).split('-')[0];
  }

  /**
   * The catalogue that best answers a locale tag, or null.
   *
   * `de-AT` falls back to `de` rather than to English: a regional variant we do
   * not carry is still that language.
   */
  function matchCatalogue(value) {
    var wanted = normaliseLocale(value);

    if (!wanted) {
      return null;
    }

    if (MESSAGES[wanted]) {
      return wanted;
    }

    var base = baseLanguage(wanted);

    return MESSAGES[base] ? base : null;
  }

  /**
   * Which language this visitor reads.
   *
   * In order: what the host page asked for, then what the visitor's own browser
   * says, then the site's configured default, then English.
   *
   * The browser outranks the site default on purpose. The default is the
   * operator's guess at who visits; the browser is the visitor answering for
   * themselves. The host page outranks both because an application that has
   * signed someone in knows better than either.
   */
  function resolveLocale(preferences) {
    var candidates = [preferences.requested]
      .concat(preferences.navigatorLanguages || [])
      .concat([preferences.siteDefault]);

    for (var index = 0; index < candidates.length; index++) {
      var match = matchCatalogue(candidates[index]);

      if (match) {
        return match;
      }
    }

    return DEFAULT_LOCALE;
  }

  function navigatorLanguages(nav) {
    if (!nav) {
      return [];
    }

    if (Array.isArray(nav.languages) && nav.languages.length) {
      return nav.languages;
    }

    return nav.language ? [nav.language] : [];
  }

  /**
   * The locale as Intl wants it, or nothing.
   *
   * Passing `undefined` makes Intl use the browser's locale, which is the right
   * fallback: it is still a language the visitor reads, just not the one the
   * widget is speaking.
   */
  function localeTags(locale) {
    var tag = normaliseLocale(locale);

    return tag ? [tag] : undefined;
  }

  /**
   * One decimal place, in the visitor's convention.
   *
   * A German visitor reads 1,4 MB rather than 1.4 MB, and toFixed() cannot say
   * so. Falls back to toFixed() where Intl is missing, because a file size in
   * the wrong convention still beats no file size.
   */
  function formatDecimal(locale, value) {
    try {
      return new Intl.NumberFormat(localeTags(locale), {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
      }).format(value);
    } catch (error) {
      reportSuppressed('number formatting', error);

      return value.toFixed(1);
    }
  }

  function isRtlLocale(locale) {
    return RTL_LANGUAGES.indexOf(baseLanguage(locale)) !== -1;
  }

  /**
   * A translator bound to one widget instance.
   *
   * Instance-scoped rather than module-scoped because two widgets can share a
   * page, and a module-level current locale would make the second one silently
   * retranslate the first.
   */
  function createTranslator(locale) {
    var active = matchCatalogue(locale) || DEFAULT_LOCALE;

    function t(key, params) {
      var template = MESSAGES[active][key];

      if (typeof template !== 'string') {
        template = MESSAGES[DEFAULT_LOCALE][key];
      }

      // Missing from English too means the key is wrong, which is a bug rather
      // than a translation gap. Showing it beats showing nothing.
      if (typeof template !== 'string') {
        return key;
      }

      if (!params) {
        return template;
      }

      return template.replace(/\{(\w+)\}/g, function (whole, name) {
        return Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : whole;
      });
    }

    t.locale = active;
    t.isRtl = isRtlLocale(active);
    t.direction = t.isRtl ? 'rtl' : 'ltr';

    return t;
  }

  // The file picker's accept hint (mobile shows the camera for image/*). The
  // server enforces the real allowlist by sniffing bytes regardless of this.
  var ATTACHMENT_ACCEPT = 'image/*,application/pdf,text/plain,.txt,.log';

  // A caught error still has to be findable.
  //
  // Every `catch` here swallows deliberately, because the visitor should not
  // see a stack trace over a chat box -- but swallowing it SILENTLY left
  // nothing in the console either, so a fault could only be located by
  // stepping through the widget with "pause on caught exceptions". That cost
  // a real support-loop investigation. The visitor-facing behaviour is
  // unchanged; the console now names what failed.
  function reportSuppressed(what, error) {
    if (root && root.console && typeof root.console.error === 'function') {
      root.console.error('[wayfindr] ' + what + ' failed:', error);
    }
  }
  var DEFAULT_COBROWSE_PAYLOAD_BUDGET = {
    mutationBatchMaxBytes: 60000,
    mutationQueueMaxRecords: 250,
    mutationFlushMs: 50,
    pressureResyncMs: 30000,
    statusPollMs: 5000,
    resyncMaxAttempts: 3,
  };
  var MESSAGE_GROUP_WINDOW_MS = 5 * 60 * 1000;
  var AGENT_TYPING_FRESH_MS = 20 * 1000;
  var widgetInstanceCount = 0;
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
    '.wayfindr-widget',
  ];

  // Inline SVG is markup, not a network fetch, so it can survive capture when
  // hard-sanitized: elements and attributes are allowlisted, everything else
  // (script, foreignObject, image, animate, event handlers, external hrefs) is
  // dropped with its subtree. The server sanitizer applies the same rules as
  // the enforcement boundary.
  var SVG_ALLOWED_ELEMENTS = [
    'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline',
    'polygon', 'text', 'tspan', 'defs', 'lineargradient', 'radialgradient',
    'stop', 'symbol', 'use', 'clippath', 'title', 'desc',
  ];

  var SVG_ALLOWED_ATTRIBUTES = [
    'viewbox', 'xmlns', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2',
    'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points', 'fill', 'stroke',
    'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray',
    'stroke-miterlimit', 'fill-rule', 'clip-rule', 'clip-path', 'opacity',
    'fill-opacity', 'stroke-opacity', 'transform', 'offset', 'stop-color',
    'stop-opacity', 'gradientunits', 'gradienttransform',
    'preserveaspectratio', 'aria-hidden', 'role', 'class', 'id',
    'href', 'xlink:href',
  ];

  // A huge inline SVG is more likely a data visualization than a logo, and it
  // would eat the snapshot HTML budget; drop it entirely rather than partially.
  var SVG_MAX_ELEMENTS = 200;

  // Attributes copied from a replaced <img> onto its placeholder so the
  // masking passes (explicit selectors and inferred sensitive terms) still see
  // the markers they match on.
  var IMAGE_PLACEHOLDER_COPIED_ATTRIBUTES = [
    'id', 'class', 'aria-label', 'data-wayfindr-mask', 'data-wayfindr-private',
    'data-secret', 'data-field', 'data-wayfindr-field', 'data-testid',
    'data-test', 'data-cy',
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
    var storage = resolveStorageOption(options);
    var visitorToken = options.visitorToken || null;
    // A function, not a value: the panel re-resolves its language when
    // bootstrap returns the site default, so a locale captured at construction
    // would be the one we had before we knew anything.
    var currentLocale = typeof options.locale === 'function' ? options.locale : null;
    var realtime = resolveRealtime(options, fetcher);
    var maskSelectors = [];
    var sensitiveTerms = [];

    if (!apiBaseUrl) {
      throw new Error('Wayfindr requires an apiBaseUrl option.');
    }

    if (!sitePublicKey) {
      throw new Error('Wayfindr requires a sitePublicKey option.');
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

    var bootstrapTicket = 0;

    return {
      anonymousId: anonymousId,
      sitePublicKey: sitePublicKey,
      bootstrap: function (pageUrl, context) {
        var ticket = ++bootstrapTicket;

        return postJson(fetcher, apiBaseUrl + '/api/widget/bootstrap', withVisitorContext({
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          page_url: pageUrl || null,
        }, context, visitorExternalId)).then(function (result) {
          // Overlapping bootstraps finishing out of order would otherwise let
          // an older answer restore obsolete masking rules -- and a stale mask
          // is a field the visitor believes is protected. The client sequences
          // its own state for the same reason the panel sequences its own:
          // this applies to host pages calling bootstrap() directly too, which
          // a caller-applied version would have left unguarded.
          if (ticket !== bootstrapTicket) {
            return result;
          }

          var token = result && result.visitor ? result.visitor.token : null;

          if (token) {
            visitorToken = token;
            storageSet(storage, visitorTokenStorageKey(sitePublicKey), token);
          }

          maskSelectors = siteMaskSelectors(result);
          sensitiveTerms = siteSensitiveTerms(result);

          return result;
        });
      },
      // Somebody is on the site. Public and unauthenticated by necessity: a
      // visitor who has never made contact has no token, and that is the whole
      // population this reports.
      reportPresence: function (pageUrl) {
        return postJson(fetcher, apiBaseUrl + '/api/widget/presence', withoutNullValues({
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          // Sanitised HERE, not only by the widget that usually calls it.
          // createClient() is a public integration surface, so a host calling
          // client.reportPresence(window.location.href) would otherwise put a
          // reset token on the wire -- which is the one thing client-side
          // sanitising exists to prevent.
          page_url: sanitisePageUrl(pageUrl),
        }));
      },
      startConversation: function (body, details) {
        details = details || {};
        var externalId = normalizeVisitorExternalId(details.visitorExternalId) || visitorExternalId;

        var payload = withVisitorContext({
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          visitor_token: requireVisitorToken(visitorToken),
          subject: details.subject || summarize(body),
          page_url: details.pageUrl || null,
        }, details.context, externalId);

        // The FIRST conversation runs the site's intake rules, and their
        // failures are the first words a new visitor ever reads from us.
        // Omitted rather than sent as null when there is nothing to say, so a
        // caller driving the client directly sends the payload it always did.
        if (currentLocale && currentLocale()) {
          payload.locale = currentLocale();
        }

        // Only fields the site actually asked for. Sending a blank key for a
        // field it does not ask for is refused by the server, and rightly.
        Object.keys(details.intake || {}).forEach(function (key) {
          payload[key] = details.intake[key];
        });

        return postJson(fetcher, apiBaseUrl + '/api/conversations', payload);
      },
      sendMessage: function (supportCode, body, clientMessageId, attachmentIds) {
        return postJson(fetcher, apiBaseUrl + '/api/conversations/' + encodeURIComponent(supportCode) + '/messages', withoutNullValues({
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          visitor_token: requireVisitorToken(visitorToken),
          body: body || null,
          client_message_id: clientMessageId || null,
          attachment_ids: (attachmentIds && attachmentIds.length) ? attachmentIds : null,
          // What WE resolved, which the server cannot: it sees the site
          // default, never the host page's choice or the visitor's browser.
          // withoutNullValues drops it when there is nothing to say.
          locale: (currentLocale && currentLocale()) || null,
        }));
      },
      uploadAttachment: function (supportCode, file) {
        var FormDataCtor = root && root.FormData ? root.FormData : (typeof FormData !== 'undefined' ? FormData : null);

        if (!FormDataCtor) {
          return Promise.reject(new Error('Wayfindr requires FormData support to upload attachments.'));
        }

        var form = new FormDataCtor();
        form.append('site_public_key', sitePublicKey);
        form.append('anonymous_id', anonymousId);
        form.append('visitor_token', requireVisitorToken(visitorToken));
        form.append('file', file);

        if (currentLocale && currentLocale()) {
          form.append('locale', currentLocale());
        }

        return postForm(fetcher, apiBaseUrl + '/api/conversations/' + encodeURIComponent(supportCode) + '/attachments', form);
      },
      // The download URL an <img> or link can point at. The visitor session
      // params ride in the query string, exactly as they do for fetchMessages;
      // the server streams the file with a forced attachment disposition.
      attachmentDownloadUrl: function (supportCode, attachmentId) {
        return apiBaseUrl + '/api/conversations/' + encodeURIComponent(supportCode) + '/attachments/' + encodeURIComponent(attachmentId) + '?' + toQueryString({
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          visitor_token: requireVisitorToken(visitorToken),
        });
      },
      // Delete a not-yet-sent upload (server only removes an unbound attachment
      // this visitor owns), freeing the conversation quota it held.
      deleteAttachment: function (supportCode, attachmentId) {
        return fetcher(this.attachmentDownloadUrl(supportCode, attachmentId), {
          method: 'DELETE',
          headers: {
            Accept: 'application/json',
          },
        }).then(function (response) {
          if (! response.ok) {
            throw responseError(response, {});
          }

          return true;
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

          if (details.seenMessageId) {
            params.seen_message_id = String(details.seenMessageId);
          }
        }

        return getJson(fetcher, apiBaseUrl + '/api/conversations/' + encodeURIComponent(supportCode) + '/messages?' + toQueryString(params));
      },
      fetchAppearance: function () {
        return getJson(fetcher, apiBaseUrl + '/api/widget/appearance?' + toQueryString({
          site_public_key: sitePublicKey,
        }));
      },
      rateConversation: function (supportCode, score, comment, episode) {
        return postJson(fetcher, apiBaseUrl + '/api/conversations/' + encodeURIComponent(supportCode) + '/rating', {
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          visitor_token: requireVisitorToken(visitorToken),
          score: score,
          comment: comment || null,
          // Which close this answer is about. The server refuses it if the
          // conversation has since been reopened and closed again, rather than
          // attributing an answer about finished work to newer work.
          episode: episode,
        });
      },
      searchArticles: function (query) {
        return getJson(fetcher, apiBaseUrl + '/api/widget/articles?' + toQueryString({
          site_public_key: sitePublicKey,
          q: query || '',
        }));
      },
      fetchArticle: function (slug) {
        return getJson(fetcher, apiBaseUrl + '/api/widget/articles/' + encodeURIComponent(slug) + '?' + toQueryString({
          site_public_key: sitePublicKey,
        }));
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
          resync_request_id: telemetry.resyncRequestId,
          resync_attempts_exhausted: telemetry.resyncAttemptsExhausted,
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

        return postJson(fetcher, apiBaseUrl + '/api/conversations/' + encodeURIComponent(supportCode) + '/cobrowse-snapshot', withoutNullValues({
          site_public_key: sitePublicKey,
          anonymous_id: anonymousId,
          visitor_token: requireVisitorToken(visitorToken),
          page_url: snapshot.pageUrl,
          title: snapshot.title,
          html: snapshot.html,
          text: snapshot.text,
          body_style: snapshot.bodyStyle || null,
          node_count: snapshot.nodeCount,
          masked_count: snapshot.maskedCount,
          mutation_sequence: snapshot.mutationSequence,
          resync_request_id: snapshot.resyncRequestId,
          // The site-level masking ruleset this widget actually masked with —
          // the one cached from bootstrap, which can differ from the site's
          // current settings if an admin edits them mid-session. The server
          // audits it as capture-time provenance.
          mask_selectors: maskSelectors,
          sensitive_terms: sensitiveTerms,
        }));
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
      getSensitiveTerms: function () {
        return sensitiveTerms.slice();
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
        var message = await this.sendMessage(conversation.support_code, body, details.clientMessageId || generateClientMessageId());

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

    // Resolved before anything is drawn. The chrome is built once, at init,
    // and bootstrap has not happened yet -- so a locale that only arrived with
    // the site payload would show every visitor an English launcher first and
    // correct it afterwards. The site's configured default is folded in later
    // by applyLocale(), and only matters when neither the host page nor the
    // browser has said anything.
    var localePreferences = {
      requested: options.locale,
      navigatorLanguages: navigatorLanguages(options.navigator || (root ? root.navigator : null)),
      siteDefault: null,
    };
    var t = createTranslator(resolveLocale(localePreferences));

    var client = createClient({
      apiBaseUrl: options.apiBaseUrl,
      sitePublicKey: options.sitePublicKey,
      // `t` is reassigned by applyLocale, so this reads the language in force
      // now rather than the one we started with.
      locale: function () {
        return t.locale;
      },
      anonymousId: options.anonymousId,
      visitorExternalId: options.visitorExternalId,
      fetch: options.fetch,
      // Resolve here rather than forwarding options.storage blindly: property
      // access would flatten an inherited value into an own property on the
      // client config, bypassing the own-property guard in resolveStorageOption.
      storage: resolveStorageOption(options),
      visitorToken: options.visitorToken,
      realtime: options.realtime,
      reverb: options.reverb,
      Pusher: options.Pusher,
    });

    injectStyles(doc);

    /**
     * What to show a visitor for a failure.
     *
     * A server-authored message is shown as the server wrote it. A failure the
     * widget generated carries a key instead, so it can be said in the
     * visitor's language rather than leaking an English sentence into an
     * otherwise translated panel.
     */
    function errorText(error, fallbackKey) {
      if (error && error.wayfindrKey) {
        return t(error.wayfindrKey, error.wayfindrParams);
      }

      if (error && typeof error.message === 'string' && error.message) {
        return error.message;
      }

      return t(fallbackKey);
    }

    var mount = resolveMount(doc, options.mount);
    var panelId = 'wayfindr-support-panel-' + (++widgetInstanceCount);
    var helpId = panelId + '-help';
    var ratingId = panelId + '-rating';
    var cobrowseCopyId = panelId + '-cobrowse-copy';
    var rootEl = doc.createElement('div');
    rootEl.className = 'wayfindr-widget';
    // Set on the widget rather than the page: a German widget on an English
    // site is ordinary, and an Arabic one has to lay out right-to-left inside a
    // left-to-right host without touching the host's own direction.
    rootEl.lang = t.locale;
    rootEl.dir = t.direction;
    rootEl.innerHTML = [
      '<button class="wayfindr-widget__launcher" type="button" aria-controls="' + escapeHtml(panelId) + '" aria-expanded="false">' + escapeHtml(options.launcherLabel || t('launcher.label')) + '</button>',
      // OUTSIDE the panel, deliberately. The visitors this feature exists to
      // see are the ones who never open the widget, so a notice that only
      // appears once they do is not a disclosure -- it is an explanation
      // offered to the people it does not apply to. ADR 0019 §2.
      '<div class="wayfindr-widget__presence" role="status" aria-live="polite" hidden>',
      '  <p class="wayfindr-widget__presence-copy">' + escapeHtml(t('presence.disclosure')) + '</p>',
      '  <button class="wayfindr-widget__presence-decline" type="button">' + escapeHtml(t('presence.decline')) + '</button>',
      '</div>',
      '<section id="' + escapeHtml(panelId) + '" class="wayfindr-widget__panel" aria-label="' + escapeHtml(t('panel.aria')) + '" hidden>',
      '  <header class="wayfindr-widget__header">',
      '    <strong>' + escapeHtml(options.title || t('header.title')) + '</strong>',
      '    <button class="wayfindr-widget__close" type="button" aria-label="' + escapeHtml(t('header.close')) + '">&times;</button>',
      '  </header>',
      '  <div class="wayfindr-widget__away" role="status" aria-live="polite" hidden></div>',
      '  <form class="wayfindr-widget__intake" hidden>',
      '    <p class="wayfindr-widget__intake-intro"></p>',
      '    <div class="wayfindr-widget__intake-fields"></div>',
      '    <p class="wayfindr-widget__intake-error" role="alert" hidden></p>',
      '    <button class="wayfindr-widget__intake-submit" type="submit">' + escapeHtml(t('intake.submit')) + '</button>',
      '  </form>',
      '  <div class="wayfindr-widget__help" hidden>',
      '    <label class="wayfindr-widget__help-label" for="' + escapeHtml(helpId) + '">' + escapeHtml(t('help.label')) + '</label>',
      '    <input class="wayfindr-widget__help-input" id="' + escapeHtml(helpId) + '" type="search" autocomplete="off" placeholder="' + escapeHtml(t('help.placeholder')) + '">',
      '    <p class="wayfindr-widget__help-status" role="status" aria-live="polite" hidden></p>',
      '    <ul class="wayfindr-widget__help-results" hidden></ul>',
      '    <div class="wayfindr-widget__help-article" hidden>',
      '      <button class="wayfindr-widget__help-back" type="button">' + escapeHtml(t('help.back')) + '</button>',
      '      <div class="wayfindr-widget__help-blocks"></div>',
      '    </div>',
      '  </div>',
      '  <div class="wayfindr-widget__timeline-wrap">',
      '    <div class="wayfindr-widget__timeline" role="log" aria-live="polite" aria-relevant="additions text" aria-atomic="false" aria-label="' + escapeHtml(t('timeline.aria')) + '" hidden></div>',
      '    <button class="wayfindr-widget__jump" type="button" hidden>' + escapeHtml(t('timeline.jump')) + '</button>',
      '  </div>',
      '  <div class="wayfindr-widget__notice" data-state="empty" role="status" aria-live="polite" aria-atomic="true">',
      '    <p class="wayfindr-widget__notice-copy">' + escapeHtml(t('notice.emptyVisitor')) + '</p>',
      '    <button class="wayfindr-widget__notice-retry" type="button" hidden>' + escapeHtml(t('notice.retry')) + '</button>',
      '  </div>',
      '  <form class="wayfindr-widget__rating" hidden>',
      '    <p class="wayfindr-widget__rating-intro"></p>',
      '    <div class="wayfindr-widget__rating-scores">',
      '      <button class="wayfindr-widget__rating-score" type="button" data-score="good"></button>',
      '      <button class="wayfindr-widget__rating-score" type="button" data-score="ok"></button>',
      '      <button class="wayfindr-widget__rating-score" type="button" data-score="bad"></button>',
      '    </div>',
      '    <label class="wayfindr-widget__rating-label" for="' + escapeHtml(ratingId) + '"></label>',
      '    <textarea class="wayfindr-widget__rating-comment" id="' + escapeHtml(ratingId) + '" rows="2" maxlength="1000"></textarea>',
      '    <button class="wayfindr-widget__rating-send" type="submit"></button>',
      '    <p class="wayfindr-widget__rating-status" role="status" aria-live="polite" hidden></p>',
      '  </form>',
      '  <p class="wayfindr-widget__typing" role="status" aria-live="polite" aria-atomic="true" hidden></p>',
      '  <p class="wayfindr-widget__connection" role="status" aria-live="polite" aria-atomic="true" hidden></p>',
      '  <form class="wayfindr-widget__form">',
      '    <label class="wayfindr-widget__label" for="wayfindr-message">' + escapeHtml(t('form.label')) + '</label>',
      '    <textarea id="wayfindr-message" class="wayfindr-widget__textarea" name="message" rows="4" placeholder="' + escapeHtml(options.placeholder || t('form.placeholder')) + '"></textarea>',
      '    <ul class="wayfindr-widget__attachments" aria-label="' + escapeHtml(t('attachments.aria')) + '" hidden></ul>',
      '    <input class="wayfindr-widget__file-input" type="file" accept="' + escapeHtml(ATTACHMENT_ACCEPT) + '" multiple hidden aria-hidden="true" tabindex="-1">',
      '    <div class="wayfindr-widget__actions">',
      '      <button class="wayfindr-widget__attach" type="button" aria-label="' + escapeHtml(t('attachment.attachAria')) + '"><span aria-hidden="true">📎</span> ' + escapeHtml(t('attachment.attach')) + '</button>',
      '      <button class="wayfindr-widget__send" type="submit">' + escapeHtml(t('form.send')) + '</button>',
      '      <button class="wayfindr-widget__refresh" type="button" hidden>' + escapeHtml(t('form.refresh')) + '</button>',
      '    </div>',
      '  </form>',
      '  <div class="wayfindr-widget__cobrowse" role="group" aria-label="' + escapeHtml(t('cobrowse.aria')) + '" aria-describedby="' + escapeHtml(cobrowseCopyId) + '" hidden>',
      '    <p id="' + escapeHtml(cobrowseCopyId) + '" class="wayfindr-widget__cobrowse-copy" role="status" aria-live="polite" aria-atomic="true">' + escapeHtml(t('cobrowse.request')) + '</p>',
      '    <div class="wayfindr-widget__cobrowse-actions">',
      '      <button class="wayfindr-widget__cobrowse-allow" type="button">' + escapeHtml(t('cobrowse.allow')) + '</button>',
      '      <button class="wayfindr-widget__cobrowse-decline" type="button">' + escapeHtml(t('cobrowse.decline')) + '</button>',
      '    </div>',
      '  </div>',
      '  <p class="wayfindr-widget__status" role="status" aria-live="polite" aria-atomic="true"></p>',
      '</section>',
    ].join('');

    mount.appendChild(rootEl);

    var launcher = rootEl.querySelector('.wayfindr-widget__launcher');
    var panel = rootEl.querySelector('.wayfindr-widget__panel');
    var close = rootEl.querySelector('.wayfindr-widget__close');
    var form = rootEl.querySelector('.wayfindr-widget__form');
    var timeline = rootEl.querySelector('.wayfindr-widget__timeline');
    var jump = rootEl.querySelector('.wayfindr-widget__jump');
    var notice = rootEl.querySelector('.wayfindr-widget__notice');
    var noticeCopy = rootEl.querySelector('.wayfindr-widget__notice-copy');
    var noticeRetry = rootEl.querySelector('.wayfindr-widget__notice-retry');
    var typing = rootEl.querySelector('.wayfindr-widget__typing');
    var connection = rootEl.querySelector('.wayfindr-widget__connection');
    var intakeForm = rootEl.querySelector('.wayfindr-widget__intake');
    var intakeIntro = rootEl.querySelector('.wayfindr-widget__intake-intro');
    var intakeFields = rootEl.querySelector('.wayfindr-widget__intake-fields');
    var intakeError = rootEl.querySelector('.wayfindr-widget__intake-error');
    var textarea = rootEl.querySelector('.wayfindr-widget__textarea');
    var attachmentsList = rootEl.querySelector('.wayfindr-widget__attachments');
    var fileInput = rootEl.querySelector('.wayfindr-widget__file-input');
    var attachButton = rootEl.querySelector('.wayfindr-widget__attach');
    var status = rootEl.querySelector('.wayfindr-widget__status');
    var send = rootEl.querySelector('.wayfindr-widget__send');
    var refresh = rootEl.querySelector('.wayfindr-widget__refresh');
    var cobrowse = rootEl.querySelector('.wayfindr-widget__cobrowse');
    var cobrowseCopy = rootEl.querySelector('.wayfindr-widget__cobrowse-copy');
    var help = rootEl.querySelector('.wayfindr-widget__help');
    var helpLabel = rootEl.querySelector('.wayfindr-widget__help-label');
    var helpInput = rootEl.querySelector('.wayfindr-widget__help-input');
    var helpStatus = rootEl.querySelector('.wayfindr-widget__help-status');
    var helpResults = rootEl.querySelector('.wayfindr-widget__help-results');
    var helpArticle = rootEl.querySelector('.wayfindr-widget__help-article');
    var helpBack = rootEl.querySelector('.wayfindr-widget__help-back');
    var helpBlocks = rootEl.querySelector('.wayfindr-widget__help-blocks');
    var helpSequence = 0;
    var rating = rootEl.querySelector('.wayfindr-widget__rating');
    var ratingIntro = rootEl.querySelector('.wayfindr-widget__rating-intro');
    var ratingLabel = rootEl.querySelector('.wayfindr-widget__rating-label');
    var ratingComment = rootEl.querySelector('.wayfindr-widget__rating-comment');
    var ratingSend = rootEl.querySelector('.wayfindr-widget__rating-send');
    var ratingStatus = rootEl.querySelector('.wayfindr-widget__rating-status');
    // The window that owns this document, not the ambient global: a test
    // harness (and an embed inside an iframe) hands the widget a document whose
    // view is not the one this script happens to be running in.
    var presenceWindow = (doc && doc.defaultView) || root || null;
    var presenceEl = rootEl.querySelector('.wayfindr-widget__presence');
    var presenceCopyEl = rootEl.querySelector('.wayfindr-widget__presence-copy');
    var presenceDeclineEl = rootEl.querySelector('.wayfindr-widget__presence-decline');
    var presenceConfig = null;
    var presenceTimer = null;
    // An override, the way messagePollMs and cobrowseStatusPollMs already work.
    // Zero disables the repeating heartbeat while leaving the first report and
    // the disclosure intact -- which is what a test wants, and what a host page
    // that only cares about arrival could ask for.
    var presencePollMs = typeof options.presencePollMs === 'number' ? Math.max(0, options.presencePollMs) : null;
    var storage = resolveStorageOption(options);
    var ratingConfig = null;
    var ratingScore = null;
    var ratingAnswered = false;
    // Which close the form on screen belongs to. Comparing the boolean alone
    // cannot tell a NEW unanswered close from the previous unanswered one --
    // both report awaiting -- so an unsubmitted draft would reappear against
    // different work, already ready to send.
    var ratingEpisode = null;
    // The episode whose answer is currently in flight, or null. Keyed on the
    // episode rather than a plain boolean, because two requests CAN be
    // outstanding at once: a stale one for a close that has since been
    // superseded, and the live one for the close now on screen.
    var ratingInFlight = null;
    var helpDebounce = null;
    var cobrowseAllow = rootEl.querySelector('.wayfindr-widget__cobrowse-allow');
    var cobrowseDecline = rootEl.querySelector('.wayfindr-widget__cobrowse-decline');
    var bootstrapped = false;
    var intakeState = null;
    var intakeConfig = null;
    var intakeAnswered = false;
    var intakeAnsweredSignature = '';
    // Only the newest bootstrap may touch the panel; anything older is a stale
    // answer that would overwrite it.
    var bootstrapSequence = 0;
    var bootstrapPromise = null;
    var supportCode = null;
    var conversationActivated = false;
    // The client persists the visitor identity per site; the widget persists
    // the active conversation reference alongside it so a page reload resumes
    // the visitor's thread instead of starting a new conversation.
    var widgetStorage = resolveStorageOption(options);
    var storedSupportCode = storageGet(widgetStorage, supportCodeStorageKey(options.sitePublicKey));
    var resumePromise = null;
    // A stored conversation is being restored. supportCode is not set until
    // that lands, so a bootstrap answer arriving first would read the visitor
    // as brand new and put the form in front of somebody who already has a
    // thread -- answers they would then watch be discarded when it restored.
    var resumePending = Boolean(storedSupportCode);
    var conversationStatus = null;
    var messages = [];
    // A fingerprint of the last rendered message list so a poll that brings no
    // changes skips rebuilding the timeline — rebuilding recreates <img>
    // elements, which (with no-store downloads) would refetch every image
    // attachment on every poll.
    var lastRenderedMessagesSignature = null;
    var realtimeSubscription = null;
    var stableConnectionState = null;
    var cobrowseGranted = false;
    var cobrowseState = 'unavailable';
    var cobrowseRequestedBy = null;
    var cobrowseVisitorNotice = null;
    var pendingCobrowseConsentFocus = false;
    var mutationObserver = null;
    var cobrowseResumeInFlight = false;
    var pendingMutationRecords = [];
    var skippedMutationRecords = 0;
    var mutationFlushTimer = null;
    var mutationSequence = 0;
    var droppedMutationBatches = 0;
    var mutationFlushMs = typeof options.mutationFlushMs === 'number' ? options.mutationFlushMs : DEFAULT_COBROWSE_PAYLOAD_BUDGET.mutationFlushMs;
    var cobrowsePressureResyncMs = typeof options.cobrowsePressureResyncMs === 'number' ? Math.max(0, options.cobrowsePressureResyncMs) : DEFAULT_COBROWSE_PAYLOAD_BUDGET.pressureResyncMs;
    var lastCobrowsePressureResyncAt = 0;
    var lastCobrowseResyncRequestId = null;
    var cobrowseResyncMaxAttempts = typeof options.cobrowseResyncMaxAttempts === 'number' ? Math.max(0, Math.floor(options.cobrowseResyncMaxAttempts)) : DEFAULT_COBROWSE_PAYLOAD_BUDGET.resyncMaxAttempts;
    var cobrowseResyncAttemptRequestId = null;
    var cobrowseResyncAttemptCount = 0;
    var cobrowseResyncExhaustionReportedRequestId = null;
    var cobrowseResyncInFlight = false;
    var mutationPayloadMaxBytes = typeof options.mutationPayloadMaxBytes === 'number'
      ? Math.max(0, options.mutationPayloadMaxBytes)
      : DEFAULT_COBROWSE_PAYLOAD_BUDGET.mutationBatchMaxBytes;
    var mutationQueueMaxRecords = typeof options.mutationQueueMaxRecords === 'number'
      ? Math.max(0, options.mutationQueueMaxRecords)
      : DEFAULT_COBROWSE_PAYLOAD_BUDGET.mutationQueueMaxRecords;
    var messagePollMs = typeof options.messagePollMs === 'number' ? Math.max(0, options.messagePollMs) : 5000;
    var messagePollTimer = null;
    var readReceiptDwellMs = typeof options.readReceiptDwellMs === 'number' ? Math.max(0, options.readReceiptDwellMs) : 1200;
    var readReceiptTimer = null;
    var pendingReadReceiptMessageId = null;
    var lastReadReceiptMessageId = null;
    var readReceiptBusy = false;
    var cobrowseStatusPollMs = typeof options.cobrowseStatusPollMs === 'number' ? Math.max(0, options.cobrowseStatusPollMs) : DEFAULT_COBROWSE_PAYLOAD_BUDGET.statusPollMs;
    // Debounced so a search is one request per pause in typing rather than one
    // per keystroke. Configurable mainly so tests need not wait on a timer.
    var helpSearchDebounceMs = typeof options.helpSearchDebounceMs === 'number' ? Math.max(0, options.helpSearchDebounceMs) : 250;
    var cobrowseStatusTimer = null;
    var typingSignalThrottleMs = typeof options.typingSignalThrottleMs === 'number' ? Math.max(0, options.typingSignalThrottleMs) : 5000;
    var lastTypingSignalAt = 0;
    var agentTypingExpiryTimer = null;
    var visitorContext = options.visitorContext || null;
    var composerBusy = false;
    var refreshBusy = false;
    // Two-step upload: files are uploaded as soon as they are picked and tracked
    // here as chips until the next send binds the ready ones to the message.
    var pendingAttachments = [];
    var pendingAttachmentSeq = 0;
    var pendingClientMessageId = null;
    var pendingClientMessageBody = null;
    var noticeRetryAction = null;
    // Captured from the rendered chrome rather than hardcoded, so the busy
    // states restore whatever the button actually says -- including a host's
    // own label and a retranslated one.
    var sendLabel = send.textContent;
    var refreshLabel = refresh.textContent;

    /**
     * Adopt the site's configured language, if it turns out to matter.
     *
     * Bootstrap is the first time the widget learns what the operator set as
     * the site default, and that only wins when neither the host page nor the
     * visitor's browser has already answered -- so most of the time this
     * changes nothing and returns early.
     *
     * When it does change, the chrome is retranslated in place rather than
     * rebuilt. Rebuilding would throw away the transcript, the composer's
     * contents and any in-flight upload, which is a poor trade for a language
     * the visitor did not ask for.
     */
    function applyLocale(siteDefault) {
      // Assigned, not merged. `||` kept the last language an operator had set
      // after they chose "follow the visitor's browser" again, so a panel left
      // open went on speaking a default that no longer existed -- and bootstrap
      // is refreshed on every open precisely so settings like this take effect.
      localePreferences.siteDefault = siteDefault;

      var next = resolveLocale(localePreferences);

      if (next === t.locale) {
        return;
      }

      t = createTranslator(next);
      rootEl.lang = t.locale;
      rootEl.dir = t.direction;

      // A label the host page supplied is the host page's to own; only the
      // widget's own defaults are retranslated.
      if (!options.launcherLabel) {
        launcher.textContent = t('launcher.label');
      }

      if (!options.title) {
        var heading = rootEl.querySelector('.wayfindr-widget__header strong');

        if (heading) {
          heading.textContent = t('header.title');
        }
      }

      if (!options.placeholder) {
        textarea.setAttribute('placeholder', t('form.placeholder'));
      }

      panel.setAttribute('aria-label', t('panel.aria'));
      close.setAttribute('aria-label', t('header.close'));
      timeline.setAttribute('aria-label', t('timeline.aria'));
      jump.textContent = t('timeline.jump');
      noticeRetry.textContent = t('notice.retry');
      attachmentsList.setAttribute('aria-label', t('attachments.aria'));
      attachButton.setAttribute('aria-label', t('attachment.attachAria'));
      cobrowse.setAttribute('aria-label', t('cobrowse.aria'));

      var attachText = attachButton.querySelector('span');

      if (attachText && attachText.nextSibling) {
        attachText.nextSibling.textContent = ' ' + t('attachment.attach');
      }

      var formLabel = rootEl.querySelector('.wayfindr-widget__label');

      if (formLabel) {
        formLabel.textContent = t('form.label');
      }

      // Re-captured, or the busy state would restore the previous language.
      sendLabel = t('form.send');
      refreshLabel = t('form.refresh');
      send.textContent = composerBusy ? t('status.sending') : sendLabel;
      refresh.textContent = refreshBusy ? t('status.refreshing') : refreshLabel;

      var intakeSubmit = rootEl.querySelector('.wayfindr-widget__intake-submit');

      if (intakeSubmit) {
        intakeSubmit.textContent = t('intake.submit');
      }

      // The help chrome is drawn with the panel and outlives every search, so
      // it needs the same treatment as the intake submit button above -- the
      // exact omission that left three controls speaking English behind a
      // German panel last time.
      helpLabel.textContent = t('help.label');
      helpInput.setAttribute('placeholder', t('help.placeholder'));
      helpBack.textContent = t('help.back');

      // The rating prompt is persistent chrome too. Leaving it out is the
      // omission that left three controls speaking English behind a German
      // panel two releases ago.
      renderRatingPrompt();

      // The intake QUESTIONS need nothing here: applyBootstrapResult applies
      // the language before it applies the intake state, so the form is rebuilt
      // in the new language two lines later. Only the submit button above
      // survives that rebuild, because it lives in the panel template.

      // These regions each have a rule deciding what belongs in them, and
      // calling it again is how they get retranslated without a second copy of
      // that decision living here.
      renderCobrowseConsent();

      // Only the states that rule owns. A transient notice -- a send failure,
      // a retry offer -- is not this function's to rewrite.
      var noticeState = notice.hidden ? null : notice.getAttribute('data-state');

      if (noticeState === 'empty' || noticeState === 'closed') {
        renderConversationNotice();
      }
    }


    function showNotice(state, copy, options) {
      options = options || {};

      notice.hidden = false;
      notice.setAttribute('data-state', state || 'info');
      noticeCopy.textContent = copy;
      noticeRetryAction = typeof options.onRetry === 'function' ? options.onRetry : null;
      noticeRetry.hidden = !options.retry;
      noticeRetry.disabled = Boolean(noticeRetryAction && composerBusy);
    }

    function hideNotice() {
      notice.hidden = true;
      noticeRetry.hidden = true;
      noticeRetryAction = null;
      noticeRetry.disabled = false;
    }

    function applyConversationStatus(conversation) {
      // The server settles whether an answer is being waited for. Widget
      // memory cannot: it is lost on reload, so the visitor would be asked
      // again about a close they already rated, and it survives a genuine
      // reopen, so they would never be asked about the next one. Absent from a
      // payload that does not carry it, the local answer stands.
      if (conversation && typeof conversation.awaiting_rating === 'boolean') {
        ratingAnswered = ! conversation.awaiting_rating;
      }

      // A NEW close is a new question, so it must arrive with an empty form --
      // whether or not the previous one was ever answered. Keyed on which close
      // it is, because an abandoned draft and a submitted answer look identical
      // from the boolean alone.
      if (conversation && typeof conversation.rating_episode === 'string' && conversation.rating_episode !== ratingEpisode) {
        ratingEpisode = conversation.rating_episode;
        ratingScore = null;
        ratingComment.value = '';
        ratingStatus.hidden = true;
        ratingStatus.textContent = '';
      }

      if (!conversation || !conversation.status) {
        return;
      }

      conversationStatus = String(conversation.status).toLowerCase();

      // The prompt exists to be asked once the conversation is closed, and this
      // is the only place that learns it closed -- whether from a poll, a
      // realtime frame, or a resume.
      renderRatingPrompt();
    }

    function renderConversationNotice() {
      if (conversationStatus === 'closed') {
        showNotice('closed', t('notice.closed'));

        return;
      }

      if (messages.length === 0) {
        showNotice('empty', supportCode
          ? t('notice.emptyAgent')
          : t('notice.emptyVisitor'));
      } else {
        hideNotice();
      }
    }

    function timelineIsAtBottom() {
      return (timeline.scrollHeight - timeline.scrollTop - timeline.clientHeight) <= 24;
    }

    function scrollTimelineToBottom() {
      timeline.scrollTop = timeline.scrollHeight;
    }

    function showJumpCue() {
      if (jump) {
        jump.hidden = false;
      }
    }

    function hideJumpCue() {
      if (jump) {
        jump.hidden = true;
      }
    }

    // Everything renderMessages reads out of each message, so an unchanged list
    // produces an identical signature and the timeline is left as-is.
    function messagesSignature(list) {
      return JSON.stringify((Array.isArray(list) ? list : []).map(function (message) {
        var sender = message.sender || {};

        return [
          message.id,
          sender.kind,
          sender.name,
          message.type,
          message.body,
          message.created_at,
          (Array.isArray(message.attachments) ? message.attachments : []).map(function (attachment) {
            return [attachment.id, attachment.is_image, attachment.filename, attachment.size_bytes];
          }),
        ];
      }));
    }

    function renderMessages(nextMessages) {
      var nextList = Array.isArray(nextMessages) ? nextMessages : messages;
      var signature = messagesSignature(nextList);

      if (signature === lastRenderedMessagesSignature) {
        // Nothing rendered changed — keep the message list in sync but leave the
        // existing DOM (and its already-loaded images) untouched. The status
        // notice can still change (e.g. the conversation was closed) without the
        // message list changing, so refresh it; and keep the read-receipt loop
        // alive.
        messages = nextList;
        renderConversationNotice();
        scheduleRenderedReadReceipt();

        return;
      }

      var previousCount = messages.length;
      var wasAtBottom = previousCount === 0 || timelineIsAtBottom();

      messages = nextList;
      lastRenderedMessagesSignature = signature;
      timeline.textContent = '';

      var previousDayKey = null;

      messages.forEach(function (message, index) {
        var dayKey = messageDayKey(message.created_at);

        if (dayKey && dayKey !== previousDayKey) {
          var separator = createDaySeparator(doc, message.created_at, t);

          if (separator) {
            timeline.appendChild(separator);
          }

          previousDayKey = dayKey;
        }

        var sender = message.sender || {};
        var senderKind = sender.kind === 'agent' ? 'agent' : 'visitor';
        var item = doc.createElement('article');
        var meta = doc.createElement('div');
        var name = doc.createElement('strong');
        var body = doc.createElement('p');
        var time = createMessageTime(doc, message.created_at);
        var delivery = createMessageDelivery(doc, senderKind, t);
        var grouped = shouldGroupMessage(message, messages[index - 1]);

        item.className = 'wayfindr-widget__message wayfindr-widget__message--' + senderKind;

        if (grouped) {
          item.className += ' wayfindr-widget__message--grouped';
        }

        if (message.id) {
          item.setAttribute('data-wayfindr-message-id', String(message.id));
        }

        meta.className = 'wayfindr-widget__message-meta';
        name.className = 'wayfindr-widget__message-name';
        name.textContent = sender.name || t(senderKind === 'agent' ? 'sender.support' : 'sender.visitor');
        body.className = 'wayfindr-widget__message-body';

        meta.appendChild(name);

        if (time) {
          meta.appendChild(time);
        }

        item.appendChild(meta);

        var attachments = Array.isArray(message.attachments) ? message.attachments : [];

        // Skip the (empty) body paragraph for an attachment-only message so the
        // preview sits directly under the meta line.
        if (message.body) {
          body.textContent = message.body;
          item.appendChild(body);
        } else if (attachments.length === 0) {
          body.textContent = '';
          item.appendChild(body);
        }

        if (attachments.length) {
          item.appendChild(createMessageAttachments(attachments));
        }

        if (delivery) {
          item.appendChild(delivery);
        }

        timeline.appendChild(item);
      });

      timeline.hidden = messages.length === 0;
      renderConversationNotice();

      var grew = messages.length > previousCount;
      var newestMessage = messages[messages.length - 1] || null;
      var newestIsVisitor = !!newestMessage && (newestMessage.sender || {}).kind !== 'agent';

      if (messages.length === 0) {
        hideJumpCue();
      } else if (wasAtBottom || (newestIsVisitor && grew)) {
        // Keep the latest message in view when the visitor is already at the
        // bottom or has just sent a new message. Requiring growth means a poll
        // or refresh that re-renders an already-seen visitor message will not
        // yank a visitor who has scrolled up to reread earlier replies.
        scrollTimelineToBottom();
        hideJumpCue();
      } else if (grew) {
        // The visitor has scrolled up; offer a gentle cue instead of yanking
        // them down to a message they did not ask to jump to.
        showJumpCue();
      }

      refresh.hidden = false;
      scheduleRenderedReadReceipt();
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

        renderConnectionState('connected');
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
        stableConnectionState = null;

        return;
      }

      var normalized = String(state || '').toLowerCase();

      if (normalized === 'connected' || normalized === 'live') {
        connection.textContent = t('connection.connected');
      } else if (normalized === 'connecting' || normalized === 'reconnecting') {
        connection.textContent = t('connection.reconnecting');
      } else if (normalized === 'trouble') {
        connection.textContent = t('connection.trouble');
      } else {
        connection.textContent = t('connection.polling');
      }

      if (normalized !== 'trouble') {
        stableConnectionState = normalized || 'polling';
      }

      connection.hidden = false;
    }

    function renderConnectionTrouble() {
      if (hasConnectedRealtime()) {
        return;
      }

      renderConnectionState('trouble');
    }

    function hasConnectedRealtime() {
      return Boolean(realtimeSubscription && (stableConnectionState === 'connected' || stableConnectionState === 'live'));
    }

    function clearConnectionTrouble() {
      if (connection.textContent !== t('connection.trouble')) {
        return;
      }

      renderConnectionState(stableConnectionState || 'polling');
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

    function clearReadReceiptTimer() {
      if (!readReceiptTimer) {
        return;
      }

      clearTimeout(readReceiptTimer);
      readReceiptTimer = null;
    }

    function cancelPendingReadReceipt() {
      clearReadReceiptTimer();
      pendingReadReceiptMessageId = null;
    }

    function renderedMessageExists(messageId) {
      var rendered = timeline.querySelectorAll('[data-wayfindr-message-id]');

      return Array.prototype.slice.call(rendered).some(function (item) {
        return item.getAttribute('data-wayfindr-message-id') === String(messageId);
      });
    }

    function latestRenderedAgentMessageId() {
      var index;

      for (index = messages.length - 1; index >= 0; index -= 1) {
        var message = messages[index] || {};
        var sender = message.sender || {};

        if (sender.kind === 'agent' && message.id && renderedMessageExists(message.id)) {
          return String(message.id);
        }
      }

      return null;
    }

    function canMarkRenderedMessagesSeen() {
      return isPanelReadable({
        panel: panel,
        document: doc,
      });
    }

    function scheduleRenderedReadReceipt() {
      var messageId;

      if (!supportCode || readReceiptBusy || !canMarkRenderedMessagesSeen()) {
        return;
      }

      messageId = latestRenderedAgentMessageId();

      if (!messageId || messageId === pendingReadReceiptMessageId || messageId === lastReadReceiptMessageId) {
        return;
      }

      clearReadReceiptTimer();
      pendingReadReceiptMessageId = messageId;
      readReceiptTimer = setTimeout(function () {
        readReceiptTimer = null;
        confirmRenderedReadReceipt(messageId);
      }, readReceiptDwellMs);

      if (typeof readReceiptTimer.unref === 'function') {
        readReceiptTimer.unref();
      }
    }

    async function confirmRenderedReadReceipt(messageId) {
      var shouldScheduleNextReadReceipt = false;

      if (!supportCode || !canMarkRenderedMessagesSeen() || latestRenderedAgentMessageId() !== String(messageId)) {
        if (pendingReadReceiptMessageId === String(messageId)) {
          pendingReadReceiptMessageId = null;
        }

        return;
      }

      readReceiptBusy = true;
      pendingReadReceiptMessageId = null;

      try {
        var result = await client.fetchMessages(supportCode, {
          markSeen: true,
          seenMessageId: messageId,
        });

        lastReadReceiptMessageId = String(messageId);
        renderMessages(result.messages || []);
        renderAgentTyping(result.agent_typing);
        shouldScheduleNextReadReceipt = true;
      } catch (error) {
        // Read receipts are a hint. A later render/open/visibility pass can retry.
      } finally {
        readReceiptBusy = false;

        if (shouldScheduleNextReadReceipt) {
          scheduleRenderedReadReceipt();
        }
      }
    }

    function handleVisibilityChange() {
      // Presence rides on the handler `destroy()` already removes rather than
      // registering a second, anonymous one -- an anonymous listener cannot be
      // removed, so a destroyed widget would keep waking up and reporting.
      handlePresenceVisibility();

      if (canMarkRenderedMessagesSeen()) {
        scheduleRenderedReadReceipt();

        return;
      }

      cancelPendingReadReceipt();
    }

    function handlePresenceVisibility() {
      if (!presenceConfig) {
        return;
      }

      if (presenceHidden()) {
        // A hidden tab reports nothing, and decaying to quiet is the honest
        // answer for somebody who is not looking.
        stopPresenceTimer();

        return;
      }

      // Through the same gate as the first report ever sent, not straight out.
      // If the config arrived while this tab was in the background, the notice
      // has never been painted -- the two-frame wait ran against a hidden
      // document -- and foregrounding used to send the heartbeat synchronously
      // inside the visibilitychange handler, ahead of the first paint the
      // visitor could have seen anything in.
      startPresenceAfterDisclosure();
    }

    function renderCobrowseConsent() {
      var requested = cobrowseState === 'requested';
      var granted = cobrowseState === 'granted';
      var requester = cobrowseRequestedBy || t('sender.support');
      var wasHidden = cobrowse.hidden;

      cobrowse.hidden = !supportCode || (!requested && !granted);
      cobrowseAllow.textContent = t(granted ? 'cobrowse.stop' : 'cobrowse.allow');
      // Its sibling was already kept current here; this one was drawn once with
      // the panel and never spoken to again.
      cobrowseDecline.textContent = t('cobrowse.decline');
      cobrowseDecline.hidden = granted;
      cobrowseCopy.textContent = granted
        ? (cobrowseVisitorNotice || t('cobrowse.active'))
        : t('cobrowse.requestFrom', { requester: requester });

      // The cobrowse controls live inside the panel, so a visitor who grants
      // cobrowse and then closes the panel would otherwise lose any sign that
      // their page is still shared. Surface a persistent indicator on the
      // launcher (shown while the panel is closed) so sharing is never silent.
      if (launcher) {
        if (granted) {
          launcher.setAttribute('data-cobrowse-active', 'true');
          launcher.setAttribute('aria-label', t('launcher.sharingAria', { label: launcher.textContent }));
        } else {
          launcher.removeAttribute('data-cobrowse-active');
          launcher.removeAttribute('aria-label');
        }
      }

      if (requested && wasHidden && !cobrowse.hidden) {
        if (isPanelReadable({ panel: panel, document: doc })) {
          cobrowseAllow.focus();
        } else {
          pendingCobrowseConsentFocus = true;
        }
      } else if (!requested) {
        pendingCobrowseConsentFocus = false;
      }
    }

    function applyCobrowseStatus(nextCobrowse) {
      nextCobrowse = nextCobrowse || {};

      var previousState = cobrowseState;
      var previousGranted = cobrowseGranted;

      cobrowseState = nextCobrowse.status || nextCobrowse.consent || 'unavailable';
      cobrowseRequestedBy = nextCobrowse.requested_by && nextCobrowse.requested_by.name
        ? nextCobrowse.requested_by.name
        : null;
      cobrowseVisitorNotice = nextCobrowse.visitor_notice && typeof nextCobrowse.visitor_notice.message === 'string'
        ? nextCobrowse.visitor_notice.message
        : null;
      cobrowseGranted = cobrowseState === 'granted' || nextCobrowse.consent === 'granted';

      if (!cobrowseGranted) {
        stopMutationStream();
        lastCobrowseResyncRequestId = null;
        resetCobrowseResyncAttempts();
        cobrowseResyncInFlight = false;
        cobrowseVisitorNotice = null;
      }

      renderCobrowseConsent();
      handleCobrowseResyncRequest(nextCobrowse.resync);

      if ((previousGranted || previousState === 'requested') && cobrowseState === 'ended') {
        status.textContent = t('cobrowse.stoppedBySupport');
      } else if ((previousGranted || previousState === 'requested') && cobrowseState === 'revoked') {
        status.textContent = t(previousGranted ? 'cobrowse.stopped' : 'cobrowse.declined');
      }
    }

    function pendingCobrowseResyncId(resync) {
      if (!resync || resync.requested !== true || !resync.request_id) {
        return null;
      }

      return String(resync.request_id);
    }

    function resetCobrowseResyncAttempts() {
      cobrowseResyncAttemptRequestId = null;
      cobrowseResyncAttemptCount = 0;
      cobrowseResyncExhaustionReportedRequestId = null;
    }

    function canAttemptCobrowseResync(requestId) {
      if (cobrowseResyncAttemptRequestId !== requestId) {
        cobrowseResyncAttemptRequestId = requestId;
        cobrowseResyncAttemptCount = 0;
        cobrowseResyncExhaustionReportedRequestId = null;
      }

      return cobrowseResyncAttemptCount < cobrowseResyncMaxAttempts;
    }

    function markCobrowseResyncAttempt(requestId) {
      if (cobrowseResyncAttemptRequestId !== requestId) {
        cobrowseResyncAttemptRequestId = requestId;
        cobrowseResyncAttemptCount = 0;
      }

      cobrowseResyncAttemptCount += 1;
    }

    async function handleCobrowseResyncRequest(resync) {
      var requestId = pendingCobrowseResyncId(resync);

      if (!supportCode || !cobrowseGranted || !requestId || cobrowseResyncInFlight || lastCobrowseResyncRequestId === requestId) {
        return;
      }

      if (!canAttemptCobrowseResync(requestId)) {
        await reportCobrowseResyncExhausted(requestId);

        return;
      }

      markCobrowseResyncAttempt(requestId);
      cobrowseResyncInFlight = true;

      try {
        await client.reportCobrowsePageState(supportCode, collectPageState());

        var snapshot = createCobrowseSnapshot(doc, {
          location: location,
          maskSelectors: client.getMaskSelectors(),
          sensitiveTerms: client.getSensitiveTerms(),
        });

        snapshot.resyncRequestId = requestId;

        await client.reportCobrowseSnapshot(supportCode, snapshot);

        lastCobrowseResyncRequestId = requestId;
      } catch (error) {
        // Leave the request unmarked so the next status refresh can retry the clean snapshot.
      } finally {
        cobrowseResyncInFlight = false;
      }
    }

    async function reportCobrowseResyncExhausted(requestId) {
      if (cobrowseResyncExhaustionReportedRequestId === requestId) {
        return;
      }

      cobrowseResyncExhaustionReportedRequestId = requestId;

      try {
        await client.reportCobrowseTelemetry(supportCode, {
          resyncRequestId: requestId,
          resyncAttemptsExhausted: true,
        });
      } catch (error) {
        // Exhaustion is a recovery hint; delayed and expired states still protect the agent path.
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

      // Realtime and cobrowse are isolated so that POLLING is always
      // scheduled.
      //
      // Polling is the fallback for realtime being unavailable, and it used to
      // be scheduled after an unguarded connectRealtime() in the same
      // sequence. A throw there -- not a null return, which was handled, but a
      // throw from inside the transport library -- skipped the very fallback
      // that exists for realtime failing. The visitor then had neither, so an
      // agent's reply never arrived by any route, and the enclosing handler
      // reported it as a failed send. A fallback sharing a failure path with
      // the thing it backs up is not a fallback.
      try {
        connectRealtime();
      } catch (error) {
        reportSuppressed('realtime connection', error);
        renderConnectionState('polling');
      }

      try {
        renderCobrowseConsent();
      } catch (error) {
        reportSuppressed('cobrowse consent', error);
      }

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
        var pageRecords = Array.prototype.slice.call(records || []).filter(function (record) {
          return !mutationRecordTouchesElement(record, rootEl);
        });

        if (pageRecords.length === 0) {
          return;
        }

        pendingMutationRecords = pendingMutationRecords.concat(pageRecords);

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

    function mutationRecordTouchesElement(record, element) {
      if (!record || !element) {
        return false;
      }

      if (nodeBelongsToElement(record.target, element)) {
        return true;
      }

      return Array.prototype.slice.call(record.addedNodes || []).some(function (node) {
        return nodeBelongsToElement(node, element);
      }) || Array.prototype.slice.call(record.removedNodes || []).some(function (node) {
        return nodeBelongsToElement(node, element);
      });
    }

    function nodeBelongsToElement(node, element) {
      if (!node || !element) {
        return false;
      }

      if (node === element) {
        return true;
      }

      var ownerElement = node.nodeType === 1 ? node : node.parentElement;

      return Boolean(ownerElement && element.contains && element.contains(ownerElement));
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
      droppedMutationBatches = 0;
      lastCobrowsePressureResyncAt = 0;
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
        view: doc.defaultView || root,
        maskSelectors: client.getMaskSelectors(),
        sensitiveTerms: client.getSensitiveTerms(),
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
        await resyncCobrowseSnapshotAfterPressure(batch);
        droppedMutationBatches = 0;
        skippedMutationRecords = Math.max(0, skippedMutationRecords - queuedSkippedCount);
      } catch (error) {
        droppedMutationBatches += 1;
      }
    }

    async function resyncCobrowseSnapshotAfterPressure(batch) {
      if (!supportCode || !cobrowseGranted || !batchHasTransportPressure(batch)) {
        return;
      }

      var nowMs = Date.now();

      if (cobrowsePressureResyncMs > 0 && nowMs - lastCobrowsePressureResyncAt < cobrowsePressureResyncMs) {
        return;
      }

      lastCobrowsePressureResyncAt = nowMs;
      cobrowseCopy.textContent = t('cobrowse.catchingUp');

      try {
        var snapshot = createCobrowseSnapshot(doc, {
          location: location,
          maskSelectors: client.getMaskSelectors(),
          sensitiveTerms: client.getSensitiveTerms(),
        });

        snapshot.mutationSequence = batch.sequence;

        await client.reportCobrowseSnapshot(supportCode, snapshot);
      } catch (error) {
        // Snapshot re-sync is a recovery affordance; mutation diagnostics remain the source of truth.
      } finally {
        renderCobrowseConsent();
      }
    }

    function batchHasTransportPressure(batch) {
      return (Number(batch && batch.droppedCount) || 0) > 0 || (Number(batch && batch.skippedCount) || 0) > 0;
    }

    async function updateCobrowseConsent(nextGranted) {
      if (!supportCode) {
        return;
      }

      var startedAt = Date.now();

      // Claim the kickoff for the whole consent update: the server commits
      // the grant as soon as the POST lands, so a status poll racing this
      // request could otherwise see "granted" and run the resume path (#544)
      // before this sequence reports — double-sending page state and
      // snapshot. Claiming up front also keeps a mid-revoke poll from
      // resuming reporting off stale granted status.
      cobrowseResumeInFlight = true;

      cobrowseAllow.disabled = true;
      cobrowseDecline.disabled = true;
      status.textContent = t(nextGranted ? 'cobrowse.granting' : 'cobrowse.revoking');

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
              sensitiveTerms: client.getSensitiveTerms(),
            }));
          } catch (error) {
            // Snapshot reporting should never undo a successful consent change.
          }

          startMutationStream();
        } else {
          stopMutationStream();
        }

        status.textContent = t(cobrowseGranted ? 'cobrowse.granted' : 'cobrowse.revoked');
      } catch (error) {
        status.textContent = errorText(error, 'cobrowse.consentFailed');
      } finally {
        cobrowseResumeInFlight = false;
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
        await resumeCobrowseReporting();

        return result;
      } catch (error) {
        if (!options.silent) {
          status.textContent = errorText(error, 'cobrowse.statusFailed');
        }

        return null;
      }
    }

    // A reloaded page that resumes a conversation (#528) discovers an
    // already-granted cobrowse session through the status poll instead of a
    // consent click, so nothing restarted reporting: the widget showed
    // "Cobrowse is active" while the agent preview stayed frozen (#544).
    // Discovering granted-but-not-reporting re-runs the same sequence the
    // consent click runs — fresh page state, fresh snapshot, mutation stream.
    // Consent stays untouched: the session was granted and never ended, the
    // visitor sees the active state with a Stop control, and revoking still
    // stops everything through the same status handling.
    async function resumeCobrowseReporting() {
      if (!cobrowseGranted || mutationObserver || cobrowseResumeInFlight || !supportCode) {
        return;
      }

      cobrowseResumeInFlight = true;

      try {
        await client.reportCobrowsePageState(supportCode, collectPageState());
      } catch (error) {
        // Best-effort: the next poll retries the resume.
      }

      try {
        await client.reportCobrowseSnapshot(supportCode, createCobrowseSnapshot(doc, {
          location: location,
          maskSelectors: client.getMaskSelectors(),
          sensitiveTerms: client.getSensitiveTerms(),
        }));
      } catch (error) {
        // Best-effort: the agent can still pull a resync explicitly.
      }

      // Only consider the resume settled once the stream is observing;
      // startMutationStream is a no-op without a body or observer support.
      startMutationStream();
      cobrowseResumeInFlight = false;
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
        status.textContent = t('status.refreshing');
      }

      try {
        var result = await client.fetchMessages(supportCode);
        applyConversationStatus(result.conversation);
        renderMessages(result.messages || []);
        renderAgentTyping(result.agent_typing);
        clearConnectionTrouble();

        if (!options.silent) {
          status.textContent = t('status.refreshed');
        }
      } catch (error) {
        renderConnectionTrouble();

        if (!options.silent) {
          status.textContent = t('error.refresh');
          showNotice('warning', t('error.refresh'), {
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
      attachButton.disabled = composerBusy;
      // Re-render chips so their remove buttons reflect the busy state.
      renderPendingAttachments();
      send.textContent = composerBusy ? t('status.sending') : sendLabel;

      if (noticeRetryAction) {
        noticeRetry.disabled = composerBusy;
      }
    }

    function setRefreshBusy(nextBusy) {
      refreshBusy = Boolean(nextBusy);
      refresh.setAttribute('aria-busy', refreshBusy ? 'true' : 'false');
      refresh.disabled = refreshBusy;
      refresh.textContent = refreshBusy ? t('status.refreshing') : refreshLabel;
      noticeRetry.disabled = refreshBusy;
    }

    function hasUploadingAttachments() {
      return pendingAttachments.some(function (attachment) {
        return attachment.status === 'uploading';
      });
    }

    function readyAttachmentIds() {
      return pendingAttachments
        .filter(function (attachment) {
          return attachment.status === 'ready' && attachment.attachmentId;
        })
        .map(function (attachment) {
          return attachment.attachmentId;
        });
    }

    // Send stays blocked while any upload is still in flight, so a message can
    // never be sent referencing a half-uploaded file.
    function refreshSendState() {
      send.disabled = composerBusy || hasUploadingAttachments();
    }

    function renderPendingAttachments() {
      attachmentsList.textContent = '';
      attachmentsList.hidden = pendingAttachments.length === 0;

      pendingAttachments.forEach(function (attachment) {
        var item = doc.createElement('li');
        item.className = 'wayfindr-widget__attach-chip wayfindr-widget__attach-chip--' + attachment.status;

        var nameEl = doc.createElement('span');
        nameEl.className = 'wayfindr-widget__attach-chip-name';
        nameEl.textContent = attachment.filename;
        item.appendChild(nameEl);

        var stateEl = doc.createElement('span');
        stateEl.className = 'wayfindr-widget__attach-chip-state';

        if (attachment.status === 'uploading') {
          stateEl.textContent = t('attachment.uploading');
        } else if (attachment.status === 'error') {
          stateEl.textContent = attachment.error || t('error.attachment');
        } else {
          stateEl.textContent = formatAttachmentSize(attachment.size);
        }

        item.appendChild(stateEl);

        var removeEl = doc.createElement('button');
        removeEl.type = 'button';
        removeEl.className = 'wayfindr-widget__attach-chip-remove';
        removeEl.setAttribute('aria-label', t('attachment.remove', { filename: attachment.filename }));
        removeEl.textContent = '×';
        // A send in flight captured the current attachment ids already; removing
        // one mid-send would desync the chips and reset the idempotency key.
        removeEl.disabled = composerBusy;
        removeEl.addEventListener('click', function () {
          removePendingAttachment(attachment.localId);
        });
        item.appendChild(removeEl);

        attachmentsList.appendChild(item);
      });

      refreshSendState();
    }

    function addPendingFiles(fileList) {
      Array.prototype.slice.call(fileList || []).forEach(function (file) {
        var entry = {
          localId: 'pa-' + (++pendingAttachmentSeq),
          filename: file && file.name ? file.name : 'attachment',
          size: file && typeof file.size === 'number' ? file.size : 0,
          // Staged files hold the File locally until a conversation exists; once
          // it does, the upload happens on pick.
          status: supportCode ? 'uploading' : 'staged',
          attachmentId: null,
          isImage: !!(file && typeof file.type === 'string' && file.type.indexOf('image/') === 0),
          error: null,
          file: file,
        };

        pendingAttachments.push(entry);
        // The draft changed, so a retry of the previous send must not reuse its
        // idempotency key.
        pendingClientMessageId = null;
        renderPendingAttachments();

        if (supportCode) {
          uploadPendingEntry(entry).catch(function () {});
        }
      });
    }

    // Uploads a staged/pending entry to the (now-existing) conversation,
    // updating its chip. Resolves the attachment id, or rejects on failure so a
    // send can surface it. Reused by pick-time upload and send-time staged
    // upload.
    function uploadPendingEntry(entry) {
      entry.status = 'uploading';
      entry.error = null;
      renderPendingAttachments();

      return client.uploadAttachment(supportCode, entry.file).then(function (result) {
        var attachment = result && result.attachment ? result.attachment : null;

        if (pendingAttachments.indexOf(entry) === -1) {
          // Removed while uploading — if it still landed, delete the orphan so
          // it does not hold conversation quota.
          if (attachment && attachment.id && supportCode) {
            client.deleteAttachment(supportCode, attachment.id).catch(function () {});
          }

          return null;
        }

        if (!attachment || !attachment.id) {
          throw new Error(t('error.attachment'));
        }

        entry.status = 'ready';
        entry.attachmentId = attachment.id;
        entry.isImage = Boolean(attachment.is_image);
        entry.filename = attachment.filename || entry.filename;
        entry.size = attachment.size_bytes || entry.size;
        renderPendingAttachments();

        return attachment.id;
      }).catch(function (error) {
        if (pendingAttachments.indexOf(entry) !== -1) {
          entry.status = 'error';
          entry.error = (error && typeof error.status === 'number' && error.status >= 400 && error.status < 500 && error.message)
            ? error.message
            : t('error.attachment');
          renderPendingAttachments();
        }

        throw error;
      });
    }

    function stagedPendingEntries() {
      return pendingAttachments.filter(function (attachment) {
        return attachment.status === 'staged';
      });
    }

    function removePendingAttachment(localId) {
      // Ignore removals while a send is in flight — that send already captured
      // the attachment ids, and mutating the set now would desync it.
      if (composerBusy) {
        return;
      }

      var entry = pendingAttachments.filter(function (attachment) {
        return attachment.localId === localId;
      })[0];

      // Free the server-side upload so it stops counting against the
      // conversation quota. Best-effort — the retention sweep is the backstop.
      if (entry && entry.status === 'ready' && entry.attachmentId && supportCode) {
        client.deleteAttachment(supportCode, entry.attachmentId).catch(function () {});
      }

      pendingAttachments = pendingAttachments.filter(function (attachment) {
        return attachment.localId !== localId;
      });
      pendingClientMessageId = null;
      renderPendingAttachments();
    }

    function clearPendingAttachments() {
      pendingAttachments = [];
      renderPendingAttachments();
    }

    function createMessageAttachments(attachments) {
      var wrap = doc.createElement('div');
      wrap.className = 'wayfindr-widget__message-attachments';

      attachments.forEach(function (attachment) {
        var el = createAttachmentElement(attachment);

        if (el) {
          wrap.appendChild(el);
        }
      });

      return wrap;
    }

    function createAttachmentElement(attachment) {
      if (!attachment || !attachment.id || !supportCode) {
        return null;
      }

      // The link/image target is the authorized download endpoint; the server
      // streams it with a forced attachment disposition and nosniff.
      var url = client.attachmentDownloadUrl(supportCode, attachment.id);
      var link = doc.createElement('a');
      link.setAttribute('href', url);
      link.setAttribute('target', '_blank');
      link.setAttribute('rel', 'noopener noreferrer');

      if (attachment.is_image) {
        link.className = 'wayfindr-widget__attachment wayfindr-widget__attachment--image';
        var img = doc.createElement('img');
        img.className = 'wayfindr-widget__attachment-image';
        img.setAttribute('src', url);
        img.setAttribute('alt', attachment.filename || t('attachment.fallbackName'));
        img.setAttribute('loading', 'lazy');
        link.appendChild(img);

        return link;
      }

      link.className = 'wayfindr-widget__attachment wayfindr-widget__attachment--file';
      link.setAttribute('download', '');

      var icon = doc.createElement('span');
      icon.className = 'wayfindr-widget__attachment-icon';
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = '📎';
      link.appendChild(icon);

      var label = doc.createElement('span');
      label.className = 'wayfindr-widget__attachment-name';
      label.textContent = attachment.filename || t('attachment.fallbackName');
      link.appendChild(label);

      if (attachment.size_bytes) {
        var size = doc.createElement('span');
        size.className = 'wayfindr-widget__attachment-size';
        size.textContent = formatAttachmentSize(attachment.size_bytes);
        link.appendChild(size);
      }

      return link;
    }

    function formatAttachmentSize(bytes) {
      bytes = Number(bytes) || 0;

      if (bytes >= 1024 * 1024) {
        return formatDecimal(t.locale, bytes / (1024 * 1024)) + ' MB';
      }

      if (bytes >= 1024) {
        return Math.round(bytes / 1024) + ' KB';
      }

      return bytes + ' B';
    }

    function open() {
      var wasHidden = panel.hidden;

      panel.hidden = false;
      launcher.hidden = true;
      launcher.setAttribute('aria-expanded', 'true');

      if (pendingCobrowseConsentFocus && cobrowseState === 'requested' && !cobrowse.hidden) {
        pendingCobrowseConsentFocus = false;
        cobrowseAllow.focus();
      } else {
        textarea.focus();
      }

      if (wasHidden && supportCode) {
        refreshMessages({ silent: true });
      }

      // The accent used to arrive only with the first send or a resume, so a
      // first-time visitor opened the panel on the brand fallback and watched
      // it change colour after they typed. Fetch it on open instead; a failure
      // here is silent by design, because the fallback is already correct.
      // Bootstrap once for the things that do not change, but re-ask on every
      // open. A tab left sitting since before closing time would otherwise
      // still show the desk as open, and one opened while away would still say
      // away long after support came back -- both silent, and both wrong at
      // exactly the moment the visitor decided to type.
      if (wasHidden) {
        refreshFromBootstrap().catch(function () {});
      }

      scheduleRenderedReadReceipt();
    }

    // Re-ask the server, and let only the newest answer touch the panel.
    //
    // Closing and reopening before the first request finishes leaves both in
    // flight. If they straddle a closing time and land out of order, the older
    // "open" answer would erase the newer away notice and the visitor would
    // keep the wrong state until they reopened again.
    function refreshFromBootstrap() {
      var seq = ++bootstrapSequence;

      bootstrapPromise = client.bootstrap(location ? location.href : null, visitorContext).then(function (result) {
        if (seq !== bootstrapSequence) {
          return;
        }

        bootstrapped = true;
        applyBootstrapResult(result);
      });

      // Opening the panel must not surface a failure: the fallback state is
      // already correct to look at. SENDING is different -- a send that
      // proceeds on a swallowed failure is a message posted without knowing
      // whether anybody is there, which is the case this exists to prevent. So
      // the rejection is kept on the promise the sender awaits, and only the
      // opener ignores it.
      bootstrapPromise.catch(function () {});

      return bootstrapPromise;
    }

    // Everything a bootstrap answer tells the widget, applied in one place.
    if (presenceDeclineEl) {
      presenceDeclineEl.addEventListener('click', function () {
        declinePresence();
      });
    }

    function applyBootstrapResult(result) {
      // Language first: everything below renders copy, and rendering it twice
      // would show the visitor the wrong language for a frame.
      applyLocale(siteLocale(result));
      applySiteAccent(rootEl, siteAccentKey(result));
      var appearance = siteAppearance(result);

      applyAppearance(appearance);

      if (appearance) {
        rememberAppearance(appearance);
      }
      applyAwayState(panel, siteAwayState(result), t);
      applyIntakeState(siteIntakeState(result));
      applyHelpAvailability(siteHasArticles(result));
      ratingConfig = siteRatingPrompt(result);
      renderRatingPrompt();

      // Presence is not STARTED from here -- that belongs to fetchSiteConfig(),
      // the one path that runs without the panel -- but a running reporter is
      // updated, because bootstrap is the freshest answer a long-lived tab
      // ever gets. fetchSiteConfig() runs once per page load, so a tab left
      // open all afternoon would otherwise keep the settings it started with
      // and go on sending page addresses an operator switched off hours ago.
      refreshPresenceSettings(result && result.site ? result.site.presence : null);
    }

    /**
     * Report that somebody is here, once the notice saying so exists.
     *
     * Four conditions, and every one of them can stop it:
     *
     *  - the operator has not switched it on for this site;
     *  - the visitor declined, which is remembered per site;
     *  - the browser cannot REMEMBER a decline, so we fail closed -- an embed
     *    passing `storage: null` or a private window would otherwise resume
     *    reporting on the next page for somebody who already said no;
     *  - the tab is hidden, which is the honest signal that nobody is looking.
     */
    function applyPresence(config) {
      stopPresence();

      presenceConfig = config && config.reports === true ? config : null;

      if (!presenceConfig) {
        return;
      }

      var key = presenceStorageKey(options.sitePublicKey);

      // Fail closed. If a "no" cannot survive a navigation, we do not get to
      // assume a "yes".
      //
      // presenceConfig is cleared before BOTH returns rather than left set:
      // the visibility handler gates on it, so leaving it truthy meant hiding
      // and re-showing the tab restarted reporting -- underneath a notice
      // saying pages were not being shared.
      if (!storageRemembers(storage, key)) {
        presenceConfig = null;

        return;
      }

      if (storageGet(storage, key) === 'declined') {
        presenceConfig = null;
        renderPresenceDeclined();

        return;
      }

      renderPresenceDisclosure();

      // The first report waits for the notice to be PAINTED, not merely to
      // exist. Being in the document is not being visible: the element is
      // inserted with its styles unresolved, and reporting in the same task
      // sent the first heartbeat before the browser had any opportunity to
      // show the visitor anything. Two frames is the ordinary way to say
      // "after the next paint" -- one schedules before the coming frame, the
      // second lands after it.
      //
      // Re-checked rather than assumed at that point, because two frames is
      // long enough for the answer to change.
      startPresenceAfterDisclosure();
    }

    /**
     * Apply a newer answer to a reporter that is already running.
     *
     * Deliberately not applyPresence(): that stops and restarts, which would
     * send a fresh first-heartbeat and re-run the paint wait every time the
     * panel opened. This changes the settings underneath a running timer and
     * leaves the timer alone.
     *
     * Turning reporting off is the exception that must be immediate rather
     * than tidy -- an operator revoking it should not have to wait for the tab
     * to close.
     */
    function refreshPresenceSettings(config) {
      if (!presenceConfig) {
        return;
      }

      if (!config || config.reports !== true) {
        stopPresence();

        return;
      }

      presenceConfig = config;

      // The notice describes what is collected, so it changes with it.
      renderPresenceDisclosure();
    }

    /**
     * Begin reporting once the notice has had a frame to appear.
     *
     * The single way into a running heartbeat, so every path -- config
     * arriving, a tab returning to the foreground -- passes the same two
     * checks. Having the first caller do them and the rest inherit the result
     * is how the visibility path came to bypass both.
     */
    function startPresenceAfterDisclosure() {
      afterNextPaint(function () {
        if (!presenceConfig) {
          return;
        }

        if (!presenceNoticeVisible() || declineRecorded()) {
          presenceConfig = null;

          return;
        }

        sendPresence();
        startPresenceTimer();
      });
    }

    /**
     * Is the notice actually on screen, rather than merely in the document?
     *
     * `offsetParent` is null for an element that is display:none or inside
     * something that is -- which covers the notice being hidden, an ancestor
     * being hidden, and the whole widget being removed between frames.
     */
    function presenceNoticeVisible() {
      if (!presenceEl || !rootEl.contains(presenceEl) || presenceEl.hidden) {
        return false;
      }

      // Walked structurally first: an ancestor being hidden hides this too, and
      // the structural answer is the only one available where there is no
      // layout to ask -- a non-visual environment reports no geometry for
      // everything, so a check that only asked geometry would either refuse to
      // report at all or, read the other way round, wave everything through.
      var node = presenceEl;

      while (node && node !== rootEl.parentNode) {
        if (node.hidden === true) {
          return false;
        }

        if (node.style && node.style.display === 'none') {
          return false;
        }

        node = node.parentElement;
      }

      // Then geometry, which is what catches being hidden by the HOST page's
      // stylesheet rather than by us -- a class we never see, on an element we
      // do not own.
      if (typeof presenceEl.getClientRects !== 'function') {
        return true;
      }

      if (presenceEl.getClientRects().length > 0) {
        return true;
      }

      // No geometry has two meanings and they are opposites: genuinely hidden,
      // or an environment that lays nothing out at all. Asking a control
      // element separates them. If the document's own body has no box either,
      // there is no layout here and geometry cannot answer -- so the structural
      // check above stands rather than being overruled by a measurement that
      // reports every element as invisible.
      var body = doc && doc.body;
      var layoutAvailable = Boolean(body && typeof body.getClientRects === 'function'
        && body.getClientRects().length > 0);

      return !layoutAvailable;
    }

    function afterNextPaint(run) {
      var raf = presenceWindow && typeof presenceWindow.requestAnimationFrame === 'function'
        ? presenceWindow.requestAnimationFrame.bind(presenceWindow)
        : null;

      if (!raf) {
        // No rAF means no rendering to wait for -- a test harness or a
        // non-visual environment. Running late is still running.
        setTimeout(run, 0);

        return;
      }

      raf(function () {
        raf(run);
      });
    }

    /**
     * Another tab wrote the decline key for this site.
     *
     * Scoped to this site's key: a visitor declining on one site says nothing
     * about another, and reacting to every storage write in the page would
     * make an unrelated host application able to stop reporting by accident.
     */
    function handlePresenceStorageChange(event) {
      if (!event || event.key !== presenceStorageKey(options.sitePublicKey)) {
        return;
      }

      if (event.newValue === 'declined') {
        stopPresenceTimer();
        presenceConfig = null;
        renderPresenceDeclined();
      }
    }

    function declineRecorded() {
      return storageGet(storage, presenceStorageKey(options.sitePublicKey)) === 'declined';
    }

    function sendPresence() {
      if (!presenceConfig || presenceHidden()) {
        return;
      }

      // The stored decline is re-read on EVERY beat rather than trusted from
      // the in-memory config. The same site is often open in several tabs, and
      // "Stop sharing" in one of them writes the site-wide key but can only
      // stop its own instance -- so every other loaded tab went on reporting
      // the visitor who had just said no. A decline is a decision about the
      // person, not about the tab they happened to click in.
      if (declineRecorded()) {
        stopPresenceTimer();
        presenceConfig = null;
        renderPresenceDeclined();

        return;
      }

      // The page only when the site asked for it. Told by the server rather
      // than decided here, and the server drops it again on arrival -- but an
      // address the operator has said not to keep should not travel at all,
      // which is the whole reason the client sanitises in the first place.
      var pageUrl = presenceConfig.page_urls === false ? null : sanitisePageUrl(currentHref());

      client.reportPresence(pageUrl).catch(function () {
        // A missed heartbeat is a visitor reading as quiet, which is a fair
        // description of somebody we cannot reach.
      });
    }

    function startPresenceTimer() {
      stopPresenceTimer();

      var every = presencePollMs === null
        ? Math.max(5, Number(presenceConfig && presenceConfig.every) || 45) * 1000
        : presencePollMs;

      if (every <= 0) {
        return;
      }

      presenceTimer = setInterval(sendPresence, every);
    }

    function stopPresenceTimer() {
      if (presenceTimer) {
        clearInterval(presenceTimer);
        presenceTimer = null;
      }
    }

    function stopPresence() {
      stopPresenceTimer();
      presenceConfig = null;

      if (presenceEl) {
        presenceEl.hidden = true;
      }
    }

    function presenceHidden() {
      return Boolean(doc && doc.visibilityState === 'hidden');
    }

    function currentHref() {
      // The RESOLVED location, then the DOCUMENT's own. Production embeds pass
      // neither `options.location` nor a document, so reading the option alone
      // meant every heartbeat omitted the page and the board could never say
      // where anybody was.
      //
      // An EXPLICITLY supplied document wins over the ambient location. A host
      // calling init({document: iframe.contentDocument}) without passing a
      // location got `root.location` -- the surrounding page -- so the widget
      // reported an address the visitor was not on, and on a same-origin
      // iframe integration that address can be an unrelated part of the site.
      // The document it was handed is the document it is in.
      if (options.document && options.document.location && options.document.location.href) {
        return options.document.location.href;
      }

      if (location && location.href) {
        return location.href;
      }

      return doc && doc.location && doc.location.href ? doc.location.href : null;
    }

    function renderPresenceDisclosure() {
      if (!presenceEl) {
        return;
      }

      presenceEl.hidden = false;

      if (presenceCopyEl) {
        // The notice has to describe what is actually collected. A site with
        // page addresses switched off sends only "somebody is here", and a
        // disclosure claiming otherwise is both untrue and a worse explanation
        // than none -- it describes a sharing the visitor cannot stop because
        // it is not happening.
        presenceCopyEl.textContent = presenceConfig && presenceConfig.page_urls === false
            ? t('presence.disclosureNoPage')
            : t('presence.disclosure');
      }

      if (presenceDeclineEl) {
        presenceDeclineEl.hidden = false;
        presenceDeclineEl.textContent = t('presence.decline');
      }
    }

    function renderPresenceDeclined() {
      if (!presenceEl) {
        return;
      }

      presenceEl.hidden = false;

      if (presenceCopyEl) {
        presenceCopyEl.textContent = t('presence.declined');
      }

      if (presenceDeclineEl) {
        presenceDeclineEl.hidden = true;
      }
    }

    function declinePresence() {
      storageSet(storage, presenceStorageKey(options.sitePublicKey), 'declined');
      stopPresenceTimer();
      presenceConfig = null;
      renderPresenceDeclined();
    }

    /**
     * What this site looks like and says to its own visitors.
     *
     * Set as custom properties rather than by rewriting rules: --wf-brand is
     * already read by 22 of them, so pointing that one token at the operator's
     * colour recolours every button, link and focus ring at once -- and leaving
     * it alone keeps Wayfindr's exactly as before.
     */
    function applyAppearance(appearance) {
      if (!appearance) {
        return;
      }

      // Cleared before applied. Bootstrap is refreshed on every open precisely
      // so a settings change takes effect, and a truthy-only assignment left a
      // long-lived tab branded with a colour the operator had removed -- the
      // same shape as the site-default language bug one release ago.
      [
        '--wf-brand-configured',
        '--wf-brand-configured-dark',
        '--wf-brand-ink-configured',
        '--wf-brand-ink-configured-dark',
      ].forEach(function (name) {
        rootEl.style.removeProperty(name);
      });

      if (appearance.accent) {
        // Both renderings, chosen by the theme in CSS rather than here. The
        // widget does not know which theme it is in -- the media query and the
        // data-wf-theme attribute do, and they already disagree deliberately.
        rootEl.style.setProperty('--wf-brand-configured', appearance.accent);
        rootEl.style.setProperty('--wf-brand-configured-dark', appearance.accent_dark || appearance.accent);
        rootEl.style.setProperty('--wf-brand-ink-configured', appearance.accent_ink || '#FFFFFF');
        rootEl.style.setProperty('--wf-brand-ink-configured-dark', appearance.accent_ink_dark || appearance.accent_ink || '#FFFFFF');
      }

      rootEl.setAttribute('data-wf-launcher', appearance.position === 'left' ? 'left' : 'right');

      // Operator copy wins over the catalogue, and the catalogue over nothing.
      // A host-page option still outranks both: it is the most specific answer.
      // Falling back to t() rather than leaving the previous value is what makes
      // clearing the copy take effect.
      if (!options.title) {
        var heading = rootEl.querySelector('.wayfindr-widget__header strong');

        if (heading) {
          heading.textContent = appearance.greeting || t('header.title');
        }
      }

      if (!options.placeholder) {
        textarea.setAttribute('placeholder', appearance.placeholder || t('form.placeholder'));
      }
    }

    /**
     * Learn the launcher's corner before anybody sees it.
     *
     * The launcher is drawn at init and bootstrap does not run until the panel
     * opens, so a left-configured launcher sat on the right on every fresh
     * load. Worst in the case the setting exists for: the right corner already
     * occupied, and the visitor unable to reach the launcher at all.
     *
     * The last answer is remembered, so a returning visitor pays nothing and a
     * first-time one pays a single read that creates no visitor record.
     */
    function applyStoredAppearance() {
      var cached = storageGet(widgetStorage, appearanceStorageKey(options.sitePublicKey));

      if (cached) {
        try {
          // Painted from the cache, and then asked anyway. The early return
          // that used to be here made a RETURNING visitor -- anyone holding a
          // cached appearance, which is everyone after their first page --
          // never fetch the config that now carries presence. They saw no
          // disclosure and sent no heartbeat: the same defect this rework was
          // for, reintroduced one line further down.
          applyAppearance(JSON.parse(cached));
        } catch (error) {
          storageRemove(widgetStorage, appearanceStorageKey(options.sitePublicKey));
        }
      }

      fetchSiteConfig();
    }

    /**
     * Ask for the public configuration a page load is allowed to know.
     *
     * Called even when a cached appearance was just applied, which is a change
     * of role for the cache rather than a change of mind about it: it still
     * buys the instant paint it was added for, but it is no longer the answer.
     *
     * It cannot be, now that presence rides along. The stored appearance has no
     * expiry, so a visitor who never opens the panel would hold whatever the
     * site's presence setting was on their first ever visit -- for good. An
     * operator switching it off would be telling a browser that stopped
     * listening months ago.
     *
     * The request is unauthenticated, writes nothing, and returns the same
     * bytes to everyone on the site, so it is one cacheable GET per page view
     * rather than anything a visitor pays for twice.
     */
    function fetchSiteConfig() {
      client.fetchAppearance().then(function (result) {
        var appearance = (result && result.appearance) || null;

        if (appearance) {
          applyAppearance(appearance);
          rememberAppearance(appearance);
        }

        // The site's language BEFORE the disclosure is rendered. It only
        // decides anything when neither the host page nor the browser has a
        // preference -- and in exactly that case a silent visitor on a German
        // site was shown an English privacy notice, because the site default
        // used to arrive with bootstrap and a silent visitor never bootstraps.
        if (result && typeof result.locale === 'string') {
          applyLocale(result.locale);
        }

        applyPresence((result && result.presence) || null);
      }).catch(function () {
        // The default corner is a fine answer to a failed lookup, and no
        // answer about presence means no reporting -- which is the direction
        // an unanswered privacy question has to fail in.
      });
    }

    function rememberAppearance(appearance) {
      try {
        storageSet(widgetStorage, appearanceStorageKey(options.sitePublicKey), JSON.stringify(appearance));
      } catch (error) {
        // A full or unavailable store just means asking again next time.
      }
    }

    /**
     * Offer the search only where there is something to find.
     *
     * Read from the bootstrap answer rather than discovered by running an empty
     * search on every open: that would be a request per open purely to decide
     * whether to draw a box, and bootstrap is already being asked.
     */
    function applyHelpAvailability(available) {
      help.hidden = !available;

      if (!available) {
        helpResults.innerHTML = '';
        helpArticle.hidden = true;
        helpStatus.hidden = true;
      }
    }

    // The composer has never been gated before. It is gated now only until the
    // questions the site asked are answered, and never once a conversation
    // exists -- coming back to an existing thread must not meet a form.
    function applyIntakeState(state) {
      // Held separately from the gate decision. A resume can set supportCode
      // after bootstrap answers, so the decision has to be re-derivable rather
      // than taken once at whichever moment happened to come first.
      intakeConfig = state;

      refreshIntakeGate();
    }

    function intakeSignature(state) {
      return state
        ? state.fields.map(function (field) { return field.name + ':' + field.required; }).join('|')
        : '';
    }

    // The one question every gate decision asks. The gate and the send path
    // have to agree on it, and two spellings of it is how a send came to
    // proceed straight through a gate that had just closed.
    function intakeGateHolds() {
      return Boolean(intakeState) && !intakeAnswered;
    }

    function refreshIntakeGate() {
      // A resume counts as having a conversation. It has not set supportCode
      // yet, but it is about to, and gating on the gap shows a form that is
      // then taken away.
      intakeState = (supportCode || resumePending) ? null : intakeConfig;

      // Answering covers the questions that were ASKED. Crossing a closing
      // time makes an email newly required, and treating the earlier answer as
      // covering it hid a field the server now demands -- a 422 no amount of
      // reopening could clear, because the flag never reset.
      var signature = intakeSignature(intakeState);

      if (signature !== intakeAnsweredSignature) {
        intakeAnswered = false;
      }

      if (!intakeGateHolds()) {
        intakeForm.hidden = true;
        setIntakeGate(false);

        return;
      }

      intakeIntro.textContent = intakeState.intro || '';
      intakeIntro.hidden = !intakeState.intro;
      intakeFields.innerHTML = '';

      intakeState.fields.forEach(function (field) {
        // doc, not the global: the widget is initialised with a document so it
        // can run under JSDOM and inside a host page that is not the top frame.
        var label = doc.createElement('label');
        var text = doc.createTextNode(
          field.required ? intakeFieldLabel(t, field.name) : t('intake.optional', { label: intakeFieldLabel(t, field.name) })
        );
        var input = doc.createElement('input');

        input.type = field.name === 'email' ? 'email' : 'text';
        input.name = field.name;
        input.required = field.required;
        input.autocomplete = field.name === 'email' ? 'email' : (field.name === 'name' ? 'name' : 'off');
        // Matches the server's rule, so the ordinary case is caught here rather
        // than as a 422 the visitor has to decipher.
        input.maxLength = 255;

        label.appendChild(text);
        label.appendChild(input);
        intakeFields.appendChild(label);
      });

      intakeForm.hidden = false;
      setIntakeGate(true);
    }

    /**
     * Build a run of inline text as an element.
     *
     * Every branch produces a node with its text assigned, never a string of
     * markup -- the same rule message bodies and intake labels already follow.
     * A run whose shape is unrecognised falls through to plain text rather than
     * being skipped, so unknown formatting loses its emphasis, not its words.
     */
    function buildSpan(span) {
      var text = String(span && span.text != null ? span.text : '');

      if (span && span.href) {
        var link = doc.createElement('a');

        link.href = span.href;
        link.textContent = text;
        // The destination came from the server, which already refused every
        // scheme but http, https and mailto. These are the belt: a help article
        // opening in the host page would navigate the visitor away from the
        // site they came to for help.
        link.target = '_blank';
        link.rel = 'noopener noreferrer nofollow';

        return link;
      }

      var el = doc.createElement(span && span.strong ? 'strong' : (span && span.code ? 'code' : 'span'));

      el.textContent = text;

      return el;
    }

    function appendSpans(parent, spans) {
      (spans || []).forEach(function (span) {
        parent.appendChild(buildSpan(span));
      });
    }

    /**
     * Build an article. A block type this version does not know renders as
     * nothing, which is the direction that failure should go: an older widget
     * meeting a newer article shows less of it, never markup it cannot judge.
     */
    function renderArticleBlocks(blocks) {
      helpBlocks.innerHTML = '';

      (blocks || []).forEach(function (block) {
        if (!block || typeof block !== 'object') {
          return;
        }

        if (block.type === 'heading') {
          var heading = doc.createElement('h3');

          heading.textContent = String(block.text || '');
          helpBlocks.appendChild(heading);

          return;
        }

        if (block.type === 'paragraph') {
          var para = doc.createElement('p');

          appendSpans(para, block.spans);
          helpBlocks.appendChild(para);

          return;
        }

        if (block.type === 'list') {
          var list = doc.createElement('ul');

          (block.items || []).forEach(function (item) {
            var li = doc.createElement('li');

            appendSpans(li, item);
            list.appendChild(li);
          });

          helpBlocks.appendChild(list);
        }
      });
    }

    /**
     * Ask how it went, once, after the conversation is closed.
     *
     * Only where the operator turned it on, only when there is a conversation
     * to be asked about, and never twice: a visitor who has answered is done,
     * and a widget that keeps asking is one they stop reading.
     */
    function renderRatingPrompt() {
      var shouldAsk = Boolean(ratingConfig && ratingConfig.asks)
        && supportCode !== null
        && conversationStatus === 'closed'
        && ! ratingAnswered;

      rating.hidden = ! shouldAsk;

      if (! shouldAsk) {
        return;
      }

      ratingIntro.textContent = ratingConfig.intro || t('rating.intro');
      ratingLabel.textContent = t('rating.comment');
      ratingSend.textContent = t('rating.send');

      [].forEach.call(rating.querySelectorAll('.wayfindr-widget__rating-score'), function (button) {
        button.textContent = t('rating.' + button.getAttribute('data-score'));
        button.setAttribute('aria-pressed', button.getAttribute('data-score') === ratingScore ? 'true' : 'false');
      });

      applyRatingFormState();
    }

    /**
     * Enable or freeze the whole form, not just the send button.
     *
     * While an answer is in flight the request has already captured the score
     * and comment, so leaving the controls live lets the visitor change what
     * they see to something that was never sent -- and then be thanked for it
     * when the response lands.
     */
    function applyRatingFormState() {
      var busy = ratingInFlight !== null && ratingInFlight === ratingEpisode;

      ratingSend.disabled = busy || ratingScore === null;
      ratingComment.disabled = busy;

      [].forEach.call(rating.querySelectorAll('.wayfindr-widget__rating-score'), function (button) {
        button.disabled = busy;
      });
    }

    [].forEach.call(rootEl.querySelectorAll('.wayfindr-widget__rating-score'), function (button) {
      button.addEventListener('click', function () {
        ratingScore = button.getAttribute('data-score');
        renderRatingPrompt();
      });
    });

    rating.addEventListener('submit', function (event) {
      event.preventDefault();

      // A score is the answer; the comment is optional and usually empty. The
      // episode has to be known too -- an answer the server cannot tie to a
      // specific close is one it would have to guess about.
      if (ratingScore === null || supportCode === null || ratingEpisode === null) {
        return;
      }

      // One answer in flight per close. Not a plain "already submitting"
      // guard: a stale request for a superseded close may still be running,
      // and the visitor must be able to answer the close actually in front of
      // them while it does.
      if (ratingInFlight === ratingEpisode) {
        return;
      }

      // Which close this request is about, captured now. A poll can land a
      // newer close while the request is in flight, and marking the widget
      // answered on the way back would hide a prompt for work this answer was
      // never about -- leaving the visitor unable to answer it until some later
      // poll happens to restore it, or not at all if polling is failing.
      var submittedEpisode = ratingEpisode;

      ratingInFlight = submittedEpisode;
      applyRatingFormState();

      client.rateConversation(supportCode, ratingScore, ratingComment.value.trim(), submittedEpisode).then(function () {
        // Says nothing when the close it answered is no longer the one on
        // screen. Thanking the visitor beside a fresh, unanswered question
        // reads as an acknowledgement OF that question -- the same reason the
        // failure branch below stays quiet. An earlier version of this thanked
        // them anyway, reasoning that they had answered something; that holds
        // only while their answer is still the one being asked about.
        if (submittedEpisode !== ratingEpisode) {
          return;
        }

        ratingAnswered = true;
        rating.hidden = true;
        status.textContent = t('rating.thanks');
      }).catch(function (error) {
        reportSuppressed('conversation rating', error);

        // A failure for a close that is no longer on screen is not this
        // prompt's failure, and saying so over a fresh question is worse than
        // saying nothing.
        if (submittedEpisode !== ratingEpisode) {
          return;
        }

        ratingStatus.textContent = t('rating.failed');
        ratingStatus.hidden = false;
      }).then(function () {
        // Only the request that owns the flag clears it. A stale one settling
        // must not unfreeze a form whose own answer is still on the wire, or
        // the visitor can send the current close twice and whichever response
        // lands last is the one stored.
        if (ratingInFlight === submittedEpisode) {
          ratingInFlight = null;
        }

        applyRatingFormState();
      });
    });

    function showHelpResults() {
      helpArticle.hidden = true;
      helpResults.hidden = helpResults.children.length === 0;
    }

    function openArticle(slug) {
      // Sequenced on the same counter as the search. The results stay on screen
      // while a fetch is in flight, so a visitor can click a second answer
      // before the first arrives -- and the slower request landing last would
      // replace the article they actually chose.
      var ticket = ++helpSequence;

      client.fetchArticle(slug).then(function (result) {
        if (ticket !== helpSequence) {
          return;
        }

        var article = (result && result.article) || {};

        renderArticleBlocks(article.blocks);
        helpResults.hidden = true;
        helpArticle.hidden = false;
        helpStatus.hidden = true;
      }).catch(function (error) {
        if (ticket !== helpSequence) {
          return;
        }

        reportSuppressed('article fetch', error);
        helpStatus.textContent = t('help.failed');
        helpStatus.hidden = false;
      });
    }

    function renderHelpResults(articles) {
      helpResults.innerHTML = '';

      articles.forEach(function (article) {
        var item = doc.createElement('li');
        var button = doc.createElement('button');

        button.type = 'button';
        button.className = 'wayfindr-widget__help-result';
        button.textContent = String(article.title || '');
        button.addEventListener('click', function () {
          openArticle(String(article.slug || ''));
        });

        item.appendChild(button);
        helpResults.appendChild(item);
      });

      showHelpResults();
    }

    /**
     * Ask the server what matches.
     *
     * Sequenced like every other read here: an earlier search finishing after a
     * later one would otherwise leave the visitor looking at results for a
     * question they have already moved on from.
     */
    function searchHelp(query) {
      var ticket = ++helpSequence;

      helpStatus.textContent = t('help.searching');
      helpStatus.hidden = false;

      client.searchArticles(query).then(function (result) {
        if (ticket !== helpSequence) {
          return;
        }

        var articles = (result && result.articles) || [];

        renderHelpResults(articles);
        helpStatus.textContent = articles.length === 0 && query !== '' ? t('help.none') : '';
        helpStatus.hidden = helpStatus.textContent === '';
      }).catch(function (error) {
        if (ticket !== helpSequence) {
          return;
        }

        reportSuppressed('article search', error);
        helpStatus.textContent = t('help.failed');
        helpStatus.hidden = false;
      });
    }

    helpInput.addEventListener('input', function () {
      if (helpDebounce) {
        clearTimeout(helpDebounce);
      }

      var query = helpInput.value.trim();

      helpDebounce = setTimeout(function () {
        searchHelp(query);
      }, helpSearchDebounceMs);
    });

    helpBack.addEventListener('click', showHelpResults);

    function setIntakeGate(gated) {
      form.hidden = gated;
    }

    // Hand the form back when the server rejects what it collected.
    //
    // Browsers accept "a@b" as an email input and the server's email:filter
    // does not, so a 422 here is ordinary rather than exotic. Treated as a
    // generic send failure it was fatal: the only UI that could correct the
    // answer was hidden, the answered flag stayed set, and Retry resent the
    // same rejected values -- the visitor could not start a conversation at
    // all without closing and reopening the panel.
    function reopenIntakeForCorrection(error) {
      if (!intakeState || !error || error.status !== 422) {
        return false;
      }

      intakeAnswered = false;
      intakeAnsweredSignature = '';
      refreshIntakeGate();

      intakeError.textContent = errorText(error, 'intake.checkDetails');
      intakeError.hidden = false;

      return true;
    }

    function intakeAnswers() {
      var answers = {};

      if (!intakeState) {
        return answers;
      }

      intakeState.fields.forEach(function (field) {
        var input = intakeFields.querySelector('[name="' + field.name + '"]');

        answers['visitor_' + field.name] = input ? input.value : '';
      });

      return answers;
    }

    intakeForm.addEventListener('submit', function (event) {
      event.preventDefault();
      intakeError.hidden = true;

      var missing = intakeState && intakeState.fields.some(function (field) {
        var input = intakeFields.querySelector('[name="' + field.name + '"]');

        return field.required && (!input || !input.value.trim());
      });

      if (missing) {
        // The server enforces this too; answering here saves a round trip and
        // is the only per-field feedback the widget has ever had.
        intakeError.textContent = t('intake.required');
        intakeError.hidden = false;

        return;
      }

      intakeAnswered = true;
      intakeAnsweredSignature = intakeSignature(intakeState);
      intakeForm.hidden = true;
      setIntakeGate(false);

      if (textarea) {
        textarea.focus();
      }
    });

    function closePanel() {
      cancelPendingReadReceipt();
      panel.hidden = true;
      launcher.hidden = false;
      launcher.setAttribute('aria-expanded', 'false');
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
    panel.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        closePanel();
      }
    });
    refresh.addEventListener('click', function () {
      refreshMessages();
    });
    if (jump) {
      jump.addEventListener('click', function () {
        scrollTimelineToBottom();
        hideJumpCue();
      });
    }
    timeline.addEventListener('scroll', function () {
      if (timelineIsAtBottom()) {
        hideJumpCue();
      }
    });
    noticeRetry.addEventListener('click', function () {
      if (noticeRetryAction) {
        noticeRetryAction();

        return;
      }

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
    attachButton.addEventListener('click', function () {
      if (composerBusy) {
        return;
      }

      fileInput.click();
    });
    fileInput.addEventListener('change', function () {
      addPendingFiles(fileInput.files);
      // Reset so re-picking the same file fires change again.
      fileInput.value = '';
    });
    textarea.addEventListener('keydown', function (event) {
      // Enter sends the message; Shift+Enter keeps the newline for multi-line
      // drafts. Ignore Enter while an IME composition is active (keyCode 229 /
      // isComposing) so composing CJK input does not send a partial message.
      if (event.key !== 'Enter' || event.shiftKey || event.altKey || event.ctrlKey || event.metaKey) {
        return;
      }

      if (event.isComposing || event.keyCode === 229) {
        return;
      }

      event.preventDefault();
      submitComposerForm();
    });
    doc.addEventListener('visibilitychange', handleVisibilityChange);

    // A decline made in ANOTHER tab. The per-beat re-read is the guarantee;
    // this is the promptness -- without it a visitor who clicks "Stop sharing"
    // watches their other tabs keep reporting for up to a full interval, which
    // is exactly the moment they are looking for the control to have worked.
    //
    // `storage` fires only in the other documents, never in the one that wrote,
    // which is what makes it the right event: the writing tab has already
    // stopped itself.
    if (presenceWindow && typeof presenceWindow.addEventListener === 'function') {
        presenceWindow.addEventListener('storage', handlePresenceStorageChange);
    }

    function submitComposerForm() {
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();

        return;
      }

      var view = doc.defaultView || root;
      var EventConstructor = view && view.Event ? view.Event : root && root.Event ? root.Event : null;

      if (EventConstructor) {
        form.dispatchEvent(new EventConstructor('submit', { bubbles: true, cancelable: true }));
      }
    }

    function retryComposerSend() {
      if (composerBusy) {
        return;
      }

      submitComposerForm();
    }

    form.addEventListener('submit', async function (event) {
      event.preventDefault();

      if (composerBusy) {
        return;
      }

      var body = textarea.value.trim();

      // An upload still in flight would leave its chip out of the send; wait for
      // it to finish (or be removed) rather than send a partial set.
      if (hasUploadingAttachments()) {
        status.textContent = t('status.waitingUploads');

        return;
      }

      var readyIds = readyAttachmentIds();
      var stagedEntries = stagedPendingEntries();

      // A message needs text, an uploaded file, or a file staged before the
      // conversation existed (uploaded at send below).
      if (!body && readyIds.length === 0 && stagedEntries.length === 0) {
        return;
      }

      setComposerBusy(true);
      status.textContent = t('status.sending');

      // Reuse the same idempotency key while retrying the same draft, so a lost
      // response on the first attempt does not create a duplicate message when
      // the visitor retries. A new draft (changed text or attachments) gets a
      // fresh key — pendingClientMessageId is reset whenever attachments change.
      if (pendingClientMessageId === null || pendingClientMessageBody !== body) {
        pendingClientMessageId = generateClientMessageId();
        pendingClientMessageBody = body;
      }

      var sentMessage;

      try {
        // A resume of a stored conversation may still be in flight on a slow
        // reload. Wait for it (it never rejects) so a quick first message
        // continues the restored thread instead of racing it and creating a
        // duplicate conversation.
        if (resumePromise) {
          await resumePromise;
          resumePromise = null;
        }

        // The rules behind the gate belong to the server and can change
        // between the panel opening and this send -- a closing time crossed,
        // an operator editing what the site asks. A conversation created on a
        // stale copy earns a 422 the visitor cannot clear, because the form is
        // rebuilt from the same stale rules that just failed. So the send that
        // creates the conversation always asks again.
        if (!supportCode || !bootstrapped) {
          await refreshFromBootstrap();
        } else if (bootstrapPromise) {
          // A refresh started when the panel opened may still be in flight.
          // Sending before it lands means sending without knowing whether
          // anybody is there, which is exactly what the away notice exists to
          // prevent.
          await bootstrapPromise;
        }

        // That answer may have opened the gate: it had not arrived when this
        // send began, or the questions just changed under it. Either way they
        // are unanswered now, and continuing would post empty answers or earn
        // the 422. The composer is hidden behind the form but keeps its text,
        // so the visitor answers and sends the same message again.
        if (intakeGateHolds()) {
          setComposerBusy(false);
          status.textContent = t('intake.pending');

          return;
        }

        // The first message creates the conversation (with the body as its
        // subject), so files staged before it existed have somewhere to go — no
        // empty conversation is created just by picking a file.
        if (!supportCode) {
          var conversation = await client.startConversation(body, {
            pageUrl: location ? location.href : null,
            context: visitorContext,
            intake: intakeAnswers(),
          });

          applyConversationStatus(conversation);
          supportCode = conversation.support_code;
          refreshIntakeGate();
          storageSet(widgetStorage, supportCodeStorageKey(options.sitePublicKey), supportCode);
          // Don't activate (polling/realtime/refresh) until the message actually
          // sends — a failed first send leaves the conversation dormant, as
          // before. The staged upload below only needs the support code.
        }

        // Upload anything staged before the conversation existed, then send with
        // every attachment id (already-uploaded plus the just-uploaded staged).
        var stagedIds = [];

        for (var i = 0; i < stagedEntries.length; i++) {
          var stagedId = await uploadPendingEntry(stagedEntries[i]);

          if (stagedId) {
            stagedIds.push(stagedId);
          }
        }

        sentMessage = await client.sendMessage(supportCode, body, pendingClientMessageId, readyIds.concat(stagedIds));
      } catch (error) {
        reportSuppressed('message send', error);
        setComposerBusy(false);

        // A rejected answer is correctable, and offering Retry instead would
        // resend the same values from a form the visitor can no longer see.
        if (reopenIntakeForCorrection(error)) {
          return;
        }

        status.textContent = t('error.send');
        showNotice('warning', t('error.send'), {
          retry: true,
          onRetry: retryComposerSend,
        });

        return;
      }

      // The message IS sent from here on, and nothing below may claim
      // otherwise. Rendering it, connecting realtime and refreshing are all
      // work that happens AFTER the server accepted it, and reporting a
      // failure for any of them told the visitor to send again — which is
      // exactly what produced duplicate messages, since the retry succeeded
      // too and reported the same false failure.
      try {
        applyConversationStatus(sentMessage.conversation);
        appendMessage(sentMessage.message);
        renderConversationNotice();
        activateConversation();

        textarea.value = '';
        clearPendingAttachments();
        pendingClientMessageId = null;
        pendingClientMessageBody = null;
        await refreshMessages({ silent: true });
        await refreshCobrowseStatus({ silent: true });
        status.textContent = t('status.messageSent', { code: supportCode });
      } catch (error) {
        reportSuppressed('post-send update', error);

        // Still a success as far as the visitor is concerned: the transcript
        // may be a beat behind, and the poll scheduled by activateConversation
        // catches it up.
        status.textContent = t('status.messageSent', { code: supportCode });
      } finally {
        setComposerBusy(false);
      }
    });

    // Resume the visitor's persisted conversation after a page reload: restore
    // the timeline, realtime/polling loops, and cobrowse status through the
    // same paths a fresh conversation uses. A server rejection (stale code,
    // revoked token, foreign reference) clears the stored code and falls back
    // to a fresh start without alarming the visitor; a transient network
    // failure keeps the code for the next load.
    async function resumeConversation(candidateCode) {
      try {
        if (!bootstrapped) {
          await refreshFromBootstrap();
        }

        var result = await client.fetchMessages(candidateCode);

        supportCode = candidateCode;
        refreshIntakeGate();
        applyConversationStatus(result.conversation);
        renderMessages(result.messages || []);
        renderAgentTyping(result.agent_typing);
        renderConversationNotice();
        activateConversation();
        status.textContent = t('status.conversationRestored', { code: supportCode });
        await refreshCobrowseStatus({ silent: true });
      } catch (error) {
        if (error && typeof error.status === 'number' && error.status >= 400 && error.status < 500) {
          storageRemove(widgetStorage, supportCodeStorageKey(options.sitePublicKey));
        }
      } finally {
        // Settled either way, and only now can the gate tell the two apart: a
        // restored conversation needs no form, while a rejected code means
        // this visitor really is starting fresh and must be asked.
        resumePending = false;
        refreshIntakeGate();
      }
    }

    applyStoredAppearance();

    if (storedSupportCode) {
      resumePromise = resumeConversation(storedSupportCode);
    }

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
        cancelPendingReadReceipt();
        clearAgentTypingExpiry();
        stopMutationStream();
        stopPresence();
        doc.removeEventListener('visibilitychange', handleVisibilityChange);

        if (presenceWindow && typeof presenceWindow.removeEventListener === 'function') {
            presenceWindow.removeEventListener('storage', handlePresenceStorageChange);
        }
        rootEl.remove();
      },
    };
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

    // The bundled copy first: widget.js carries the realtime library itself,
    // published under a namespaced global so the host page's own `Pusher` is
    // left exactly as it was. `root.Pusher` remains a fallback for pages that
    // still load the library separately, which the old install snippet did.
    var Pusher = options.Pusher || (root && (root.__wayfindrPusher || root.Pusher));

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
          // pusher-js demands a cluster even when wsHost names the server
          // outright, and throws "Options object must provide a cluster"
          // without one -- so every widget realtime connection was failing at
          // construction, on every install, whatever the host.
          //
          // The value is unused here: a cluster only picks Pusher's own hosted
          // endpoint, which wsHost replaces. An empty string satisfies the
          // check and is what Laravel Echo passes for a self-hosted Reverb.
          cluster: reverb.cluster || '',
          wsHost: reverb.host,
          wsPort: reverb.wsPort || port,
          wssPort: reverb.wssPort || port,
          forceTLS: scheme === 'https',
          // Websockets only, and no telemetry -- both stated rather than
          // inherited. The library's built-in defaults still name
          // pusher.com hosts: `cdn_https` is where it lazily fetches
          // dependencies for the HTTP fallback transports, and `stats_host` is
          // where it reports usage. Restricting transports means the first is
          // never reached, and stats are off unless asked for -- but a
          // self-hosted install should not depend on a third party's defaults
          // staying convenient. Saying so keeps a library upgrade from
          // quietly reintroducing an outbound request (issue #714).
          enabledTransports: reverb.enabledTransports || ['ws', 'wss'],
          enableStats: false,
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

  function parseMessageDate(value) {
    if (!value) {
      return null;
    }

    var date = new Date(String(value));

    return Number.isNaN(date.getTime()) ? null : date;
  }

  function dayKeyFromDate(date) {
    return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
  }

  function messageDayKey(value) {
    var date = parseMessageDate(value);

    return date ? dayKeyFromDate(date) : null;
  }

  function createDaySeparator(doc, value, t) {
    var date = parseMessageDate(value);

    if (!date) {
      return null;
    }

    var separator = doc.createElement('div');
    separator.className = 'wayfindr-widget__day-separator';
    separator.setAttribute('role', 'separator');

    var label = doc.createElement('time');
    label.className = 'wayfindr-widget__day-label';
    label.dateTime = dayKeyFromDate(date);
    label.textContent = formatDayLabel(date, t);

    separator.appendChild(label);

    return separator;
  }

  function formatDayLabel(date, t) {
    var today = new Date();
    var todayKey = dayKeyFromDate(today);
    var yesterdayKey = dayKeyFromDate(new Date(today.getTime() - 24 * 60 * 60 * 1000));
    var key = dayKeyFromDate(date);

    if (key === todayKey) {
      return t('date.today');
    }

    if (key === yesterdayKey) {
      return t('date.yesterday');
    }

    try {
      return date.toLocaleDateString(localeTags(t.locale), { year: 'numeric', month: 'long', day: 'numeric' });
    } catch (error) {
      return dayKeyFromDate(date);
    }
  }

  function pad2(value) {
    return (value < 10 ? '0' : '') + value;
  }

  function createMessageDelivery(doc, senderKind, t) {
    if (senderKind !== 'visitor') {
      return null;
    }

    var delivery = doc.createElement('span');
    delivery.className = 'wayfindr-widget__message-delivery';
    delivery.setAttribute('aria-label', t('receipt.aria'));
    delivery.textContent = t('receipt.label');

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

  // The site palette, repeated here on purpose. This key is interpolated into
  // a CSS custom property NAME on a page Wayfindr does not own, so the widget
  // validates it itself rather than trusting a response -- the server already
  // constrains it, and that is exactly why a second check is cheap.
  var SITE_COLORS = ['red', 'blue', 'ochre', 'pine', 'violet', 'rust'];

  /**
   * The language the operator configured for this site, if any.
   *
   * Only a hint: {@see resolveLocale} puts it behind the host page and the
   * visitor's own browser, because it is the operator's guess at who visits
   * rather than an answer from the visitor.
   */
  function siteLocale(result) {
    var site = result && result.site ? result.site : null;

    return site && typeof site.locale === 'string' ? site.locale : null;
  }

  function siteAccentKey(result) {
    var key = result && result.site ? result.site.color : null;

    return typeof key === 'string' && SITE_COLORS.indexOf(key) !== -1 ? key : null;
  }

  function applySiteAccent(element, key) {
    if (!element || !key) {
      return;
    }

    element.style.setProperty('--wf-site-accent', 'var(--wf-site-' + key + ')');
  }

  function siteAwayState(result) {
    var availability = result && result.site ? result.site.availability : null;

    if (!availability || availability.away !== true) {
      return null;
    }

    return {
      // Operator-authored copy: shown as typed, escaped, never interpreted.
      message: typeof availability.message === 'string' && availability.message.trim()
        ? availability.message.trim()
        : null,
      opensAt: typeof availability.opens_at === 'string' ? availability.opens_at : null,
    };
  }

  function formatReturn(opensAt, locale) {
    if (!opensAt) {
      return null;
    }

    var when = new Date(opensAt);

    if (isNaN(when.getTime())) {
      return null;
    }

    // Rendered in the visitor's own locale and zone: they care what time it is
    // where they are, not where the desk is.
    //
    // A weekday alone is only unambiguous within the coming week. A site open
    // one day a week returns exactly seven days out, and "Back Monday" read on
    // a Monday evening names a time that has already passed. Past six days the
    // date is included.
    var options = { weekday: 'long', hour: 'numeric', minute: '2-digit' };

    if (when.getTime() - Date.now() > 6 * 24 * 60 * 60 * 1000) {
      options.day = 'numeric';
      options.month = 'long';
    }

    try {
      return when.toLocaleString(localeTags(locale), options);
    } catch (error) {
      return when.toISOString();
    }
  }

  function applyAwayState(panelEl, away, t) {
    if (!panelEl) {
      return;
    }

    var el = panelEl.querySelector('.wayfindr-widget__away');

    if (!el) {
      return;
    }

    if (!away) {
      el.hidden = true;
      el.textContent = '';

      return;
    }

    var lines = [];

    if (away.message) {
      lines.push(away.message);
    } else {
      lines.push(t('away.default'));
    }

    var back = formatReturn(away.opensAt, t.locale);

    if (back) {
      lines.push(t('away.back', { when: back }));
    }

    el.textContent = lines.join(' ');
    el.hidden = false;
  }

  var INTAKE_FIELDS = ['name', 'email', 'reason'];

  /**
   * The question a field asks, in the visitor's language.
   *
   * Keyed off the field name the server sends, which never changes with the
   * locale -- the wire format stays English so a translated widget and an
   * untranslated server still agree about what is being asked.
   */
  function intakeFieldLabel(t, field) {
    return t('intake.field.' + field);
  }

  function siteAppearance(result) {
    var site = (result && result.site) || {};

    return site.appearance && typeof site.appearance === 'object' ? site.appearance : null;
  }

  function siteRatingPrompt(result) {
    var prompt = (result && result.site && result.site.rating) || null;

    return prompt && typeof prompt === 'object' ? {
      asks: prompt.asks === true,
      // Operator copy: shown as typed, escaped, never interpreted.
      intro: typeof prompt.intro === 'string' && prompt.intro.trim() ? prompt.intro.trim() : null,
    } : null;
  }

  function siteHasArticles(result) {
    var site = (result && result.site) || {};

    return Boolean(site.articles && site.articles.available);
  }

  function siteIntakeState(result) {
    var site = result && result.site ? result.site : null;
    var intake = site ? site.intake : null;

    if (!intake || intake.asks !== true || !intake.fields) {
      return null;
    }

    // No identification check here any more. The server sends the fields it
    // will actually enforce, already accounting for identification and for the
    // desk being away -- the widget draws what it is told. Deciding separately
    // is what hid the form for fields the server still demanded, handing
    // identified visitors a 422 they could do nothing about.

    var fields = [];

    INTAKE_FIELDS.forEach(function (name) {
      var mode = intake.fields[name];

      if (mode === 'optional' || mode === 'required') {
        fields.push({ name: name, required: mode === 'required' });
      }
    });

    if (!fields.length) {
      return null;
    }

    return {
      fields: fields,
      intro: typeof intake.intro === 'string' && intake.intro.trim() ? intake.intro.trim() : null,
    };
  }

  function siteMaskSelectors(result) {
    var settings = result && result.site ? result.site.settings || {} : {};
    var selectors = settings.mask_selectors;

    return Array.isArray(selectors) ? selectors.filter(function (selector) {
      return typeof selector === 'string' && selector.trim();
    }) : [];
  }

  function siteSensitiveTerms(result) {
    var settings = result && result.site ? result.site.settings || {} : {};
    var terms = settings.mask_terms;

    if (!Array.isArray(terms)) {
      return [];
    }

    var normalized = [];

    terms.forEach(function (term) {
      if (typeof term !== 'string') {
        return;
      }

      var token = normalizeSensitiveToken(term);

      if (token && normalized.indexOf(token) === -1) {
        normalized.push(token);
      }
    });

    return normalized;
  }

  function forEachChildNode(node, callback) {
    Array.prototype.slice.call((node && node.childNodes) || []).forEach(callback);
  }

  // Deep-clone a node for capture, inlining open shadow roots so web-component
  // content is visible (and maskable) in the snapshot. Closed shadow roots are
  // inaccessible by design and are simply absent. Falls back to a plain deep
  // clone for nodes without shadow content, so behavior is unchanged for pages
  // that do not use shadow DOM.
  // Computed-style properties captured into the snapshot so the agent preview
  // resembles the visitor page. Inherited properties are emitted only when they
  // differ from the parent; "own" properties only when not at their default.
  // The set is intentionally small (color + typography + surface) to stay within
  // the snapshot payload budget. The server style sanitizer is the enforcement
  // boundary and drops anything outside its allowlist.
  var CAPTURED_INHERITED_STYLE_PROPERTIES = [
    'color', 'font-family', 'font-size', 'font-weight', 'font-style',
    'line-height', 'text-align', 'text-transform',
  ];

  var CAPTURED_OWN_STYLE_PROPERTIES = [
    'background-color', 'background-image', 'border', 'border-radius',
    'box-shadow', 'max-width', 'opacity', 'text-decoration-line',
  ];

  // Layout is only captured on flex/grid containers. Containers are a small
  // minority of nodes, so this restores multi-column structure (heroes, navs,
  // button rows) without serializing box metrics for every element on the page.
  var CAPTURED_CONTAINER_DISPLAY_VALUES = ['flex', 'inline-flex', 'grid', 'inline-grid'];

  var CAPTURED_LAYOUT_STYLE_PROPERTIES = [
    'flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'gap',
    'grid-template-columns', 'padding', 'margin',
  ];

  // Composition built on positioning instead of flex/grid (floating cards,
  // overlays, badges) collapses into normal flow without these. relative and
  // absolute replay faithfully now that the preview renders at the visitor's
  // viewport width; fixed and sticky are intentionally left uncaptured — page
  // chrome (banners, navbars) stays in flow rather than pinning over the
  // preview.
  var CAPTURED_POSITION_VALUES = ['relative', 'absolute'];

  var CAPTURED_POSITION_OFFSET_PROPERTIES = ['top', 'right', 'bottom', 'left'];

  var POSITION_OFFSET_MAX_PX = 10000;

  function capturablePositionOffset(value) {
    var parsed = /^(-?\d+(?:\.\d+)?)px$/.exec(value || '');

    return parsed && Math.abs(parseFloat(parsed[1])) <= POSITION_OFFSET_MAX_PX;
  }

  // Tilted cards and badges are composed with 2D transforms, and a
  // transformed element is the containing block for its absolute
  // descendants — without the transform, both the tilt and the anchoring
  // are lost. Computed transforms normalize to matrix(a, b, c, d, tx, ty);
  // only that 2D form is captured, with bounded finite components (3D
  // matrices and unresolved function forms stay uncaptured).
  var TRANSFORM_SCALE_COMPONENT_MAX = 100;

  function capturableTransformMatrix(value) {
    var parsed = /^matrix\(([^)]+)\)$/.exec(value || '');

    if (!parsed) {
      return false;
    }

    var parts = parsed[1].split(',');

    if (parts.length !== 6) {
      return false;
    }

    for (var index = 0; index < 6; index += 1) {
      var component = parseFloat(parts[index]);
      var bound = index < 4 ? TRANSFORM_SCALE_COMPONENT_MAX : POSITION_OFFSET_MAX_PX;

      if (!isFinite(component) || Math.abs(component) > bound) {
        return false;
      }
    }

    return true;
  }

  function capturableTransformOrigin(value) {
    var parts = String(value || '').split(' ');

    if (parts.length < 2 || parts.length > 3) {
      return false;
    }

    for (var index = 0; index < parts.length; index += 1) {
      if (! capturablePositionOffset(parts[index])) {
        return false;
      }
    }

    return true;
  }

  // Once inside fixed/sticky page chrome (intentionally left in flow),
  // absolute descendants must not be captured either: their containing block
  // is the dropped ancestor, so their offsets would re-anchor to the wrong
  // element in the replay. A relative element re-establishes a faithful
  // containing block below the chrome, lifting the suppression.
  function nextPositionSuppressed(position, suppressed) {
    if (position === 'fixed' || position === 'sticky') {
      return true;
    }

    return position === 'relative' ? false : suppressed;
  }

  // A live-inserted subtree (SPA content) is captured with a style context
  // rooted at the added element, reading its real parent for the inherited
  // baseline so only differences are emitted — the same rule the snapshot
  // uses as it descends. Position suppression is seeded by replaying the
  // ancestor chain top-down, so an added absolute element inside fixed/sticky
  // page chrome is suppressed exactly as it would be in a full snapshot.
  function styleContextForAddedElement(element, parent, view, maskSelectors, sensitiveTerms, maxStyledElements) {
    if (!view || typeof view.getComputedStyle !== 'function' || !element || element.nodeType !== 1) {
      return null;
    }

    parent = (parent && parent.nodeType === 1 ? parent : element.parentElement) || null;
    var readParentValue = null;

    // The preview shell's <body> only receives background declarations
    // (CobrowseReplayPreview only replays those onto it), so a snapshot roots
    // at the body and lets its direct children emit their full inherited
    // styles rather than comparing against the body. A top-level addition
    // must match: with the body/html as the parent there is no inherited
    // baseline, so page typography and color ride along on the added element
    // instead of being suppressed as "same as body."
    var parentTag = parent ? String(parent.tagName || '').toLowerCase() : '';
    var parentEstablishesBaseline = parent && parentTag !== 'body' && parentTag !== 'html';

    if (parentEstablishesBaseline) {
      try {
        var parentComputed = view.getComputedStyle(parent);

        readParentValue = function (property) {
          return parentComputed.getPropertyValue(property);
        };
      } catch (error) {
        readParentValue = null;
      }
    }

    var ancestors = [];

    for (var ancestor = parent; ancestor && ancestor.nodeType === 1; ancestor = ancestor.parentElement) {
      ancestors.unshift(ancestor);
    }

    var positionSuppressed = false;

    ancestors.forEach(function (node) {
      try {
        positionSuppressed = nextPositionSuppressed(
          view.getComputedStyle(node).getPropertyValue('position'),
          positionSuppressed
        );
      } catch (error) {
        // Leave suppression unchanged if an ancestor's style is unreadable.
      }
    });

    return {
      view: view,
      readParentValue: readParentValue,
      isRoot: false,
      positionSuppressed: positionSuppressed,
      maskSelectors: maskSelectors,
      sensitiveTerms: sensitiveTerms,
      budget: {
        captured: 0,
        max: typeof maxStyledElements === 'number' ? maxStyledElements : 200,
      },
    };
  }

  function isCapturableStyleValue(value, maxLength) {
    if (!value || value.length > (maxLength || 200)) {
      return false;
    }

    var lower = value.toLowerCase();

    // Never capture anything that could fetch a resource or inject markup. The
    // captured set excludes url()-bearing properties, but guard the value too.
    return lower.indexOf('url(') === -1
      && lower.indexOf('image-set(') === -1
      && lower.indexOf('expression(') === -1
      && lower.indexOf('<') === -1
      && lower.indexOf('>') === -1;
  }

  // Gradients and multi-shadow lists legitimately run longer than the default
  // value cap; everything else keeps the tight bound.
  function ownStyleValueMaxLength(property) {
    if (property === 'background-image') {
      return 500;
    }

    if (property === 'box-shadow') {
      return 300;
    }

    return 200;
  }

  function isCapturableOwnStyleValue(property, value) {
    if (! isCapturableStyleValue(value, ownStyleValueMaxLength(property))) {
      return false;
    }

    // background-image is only captured when it is purely a CSS gradient —
    // color math, no resource fetch. The generic guard above already rejects
    // url()/image-set(); this prefix check rejects everything else
    // (cross-fade(), element(), paint()) rather than allowlisting per case.
    if (property === 'background-image') {
      return /^(repeating-)?(linear|radial|conic)-gradient\(/.test(value);
    }

    // max-width constrains plain block columns (it can never cause overflow),
    // so it is captured on every element — but only as a plain length.
    // Keyword sizes (min-content, fit-content(...)) stay uncaptured.
    if (property === 'max-width') {
      return /^\d+(?:\.\d+)?(px|%)$/.test(value);
    }

    return true;
  }

  function isDefaultOwnStyleValue(property, value) {
    if (property === 'background-color') {
      return value === 'rgba(0, 0, 0, 0)' || value === 'transparent';
    }

    if (property === 'background-image' || property === 'box-shadow') {
      return value === 'none';
    }

    if (property === 'border') {
      // Computed border shorthand with no visible border reads
      // "0px none rgb(...)" (the color tracks the text color).
      return value.indexOf('0px') === 0;
    }

    if (property === 'border-radius') {
      return value === '0px' || value === '0px 0px 0px 0px';
    }

    if (property === 'max-width') {
      return value === 'none';
    }

    if (property === 'opacity') {
      return value === '1';
    }

    if (property === 'text-decoration-line') {
      return value === 'none';
    }

    return false;
  }

  // Browsers include named grid lines ("[content-start] 480px [content-end]")
  // in the computed grid-template-columns value. The server's value grammar
  // rejects brackets, which would silently drop the whole declaration and
  // collapse the grid; the track sizes alone replay fine, so strip the names.
  function normalizeLayoutStyleValue(property, value) {
    if (property === 'grid-template-columns') {
      return value.replace(/\[[^\]]*\]/g, ' ').replace(/\s+/g, ' ').trim();
    }

    return value;
  }

  function isDefaultLayoutStyleValue(property, value) {
    if (property === 'flex-direction') {
      return value === 'row';
    }

    if (property === 'flex-wrap') {
      return value === 'nowrap';
    }

    if (property === 'justify-content' || property === 'align-items') {
      return value === 'normal';
    }

    if (property === 'gap') {
      return value === 'normal' || value === 'normal normal' || value === '0px' || value === '0px 0px';
    }

    if (property === 'grid-template-columns') {
      return value === 'none';
    }

    if (property === 'padding' || property === 'margin') {
      return value === '0px' || value === '0px 0px 0px 0px';
    }

    return false;
  }

  // readValue / readParentValue: (property) => string. readParentValue is null
  // for a style root (so it establishes the base for inherited properties).
  function buildCapturedStyle(readValue, readParentValue, suppressAbsolute) {
    var declarations = [];

    CAPTURED_INHERITED_STYLE_PROPERTIES.forEach(function (property) {
      var value = readValue(property);

      if (! isCapturableStyleValue(value)) {
        return;
      }

      // Inherited: only emit when it actually changes from the parent.
      if (readParentValue && readParentValue(property) === value) {
        return;
      }

      declarations.push(property + ':' + value);
    });

    CAPTURED_OWN_STYLE_PROPERTIES.forEach(function (property) {
      var value = readValue(property);

      if (! isCapturableOwnStyleValue(property, value) || isDefaultOwnStyleValue(property, value)) {
        return;
      }

      declarations.push(property + ':' + value);
    });

    var display = readValue('display');

    if (CAPTURED_CONTAINER_DISPLAY_VALUES.indexOf(display) !== -1) {
      declarations.push('display:' + display);

      CAPTURED_LAYOUT_STYLE_PROPERTIES.forEach(function (property) {
        var value = normalizeLayoutStyleValue(property, readValue(property));

        if (! isCapturableStyleValue(value) || isDefaultLayoutStyleValue(property, value)) {
          return;
        }

        declarations.push(property + ':' + value);
      });
    }

    var transform = readValue('transform');

    if (capturableTransformMatrix(transform)) {
      declarations.push('transform:' + transform);

      var transformOrigin = readValue('transform-origin');

      if (capturableTransformOrigin(transformOrigin)) {
        declarations.push('transform-origin:' + transformOrigin);
      }
    }

    var position = readValue('position');

    if (
      CAPTURED_POSITION_VALUES.indexOf(position) !== -1
      && ! (position === 'absolute' && suppressAbsolute)
    ) {
      // position:relative is captured even with no offsets: it establishes
      // the containing block that absolute descendants anchor to.
      declarations.push('position:' + position);

      CAPTURED_POSITION_OFFSET_PROPERTIES.forEach(function (property) {
        var value = readValue(property);

        if (capturablePositionOffset(value)) {
          declarations.push(property + ':' + value);
        }
      });

      var zIndex = readValue('z-index');

      if (/^-?\d{1,4}$/.test(zIndex || '')) {
        declarations.push('z-index:' + zIndex);
      }
    }

    return declarations.join(';');
  }

  // Sensitive fields and form controls must never have computed styles
  // serialized: their styling can be derived from raw values or validity state,
  // which would leak a signal the masking pass is meant to remove.
  function shouldSkipStyleCapture(node, maskSelectors, sensitiveTerms) {
    return isFormControl(node) || isMaskedElement(node, maskSelectors, sensitiveTerms);
  }

  // Decorative elements with no content of their own — skeleton blocks,
  // gradient panels, dots, spacers — get their size from stylesheet rules the
  // capture pass cannot see, so they collapse to nothing in the replay. For
  // content-empty elements, capture the rendered pixel box instead: the
  // preview renders at the visitor's reported viewport width, so pixel boxes
  // replay faithfully. Inline elements ignore width/height and are skipped.
  var EMPTY_ELEMENT_SIZE_MAX_PX = 4000;

  function isContentEmptyElement(node) {
    // An open shadow host is not empty: its shadow subtree renders inside it
    // (and is captured and masked through the shadow wrapper). Sizing the host
    // from its light DOM alone would serialize a box derived from
    // not-yet-masked shadow content.
    if (node.shadowRoot || node.childElementCount > 0) {
      return false;
    }

    var text = node.textContent;

    return !text || String(text).trim() === '';
  }

  function buildEmptyElementSizeStyle(node, readValue) {
    if (! isContentEmptyElement(node) || readValue('display') === 'inline') {
      return '';
    }

    var declarations = [];

    ['width', 'height'].forEach(function (property) {
      var value = readValue(property);
      var parsed = /^(\d+(?:\.\d+)?)px$/.exec(value || '');

      if (! parsed) {
        return;
      }

      var pixels = parseFloat(parsed[1]);

      if (pixels > 0 && pixels <= EMPTY_ELEMENT_SIZE_MAX_PX) {
        declarations.push(property + ':' + value);
      }
    });

    return declarations.join(';');
  }

  function isSafeSvgAttributeValue(name, value) {
    var raw = String(value || '');
    var lower = raw.toLowerCase();

    if (lower.indexOf('<') !== -1 || lower.indexOf('>') !== -1
      || lower.indexOf('javascript:') !== -1 || lower.indexOf('data:') !== -1) {
      return false;
    }

    if (name === 'href' || name === 'xlink:href') {
      return /^#[a-z0-9_:.-]+$/i.test(raw);
    }

    // Internal paint-server references (fill="url(#gradient)") are the only
    // url() form allowed anywhere inside captured SVG.
    if (lower.indexOf('url(') !== -1) {
      return /^url\(#[a-z0-9_:.-]+\)$/i.test(raw.trim());
    }

    return true;
  }

  function sanitizeSvgForCapture(node) {
    if (!node || !node.ownerDocument || !node.querySelectorAll) {
      return null;
    }

    if (node.querySelectorAll('*').length > SVG_MAX_ELEMENTS) {
      return null;
    }

    return sanitizeSvgNode(node);
  }

  function sanitizeSvgNode(node) {
    if (node.nodeType === 3) {
      // Text inside <text>/<title>/<desc> is the same class of data as page
      // text, which the snapshot already captures.
      return node.cloneNode(true);
    }

    if (node.nodeType !== 1) {
      return null;
    }

    var tagName = String(node.tagName || '').toLowerCase();

    if (SVG_ALLOWED_ELEMENTS.indexOf(tagName) === -1) {
      // Disallowed elements (script, foreignObject, image, animate, filter…)
      // are dropped with their entire subtree rather than partially kept.
      return null;
    }

    var clone = node.cloneNode(false);
    var attributes = Array.prototype.slice.call(clone.attributes || []);

    attributes.forEach(function (attribute) {
      var name = String(attribute.name || '').toLowerCase();

      if (SVG_ALLOWED_ATTRIBUTES.indexOf(name) === -1 || !isSafeSvgAttributeValue(name, attribute.value)) {
        clone.removeAttribute(attribute.name);
      }
    });

    forEachChildNode(node, function (child) {
      var sanitized = sanitizeSvgNode(child);

      if (sanitized) {
        clone.appendChild(sanitized);
      }
    });

    return clone;
  }

  // Replace an <img> with a same-size placeholder: layout space and alt text
  // survive, but no image URL ever reaches the agent (who must not fetch
  // visitor resources) and no pixel data ever leaves the visitor page. The
  // custom tag never collides with nth-of-type mutation paths.
  function imagePlaceholderForCapture(node) {
    var ownerDocument = node.ownerDocument;

    if (!ownerDocument) {
      return null;
    }

    var placeholder = ownerDocument.createElement('wayfindr-img-placeholder');

    IMAGE_PLACEHOLDER_COPIED_ATTRIBUTES.forEach(function (name) {
      if (node.hasAttribute && node.hasAttribute(name)) {
        placeholder.setAttribute(name, node.getAttribute(name));
      }
    });

    var width = 0;
    var height = 0;

    if (typeof node.getBoundingClientRect === 'function') {
      var rect = node.getBoundingClientRect();
      width = Math.round(rect.width) || 0;
      height = Math.round(rect.height) || 0;
    }

    if (!width && node.getAttribute) {
      width = parseInt(node.getAttribute('width'), 10) || 0;
    }

    if (!height && node.getAttribute) {
      height = parseInt(node.getAttribute('height'), 10) || 0;
    }

    var sizes = [];

    if (width > 0 && width <= 4000) {
      sizes.push('width:' + width + 'px');
    }

    if (height > 0 && height <= 4000) {
      sizes.push('height:' + height + 'px');
    }

    if (sizes.length) {
      placeholder.setAttribute('style', sizes.join(';'));
    }

    var alt = node.getAttribute ? normalizeWhitespace(node.getAttribute('alt') || '') : '';

    if (alt) {
      placeholder.textContent = truncateString(alt, 120);
    }

    return placeholder;
  }

  function cloneForCapture(node, styleContext) {
    if (!node) {
      return null;
    }

    if (node.nodeType !== 1) {
      return node.cloneNode(true);
    }

    var tagName = String(node.tagName || '').toLowerCase();

    if (tagName === 'svg') {
      return sanitizeSvgForCapture(node);
    }

    if (tagName === 'img') {
      return imagePlaceholderForCapture(node);
    }

    // <template> content is an inert fragment that the preview never renders and
    // that the querySelectorAll-based masking helpers cannot reach, so copying
    // it would serialize unmasked markup. Capture the empty shell only.
    if (tagName === 'template') {
      return node.cloneNode(false);
    }

    var clone = node.cloneNode(false);
    var ownerDocument = node.ownerDocument;
    var childStyleContext = styleContext;

    if (
      styleContext
      && styleContext.view
      && typeof styleContext.view.getComputedStyle === 'function'
      && styleContext.budget.captured < styleContext.budget.max
    ) {
      try {
        var computed = styleContext.view.getComputedStyle(node);
        var readValue = function (property) {
          return computed.getPropertyValue(property);
        };

        // The snapshot serializes body.innerHTML, so the body's own style is
        // dropped; skip emitting it (and don't count it against the budget) and
        // let the body's children act as style roots that establish the base
        // inherited values.
        //
        // Never serialize styles for masked elements or form controls: a page
        // could style a sensitive field by its raw value or validity (e.g.
        // input[value^="4"] or :valid setting a background), which would leak a
        // value-derived signal even though the field itself is masked. Their
        // computed style is still read so children inherit accurate parent
        // values for comparison; it is just not written to the clone.
        if (! styleContext.isRoot) {
          styleContext.budget.captured += 1;

          if (! shouldSkipStyleCapture(node, styleContext.maskSelectors, styleContext.sensitiveTerms)) {
            var captured = buildCapturedStyle(readValue, styleContext.readParentValue, styleContext.positionSuppressed);
            var emptySize = buildEmptyElementSizeStyle(node, readValue);

            if (emptySize) {
              captured = captured ? captured + ';' + emptySize : emptySize;
            }

            if (captured) {
              var existing = clone.getAttribute('style');
              clone.setAttribute('style', existing ? existing + ';' + captured : captured);
            }
          }
        }

        childStyleContext = {
          view: styleContext.view,
          budget: styleContext.budget,
          isRoot: false,
          maskSelectors: styleContext.maskSelectors,
          sensitiveTerms: styleContext.sensitiveTerms,
          readParentValue: styleContext.isRoot ? null : readValue,
          positionSuppressed: nextPositionSuppressed(readValue('position'), styleContext.positionSuppressed),
        };
      } catch (error) {
        // Style capture is best-effort; fall back to structural cloning.
        childStyleContext = styleContext;
      }
    }

    forEachChildNode(node, function (child) {
      var clonedChild = cloneForCapture(child, childStyleContext);

      if (clonedChild) {
        clone.appendChild(clonedChild);
      }
    });

    // Inline open shadow content after the light children, inside a uniquely
    // tagged wrapper. Mutation paths are computed from the real (light) DOM with
    // nth-of-type, so the wrapper must not share a tag with any real element or
    // it would shift those indices and make replayed paths resolve to the wrong
    // node. A custom <wayfindr-shadow-content> tag never appears in a real path.
    if (node.shadowRoot && ownerDocument) {
      var shadowContainer = ownerDocument.createElement('wayfindr-shadow-content');

      forEachChildNode(node.shadowRoot, function (child) {
        var clonedChild = cloneForCapture(child, childStyleContext);

        if (clonedChild) {
          shadowContainer.appendChild(clonedChild);
        }
      });

      clone.appendChild(shadowContainer);
    }

    return clone;
  }

  // The snapshot serializes body.innerHTML, so the page-level background — the
  // single most visible style on many pages — never rides along with element
  // capture. Read the background family from a single source element: the body
  // when it paints any background of its own, otherwise the root element
  // (whose background paints the canvas behind a transparent body). Merging
  // per property instead would composite the root's gradient over an opaque
  // body, which browsers never do. Same gradient-only rules as element
  // capture: color math only, never a resource fetch.
  var PAGE_BACKGROUND_PROPERTIES = ['background-color', 'background-image'];

  function capturePageBackgroundStyle(doc, view) {
    if (!doc || !view || typeof view.getComputedStyle !== 'function') {
      return '';
    }

    var readSource = null;

    [doc.body, doc.documentElement].filter(Boolean).some(function (element) {
      var computed;

      try {
        computed = view.getComputedStyle(element);
      } catch (error) {
        return false;
      }

      var read = function (property) {
        return computed.getPropertyValue(property);
      };

      var paintsBackground = PAGE_BACKGROUND_PROPERTIES.some(function (property) {
        var value = read(property);

        return isCapturableOwnStyleValue(property, value) && ! isDefaultOwnStyleValue(property, value);
      });

      if (paintsBackground) {
        readSource = read;
      }

      return paintsBackground;
    });

    if (!readSource) {
      return '';
    }

    var declarations = [];

    PAGE_BACKGROUND_PROPERTIES.forEach(function (property) {
      var value = readSource(property);

      if (isCapturableOwnStyleValue(property, value) && ! isDefaultOwnStyleValue(property, value)) {
        declarations.push(property + ':' + value);
      }
    });

    // Patterned backgrounds (grid lines, stripes) are gradients tiled by
    // background-size; without it a tiled pattern replays as one page-sized
    // gradient.
    if (declarations.join(';').indexOf('background-image:') !== -1) {
      var size = readSource('background-size');

      if (size && size.indexOf('auto') === -1 && isCapturableStyleValue(size)) {
        declarations.push('background-size:' + size);
      }
    }

    return declarations.join(';');
  }

  function createCobrowseSnapshot(doc, options) {
    options = options || {};

    var location = options.location || (doc && doc.location) || null;
    var view = options.view || (doc && doc.defaultView) || null;
    var styleContext = (options.captureStyles === false || !view || typeof view.getComputedStyle !== 'function')
      ? null
      : {
        view: view,
        readParentValue: null,
        isRoot: true,
        positionSuppressed: false,
        maskSelectors: DEFAULT_MASK_SELECTORS.concat(options.maskSelectors || []),
        sensitiveTerms: options.sensitiveTerms || [],
        budget: {
          captured: 0,
          max: typeof options.maxStyledElements === 'number' ? options.maxStyledElements : 800,
        },
      };
    var source = doc && doc.body ? cloneForCapture(doc.body, styleContext) : null;

    if (!source) {
      return {
        pageUrl: location ? String(location.href || '') : '',
        title: doc && doc.title ? doc.title : '',
        html: '',
        text: '',
        bodyStyle: '',
        nodeCount: 0,
        maskedCount: 0,
      };
    }

    removeMatching(source, DEFAULT_REMOVE_SELECTORS);

    var maskedCount = maskMatching(source, DEFAULT_MASK_SELECTORS.concat(options.maskSelectors || []));
    maskedCount += maskInferredSensitiveElements(source, options.sensitiveTerms || []);
    clearFormControlValues(source);

    return {
      pageUrl: location ? String(location.href || '') : '',
      title: doc.title || '',
      html: truncateString(source.innerHTML || '', options.maxHtmlLength || 60000),
      text: truncateString(normalizeWhitespace(source.textContent || ''), options.maxTextLength || 10000),
      bodyStyle: styleContext ? capturePageBackgroundStyle(doc, view) : '',
      nodeCount: source.querySelectorAll ? source.querySelectorAll('*').length : 0,
      maskedCount: maskedCount,
    };
  }

  function createCobrowseMutationBatch(records, options) {
    options = options || {};

    var doc = options.document || null;
    var location = options.location || (doc && doc.location) || null;
    var view = options.view || (doc && doc.defaultView) || null;
    var captureStyles = options.captureStyles !== false && view && typeof view.getComputedStyle === 'function';
    var maskSelectors = DEFAULT_MASK_SELECTORS.concat(options.maskSelectors || []);
    var sensitiveTerms = options.sensitiveTerms || [];
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
        sensitiveTerms: sensitiveTerms,
        view: captureStyles ? view : null,
        maxStyledElements: options.maxStyledElementsPerMutation,
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
      text: isMaskedElement(element, options.maskSelectors, options.sensitiveTerms)
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
      attributeValue: isMaskedElement(element, options.maskSelectors, options.sensitiveTerms)
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
    var styleContext = options.view
      ? styleContextForAddedElement(element, record.target, options.view, options.maskSelectors, options.sensitiveTerms, options.maxStyledElements)
      : null;
    var clone = cloneForCapture(element, styleContext);
    var maskedCount;

    removeMatching(clone, DEFAULT_REMOVE_SELECTORS);
    maskedCount = isMaskedElement(element, options.maskSelectors, options.sensitiveTerms)
      ? maskWholeElement(clone)
      : maskMatching(clone, options.maskSelectors);
    maskedCount += maskInferredSensitiveElements(clone, options.sensitiveTerms);
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
      text: isMaskedElement(element, options.maskSelectors, options.sensitiveTerms)
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

  function maskInferredSensitiveElements(source, extraTerms) {
    var masked = [];

    allElements(source).forEach(function (element) {
      if (
        masked.indexOf(element) !== -1
        || isAlreadyMaskedElement(element)
        || !isInferredSensitiveElement(element, source, extraTerms)
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

  function isMaskedElement(element, selectors, extraTerms) {
    return elementMatchesOrClosest(element, selectors || DEFAULT_MASK_SELECTORS)
      || isInferredSensitiveElement(element, element ? element.ownerDocument : null, extraTerms);
  }

  function isInferredSensitiveElement(element, source, extraTerms) {
    if (!element || elementMatchesOrClosest(element, ['[data-wayfindr-allow]'])) {
      return false;
    }

    if (hasSensitiveAttribute(element, extraTerms)) {
      return true;
    }

    return isFormControl(element) && hasSensitiveLabel(element, source || element.ownerDocument, extraTerms);
  }

  function hasSensitiveAttribute(element, extraTerms) {
    return SENSITIVE_FIELD_ATTRIBUTES.some(function (attributeName) {
      return hasSensitiveTerm(element.getAttribute(attributeName), extraTerms);
    });
  }

  function hasSensitiveLabel(element, source, extraTerms) {
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
      return hasSensitiveTerm(label.textContent || '', extraTerms);
    });
  }

  function hasSensitiveTerm(value, extraTerms) {
    var normalized = normalizeSensitiveToken(value);

    if (!normalized) {
      return false;
    }

    var terms = extraTerms && extraTerms.length
      ? SENSITIVE_FIELD_TERMS.concat(extraTerms)
      : SENSITIVE_FIELD_TERMS;

    return terms.some(function (term) {
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

  function generateClientMessageId() {
    try {
      if (typeof crypto !== 'undefined' && crypto && typeof crypto.randomUUID === 'function') {
        return 'wf-' + crypto.randomUUID();
      }
    } catch (error) {
      // Fall through to the timestamp/random fallback below.
    }

    return 'wf-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
  }

  function visitorTokenStorageKey(sitePublicKey) {
    return 'wayfindr:' + sitePublicKey + ':visitor-token';
  }

  function appearanceStorageKey(sitePublicKey) {
    return 'wayfindr:' + sitePublicKey + ':appearance';
  }

  function supportCodeStorageKey(sitePublicKey) {
    return 'wayfindr:' + sitePublicKey + ':support-code';
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

  // Multipart POST for file uploads. Deliberately does NOT set Content-Type —
  // the browser adds the multipart boundary itself.
  function postForm(fetcher, url, formData) {
    return fetcher(url, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
      },
      body: formData,
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
      throw responseError(response, data);
    }

    return data.data;
  }

  async function readRawJsonResponse(response) {
    var data = await response.json().catch(function () {
      return {};
    });

    if (!response.ok) {
      throw responseError(response, data);
    }

    return data;
  }

  // Carry the HTTP status on the error so callers can tell a server rejection
  // (a stale or foreign reference) from a transient network failure.
  function responseError(response, data) {
    // A server-authored message is the server's copy and is shown as-is. Our
    // own generic failure carries a key instead, so the widget can say it in
    // the visitor's language rather than leaking an English sentence.
    var serverMessage = typeof data.message === 'string' && data.message ? data.message : null;
    var error = new Error(serverMessage || 'Wayfindr request failed with status ' + response.status + '.');

    if (!serverMessage) {
      error.wayfindrKey = 'error.requestFailed';
      error.wayfindrParams = { status: response.status };
    }
    error.status = response.status;

    return error;
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

  // Does this storage actually keep things?
  //
  // `storageSet` swallows failures, which is right for a cached token and wrong
  // for a decline: an embed passing `storage: null`, private browsing, or a
  // locked-down browser all leave a "no" that evaporates on the next
  // navigation. Presence has to know the difference, so it writes a sentinel
  // and reads it back rather than trusting the write.
  function storageRemembers(storage, key) {
    if (!storage) {
      return false;
    }

    try {
      var probe = key + ':probe';

      storage.setItem(probe, '1');
      var read = storage.getItem(probe);
      storage.removeItem(probe);

      return read === '1';
    } catch (error) {
      return false;
    }
  }

  function presenceStorageKey(sitePublicKey) {
    return 'wayfindr:' + sitePublicKey + ':presence-declined';
  }

  // The same rule the server applies, applied before the request is built.
  //
  // Not redundant with the server pass: sanitising on arrival means the raw URL
  // has already crossed the wire, and a query string that reached Wayfindr
  // reached proxies, access logs and error trackers on the way. Removing a
  // token after transmitting it is not removing it.
  function sanitisePageUrl(href) {
    if (typeof href !== 'string' || href === '') {
      return null;
    }

    try {
      var parsed = new URL(href);

      // http and https only. These values become clickable links in the agent
      // dashboard, and `javascript://host/%0Aalert(1)` parses perfectly.
      if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
        return null;
      }

      // Rebuilt from named parts, so anything not named -- query, fragment,
      // `user:pass@` credentials -- cannot survive by being forgotten.
      //
      // The PATH is redacted too, not copied. The server does this at rest, but
      // the promise this function makes is that the secret never goes over the
      // wire -- and `/reset-password/9f2c8a1b...` puts one in the one part that
      // survives the query and fragment being dropped. Copying the pathname
      // verbatim sent it to Wayfindr, to every proxy in between, and into
      // request logs on both sides, where redacting it afterwards cannot reach.
      return parsed.protocol + '//' + parsed.host + redactPathSegments(parsed.pathname);
    } catch (error) {
      return null;
    }
  }

  /**
   * Replace path segments that look like a credential rather than a page name.
   *
   * Mirrors VisitorPageUrl::looksOpaque() on the server deliberately: the same
   * rule on both sides means the agent sees the same string whether it was
   * redacted here or there, and a disagreement would show up as page addresses
   * that change shape depending on which path they took.
   *
   * Crude, and a heuristic rather than a proof. It will sometimes redact a long
   * harmless slug, which is the right way round for a rule whose failures are
   * credentials.
   */
  function redactPathSegments(pathname) {
    if (typeof pathname !== 'string' || pathname === '') {
      return '';
    }

    return pathname.split('/').map(function (segment) {
      return looksOpaqueSegment(segment) ? '[redacted]' : segment;
    }).join('/');
  }

  function looksOpaqueSegment(segment) {
    // A UUID is never a page name.
    if (/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(segment)) {
      return true;
    }

    // A separator used to end the test, on the reasoning that a slug is words
    // joined by hyphens. It is also how a credential is punctuated:
    // `/invite/ABC-123` and `/reset/abc_def123` walked straight past a rule
    // that read any hyphen as proof of readability -- and here that meant the
    // credential crossed the wire before the server could redact anything.
    //
    // Kept identical to VisitorPageUrl::looksOpaque(). A disagreement between
    // the two shows up as addresses that change shape depending on which path
    // they took, and the client's is the one that decides what leaves at all.
    var bare = segment.replace(/[-_.]/g, '');

    if (bare.length >= 5 && /^[A-Z0-9]+$/.test(bare)) {
      return true;
    }

    return segment.split(/[-_.]/).some(looksOpaquePart);
  }

  function looksOpaquePart(part) {
    if (part.length >= 32) {
      return true;
    }

    // Carries a digit and is past the length of a version or a year: `v2`,
    // `2024` and `page3` survive, `A1B2C3` and `123456` do not.
    if (part.length >= 6 && /[0-9]/.test(part)) {
      return true;
    }

    // Sixteen letters unbroken is past the words routes are named after.
    if (part.length >= 16) {
      return true;
    }

    return part.length >= 5 && /^[A-Z0-9]+$/.test(part);
  }

  function storageRemove(storage, key) {
    try {
      if (storage) {
        storage.removeItem(key);
      }
    } catch (error) {
      // Private browsing and locked-down embeds can reject storage writes.
    }
  }

  // A storage override only counts when it is the caller's own, defined
  // property. Undefined means "not provided" — including when a wrapper
  // forwards `storage: options.storage` from options that never set the key,
  // which is how Wayfindr.init embeds silently lost all persistence — and an
  // inherited property (Object.create chains, prototype pollution) is never
  // treated as an explicit choice. Explicit null still disables storage.
  function resolveStorageOption(options) {
    if (Object.prototype.hasOwnProperty.call(options, 'storage') && options.storage !== undefined) {
      return options.storage;
    }

    return defaultStorage();
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
      // wayfindr:tokens:start
      // Generated from packages/design-tokens/tokens.json by scripts/generate-design-tokens.php. Do not edit by hand -- run `make design-tokens`.
      '.wayfindr-widget{--wf-paper:#F1F1EE;--wf-surface:#FFFFFF;--wf-surface-2:#E9E9E4;--wf-ink:#16181A;--wf-ink-invert:var(--wf-brand-ink-configured,#F1F1EE);--wf-muted:#6A6E71;--wf-rule:#DCDCD6;--wf-rule-firm:#C4C4BD;--wf-brand:var(--wf-brand-configured,#0D6F68);--wf-signal-rest:#8C9194;--wf-signal-go:#1E7A4C;--wf-signal-hold:#C98A06;--wf-signal-stop:#C3352B;--wf-site-red:#C3352B;--wf-site-blue:#2D4EA2;--wf-site-ochre:#C98A06;--wf-site-pine:#1E7A4C;--wf-site-violet:#6B4E9B;--wf-site-rust:#B5542A;--wf-font-sans:"IBM Plex Sans",ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;--wf-font-cond:"IBM Plex Sans Condensed","IBM Plex Sans",ui-sans-serif,system-ui,sans-serif;--wf-font-mono:"IBM Plex Mono",ui-monospace,"SF Mono",Menlo,Consolas,monospace;--wf-text-display:2.05rem;--wf-text-title:1.3rem;--wf-text-body:0.97rem;--wf-text-ui:0.875rem;--wf-text-label:0.75rem;--wf-text-code:0.86rem;--wf-space-1:4px;--wf-space-2:8px;--wf-space-3:12px;--wf-space-4:16px;--wf-space-5:24px;--wf-space-6:32px;--wf-space-7:48px;--wf-radius:2px;--wf-radius-full:999px;--wf-border:1px;--wf-rail:3px;--wf-row-min:34px}',
      '@media (prefers-color-scheme:dark){.wayfindr-widget:not([data-wf-theme="light"]){--wf-paper:#141517;--wf-surface:#1B1D20;--wf-surface-2:#24272A;--wf-ink:#ECECE8;--wf-ink-invert:var(--wf-brand-ink-configured-dark,#16181A);--wf-muted:#9BA0A3;--wf-rule:#2E3134;--wf-rule-firm:#3D4145;--wf-brand:var(--wf-brand-configured-dark,#3FA69D);--wf-signal-rest:#7E8386;--wf-signal-go:#4CA97A;--wf-signal-hold:#E0A72A;--wf-signal-stop:#E2685C;--wf-site-red:#D54C43;--wf-site-blue:#5578D0;--wf-site-ochre:#A57105;--wf-site-pine:#238C57;--wf-site-violet:#896EB6;--wf-site-rust:#C65C2E}}',
      '.wayfindr-widget[data-wf-theme="dark"]{--wf-paper:#141517;--wf-surface:#1B1D20;--wf-surface-2:#24272A;--wf-ink:#ECECE8;--wf-ink-invert:var(--wf-brand-ink-configured-dark,#16181A);--wf-muted:#9BA0A3;--wf-rule:#2E3134;--wf-rule-firm:#3D4145;--wf-brand:var(--wf-brand-configured-dark,#3FA69D);--wf-signal-rest:#7E8386;--wf-signal-go:#4CA97A;--wf-signal-hold:#E0A72A;--wf-signal-stop:#E2685C;--wf-site-red:#D54C43;--wf-site-blue:#5578D0;--wf-site-ochre:#A57105;--wf-site-pine:#238C57;--wf-site-violet:#896EB6;--wf-site-rust:#C65C2E}',
      // wayfindr:tokens:end
      '.wayfindr-widget{position:fixed;inset-inline-end:20px;bottom:20px;z-index:2147483000;font-family:var(--wf-font-sans);color:var(--wf-ink)}',
      '.wayfindr-widget *{box-sizing:border-box}',
      '.wayfindr-widget [hidden]{display:none!important}',
      '.wayfindr-widget__launcher,.wayfindr-widget__send{border:0;border-radius:999px;background:var(--wf-brand);color:var(--wf-ink-invert);box-shadow:0 12px 30px rgba(8,37,34,.18);cursor:pointer;font:700 14px/1 var(--wf-font-sans)}',
      '.wayfindr-widget__launcher{position:relative;min-height:48px;padding:0 18px}',
      // Logical properties, so a left-positioned launcher still follows an
      // RTL language rather than fighting it -- see the widget language work.
      // No issue number here on purpose: the token guard scans this block for
      // hardcoded colours and reads a bare hash-774 as a three-digit hex.
      '.wayfindr-widget[data-wf-launcher="left"]{inset-inline-end:auto;inset-inline-start:20px}',
      '.wayfindr-widget__launcher[data-cobrowse-active="true"]::after{content:"";position:absolute;top:-3px;inset-inline-end:-3px;width:14px;height:14px;border-radius:999px;background:var(--wf-signal-hold);border:2px solid var(--wf-surface);box-shadow:0 0 0 2px color-mix(in srgb, var(--wf-signal-hold) 35%, transparent)}',
      '.wayfindr-widget__send{min-height:40px;padding:0 14px;border-radius:6px}',
      '.wayfindr-widget__launcher:hover,.wayfindr-widget__send:hover{background:color-mix(in srgb, var(--wf-brand) 80%, var(--wf-ink))}',
      '.wayfindr-widget__send:disabled{cursor:wait;opacity:.7}',
      '.wayfindr-widget__panel{display:flex;flex-direction:column;width:min(360px,calc(100vw - 32px));max-height:calc(100vh - 40px);max-height:calc(100dvh - 40px);border:1px solid var(--wf-rule);border-top:3px solid var(--wf-site-accent,var(--wf-brand));border-radius:8px;background:var(--wf-surface);box-shadow:0 20px 55px rgba(8,37,34,.2);overflow:auto}',
      '.wayfindr-widget__panel>*{flex-shrink:0}',
      '.wayfindr-widget__panel>.wayfindr-widget__timeline-wrap{flex:0 1 auto;min-height:0}',
      '.wayfindr-widget__header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid var(--wf-rule);background:var(--wf-paper)}',
      '.wayfindr-widget__close{border:0;background:transparent;color:var(--wf-muted);cursor:pointer;font:700 24px/1 var(--wf-font-sans);padding:0}',
      '.wayfindr-widget__timeline{display:grid;gap:10px;flex:1 1 auto;min-height:0;max-height:280px;overflow:auto;padding:14px 16px;border-bottom:1px solid var(--wf-rule);background:var(--wf-surface-2)}',
      '.wayfindr-widget__intake{display:grid;gap:10px;margin:0;padding:14px 16px;border-bottom:1px solid var(--wf-rule);background:var(--wf-surface-2)}',
      '.wayfindr-widget__intake-intro{margin:0;color:var(--wf-muted);font-size:13px;line-height:1.4}',
      '.wayfindr-widget__intake-fields{display:grid;gap:8px}',
      '.wayfindr-widget__intake label{display:grid;gap:4px;color:var(--wf-muted);font-size:12px}',
      '.wayfindr-widget__intake input{min-width:0;padding:8px 10px;border:1px solid var(--wf-rule);border-radius:6px;background:var(--wf-surface);color:var(--wf-ink);font:inherit;font-size:13px}',
      '.wayfindr-widget__intake-error{margin:0;color:var(--wf-signal-attention);font-size:12px}',
      // The rating prompt sits where the composer would be, in the same
      // surface the intake form uses -- it is a question the desk is asking,
      // not a message in the transcript.
      '.wayfindr-widget__rating{display:grid;gap:10px;margin:0;padding:14px 16px;border-bottom:1px solid var(--wf-rule);background:var(--wf-surface-2)}',
      '.wayfindr-widget__rating-intro{margin:0;color:var(--wf-ink);font-size:13px;line-height:1.4}',
      '.wayfindr-widget__rating-scores{display:flex;gap:8px}',
      '.wayfindr-widget__rating-score{flex:1 1 0;min-width:0;min-height:34px;border:1px solid var(--wf-rule);border-radius:6px;background:var(--wf-surface);color:var(--wf-ink);cursor:pointer;padding:0 8px;font:700 13px/1 var(--wf-font-sans)}',
      '.wayfindr-widget__rating-score:hover{border-color:var(--wf-brand);color:var(--wf-brand)}',
      // The chosen answer has to be visible without colour alone carrying it,
      // so the border thickens as well as changing hue.
      '.wayfindr-widget__rating-score[aria-pressed="true"]{border-color:var(--wf-brand);background:color-mix(in srgb, var(--wf-brand) 12%, var(--wf-surface));color:var(--wf-brand);box-shadow:inset 0 0 0 1px var(--wf-brand)}',
      '.wayfindr-widget__rating-label{margin:0;color:var(--wf-muted);font-size:12px}',
      '.wayfindr-widget__rating-comment{min-width:0;padding:8px 10px;border:1px solid var(--wf-rule);border-radius:6px;background:var(--wf-surface);color:var(--wf-ink);font:inherit;font-size:13px;resize:vertical}',
      '.wayfindr-widget__rating-send{justify-self:start;min-height:34px;border:1px solid var(--wf-rule);border-radius:6px;background:var(--wf-surface);color:var(--wf-ink);cursor:pointer;padding:0 12px;font:700 13px/1 var(--wf-font-sans)}',
      '.wayfindr-widget__rating-send:hover:not(:disabled){border-color:var(--wf-brand);color:var(--wf-brand)}',
      '.wayfindr-widget__rating-send:disabled{opacity:0.55;cursor:default}',
      '.wayfindr-widget__rating-status{margin:0;color:var(--wf-signal-stop);font-size:12px}',
      '.wayfindr-widget__away{margin:0;padding:14px 16px;border-bottom:1px solid var(--wf-rule);background:color-mix(in srgb, var(--wf-signal-hold) 12%, var(--wf-surface));color:color-mix(in srgb, var(--wf-signal-hold) 70%, var(--wf-ink));font-size:13px;line-height:1.4}',
      '.wayfindr-widget__notice{display:grid;gap:10px;margin:0;padding:14px 16px;border-bottom:1px solid var(--wf-rule);background:var(--wf-surface-2);color:var(--wf-muted);font-size:13px;line-height:1.4}',
      '.wayfindr-widget__notice[data-state="warning"]{background:color-mix(in srgb, var(--wf-signal-hold) 12%, var(--wf-surface));color:color-mix(in srgb, var(--wf-signal-hold) 70%, var(--wf-ink))}',
      '.wayfindr-widget__notice-copy{margin:0}',
      '.wayfindr-widget__presence{display:flex;gap:8px;align-items:center;justify-content:flex-end;max-width:min(280px,calc(100vw - 40px));margin-bottom:8px;padding:6px 10px;border:var(--wf-border) solid var(--wf-rule);border-radius:var(--wf-radius);background:var(--wf-surface);color:var(--wf-muted);font-size:12px;line-height:1.35;box-shadow:0 6px 18px rgba(8,37,34,.10)}',
      '.wayfindr-widget__presence-copy{margin:0}',
      '.wayfindr-widget__presence-decline{background:none;border:0;padding:0;font:inherit;text-decoration:underline;cursor:pointer;color:inherit;white-space:nowrap}',
      '.wayfindr-widget__notice-retry{justify-self:start;min-height:34px;border:1px solid var(--wf-rule);border-radius:6px;background:var(--wf-surface);color:var(--wf-ink);cursor:pointer;padding:0 12px;font:700 13px/1 var(--wf-font-sans)}',
      '.wayfindr-widget__notice-retry:hover{border-color:var(--wf-brand);color:var(--wf-brand)}',
      '.wayfindr-widget__notice-retry:disabled{cursor:wait;opacity:.7}',
      '.wayfindr-widget__typing{margin:0;padding:9px 16px;border-bottom:1px solid var(--wf-rule);background:var(--wf-surface-2);color:var(--wf-muted);font-size:13px;line-height:1.35}',
      '.wayfindr-widget__connection{margin:0;padding:10px 16px 0;color:var(--wf-muted);font-size:12px;line-height:1.35}',
      '.wayfindr-widget__message{display:grid;gap:4px;width:88%;border:1px solid var(--wf-rule);border-radius:8px;padding:9px 10px;background:var(--wf-surface)}',
      '.wayfindr-widget__message--agent{justify-self:end;background:var(--wf-surface-2);border-color:var(--wf-rule)}',
      '.wayfindr-widget__message--grouped{margin-top:-6px}',
      '.wayfindr-widget__timeline-wrap{position:relative;display:flex;flex-direction:column;min-height:0}',
      '.wayfindr-widget__jump{position:absolute;left:50%;bottom:10px;transform:translateX(-50%);border:1px solid var(--wf-rule);background:var(--wf-surface-2);color:var(--wf-ink);border-radius:999px;padding:4px 12px;font-size:12px;line-height:1.2;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,0.12)}',
      '.wayfindr-widget__day-separator{display:flex;align-items:center;justify-content:center;margin:2px 0}',
      '.wayfindr-widget__day-label{color:var(--wf-muted);font-size:11px;line-height:1.2;background:var(--wf-surface-2);border-radius:999px;padding:2px 10px;white-space:nowrap}',
      '.wayfindr-widget__message-meta{display:flex;align-items:center;justify-content:space-between;gap:10px}',
      '.wayfindr-widget__message-name{color:var(--wf-muted);font-size:12px;line-height:1.2}',
      '.wayfindr-widget__message--grouped .wayfindr-widget__message-name{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap}',
      '.wayfindr-widget__message--grouped .wayfindr-widget__message-meta{justify-content:flex-end}',
      '.wayfindr-widget__message-time{color:var(--wf-muted);font-size:11px;line-height:1.2;white-space:nowrap}',
      '.wayfindr-widget__message-body{margin:0;white-space:pre-wrap;color:var(--wf-ink);font-size:14px;line-height:1.4}',
      '.wayfindr-widget__message-delivery{justify-self:end;color:var(--wf-muted);font-size:11px;line-height:1.2}',
      '.wayfindr-widget__message-attachments{display:grid;gap:6px;margin-top:2px}',
      '.wayfindr-widget__attachment{display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:var(--wf-brand)}',
      '.wayfindr-widget__attachment--file{border:1px solid var(--wf-rule);border-radius:6px;padding:6px 8px;background:var(--wf-surface-2);color:var(--wf-ink);font-size:13px;line-height:1.3}',
      '.wayfindr-widget__attachment--file:hover{border-color:var(--wf-brand)}',
      '.wayfindr-widget__attachment-name{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:180px}',
      '.wayfindr-widget__attachment-size{color:var(--wf-muted);font-size:11px}',
      '.wayfindr-widget__attachment--image{display:block}',
      '.wayfindr-widget__attachment-image{display:block;max-width:100%;max-height:220px;border-radius:6px;border:1px solid var(--wf-rule)}',
      '.wayfindr-widget__attachments{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:6px}',
      '.wayfindr-widget__attachments[hidden]{display:none}',
      '.wayfindr-widget__attach-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--wf-rule);border-radius:999px;padding:4px 6px 4px 10px;background:var(--wf-surface-2);font-size:12px;line-height:1.3;color:var(--wf-ink);max-width:100%}',
      '.wayfindr-widget__attach-chip--error{border-color:color-mix(in srgb, var(--wf-signal-stop) 45%, var(--wf-rule));background:color-mix(in srgb, var(--wf-signal-stop) 10%, var(--wf-surface));color:var(--wf-signal-stop)}',
      '.wayfindr-widget__attach-chip-name{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:150px}',
      '.wayfindr-widget__attach-chip-state{color:var(--wf-muted);font-size:11px}',
      '.wayfindr-widget__attach-chip--error .wayfindr-widget__attach-chip-state{color:var(--wf-signal-stop)}',
      '.wayfindr-widget__attach-chip-remove{border:0;background:transparent;color:var(--wf-muted);cursor:pointer;font:700 16px/1 var(--wf-font-sans);padding:0 2px}',
      '.wayfindr-widget__attach-chip-remove:hover{color:var(--wf-signal-stop)}',
      '.wayfindr-widget__attach{min-height:40px;border:1px solid var(--wf-rule);border-radius:6px;background:var(--wf-surface);color:var(--wf-ink);cursor:pointer;padding:0 12px;font:700 14px/1 var(--wf-font-sans)}',
      '.wayfindr-widget__attach:hover{border-color:var(--wf-brand);color:var(--wf-brand)}',
      '.wayfindr-widget__attach:disabled{cursor:wait;opacity:.7}',
      '.wayfindr-widget__form{display:grid;gap:10px;padding:16px}',
      '.wayfindr-widget__label{font-size:13px;font-weight:700}',
      '.wayfindr-widget__textarea{width:100%;resize:vertical;border:1px solid var(--wf-rule);border-radius:6px;padding:10px;background:var(--wf-surface);color:var(--wf-ink);font:16px/1.4 var(--wf-font-sans)}',
      '.wayfindr-widget__textarea:focus{outline:3px solid color-mix(in srgb, var(--wf-brand) 22%, transparent);border-color:var(--wf-brand)}',
      '.wayfindr-widget__textarea:disabled{background:var(--wf-paper);color:var(--wf-muted);cursor:wait}',
      '.wayfindr-widget__actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}',
      '.wayfindr-widget__refresh{min-height:40px;border:1px solid var(--wf-rule);border-radius:6px;background:var(--wf-surface);color:var(--wf-ink);cursor:pointer;padding:0 12px;font:700 14px/1 var(--wf-font-sans)}',
      '.wayfindr-widget__refresh:hover{border-color:var(--wf-brand);color:var(--wf-brand)}',
      '.wayfindr-widget__refresh:disabled{cursor:wait;opacity:.7}',
      '.wayfindr-widget__cobrowse{display:grid;gap:8px;padding:0 16px 16px}',
      '.wayfindr-widget__cobrowse-copy{margin:0;color:var(--wf-muted);font-size:13px;line-height:1.35}',
      '.wayfindr-widget__cobrowse-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}',
      '.wayfindr-widget__help{display:flex;flex-direction:column;gap:6px;padding:12px 16px;border-bottom:1px solid var(--wf-rule)}',
      '.wayfindr-widget__help-label{color:var(--wf-muted);font:700 11px/1 var(--wf-font-sans);letter-spacing:.06em;text-transform:uppercase}',
      '.wayfindr-widget__help-input{min-height:36px;padding:0 10px;border:1px solid var(--wf-rule);border-radius:6px;background:var(--wf-paper);color:var(--wf-ink);font:400 14px/1 var(--wf-font-sans)}',
      '.wayfindr-widget__help-status{margin:0;color:var(--wf-muted);font-size:12px;line-height:1.4}',
      '.wayfindr-widget__help-results{margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:2px}',
      '.wayfindr-widget__help-result{width:100%;text-align:left;padding:8px 10px;border:0;border-radius:6px;background:transparent;color:var(--wf-ink);cursor:pointer;font:500 14px/1.3 var(--wf-font-sans)}',
      '.wayfindr-widget__help-result:hover{color:var(--wf-brand)}',
      '.wayfindr-widget__help-back{align-self:flex-start;padding:0;border:0;background:none;color:var(--wf-brand);cursor:pointer;font:500 12px/1 var(--wf-font-sans);text-decoration:underline}',
      '.wayfindr-widget__help-blocks{color:var(--wf-ink);font-size:14px;line-height:1.5}',
      '.wayfindr-widget__help-blocks h3{margin:10px 0 4px;font-size:14px}',
      '.wayfindr-widget__help-blocks p{margin:0 0 8px}',
      '.wayfindr-widget__help-blocks ul{margin:0 0 8px;padding-left:18px}',
      '.wayfindr-widget__help-blocks a{color:var(--wf-brand)}',
      '.wayfindr-widget__help-blocks code{font-family:var(--wf-font-mono);font-size:13px}',
      '.wayfindr-widget__cobrowse-allow,.wayfindr-widget__cobrowse-decline{min-height:36px;border:1px solid var(--wf-rule);border-radius:6px;background:var(--wf-surface);color:var(--wf-ink);cursor:pointer;padding:0 12px;font:700 13px/1 var(--wf-font-sans)}',
      '.wayfindr-widget__cobrowse-allow{background:var(--wf-brand);border-color:var(--wf-brand);color:var(--wf-ink-invert)}',
      '.wayfindr-widget__cobrowse-allow:hover{background:color-mix(in srgb, var(--wf-brand) 80%, var(--wf-ink));border-color:color-mix(in srgb, var(--wf-brand) 80%, var(--wf-ink));color:var(--wf-ink-invert)}',
      '.wayfindr-widget__cobrowse-decline:hover{border-color:var(--wf-brand);color:var(--wf-brand)}',
      '.wayfindr-widget__cobrowse-allow:disabled,.wayfindr-widget__cobrowse-decline:disabled{cursor:wait;opacity:.7}',
      '.wayfindr-widget__status{min-height:20px;margin:0;padding:0 16px 16px;color:var(--wf-muted);font-size:13px}',
      '@media (max-width:480px){.wayfindr-widget{inset-inline-end:12px;bottom:12px}.wayfindr-widget__panel{width:calc(100vw - 24px);max-height:calc(100dvh - 24px)}}',
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
      // The host page's answer, and the only one that outranks the visitor's
      // own browser: an application that has signed someone in knows which
      // language they chose.
      locale: script.dataset.wayfindrLocale,
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
    cobrowsePayloadBudget: Object.freeze(Object.assign({}, DEFAULT_COBROWSE_PAYLOAD_BUDGET)),
    createCobrowseSnapshot: createCobrowseSnapshot,
    createCobrowseMutationBatch: createCobrowseMutationBatch,
    init: init,
    // Exposed so the catalogues can be checked against each other rather than
    // by reading them: a locale missing one key looks translated and says one
    // sentence in English, which is precisely what survives review.
    messages: MESSAGES,
    locales: Object.keys(MESSAGES),
    // Which way a language runs. Public because a host page embedding the
    // widget in its own layout needs the same answer, and because the widget
    // itself cannot demonstrate it until an RTL catalogue ships.
    textDirection: function (locale) {
      return isRtlLocale(locale) ? 'rtl' : 'ltr';
    },
    normalizeApiBaseUrl: normalizeApiBaseUrl,
    resolveAnonymousId: resolveAnonymousId,
  };

  if (typeof setTimeout === 'function') {
    setTimeout(autoInitFromCurrentScript, 0);
  }

  return api;
});
