#!/usr/bin/env python3
"""Exercise the host protection state machine with real private archive custody.

Docker interactions are fixtures; no host enrollment or provider writes occur.
"""

import copy
import hashlib
import gzip
import importlib.util
import io
import json
from pathlib import Path
import sys
import tarfile
import tempfile
import threading
import types
import unittest
import uuid
from unittest.mock import patch

sys.dont_write_bytecode = True
ROOT = Path(__file__).absolute().parents[1]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


UP = module("protection_updater", "scripts/self-host/updater.py")
PROTECT = module("protection_engine", "scripts/self-host/update_protection.py")
FIXTURE = module("protection_fixture", "scripts/test_protection_archive.py")
HELPER = module("protection_helper_fixture", "scripts/test_host_updater.py")
API = types.SimpleNamespace(**{name: getattr(UP, name) for name in ("Refusal", "capture", "trusted", "strict_json", "atomic_write", "read_object", "encoded")})
SOURCE = {"version": "1.1.1", "commit": FIXTURE.COMMIT}
IMAGE = "sha256:" + "b" * 64
KEY = "k" * 32
KEY_FINGERPRINTS = [hashlib.sha256(KEY.encode()).hexdigest()]


class Config:
    installation_id = HELPER.INSTALLATION
    token = HELPER.TOKEN

    def __init__(self, directory):
        self.value = {"client_uid": 1000, "compose_project": "wayfindr-self-hosting", "install_dir": str(directory),
                      "image_reference": "ghcr.io/adamgreenwell/wayfindr:1.1.1", "overlay_sha256": "c" * 64}
        for name in (".env", "compose.yml", "compose.updater.yml", "install.sh"):
            (directory / name).write_text("fixture-private-value-" + name)

    def verify_files(self):
        pass


class Engine:
    def __init__(self):
        self.ids = {service: str(index + 1) * 64 for index, service in enumerate(PROTECT.SERVICES)}
        self.running = True
        self.held = False
        self.calls = []
        self.fault = None
        self.active = False
        self.pause = False
        self.extra_writer = False
        self.crash_after_stop = False
        self.ledger = False

    def service_ids(self):
        return self.ids.copy()

    def inspect(self, container):
        service = next(service for service, identifier in self.ids.items() if identifier == container)
        return {"id": container, "image": IMAGE, "project": "wayfindr-self-hosting", "service": service, "restarts": 0,
                "state": {"Running": self.running, "Paused": self.pause, "Restarting": False, "Dead": False,
                          "OOMKilled": False, "Error": "", "ExitCode": 0}}

    def image_source(self, image):
        return SOURCE.copy()

    def check_selected_image(self, image):
        self.calls.append("image_verified")

    def effective_keys(self, container):
        return {"schema": 1, "current": KEY, "previous": [], "cipher": "AES-256-CBC", "fingerprints": KEY_FINGERPRINTS, "capture_binding_sha256": "d" * 64}

    def environment_binding(self, container):
        return {"sha256": "e" * 64, "keys": ["APP_KEY", "DB_DATABASE"]}

    def require_source(self, ids, image):
        pass

    def dependencies(self):
        return {"6" * 64, "7" * 64}

    def writers(self, allowed):
        if self.extra_writer:
            raise UP.Refusal("writer_unverified")

    def window(self, container, operation, action):
        self.calls.append(action)
        if action == "enter":
            self.held = True
        if action == "release":
            self.held = False
        return {"schema": 1, "operation_id": operation if self.held else None, "held": self.held,
                "ordinary_maintenance": self.fault == "ordinary", "source": {**SOURCE, "profile": "image"}, "ledger_supported": True}

    def ensure_window(self, ids, operation, image):
        self.calls.append("ensure_fence")
        return self.window(ids["web"], operation, "enter")

    def drain(self, ids, timeout):
        self.calls.append("drain")
        assert self.held and ids == self.ids
        if self.fault == "drain":
            raise UP.Refusal("drain_timeout")
        self.running = False
        if self.crash_after_stop:
            raise KeyboardInterrupt()

    def stop_supported(self):
        pass

    def backup_name(self, operation):
        return "wayfindr-updater-backup-" + operation

    def backup_active(self, operation):
        return self.active

    def backup(self, operation, image, context):
        self.calls.append("backup")
        assert self.held and not self.running and image == IMAGE
        if self.fault == "backup":
            raise UP.Refusal("backup_failed")
        if self.fault == "backup_active":
            self.active = True
            raise UP.Refusal("backup_failed")
        files = {"database.sql": b"SQL recovery fixture\n", "attachments/attachments/key.bin": b"local binary"}
        manifest = FIXTURE.manifest(files)
        manifest["app_key_fingerprints"] = KEY_FINGERPRINTS if self.fault != "keys" else ["1" * 64]
        manifest["protective_operation"] = operation
        raw = json.dumps(manifest, indent=4).encode() + b"\n"
        self.archive = gzip.compress(FIXTURE.tar_bytes(files, raw), mtime=0)
        receipt = FIXTURE.receipt(self.archive, raw)
        receipt["operation_id"] = operation
        receipt["coverage"]["erasure_ledger_present"] = self.ledger
        if self.fault == "receipt":
            receipt["archive_bytes"] = True
        return receipt

    def copy_tar(self, container, source, destination, maximum):
        self.calls.append("copy_ledger" if source.endswith("erasure-ledger") else "copy_archive")
        with tarfile.open(destination, "w", format=tarfile.USTAR_FORMAT) as archive:
            if source.endswith("erasure-ledger"):
                member = tarfile.TarInfo("erasure-ledger")
                member.type = tarfile.DIRTYPE
                archive.addfile(member)
                member = tarfile.TarInfo("erasure-ledger/fixture.json")
                if self.fault == "ledger_link":
                    member.type, member.linkname = tarfile.SYMTYPE, "/outside"
                    archive.addfile(member)
                else:
                    content = b"separate erasure fixture"
                    member.size = len(content)
                    archive.addfile(member, io.BytesIO(content))
            else:
                content = self.archive if self.fault != "archive" else b"corrupted archive"
                member = tarfile.TarInfo("archive.tar.gz")
                member.size = len(content)
                archive.addfile(member, io.BytesIO(content))
        destination.chmod(0o600)

    def start(self, ids):
        self.calls.append("start")
        assert self.held and ids and all(self.ids[service] == container for service, container in ids.items())
        self.running = True

    def web_status(self, container):
        if self.fault == "post_release" and "release" in self.calls and not self.held:
            return 500
        return 503 if self.held else 200

    def reverb_ready(self, container):
        pass


class ProtectionTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        install = self.root / "install"
        install.mkdir()
        self.config, self.engine = Config(install), Engine()
        self.path = self.root / "journal.json"
        UP.atomic_write(self.path, UP.initial_journal(self.config.installation_id))
        self.journal = UP.Journal(self.path, self.config.installation_id, secure=False)
        self.generation = str(uuid.uuid4())
        self.journal.begin_generation(self.generation)
        self.operation = self.journal.accept(str(uuid.uuid4()), "v1.1.2", self.generation)[0]
        self.journal.preparing(self.operation)
        self.journal.finish(self.operation, "execution_not_available", {"source": SOURCE.copy(), "plan_id": "d" * 64,
            "target": {"tag": "v1.1.2", "version": "1.1.2", "commit": "e" * 40, "image_digest": "sha256:" + "f" * 64}})
        self.protector = PROTECT.Protector(self.config, self.journal, self.root, API, self.engine, secure=False)

    def status(self):
        return self.journal.status(self.operation)

    def protect(self):
        self.assertTrue(self.journal.claim_protection(self.operation, self.generation))
        self.protector(self.operation)

    def test_verified_custody_then_original_services_and_owned_release(self):
        self.engine.ledger = True
        self.protect()
        result = self.status()
        self.assertIsNone(result["active_operation"])
        self.assertEqual("protection_verified", result["operation"]["error"])
        evidence = result["operation"]["protection"]
        self.assertEqual("verified", evidence["phase"])
        self.assertTrue(evidence["custody_verified"])
        self.assertTrue(evidence["services_recovered"])
        self.assertFalse(evidence["hold_owned"])
        self.assertEqual(1, evidence["external_attachment_disks"])
        self.assertEqual("not-configured", evidence["offsite_verification"])
        calls = self.engine.calls
        for before, after in (("enter", "drain"), ("drain", "backup"), ("backup", "copy_archive"), ("copy_ledger", "start"), ("ensure_fence", "start"), ("start", "release")):
            self.assertLess(calls.index(before), calls.index(after))
        custody = self.root / "protection" / self.operation
        self.assertEqual(0o700, custody.stat().st_mode & 0o777)
        for name in ("archive.tar.gz", "config-env", "installation.json", "keys.json", "erasure-ledger.tar", "receipt.json", "custody.json"):
            self.assertEqual(0o600, (custody / name).stat().st_mode & 0o777)
        serialized = json.dumps(result)
        for secret in ("fixture-private-value", "key.bin", "fixture.json", "/install", "app_key_fingerprints", KEY):
            self.assertNotIn(secret, serialized)
        self.assertFalse(result["operation"]["mutation_started"])
        self.assertFalse(self.journal.claim_protection(self.operation, self.generation))
        self.assertEqual(1, calls.count("backup"))

    def test_drain_timeout_retains_hold_and_never_snapshots_or_restarts(self):
        self.engine.fault = "drain"
        self.protect()
        self.assertEqual(self.operation, self.status()["active_operation"])
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertEqual("drain_timeout", self.status()["operation"]["protection"]["error"])
        self.assertTrue(self.engine.held)
        self.assertNotIn("backup", self.engine.calls)
        self.assertNotIn("start", self.engine.calls)

    def test_failed_or_invalid_backup_recovers_checked_old_release(self):
        for fault in ("backup", "receipt", "archive", "ledger_link", "keys"):
            with self.subTest(fault=fault):
                self.setUp()
                self.engine.fault, self.engine.ledger = fault, fault == "ledger_link"
                self.protect()
                self.assertIsNone(self.status()["active_operation"])
                self.assertEqual("blocked", self.status()["operation"]["phase"])
                self.assertFalse(self.status()["operation"]["protection"]["custody_verified"])
                self.assertTrue(self.engine.running)
                self.assertFalse(self.engine.held)
                self.assertEqual(1, self.engine.calls.count("backup"))

    def test_active_backup_after_client_failure_keeps_writers_stopped(self):
        self.engine.fault = "backup_active"
        self.protect()
        self.assertTrue(self.engine.held)
        self.assertFalse(self.engine.running)
        self.assertEqual(self.operation, self.status()["active_operation"])
        self.assertNotIn("start", self.engine.calls)

    def test_baseline_refusal_preserves_ordinary_maintenance_and_no_side_effects(self):
        for fault in ("ordinary", "paused", "extra"):
            with self.subTest(fault=fault):
                self.setUp()
                self.engine.fault = "ordinary" if fault == "ordinary" else None
                self.engine.pause, self.engine.extra_writer = fault == "paused", fault == "extra"
                self.protect()
                self.assertIsNone(self.status()["active_operation"])
                for action in ("enter", "drain", "backup", "start", "release"):
                    self.assertNotIn(action, self.engine.calls)

    def test_crash_after_stop_requires_explicit_recovery_and_reestablishes_fence(self):
        self.engine.crash_after_stop = True
        self.journal.claim_protection(self.operation, self.generation)
        with self.assertRaises(KeyboardInterrupt):
            self.protector(self.operation)
        self.engine.held = False  # Missing marker cannot authorize a normal bootstrap.
        reloaded = UP.Journal(self.path, self.config.installation_id, secure=False)
        reloaded.begin_generation(str(uuid.uuid4()))
        self.assertEqual("recovery_required", reloaded.status()["operation"]["phase"])
        with self.assertRaises(UP.Refusal):
            reloaded.reconcile(self.operation)
        reloaded.claim_protection(self.operation, str(uuid.uuid4()), recover=True)
        self.protector.journal, self.journal = reloaded, reloaded
        self.protector(self.operation, recovery=True)
        self.assertIsNone(self.status()["active_operation"])
        self.assertNotIn("backup", self.engine.calls)
        self.assertLess(self.engine.calls.index("ensure_fence"), self.engine.calls.index("start"))
        self.assertFalse(self.engine.held)

    def test_post_release_failure_reestablishes_owned_hold(self):
        self.engine.fault = "post_release"
        self.protect()
        self.assertEqual(self.operation, self.status()["active_operation"])
        self.assertTrue(self.engine.held)
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertTrue(self.status()["operation"]["protection"]["hold_owned"])
        self.assertFalse(self.status()["operation"]["protection"]["services_recovered"])

    def test_root_action_only_and_accepted_intent_precedes_async_worker(self):
        entered, resume = threading.Event(), threading.Event()
        observed = []
        def protector(operation, recovery):
            observed.append(json.loads(self.path.read_bytes())["operations"][operation]["checkpoint"])
            entered.set()
            resume.wait(5)
        controller = UP.Controller(self.config, self.journal, protector=protector)
        self.addCleanup(controller.workers.shutdown, wait=True)
        request = HELPER.request("protect", operation_id=self.operation)
        with self.assertRaises(UP.Refusal) as refusal:
            controller.dispatch(request, 1000)
        self.assertEqual("authentication_failed", refusal.exception.reason)
        result = controller.dispatch(request, 0)
        self.assertTrue(entered.wait(2))
        self.assertEqual("protection_started", result["operation"]["checkpoint"])
        self.assertEqual(["protection_started"], observed)
        resume.set()

    def test_protection_schema_unknown_fields_types_and_overclaims_refuse(self):
        self.protect()
        valid = self.journal.value
        mutations = {"phase": [], "archive_bytes": True, "archive_sha256": "bad", "source_image_id": "mutable-tag",
                     "local_attachment_disks": -1, "offsite_uploaded": None, "hold_owned": True,
                     "custody_verified": False, "error": "configuration_changed", "customer_secret": "private"}
        for key, bad in mutations.items():
            value = copy.deepcopy(valid)
            value["operations"][self.operation]["protection"][key] = bad
            with self.subTest(key=key), self.assertRaises(UP.Refusal) as refusal:
                UP.validate_journal(value, self.config.installation_id)
            self.assertEqual("journal_corrupt", refusal.exception.reason)

    def test_legacy_version_journal_stays_readable(self):
        value = copy.deepcopy(self.journal.value)
        value["operations"][self.operation]["executor_version"] = "0.1.0"
        UP.validate_journal(value, self.config.installation_id)

    def test_missing_overlay_hash_blocks_before_fence(self):
        del self.config.value["overlay_sha256"]
        self.protect()
        self.assertEqual("protection_unavailable", self.status()["operation"]["error"])
        self.assertNotIn("enter", self.engine.calls)

    def test_runtime_key_mismatch_or_invalid_cipher_blocks_before_fence(self):
        original = self.engine.effective_keys
        def different(container):
            value = original(container)
            if container == self.engine.ids["queue"]:
                value = {**value, "current": "p" * 32, "fingerprints": [hashlib.sha256(b"p" * 32).hexdigest()]}
            return value
        self.engine.effective_keys = different
        self.protect()
        self.assertEqual("custody_failed", self.status()["operation"]["error"])
        self.assertNotIn("enter", self.engine.calls)

    def test_partial_resume_can_finish_but_partial_drain_remains_held(self):
        self.journal.claim_protection(self.operation, self.generation)
        context, keys = self.protector.baseline(self.operation)
        directory = self.root / "protection" / self.operation
        directory.mkdir(parents=True)
        UP.atomic_write(directory / "keys.json", keys)
        stopped = {self.engine.ids["queue"]}
        original_inspect = self.engine.inspect
        def inspect(container):
            value = original_inspect(container)
            value["state"]["Running"] = container not in stopped
            return value
        self.engine.inspect = inspect
        context["stage"] = "drain_intent"
        with self.assertRaises(UP.Refusal):
            self.protector.recover(directory, self.operation, context)
        self.assertNotIn("start", self.engine.calls)
        context["stage"] = "resume_intent"
        resumed = []
        def start(ids):
            self.assertTrue(self.engine.held)
            resumed.append(ids)
            stopped.clear()
        self.engine.start = start
        self.protector.recover(directory, self.operation, context)
        self.assertEqual([{"queue": self.engine.ids["queue"]}], resumed)
        self.assertFalse(self.engine.held)

    def test_manual_start_reset_of_historical_restart_counts_is_expected(self):
        restarts = {service: 2 for service in PROTECT.SERVICES}
        original_inspect = self.engine.inspect
        def inspect(container):
            value = original_inspect(container)
            value["restarts"] = restarts[value["service"]]
            return value
        original_start = self.engine.start
        def start(ids):
            original_start(ids)
            for service in ids:
                restarts[service] = 0
        self.engine.inspect, self.engine.start = inspect, start
        self.protect()
        self.assertEqual("protection_verified", self.status()["operation"]["error"])

    def test_fixed_docker_commands_pin_old_image_and_never_force_kill(self):
        default = PROTECT.Protector(self.config, self.journal, self.root, API, secure=False)
        self.assertIsInstance(default.engine, PROTECT.DockerEngine)
        self.assertEqual(self.root, default.engine.state_dir)
        engine = PROTECT.DockerEngine(self.config, API, self.root)
        directory = self.root / "protection" / self.operation
        directory.mkdir(parents=True)
        UP.atomic_write(directory / "image.yml", {"services": {"web": {"image": IMAGE}}})
        commands = []
        def capture(command, **kwargs):
            commands.append(command)
            return 0, b"{}"
        fake_api = types.SimpleNamespace(**API.__dict__)
        fake_api.capture, fake_api.trusted = capture, lambda *_args, **_kwargs: None
        engine.api = fake_api
        engine.drain(self.engine.ids, 120)
        self.assertIn("--timeout=-1", commands[0])
        self.assertIn("--signal=TERM", commands[0])
        engine.inspect = lambda _: {"state": {"Running": False}}
        engine.require_source = lambda *_: None
        engine.ensure_window(self.engine.ids, self.operation, IMAGE)
        command = commands[-1]
        for option in ("--entrypoint", "--no-deps", "--pull=never", str(directory / "image.yml")):
            self.assertIn(option, command)
        self.assertEqual("php", command[command.index("--entrypoint") + 1])
        for forbidden in ("kill", "up", "migrate", "pull", "restart"):
            self.assertNotIn(forbidden, command)


if __name__ == "__main__":
    unittest.main(verbosity=2)
