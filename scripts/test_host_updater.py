#!/usr/bin/env python3
"""Exercise durable helper ownership without host enrollment or application writes."""

import base64
import copy
import importlib.util
import json
import os
from pathlib import Path
import socket
import stat
import subprocess
import sys
import tempfile
import threading
import time
import types
import unittest
import uuid
from concurrent.futures import ThreadPoolExecutor
from unittest.mock import patch

sys.dont_write_bytecode = True
ROOT = Path(__file__).absolute().parents[1]
SPEC = importlib.util.spec_from_file_location("host_updater", ROOT / "scripts/self-host/updater.py")
UP = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(UP)
INSTALLATION = "1567a42e-bcc8-4bf9-8a57-6a48d107aefe"
TOKEN = "a" * 64


class FakeConfig:
    installation_id = INSTALLATION
    token = TOKEN
    value = {"client_uid": 1000, "image_reference": "ghcr.io/adamgreenwell/wayfindr:1.2.3"}

    def verify_files(self):
        pass

    def capabilities(self):
        return {"helper": {"capabilities": ["plan", "status"]}}


def request(action="status", **extra):
    return {"protocol": 1, "installation_id": INSTALLATION, "nonce": uuid.uuid4().hex,
            "issued_at": int(time.time()), "action": action, **extra}


class HelperTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name) / "journal.json"
        UP.atomic_write(self.path, UP.initial_journal(INSTALLATION))
        self.journal = UP.Journal(self.path, INSTALLATION, secure=False)

    def controller(self, preparer=None):
        controller = UP.Controller(FakeConfig(), self.journal, preparer or (lambda _: ("execution_not_available", None)))
        self.addCleanup(controller.workers.shutdown, wait=True)
        return controller

    def refusal(self, reason, function, *args, **kwargs):
        with self.assertRaises(UP.Refusal) as failure:
            function(*args, **kwargs)
        self.assertEqual(reason, failure.exception.reason)

    def accept(self, tag="v1.2.4"):
        return self.journal.accept(str(uuid.uuid4()), tag, str(uuid.uuid4()))[0]

    def test_atomic_journal_is_private_and_exactly_readable(self):
        self.assertEqual(0o600, self.path.stat().st_mode & 0o777)
        self.assertEqual(UP.initial_journal(INSTALLATION), json.loads(self.path.read_bytes()))
        self.assertEqual([], list(self.path.parent.glob(".journal-*")))

    def test_missing_and_corrupt_journals_never_initialize_or_clear_ownership(self):
        for raw in (b"{}", b"{", b'{"schema":1,"schema":1}', b"[]"):
            with self.subTest(raw=raw):
                self.path.write_bytes(raw)
                self.refusal("journal_corrupt", UP.Journal, self.path, INSTALLATION, secure=False)
                self.assertEqual(raw, self.path.read_bytes())
        self.path.unlink()
        self.refusal("journal_corrupt", UP.Journal, self.path, INSTALLATION, secure=False)
        self.assertFalse(self.path.exists())

    def test_future_mutation_unknown_fields_and_typed_corruption_refuse(self):
        operation_id = self.accept()
        mutations = [("mutation_started", True), ("phase", "applying"), ("phase", []),
                     ("checkpoint", {}), ("error", []), ("events", []), ("extra_secret", "customer-data")]
        for key, bad in mutations:
            value = copy.deepcopy(self.journal.value)
            value["operations"][operation_id][key] = bad
            with self.subTest(key=key, bad=bad):
                self.refusal("journal_corrupt", UP.validate_journal, value, INSTALLATION)
        value = copy.deepcopy(self.journal.value)
        value["operations"][operation_id]["events"][0]["code"] = []
        self.refusal("journal_corrupt", UP.validate_journal, value, INSTALLATION)

    def test_same_request_is_durable_idempotency_and_changed_target_refuses(self):
        request_id, generation = str(uuid.uuid4()), str(uuid.uuid4())
        operation_id, created = self.journal.accept(request_id, "v1.2.4", generation)
        self.assertTrue(created)
        reloaded = UP.Journal(self.path, INSTALLATION, secure=False)
        self.assertEqual((operation_id, False), reloaded.accept(request_id, "v1.2.4", generation))
        self.refusal("idempotency_conflict", reloaded.accept, request_id, "v1.2.5", generation)
        self.refusal("operation_busy", reloaded.accept, str(uuid.uuid4()), "v1.2.5", generation)

    def test_concurrent_requests_accept_only_one_operation(self):
        generation = str(uuid.uuid4())
        def submit(_):
            try:
                return self.journal.accept(str(uuid.uuid4()), "v1.2.4", generation)
            except UP.Refusal as failure:
                return failure.reason
        with ThreadPoolExecutor(max_workers=12) as pool:
            results = list(pool.map(submit, range(24)))
        self.assertEqual(1, len([result for result in results if isinstance(result, tuple)]))
        self.assertEqual(23, results.count("operation_busy"))
        self.assertEqual(1, len(json.loads(self.path.read_bytes())["operations"]))

    def test_accepted_commit_precedes_worker_and_client_cancellation_does_not_cancel(self):
        started, finish = threading.Event(), threading.Event()
        observed = []
        def preparer(_):
            disk = json.loads(self.path.read_bytes())
            observed.append(disk["operations"][disk["active_operation"]]["checkpoint"])
            started.set()
            finish.wait(3)
            return "execution_not_available", None
        controller = self.controller(preparer)
        self.addCleanup(finish.set)
        payload = request("prepare", request_id=str(uuid.uuid4()), release_tag="v1.2.4")
        response = controller.dispatch(payload, 1000)
        self.assertTrue(started.wait(2))
        operation_id = response["operation"]["operation_id"]
        self.assertEqual(["prepare_started"], observed)
        # Caller discards its response; independent worker remains running.
        del response
        self.assertEqual(operation_id, self.journal.status()["active_operation"])
        retry = controller.dispatch({**payload, "nonce": uuid.uuid4().hex}, 1000)
        self.assertEqual(operation_id, retry["operation"]["operation_id"])
        finish.set()
        controller.workers.shutdown(wait=True)
        self.assertIsNone(self.journal.status()["active_operation"])
        self.assertEqual("execution_not_available", self.journal.status()["operation"]["error"])

    def test_restart_holds_stale_operation_until_explicit_root_reconciliation(self):
        operation_id = self.accept()
        self.journal.preparing(operation_id)
        self.journal.value["heartbeat_at"] = 1
        self.journal.commit(self.journal.value)
        reloaded = UP.Journal(self.path, INSTALLATION, secure=False)
        reloaded.begin_generation(str(uuid.uuid4()))
        status = reloaded.status()
        self.assertEqual("reconciliation_required", status["operation"]["phase"])
        self.assertEqual(operation_id, status["active_operation"])
        self.refusal("operation_busy", reloaded.accept, str(uuid.uuid4()), "v1.2.4", str(uuid.uuid4()))
        # Further restarts do not grow the event list or infer timeout success.
        before = len(status["operation"]["events"])
        reloaded.begin_generation(str(uuid.uuid4()))
        self.assertEqual(before, len(reloaded.status()["operation"]["events"]))
        reloaded.reconcile(operation_id)
        self.assertEqual("interrupted_prepare", reloaded.status()["operation"]["error"])
        self.assertIsNone(reloaded.status()["active_operation"])

    def test_actual_process_death_preserves_accepted_operation(self):
        code = """
import importlib.util, pathlib, sys, uuid, os
spec=importlib.util.spec_from_file_location('up',sys.argv[1]); up=importlib.util.module_from_spec(spec); spec.loader.exec_module(up)
j=up.Journal(pathlib.Path(sys.argv[2]),sys.argv[3],secure=False)
j.accept(str(uuid.uuid4()),'v1.2.4',str(uuid.uuid4()))
os._exit(9)
"""
        child = subprocess.run([sys.executable, "-B", "-c", code, str(ROOT / "scripts/self-host/updater.py"), str(self.path), INSTALLATION], capture_output=True)
        self.assertEqual(9, child.returncode)
        reloaded = UP.Journal(self.path, INSTALLATION, secure=False)
        reloaded.begin_generation(str(uuid.uuid4()))
        self.assertEqual("reconciliation_required", reloaded.status()["operation"]["phase"])
        self.assertFalse(reloaded.status()["operation"]["mutation_started"])

    def test_uncertain_fsync_holds_journal_and_never_starts_worker(self):
        controller = self.controller()
        with patch.object(UP.os, "fsync", side_effect=[None, OSError("synthetic durability failure")]):
            self.refusal("journal_unavailable", controller.dispatch, request("prepare", request_id=str(uuid.uuid4()), release_tag="v1.2.4"), 1000)
        self.assertTrue(self.journal.failed)
        disk = UP.Journal(self.path, INSTALLATION, secure=False)
        self.assertEqual("accepted", disk.status()["operation"]["phase"])
        self.refusal("journal_unavailable", self.journal.accept, str(uuid.uuid4()), "v1.2.4", str(uuid.uuid4()))

    def test_lifetime_lock_survives_request_completion_and_is_process_exclusive(self):
        lock_path = self.path.parent / "helper.lock"
        with UP.HostLock(lock_path, secure=False):
            self.refusal("operation_busy", UP.HostLock, lock_path, secure=False)
            code = """
import importlib.util,pathlib,sys
s=importlib.util.spec_from_file_location('up',sys.argv[1]);u=importlib.util.module_from_spec(s);s.loader.exec_module(u)
try:u.HostLock(pathlib.Path(sys.argv[2]),secure=False)
except u.Refusal as e: print(e.reason);sys.exit(0)
sys.exit(2)
"""
            child = subprocess.run([sys.executable, "-B", "-c", code, str(ROOT / "scripts/self-host/updater.py"), str(lock_path)], capture_output=True)
            self.assertEqual(0, child.returncode)
            self.assertEqual(b"operation_busy\n", child.stdout)
        UP.HostLock(lock_path, secure=False).close()

    def test_logs_use_global_revision_cursor_across_multiple_operations(self):
        for _ in range(2):
            operation_id = self.accept()
            self.journal.preparing(operation_id)
            self.journal.finish(operation_id, "execution_not_available")
        page = self.journal.logs(operation_id, 0, 1)
        self.assertGreater(page["next_cursor"], 1)
        self.assertTrue(page["has_more"])
        next_page = self.journal.logs(operation_id, page["next_cursor"], 100)
        self.assertEqual(2, len(next_page["events"]))
        self.assertFalse(next_page["has_more"])
        empty = self.journal.logs(operation_id, next_page["next_cursor"], 100)
        self.assertEqual([], empty["events"])
        self.assertEqual(next_page["next_cursor"], empty["next_cursor"])

    def test_prepare_failure_is_stable_redacted_and_readable_without_app(self):
        def unavailable(_):
            raise RuntimeError("APP_KEY=secret and customer@example.test")
        controller = self.controller(unavailable)
        controller.dispatch(request("prepare", request_id=str(uuid.uuid4()), release_tag="v1.2.4"), 1000)
        controller.workers.shutdown(wait=True)
        status = UP.Journal(self.path, INSTALLATION, secure=False).status()
        self.assertEqual("prepare_failed", status["operation"]["error"])
        self.assertNotIn("secret", json.dumps(status))
        self.assertNotIn("customer", self.path.read_text())

    def test_strict_request_fields_protocol_identity_uid_time_nonce_and_types(self):
        controller = self.controller()
        cases = [({"protocol": True}, "protocol_unsupported"), ({"protocol": 2}, "protocol_unsupported"),
                 ({"installation_id": str(uuid.uuid4())}, "installation_mismatch"),
                 ({"issued_at": True}, "request_invalid"), ({"issued_at": 0}, "request_expired"),
                 ({"nonce": "bad"}, "request_invalid"), ({"command": "sh"}, "request_invalid"),
                 ({"path": "/etc"}, "request_invalid"), ({"image": "custom"}, "request_invalid"),
                 ({"action": "apply"}, "request_invalid"), ({"action": []}, "request_invalid"),
                 ({"operation_id": "bad"}, "request_invalid")]
        for change, reason in cases:
            with self.subTest(change=change):
                self.refusal(reason, controller.dispatch, {**request(), **change}, 1000)
        self.refusal("authentication_failed", controller.dispatch, request(), 1001)
        payload = request()
        controller.dispatch(payload, 1000)
        self.refusal("replay_detected", controller.dispatch, payload, 1000)
        for tag in ("latest", "v1.2.3-beta.1", "v1.2.3;id", "v01.2.3", ["v1.2.3"]):
            self.refusal("request_invalid", controller.dispatch, request("prepare", request_id=str(uuid.uuid4()), release_tag=tag), 1000)
        for cursor, limit in ((True, 1), (0, True), (-1, 1), (0, 101), (0, 0)):
            self.refusal("request_invalid", controller.dispatch, request("logs", operation_id=str(uuid.uuid4()), cursor=cursor, limit=limit), 1000)
        self.assertEqual(0, len(self.journal.value["operations"]))

    def test_duplicate_json_keys_and_nonfinite_nested_numbers_refuse(self):
        for raw in (b'{"protocol":1,"protocol":1}', b'{"nested":{"a":1,"a":2}}', b'{"a":NaN}', b'{"a":Infinity}'):
            self.refusal("request_invalid", UP.strict_json, raw)

    def test_hmac_direction_and_exact_payload_bytes_are_bound(self):
        payload = request()
        wire = UP.envelope(payload, TOKEN, "request")
        self.assertEqual(payload, UP.unpack_envelope(wire, TOKEN, "request"))
        self.refusal("authentication_failed", UP.unpack_envelope, wire, "b" * 64, "request")
        self.refusal("authentication_failed", UP.unpack_envelope, wire, TOKEN, "response")
        outer = json.loads(wire)
        outer["payload"] = base64.b64encode(b'{"action":"apply"}').decode()
        self.refusal("authentication_failed", UP.unpack_envelope, json.dumps(outer).encode(), TOKEN, "request")

    def test_plan_receipt_only_selects_safe_reported_facts(self):
        target = {"tag": "v1.2.4", "version": "1.2.4", "commit": "b" * 40,
                  "image_digest": "sha256:" + "c" * 64, "release_notes": "private customer data"}
        target["image_reference"] = "ghcr.io/adamgreenwell/wayfindr:1.2.4@" + target["image_digest"]
        plan = {"schema": 1, "plan_id": "d" * 64, "target": target,
                "source": {"runtime_version": "1.2.3", "runtime_commit": "e" * 40},
                "status": "update_available", "release_requirements": {"migration_blocked": False},
                "provenance": {"repository": "adamgreenwell/wayfindr", "tag": "v1.2.4", "commit": "b" * 40,
                               "history_complete": True, "manifest_sha256": "a" * 64, "history_sha256": "a" * 64, "digest_asset_sha256": "a" * 64}}
        reason, facts = UP.plan_facts(plan, "v1.2.4", FakeConfig())
        self.assertEqual("execution_not_available", reason)
        self.assertNotIn("private", json.dumps(facts))
        operation_id = self.accept()
        self.journal.preparing(operation_id)
        self.journal.finish(operation_id, reason, facts)
        self.assertEqual("plan_reported", self.journal.status()["operation"]["checkpoint"])
        for mutation in (lambda p: p["target"].update(tag="v1.2.5"),
                         lambda p: p["target"].update(image_reference="custom/image"),
                         lambda p: p["provenance"].update(repository="untrusted/repo"),
                         lambda p: p.update(plan_id="invalid")):
            invalid = copy.deepcopy(plan)
            mutation(invalid)
            self.refusal("prepare_output_invalid", UP.plan_facts, invalid, "v1.2.4", FakeConfig())
        plan["release_requirements"]["migration_blocked"] = True
        self.assertEqual("prerequisites_unmet", UP.plan_facts(plan, "v1.2.4", FakeConfig())[0])

    def test_child_timeout_output_limit_and_stderr_are_bounded(self):
        self.refusal("prepare_timeout", UP.capture, [sys.executable, "-c", "import time;time.sleep(5)"], timeout=0.05)
        self.refusal("prepare_output_invalid", UP.capture, [sys.executable, "-c", "print('x'*1100000)"])
        code, output = UP.capture([sys.executable, "-c", "import sys;sys.stderr.write('APP_KEY=secret');print('safe')"])
        self.assertEqual((0, b"safe\n"), (code, output))

    def test_trusted_ancestors_deny_app_owned_symlink_and_writable_paths(self):
        target = Path("/opt/wayfindr/.env")
        for mode, owner in ((stat.S_IFDIR | 0o755, 1000), (stat.S_IFLNK | 0o777, 0), (stat.S_IFDIR | 0o775, 0)):
            def metadata(path):
                return types.SimpleNamespace(st_uid=owner if path == target.parent else 0,
                                             st_mode=mode if path == target.parent else stat.S_IFREG | 0o600 if path == target else stat.S_IFDIR | 0o755)
            with patch.object(Path, "lstat", metadata):
                self.refusal("configuration_changed", UP.trusted, target)

    def test_offline_configuration_load_does_not_require_healthy_application_files(self):
        value = {"schema": 1, "installation_id": INSTALLATION, "install_dir": "/opt/wayfindr",
                 "compose_project": "wayfindr-self-hosting", "client_uid": 1000, "client_gid": 1000,
                 "image_reference": "ghcr.io/adamgreenwell/wayfindr:1.2.3", "compose_sha256": "b" * 64, "env_sha256": "c" * 64, "installer_sha256": "d" * 64}
        credential = {"schema": 1, "installation_id": INSTALLATION, "token": TOKEN}
        with patch.object(UP, "trusted"), patch.object(Path, "stat", return_value=types.SimpleNamespace(st_mode=0o440)), patch.object(UP, "read_object", side_effect=[value, credential]), patch.object(UP.Configuration, "verify_files", side_effect=UP.Refusal("configuration_changed")) as verify:
            config = UP.Configuration.load(verify_install=False)
            self.assertEqual(INSTALLATION, config.installation_id)
            verify.assert_not_called()
        with patch.object(UP, "trusted"), patch.object(Path, "stat", return_value=types.SimpleNamespace(st_mode=0o440)), patch.object(UP, "read_object", side_effect=[value, credential]), patch.object(UP.Configuration, "verify_files", side_effect=UP.Refusal("configuration_changed")):
            self.refusal("configuration_changed", UP.Configuration.load)

    @unittest.skipUnless(hasattr(socket, "SO_PEERCRED"), "Linux peer credentials required; CI exercises this")
    def test_real_linux_peer_uid_hmac_and_disconnected_client(self):
        controller = self.controller()
        # Test-only fake configuration trusts this process's actual CI uid.
        controller.config = FakeConfig()
        controller.config.value = {**controller.config.value, "client_uid": os.getuid()}
        server = UP.Server(controller)
        self.addCleanup(server.pool.shutdown, wait=True)
        def roundtrip(payload, token=TOKEN, close=False):
            client, host = socket.socketpair(socket.AF_UNIX, socket.SOCK_STREAM)
            server.slots.acquire()
            worker = threading.Thread(target=server.handle, args=(host,))
            worker.start()
            client.sendall(UP.envelope(payload, token, "request"))
            if close:
                client.close()
                worker.join(2)
                return None
            result = client.makefile("rb").readline()
            client.close()
            worker.join(2)
            return result
        response = UP.unpack_envelope(roundtrip(request()), TOKEN, "response")
        self.assertTrue(response["ok"])
        self.assertEqual({"error": "authentication_failed"}, json.loads(roundtrip(request(), "b" * 64)))
        roundtrip(request("prepare", request_id=str(uuid.uuid4()), release_tag="v1.2.4"), close=True)
        controller.workers.shutdown(wait=True)
        self.assertEqual("blocked", UP.Journal(self.path, INSTALLATION, secure=False).status()["operation"]["phase"])


if __name__ == "__main__":
    unittest.main(verbosity=2)
