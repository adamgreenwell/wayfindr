# Helper 0.11 candidate

Helper 0.11.0 is published in application release v1.4.5. Guarded publication,
complete public source-archive readback and both architecture image identities
passed independent checks; see the [publication receipt](evidence/managed-update/2026-10-14-v1.4.5-publication.json).
Native installed-helper exchange and application-upgrade/restore qualification
remain pending. The helper requires a separate administrator upgrade; an
application image pull does not replace it. This page provides no installation command.

Docker health checks appear in a container's `ExecIDs` while their processes
run. Helper 0.10's protection guard refuses every nonempty list immediately.
Helper 0.11 waits up to ten seconds for all existing execs to settle,
using one monotonic deadline across polling and the final process check. It does
not exempt health checks or ignore unknown commands. Persistent execs, malformed
metadata, Docker errors, deadline expiry and hidden upgrade-window processes
still refuse protection. No writer is fenced until settlement passes.

The native helper 0.10 lab captured six ordinary health-check execs, each
matching its configured command and clearing afterward. Offline replay of the
unchanged published guard refused each observation. This demonstrates a normal
health-check refusal path. The historical exec from the failed application
upgrade was not retained, so this observation does not establish that attempt's
exact cause.

The ordinary root-only upgrade admits complete exact public helper bundles
0.4 through 0.10 on an independently verified idle installation. Public 0.10 is
bound to v1.4.4 commit `8ce37cb41cc2b0a94d3ca038ee0b29164ea539b4`, bundle SHA-256
`e20466d01154eade69846fa68928f9596fd0e0fd4fe8c5aa0ba67a11ab013132`
and original upgrade CLI SHA-256
`4ded4afcf37304d5e5fc54ecea4ae1977c09485824a4107845d7f94daea0c677`.
Historical executor journals through 0.10 remain readable without rewriting
their operations; new operations identify executor 0.11.0.

A pending original 0.10 helper transaction must finish with its original
v1.4.4 distribution and CLI. The successor validates that contract and reports
its immutable recovery pointers before accessing the host. It does not retarget
the transaction. Mixed or customized helper trees, active application
operations and held installations remain ineligible. Existing recovery
procedures and the separately scoped active-operation bridge keep their guards.

The self-host suite passes with 1,043 Python cases run, including one Linux-only
peer-credential case skipped on macOS, plus the shell contracts. Tests cover transient and
persistent execs, malformed and failed inspection, deadline exhaustion,
historical journals, idle 0.10 replacement and interrupted 0.10 transactions.
These source checks do not establish native helper 0.11 qualification.
Managed application qualification remains **0/100**.
