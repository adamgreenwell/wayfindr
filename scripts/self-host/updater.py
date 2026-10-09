#!/usr/bin/env python3
"""Host-owned update preparation and journal. No application mutation engine yet."""

from __future__ import annotations

import argparse
import base64
import copy
import fcntl
import hashlib
import hmac
import json
import os
import platform
import re
import selectors
import socket
import stat
import struct
import subprocess
import sys
import threading
import time
import uuid
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

VERSION = "0.1.0"
PROTOCOL = 1
CONFIG = Path("/etc/wayfindr-updater/installation.json")
CREDENTIAL = Path("/etc/wayfindr-updater/credential.json")
STATE_DIR = Path("/var/lib/wayfindr-updater")
SOCKET = Path("/run/wayfindr-updater/updater.sock")
REQUEST_MAX = 16_384
RESPONSE_MAX = 1_048_576
JOURNAL_MAX = 8_388_608
OPERATIONS_MAX = 1024
TAG = re.compile(r"v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\Z")
HEX = re.compile(r"[0-9a-f]{64}\Z")
COMMIT = re.compile(r"(?:[0-9a-f]{40}|[0-9a-f]{64})\Z")
DIGEST = re.compile(r"sha256:[0-9a-f]{64}\Z")
IMAGE = re.compile(r"ghcr\.io/adamgreenwell/wayfindr:(v?(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*))(?:@sha256:[0-9a-f]{64})?\Z")
PHASES = {"accepted", "preparing", "reconciliation_required", "blocked"}
CHECKPOINTS = {"accepted", "prepare_started", "plan_reported"}
EVENTS = {"operation_accepted", "prepare_started", "plan_reported", "operation_blocked", "reconciliation_required", "interrupted_prepare"}
ERRORS = {
    "authentication_failed", "request_invalid", "protocol_unsupported", "installation_mismatch",
    "replay_detected", "request_expired", "operation_busy", "idempotency_conflict",
    "operation_missing", "journal_unavailable", "journal_corrupt", "journal_full",
    "helper_unavailable", "configuration_changed", "prepare_unavailable", "prepare_failed",
    "prepare_timeout", "prepare_output_invalid", "prerequisites_unmet", "identity_unverified",
    "no_update_required", "execution_not_available", "reconciliation_required", "interrupted_prepare",
}


class Refusal(Exception):
    def __init__(self, reason: str):
        self.reason = reason if reason in ERRORS else "helper_unavailable"
        super().__init__(self.reason)


def is_uuid(value: object) -> bool:
    if not isinstance(value, str) or re.fullmatch(r"[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}", value) is None:
        return False
    try:
        return str(uuid.UUID(value)) == value
    except ValueError:
        return False


def integer(value: object, minimum: int = 0) -> bool:
    return type(value) is int and value >= minimum


def strict_json(raw: bytes, reason: str = "request_invalid") -> dict:
    def pairs(items):
        result = {}
        for key, value in items:
            if key in result:
                raise Refusal(reason)
            result[key] = value
        return result

    try:
        value = json.loads(raw, object_pairs_hook=pairs, parse_constant=lambda _: (_ for _ in ()).throw(Refusal(reason)))
    except (ValueError, UnicodeError, RecursionError):
        raise Refusal(reason) from None
    if not isinstance(value, dict):
        raise Refusal(reason)
    return value


def encoded(value: dict) -> bytes:
    return json.dumps(value, separators=(",", ":"), sort_keys=True, allow_nan=False).encode("utf-8")


def trusted(path: Path, *, directory: bool = False, socket_node: bool = False) -> None:
    """Verify every ancestor; a trusted leaf inside an app-owned home is unsafe."""
    if not path.is_absolute() or ".." in path.parts:
        raise Refusal("configuration_changed")
    for candidate in reversed([path, *path.parents]):
        try:
            info = candidate.lstat()
        except OSError:
            raise Refusal("configuration_changed") from None
        leaf = candidate == path
        expected = stat.S_ISSOCK if leaf and socket_node else stat.S_ISDIR if not leaf or directory else stat.S_ISREG
        if info.st_uid != 0 or not expected(info.st_mode) or info.st_mode & (0o002 if leaf and socket_node else 0o022):
            raise Refusal("configuration_changed")


