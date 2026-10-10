#!/usr/bin/env python3
"""Exercise apply/recovery against a real durable journal and protective archive.

Docker/network are controlled fixtures. No enrollment, image pull, deployment,
provider write, database restore, or pruning happens in these tests.
"""

import copy
import hashlib
import hmac
import importlib.util
import json
from email.message import Message
from pathlib import Path
import sys
import subprocess
import tempfile
import types
import unittest
import uuid
from unittest.mock import patch

sys.dont_write_bytecode = True
ROOT = Path(__file__).absolute().parents[1]


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, ROOT / filename)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


FIX = module("apply_protect_fixture", "scripts/test_update_protection.py")
UP, PROTECT = FIX.UP, FIX.PROTECT
APPLY = module("apply_executor", "scripts/self-host/update_apply.py")
SOURCE = FIX.SOURCE
TARGET = {"tag": "v1.1.2", "version": "1.1.2", "commit": "e" * 40, "image_digest": "sha256:" + "f" * 64}
TARGET_IMAGE = "sha256:" + "a" * 64
PLATFORM_DESCRIPTOR = {"mediaType": "application/vnd.oci.image.manifest.v1+json", "digest": "sha256:" + "1" * 64, "size": 7311, "platform": {"os": "linux", "architecture": "arm64"}}


class Config(UP.Configuration):
    def verify_files(self):
        directory = Path(self.value["install_dir"])
        for name, field in ((".env", "env_sha256"), ("compose.yml", "compose_sha256"), ("install.sh", "installer_sha256"), ("compose.updater.yml", "overlay_sha256")):
            if APPLY.digest((directory / name).read_bytes()) != self.value[field]:
                raise UP.Refusal("configuration_changed")


class Artifacts:
    def __init__(self, config, engine):
        self.config, self.engine = config, engine

    def prepare(self, target, plan, architecture, directory):
        assert directory.is_dir() and directory.stat().st_mode & 0o777 == 0o700
        self.engine.calls.append("artifacts")
        self.engine.trip("download")
        if self.engine.fault == "compose_change":
            compose = "0" * 64
        else:
            compose = self.config.value["compose_sha256"]
        return {"schema": 2, "target": target, "index_digest": target["image_digest"], "platform_manifest_digest": "sha256:" + "1" * 64,
                "config_digest": TARGET_IMAGE, "platform_manifest_descriptor": {**PLATFORM_DESCRIPTOR, "platform": {"os": "linux", "architecture": architecture}}, "manifest_sha256": "2" * 64, "history_sha256": "3" * 64,
                "digest_asset_sha256": "4" * 64, "compose_sha256": compose, "baked_history_sha256": "5" * 64,
                "local_image_id": self.engine.target_image, "local_image_descriptor": None if self.engine.target_image == TARGET_IMAGE else {"mediaType": "application/vnd.oci.image.index.v1+json", "digest": target["image_digest"], "size": 1609},
                "execution_reference": "ghcr.io/adamgreenwell/wayfindr:" + target["version"] + "@" + target["image_digest"], "execution_platform": "linux/" + architecture}

    def verify(self, target, evidence, architecture, directory):
        if self.engine.fault == "staging":
            raise UP.Refusal("artifact_verification_failed")
        return evidence


class Engine(FIX.Engine):
    def __init__(self):
        super().__init__()
        self.originals = self.ids.copy()
        self.targets = {}
        self.target_running = {}
        self.migrated = False
        self.migration_active = False
        self.complete = None
        self.crash = None
        self.origin_stale = False
        self.target_image = TARGET_IMAGE

    def trip(self, stage):
        if self.crash == stage:
            self.crash = None
            raise KeyboardInterrupt()
        if self.fault == stage:
            raise UP.Refusal("migration_failed" if stage == "migration" else "apply_timeout")

    def full_plan(self, container, tag):
        self.calls.append("fresh_plan")
        return {"fixture": True}

    def layout(self, container):
        return {"env": ["APP_KEY=" + FIX.KEY], "cmd": ["fixture"], "entrypoint": ["fixture"], "user": "1000", "workdir": "/app/apps/server",
                "mounts": [{"Type": "volume", "Name": "storage", "Source": "/var/storage", "Destination": "/app/apps/server/storage", "RW": True, "Driver": "local"}],
                "created": "2026-01-01T00:00:00Z"}

    def origin(self, web):
        return "https://fixture.invalid"

    def inspect(self, container):
        for service, identifier in self.targets.items():
            if container == identifier:
                return {"id": container, "image": self.target_image, "project": "wayfindr-self-hosting", "service": service, "oneoff": "False", "restarts": 0,
                        "state": {"Running": self.target_running[service], "Paused": False, "Restarting": False, "Dead": False, "OOMKilled": False, "Error": "", "ExitCode": 0}}
        original = self.ids
        self.ids = self.originals
        try:
            return super().inspect(container)
        finally:
            self.ids = original

    def service_ids(self):
        return {**self.originals, **self.targets}

    def all_ids(self, service):
        return [self.targets.get(service, self.originals[service])]

    def create(self, directory, service):
        self.calls.append("create_" + service)
        self.trip("create_" + service)
        self.targets[service] = hashlib.sha256(service.encode()).hexdigest()
        self.target_running[service] = False
        self.trip("after_create_" + service)

    def start(self, ids):
        if all(container in self.originals.values() for container in ids.values()):
            return super().start(ids)
        assert self.held
        for service, container in ids.items():
            assert self.targets[service] == container
            self.calls.append("start_" + service)
            self.target_running[service] = True
            self.trip("start_" + service)

    def target_layout(self, container, service, directory, state, *, processes=True):
        self.calls.append(("layout_" if processes else "prestart_") + service)
        self.trip("layout_" + service)

    def role_ready(self, container, service):
        self.calls.append("role_" + service)

    def oneoff_active(self, name):
        return self.migration_active if "-migration-" in name else False

    def ensure_target_window(self, directory, operation, state):
        self.settled(self.service_ids(), operation)
        if self.migration_active or self.active:
            raise UP.Refusal("migration_ambiguous")
        self.held = True
        return self.window(self.targets.get("web", self.originals["web"]), operation, "status")

    def window(self, container, operation, action):
        result = super().window(container, operation, action)
        if container in self.targets.values() or self.migrated:
            result["source"] = {"version": TARGET["version"], "commit": TARGET["commit"], "profile": "image"}
        return result

    def facts(self, operation, state, phase, source=False):
        identity = SOURCE if source else {key: TARGET[key] for key in ("version", "commit")}
        return {"schema": 1, "operation_id": operation, "plan_id": state["plan_id"], "phase": phase, "target": {**identity, "profile": "image"},
                "binding_sha256": state["source_context"]["capture_binding_sha256"], "manifest_sha256": "2" * 64, "history_sha256": "5" * 64,
                "migrations_sha256": "6" * 64, "release_state_sha256": "7" * 64, "pending_migrations": 0,
                "guards_clear": True, "database_verified": True, "redis_verified": True, "hold_owned": True}

    def oneoff(self, directory, operation, state, action):
        self.calls.append(action)
        if action == "protocol":
            self.trip("protocol")
            return copy.deepcopy(APPLY.OPERATOR_CONTRACT)
        assert self.held and not self.running
        if action == "assess":
            self.trip("assessment")
        if action == "migrate":
            assert self.complete is None
            self.trip("migration")
            self.migrated = True
            self.complete = self.facts(operation, state, "complete")
            self.trip("after_migration")
            return self.complete
        if action == "receipt":
            if self.complete is None:
                raise UP.Refusal("migration_ambiguous")
            return self.complete
        return self.facts(operation, state, "verified" if action == "verify" else "assessed")

    def runtime_receipt(self, container, operation, state, source=False):
        self.trip("runtime")
        self.trip("fallback_runtime" if source else "target_runtime")
        result = self.facts(operation, state, "assessed" if source else "verified", source)
        if self.fault == "stale_receipt" and not source:
            result["target"] = {**SOURCE, "profile": "image"}
        if self.fault == "pending_source" and source:
            result["pending_migrations"] = 1
        return result

    def realtime_receipt(self, container, operation, state, source=False):
        self.calls.append("realtime_source" if source else "realtime_target")
        self.trip("realtime_source" if source else "realtime_target")
        identity = SOURCE if source else {key: TARGET[key] for key in ("version", "commit")}
        return {"schema": 1, "operation_id": operation, "plan_id": state["plan_id"],
                "phase": "realtime_verified", "target": {**identity, "profile": "image"},
                "binding_sha256": state["source_context"]["capture_binding_sha256"],
                "hold_owned": True, "realtime_verified": True}


class ApplyTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        install = self.root / "install"
        install.mkdir()
        for filename in (".env", "compose.yml", "install.sh"):
            (install / filename).write_text("private-fixture-" + filename)
        (install / "compose.updater.yml").write_bytes((ROOT / "docker/self-hosting/compose.updater.yml").read_bytes().replace(b"__WAYFINDR_INSTALLATION_ID__", FIX.HELPER.INSTALLATION.encode()))
        (install / ".updater-enrolled").write_text(FIX.HELPER.INSTALLATION)
        value = {"schema": 1, "installation_id": FIX.HELPER.INSTALLATION, "client_uid": 1000, "client_gid": 1000,
                 "install_dir": str(install), "compose_project": "wayfindr-self-hosting", "image_reference": "ghcr.io/adamgreenwell/wayfindr:1.1.1"}
        for filename, field in ((".env", "env_sha256"), ("compose.yml", "compose_sha256"), ("install.sh", "installer_sha256"), ("compose.updater.yml", "overlay_sha256")):
            value[field] = APPLY.digest((install / filename).read_bytes())
        self.config = Config(value, FIX.HELPER.TOKEN)
        self.configpath = self.root / "installation.json"
        UP.atomic_write(self.configpath, value)
        self.api = types.SimpleNamespace(**vars(FIX.API), Configuration=UP.Configuration, CONFIG=self.configpath, validate_journal=UP.validate_journal, JOURNAL_MAX=UP.JOURNAL_MAX)
        self.api.plan_facts = lambda *_: ("execution_not_available", {"source": SOURCE, "target": TARGET, "plan_id": "d" * 64})
        self.journalpath = self.root / "journal.json"
        UP.atomic_write(self.journalpath, UP.initial_journal(self.config.installation_id))
        self.journal = UP.Journal(self.journalpath, self.config.installation_id, secure=False)
        self.generation = str(uuid.uuid4())
        self.journal.begin_generation(self.generation)
        self.operation = self.journal.accept(str(uuid.uuid4()), TARGET["tag"], self.generation)[0]
        self.journal.preparing(self.operation)
        self.journal.finish(self.operation, "execution_not_available", {"source": SOURCE, "target": TARGET, "plan_id": "d" * 64})
        self.engine = Engine()
        self.protector = PROTECT.Protector(self.config, self.journal, self.root, self.api, self.engine, secure=False)
        self.applier = APPLY.Applier(self.config, self.journal, self.root, self.api, self.engine, Artifacts(self.config, self.engine), self.protector, secure=False, proof=self.proof)

    def proof(self, origin, keys, operation, identity, held, api):
        assert origin == "https://fixture.invalid" and keys["current"] == FIX.KEY and held == self.engine.held
        self.engine.calls.append("origin_held" if held else "origin_serving")
        self.engine.trip("origin_held" if held else "origin_serving")
        if self.engine.origin_stale:
            raise UP.Refusal("origin_verification_failed")

    def status(self):
        return self.journal.status(self.operation)

    def apply(self):
        assert self.journal.claim_apply(self.operation, self.generation)
        self.applier(self.operation)

    def recover(self):
        self.journal = UP.Journal(self.journalpath, self.config.installation_id, secure=False)
        self.generation = str(uuid.uuid4())
        self.journal.begin_generation(self.generation)
        assert self.journal.claim_apply(self.operation, self.generation, recover=True)
        self.applier.journal = self.protector.journal = self.journal
        self.applier(self.operation, recovery=True)

    def test_full_apply_keeps_fence_from_fresh_backup_to_all_runtime_verification(self):
        self.apply()
        self.assertEqual("succeeded", self.status()["operation"]["phase"])
        self.assertIsNone(self.status()["active_operation"])
        self.assertFalse(self.engine.held)
        self.assertFalse(self.engine.running)
        self.assertTrue(all(self.engine.target_running.values()))
        self.assertEqual("retained", self.status()["operation"]["protection"]["phase"])
        self.assertFalse(self.status()["operation"]["protection"]["services_recovered"])
        for first, second in (("artifacts", "enter"), ("backup", "migrate"), ("migrate", "create_web"), ("prestart_web", "start_web"), ("role_reverb", "release"), ("origin_held", "release"), ("release", "origin_serving")):
            self.assertLess(self.engine.calls.index(first), self.engine.calls.index(second))
        self.assertEqual(1, self.engine.calls.count("backup"))
        self.assertEqual(1, self.engine.calls.count("migrate"))
        self.assertFalse(self.journal.claim_apply(self.operation, self.generation))
        self.assertEqual(TARGET["image_digest"], self.config.value["image_reference"].split("@")[1])
        self.assertNotIn(FIX.KEY, json.dumps(self.status()))
        self.assertTrue((self.root / "protection" / self.operation / "archive.tar.gz").exists())

    def test_containerd_index_id_survives_migration_service_reconciliation_and_recovery(self):
        self.engine.target_image = TARGET["image_digest"]
        self.engine.crash = "after_create_queue"
        with self.assertRaises(KeyboardInterrupt):
            self.apply()
        self.recover()
        self.assertEqual("succeeded", self.status()["operation"]["phase"])
        self.assertEqual(1, self.engine.calls.count("migrate"))
        self.assertEqual(1, self.engine.calls.count("create_queue"))
        state = UP.read_object(self.root / "apply" / self.operation / "state.json", 1_000_000, "recovery_required")
        self.assertEqual(2, state["schema"])
        self.assertEqual(TARGET_IMAGE, state["artifacts"]["config_digest"])
        self.assertEqual(TARGET["image_digest"], state["artifacts"]["local_image_id"])
        for filename in (self.root / "apply" / self.operation / "target.yml", Path(self.config.value["install_dir"]) / "compose.updater.yml"):
            for service in json.loads(filename.read_bytes())["services"].values():
                self.assertEqual(state["artifacts"]["execution_reference"], service["image"])
                self.assertEqual(state["artifacts"]["execution_platform"], service["platform"])

    def test_public_config_digest_is_never_adopted_as_a_containerd_service_id(self):
        self.engine.target_image = TARGET["image_digest"]
        self.engine.crash = "after_create_queue"
        with self.assertRaises(KeyboardInterrupt):
            self.apply()
        self.engine.target_image = TARGET_IMAGE
        before = self.engine.calls.count("migrate")
        self.recover()
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertEqual(before, self.engine.calls.count("migrate"))
        self.assertNotIn("start_queue", self.engine.calls)
        self.assertNotIn("release", self.engine.calls)

    def test_legacy_apply_schema_is_preserved_and_refused_without_replaying_migration(self):
        self.engine.crash = "after_migration"
        with self.assertRaises(KeyboardInterrupt):
            self.apply()
        path = self.root / "apply" / self.operation / "state.json"
        state = json.loads(path.read_bytes())
        state["schema"] = 1
        UP.atomic_write(path, state)
        before = path.read_bytes()
        self.recover()
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertEqual(before, path.read_bytes())
        self.assertEqual(1, self.engine.calls.count("migrate"))
        self.assertNotIn("receipt", self.engine.calls)

    def test_selector_platform_drift_at_each_execution_boundary_refuses_before_next_action(self):
        self.assertTrue(self.journal.claim_apply(self.operation, self.generation))
        directory = self.root / "apply" / self.operation
        state = self.applier.initial(directory, self.operation)
        self.applier.download(directory, self.operation, state)
        target = directory / "target.yml"
        good = target.read_bytes()
        for boundary in ("operator_protocol", "migration", "reconcile_services", "verify_runtime", "promote", "finish_target"):
            for field, bad in (("image", TARGET_IMAGE), ("platform", "linux/unverified")):
                altered = json.loads(good)
                altered["services"]["web"][field] = bad
                target.write_bytes(UP.encoded(altered))
                before = len(self.engine.calls)
                with self.subTest(boundary=boundary, field=field):
                    with self.assertRaises(UP.Refusal) as failure:
                        args = [directory, self.operation, state]
                        if boundary == "migration":
                            args.append(False)
                        getattr(self.applier, boundary)(*args)
                    self.assertEqual("configuration_changed", failure.exception.reason)
                    self.assertEqual(before, len(self.engine.calls))
                target.write_bytes(good)

    def test_incompatible_target_protocol_refuses_before_fencing_draining_or_backup(self):
        original = self.engine.oneoff
        self.engine.oneoff = lambda directory, operation, state, action: {} if action == "protocol" else original(directory, operation, state, action)
        frozen_config = copy.deepcopy(self.config.value)
        self.apply()
        for forbidden in ("enter", "ensure_fence", "drain", "backup", "start", "release", "assess", "migrate"):
            self.assertNotIn(forbidden, self.engine.calls, "An incompatible target must not interrupt the running source: " + forbidden)
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertEqual("apply_unavailable", self.status()["operation"]["error"])
        self.assertFalse(self.status()["operation"]["mutation_started"])
        self.assertFalse(self.status()["operation"]["apply"]["hold_owned"])
        self.assertFalse(self.status()["operation"]["protection"]["hold_owned"])
        self.assertFalse(self.status()["operation"]["apply"]["services_verified"])
        self.assertFalse(self.status()["operation"]["apply"]["origin_verified"])
        self.assertIsNone(self.status()["operation"]["apply"]["runtime_receipt_sha256"])
        self.assertIsNone(self.status()["operation"]["apply"]["migration_receipt_sha256"])
        self.assertTrue(self.engine.running)
        self.assertFalse(self.engine.held)
        self.assertFalse(self.engine.targets)
        self.assertEqual(frozen_config, self.config.value)
        self.assertEqual(self.engine.originals, self.engine.service_ids())
        state = UP.read_object(self.root / "apply" / self.operation / "state.json", 1_000_000, "recovery_required")
        self.assertEqual("downloaded", state["stage"])
        self.assertFalse((self.root / "protection" / self.operation).exists())

    def test_broken_public_realtime_cannot_complete_or_release_the_target(self):
        self.engine.fault = "realtime_target"
        self.apply()
        operation = self.status()["operation"]
        self.assertEqual("recovery_required", operation["phase"])
        self.assertTrue(operation["mutation_started"])
        self.assertTrue(operation["apply"]["hold_owned"])
        self.assertTrue(self.engine.held)
        self.assertFalse(operation["apply"]["services_verified"])
        self.assertIsNone(operation["apply"]["runtime_receipt_sha256"])
        self.assertNotIn("release", self.engine.calls)

    def test_runtime_realtime_proof_requires_exact_operation_plan_identity_and_binding(self):
        for field in ("schema", "operation_id", "plan_id", "target", "binding_sha256", "hold_owned", "realtime_verified", "extra"):
            with self.subTest(field=field):
                self.tearDown()
                self.setUp()
                original = self.engine.realtime_receipt
                def changed(*args, **kwargs):
                    result = original(*args, **kwargs)
                    result[field] = True if field == "schema" else None
                    return result
                self.engine.realtime_receipt = changed
                self.apply()
                self.assertEqual("recovery_required", self.status()["operation"]["phase"])
                self.assertTrue(self.engine.held)
                self.assertIsNone(self.status()["operation"]["apply"]["runtime_receipt_sha256"])
                self.assertNotIn("release", self.engine.calls)

    def test_success_retains_private_realtime_transport_evidence(self):
        self.apply()
        self.assertEqual("succeeded", self.status()["operation"]["phase"])
        receipt = UP.read_object(self.root / "apply" / self.operation / "runtime.json", 1_000_000, "recovery_required")
        self.assertTrue(receipt["realtime"]["realtime_verified"])
        self.assertEqual(self.operation, receipt["realtime"]["operation_id"])
        self.assertEqual(1, self.engine.calls.count("realtime_target"))

    def test_realtime_proof_flags_require_boolean_true(self):
        for field in ("hold_owned", "realtime_verified"):
            for invalid in (1, 1.0, 0, "true", None):
                with self.subTest(field=field, invalid=invalid):
                    self.tearDown()
                    self.setUp()
                    original = self.engine.realtime_receipt
                    def changed(*args, **kwargs):
                        result = original(*args, **kwargs)
                        result[field] = invalid
                        return result
                    self.engine.realtime_receipt = changed
                    self.apply()
                    self.assertEqual("recovery_required", self.status()["operation"]["phase"])
                    self.assertTrue(self.engine.held)
                    self.assertNotIn("release", self.engine.calls)

    def test_failed_source_realtime_verification_keeps_fallback_held(self):
        self.engine.fault = "assessment"
        original = self.engine.realtime_receipt
        def source_failure(*args, **kwargs):
            if args[-1] is True:
                raise UP.Refusal("runtime_verification_failed")
            return original(*args, **kwargs)
        self.engine.realtime_receipt = source_failure
        self.apply()
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertFalse(self.status()["operation"]["mutation_started"])
        self.assertTrue(self.engine.held)
        self.assertNotIn("release", self.engine.calls)

    def test_crashed_early_protocol_probe_retains_source_and_requires_explicit_recovery_after_restart(self):
        self.engine.crash = "protocol"
        with self.assertRaises(KeyboardInterrupt):
            self.apply()
        old_generation = self.generation
        self.journal = UP.Journal(self.journalpath, self.config.installation_id, secure=False)
        self.generation = str(uuid.uuid4())
        self.journal.begin_generation(self.generation)
        self.assertNotEqual(old_generation, self.status()["generation"])
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertFalse(self.status()["operation"]["apply"]["hold_owned"])
        self.assertFalse(self.status()["operation"]["protection"]["hold_owned"])
        self.assertTrue(self.engine.running)
        self.assertFalse(self.engine.held)
        for forbidden in ("enter", "ensure_fence", "drain", "backup", "start", "release", "assess", "migrate"):
            self.assertNotIn(forbidden, self.engine.calls)
        self.assertFalse((self.root / "protection" / self.operation).exists())
        self.recover()
        self.assertEqual("failed_safe", self.status()["operation"]["phase"])
        self.assertTrue(self.engine.running)
        self.assertFalse(self.engine.held)
        self.assertEqual(1, self.engine.calls.count("protocol"))
        self.assertNotIn("migrate", self.engine.calls)

    def test_target_protocol_is_verified_before_source_interruption_and_rechecked_before_schema(self):
        self.apply()
        probes = [index for index, call in enumerate(self.engine.calls) if call == "protocol"]
        self.assertEqual(2, len(probes))
        self.assertLess(self.engine.calls.index("artifacts"), probes[0])
        self.assertLess(probes[0], self.engine.calls.index("enter"))
        self.assertLess(self.engine.calls.index("backup"), probes[1])
        self.assertLess(probes[1], self.engine.calls.index("assess"))
        self.assertEqual("succeeded", self.status()["operation"]["phase"])

    def test_changed_target_protocol_at_schema_recheck_recovers_verified_source_without_migration(self):
        original = self.engine.oneoff
        probes = []
        def changed(directory, operation, state, action):
            if action == "protocol":
                probes.append(action)
                if len(probes) == 2:
                    return {}
            return original(directory, operation, state, action)
        self.engine.oneoff = changed
        self.apply()
        self.assertEqual(2, len(probes))
        self.assertEqual("failed_safe", self.status()["operation"]["phase"])
        self.assertFalse(self.status()["operation"]["mutation_started"])
        self.assertTrue(self.engine.running)
        self.assertFalse(self.engine.held)
        self.assertEqual(1, self.engine.calls.count("backup"))
        self.assertNotIn("assess", self.engine.calls)
        self.assertNotIn("migrate", self.engine.calls)

    def test_failures_before_migration_verify_and_recover_original_services(self):
        for fault in ("download", "compose_change", "assessment", "backup", "archive"):
            with self.subTest(fault=fault):
                self.setUp()
                original = copy.deepcopy(self.config.value)
                self.engine.fault = fault
                self.apply()
                self.assertEqual("failed_safe", self.status()["operation"]["phase"])
                self.assertFalse(self.status()["operation"]["mutation_started"])
                self.assertTrue(self.engine.running)
                self.assertFalse(self.engine.held)
                self.assertNotIn("migrate", self.engine.calls)
                self.assertFalse(self.engine.targets)
                self.assertEqual(original, self.config.value)

    def test_pre_schema_unknown_drain_or_active_backup_keeps_hold(self):
        for fault in ("drain", "backup_active", "fallback_runtime", "pending_source"):
            with self.subTest(fault=fault):
                self.setUp()
                self.engine.fault = "download" if fault in {"fallback_runtime", "pending_source"} else fault
                if fault in {"fallback_runtime", "pending_source"}:
                    original = self.applier.artifacts.prepare
                    def fail_after(*args, fault=fault):
                        self.engine.fault = fault
                        raise UP.Refusal("download_failed")
                    self.applier.artifacts.prepare = fail_after
                self.apply()
                self.assertEqual("recovery_required", self.status()["operation"]["phase"])
                self.assertEqual(self.operation, self.status()["active_operation"])
                self.assertTrue(self.engine.held)
                self.assertNotIn("migrate", self.engine.calls)
                self.assertNotIn("release", self.engine.calls)

    def test_schema_intent_failures_never_restore_source_or_release(self):
        for fault in ("migration", "runtime", "stale_receipt", "layout_queue", "origin_held"):
            with self.subTest(fault=fault):
                self.setUp()
                self.engine.fault = fault
                self.apply()
                self.assertEqual("recovery_required", self.status()["operation"]["phase"])
                self.assertTrue(self.status()["operation"]["mutation_started"])
                self.assertTrue(self.engine.held)
                self.assertFalse(self.engine.running)
                self.assertNotIn("release", self.engine.calls)

    def test_missing_migration_completion_never_replays_owner(self):
        self.engine.fault = "migration"
        self.apply()
        self.engine.fault = None
        self.recover()
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertEqual(1, self.engine.calls.count("migrate"))
        self.assertFalse(self.engine.targets)

    def test_completed_migration_after_helper_crash_recovers_without_replay(self):
        self.engine.crash = "after_migration"
        with self.assertRaises(KeyboardInterrupt):
            self.apply()
        self.assertTrue(self.engine.held)
        self.recover()
        self.assertEqual("succeeded", self.status()["operation"]["phase"])
        self.assertEqual(1, self.engine.calls.count("migrate"))
        self.assertEqual(1, self.engine.calls.count("backup"))

    def test_created_target_is_reconciled_without_second_force_recreate(self):
        self.engine.crash = "after_create_queue"
        with self.assertRaises(KeyboardInterrupt):
            self.apply()
        self.recover()
        self.assertEqual("succeeded", self.status()["operation"]["phase"])
        self.assertEqual(1, self.engine.calls.count("create_queue"))
        self.assertEqual(1, self.engine.calls.count("migrate"))

    def test_ambiguous_create_with_no_observed_target_is_held_without_replay(self):
        self.engine.fault = "create_queue"
        self.apply()
        self.engine.fault = None
        self.recover()
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertEqual(1, self.engine.calls.count("create_queue"))
        self.assertNotIn("release", self.engine.calls)

    def test_fresh_prepare_identity_change_refuses_before_protect(self):
        self.api.plan_facts = lambda *_: ("execution_not_available", {"source": SOURCE, "target": TARGET, "plan_id": "0" * 64})
        self.apply()
        self.assertEqual("failed_safe", self.status()["operation"]["phase"])
        self.assertNotIn("backup", self.engine.calls)
        self.assertNotIn("migrate", self.engine.calls)

    def test_post_release_origin_failure_reacquires_target_hold(self):
        self.engine.fault = "origin_serving"
        self.apply()
        self.assertTrue(self.engine.held)
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertFalse(self.engine.running)
        self.engine.fault = None
        self.recover()
        self.assertEqual("succeeded", self.status()["operation"]["phase"])
        self.assertEqual(1, self.engine.calls.count("migrate"))

    def test_active_migration_container_blocks_recovery_even_complete_receipt(self):
        self.engine.crash = "after_migration"
        with self.assertRaises(KeyboardInterrupt):
            self.apply()
        self.engine.migration_active = True
        self.recover()
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertFalse(self.engine.targets)
        self.assertNotIn("receipt", self.engine.calls)

    def test_source_fallback_origin_is_verified_before_release(self):
        self.engine.fault = "download"
        self.engine.origin_stale = True
        self.apply()
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertTrue(self.engine.held)
        self.assertNotIn("release", self.engine.calls)

    def test_source_post_release_origin_failure_reacquires_source_fence(self):
        def failed(*_args):
            self.engine.fault = "origin_serving"
            raise UP.Refusal("download_failed")
        self.applier.artifacts.prepare = failed
        self.apply()
        self.assertTrue(self.engine.held)
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertTrue(self.status()["operation"]["apply"]["hold_owned"])
        self.assertEqual(1, self.engine.calls.count("release"))
        self.engine.fault = None
        self.recover()
        self.assertEqual("failed_safe", self.status()["operation"]["phase"])
        self.assertNotIn("migrate", self.engine.calls)

    def test_private_intent_without_public_intent_is_still_schema_ambiguous(self):
        original = self.journal.apply_checkpoint
        def interrupted(operation, checkpoint, facts, *args, **kwargs):
            if checkpoint == "migration_intent":
                # Read a U5 interruption produced by its former private-first
                # ordering; U6 now admits cancellation before private intent.
                path = self.root / "apply" / operation / "state.json"
                state = UP.read_object(path, 1_000_000, "recovery_required")
                state["stage"] = "migration_intent"
                UP.atomic_write(path, state)
                raise KeyboardInterrupt()
            return original(operation, checkpoint, facts, *args, **kwargs)
        self.journal.apply_checkpoint = interrupted
        with self.assertRaises(KeyboardInterrupt):
            self.apply()
        self.assertFalse(self.status()["operation"]["mutation_started"])
        self.recover()
        self.assertTrue(self.status()["operation"]["mutation_started"])
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertNotIn("migrate", self.engine.calls)

    def test_recovery_never_boots_oneoff_from_corrupted_staging(self):
        self.engine.crash = "after_migration"
        with self.assertRaises(KeyboardInterrupt):
            self.apply()
        target = self.root / "apply" / self.operation / "target.yml"
        target.write_text('{"services":{"web":{"image":"untrusted"}}}')
        calls = len(self.engine.calls)
        self.recover()
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertTrue(self.engine.held)
        self.assertNotIn("receipt", self.engine.calls[calls:])

    def test_unclaimed_manual_target_container_is_never_adopted(self):
        self.engine.crash = "after_migration"
        with self.assertRaises(KeyboardInterrupt):
            self.apply()
        self.engine.targets["queue"] = "c" * 64
        self.engine.target_running["queue"] = False
        self.recover()
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertNotIn("start_queue", self.engine.calls)

    def test_promotion_interruption_allows_only_exact_pair_and_completes_recovery(self):
        for crash_point in ("before_config", "after_config"):
            with self.subTest(crash_point=crash_point):
                self.setUp()
                original = self.api.atomic_write
                def interrupted(path, value):
                    if path == self.configpath and crash_point == "before_config":
                        raise KeyboardInterrupt()
                    original(path, value)
                    if path == self.configpath and crash_point == "after_config":
                        raise KeyboardInterrupt()
                self.api.atomic_write = interrupted
                with self.assertRaises(KeyboardInterrupt):
                    self.apply()
                statepath = self.root / "apply" / self.operation / "state.json"
                state = UP.read_object(statepath, 1_000_000, "fixture")
                self.assertEqual("configuration_commit_intent", state["stage"])
                self.api.atomic_write = original
                self.api.trusted = lambda *_args, **_kwargs: None
                self.config.value = UP.read_object(self.configpath, 16384, "fixture")
                self.assertTrue(APPLY.verify_transition(self.config, self.root, self.api))
                # An unrelated environment edit never uses the exception.
                env = Path(self.config.value["install_dir"]) / ".env"
                previous = env.read_bytes()
                env.write_bytes(b"unreviewed environment")
                with self.assertRaises(UP.Refusal):
                    APPLY.verify_transition(self.config, self.root, self.api)
                env.write_bytes(previous)
                if crash_point == "before_config":
                    self.api.atomic_write = interrupted
                    with self.assertRaises(KeyboardInterrupt):
                        self.recover()
                    self.api.atomic_write = original
                    self.assertTrue(APPLY.verify_transition(self.config, self.root, self.api))
                self.recover()
                self.assertEqual("succeeded", self.status()["operation"]["phase"])
                self.assertEqual(1, self.engine.calls.count("migrate"))
                self.config.verify_files()

    def test_missing_private_promotion_receipt_cannot_bypass_configuration_check(self):
        original = self.api.atomic_write
        def interrupted(path, value):
            if path == self.configpath:
                raise KeyboardInterrupt()
            original(path, value)
        self.api.atomic_write = interrupted
        with self.assertRaises(KeyboardInterrupt):
            self.apply()
        self.api.trusted = lambda *_args, **_kwargs: None
        (self.root / "apply" / self.operation / "runtime.json").unlink()
        with self.assertRaises(UP.Refusal):
            APPLY.verify_transition(self.config, self.root, self.api)

    def test_cold_start_waits_for_readiness_without_repeating_mutation(self):
        observations = []
        original = self.engine.role_ready
        def delayed(container, service):
            observations.append(service)
            if len(observations) == 1:
                raise UP.Refusal("runtime_verification_failed")
            original(container, service)
        self.engine.role_ready = delayed
        with patch.object(APPLY.time, "sleep") as sleep:
            self.apply()
        self.assertEqual("succeeded", self.status()["operation"]["phase"])
        self.assertEqual(1, sleep.call_count)
        self.assertEqual(1, self.engine.calls.count("migrate"))

    def test_initial_read_only_baseline_failure_can_verify_source_after_explicit_recovery(self):
        self.engine.pause = True
        self.apply()
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertFalse((self.root / "apply" / self.operation / "state.json").exists())
        self.engine.pause = False
        self.recover()
        self.assertEqual("failed_safe", self.status()["operation"]["phase"])
        self.assertTrue(self.engine.running)
        self.assertFalse(self.engine.held)
        self.assertNotIn("migrate", self.engine.calls)

    def test_missing_state_after_actual_effect_checkpoint_never_qualifies_as_pre_start(self):
        self.engine.crash = "after_migration"
        with self.assertRaises(KeyboardInterrupt):
            self.apply()
        (self.root / "apply" / self.operation / "state.json").unlink()
        before = len(self.engine.calls)
        self.recover()
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertTrue(self.engine.held)
        self.assertEqual(before, len(self.engine.calls))


