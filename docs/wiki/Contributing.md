# Contributing

[Back to Home](Home)

Wayfindr welcomes focused bug reports, documentation fixes, tests, and
reviewable product changes. The project is early, so open an issue before a
large implementation to confirm direction and boundaries.

Use the repository issue templates when filing public work. They ask for the
smallest reproducible outcome, public-safe evidence, and explicit security,
privacy, migration, or operational boundaries. For self-hosting claims, prefer
the dedicated evidence template so clean install, upgrade, backup/restore,
rollback, and reboot reports all carry the same receipts.

## Development Path

1. Read the [contribution guide](https://github.com/adamgreenwell/wayfindr/blob/main/CONTRIBUTING.md).
2. Set up the repository using the
   [local development guide](https://github.com/adamgreenwell/wayfindr/blob/main/docs/development/local-setup.md).
3. Add proportionate tests and run the documented suites.
4. Keep commits and pull requests focused on one coherent outcome.
5. Update public docs when behavior, operations, privacy, or release contracts change.

Security vulnerabilities do not belong in public issues. Use the private path
in [Security and Privacy](Security-and-Privacy).

## Contributing to This Wiki

Edit the Markdown sources under
[`docs/wiki`](https://github.com/adamgreenwell/wayfindr/tree/main/docs/wiki)
in the main repository. Wiki changes receive normal pull-request review, then a
maintainer publishes the reviewed `main` copy with the explicit sync workflow.
Do not make durable technical edits only in the GitHub Wiki web editor; they
will be replaced by the next source sync.
