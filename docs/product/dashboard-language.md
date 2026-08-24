# The language the dashboard speaks

Status: **in progress.** The plumbing is shipped and the agent profile page is
translated, with one recorded exception below. The remaining views are being
extracted surface by surface.

### Copy an agent reads, and copy an agent SENDS

The dashboard language is a preference about what **the agent reads**. It must
never decide what **a visitor receives**.

A reply helper is the case that makes this concrete: its *label* is chrome — it
names the helper to the agent choosing it — while its *body* is a draft message
to the visitor, dropped into the composer and sent. Translating the body would
mean a German-speaking agent sending German to an English visitor without ever
choosing to. The visitor's language belongs to the widget (ADR 0017) and has
nothing to do with this setting.

So the label is translated, the body is not, and the body carries `lang="en"`
because it genuinely is English sitting in a German page.

**The same rule governs realtime payloads.** One broadcast reaches every agent
watching a conversation, and they do not all read the same language — so a
payload carries `state` and `detail_key`, never prose, and each page renders
those into its own agent's words.

### The recorded exceptions

The conversation **detail** page carries the largest one. Its cobrowse panel is
supplied by the `CobrowseConsentState` family — nine support classes and roughly
a hundred and thirty strings, shared with the widget and the operator console —
so the panel declares `lang="en"` and that vocabulary extracts as its own
change. The headings *around* it are this page's and are translated, the same
split the queue's cobrowse cell uses.

Two mechanisms exist so an exception can say so: `x-tab-panel` merges
`$attributes`, and a tab may pass `badge_lang` for a badge that sits outside its
own panel. Without them the attribute was silently dropped.


The profile page shows a mail-readiness sentence built by `OperatorReadiness`.
That class holds the **operator console's** vocabulary — around a thousand lines
of copy shared across that surface — so it extracts with the operator console
rather than from a page-shaped change reaching into it. Until then, an agent
reading the dashboard in German sees that one sentence in English.

**A recorded exception has to say it is one, down to the value.** Both exceptions sit inside a page
region marked with the agent's language, so left unmarked a screen reader
pronounces the one deliberately untranslated sentence on the page with German
phonetics. Each carries its own `lang`, which means the readiness cards on the
profile page are not uniform: the translated ones follow the page and the mail
one declares English. An exception that assistive technology cannot see is not
an exception, it is a defect.

The queue's cobrowse cell is the awkward case, and worth knowing about before
the next one: its label, message and guidance are wholly English, so the element
carrying them is marked — but `Last report …` and `Pressure …` are **mixed**, a
translated label wrapping an untranslated value in one sentence whose word order
the catalogue owns. Splitting the sentence to wrap the value is exactly the
fragment concatenation this extraction refuses, so the marked value is passed in
as the placeholder and only our own catalogue string renders unescaped around
it. The value is escaped on the way in, and there is a test that says so rather
than a comment.

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

### A shared view may translate; a shared model may not

The two look like the same case and are not. **A view is only ever rendered
inside a request**, and the locale is scoped per request to surfaces that have
been extracted — so a shared Blade component can use the catalogue directly and
will render German on the conversation queue and English on the conversation
detail page beside it, for the same agent in the same session. That is exactly
right while the extraction is half done, and it is why
`support-code-reference` is translated rather than deferred.

**A model can be reached where no request exists** — a queued job, a console
command, a mail build — and there the locale is whatever the process last set,
scoped to nothing. That is the reason models hand out state.

### A guard's catalogue list and state list both rot

Two mutations survived the raw-key guard on the ticket queue for reasons that
have nothing to do with the guard's logic: its catalogue list still named only
the catalogues that existed when it was written, so a raw `tickets.row.…` key
was invisible to it; and its state list only opened conversation pages, so it
never rendered the ticket queue at all.

Both are maintenance debts a guard accrues silently. The leak guard avoids the
second by asserting that **every GET-able route in `EXTRACTED_ROUTES` is
audited** — it fails when a surface is extracted without being added, which is
exactly how the ticket queue got its states. The catalogue list has no such
check yet and is worth one when a fifth catalogue lands.

### An element cannot go inside an attribute

