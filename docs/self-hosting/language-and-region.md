# Language and Region

Wayfindr's dashboard is translated into English, German and Italian, and renders
times on a clock you choose. Both are set in the browser at
**Operator console → Language and region**, and take effect immediately with no
restart.

Two settings, both **defaults**:

| Setting | What it decides |
| --- | --- |
| **Language** | What the agent dashboard reads in |
| **Timezone** | What clock times and report days are shown on |

## They are defaults, not rules

An agent who picks their own language or timezone on their profile keeps that
choice. These answer for everyone who has not chosen — which, on a new install,
is everyone.

That is why setup asks for them. An install that guessed wrong is not broken, it
is merely foreign, so nobody reports it: an agent in Berlin reading `14:32`
assumes that is how the product works, and a report covering "yesterday" covers
a day that ended two hours before theirs did. The setup checklist raises this
once, and clears permanently as soon as you confirm it — including when the
defaults were right all along.

## The widget is not affected

What a **visitor** sees in the chat widget is chosen from their own browser
language, not from this setting. An install set to German still greets an
English-speaking visitor in English.

## Timezone does not rewrite history

Every record is stored in **UTC** and stays there. Changing the timezone changes
how existing timestamps are *read*, not what was written — so switching from
`UTC` to `Europe/Berlin` shifts what you see by the offset, immediately and
reversibly, with no migration and no risk to stored data.

This is also why the storage clock is **not** what this setting changes. That
one is `app.timezone`, and it is **hardcoded to `UTC`** in `config/app.php` —
there is no environment variable for it, deliberately.

Laravel writes `created_at` through that value, into columns that carry no
offset. Point it at a local zone and rows written from then on record local
wall-clock time in a column every other reader — and every report query —
treats as UTC. The drift is permanent and invisible, which is why it is not
offered as a setting at all. The display clock is a separate setting for
exactly that reason.

## Environment defaults

The GUI setting overrides the environment, the same way mail, storage, scanning
and backups do
([ADR 0011](../decisions/0011-operator-settings-and-guided-onboarding.md)). To
seed a **new** install — a fleet deployment, or a container image — these are
the keys:

```dotenv
APP_LOCALE=de
WAYFINDR_DASHBOARD_TIMEZONE=Europe/Berlin
```

They decide what a fresh install reads in **before anybody has answered the
setup step**, and what the console's form is pre-filled with when it is first
opened.

Once the setting is saved in the console, the console is the answer and these
are no longer consulted. That is deliberate rather than an omission: the whole
point of the setup step is that a person confirmed the clock, and a "go back to
whatever the environment says" control would be a way to un-confirm it while the
checklist still read as ready. Changing the language or timezone later means
changing it in the console.

## Which timezones are accepted

Any [IANA identifier](https://en.wikipedia.org/wiki/List_of_tz_database_time_zones)
your host's tzdata knows — `Europe/Berlin`, `America/New_York`, `Asia/Tokyo`,
`UTC`. The list in the console comes from the platform itself, so it matches
your host exactly and stays right as tzdata adds and renames zones.

## Site support hours are separate

A site's **support hours** carry their own timezone, set per site under the
site's settings. That is deliberate: "visitors are told support is back at
09:00" is a statement about the site's own schedule, and it stays true no matter
which clock the agent reading it is on.
