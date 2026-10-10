# Helper 0.6 candidate and legacy transaction recovery

This is an **unreleased development candidate**, tracked by
[#1140](https://github.com/adamgreenwell/wayfindr/issues/1140). It does not establish
publication, installed helper acceptance, application recovery or completed
managed-update qualification. Public v1.3.0 still contains helper 0.5.0. Follow
the [published helper guide](managed-updater.md#upgrading-the-installed-helper)
for that release. Do not install candidate code on a production host.

## Normal idle replacement

Candidate helper 0.6.0 keeps protocol 1 and supports only the complete, exact
five-module helper 0.4.0 from v1.2.0 or helper 0.5.0 from v1.3.0 as its original
generation. A reported version alone is insufficient. Modified, mixed and
unknown bundles refuse before importing installed code or changing the service.

The root-owned reviewed successor distribution's `inspect`, `upgrade` and
`recover` commands retain the existing explicit bundle-hash and transaction-UUID
requirements. Each transaction records its actual original file map; every
stage and its receipt preserve that source identity. Historical 0.5 executor
journals remain readable without rewriting their operations or the first
verified receipt generation. The source lifetime lock, enrollment, credentials,
retained recovery files, application containers and application data retain the
same preservation requirements.

All active or interrupted application operations still refuse normal helper
replacement, including the published 0.5 process-listing failure. This candidate
does not implement a recovery bridge for that failure. Keep the failed operation
and its original helper intact. Qualification on a fresh isolated host can
exercise the normal idle path while that bridge receives separate review.

## Finish an interrupted 0.4 to 0.5 helper transaction first

An existing helper transaction cannot change its destination. The successor CLI
returns `legacy_helper_recovery_required` for an exact retained 0.4 to 0.5
transaction, including a complete first-write scratch record. It changes no
code, journal, gate or transaction bytes and issues no service command. It does
not recover that transaction into 0.6.

The successor source distribution includes a frozen, byte-identical copy of the
reviewed corrected 0.5 CLI at
`scripts/self-host/upgrade-updater-0.5-recovery.py`. Its SHA-256 is:

```text
fe494f93552246677ee8a97c55a819ab748de4910bf300123dbb6afe408d4616
```

The five destination helper modules remain the exact **public v1.3.0** bytes,
commit `1bae329c4e349017716827dd0ce26b9ba7f9f2ac`, with this bundle SHA-256:

```text
e8523f24327188c36e6a352cd4366977afdb96b02f56d17760d7bd8063cada29
```

Before publication, review and independently verify that the immutable successor
source asset includes the frozen CLI with the stated digest. A moving branch or
an open pull request is not an operator delivery mechanism. The following is
the candidate recovery procedure after that delivery gate is satisfied:

1. Retain the original transaction UUID, private transaction/scratch bytes,
   stop gate, journal and retained original generation. Do not clear, edit,
   reenroll or replace any installed helper module.
2. Obtain and independently verify the public v1.3.0 source distribution into
   `/root/reviewed-wayfindr-v1.3.0`. Review the immutable successor distribution
   separately at `/root/reviewed-wayfindr-successor`. Both trees and every
   ancestor must be canonical, root-owned and not writable by other users.
3. Verify the frozen CLI digest, then copy **only the CLI** into the reviewed
   v1.3.0 staging tree under its standard filename. This changes the staging
   tree, not `/usr/local/lib/wayfindr-updater`:

   ```bash
   cd /root/reviewed-wayfindr-v1.3.0
   printf '%s  %s\n' \
     fe494f93552246677ee8a97c55a819ab748de4910bf300123dbb6afe408d4616 \
     /root/reviewed-wayfindr-successor/scripts/self-host/upgrade-updater-0.5-recovery.py \
     | sha256sum --check --strict
   install -m 0644 \
     /root/reviewed-wayfindr-successor/scripts/self-host/upgrade-updater-0.5-recovery.py \
     scripts/self-host/upgrade-updater.py
   /usr/bin/python3 scripts/self-host/upgrade-updater.py inspect
   ```

   Require `helper_version = 0.5.0`, `protocol = 1` and the exact `e852…` bundle
   digest above. The subsequent command independently requires that exact hash;
   changed helper modules cannot be accepted by substituting a new inspect hash.
4. From that v1.3.0 staging tree, recover the **original** transaction:

   ```bash
   /usr/bin/python3 scripts/self-host/upgrade-updater.py recover \
     --transaction=<original-transaction-uuid> \
     --expected-bundle-sha256=e8523f24327188c36e6a352cd4366977afdb96b02f56d17760d7bd8063cada29
   ```

   Require the original UUID, source 0.4.0, destination 0.5.0, exact `e852…`
   bundle, `preserved = true` and `application_changed = false` in the completed
   receipt. Retain that receipt and its original code directory. Authenticated
   status must report idle helper 0.5.0; an active application operation remains
   a refusal. If recovery refuses, preserve its evidence and stop this procedure.
5. Only after the original transaction completes, use the separate reviewed
   successor tree's `inspect` and `upgrade` with its distinct 0.6 bundle hash.
   This creates a new UUID and a separate 0.5 to 0.6 receipt. It does not replace
   or reinterpret the original receipt.

The frozen compatibility CLI accepts only its existing 0.4 to 0.5 transaction
rules; it cannot target 0.6. Its presence does not authorize application recovery
or removal of an existing hold. Candidate tests cover each durable legacy stage
and the complete same-transaction recovery followed by a separate successor
transaction using isolated synthetic filesystems. Linux native interruption
proofs, both architecture boundaries, publication verification and application
qualification remain separate gates.