A bulk edit that wraps every `__()` on a page in a language marker will break
one of them, because one of them is always in a `title`:

```blade
title="<x-lang>{{ __('...') }}</x-lang>"   {{-- the span's quote closes `title` --}}
```

Every attribute after it is then parsed as fallback text. On the cobrowse
`<iframe>` that meant `sandbox` and `srcdoc` stopped being attributes at all:
the preview rendered blank and the realtime script could not find the frame. It
looks like a formatting change and it is a functional outage.

**An attribute takes its language from its element**, so the marker goes on the
element and the attribute stays a plain translated string. Guarded by
`no language marker is rendered inside an attribute`, which scans every Blade
file for element markup inside a quoted attribute value.

### A value and its language travel together or not at all

`formatResyncRequest()` has five branches, each hand-listing its keys. Three
received the language field and two did not — so a German `diffForHumans()`
value was announced as English on exactly the states a *pending* resync
produces, which are the common ones.

Fixing the two branches would have left the sixth branch to come. The pair is
built together instead:

```php
...$this->momentPair('requested_at', $requestedAt, 'Request time unavailable'),
```

A machine timestamp is exempt and the guard knows it: `retry_at` is `toJSON()`
for a `data-` attribute the script parses, not prose anyone hears.

### Marking a half-extracted surface: state the majority, reset the minority

A surface part-way through extraction has translated chrome around untranslated
values, and assistive technology has to be told which is which. There are two
ways to do it and only one of them is safe.

Marking each untranslated **value** looks tidier and is wrong: it is complete
only if you find every English fragment, and you will not. The cobrowse panel
had thirty-five English values, and marking all of them still left bare chrome
words — `Received`, `Expires`, `Expired` — sitting in the markup between a label
and its timestamp, announced as German the moment the panel-level marker came
off.

So the region **states its own majority language**, and every translated
fragment inside resets to the document's:

```blade
<x-tab-panel id="cobrowse" lang="en">
    <h2><x-lang>{{ __('conversations.detail.tabs.cobrowse') }}</x-lang></h2>
    <p>{{ $cobrowseConsent['message'] }}</p>   {{-- English: no marker needed --}}
```

That is complete by construction: anything missed stays English, which it is.
The reverse approach fails silently in the direction that is hardest to see.

Both failures are real and they are mirror images — marking the whole panel
English made a screen reader pronounce the German headings with English rules;
taking the marker off left the English chrome announced as German. The tests
assert **both** directions, per text node with its effective language, because
an element-level check reads a nested reset as part of the text around it.

### Codex puts findings in two places, and one of them has no thread

Findings arrive as inline review comments **and** in the review body. Only the
inline ones become resolvable threads, so a "zero unresolved threads" check
reports a clean PR while a body-level finding sits unaddressed. One was found
by accident, while fixing the *verdict detection*, minutes before a merge.

Read both:

```bash
gh api repos/OWNER/REPO/pulls/N/reviews --paginate \
  --jq '.[] | select(.user.login=="chatgpt-codex-connector[bot]") | "\(.commit_id) \(.body)"'
```

The verdict lives on the **review object**, not in issue comments — `commit_id`
is the reliable field, and a clean verdict's body says `**Reviewed commit:**`
with no findings under it.

### The flash belongs to the destination, not the controller

A page that renders `__(session('status'))` makes every controller that can
redirect to it one of *its* surfaces. `AgentTicketController` flashes from the
ticket page and from the conversation page — `redirect()->back()` decides — so
its twelve status strings are keys now, and the ticket page translates them too.
That page is not extracted, so `__()` answers in the install default there:
English, correctly, and without a second code path.

The general rule: **any view that renders a flash should call `__()` on it.**
`__()` returns a non-key string unchanged, so it costs nothing on surfaces that
flash literals and prevents a raw key from ever reaching a page.

### Scoping a locale does nothing for a message built as a PHP string

`ValidationException::withMessages(['file' => 'This file type is not allowed.'])`
is English whatever locale is active. Route scoping only decides which
catalogue `__()` reads — it cannot reach a literal.

