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
widget still works with the manual refresh button. The Reverb app key is public
client configuration; never expose `REVERB_APP_SECRET` in browser code.

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

For local development inside this monorepo, use
`../../packages/widget-js/src/wayfindr-widget.js` from the plain HTML example.

The widget currently supports the first visitor loop:

- bootstrap the site config, anonymous visitor, and signed visitor token,
- start a conversation,
- send visitor messages,
- render the visitor-visible conversation message timeline,
- receive live agent replies over Reverb when configured,
- grant or revoke cobrowse consent,
- report lightweight cobrowse connection telemetry after consent,
- manually refresh for agent replies when realtime is unavailable.

```js
const client = Wayfindr.createClient({
  apiBaseUrl: 'https://your-wayfindr-host.example',
  sitePublicKey: 'site_demo_public_key',
});

const result = await client.sendFirstMessage('Can you help me?', {
  pageUrl: window.location.href,
});

const timeline = await client.fetchMessages(result.conversation.support_code);
```

`sendFirstMessage` bootstraps the visitor session automatically when needed.
Lower-level calls such as `startConversation`, `sendMessage`, and
`fetchMessages` expect the visitor to have been bootstrapped first.
`subscribeToConversation` prepares a private `conversations.{supportCode}`
subscription for realtime adapters and uses Wayfindr's signed visitor session
when authorizing the channel.
`setCobrowseConsent` and `reportCobrowseTelemetry` prepare the consent and
measurement path for shared page-state cobrowsing.

## Development

```bash
npm test
```

The script attaches `window.Wayfindr` for classic script tags and also exports
the same API through CommonJS so the package can be tested without a browser
build step.

License: MIT.
