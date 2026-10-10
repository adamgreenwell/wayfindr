#!/usr/bin/env python3
"""Adversarial U8 contract tests. All observations below are invented fixtures.

The complete fixture exercises consistency validation only. No VM, artifact,
upgrade, reboot, backup or restore is executed or qualified by these tests.
"""

import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import uuid

sys.dont_write_bytecode = True
ROOT = Path(__file__).absolute().parents[1]
SOURCE = ROOT / "scripts/self-host/update_vm_evidence.py"


def module(path=SOURCE):
    spec = importlib.util.spec_from_file_location("vm_evidence", path)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


EVIDENCE = module()
START = "2026-10-09T12:00:00Z"
END = "2026-10-09T13:00:00Z"


def digest(label):
    return hashlib.sha256(label.encode()).hexdigest()


def identifier(label):
    return str(uuid.uuid5(uuid.NAMESPACE_URL, "https://fixture.invalid/" + label))


def artifact(number, tag, architecture="amd64", store="classic"):
    item = {"id": "artifact-" + str(number), "tag": tag, "commit": digest(tag + "commit")[:40], "architecture": architecture,
            "publication": "published", "resolved_at": START, "chain_verified": True, "fresh_pull": True}
    for key in ("index_digest", "platform_manifest_digest", "config_digest"):
        item[key] = "sha256:" + digest(tag + key + (architecture if key != "index_digest" else ""))
    for key in ("manifest_sha256", "history_sha256", "installer_sha256", "compose_sha256", "actions_sha256"):
        item[key] = digest(tag + key)
    item.update(local_image_id=item["config_digest"] if store == "classic" else item["index_digest"],
                local_image_descriptor=None if store == "classic" else {"mediaType": "application/vnd.oci.image.index.v1+json",
                                                                       "digest": item["index_digest"], "size": 1000},
                execution_reference=EVIDENCE.IMAGE + ":" + tag[1:] + "@" + item["index_digest"], execution_platform="linux/" + architecture)
    return item


def environment(number, reboot=False, architecture="amd64", store="classic"):
    return {"id": "vm-" + str(number), "kind": "actual_vm", "os": "ubuntu_24_04", "architecture": architecture, "image_store": store,
            "fresh_os": True, "dedicated": True, "no_developer_mounts": True, "no_reused_data": True,
            "systemd": True, "docker_engine": True, "boot_id_before": identifier("boot-before-" + str(number)),
            "boot_id_after": identifier(("boot-after-" if reboot else "boot-before-") + str(number)),
            "reboot_requested_at": "2026-10-09T12:20:00Z" if reboot else None,
            "reboot_observed_at": "2026-10-09T12:21:00Z" if reboot else None,
            "observer_receipt_sha256": digest("vm-receipt-" + str(number))}


def serving(item, posture="target"):
    return {"posture": posture, "artifact_id": item["id"],
            "services": {name: {"before_id": digest("before-" + name), "after_id": digest("after-" + name),
                                "image_digest": item["local_image_id"], "healthy": True, "process_verified": True,
                                "platform_manifest_digest": item["platform_manifest_digest"] if item["local_image_descriptor"] is not None else None}
                         for name in EVIDENCE.SERVICES},
            "postgres_verified": True, "redis_verified": True, "configured_origin_verified": True,
            "support_loop_verified": True, "realtime_verified": True, "maintenance_verified": False,
            "writers_stopped_verified": False, "observer_receipt_sha256": digest("serving-receipt")}


