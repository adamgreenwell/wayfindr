#!/usr/bin/env python3
"""Validate bounded, sanitized U8 evidence; never provision, execute, or restore.

This checks consistency of recorded observations. It cannot authenticate a
self-reported VM observation, and fixture validation is not release evidence.
Only the full published-artifact matrix, repeated for native amd64 and arm64 on
both classic and containerd image stores, can receive ``qualified``. Exit 0 is
reserved for that result; valid incomplete reports exit 2 and invalid input 1.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
from pathlib import Path
import re
import sys
import uuid

MAX_BYTES = 1_048_576
SCENARIOS = (
    "stable_patch", "stable_minor", "major_actions", "skipped_span", "below_floor",
    "legacy_identity", "metadata_failure", "pull_failure", "disk_failure", "backup_failure",
    "unsupported_ownership", "duplicate_requests", "conflicting_operations", "long_jobs",
    "browser_network_loss", "helper_restart", "vm_reboot", "interrupted_migration",
    "stale_services", "broken_origin", "broken_realtime", "authorization", "protocol_boundary",
    "old_app_new_helper", "new_app_old_helper",
)
LIMITATIONS = {
    "artifacts_unpublished", "vm_provider_unavailable", "vm_not_provisioned", "pre_publication_build",
    "remote_storage_unavailable", "reboot_not_executed", "restore_not_executed", "matrix_incomplete",
    "unsupported_platform", "fixture_only", "artifacts_unverified",
}
INVARIANTS = (
    "conversations", "local_attachments", "remote_attachments", "encrypted_settings", "application_key",
    "erasure_tombstones", "installation_identity", "tls_certificates", "proxy_configuration",
)
SERVICES = ("web", "queue", "backup-queue", "scheduler", "reverb")
HEX = re.compile(r"[0-9a-f]{64}\Z")
DIGEST = re.compile(r"sha256:[0-9a-f]{64}\Z")
COMMIT = re.compile(r"(?:[0-9a-f]{40}|[0-9a-f]{64})\Z")
VERSION = re.compile(r"(0|[1-9][0-9]{0,5})\.(0|[1-9][0-9]{0,5})\.(0|[1-9][0-9]{0,5})\Z")
TAG = re.compile(r"v(0|[1-9][0-9]{0,5})\.(0|[1-9][0-9]{0,5})\.(0|[1-9][0-9]{0,5})\Z")
PHASES = {"rejected", "blocked", "succeeded", "failed_safe", "cancelled", "recovery_required", "reconciliation_required"}
CHECKPOINTS = {
    "none", "accepted", "prepare_started", "plan_reported", "protection_started", "fenced", "drained",
    "backup_verified", "services_resumed", "protection_released", "apply_started", "target_download_intent",
    "target_verified", "data_protected", "migration_intent", "migrations_verified", "target_restart_intent",
    "target_services_started", "runtime_verified", "configuration_commit_intent", "configuration_committed",
    "apply_release_intent", "serving_verified", "previous_serving_verified",
}
ERRORS = {
    "authentication_failed", "request_invalid", "protocol_unsupported", "installation_mismatch", "replay_detected",
    "request_expired", "operation_busy", "idempotency_conflict", "helper_unavailable", "configuration_changed",
    "prepare_failed", "prepare_timeout", "prepare_output_invalid", "prerequisites_unmet", "identity_unverified",
    "no_update_required", "execution_not_available", "reconciliation_required", "interrupted_prepare",
    "protection_failed", "protection_timeout", "drain_timeout", "backup_failed", "backup_invalid", "custody_failed",
    "recovery_required", "source_changed", "maintenance_present", "writer_unverified", "plan_mismatch",
    "cancel_unavailable", "apply_unavailable", "apply_failed", "apply_timeout", "download_failed", "artifact_invalid",
    "artifact_verification_failed", "platform_mismatch", "migration_failed", "migration_ambiguous",
    "runtime_verification_failed", "origin_verification_failed", "configuration_commit_failed", "cancelled",
    "metadata_unavailable", "disk_full", "ownership_unsupported", "minimum_version_unmet", "actions_required",
}
CLAIMS = {"qualification", "not_run", "blocked", "pre_publication"}
SUCCESS_CASES = {
    "stable_patch", "stable_minor", "skipped_span", "duplicate_requests", "conflicting_operations", "long_jobs",
    "browser_network_loss", "helper_restart", "vm_reboot",
}
REFUSAL_CASES = {
    "below_floor", "legacy_identity", "metadata_failure", "unsupported_ownership", "authorization", "protocol_boundary",
    "old_app_new_helper", "new_app_old_helper",
}
RECOVERY_CASES = {"interrupted_migration", "stale_services", "broken_origin", "broken_realtime"}
TOP_KEYS = {
    "schema", "claim", "run_id", "started_at", "finished_at", "artifacts", "environments", "backup", "restore",
    "synthetic", "scenarios", "limitations",
}
IMAGE = "ghcr.io/adamgreenwell/wayfindr"
IMAGE_STORES = {"classic", "containerd"}
STORE_PLATFORMS = {(architecture, store) for architecture in ("amd64", "arm64") for store in IMAGE_STORES}
INDEX_TYPES = {"application/vnd.oci.image.index.v1+json", "application/vnd.docker.distribution.manifest.list.v2+json"}
MANIFEST_TYPES = {"application/vnd.oci.image.manifest.v1+json", "application/vnd.docker.distribution.manifest.v2+json"}
BINDING_KEYS = {"local_image_id", "local_image_descriptor", "execution_reference", "execution_platform"}


class Invalid(ValueError):
    """Classified structural refusal; never include supplied text in output."""


def object_shape(value, keys):
    if type(value) is not dict or set(value) != set(keys):
        raise Invalid("schema_invalid")


def enum(value, choices):
    if type(value) is not str or value not in choices:
        raise Invalid("schema_invalid")


def pattern(value, expression):
    if type(value) is not str or len(value) > 128 or not expression.fullmatch(value):
        raise Invalid("schema_invalid")


def boolean(value):
    if type(value) is not bool:
        raise Invalid("schema_invalid")


def integer(value, minimum=0, maximum=2**53 - 1):
    if type(value) is not int or not minimum <= value <= maximum:
        raise Invalid("schema_invalid")


def identifier(value):
    if type(value) is not str:
        raise Invalid("schema_invalid")
    try:
        if str(uuid.UUID(value)) != value:
            raise ValueError()
    except ValueError:
        raise Invalid("schema_invalid") from None


def timestamp(value):
    if type(value) is not str or len(value) != 20:
        raise Invalid("time_invalid")
    try:
        parsed = dt.datetime.strptime(value, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=dt.timezone.utc)
        if parsed.strftime("%Y-%m-%dT%H:%M:%SZ") != value:
            raise ValueError()
        return parsed
    except ValueError:
        raise Invalid("time_invalid") from None


def period(value, start, end):
    a, b = timestamp(value["started_at"]), timestamp(value["finished_at"])
    if not start <= a <= b <= end:
        raise Invalid("time_invalid")


def array(value, maximum):
    if type(value) is not list or len(value) > maximum:
        raise Invalid("schema_invalid")


def flags(value, keys):
    object_shape(value, keys)
    for entry in value.values():
        boolean(entry)


def empty_report(claim="not_run", limitations=None, *, run_id=None, observed_at=None):
    """Create an honest scaffold, containing no observations or secret values."""
    at = observed_at or dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    return {"schema": 2, "claim": claim, "run_id": run_id or str(uuid.uuid4()), "started_at": at, "finished_at": at,
            "artifacts": [], "environments": [], "backup": None, "restore": None, "synthetic": None,
            "scenarios": [], "limitations": sorted(limitations or {"matrix_incomplete"})}


def parse(raw):
    """Bound JSON, reject duplicate keys/non-JSON numbers before schema checks."""
    if type(raw) is not bytes or len(raw) > MAX_BYTES:
        raise Invalid("input_invalid")

    def pairs(entries):
        result = {}
        for key, value in entries:
            if key in result:
                raise Invalid("input_invalid")
            result[key] = value
        return result

    def constant(_):
        raise Invalid("input_invalid")

    try:
        result = json.loads(raw.decode("utf-8"), object_pairs_hook=pairs, parse_constant=constant)
        def bounded(item, depth=0):
            if depth > 16:
                raise Invalid("input_invalid")
            if type(item) is dict:
                for key, entry in item.items():
                    bounded(key, depth + 1)
                    bounded(entry, depth + 1)
            elif type(item) is list:
                for entry in item:
                    bounded(entry, depth + 1)
            elif type(item) is str and (len(item) > 128 or any(0xD800 <= ord(char) <= 0xDFFF for char in item)):
                raise Invalid("input_invalid")
        bounded(result)
        if type(result) is not dict:
            raise Invalid("input_invalid")
        return result
    except (UnicodeError, ValueError, RecursionError):
        raise Invalid("input_invalid") from None


def descriptor_summary(value):
    """Project verified private OCI metadata into the public report allowlist.

    Registry annotations and other optional source fields are never public log
    fields. This projection does not verify a registry chain or a Docker image.
    """
    if value is None:
        return None
    if type(value) is not dict or not {"mediaType", "digest", "size"} <= set(value):
        raise Invalid("schema_invalid")
    return {key: value[key] for key in ("mediaType", "digest", "size")}


def artifact_store(value):
    return "classic" if value["local_image_descriptor"] is None else "containerd"


def image_binding(value):
    pattern(value["local_image_id"], DIGEST)
    if (value["execution_reference"] != IMAGE + ":" + value["tag"][1:] + "@" + value["index_digest"]
            or value["execution_platform"] != "linux/" + value["architecture"]):
        raise Invalid("identity_mismatch")
    descriptor = value["local_image_descriptor"]
    if value["local_image_id"] == value["config_digest"]:
        if descriptor is not None:
            raise Invalid("identity_mismatch")
        return
    if value["local_image_id"] not in {value["index_digest"], value["platform_manifest_digest"]}:
        raise Invalid("identity_mismatch")
    object_shape(descriptor, {"mediaType", "digest", "size"})
    pattern(descriptor["digest"], DIGEST)
    integer(descriptor["size"], 1, 1_000_000_000)
    enum(descriptor["mediaType"], INDEX_TYPES if value["local_image_id"] == value["index_digest"] else MANIFEST_TYPES)
    if descriptor["digest"] != value["local_image_id"]:
        raise Invalid("identity_mismatch")


def artifact(value, start, end, schema=1):
    keys = {"id", "tag", "commit", "architecture", "index_digest", "platform_manifest_digest", "config_digest",
                         "manifest_sha256", "history_sha256", "installer_sha256", "compose_sha256", "actions_sha256", "publication",
                         "resolved_at", "chain_verified", "fresh_pull"}
    object_shape(value, keys | BINDING_KEYS if schema == 2 else keys)
    pattern(value["id"], re.compile(r"artifact-[1-9][0-9]{0,2}\Z"))
    pattern(value["tag"], TAG)
    pattern(value["commit"], COMMIT)
    enum(value["architecture"], {"amd64", "arm64"})
    for key in ("index_digest", "platform_manifest_digest", "config_digest"):
        pattern(value[key], DIGEST)
    for key in ("manifest_sha256", "history_sha256", "installer_sha256", "compose_sha256", "actions_sha256"):
        pattern(value[key], HEX)
    if schema == 2:
        image_binding(value)
    enum(value["publication"], {"published", "pre_publication"})
    boolean(value["chain_verified"])
    boolean(value["fresh_pull"])
    if not start <= timestamp(value["resolved_at"]) <= end:
        raise Invalid("time_invalid")


def environment(value, start, end, schema=1):
    keys = {"id", "kind", "os", "architecture", "fresh_os", "dedicated", "no_developer_mounts",
                         "no_reused_data", "systemd", "docker_engine", "boot_id_before", "boot_id_after",
                         "reboot_requested_at", "reboot_observed_at", "observer_receipt_sha256"}
    object_shape(value, keys | {"image_store"} if schema == 2 else keys)
    pattern(value["id"], re.compile(r"vm-[1-9][0-9]{0,2}\Z"))
    enum(value["kind"], {"actual_vm", "github_runner", "container", "fixture"})
    enum(value["os"], {"ubuntu_24_04", "unsupported"})
    enum(value["architecture"], {"amd64", "arm64", "unsupported"})
    if schema == 2:
        enum(value["image_store"], IMAGE_STORES)
    for key in ("fresh_os", "dedicated", "no_developer_mounts", "no_reused_data", "systemd", "docker_engine"):
        boolean(value[key])
    for key in ("boot_id_before", "boot_id_after"):
        identifier(value[key])
    pattern(value["observer_receipt_sha256"], HEX)
    a, b = value["reboot_requested_at"], value["reboot_observed_at"]
    if a is None and b is None:
        return
    if a is None or b is None or not start <= timestamp(a) <= timestamp(b) <= end:
        raise Invalid("time_invalid")


def backup(value, environments, artifacts):
    object_shape(value, {"environment_id", "source_artifact_id", "installation_identity_sha256", "created", "archive_sha256", "manifest_sha256", "archive_bytes",
                         "archive_validated", "custody_verified", "retained", "local_disks", "remote_disks",
                         "remote_dependency_verified", "observer_receipt_sha256"})
    if value["environment_id"] not in environments or value["source_artifact_id"] not in artifacts:
        raise Invalid("schema_invalid")
    for key in ("archive_sha256", "manifest_sha256", "observer_receipt_sha256", "installation_identity_sha256"):
        pattern(value[key], HEX)
    for key in ("created", "archive_validated", "custody_verified", "retained", "remote_dependency_verified"):
        boolean(value[key])
    integer(value["archive_bytes"], 1)
    integer(value["local_disks"], 0, 64)
    integer(value["remote_disks"], 0, 64)


def restore(value, environments, artifacts, schema=1):
    object_shape(value, {"environment_id", "artifact_id", "installation_identity_sha256", "archive_sha256", "executed", "succeeded", "independent_vm",
                         "origin_verified", "runtime_verified", "observer_receipt_sha256", "serving"})
    if value["environment_id"] not in environments or value["artifact_id"] not in artifacts:
        raise Invalid("schema_invalid")
    for key in ("archive_sha256", "observer_receipt_sha256", "installation_identity_sha256"):
        pattern(value[key], HEX)
    for key in ("executed", "succeeded", "independent_vm", "origin_verified", "runtime_verified"):
        boolean(value[key])
    serving(value["serving"], schema)


def synthetic(value):
    object_shape(value, {"content", "digest_method", "fixture_id", "invariants", "erased_content_absent"})
    enum(value["content"], {"synthetic_only"})
    enum(value["digest_method"], {"run_keyed_logical_sha256"})
    identifier(value["fixture_id"])
    boolean(value["erased_content_absent"])
    object_shape(value["invariants"], INVARIANTS)
    for observation in value["invariants"].values():
        object_shape(observation, {"before", "after", "restored", "count", "verified"})
        for key in ("before", "after", "restored"):
            pattern(observation[key], HEX)
        integer(observation["count"], 1, 100_000)
        boolean(observation["verified"])


def operation(value):
    object_shape(value, {"operation_id", "request_id", "plan_id", "requested_tag", "phase", "checkpoint",
                         "mutation_started", "source", "target", "apply", "protection", "error", "revision"})
    for key in ("operation_id", "request_id"):
        if value[key] is not None:
            identifier(value[key])
    if value["plan_id"] is not None:
        pattern(value["plan_id"], HEX)
    pattern(value["requested_tag"], TAG)
    enum(value["phase"], PHASES)
    enum(value["checkpoint"], CHECKPOINTS)
    boolean(value["mutation_started"])
    integer(value["revision"])
    if value["error"] is not None:
        enum(value["error"], ERRORS)
    if value["source"] is not None:
        object_shape(value["source"], {"version", "commit"})
        pattern(value["source"]["version"], VERSION)
        pattern(value["source"]["commit"], COMMIT)
    if value["target"] is not None:
        object_shape(value["target"], {"tag", "version", "commit", "image_digest"})
        pattern(value["target"]["tag"], TAG)
        pattern(value["target"]["version"], VERSION)
        pattern(value["target"]["commit"], COMMIT)
        pattern(value["target"]["image_digest"], DIGEST)
    if value["apply"] is not None:
        object_shape(value["apply"], {"phase", "index_digest", "platform_manifest_digest", "config_digest",
                                     "manifest_sha256", "history_sha256", "migration_receipt_sha256",
                                     "runtime_receipt_sha256", "migration_started", "migration_verified", "services_verified",
                                     "origin_verified", "configuration_committed", "hold_owned", "error"})
        enum(value["apply"]["phase"], {"verified", "fallback", "recovery_required"})
        for key in ("index_digest", "platform_manifest_digest", "config_digest"):
            if value["apply"][key] is not None:
                pattern(value["apply"][key], DIGEST)
        for key in ("manifest_sha256", "history_sha256", "migration_receipt_sha256", "runtime_receipt_sha256"):
            if value["apply"][key] is not None:
                pattern(value["apply"][key], HEX)
        for key in ("migration_started", "migration_verified", "services_verified", "origin_verified", "configuration_committed", "hold_owned"):
            boolean(value["apply"][key])
        if value["apply"]["error"] is not None:
            enum(value["apply"]["error"], ERRORS)
    if value["protection"] is not None:
        object_shape(value["protection"], {"phase", "archive_sha256", "manifest_sha256", "custody_verified", "hold_owned"})
        enum(value["protection"]["phase"], {"captured", "retained", "verified", "recovery_required"})
        for key in ("archive_sha256", "manifest_sha256"):
            if value["protection"][key] is not None:
                pattern(value["protection"][key], HEX)
        boolean(value["protection"]["custody_verified"])
        boolean(value["protection"]["hold_owned"])


def serving(value, schema=1):
    object_shape(value, {"posture", "artifact_id", "services", "postgres_verified", "redis_verified",
                         "configured_origin_verified", "support_loop_verified", "realtime_verified", "maintenance_verified",
                         "writers_stopped_verified", "observer_receipt_sha256"})
    enum(value["posture"], {"target", "previous", "maintenance"})
    if value["artifact_id"] is not None:
        pattern(value["artifact_id"], re.compile(r"artifact-[1-9][0-9]{0,2}\Z"))
    pattern(value["observer_receipt_sha256"], HEX)
    for key in ("postgres_verified", "redis_verified", "configured_origin_verified", "support_loop_verified", "realtime_verified",
                "maintenance_verified", "writers_stopped_verified"):
        boolean(value[key])
    object_shape(value["services"], SERVICES)
    for service in value["services"].values():
        keys = {"before_id", "after_id", "image_digest", "healthy", "process_verified"}
        object_shape(service, keys | {"platform_manifest_digest"} if schema == 2 else keys)
        for key in ("before_id", "after_id"):
            if service[key] is not None:
                pattern(service[key], HEX)
        if service["image_digest"] is not None:
            pattern(service["image_digest"], DIGEST)
        if schema == 2 and service["platform_manifest_digest"] is not None:
            pattern(service["platform_manifest_digest"], DIGEST)
        boolean(service["healthy"])
        boolean(service["process_verified"])


def scenario(value, environments, artifacts, start, end, schema=1):
    object_shape(value, {"id", "environment_id", "source_artifact_id", "target_artifact_id", "started_at", "finished_at",
                         "execution", "command_profile", "observer", "observer_receipt_sha256", "helper_before", "helper_after",
                         "app_protocol_before", "app_protocol_after", "helper_protocol", "checks", "operation", "serving",
                         "synthetic_before", "synthetic_after", "executor_generation_before", "executor_generation_after",
                         "mutation_count", "conflicting_mutation_count", "request_attempt_count", "journey_before",
                         "skipped_artifact_ids", "action_evidence"})
    enum(value["id"], SCENARIOS)
    for key, collection in (("environment_id", environments), ("source_artifact_id", artifacts), ("target_artifact_id", artifacts)):
        if type(value[key]) is not str or value[key] not in collection:
            raise Invalid("schema_invalid")
    period(value, start, end)
    enum(value["execution"], {"actual_vm", "fixture", "not_run"})
    enum(value["command_profile"], {"managed_update_vm_v1"})
    enum(value["observer"], {"vm_probe_v1", "fixture"})
    pattern(value["observer_receipt_sha256"], HEX)
    for key in ("helper_before", "helper_after"):
        pattern(value[key], VERSION)
    for key in ("app_protocol_before", "app_protocol_after", "helper_protocol"):
        integer(value[key], 0, 255)
    for key in ("executor_generation_before", "executor_generation_after"):
        identifier(value[key])
    for key in ("mutation_count", "conflicting_mutation_count", "request_attempt_count"):
        integer(value[key], 0, 1000)
    for key in ("synthetic_before", "synthetic_after"):
        object_shape(value[key], INVARIANTS)
        for digest in value[key].values():
            pattern(digest, HEX)
    if value["journey_before"] is not None:
        object_shape(value["journey_before"], {"operation_id", "request_id", "plan_id", "revision"})
        for key in ("operation_id", "request_id"):
            identifier(value["journey_before"][key])
        pattern(value["journey_before"]["plan_id"], HEX)
        integer(value["journey_before"]["revision"], 1)
    array(value["skipped_artifact_ids"], 16)
    if len(set(value["skipped_artifact_ids"])) != len(value["skipped_artifact_ids"]):
        raise Invalid("schema_invalid")
    for artifact_id in value["skipped_artifact_ids"]:
        if type(artifact_id) is not str or artifact_id not in artifacts:
            raise Invalid("schema_invalid")
    if value["action_evidence"] is not None:
        action = value["action_evidence"]
        object_shape(action, {"manifest_sha256", "requirements_sha256", "refusal_operation_id", "refusal_plan_id", "refusal_target",
                              "refusal_phase", "refusal_error", "refusal_mutation_started", "refusal_receipt_sha256",
                              "fulfillment_receipt_sha256", "fulfilled_verified"})
        for key in ("manifest_sha256", "requirements_sha256", "refusal_plan_id", "refusal_receipt_sha256", "fulfillment_receipt_sha256"):
            pattern(action[key], HEX)
        identifier(action["refusal_operation_id"])
        object_shape(action["refusal_target"], {"tag", "commit", "index_digest"})
        pattern(action["refusal_target"]["tag"], TAG)
        pattern(action["refusal_target"]["commit"], COMMIT)
        pattern(action["refusal_target"]["index_digest"], DIGEST)
        enum(action["refusal_phase"], {"blocked", "rejected"})
        enum(action["refusal_error"], {"actions_required", "prerequisites_unmet"})
        boolean(action["refusal_mutation_started"])
        boolean(action["fulfilled_verified"])
    flags(value["checks"], {"injection_observed", "expectation_verified", "no_duplicate_mutation", "exclusive_operation",
                            "redaction_verified", "prerequisites_verified", "data_verified"})
    operation(value["operation"])
    serving(value["serving"], schema)


def service_platform_matches(service, selected, schema):
    if schema == 1:
        return True
    observed = service["platform_manifest_digest"]
    return observed == selected["platform_manifest_digest"] or (observed is None and artifact_store(selected) == "classic")


def version(tag):
    return tuple(int(part) for part in tag.removeprefix("v").split("."))


def qualified_scenario(item, artifacts, environments, issues, data, schema=1):
    source, target = artifacts[item["source_artifact_id"]], artifacts[item["target_artifact_id"]]
    current, posture = item["operation"], item["serving"]
    ident = item["id"]
    if source["architecture"] != environments[item["environment_id"]]["architecture"] or target["architecture"] != source["architecture"]:
        issues.add("identity_mismatch")
    if schema == 2 and any(artifact_store(selected) != environments[item["environment_id"]]["image_store"] for selected in (source, target)):
        issues.add("identity_mismatch")
    if item["execution"] != "actual_vm" or item["observer"] != "vm_probe_v1" or environments[item["environment_id"]]["kind"] != "actual_vm" or not all(item["checks"].values()):
        issues.add("scenario_unverified")
    if (version(target["tag"]) <= version(source["tag"]) or current["requested_tag"] != target["tag"]
            or source["index_digest"] == target["index_digest"] or source["config_digest"] == target["config_digest"]):
        issues.add("identity_mismatch")
    if (data is None or any(item["synthetic_before"][name] != data["invariants"][name]["before"]
                           or item["synthetic_after"][name] != data["invariants"][name]["after"] for name in INVARIANTS)):
        issues.add("synthetic_invariants_unverified")
    if item["mutation_count"] != int(current["mutation_started"]) or item["conflicting_mutation_count"] != 0 or item["request_attempt_count"] < 1:
        issues.add("scenario_unverified")
    if ident in {"duplicate_requests", "conflicting_operations"} and item["request_attempt_count"] < 2:
        issues.add("scenario_unverified")
    a, b = version(source["tag"]), version(target["tag"])
    if ident == "stable_patch" and (a[:2] != b[:2] or b[2] <= a[2]):
        issues.add("identity_mismatch")
    if ident == "stable_minor" and (a[0] != b[0] or b[1] <= a[1]):
        issues.add("identity_mismatch")
    if ident == "major_actions" and b[0] <= a[0]:
        issues.add("identity_mismatch")
    skipped = item["skipped_artifact_ids"]
    if ident == "skipped_span":
        if not skipped or any(not a < version(artifacts[key]["tag"]) < b for key in skipped):
            issues.add("skipped_span_unverified")
    elif skipped:
        issues.add("skipped_span_unverified")
    action = item["action_evidence"]
    if ident == "major_actions":
        if (current["phase"] != "succeeded" or action is None or action["refusal_mutation_started"] or not action["fulfilled_verified"]
                or action["manifest_sha256"] != target["manifest_sha256"] or action["requirements_sha256"] != target["actions_sha256"]
                or action["refusal_target"] != {key: target[key] for key in ("tag", "commit", "index_digest")}
                or action["refusal_receipt_sha256"] == action["fulfillment_receipt_sha256"]):
            issues.add("major_actions_unverified")
    elif action is not None:
        issues.add("major_actions_unverified")
    if current["source"] is not None and current["source"] != {"version": source["tag"][1:], "commit": source["commit"]}:
        issues.add("identity_mismatch")
    expected = {"tag": target["tag"], "version": target["tag"][1:], "commit": target["commit"], "image_digest": target["index_digest"]}
    if current["target"] is not None and current["target"] != expected:
        issues.add("identity_mismatch")
    if ident in SUCCESS_CASES and current["phase"] != "succeeded":
        issues.add("scenario_unverified")
    if ident in REFUSAL_CASES and current["phase"] not in {"rejected", "blocked"}:
        issues.add("scenario_unverified")
    if ident in RECOVERY_CASES and current["phase"] != "recovery_required":
        issues.add("scenario_unverified")
    if ident in {"pull_failure", "disk_failure", "backup_failure"} and current["phase"] not in {"rejected", "blocked", "failed_safe"}:
        issues.add("scenario_unverified")
    if current["phase"] == "succeeded":
        apply, protected = current["apply"], current["protection"]
        if (current["operation_id"] is None or current["request_id"] is None or current["plan_id"] is None or current["revision"] < 1
                or current["source"] is None or current["target"] is None or current["error"] is not None
                or not current["mutation_started"] or current["checkpoint"] != "serving_verified" or apply is None or protected is None):
            issues.add("scenario_unverified")
        else:
            hashes = ("index_digest", "platform_manifest_digest", "config_digest", "manifest_sha256", "history_sha256")
            if any(apply[key] != target[key] for key in hashes):
                issues.add("identity_mismatch")
            if (apply["phase"] != "verified" or apply["error"] is not None or apply["hold_owned"]
                    or not all(apply[key] for key in ("migration_started", "migration_verified", "services_verified", "origin_verified", "configuration_committed"))
                    or apply["migration_receipt_sha256"] is None or apply["runtime_receipt_sha256"] is None
                    or protected["phase"] != "retained" or protected["hold_owned"] or not protected["custody_verified"]
                    or protected["archive_sha256"] is None or protected["manifest_sha256"] is None):
                issues.add("scenario_unverified")
        if (version(item["helper_after"]) < ((0, 5, 0) if schema == 2 else (0, 4, 0)) or item["app_protocol_before"] != 1
                or item["app_protocol_after"] != 1 or item["helper_protocol"] != 1):
            issues.add("compatibility_unverified")
        if posture["posture"] != "target":
            issues.add("serving_unverified")
    elif current["phase"] in {"failed_safe", "cancelled", "blocked", "rejected"}:
        if current["mutation_started"] or current["error"] is None or posture["posture"] != "previous":
            issues.add("scenario_unverified")
        if item["app_protocol_after"] != item["app_protocol_before"]:
            issues.add("compatibility_unverified")
        if current["phase"] in {"failed_safe", "cancelled"}:
            apply = current["apply"]
            if (current["checkpoint"] != "previous_serving_verified" or apply is None or apply["phase"] != "fallback"
                    or apply["migration_started"] or apply["migration_verified"] or not apply["services_verified"]
                    or not apply["origin_verified"] or apply["hold_owned"]):
                issues.add("scenario_unverified")
    else:
        if posture["posture"] != "maintenance" or current["error"] is None:
            issues.add("maintenance_unverified")
        if current["phase"] == "recovery_required":
            apply, protected = current["apply"], current["protection"]
            if (current["operation_id"] is None or current["request_id"] is None or current["plan_id"] is None
                    or current["source"] is None or current["target"] is None or apply is None
                    or apply["phase"] != "recovery_required" or apply["error"] != current["error"]
                    or apply["migration_started"] != current["mutation_started"]):
                issues.add("maintenance_unverified")
            if current["mutation_started"] and (apply is None or protected is None or not protected["custody_verified"]
                    or protected["archive_sha256"] is None or protected["manifest_sha256"] is None
                    or any(apply[key] != target[key] for key in ("index_digest", "platform_manifest_digest", "config_digest", "manifest_sha256", "history_sha256"))):
                issues.add("maintenance_unverified")
    if posture["posture"] == "maintenance":
        if not posture["maintenance_verified"] or not posture["writers_stopped_verified"] or posture["support_loop_verified"]:
            issues.add("maintenance_unverified")
    else:
        selected = target if posture["posture"] == "target" else source
        if (posture["artifact_id"] != selected["id"] or posture["maintenance_verified"] or not all(posture[key] for key in
                ("postgres_verified", "redis_verified", "configured_origin_verified", "support_loop_verified", "realtime_verified"))):
            issues.add("serving_unverified")
        observed_ids = []
        for service in posture["services"].values():
            if (not service["healthy"] or not service["process_verified"] or service["after_id"] is None or service["before_id"] is None
                    or service["image_digest"] != selected["local_image_id" if schema == 2 else "config_digest"]
                    or not service_platform_matches(service, selected, schema)
                    or (posture["posture"] == "target" and service["after_id"] == service["before_id"])):
                issues.add("serving_unverified")
            observed_ids.append(service["after_id"])
        if len(set(observed_ids)) != len(SERVICES):
            issues.add("serving_unverified")
    if ident == "vm_reboot":
        env = environments[item["environment_id"]]
        if (env["boot_id_before"] == env["boot_id_after"] or env["reboot_requested_at"] is None
                or not timestamp(item["started_at"]) <= timestamp(env["reboot_requested_at"]) <= timestamp(env["reboot_observed_at"]) <= timestamp(item["finished_at"])):
            issues.add("reboot_unverified")
    if ident == "new_app_old_helper" and version(item["helper_before"]) >= (0, 4, 0):
        issues.add("compatibility_unverified")
    if ident in {"helper_restart", "vm_reboot"} and item["executor_generation_before"] == item["executor_generation_after"]:
        issues.add("reboot_unverified")
    if ident in {"helper_restart", "vm_reboot"}:
        before = item["journey_before"]
        if (before is None or any(before[key] != current[key] for key in ("operation_id", "request_id", "plan_id"))
                or before["revision"] >= current["revision"]):
            issues.add("journey_unverified")
    if ident == "old_app_new_helper" and (item["app_protocol_before"] != 0 or version(item["helper_after"]) < (0, 4, 0)):
        issues.add("compatibility_unverified")


def validate_report(value):
    """Return only classified machine output; arbitrary source values never echo."""
    try:
        return _validate_report(value)
    except Invalid as refusal:
        return {"schema": 1, "status": "invalid", "qualified": False, "scenario_count": 0,
                "issues": [str(refusal)], "missing_scenarios": list(SCENARIOS)}
    except (KeyError, TypeError, ValueError, RecursionError):
        return {"schema": 1, "status": "invalid", "qualified": False, "scenario_count": 0,
                "issues": ["schema_invalid"], "missing_scenarios": list(SCENARIOS)}


def _validate_report(value):
    object_shape(value, TOP_KEYS)
    if type(value["schema"]) is not int or value["schema"] not in {1, 2}:
        raise Invalid("schema_invalid")
    schema = value["schema"]
    enum(value["claim"], CLAIMS)
    identifier(value["run_id"])
    start, end = timestamp(value["started_at"]), timestamp(value["finished_at"])
    if end < start or end - start > dt.timedelta(days=30):
        raise Invalid("time_invalid")
    arrays = (("artifacts", 64), ("environments", 64), ("scenarios", len(SCENARIOS) * (4 if schema == 2 else 1)), ("limitations", len(LIMITATIONS)))
    for name, maximum in arrays:
        array(value[name], maximum)
    for limitation in value["limitations"]:
        enum(limitation, LIMITATIONS)
    if len(set(value["limitations"])) != len(value["limitations"]):
        raise Invalid("schema_invalid")
    artifacts, environments, cases = {}, {}, {}
    for item in value["artifacts"]:
        artifact(item, start, end, schema)
        if item["id"] in artifacts:
            raise Invalid("schema_invalid")
        artifacts[item["id"]] = item
    identities = {(item["tag"], item["architecture"], artifact_store(item)) if schema == 2 else item["tag"] for item in artifacts.values()}
    if len(identities) != len(artifacts):
        raise Invalid("schema_invalid")
    if schema == 2:
        # The same public release may have observations on several platforms
        # and stores, but its published identity cannot change between them.
        releases, platforms = {}, {}
        for item in artifacts.values():
            public = {key: item[key] for key in ("commit", "index_digest", "manifest_sha256", "history_sha256", "installer_sha256", "compose_sha256", "actions_sha256")}
            selected = {key: item[key] for key in ("platform_manifest_digest", "config_digest")}
            if releases.setdefault(item["tag"], public) != public or platforms.setdefault((item["tag"], item["architecture"]), selected) != selected:
                raise Invalid("identity_mismatch")
    for item in value["environments"]:
        environment(item, start, end, schema)
        if item["id"] in environments:
            raise Invalid("schema_invalid")
        environments[item["id"]] = item
    for item in value["scenarios"]:
        scenario(item, environments, artifacts, start, end, schema)
        key = (item["id"], item["environment_id"]) if schema == 2 else item["id"]
        if key in cases:
            raise Invalid("schema_invalid")
        cases[key] = item
    if value["backup"] is not None:
        backup(value["backup"], environments, artifacts)
    if value["restore"] is not None:
        restore(value["restore"], environments, artifacts, schema)
    if value["synthetic"] is not None:
        synthetic(value["synthetic"])
    issues = set()
    if schema == 1:
        issues.add("legacy_image_binding")
    missing = [name for name in SCENARIOS if name not in {item["id"] for item in cases.values()}]
    if missing:
        issues.add("scenario_missing")
    if value["limitations"]:
        issues.add("qualification_limited")
    if len(artifacts) < 2 or any(item["publication"] != "published" for item in artifacts.values()):
        issues.add("publication_unverified")
    if not artifacts or any(not item["chain_verified"] or not item["fresh_pull"] for item in artifacts.values()):
        issues.add("artifact_chain_unverified")
    if len(environments) < 2 or any(item["kind"] != "actual_vm" or item["os"] != "ubuntu_24_04" or item["architecture"] == "unsupported"
                                   or not all(item[key] for key in ("fresh_os", "dedicated", "no_developer_mounts", "no_reused_data", "systemd", "docker_engine")) for item in environments.values()):
        issues.add("vm_isolation_unverified")
    saved, restored, data = value["backup"], value["restore"], value["synthetic"]
    if schema == 2 and saved is not None:
        selected, host = artifacts[saved["source_artifact_id"]], environments[saved["environment_id"]]
        if selected["architecture"] != host["architecture"] or artifact_store(selected) != host["image_store"]:
            issues.add("backup_unverified")
    if (saved is None or not all(saved[key] for key in ("created", "archive_validated", "custody_verified", "retained", "remote_dependency_verified"))
            or not saved["local_disks"] or not saved["remote_disks"]):
        issues.add("backup_unverified")
    if (restored is None or saved is None or not all(restored[key] for key in ("executed", "succeeded", "independent_vm", "origin_verified", "runtime_verified"))
            or restored["environment_id"] == saved["environment_id"] or restored["archive_sha256"] != saved["archive_sha256"]
            or restored["artifact_id"] != saved["source_artifact_id"]
            or restored["installation_identity_sha256"] != saved["installation_identity_sha256"]):
        issues.add("restore_unverified")
    if (data is None or not data["erased_content_absent"] or any(not item["verified"] or item["before"] != item["after"] or item["before"] != item["restored"] for item in data["invariants"].values())):
        issues.add("synthetic_invariants_unverified")
    for item in cases.values():
        qualified_scenario(item, artifacts, environments, issues, data, schema)
    if schema == 2:
        covered = {combination: set() for combination in STORE_PLATFORMS}
        for item in cases.values():
            local_issues = set()
            qualified_scenario(item, artifacts, environments, local_issues, data, schema)
            if not local_issues:
                host = environments[item["environment_id"]]
                covered.get((host["architecture"], host["image_store"]), set()).add(item["id"])
        if any(names != set(SCENARIOS) for names in covered.values()):
            issues.add("image_store_matrix_unverified")
    if restored is not None:
        posture = restored["serving"]
        selected = artifacts[restored["artifact_id"]]
        if (selected["architecture"] != environments[restored["environment_id"]]["architecture"]
                or posture["artifact_id"] != restored["artifact_id"] or posture["posture"] == "maintenance" or posture["maintenance_verified"]
                or not all(posture[key] for key in ("postgres_verified", "redis_verified", "configured_origin_verified", "support_loop_verified", "realtime_verified"))
                or (schema == 2 and artifact_store(selected) != environments[restored["environment_id"]]["image_store"])
                or any(not entry["healthy"] or not entry["process_verified"] or entry["after_id"] is None
                       or entry["image_digest"] != selected["local_image_id" if schema == 2 else "config_digest"]
                       or not service_platform_matches(entry, selected, schema) for entry in posture["services"].values())
                or len({entry["after_id"] for entry in posture["services"].values()}) != len(SERVICES)):
            issues.add("restore_unverified")
    if saved is not None and data is not None and saved["installation_identity_sha256"] != data["invariants"]["installation_identity"]["before"]:
        issues.add("identity_mismatch")
    if "vm_reboot" not in {item["id"] for item in cases.values()}:
        issues.add("reboot_unverified")
    # An explicitly limited claim never upgrades itself to release qualification.
    status = value["claim"] if value["claim"] != "qualification" else ("unqualified" if issues else "qualified")
    return {"schema": 1, "status": status, "qualified": status == "qualified", "scenario_count": len(cases),
            "issues": sorted(issues), "missing_scenarios": missing}


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("report", type=Path, help="Sanitized JSON evidence report (read-only)")
    args = parser.parse_args(argv)
    try:
        with args.report.open("rb") as source:
            result = validate_report(parse(source.read(MAX_BYTES + 1)))
    except (OSError, Invalid):
        result = {"schema": 1, "status": "invalid", "qualified": False, "scenario_count": 0,
                  "issues": ["input_invalid"], "missing_scenarios": list(SCENARIOS)}
    print(json.dumps(result, sort_keys=True, separators=(",", ":")))
    return 0 if result["qualified"] else 1 if result["status"] == "invalid" else 2


if __name__ == "__main__":
    sys.exit(main())
