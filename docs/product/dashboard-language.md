# The language the dashboard speaks

Status: **in progress.** The plumbing is shipped and the agent profile page is
translated, with one recorded exception below. The remaining views are being
extracted surface by surface.

### The recorded exception

The profile page shows a mail-readiness sentence built by `OperatorReadiness`.
That class holds the **operator console's** vocabulary — around a thousand lines
of copy shared across that surface — so it extracts with the operator console
rather than from a page-shaped change reaching into it. Until then, an agent
reading the dashboard in German sees that one sentence in English.

Written down rather than left to be discovered, because the failure mode of an
extraction is precisely a page that looks finished and is not.

Distinct from [ADR 0017](../decisions/0017-speaking-the-visitors-language.md),
which is about the **widget** and therefore about visitors. This is about
agents, and the two are deliberately independent: a desk can answer German
visitors from an English dashboard, or the reverse.

## Why this matters more here than for a hosted competitor

Operators who choose self-hosting frequently do so for data-residency reasons,
which correlates almost exactly with not operating primarily in English. The
product's strongest advantage — operator control and self-hosting depth — was
aimed at precisely the buyers least served by its only language.

## How an agent chooses

**Profile → Dashboard language.** Per agent, not per account or per install: a
dashboard is read by people rather than by an organisation, and a team spread
across countries has agents who each want their own tools in their own language
without having to agree with a colleague first.

Leaving it unset means the install default, which is what every agent had before
the setting existed. An agent who picked a language can stop having picked one.

**`Accept-Language` is deliberately ignored.** An agent's browser is often
configured by whoever set the machine up, and a tool people work in all day
should change language when they say so and not before.

## How the copy is organised

Short keys grouped by surface — `lang/en/profile.php`, and a matching
`lang/de/profile.php` — rather than English-as-key JSON.

English-as-key reads well in a diff and fails badly in practice here: this
codebase's copy is edited constantly, and prose in a key position means every
edit silently orphans its translations, with the old English reappearing for
every other language.

### Extraction covers what the page shows, not what the view contains

The catalogue has to reach copy built outside the Blade file — labels assembled
in a controller, option maps on a model, status text from a support class. None
of that appears in the view, so grepping the template says a page is finished
while a third of it is still English, and nobody notices until they switch
language and read a page in two at once.

### Extraction does not edit copy

An extraction that quietly tidies wording is one nobody can trust to be
behaviour-preserving. Where extraction surfaces a copy problem — the profile
page turned out to call itself *Agent Profile* in the browser tab and *Agent
profile* in its heading — the string is preserved exactly, with the reason
recorded beside it, and the fix is left to be made on purpose.

### The selector names languages in their own language

`English`, `Deutsch` — autonyms, never glossed, and deliberately identical in
every rendering language. It is the one place on a translated page where copy
reading the same in English and German is correct.

The reason is who reads it. This selector asks an agent which language *they*
want to read, so the reader of any option is by definition someone who reads
that language. The gloss it used to carry — `Deutsch (German)` — helped exactly
one audience and put an English word inside the German page for everyone else.

The **widget** language list keeps its glosses on purpose. That one asks an
operator to choose a language for visitors, and the operator may well not read
it. Same words, different question.

### Two things a page shows that are not in any page catalogue

**Validation messages come from Laravel**, not from a surface catalogue, and a
mistyped current password is the likeliest thing to happen on a form. Left
alone, the most ordinary error path on a translated page answers in English.
`lang/de/validation.php` covers the rules the dashboard actually validates with
rather than restating all hundred Laravel ships; anything else falls through to
the framework's English, so a rule added later is a correct English sentence and
never a raw key.

Its `attributes` map matters more than it looks. Without it a German agent is
told `current_password muss ausgefüllt werden`, which is worse than English —
it reads as a system error rather than as a form asking for something.

**A flash message is written in one request and read in the next**, and on this
page the request that writes it is the one that can change the agent's language.
Translating at the point of writing would land a German page carrying an English
confirmation, or the reverse. So the catalogue **key** is what gets flashed and
the view translates it, which makes the ordering irrelevant instead of making
one ordering correct.

## What holds it together

Three guards, because a missed string is invisible until somebody switches
language:

- every catalogue must carry the same keys as English, since a missing key
  renders as `profile.alerts.mode` on the page and reads as a bug rather than
  as an untranslated string;
- an extracted view must contain no prose;
- German must actually render end to end, including the copy generated outside
  the view.

The third guard renders the page in **several states** rather than once. Copy
that only appears on a branch — a cadence nobody selected, a digest that has
already run, the flash after a save, the error under a field — is exactly the
copy an extraction misses, and a single default render reaches none of it. Every
miss found by review so far has been on a branch of this kind.

### The locale is scoped to extracted surfaces, not just the `lang` attribute

While this epic is half-finished, most pages are still English. Telling a screen
reader that an English page is German makes it pronounce English words with
German phonetics: a sighted agent never notices, and someone listening to the
page hears nothing else.

The first attempt marked the *document* — the locale switched everywhere and a
per-view flag decided what `lang` claimed. That was wrong, and the way it was
wrong is the useful part. A global locale leaks into an English page through
every seam that reads it: a model's option labels, a Carbon `diffForHumans()`, a
validation message, a shared support class. Each leak is a separate discovery
with a separate fix, and there is no end to the list — two review rounds found
three of them and there was no reason to think that was all.

So `DashboardLanguage::EXTRACTED_ROUTES` names the surfaces whose copy has been
through the extraction, and the middleware sets the locale from it: the agent's
own language on a surface that can speak it, English everywhere else. On a page
that has not been extracted there is then nothing German to be inconsistent
with, and `<html lang="en">` is simply true rather than a claim a second
mechanism has to keep honest.

The list exists for the length of the epic and deletes itself with it. Write
routes sit beside their page, because they render it back on a validation
failure.

**The shell is its own language.** The navigation, topbar and support-code
search live in the layout and are not extracted, so a document declaring itself
German would be lying about most of its own chrome — the same defect from the
other direction, and a screen reader would pronounce *Conversations* and *Sign
out* with German phonetics. The root `<html lang>` therefore states the
**shell's** language and `<main>` states the **page's**, which is what `lang` is
for. When the shell is extracted the root follows the locale and the attribute
on `<main>` stops being needed.

Two strings escape `<main>` and have to say so themselves: the document
`<title>`, and the topbar breadcrumb, which falls back to the page title on
surfaces with no rail item. Both are page copy standing in shell territory, and
without a `lang` of their own a screen reader pronounces them as English.

### Copy can be wrong without being English

`'„unbeantwortet\"'` in a single-quoted PHP string keeps its backslash — PHP
only honours `\\` and `\'` there — so a German agent read `unbeantwortet\"`
on the page.

No render comparison can see that. The string differs from its English original,
which is the only question such a test asks. Two catalogue guards cover it
instead: no value carries a backslash, and German copy uses `„…“` rather than
straight quotes, which is the same slip a translator makes by habit when the
surrounding code is English.

The general rule, and the one worth carrying to the surfaces still to come: when
a guard cannot reach a class of mistake, assert that class directly rather than
loosening the guard until it produces noise.

## Not doing

- Machine translation of conversation text. Useful, and it belongs with agent
  copilot work rather than here.
- Committing to a language count. The value is proving the seams hold; a second
  language does that and a tenth does not add to it.
- RTL layout. Worth assessing while the extraction is open, and no RTL language
  is offered yet.
