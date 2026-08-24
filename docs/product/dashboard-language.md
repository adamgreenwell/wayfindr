# The language the dashboard speaks

Status: **in progress.** The plumbing is shipped and the agent profile page is
translated, with one recorded exception below. The remaining views are being
extracted surface by surface.

### The recorded exceptions

The profile page shows a mail-readiness sentence built by `OperatorReadiness`.
That class holds the **operator console's** vocabulary — around a thousand lines
of copy shared across that surface — so it extracts with the operator console
rather than from a page-shaped change reaching into it. Until then, an agent
reading the dashboard in German sees that one sentence in English.

**A recorded exception has to say it is one.** Both exceptions sit inside a page
region marked with the agent's language, so left unmarked a screen reader
pronounces the one deliberately untranslated sentence on the page with German
phonetics. Each carries its own `lang`, which means the readiness cards on the
profile page are not uniform: the translated ones follow the page and the mail
one declares English. An exception that assistive technology cannot see is not
an exception, it is a defect.

The **conversation queue** has the same shape of exception for the same reason:
`CobrowseConsentState` supplies the transport label on every row, and its
hundred-odd strings are shared with the conversation detail page. Until cobrowse
is extracted, a German agent reads that one cell in English.

Written down rather than left to be discovered, because the failure mode of an
extraction is precisely a page that looks finished and is not. Both exceptions
are named in their tests, and the queue's test **fails if its exemption stops
matching anything** — so an allowlist cannot outlive the thing it excuses and
quietly start covering real misses.

One hazard recorded ahead of that work: the queue view decides whether to show
cobrowse pressure by comparing the value against the English strings
`No drops reported` and `No recent drops reported`. That comparison breaks the
moment those are translated, and needs to move to a state key rather than prose.

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

**Shared vocabulary gets its own catalogue.** `presence.php` holds how recently
a visitor was seen, because the conversation queue names those states in a
filter *and* on every row, and the visitors directory names them again. Held per
surface they would drift the first time a translator improved one of them, and
the queue would show two different words for one state on the same screen.

### Models answer with state; surfaces render copy

The first version of the queue extraction put `__()` inside
`Conversation::attentionLabel()` and `Visitor::presenceLabel()`, and recorded
the consequence here as a cost worth paying: the visitors directory would render
those labels in German while the rest of that page stayed English.

It was not worth paying, and the reasoning was wrong in a way worth keeping.
**A model is read by every surface that touches it, so translating in one is
unscopeable by construction.** Those two methods reached the conversation detail
page and the visitors directory — documents that are not extracted and correctly
declare `<html lang="en">` — which is precisely the mixed-language problem the
per-surface flag exists to prevent, arriving through the model instead of through
the layout.

So a model answers with a **state** (`attentionState()`, `presenceState()`) and
each extracted surface translates that state at its own call site. The label
methods stay English until their last consumer is extracted, and then they go
away. A test asserts the unextracted page still reads English for a German
agent, which is the correct answer until somebody extracts it.

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

### Extraction does not edit copy — with one recorded exception



An extraction that quietly tidies wording is one nobody can trust to be
behaviour-preserving. Where extraction surfaces a copy problem — the profile
page turned out to call itself *Agent Profile* in the browser tab and *Agent
profile* in its heading — the string is preserved exactly, with the reason
recorded beside it, and the fix is left to be made on purpose.

**The exception, and the shape that earns one.** The conversation queue's
attention lane read *"2 need attention shown of 3 matching conversations"* — a
whole clause dropped into the slot the other lanes fill with a number. English
tolerates it; German does not, producing *"1 benötigt Aufmerksamkeit von 3
passenden Unterhaltungen angezeigt"*, and no catalogue can reorder a clause that
arrives pre-assembled. It now reads *"2 of 3 matching conversations need
attention"* in both languages.

So the rule is not "never change copy" but **never change copy for taste**. A
sentence whose *structure* cannot be translated has to change, because the
alternative is a sentence that is wrong in every language but the one it was
written in. The figures it reports are unchanged, and the test that pinned the
old wording was updated with the reason beside it rather than loosened.

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

### Sentences are translated whole, never assembled

The queue used to build its summary as `'Showing '.$count.' after the '.$lane.'
support-lane filter.'` — one sentence to read, three fragments in English word
order. No other language is obliged to keep that order, and a translator handed
the fragments cannot move them. Composed sentences are single catalogue entries
with placeholders.

Counts go through `trans_choice`, **including the verb**. `1 needs attention`
against `3 need attention` is a plural rule; English inflects the verb for
number and German does not, so a label built as a noun plus a separately chosen
verb is correct in one language by luck. Several German plural forms are
deliberately identical on both sides of the `|`, which is the right translation
rather than a copy-paste slip.

**And the sentence AROUND a count agrees with it too.** Getting `:shown` to
choose between *1 conversation* and *3 conversations* is only half the job: the
verbs either side of it agree with the same number. German inflects both the
auxiliary and the relative-clause verb, so a correctly pluralised count still
produced *Es werden 1 Unterhaltung angezeigt, die … entsprechen* — two plural
verbs about one conversation. English had the same class of error where the
count was not the subject: *1 shown of 1 matching conversations*. Any sentence
that interpolates a count is itself a `trans_choice` on that count.

### What a two-language render comparison cannot see

The comparison — render twice, treat any sentence surviving the change as
untranslated — is the main net, and three things slip through it. Each one hid
real untranslated copy on the queue that only mutation testing found.

**Rows are one line of several fields.** `strip_tags` collapses a row into
`· Latest visitor message · Activity 2 minutes ago · <the message body>`, so a
line-level comparison judges copy and data together, and dropping any line
containing data throws away the copy beside it. The comparison splits on the
separator and judges each field alone.

**An interpolated value can be localised too.** `Opened 2 minutes ago` and
`Opened vor 2 Minuten` are not equal, so an untranslated `Opened` passes a
comparison test forever. Segments carrying a number are set aside and asserted
directly instead.

**Short copy is below the floor.** Every column header on the queue is shorter
than the length floor that keeps names and numbers out of the comparison.
Lowering it is not the fix; the headers are read from the header row itself and
compared position by position. Asserting merely that the German page *contains*
`Besucher` passes while the header still says `Visitor`, because the word also
appears in the search hint and in a lane label — a real mutation survived
exactly that.

The rule these share: when the general net cannot reach a class of copy, assert
that class directly rather than loosening the net until it produces noise.

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