class Response:
    def __init__(self, status, proofs):
        self.code, self.headers = status, Message()
        for proof in proofs:
            self.headers.add_header("X-Wayfindr-Update-Proof", proof)

    def __enter__(self):
        return self

    def __exit__(self, *_):
        pass


class ProofTests(unittest.TestCase):
    def proof(self, held, tamper=None):
        class Opener:
            def open(_self, request, timeout):
                challenge = request.get_header("X-wayfindr-update-challenge")
                payload = json.dumps([1, challenge, "operation" if held else None, SOURCE["version"], SOURCE["commit"]], separators=(",", ":")).encode()
                derived = hmac.digest(FIX.KEY.encode(), b"wayfindr-managed-origin-v1", "sha256")
                value = hmac.new(derived, payload, "sha256").hexdigest()
                return Response(302 if tamper == "redirect" else 503 if held else 200,
                                [] if tamper == "missing" else [value, value] if tamper == "duplicate" else ["f" * 64] if tamper == "stale" else [value])
        APPLY.origin_proof("https://fixture.invalid", {"current": FIX.KEY}, "operation", SOURCE, held, FIX.API, opener=Opener())

    def test_nonce_proof_matches_php_contract_held_and_released(self):
        self.proof(True)
        self.proof(False)

    def test_stale_missing_duplicate_and_redirect_proofs_refuse(self):
        for tamper in ("stale", "missing", "duplicate", "redirect"):
            with self.subTest(tamper=tamper), self.assertRaises(UP.Refusal) as error:
                self.proof(True, tamper)
            self.assertEqual("origin_verification_failed", error.exception.reason)

    def test_origin_network_child_is_bounded_and_never_receives_application_key(self):
        child = types.SimpleNamespace(returncode=0, communicate=lambda request, timeout: (b'{"status":503,"proof":"' + b"a" * 64 + b'"}', None), poll=lambda: 0)
        with patch.object(APPLY.subprocess, "Popen", return_value=child) as start:
            self.assertEqual(503, APPLY.bounded_origin_response("https://fixture.invalid", "a" * 64)["status"])
        self.assertEqual(["/usr/bin/python3", str(Path(APPLY.__file__).absolute()), "--origin-probe"], start.call_args.args[0])
        self.assertEqual("/nonexistent", start.call_args.kwargs["env"]["HOME"])

    def test_stalled_origin_dns_or_headers_terminates_only_network_child(self):
        from unittest.mock import Mock
        child = Mock(returncode=None)
        child.poll.return_value = None
        child.communicate.side_effect = [subprocess.TimeoutExpired("probe", 12), (b"", None)]
        with patch.object(APPLY.subprocess, "Popen", return_value=child), self.assertRaises(subprocess.TimeoutExpired):
            APPLY.bounded_origin_response("https://fixture.invalid", "a" * 64)
        child.kill.assert_called_once()

    def test_origin_requires_reviewed_root_url_and_no_credentials_or_controls(self):
        for origin in ("ftp://fixture.invalid", "https://a:b@fixture.invalid", "https://fixture.invalid/path", "https://fixture.invalid/?x=1", "https://fixture.invalid/#fragment", "https://fixture.invalid\n", "https://fixture.invalid:0"):
            self.assertFalse(APPLY.valid_origin(origin), origin)
        self.assertTrue(APPLY.valid_origin("http://127.0.0.1:8000"))


