#!/usr/bin/env python3
"""Durable apply admission and recovery evidence; no Docker or provider writes."""

import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import threading
import time
import types
import unittest
import uuid
from unittest.mock import patch

sys.dont_write_bytecode = True
ROOT = Path(__file__).absolute().parents[1]
SPEC = importlib.util.spec_from_file_location("apply_updater", ROOT / "scripts/self-host/updater.py")
UP = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(UP)
INSTALLATION = "1567a42e-bcc8-4bf9-8a57-6a48d107aefe"


class Config:
    installation_id = INSTALLATION
    token = "a" * 64
    value = {"client_uid": 1000}

    def verify_files(self):
        pass

    def capabilities(self):
        return {"helper": {"capabilities": ["plan", "status"]}}


def payload(action, operation_id, **extra):
    return {"protocol": 1, "installation_id": INSTALLATION, "nonce": uuid.uuid4().hex,
            "issued_at": int(time.time()), "action": action, "operation_id": operation_id, **extra}


def capture_receipt():
    return {**UP.protection_state(), "phase": "captured", "archive_sha256": "a" * 64,
            "manifest_sha256": "b" * 64, "archive_bytes": 1024,
            "source_image_id": "sha256:" + "c" * 64, "local_attachment_disks": 2,
            "external_attachment_disks": 1, "offsite_uploaded": False,
            "offsite_verification": "not-configured", "custody_verified": True,
            "hold_owned": True}


class ApplyContractTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name) / "journal.json"
        UP.atomic_write(self.path, UP.initial_journal(INSTALLATION))
        self.journal = UP.Journal(self.path, INSTALLATION, secure=False)
        self.generation = str(uuid.uuid4())
        self.journal.begin_generation(self.generation)

    def refuse(self, reason, function, *args, **kwargs):
        with self.assertRaises(UP.Refusal) as failure:
            function(*args, **kwargs)
        self.assertEqual(reason, failure.exception.reason)

    def prepared(self, reason="execution_not_available"):
        operation_id = self.journal.accept(str(uuid.uuid4()), "v1.2.4", self.generation)[0]
        self.journal.preparing(operation_id)
        self.journal.finish(operation_id, reason, {
            "source": {"version": "1.2.3", "commit": "a" * 40},
            "target": {"tag": "v1.2.4", "version": "1.2.4", "commit": "b" * 40,
                       "image_digest": "sha256:" + "c" * 64}, "plan_id": "d" * 64,
        })
        return operation_id

    def claimed(self):
        operation_id = self.prepared()
        self.assertTrue(self.journal.claim_apply(operation_id, self.generation))
        return operation_id

    def captured(self):
        operation_id = self.claimed()
        self.journal.apply_checkpoint(operation_id, "target_verified", {
            "phase": "protecting", "index_digest": "sha256:" + "c" * 64,
            "platform_manifest_digest": "sha256:" + "e" * 64,
            "config_digest": "sha256:" + "f" * 64,
            "manifest_sha256": "1" * 64, "history_sha256": "2" * 64,
        })
        self.journal.protection_checkpoint(operation_id, "backup_verified", capture_receipt())
        self.journal.apply_checkpoint(operation_id, "data_protected", {"phase": "applying", "hold_owned": True})
        return operation_id

    def success_ready(self):
        operation_id = self.captured()
        self.journal.apply_checkpoint(operation_id, "migration_intent", {"phase": "applying"})
        self.journal.apply_checkpoint(operation_id, "migrations_verified", {
            "migration_verified": True, "migration_receipt_sha256": "3" * 64,
        })
        self.journal.apply_checkpoint(operation_id, "runtime_verified", {
            "phase": "verifying", "services_verified": True, "runtime_receipt_sha256": "4" * 64,
        })
        self.journal.apply_checkpoint(operation_id, "configuration_committed", {"configuration_committed": True})
        self.journal.apply_checkpoint(operation_id, "serving_verified", {"hold_owned": False, "origin_verified": True})
        return operation_id

    def controller(self, applier):
        controller = UP.Controller(Config(), self.journal, preparer=lambda _: ("prepare_failed", None), applier=applier)
        self.generation = controller.generation
        self.addCleanup(controller.workers.shutdown, wait=True)
        return controller

    def test_fresh_apply_claim_is_durable_and_initializes_continuous_capture(self):
        operation_id = self.claimed()
        reloaded = UP.Journal(self.path, INSTALLATION, secure=False)
        operation = reloaded.status(operation_id)["operation"]
        self.assertEqual("downloading", operation["phase"])
        self.assertEqual("apply_started", operation["checkpoint"])
        self.assertEqual(UP.apply_state(), operation["apply"])
        self.assertEqual(UP.protection_state(), operation["protection"])
        self.assertFalse(operation["mutation_started"])
        self.assertEqual(operation_id, reloaded.status()["active_operation"])
        self.assertFalse(reloaded.claim_apply(operation_id, self.generation))

    def test_helper_upgrade_preserves_historical_executor_versions_without_rewriting(self):
        operation_id = self.prepared()
        value = copy.deepcopy(self.journal.value)
        value["operations"][operation_id]["executor_version"] = "0.4.0"
        UP.atomic_write(self.path, value)
        before = self.path.read_bytes()
        historical = UP.Journal(self.path, INSTALLATION, secure=False)
        self.assertEqual("0.4.0", historical.status(operation_id)["operation"]["executor_version"])
        self.assertEqual(before, self.path.read_bytes())
        new_id = historical.accept(str(uuid.uuid4()), "v1.2.5", self.generation)[0]
        self.assertEqual("0.5.0", historical.status(new_id)["operation"]["executor_version"])
        self.assertEqual("0.4.0", historical.status(operation_id)["operation"]["executor_version"])

    def test_helper_upgrade_gate_blocks_every_mutation_but_keeps_observation(self):
        controller = self.controller(lambda *_args, **_kwargs: None)
        operation_id = self.prepared()
        state_dir = self.path.parent
        marker = state_dir / "helper-upgrade.json"
        marker.write_text("incomplete transaction")
        with patch.object(UP, "STATE_DIR", state_dir):
            requests = [payload(action, operation_id) for action in ("protect", "recover-protection", "apply", "recover-apply")]
            requests.extend(payload(action, operation_id, plan_id="d" * 64, request_id=str(uuid.uuid4()), actor={"id": 1}) for action in ("start", "cancel"))
            prepare = payload("prepare", operation_id, request_id=str(uuid.uuid4()), release_tag="v1.2.5")
            del prepare["operation_id"]
            requests.append(prepare)
            before = self.path.read_bytes()
            for request in requests:
                self.refuse("operation_busy", controller.dispatch, request, 0)
            self.assertEqual(before, self.path.read_bytes())
            self.assertEqual(operation_id, controller.dispatch(payload("status", operation_id), 0)["operation"]["operation_id"])
            for action, extra in (("capabilities", {}), ("history", {"cursor": 0, "limit": 20})):
                request = payload(action, operation_id, **extra)
                del request["operation_id"]
                controller.dispatch(request, 0)
            controller.dispatch(payload("logs", operation_id, cursor=0, limit=20), 0)
            marker.unlink()
            marker.symlink_to(state_dir / "missing")
            self.assertTrue(UP.helper_upgrade_pending())
            self.refuse("operation_busy", controller.dispatch, payload("apply", operation_id), 0)

    def test_only_fresh_complete_prepared_plan_can_be_applied(self):
        for reason in ("no_update_required", "prerequisites_unmet", "prepare_failed", "identity_unverified"):
            operation_id = self.prepared(reason)
            self.refuse("apply_unavailable", self.journal.claim_apply, operation_id, self.generation)
        operation_id = self.prepared()
        self.assertTrue(self.journal.claim_protection(operation_id, self.generation))
        self.refuse("apply_unavailable", self.journal.claim_apply, operation_id, self.generation)
        self.journal.protection_finish(operation_id, "protection_failed", True)
        self.refuse("apply_unavailable", self.journal.claim_apply, operation_id, self.generation)

    def test_missing_source_target_plan_and_unknown_operation_refuse(self):
        self.refuse("operation_missing", self.journal.claim_apply, str(uuid.uuid4()), self.generation)
        for key in ("source", "target", "plan_id"):
            operation_id = self.prepared()
            value = copy.deepcopy(self.journal.value)
            value["operations"][operation_id][key] = None
            self.journal.commit(value)
            self.refuse("apply_unavailable", self.journal.claim_apply, operation_id, self.generation)

    def test_root_only_apply_payload_cannot_choose_paths_tags_images_or_commands(self):
        controller = self.controller(lambda *_args, **_kwargs: None)
        operation_id = self.prepared()
        for action in ("apply", "recover-apply"):
            self.refuse("authentication_failed", controller.dispatch, payload(action, operation_id), 1000)
            for key in ("path", "release_tag", "image", "command", "backup", "force"):
                self.refuse("request_invalid", controller.dispatch, payload(action, operation_id, **{key: "secret"}), 0)
            self.refuse("request_invalid", controller.dispatch, payload(action, "invalid"), 0)
        self.assertEqual(["plan", "status"], controller.config.capabilities()["helper"]["capabilities"])

    def test_durable_claim_precedes_worker_and_disconnect_cannot_cancel_it(self):
        started, finish = threading.Event(), threading.Event()
        observed = []
        def applier(operation_id, *, recovery):
            disk = json.loads(self.path.read_bytes())
            observed.append((disk["active_operation"], disk["operations"][operation_id]["checkpoint"], recovery))
            started.set()
            finish.wait(3)
        controller = self.controller(applier)
        self.addCleanup(finish.set)
        operation_id = self.prepared()
        response = controller.dispatch(payload("apply", operation_id), 0)
        del response
        self.assertTrue(started.wait(2))
        self.assertEqual([(operation_id, "apply_started", False)], observed)
        self.assertEqual(operation_id, self.journal.status()["active_operation"])
        controller.dispatch(payload("apply", operation_id), 0)
        finish.set()
        controller.workers.shutdown(wait=True)
        self.assertEqual(1, len(observed))
        self.assertEqual(operation_id, self.journal.status()["active_operation"])

    def test_unknown_worker_error_holds_and_redacts_without_inferred_safe_failure(self):
        def applier(*_args, **_kwargs):
            raise RuntimeError("database_password=customer-secret")
        controller = self.controller(applier)
        operation_id = self.prepared()
        controller.dispatch(payload("apply", operation_id), 0)
        controller.workers.shutdown(wait=True)
        operation = self.journal.status()["operation"]
        self.assertEqual("recovery_required", operation["phase"])
        self.assertEqual("apply_failed", operation["apply"]["error"])
        self.assertEqual(operation_id, self.journal.status()["active_operation"])
        self.assertNotIn("customer-secret", self.path.read_text())

    def test_migration_intent_commits_possible_schema_mutation_before_child_dispatch(self):
        operation_id = self.captured()
        self.journal.apply_checkpoint(operation_id, "migration_intent", {"phase": "applying"})
        disk = json.loads(self.path.read_bytes())["operations"][operation_id]
        self.assertTrue(disk["mutation_started"])
        self.assertTrue(disk["apply"]["migration_started"])
        self.assertFalse(disk["apply"]["migration_verified"])
        self.refuse("journal_corrupt", self.journal.apply_checkpoint, operation_id, "migrations_verified", {"migration_started": False})
        self.refuse("journal_corrupt", self.journal.apply_finish, operation_id, "migration_failed", "failed_safe")
        self.assertEqual(operation_id, self.journal.status()["active_operation"])

    def test_restart_preserves_possible_migration_and_requires_explicit_recovery(self):
        operation_id = self.captured()
        self.journal.apply_checkpoint(operation_id, "migration_intent", {"phase": "applying"})
        reloaded = UP.Journal(self.path, INSTALLATION, secure=False)
        reloaded.begin_generation(str(uuid.uuid4()))
        status = reloaded.status()
        self.assertEqual("recovery_required", status["operation"]["phase"])
        self.assertEqual("migration_intent", status["operation"]["checkpoint"])
        self.assertTrue(status["operation"]["mutation_started"])
        self.assertTrue(status["operation"]["apply"]["migration_started"])
        self.assertTrue(status["operation"]["apply"]["hold_owned"])
        self.refuse("apply_unavailable", reloaded.claim_protection, operation_id, str(uuid.uuid4()), True)
        self.refuse("reconciliation_required", reloaded.reconcile, operation_id)
        self.refuse("operation_busy", reloaded.accept, str(uuid.uuid4()), "v1.2.5", str(uuid.uuid4()))
        before = status["revision"]
        reloaded.begin_generation(str(uuid.uuid4()))
        self.assertEqual(before, reloaded.status()["revision"])
        self.assertTrue(reloaded.claim_apply(operation_id, str(uuid.uuid4()), True))
        self.assertTrue(reloaded.status()["operation"]["mutation_started"])
        self.refuse("journal_corrupt", reloaded.apply_checkpoint, operation_id, "data_protected", {"error": None})

    def test_actual_process_death_after_migration_intent_stays_held(self):
        operation_id = self.captured()
        code = """
import importlib.util, pathlib, sys, os
spec=importlib.util.spec_from_file_location('up',sys.argv[1]); up=importlib.util.module_from_spec(spec); spec.loader.exec_module(up)
j=up.Journal(pathlib.Path(sys.argv[2]),sys.argv[3],secure=False)
j.apply_checkpoint(sys.argv[4],'migration_intent',{'phase':'applying'})
os._exit(9)
"""
        result = subprocess.run([sys.executable, "-B", "-c", code, str(ROOT / "scripts/self-host/updater.py"), str(self.path), INSTALLATION, operation_id], capture_output=True)
        self.assertEqual(9, result.returncode)
        reloaded = UP.Journal(self.path, INSTALLATION, secure=False)
        reloaded.begin_generation(str(uuid.uuid4()))
        self.assertEqual(operation_id, reloaded.status()["active_operation"])
        self.assertTrue(reloaded.status()["operation"]["apply"]["migration_started"])

    def test_success_requires_all_receipts_target_proofs_config_and_release(self):
        operation_id = self.success_ready()
        self.journal.apply_finish(operation_id, None)
        status = self.journal.status()
        self.assertEqual("succeeded", status["operation"]["phase"])
        self.assertEqual("verified", status["operation"]["apply"]["phase"])
        self.assertEqual("retained", status["operation"]["protection"]["phase"])
        self.assertFalse(status["operation"]["protection"]["services_recovered"])
        self.assertFalse(status["operation"]["protection"]["hold_owned"])
        self.assertIsNone(status["active_operation"])
        reloaded = UP.Journal(self.path, INSTALLATION, secure=False)
        self.assertFalse(reloaded.claim_apply(operation_id, self.generation))
        for key in ("index_digest", "platform_manifest_digest", "config_digest", "manifest_sha256", "history_sha256", "migration_receipt_sha256", "runtime_receipt_sha256"):
            value = copy.deepcopy(self.journal.value)
            value["operations"][operation_id]["apply"][key] = None
            with self.subTest(key=key):
                self.refuse("journal_corrupt", UP.validate_journal, value, INSTALLATION)
        for key in ("migration_started", "migration_verified", "services_verified", "origin_verified", "configuration_committed"):
            value = copy.deepcopy(self.journal.value)
            value["operations"][operation_id]["apply"][key] = False
            with self.subTest(key=key):
                self.refuse("journal_corrupt", UP.validate_journal, value, INSTALLATION)

    def test_incomplete_success_cannot_release_host_ownership(self):
        operation_id = self.captured()
        before = self.path.read_bytes()
        self.refuse("journal_corrupt", self.journal.apply_finish, operation_id, None)
        self.assertEqual(before, self.path.read_bytes())
        self.assertEqual(operation_id, self.journal.status()["active_operation"])

    def test_failed_safe_requires_proven_previous_origin_and_no_possible_schema_mutation(self):
        operation_id = self.claimed()
        self.refuse("journal_corrupt", self.journal.apply_finish, operation_id, "download_failed", "failed_safe")
        self.journal.apply_checkpoint(operation_id, "previous_serving_verified", {
            "phase": "verifying", "runtime_receipt_sha256": "5" * 64,
            "services_verified": True, "origin_verified": True, "hold_owned": False,
        })
        self.journal.apply_finish(operation_id, "download_failed", "failed_safe")
        operation = self.journal.status()["operation"]
        self.assertEqual("failed_safe", operation["phase"])
        self.assertEqual("download_failed", operation["error"])
        self.assertEqual("fallback", operation["apply"]["phase"])
        self.assertFalse(operation["mutation_started"])
        self.assertIsNone(self.journal.status()["active_operation"])

    def test_apply_evidence_rejects_unknown_untyped_and_overclaimed_values(self):
        operation_id = self.claimed()
        mutations = [("command", "secret"), ("phase", []), ("phase", "succeeded"),
                     ("index_digest", "sha256:" + "A" * 64), ("config_digest", "latest"),
                     ("manifest_sha256", {}), ("history_sha256", 1),
                     ("migration_receipt_sha256", "short"), ("runtime_receipt_sha256", ["secret"]),
                     ("migration_started", 1), ("hold_owned", "false"),
                     ("migration_verified", True), ("services_verified", True),
                     ("error", "postgres password:secret"), ("phase", "verified")]
        for key, bad in mutations:
            value = copy.deepcopy(self.journal.value)
            value["operations"][operation_id]["apply"][key] = bad
            with self.subTest(key=key, bad=bad):
                self.refuse("journal_corrupt", UP.validate_journal, value, INSTALLATION)
        self.refuse("journal_corrupt", self.journal.apply_checkpoint, operation_id, "shell_exec", {})
        self.refuse("journal_corrupt", self.journal.apply_checkpoint, operation_id, "target_verified", {"command": "secret"})

    def test_captured_and_retained_custody_never_claim_original_service_recovery(self):
        receipt = capture_receipt()
        UP.validate_protection(receipt)
        UP.validate_protection({**receipt, "phase": "retained", "hold_owned": False})
        for key, bad in (("custody_verified", False), ("hold_owned", False), ("services_recovered", True), ("archive_sha256", None)):
            self.refuse("journal_corrupt", UP.validate_protection, {**receipt, key: bad})
        self.refuse("journal_corrupt", UP.validate_protection, {**receipt, "phase": "retained"})
        self.refuse("journal_corrupt", UP.validate_protection, {**UP.protection_state(), "phase": "backing_up", "custody_verified": True})

    def test_post_migration_checkpoint_cannot_erase_both_possible_mutation_flags(self):
        operation_id = self.captured()
        self.journal.apply_checkpoint(operation_id, "migration_intent", {"phase": "applying"})
        for checkpoint in UP.MUTATION_CHECKPOINTS:
            value = copy.deepcopy(self.journal.value)
            operation = value["operations"][operation_id]
            operation["checkpoint"] = checkpoint
            operation["mutation_started"] = operation["apply"]["migration_started"] = False
            with self.subTest(checkpoint=checkpoint):
                self.refuse("journal_corrupt", UP.validate_journal, value, INSTALLATION)

    def test_protection_and_apply_observed_hold_ownership_remain_in_sync(self):
        operation_id = self.captured()
        operation = self.journal.status()["operation"]
        self.assertTrue(operation["apply"]["hold_owned"])
        self.assertTrue(operation["protection"]["hold_owned"])
        self.journal.apply_checkpoint(operation_id, "previous_serving_verified", {"phase": "protecting", "hold_owned": False})
        operation = self.journal.status()["operation"]
        self.assertFalse(operation["protection"]["hold_owned"])
        self.assertEqual("resuming", operation["protection"]["phase"])
        self.journal.protection_checkpoint(operation_id, "fenced", {"hold_owned": True})
        operation = self.journal.status()["operation"]
        self.assertTrue(operation["apply"]["hold_owned"])
        self.assertTrue(operation["protection"]["hold_owned"])
        self.assertEqual("resuming", operation["protection"]["phase"])

    def test_legacy_plan_and_protection_journals_remain_readable(self):
        operation_id = self.prepared()
        for version in ("0.1.0", "0.2.0"):
            value = copy.deepcopy(self.journal.value)
            value["operations"][operation_id]["executor_version"] = version
            UP.validate_journal(value, INSTALLATION)
        self.journal.claim_protection(operation_id, self.generation)
        value = copy.deepcopy(self.journal.value)
        value["operations"][operation_id]["executor_version"] = "0.2.0"
        UP.validate_journal(value, INSTALLATION)

    def test_uncertain_claim_or_intent_fsync_never_dispatches_or_releases(self):
        calls = []
        controller = self.controller(lambda *_args, **_kwargs: calls.append(True))
        operation_id = self.prepared()
        before = self.path.read_bytes()
        with patch.object(UP, "atomic_write", side_effect=UP.Refusal("journal_unavailable")):
            self.refuse("journal_unavailable", controller.dispatch, payload("apply", operation_id), 0)
        self.assertEqual([], calls)
        self.assertEqual(before, self.path.read_bytes())
        self.refuse("journal_unavailable", self.journal.status)
        reloaded = UP.Journal(self.path, INSTALLATION, secure=False)
        reloaded.claim_apply(operation_id, self.generation)
        reloaded.apply_checkpoint(operation_id, "target_verified", {
            "phase": "protecting", "index_digest": "sha256:" + "c" * 64,
            "platform_manifest_digest": "sha256:" + "e" * 64, "config_digest": "sha256:" + "f" * 64,
            "manifest_sha256": "1" * 64, "history_sha256": "2" * 64,
        })
        reloaded.protection_checkpoint(operation_id, "backup_verified", capture_receipt())
        with patch.object(UP, "atomic_write", side_effect=UP.Refusal("journal_unavailable")):
            self.refuse("journal_unavailable", reloaded.apply_checkpoint, operation_id, "migration_intent", {"phase": "applying"})
        self.refuse("journal_unavailable", reloaded.apply_finish, operation_id, "migration_failed", "failed_safe")
        self.assertEqual(operation_id, json.loads(self.path.read_bytes())["active_operation"])

    def test_transition_loading_is_narrow_and_requires_explicit_verified_true(self):
        config_path = Path(self.temp.name) / "configuration.json"
        auth_path = Path(self.temp.name) / "credential.json"
        config = {"schema": 1, "installation_id": INSTALLATION,
                  "install_dir": "/var/lib/wayfindr-install", "compose_project": "wayfindr-self-hosting",
                  "client_uid": 1000, "client_gid": 1000, "image_reference": "ghcr.io/adamgreenwell/wayfindr:1.2.3",
                  "compose_sha256": "a" * 64, "env_sha256": "b" * 64, "installer_sha256": "c" * 64,
                  "overlay_sha256": "d" * 64}
        UP.atomic_write(config_path, config)
        UP.atomic_write(auth_path, {"schema": 1, "installation_id": INSTALLATION, "token": "a" * 64})
        observed = []
        def load(module):
            def transition(value, state_dir, api):
                observed.append((value.value, state_dir, api.CONFIG, api.Configuration, api.validate_journal))
                return True
            module.verify_transition = transition
        spec = types.SimpleNamespace(loader=types.SimpleNamespace(exec_module=load), name="transition", submodule_search_locations=None)
        with patch.object(UP, "trusted"), patch.object(UP.Configuration, "verify_files", side_effect=UP.Refusal("configuration_changed")), patch("importlib.util.spec_from_file_location", return_value=spec), patch("importlib.util.module_from_spec", return_value=types.SimpleNamespace()):
            loaded = UP.Configuration.load(config_path, auth_path)
        self.assertEqual(config, loaded.value)
        self.assertEqual([(config, UP.STATE_DIR, config_path, UP.Configuration, UP.validate_journal)], observed)
        for result in (None, False, 1, "true"):
            def invalid_load(module):
                module.verify_transition = lambda *_args: result
            spec.loader.exec_module = invalid_load
            with patch.object(UP, "trusted"), patch.object(UP.Configuration, "verify_files", side_effect=UP.Refusal("configuration_changed")), patch("importlib.util.spec_from_file_location", return_value=spec), patch("importlib.util.module_from_spec", return_value=types.SimpleNamespace()):
                self.refuse("configuration_changed", UP.Configuration.load, config_path, auth_path)
        with patch.object(UP, "trusted"), patch.object(UP.Configuration, "verify_files", side_effect=UP.Refusal("helper_unavailable")), patch("importlib.util.spec_from_file_location") as imported:
            self.refuse("helper_unavailable", UP.Configuration.load, config_path, auth_path)
            imported.assert_not_called()
        with patch.object(UP, "trusted"), patch.object(UP.Configuration, "verify_files"), patch("importlib.util.spec_from_file_location") as imported:
            UP.Configuration.load(config_path, auth_path)
            imported.assert_not_called()


if __name__ == "__main__":
    unittest.main(verbosity=2)
