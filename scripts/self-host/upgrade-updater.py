#!/usr/bin/env python3
"""Explicit root-only replacement of the published 0.4.0 host helper.

Review a root-owned distribution first, obtain its bundle hash with ``inspect``,
then pass that exact hash to ``upgrade``. An interruption requires ``recover``
with the recorded transaction ID and the same distribution/hash. Application
images, containers, enrollment credentials and retained recovery are never
changed. Linux cgroup-v2 freezing, cgroup.kill and renameat2 are mandatory.
"""

from __future__ import annotations

import argparse
import ast
import ctypes
import fcntl
import hashlib
import json
import os
from pathlib import Path
import platform
import re
import secrets
import socket
import stat
import struct
import subprocess
import sys
import time
import types
import uuid


sys.dont_write_bytecode = True
VERSION = "0.5.0"
CODE = Path("/usr/local/lib/wayfindr-updater")
STATE = Path("/var/lib/wayfindr-updater")
CONFIG = Path("/etc/wayfindr-updater/installation.json")
CREDENTIAL = Path("/etc/wayfindr-updater/credential.json")
CLI = Path("/usr/local/bin/wayfindr-updater")
UNIT = Path("/etc/systemd/system/wayfindr-updater.service")
TMPFILES = Path("/etc/tmpfiles.d/wayfindr-updater.conf")
RUNTIME = Path("/run/wayfindr-updater")
SOCKET = RUNTIME / "updater.sock"
TRANSACTION = STATE / "helper-upgrade.json"
STOP = STATE / "helper-upgrade-stop"
LOCK = STATE / "helper-upgrade.lock"
RECEIPTS = STATE / "helper-upgrades"
DROPIN = Path("/etc/systemd/system/wayfindr-updater.service.d/10-helper-upgrade.conf")
GATE_TEMP = DROPIN.with_name(".10-helper-upgrade.next")
SERVICE = "wayfindr-updater.service"
CGROUP = Path("/sys/fs/cgroup/system.slice/wayfindr-updater.service")
PROC = Path("/proc")
SYSTEMCTL = Path("/usr/bin/systemctl")
BUSCTL = Path("/usr/bin/busctl")
TEST = Path("/usr/bin/test")
HEX = re.compile(r"[a-f0-9]{64}\Z")
FILES = ("updater.py", "update_protection.py", "update_apply.py", "update_artifacts.py", "protection_archive.py")
CODE_MODES = {0o700, 0o750, 0o755}
# These exact source bytes shipped in v1.2.0, commit 56374e9574ed84616aae430d06589cfe2f0b33a0.
PUBLISHED = {
    "updater.py": "70fa1541552dd7ad4275fb4b1f66be0c01f6c6e3d1417685fc375119a4b7c778",
    "update_protection.py": "f61f6323be01c541722d2b7393412523a0985cc30ade647f1d4cce7a606e0b85",
    "update_apply.py": "5abe0fe47424cd9bc478373c783ac48a976fa83eb9ee6d0d75eaca0203bede45",
    "update_artifacts.py": "e6b2e99f1eec06d5cd4d4ab77691b341e68bdee7efc7cfd36eb97b1e252f8e41",
    "protection_archive.py": "50a7f3a68845a63d5716d5338e1a104f065171f034e71b3e7d1d3af1deb92944",
}
UNIT_SHA = "f478f16ab8d42796f10b94abd5531d9dae63b01e9e8a26fa59345c426ca96f25"
TMPFILES_SHA = "47aab750e3e8b3c22223877b10c50f7ba340f9acafcc0a1f54f0812843cc9dc5"
WRAPPER = b'#!/bin/sh\nexec /usr/bin/python3 /usr/local/lib/wayfindr-updater/updater.py "$@"\n'
LEGACY_GATE = b"[Unit]\nConditionPathExists=!/var/lib/wayfindr-updater/helper-upgrade-stop\n"
GATE = LEGACY_GATE + b"\n[Service]\nExecCondition=/usr/bin/test ! -e /var/lib/wayfindr-updater/helper-upgrade-stop\n"


class UpgradeError(Exception):
    """Only fixed operator-safe classifications reach terminal output."""


