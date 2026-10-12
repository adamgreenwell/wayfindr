#!/usr/bin/env python3
"""Offline eligibility and read-only baseline guards for the historical 0.6 bridge.

The fixture preserves a captured journal's shape, with synthetic private
identifiers and times. Public release facts remain exact. No native helper,
Docker, socket, systemd, provider or application operation is invoked.
"""

from __future__ import annotations

import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest.mock import Mock, patch

sys.dont_write_bytecode = True
ROOT = Path(__file__).absolute().parents[1]


def load(name, relative):
    spec = importlib.util.spec_from_file_location(name, ROOT / relative)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


BRIDGE = load("eligibility_bridge", "scripts/self-host/bridge-updater.py")
RUNTIME = load("eligibility_runtime", "scripts/self-host/updater.py")
PROTECTION = load("eligibility_protection", "scripts/self-host/update_protection.py")
FIXTURE = ROOT / "scripts/fixtures/helper-recovery-bridge-journal.json"
JOURNAL = json.loads(FIXTURE.read_bytes())
INSTALLATION = JOURNAL["installation_id"]
OPERATION = JOURNAL["active_operation"]
OTHER_UUID = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"
ERROR = BRIDGE.U.UpgradeError


def fixture():
    return copy.deepcopy(JOURNAL)


def operation(value):
    return value["operations"][OPERATION]


