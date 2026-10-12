# Helper 0.10 candidate compatibility

Helper 0.10.0 is an unpublished source candidate. Public v1.4.3 contains
helper 0.9.0. Candidate tests, merge, publication, public readback and native
qualification are separate gates; this page provides no installation command.

The candidate verifies the serving source's configured origin before taking
maintenance ownership or stopping a writer. It uses the existing fresh
challenge and release/key-bound response proof, with normal host certificate
and hostname verification. Target protocol admission and source-origin proof
must both pass before protection begins. A refused or unsettled early probe
retains `recovery_required`, with no schema intent or newly owned hold. It does
not automatically fence the source or claim verified safe failure.

Explicit recovery of a durably pre-protection attempt repeats the source
origin check before any fallback that could fence it. A later operation that
already owns protection keeps its existing recovery procedure. Held and released
target-origin verification remain mandatory; the early source proof cannot
substitute for them. The candidate does not import a private CA or disable TLS
verification.

The [v1.4.2 → v1.4.3 native attempt](managed-update-qualification.md) remains
preserved in recovery. Read-only diagnostics established that the guest's system
trust store rejected the lab certificate, while the exact independently captured
lab CA returned a verified, correctly signed held v1.4.3 response. No recovery
or certificate installation was performed on that failed installation. See the
[lab trust prerequisite](managed-update-qualification.md#configured-origin-trust-before-start).

Ordinary root-only replacement admits the complete exact public helper bundles
0.4 through 0.9 on an independently verified idle installation. In particular,
public 0.9 is bound to v1.4.3 commit
`4376acbd271e88c329e612efecfba753531bb4a9`, bundle SHA-256
`a0609d7799e6f31b1eb8ca14cbdbd3aeb3801ab381507dfb5b5f5b8b0b0405da`,
and original CLI SHA-256
`e40832563998271f2cec09b7c280f82d91374a23310a7f81c6c2642a6d80c51f`.
Customized or mixed trees, incomplete transactions and active application
operations remain ineligible. Historical executor journals through 0.9 remain
readable; new operations identify executor 0.10.0.

Finish a pending helper transaction using its original destination delivery.
A pending 0.9 transaction requires the complete original public v1.4.3
distribution and CLI above. The successor cannot retarget that transaction,
replace an active helper, release an application hold or authorize recovery.
The separate active-operation bridge retains its original 0.5-to-0.6 scope.

Offline tests exercise early refusal, explicit recovery, exact helper provenance
and preservation. They do not establish a native 0.9-to-0.10 exchange, a complete
application upgrade, delivery or second-VM restore. Managed qualification
remains **0/100**.