def refuse(code):
    raise UpgradeError(code)


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def encoded(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"), allow_nan=False) + "\n").encode()


def exists(path):
    return path.exists() or path.is_symlink()


def trusted(path, *, directory=False):
    if not path.is_absolute() or ".." in path.parts:
        refuse("untrusted_path")
    for entry in reversed([path, *path.parents]):
        information = entry.lstat()
        expected = stat.S_ISDIR if entry != path or directory else stat.S_ISREG
        if not expected(information.st_mode) or information.st_uid != 0 or information.st_mode & 0o022:
            refuse("untrusted_path")


def private(path, mode=0o600, gid=0):
    trusted(path)
    information = path.stat()
    if information.st_gid != gid or stat.S_IMODE(information.st_mode) != mode or information.st_nlink != 1:
        refuse("untrusted_metadata")


def read_json(path, maximum=8_388_608):
    trusted(path)
    raw = path.read_bytes()
    if len(raw) > maximum:
        refuse("state_invalid")
    def unique(pairs):
        value = {}
        for key, item in pairs:
            if key in value:
                refuse("state_invalid")
            value[key] = item
        return value
    try:
        value = json.loads(raw, object_pairs_hook=unique, parse_constant=lambda _: refuse("state_invalid"))
    except (ValueError, UnicodeError):
        refuse("state_invalid")
    if not isinstance(value, dict):
        refuse("state_invalid")
    return value


def sync(directory):
    descriptor = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def create(path, raw, mode=0o600):
    previous_umask = os.umask(0)
    try:
        descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    finally:
        os.umask(previous_umask)
    try:
        os.fchmod(descriptor, mode)
        os.fchown(descriptor, 0, 0)
        with os.fdopen(descriptor, "wb", closefd=False) as output:
            output.write(raw)
            output.flush()
            os.fsync(output.fileno())
    finally:
        os.close(descriptor)
    sync(path.parent)


def store(value):
    temporary = STATE / ".helper-upgrade.next"
    create(temporary, encoded(value))
    os.replace(temporary, TRANSACTION)
    sync(STATE)


def preserve_partial_write(transaction_id):
    """Only explicit recovery may retain this owned, non-authoritative scratch."""
    temporary = STATE / ".helper-upgrade.next"
    if not exists(temporary):
        return
    private(temporary)
    if not exists(RECEIPTS):
        RECEIPTS.mkdir(mode=0o700)
        sync(STATE)
    trusted(RECEIPTS, directory=True)
    destination = RECEIPTS / (transaction_id + ".incomplete-write-" + uuid.uuid4().hex)
    os.rename(temporary, destination)
    sync(STATE)
    sync(RECEIPTS)


def preserve_partial_file(path, expected, transaction_id, mode):
    """Retain a known interrupted prefix; never adopt unrelated corrupt bytes."""
    private(path, mode)
    raw = path.read_bytes()
    if not expected.startswith(raw):
        refuse("transaction_changed")
    if not exists(RECEIPTS):
        RECEIPTS.mkdir(mode=0o700)
        sync(STATE)
    trusted(RECEIPTS, directory=True)
    retained = RECEIPTS / (transaction_id + ".incomplete-" + path.name + "-" + digest(raw))
    if exists(retained):
        private(retained)
        previous = retained.read_bytes()
        if previous != raw:
            if not raw.startswith(previous):
                refuse("transaction_changed")
            # An interrupted attempt to retain this same known prefix must
            # remain audit evidence too; preserve it before recreating the
            # deterministic complete copy, without deleting either source.
            incomplete = retained.with_name(retained.name + ".incomplete-write-" + uuid.uuid4().hex)
            os.rename(retained, incomplete)
            sync(RECEIPTS)
            create(retained, raw)
    else:
        create(retained, raw)


def ensure_stop(transaction_id, *, recovering=False):
    expected = (transaction_id + "\n").encode()
    temporary = STATE / ".helper-upgrade-stop.next"
    if exists(temporary):
        if not recovering:
            refuse("recovery_required")
        preserve_partial_file(temporary, expected, transaction_id, 0o600)
        unlink(temporary)
    if not exists(STOP):
        create(STOP, expected)
    private(STOP)
    if STOP.read_bytes() != expected:
        if not recovering:
            refuse("transaction_changed")
        preserve_partial_file(STOP, expected, transaction_id, 0o600)
        create(temporary, expected)
        # Keep the existence gate continuously present while repairing only an
        # owned interrupted prefix of this exact transaction's stop marker.
        os.replace(temporary, STOP)
        sync(STATE)


def unlink(path):
    path.unlink()
    sync(path.parent)


def runtime_from(path):
    runtime = types.ModuleType("reviewed_wayfindr_upgrade_runtime")
    runtime.__file__ = str(path)
    exec(compile(path.read_bytes(), str(path), "exec"), vars(runtime))
    # Explicit arguments below avoid default-argument path surprises in tests.
    runtime.STATE_DIR = STATE
    return runtime


def bundle(distribution):
    trusted(distribution, directory=True)
    source = distribution / "scripts/self-host"
    hashes = {}
    for name in FILES:
        path = source / name
        trusted(path)
        raw = path.read_bytes()
        compile(raw, str(path), "exec")
        hashes[name] = digest(raw)
    declarations = {}
    for statement in ast.parse((source / "updater.py").read_bytes()).body:
        if isinstance(statement, ast.Assign) and len(statement.targets) == 1 and isinstance(statement.targets[0], ast.Name):
            if statement.targets[0].id in {"VERSION", "PROTOCOL"}:
                declarations[statement.targets[0].id] = ast.literal_eval(statement.value)
    if declarations != {"VERSION": VERSION, "PROTOCOL": 1}:
        refuse("distribution_incompatible")
    value = {"schema": 1, "helper_version": VERSION, "protocol": 1, "files": hashes}
    return value, digest(encoded(value))


def code_hashes(directory, *, partial=False):
    trusted(directory, directory=True)
    # Published enrollment uses mkdir0755 without overriding the operator's
    # umask. These exact safe modes cover normal022,027 and077 root shells.
    if stat.S_IMODE(directory.stat().st_mode) not in CODE_MODES or directory.stat().st_gid != 0:
        refuse("code_changed")
    names = {entry.name for entry in directory.iterdir()}
    if names - set(FILES) or (not partial and names != set(FILES)):
        refuse("code_changed")
    hashes = {}
    for name in names:
        path = directory / name
        private(path, 0o644)
        hashes[name] = digest(path.read_bytes())
    return hashes


def run(*arguments):
    try:
        result = subprocess.run([str(SYSTEMCTL), *arguments], stdin=subprocess.DEVNULL,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=False,
                                timeout=30, env={"PATH": "/usr/bin:/bin", "LANG": "C"}, text=True)
    except (OSError, subprocess.TimeoutExpired):
        refuse("systemd_unavailable")
    if result.returncode != 0:
        refuse("systemd_refused")
    return result.stdout.strip()


def service():
    names = ("ActiveState", "SubState", "MainPID", "ControlGroup", "FragmentPath", "DropInPaths", "FreezerState", "Restart")
    result = run("show", SERVICE, "--property=" + ",".join(names))
    value = dict(line.split("=", 1) for line in result.splitlines() if "=" in line)
    if set(value) != set(names) or value["FragmentPath"] != str(UNIT) or value["DropInPaths"] not in {"", str(DROPIN)} or value["Restart"] != "on-failure":
        refuse("service_changed")
    return value


def condition_loaded():
    try:
        result = subprocess.run([str(BUSCTL), "--json=short", "get-property", "org.freedesktop.systemd1",
                                 "/org/freedesktop/systemd1/unit/wayfindr_2dupdater_2eservice",
                                 "org.freedesktop.systemd1.Service", "ExecCondition"],
                                stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                check=False, timeout=30, env={"PATH": "/usr/bin:/bin", "LANG": "C"}, text=True)
        if result.returncode != 0 or len(result.stdout) > 65536:
            refuse("service_changed")
        value = json.loads(result.stdout)
    except (OSError, subprocess.TimeoutExpired, ValueError):
        refuse("systemd_unavailable")
    # D-Bus preserves the argument array and every configured condition; joined
    # systemctl text could hide an extra command or ambiguous argument boundary.
    if not isinstance(value, dict) or set(value) != {"type", "data"} or value["type"] != "a(sasbttttuii)" or not isinstance(value["data"], list) or len(value["data"]) != 1:
        return False
    command = value["data"][0]
    return (isinstance(command, list) and len(command) == 10
            and command[:3] == [str(TEST), [str(TEST), "!", "-e", str(STOP)], False]
            and type(command[2]) is bool
            and all(type(number) is int for number in command[3:]))


def events():
    return dict(line.split() for line in (CGROUP / "cgroup.events").read_text().splitlines())


def cgroup_empty():
    try:
        return events().get("populated") == "0"
    except FileNotFoundError:
        # systemd may remove the empty service cgroup before our next read.
        # Missing events in an existing group is not proof that it is empty.
        if exists(CGROUP):
            refuse("helper_not_stopped")
        current = service()
        return (current["MainPID"] == "0" and current["ActiveState"] in {"inactive", "failed"}
                and current["ControlGroup"] in {"", "/system.slice/" + SERVICE}
                and current["DropInPaths"] == str(DROPIN))


def supported(*, recovering=False):
    if sys.platform != "linux" or os.geteuid() != 0 or sys.version_info < (3, 11) or platform.machine().lower() not in {"x86_64", "amd64", "aarch64", "arm64"}:
        refuse("host_unsupported")
    trusted(SYSTEMCTL)
    for executable in (BUSCTL, TEST):
        trusted(executable)
        if not executable.stat().st_mode & 0o111:
            refuse("host_unsupported")
    trusted(Path("/sys/fs/cgroup/cgroup.controllers"))
    # Never silently fall back to ordinary stop: it thaws and races legacy Start.
    for name in ("cgroup.freeze", "cgroup.kill", "cgroup.events"):
        if not recovering or CGROUP.exists():
            trusted(CGROUP / name)
    version = run("--version").splitlines()[0]
    if re.fullmatch(r"systemd ([0-9]+)(?: .*)?", version) is None or int(version.split()[1]) < 252:
        refuse("host_unsupported")
    if not hasattr(ctypes.CDLL(None), "renameat2"):
        refuse("atomic_exchange_unsupported")
    current = service()
    if not recovering and (current["ActiveState"] != "active" or current["SubState"] != "running" or not current["MainPID"].isdigit() or int(current["MainPID"]) <= 0 or current["ControlGroup"] != "/system.slice/" + SERVICE or current["FreezerState"] != "running" or events().get("frozen") != "0"):
        refuse("helper_not_running")
    if current["MainPID"].isdigit() and int(current["MainPID"]) > 0:
        process = Path("/proc") / current["MainPID"]
        if (process / "cmdline").read_bytes() != b"/usr/bin/python3\0/usr/local/lib/wayfindr-updater/updater.py\0serve\0" or (process / "cgroup").read_text() != "0::/system.slice/" + SERVICE + "\n":
            refuse("service_changed")


def owned_gate(*, allow_legacy=False):
    if exists(DROPIN):
        private(DROPIN, 0o644)
        allowed = {GATE, LEGACY_GATE} if allow_legacy else {GATE}
        children = {DROPIN, GATE_TEMP} if allow_legacy else {DROPIN}
        if DROPIN.read_bytes() not in allowed or set(DROPIN.parent.iterdir()) - children:
            refuse("service_changed")
        if exists(GATE_TEMP):
            private(GATE_TEMP, 0o644)
            if not GATE.startswith(GATE_TEMP.read_bytes()):
                refuse("service_changed")
    elif exists(DROPIN.parent):
        trusted(DROPIN.parent, directory=True)
        if any(DROPIN.parent.iterdir()):
            refuse("service_changed")


def gate_install(*, recovery=None):
    owned_gate(allow_legacy=recovery is not None)
    if exists(GATE_TEMP):
        if recovery is None:
            refuse("recovery_required")
        preserve_partial_file(GATE_TEMP, GATE, recovery, 0o644)
        unlink(GATE_TEMP)
    if not DROPIN.parent.exists():
        trusted(DROPIN.parent.parent, directory=True)
        DROPIN.parent.mkdir(mode=0o755)
        sync(DROPIN.parent.parent)
    if not DROPIN.exists():
        create(DROPIN, GATE, 0o644)
    elif DROPIN.read_bytes() == LEGACY_GATE:
        if recovery is None:
            refuse("service_changed")
        preserve_partial_file(DROPIN, GATE, recovery, 0o644)
        create(GATE_TEMP, GATE, 0o644)
        os.replace(GATE_TEMP, DROPIN)
        sync(DROPIN.parent)
    run("daemon-reload")
    if service()["DropInPaths"] != str(DROPIN) or not condition_loaded():
        refuse("service_changed")


def identity(path, *, directory=False):
    trusted(path, directory=directory)
    information = path.stat()
    return [information.st_dev, information.st_ino, information.st_uid, information.st_gid, stat.S_IMODE(information.st_mode)]


def snapshot(config):
    paths = [CONFIG, CREDENTIAL, CLI, UNIT, TMPFILES]
    install = Path(config.value["install_dir"])
    paths += [install / name for name in ("compose.yml", ".env", "install.sh", "compose.updater.yml", ".updater-enrolled")]
    result = {str(path): {"identity": identity(path), "sha256": digest(path.read_bytes())} for path in paths}
    result[str(RUNTIME)] = {"identity": identity(RUNTIME, directory=True)}
    result[str(STATE)] = {"identity": identity(STATE, directory=True)}
    result[str(STATE / "helper.lock")] = {"identity": identity(STATE / "helper.lock")}
    return result


def verify_snapshot(config, expected):
    if snapshot(config) != expected:
        refuse("preserved_identity_changed")


def enrollment(runtime, distribution):
    trusted(CONFIG)
    configuration_gid = CONFIG.stat().st_gid
    if configuration_gid not in {0, 1000}:
        refuse("untrusted_metadata")
    # Enrollment writes gid0; a successful0.4 managed promotion atomically
    # replaces this file from the root:1000 service. Preserve either proven
    # canonical ownership rather than changing it during a helper upgrade.
    private(CONFIG, 0o600, configuration_gid)
    private(CREDENTIAL, 0o440, 1000)
    config = runtime.Configuration.load(CONFIG, CREDENTIAL)
    install = Path(config.value["install_dir"])
    if exists(install / ".upgrade.lock"):
        refuse("operation_busy")
    private(install / ".updater-enrolled")
    if CLI.read_bytes() != WRAPPER or stat.S_IMODE(CLI.stat().st_mode) != 0o755:
        refuse("code_changed")
    template = distribution / "docker/self-hosting/wayfindr-updater.service"
    trusted(template)
    if digest(template.read_bytes()) != UNIT_SHA or digest(TMPFILES.read_bytes()) != TMPFILES_SHA:
        refuse("service_changed")
    path = str(install)
    if re.fullmatch(r"/[A-Za-z0-9_./-]+", path) is None or ".." in install.parts or path == "/":
        refuse("configuration_changed")
    if UNIT.read_bytes() != template.read_bytes().replace(b"__WAYFINDR_INSTALL_DIR__", path.encode("ascii")):
        refuse("service_changed")
    if identity(RUNTIME, directory=True)[2:] != [0, 1000, 0o750]:
        refuse("runtime_changed")
    if identity(STATE, directory=True)[2:] != [0, 1000, 0o700]:
        refuse("state_ownership_changed")
    for path, mode in ((CLI, 0o755), (UNIT, 0o644), (TMPFILES, 0o644)):
        private(path, mode)
    # The systemd unit runs root:1000; HostLock creates this inode in that group.
    # Preserve it instead of changing ownership or recreating a parallel lock.
    private(STATE / "helper.lock", 0o600, 1000)
    return config


def idle(runtime, config, *, private_code=None):
    journal = runtime.Journal(STATE / "journal.json", config.installation_id)
    value = journal.value
    if value["active_operation"] is not None or any(operation["phase"] not in runtime.TERMINAL_PHASES or operation.get("protection", {}).get("hold_owned") or operation.get("apply", {}).get("hold_owned") for operation in value["operations"].values()):
        refuse("operation_busy")
    allowed = {"journal.json", "helper.lock", LOCK.name, TRANSACTION.name, STOP.name, RECEIPTS.name, "apply", "protection"}
    if {path.name for path in STATE.iterdir()} - allowed:
        refuse("partial_state")
    retained = {}
    for kind in ("apply", "protection"):
        parent = STATE / kind
        if not exists(parent):
            continue
        trusted(parent, directory=True)
        for directory in parent.iterdir():
            trusted(directory, directory=True)
            operation = value["operations"].get(directory.name)
            if operation is None or kind not in operation:
                refuse("unowned_recovery")
            module = runtime_from((private_code or CODE) / ("update_apply.py" if kind == "apply" else "update_protection.py"))
            if kind == "apply":
                # Older terminal applies legitimately record older image and
                # overlay bindings. Validate those records without asking the
                # old executor to adopt today's files or execute any recovery.
                state = runtime.read_object(directory / "state.json", 1_000_000, "recovery_required")
                historical = historical_configuration(runtime, config, state, operation["phase"])
                module.Applier(historical, journal, STATE, runtime).load(directory, directory.name)
            else:
                module.Protector(config, journal, STATE, runtime).load_context(directory, directory.name)
            for path in sorted(directory.rglob("*")):
                if path.is_dir() and not path.is_symlink():
                    retained[str(path)] = {"identity": identity(path, directory=True)}
                else:
                    trusted(path)
                    with path.open("rb") as contents:
                        checksum = hashlib.file_digest(contents, "sha256").hexdigest()
                    retained[str(path)] = {"identity": identity(path), "sha256": checksum}
    return {key: value[key] for key in ("schema", "installation_id", "revision", "active_operation", "operations", "last_operation")}, retained, value["generation"]


def historical_configuration(runtime, current, state, phase):
    configurations = {}
    for name in ("old_config", "new_config"):
        value = state.get(name)
        if value is None and name == "new_config" and phase in {"failed_safe", "cancelled"}:
            continue
        if not isinstance(value, dict):
            refuse("unowned_recovery")
        recorded = runtime.Configuration(value, current.token)
        if {key: item for key, item in recorded.value.items() if key not in {"image_reference", "overlay_sha256"}} != {key: item for key, item in current.value.items() if key not in {"image_reference", "overlay_sha256"}}:
            refuse("unowned_recovery")
        configurations[name] = recorded
    selected = "new_config" if phase == "succeeded" else "old_config"
    if selected not in configurations:
        refuse("unowned_recovery")
    return configurations[selected]


def exchange(left, right):
    library = ctypes.CDLL(None, use_errno=True)
    rename = library.renameat2
    rename.argtypes = (ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint)
    rename.restype = ctypes.c_int
    if rename(-100, os.fsencode(left), -100, os.fsencode(right), 2) != 0:
        refuse("atomic_exchange_failed")
    sync(left.parent)


def authenticate(runtime, config, *, expected_pid=None):
    deadline = time.monotonic() + 10
    while time.monotonic() < deadline:
        try:
            runtime.trusted(SOCKET, socket_node=True)
            nonce = secrets.token_hex(16)
            request = {"protocol": 1, "installation_id": config.installation_id, "nonce": nonce,
                       "issued_at": int(time.time()), "action": "capabilities"}
            with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:
                connection.settimeout(0.25)
                connection.connect(str(SOCKET))
                peer_pid, peer_uid, _ = struct.unpack("3i", connection.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
                if peer_uid != 0 or (expected_pid is not None and peer_pid != expected_pid):
                    refuse("startup_unverified")
                connection.sendall(runtime.envelope(request, config.token, "request"))
                raw = bytearray()
                while not raw.endswith(b"\n"):
                    chunk = connection.recv(min(4096, runtime.RESPONSE_MAX + 1 - len(raw)))
                    if not chunk or len(raw) + len(chunk) > runtime.RESPONSE_MAX or b"\n" in chunk[:-1]:
                        refuse("startup_unverified")
                    raw.extend(chunk)
            response = runtime.unpack_envelope(bytes(raw), config.token, "response")
            expected = {"protocol": 1, "installation_id": config.installation_id, "nonce": nonce, "ok": True, "result": config.capabilities()}
            if response != expected or response["result"]["helper"]["version"] != VERSION:
                refuse("startup_unverified")
            return
        except (OSError, runtime.Refusal):
            time.sleep(0.1)
    refuse("startup_unverified")


def running_helper():
    current = service()
    if (current["ActiveState"] != "active" or current["SubState"] != "running"
            or not re.fullmatch(r"[1-9][0-9]*", current["MainPID"])
            or current["ControlGroup"] != "/system.slice/" + SERVICE
            or current["DropInPaths"] != str(DROPIN)
            or current["FreezerState"] not in {"running", "frozen"}
            or not condition_loaded()):
        refuse("startup_unverified")
    process = PROC / current["MainPID"]
    for path in (process / "cmdline", process / "cgroup", CGROUP / "cgroup.freeze", CGROUP / "cgroup.events"):
        trusted(path)
    if ((process / "cmdline").read_bytes() != b"/usr/bin/python3\0/usr/local/lib/wayfindr-updater/updater.py\0serve\0"
            or (process / "cgroup").read_text() != "0::/system.slice/" + SERVICE + "\n"
            or (CGROUP / "cgroup.freeze").read_text() != "0\n"
            or events().get("frozen") != "0" or events().get("populated") != "1"):
        refuse("startup_unverified")
    return current


def authenticate_started(runtime, config):
    # Killing the frozen old cgroup can leave systemd's cached FreezerState
    # frozen after a new, unfrozen cgroup starts. Normalize only the authenticated
    # new process while the transaction still blocks application mutations.
    before = running_helper()
    authenticate(runtime, config, expected_pid=int(before["MainPID"]))
    if before["FreezerState"] == "frozen":
        run("thaw", SERVICE)
    after = running_helper()
    if (after["FreezerState"] != "running"
            or {key: value for key, value in before.items() if key != "FreezerState"}
            != {key: value for key, value in after.items() if key != "FreezerState"}):
        refuse("startup_unverified")
    authenticate(runtime, config, expected_pid=int(after["MainPID"]))


def stop_idle(runtime, config, record, *, private_code=None, recovering=False):
    current = service()
    if current["ActiveState"] in {"inactive", "failed"} and current["MainPID"] == "0":
        if not cgroup_empty():
            refuse("helper_not_stopped")
    else:
        if current["ActiveState"] != "active" or current["ControlGroup"] != "/system.slice/" + SERVICE:
            refuse("service_changed")
        if recovering and current["FreezerState"] == "frozen" and events().get("frozen") == "0":
            # The old condition-only gate allowed automatic restart, leaving
            # systemd's cached freezer state stale for the new running cgroup.
            # Only validated explicit recovery normalizes an already-unfrozen
            # group before requesting and proving a fresh freeze.
            run("thaw", SERVICE)
            if service()["FreezerState"] != "running" or events().get("frozen") != "0":
                refuse("freeze_unverified")
        run("freeze", SERVICE)
        frozen = False
        killed = False
        try:
            frozen = service()["FreezerState"] == "frozen" and events().get("frozen") == "1"
            if not frozen:
                refuse("freeze_unverified")
            try:
                history, retained, generation = idle(runtime, config, private_code=private_code)
            except UpgradeError as failure:
                if str(failure) == "operation_busy" and record["stage"] == "prepared" and code_hashes(CODE) == PUBLISHED:
                    # An accepted Start won the race. Keep that complete old
                    # generation running and withdraw only this owned intent.
                    unlink(STOP)
                    unlink(TRANSACTION)
                raise
            verify_snapshot(config, record["preserved"])
            if record["history"] is not None and (history != record["history"] or retained != record["retained"]):
                refuse("retained_state_changed")
            if record["history"] is None:
                record.update(history=history, retained=retained, previous_generation=generation)
            if record["stage"] == "prepared":
                record["stage"] = "frozen_idle"
            store(record)
            # cgroup.kill kills the frozen group atomically; systemctl stop first
            # would thaw it and allow a pending legacy Start to run.
            with (CGROUP / "cgroup.kill").open("w") as output:
                output.write("1\n")
            killed = True
            deadline = time.monotonic() + 10
            while not cgroup_empty():
                if time.monotonic() >= deadline:
                    refuse("helper_not_stopped")
                time.sleep(0.05)
            run("stop", SERVICE)
        finally:
            if not killed:
                run("thaw", SERVICE)
    current = service()
    if current["MainPID"] != "0" or current["ActiveState"] not in {"inactive", "failed"} or not cgroup_empty():
        refuse("helper_not_stopped")
    return runtime.HostLock(STATE / "helper.lock")


def validate_record(record, selected_hash, new):
    expected = {"schema", "transaction_id", "installation_id", "bundle_sha256", "old", "new", "preserved", "stage", "history", "retained", "previous_generation", "verified_generation", "code_directory_mode"}
    if set(record) != expected or type(record["schema"]) is not int or record["schema"] != 1 or record["old"] != PUBLISHED or record["new"] != new or record["bundle_sha256"] != selected_hash or record["stage"] not in {"prepared", "frozen_idle", "staging", "switched", "starting", "verified"}:
        refuse("transaction_changed")
    try:
        if str(uuid.UUID(record["transaction_id"])) != record["transaction_id"] or str(uuid.UUID(record["installation_id"])) != record["installation_id"]:
            refuse("transaction_changed")
    except (ValueError, TypeError, AttributeError):
        refuse("transaction_changed")
    if not isinstance(record["preserved"], dict) or not isinstance(record["retained"], dict) or (record["history"] is not None and not isinstance(record["history"], dict)):
        refuse("transaction_changed")
    if type(record["code_directory_mode"]) is not int or record["code_directory_mode"] not in CODE_MODES:
        refuse("transaction_changed")
    if record["verified_generation"] is not None:
        try:
            if str(uuid.UUID(record["verified_generation"])) != record["verified_generation"]:
                refuse("transaction_changed")
        except (ValueError, TypeError, AttributeError):
            refuse("transaction_changed")


def validate_generation(record, current, staged):
    for directory in (CODE, staged):
        if exists(directory) and stat.S_IMODE(directory.stat().st_mode) != record["code_directory_mode"]:
            refuse("transaction_changed")
    stage = record["stage"]
    if stage in {"prepared", "frozen_idle"}:
        if current != PUBLISHED or exists(staged):
            refuse("transaction_changed")
    elif stage in {"switched", "starting", "verified"}:
        if current != record["new"] or code_hashes(staged) != PUBLISHED:
            refuse("transaction_changed")
    elif stage == "staging":
        if current == record["new"]:
            if code_hashes(staged) != PUBLISHED:
                refuse("transaction_changed")
        elif current != PUBLISHED:
            refuse("transaction_changed")


def finish(distribution, selected_hash, *, recovery=None):
    declaration, actual = bundle(distribution)
    if not HEX.fullmatch(selected_hash) or actual != selected_hash:
        refuse("distribution_changed")
    supported(recovering=recovery is not None)
    owned_gate(allow_legacy=recovery is not None)
    trusted(STATE, directory=True)
    trusted(CODE.parent, directory=True)
    current_hashes = code_hashes(CODE)
    if current_hashes not in (PUBLISHED, declaration["files"]):
        refuse("unsupported_installed_helper")
    if recovery is None and (exists(TRANSACTION) or exists(STOP) or exists(STATE / ".helper-upgrade.next") or exists(STATE / ".helper-upgrade-stop.next")):
        refuse("recovery_required")
    if recovery is None and current_hashes != PUBLISHED:
        refuse("unsupported_installed_helper")
    if exists(LOCK):
        private(LOCK)
    # The separate serialization lock never replaces the daemon's lifetime lock.
    descriptor = os.open(LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    os.fchmod(descriptor, 0o600)
    os.fchown(descriptor, 0, 0)
    try:
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            refuse("operation_busy")
        # Another CLI may have completed between the read-only preflight and
        # this serialization lock. Never act on that stale source observation.
        if code_hashes(CODE) != current_hashes:
            refuse("installation_changed")
        if recovery is None and (exists(TRANSACTION) or exists(STOP) or exists(STATE / ".helper-upgrade.next") or exists(STATE / ".helper-upgrade-stop.next")):
            refuse("recovery_required")
        runtime = runtime_from(CODE / "updater.py")
        config = enrollment(runtime, distribution)
        if recovery is None:
            history, retained, generation = idle(runtime, config)
            record = {"schema": 1, "transaction_id": str(uuid.uuid4()), "installation_id": config.installation_id,
                      "bundle_sha256": actual, "old": PUBLISHED, "new": declaration["files"], "preserved": snapshot(config),
                      "code_directory_mode": stat.S_IMODE(CODE.stat().st_mode),
                      "stage": "prepared", "history": None, "retained": {}, "previous_generation": generation, "verified_generation": None}
            gate_install()
            store(record)
            ensure_stop(record["transaction_id"])
        else:
            if not exists(TRANSACTION):
                temporary = STATE / ".helper-upgrade.next"
                private(temporary)
                pending = read_json(temporary)
                validate_record(pending, actual, declaration["files"])
                if pending["transaction_id"] != recovery or pending["installation_id"] != config.installation_id or pending["stage"] != "prepared" or current_hashes != PUBLISHED or pending["history"] is not None:
                    refuse("transaction_changed")
                verify_snapshot(config, pending["preserved"])
                os.replace(temporary, TRANSACTION)
                sync(STATE)
            private(TRANSACTION)
            record = read_json(TRANSACTION)
            validate_record(record, actual, declaration["files"])
            if record["transaction_id"] != recovery or record["installation_id"] != config.installation_id:
                refuse("transaction_changed")
            preserve_partial_write(recovery)
            if not exists(STOP):
                if current_hashes != record["new"]:
                    # A crash before the original stop-file write is safe only
                    # with the unchanged complete legacy code generation.
                    if record["stage"] != "prepared" or current_hashes != PUBLISHED:
                        refuse("transaction_changed")
            ensure_stop(recovery, recovering=True)
            if DROPIN.read_bytes() not in {GATE, LEGACY_GATE}:
                refuse("transaction_changed")
            verify_snapshot(config, record["preserved"])
        validate_record(record, actual, declaration["files"])
        if current_hashes not in (PUBLISHED, record["new"]):
            refuse("code_changed")
        staged = CODE.parent / (".wayfindr-updater-generation-" + record["transaction_id"])
        validate_generation(record, current_hashes, staged)
        if recovery is not None:
            gate_install(recovery=recovery)
        private_code = CODE
        if current_hashes == record["new"]:
            if code_hashes(staged) != PUBLISHED:
                refuse("code_changed")
            private_code = staged
        with stop_idle(runtime, config, record, private_code=private_code, recovering=recovery is not None):
            history, retained, generation = idle(runtime, config, private_code=private_code)
            if record["history"] is None:
                record.update(history=history, retained=retained, previous_generation=generation, stage="frozen_idle")
                store(record)
            if history != record["history"] or retained != record["retained"]:
                refuse("retained_state_changed")
            verify_snapshot(config, record["preserved"])
            if code_hashes(CODE) == PUBLISHED:
                record["stage"] = "staging"
                store(record)
                if not exists(staged):
                    # Create the exact recorded mode immediately: a crash
                    # between mkdir and chmod must not leave a different mode
                    # merely because this operator uses a restrictive umask.
                    previous_umask = os.umask(0)
                    try:
                        staged.mkdir(mode=record["code_directory_mode"])
                    finally:
                        os.umask(previous_umask)
                    os.chown(staged, 0, 0)
                    sync(staged.parent)
                partial = code_hashes(staged, partial=True)
                for name, checksum in list(partial.items()):
                    if checksum != record["new"][name]:
                        if recovery is None:
                            refuse("code_changed")
                        expected = (distribution / "scripts/self-host" / name).read_bytes()
                        if digest(expected) != record["new"][name]:
                            refuse("distribution_changed")
                        preserve_partial_file(staged / name, expected, record["transaction_id"], 0o644)
                        unlink(staged / name)
                        del partial[name]
                for name in FILES:
                    if name not in partial:
                        create(staged / name, (distribution / "scripts/self-host" / name).read_bytes(), 0o644)
                if code_hashes(staged) != record["new"]:
                    refuse("distribution_changed")
                exchange(CODE, staged)
            if code_hashes(CODE) != record["new"] or code_hashes(staged) != PUBLISHED:
                refuse("code_changed")
            validate_generation(record, record["new"], staged)
            record["stage"] = "switched"
            store(record)
            new_runtime = runtime_from(CODE / "updater.py")
            new_config = enrollment(new_runtime, distribution)
            if idle(new_runtime, new_config, private_code=staged)[:2] != (record["history"], record["retained"]):
                refuse("retained_state_changed")
            verify_snapshot(new_config, record["preserved"])
            record["stage"] = "starting"
            store(record)
            unlink(STOP)
        # New0.5 may run, but its persistent admission barrier blocks all writes
        # until authenticated startup and preservation have both been verified.
        run("start", SERVICE)
        authenticate_started(new_runtime, new_config)
        history, retained, generation = idle(new_runtime, new_config, private_code=staged)
        if history != record["history"] or retained != record["retained"] or generation == record["previous_generation"] or not new_runtime.is_uuid(generation):
            refuse("startup_unverified")
        verify_snapshot(new_config, record["preserved"])
        if code_hashes(CODE) != record["new"] or code_hashes(staged) != PUBLISHED:
            refuse("code_changed")
        validate_generation(record, record["new"], staged)
        record["stage"] = "verified"
        if record["verified_generation"] is None:
            record["verified_generation"] = generation
        store(record)
        if not exists(RECEIPTS):
            RECEIPTS.mkdir(mode=0o700)
            sync(STATE)
        trusted(RECEIPTS, directory=True)
        receipt = RECEIPTS / (record["transaction_id"] + ".json")
        result = {"schema": 1, "transaction_id": record["transaction_id"], "installation_id": config.installation_id,
                  "from_helper_version": "0.4.0", "helper_version": VERSION, "protocol": 1,
                  "bundle_sha256": actual, "generation": record["verified_generation"], "preserved": True,
                  "application_changed": False, "retained_old_code": str(staged)}
        if exists(receipt):
            private(receipt)
            if receipt.read_bytes() != encoded(result):
                if recovery is None:
                    refuse("transaction_changed")
                preserve_partial_file(receipt, encoded(result), record["transaction_id"], 0o600)
                unlink(receipt)
                create(receipt, encoded(result))
        else:
            create(receipt, encoded(result))
        unlink(TRANSACTION)
        return result
    finally:
        os.close(descriptor)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser("inspect", help="Read the reviewed distribution's exact target bundle hash.")
    for name in ("upgrade", "recover"):
        command = commands.add_parser(name)
        command.add_argument("--expected-bundle-sha256", required=True)
        if name == "recover":
            command.add_argument("--transaction", required=True)
    options = parser.parse_args()
    distribution = Path(__file__).absolute().parents[2]
    try:
        if options.command == "inspect":
            declaration, checksum = bundle(distribution)
            result = {**declaration, "bundle_sha256": checksum}
        else:
            result = finish(distribution, options.expected_bundle_sha256, recovery=getattr(options, "transaction", None))
        print(json.dumps(result, sort_keys=True))
        return 0
    except Exception as failure:
        reason = str(failure) if isinstance(failure, UpgradeError) else "upgrade_unavailable"
        result = {"schema": 1, "status": "refused", "reason": reason,
                  "recovery_required": exists(TRANSACTION) or exists(STATE / ".helper-upgrade.next")}
        for path in (TRANSACTION, STATE / ".helper-upgrade.next"):
            if exists(path):
                try:
                    transaction_id = read_json(path)["transaction_id"]
                    if str(uuid.UUID(transaction_id)) == transaction_id:
                        result["transaction_id"] = transaction_id
                except Exception:
                    pass
                break
        print(json.dumps(result, sort_keys=True), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
