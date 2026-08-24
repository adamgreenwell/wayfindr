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

## Not doing

- Machine translation of conversation text. Useful, and it belongs with agent
  copilot work rather than here.
- Committing to a language count. The value is proving the seams hold; a second
  language does that and a tenth does not add to it.
- RTL layout. Worth assessing while the extraction is open, and no RTL language
  is offered yet.
