#!/usr/bin/env python3
"""Authenticated operator admission, cancellation, and root-owned history fixtures.

Only private temporary journals and synthetic Docker/network evidence are used.
"""

import copy
import importlib.util
import itertools
from pathlib import Path
import sys
import threading
import time
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


CONTRACT = module("operator_contract_fixture", "scripts/test_update_apply_contract.py")
LIFECYCLE = module("operator_apply_fixture", "scripts/test_update_apply.py")
UP = CONTRACT.UP
ACTOR = {"id": 42}
PLAN = "d" * 64


def request(action, operation=None, **fields):
    value = {"protocol": 1, "installation_id": CONTRACT.INSTALLATION,
             "nonce": uuid.uuid4().hex, "issued_at": int(time.time()), "action": action}
    if operation is not None:
        value.update(operation_id=operation, plan_id=PLAN, request_id=str(uuid.uuid4()), actor=ACTOR)
    return {**value, **fields}


class OperatorContractTests(unittest.TestCase):
    setUp = CONTRACT.ApplyContractTests.setUp
    prepared = CONTRACT.ApplyContractTests.prepared
    captured = CONTRACT.ApplyContractTests.captured
    claimed = CONTRACT.ApplyContractTests.claimed
    refuse = CONTRACT.ApplyContractTests.refuse
    controller = CONTRACT.ApplyContractTests.controller

    def test_actor_prepare_and_start_are_durable_before_app_worker(self):
        started, finish = threading.Event(), threading.Event()
        observed = []
        def apply(operation, **_):
            disk = UP.Journal(self.path, CONTRACT.INSTALLATION, secure=False)
            observed.append(disk.status(operation)["operation"]["operator"])
            started.set()
            finish.wait(2)
        controller = self.controller(apply)
        self.addCleanup(finish.set)
        op, _ = self.journal.accept(str(uuid.uuid4()), "v1.2.4", controller.generation, ACTOR)
        self.journal.preparing(op)
        self.journal.finish(op, "execution_not_available", {
            "source": {"version": "1.2.3", "commit": "a" * 40},
            "target": {"tag": "v1.2.4", "version": "1.2.4", "commit": "b" * 40, "image_digest": "sha256:" + "c" * 64}, "plan_id": PLAN})
        start = request("start", op)
        controller.dispatch(start, 1000)
        self.assertTrue(started.wait(1))
        self.assertEqual(ACTOR, observed[0]["prepare"]["actor"])
        receipt = observed[0]["start"]
        self.assertEqual(start["request_id"], receipt["request_id"])
        self.assertEqual(PLAN, receipt["plan_id"])
        self.assertEqual(ACTOR, receipt["actor"])
        self.assertGreater(receipt["revision"], observed[0]["prepare"]["revision"])
        controller.dispatch({**start, "nonce": uuid.uuid4().hex}, 1000)
        finish.set()
        controller.workers.shutdown(wait=True)
        self.assertEqual(1, len(observed))

    def test_start_rejects_stale_plan_and_cross_actor_or_action_idempotency(self):
        op = self.prepared()
        ident = str(uuid.uuid4())
        self.refuse("plan_mismatch", self.journal.start, op, "0" * 64, ident, ACTOR, self.generation)
        self.assertTrue(self.journal.start(op, PLAN, ident, ACTOR, self.generation))
        self.assertFalse(self.journal.start(op, PLAN, ident, ACTOR, self.generation))
        self.refuse("idempotency_conflict", self.journal.start, op, PLAN, ident, {"id": 43}, self.generation)
        self.refuse("idempotency_conflict", self.journal.cancel, op, PLAN, ident, ACTOR)
        self.refuse("apply_unavailable", self.journal.start, op, PLAN, str(uuid.uuid4()), ACTOR, self.generation)
        self.refuse("idempotency_conflict", self.journal.accept, ident, "v1.2.4", self.generation)

    def test_actor_prepare_retry_cannot_replace_original_attribution(self):
        ident = str(uuid.uuid4())
        op, _ = self.journal.accept(ident, "v1.2.4", self.generation, ACTOR)
        revision = self.journal.status()["revision"]
        self.assertEqual((op, False), self.journal.accept(ident, "v1.2.4", self.generation, ACTOR))
        self.assertEqual(revision, self.journal.status()["revision"])
        self.refuse("idempotency_conflict", self.journal.accept, ident, "v1.2.4", self.generation, {"id": 43})
        self.refuse("idempotency_conflict", self.journal.accept, ident, "v1.2.4", self.generation)

    def test_app_cannot_supply_recovery_paths_commands_or_untyped_actor(self):
        controller = self.controller(lambda *_args, **_kwargs: None)
        op = self.prepared()
        for action in ("start", "cancel"):
            for field in ("path", "image", "command", "force", "backup", "target", "recovery"):
                self.refuse("request_invalid", controller.dispatch, request(action, op, **{field: "secret"}), 1000)
            for actor in (None, True, {"id": True}, {"id": 0}, {"id": -1}, {"id": "42"}, {"id": 1, "name": "secret"}, {"id": 2 ** 63}):
                self.refuse("request_invalid", controller.dispatch, request(action, op, actor=actor), 1000)
            self.refuse("authentication_failed", controller.dispatch, request(action, op), 1001)
        for action in ("recover-apply", "recover-protection", "apply", "protect"):
            raw = request(action, op)
            self.refuse("authentication_failed", controller.dispatch, raw, 1000)

    def test_signed_request_replay_and_unknown_fields_do_not_claim(self):
        controller = self.controller(lambda *_args, **_kwargs: None)
        raw = request("history", cursor=0, limit=20)
        controller.dispatch(raw, 1000)
        self.refuse("replay_detected", controller.dispatch, raw, 1000)
        self.refuse("request_invalid", controller.dispatch, request("history", cursor=True, limit=20), 1000)
        self.refuse("request_invalid", controller.dispatch, request("history", cursor=1025, limit=20), 1000)
        self.refuse("request_invalid", controller.dispatch, request("history", cursor=0, limit=51), 1000)
        self.refuse("request_invalid", controller.dispatch, request("history", cursor=0, limit=20, secret="private"), 1000)

    def test_cancel_admission_is_durable_idempotent_and_closes_migration(self):
        op = self.captured()
        ident = str(uuid.uuid4())
        self.assertTrue(self.journal.cancel(op, PLAN, ident, ACTOR))
        restored = UP.Journal(self.path, CONTRACT.INSTALLATION, secure=False)
        receipt = restored.status(op)["operation"]["operator"]["cancel"]
        self.assertEqual("requested", receipt["state"])
        self.assertEqual(ACTOR, receipt["actor"])
        self.assertFalse(restored.cancel(op, PLAN, ident, ACTOR))
        self.refuse("cancel_requested", restored.apply_checkpoint, op, "migration_intent", {"phase": "applying"})
        self.assertFalse(restored.status(op)["operation"]["mutation_started"])
        self.refuse("idempotency_conflict", restored.cancel, op, PLAN, ident, {"id": 43})
        self.refuse("cancel_unavailable", restored.cancel, op, PLAN, str(uuid.uuid4()), ACTOR)

    def test_migration_admission_wins_once_and_refuses_cancellation(self):
        op = self.captured()
        self.journal.apply_checkpoint(op, "migration_intent", {"phase": "applying"})
        self.refuse("cancel_unavailable", self.journal.cancel, op, PLAN, str(uuid.uuid4()), ACTOR)
        self.assertTrue(self.journal.status(op)["operation"]["mutation_started"])
        self.assertNotIn("operator", self.journal.status(op)["operation"])

    def test_cancel_and_migration_race_admits_exactly_one_durable_owner(self):
        op = self.captured()
        barrier = threading.Barrier(3)
        results = []
        def attempt(action):
            barrier.wait()
            try:
                if action == "cancel":
                    self.journal.cancel(op, PLAN, str(uuid.uuid4()), ACTOR)
                else:
                    self.journal.apply_checkpoint(op, "migration_intent", {"phase": "applying"})
                results.append((action, "accepted"))
            except UP.Refusal as failure:
                results.append((action, failure.reason))
        threads = [threading.Thread(target=attempt, args=(action,)) for action in ("cancel", "migration")]
        for thread in threads:
            thread.start()
        barrier.wait()
        for thread in threads:
            thread.join(2)
            self.assertFalse(thread.is_alive())
        self.assertEqual(1, sum(reason == "accepted" for _, reason in results))
        restored = UP.Journal(self.path, CONTRACT.INSTALLATION, secure=False).status(op)["operation"]
        if restored["mutation_started"]:
            self.assertIn(("cancel", "cancel_unavailable"), results)
            self.assertNotIn("operator", restored)
        else:
            self.assertIn(("migration", "cancel_requested"), results)
            self.assertEqual("requested", restored["operator"]["cancel"]["state"])

    def test_receipt_actor_revision_and_time_use_the_exact_event_when_clock_advances(self):
        clock = itertools.count(1_800_000_000)
        with patch.object(UP.time, "time", side_effect=lambda: next(clock)):
            op, _ = self.journal.accept(str(uuid.uuid4()), "v1.2.4", self.generation, ACTOR)
            self.journal.preparing(op)
            self.journal.finish(op, "execution_not_available", {
                "source": {"version": "1.2.3", "commit": "a" * 40},
                "target": {"tag": "v1.2.4", "version": "1.2.4", "commit": "b" * 40, "image_digest": "sha256:" + "c" * 64}, "plan_id": PLAN})
            self.journal.start(op, PLAN, str(uuid.uuid4()), ACTOR, self.generation)
            self.journal.cancel(op, PLAN, str(uuid.uuid4()), ACTOR)
        operation = UP.Journal(self.path, CONTRACT.INSTALLATION, secure=False).status(op)["operation"]
        for action, code in (("prepare", "operation_accepted"), ("start", "operator_started"), ("cancel", "cancel_requested")):
            receipt = operation["operator"][action]
            event = next(event for event in operation["events"] if event["revision"] == receipt["revision"])
            self.assertEqual(code, event["code"])
            self.assertEqual(event["at"], receipt["at"])

    def test_uncertain_operator_start_and_cancel_fsync_never_dispatch_or_close_hold(self):
        op = self.prepared()
        with patch.object(UP, "atomic_write", side_effect=UP.Refusal("journal_unavailable")):
            self.refuse("journal_unavailable", self.journal.start, op, PLAN, str(uuid.uuid4()), ACTOR, self.generation)
        self.assertTrue(self.journal.failed)
        self.journal = UP.Journal(self.path, CONTRACT.INSTALLATION, secure=False)
        self.journal.start(op, PLAN, str(uuid.uuid4()), ACTOR, self.generation)
        with patch.object(UP, "atomic_write", side_effect=UP.Refusal("journal_unavailable")):
            self.refuse("journal_unavailable", self.journal.cancel, op, PLAN, str(uuid.uuid4()), ACTOR)
        self.assertTrue(self.journal.failed)
        self.refuse("journal_unavailable", self.journal.check_cancel, op)
        restored = UP.Journal(self.path, CONTRACT.INSTALLATION, secure=False).status(op)
        self.assertEqual(op, restored["active_operation"])
        self.assertIsNone(restored["operation"]["operator"]["cancel"])

    def test_restart_retains_cancellation_but_app_cannot_implicitly_recover(self):
        op = self.claimed()
        self.journal.cancel(op, PLAN, str(uuid.uuid4()), ACTOR)
        self.journal.begin_generation(str(uuid.uuid4()))
        self.assertEqual("recovery_required", self.journal.status(op)["operation"]["phase"])
        self.assertEqual("requested", self.journal.status(op)["operation"]["operator"]["cancel"]["state"])
        self.refuse("cancel_unavailable", self.journal.cancel, op, PLAN, str(uuid.uuid4()), ACTOR)

    def test_history_is_bounded_authoritative_paginated_and_keeps_old_records(self):
        ops = [self.prepared() for _ in range(4)]
        page = self.journal.history(0, 2)
        expected = sorted((self.journal.status(op)["operation"] for op in ops), key=lambda item: (item["created_at"], item["operation_id"]), reverse=True)
        self.assertEqual(expected[:2], page["operations"])
        self.assertEqual(2, page["next_cursor"])
        self.assertTrue(page["has_more"])
        self.assertEqual(expected[2:], self.journal.history(2, 2)["operations"])
        self.assertFalse(self.journal.history(4, 2)["has_more"])
        for version in ("0.1.0", "0.2.0", "0.3.0"):
            value = copy.deepcopy(self.journal.value)
            value["operations"][ops[0]]["executor_version"] = version
            UP.validate_journal(value, CONTRACT.INSTALLATION)
        self.assertNotIn("token", str(page))

    def test_operator_journal_unknown_fields_fake_completion_or_unsafe_actor_refuse(self):
        op = self.claimed()
        self.journal.cancel(op, PLAN, str(uuid.uuid4()), ACTOR)
        for edit in (lambda rec: rec.update(password="secret"), lambda rec: rec.update(state="completed"),
                     lambda rec: rec.update(revision=True), lambda rec: rec.update(revision=999999),
                     lambda rec: rec.update(actor={"id": True}), lambda rec: rec.update(plan_id="0" * 64)):
            value = copy.deepcopy(self.journal.value)
            edit(value["operations"][op]["operator"]["cancel"])
            self.refuse("journal_corrupt", UP.validate_journal, value, CONTRACT.INSTALLATION)

    def test_cancel_cannot_publish_completion_without_source_serving_evidence(self):
        op = self.claimed()
        self.journal.cancel(op, PLAN, str(uuid.uuid4()), ACTOR)
        self.refuse("journal_corrupt", self.journal.apply_finish, op, "cancelled", "cancelled")
        self.assertEqual(op, self.journal.status(op)["active_operation"])


