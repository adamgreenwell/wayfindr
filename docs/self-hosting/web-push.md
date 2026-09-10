# Web Push

Web Push reaches an agent when the dashboard is not in front of them. It is
optional and off on a new installation: nothing else depends on it, and an
install that never configures it still delivers every alert to the alert centre
and, where the agent asked for it, by mail.

It is configured by a platform operator, once, for the whole install — agents
cannot supply their own credentials. Each agent then opts in per browser.

The operator settings are DB-backed and override the environment, the same way
mail, storage, scanning and backups do (ADR 0011,
`docs/decisions/0011-operator-settings-and-guided-onboarding.md`). There is no
decision record specific to Web Push.

## Configure it in the operator console

Open **Operator → Web push** (`/operator/settings/web-push`). Three values:

| Field | What it is | Accepted form |
|---|---|---|
| Subject | The contact identity signed into every request to a push service. It is how Google, Mozilla or Microsoft reach you if your install misbehaves. | `mailto:` followed by a valid address, or an `https://` URL. Nothing else. |
| Public key | The application server key handed to agent browsers. Each browser subscription is bound to it. | base64url, 87 characters. |
| Private key | Signs every outbound push. Never leaves the server. | base64url, 43 characters. |

The subject is deliberately strict. A bare address with no `mailto:` prefix is
rejected, so is any `http://` URL, and the prefix is matched case-sensitively —
`MAILTO:you@example.test` is refused where `mailto:you@example.test` is accepted.

### Generating the key pair

Generate it with `--show`:

```bash
php artisan webpush:vapid --show
```

That prints a `VAPID_PUBLIC_KEY=` and `VAPID_PRIVATE_KEY=` pair and changes
nothing. Copy both into the console form.

**Run it without `--show` and it edits `.env` instead of printing**, which is
wrong here for three separate reasons, and the settings page's own help text
recommends the bare command anyway — that hint predates this guide.

1. On an install already configured through the console, the rewrite silently
   does nothing. It builds its search pattern from the *effective* configuration,
   which is the value the database supplied, so it matches no line in `.env` —
   and it still reports `VAPID keys set successfully.`
2. Inside the official Docker image there is no environment file to rewrite. The
   image excludes `.env` and the entrypoint never creates one.
3. It never writes a subject. A pair written that way leaves the install
   *incomplete* rather than ready, which is a warning on the operator console
   and, on an otherwise-healthy install, its headline next step.

Whatever you generate, the subject is yours to choose. Nothing generates it.

### What the form will and will not accept

Public and private keys are replaced **as a pair**. Submitting one without the
other is refused with *Replace the public and private VAPID keys together*.
Leaving the private key blank keeps the stored one.

A subject on its own does save. So does a save that leaves the install
incomplete — only a value that is positively *invalid* is rejected. The green
**Web Push settings saved.** flash therefore does not mean push is on. The status
chip on the page, and the readiness card, are what answer that.

Only the private key is encrypted at rest, under `APP_KEY`. The subject and
public key are stored as ordinary rows, which is correct — both are published to
every agent browser and to every push service — but it does surprise people who
assume the whole group is encrypted. The private key is never rendered back into
the form; the field shows only whether one is set.

## Rotating or clearing the keys deletes every subscription

Changing the public key **deletes every push subscription for every agent on the
install**, in the same transaction. So does ticking *Clear the VAPID
configuration*. There is no confirmation step and no count shown beforehand.

Two things make this worse than it first sounds:

- **Each agent's push preference stays switched on.** Their profile offers to
  enable this browser again rather than reporting an error, so nobody is told.
  Agent preferences are not a truthful record of who is still reachable after a
  rotation; the `push_subscriptions` table is.
- **Nothing re-subscribes them.** Every agent must opt in again, on every
  browser, by hand. Tell them out of band.

Rotate on purpose — a leaked private key is a real reason — and not as
maintenance.

*Clear the VAPID configuration* is also not a way back to environment variables.
It stores empty values, and empty is a real override, so it shadows
`VAPID_SUBJECT`, `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY` from then on. The
console offers no route back to inheriting from the environment; that needs the
rows removed from `operator_settings` directly.

## "Ready" is a self-check, not a delivery test

The **Ready** status means the subject parses and the two keys verify against
each other. That check is done entirely offline: it signs a token against a
placeholder audience and checks the signature. It makes no network call.

An install with no outbound access to push services will report **Ready** and
fail every delivery.

There is no test-send button. Web push is the only credential-bearing operator
settings page without one — mail, agent copilot, storage, scanning and backups
each have one, and this does not. The only end-to-end proof is an agent enabling
push on a real browser and receiving a real notification. Do that once before
telling a team the feature is on.

