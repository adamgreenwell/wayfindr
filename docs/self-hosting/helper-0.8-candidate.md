# Helper 0.8 candidate compatibility

This page preserves the original helper 0.8 candidate contract. Application
v1.4.2 and helper 0.8.0 are now public at commit
`79ae1e96b267f0bac4ff4dc15bbc8380e394f999`. The separate administrator helper
exchange passed its actual-output audits; the subsequent application upgrade
failed with a backup-worker verification refusal. See the
[dated qualification record](managed-update-qualification.md) for its partial
runtime state and recovery boundaries. The next
[v1.4.3/helper 0.9 candidate](helper-0.9-candidate.md) remains unpublished.

The contract below retains its original source and verification limits; it is
not a command to recover the failed application operation.

The candidate keeps protocol 1. Ordinary root-only replacement admits only the
complete exact five-module public bundles for helper 0.4.0 (v1.2.0), 0.5.0
(v1.3.0), 0.6.0 (v1.4.0) or 0.7.0 (v1.4.1). A reported version, customized
module or mixed tree is insufficient. Enrollment, authority, application data,
runtime, lifetime locks and retained generations keep the existing preservation
requirements. Terminal historical executor journals through 0.7 remain readable;
new operations identify executor 0.8.0.

Every active, interrupted or held application operation still refuses ordinary
helper replacement. Installing corrected helper code does not authorize
recovery, replay an ambiguous intent or clear a protective hold. The v1.4.1
runtime-layout refusal and its retained evidence remain historical facts.

## Finish pending transactions in their original distribution

The new CLI cannot retarget an interrupted helper transaction. Finish the
original UUID with its complete original destination distribution, then verify
the helper idle before a distinct successor transaction. Keep the original
receipt, code generations and failure evidence.

| Pending destination | Required original recovery delivery |
| --- | --- |
| 0.5.0 | Frozen `upgrade-updater-0.5-recovery.py` and exact public 0.5 modules; CLI SHA-256 `fe494f93552246677ee8a97c55a819ab748de4910bf300123dbb6afe408d4616` |
| 0.6.0 | Complete public v1.4.0 at `0f4312f8f7bfae7dae556ea547fccda2cf66f43e`; normal CLI SHA-256 `53e33e9361129201855964b2e7f696de42cc92ee1b85b287a91fc4b16ba8222f` |
| 0.7.0 | Complete public v1.4.1 at `fb16730f7fdc052dadaab1af372d0aa225f70d90`; normal CLI SHA-256 `d65072646eb9d7fa07c47f8921c64a00e0f6228ce9cb777d8b3b282c6211aff4` |

The exact public 0.7 bundle SHA-256 is
`5846707c5c3628f5f755d16a4c582f55f83f64d88dc42bde1e131fae3cfa8185`.
Its published `update_apply.py` SHA-256 remains
`96f2cf802c6eb95831bb000663b57d9bcebc27eeebd143b973026e8426850c45`;
the corrected candidate file cannot substitute for those historical bytes.
The 0.8 CLI reports the exact original delivery when it finds a valid pending
0.7 transaction, before host-service access or any mutation.

The separate active-operation bridge remains limited to its original
pre-mutation 0.5 failure and the complete public v1.4.0 distribution with helper
0.6. It is unavailable from either the 0.7 or 0.8 distribution. No existing
application hold becomes eligible for this candidate.

## Verification limits

Offline lifecycle checks model manager/freezer/exchange behavior and cover idle
0.7 admission, interruption, same-transaction recovery, preserved terminal
history and active-operation refusals. These checks do not prove a native 0.7
to 0.8 exchange, serving application update, retained protective archive restore
or any completed managed qualification case. Fresh disposable VMs and actual
public artifacts are required for those later observations.