class CancellationLifecycleTests(unittest.TestCase):
    setUp = LIFECYCLE.ApplyTests.setUp
    status = LIFECYCLE.ApplyTests.status
    proof = LIFECYCLE.ApplyTests.proof
    recover = LIFECYCLE.ApplyTests.recover

    def cancel(self):
        self.journal.cancel(self.operation, PLAN, str(uuid.uuid4()), ACTOR)

    def start(self):
        self.journal.start(self.operation, PLAN, str(uuid.uuid4()), ACTOR, self.generation)

    def test_cancellation_at_download_fence_drain_backup_and_assess_resumes_verified_source(self):
        for stage in ("download", "fence", "drain", "backup", "assess"):
            with self.subTest(stage=stage):
                self.setUp()
                if stage == "download":
                    original = self.applier.artifacts.prepare
                    def changed(*args):
                        value = original(*args)
                        self.cancel()
                        return value
                    self.applier.artifacts.prepare = changed
                elif stage == "fence":
                    original = self.engine.window
                    def changed(container, operation, action):
                        value = original(container, operation, action)
                        if action == "enter" and self.status()["operation"]["operator"]["cancel"] is None:
                            self.cancel()
                        return value
                    self.engine.window = changed
                elif stage in {"drain", "backup"}:
                    original = getattr(self.engine, stage)
                    def changed(*args, **kwargs):
                        value = original(*args, **kwargs)
                        self.cancel()
                        return value
                    setattr(self.engine, stage, changed)
                else:
                    original = self.engine.oneoff
                    def changed(directory, operation, state, action):
                        value = original(directory, operation, state, action)
                        if action == "assess":
                            self.cancel()
                        return value
                    self.engine.oneoff = changed
                self.start()
                self.applier(self.operation)
                result = self.status()
                self.assertEqual("cancelled", result["operation"]["phase"])
                self.assertEqual("completed", result["operation"]["operator"]["cancel"]["state"])
                self.assertTrue(result["operation"]["protection"]["services_recovered"])
                self.assertTrue(result["operation"]["apply"]["origin_verified"])
                self.assertFalse(self.engine.held)
                self.assertTrue(self.engine.running)
                self.assertIsNone(result["active_operation"])
                self.assertNotIn("migrate", self.engine.calls)

    def test_cancel_after_schema_intent_is_denied_and_target_apply_continues(self):
        original = self.engine.oneoff
        denied = []
        def changed(directory, operation, state, action):
            if action == "migrate":
                with self.assertRaises(LIFECYCLE.UP.Refusal) as failure:
                    self.cancel()
                denied.append(failure.exception.reason)
            return original(directory, operation, state, action)
        self.engine.oneoff = changed
        self.start()
        self.applier(self.operation)
        self.assertEqual(["cancel_unavailable"], denied)
        self.assertEqual("succeeded", self.status()["operation"]["phase"])
        self.assertEqual(1, self.engine.calls.count("migrate"))

    def test_old_or_overclaimed_target_protocol_refuses_before_schema_admission(self):
        good = LIFECYCLE.APPLY.OPERATOR_CONTRACT
        for invalid in ({}, {**good, "protocol": True}, {**good, "minimum_helper_version": "0.3.0"},
                        {**good, "capabilities": ["plan", "status"]}, {**good, "extra": "untrusted"}):
            with self.subTest(contract=invalid):
                self.setUp()
                original = self.engine.oneoff
                self.engine.oneoff = lambda directory, operation, state, action: invalid if action == "protocol" else original(directory, operation, state, action)
                self.start()
                self.applier(self.operation)
                self.assertEqual("failed_safe", self.status()["operation"]["phase"])
                self.assertFalse(self.status()["operation"]["mutation_started"])
                self.assertTrue(self.engine.running)
                self.assertFalse(self.engine.held)
                self.assertNotIn("assess", self.engine.calls)
                self.assertNotIn("migrate", self.engine.calls)

    def test_unsettled_target_protocol_timeout_holds_until_explicit_source_recovery(self):
        original_oneoff, original_settled = self.engine.oneoff, self.engine.settled
        probe_active = [False]
        def unsettled(ids, operation):
            original_settled(ids, operation)
            if probe_active[0]:
                raise LIFECYCLE.UP.Refusal("recovery_required")
        def oneoff(directory, operation, state, action):
            if action == "protocol":
                self.engine.calls.append("protocol")
                probe_active[0] = True
                raise LIFECYCLE.UP.Refusal("runtime_verification_failed")
            return original_oneoff(directory, operation, state, action)
        self.engine.oneoff, self.engine.settled = oneoff, unsettled
        self.start()
        self.applier(self.operation)
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertTrue(self.engine.held)
        self.assertFalse(self.engine.running)
        self.assertNotIn("release", self.engine.calls)
        self.assertNotIn("migrate", self.engine.calls)
        probe_active[0] = False
        self.recover()
        self.assertEqual("failed_safe", self.status()["operation"]["phase"])
        self.assertEqual(1, self.engine.calls.count("protocol"))

    def test_interrupted_promotion_reads_large_valid_history_with_authoritative_bound(self):
        original = self.api.atomic_write
        def interrupted(path, value):
            if path == self.configpath:
                raise KeyboardInterrupt()
            original(path, value)
        self.api.atomic_write = interrupted
        self.start()
        with self.assertRaises(KeyboardInterrupt):
            self.applier(self.operation)
        self.api.atomic_write = original
        self.api.trusted = lambda *_args, **_kwargs: None
        journal = copy.deepcopy(self.journal.value)
        for _ in range(1000):
            op = str(uuid.uuid4())
            events = []
            for _ in range(32):
                journal["revision"] += 1
                events.append({"revision": journal["revision"], "at": 1_800_000_000, "code": "succeeded", "phase": "succeeded"})
            journal["operations"][op] = {"operation_id": op, "request_id": str(uuid.uuid4()), "release_tag": "v1.2.4",
                "phase": "succeeded", "checkpoint": "serving_verified", "executor_generation": self.generation,
                "executor_version": "0.3.0", "mutation_started": True, "created_at": 1_800_000_000,
                "updated_at": 1_800_000_000, "revision": journal["revision"], "error": None,
                "source": copy.deepcopy(journal["operations"][self.operation]["source"]),
                "target": copy.deepcopy(journal["operations"][self.operation]["target"]),
                "plan_id": PLAN, "events": events,
                "apply": {**copy.deepcopy(journal["operations"][self.operation]["apply"]), "phase": "verified", "configuration_committed": True, "hold_owned": False},
                "protection": {**copy.deepcopy(journal["operations"][self.operation]["protection"]), "phase": "retained", "hold_owned": False}}
            journal["operations"][op]["release_tag"] = journal["operations"][self.operation]["release_tag"]
        raw = LIFECYCLE.UP.encoded(journal)
        self.assertGreater(len(raw), 4_000_000)
        self.assertLess(len(raw), LIFECYCLE.UP.JOURNAL_MAX)
        LIFECYCLE.UP.validate_journal(journal, self.config.installation_id)
        LIFECYCLE.UP.atomic_write(self.journalpath, journal)
        self.assertTrue(LIFECYCLE.APPLY.verify_transition(self.config, self.root, self.api))
        # A valid small object padded beyond the same hard ceiling still refuses.
        self.journalpath.write_bytes(raw + b" " * (LIFECYCLE.UP.JOURNAL_MAX + 1 - len(raw)))
        with self.assertRaises(LIFECYCLE.UP.Refusal):
            LIFECYCLE.APPLY.verify_transition(self.config, self.root, self.api)

    def test_public_intent_before_private_write_keeps_interrupted_migration_ambiguous(self):
        original = self.applier.persist
        def interrupted(directory, state, stage):
            if stage == "migration_intent":
                raise KeyboardInterrupt()
            original(directory, state, stage)
        self.applier.persist = interrupted
        self.start()
        with self.assertRaises(KeyboardInterrupt):
            self.applier(self.operation)
        self.assertTrue(self.status()["operation"]["mutation_started"])
        self.applier.persist = original
        self.recover()
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertTrue(self.engine.held)
        self.assertNotIn("migrate", self.engine.calls)

    def test_cancel_with_active_backup_never_claims_old_release_recovery(self):
        original = self.engine.backup
        def pending(*args):
            self.cancel()
            self.engine.active = True
            raise LIFECYCLE.UP.Refusal("backup_failed")
        self.engine.backup = pending
        self.start()
        self.applier(self.operation)
        self.assertEqual("recovery_required", self.status()["operation"]["phase"])
        self.assertEqual("requested", self.status()["operation"]["operator"]["cancel"]["state"])
        self.assertTrue(self.engine.held)
        self.assertFalse(self.engine.running)
        self.assertNotIn("migrate", self.engine.calls)
        self.engine.backup = original
        self.engine.active = False
        self.recover()
        self.assertEqual("cancelled", self.status()["operation"]["phase"])