def read_object(path: Path, maximum: int, reason: str) -> dict:
    try:
        with path.open("rb") as source:
            raw = source.read(maximum + 1)
        if len(raw) > maximum:
            raise Refusal(reason)
        return strict_json(raw, reason)
    except OSError:
        raise Refusal(reason) from None


def atomic_write(path: Path, value: dict) -> None:
    raw = encoded(value)
    if len(raw) > JOURNAL_MAX:
        raise Refusal("journal_full")
    temporary = path.parent / (".journal-" + uuid.uuid4().hex)
    try:
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            view = memoryview(raw)
            while view:
                view = view[os.write(fd, view):]
            os.fsync(fd)
        finally:
            os.close(fd)
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    except OSError:
        raise Refusal("journal_unavailable") from None
    finally:
        try:
            temporary.unlink(missing_ok=True)
        except OSError:
            pass


def initial_journal(installation_id: str) -> dict:
    return {"schema": 1, "installation_id": installation_id, "revision": 0,
            "generation": None, "heartbeat_at": 0, "active_operation": None,
            "last_operation": None, "operations": {}}


def validate_journal(value: dict, installation_id: str) -> None:
    expected = set(initial_journal(installation_id))
    if set(value) != expected or type(value["schema"]) is not int or value["schema"] != 1 or value["installation_id"] != installation_id:
        raise Refusal("journal_corrupt")
    if not integer(value["revision"]) or not integer(value["heartbeat_at"]) or (value["generation"] is not None and not is_uuid(value["generation"])):
        raise Refusal("journal_corrupt")
    operations = value["operations"]
    if not isinstance(operations, dict) or len(operations) > OPERATIONS_MAX:
        raise Refusal("journal_corrupt")
    for pointer in ("active_operation", "last_operation"):
        if value[pointer] is not None and (not is_uuid(value[pointer]) or value[pointer] not in operations):
            raise Refusal("journal_corrupt")
    requests = set()
    active = []
    for operation_id, operation in operations.items():
        keys = {"operation_id", "request_id", "release_tag", "phase", "checkpoint", "executor_generation",
                "executor_version", "mutation_started", "created_at", "updated_at", "revision", "error",
                "source", "target", "plan_id", "events"}
        if not isinstance(operation, dict) or set(operation) != keys or not is_uuid(operation_id) or operation["operation_id"] != operation_id:
            raise Refusal("journal_corrupt")
        if not is_uuid(operation["request_id"]) or operation["request_id"] in requests or not is_uuid(operation["executor_generation"]):
            raise Refusal("journal_corrupt")
        requests.add(operation["request_id"])
        if not isinstance(operation["release_tag"], str) or TAG.fullmatch(operation["release_tag"]) is None or len(operation["release_tag"]) > 128:
            raise Refusal("journal_corrupt")
        if not isinstance(operation["phase"], str) or operation["phase"] not in PHASES or not isinstance(operation["checkpoint"], str) or operation["checkpoint"] not in CHECKPOINTS or operation["executor_version"] != VERSION or operation["mutation_started"] is not False:
            raise Refusal("journal_corrupt")
        if any(not integer(operation[key]) for key in ("created_at", "updated_at", "revision")) or operation["revision"] > value["revision"]:
            raise Refusal("journal_corrupt")
        if operation["error"] is not None and (not isinstance(operation["error"], str) or operation["error"] not in ERRORS):
            raise Refusal("journal_corrupt")
        if operation["phase"] != "blocked":
            active.append(operation_id)
        if operation["source"] is not None:
            source = operation["source"]
            if not isinstance(source, dict) or set(source) != {"version", "commit"} or not isinstance(source["version"], str) or not TAG.fullmatch("v" + source["version"].removeprefix("v")) or not isinstance(source["commit"], str) or not COMMIT.fullmatch(source["commit"]):
                raise Refusal("journal_corrupt")
        if operation["target"] is not None:
            target = operation["target"]
            if not isinstance(target, dict) or set(target) != {"tag", "version", "commit", "image_digest"} or target["tag"] != operation["release_tag"] or target["version"] != operation["release_tag"][1:] or not isinstance(target["commit"], str) or not COMMIT.fullmatch(target["commit"]) or not isinstance(target["image_digest"], str) or not DIGEST.fullmatch(target["image_digest"]):
                raise Refusal("journal_corrupt")
        if operation["plan_id"] is not None and (not isinstance(operation["plan_id"], str) or not HEX.fullmatch(operation["plan_id"])):
            raise Refusal("journal_corrupt")
        events = operation["events"]
        if not isinstance(events, list) or not 1 <= len(events) <= 32:
            raise Refusal("journal_corrupt")
        previous = 0
        for event in events:
            if not isinstance(event, dict) or set(event) != {"revision", "at", "code", "phase"} or not integer(event["revision"], 1) or event["revision"] <= previous or event["revision"] > operation["revision"] or not integer(event["at"]) or not isinstance(event["code"], str) or event["code"] not in EVENTS or not isinstance(event["phase"], str) or event["phase"] not in PHASES:
                raise Refusal("journal_corrupt")
            previous = event["revision"]
        if events[-1]["revision"] != operation["revision"] or events[-1]["phase"] != operation["phase"]:
            raise Refusal("journal_corrupt")
    if active != ([] if value["active_operation"] is None else [value["active_operation"]]):
        raise Refusal("journal_corrupt")


