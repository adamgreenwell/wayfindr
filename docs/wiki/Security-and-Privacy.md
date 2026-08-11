# Security and Privacy

[Back to Home](Home)

Wayfindr handles support conversations, visitor context, attachments, and
consented cobrowse data. Self-hosters control the infrastructure and are
responsible for lawful use, access, retention, backups, notices, and deletion
or export workflows.

## Report a Vulnerability

Do not publish exploit details in an issue or pull request. Follow the
[security policy](https://github.com/adamgreenwell/wayfindr/blob/main/SECURITY.md)
and use
[private vulnerability reporting](https://github.com/adamgreenwell/wayfindr/security/advisories/new).

## Before Real Traffic

- Use HTTPS and secure WebSocket routing.
- Keep application, database, Redis, host packages, and images patched.
- Store secrets outside Git and avoid placing them in logs or issue reports.
- Review account roles, site assignments, and platform-operator boundaries.
- Configure attachment storage and malware scanning deliberately.
- Set retention expectations and prove backup deletion behavior.
- Use synthetic data for tests and support reproductions.

The repository remains authoritative for
[data responsibility](https://github.com/adamgreenwell/wayfindr/blob/main/docs/privacy/data-responsibility.md),
the [data inventory](https://github.com/adamgreenwell/wayfindr/blob/main/docs/privacy/data-inventory.md),
and [cobrowse boundaries](https://github.com/adamgreenwell/wayfindr/blob/main/docs/privacy/cobrowse-data-boundaries.md).