class EligibilityTests(unittest.TestCase):
    def assert_ineligible(self, value, operation_id=OPERATION):
        # Rebind the changed fixture's digest: rejection must come from the
        # state predicate or journal validator, not an unrelated hash mismatch.
        with self.assertRaises(ERROR):
            BRIDGE.require_state(RUNTIME, value, INSTALLATION, operation_id, BRIDGE.state_digest(value))

    def test_sanitized_capture_is_valid_and_eligible_without_mutation(self):
        value = fixture()
        before = copy.deepcopy(value)
        RUNTIME.validate_journal(value, INSTALLATION)
        expected = {key: item for key, item in value.items() if key not in {"generation", "heartbeat_at"}}
        self.assertEqual(expected, BRIDGE.require_state(RUNTIME, value, INSTALLATION, OPERATION, BRIDGE.state_digest(value)))
        self.assertEqual(before, value)
        self.assertEqual([1, 2, 3, 4, 5, 6, 7], [event["revision"] for event in operation(value)["events"]])
        self.assertEqual(BRIDGE.SOURCE, operation(value)["source"])
        self.assertEqual(BRIDGE.TARGET, operation(value)["target"])
        self.assertEqual(4101, operation(value)["operator"]["prepare"]["actor"]["id"])
        self.assertEqual(4101, operation(value)["operator"]["start"]["actor"]["id"])

    def test_only_top_level_generation_and_heartbeat_are_excluded(self):
        value = fixture()
        expected_sha = BRIDGE.state_digest(value)
        value["generation"] = OTHER_UUID
        value["heartbeat_at"] += 60
        self.assertEqual(expected_sha, BRIDGE.state_digest(value))
        self.assertEqual(BRIDGE.journal_state(JOURNAL), BRIDGE.require_state(RUNTIME, value, INSTALLATION, OPERATION, expected_sha))
        operation(value)["executor_generation"] = OTHER_UUID
        self.assertNotEqual(expected_sha, BRIDGE.state_digest(value))
        with self.assertRaises(ERROR):
            BRIDGE.require_state(RUNTIME, value, INSTALLATION, OPERATION, expected_sha)

    def test_digest_is_canonical_and_binds_every_nonvolatile_leaf(self):
        value = fixture()
        canonical = {key: item for key, item in value.items() if key not in {"generation", "heartbeat_at"}}
        raw = (json.dumps(canonical, sort_keys=True, separators=(",", ":")) + "\n").encode()
        digest = hashlib.sha256(raw).hexdigest()
        self.assertEqual(digest, BRIDGE.state_digest(value))
        self.assertEqual(digest, BRIDGE.state_digest(dict(reversed(list(value.items())))))

        def leaves(node, prefix=()):
            if isinstance(node, dict):
                for key, child in node.items():
                    yield from leaves(child, (*prefix, key))
            elif isinstance(node, list):
                for index, child in enumerate(node):
                    yield from leaves(child, (*prefix, index))
            else:
                yield prefix, node

        for path, original in leaves(canonical):
            changed = fixture()
            parent = changed
            for key in path[:-1]:
                parent = parent[key]
            parent[path[-1]] = (not original if type(original) is bool else original + 1 if type(original) is int
                               else "changed-offline-fixture" if original is None else original + "x")
            with self.subTest(path=path):
                self.assertNotEqual(digest, BRIDGE.state_digest(changed))

    def test_real_generation_start_preserves_the_entire_nonvolatile_map(self):
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / "journal.json"
            path.write_bytes(RUNTIME.encoded(JOURNAL))
            journal = RUNTIME.Journal(path, INSTALLATION, secure=False)
            expected = BRIDGE.state_digest(journal.value)
            with patch.object(RUNTIME.time, "time", return_value=1700000100):
                journal.begin_generation(OTHER_UUID)
            fresh = json.loads(path.read_bytes())
            self.assertEqual(OTHER_UUID, fresh["generation"])
            self.assertEqual(1700000100, fresh["heartbeat_at"])
            self.assertEqual(JOURNAL["operations"], fresh["operations"])
            self.assertEqual(JOURNAL["revision"], fresh["revision"])
            self.assertEqual(BRIDGE.journal_state(JOURNAL), BRIDGE.require_state(RUNTIME, fresh, INSTALLATION, OPERATION, expected))

    def test_wrong_installation_operation_or_expected_digest_refuses(self):
        value = fixture()
        digest = BRIDGE.state_digest(value)
        for installation, op, expected in [(OTHER_UUID, OPERATION, digest), (INSTALLATION, OTHER_UUID, digest),
                                            (INSTALLATION, None, digest), (INSTALLATION, OPERATION, "0" * 64),
                                            (INSTALLATION, OPERATION, None), (INSTALLATION, OPERATION, "pending")]:
            with self.subTest(installation=installation, operation=op, digest=expected), self.assertRaises(ERROR):
                BRIDGE.require_state(RUNTIME, value, installation, op, expected)

    def test_invalid_schema_shapes_and_boolean_integer_aliases_refuse(self):
        for key, replacement in [("schema", True), ("revision", True), ("heartbeat_at", True),
                                 ("generation", "invalid"), ("operations", []), ("schema", 2)]:
            value = fixture()
            value[key] = replacement
            with self.subTest(field=key, replacement=replacement):
                self.assert_ineligible(value)
        for key in ["schema", "installation_id", "operations", "active_operation"]:
            value = fixture()
            del value[key]
            with self.subTest(missing=key):
                self.assert_ineligible(value)
        value = fixture()
        value["unexpected"] = "offline"
        self.assert_ineligible(value)

    def test_active_last_and_single_operation_boundaries_refuse(self):
        for key, replacement in [("active_operation", None), ("last_operation", None),
                                 ("active_operation", OTHER_UUID), ("last_operation", OTHER_UUID), ("revision", 8)]:
            value = fixture()
            value[key] = replacement
            with self.subTest(field=key):
                self.assert_ineligible(value)
        value = fixture()
        value["operations"][OTHER_UUID] = copy.deepcopy(operation(value))
        self.assert_ineligible(value)
        value = fixture()
        value["operations"] = {}
        self.assert_ineligible(value)

    def test_operation_phase_checkpoint_executor_error_and_mutation_refuse(self):
        for key, replacement in [("phase", "downloading"), ("phase", "blocked"),
                                 ("checkpoint", "target_download_intent"), ("checkpoint", "migration_intent"),
                                 ("executor_version", "0.4.0"), ("executor_version", "0.6.0"),
                                 ("executor_version", "0.7.0"), ("executor_version", "0.8.0"), ("executor_version", "0.9.0"), ("executor_version", "0.10.0"), ("mutation_started", True),
                                 ("mutation_started", 0), ("error", None), ("error", "apply_failed"), ("revision", 6)]:
            value = fixture()
            operation(value)[key] = replacement
            with self.subTest(field=key, replacement=replacement):
                self.assert_ineligible(value)

    def test_every_apply_artifact_and_flag_refuses_when_not_pristine(self):
        original = operation(JOURNAL)["apply"]
        for key, initial in original.items():
            value = fixture()
            replacement = (True if initial is False else "downloading" if key == "phase" else None if key == "error"
                           else ("sha256:" + "a" * 64 if key.endswith("digest") else "a" * 64))
            if key == "index_digest":
                replacement = BRIDGE.TARGET["image_digest"]
            operation(value)["apply"][key] = replacement
            with self.subTest(field=key):
                self.assert_ineligible(value)

    def test_every_protection_artifact_and_flag_refuses_when_not_pristine(self):
        for key, initial in operation(JOURNAL)["protection"].items():
            value = fixture()
            replacement = (True if initial is False or key == "offsite_uploaded" else "draining" if key == "phase"
                           else "recovery_required" if key == "error" else 1 if key.endswith("bytes") or key.endswith("disks")
                           else "existence-and-size" if key == "offsite_verification"
                           else "sha256:" + "a" * 64 if key == "source_image_id" else "a" * 64)
            operation(value)["protection"][key] = replacement
            with self.subTest(field=key):
                self.assert_ineligible(value)

    def test_public_source_target_and_release_scope_refuse_other_facts(self):
        for group, key, replacement in [("source", "version", "1.2.1"), ("source", "commit", "a" * 40),
                                        ("target", "tag", "v1.3.1"), ("target", "version", "1.3.1"),
                                        ("target", "commit", "a" * 40), ("target", "image_digest", "sha256:" + "a" * 64)]:
            value = fixture()
            operation(value)[group][key] = replacement
            with self.subTest(group=group, field=key):
                self.assert_ineligible(value)
        value = fixture()
        operation(value)["release_tag"] = "v1.3.1"
        self.assert_ineligible(value)

    def test_each_event_code_phase_and_revision_is_required(self):
        for index in range(7):
            for key, replacement in [("code", "apply_recovery_started"), ("phase", "protecting"), ("revision", 8)]:
                value = fixture()
                operation(value)["events"][index][key] = replacement
                with self.subTest(index=index, field=key):
                    self.assert_ineligible(value)

    def test_truncated_retried_cancelled_reordered_or_clock_reversed_events_refuse(self):
        for mode in ["missing-first", "missing-last", "retry", "cancel", "reordered", "clock-reversed"]:
            value = fixture()
            events = operation(value)["events"]
            if mode == "missing-first":
                events.pop(0)
            elif mode == "missing-last":
                events.pop()
            elif mode in {"retry", "cancel"}:
                events[-1]["code"] = "apply_recovery_started" if mode == "retry" else "cancel_requested"
            elif mode == "reordered":
                events[0], events[1] = events[1], events[0]
            else:
                events[3]["at"] = events[-1]["at"] + 1
            with self.subTest(mode=mode):
                self.assert_ineligible(value)

    def test_operator_receipts_must_have_prepare_start_and_no_cancel(self):
        for action in ["prepare", "start"]:
            value = fixture()
            operation(value)["operator"][action] = None
            with self.subTest(missing=action):
                self.assert_ineligible(value)
        value = fixture()
        start = copy.deepcopy(operation(value)["operator"]["start"])
        start.update(request_id=OTHER_UUID, state="requested")
        operation(value)["operator"]["cancel"] = start
        self.assert_ineligible(value)
        for action, key, replacement in [("prepare", "revision", 2), ("start", "revision", 6),
                                         ("start", "plan_id", "a" * 64), ("start", "request_id", operation(JOURNAL)["request_id"]),
                                         ("prepare", "actor", {"id": True}), ("start", "actor", {"id": 0})]:
            value = fixture()
            operation(value)["operator"][action][key] = replacement
            with self.subTest(action=action, field=key):
                self.assert_ineligible(value)

    def test_valid_private_fact_changes_still_refuse_the_original_binding(self):
        expected = BRIDGE.state_digest(JOURNAL)
        for kind in ["actor", "request", "plan", "executor-generation", "times"]:
            value = fixture()
            op = operation(value)
            if kind == "actor":
                for action in ["prepare", "start"]:
                    op["operator"][action]["actor"]["id"] += 1
            elif kind == "request":
                op["operator"]["start"]["request_id"] = OTHER_UUID
            elif kind == "plan":
                op["plan_id"] = op["operator"]["start"]["plan_id"] = "a" * 64
            elif kind == "executor-generation":
                op["executor_generation"] = OTHER_UUID
            else:
                op["created_at"] += 1
                op["updated_at"] += 1
                for event in op["events"]:
                    event["at"] += 1
                for action in ["prepare", "start"]:
                    op["operator"][action]["at"] += 1
            RUNTIME.validate_journal(value, INSTALLATION)
            with self.subTest(kind=kind), self.assertRaises(ERROR):
                BRIDGE.require_state(RUNTIME, value, INSTALLATION, OPERATION, expected)