class Configuration:
    def __init__(self, value: dict, token: str):
        keys = {"schema", "installation_id", "install_dir", "compose_project", "client_uid", "client_gid", "image_reference", "compose_sha256", "env_sha256", "installer_sha256"}
        if set(value) != keys or type(value["schema"]) is not int or value["schema"] != 1 or not is_uuid(value["installation_id"]):
            raise Refusal("configuration_changed")
        if value["compose_project"] != "wayfindr-self-hosting" or type(value["client_uid"]) is not int or value["client_uid"] != 1000 or type(value["client_gid"]) is not int or value["client_gid"] != 1000:
            raise Refusal("configuration_changed")
        if not isinstance(value["install_dir"], str) or not Path(value["install_dir"]).is_absolute() or ".." in Path(value["install_dir"]).parts:
            raise Refusal("configuration_changed")
        if not isinstance(value["image_reference"], str) or not IMAGE.fullmatch(value["image_reference"]) or any(not isinstance(value[key], str) or not HEX.fullmatch(value[key]) for key in ("compose_sha256", "env_sha256", "installer_sha256")) or not isinstance(token, str) or not HEX.fullmatch(token):
            raise Refusal("configuration_changed")
        self.value = value
        self.token = token
        self.installation_id = value["installation_id"]

    @classmethod
    def load(cls, config: Path = CONFIG, credential: Path = CREDENTIAL, *, verify_install: bool = True):
        trusted(config)
        trusted(credential)
        if credential.stat().st_mode & 0o007:
            raise Refusal("configuration_changed")
        value = read_object(config, REQUEST_MAX, "configuration_changed")
        auth = read_object(credential, REQUEST_MAX, "configuration_changed")
        if set(auth) != {"schema", "installation_id", "token"} or type(auth["schema"]) is not int or auth["schema"] != 1 or auth["installation_id"] != value.get("installation_id"):
            raise Refusal("configuration_changed")
        result = cls(value, auth["token"])
        if verify_install:
            result.verify_files()
        return result

    def verify_files(self):
        directory = Path(self.value["install_dir"])
        trusted(directory, directory=True)
        for name, key in (("compose.yml", "compose_sha256"), (".env", "env_sha256"), ("install.sh", "installer_sha256")):
            path = directory / name
            trusted(path)
            try:
                with path.open("rb") as source:
                    digest = hashlib.file_digest(source, "sha256").hexdigest()
            except OSError:
                raise Refusal("configuration_changed") from None
            if digest != self.value[key]:
                raise Refusal("configuration_changed")
        trusted(directory / ".updater-enrolled")
        try:
            identity = (directory / ".updater-enrolled").read_text().strip()
        except (OSError, UnicodeError):
            raise Refusal("configuration_changed") from None
        if identity != self.installation_id:
            raise Refusal("configuration_changed")

    def capabilities(self):
        architecture = {"x86_64": "amd64", "aarch64": "arm64", "arm64": "arm64"}.get(platform.machine().lower(), "unknown")
        return {"ownership": "installer-managed", "installation_id": self.installation_id, "enrolled": True,
                "platform": "linux", "architecture": architecture, "image_reference": self.value["image_reference"],
                "helper": {"protocol": PROTOCOL, "version": VERSION, "capabilities": ["plan", "status"]},
                "managed_policy": {}}


