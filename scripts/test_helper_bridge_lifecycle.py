#!/usr/bin/env python3
"""Historical 0.5-to-0.6 bridge; modeled Linux manager/freezer/socket only."""
import contextlib
import copy
import importlib.util
import json
import os
import struct
import types
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).absolute().parents[1]


def module(path, name):
    spec = importlib.util.spec_from_file_location(name, path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


PURE = module(ROOT / "scripts/test_helper_recovery_bridge.py", "bridge_pure")
OLD = module(ROOT / "scripts/test_updater_upgrade.py", "existing_lifecycle_fixture")
B = PURE.BRIDGE
U = OLD.UPGRADE
SOURCE_AUTHENTICATE = B.authenticate_source
PUBLIC_IDENTITIES = json.loads((ROOT / "scripts/fixtures/helper-recovery-bridge-public-identities.json").read_bytes())


class Crash(Exception):
    pass


class BridgeLifecycleTests(unittest.TestCase):
    def qualification(self, host):
        root = host.state / "qualification"
        root.mkdir(mode=0o700)
        marker = {"schema": 2, "purpose": "disposable-update-qualification", "installation_id": host.installation_id,
                  "run_id": "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", "initial_boot_id": "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",
                  "vm_kind": "dedicated_vm", "created_at": "2026-01-01T00:00:00Z",
                  "source": copy.deepcopy(PUBLIC_IDENTITIES["release"]["source"]),
                  "target": copy.deepcopy(PUBLIC_IDENTITIES["release"]["target"]),
                  "helper": {"source": copy.deepcopy(PUBLIC_IDENTITIES["helper"]["source"]),
                             "selected": copy.deepcopy(PUBLIC_IDENTITIES["helper"]["selected"]),
                             "transaction_id": "cccccccc-cccc-4ccc-8ccc-cccccccccccc", "receipt_sha256": "d" * 64}}
        host.write(root / "disposable.json", json.dumps(marker).encode(), 0o600)
        return root, marker

    def assert_qualification_refused_before_transaction(self, change):
        with self.host() as host:
            root, marker = self.qualification(host)
            change(marker)
            host.write(root / "disposable.json", json.dumps(marker).encode(), 0o600)
            with self.assertRaisesRegex(U.UpgradeError, "bridge_qualification_unverified"):
                host.bridge()
            self.assertEqual(0, host.kill_count)
            self.assertEqual(0, host.exchange_count)
            self.assertFalse(U.TRANSACTION.exists())

    def test_unknown_nested_helper_proof_refuses_before_transaction(self):
        self.assert_qualification_refused_before_transaction(lambda marker: marker.update(helper={"unknown": True}))

    def test_substituted_public_source_image_refuses_before_transaction(self):
        self.assert_qualification_refused_before_transaction(lambda marker: marker["source"].update(image_digest="sha256:" + "f" * 64))

    def test_unknown_nested_release_field_refuses_before_transaction(self):
        self.assert_qualification_refused_before_transaction(lambda marker: marker["source"].update(unknown=True))

    def test_impossible_calendar_date_refuses_before_transaction(self):
        self.assert_qualification_refused_before_transaction(lambda marker: marker.update(created_at="2026-02-30T00:00:00Z"))

    def test_full_public_identity_fixture_has_no_private_attestation_facts(self):
        self.assertEqual({"release", "helper"}, set(PUBLIC_IDENTITIES))
        for kind, pins in (("release", B.QUALIFICATION_RELEASE_IDENTITIES), ("helper", B.QUALIFICATION_HELPER_IDENTITIES)):
            self.assertEqual(set(pins), set(PUBLIC_IDENTITIES[kind]))
            for role, checksum in pins.items():
                self.assertEqual(checksum, U.digest(U.encoded(PUBLIC_IDENTITIES[kind][role])))
        for role, version in (("source", "0.4.0"), ("selected", "0.5.0")):
            distribution = PUBLIC_IDENTITIES["helper"][role]
            self.assertEqual(U.PUBLISHED[version], distribution["files"])
            self.assertEqual(set(U.FILES), set(distribution["files"]))
            self.assertEqual(5, len(distribution["provenance_files"]))
            self.assertEqual(distribution["bundle_sha256"], U.digest(U.encoded({key: distribution[key] for key in ("schema", "helper_version", "protocol", "files")})))
        self.assertIsNone(PUBLIC_IDENTITIES["helper"]["source"]["upgrade_cli_sha256"])
        self.assertEqual("19084e3126c578f4b002a876d0a21fa5187b65f1a931fbf6278ab70db9b39cbf", PUBLIC_IDENTITIES["helper"]["selected"]["upgrade_cli_sha256"])
        raw = json.dumps(PUBLIC_IDENTITIES)
        for private_field in ("installation_id", "run_id", "transaction_id", "receipt_sha256", "initial_boot_id", "created_at"):
            self.assertNotIn('"' + private_field + '"', raw)

    def test_each_public_release_field_is_bound_to_the_reviewed_identity(self):
        for role in ("source", "target"):
            for field, original in PUBLIC_IDENTITIES["release"][role].items():
                with self.subTest(role=role, field=field):
                    def change(marker):
                        marker[role][field] = not original if type(original) is bool else None
                    self.assert_qualification_refused_before_transaction(change)

    def test_helper_distribution_full_provenance_and_historical_cli_are_bound(self):
        for role in ("source", "selected"):
            for field in PUBLIC_IDENTITIES["helper"][role]:
                with self.subTest(role=role, field=field):
                    def change(marker):
                        marker["helper"][role][field] = None if field != "upgrade_cli_sha256" else "f" * 64
                    self.assert_qualification_refused_before_transaction(change)
            for field in ("files", "provenance_files"):
                for name in PUBLIC_IDENTITIES["helper"][role][field]:
                    with self.subTest(role=role, field=field, name=name):
                        def change(marker):
                            marker["helper"][role][field][name] = "f" * 64
                            if field == "files":
                                distribution = marker["helper"][role]
                                distribution["bundle_sha256"] = U.digest(U.encoded({key: distribution[key] for key in ("schema", "helper_version", "protocol", "files")}))
                        self.assert_qualification_refused_before_transaction(change)

    def test_historical_attestation_dates_require_real_canonical_utc_values(self):
        for date in ("2026-13-01T00:00:00Z", "2026-01-01T24:00:00Z", "2026-01-01T00:00:60Z", "2026-1-1T00:00:00Z", "2026-01-01T00:00:00+00:00"):
            with self.subTest(date=date):
                self.assert_qualification_refused_before_transaction(lambda marker: marker.update(created_at=date))
        with self.host() as host:
            root, marker = self.qualification(host)
            marker["created_at"] = "2024-02-29T23:59:59Z"
            host.write(root / "disposable.json", json.dumps(marker).encode(), 0o600)
            self.assertTrue(host.bridge()["active_operation_preserved"])

    @contextlib.contextmanager
    def host(self, target_version="0.6.0"):
        # The bridge remains a historical 0.6 destination even when the normal
        # lifecycle CLI advances. Do not broaden product eligibility for it.
        with patch.object(U, "VERSION", target_version), tempfile.TemporaryDirectory(prefix="wayfindr-active-bridge-") as temporary:
            host = OLD.Host(Path(temporary).resolve(), "0.5.0")
            value = PURE.fixture()
            value["installation_id"] = host.installation_id
            host.write(host.state / "journal.json", json.dumps(value).encode(), 0o600)
            host.write(host.cgroup / "cgroup.procs", b"123\n", 0o644)
            host.dropin.parent.mkdir(parents=True, exist_ok=True)
            host.write(host.dropin, U.GATE, 0o644)
            baseline = {"context": {"schema": 1, "source": B.SOURCE}, "runtime_sha256": "c" * 64}
            with host.activated(), patch.object(B, "U", U), patch.object(B, "refuse", U.refuse), \
                    patch.object(B, "authenticate_source", lambda runtime, config, identity: host.commands.append(("source-authenticate", identity[0]))), \
                    patch.object(B, "readonly_baseline", return_value=baseline):
                host.operation_id = value["active_operation"]
                host.expected_sha = B.state_digest(value)
                host.bridge = lambda: B.finish(host.distribution, host.selected(), host.operation_id, host.expected_sha)
                def recover():
                    record = U.read_json(U.TRANSACTION)
                    return B.finish(host.distribution, host.selected(), host.operation_id, host.expected_sha, recovery=record["transaction_id"])
                host.bridge_recover = recover
                yield host

    def test_new_helper_destination_refuses_before_transaction(self):
        for version in ("0.7.0", "0.8.0"):
            with self.subTest(version=version), self.host(target_version=version) as host:
                with self.assertRaisesRegex(U.UpgradeError, "distribution_changed"):
                    host.bridge()
                self.assertEqual(0, host.exchange_count)
                self.assertEqual(0, host.kill_count)
                self.assertFalse(U.TRANSACTION.exists())

    def test_bridge_replaces_only_code_preserving_entire_active_journal_and_locks(self):
        with self.host() as host:
            before = (host.state / "journal.json").read_bytes()
            lock = host.identity(host.state / "helper.lock")
            result = host.bridge()
            after = U.read_json(host.state / "journal.json")
            self.assertEqual(B.journal_state(json.loads(before)), B.journal_state(after))
            self.assertEqual("0.5.0", after["operations"][host.operation_id]["executor_version"])
            self.assertEqual(host.operation_id, after["active_operation"])
            self.assertTrue(result["active_operation_preserved"])
            self.assertFalse(result["application_changed"])
            self.assertEqual(1, host.exchange_count)
            self.assertEqual(1, host.kill_count)
            self.assertEqual(lock, host.identity(host.state / "helper.lock"))
            retained = B.bridge_directory(result["transaction_id"])
            self.assertEqual(before, (retained / "journal-before.json").read_bytes())
            self.assertEqual(result, U.read_json(retained / "receipt.json"))
            self.assertEqual(host.old_hashes, U.code_hashes(Path(result["retained_old_code"])))
            self.assertFalse(U.TRANSACTION.exists())
            self.assertFalse(U.STOP.exists())
            self.assertEqual({retained}, set(U.RECEIPTS.iterdir()))

    def test_normal_upgrade_still_refuses_the_same_active_operation(self):
        with self.host() as host:
            with self.assertRaisesRegex(U.UpgradeError, "operation_busy"):
                host.upgrade()
            self.assertEqual(0, host.exchange_count)
            self.assertEqual(0, host.kill_count)
            self.assertFalse(U.TRANSACTION.exists())

    def test_runner_attestation_survives_bridge_and_interrupted_same_transaction_recovery(self):
        for interrupted in (False, True):
            with self.subTest(interrupted=interrupted), self.host() as host:
                root, marker = self.qualification(host)
                runtime = U.runtime_from(host.code / "updater.py")
                config = U.enrollment(runtime, host.distribution)
                original = B.qualification_state(config)
                observed = B.observe_state(runtime, config, host.operation_id, host.expected_sha)
                self.assertEqual(original, observed[3]["qualification"])
                if interrupted:
                    store = U.store
                    def crash(record):
                        store(record)
                        if record["stage"] == "switched": raise Crash("switched")
                    with patch.object(U, "store", crash), self.assertRaises(Crash): host.bridge()
                    self.assertEqual(original, U.read_json(U.TRANSACTION)["retained"]["qualification"])
                    result = host.bridge_recover()
                else:
                    result = host.bridge()
                self.assertTrue(result["active_operation_preserved"])
                self.assertEqual(original, B.qualification_state(config))
                self.assertEqual(marker, U.read_json(root / "disposable.json"))
                self.assertEqual("0.5.0", marker["helper"]["selected"]["helper_version"])
                self.assertEqual(1, host.exchange_count)
                self.assertFalse(U.TRANSACTION.exists())

    def test_unknown_partial_or_mismatched_runner_attestation_refuses_before_transaction(self):
        for selected in ("extra", "missing", "mode", "directory-mode", "symlink", "hardlink", "corrupt", "duplicate", "nonfinite", "nonobject", "schema", "purpose", "installation", "source", "target", "run", "boot", "helper", "extra-field"):
            with self.subTest(selected=selected), self.host() as host:
                root, marker = self.qualification(host)
                path = root / "disposable.json"
                if selected == "extra": host.write(root / "unknown", b"preserve", 0o600)
                elif selected == "missing": path.unlink()
                elif selected == "mode": path.chmod(0o644)
                elif selected == "directory-mode": root.chmod(0o755)
                elif selected == "symlink":
                    path.rename(root / "retained");path.symlink_to(root / "retained")
                elif selected == "hardlink": (host.root / "marker-link").hardlink_to(path)
                elif selected == "corrupt": host.write(path, b'{"schema":', 0o600)
                elif selected == "duplicate": host.write(path, b'{"schema":2,' + json.dumps(marker).encode()[1:], 0o600)
                elif selected == "nonfinite": host.write(path, json.dumps({**marker, "helper": {"value": float("nan")}}).encode(), 0o600)
                elif selected == "nonobject": host.write(path, b'[]', 0o600)
                else:
                    if selected == "schema": marker["schema"] = True
                    elif selected == "purpose": marker["purpose"] = "another-purpose"
                    elif selected == "installation": marker["installation_id"] = "changed"
                    elif selected == "source": marker["source"]["commit"] = "f" * 40
                    elif selected == "target": marker["target"] = {**marker["target"], "commit": "e" * 40}
                    elif selected == "run": marker["run_id"] = "changed"
                    elif selected == "boot": marker["initial_boot_id"] = "changed"
                    elif selected == "helper": marker["helper"] = None
                    elif selected == "extra-field": marker["unknown"] = True
                    host.write(path, json.dumps(marker).encode(), 0o600)
                with self.assertRaises(U.UpgradeError): host.bridge()
                self.assertEqual(0, host.kill_count)
                self.assertEqual(0, host.exchange_count)
                self.assertFalse(U.TRANSACTION.exists())

    def test_runner_attestation_root_owner_group_and_exact_modes_are_required(self):
        for node in ("directory", "marker"):
            for index, changed in ((2, 1000), (3, 1000), (4, 0o750)):
                with self.subTest(node=node, index=index), self.host() as host:
                    root, _ = self.qualification(host)
                    target = root if node == "directory" else root / "disposable.json"
                    identity = U.identity
                    def metadata(path, **options):
                        result = identity(path, **options)
                        if path == target: result[index] = changed
                        return result
                    with patch.object(U, "identity", metadata), self.assertRaises(U.UpgradeError): host.bridge()
                    self.assertFalse(U.TRANSACTION.exists())
                    self.assertEqual(0, host.kill_count)
                    self.assertEqual(0, host.exchange_count)

    def test_runner_attestation_change_at_frozen_boundary_retains_transaction_without_kill(self):
        with self.host() as host:
            root, marker = self.qualification(host)
            def race():
                marker["created_at"] = "2026-01-02T00:00:00Z"
                host.write(root / "disposable.json", json.dumps(marker).encode(), 0o600)
            host.race = race
            with self.assertRaisesRegex(U.UpgradeError, "bridge_state_changed"): host.bridge()
            self.assertEqual(0, host.kill_count)
            self.assertEqual(0, host.exchange_count)
            self.assertTrue(U.TRANSACTION.exists())

    def test_runner_attestation_drift_after_baseline_or_boot_and_during_recovery_refuses(self):
        for boundary in ("baseline", "boot", "recovery"):
            for change in ("bytes", "inode", "disappearance", "appearance"):
                with self.subTest(boundary=boundary, change=change), self.host() as host:
                    root, marker = self.qualification(host)
                    path = root / "disposable.json"
                    if change == "appearance": path.unlink();root.rmdir()
                    def drift():
                        if change == "bytes":
                            marker["helper"]["receipt_sha256"] = "f" * 64
                            host.write(path, json.dumps(marker).encode(), 0o600)
                        elif change == "inode":
                            raw = path.read_bytes();path.rename(host.root / "retained-marker")
                            host.write(path, raw, 0o600)
                        elif change == "disappearance": path.unlink();root.rmdir()
                        else: self.qualification(host)
                    if boundary == "baseline":
                        baseline = B.readonly_baseline
                        fired = False
                        def observe(*args):
                            nonlocal fired
                            value = baseline(*args)
                            if not fired: fired = True;drift()
                            return value
                        with patch.object(B, "readonly_baseline", observe), self.assertRaisesRegex(U.UpgradeError, "bridge_state_changed"): host.bridge()
                        self.assertFalse(U.TRANSACTION.exists())
                        self.assertEqual(0, host.kill_count)
                        self.assertEqual(0, host.exchange_count)
                    elif boundary == "boot":
                        run = U.run
                        def start(*args, **options):
                            value = run(*args, **options)
                            if args[0] == "start": drift()
                            return value
                        with patch.object(U, "run", start), self.assertRaisesRegex(U.UpgradeError, "bridge_state_changed"): host.bridge()
                        self.assertTrue(U.TRANSACTION.exists())
                        self.assertEqual(1, host.exchange_count)
                        self.assertFalse((B.bridge_directory(U.read_json(U.TRANSACTION)["transaction_id"]) / "receipt.json").exists())
                    else:
                        store = U.store
                        def crash(record):
                            store(record)
                            if record["stage"] == "prepared": raise Crash("prepared")
                        with patch.object(U, "store", crash), self.assertRaises(Crash): host.bridge()
                        drift()
                        with self.assertRaisesRegex(U.UpgradeError, "bridge_state_changed"): host.bridge_recover()
                        self.assertTrue(U.TRANSACTION.exists())
                        self.assertEqual(0, host.kill_count)
                        self.assertEqual(0, host.exchange_count)

    def test_earlier_candidate_record_requires_qualification_to_remain_absent(self):
        for added in (False, True):
            with self.subTest(added=added), self.host() as host:
                store = U.store
                def old_record(record):
                    if record["stage"] == "prepared":
                        record["retained"].pop("qualification", None)
                        store(record)
                        raise Crash("prepared")
                    store(record)
                with patch.object(U, "store", old_record), self.assertRaises(Crash): host.bridge()
                if added:
                    self.qualification(host)
                    with self.assertRaisesRegex(U.UpgradeError, "bridge_state_changed"): host.bridge_recover()
                    self.assertEqual(0, host.kill_count)
                    self.assertEqual(0, host.exchange_count)
                else:
                    self.assertTrue(host.bridge_recover()["active_operation_preserved"])

    def test_journal_worker_race_before_freeze_refuses_without_kill_or_exchange(self):
        with self.host() as host:
            def race():
                value = U.read_json(host.state / "journal.json")
                value["revision"] += 1
                host.write(host.state / "journal.json", json.dumps(value).encode(), 0o600)
            host.race = race
            with self.assertRaises(U.UpgradeError):
                host.bridge()
            self.assertEqual(0, host.kill_count)
            self.assertEqual(0, host.exchange_count)
            self.assertEqual(host.old_hashes, U.code_hashes(host.code))
            self.assertTrue(U.TRANSACTION.exists())

    def test_extra_helper_child_refuses_while_old_generation_remains_complete(self):
        with self.host() as host:
            host.write(host.cgroup / "cgroup.procs", b"123\n124\n", 0o644)
            with self.assertRaisesRegex(U.UpgradeError, "bridge_worker_unsettled"):
                host.bridge()
            self.assertEqual(0, host.kill_count)
            self.assertEqual(0, host.exchange_count)

    def test_every_durable_stage_can_resume_only_with_explicit_same_transaction(self):
        for stage in ("prepared", "frozen", "staging", "switched", "starting", "verified"):
            with self.subTest(stage=stage), self.host() as host:
                original_store = U.store
                fired = False
                def interrupt(record):
                    nonlocal fired
                    original_store(record)
                    if record["stage"] == stage and not fired:
                        fired = True
                        raise Crash(stage)
                with patch.object(U, "store", interrupt), self.assertRaises(Crash):
                    host.bridge()
                old_or_new = U.code_hashes(host.code)
                self.assertIn(old_or_new, (host.old_hashes, U.bundle(host.distribution)[0]["files"]))
                with self.assertRaises(U.UpgradeError):
                    host.bridge()
                result = host.bridge_recover()
                self.assertTrue(result["active_operation_preserved"])
                self.assertEqual(1, host.exchange_count)

    def test_successful_exchange_before_stage_update_never_exchanges_back(self):
        with self.host() as host:
            original_exchange = U.exchange
            def interrupt(left, right):
                original_exchange(left, right)
                raise Crash("after_exchange")
            with patch.object(U, "exchange", interrupt), self.assertRaises(Crash):
                host.bridge()
            self.assertEqual("staging", U.read_json(U.TRANSACTION)["stage"])
            self.assertEqual(U.bundle(host.distribution)[0]["files"], U.code_hashes(host.code))
            self.assertTrue(host.bridge_recover()["preserved"])
            self.assertEqual(1, host.exchange_count)

    def test_schema_one_normal_recovery_and_schema_two_bridge_never_adopt_each_other(self):
        with self.host() as host:
            original_run = U.run
            def interrupt(*args):
                if args[0] == "freeze":
                    raise Crash("before_freeze")
                return original_run(*args)
            with patch.object(U, "run", interrupt), self.assertRaises(Crash):
                host.bridge()
            record = U.read_json(U.TRANSACTION)
            with self.assertRaisesRegex(U.UpgradeError, "transaction_changed"):
                host.recover()
            changed = copy.deepcopy(record)
            changed["schema"] = 1
            with self.assertRaisesRegex(U.UpgradeError, "bridge_transaction_changed"):
                B.validate_record(changed, host.selected(), U.bundle(host.distribution)[0]["files"], host.operation_id, host.expected_sha)

    def test_completion_death_preserves_transaction_and_never_clears_application_ownership(self):
        for checkpoint in (1, 2):
            with self.subTest(checkpoint=checkpoint), self.host() as host:
                original = U.verify_completion
                count = 0
                def die(*args):
                    nonlocal count
                    count += 1
                    if count == checkpoint:
                        host.running = False
                    return original(*args)
                with patch.object(U, "verify_completion", die), self.assertRaises(U.UpgradeError):
                    host.bridge()
                self.assertTrue(U.TRANSACTION.exists())
                self.assertEqual(host.operation_id, U.read_json(host.state / "journal.json")["active_operation"])
                receipt = B.bridge_directory(U.read_json(U.TRANSACTION)["transaction_id"]) / "receipt.json"
                self.assertEqual(checkpoint == 2, receipt.exists())

    def test_unknown_installed_code_refuses_before_import_or_any_marker(self):
        with self.host() as host:
            (host.code / "updater.py").write_bytes(b"raise RuntimeError('unreviewed')\n")
            with patch.object(U, "runtime_from", side_effect=AssertionError("unknown code imported")), \
                    self.assertRaisesRegex(U.UpgradeError, "unsupported_installed_helper"):
                host.bridge()
            self.assertFalse(U.TRANSACTION.exists())

    def test_first_positive_process_identity_cannot_change_before_freeze(self):
        with self.host() as host:
            original = U.store
            def replace_process(record):
                original(record)
                host.write(host.proc / "123/stat", host.stat_bytes(start=789), 0o444)
            with patch.object(U, "store", replace_process), self.assertRaises(U.UpgradeError):
                host.bridge()
            self.assertEqual(0, host.kill_count)
            self.assertEqual(0, host.exchange_count)
            self.assertTrue(U.TRANSACTION.exists())

    def test_missing_original_owned_gate_refuses_before_creating_transaction_or_lock(self):
        with self.host() as host:
            U.DROPIN.unlink()
            U.DROPIN.parent.rmdir()
            with self.assertRaisesRegex(U.UpgradeError, "bridge_source_gate_required"):
                host.bridge()
            self.assertFalse(U.TRANSACTION.exists())
            self.assertFalse(U.LOCK.exists())
            self.assertEqual(0, host.kill_count)
            self.assertEqual(0, host.exchange_count)

    def test_even_volatile_journal_fields_cannot_change_during_exchange(self):
        with self.host() as host:
            original = U.exchange
            def write_after_exchange(left, right):
                original(left, right)
                value = U.read_json(host.state / "journal.json")
                value["heartbeat_at"] += 1
                host.write(host.state / "journal.json", U.encoded(value), 0o600)
            with patch.object(U, "exchange", write_after_exchange), self.assertRaisesRegex(U.UpgradeError, "bridge_state_changed"):
                host.bridge()
            self.assertEqual(1, host.exchange_count)
            self.assertNotIn(("start", U.SERVICE), host.commands)
            self.assertTrue(U.STOP.exists())
            self.assertTrue(U.TRANSACTION.exists())

    def interrupt_at_stage(self, host, stage):
        original = U.store
        def crash(record):
            original(record)
            if record["stage"] == stage:
                raise Crash(stage)
        with patch.object(U, "store", crash), self.assertRaises(Crash):
            host.bridge()
        return U.read_json(U.TRANSACTION)

    def test_each_known_partial_write_is_retained_before_same_transaction_recovery(self):
        for selected in ("transaction", "raw-journal", "module", "receipt"):
            with self.subTest(selected=selected), self.host() as host:
                create = U.create
                interrupted = []
                def crash(path, raw, mode=0o600):
                    chosen = ((selected == "transaction" and path.name == ".helper-upgrade.next" and b'"stage":"frozen"' in raw)
                              or (selected == "raw-journal" and path.name == "journal-before.json")
                              or (selected == "module" and path.name == "update_apply.py" and path.parent.name.startswith(".wayfindr-updater-generation-"))
                              or (selected == "receipt" and path.name == "receipt.json"))
                    if chosen and not interrupted:
                        partial = raw[:max(1, len(raw) // 3)]
                        create(path, partial, mode)
                        interrupted.append(partial)
                        raise Crash(selected)
                    return create(path, raw, mode)
                with patch.object(U, "create", crash), self.assertRaises(Crash):
                    host.bridge()
                record = U.read_json(U.TRANSACTION)
                result = host.bridge_recover()
                custody = B.bridge_directory(record["transaction_id"])
                self.assertTrue(result["preserved"])
                self.assertEqual(1, host.exchange_count)
                self.assertIn(interrupted[0], [entry.read_bytes() for entry in custody.iterdir()])
                self.assertEqual(result["generation"], U.read_json(host.state / "journal.json")["generation"])
                self.assertFalse(U.TRANSACTION.exists())

    def test_partial_stop_marker_and_scratch_repair_requires_exact_owned_prefix(self):
        for selected in ("stop", "scratch", "invalid-stop", "invalid-scratch"):
            with self.subTest(selected=selected), self.host() as host:
                record = self.interrupt_at_stage(host, "frozen")
                expected = (record["transaction_id"] + "\n").encode()
                path = U.STOP if selected.endswith("stop") else U.STATE / ".helper-upgrade-stop.next"
                partial = b"unknown" if selected.startswith("invalid") else expected[:8]
                host.write(path, partial, 0o600)
                if selected.startswith("invalid"):
                    with self.assertRaises(U.UpgradeError):
                        host.bridge_recover()
                    self.assertEqual(partial, path.read_bytes())
                    self.assertEqual(0, host.kill_count)
                else:
                    self.assertTrue(host.bridge_recover()["preserved"])
                    self.assertIn(partial, [entry.read_bytes() for entry in B.bridge_directory(record["transaction_id"]).iterdir()])

    def test_unknown_owned_custody_entries_refuse_before_killing_or_exchange(self):
        for selected in ("file", "directory", "symlink", "wrong-journal", "wrong-mode"):
            with self.subTest(selected=selected), self.host() as host:
                record = self.interrupt_at_stage(host, "frozen")
                directory = B.directory(record)
                path = directory / "unowned"
                if selected == "directory":
                    path.mkdir()
                elif selected == "symlink":
                    path.symlink_to(host.state / "journal.json")
                elif selected == "wrong-journal":
                    host.write(directory / "journal-before.json", b"unknown", 0o600)
                elif selected == "wrong-mode":
                    host.write(directory / "journal-before.json", bytes.fromhex(record["raw_journal_hex"]), 0o644)
                else:
                    host.write(path, b"unowned", 0o600)
                with self.assertRaises(U.UpgradeError):
                    host.bridge_recover()
                self.assertEqual(0, host.kill_count)
                self.assertEqual(0, host.exchange_count)
                self.assertTrue(U.TRANSACTION.exists())

    def test_completed_receipt_tracks_the_fresh_verified_recovery_generation(self):
        for selected in ("before-receipt", "after-receipt"):
            with self.subTest(selected=selected), self.host() as host:
                if selected == "before-receipt":
                    record = self.interrupt_at_stage(host, "verified")
                else:
                    completion = U.verify_completion
                    count = 0
                    def crash(*args):
                        nonlocal count
                        count += 1
                        if count == 2:
                            raise Crash("after_receipt")
                        return completion(*args)
                    with patch.object(U, "verify_completion", crash), self.assertRaises(Crash):
                        host.bridge()
                    record = U.read_json(U.TRANSACTION)
                previous = record["verified_generation"]
                result = host.bridge_recover()
                self.assertNotEqual(previous, result["generation"])
                self.assertEqual(U.read_json(host.state / "journal.json")["generation"], result["generation"])
                self.assertEqual(result, U.read_json(B.bridge_directory(record["transaction_id"]) / "receipt.json"))
                if selected == "after-receipt":
                    self.assertTrue(list(B.bridge_directory(record["transaction_id"]).glob("receipt-previous-*")))

    def test_bridge_custody_survives_separate_app_recovery_and_normal_idle_inspection(self):
        with self.host() as host:
            result = host.bridge()
            path = B.bridge_directory(result["transaction_id"]) / "receipt.json"
            before = path.read_bytes()
            runtime = U.runtime_from(host.code / "updater.py")
            config = U.enrollment(runtime, host.distribution)
            journal = runtime.Journal(host.state / "journal.json", host.installation_id)
            # This models a separately authorized, verified application
            # recovery outcome. The bridge itself never invokes these APIs.
            journal.claim_apply(host.operation_id, journal.value["generation"], recover=True)
            journal.apply_checkpoint(host.operation_id, "previous_serving_verified", {
                "phase": "verifying", "runtime_receipt_sha256": "5" * 64, "services_verified": True,
                "origin_verified": True, "hold_owned": False})
            journal.apply_finish(host.operation_id, "recovery_required", outcome="failed_safe")
            history, _, _ = U.idle(runtime, config)
            self.assertIsNone(history["active_operation"])
            self.assertEqual(before, path.read_bytes())

    def test_preexisting_normal_receipt_history_is_byte_and_inode_preserved(self):
        with self.host() as host:
            U.RECEIPTS.mkdir(mode=0o700)
            path = U.RECEIPTS / "prior-completion.json"
            host.write(path, b'{"schema":1,"private":"prior-helper-receipt"}\n', 0o600)
            before = B.private_tree(U.RECEIPTS)
            result = host.bridge()
            self.assertEqual(before, B.private_tree(U.RECEIPTS, excluding=B.bridge_directory(result["transaction_id"])))

    def test_invalid_request_and_record_types_fail_closed_before_replacement(self):
        with self.host() as host:
            for operation, checksum, selected, recovery in [(None, host.expected_sha, host.selected(), None),
                    (host.operation_id, [], host.selected(), None), (host.operation_id, host.expected_sha, False, None),
                    (host.operation_id, host.expected_sha, host.selected(), "unknown")]:
                with self.subTest(operation=operation, checksum=checksum), self.assertRaisesRegex(U.UpgradeError, "bridge_request_invalid"):
                    B.finish(host.distribution, selected, operation, checksum, recovery=recovery)
            self.assertFalse(U.LOCK.exists())
            record = self.interrupt_at_stage(host, "frozen")
            for field, value in [("verified_generation", []), ("bundle_sha256", None), ("stage", []),
                                 ("raw_journal_hex", "00 zz"), ("freeze_identity", ["123", 456, str(U.UNIT), [], "on-failure"]),
                                 ("verified_generations", [True])]:
                changed = copy.deepcopy(record)
                changed[field] = value
                with self.subTest(field=field), self.assertRaises(U.UpgradeError):
                    B.validate_record(changed, host.selected(), U.bundle(host.distribution)[0]["files"], host.operation_id, host.expected_sha)

    def test_frozen_group_kill_timeout_never_thaws_or_exchanges_code(self):
        with self.host() as host:
            host.kill_settles = False
            with self.assertRaisesRegex(U.UpgradeError, "helper_not_stopped"):
                host.bridge()
            self.assertTrue(U.STOP.exists())
            self.assertEqual(0, host.exchange_count)
            self.assertNotIn(("stop", U.SERVICE), host.commands)
            self.assertNotIn(("thaw", U.SERVICE), host.commands)

    def test_source_authentication_binds_peer_nonce_hmac_version_and_process(self):
        for selected in ("valid", "peer", "uid", "nonce", "hmac", "version", "closed", "slow-bytes", "process-replaced"):
            with self.subTest(selected=selected), self.host() as host:
                runtime = U.runtime_from(host.code / "updater.py")
                config = U.enrollment(runtime, host.distribution)
                _, identity = U.running_helper(starting=True)
                class Connection:
                    def __init__(self, *unused): self.raw = b""
                    def __enter__(self): return self
                    def __exit__(self, *unused): return None
                    def settimeout(self, timeout):
                        if timeout <= 0: raise AssertionError("unbounded socket operation")
                    def connect(self, path):
                        if path != str(U.SOCKET): raise AssertionError("wrong socket")
                    def getsockopt(self, *unused):
                        return struct.pack("3i", 124 if selected == "peer" else 123, 1000 if selected == "uid" else 0, 1000)
                    def sendall(self, raw):
                        request = runtime.unpack_envelope(raw, config.token, "request")
                        result = config.capabilities()
                        if selected == "version": result["helper"]["version"] = "0.6.0"
                        value = {"protocol": 1, "installation_id": config.installation_id,
                                 "nonce": "different" if selected == "nonce" else request["nonce"], "ok": True, "result": result}
                        self.raw = runtime.envelope(value, "b" * 64 if selected == "hmac" else config.token, "response")
                    def recv(self, maximum):
                        if selected == "closed": return b""
                        if selected == "slow-bytes":
                            host.clock += 0.2
                            maximum = 1
                        if selected == "process-replaced":
                            host.write(host.proc / "123/stat", host.stat_bytes(start=789), 0o444)
                        chunk, self.raw = self.raw[:maximum], self.raw[maximum:]
                        return chunk
                with patch.object(runtime, "trusted"), patch.object(B.socket, "socket", Connection), \
                        patch.object(B.socket, "SO_PEERCRED", 17, create=True):
                    if selected == "valid":
                        SOURCE_AUTHENTICATE(runtime, config, identity)
                    else:
                        with self.assertRaises(U.UpgradeError):
                            SOURCE_AUTHENTICATE(runtime, config, identity)
                self.assertNotIn(("thaw", U.SERVICE), host.commands)
                self.assertEqual(0, host.kill_count)

    def test_full_runtime_binding_includes_exited_storage_init_and_complete_mounts(self):
        with self.host() as host:
            runtime = U.runtime_from(host.code / "updater.py")
            config = U.enrollment(runtime, host.distribution)
            fake = PURE.BaselineEngine()
            for row in fake.records.values(): row["project"] = config.value["compose_project"]
            fake.backup_name = lambda operation: "wayfindr-updater-backup-" + operation
            fake.oneoff_active = lambda name: False
            fake.commands_settled = lambda identifier: None
            fake.call = lambda args: "8" * 64
            class Engine:
                effective_keys = PURE.PROTECTION.DockerEngine.effective_keys
                web_status = PURE.PROTECTION.DockerEngine.web_status
                def __new__(cls, *unused): return fake
            protection = types.SimpleNamespace(DockerEngine=Engine, Protector=PURE.PROTECTION.Protector,
                                               CONTAINER=PURE.PROTECTION.CONTAINER)
            rows = {}
            for index in range(1, 9):
                identifier = str(index) * 64
                storage = index == 8
                rows[identifier] = {"Id": identifier, "Image": fake.image, "RestartCount": 0, "ExecIDs": None,
                    "Config": {"Env": ["PRIVATE_SYNTHETIC=value"], "Labels": {
                        "com.docker.compose.project": config.value["compose_project"],
                        "com.docker.compose.service": "storage-init" if storage else "synthetic",
                        "com.docker.compose.oneoff": "False"}},
                    "HostConfig": {"ReadonlyRootfs": storage}, "NetworkSettings": {},
                    "Mounts": [{"Destination": "/a", "Source": "synthetic-a", "RW": True},
                               {"Destination": "/b", "Source": "synthetic-b", "RW": False}],
                    "State": {"Status": "exited" if storage else "running", "Running": not storage,
                              "ExitCode": 0, "Pid": 0 if storage else index + 100, "StartedAt": "synthetic-first"}}
            observed = []
            def capture(command, **unused):
                self.assertEqual("inspect", command[-2])
                observed.append(command[-1])
                return 0, json.dumps([rows[command[-1]]]).encode()
            original = U.runtime_from
            def load(path):
                return protection if path.name == "update_protection.py" else original(path)
            with patch.object(U, "runtime_from", load), patch.object(runtime, "capture", capture), patch.object(runtime, "trusted"):
                before = B._readonly_baseline(runtime, config, host.distribution, host.operation_id)
                self.assertEqual({str(index) * 64 for index in range(1, 9)}, set(observed))
                for row in rows.values(): row["Mounts"].reverse()
                self.assertEqual(before, B._readonly_baseline(runtime, config, host.distribution, host.operation_id))
                rows["8" * 64]["State"]["StartedAt"] = "synthetic-restarted"
                self.assertNotEqual(before, B._readonly_baseline(runtime, config, host.distribution, host.operation_id))
                for selected in ("pending-exec", "running-storage", "duplicate-mount"):
                    saved = copy.deepcopy(rows["8" * 64])
                    if selected == "pending-exec": rows["8" * 64]["ExecIDs"] = ["created-command"]
                    elif selected == "running-storage": rows["8" * 64]["State"]["Running"] = True
                    else: rows["8" * 64]["Mounts"].append(copy.deepcopy(rows["8" * 64]["Mounts"][0]))
                    with self.subTest(selected=selected), self.assertRaises(U.UpgradeError):
                        B._readonly_baseline(runtime, config, host.distribution, host.operation_id)
                    rows["8" * 64] = saved


if __name__ == "__main__":
    unittest.main()