class BaselineCommandTests(unittest.TestCase):
    """Probe the real bridge's command boundary before a fake baseline can act."""

    def probe(self, command, *, allowed):
        class BaselineReached(Exception):
            pass

        captured = []
        def capture(args, **options):
            if args == command:
                captured.append(args)
                raise BaselineReached
            # The real wrapper checks named one-offs before entering baseline.
            if args[5:6] == ["ps"]:
                return 0, b""
            raise AssertionError("Unexpected offline observation: " + repr(args))

        class Protector:
            def __init__(self, config, journal, state, api, **options):
                self.api = api
            def baseline(self, operation_id):
                return self.api.capture(command)

        protection = types.SimpleNamespace(DockerEngine=PROTECTION.DockerEngine, Protector=Protector,
                                           CONTAINER=PROTECTION.CONTAINER)
        runtime = types.SimpleNamespace(Refusal=RUNTIME.Refusal, trusted=Mock(), strict_json=RUNTIME.strict_json,
                                        encoded=RUNTIME.encoded, capture=capture, Journal=Mock())
        config = types.SimpleNamespace(installation_id=INSTALLATION,
            value={"install_dir": "/offline/wayfindr", "compose_project": "offline-bridge"})
        refused = None
        with patch.object(BRIDGE.U, "runtime_from", return_value=protection):
            try:
                BRIDGE.readonly_baseline(runtime, config, Path("/offline/distribution"), OPERATION)
            except ERROR as error:
                refused = error
            except BaselineReached:
                pass
        self.assertEqual(not allowed, refused is not None)
        if refused is not None:
            self.assertEqual("bridge_command_refused", str(refused))
        self.assertEqual([command] if allowed else [], captured)

    def test_required_compose_config_observation_is_admitted(self):
        docker = ["/usr/bin/docker", "--host", "unix:///var/run/docker.sock", "--config", "/etc/wayfindr-updater/docker"]
        command = docker + ["compose", "--project-name", "offline-bridge", "--project-directory", "/offline/wayfindr",
                            "--env-file", "/offline/wayfindr/.env", "-f", "/offline/wayfindr/compose.yml",
                            "-f", "/offline/wayfindr/compose.updater.yml", "config", "--format", "json"]
        self.probe(command, allowed=True)

    def test_container_mutation_and_unreviewed_exec_are_never_delegated(self):
        docker = ["/usr/bin/docker", "--host", "unix:///var/run/docker.sock", "--config", "/etc/wayfindr-updater/docker"]
        container = "a" * 64
        for args in [["stop", container], ["start", container], ["restart", container], ["rm", "-f", container],
                     ["pull", "ghcr.io/adamgreenwell/wayfindr:1.3.0"], ["exec", container, "php", "-r", "echo 'unreviewed';"],
                     ["exec", container, "php", "artisan", "wayfindr:upgrade-window", OPERATION, "--action=enter", "--json"],
                     ["exec", container, "php", "artisan", "migrate", "--force"], ["compose", "up", "-d"]]:
            with self.subTest(args=args):
                self.probe(docker + args, allowed=False)

    def test_window_status_observation_has_exact_operation_binding(self):
        docker = ["/usr/bin/docker", "--host", "unix:///var/run/docker.sock", "--config", "/etc/wayfindr-updater/docker"]
        command = docker + ["exec", "a" * 64, "php", "artisan", "wayfindr:upgrade-window", OPERATION, "--action=status", "--json"]
        self.probe(command, allowed=True)
        command[-3] = OTHER_UUID
        self.probe(command, allowed=False)

    def test_each_pending_oneoff_refuses_before_baseline(self):
        class BaselineReached(Exception):
            pass

        names = ["wayfindr-updater-backup-", "wayfindr-updater-migration-", "wayfindr-updater-fence-"]
        names += ["wayfindr-updater-apply-" + action + "-" for action in ["protocol", "assess", "receipt", "verify"]]
        for prefix in names:
            selected = prefix + OPERATION
            class Engine(PROTECTION.DockerEngine):
                def oneoff_active(self, name):
                    return name == selected
            baseline = Mock(side_effect=BaselineReached)
            protection = types.SimpleNamespace(DockerEngine=Engine, CONTAINER=PROTECTION.CONTAINER,
                                                Protector=Mock(return_value=types.SimpleNamespace(baseline=baseline)))
            runtime = types.SimpleNamespace(Refusal=RUNTIME.Refusal, trusted=Mock(), strict_json=RUNTIME.strict_json,
                                            encoded=RUNTIME.encoded, capture=Mock(side_effect=AssertionError("No native commands")),
                                            Journal=Mock())
            config = types.SimpleNamespace(installation_id=INSTALLATION,
                value={"install_dir": "/offline/wayfindr", "compose_project": "offline-bridge"})
            refused = None
            with self.subTest(oneoff=prefix), patch.object(BRIDGE.U, "runtime_from", return_value=protection):
                try:
                    BRIDGE.readonly_baseline(runtime, config, Path("/offline/distribution"), OPERATION)
                except ERROR as error:
                    refused = error
                except BaselineReached:
                    pass
                self.assertIsNotNone(refused)
                self.assertEqual("bridge_effects_unverified", str(refused))
            baseline.assert_not_called()
            runtime.capture.assert_not_called()


