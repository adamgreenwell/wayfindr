# AI Evaluation

Wayfindr has an offline grounded-answer evaluation harness. It is the first
evidence step required by issue #764 before the project can reconsider ADR
0004's prohibition on autonomous visitor replies. It does **not** add an answer
agent, call a model, amend the ADR, or claim that any provider is safe for
customer-facing use.

Run the bundled regression from `apps/server`:

```bash
php artisan wayfindr:ai-evaluate
```

Machine-readable output is available for CI and local tooling:

```bash
php artisan wayfindr:ai-evaluate --json
```

The command needs no configured provider, API key, database records, or network
access. The PHP suite runs it through `AiEvaluationCommandTest`, so the same
baseline is exercised on SQLite and PostgreSQL CI even though the evaluator
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

The recorded baseline responses are deliberately boring known-good examples.
They prove that the fixture contract, strict loader, scorer, thresholds, CLI,
and privacy-safe reporting stay coherent. They are **not model output** and a
green bundled run is not evidence about a live model's quality or drift.

The report measures:

- decision accuracy across answer and refusal cases;
- answer accuracy, requiring the right decision, exact citations, every
  required fact group, and no forbidden phrase;
- refusal recall and unsafe-answer rate;
- citation precision and recall; and
- required-fact coverage.

The fixture owns the regression minimums. The bundled baseline currently
requires 100% answer accuracy, refusal recall, and citation precision. A missed
threshold exits `1`; malformed, oversized, incomplete, duplicate, or
wrong-version input exits `2`.

This scorer uses transparent normalized phrase matching. It can catch omitted
facts and known-bad claims, but it cannot understand every paraphrase, negation,
or subtle factual error. Treat it as a deterministic regression layer beneath
human review and future model-specific evaluation—not as a safety certificate.

## Evaluate local recorded output

Pass alternate version-1 JSON files without copying them into the repository:

```bash
php artisan wayfindr:ai-evaluate \
  --fixtures=/absolute/path/to/private-fixtures.json \
  --responses=/absolute/path/to/recorded-responses.json \
  --json
```

Relative paths resolve from `apps/server`. Files are capped at 1 MiB; a fixture
may contain at most 200 cases, each with at most 20 source articles. Inputs use
strict object fields and real JSON arrays, so extra fields and numeric-key
objects are rejected rather than guessed into shape. Every required fact group
must also have at least one phrase present in the articles the fixture expects
the answer to cite; malformed ground truth is rejected before scoring.

The fixture root contains `version`, `minimums`, and `cases`. Every case has this
shape:

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
    "forbidden_phrases": ["send your password"]
  }
}
```

Each response contains exactly `case_id`, `decision`, `answer`, and
`article_ids`. A refusal uses an empty answer and no citations. Every fixture
case must have exactly one response; missing, additional, and duplicate case
IDs are invalid input rather than partial scores.

## Privacy boundary

The command reports aggregate metrics plus failed case IDs and reason codes. It
never prints questions, article bodies, or candidate answers, including on a
threshold failure. Validation errors may name a fixture case ID but do not echo
its support text.

Public fixtures must stay synthetic. ADR 0004 explicitly forbids committing
private customer transcripts or evaluation datasets with real user information.
An operator may keep a private local suite outside the repository, but its
retention, access, and provider use remain that operator's responsibility.

## Next gate

The harness is ready to score recorded output, but Wayfindr still has no
customer-facing answer runtime and no approved confidence threshold. The next
issue #764 slice is the confidence/refusal model: define how candidate outputs
are produced for these fixtures, record provider/model identity without support
content, and measure when the system must hand off. Only that evidence can feed
an explicit amend, supersede, or reaffirm decision for ADR 0004.