class DockerOneoffTests(unittest.TestCase):
    def setUp(self):
        self.operation = str(uuid.uuid4())
        self.reference = "ghcr.io/adamgreenwell/wayfindr:" + TARGET["version"] + "@" + TARGET["image_digest"]
        self.state = {"source": SOURCE, "target": TARGET, "plan_id": "d" * 64, "source_context": {"capture_binding_sha256": "e" * 64},
                      "artifacts": {"config_digest": TARGET_IMAGE, "local_image_id": TARGET["image_digest"], "execution_reference": self.reference, "execution_platform": "linux/arm64", "platform_manifest_descriptor": PLATFORM_DESCRIPTOR}}
        self.calls = []
        self.api = types.SimpleNamespace(**vars(FIX.API))
        self.api.capture = lambda args, **kwargs: (self.calls.append(args) or (0, b'{"verified":true}'))
        config = types.SimpleNamespace(value={"install_dir": "/opt/wayfindr", "compose_project": "wayfindr-self-hosting"})
        self.engine = APPLY.Engine(config, self.api, "/var/lib/wayfindr-updater")
        self.record = {"image": TARGET["image_digest"], "project": config.value["compose_project"], "service": "web", "oneoff": "True",
                       "state": {"Status": "exited", "Running": False, "Paused": False, "Restarting": False, "Dead": False, "OOMKilled": False, "Error": "", "ExitCode": 0}}
        self.layout = {"image_reference": self.reference, "entrypoint": ["php"], "operation": self.operation, "image_manifest_descriptor": PLATFORM_DESCRIPTOR}
        self.engine.base.inspect = lambda *_: copy.deepcopy(self.record)
        self.engine.base.oneoff_active = lambda *_: False
        self.engine.base.call = lambda args, *_args, **_kwargs: self.calls.append(args) or ""
        self.engine.layout = lambda *_: copy.deepcopy(self.layout)

    def test_every_oneoff_checks_actual_containerd_id_and_exact_command_before_acceptance(self):
        for action in ("protocol", "assess", "migrate", "receipt", "verify", "fence"):
            self.layout["cmd"] = self.engine.command(self.operation, self.state, action)
            with self.subTest(action=action):
                self.assertEqual({"verified": True}, self.engine.oneoff(Path("/var/private"), self.operation, self.state, action))
                command = next(args for args in reversed(self.calls) if "run" in args)
                self.assertIn("--pull=never", command)
                self.assertNotIn("--rm", command)

    def test_wrong_oneoff_image_selector_command_owner_or_state_is_never_accepted(self):
        for action in ("protocol", "assess", "migrate", "receipt", "verify", "fence"):
            self.layout["cmd"] = self.engine.command(self.operation, self.state, action)
            mutations = (("record", "image", TARGET_IMAGE), ("record", "project", "other"), ("record", "oneoff", "False"),
                         ("layout", "image_reference", TARGET_IMAGE), ("layout", "entrypoint", ["sh"]), ("layout", "cmd", ["artisan", "migrate"]),
                         ("layout", "operation", "other"), ("status", "Running", True), ("status", "Status", "created"), ("status", "ExitCode", False))
            for area, field, wrong in mutations:
                selected = self.record["state"] if area == "status" else self.record if area == "record" else self.layout
                good = selected[field]
                selected[field] = wrong
                with self.subTest(action=action, area=area, field=field):
                    with self.assertRaises(UP.Refusal) as failure:
                        self.engine.check_oneoff("owned-container", self.operation, self.state, action)
                    self.assertEqual("migration_ambiguous" if action == "migrate" else "runtime_verification_failed", failure.exception.reason)
                selected[field] = good

    def test_same_index_with_wrong_or_missing_selected_manifest_refuses_every_oneoff(self):
        for action in ("protocol", "assess", "migrate", "receipt", "verify", "fence"):
            self.layout["cmd"] = self.engine.command(self.operation, self.state, action)
            for observed in (None, {**PLATFORM_DESCRIPTOR, "digest": TARGET["image_digest"]}, {**PLATFORM_DESCRIPTOR, "platform": {"os": "linux", "architecture": "amd64"}}, {**PLATFORM_DESCRIPTOR, "size": 7312}):
                self.layout["image_manifest_descriptor"] = observed
                with self.subTest(action=action, observed=observed), self.assertRaises(UP.Refusal):
                    self.engine.check_oneoff("owned-container", self.operation, self.state, action)

    def test_existing_migration_is_never_removed_or_replayed(self):
        self.engine.base.call = lambda args, *_args, **_kwargs: self.calls.append(args) or ("a" * 64 if args[0] == "ps" else "")
        with self.assertRaises(UP.Refusal) as failure:
            self.engine.oneoff(Path("/var/private"), self.operation, self.state, "migrate")
        self.assertEqual("migration_ambiguous", failure.exception.reason)
        self.assertFalse(any("run" in args or args[0] == "rm" for args in self.calls))