class HostLock:
    def __init__(self, path: Path, secure: bool = True):
        if secure:
            trusted(path.parent, directory=True)
            if path.exists() or path.is_symlink():
                trusted(path)
        try:
            self.fd = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
            fcntl.flock(self.fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            os.close(self.fd)
            raise Refusal("operation_busy") from None
        except OSError:
            raise Refusal("journal_unavailable") from None

    def close(self):
        if self.fd is not None:
            os.close(self.fd)
            self.fd = None

    def __enter__(self):
        return self

    def __exit__(self, *_):
        self.close()


class Journal:
    def __init__(self, path: Path, installation_id: str, *, secure: bool = True):
        if secure:
            trusted(path)
            if path.stat().st_mode & 0o077:
                raise Refusal("journal_corrupt")
        self.path = path
        self.installation_id = installation_id
        self.value = read_object(path, JOURNAL_MAX, "journal_corrupt")
        validate_journal(self.value, installation_id)
        self.mutex = threading.RLock()
        self.failed = False

    def commit(self, value):
        if self.failed:
            raise Refusal("journal_unavailable")
        validate_journal(value, self.installation_id)
        try:
            atomic_write(self.path, value)
        except Refusal:
            # Even a rename with uncertain directory fsync holds new requests.
            self.failed = True
            raise
        self.value = value

    @staticmethod
    def event(value, operation, code):
        value["revision"] += 1
        operation["revision"] = value["revision"]
        operation["updated_at"] = int(time.time())
        operation["events"].append({"revision": operation["revision"], "at": operation["updated_at"], "code": code, "phase": operation["phase"]})

    def begin_generation(self, generation):
        with self.mutex:
            value = copy.deepcopy(self.value)
            value["generation"] = generation
            value["heartbeat_at"] = int(time.time())
            if value["active_operation"] is not None:
                operation = value["operations"][value["active_operation"]]
                if operation["phase"] != "reconciliation_required":
                    operation["phase"] = "reconciliation_required"
                    operation["error"] = "reconciliation_required"
                    self.event(value, operation, "reconciliation_required")
            self.commit(value)

    def heartbeat(self):
        with self.mutex:
            value = copy.deepcopy(self.value)
            value["heartbeat_at"] = int(time.time())
            self.commit(value)

    def accept(self, request_id, tag, generation):
        with self.mutex:
            if self.failed:
                raise Refusal("journal_unavailable")
            for operation in self.value["operations"].values():
                if operation["request_id"] == request_id:
                    if operation["release_tag"] != tag:
                        raise Refusal("idempotency_conflict")
                    return operation["operation_id"], False
            if self.value["active_operation"] is not None:
                raise Refusal("operation_busy")
            if len(self.value["operations"]) >= OPERATIONS_MAX:
                raise Refusal("journal_full")
            value = copy.deepcopy(self.value)
            operation_id = str(uuid.uuid4())
            now = int(time.time())
            operation = {"operation_id": operation_id, "request_id": request_id, "release_tag": tag,
                         "phase": "accepted", "checkpoint": "accepted", "executor_generation": generation,
                         "executor_version": VERSION, "mutation_started": False, "created_at": now,
                         "updated_at": now, "revision": 0, "error": None, "source": None, "target": None,
                         "plan_id": None, "events": []}
            value["active_operation"] = value["last_operation"] = operation_id
            value["operations"][operation_id] = operation
            self.event(value, operation, "operation_accepted")
            self.commit(value)
            return operation_id, True

    def preparing(self, operation_id):
        with self.mutex:
            value = copy.deepcopy(self.value)
            operation = value["operations"][operation_id]
            if value["active_operation"] != operation_id or operation["phase"] != "accepted":
                raise Refusal("reconciliation_required")
            operation["phase"] = "preparing"
            operation["checkpoint"] = "prepare_started"
            self.event(value, operation, "prepare_started")
            self.commit(value)

    def finish(self, operation_id, reason, facts=None):
        with self.mutex:
            value = copy.deepcopy(self.value)
            operation = value["operations"][operation_id]
            if value["active_operation"] != operation_id or operation["phase"] != "preparing":
                raise Refusal("reconciliation_required")
            if facts is not None:
                operation.update(facts)
                operation["checkpoint"] = "plan_reported"
                self.event(value, operation, "plan_reported")
            operation["phase"] = "blocked"
            operation["error"] = reason
            self.event(value, operation, "operation_blocked")
            value["active_operation"] = None
            self.commit(value)

    def reconcile(self, operation_id):
        with self.mutex:
            if self.value["active_operation"] != operation_id:
                raise Refusal("operation_missing")
            value = copy.deepcopy(self.value)
            operation = value["operations"][operation_id]
            # U3 performed read-only preparation only. Future unknown checkpoints
            # and mutation evidence are rejected by the journal validator.
            if operation["phase"] not in {"accepted", "preparing", "reconciliation_required"} or operation["checkpoint"] not in {"accepted", "prepare_started"} or operation["mutation_started"] is not False:
                raise Refusal("reconciliation_required")
            operation["phase"] = "blocked"
            operation["error"] = "interrupted_prepare"
            self.event(value, operation, "interrupted_prepare")
            value["active_operation"] = None
            self.commit(value)

    def status(self, operation_id=None):
        with self.mutex:
            if self.failed:
                raise Refusal("journal_unavailable")
            operation_id = operation_id or self.value["active_operation"] or self.value["last_operation"]
            if operation_id is not None and operation_id not in self.value["operations"]:
                raise Refusal("operation_missing")
            return {"schema": 1, "installation_id": self.installation_id, "revision": self.value["revision"],
                    "helper_version": VERSION, "generation": self.value["generation"],
                    "heartbeat_at": self.value["heartbeat_at"], "active_operation": self.value["active_operation"],
                    "operation": copy.deepcopy(self.value["operations"].get(operation_id))}

    def logs(self, operation_id, cursor, limit):
        with self.mutex:
            if self.failed:
                raise Refusal("journal_unavailable")
            if operation_id not in self.value["operations"]:
                raise Refusal("operation_missing")
            events = self.value["operations"][operation_id]["events"]
            remaining = [event for event in events if event["revision"] > cursor]
            selected = copy.deepcopy(remaining[:limit])
            return {"operation_id": operation_id, "events": selected, "next_cursor": selected[-1]["revision"] if selected else cursor, "has_more": len(remaining) > len(selected)}


def plan_facts(plan: dict, tag: str, config: Configuration):
    # This receipt is application-reported. Shape and identity binding are not
    # independent host provenance. A future apply engine must attest metadata
    # and the running image itself; this journal never authorizes execution.
    target = plan.get("target")
    source = plan.get("source")
    if type(plan.get("schema")) is not int or plan["schema"] != 1 or not isinstance(plan.get("plan_id"), str) or not HEX.fullmatch(plan["plan_id"]) or not isinstance(target, dict) or not isinstance(source, dict):
        raise Refusal("prepare_output_invalid")
    if target.get("tag") != tag or target.get("version") != tag[1:] or not isinstance(target.get("commit"), str) or not COMMIT.fullmatch(target["commit"]) or not isinstance(target.get("image_digest"), str) or not DIGEST.fullmatch(target["image_digest"]):
        raise Refusal("prepare_output_invalid")
    if target.get("image_reference") != "ghcr.io/adamgreenwell/wayfindr:" + tag[1:] + "@" + target["image_digest"]:
        raise Refusal("prepare_output_invalid")
    provenance = plan.get("provenance")
    if not isinstance(provenance, dict) or provenance.get("repository") != "adamgreenwell/wayfindr" or provenance.get("commit") != target["commit"] or provenance.get("tag") != tag or provenance.get("history_complete") is not True or any(not isinstance(provenance.get(key), str) or not HEX.fullmatch(provenance[key]) for key in ("manifest_sha256", "history_sha256", "digest_asset_sha256")):
        raise Refusal("prepare_output_invalid")
    source_facts = None
    source_version = source.get("runtime_version")
    source_commit = source.get("runtime_commit")
    if isinstance(source_version, str) and isinstance(source_commit, str) and COMMIT.fullmatch(source_commit) and TAG.fullmatch("v" + source_version.removeprefix("v")):
        source_facts = {"version": source_version.removeprefix("v"), "commit": source_commit}
    status = plan.get("status")
    if not isinstance(status, str) or status not in {"update_available", "up_to_date", "blocked", "identity_unknown", "identity_conflict", "downgrade_refused", "requirements_outstanding"}:
        raise Refusal("prepare_output_invalid")
    requirements = plan.get("release_requirements")
    if not isinstance(requirements, dict) or type(requirements.get("migration_blocked")) is not bool:
        raise Refusal("prepare_output_invalid")
    expected_version = IMAGE.fullmatch(config.value["image_reference"])[1].removeprefix("v")
    if source_facts is not None and source_facts["version"] != expected_version:
        raise Refusal("configuration_changed")
    reason = "execution_not_available"
    if requirements["migration_blocked"] or status in {"blocked", "requirements_outstanding", "downgrade_refused"}:
        reason = "prerequisites_unmet"
    elif source_facts is None or status in {"identity_unknown", "identity_conflict"}:
        reason = "identity_unverified"
    elif status == "up_to_date":
        reason = "no_update_required"
    return reason, {"plan_id": plan["plan_id"], "source": source_facts,
                    "target": {key: target[key] for key in ("tag", "version", "commit", "image_digest")}}


def capture(command: list[str], *, timeout: int = 90) -> tuple[int, bytes]:
    """Bound child output without retaining stderr or passing ambient Docker env."""
    environment = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8", "HOME": "/nonexistent", "DOCKER_CONFIG": "/etc/wayfindr-updater/docker"}
    try:
        process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, env=environment, stdin=subprocess.DEVNULL, start_new_session=True)
    except OSError:
        raise Refusal("prepare_unavailable") from None
    output = bytearray()
    deadline = time.monotonic() + timeout
    selector = selectors.DefaultSelector()
    try:
        selector.register(process.stdout, selectors.EVENT_READ)
        while selector.get_map():
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise Refusal("prepare_timeout")
            for key, _ in selector.select(min(remaining, 1)):
                chunk = os.read(key.fileobj.fileno(), 65_536)
                if not chunk:
                    selector.unregister(key.fileobj)
                output.extend(chunk)
                if len(output) > RESPONSE_MAX:
                    raise Refusal("prepare_output_invalid")
        try:
            code = process.wait(timeout=max(0.01, deadline - time.monotonic()))
        except subprocess.TimeoutExpired:
            raise Refusal("prepare_timeout") from None
        return code, bytes(output)
    finally:
        selector.close()
        if process.poll() is None:
            import signal
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            process.wait()
        process.stdout.close()