def complete_fixture():
    """A synthetic fully populated record, never release or real-VM evidence."""
    source, patch, minor, major = [artifact(n, tag) for n, tag in enumerate(("v1.1.1", "v1.1.2", "v1.3.0", "v2.0.0"), 1)]
    result = EVIDENCE.empty_report("qualification", run_id=identifier("run"), observed_at=START)
    result.update(finished_at=END, limitations=[], artifacts=[source, patch, minor, major],
                  environments=[environment(1, True), environment(2)])
    hashes = {key: digest("logical-synthetic-" + key) for key in EVIDENCE.INVARIANTS}
    result["synthetic"] = {"content": "synthetic_only", "digest_method": "run_keyed_logical_sha256",
                           "fixture_id": identifier("fixture"), "erased_content_absent": True,
                           "invariants": {key: {"before": value, "after": value, "restored": value, "count": 3, "verified": True}
                                          for key, value in hashes.items()}}
    result["backup"] = {"environment_id": "vm-1", "source_artifact_id": source["id"],
                        "installation_identity_sha256": hashes["installation_identity"], "created": True,
                        "archive_sha256": digest("archive"), "manifest_sha256": digest("archive-manifest"),
                        "archive_bytes": 123_456, "archive_validated": True, "custody_verified": True, "retained": True,
                        "local_disks": 1, "remote_disks": 1, "remote_dependency_verified": True,
                        "observer_receipt_sha256": digest("backup-receipt")}
    result["restore"] = {"environment_id": "vm-2", "artifact_id": source["id"],
                         "installation_identity_sha256": hashes["installation_identity"],
                         "archive_sha256": digest("archive"), "executed": True, "succeeded": True, "independent_vm": True,
                         "origin_verified": True, "runtime_verified": True, "observer_receipt_sha256": digest("restore-receipt"),
                         "serving": serving(source, "previous")}
    for name in EVIDENCE.SCENARIOS:
        target = minor if name in {"stable_minor", "skipped_span"} else major if name == "major_actions" else patch
        succeeded = name in EVIDENCE.SUCCESS_CASES or name == "major_actions"
        phase = "succeeded" if succeeded else "recovery_required" if name in EVIDENCE.RECOVERY_CASES else "blocked"
        protected = {"phase": "retained", "archive_sha256": digest("archive-" + name),
                     "manifest_sha256": digest("archive-manifest-" + name), "custody_verified": True, "hold_owned": False}
        apply = {"phase": "verified", **{key: target[key] for key in ("index_digest", "platform_manifest_digest", "config_digest", "manifest_sha256", "history_sha256")},
                 "migration_receipt_sha256": digest("migrations-" + name), "runtime_receipt_sha256": digest("runtime-" + name),
                 "migration_started": True, "migration_verified": True, "services_verified": True, "origin_verified": True,
                 "configuration_committed": True, "hold_owned": False, "error": None}
        operation = {"operation_id": identifier(name), "request_id": identifier(name + "request"), "plan_id": digest(name + "plan"),
                     "requested_tag": target["tag"], "phase": phase, "checkpoint": "serving_verified" if succeeded else "plan_reported",
                     "mutation_started": succeeded or name in EVIDENCE.RECOVERY_CASES,
                     "source": {"version": source["tag"][1:], "commit": source["commit"]},
                     "target": {"tag": target["tag"], "version": target["tag"][1:], "commit": target["commit"], "image_digest": target["index_digest"]},
                     "apply": apply if succeeded else None, "protection": protected if succeeded else None,
                     "error": None if succeeded else "recovery_required" if name in EVIDENCE.RECOVERY_CASES else "prerequisites_unmet", "revision": 10}
        if name in EVIDENCE.RECOVERY_CASES:
            apply.update(phase="recovery_required", error="recovery_required", hold_owned=True,
                         migration_verified=False, services_verified=False, origin_verified=False, configuration_committed=False)
            protected.update(phase="captured", hold_owned=True)
            operation.update(apply=apply, protection=protected, checkpoint="migration_intent")
        posture = serving(target) if succeeded else serving(source, "previous")
        if phase == "recovery_required":
            posture.update(posture="maintenance", artifact_id=None, configured_origin_verified=False, support_loop_verified=False,
                           realtime_verified=False, maintenance_verified=True, writers_stopped_verified=True)
            for observed in posture["services"].values():
                observed.update(healthy=False, process_verified=False, after_id=None, image_digest=None, platform_manifest_digest=None)
        result["scenarios"].append({"id": name, "environment_id": "vm-1", "source_artifact_id": source["id"],
                                    "target_artifact_id": target["id"], "started_at": START, "finished_at": END,
                                    "execution": "actual_vm", "command_profile": "managed_update_vm_v1", "observer": "vm_probe_v1",
                                    "observer_receipt_sha256": digest("scenario-receipt-" + name), "helper_before": "0.3.0" if name == "new_app_old_helper" else "0.4.0",
                                    "helper_after": "0.5.0" if succeeded else "0.4.0", "app_protocol_before": 0 if name == "old_app_new_helper" else 1,
                                    "app_protocol_after": 0 if name == "old_app_new_helper" else 1, "helper_protocol": 1,
                                    "checks": {key: True for key in ("injection_observed", "expectation_verified", "no_duplicate_mutation",
                                                                    "exclusive_operation", "redaction_verified", "prerequisites_verified", "data_verified")},
                                    "operation": operation, "serving": posture, "synthetic_before": hashes.copy(), "synthetic_after": hashes.copy(),
                                    "executor_generation_before": identifier("generation-before"), "executor_generation_after": identifier("generation-after"),
                                    "mutation_count": int(operation["mutation_started"]), "conflicting_mutation_count": 0, "request_attempt_count": 2,
                                    "journey_before": {key: operation[key] for key in ("operation_id", "request_id", "plan_id")} | {"revision": 9}
                                    if name in {"helper_restart", "vm_reboot"} else None,
                                    "skipped_artifact_ids": [patch["id"]] if name == "skipped_span" else [],
                                    "action_evidence": {"manifest_sha256": target["manifest_sha256"], "requirements_sha256": target["actions_sha256"],
                                                        "refusal_operation_id": identifier("refusal"), "refusal_plan_id": digest("refusal-plan"),
                                                        "refusal_target": {key: target[key] for key in ("tag", "commit", "index_digest")},
                                                        "refusal_phase": "blocked", "refusal_error": "actions_required", "refusal_mutation_started": False,
                                                        "refusal_receipt_sha256": digest("refusal-receipt"), "fulfillment_receipt_sha256": digest("fulfillment-receipt"),
                                                        "fulfilled_verified": True} if name == "major_actions" else None})
    # Each store/platform must execute the complete matrix. These are still
    # invented consistency fixtures, not VM observations or release proof.
    first_cases = copy.deepcopy(result["scenarios"])
    first_artifacts = list(result["artifacts"])
    for number, architecture, store in ((3, "amd64", "containerd"), (4, "arm64", "classic"), (5, "arm64", "containerd")):
        result["environments"].append(environment(number, True, architecture, store))
        replacements = {old["id"]: artifact(len(result["artifacts"]) + offset, old["tag"], architecture, store)
                        for offset, old in enumerate(first_artifacts, 1)}
        result["artifacts"].extend(replacements.values())
        for original in first_cases:
            item = copy.deepcopy(original)
            item["environment_id"] = "vm-" + str(number)
            for field in ("source_artifact_id", "target_artifact_id"):
                item[field] = replacements[item[field]]["id"]
            item["skipped_artifact_ids"] = [replacements[key]["id"] for key in item["skipped_artifact_ids"]]
            if item["serving"]["artifact_id"] is not None:
                selected = replacements[item["serving"]["artifact_id"]]
                item["serving"]["artifact_id"] = selected["id"]
                for observed in item["serving"]["services"].values():
                    observed["image_digest"] = selected["local_image_id"]
                    observed["platform_manifest_digest"] = selected["platform_manifest_digest"] if store == "containerd" else None
            target = next(value for value in replacements.values() if value["id"] == item["target_artifact_id"])
            if item["operation"]["apply"] is not None:
                for key in ("index_digest", "platform_manifest_digest", "config_digest"):
                    item["operation"]["apply"][key] = target[key]
            result["scenarios"].append(item)
    return result