class BaselineEngine:
    """Observation results for the real Protector.baseline implementation."""

    def __init__(self):
        self.ids = {name: str(index) * 64 for index, name in enumerate(PROTECTION.SERVICES, 1)}
        self.image = "sha256:" + "a" * 64
        self.source = copy.deepcopy(BRIDGE.SOURCE)
        self.records = {identifier: {"id": identifier, "project": "offline-bridge", "service": name,
            "image": self.image, "restarts": 0, "state": {"Running": True, "Paused": False, "Restarting": False,
            "Dead": False, "OOMKilled": False, "Error": "", "ExitCode": 0}}
            for name, identifier in self.ids.items()}
        self.window_value = {"schema": 1, "operation_id": None, "held": False, "ordinary_maintenance": False,
                             "source": {**self.source, "profile": "image"}, "ledger_supported": True}
        self.keys = {"schema": 1, "current": "k" * 32, "previous": [], "cipher": "AES-256-CBC",
                     "fingerprints": [hashlib.sha256(b"k" * 32).hexdigest()], "capture_binding_sha256": "d" * 64}
        self.pending_exec = False
        self.extra_writer = False
        self.wrong_keys = False
        self.status = 200
        self.actions = []

    def service_ids(self): return self.ids.copy()
    def inspect(self, identifier): return copy.deepcopy(self.records[identifier])
    def image_source(self, image): return copy.deepcopy(self.source)
    def check_selected_image(self, image): pass
    def require_source(self, ids, image): pass
    def stop_supported(self): pass
    def dependencies(self): return {"6" * 64, "7" * 64}
    def environment_binding(self, identifier): return {"sha256": "e" * 64, "keys": ["APP_KEY", "DB_DATABASE"]}
    def web_status(self, identifier): return self.status
    def writers(self, allowed):
        if self.extra_writer:
            raise RUNTIME.Refusal("writer_unverified")
    def settled(self, ids, operation_id):
        if self.pending_exec:
            raise RUNTIME.Refusal("recovery_required")
    def effective_keys(self, identifier):
        keys = copy.deepcopy(self.keys)
        if self.wrong_keys and identifier != self.ids["web"]:
            keys["capture_binding_sha256"] = "f" * 64
        return keys
    def window(self, identifier, operation_id, action):
        if action != "status":
            raise AssertionError("Baseline attempted a window mutation")
        self.actions.append(action)
        return copy.deepcopy(self.window_value)


class ReadonlyBaselineTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.path = self.root / "journal.json"
        self.path.write_bytes(RUNTIME.encoded(JOURNAL))
        self.journal = RUNTIME.Journal(self.path, INSTALLATION, secure=False)
        self.config = types.SimpleNamespace(installation_id=INSTALLATION, verify_files=Mock(),
            value={"install_dir": str(self.root), "compose_project": "offline-bridge", "overlay_sha256": "c" * 64})
        self.engine = BaselineEngine()
        self.write = Mock(side_effect=AssertionError("Read-only baseline attempted a journal/private-state write"))
        self.api = types.SimpleNamespace(Refusal=RUNTIME.Refusal, encoded=RUNTIME.encoded, atomic_write=self.write)
        self.protector = PROTECTION.Protector(self.config, self.journal, self.root, self.api, self.engine, secure=False)

    def assert_unchanged(self):
        self.assertEqual(JOURNAL, json.loads(self.path.read_bytes()))
        self.assertEqual(JOURNAL, self.journal.value)
        self.write.assert_not_called()

    def test_real_baseline_observes_only_and_does_not_create_recovery_state(self):
        before = {path.name: path.read_bytes() for path in self.root.iterdir()}
        context, keys = self.protector.baseline(OPERATION)
        self.assertEqual(self.engine.ids, context["containers"])
        self.assertEqual(self.engine.keys, keys)
        self.assertEqual(["status"], self.engine.actions)
        self.assertEqual(before, {path.name: path.read_bytes() for path in self.root.iterdir()})
        self.assert_unchanged()

    def test_hold_maintenance_missing_ledger_and_wrong_source_refuse(self):
        for key, replacement in [("held", True), ("ordinary_maintenance", True), ("ledger_supported", False),
                                 ("operation_id", OTHER_UUID), ("source", {**BRIDGE.SOURCE, "profile": "classic"})]:
            original = copy.deepcopy(self.engine.window_value)
            self.engine.window_value[key] = replacement
            with self.subTest(field=key), self.assertRaises(RUNTIME.Refusal):
                self.protector.baseline(OPERATION)
            self.engine.window_value = original
            self.assert_unchanged()

    def test_pending_commands_extra_writers_unhealthy_http_and_key_drift_refuse(self):
        for key, replacement in [("pending_exec", True), ("extra_writer", True), ("status", 503), ("wrong_keys", True)]:
            original = getattr(self.engine, key)
            setattr(self.engine, key, replacement)
            with self.subTest(field=key), self.assertRaises(RUNTIME.Refusal):
                self.protector.baseline(OPERATION)
            setattr(self.engine, key, original)
            self.assert_unchanged()

    def test_changed_source_or_service_identity_state_refuses(self):
        self.engine.source["commit"] = "a" * 40
        with self.assertRaises(RUNTIME.Refusal):
            self.protector.baseline(OPERATION)
        self.engine.source = copy.deepcopy(BRIDGE.SOURCE)
        identifier = self.engine.ids["queue"]
        for key, replacement in [("Running", False), ("Paused", True), ("Restarting", True), ("OOMKilled", True),
                                 ("Dead", True), ("Error", "offline failure")]:
            original = self.engine.records[identifier]["state"][key]
            self.engine.records[identifier]["state"][key] = replacement
            with self.subTest(field=key), self.assertRaises(RUNTIME.Refusal):
                self.protector.baseline(OPERATION)
            self.engine.records[identifier]["state"][key] = original
        self.assert_unchanged()

    def test_existing_upgrade_lock_and_changed_enrollment_refuse(self):
        lock = self.root / ".upgrade.lock"
        lock.write_bytes(b"offline-owned-lock")
        with self.assertRaises(RUNTIME.Refusal):
            self.protector.baseline(OPERATION)
        lock.unlink()
        self.config.verify_files.side_effect = RUNTIME.Refusal("configuration_changed")
        with self.assertRaises(RUNTIME.Refusal):
            self.protector.baseline(OPERATION)
        self.assert_unchanged()


if __name__ == "__main__":
    unittest.main()
