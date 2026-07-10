# Email Delivery (Outbound Mail)

Wayfindr sends outbound email for a few things that matter once real support
traffic is flowing:

- **Alert digests** — the metadata-only summaries agents opt into
  ([alert-digests-and-escalation.md](../product/alert-digests-and-escalation.md)),
  delivered by the hourly `wayfindr:send-alert-digests` scheduled job.
- **Password resets** and other account notices.
- **Operator notices** surfaced by the readiness screens.

If the mailer is left in `log` mode, none of this leaves the app — digests
pile up as log lines and agents never hear that a visitor is waiting. So a real
outbound transport is a dogfood-readiness gate, not an optional extra
([mvp-dogfood-readiness.md](../product/mvp-dogfood-readiness.md)).

This page is the **provider-specific recipe book**. For the generic Laravel
runtime contract — where mail sits in the environment alongside the queue,
scheduler, and Reverb — see
[runtime-requirements.md](runtime-requirements.md) and the Forge checklist in
[laravel-forge.md](laravel-forge.md). Both explain the env shape; this page
shows how to fill it in for a specific provider, starting with Google
Workspace.

## What the app needs

Wayfindr uses Laravel's stock SMTP mailer (Symfony transport) — there is no
custom transport, no `alwaysFrom`, and no from-address allowlist in the app. So
any provider that speaks SMTP works with environment variables alone; no code
changes are required. The mailer only needs:

- `MAIL_MAILER` set to a real transport (`smtp`, or a Laravel-supported API
  mailer such as `ses`, `postmark`, or `resend`) — **not** `log`.
- A reachable host/port, and credentials **only if the provider requires
  them** (see the Workspace relay note below — IP-authenticated relays need
  none).
- A monitored `MAIL_FROM_ADDRESS` on a domain you control.

### The `MAIL_SCHEME` rule

This trips people up, so it is worth stating plainly:

- **STARTTLS ports (587, 2587):** leave `MAIL_SCHEME` unset or `null`. Laravel's
  SMTP transport does **not** accept `tls` as a scheme — setting
  `MAIL_SCHEME=tls` breaks the connection.
- **Implicit TLS port (465):** set `MAIL_SCHEME=smtps`.

## Google Workspace

If your domain's email is on Google Workspace (as `wayfindr.cc` is), there are
two ways to send. Prefer the first.

### Recommended: Workspace SMTP relay service

The **SMTP relay service** (`smtp-relay.gmail.com`) is Google's supported path
for an *application* sending mail through Workspace. It authenticates the whole
server — typically by IP address — rather than a single human's mailbox, lets
you send **as any address in your domains** (e.g. `support@wayfindr.cc`), and
allows up to ~10,000 recipients per day. It does not depend on per-user App
Passwords, which Google is progressively deprecating.

**1. Configure the relay in the Admin console** (you need Workspace admin
access):

1. Sign in to [admin.google.com](https://admin.google.com).
2. Go to **Apps → Google Workspace → Gmail → Routing**.
3. Find **SMTP relay service** and click **Configure** (or **Add another
   rule**). If a relay rule already exists, **edit that one** rather than adding
   a second — a fresh Workspace may already ship with a relay rule whose
   defaults are not what you want (commonly *Require SMTP Authentication: ON*,
   *Only accept mail from the specified IP addresses: OFF*).
4. Give it a name, e.g. `Wayfindr`.
5. **Allowed senders:** choose **Only addresses in my domains**. This lets the
   app send as any address in a domain verified on this Workspace (e.g.
   `support@wayfindr.cc`) while blocking spoofed external senders.
6. **Authentication:** tick **Only accept mail from the specified IP
   addresses**, add your Wayfindr server's **public IP** (on Forge, the server's
   IP — `curl -s https://api.ipify.org` from the box if you are unsure), and
   **leave *Require SMTP Authentication* unchecked**. This is the clean,
   credential-free path for a headless server: the relay trusts the connection
   by source IP, so the app needs no username or password. (Enabling *Require
   SMTP Authentication* instead means signing in as a Workspace user, which
   brings App Passwords back into the picture — so for a server, IP allowlisting
   alone is preferred. Note the tradeoff: with auth off, anything that can send
   from that IP can relay as one of your domain addresses, which is fine on a
   dedicated box but worth knowing on a shared one.)
7. **Encryption:** tick **Require TLS encryption**.
8. **Save.** Changes usually apply within minutes but can take up to ~24 hours
   to propagate.

> **Prerequisite:** "Only addresses in my domains" means the sending domain must
> be **verified on this Workspace** (Admin console → *Account → Domains*). If
> `MAIL_FROM_ADDRESS`'s domain is not listed there, the relay rejects the
> sender.