class EvidenceTests(unittest.TestCase):
    def setUp(self):
        self.report = complete_fixture()

    def validate(self):
        return EVIDENCE.validate_report(self.report)

    def case(self, name="stable_patch"):
        return next(item for item in self.report["scenarios"] if item["id"] == name)

    def assert_refused(self, code=None):
        result = self.validate()
        self.assertFalse(result["qualified"])
        self.assertNotEqual("qualified", result["status"])
        if code is not None:
            self.assertIn(code, result["issues"])

    def test_complete_synthetic_record_only_exercises_consistency(self):
        result = self.validate()
        self.assertEqual("qualified", result["status"])
        self.assertEqual(100, result["scenario_count"])
        self.assertEqual([], result["issues"])
        self.assertEqual([], result["missing_scenarios"])

    def test_scaffolds_never_count_as_execution(self):
        for claim in ("not_run", "blocked", "pre_publication", "qualification"):
            with self.subTest(claim=claim):
                result = EVIDENCE.validate_report(EVIDENCE.empty_report(claim, {"artifacts_unpublished", "vm_not_provisioned"}))
                self.assertEqual("unqualified" if claim == "qualification" else claim, result["status"])
                self.assertFalse(result["qualified"])
                self.assertEqual(list(EVIDENCE.SCENARIOS), result["missing_scenarios"])

    def test_limited_claims_cannot_promote_themselves(self):
        for claim in ("not_run", "blocked", "pre_publication"):
            self.report["claim"] = claim
            self.assert_refused()

    def test_unknown_fields_reject_raw_logs_and_secrets_without_echo(self):
        for record in (self.report, self.case(), self.case()["operation"], self.report["restore"]):
            record["raw_log"] = "Authorization: Bearer secret-customer-value"
            self.assert_refused("schema_invalid")
            self.assertNotIn("secret-customer-value", json.dumps(self.validate()))
            del record["raw_log"]

    def test_unknown_classifications_fail_closed(self):
        for record, key, value in ((self.report, "claim", "passed"), (self.case(), "execution", "success"),
                                   (self.case()["operation"], "phase", "ok"), (self.case()["operation"], "error", "exit0")):
            old = record[key]
            record[key] = value
            self.assert_refused("schema_invalid")
            record[key] = old

    def test_boolean_numbers_and_counts_keep_json_types(self):
        for record, key, value in ((self.report, "schema", True), (self.case(), "mutation_count", True),
                                   (self.case()["checks"], "expectation_verified", 1), (self.case(), "helper_protocol", True),
                                   (self.report["backup"], "archive_bytes", 0)):
            old = record[key]
            record[key] = value
            self.assert_refused("schema_invalid")
            record[key] = old

    def test_timestamp_order_and_window_are_bounded(self):
        for end in ("2026-10-09T11:59:59Z", "2027-01-01T00:00:00Z", "2026-10-09T13:00:00+00:00", "2026-99-99T13:00:00Z"):
            self.report["finished_at"] = end
            self.assert_refused("time_invalid")

    def test_missing_or_duplicated_cases_cannot_pass(self):
        self.report["scenarios"].pop()
        self.assert_refused("image_store_matrix_unverified")
        self.report["scenarios"].append(copy.deepcopy(self.report["scenarios"][0]))
        self.assert_refused("schema_invalid")

    def test_all_cases_are_required_on_each_native_platform_and_store(self):
        self.report["scenarios"] = self.report["scenarios"][:25]
        result = self.validate()
        self.assertEqual([], result["missing_scenarios"])
        self.assert_refused("image_store_matrix_unverified")
        self.report["scenarios"] = [item for item in self.report["scenarios"] if item["id"] != "pull_failure"]
        self.assert_refused("scenario_missing")

    def test_legacy_schema_remains_readable_but_can_never_qualify(self):
        self.report["schema"] = 1
        self.report["scenarios"] = self.report["scenarios"][:25]
        self.report["artifacts"] = self.report["artifacts"][:4]
        self.report["environments"] = self.report["environments"][:2]
        for item in self.report["artifacts"]:
            for key in EVIDENCE.BINDING_KEYS:
                del item[key]
        for item in self.report["environments"]:
            del item["image_store"]
        for item in self.report["scenarios"]:
            item["helper_after"] = "0.4.0"
            for observed in item["serving"]["services"].values():
                del observed["platform_manifest_digest"]
        for observed in self.report["restore"]["serving"]["services"].values():
            del observed["platform_manifest_digest"]
        result = self.validate()
        self.assertEqual("unqualified", result["status"])
        self.assertEqual(["legacy_image_binding"], result["issues"])
        self.assertEqual(25, result["scenario_count"])

    def test_legacy_scaffold_has_explicit_qualification_limitation(self):
        report = EVIDENCE.empty_report("blocked")
        report["schema"] = 1
        result = EVIDENCE.validate_report(report)
        self.assertEqual("blocked", result["status"])
        self.assertIn("legacy_image_binding", result["issues"])

    def test_local_binding_has_exact_index_selector_platform_and_descriptor(self):
        item = self.report["artifacts"][4]
        original = copy.deepcopy(item)
        mutations = (("local_image_id", "sha256:" + "f" * 64),
                     ("execution_reference", EVIDENCE.IMAGE + ":latest"),
                     ("execution_reference", EVIDENCE.IMAGE + "@" + item["index_digest"]),
                     ("execution_reference", EVIDENCE.IMAGE + ":" + item["tag"][1:] + "@" + item["config_digest"]),
                     ("execution_platform", "linux/arm64"),
                     ("local_image_descriptor", {**item["local_image_descriptor"], "digest": item["platform_manifest_digest"]}))
        for key, replacement in mutations:
            with self.subTest(key=key, replacement=replacement):
                item[key] = replacement
                self.assert_refused("identity_mismatch")
                item.clear()
                item.update(copy.deepcopy(original))
        item["local_image_descriptor"]["mediaType"] = "application/vnd.oci.image.manifest.v1+json"
        self.assert_refused("schema_invalid")

    def test_classic_and_containerd_bindings_cannot_be_interchanged(self):
        item = self.report["artifacts"][0]
        item["local_image_descriptor"] = {"mediaType": "application/vnd.oci.image.index.v1+json", "digest": item["index_digest"], "size": 1000}
        self.assert_refused("identity_mismatch")
        item["local_image_descriptor"] = None
        self.report["environments"][0]["image_store"] = "containerd"
        self.assert_refused("identity_mismatch")
        self.assert_refused("backup_unverified")
        self.report["environments"][0]["image_store"] = "classic"
        self.report["environments"][1]["image_store"] = "containerd"
        self.assert_refused("restore_unverified")

    def test_containerd_services_bind_to_observed_local_id_not_config_digest(self):
        item = self.report["artifacts"][4]
        case = next(entry for entry in self.report["scenarios"] if entry["environment_id"] == "vm-3" and entry["id"] == "below_floor")
        self.assertNotEqual(item["config_digest"], item["local_image_id"])
        case["serving"]["services"]["web"]["image_digest"] = item["config_digest"]
        self.assert_refused("serving_unverified")

    def test_index_local_id_does_not_substitute_for_actual_container_platform(self):
        item = self.report["artifacts"][4]
        case = next(entry for entry in self.report["scenarios"] if entry["environment_id"] == "vm-3" and entry["id"] == "below_floor")
        observed = case["serving"]["services"]["web"]
        self.assertEqual(item["index_digest"], observed["image_digest"])
        # The other native platform shares the public index and local image ID.
        other_platform = self.report["artifacts"][12]["platform_manifest_digest"]
        for replacement in (None, item["index_digest"], item["config_digest"], other_platform):
            with self.subTest(replacement=replacement):
                observed["platform_manifest_digest"] = replacement
                self.assert_refused("serving_unverified")
                self.assert_refused("image_store_matrix_unverified")
        del observed["platform_manifest_digest"]
        self.assert_refused("schema_invalid")

    def test_optional_classic_container_descriptor_must_match_selected_platform(self):
        selected = self.report["artifacts"][1]
        observed = self.case()["serving"]["services"]["web"]
        observed["platform_manifest_digest"] = selected["platform_manifest_digest"]
        self.assertTrue(self.validate()["qualified"])
        observed["platform_manifest_digest"] = selected["index_digest"]
        self.assert_refused("serving_unverified")

    def test_restored_container_descriptor_must_match_selected_platform(self):
        observed = self.report["restore"]["serving"]["services"]["scheduler"]
        observed["platform_manifest_digest"] = self.report["artifacts"][1]["platform_manifest_digest"]
        self.assert_refused("restore_unverified")

    def test_schema2_success_requires_helper_with_the_new_binding_implementation(self):
        self.case()["helper_after"] = "0.4.0"
        self.assert_refused("compatibility_unverified")
        self.assert_refused("image_store_matrix_unverified")

    def test_selected_manifest_local_id_is_supported_with_exact_descriptor(self):
        for item in self.report["artifacts"]:
            if item["local_image_descriptor"] is not None:
                item["local_image_id"] = item["platform_manifest_digest"]
                item["local_image_descriptor"] = {"mediaType": "application/vnd.oci.image.manifest.v1+json", "digest": item["platform_manifest_digest"], "size": 2000}
        artifacts = {item["id"]: item for item in self.report["artifacts"]}
        for case in self.report["scenarios"]:
            if case["serving"]["artifact_id"] is not None:
                for entry in case["serving"]["services"].values():
                    entry["image_digest"] = artifacts[case["serving"]["artifact_id"]]["local_image_id"]
        self.assertTrue(self.validate()["qualified"])

    def test_public_descriptor_summary_drops_optional_registry_data(self):
        value = {"mediaType": "application/vnd.oci.image.index.v1+json", "digest": "sha256:" + "a" * 64,
                 "size": 1000, "annotations": {"unknown": "private-source-value"}, "platform": {"os": "linux", "architecture": "amd64"}}
        self.assertEqual({key: value[key] for key in ("mediaType", "digest", "size")}, EVIDENCE.descriptor_summary(value))
        self.assertIsNone(EVIDENCE.descriptor_summary(None))
        self.report["artifacts"][4]["local_image_descriptor"] = value
        self.assert_refused("schema_invalid")
        self.assertNotIn("private-source-value", json.dumps(self.validate()))

    def test_same_release_observations_cannot_disagree_across_stores(self):
        item = self.report["artifacts"][4]
        for key in ("commit", "index_digest", "manifest_sha256", "config_digest", "platform_manifest_digest"):
            original = copy.deepcopy(item)
            item[key] = "f" * 40 if key == "commit" else "f" * 64 if key.endswith("sha256") else "sha256:" + "f" * 64
            if key == "index_digest":
                item.update(local_image_id=item[key], execution_reference=EVIDENCE.IMAGE + ":" + item["tag"][1:] + "@" + item[key])
                item["local_image_descriptor"]["digest"] = item[key]
            self.assert_refused("identity_mismatch")
            item.clear()
            item.update(original)

    def test_fixture_runner_and_container_are_not_actual_vm_observations(self):
        for kind in ("github_runner", "container", "fixture"):
            self.report["environments"][0]["kind"] = kind
            self.assert_refused("vm_isolation_unverified")
        self.report["environments"][0]["kind"] = "actual_vm"
        self.case()["execution"] = "fixture"
        self.assert_refused("scenario_unverified")

    def test_vm_isolation_facts_and_publication_are_required(self):
        for key in ("fresh_os", "dedicated", "no_developer_mounts", "no_reused_data", "systemd", "docker_engine"):
            self.report["environments"][0][key] = False
            self.assert_refused("vm_isolation_unverified")
            self.report["environments"][0][key] = True
        self.report["artifacts"][0]["publication"] = "pre_publication"
        self.assert_refused("publication_unverified")

    def test_artifact_resolution_chain_and_fresh_pull_cannot_be_skipped(self):
        for key in ("chain_verified", "fresh_pull"):
            self.report["artifacts"][0][key] = False
            self.assert_refused("artifact_chain_unverified")
            self.report["artifacts"][0][key] = True
        self.report["artifacts"][0]["config_digest"] = "local-image"
        self.assert_refused("schema_invalid")

    def test_requested_target_source_and_apply_chain_are_bound(self):
        case = self.case()
        for record, key, replacement in ((case["operation"], "requested_tag", "v9.9.9"),
                                         (case["operation"]["source"], "commit", "f" * 40),
                                         (case["operation"]["target"], "image_digest", "sha256:" + "f" * 64),
                                         (case["operation"]["apply"], "platform_manifest_digest", "sha256:" + "f" * 64),
                                         (case["operation"]["apply"], "config_digest", "sha256:" + "f" * 64),
                                         (case["operation"]["apply"], "history_sha256", "f" * 64)):
            old = record[key]
            record[key] = replacement
            self.assert_refused("identity_mismatch")
            record[key] = old

    def test_platform_artifacts_are_bound_to_the_actual_guest_architecture(self):
        self.report["environments"][0]["architecture"] = "arm64"
        self.assert_refused("identity_mismatch")
        self.report["environments"][0]["architecture"] = "amd64"
        self.report["environments"][1]["architecture"] = "arm64"
        self.assert_refused("restore_unverified")

    def test_success_requires_completed_guard_receipts_and_released_hold(self):
        apply = self.case()["operation"]["apply"]
        for key in ("migration_started", "migration_verified", "services_verified", "origin_verified", "configuration_committed"):
            apply[key] = False
            self.assert_refused("scenario_unverified")
            apply[key] = True
        for key in ("migration_receipt_sha256", "runtime_receipt_sha256"):
            old = apply[key]
            apply[key] = None
            self.assert_refused("scenario_unverified")
            apply[key] = old
        apply["hold_owned"] = True
        self.assert_refused("scenario_unverified")

    def test_success_requires_durable_operation_and_plan_identity(self):
        operation = self.case()["operation"]
        for key in ("operation_id", "request_id", "plan_id", "source", "target"):
            old = operation[key]
            operation[key] = None
            self.assert_refused("scenario_unverified")
            operation[key] = old
        operation["checkpoint"] = "runtime_verified"
        self.assert_refused("scenario_unverified")

    def test_http_health_alone_and_stale_services_are_insufficient(self):
        posture = self.case()["serving"]
        for key in ("configured_origin_verified", "support_loop_verified", "realtime_verified", "postgres_verified", "redis_verified"):
            posture[key] = False
            self.assert_refused("serving_unverified")
            posture[key] = True
        service = posture["services"]["scheduler"]
        service["after_id"] = service["before_id"]
        self.assert_refused("serving_unverified")
        service["after_id"] = digest("after-scheduler")
        service["image_digest"] = self.report["artifacts"][0]["config_digest"]
        self.assert_refused("serving_unverified")

    def test_distinct_service_roles_cannot_reuse_one_container(self):
        services = self.case()["serving"]["services"]
        services["queue"]["after_id"] = services["web"]["after_id"]
        self.assert_refused("serving_unverified")

    def test_post_migration_uncertainty_requires_visible_maintenance_and_stopped_writers(self):
        posture = self.case("interrupted_migration")["serving"]
        for key in ("maintenance_verified", "writers_stopped_verified"):
            posture[key] = False
            self.assert_refused("maintenance_unverified")
            posture[key] = True
        posture["support_loop_verified"] = True
        self.assert_refused("maintenance_unverified")

    def test_held_recovery_needs_the_actual_journal_identity_and_protective_chain(self):
        case = self.case("interrupted_migration")
        for field in ("apply", "protection", "plan_id", "source", "target"):
            old = case["operation"][field]
            case["operation"][field] = None
            self.assert_refused("maintenance_unverified")
            case["operation"][field] = old
        case["operation"]["apply"]["migration_started"] = False
        self.assert_refused("maintenance_unverified")

    def test_pre_mutation_failure_cannot_claim_a_target_or_hidden_mutation(self):
        case = self.case("backup_failure")
        case["operation"]["mutation_started"] = True
        case["mutation_count"] = 1
        self.assert_refused("scenario_unverified")
        case["operation"]["mutation_started"] = False
        case["mutation_count"] = 0
        case["serving"]["posture"] = "target"
        self.assert_refused("scenario_unverified")

    def test_reboot_needs_changed_same_vm_boot_identity_and_observed_time(self):
        env = self.report["environments"][0]
        env["boot_id_after"] = env["boot_id_before"]
        self.assert_refused("reboot_unverified")
        env["boot_id_after"] = identifier("changed")
        env["reboot_requested_at"] = None
        env["reboot_observed_at"] = None
        self.assert_refused("reboot_unverified")

    def test_helper_restart_needs_a_new_executor_generation(self):
        case = self.case("helper_restart")
        case["executor_generation_after"] = case["executor_generation_before"]
        self.assert_refused("reboot_unverified")

    def test_restart_and_reboot_resume_the_exact_pre_interruption_journey(self):
        for name in ("helper_restart", "vm_reboot"):
            case = self.case(name)
            before = case["journey_before"]
            for key in ("operation_id", "request_id", "plan_id", "revision"):
                old = before[key]
                before[key] = case["operation"]["revision"] if key == "revision" else digest("other-plan") if key == "plan_id" else identifier("other-journey")
                self.assert_refused("journey_unverified")
                before[key] = old
            case["journey_before"] = None
            self.assert_refused("journey_unverified")
            case["journey_before"] = before

    def test_skipped_span_needs_a_published_intermediate_release(self):
        case = self.case("skipped_span")
        for skipped in ([], [case["source_artifact_id"]], [case["target_artifact_id"]], ["artifact-4"]):
            case["skipped_artifact_ids"] = skipped
            self.assert_refused("skipped_span_unverified")
        case["skipped_artifact_ids"] = ["artifact-2"]
        case["target_artifact_id"] = "artifact-2"
        self.assert_refused("skipped_span_unverified")

    def test_major_actions_need_bound_refusal_and_fulfilled_success(self):
        case = self.case("major_actions")
        action = case["action_evidence"]
        for key, replacement in (("fulfilled_verified", False), ("refusal_mutation_started", True),
                                 ("requirements_sha256", "f" * 64), ("manifest_sha256", "f" * 64),
                                 ("refusal_receipt_sha256", action["fulfillment_receipt_sha256"])):
            old = action[key]
            action[key] = replacement
            self.assert_refused("major_actions_unverified")
            action[key] = old
        case["operation"]["phase"] = "blocked"
        self.assert_refused("major_actions_unverified")
        case["operation"]["phase"] = "succeeded"
        action["refusal_target"]["tag"] = "v9.9.9"
        self.assert_refused("major_actions_unverified")

    def test_duplicate_and_conflicting_requests_cannot_duplicate_mutation(self):
        for name in ("duplicate_requests", "conflicting_operations"):
            case = self.case(name)
            case["mutation_count"] = 2
            self.assert_refused("scenario_unverified")
            case["mutation_count"] = 1
            case["conflicting_mutation_count"] = 1
            self.assert_refused("scenario_unverified")
            case["conflicting_mutation_count"] = 0
            case["request_attempt_count"] = 1
            self.assert_refused("scenario_unverified")
            case["request_attempt_count"] = 2

    def test_backup_creation_cannot_substitute_for_independent_restore(self):
        original = self.report["restore"]
        self.report["restore"] = None
        self.assert_refused("restore_unverified")
        self.report["restore"] = original
        original["environment_id"] = self.report["backup"]["environment_id"]
        self.assert_refused("restore_unverified")
        original["environment_id"] = "vm-2"
        original["archive_sha256"] = "f" * 64
        self.assert_refused("restore_unverified")

    def test_restore_requires_exact_artifact_runtime_and_instance_identity(self):
        restore = self.report["restore"]
        restore["serving"]["services"]["web"]["image_digest"] = self.report["artifacts"][1]["config_digest"]
        self.assert_refused("restore_unverified")
        restore["serving"] = serving(self.report["artifacts"][0], "previous")
        restore["installation_identity_sha256"] = "f" * 64
        self.assert_refused("restore_unverified")

    def test_protective_archive_and_remote_dependencies_must_survive(self):
        saved = self.report["backup"]
        for key in ("created", "archive_validated", "custody_verified", "retained", "remote_dependency_verified"):
            saved[key] = False
            self.assert_refused("backup_unverified")
            saved[key] = True
        saved["remote_disks"] = 0
        self.assert_refused("backup_unverified")

    def test_all_synthetic_logical_data_and_erasure_observations_are_bound(self):
        for name in EVIDENCE.INVARIANTS:
            item = self.report["synthetic"]["invariants"][name]
            old = item["after"]
            item["after"] = "f" * 64
            self.assert_refused("synthetic_invariants_unverified")
            item["after"] = old
            self.case()["synthetic_after"][name] = "f" * 64
            self.assert_refused("synthetic_invariants_unverified")
            self.case()["synthetic_after"][name] = old
        self.report["synthetic"]["erased_content_absent"] = False
        self.assert_refused("synthetic_invariants_unverified")

    def test_compatibility_is_exact_and_new_app_old_helper_stays_refused(self):
        case = self.case()
        case["helper_after"] = "0.3.0"
        self.assert_refused("compatibility_unverified")
        case["helper_after"] = "0.4.0"
        case["helper_protocol"] = 2
        self.assert_refused("compatibility_unverified")
        case["helper_protocol"] = 1
        self.case("new_app_old_helper")["helper_before"] = "0.4.0"
        self.assert_refused("compatibility_unverified")

    def test_refused_app_cannot_silently_gain_the_target_protocol(self):
        self.case("old_app_new_helper")["app_protocol_after"] = 1
        self.assert_refused("compatibility_unverified")

    def test_parser_rejects_duplicates_non_json_numbers_depth_and_oversize(self):
        for raw in (b'{"schema":1,"schema":1}', b'{"schema":NaN}', b'{"schema":Infinity}', b'\xff',
                    b"[" * 1200 + b"0" + b"]" * 1200, b" " * (EVIDENCE.MAX_BYTES + 1)):
            with self.subTest(raw_length=len(raw)), self.assertRaises(EVIDENCE.Invalid):
                EVIDENCE.parse(raw)

    def test_cli_is_read_only_machine_output_with_distinct_exit_statuses(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "report.json"
            for report, expected in ((self.report, 0), (EVIDENCE.empty_report("blocked"), 2), ({"raw_log": "secret"}, 1)):
                raw = json.dumps(report).encode()
                path.write_bytes(raw)
                before = path.stat()
                result = subprocess.run([sys.executable, str(SOURCE), str(path)], capture_output=True, text=True, check=False)
                self.assertEqual(expected, result.returncode)
                self.assertEqual(b"", result.stderr.encode())
                self.assertEqual(raw, path.read_bytes())
                self.assertEqual(before.st_mtime_ns, path.stat().st_mtime_ns)
                self.assertNotIn("secret", result.stdout)
                self.assertEqual(expected == 0, json.loads(result.stdout)["qualified"])

    def test_missing_file_and_oversize_fail_without_paths_or_content(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "secret-customer-path.json"
            for create in (False, True):
                if create:
                    path.write_bytes(b" " * (EVIDENCE.MAX_BYTES + 1))
                result = subprocess.run([sys.executable, str(SOURCE), str(path)], capture_output=True, text=True, check=False)
                self.assertEqual(1, result.returncode)
                self.assertNotIn("secret-customer-path", result.stdout + result.stderr)
                self.assertEqual(["input_invalid"], json.loads(result.stdout)["issues"])

    def test_source_copy_mutations_demonstrate_target_and_reboot_guards(self):
        # Mutants exist only in a private temp directory; working source is untouched.
        original = SOURCE.read_text()
        mutations = (
            ("current[\"requested_tag\"] != target[\"tag\"]", "False", "target"),
            ("env[\"boot_id_before\"] == env[\"boot_id_after\"]", "False", "reboot"),
        )
        with tempfile.TemporaryDirectory() as directory:
            for old, new, kind in mutations:
                with self.subTest(kind=kind):
                    self.assertEqual(1, original.count(old))
                    copied = Path(directory) / (kind + ".py")
                    copied.write_text(original.replace(old, new, 1))
                    record = complete_fixture()
                    if kind == "target":
                        record["scenarios"][0]["operation"]["requested_tag"] = "v9.9.9"
                    else:
                        record["environments"][0]["boot_id_after"] = record["environments"][0]["boot_id_before"]
                    self.assertFalse(EVIDENCE.validate_report(record)["qualified"])
                    self.assertTrue(module(copied).validate_report(record)["qualified"])
        self.assertEqual(original, SOURCE.read_text())

    def test_source_copy_mutations_demonstrate_local_image_and_selector_guards(self):
        original = SOURCE.read_text()
        mutations = (
            ('service["image_digest"] != selected["local_image_id" if schema == 2 else "config_digest"]', 'False', "local-image"),
            ('        image_binding(value)', '        pass', "selector"),
            ('or not service_platform_matches(service, selected, schema)', 'or False', "container-platform"),
            ('if any(names != set(SCENARIOS) for names in covered.values()):', 'if False:', "matrix"),
        )
        with tempfile.TemporaryDirectory() as directory:
            for old, new, kind in mutations:
                with self.subTest(kind=kind):
                    self.assertEqual(1, original.count(old))
                    copied = Path(directory) / (kind + ".py")
                    copied.write_text(original.replace(old, new, 1))
                    record = complete_fixture()
                    if kind == "local-image":
                        item = record["artifacts"][4]
                        case = next(entry for entry in record["scenarios"] if entry["environment_id"] == "vm-3" and entry["id"] == "below_floor")
                        case["serving"]["services"]["web"]["image_digest"] = item["config_digest"]
                    elif kind == "container-platform":
                        case = next(entry for entry in record["scenarios"] if entry["environment_id"] == "vm-3" and entry["id"] == "below_floor")
                        case["serving"]["services"]["web"]["platform_manifest_digest"] = record["artifacts"][12]["platform_manifest_digest"]
                    elif kind == "selector":
                        record["artifacts"][4]["execution_reference"] = EVIDENCE.IMAGE + ":latest"
                    else:
                        record["scenarios"].pop()
                    self.assertFalse(EVIDENCE.validate_report(record)["qualified"])
                    self.assertTrue(module(copied).validate_report(record)["qualified"])
        self.assertEqual(original, SOURCE.read_text())


if __name__ == "__main__":
    unittest.main()
