#!/usr/bin/env python3
"""Explicit helper replacement for one proven pre-mutation 0.5 apply failure.

This action preserves the application's recovery ownership. It never performs
application recovery, clears its journal, or relaxes ordinary idle upgrades.
"""

from __future__ import annotations

import argparse
import copy
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import socket
import stat
import struct
import sys
import time
import types
import uuid

sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location("helper_lifecycle", Path(__file__).with_name("upgrade-updater.py"))
U = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(U)
LIFE = U
refuse = U.refuse
KIND = "active_apply_helper_bridge"
STAGES = {"prepared", "frozen", "staging", "switched", "starting", "verified"}
SOURCE = {"version": "1.2.0", "commit": "56374e9574ed84616aae430d06589cfe2f0b33a0"}
TARGET = {"tag": "v1.3.0", "version": "1.3.0", "commit": "1bae329c4e349017716827dd0ce26b9ba7f9f2ac",
          "image_digest": "sha256:f7b5361f32c4b81010d74da37d310aec048f7101d8ea3ec4a99cea9f90df8dd0"}
EVENTS = [("operation_accepted", "accepted"), ("prepare_started", "preparing"),
          ("plan_reported", "preparing"), ("operation_blocked", "blocked"),
          ("operator_started", "downloading"), ("apply_started", "downloading"),
          ("recovery_required", "recovery_required")]


def journal_state(value, operation_id=None):
    """Only the daemon's two volatile top-level fields may change on boot."""
    return {key: copy.deepcopy(item) for key, item in value.items() if key not in {"generation", "heartbeat_at"}}


def state_digest(value):
    return U.digest(U.encoded(journal_state(value)))


def require_state(runtime, value, installation_id, operation_id, expected_sha):
    try:
        runtime.validate_journal(value, installation_id)
    except runtime.Refusal:
        refuse("bridge_state_ineligible")
    if (not runtime.is_uuid(operation_id) or not isinstance(expected_sha, str) or not U.HEX.fullmatch(expected_sha)
            or value["active_operation"] != operation_id or value["last_operation"] != operation_id
            or set(value["operations"]) != {operation_id} or value["revision"] != 7
            or state_digest(value) != expected_sha):
        refuse("bridge_state_changed")
    operation = value["operations"][operation_id]
    if (operation["phase"] != "recovery_required" or operation["checkpoint"] != "apply_started"
            or operation["executor_version"] != "0.5.0" or operation["mutation_started"] is not False
            or operation["error"] != "recovery_required" or operation["revision"] != 7
            or operation["source"] != SOURCE or operation["target"] != TARGET
            or operation["release_tag"] != TARGET["tag"]
            or [(event["code"], event["phase"]) for event in operation["events"]] != EVENTS
            or [event["revision"] for event in operation["events"]] != list(range(1, 8))
            or [event["at"] for event in operation["events"]] != sorted(event["at"] for event in operation["events"])):
        refuse("bridge_state_ineligible")
    apply = operation.get("apply", {})
    protection = operation.get("protection", {})
    if (apply != {**runtime.apply_state(), "phase": "recovery_required", "error": "recovery_required"}
            or protection != runtime.protection_state()
            or operation.get("operator", {}).get("prepare") is None
            or operation.get("operator", {}).get("start") is None
            or operation.get("operator", {}).get("cancel") is not None):
        refuse("bridge_effects_unverified")
    return journal_state(value)


def private_tree(path, *, excluding=None):
    """Retain every owned recovery/history entry, never infer effects from absence."""
    if not U.exists(path):
        return None
    result = {}
    total = 0
    entries = [path, *sorted(path.rglob("*"))]
    if excluding is not None:
        entries = [entry for entry in entries if entry != excluding and excluding not in entry.parents]
    if len(entries) > 4096:
        refuse("bridge_state_unavailable")
    for entry in entries:
        if entry.is_dir() and not entry.is_symlink():
            facts = {"identity": U.identity(entry, directory=True)}
        else:
            U.trusted(entry)
            info = entry.stat()
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
                refuse("bridge_state_unavailable")
            total += info.st_size
            if total > 10_000_000:
                refuse("bridge_state_unavailable")
            facts = {"identity": U.identity(entry), "sha256": U.digest(entry.read_bytes())}
        result[str(entry.relative_to(path))] = facts
    return result