**2. Set the environment** on the Wayfindr host (Forge → Site → Environment, or
your `.env`):

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.gmail.com
MAIL_PORT=587
MAIL_SCHEME=null
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=support@wayfindr.cc
MAIL_FROM_NAME="${APP_NAME}"
```

With IP-authenticated relay you **leave `MAIL_USERNAME` and `MAIL_PASSWORD`
empty** — Laravel's SMTP transport skips the AUTH handshake when no username is
set, and the relay accepts the connection because it recognises the server's
IP. `MAIL_FROM_ADDRESS` must be a real address in a domain the relay allows
(here, any `@wayfindr.cc` address).

> If your host reassigns the server IP, or you run behind a NAT/egress gateway,
> add every possible egress IP to the relay's allowed list — the relay checks
> the source IP of the connection, not the server's configured address.

### Fallback: `smtp.gmail.com` with an App Password

If you do not have Workspace admin access (or you are on a plain Gmail account),
you can send through a single mailbox using a 16-character **App Password**.
Google is deprecating App Passwords and this path caps at ~2,000 messages/day,
so treat it as a starter or last resort rather than the long-term setup.

1. The sending account needs **2-Step Verification** enabled.
2. Create an App Password at
   [myaccount.google.com → Security → App passwords](https://myaccount.google.com/apppasswords).
3. Set the environment (paste the App Password **without spaces**):

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_SCHEME=null
MAIL_USERNAME=support@wayfindr.cc
MAIL_PASSWORD=your16charapppassword
MAIL_FROM_ADDRESS=support@wayfindr.cc
MAIL_FROM_NAME="${APP_NAME}"
```

With this path the `MAIL_FROM_ADDRESS` must be the **authenticated mailbox**
itself, or an alias configured under that account's **Settings → Accounts →
Send mail as**. Sending as an unrelated address will be rewritten or rejected.

## Deliverability: SPF, DKIM, DMARC

Getting mail *sent* is only half the job — you also want it to land in the
inbox, not spam. For a Workspace domain sending through the relay:

- **SPF** — your domain's SPF TXT record should include Google's servers. The
  Workspace default `v=spf1 include:_spf.google.com ~all` already covers the
  relay, since it sends from Google IPs.
- **DKIM** — in the Admin console, go to **Apps → Google Workspace → Gmail →
  Authenticate email**, generate a DKIM key for the domain, publish the TXT
  record it gives you, then click **Start authentication**. Relayed mail is
  then DKIM-signed for your domain.
- **DMARC** — once SPF and DKIM pass, publish a `_dmarc.<domain>` TXT record.
  Start permissive (`v=DMARC1; p=none; rua=mailto:dmarc@<domain>`) to collect
  reports, then tighten to `quarantine`/`reject` once you have confirmed
  legitimate mail aligns.

## Verify from the deployed host

After configuring the environment (and running the Forge deploy / restarting
the app so it picks up the new values), send a real smoke test from the box
that runs Wayfindr. `artisan` lives under `apps/server` in the monorepo, so run
it from there:

```bash
cd apps/server
php artisan wayfindr:mail-test --to="you@wayfindr.cc"
```

The command prints the active mailer, SMTP host/port/scheme, and sender, then
sends a test message — it never prints your SMTP credentials. Confirm the
message arrives, then check the readiness screens (`/operator` and the account
readiness page) — the **Mail transport** check should read healthy.

To confirm the scheduled digest path end to end, see the alert-digest smoke
test under **Queues And Scheduler** in
[laravel-forge.md](laravel-forge.md#queues-and-scheduler).

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| Nothing sends; no error | `MAIL_MAILER` is still `log`. Set a real transport and redeploy. |
| `535 … Username and Password not accepted` | App Password path: 2-Step Verification is off, or the password was mistyped / had spaces. |
| `550 … Mail relay denied` / `Invalid credentials` on the relay | The server's public IP is not in the relay's allowed list, or `MAIL_FROM_ADDRESS` is not in an allowed domain. |
| Mail sends but lands in spam | DKIM not set up, or the From domain does not match the authenticated/relaying domain. Work through the SPF/DKIM/DMARC section. |
| `Connection could not be established` / timeout | The host firewall blocks outbound SMTP. Many providers block port 25; use 587 (or 465 with `MAIL_SCHEME=smtps`). |
| Works locally, not on the server | The `.env` change was not deployed, or the config cache is stale — redeploy or run `php artisan config:clear`. |

## Other providers

The same env contract applies to any SMTP provider — swap `MAIL_HOST`,
`MAIL_PORT`, and credentials for the provider's values and keep the
`MAIL_SCHEME` rule above in mind. Laravel also ships first-class API mailers for
Amazon SES, Postmark, and Resend; for those, set `MAIL_MAILER` accordingly and
provide the provider's API key per the
[Laravel mail documentation](https://laravel.com/docs/mail). Whichever you
choose, finish with the `wayfindr:mail-test` smoke test above.
