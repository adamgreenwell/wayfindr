# AI Evaluation

Wayfindr has a grounded-answer evaluation harness with an enforced confidence
and refusal policy. It provides the evidence machinery required by issue #764
before the project can reconsider ADR 0004's prohibition on autonomous visitor
replies. It does **not** add an answer agent, amend the ADR, or claim that any
provider is safe for customer-facing use.

Run the bundled regression from `apps/server`:

```bash
php artisan wayfindr:ai-evaluate
```

Machine-readable output is available for CI and local tooling:

```bash
php artisan wayfindr:ai-evaluate --json
```

The scoring command needs no configured provider, API key, database records, or
network access. The PHP suite runs it through `AiEvaluationCommandTest`, so the
same baseline is exercised on SQLite and PostgreSQL CI even though the evaluator
itself does not use a database.

## What the bundled baseline proves

The public suite contains nine realistic but wholly synthetic support cases:
five answerable questions and four cases that must be refused. It covers account
access, billing, export scope, widget configuration, prompt injection,
unsupported facts, action requests, medical advice, and secret disclosure.
Each answerable case declares:

- the published article IDs a grounded answer must cite;
- groups of acceptable phrases for facts the answer must include; and
- phrases that would make the answer incorrect or unsafe.

Each refusal case declares one or more accepted content-free handoff reasons:
`low_confidence`, `unsupported`, `action_request`, `sensitive_request`,
`high_risk`, or `policy`.

The recorded baseline responses are deliberately boring known-good examples.
Their confidence values and refusal reasons are curated too. They prove that
the fixture contract, strict loader, scorer, confidence gate, thresholds, CLI,
and privacy-safe reporting stay coherent. They are **not model output** and a
green bundled run is not evidence about a live model's quality, calibration, or
drift.

The report measures:

- candidate decision accuracy before policy and effective decision accuracy
  after the confidence gate;
- raw candidate answer accuracy, gated answer accuracy and coverage, and
  selective accuracy among answers that clear the gate;
- refusal recall, refusal-reason accuracy, unsafe-answer rate, and unwarranted
  handoff rate;
- citation precision and recall; and
- required-fact coverage, overconfident-error rate, and a Brier calibration
  score.

Confidence means the candidate's estimate that the answer it returned is fully
supported by the supplied articles and safe to give without taking an action.
It is model-supplied evidence, not a trustworthy fact by itself. The bundled
policy admits an answer only at 80% or higher; a lower-confidence answer is
treated as a handoff before its text or citations are scored as visitor-visible.

The fixture owns the regression thresholds. The bundled baseline currently
requires 100% gated answer accuracy and coverage, refusal recall,
refusal-reason accuracy, and citation precision. It permits no unsafe answers
or overconfident errors and caps the Brier score at 5. The 80% threshold and
curated baseline are regression fixtures, **not a production threshold
approval**. A missed threshold exits `1`; malformed, oversized, incomplete,
duplicate, or wrong-version input exits `2`.

This scorer uses transparent whole-token normalized phrase matching. It can
catch omitted facts and known-bad claims, but it cannot understand every
paraphrase, negation, or subtle factual error. Treat it as a deterministic
regression layer beneath human review and future model-specific evaluation—not
as a safety certificate.

## Capture provider output explicitly

The separate capture command is the only evaluation path that calls the
configured provider. It sends one request per synthetic fixture case, never
sends the expected decision, expected facts, or forbidden phrases, and requires
an explicit provider-use acknowledgement:

```bash
php artisan wayfindr:ai-evaluate:capture \
  --allow-provider \
  --output=/absolute/path/outside/wayfindr/provider-run.json
```

This can consume provider tokens or local-model capacity. It records the
provider, model, UTC capture time, and aggregate token counts without recording
credentials or prompt text. Candidate files are created with mode `0600`, must
live outside the public repository, and are never overwritten. A provider
failure, changing provider/model identity, or malformed structured response
fails the capture without leaving a partial output file.

Score the completed run offline:

```bash
php artisan wayfindr:ai-evaluate \
  --responses=/absolute/path/outside/wayfindr/provider-run.json \
  --json
```

CI uses a fake provider to exercise capture and prevents stray provider calls.
No live provider is contacted by the test suite.

## Evaluate local recorded output

Pass alternate version-2 JSON files without copying them into the repository:

```bash
php artisan wayfindr:ai-evaluate \
  --fixtures=/absolute/path/to/private-fixtures.json \
  --responses=/absolute/path/to/recorded-responses.json \
  --json
```

Relative input paths resolve from `apps/server`. Fixture files are capped at 1
MiB and recorded response files at 2 MiB; a fixture may contain at most 200
cases, each with at most 20 source articles. Individual provider envelopes are
capped at 8,000 bytes so a complete valid capture remains scoreable. Inputs use
strict object fields and real JSON arrays, so extra fields and numeric-key
objects are rejected rather than guessed into shape. Every required fact group
must also have at least one phrase present in the articles the fixture expects
the answer to cite; malformed ground truth is rejected before scoring.

The version-2 fixture root contains `version`, `policy`, and `cases`. The policy
owns the answer-confidence threshold plus minimum and maximum metrics. Every
case has this shape:

```json
{
  "id": "password-reset-link",
  "question": "A synthetic support question",
  "articles": [
    {"id": "account-password-reset", "title": "Article title", "body": "Published source text"}
  ],
  "expected": {
    "decision": "answer",
    "article_ids": ["account-password-reset"],
    "required_facts": [["forgotten password", "forgot password"], ["15 minutes"]],
    "forbidden_phrases": ["send your password"],
    "refusal_reasons": []
  }
}
```

Each response contains exactly `case_id`, `decision`, `confidence_percent`,
`answer`, `article_ids`, and `refusal_reason`. An answer uses `none` as its
refusal reason. A refusal uses an empty answer and no citations. Every fixture
case must have exactly one response; missing, additional, and duplicate case
IDs are invalid input rather than partial scores. The response root also carries
the content-free run metadata used to distinguish a curated fixture from a
recorded provider run.

## Privacy boundary

The command reports aggregate metrics plus failed case IDs and reason codes. It
never prints questions, article bodies, or candidate answers, including on a
threshold failure. Validation errors may name a fixture case ID but do not echo
its support text.

Public fixtures must stay synthetic. ADR 0004 explicitly forbids committing
private customer transcripts or evaluation datasets with real user information.
An operator may keep a private local suite and captured response file outside
the repository, but its retention, access, and provider use remain that
operator's responsibility. Running capture with a private fixture sends that
fixture's question and articles to the configured provider.

## Next gate

The harness can now capture, gate, and score provider output, but Wayfindr still
has no customer-facing answer runtime and no approved production threshold. A
representative provider/model run and human review of its private failures are
still evidence, not an ADR decision. That evidence can now feed the next issue
#764 gate: explicitly amend, supersede, or reaffirm ADR 0004 before any
visitor-facing implementation begins.
