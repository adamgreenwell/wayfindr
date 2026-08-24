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

## What holds it together

Three guards, because a missed string is invisible until somebody switches
language:

- every catalogue must carry the same keys as English, since a missing key
  renders as `profile.alerts.mode` on the page and reads as a bug rather than
  as an untranslated string;
- an extracted view must contain no prose;
- German must actually render end to end, including the copy generated outside
  the view.

## Not doing

- Machine translation of conversation text. Useful, and it belongs with agent
  copilot work rather than here.
- Committing to a language count. The value is proving the seams hold; a second
  language does that and a tenth does not add to it.
- RTL layout. Worth assessing while the extraction is open, and no RTL language
  is offered yet.
