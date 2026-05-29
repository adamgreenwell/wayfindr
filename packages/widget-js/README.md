# Wayfindr Widget JS

Classic script-compatible browser widget SDK for Wayfindr.

The initial package exposes a small global API and is intentionally friendly to
plain HTML sites:

```html
<script src="https://js.pusher.com/8.3.0/pusher.min.js"></script>
<script src="https://your-wayfindr-host.example/widget.js"></script>
<script>
  Wayfindr.init({
    apiBaseUrl: 'https://your-wayfindr-host.example',
    sitePublicKey: 'site_demo_public_key',
    visitorContext: {
      plan: 'Team',
      support_region: 'EU',
    },
    reverb: {
      appKey: 'your-public-reverb-app-key',
      host: 'your-wayfindr-host.example',
      port: 443,
      scheme: 'https',
    },
  });
</script>
```

The Pusher script is only required for live Reverb updates. Without it, the
widget falls back to light message polling and the manual refresh button. When
live updates are connected, unavailable, or reconnecting, the widget shows a
small calm status note so visitors know refresh is still available without
turning transport hiccups into a scary error state. The Reverb app key is
public client configuration; never expose
`REVERB_APP_SECRET` in browser code.

Classic script tags can also use data attributes:

```html
<script src="https://js.pusher.com/8.3.0/pusher.min.js"></script>
<script
  src="https://your-wayfindr-host.example/widget.js"
  data-wayfindr-api-base-url="https://your-wayfindr-host.example"
  data-wayfindr-site-key="site_demo_public_key"
  data-wayfindr-reverb-app-key="your-public-reverb-app-key"
  data-wayfindr-reverb-host="your-wayfindr-host.example"
  data-wayfindr-reverb-port="443"
  data-wayfindr-reverb-scheme="https"
></script>
```

For installed Wayfindr servers, the agent dashboard shows a copy-ready snippet
on each site's settings page. Use that generated snippet when possible so the
site public key, API base URL, and public Reverb settings stay aligned with the
running installation.

For local development inside this monorepo, use
`../../packages/widget-js/src/wayfindr-widget.js` from the plain HTML example.

The widget currently supports the first visitor loop:

- bootstrap the site config, anonymous visitor, and signed visitor token,
- start a conversation,
- send visitor messages,
- render the visitor-visible conversation message timeline with timestamps,
- receive live agent replies over Reverb when configured,
- fetch the current cobrowse request status,
- show explicit allow/decline controls only after support requests cobrowse,
- grant, decline, or revoke cobrowse consent,
- report lightweight cobrowse connection telemetry after consent,
- report passive page state after consent,
- report an initial sanitized DOM snapshot after consent,
- report bounded sanitized DOM mutation batches after consent,
- poll lightly and manually refresh for agent replies when realtime is unavailable.

```js
const client = Wayfindr.createClient({
  apiBaseUrl: 'https://your-wayfindr-host.example',
  sitePublicKey: 'site_demo_public_key',
});

const result = await client.sendFirstMessage('Can you help me?', {
  pageUrl: window.location.href,
  context: {
    plan: 'Team',
    support_region: 'EU',
  },
});

const timeline = await client.fetchMessages(result.conversation.support_code);
```

`sendFirstMessage` bootstraps the visitor session automatically when needed.
Lower-level calls such as `startConversation`, `sendMessage`,
`fetchMessages`, and `fetchCobrowseStatus` expect the visitor to have been
bootstrapped first.
Host pages may pass a small `visitorContext` object to `Wayfindr.init`, or a
`context` object to `bootstrap`, `startConversation`, or `sendFirstMessage`.
Keep it operational and low-risk, such as plan, tier, product area, or support
region. Wayfindr sanitizes this context server-side, drops obvious sensitive
keys and non-scalar values, and truncates long values before agents can see it.
`subscribeToConversation` prepares a private `conversations.{supportCode}`
subscription for realtime adapters and uses Wayfindr's signed visitor session
when authorizing the channel.
`fetchCobrowseStatus`, `setCobrowseConsent`, `reportCobrowseTelemetry`,
`reportCobrowsePageState`, `reportCobrowseSnapshot`, and
`reportCobrowseMutations` prepare the consent, measurement, initial snapshot,
and bounded mutation path for shared page-state cobrowsing.
Widget mutation batches are also capped client-side before they leave the
browser. The default cap is 60,000 serialized bytes, and host pages can lower
or raise it with `mutationPayloadMaxBytes` when calling `Wayfindr.init`. The
widget keeps at most 250 pending mutation records between flushes by default;
`mutationQueueMaxRecords` can tune that queue for especially noisy pages.
`createCobrowseSnapshot` masks password fields, hidden fields, configured mask
selectors, and common sensitive-looking fields before snapshot data leaves the
visitor browser. Host pages can mark sensitive regions with
`data-wayfindr-mask` or `data-wayfindr-private`; `data-wayfindr-allow` should
only be used for deliberate false positives.
`createCobrowseMutationBatch` applies the same masking posture to text, safe
attribute, added-node, and removed-node mutation records before they are sent.

## Development

```bash
npm test
```

The script attaches `window.Wayfindr` for classic script tags and also exports
the same API through CommonJS so the package can be tested without a browser
build step.

License: MIT.
