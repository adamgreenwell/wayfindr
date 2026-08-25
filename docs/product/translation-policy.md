# The rules a translation follows

Status: **policy set, not yet enforced.** This document is written before the
pipeline that will apply it and before the shipped German is measured against
it, deliberately in that order. Read the other way round, whatever `lang/de`
happens to contain becomes the specification by accident.

It has two readers. One is a person reviewing a diff. The other is a machine:
the brief handed to whichever engine produces a first draft. Anything an engine
needs to be told belongs here rather than in a prompt somewhere, because a
prompt is not reviewable and this is.

The companion is [`resources/translation/glossary.php`](../../apps/server/resources/translation/glossary.php),
which holds the same policy in the parts a program can check.

## 1. Register: formal, and the trap that follows

**German is `Sie`. Italian is `Lei`. Every language that distinguishes gets the
formal address.**

The reasoning is the reasoning of ADR 0017 turned around. The widget is for a
visitor who chose nothing and arrived with a question; the dashboard is for an
agent at work, in a tool their employer bought, frequently in a European market
where the informal address from a vendor reads as presumption rather than
warmth. Wayfindr's English voice is plain and unfussy, and the mistake would be
to hear "plain" as "informal" and translate the register instead of the tone.
Plain German is `Sie`.

**The trap: register is a property of sentences, not of the catalogue.** A
button is not addressed to anyone — it names an action. German UI convention
puts that in the infinitive, and `Sie` never appears:

    'Send message'  ->  'Nachricht senden'     not  'Senden Sie eine Nachricht'
    'Refresh'       ->  'Aktualisieren'
    'Sign out'      ->  'Abmelden'

`Sie` belongs in the prose — hints, empty states, confirmations, the sentence
that explains what a filter did. Roughly 195 of 841 strings are twelve
characters or fewer and almost none of them should contain it. An engine told
only "use the formal address" will produce `Senden Sie eine Nachricht` on a
button, and it will look wrong to anyone who reads German.

Italian takes `Lei` by the same argument. The form Italian action labels take is
**an open question** and is not settled here; it gets answered when Italian is
actually on deck, by someone who can check it against shipped Italian software
rather than against a rule inferred from German.

## 2. What is never translated

Six categories, in rising order of how badly it breaks when they are.

**Placeholders.** `:count`, `:elapsed`, `:shown`, and twenty-two others, ~150
occurrences. These are tokens Laravel substitutes, not words. `:elapsed` and
`:shown` have plausible readings in both target languages, which is exactly why
a general-purpose engine will translate them. The pipeline protects them; the
policy is that a translated placeholder is a defect, never a style question.

**Widget tokens.** `{label}`, `{filename}`, `{code}` — the same rule in the
widget's own substitution syntax.

**Plural range syntax.** `{1}` and `[2,*]` in a `trans_choice` string are
Laravel grammar. See §5 — these strings are never sent whole.

**Format literals.** `WF-ABC123`, `Ticket #123`, `RTT`, `ID`, `URL`. A support
code shown to a German agent is the same string the visitor reads it from.

**The product name.** Already the rule in `lang/en/nav.php`: *a product name is
not copy.* It is absent from the English catalogue and stays absent.

**Copy an agent SENDS.** The rule from
[dashboard-language.md](dashboard-language.md), and the one no engine can infer:
a reply helper's *label* is chrome and translates, its *body* is a draft the
visitor receives and does not. Nothing about the string distinguishes them. The
catalogue must mark it, and the pipeline must honour the mark, because a bulk
pass over `lang/en/*.php` flattens the distinction in silence and the failure
mode is a German-speaking agent sending German to an English visitor.

**Ratified cognates** are a seventh, smaller case: `Agent`, `Cobrowse`, `Name`
and the autonyms already appear identically in both catalogues on purpose, and
the render-comparison test carries a list that fails when an entry stops being
used. Additions to that list are glossary changes, not translation choices.

## 3. The glossary binds every string

One English term, one target term, everywhere. Eight hundred strings translated
independently will drift — `Unterhaltung` here, `Konversation` there — and the
drift is invisible in a diff read file by file. It is only visible against a
term table, which is why the term table is the artifact.

Five collisions matter more than the rest, because each is a pair English keeps
apart and a careless translation merges:

| These are different | and must stay different |
| --- | --- |
| **site** / **page** | `Website` / `Seite`. German `Seite` means *page*. Translating *site* as `Seite` merges the two concepts the sites list exists to separate. |
| **alert** / **message** / **notification** | An alert is a thing the product raises; a message is a thing a person sent. |
| **agent** / **operator** | An agent answers conversations. An operator runs the install. The break-glass and operator-settings surfaces depend on the distinction. |
| **open** (state) / **open** (action) | `Offen` / `Öffnen`. English spells them the same; German does not, and `'open' => 'Open'` appears three times in the catalogue. |
| **presence** / **status** | `Präsenzstatus` / `Status`. `conversations.php` puts these in adjacent columns and `tickets.php` names a Status field; bare `Status` for both would show one screen two columns with one name. |

## 4. A short string carries its context or it gets guessed

One hundred and ninety-five strings are twelve characters or fewer: chips,
badges, filter labels, buttons. They are the highest-risk part of the corpus,
because a fragment has no grammar to recover meaning from and the key is often
the only signal.

`'read' => 'Read'`, `'copy' => 'Copy'`, `'focus' => 'Focus'`, `'work' => 'Work'`
— each is a different part of speech in the target language depending on
something not present in the string.

**The rule: where a string's part of speech is not recoverable from its key, the
catalogue gets a note saying which it is.** That note is a comment in the
English file, it travels to the engine as context, and it is the cheapest fix
available — an English-language comment written once, by whoever knows the
answer, instead of a per-language guess made repeatedly.