Two paths that reach an extracted surface were full of them: the attachment
upload service, which the German composer posts to, and the linked-ticket
actions, which redirect back to the German panel. Twelve messages between them,
and Codex found one — the guard found the other eleven.

Guarded by `nothing on an extracted path throws a literal validation message`,
which reads every `withMessages([...])` block in those files and fails on any
prose literal.

### Content that is stored is not content that is rendered

The sharpest version of the rule this whole document circles.

A ticket's subject and description are written **once** and read by everyone:
other agents on other language settings, notification emails, the API, and
whatever external issue tracker the account has linked. Generating them in the
creating agent's language puts one person's dashboard preference into shared
data permanently, where nothing can translate it back — the agent who picked
German has decided what an English colleague reads next year.

`DashboardLanguage::forStoredContent()` names the distinction: the **install's**
own language, which is what every unextracted surface already renders and does
not change with whoever pressed the button.

The test to reach for: create the record as a German agent, and assert the
stored value is the install's language *and specifically not* the German. The
second half matters — without it the assertion passes on an English-only
install, which is every install today.

### A write answers in the language of the page it renders back to

Listing a write route beside its own page works only while the endpoint serves
one surface. A linked-ticket action serves two: the same
`AgentTicketController::close()` is submitted from the ticket page and from the
conversation panel, and its **validation runs before the redirect**. Listing it
would answer in German on the English ticket page; not listing it put English
errors on the German conversation panel. Neither is a locale the endpoint can
have.

So for an unsafe request the locale is resolved from the **referer's** route —
the surface that will render the answer. Same-origin only, and reads are
excluded because a GET renders itself. The referer only ever picks a language,
so a wrong or forged one costs nothing.

### The conversation is not the dashboard

The transcript is the page's primary content and has nothing to do with the
language the agent reads the *chrome* in. A visitor writes in whatever they came
in with; an agent replies in whatever they chose to reply in. Letting message
bodies inherit `lang="de"` from the document has a screen reader pronounce an
English conversation with German rules — on the one part of the page people
actually read.

`lang=""` again, for the same reason it is right on a managed reply template.

### A missing formatter is not missing data

`Intl.RelativeTimeFormat` is absent in some embedded webviews that support
WebSockets perfectly well. Treating that as "no timestamp" replaced a real
*"seen 2 minutes ago"* with *"no visitor heartbeat yet"* — a different fact, not
a degraded one, on every event.

`fillElapsed()` returns **null** when it cannot produce a value, distinct from
the fallback it returns when the data really is missing, and callers route
through a writer that skips a null and leaves the server-rendered text alone.

### The language of a value nobody owns is `lang=""`

A reply template's body is not chrome and not the dashboard's copy. A built-in
is English, and says so. A **managed** template is written by the account in
whatever language it works in, and neither English nor the agent's language is
a defensible guess — HTML has an answer for this and it is `lang=""`, meaning
unknown. The picker carries it too, because selecting a template rewrites the
textarea and the draft stops being the agent's language at that moment.

Guessing here is worse than admitting: a screen reader acts on the claim.

### An endpoint the page calls is part of the page

`EXTRACTED_ROUTES` scopes the locale per route, so an endpoint the page posts to
answers in the install default unless it is listed. The transcript endpoint was
caught early; the **attachment** endpoint was not, and the composer prefers the
response's own `message` over its local fallback — so an oversized file put
English into a German page on an ordinary upload.

When a surface is extracted, list every endpoint it calls, not just the ones
that render markup.

### Copy wrapped around data is invisible to the leak guard

The detail page's `<title>` stayed `Conversation WF-…` through five rounds of
auditing. The leak guard skips any sentence containing a data token — the
support code, the account name, an email — because that token renders
identically in both languages and would otherwise be reported as a leak. A
sentence *built around* one is discarded with it.

The row-copy test exists for the same reason on the queue. The page title needed
its own, because it is the tab and the first thing a screen reader announces,
and it is the one string a page never renders in its body.

### A guard is only as good as the states it visits

Said once already about the support-lookup empty state, and true again for the
opposite reason. The leak audit's fixture creates tickets and messages, so the
**ticket-creation form and the empty transcript never rendered** — and mutations
of the category options, both guidance components and the empty transcript all
survived every state it visited.

