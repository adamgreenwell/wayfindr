# Inbound Mail

[Back to self-hosting](install.md)

Email arriving at a site's address becomes a message on the right conversation,
for the right person. This page is how to switch that on.

**It is off until you switch it on.** `WAYFINDR_INBOUND_MAIL_SECRET` is empty by
default, and while it is empty `POST /api/mail/inbound` answers `404` rather
than standing open. An endpoint that writes conversations is worse left open
than left off, so a 404 here means "not configured", not "broken".

## What you need

1. A mail provider that can POST parsed mail to a URL.
2. A site with an `inbound_address` set — mail is routed to a site by the
   address it was **sent to**, so this is what tells one site from another.
   Sites share one endpoint and one secret; the install has a single mail
   provider.
3. Two environment values.

```dotenv
# The channel is off while this is empty.
WAYFINDR_INBOUND_MAIL_SECRET=
# Which scheme your provider actually speaks: wayfindr, mailgun, or postmark.
WAYFINDR_INBOUND_MAIL_PROVIDER=wayfindr
```

## Choosing the provider setting

Providers do not agree on how a webhook proves it is genuine, so Wayfindr
verifies what each one actually sends. Pick the setting that matches yours —
the secret means something different in each.

| `..._PROVIDER` | What is verified | What the secret is |
| --- | --- | --- |
| `mailgun` | Mailgun's own signature over `timestamp + token`, and that the timestamp is recent | Your **HTTP webhook signing key** |
| `postmark` | HTTP Basic credentials Postmark sends with every delivery | The **password** you put in the webhook URL |
| `wayfindr` (default) | `X-Wayfindr-Signature: sha256=<HMAC-SHA256 of the raw body>` | A secret you choose |

An unrecognised value verifies nothing and therefore accepts nothing — every
delivery gets `401`. A typo is not permission to accept unverified mail.

### Mailgun

Set `WAYFINDR_INBOUND_MAIL_PROVIDER=mailgun` and put your **HTTP webhook signing
key** in `WAYFINDR_INBOUND_MAIL_SECRET`. That key is in the sending domain's
security settings; it is **not** your API key, and the two are easy to confuse.

Point a **route** at `https://your-install.example.com/api/mail/inbound`.
Mailgun sends `timestamp`, `token` and `signature` alongside the parsed mail,
and Wayfindr checks all three.

Use a route, not the newer nested webhook shape. That shape carries the sender
and body under `event-data`, which Wayfindr does not parse, so it would verify
and then create nothing — and it is refused rather than accepted-and-ignored so
you find out immediately.

Two things bound replay, and both matter, because **Mailgun's signature covers
the timestamp and token and not the body**. A valid tuple would otherwise
authenticate *any* payload:

- deliveries more than five minutes old are refused, in either direction — a
  clock far ahead is not evidence of freshness;
- each token is accepted **once**. A second delivery reusing it is refused
  whatever it contains.

Attachments on a route arrive as file uploads rather than in the JSON body, and
are stored like any other attachment.

### Postmark

Set `WAYFINDR_INBOUND_MAIL_PROVIDER=postmark`, choose a long random password,
and put it in both `WAYFINDR_INBOUND_MAIL_SECRET` and the webhook URL:

```
https://wayfindr:YOUR-PASSWORD@your-install.example.com/api/mail/inbound
```

Any username works; only the password is checked.

**This is the weakest of the three, and the reason is Postmark's.** Postmark
computes no customer-keyed signature over anything, so there is nothing to
verify beyond a shared secret: no proof the body is untampered, and no replay
bound. If that matters to you, put Mailgun or your own re-signing proxy in
front instead. It is stated here rather than glossed over because choosing a
provider is choosing a security posture.

### wayfindr

The default, and the right setting when something you control posts to this
endpoint — a small function or worker that verifies your provider's own scheme
and re-signs the body in Wayfindr's. Sign the **raw body** with
HMAC-SHA256 and send:

```
X-Wayfindr-Signature: sha256=<hex digest>
```

Before provider schemes existed this was the only option, and it needed exactly
such a proxy. It remains supported so those installs keep working, and it is
still the answer for a provider Wayfindr does not yet verify natively — SES and
SendGrid among them.

## What the responses mean

| Status | Meaning |
| --- | --- |
| `404` | The channel is off. `WAYFINDR_INBOUND_MAIL_SECRET` is empty. |
| `401` | The delivery did not verify: wrong secret, wrong `..._PROVIDER`, a stale Mailgun timestamp, or an unrecognised provider name. |
| `200` `Accepted.` | Routed onto a conversation. |
| `200` `Ignored.` | Understood and deliberately not routed — no usable sender, or an address matching no site. |

The last one is deliberate and worth understanding: **a message for an unknown
address is answered `200`, not an error.** Providers retry failures, and
retrying mail addressed to a site that does not exist would retry for ever.

## Checking it works

Once configured, send a real email to the site's `inbound_address` and watch the
conversation appear. If nothing does:

- `401` on every delivery is almost always `..._PROVIDER` not matching the
  provider actually calling, or Mailgun's API key used where the signing key
  belongs.
- `200 Ignored.` means the delivery verified and was understood — check the
  address it was sent to matches a site's `inbound_address` exactly.
- Nothing at all in the logs means the provider is not reaching you; check the
  webhook URL and that your TLS certificate is valid, since most providers will
  not post to an untrusted one.

Outbound mail is configured separately — see
[email-delivery.md](email-delivery.md).
