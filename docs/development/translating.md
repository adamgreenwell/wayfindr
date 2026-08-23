# Translating the dashboard

The widget is done ([ADR 0017](../decisions/0017-speaking-the-visitors-language.md)).
The dashboard is being extracted a surface at a time, and this is the working
guide for whoever picks up the next one.

## Where the words live

`apps/server/lang/<locale>/<surface>.php`, one file per surface, named after the
surface. `signin.php`, `nav.php`, and so on.

Avoid the four names Laravel ships with — `auth`, `passwords`, `pagination`,
`validation`. A file with one of those names replaces the framework's, so
`auth.php` containing only your keys silently removes `auth.failed`. Those four
are for overriding framework strings deliberately, not for new copy.

## Naming keys

**Name the slot, not the sentence.** `signin.login.submit`, never
`signin.login.sign_in`. A key named after the current wording has to be renamed
when the wording changes, which turns a copy edit into a migration across every
locale.

Group by screen, then by role:

```php
'login' => [
    'title' => 'Agent Login',
    'submit' => 'Sign in',
],
```

## The rule that makes extraction safe

**English must come out the other side unchanged, to the character.**

The suite asserts English prose in roughly three thousand places. If extraction
changes a word, the failure looks like a broken feature rather than a reworded
string, and the diff that caused it is enormous. Copy the shipped text into the
catalogue exactly — improve it in a separate commit, on purpose, where the test
churn is the point rather than the noise.

The practical check: extract a surface, run the whole suite, and expect *zero*
failures. Any failure means the words moved.

## Interpolation

Laravel's `:placeholder` syntax, and translate the sentence rather than
assembling it:

```php
'waiting' => ':count conversations are waiting',
```

Never `__('a.prefix') . $value . __('a.suffix')`. Word order is not a constant
across languages, and a translator handed three fragments cannot fix it.

## What is not extracted yet

Most of it. As of the first tranche: the sign-in surface and the application
shell. Still literal, in rough order of how much copy they carry:

- `OperatorReadiness` — around 270 display strings, the largest single source
- the operator console and settings screens
- conversations, tickets, sites and account screens
- roughly 134 controller flash messages
- around 20 `ValidationException::withMessages()` sites
- Mail and Notification classes
- the option maps on `User` (alert mode, cadence) — note the **keys are also the
  validation allowlist**, so translate the values and leave the keys alone

## Why no language is offered in the interface yet

`Locales::EXTRACTION_COMPLETE` is `false`, so `Locales::offerable()` returns
English only, and there is no language selector anywhere.

A console that is German in the sidebar and English in every screen behind it is
worse than one that is honestly English throughout. The flag is flipped by a
person when extraction is actually finished — deliberately not computed, because
key parity cannot answer it. German has *full parity* today across everything
extracted, while the great majority of the console is still English literals no
catalogue knows about. **A language that matches is not a language that is
ready.**

`Locales::hasFullParity()` is a drift check for the opposite failure: a key added
to `en` and forgotten in `de` produces one English sentence in an otherwise
German page, which is exactly the sort of thing that survives review. A test
holds every carried language to English's key set.

## Right to left

The document carries `dir` alongside `lang`, from `Locales::direction()`. No
right-to-left catalogue ships, so the mechanism is in place and has never
rendered in anger — the same honest position the widget is in.

The dashboard's CSS is **not** ready for it: around twenty-one physical
properties (`border-left`, `margin-left`, `text-align: left`, `right: 0`) and no
logical ones. The site-colour rail (`border-left` on queue rows and the
transcript) is the load-bearing one. That conversion is its own piece of work
and should happen before any right-to-left language is claimed.