def observe_state(runtime, config, operation_id, expected_sha, *, record=None, distribution=None, recovering=False):
    U.private(U.STATE / "journal.json", 0o600, 1000)
    raw = (U.STATE / "journal.json").read_bytes()
    value = runtime.strict_json(raw, "journal_corrupt")
    state = require_state(runtime, value, config.installation_id, operation_id, expected_sha)
    allowed = {"journal.json", "helper.lock", U.LOCK.name, U.TRANSACTION.name, U.STOP.name,
               U.RECEIPTS.name, "apply", "protection"}
    if recovering:
        allowed |= {".helper-upgrade.next", ".helper-upgrade-stop.next"}
    if {path.name for path in U.STATE.iterdir()} - allowed:
        refuse("partial_state")
    retained = {}
    for kind in ("apply", "protection"):
        path = U.STATE / kind
        if U.exists(path):
            U.trusted(path, directory=True)
            if list(path.iterdir()):
                refuse("bridge_effects_unverified")
        retained[kind] = private_tree(path)
    own = bridge_directory(record["transaction_id"]) if record is not None else None
    if record is not None:
        validate_custody(record, distribution)
    history = private_tree(U.RECEIPTS, excluding=own)
    if record is not None and record["retained"]["helper-upgrades"] is None and history is not None:
        # This action may create a receipt parent only when it did not exist
        # before. Every unrelated entry still refuses; the owned subtree is
        # validated independently and is already admitted by ordinary idle
        # lifecycle inspection after application recovery.
        if history != {".": {"identity": U.identity(U.RECEIPTS, directory=True)}} or U.identity(U.RECEIPTS, directory=True)[2:] != [0, 0, 0o700]:
            refuse("bridge_unowned_history")
        history = None
    retained["helper-upgrades"] = history
    return raw, value, state, retained


def readonly_baseline(runtime, config, distribution, operation_id):
    try:
        return _readonly_baseline(runtime, config, distribution, operation_id)
    except runtime.Refusal:
        refuse("bridge_baseline_unverified")


def _readonly_baseline(runtime, config, distribution, operation_id):
    """Invoke only the corrected baseline; never its initial/recovery executor."""
    protection = U.runtime_from(distribution / "scripts/self-host/update_protection.py")
    docker = ["/usr/bin/docker", "--host", "unix:///var/run/docker.sock", "--config", "/etc/wayfindr-updater/docker"]
    compose = docker + ["compose", "--project-name", config.value["compose_project"],
                       "--project-directory", config.value["install_dir"], "--env-file", config.value["install_dir"] + "/.env",
                       "-f", config.value["install_dir"] + "/compose.yml", "-f", config.value["install_dir"] + "/compose.updater.yml"]
    # Derive the two exact fixed PHP programs from reviewed target methods;
    # recording these arguments performs no process or application operation.
    scripts = set()
    class Programs:
        def call(self, args, *unused, **options):
            scripts.add(args[-1])
            return "200"
    for name in ("effective_keys", "web_status"):
        getattr(protection.DockerEngine, name)(Programs(), "0" * 64)

    def capture(command, *, timeout=90):
        allowed = command == compose + ["config", "--format", "json"]
        if command[:len(docker)] == docker:
            args = command[len(docker):]
            allowed = allowed or (args == ["stop", "--help"] or args[:1] in (["ps"], ["inspect"])
                       or args[:2] == ["image", "inspect"]
                       or (len(args) == 4 and args[0] == "top" and protection.CONTAINER.fullmatch(args[1]) and args[2:] == ["-eo", "pid,args"])
                       or (len(args) == 5 and args[0] == "exec" and protection.CONTAINER.fullmatch(args[1])
                           and args[2:4] == ["php", "-r"] and args[4] in scripts)
                       or (args[:1] == ["exec"] and len(args) == 8 and protection.CONTAINER.fullmatch(args[1])
                           and args[2:] == ["php", "artisan", "wayfindr:upgrade-window", operation_id, "--action=status", "--json"]))
        if not allowed:
            refuse("bridge_command_refused")
        return runtime.capture(command, timeout=timeout)

    api = types.SimpleNamespace(**{name: getattr(runtime, name) for name in
                                ("Refusal", "trusted", "strict_json", "encoded")}, capture=capture)
    journal = runtime.Journal(U.STATE / "journal.json", config.installation_id)
    engine = protection.DockerEngine(config, api, U.STATE)
    names = [engine.backup_name(operation_id), "wayfindr-updater-migration-" + operation_id,
             "wayfindr-updater-fence-" + operation_id]
    names += ["wayfindr-updater-apply-" + action + "-" + operation_id for action in
              ("protocol", "assess", "receipt", "verify")]
    if any(engine.oneoff_active(name) for name in names):
        refuse("bridge_effects_unverified")
    context, keys = protection.Protector(config, journal, U.STATE, api, engine=engine).baseline(operation_id)
    # The complete key material remains process-private and is never serialized.
    del keys
    ids = engine.service_ids()
    dependencies = engine.dependencies()
    storage = engine.call(["ps", "-a", "-q", "--no-trunc", "--filter", "label=com.docker.compose.project=" + config.value["compose_project"],
                           "--filter", "label=com.docker.compose.service=storage-init", "--filter", "label=com.docker.compose.oneoff=False"])
    if not protection.CONTAINER.fullmatch(storage) or storage in set(ids.values()) | dependencies:
        refuse("bridge_runtime_unverified")
    containers = {}
    for identifier in sorted(set(ids.values()) | dependencies | {storage}):
        engine.commands_settled(identifier)
        code, raw = capture(docker + ["inspect", identifier])
        rows = json.loads(raw) if code == 0 else None
        if not isinstance(rows, list) or len(rows) != 1:
            refuse("bridge_runtime_unverified")
        row = rows[0]
        if not isinstance(row, dict) or row.get("Id") != identifier or row.get("ExecIDs") not in (None, []):
            refuse("bridge_runtime_unverified")
        if identifier == storage and (row.get("Config", {}).get("Labels", {}).get("com.docker.compose.project") != config.value["compose_project"]
                                      or row.get("Config", {}).get("Labels", {}).get("com.docker.compose.service") != "storage-init"
                                      or row.get("Config", {}).get("Labels", {}).get("com.docker.compose.oneoff") != "False"):
            refuse("bridge_runtime_unverified")
        if identifier == storage and (row.get("State", {}).get("Status") != "exited" or row.get("State", {}).get("Running") is not False
                                      or row.get("State", {}).get("ExitCode") != 0 or row.get("State", {}).get("Pid") != 0):
            refuse("bridge_runtime_unverified")
        mounts = row.get("Mounts")
        if (not isinstance(mounts, list) or any(not isinstance(item, dict) or not isinstance(item.get("Destination"), str) for item in mounts)
                or len({item["Destination"] for item in mounts}) != len(mounts)):
            refuse("bridge_runtime_unverified")
        containers[identifier] = {key: row.get(key) for key in ("Id", "Image", "RestartCount", "Config", "HostConfig", "NetworkSettings")}
        containers[identifier]["Mounts"] = sorted(mounts, key=lambda item: item["Destination"])
        containers[identifier]["State"] = {key: row.get("State", {}).get(key) for key in
                                           ("Status", "Running", "Paused", "Restarting", "OOMKilled", "Dead", "Pid", "ExitCode", "Error", "StartedAt", "FinishedAt")}
    # Secret-bearing Config/HostConfig are bound by digest, never printed.
    if any(engine.oneoff_active(name) for name in names):
        refuse("bridge_effects_unverified")
    return {"context": context, "runtime_sha256": U.digest(U.encoded(containers))}