class DockerOperatorCommandTests(unittest.TestCase):
    def engine(self, activity):
        calls, names = [], []
        def active(name):
            names.append(name)
            return activity(name, len(names))
        def capture(command, **kwargs):
            calls.append((command, kwargs))
            return 0, UP.encoded(LIFECYCLE.APPLY.OPERATOR_CONTRACT)
        engine = object.__new__(LIFECYCLE.APPLY.Engine)
        engine.base = types.SimpleNamespace(compose=["fixed-docker", "compose"], oneoff_active=active,
                                            settled=lambda *_: None, commands_settled=lambda *_: None)
        engine.api = types.SimpleNamespace(capture=capture, strict_json=UP.strict_json, Refusal=UP.Refusal)
        return engine, calls, names

    def test_target_protocol_is_one_fixed_bounded_php_command_without_environment_secrets(self):
        engine, calls, names = self.engine(lambda *_: False)
        op = str(uuid.uuid4())
        receipt = engine.oneoff(Path("/private/fixture"), op, {}, "protocol")
        self.assertEqual(LIFECYCLE.APPLY.OPERATOR_CONTRACT, receipt)
        self.assertEqual(["wayfindr-updater-apply-protocol-" + op] * 2, names)
        command, options = calls[0]
        self.assertEqual(90, options["timeout"])
        self.assertEqual(["web", "artisan", "wayfindr:updater-status", "--protocol-contract"], command[-4:])
        for fixed in ("--no-deps", "--pull=never", "--entrypoint", "php", "--rm", "-T"):
            self.assertIn(fixed, command)
        self.assertNotIn("up", command)
        self.assertNotIn("APP_KEY", str(command))

    def test_pending_probe_or_success_output_with_unsettled_oneoff_cannot_authorize_another_command(self):
        for active_at in (1, 2):
            with self.subTest(active_at=active_at):
                engine, calls, _ = self.engine(lambda _name, count: count == active_at)
                with self.assertRaises(UP.Refusal) as failure:
                    engine.oneoff(Path("/private/fixture"), str(uuid.uuid4()), {}, "protocol")
                self.assertEqual("recovery_required", failure.exception.reason)
                self.assertEqual(active_at - 1, len(calls))

    def test_every_unsettled_operator_oneoff_blocks_source_release_and_target_refencing(self):
        for action in ("protocol", "assess", "receipt", "verify"):
            with self.subTest(action=action):
                engine, calls, _ = self.engine(lambda name, _: "-apply-" + action + "-" in name)
                op = str(uuid.uuid4())
                with self.assertRaises(UP.Refusal):
                    engine.settled({}, op)
                engine.all_ids = lambda _: []
                with self.assertRaises(UP.Refusal):
                    engine.ensure_target_window(Path("/private/fixture"), op, {})
                self.assertEqual([], calls)


if __name__ == "__main__":
    unittest.main(verbosity=2)