The audit now also walks a conversation that has neither, and asserts that it
has neither before trusting the result. First-run states are not an edge case
here: they are the only states in which half this page's copy exists.

Four gaps of this shape have been found in this file so far, and the pattern is
always the same — **a branch the fixture cannot reach is a branch nothing
guards**:

| what was invisible | because the fixture had |
|---|---|
| ticket-creation choices | tickets already |
| the empty transcript | messages already |
| the transcript sender roles | *no* messages |
| the `Unknown visitor` fallback | a visitor with an id |
| the recovery timeline | no resync request |
| the ignored-response branch | no ignored responses |

Every one of them was a real finding first and a fixture change second. When a
guard passes, ask what it rendered — not just what it asserted.

Some of this cannot be reached by rendering at all, and is checked at the
source instead: **`every catalogue file answers the same set of keys`** compares
`lang/en` against `lang/de` for every file, so a key added to one language and
not the other fails immediately rather than waiting for a state to render it.
It also lists every German string identical to its English — a real cognate, or
a missed translation — and each one has to be named deliberately. That list is
the shortlist for the native-speaker pass.

Copy inside a `<script>` is invisible to all of this: the announcement walker
strips scripts before it looks at anything. The reply composer and the realtime
handlers are checked at the source instead, against the rule that every word
they write comes from the catalogue.

### `diffForHumans()` follows the page locale, so a model must report the language

The trap under all of this. A field that looks English in the source can be
German at runtime:

```php
'ended_at' => $this->formatMoment($session->ended_at, 'Still active'),
```

With a timestamp that is `diffForHumans()` — *"vor 20 Sekunden"* for a German
agent. Without one it is the static English fallback. **Same field, two
languages, decided by data the view cannot see.** Marking it English mispronounces
the German; leaving it unmarked mispronounces the English.

The view must not guess by comparing the prose. The model reports it:

```php
private function momentLanguage(mixed $moment): string
{
    return ! $moment || ! method_exists($moment, 'diffForHumans')
        ? DashboardLanguage::FALLBACK
        : app()->getLocale();
}
```

### A broadcast carries timestamps, never durations

Same rule as labels, one step further. `visitorReadPayload()` used to send
`seen_label` — a duration formatted by `diffForHumans()` in whichever agent's
request happened to build the broadcast. Every other agent watching then read it
in a language they did not choose.

The payload carries the **timestamp**; the page formats it with
`Intl.RelativeTimeFormat` in the reading agent's language. Anything a broadcast
formats is frozen at the moment it is built, so it must not be prose.

### An unreplaced placeholder is the same bug wearing a translation

A sentence rendered without its parameters shows `:elapsed` or `:count` to the
agent. It is in the right language, it looks like copy, and it is nonsense — so
neither the comparison nor the raw-key check can see it. A mutation that pinned
a timing value to null rendered *"Wartet seit :elapsed auf Antwort"*, which
still contained every German word the assertions were looking for.

The guard collects placeholder names **from the catalogues**, so it cannot go
stale as sentences gain parameters.

### A raw key on the page is always a bug, and needs its own guard

A missing key renders as `conversations.row.something` — readable enough to pass
for copy in a screenshot and wrong to everybody. It gets a test because
substring assertions cannot see it: turning a key back into a translated
*string* makes the view look up `conversations.row.Letzte Besuchernachricht`,
which misses and renders the key — and the key **contains** the German the
assertion was looking for, so `toContain` passes on a broken page.

Match the shape of a key, not the catalogue name: an English sentence ending
"…for your profile." contains `profile.` and is perfectly good copy.

### Adding a key must never take the English answer away

A model gains a key and **keeps** its English label, because the surfaces that
have not been extracted still read the label. Setting `actor` to null and
supplying only `actor_key` blanked the lifecycle actor on the ticket detail page
— a page the change did not touch and no test in that PR opened.

The corollary is what belongs in a key at all: a real actor *name* is **data**,
returned as itself with no key; only the `Visitor` and `System` fallbacks are
copy. A key for a name would be a key for something no catalogue can hold.

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