class Prepare:
    def __init__(self, config: Configuration):
        self.config = config

    def __call__(self, tag):
        self.config.verify_files()
        directory = Path(self.config.value["install_dir"])
        if (directory / ".upgrade.lock").exists() or (directory / ".upgrade.lock").is_symlink():
            raise Refusal("operation_busy")
        trusted(Path("/usr/bin/docker"))
        trusted(Path("/etc/wayfindr-updater/docker"), directory=True)
        command = ["/usr/bin/docker", "--host", "unix:///var/run/docker.sock", "--config", "/etc/wayfindr-updater/docker",
                   "compose", "--project-name", self.config.value["compose_project"], "--project-directory", str(directory),
                   "--env-file", str(directory / ".env"), "-f", str(directory / "compose.yml"), "exec", "-T", "web",
                   "php", "artisan", "wayfindr:update-plan", "--ref=" + tag, "--json"]
        code, output = capture(command)
        if code not in {0, 78}:
            raise Refusal("prepare_failed")
        plan = strict_json(output, "prepare_output_invalid")
        self.config.verify_files()
        return plan_facts(plan, tag, self.config)


class Controller:
    def __init__(self, config: Configuration, journal: Journal, preparer=None):
        self.config = config
        self.journal = journal
        self.generation = str(uuid.uuid4())
        self.preparer = preparer if preparer is not None else Prepare(config)
        self.nonces = {}
        self.nonce_mutex = threading.Lock()
        self.workers = ThreadPoolExecutor(max_workers=1, thread_name_prefix="wayfindr-prepare")
        journal.begin_generation(self.generation)

    def validate(self, payload, uid):
        if uid not in {0, self.config.value["client_uid"]}:
            raise Refusal("authentication_failed")
        common = {"protocol", "installation_id", "nonce", "issued_at", "action"}
        if type(payload.get("protocol")) is not int or payload["protocol"] != PROTOCOL:
            raise Refusal("protocol_unsupported")
        if payload.get("installation_id") != self.config.installation_id:
            raise Refusal("installation_mismatch")
        if not isinstance(payload.get("nonce"), str) or re.fullmatch(r"[0-9a-f]{32}", payload["nonce"]) is None or not integer(payload.get("issued_at")):
            raise Refusal("request_invalid")
        if abs(int(time.time()) - payload["issued_at"]) > 30:
            raise Refusal("request_expired")
        action = payload.get("action")
        if action == "capabilities":
            expected = common
        elif action == "prepare":
            expected = common | {"request_id", "release_tag"}
            if not is_uuid(payload.get("request_id")) or not isinstance(payload.get("release_tag"), str) or len(payload["release_tag"]) > 128 or TAG.fullmatch(payload["release_tag"]) is None:
                raise Refusal("request_invalid")
        elif action == "status":
            expected = common | ({"operation_id"} if "operation_id" in payload else set())
            if "operation_id" in payload and not is_uuid(payload["operation_id"]):
                raise Refusal("request_invalid")
        elif action == "logs":
            expected = common | {"operation_id", "cursor", "limit"}
            if not is_uuid(payload.get("operation_id")) or not integer(payload.get("cursor")) or not integer(payload.get("limit"), 1) or payload["limit"] > 100:
                raise Refusal("request_invalid")
        else:
            raise Refusal("request_invalid")
        if set(payload) != expected:
            raise Refusal("request_invalid")
        with self.nonce_mutex:
            now = int(time.time())
            self.nonces = {key: at for key, at in self.nonces.items() if now - at <= 60}
            if payload["nonce"] in self.nonces:
                raise Refusal("replay_detected")
            if len(self.nonces) >= 4096:
                raise Refusal("helper_unavailable")
            self.nonces[payload["nonce"]] = now

    def dispatch(self, payload, uid):
        self.validate(payload, uid)
        action = payload["action"]
        if action == "capabilities":
            self.config.verify_files()
            return self.config.capabilities()
        if action == "status":
            return self.journal.status(payload.get("operation_id"))
        if action == "logs":
            return self.journal.logs(payload["operation_id"], payload["cursor"], payload["limit"])
        operation_id, created = self.journal.accept(payload["request_id"], payload["release_tag"], self.generation)
        if created:
            self.workers.submit(self.work, operation_id, payload["release_tag"])
        return self.journal.status(operation_id)

    def work(self, operation_id, tag):
        try:
            self.journal.preparing(operation_id)
            try:
                reason, facts = self.preparer(tag)
            except Refusal as failure:
                reason, facts = failure.reason, None
            except Exception:
                reason, facts = "prepare_failed", None
            self.journal.finish(operation_id, reason, facts)
        except Exception:
            # A journal failure leaves durable ownership held; never clear it
            # in exception cleanup or publish a success inferred from HTTP.
            return