One status deserves separate mention. **Temporarily unavailable** means the
operator settings store itself could not be read — a database or cache fault —
not that the credentials are wrong. Deliveries retry and subscriptions survive.
Rotating keys in response would destroy every subscription to fix a fault that
had nothing to do with them; the console says so where it reports the condition.

If the saved private key cannot be decrypted, the cause is almost always a
rotated `APP_KEY`. The key material is not exposed by this, but it is no longer
readable: the install falls back to whatever `VAPID_PRIVATE_KEY` the environment
holds, which on most installs is nothing, and the status drops to incomplete.
Enter a replacement pair, or clear Web Push and start again.

## It needs a queue worker

Push delivery is queued, on the default queue connection, with a deliberate short
delay. **Without a running queue worker, notifications are never sent** — and
nothing on the settings page reports it, because the credentials are fine.

The Compose stack runs a worker already. If you changed `QUEUE_CONNECTION`, note
that the bundled worker command names its connection literally rather than
reading that variable, so the worker has to be changed with it.

Push subscriptions are removed when a push service reports one gone (404 or 410),
when the public key changes, and when an agent unsubscribes or signs out. There
is no scheduled pruning, and there is no cleanup command. One consequence is
worth knowing: rotating the public key *through the environment* rather than the
console does not purge the stale rows, and they then accumulate out of sight.
Rotate in the console.

## What a push service can see

The payload is encrypted end to end, and it is deliberately thin: a generic
title and body in the agent's language, plus an alert identifier and version.
The substance of the alert is not in it. A notification tells an agent that
something needs them and gets them to the right screen; it does not carry the
conversation.

The encryption does not hide everything, and it would be wrong to imply it does.
Whoever runs the push service still sees the device endpoint, your VAPID subject,
your install's stable public key, the address the request came from, and the
timing of every notification per device. That is enough to associate an install's
agents with the contact address you configured. If that matters for your
deployment, the honest options are to leave Web Push off or to accept it, not to
configure around it.

Requests go to whichever push service each agent's browser nominates — Google for
Chrome, Mozilla for Firefox, Microsoft for Edge — so the egress is not a fixed
list of hosts you can enumerate in advance. There is no allowlist setting, and
outbound requests deliberately do not use a proxy, so an install that reaches the
internet only through one cannot deliver push at all.

## Environment baseline

The same three values can seed an install before anything is saved in the
console:

```dotenv
VAPID_SUBJECT=
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
```

Once an operator saves the form, the stored values win, and later edits to these
variables have no effect — including an edit made to fix a problem, which is a
quiet way to lose an afternoon. Environment values also read at config-build
time, so an install that uses them needs `php artisan config:cache` and a worker
restart before a change applies. Console values need neither.

Use the environment as an initial baseline, and the console as the control
surface. The Compose templates do not list these variables, and that is
consistent rather than an oversight: no operator-settings key appears in them.

`WEBPUSH_DB_TABLE` and `WEBPUSH_DB_CONNECTION` exist and should be left alone.
Subscriptions have to live on the application's primary connection; pointing
them elsewhere does not disable the feature cleanly, it makes agents' subscribe
requests fail and blocks key changes in the console.

## Which browsers this reaches

Push needs a secure context, so a real install needs HTTPS. Chrome, Firefox and
Edge on desktop and Android are what this is built for.

**iOS is not supported.** Apple delivers web push only to a site the user has
added to their Home Screen as a web app, and Wayfindr ships neither the web app
manifest nor the metadata that would require. In an ordinary iOS Safari tab the
capability check simply fails and the agent's checkbox is disabled. An agent on
an iPhone should use mail alerts.

Signing out unsubscribes that browser, and if it was the agent's last one, turns
their push preference off. Closing the tab does not — that is the case this
feature exists for.

The service worker does not take over from a previous copy while any dashboard
tab is still open. After an upgrade that changes it, agents keep the old
behaviour until they have closed every Wayfindr tab.

## What is not built

Named so that nothing above is read as promising it: there is no delivery report
or per-endpoint failure log in the dashboard, no artisan command for push, no
scheduled cleanup of subscription rows, and no push entry in the data inventory
under `docs/privacy/`. Failed deliveries are retried and then dropped; the record
of what reached whom is the alert centre's, not a push log's.

None of the browser-side lifecycle is covered by automated tests. The service
worker and the subscribe flow are asserted as source text, not exercised in a
browser, so an upgrade that changes them is worth confirming by hand on one real
browser before an agent finds out for you.