class DockerLayoutTests(unittest.TestCase):
    def setUp(self):
        self.api = types.SimpleNamespace(**vars(FIX.API))
        self.config = types.SimpleNamespace(value={"install_dir": "/opt/wayfindr", "compose_project": "wayfindr-self-hosting"})
        self.engine = APPLY.Engine(self.config, self.api, "/var/lib/wayfindr-updater")
        self.reference = "ghcr.io/adamgreenwell/wayfindr:" + TARGET["version"] + "@" + TARGET["image_digest"]
        self.state = {"operation_id": "owned-operation", "started": 1, "artifacts": {"config_digest": TARGET_IMAGE, "local_image_id": TARGET["image_digest"],
                      "execution_reference": self.reference, "execution_platform": "linux/arm64", "platform_manifest_descriptor": PLATFORM_DESCRIPTOR}, "layouts": {"queue": {"mounts": []}}}
        self.layout = {"env": ["APP_KEY=private", "WAYFINDR_AUTO_MIGRATE=0"], "mounts": [], "cmd": ["php", "artisan", "queue:work", "redis"],
                       "entrypoint": ["wayfindr-entrypoint"], "user": "1000", "workdir": "/app/apps/server", "operation": "owned-operation", "created": "2026-01-01T00:00:00Z", "image_reference": self.reference, "image_manifest_descriptor": PLATFORM_DESCRIPTOR}
        self.engine.layout = lambda *_: copy.deepcopy(self.layout)
        self.rendered = {"services": {"queue": {"image": self.reference, "platform": "linux/arm64", "environment": {"APP_KEY": "private", "WAYFINDR_AUTO_MIGRATE": "0"}, "command": ["php", "artisan", "queue:work", "redis"]}}}
        self.engine.render = lambda *_: copy.deepcopy(self.rendered)
        self.image = {"env": [], "cmd": ["frankenphp"], "entrypoint": ["wayfindr-entrypoint"], "user": "1000", "workdir": "/app/apps/server"}
        self.calls = []
        def call(args, *_args, **_kwargs):
            self.calls.append(args)
            return copy.deepcopy(self.image) if args[0] == "image" else "php artisan queue:work redis"
        self.engine.base.call = call

    def test_prestart_inspection_checks_configuration_without_requiring_live_process(self):
        self.engine.target_layout("a" * 64, "queue", Path("/var/private"), self.state, processes=False)
        self.assertFalse(any(args[0] == "top" for args in self.calls))
        self.engine.target_layout("a" * 64, "queue", Path("/var/private"), self.state)
        self.assertTrue(any(args[0] == "top" for args in self.calls))
        self.assertTrue(all(args[-1] == self.reference for args in self.calls if args[0] == "image"))

    def test_wrong_operation_environment_role_mount_entrypoint_or_age_refuses_before_start(self):
        for field, wrong in (("operation", "other"), ("env", ["APP_KEY=private", "WAYFINDR_AUTO_MIGRATE=1"]), ("cmd", ["php", "artisan", "migrate"]),
                             ("mounts", [{"Destination": "/app/apps/server/storage", "Name": "other"}]), ("entrypoint", ["sh"]), ("created", "1970-01-01T00:00:00Z"), ("image_reference", TARGET_IMAGE)):
            with self.subTest(field=field):
                original = copy.deepcopy(self.layout)
                self.layout[field] = wrong
                with self.assertRaises(UP.Refusal):
                    self.engine.target_layout("a" * 64, "queue", Path("/var/private"), self.state, processes=False)
                self.layout = original

    def test_rendered_floating_image_or_wrong_platform_refuses_before_start(self):
        for field, wrong in (("image", "ghcr.io/adamgreenwell/wayfindr:latest"), ("platform", "linux/amd64")):
            original = copy.deepcopy(self.rendered)
            self.rendered["services"]["queue"][field] = wrong
            with self.subTest(field=field), self.assertRaises(UP.Refusal):
                self.engine.target_layout("a" * 64, "queue", Path("/var/private"), self.state, processes=False)
            self.rendered = original

    def test_same_index_wrong_selected_manifest_refuses_service_before_start(self):
        for observed in (None, {**PLATFORM_DESCRIPTOR, "digest": TARGET_IMAGE}, {**PLATFORM_DESCRIPTOR, "platform": {"os": "linux", "architecture": "amd64"}}):
            self.layout["image_manifest_descriptor"] = observed
            with self.subTest(observed=observed), self.assertRaises(UP.Refusal):
                self.engine.target_layout("a" * 64, "queue", Path("/var/private"), self.state, processes=False)

    def test_classic_config_id_allows_absent_descriptor_but_rejects_wrong_present_descriptor(self):
        self.state["artifacts"]["local_image_id"] = TARGET_IMAGE
        self.layout["image_manifest_descriptor"] = None
        self.engine.target_layout("a" * 64, "queue", Path("/var/private"), self.state, processes=False)
        self.layout["image_manifest_descriptor"] = {**PLATFORM_DESCRIPTOR, "digest": TARGET_IMAGE}
        with self.assertRaises(UP.Refusal):
            self.engine.target_layout("a" * 64, "queue", Path("/var/private"), self.state, processes=False)

    def test_fixed_cli_does_not_use_reserved_version_option_or_raw_migrate(self):
        state = {"source": SOURCE, "target": TARGET, "plan_id": "d" * 64, "source_context": {"capture_binding_sha256": "e" * 64}}
        command = self.engine.command("owned", state, "migrate")
        self.assertIn("--target-version=1.1.2", command)
        self.assertNotIn("--version=1.1.2", command)
        self.assertIn("wayfindr:managed-apply", command)


if __name__ == "__main__":
    unittest.main()