def authenticate_source(runtime, config, identity):
    try:
        return _authenticate_source(runtime, config, identity)
    except (runtime.Refusal, OSError, ValueError, struct.error):
        refuse("bridge_source_unverified")


def _authenticate_source(runtime, config, identity):
    deadline = time.monotonic() + 10
    nonce = secrets.token_hex(16)
    request = {"protocol": 1, "installation_id": config.installation_id, "nonce": nonce,
               "issued_at": int(time.time()), "action": "capabilities"}
    runtime.trusted(U.SOCKET, socket_node=True)
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:
        connection.settimeout(U.startup_remaining(deadline, "authentication"))
        connection.connect(str(U.SOCKET))
        pid, uid, _ = struct.unpack("3i", connection.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
        if uid != 0 or pid != int(identity[0]):
            refuse("bridge_source_unverified")
        connection.settimeout(U.startup_remaining(deadline, "authentication"))
        connection.sendall(runtime.envelope(request, config.token, "request"))
        raw = bytearray()
        while not raw.endswith(b"\n"):
            connection.settimeout(U.startup_remaining(deadline, "authentication"))
            chunk = connection.recv(min(4096, runtime.RESPONSE_MAX + 1 - len(raw)))
            if not chunk or len(raw) + len(chunk) > runtime.RESPONSE_MAX or b"\n" in chunk[:-1]:
                refuse("bridge_source_unverified")
            raw.extend(chunk)
    expected = {"protocol": 1, "installation_id": config.installation_id, "nonce": nonce,
                "ok": True, "result": config.capabilities()}
    if runtime.unpack_envelope(bytes(raw), config.token, "response") != expected or expected["result"]["helper"]["version"] != "0.5.0":
        refuse("bridge_source_unverified")
    U.running_helper(expected_identity=identity, starting=True, deadline=deadline)
    U.startup_remaining(deadline, "authentication")


def bridge_directory(transaction_id):
    return U.RECEIPTS / ("active-bridge-" + transaction_id)


def canonical_uuid(value):
    try:
        return isinstance(value, str) and str(uuid.UUID(value)) == value
    except (ValueError, TypeError, AttributeError):
        return False


def strict_object(raw):
    def unique(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                refuse("bridge_transaction_changed")
            result[key] = value
        return result
    try:
        value = json.loads(raw, object_pairs_hook=unique, parse_constant=lambda _: refuse("bridge_transaction_changed"))
    except (ValueError, UnicodeError, TypeError):
        refuse("bridge_transaction_changed")
    if not isinstance(value, dict):
        refuse("bridge_transaction_changed")
    return value


def validate_record(record, selected_hash, new, operation_id, expected_sha):
    keys = {"schema", "kind", "transaction_id", "installation_id", "operation_id", "journal_state_sha256",
            "bundle_sha256", "old", "new", "preserved", "stage", "history", "retained", "previous_generation",
            "verified_generation", "verified_generations", "code_directory_mode", "baseline", "raw_journal_hex", "raw_journal_sha256", "freeze_identity"}
    if (not isinstance(record, dict) or set(record) != keys or type(record["schema"]) is not int or record["schema"] != 2
            or record["kind"] != KIND or record["old"] != U.PUBLISHED["0.5.0"] or record["new"] != new
            or record["bundle_sha256"] != selected_hash or record["operation_id"] != operation_id
            or record["journal_state_sha256"] != expected_sha or not isinstance(record["stage"], str) or record["stage"] not in STAGES
            or type(record["code_directory_mode"]) is not int or record["code_directory_mode"] not in U.CODE_MODES
            or any(not isinstance(record[key], str) or not U.HEX.fullmatch(record[key]) for key in ("bundle_sha256", "journal_state_sha256"))):
        refuse("bridge_transaction_changed")
    for key in ("transaction_id", "installation_id", "operation_id", "previous_generation"):
        if not canonical_uuid(record[key]):
            refuse("bridge_transaction_changed")
    if record["verified_generation"] is not None and not canonical_uuid(record["verified_generation"]):
        refuse("bridge_transaction_changed")
    generations = record["verified_generations"]
    if (not isinstance(generations, list) or len(generations) > 1024 or any(not canonical_uuid(item) for item in generations)
            or len(set(generations)) != len(generations)
            or record["verified_generation"] != (generations[-1] if generations else None)):
        refuse("bridge_transaction_changed")
    if (not all(isinstance(record[key], dict) for key in ("history", "retained", "preserved", "baseline"))
            or set(record["retained"]) != {"apply", "protection", "helper-upgrades"}
            or U.digest(U.encoded(record["history"])) != expected_sha):
        refuse("bridge_transaction_changed")
    identity = record["freeze_identity"]
    if (not isinstance(identity, list) or len(identity) != 5 or not isinstance(identity[0], str)
                                 or re.fullmatch(r"[1-9][0-9]*", identity[0]) is None or type(identity[1]) is not int
                                 or identity[1] <= 0 or identity[2] != str(U.UNIT)
                                 or not isinstance(identity[3], str) or identity[3] not in {"", str(U.DROPIN)} or identity[4] != "on-failure"):
        refuse("bridge_transaction_changed")
    if record["raw_journal_hex"] is None:
        if record["stage"] != "prepared" or record["raw_journal_sha256"] is not None or generations:
            refuse("bridge_transaction_changed")
    else:
        if not isinstance(record["raw_journal_hex"], str) or not re.fullmatch(r"(?:[a-f0-9]{2})+", record["raw_journal_hex"]):
            refuse("bridge_transaction_changed")
        try:
            raw = bytes.fromhex(record["raw_journal_hex"])
        except (ValueError, TypeError):
            refuse("bridge_transaction_changed")
        original = strict_object(raw)
        if (len(raw) > 1_000_000 or U.digest(raw) != record["raw_journal_sha256"]
                or original.get("generation") != record["previous_generation"]
                or type(original.get("heartbeat_at")) is not int or original["heartbeat_at"] < 0
                or journal_state(original) != record["history"] or state_digest(original) != expected_sha):
            refuse("bridge_transaction_changed")


def result_for(record, generation=None):
    return {"schema": 2, "kind": KIND, "transaction_id": record["transaction_id"], "installation_id": record["installation_id"],
            "operation_id": record["operation_id"], "journal_state_sha256": record["journal_state_sha256"], "from_helper_version": "0.5.0",
            "helper_version": "0.6.0", "protocol": 1, "bundle_sha256": record["bundle_sha256"],
            "generation": generation or record["verified_generation"], "preserved": True, "active_operation_preserved": True,
            "application_changed": False,
            "retained_old_code": str(U.CODE.parent / (".wayfindr-updater-generation-" + record["transaction_id"])),
            "original_journal_sha256": record["raw_journal_sha256"]}


def validate_custody(record, distribution):
    """Only this transaction's recognized private audit bytes are excluded."""
    path = bridge_directory(record["transaction_id"])
    if not U.exists(path):
        return
    if U.identity(path, directory=True)[2:] != [0, 0, 0o700]:
        refuse("bridge_transaction_changed")
    entries = list(path.iterdir())
    if len(entries) > 4096:
        refuse("bridge_unowned_history")
    original = bytes.fromhex(record["raw_journal_hex"]) if record["raw_journal_hex"] is not None else None
    receipts = [U.encoded(result_for(record, generation)) for generation in record["verified_generations"]]
    total = 0
    for entry in entries:
        mode = 0o644 if re.fullmatch(r"code-incomplete-(?:" + "|".join(re.escape(name) for name in U.FILES) + r")-[a-f0-9]{32}", entry.name) else 0o600
        U.private(entry, mode)
        total += entry.stat().st_size
        if total > 10_000_000:
            refuse("bridge_unowned_history")
        raw = entry.read_bytes()
        if entry.name == "journal-before.json" or re.fullmatch(r"journal-incomplete-[a-f0-9]{32}", entry.name):
            valid = original is not None and original.startswith(raw)
        elif entry.name == "receipt.json" or re.fullmatch(r"receipt-(?:incomplete|previous)-[a-f0-9]{32}", entry.name):
            valid = any(expected.startswith(raw) for expected in receipts)
        elif re.fullmatch(r"stop-incomplete-[a-f0-9]{32}", entry.name):
            valid = (record["transaction_id"] + "\n").encode().startswith(raw)
        elif re.fullmatch(r"transaction-incomplete-[a-f0-9]{32}", entry.name):
            # A scratch is retained as non-authoritative evidence only. Its
            # bytes never supply a state transition or authorize an exchange.
            valid = len(raw) <= 8_388_608
        elif mode == 0o644 and distribution is not None:
            name = entry.name[len("code-incomplete-"):-33]
            expected = (distribution / "scripts/self-host" / name).read_bytes()
            valid = U.digest(expected) == record["new"][name] and expected.startswith(raw)
        else:
            valid = False
        if not valid:
            refuse("bridge_unowned_history")


def retain_raw_journal(record):
    directory = bridge_directory(record["transaction_id"])
    for path in (directory.parent, directory):
        if not U.exists(path):
            path.mkdir(mode=0o700)
            U.sync(path.parent)
        if U.identity(path, directory=True)[2:] != [0, 0, 0o700]:
            refuse("bridge_transaction_changed")
    raw = bytes.fromhex(record["raw_journal_hex"])
    destination = directory / "journal-before.json"
    if U.exists(destination):
        U.private(destination, 0o600)
        if destination.read_bytes() != raw:
            # Preserve exact interrupted prefixes; never accept an unrelated
            # journal as the original or overwrite the live journal.
            partial = destination.read_bytes()
            if not raw.startswith(partial):
                refuse("bridge_transaction_changed")
            os.replace(destination, directory / ("journal-incomplete-" + uuid.uuid4().hex))
            U.sync(directory)
            U.create(destination, raw)
    else:
        U.create(destination, raw)
    return destination


def freeze_stop(runtime, config, distribution, record, *, recovering=False):
    current = U.service()
    if current["ActiveState"] in {"inactive", "failed"} and current["MainPID"] == "0":
        if not recovering or record["raw_journal_hex"] is None or not U.cgroup_empty():
            refuse("bridge_source_unverified")
    else:
        already_frozen = recovering and current["FreezerState"] == "frozen" and U.events().get("frozen") == "1"
        if already_frozen:
            identity = tuple(record["freeze_identity"] or ())
            process = U.PROC / current["MainPID"]
            U.trusted(process / "cmdline")
            U.trusted(process / "cgroup")
            if (len(identity) != 5 or current["MainPID"] != identity[0] or U.process_start(current) != identity[1]
                    or current["FragmentPath"] != identity[2] or current["DropInPaths"] != identity[3]
                    or current["Restart"] != identity[4] or not U.condition_loaded()
                    or (process / "cmdline").read_bytes() != b"/usr/bin/python3\0/usr/local/lib/wayfindr-updater/updater.py\0serve\0"
                    or (process / "cgroup").read_text() != "0::/system.slice/" + U.SERVICE + "\n"):
                refuse("bridge_source_unverified")
        else:
            expected = tuple(record["freeze_identity"]) if record["stage"] == "prepared" else None
            before, identity = U.running_helper(expected_identity=expected, starting=True)
            if U.code_hashes(U.CODE) == record["old"]:
                authenticate_source(runtime, config, identity)
            else:
                U.authenticate(runtime, config, expected_pid=int(identity[0]))
                U.running_helper(expected_identity=identity, starting=True)
            if before["FreezerState"] != "running":
                U.settle_manager(before, identity)
            record["freeze_identity"] = list(identity)
            U.store(record)
            U.run("freeze", U.SERVICE)
        killed = False
        try:
            frozen = U.service()
            if (frozen["MainPID"] != identity[0] or U.process_start(frozen) != identity[1]
                    or frozen["FreezerState"] != "frozen" or U.events().get("frozen") != "1"):
                refuse("freeze_unverified")
            U.trusted(U.CGROUP / "cgroup.procs")
            if (U.CGROUP / "cgroup.procs").read_text().split() != [identity[0]]:
                refuse("bridge_worker_unsettled")
            raw, value, state, retained = observe_state(runtime, config, record["operation_id"], record["journal_state_sha256"], record=record, distribution=distribution)
            if state != record["history"] or retained != record["retained"]:
                refuse("bridge_state_changed")
            U.verify_snapshot(config, record["preserved"])
            if readonly_baseline(runtime, config, distribution, record["operation_id"]) != record["baseline"]:
                refuse("bridge_source_changed")
            if (U.STATE / "journal.json").read_bytes() != raw:
                refuse("bridge_state_changed")
            if record["stage"] == "prepared":
                record.update(stage="frozen", raw_journal_hex=raw.hex(), raw_journal_sha256=U.digest(raw), previous_generation=value["generation"])
                U.store(record)
            elif U.code_hashes(U.CODE) == record["old"] and U.digest(raw) != record["raw_journal_sha256"]:
                refuse("bridge_state_changed")
            retain_raw_journal(record)
            with (U.CGROUP / "cgroup.kill").open("w") as stream:
                stream.write("1\n")
            killed = True
            deadline = time.monotonic() + 10
            while not U.cgroup_empty():
                if time.monotonic() >= deadline:
                    refuse("helper_not_stopped")
                time.sleep(0.05)
            U.run("stop", U.SERVICE)
        finally:
            if not killed and record["stage"] == "prepared":
                U.run("thaw", U.SERVICE)
    current = U.service()
    if current["MainPID"] != "0" or current["ActiveState"] not in {"inactive", "failed"} or not U.cgroup_empty():
        refuse("helper_not_stopped")
    return runtime.HostLock(U.STATE / "helper.lock")


def stage_generation(distribution, record, *, recovering):
    staged = U.CODE.parent / (".wayfindr-updater-generation-" + record["transaction_id"])
    current = U.code_hashes(U.CODE)
    generation_record = {**record, "stage": "frozen_idle" if record["stage"] == "frozen" else record["stage"]}
    U.validate_generation(generation_record, current, staged)
    if current == record["old"]:
        record["stage"] = "staging"
        U.store(record)
        if not U.exists(staged):
            previous_umask = os.umask(0)
            try:
                staged.mkdir(mode=record["code_directory_mode"])
            finally:
                os.umask(previous_umask)
            os.chown(staged, 0, 0)
            U.sync(staged.parent)
        partial = U.code_hashes(staged, partial=True)
        for name, checksum in list(partial.items()):
            if checksum != record["new"][name]:
                if not recovering:
                    refuse("code_changed")
                expected = (distribution / "scripts/self-host" / name).read_bytes()
                if U.digest(expected) != record["new"][name] or not expected.startswith((staged / name).read_bytes()):
                    refuse("code_changed")
                # Keep interrupted staged bytes in bridge-private custody.
                destination = bridge_directory(record["transaction_id"]) / ("code-incomplete-" + name + "-" + uuid.uuid4().hex)
                os.replace(staged / name, destination)
                U.sync(staged)
                U.sync(destination.parent)
                del partial[name]
        for name in U.FILES:
            if name not in partial:
                U.create(staged / name, (distribution / "scripts/self-host" / name).read_bytes(), 0o644)
        if U.code_hashes(staged) != record["new"]:
            refuse("distribution_changed")
        U.exchange(U.CODE, staged)
    if U.code_hashes(U.CODE) != record["new"] or U.code_hashes(staged) != record["old"]:
        refuse("code_changed")
    U.validate_generation({**record, "stage": "switched"}, record["new"], staged)
    record["stage"] = "switched"
    U.store(record)
    return staged


def directory(record):
    path = bridge_directory(record["transaction_id"])
    for entry in (path.parent, path):
        if not U.exists(entry):
            entry.mkdir(mode=0o700)
            U.sync(entry.parent)
        if U.identity(entry, directory=True)[2:] != [0, 0, 0o700]:
            refuse("bridge_transaction_changed")
    return path


def preserve_scratch(record):
    temporary = U.STATE / ".helper-upgrade.next"
    if U.exists(temporary):
        U.private(temporary)
        if temporary.stat().st_size > 8_388_608:
            refuse("bridge_transaction_changed")
        destination = directory(record) / ("transaction-incomplete-" + uuid.uuid4().hex)
        os.replace(temporary, destination)
        U.sync(U.STATE)
        U.sync(destination.parent)


def ensure_stop(record, *, recovering=False):
    expected = (record["transaction_id"] + "\n").encode()
    temporary = U.STATE / ".helper-upgrade-stop.next"
    for path in (temporary, U.STOP):
        if not U.exists(path):
            continue
        U.private(path)
        raw = path.read_bytes()
        if path == U.STOP and raw == expected:
            continue
        if not recovering or not expected.startswith(raw):
            refuse("bridge_transaction_changed")
        retained = directory(record) / ("stop-incomplete-" + uuid.uuid4().hex)
        U.create(retained, raw)
        if path == temporary:
            U.unlink(path)
        else:
            # Keep the restart existence gate continuously present during an
            # exact interrupted-prefix repair of this transaction's marker.
            U.create(temporary, expected)
            os.replace(temporary, U.STOP)
            U.sync(U.STATE)
    if not U.exists(U.STOP):
        U.create(U.STOP, expected)


def verify_preserved(runtime, config, distribution, record, *, recovering=False):
    raw, value, history, retained = observe_state(runtime, config, record["operation_id"], record["journal_state_sha256"], record=record, distribution=distribution, recovering=recovering)
    if history != record["history"] or retained != record["retained"]:
        refuse("bridge_state_changed")
    U.verify_snapshot(config, record["preserved"])
    if readonly_baseline(runtime, config, distribution, record["operation_id"]) != record["baseline"]:
        refuse("bridge_source_changed")
    return raw, value


def finish(distribution, selected_hash, operation_id, expected_sha, *, recovery=None):
    if (not canonical_uuid(operation_id) or not isinstance(expected_sha, str) or not U.HEX.fullmatch(expected_sha)
            or not isinstance(selected_hash, str) or not U.HEX.fullmatch(selected_hash)
            or (recovery is not None and not canonical_uuid(recovery))):
        refuse("bridge_request_invalid")
    declaration, actual = U.bundle(distribution)
    if actual != selected_hash or declaration["helper_version"] != "0.6.0":
        refuse("distribution_changed")
    U.supported(recovering=recovery is not None)
    U.owned_gate()
    if not U.exists(U.DROPIN):
        refuse("bridge_source_gate_required")
    U.trusted(U.STATE, directory=True)
    U.trusted(U.CODE.parent, directory=True)
    if U.exists(U.LOCK):
        U.private(U.LOCK)
    descriptor = os.open(U.LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    os.fchmod(descriptor, 0o600)
    os.fchown(descriptor, 0, 0)
    try:
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            refuse("operation_busy")
        current = U.code_hashes(U.CODE)
        if current not in (U.PUBLISHED["0.5.0"], declaration["files"]):
            refuse("unsupported_installed_helper")
        runtime = U.runtime_from(U.CODE / "updater.py")
        config = U.enrollment(runtime, distribution)
        if recovery is None:
            if current != U.PUBLISHED["0.5.0"]:
                refuse("unsupported_installed_helper")
            if any(U.exists(path) for path in (U.TRANSACTION, U.STOP, U.STATE / ".helper-upgrade.next", U.STATE / ".helper-upgrade-stop.next", U.GATE_TEMP)):
                refuse("recovery_required")
            raw, value, history, retained = observe_state(runtime, config, operation_id, expected_sha)
            _, source_identity = U.running_helper(starting=True)
            authenticate_source(runtime, config, source_identity)
            baseline = readonly_baseline(runtime, config, distribution, operation_id)
            # Bind all journal/retained/authority facts again after baseline;
            # heartbeat alone may advance before the frozen snapshot.
            second = observe_state(runtime, config, operation_id, expected_sha)
            if second[2:] != (history, retained):
                refuse("bridge_state_changed")
            record = {"schema": 2, "kind": KIND, "transaction_id": str(uuid.uuid4()), "installation_id": config.installation_id,
                      "operation_id": operation_id, "journal_state_sha256": expected_sha, "bundle_sha256": actual,
                      "old": U.PUBLISHED["0.5.0"], "new": declaration["files"], "preserved": U.snapshot(config),
                      "stage": "prepared", "history": history, "retained": retained, "previous_generation": value["generation"],
                      "verified_generation": None, "verified_generations": [], "code_directory_mode": stat.S_IMODE(U.CODE.stat().st_mode),
                      "baseline": baseline, "raw_journal_hex": None, "raw_journal_sha256": None, "freeze_identity": list(source_identity)}
            U.gate_install()
            U.store(record)
            ensure_stop(record)
        else:
            if not U.exists(U.TRANSACTION):
                temporary = U.STATE / ".helper-upgrade.next"
                U.private(temporary)
                record = U.read_json(temporary)
                validate_record(record, actual, declaration["files"], operation_id, expected_sha)
                if record["stage"] != "prepared" or current != record["old"] or record["transaction_id"] != recovery:
                    refuse("bridge_transaction_changed")
                U.verify_snapshot(config, record["preserved"])
                os.replace(temporary, U.TRANSACTION)
                U.sync(U.STATE)
            U.private(U.TRANSACTION)
            record = U.read_json(U.TRANSACTION)
            validate_record(record, actual, declaration["files"], operation_id, expected_sha)
            if record["transaction_id"] != recovery or record["installation_id"] != config.installation_id:
                refuse("bridge_transaction_changed")
            staged = U.CODE.parent / (".wayfindr-updater-generation-" + recovery)
            U.validate_generation({**record, "stage": "frozen_idle" if record["stage"] == "frozen" else record["stage"]}, current, staged)
            verify_preserved(runtime, config, distribution, record, recovering=True)
            preserve_scratch(record)
            if not U.exists(U.STOP) and record["stage"] not in {"prepared", "starting", "verified"}:
                refuse("bridge_transaction_changed")
            ensure_stop(record, recovering=True)
            U.gate_install(recovery=recovery)
        validate_record(record, actual, declaration["files"], operation_id, expected_sha)
        with freeze_stop(runtime, config, distribution, record, recovering=recovery is not None):
            raw, value = verify_preserved(runtime, config, distribution, record)
            if current == record["old"] and U.digest(raw) != record["raw_journal_sha256"]:
                refuse("bridge_state_changed")
            retain_raw_journal(record)
            staged = stage_generation(distribution, record, recovering=recovery is not None)
            new_runtime = U.runtime_from(U.CODE / "updater.py")
            new_config = U.enrollment(new_runtime, distribution)
            after_exchange, _ = verify_preserved(new_runtime, new_config, distribution, record)
            if after_exchange != raw:
                refuse("bridge_state_changed")
            record["stage"] = "starting"
            U.store(record)
            U.unlink(U.STOP)
        U.run("start", U.SERVICE)
        verified_identity = U.authenticate_started(new_runtime, new_config)
        _, after = verify_preserved(new_runtime, new_config, distribution, record)
        if after["generation"] == record["previous_generation"] or not new_runtime.is_uuid(after["generation"]):
            refuse("startup_unverified")
        if U.code_hashes(U.CODE) != record["new"] or U.code_hashes(staged) != record["old"]:
            refuse("code_changed")
        U.validate_generation({**record, "stage": "switched"}, record["new"], staged)
        U.verify_completion(new_runtime, new_config, verified_identity)
        record["stage"] = "verified"
        record["verified_generation"] = after["generation"]
        if after["generation"] not in record["verified_generations"]:
            record["verified_generations"].append(after["generation"])
        U.store(record)
        result = result_for(record)
        receipt = directory(record) / "receipt.json"
        encoded = U.encoded(result)
        if U.exists(receipt):
            U.private(receipt)
            if receipt.read_bytes() != encoded:
                partial = receipt.read_bytes()
                previous = [U.encoded(result_for(record, generation)) for generation in record["verified_generations"]]
                if recovery is None or not any(expected.startswith(partial) for expected in previous):
                    refuse("bridge_transaction_changed")
                prefix = "receipt-previous-" if partial in previous else "receipt-incomplete-"
                os.replace(receipt, receipt.with_name(prefix + uuid.uuid4().hex))
                U.sync(receipt.parent)
                U.create(receipt, encoded)
        else:
            U.create(receipt, encoded)
        U.verify_completion(new_runtime, new_config, verified_identity)
        U.unlink(U.TRANSACTION)
        return result
    finally:
        os.close(descriptor)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    for name in ("inspect-apply-bridge", "bridge-apply-recovery", "recover-apply-bridge"):
        command = commands.add_parser(name)
        command.add_argument("--operation", required=True)
        if name != "inspect-apply-bridge":
            command.add_argument("--expected-journal-state-sha256", required=True)
            command.add_argument("--expected-bundle-sha256", required=True)
        if name == "recover-apply-bridge":
            command.add_argument("--transaction", required=True)
    options = parser.parse_args()
    try:
        if os.geteuid() != 0 or sys.platform != "linux" or sys.version_info < (3, 11):
            refuse("unsupported_host")
        os.umask(0o077)
        distribution = Path(__file__).absolute().parents[2]
        if options.command == "inspect-apply-bridge":
            if U.code_hashes(U.CODE) != U.PUBLISHED["0.5.0"]:
                refuse("unsupported_installed_helper")
            runtime = U.runtime_from(U.CODE / "updater.py")
            config = U.enrollment(runtime, distribution)
            value = U.read_json(U.STATE / "journal.json")
            checksum = state_digest(value)
            observe_state(runtime, config, options.operation, checksum)
            result = {"schema": 2, "kind": KIND, "operation_id": options.operation, "journal_state_sha256": checksum,
                      "baseline_verified": False, "helper_replacement_started": False}
        else:
            result = finish(distribution, options.expected_bundle_sha256, options.operation,
                            options.expected_journal_state_sha256, recovery=getattr(options, "transaction", None))
        print(json.dumps(result, sort_keys=True))
        return 0
    except Exception as failure:
        reason = str(failure) if isinstance(failure, U.UpgradeError) else "bridge_unavailable"
        result = {"schema": 2, "kind": KIND, "status": "refused", "reason": reason,
                  "recovery_required": U.exists(U.TRANSACTION) or U.exists(U.STATE / ".helper-upgrade.next")}
        if isinstance(failure, U.StartupError):
            result["startup_stage"] = failure.stage
        print(json.dumps(result, sort_keys=True))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