**A concept can need two terms, split by density.** This has now come up three
times and is a rule rather than a series of accidents. *Visitor* takes
`Besuchende` in a sentence and `Besucher` in a badge. *Needs attention* takes
`Erfordert Aufmerksamkeit` as a status string and `Handlungsbedarf` as a column
header, because the full phrase does not fit and German software would not use
it there anyway. *Open* takes `Offen` or `Öffnen` depending on whether it names
a state or an action.

English hides all three, because English fragments happen to be spelled like
English sentences. German does not hide them. So where the split exists, the
glossary carries **both terms under distinct keys** — never one term with a note
about when to vary it, which is a rule nothing can check and everything ignores.
Expect Italian to need its own splits in different places.

This is the point at which these catalogues are already unusually well placed.
`lang/en/conversations.php` explains its own plural rule and why its sentences
are not concatenated from fragments; `lang/en/nav.php` explains why the product
name is absent. **Those docblocks are a translator brief and they are the single
strongest argument for the engine we choose** — a translation API whose entire
request body is `{ targetLanguage, texts }` has nowhere to put them.

## 5. Plurals are per-language and never mirrored

Thirty-three strings use the explicit-range form:

    '{1} 1 ticket matches|[2,*] :count tickets match'

Two rules. **Segments are translated individually and rejoined** — a whole
string sent to an engine comes back with the pipe as prose. And **the target
language decides how many segments it needs**, which is not always two: German
and Italian take two, Polish takes three, and a pipeline that assumes the
English shape will be wrong the first time a Slavic language is added.

The English catalogue already puts the verb inside the segment (`matches` /
`match`) rather than outside it, which is what makes this translatable at all.

## 6. Typography belongs to the language

German quotation marks are `„…“`, not `"…"`. This is already enforced by a
catalogue guard, alongside the rule that no value carries a backslash — a
single-quoted PHP string keeps `\"` verbatim, and a German agent once read
`unbeantwortet\"` on the page. No render comparison can see that class of
mistake, because the string does differ from its English original.

Both guards are policy, not incidental tests: **when a guard cannot reach a
class of mistake, assert the class directly.**

## 7. Length is reviewed against the rendering, not the diff

German runs twenty to thirty-five per cent longer than English, and the strings
with the least room are the same 195 short ones from §4. A term that reads
perfectly in a diff can still break a chip.

So a language is not done when its diff is clean. It is done when the surfaces
carrying its shortest strings have been looked at.

## 8. Gendered nouns in German

**Decided: neutral rewording by default, generic masculine as the fallback in
tight singular microcopy, and the colon form is not used.**

`Besucher` is ninety occurrences and the most frequent term in the catalogue, so
this is the entry with the widest blast radius in the table.

**The colon form is rejected on accessibility grounds first.** Screen readers
render `Besucher:innen` inconsistently across VoiceOver, NVDA and JAWS — a
pause, an explicit *"Besucher Doppelpunkt innen"*, or the suffix swallowed
entirely. This codebase has spent real effort on what gets announced, and a
form whose pronunciation depends on the reader's engine and settings undoes some
of it. That it also remains polarising in DACH B2B is the second reason, not the
first.

**The default is the participial form**, `Besuchende` — screen-reader clean,
grammatically standard, inclusive without forcing punctuation into a label. It
carries counts, tables and lists: `Anzahl Besuchende`.

**The fallback is `Besucher`**, for dense singular microcopy where
`Besuchende(r)` turns clumsy: a filter option, a badge, a row label. The
participle strictly means *people currently in the act of visiting*, which is
comfortable in the plural and stiff in the singular — and the singular is where
most of these ninety occurrences live.

Where a sentence can name the visit instead of the person, it should:
`Besuch`, or `Zugriff` for access-shaped phrasing. That avoids the choice
entirely and is usually the better German.

**This rule is held by the reviewer, not by a guard, and the policy says so
rather than implying otherwise.** "Where syntactically smooth" is a judgement
made per string; no test can evaluate it, and an engine handed one term for
*visitor* will use that term ninety times. So the glossary carries three
entries — `visitor_plural`, `visitor_singular`, `visit_abstract` — which forces
each string to pick one deliberately instead of inheriting a default. It is the
same shape as the interpolated-fragment case in
[dashboard-language.md](dashboard-language.md): where a guard cannot reach a
class of mistake and asserting the class directly is not possible either, the
rule is written down and the review carries it.

## 9. What "reviewed" means, and where it stops

The reviewer reads the target language without speaking it. That is the right
competence for this job and the wrong one for authoring, which is the whole
shape of the pipeline: **it optimises for a reviewable diff over a better first
draft.**

The checklist, in order of what actually catches things:

1. The glossary term table, before any strings. Thirty-five terms decide eight
   hundred strings; nothing else on this list has that leverage.
2. The short strings — part of speech, and length against the rendered surface.
3. The prose strings — register, and whether `Sie` appears where it should and
   nowhere it should not.
4. Placeholders and plural segments survived. Mechanical; the pipeline asserts
   it and the reviewer should not be spending attention here.

**A language ships only if somebody can read it.** Murf offers Japanese, Korean
and Polish; there is no review capability for any of them, and a machine
translation nobody has read is not a language pack, it is a liability with a
flag next to it. Those need a native reviewer before release, or they wait.

## Not doing

- **A term table for Italian.** Italian gets one when Italian starts. Building
  it now doubles the review burden before German is settled, and German is the
  language that proves the seams.
- **Committing to a language count.** Unchanged from
  [dashboard-language.md](dashboard-language.md): the value is proving the seams
  hold, and a second language does that.
- **Translating conversation content.** Still agent-copilot work, still not this.