def envelope(payload, token, direction):
    body = base64.b64encode(encoded(payload)).decode("ascii")
    mac = hmac.new(token.encode("ascii"), ("wayfindr-updater-v1:" + direction + "\n" + body).encode("ascii"), hashlib.sha256).hexdigest()
    return encoded({"payload": body, "mac": mac}) + b"\n"


def unpack_envelope(raw, token, direction):
    value = strict_json(raw)
    if set(value) != {"payload", "mac"} or not isinstance(value["payload"], str) or not isinstance(value["mac"], str) or HEX.fullmatch(value["mac"]) is None:
        raise Refusal("authentication_failed")
    try:
        expected = hmac.new(token.encode("ascii"), ("wayfindr-updater-v1:" + direction + "\n" + value["payload"]).encode("ascii"), hashlib.sha256).hexdigest()
        if not hmac.compare_digest(expected, value["mac"]):
            raise Refusal("authentication_failed")
        decoded = base64.b64decode(value["payload"], validate=True)
    except (ValueError, UnicodeError):
        raise Refusal("authentication_failed") from None
    return strict_json(decoded)


class Server:
    def __init__(self, controller: Controller, address=SOCKET):
        self.controller = controller
        self.address = address
        self.pool = ThreadPoolExecutor(max_workers=8, thread_name_prefix="wayfindr-socket")
        self.slots = threading.BoundedSemaphore(8)
        self.stop = threading.Event()

    def handle(self, connection):
        payload = None
        try:
            connection.settimeout(5)
            _, uid, _ = struct.unpack("3i", connection.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, struct.calcsize("3i")))
            if uid not in {0, self.controller.config.value["client_uid"]}:
                raise Refusal("authentication_failed")
            body = bytearray()
            deadline = time.monotonic() + 5
            while not body.endswith(b"\n"):
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise Refusal("request_expired")
                connection.settimeout(remaining)
                chunk = connection.recv(min(4096, REQUEST_MAX + 1 - len(body)))
                if not chunk or len(body) + len(chunk) > REQUEST_MAX or b"\n" in chunk[:-1]:
                    raise Refusal("request_invalid")
                body.extend(chunk)
            payload = unpack_envelope(bytes(body), self.controller.config.token, "request")
            try:
                result = self.controller.dispatch(payload, uid)
                response = {"protocol": PROTOCOL, "installation_id": self.controller.config.installation_id,
                            "nonce": payload["nonce"], "ok": True, "result": result}
            except Refusal as refusal:
                response = {"protocol": PROTOCOL, "installation_id": self.controller.config.installation_id,
                            "nonce": payload.get("nonce"), "ok": False, "error": refusal.reason}
            reply = envelope(response, self.controller.config.token, "response")
            if len(reply) > RESPONSE_MAX:
                raise Refusal("helper_unavailable")
            connection.sendall(reply)
        except Exception:
            try:
                connection.sendall(b'{"error":"authentication_failed"}\n')
            except OSError:
                pass
        finally:
            connection.close()
            self.slots.release()

    def serve(self):
        trusted(self.address.parent, directory=True)
        if self.address.exists() or self.address.is_symlink():
            trusted(self.address, socket_node=True)
            self.address.unlink()
        listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        try:
            listener.bind(str(self.address))
            os.chmod(self.address, 0o660)
            listener.listen(16)
            listener.settimeout(1)
            heartbeat = time.monotonic()
            while not self.stop.is_set():
                try:
                    connection, _ = listener.accept()
                except socket.timeout:
                    connection = None
                if connection is not None:
                    if self.slots.acquire(blocking=False):
                        self.pool.submit(self.handle, connection)
                    else:
                        connection.close()
                if time.monotonic() - heartbeat >= 10:
                    self.controller.journal.heartbeat()
                    heartbeat = time.monotonic()
        finally:
            listener.close()
            self.pool.shutdown(wait=True)
            self.controller.workers.shutdown(wait=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="action", required=True)
    subparsers.add_parser("serve")
    status = subparsers.add_parser("status")
    status.add_argument("--operation")
    status.add_argument("--json", action="store_true")
    logs = subparsers.add_parser("logs")
    logs.add_argument("--operation", required=True)
    logs.add_argument("--cursor", type=int, default=0)
    logs.add_argument("--limit", type=int, default=50)
    logs.add_argument("--json", action="store_true")
    reconcile = subparsers.add_parser("reconcile")
    reconcile.add_argument("--operation", required=True)
    args = parser.parse_args()
    try:
        if sys.version_info < (3, 11) or sys.platform != "linux" or os.geteuid() != 0:
            raise Refusal("helper_unavailable")
        operation_id = getattr(args, "operation", None)
        if operation_id is not None and not is_uuid(operation_id):
            raise Refusal("request_invalid")
        # Recovery reads depend on trusted enrollment identity, not a healthy
        # application or unchanged Compose/.env files. Execution still verifies.
        config = Configuration.load(verify_install=args.action == "serve")
        if args.action in {"serve", "reconcile"}:
            with HostLock(STATE_DIR / "helper.lock"):
                journal = Journal(STATE_DIR / "journal.json", config.installation_id)
                if args.action == "serve":
                    Server(Controller(config, journal)).serve()
                else:
                    journal.reconcile(operation_id)
                    print(encoded(journal.status(operation_id)).decode())
        else:
            journal = Journal(STATE_DIR / "journal.json", config.installation_id)
            if args.action == "logs":
                if args.cursor < 0 or not 1 <= args.limit <= 100:
                    raise Refusal("request_invalid")
                result = journal.logs(operation_id, args.cursor, args.limit)
            else:
                result = journal.status(operation_id)
            print(json.dumps(result, indent=None if args.json else 2, sort_keys=True))
        return 0
    except Exception as failure:
        reason = failure.reason if isinstance(failure, Refusal) else "helper_unavailable"
        print(encoded({"schema": 1, "status": "failed", "reason": reason}).decode(), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