**The shell was its own language, and briefly needed saying so.** While the
navigation, topbar and search were English, a document declaring itself German
would have been lying about most of its own chrome, so the root stated the
shell's language and `<main>` the page's.

**That has now collapsed, because the shell is extracted.** An extracted route
renders a uniformly German document and an unextracted one a uniformly English
document, so there is no mixed document left to describe: one `lang` on the
root, none on `<main>`, none on the title or breadcrumb. The route list decides
the whole page rather than only its main region.

Recorded exceptions still carry their own `lang` — they are English inside
German pages on purpose, and they say so.

The breadcrumb was the subtlest case while that split existed: **which**
language it is depended on where its label came from — a rail item's label was
shell copy and English, while the fallback to the page title was the agent's,
and the two only differed on a surface that was extracted *and* had a rail item.
Extracting the shell removed the distinction rather than solving it, which is
usually the better outcome.

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

**Case agrees too, and gender decides the ending.** Both queues interpolate a
count after `von`, which takes the dative — and after a bare numeral the
adjective takes *strong* endings, where the ending depends on gender. *Die
Unterhaltung* is feminine and takes `-er` (`von 1 passender Unterhaltung`); *das
Ticket* is neuter and takes `-em` (`von 1 passendem Ticket`). Two sentences that
look identical in English are not the same sentence in German, and a
word-for-word translation gives the nominative for both.

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

A fifth, from the ticket queue: **a filter chip's label is invisible to the
comparison** because the value it wraps differs between languages and carries
the whole string with it — `Kategorie: Fehler` against `Category: Bug` differs
whether or not `Category:` was translated. Same shape as the cobrowse
`Letzte Meldung` case.

And a sixth: **wrong-but-translated copy**. Pinning the ticket queue's heading
to one status still produces German, just the wrong German — `2 offen` while
showing closed tickets differs from `2 open` exactly as a correct translation
would. Only a direct assertion that the heading names its own status can see it.

A seventh, and the one that has cost the most: **a branch no fixture reaches is
not audited at all.** The ticket queue's conditional rows — reply visibility, an
escalation cue, a lifecycle note, an external sync attempt — each render only in
a state an ordinary fixture never produces, so their copy stayed English through
a whole review round with every guard green. The world now builds those states
explicitly, and where a branch needs one fixture per case (five lifecycle
actions) the **mapping** is asserted directly instead of five pages rendered.

An eighth, which is really the seventh sharpened: **a fall-through branch is
the one most likely to render and the least likely to be fixtured.** Three
branches build an external-attempt cue; I extracted the two named ones and left
the `default`, which every action that is not a create or a remove lands in.

And a matching test trap: **asserting `trans('some.key')` proves the key exists,
not that the code path uses it.** A mutation of the path survived that
assertion; asserting the rendered value caught it.

The rule these share: when the general net cannot reach a class of copy, assert
that class directly rather than loosening the net until it produces noise.

### The net that replaced all of that

Eight review findings on the queue were the same shape — copy reaching an
extracted page from somewhere that is not that page: a model, a shared
component, a support class, a nullable fallback, an attribute. Each was found
individually and none of them said where the next one was.

So the comparison is now **lang-aware and attribute-aware**, driven by
`DashboardLanguage::EXTRACTED_ROUTES`. It reads every text node *and* every
`title`, `aria-label`, `placeholder` and `alt`, resolves each string's effective
language the way a screen reader does (nearest ancestor carrying `lang`), and
flags anything announced as German that did not change when the language did.
A recorded exception that declares itself English is skipped because it *says*
it is English, not because it is on a list. Five of the seven leak shapes found
by review are caught by it; each is mutation-verified.

Two things it deliberately does not do. It ignores **cognates** — `Name`,
`Agent`, `Cobrowse`, and the autonyms — by exact string, with a companion test
that fails when an entry stops appearing, so the list cannot outlive what it
excuses. And it **cannot see an untranslated fragment interpolated into a
translated sentence**: `Letzte Meldung Not reported` differs from `Last report
Not reported`, so the sentence passes while the value inside it is English.
That one is held by the rule instead — interpolated untranslated values are
marked at the point of interpolation — and by a test that says so.

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
