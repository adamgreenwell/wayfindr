# Helper 0.9 candidate compatibility

Application v1.4.3 and helper 0.9.0 are an unpublished source candidate. Public
v1.4.2 contains the original helper 0.8.0. Candidate review, exact-main CI,
publication, complete public-source readback and native qualification remain
separate gates. This page records the source contract, not an install command.

The candidate keeps protocol 1 and corrects Compose JSON dollar escaping in
runtime verification. Only the rendered Compose serialization layer is decoded;
Docker's inspected values and inherited image defaults stay literal. The
[public v1.4.1-to-v1.4.2 failure](managed-update-qualification.md) remains a failed
partial transition, with no completed runtime receipt or configuration promotion.

Ordinary root-only helper replacement admits only the complete exact five-module
public bundles for 0.4.0 (v1.2.0), 0.5.0 (v1.3.0), 0.6.0 (v1.4.0), 0.7.0
(v1.4.1) or 0.8.0 (v1.4.2), on an independently verified idle installation.
Reported versions, customized modules and mixed trees are insufficient.
Enrollment, authority, runtime, application data, lifetime locks, receipts and
retained code generations keep their existing preservation requirements.
Historical executor journals through 0.8 remain readable; new operations identify
executor 0.9.0.

Active, interrupted or held application operations still refuse ordinary helper
replacement. In particular, the failed public 0.8 operation is not eligible.
Installing corrected helper code does not authorize application recovery, replay
an ambiguous creation intent, clear a journal or release a protective hold.

## Finish pending transactions in their original distribution

The successor cannot retarget an interrupted helper transaction. Finish the
original UUID with its complete original destination distribution, then verify
the helper idle before a distinct successor transaction. Preserve the original
receipt, both code generations and failure evidence.

| Pending destination | Required original recovery delivery |
| --- | --- |
| 0.5.0 | Frozen `upgrade-updater-0.5-recovery.py` and exact public 0.5 modules; CLI SHA-256 `fe494f93552246677ee8a97c55a819ab748de4910bf300123dbb6afe408d4616` |
| 0.6.0 | Complete public v1.4.0 at `0f4312f8f7bfae7dae556ea547fccda2cf66f43e`; normal CLI SHA-256 `53e33e9361129201855964b2e7f696de42cc92ee1b85b287a91fc4b16ba8222f` |
| 0.7.0 | Complete public v1.4.1 at `fb16730f7fdc052dadaab1af372d0aa225f70d90`; normal CLI SHA-256 `d65072646eb9d7fa07c47f8921c64a00e0f6228ce9cb777d8b3b282c6211aff4` |
| 0.8.0 | Complete public v1.4.2 at `79ae1e96b267f0bac4ff4dc15bbc8380e394f999`; normal CLI SHA-256 `b1f5ab67e59d84ec1a4e39d9351683af423b75b5f733359c226a0a6ebd7745a7` |

The exact public 0.8 bundle SHA-256 is
`36d9cb8a15021ae78832b8b080a0c3707b9d1c1fca1a9142f6c714cbd1a1d92a`.
Its published `update_apply.py` SHA-256 remains
`a5bedb58d64f8680ab8474da4e62d5e55b7223ae9ab25f397e72708300765d52`.
The corrected candidate module cannot substitute for those historical bytes.
The 0.9 CLI identifies the original v1.4.2 delivery for a valid pending 0.8
transaction before host-service access or mutation. Normal 0.8 recovery uses the
original schema-1 helper transaction, not a schema-2 bridge or application apply.

The separate active-operation bridge remains limited to its original
pre-mutation 0.5 failure and the complete public v1.4.0 distribution with helper
0.6. It is unavailable from the 0.7, 0.8 and 0.9 distributions. No existing
application hold becomes eligible for this candidate.

## Verification limits

Offline lifecycle tests model manager/freezer/exchange behavior. Source tests
and saved-output replay cover the Compose correction, exact public 0.8
compatibility, interrupted transactions and preservation refusals. They do not
prove a native 0.8-to-0.9 helper exchange, a complete serving application upgrade,
protective archive delivery or ordinary second-VM restore. Fresh disposable VMs
and actual public artifacts are required for those later observations.
