#!/usr/bin/env python3
"""Helper lifecycle fixtures; never contact host systemd, Docker or /etc.

Filesystem writes, hashing, enrollment/journal validation and preservation are
real in isolated temporary directories. Systemd/freezer and Linux directory
exchange are modeled explicitly; a separate Linux VM must prove those APIs.
"""

from __future__ import annotations

import contextlib
import hashlib
import importlib.util
import io
import json
import os
import re
import sys
from pathlib import Path
import shutil
import stat
import tempfile
import types
import unittest
from unittest.mock import patch
import uuid


ROOT = Path(__file__).absolute().parents[1]
SPEC = importlib.util.spec_from_file_location("upgrade_updater", ROOT / "scripts/self-host/upgrade-updater.py")
UPGRADE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(UPGRADE)


class Crash(Exception):
    pass


class Host:
    def __init__(self, root, source_version="0.4.0"):
        self.source_version = source_version
        self.root = root
        self.distribution = root / "reviewed-distribution"
        self.install = root / "opt/wayfindr"
        self.state = root / "var/lib/wayfindr-updater"
        self.code = root / "usr/local/lib/wayfindr-updater"
        self.config = root / "etc/wayfindr-updater/installation.json"
        self.credential = self.config.with_name("credential.json")
        self.runtime = root / "run/wayfindr-updater"
        self.cgroup = root / "cgroup/system.slice/wayfindr-updater.service"
        self.proc = root / "proc"
        self.unit = root / "etc/systemd/system/wayfindr-updater.service"
        self.tmpfiles = root / "etc/tmpfiles.d/wayfindr-updater.conf"
        self.cli = root / "usr/local/bin/wayfindr-updater"
        self.dropin = self.unit.with_name(self.unit.name + ".d") / "10-helper-upgrade.conf"
        self.installation_id = str(uuid.uuid4())
        self.configuration_gid = 0
        self.commands = []
        self.running = True
        self.frozen = False
        self.stale_freezer = False
        self.stale_after_start = False
        self.thaw_works = True
        self.freeze_works = True
        self.condition_works = True
        self.kill_settles = True
        self.race = None
        self.kill_count = 0
        self.exchange_count = 0
        self.clock = 0.0
        self.waits = []
        self.on_sleep = None
        for path in (self.install, self.state, self.code, self.config.parent, self.runtime, self.cgroup, self.unit.parent, self.tmpfiles.parent, self.cli.parent, self.distribution / "scripts/self-host", self.distribution / "docker/self-hosting"):
            path.mkdir(parents=True, exist_ok=True)
        os.chmod(self.code, 0o755)
        os.chmod(self.state, 0o700)
        os.chmod(self.runtime, 0o750)
        for name in UPGRADE.FILES:
            raw = (ROOT / "scripts/self-host" / name).read_bytes()
            if name == "updater.py":
                old = re.sub(rb'VERSION = "[0-9.]+"', ('VERSION = "' + source_version + '"').encode(), raw, count=1)
                new = re.sub(rb'VERSION = "[0-9.]+"', ('VERSION = "' + UPGRADE.VERSION + '"').encode(), raw, count=1)
            else:
                old, new = raw, raw + b"\n# Synthetic reviewed helper generation.\n"
            self.write(self.code / name, old, 0o644)
            self.write(self.distribution / "scripts/self-host" / name, new, 0o644)
        self.old_hashes = {name: UPGRADE.digest((self.code / name).read_bytes()) for name in UPGRADE.FILES}
        template = (ROOT / "docker/self-hosting/wayfindr-updater.service").read_bytes()
        self.write(self.distribution / "docker/self-hosting/wayfindr-updater.service", template, 0o644)
        self.write(self.unit, template.replace(b"__WAYFINDR_INSTALL_DIR__", str(self.install).encode()), 0o644)
        self.write(self.tmpfiles, (ROOT / "docker/self-hosting/wayfindr-updater.conf").read_bytes(), 0o644)
        self.write(self.cli, UPGRADE.WRAPPER, 0o755)
        files = {"compose.yml": b"official compose\n", ".env": b"APP_KEY=private-fixture-key\n", "install.sh": b"reviewed old installer\n", "compose.updater.yml": b"owned overlay\n", ".updater-enrolled": (self.installation_id + "\n").encode()}
        for name, raw in files.items():
            self.write(self.install / name, raw, 0o600 if name in {".env", ".updater-enrolled"} else 0o644)
        value = {"schema": 1, "installation_id": self.installation_id, "install_dir": str(self.install),
                 "compose_project": "wayfindr-self-hosting", "client_uid": 1000, "client_gid": 1000,
                 "image_reference": "ghcr.io/adamgreenwell/wayfindr:1.2.0"}
        value.update({key: UPGRADE.digest(files[name]) for name, key in (("compose.yml", "compose_sha256"), (".env", "env_sha256"), ("install.sh", "installer_sha256"), ("compose.updater.yml", "overlay_sha256"))})
        self.write(self.config, json.dumps(value).encode(), 0o600)
        self.write(self.credential, json.dumps({"schema": 1, "installation_id": self.installation_id, "token": "a" * 64}).encode(), 0o440)
        self.write(self.state / "helper.lock", b"", 0o600)
        self.write(self.cgroup / "cgroup.kill", b"", 0o600)
        self.write(self.cgroup / "cgroup.freeze", b"0\n", 0o644)
        self.write(self.cgroup / "cgroup.events", b"populated 1\nfrozen 0\n", 0o644)
        (self.proc / "123").mkdir(parents=True)
        self.write(self.proc / "123/cmdline", b"/usr/bin/python3\0/usr/local/lib/wayfindr-updater/updater.py\0serve\0", 0o444)
        self.write(self.proc / "123/cgroup", ("0::/system.slice/" + UPGRADE.SERVICE + "\n").encode(), 0o444)
        self.write(self.proc / "123/stat", self.stat_bytes(), 0o444)
        self.original_runtime_from = UPGRADE.runtime_from
        self.original_authenticate = UPGRADE.authenticate
        self.original_run = UPGRADE.run
        self.original_condition_loaded = UPGRADE.condition_loaded
        runtime = self.runtime_from(self.code / "updater.py")
        value = runtime.initial_journal(self.installation_id)
        value["generation"] = str(uuid.uuid4())
        self.write(self.state / "journal.json", json.dumps(value).encode(), 0o600)

    @staticmethod
    def write(path, raw, mode):
        if path.exists() and mode == 0o444:
            # Model kernel-owned proc observations changing without asking
            # the fixture's unprivileged test process to write a0444 file.
            os.chmod(path, 0o600)
        path.write_bytes(raw)
        os.chmod(path, mode)

    @staticmethod
    def stat_bytes(pid=123, start=456, comm=b"python3"):
        return str(pid).encode() + b" (" + comm + b") S " + b"0 " * 18 + str(start).encode() + b" 0 0\n"

    def sleep(self, duration):
        self.clock += duration
        self.waits.append(duration)
        if self.on_sleep:
            self.on_sleep()

    def trust(self, path, *, directory=False):
        if not path.is_absolute() or ".." in path.parts:
            UPGRADE.refuse("untrusted_path")
        for entry in [path, *path.parents]:
            if entry.is_symlink():
                UPGRADE.refuse("untrusted_path")
            if entry == self.root:
                break
        info = path.stat()
        if not (stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode)) or info.st_mode & 0o022:
            UPGRADE.refuse("untrusted_path")

    def private(self, path, mode=0o600, gid=0):
        self.trust(path)
        if stat.S_IMODE(path.stat().st_mode) != mode or path.stat().st_nlink != 1:
            UPGRADE.refuse("untrusted_metadata")

    def identity(self, path, *, directory=False):
        self.trust(path, directory=directory)
        info = path.stat()
        group = self.configuration_gid if path == self.config else 1000 if path in {self.runtime, self.state, self.state / "helper.lock"} else 0
        return [info.st_dev, info.st_ino, 0, group, stat.S_IMODE(info.st_mode)]

    def runtime_from(self, path):
        runtime = self.original_runtime_from(path)
        if path.name == "updater.py":
            def trusted(item, *, directory=False, socket_node=False):
                self.trust(item, directory=directory)
            runtime.trusted = trusted
            runtime.STATE_DIR = self.state
        return runtime

    def service(self, **_options):
        return {"ActiveState": "active" if self.running else "inactive", "SubState": "running" if self.running else "dead",
                "MainPID": "123" if self.running else "0", "ControlGroup": "/system.slice/" + UPGRADE.SERVICE,
                "FragmentPath": str(self.unit), "DropInPaths": str(self.dropin) if self.dropin.exists() else "",
                "FreezerState": "frozen" if self.frozen or self.stale_freezer else "running", "Restart": "on-failure"}

    def condition_loaded(self, **_options):
        return self.condition_works and self.dropin.exists() and self.dropin.read_bytes() == UPGRADE.GATE

    def events(self):
        if self.running and self.kill_settles and (self.cgroup / "cgroup.kill").read_bytes() == b"1\n":
            self.kill_count += 1
            self.running = False
        return {"frozen": "1" if self.frozen else "0", "populated": "1" if self.running else "0"}

    def run(self, *arguments, **_options):
        self.commands.append(arguments)
        if arguments[0] == "show":
            return "\n".join(key + "=" + value for key, value in self.service().items())
        if arguments[0] == "freeze":
            if self.race:
                self.race()
            self.frozen = self.freeze_works
            self.write(self.cgroup / "cgroup.freeze", b"1\n" if self.frozen else b"0\n", 0o644)
        elif arguments[0] == "thaw":
            if self.thaw_works:
                self.frozen = False
                self.stale_freezer = False
                self.write(self.cgroup / "cgroup.freeze", b"0\n", 0o644)
        elif arguments[0] == "stop":
            assert not self.running, "The cgroup must be observed empty before stop can auto-thaw."
            self.running = False
            self.frozen = False
            if self.cgroup.exists():
                self.write(self.cgroup / "cgroup.freeze", b"0\n", 0o644)
                self.write(self.cgroup / "cgroup.kill", b"", 0o600)
        elif arguments[0] == "start":
            assert not UPGRADE.STOP.exists(), "Persistent stop gate must be cleared only after complete code exchange."
            assert UPGRADE.TRANSACTION.exists(), "Admission barrier must remain until new startup is authenticated."
            self.running = True
            self.stale_freezer = self.stale_after_start
            self.cgroup.mkdir(parents=True, exist_ok=True)
            self.write(self.cgroup / "cgroup.freeze", b"0\n", 0o644)
            self.write(self.cgroup / "cgroup.events", b"populated 1\nfrozen 0\n", 0o644)
            self.write(self.cgroup / "cgroup.kill", b"", 0o600)
            runtime = self.runtime_from(self.code / "updater.py")
            journal = runtime.Journal(self.state / "journal.json", self.installation_id)
            journal.begin_generation(str(uuid.uuid4()))
        elif arguments[0] != "daemon-reload":
            raise AssertionError("Unexpected systemd command")
        return ""

    def exchange(self, left, right):
        assert not self.running, "No module may be replaced while the old daemon can lazily import."
        assert UPGRADE.STOP.exists() and UPGRADE.TRANSACTION.exists()
        self.exchange_count += 1
        middle = left.with_name("fixture-only-exchange-temporary")
        os.rename(left, middle)
        os.rename(right, left)
        os.rename(middle, right)

    def authenticate(self, runtime, config, *, expected_pid=None):
        assert self.running
        assert UPGRADE.TRANSACTION.exists()
        assert runtime.VERSION == UPGRADE.VERSION
        assert config.installation_id == self.installation_id
        assert expected_pid in {None, 123}
        self.commands.append(("authenticate", expected_pid))

    @contextlib.contextmanager
    def activated(self):
        paths = {"CODE": self.code, "STATE": self.state, "CONFIG": self.config, "CREDENTIAL": self.credential,
                 "CLI": self.cli, "UNIT": self.unit, "TMPFILES": self.tmpfiles, "RUNTIME": self.runtime,
                 "SOCKET": self.runtime / "updater.sock", "TRANSACTION": self.state / "helper-upgrade.json",
                 "STOP": self.state / "helper-upgrade-stop", "LOCK": self.state / "helper-upgrade.lock",
                 "RECEIPTS": self.state / "helper-upgrades", "DROPIN": self.dropin, "CGROUP": self.cgroup,
                 "PROC": self.proc}
        paths["GATE_TEMP"] = self.dropin.with_name(".10-helper-upgrade.next")
        # All production calls remain real except host ownership identities,
        # privileged APIs and directory exchange unavailable on macOS.
        with contextlib.ExitStack() as stack:
            for name, value in paths.items():
                stack.enter_context(patch.object(UPGRADE, name, value))
            stack.enter_context(patch.object(UPGRADE, "PUBLISHED", self.old_hashes if UPGRADE.VERSION == "0.5.0" else {self.source_version: self.old_hashes}))
            stack.enter_context(patch.object(UPGRADE, "supported"))
            stack.enter_context(patch.object(UPGRADE, "trusted", self.trust))
            stack.enter_context(patch.object(UPGRADE, "private", self.private))
            stack.enter_context(patch.object(UPGRADE, "identity", self.identity))
            stack.enter_context(patch.object(UPGRADE, "runtime_from", self.runtime_from))
            stack.enter_context(patch.object(UPGRADE, "run", self.run))
            stack.enter_context(patch.object(UPGRADE, "events", self.events))
            stack.enter_context(patch.object(UPGRADE, "condition_loaded", self.condition_loaded))
            stack.enter_context(patch.object(UPGRADE, "exchange", self.exchange))
            stack.enter_context(patch.object(UPGRADE, "authenticate", self.authenticate))
            stack.enter_context(patch.object(UPGRADE.time, "monotonic", lambda: self.clock))
            stack.enter_context(patch.object(UPGRADE.time, "sleep", self.sleep))
            stack.enter_context(patch.object(UPGRADE.os, "chown"))
            stack.enter_context(patch.object(UPGRADE.os, "fchown"))
            # Real code_hashes additionally enforces gid0; fixture owner group is
            # deliberately host-dependent, while its complete byte/mode/set
            # checks are kept rather than mocked.
            original_stat = Path.stat
            def metadata(path, *args, **kwargs):
                info = original_stat(path, *args, **kwargs)
                if path == self.code or path.name.startswith(".wayfindr-updater-generation-") or path == self.config:
                    fields = list(info)
                    fields[5] = self.configuration_gid if path == self.config else 0
                    return os.stat_result(fields)
                return info
            stack.enter_context(patch.object(Path, "stat", metadata))
            yield self

    def selected(self):
        return UPGRADE.bundle(self.distribution)[1]

    def upgrade(self):
        return UPGRADE.finish(self.distribution, self.selected())

    def recover(self):
        marker = UPGRADE.TRANSACTION if UPGRADE.TRANSACTION.exists() else self.state / ".helper-upgrade.next"
        record = UPGRADE.read_json(marker)
        return UPGRADE.finish(self.distribution, self.selected(), recovery=record["transaction_id"])


class UpgradeTests(unittest.TestCase):
    source_version = "0.4.0"
    @contextlib.contextmanager
    def host(self):
        with tempfile.TemporaryDirectory(prefix="wayfindr-upgrade-fixture-") as directory:
            fixture = Host(Path(directory).resolve(), self.source_version)
            with fixture.activated():
                yield fixture

    def test_old_source_is_replaced_as_one_complete_generation_preserving_enrollment_and_locks(self):
        with self.host() as host:
            runtime = host.runtime_from(host.code / "updater.py")
            config = UPGRADE.enrollment(runtime, host.distribution)
            before = UPGRADE.snapshot(config)
            old_journal = UPGRADE.read_json(host.state / "journal.json")
            result = host.upgrade()
            self.assertEqual("0.6.0", result["helper_version"])
            self.assertEqual(self.source_version, result["from_helper_version"])
            self.assertEqual(host.installation_id, result["installation_id"])
            self.assertFalse(result["application_changed"])
            self.assertTrue(result["preserved"])
            self.assertEqual(before, UPGRADE.snapshot(config))
            self.assertEqual(1, host.exchange_count)
            self.assertEqual(1, host.kill_count)
            self.assertEqual(host.old_hashes, UPGRADE.code_hashes(Path(result["retained_old_code"])))
            self.assertEqual(UPGRADE.bundle(host.distribution)[0]["files"], UPGRADE.code_hashes(host.code))
            after = UPGRADE.read_json(host.state / "journal.json")
            for name in old_journal.keys() - {"generation", "heartbeat_at"}:
                self.assertEqual(old_journal[name], after[name], name)
            self.assertNotEqual(old_journal["generation"], after["generation"])
            self.assertFalse(UPGRADE.TRANSACTION.exists())
            self.assertFalse(UPGRADE.STOP.exists())
            self.assertTrue(host.dropin.exists())
            self.assertTrue(host.running)

    def test_exact_target_bundle_hash_refuses_before_any_host_mutation(self):
        with self.host() as host:
            with self.assertRaisesRegex(UPGRADE.UpgradeError, "distribution_changed"):
                UPGRADE.finish(host.distribution, "a" * 64)
            self.assertEqual([], host.commands)
            self.assertFalse(UPGRADE.LOCK.exists())

    def test_completed_upgrade_normalizes_stale_manager_cache_before_clearing_admission(self):
        with self.host() as host:
            host.stale_after_start = True
            result = host.upgrade()
            self.assertTrue(result["preserved"])
            self.assertEqual("running", host.service()["FreezerState"])
            self.assertEqual("0", host.events()["frozen"])
            self.assertEqual(b"0\n", (host.cgroup / "cgroup.freeze").read_bytes())
            start = host.commands.index(("start", UPGRADE.SERVICE))
            self.assertEqual([("start", UPGRADE.SERVICE), ("authenticate", 123),
                              ("thaw", UPGRADE.SERVICE), ("authenticate", 123),
                              ("authenticate", 123), ("authenticate", 123)],
                             [command for command in host.commands[start:] if command[0] != "show"])
            self.assertFalse(UPGRADE.TRANSACTION.exists())

    def test_unverified_manager_thaw_retains_transaction_without_success_receipt(self):
        with self.host() as host:
            host.stale_after_start = True
            host.thaw_works = False
            with self.assertRaisesRegex(UPGRADE.UpgradeError, "startup_unverified"):
                host.upgrade()
            self.assertEqual("starting", UPGRADE.read_json(UPGRADE.TRANSACTION)["stage"])
            self.assertFalse(UPGRADE.RECEIPTS.exists())
            self.assertEqual(1, host.commands.count(("thaw", UPGRADE.SERVICE)))
            host.thaw_works = True
            self.assertTrue(host.recover()["preserved"])

    def test_new_helper_kernel_freeze_or_wrong_process_never_triggers_normalization(self):
        for changed in ("kernel_freeze", "kernel_events", "empty_group", "cmdline", "cgroup"):
            with self.subTest(changed=changed), self.host() as host:
                host.stale_after_start = True
                run = host.run
                def change_after_start(*arguments, **options):
                    result = run(*arguments, **options)
                    if arguments[0] == "start":
                        if changed == "kernel_freeze":
                            host.write(host.cgroup / "cgroup.freeze", b"1\n", 0o644)
                        elif changed in {"cmdline", "cgroup"}:
                            path = host.proc / "123" / changed
                            os.chmod(path, 0o644)
                            host.write(path, b"different process\n", 0o444)
                    return result
                events = host.events
                def changed_events():
                    value = events()
                    if host.stale_freezer:
                        if changed == "kernel_events":
                            value["frozen"] = "1"
                        elif changed == "empty_group":
                            value["populated"] = "0"
                    return value
                with patch.object(UPGRADE, "run", change_after_start), patch.object(UPGRADE, "events", changed_events):
                    with self.assertRaisesRegex(UPGRADE.UpgradeError, "startup_unverified"):
                        host.upgrade()
                self.assertNotIn(("thaw", UPGRADE.SERVICE), host.commands)
                self.assertTrue(UPGRADE.TRANSACTION.exists())
                self.assertFalse(UPGRADE.RECEIPTS.exists())

    def test_new_helper_pid_change_during_thaw_retains_admission_barrier(self):
        with self.host() as host:
            host.stale_after_start = True
            (host.proc / "124").mkdir()
            for name in ("cmdline", "cgroup", "stat"):
                host.write(host.proc / "124" / name, (host.proc / "123" / name).read_bytes(), 0o444)
            host.write(host.proc / "124/stat", host.stat_bytes(pid=124), 0o444)
            service = host.service
            def changed_service(**_options):
                value = service()
                if ("thaw", UPGRADE.SERVICE) in host.commands:
                    value["MainPID"] = "124"
                return value
            with patch.object(UPGRADE, "service", changed_service):
                with self.assertRaisesRegex(UPGRADE.UpgradeError, "startup_unverified"):
                    host.upgrade()
            self.assertEqual(1, host.commands.count(("authenticate", 123)))
            self.assertTrue(UPGRADE.TRANSACTION.exists())
            self.assertFalse(UPGRADE.RECEIPTS.exists())

    def test_authentication_refuses_root_socket_from_a_different_service_pid(self):
        with self.host() as host:
            runtime = host.runtime_from(host.code / "updater.py")
            runtime.VERSION = UPGRADE.VERSION
            config = UPGRADE.enrollment(runtime, host.distribution)
            nonce = "b" * 32
            with patch.object(runtime, "trusted"), patch.object(UPGRADE.secrets, "token_hex", return_value=nonce), \
                    patch.object(UPGRADE.socket, "SO_PEERCRED", 17, create=True), \
                    patch.object(UPGRADE.socket, "socket") as socket_factory:
                connection = socket_factory.return_value.__enter__.return_value
                connection.getsockopt.return_value = UPGRADE.struct.pack("3i", 124, 0, 0)
                connection.recv.return_value = runtime.envelope({"protocol": 1, "installation_id": config.installation_id,
                    "nonce": nonce, "ok": True, "result": config.capabilities()}, config.token, "response")
                with self.assertRaisesRegex(UPGRADE.UpgradeError, "startup_unverified"):
                    host.original_authenticate(runtime, config, expected_pid=123)

    def test_startup_waits_for_same_process_to_exec_before_authentication(self):
        with self.host() as host:
            canonical = (host.proc / "123/cmdline").read_bytes()
            run = host.run
            def pre_exec(*arguments, **options):
                result = run(*arguments, **options)
                if arguments[0] == "start":
                    host.write(host.proc / "123/cmdline", b"pre-exec systemd process\0", 0o444)
                return result
            def finish_exec():
                self.assertNotIn(("authenticate", 123), host.commands)
                if len(host.waits) == 3:
                    host.write(host.proc / "123/cmdline", canonical, 0o444)
            host.on_sleep = finish_exec
            with patch.object(UPGRADE, "run", pre_exec):
                try:
                    result = host.upgrade()
                except UPGRADE.UpgradeError:
                    self.fail("Same-PID pre-exec startup must wait for canonical readiness before authentication")
                self.assertTrue(result["preserved"])
            self.assertEqual(3, len(host.waits), "Pre-exec observations must be retried, not accepted immediately")
            self.assertEqual(1, host.commands.count(("start", UPGRADE.SERVICE)))

    def test_startup_waits_for_manager_pid_and_cgroup_population(self):
        with self.host() as host:
            service = host.service
            events = host.events
            def starting_manager(**_options):
                value = service()
                if ("start", UPGRADE.SERVICE) in host.commands and host.clock < 0.2:
                    value.update(ActiveState="activating", SubState="start", MainPID="0", ControlGroup="")
                return value
            def filling_group():
                value = events()
                if ("start", UPGRADE.SERVICE) in host.commands and host.clock < 0.4:
                    value["populated"] = "0"
                return value
            with patch.object(UPGRADE, "service", starting_manager), patch.object(UPGRADE, "events", filling_group):
                self.assertTrue(host.upgrade()["preserved"], "Manager/PID/kernel startup transitions must settle before authentication")
            self.assertGreaterEqual(host.clock, 0.4)
            self.assertNotIn(("thaw", UPGRADE.SERVICE), host.commands)

    def test_permanently_unready_startup_is_bounded_and_classified(self):
        for boundary in ("manager", "process", "kernel"):
            with self.subTest(boundary=boundary), self.host() as host:
                service, run, events = host.service, host.run, host.events
                def unready_manager(**_options):
                    value = service()
                    if boundary == "manager" and ("start", UPGRADE.SERVICE) in host.commands:
                        value.update(ActiveState="activating", SubState="start", MainPID="0")
                    return value
                def unready_process(*arguments, **options):
                    result = run(*arguments, **options)
                    if boundary == "process" and arguments[0] == "start":
                        host.write(host.proc / "123/cmdline", b"still pre-exec\0", 0o444)
                    return result
                def unready_kernel():
                    value = events()
                    if boundary == "kernel" and ("start", UPGRADE.SERVICE) in host.commands:
                        value["populated"] = "0"
                    return value
                with patch.object(UPGRADE, "service", unready_manager), patch.object(UPGRADE, "run", unready_process), \
                        patch.object(UPGRADE, "events", unready_kernel):
                    with self.assertRaises(UPGRADE.StartupError) as failure:
                        host.upgrade()
                self.assertEqual(boundary, failure.exception.stage)
                self.assertEqual("startup_unverified", str(failure.exception))
                self.assertAlmostEqual(10, host.clock, msg="Startup must have a finite monotonic deadline")
                self.assertEqual("starting", UPGRADE.read_json(UPGRADE.TRANSACTION)["stage"])
                self.assertNotIn(("authenticate", 123), host.commands)
                self.assertNotIn(("thaw", UPGRADE.SERVICE), host.commands)
                self.assertFalse(UPGRADE.RECEIPTS.exists())

    def test_readiness_wait_never_adopts_a_restarted_process(self):
        for reused_pid in (False, True):
            with self.subTest(reused_pid=reused_pid), self.host() as host:
                run, service = host.run, host.service
                (host.proc / "124").mkdir()
                for name in ("cmdline", "cgroup"):
                    host.write(host.proc / "124" / name, (host.proc / "123" / name).read_bytes(), 0o444)
                host.write(host.proc / "124/stat", host.stat_bytes(pid=124, start=789), 0o444)
                def pre_exec(*arguments, **options):
                    result = run(*arguments, **options)
                    if arguments[0] == "start":
                        host.write(host.proc / "123/cmdline", b"pre-exec\0", 0o444)
                    return result
                def restarted_manager(**_options):
                    value = service()
                    if host.waits and not reused_pid:
                        value["MainPID"] = "124"
                    return value
                def restart():
                    if reused_pid:
                        host.write(host.proc / "123/stat", host.stat_bytes(start=789), 0o444)
                        host.write(host.proc / "123/cmdline", (host.proc / "124/cmdline").read_bytes(), 0o444)
                host.on_sleep = restart
                with patch.object(UPGRADE, "run", pre_exec), patch.object(UPGRADE, "service", restarted_manager):
                    with self.assertRaises(UPGRADE.StartupError, msg="Readiness must never adopt a restarted or reused PID") as failure:
                        host.upgrade()
                self.assertEqual("process", failure.exception.stage)
                self.assertNotIn(("authenticate", 123), host.commands)
                self.assertNotIn(("thaw", UPGRADE.SERVICE), host.commands)
                self.assertFalse(UPGRADE.RECEIPTS.exists())
                self.assertTrue(UPGRADE.TRANSACTION.exists())

    def test_process_starttime_parser_handles_comm_boundaries_and_refuses_corruption(self):
        with self.host() as host:
            host.write(host.proc / "123/stat", host.stat_bytes(comm=b"python ) (worker) name"), 0o444)
            self.assertEqual(456, UPGRADE.process_start({"MainPID": "123"}))
            for raw in (b"123 (python3) S 0\n", host.stat_bytes(pid=124), host.stat_bytes(start=0),
                        host.stat_bytes(start="changed"), b"123 python3 S " + b"0 " * 20):
                with self.subTest(raw=raw):
                    host.write(host.proc / "123/stat", raw, 0o444)
                    with self.assertRaises(UPGRADE.StartupError) as failure:
                        UPGRADE.process_start({"MainPID": "123"})
                    self.assertEqual("process", failure.exception.stage)

    def test_same_pid_reuse_during_authentication_refuses_before_thaw(self):
        with self.host() as host:
            host.stale_after_start = True
            authenticate = host.authenticate
            def reused(*arguments, **keywords):
                authenticate(*arguments, **keywords)
                host.write(host.proc / "123/stat", host.stat_bytes(start=789), 0o444)
            with patch.object(UPGRADE, "authenticate", reused):
                with self.assertRaises(UPGRADE.StartupError) as failure:
                    host.upgrade()
            self.assertEqual("process", failure.exception.stage)
            self.assertNotIn(("thaw", UPGRADE.SERVICE), host.commands)
            self.assertTrue(UPGRADE.TRANSACTION.exists())
            self.assertFalse(UPGRADE.RECEIPTS.exists())

    def test_post_thaw_waits_for_manager_cache_on_the_authenticated_process(self):
        with self.host() as host:
            host.stale_after_start = True
            service = host.service
            def thawing_manager(**_options):
                value = service()
                if ("thaw", UPGRADE.SERVICE) in host.commands and len(host.waits) < 3:
                    value["FreezerState"] = "thawing"
                return value
            with patch.object(UPGRADE, "service", thawing_manager):
                try:
                    result = host.upgrade()
                except UPGRADE.UpgradeError:
                    self.fail("Authenticated thaw must wait for asynchronous manager completion")
                self.assertTrue(result["preserved"])
            self.assertEqual(3, len(host.waits))
            self.assertEqual(1, host.commands.count(("thaw", UPGRADE.SERVICE)))
            self.assertEqual(4, host.commands.count(("authenticate", 123)))

    def test_post_thaw_same_pid_reuse_or_kernel_mutation_never_completes(self):
        for changed in ("starttime", "kernel_freeze", "empty_group", "service"):
            with self.subTest(changed=changed), self.host() as host:
                host.stale_after_start = True
                run, service, events = host.run, host.service, host.events
                def changed_after_thaw(*arguments, **options):
                    result = run(*arguments, **options)
                    if arguments[0] == "thaw":
                        if changed == "starttime":
                            host.write(host.proc / "123/stat", host.stat_bytes(start=789), 0o444)
                        elif changed == "kernel_freeze":
                            host.write(host.cgroup / "cgroup.freeze", b"1\n", 0o644)
                    return result
                def changed_service(**_options):
                    value = service()
                    if changed == "service" and ("thaw", UPGRADE.SERVICE) in host.commands:
                        value["FragmentPath"] = "/unexpected.service"
                    return value
                def changed_events():
                    value = events()
                    if changed == "empty_group" and ("thaw", UPGRADE.SERVICE) in host.commands:
                        value["populated"] = "0"
                    return value
                with patch.object(UPGRADE, "run", changed_after_thaw), patch.object(UPGRADE, "service", changed_service), \
                        patch.object(UPGRADE, "events", changed_events):
                    with self.assertRaises(UPGRADE.StartupError):
                        host.upgrade()
                self.assertEqual(1, host.commands.count(("thaw", UPGRADE.SERVICE)))
                self.assertEqual(1, host.commands.count(("authenticate", 123)))
                self.assertTrue(UPGRADE.TRANSACTION.exists())
                self.assertFalse(UPGRADE.RECEIPTS.exists())

    def test_explicit_recovery_waits_for_same_process_thaw_before_fresh_freeze(self):
        with self.host() as host:
            host.stale_after_start = True
            with patch.object(UPGRADE, "authenticate", side_effect=UPGRADE.UpgradeError("startup_unverified")):
                with self.assertRaises(UPGRADE.UpgradeError):
                    host.upgrade()
            original = UPGRADE.read_json(UPGRADE.TRANSACTION)
            service = host.service
            def thawing_manager(**_options):
                value = service()
                if ("thaw", UPGRADE.SERVICE) in host.commands and len(host.waits) < 3:
                    value["FreezerState"] = "thawing"
                return value
            def before_freeze():
                self.assertEqual(1, host.commands.count(("freeze", UPGRADE.SERVICE)),
                                 "Explicit recovery must settle its observed process identity before a new freeze")
            host.on_sleep = before_freeze
            with patch.object(UPGRADE, "service", thawing_manager):
                try:
                    result = host.recover()
                except UPGRADE.UpgradeError:
                    self.fail("Explicit recovery must wait for asynchronous cached-freezer normalization")
            self.assertEqual(original["transaction_id"], result["transaction_id"])
            self.assertEqual(3, len(host.waits))
            self.assertEqual(1, host.exchange_count)

    def test_missing_stat_after_positive_manager_pid_never_adopts_a_later_process(self):
        with self.host() as host:
            run = host.run
            def vanished(*arguments, **options):
                result = run(*arguments, **options)
                if arguments[0] == "start":
                    (host.proc / "123/stat").unlink()
                return result
            with patch.object(UPGRADE, "run", vanished):
                with self.assertRaises(UPGRADE.StartupError) as failure:
                    host.upgrade()
            self.assertEqual("process", failure.exception.stage)
            self.assertEqual([], host.waits)
            self.assertNotIn(("thaw", UPGRADE.SERVICE), host.commands)
            self.assertTrue(UPGRADE.TRANSACTION.exists())

    def test_successful_probe_after_deadline_is_refused(self):
        with self.host() as host:
            host.dropin.parent.mkdir()
            host.write(host.dropin, UPGRADE.GATE, 0o644)
            condition = host.condition_loaded
            def slow_probe(**options):
                value = condition(**options)
                host.clock += 11
                return value
            with patch.object(UPGRADE, "condition_loaded", slow_probe):
                with self.assertRaises(UPGRADE.StartupError, msg="Successful readiness after its monotonic deadline must be refused"):
                    UPGRADE.wait_running_helper()
            self.assertNotIn(("thaw", UPGRADE.SERVICE), host.commands)

    def test_readiness_systemd_probes_share_remaining_monotonic_budget(self):
        with self.host() as host:
            current = host.service()
            current["DropInPaths"] = str(host.dropin)
            command = [str(UPGRADE.TEST), [str(UPGRADE.TEST), "!", "-e", str(UPGRADE.STOP)], False, *([0] * 7)]
            manager = types.SimpleNamespace(returncode=0, stdout="\n".join(key + "=" + value for key, value in current.items()))
            condition = types.SimpleNamespace(returncode=0, stdout=json.dumps({"type": "a(sasbttttuii)", "data": [command]}))
            # Both real read-only subprocess wrappers receive the same2.5s
            # remainder rather than their ordinary30s independent timeout.
            with patch.object(UPGRADE, "run", host.original_run), \
                    patch.object(UPGRADE.subprocess, "run", side_effect=[manager, condition]) as probe:
                self.assertEqual(current, UPGRADE.service(deadline=2.5))
                self.assertTrue(host.original_condition_loaded(deadline=2.5))
                self.assertEqual([2.5, 2.5], [call.kwargs["timeout"] for call in probe.call_args_list])

    def test_slow_byte_authentication_cannot_extend_monotonic_deadline(self):
        with self.host() as host:
            runtime = host.runtime_from(host.code / "updater.py")
            runtime.RESPONSE_MAX = 128
            config = UPGRADE.enrollment(runtime, host.distribution)
            with patch.object(runtime, "trusted"), patch.object(UPGRADE.socket, "SO_PEERCRED", 17, create=True), \
                    patch.object(UPGRADE.socket, "socket") as factory:
                connection = factory.return_value.__enter__.return_value
                connection.getsockopt.return_value = UPGRADE.struct.pack("3i", 123, 0, 0)
                def slow_byte(_maximum):
                    host.clock += 0.2
                    return b"x"
                connection.recv.side_effect = slow_byte
                with self.assertRaisesRegex(UPGRADE.UpgradeError, "startup_unverified"):
                    host.original_authenticate(runtime, config, expected_pid=123)
                self.assertLessEqual(connection.recv.call_count, 51, "Slow byte framing must stop at the original authentication deadline")
                self.assertLessEqual(host.clock, 10.2)
                self.assertLess(connection.settimeout.call_args.args[0], 0.25, "Final receive timeout must be bounded by remaining time")

    def test_readiness_probe_errors_have_a_fixed_manager_classification(self):
        for probe in ("service", "condition_loaded"):
            with self.subTest(probe=probe), self.host() as host:
                with patch.object(UPGRADE, probe, side_effect=UPGRADE.UpgradeError("systemd_unavailable")):
                    with self.assertRaises(UPGRADE.StartupError) as failure:
                        UPGRADE.wait_running_helper()
                self.assertEqual("manager", failure.exception.stage)
                self.assertEqual("startup_unverified", str(failure.exception))
                self.assertTrue(all(command[0] == "show" for command in host.commands))

    def test_operator_error_retains_reason_and_adds_only_fixed_startup_stage(self):
        with patch.object(UPGRADE.sys, "argv", ["upgrade-updater.py", "upgrade", "--expected-bundle-sha256", "a" * 64]), \
                patch.object(UPGRADE, "finish", side_effect=UPGRADE.StartupError("post_thaw")), \
                patch.object(UPGRADE, "exists", return_value=False), contextlib.redirect_stderr(io.StringIO()) as output:
            self.assertEqual(1, UPGRADE.main())
        self.assertEqual({"schema": 1, "status": "refused", "reason": "startup_unverified",
                          "startup_stage": "post_thaw", "recovery_required": False}, json.loads(output.getvalue()))

    def test_helper_death_or_replacement_during_preservation_cannot_commit_completion(self):
        for changed in ("dead", "pid", "reused_pid"):
            with self.subTest(changed=changed), self.host() as host:
                verify, service = UPGRADE.verify_snapshot, host.service
                replaced = False
                (host.proc / "124").mkdir()
                for name in ("cmdline", "cgroup"):
                    host.write(host.proc / "124" / name, (host.proc / "123" / name).read_bytes(), 0o444)
                host.write(host.proc / "124/stat", host.stat_bytes(pid=124, start=789), 0o444)
                def replacement_service(**options):
                    value = service(**options)
                    if replaced and changed == "pid":
                        value["MainPID"] = "124"
                    return value
                def die_after_authentication(*arguments):
                    nonlocal replaced
                    result = verify(*arguments)
                    if host.commands.count(("authenticate", 123)) == 2:
                        replaced = True
                        if changed == "dead":
                            host.running = False
                        elif changed == "reused_pid":
                            host.write(host.proc / "123/stat", host.stat_bytes(start=789), 0o444)
                    return result
                with patch.object(UPGRADE, "verify_snapshot", die_after_authentication), \
                        patch.object(UPGRADE, "service", replacement_service):
                    with self.assertRaises(UPGRADE.StartupError, msg="Completion must revalidate the authenticated process after disk preservation"):
                        host.upgrade()
                self.assertTrue(replaced)
                self.assertTrue(UPGRADE.TRANSACTION.exists(), "A dead or replaced helper must retain the admission barrier")
                self.assertEqual("starting", UPGRADE.read_json(UPGRADE.TRANSACTION)["stage"],
                                 "Preservation cannot publish success for an exited or replaced helper")
                self.assertFalse(UPGRADE.RECEIPTS.exists(), "Preservation cannot publish success for an exited or replaced helper")

    def test_helper_death_or_reuse_during_receipt_write_cannot_clear_barrier(self):
        for reused in (False, True):
            with self.subTest(reused=reused), self.host() as host:
                create = UPGRADE.create
                def die_during_receipt(path, raw, mode=0o600):
                    create(path, raw, mode)
                    if path.parent == UPGRADE.RECEIPTS:
                        if reused:
                            host.write(host.proc / "123/stat", host.stat_bytes(start=789), 0o444)
                        else:
                            host.running = False
                with patch.object(UPGRADE, "create", die_during_receipt):
                    with self.assertRaises(UPGRADE.StartupError, msg="Receipt fsync cannot clear the barrier after the authenticated helper exits or is reused"):
                        host.upgrade()
                record = UPGRADE.read_json(UPGRADE.TRANSACTION)
                self.assertEqual("verified", record["stage"])
                self.assertTrue(UPGRADE.TRANSACTION.exists(), "Explicit recovery must remain required after an unsettled receipt write")
                self.assertTrue((UPGRADE.RECEIPTS / (record["transaction_id"] + ".json")).exists(), "Retain the actual durable receipt alongside its pending transaction")

    def test_completion_requires_fresh_same_peer_authentication_at_each_commit_boundary(self):
        for refused_call in (3, 4):
            with self.subTest(refused_call=refused_call), self.host() as host:
                authenticate = host.authenticate
                calls = 0
                def wrong_peer(*arguments, **keywords):
                    nonlocal calls
                    calls += 1
                    if calls == refused_call:
                        UPGRADE.refuse("startup_unverified")
                    authenticate(*arguments, **keywords)
                with patch.object(UPGRADE, "authenticate", wrong_peer):
                    with self.assertRaises(UPGRADE.StartupError, msg="Each completion boundary requires fresh authentication of the exact verified peer") as failure:
                        host.upgrade()
                self.assertEqual("authentication", failure.exception.stage)
                self.assertEqual(refused_call, calls)
                self.assertTrue(UPGRADE.TRANSACTION.exists())
                self.assertEqual("starting" if refused_call == 3 else "verified", UPGRADE.read_json(UPGRADE.TRANSACTION)["stage"])

    def test_completion_late_success_cannot_renew_the_readiness_budget(self):
        with self.host() as host:
            def late_observation(**_options):
                host.clock += 11
                return host.service(), ("123", 456)
            with patch.object(UPGRADE, "running_helper", late_observation), \
                    patch.object(UPGRADE, "authenticate_identity", return_value=(host.service(), ("123", 456))) as authentication:
                with self.assertRaises(UPGRADE.StartupError, msg="Completion must reject a late successful observation before renewing authentication"):
                    UPGRADE.verify_completion(None, None, ("123", 456))
                authentication.assert_not_called()

    def test_real_enrollment_under_umask077_preserves_private_code_directory_through_upgrade(self):
        specification = importlib.util.spec_from_file_location("upgrade_enrollment_fixture", ROOT / "scripts/test_updater_enrollment.py")
        fixture = importlib.util.module_from_spec(specification)
        specification.loader.exec_module(fixture)
        enrollment = fixture.EnrollmentTests()
        with enrollment.synthetic_host() as (_, install, _, _, _):
            # Execute the actual enrollment function's mkdir, unchanged from
            # published0.4, under a real restrictive process umask. Its host
            # Docker/systemd calls alone are modeled by the enrollment fixture.
            previous_umask = os.umask(0o077)
            try:
                fixture.ENROLL.enroll(install)
            finally:
                os.umask(previous_umask)
            enrolled = fixture.ENROLL.CODE_DIR
            self.assertEqual(0o700, stat.S_IMODE(enrolled.stat().st_mode))
            with self.host() as host:
                source = {name: (host.code / name).read_bytes() for name in UPGRADE.FILES}
                shutil.rmtree(host.code)
                os.rename(enrolled, host.code)
                for name, raw in source.items():
                    host.write(host.code / name, raw, 0o644)
                try:
                    result = host.upgrade()
                except Exception as failure:
                    self.fail("Published enrollment under umask077 must upgrade its safe0700 directory: " + type(failure).__name__)
                self.assertEqual(0o700, stat.S_IMODE(host.code.stat().st_mode), "Replacement must preserve the enrollment-created directory mode")
                self.assertEqual(0o700, stat.S_IMODE(Path(result["retained_old_code"]).stat().st_mode))

    def test_supported_code_modes_are_preserved_across_interrupted_upgrade_recovery(self):
        for mode in (0o700, 0o750, 0o755):
            with self.subTest(mode=mode), self.host() as host:
                os.chmod(host.code, mode)
                store = UPGRADE.store
                def crash(record):
                    store(record)
                    if record["stage"] == "switched":
                        raise Crash()
                with patch.object(UPGRADE, "store", crash), self.assertRaises(Crash):
                    host.upgrade()
                record = UPGRADE.read_json(UPGRADE.TRANSACTION)
                self.assertEqual(mode, record["code_directory_mode"])
                result = host.recover()
                self.assertEqual(mode, stat.S_IMODE(host.code.stat().st_mode))
                self.assertEqual(mode, stat.S_IMODE(Path(result["retained_old_code"]).stat().st_mode))

    def test_staging_mkdir_crash_under_umask077_retains_the_recorded_mode_and_recovers(self):
        with self.host() as host:
            mkdir = Path.mkdir
            def crash(path, *arguments, **options):
                mkdir(path, *arguments, **options)
                if path.name.startswith(".wayfindr-updater-generation-"):
                    raise Crash()
            previous_umask = os.umask(0o077)
            try:
                with patch.object(Path, "mkdir", crash), self.assertRaises(Crash):
                    host.upgrade()
                record = UPGRADE.read_json(UPGRADE.TRANSACTION)
                staged = host.code.parent / (".wayfindr-updater-generation-" + record["transaction_id"])
                self.assertEqual(0o755, stat.S_IMODE(staged.stat().st_mode), "Staging mkdir must atomically preserve the recorded mode despite umask077")
                self.assertEqual(0o077, os.umask(0o077), "The CLI must restore the caller's umask even when staging is interrupted")
                self.assertTrue(host.recover()["preserved"])
            finally:
                os.umask(previous_umask)

    def test_staged_module_open_crash_under_umask077_creates_exact_mode_and_recovers(self):
        with self.host() as host:
            open_file = os.open
            interrupted = []
            def crash(path, *arguments, **options):
                descriptor = open_file(path, *arguments, **options)
                if Path(path).name == "updater.py" and Path(path).parent.name.startswith(".wayfindr-updater-generation-"):
                    interrupted.append(Path(path))
                    os.close(descriptor)  # SIGKILL would close the process's fd.
                    raise Crash()
                return descriptor
            previous_umask = os.umask(0o077)
            try:
                with patch.object(UPGRADE.os, "open", crash), self.assertRaises(Crash):
                    host.upgrade()
                self.assertEqual(1, len(interrupted))
                self.assertEqual(0o644, stat.S_IMODE(interrupted[0].stat().st_mode), "Exclusive module creation must immediately use0644 despite umask077")
                self.assertEqual(0o077, os.umask(0o077))
                self.assertTrue(host.recover()["preserved"])
            finally:
                os.umask(previous_umask)

    def test_unknown_code_directory_modes_refuse_before_host_mutation(self):
        for mode in (0o711, 0o744, 0o701, 0o1777, 0o2755):
            with self.subTest(mode=mode), self.host() as host:
                os.chmod(host.code, mode)
                with self.assertRaises(UPGRADE.UpgradeError):
                    host.upgrade()
                self.assertEqual([], host.commands)
                self.assertFalse(UPGRADE.LOCK.exists())

    def test_recovery_refuses_changed_supported_modes_in_either_generation(self):
        for generation in ("current", "retained"):
            with self.subTest(generation=generation), self.host() as host:
                with patch.object(UPGRADE, "authenticate", side_effect=Crash()), self.assertRaises(Crash):
                    host.upgrade()
                record = UPGRADE.read_json(UPGRADE.TRANSACTION)
                staged = host.code.parent / (".wayfindr-updater-generation-" + record["transaction_id"])
                os.chmod(host.code if generation == "current" else staged, 0o700)
                host.commands.clear()
                with self.assertRaisesRegex(UPGRADE.UpgradeError, "transaction_changed"):
                    host.recover()
                self.assertNotIn(("freeze", UPGRADE.SERVICE), host.commands)
                self.assertTrue(UPGRADE.TRANSACTION.exists())

    def test_unsupported_platform_api_refuses_before_lock_or_transaction_gate(self):
        with self.host() as host:
            with patch.object(UPGRADE, "supported", side_effect=UPGRADE.UpgradeError("host_unsupported")):
                with self.assertRaisesRegex(UPGRADE.UpgradeError, "host_unsupported"):
                    host.upgrade()
            self.assertEqual([], host.commands)
            self.assertFalse(UPGRADE.LOCK.exists())
            self.assertFalse(host.dropin.exists())
            self.assertFalse(UPGRADE.TRANSACTION.exists())

    def test_modified_installed_module_refuses_before_import_or_service_change(self):
        with self.host() as host:
            with (host.code / "updater.py").open("ab") as output:
                output.write(b"\nraise RuntimeError('must never execute')\n")
            with patch.object(UPGRADE, "runtime_from") as importer:
                with self.assertRaisesRegex(UPGRADE.UpgradeError, "unsupported_installed_helper"):
                    host.upgrade()
                importer.assert_not_called()
            self.assertEqual([], host.commands)
            self.assertFalse(UPGRADE.LOCK.exists())

    def test_symlink_extra_file_and_writable_module_refuse(self):
        for mutation in ("symlink", "extra", "writable"):
            with self.subTest(mutation=mutation), self.host() as host:
                path = host.code / "protection_archive.py"
                if mutation == "symlink":
                    path.unlink()
                    path.symlink_to(host.distribution / "scripts/self-host/protection_archive.py")
                elif mutation == "extra":
                    (host.code / "__pycache__").mkdir()
                else:
                    os.chmod(path, 0o666)
                with self.assertRaises(UPGRADE.UpgradeError):
                    host.upgrade()
                self.assertEqual([], host.commands)

    def test_wrong_controller_overlay_env_credential_marker_wrapper_unit_rule_refuse_before_freeze(self):
        for target in ("install.sh", "compose.updater.yml", ".env", ".updater-enrolled", "credential", "wrapper", "unit", "tmpfiles"):
            with self.subTest(target=target), self.host() as host:
                path = {"credential": host.credential, "wrapper": host.cli, "unit": host.unit, "tmpfiles": host.tmpfiles}.get(target, host.install / target)
                os.chmod(path, 0o600 if target == "credential" else stat.S_IMODE(path.stat().st_mode))
                with path.open("ab") as output:
                    output.write(b"changed")
                with self.assertRaises(Exception):
                    host.upgrade()
                self.assertNotIn(("freeze", UPGRADE.SERVICE), host.commands)
                self.assertEqual(0, host.exchange_count)

    def test_active_before_preflight_refuses_without_freeze(self):
        with self.host() as host:
            runtime = host.runtime_from(host.code / "updater.py")
            journal = runtime.Journal(host.state / "journal.json", host.installation_id)
            journal.accept(str(uuid.uuid4()), "v1.3.0", journal.value["generation"])
            with self.assertRaisesRegex(UPGRADE.UpgradeError, "operation_busy"):
                host.upgrade()
            self.assertEqual([], host.commands)
            self.assertTrue(host.running)

    def test_interrupted_application_refuses_without_recovery_or_helper_gate_mutation(self):
        with self.host() as host:
            runtime = host.runtime_from(host.code / "updater.py")
            journal = runtime.Journal(host.state / "journal.json", host.installation_id)
            operation = journal.accept(str(uuid.uuid4()), "v1.3.0", journal.value["generation"])[0]
            journal.preparing(operation)
            journal.finish(operation, "execution_not_available", {"source": {"version": "1.2.0", "commit": "a" * 40},
                           "plan_id": "d" * 64, "target": {"tag": "v1.3.0", "version": "1.3.0",
                           "commit": "e" * 40, "image_digest": "sha256:" + "f" * 64}})
            self.assertTrue(journal.claim_apply(operation, journal.value["generation"]))
            journal.begin_generation(str(uuid.uuid4()))
            self.assertEqual("recovery_required", journal.status(operation)["operation"]["phase"])
            before = (host.state / "journal.json").read_bytes()
            with self.assertRaisesRegex(UPGRADE.UpgradeError, "operation_busy"):
                host.upgrade()
            self.assertEqual(before, (host.state / "journal.json").read_bytes())
            self.assertEqual([], host.commands)
            self.assertEqual((0, 0), (host.kill_count, host.exchange_count))
            self.assertFalse(UPGRADE.TRANSACTION.exists())
            self.assertFalse(UPGRADE.STOP.exists())
            self.assertFalse(host.dropin.exists())

    def test_start_accepted_between_preflight_and_freeze_is_thawed_without_kill_or_exchange(self):
        with self.host() as host:
            def race():
                runtime = host.runtime_from(host.code / "updater.py")
                journal = runtime.Journal(host.state / "journal.json", host.installation_id)
                journal.accept(str(uuid.uuid4()), "v1.3.0", journal.value["generation"])
            host.race = race
            with self.assertRaisesRegex(UPGRADE.UpgradeError, "operation_busy"):
                host.upgrade()
            self.assertTrue(host.running)
            self.assertFalse(host.frozen)
            self.assertEqual(0, host.kill_count)
            self.assertEqual(0, host.exchange_count)
            self.assertFalse(UPGRADE.TRANSACTION.exists())
            self.assertFalse(UPGRADE.STOP.exists())
            self.assertEqual(host.old_hashes, UPGRADE.code_hashes(host.code))
            self.assertIsNotNone(UPGRADE.read_json(host.state / "journal.json")["active_operation"])

    def test_execcondition_blocks_the_actual_automatic_restart_path_while_conditionpath_is_skipped(self):
        with self.host() as host:
            killed = False
            skipped = []
            def automatic_restart_events():
                nonlocal killed
                if not killed and (host.cgroup / "cgroup.kill").read_bytes() == b"1\n":
                    killed = True
                    host.kill_count += 1
                    host.running = False
                    # systemd skips Unit Conditions for AUTO_RESTART's
                    # UNIT_ACTIVATING state, but executes Service ExecCondition.
                    condition = b"ExecCondition=/usr/bin/test ! -e /var/lib/wayfindr-updater/helper-upgrade-stop\n"
                    if condition in host.dropin.read_bytes() and UPGRADE.STOP.exists():
                        skipped.append(True)
                    else:
                        host.running = True
                return {"frozen": "1" if host.frozen else "0", "populated": "1" if host.running else "0"}
            with patch.object(UPGRADE, "events", automatic_restart_events):
                try:
                    result = host.upgrade()
                except Exception as failure:
                    self.fail("Automatic restart must be blocked by the service ExecCondition before replacing old code: " + type(failure).__name__)
            self.assertEqual([True], skipped)
            self.assertTrue(result["preserved"])
            self.assertEqual("on-failure", host.service()["Restart"])

    def test_unloaded_execcondition_refuses_before_freeze_or_kill(self):
        with self.host() as host:
            host.condition_works = False
            with self.assertRaisesRegex(UPGRADE.UpgradeError, "service_changed"):
                host.upgrade()
            self.assertNotIn(("freeze", UPGRADE.SERVICE), host.commands)
            self.assertEqual(0, host.kill_count)
            self.assertFalse(UPGRADE.TRANSACTION.exists())

    def test_condition_tools_must_be_executable_before_any_systemd_or_transaction_action(self):
        for missing_execute in (UPGRADE.BUSCTL, UPGRADE.TEST):
            with self.subTest(path=missing_execute), patch.object(UPGRADE.sys, "platform", "linux"), patch.object(UPGRADE.os, "geteuid", return_value=0), patch.object(UPGRADE.platform, "machine", return_value="aarch64"), patch.object(UPGRADE, "trusted"), patch.object(Path, "stat", lambda path: types.SimpleNamespace(st_mode=stat.S_IFREG | (0o644 if path == missing_execute else 0o755))), patch.object(UPGRADE, "run") as run:
                with self.assertRaisesRegex(UPGRADE.UpgradeError, "host_unsupported"):
                    UPGRADE.supported()
                run.assert_not_called()

    def test_effective_execcondition_readback_requires_one_exact_typed_command_and_argv(self):
        command = [str(UPGRADE.TEST), [str(UPGRADE.TEST), "!", "-e", str(UPGRADE.STOP)], False, 0, 0, 0, 0, 0, 0, 0]
        valid = {"type": "a(sasbttttuii)", "data": [command]}
        invalid = [{"type": valid["type"], "data": []}, {**valid, "data": [command, command]},
                   {**valid, "data": [["/bin/false", *command[1:]]]},
                   {**valid, "data": [[command[0], [command[0] + " !", "-e", str(UPGRADE.STOP)], *command[2:]]]},
                   {**valid, "data": [[*command[:2], True, *command[3:]]]},
                   {**valid, "data": [[*command[:2], 0, *command[3:]]]},
                   {**valid, "type": "unknown"}]
        for index, value in enumerate([valid, *invalid]):
            with self.subTest(value=value), patch.object(UPGRADE.subprocess, "run", return_value=types.SimpleNamespace(returncode=0, stdout=json.dumps(value))):
                self.assertEqual(index == 0, UPGRADE.condition_loaded())

    @contextlib.contextmanager
    def legacy_gate_transaction(self):
        with self.host() as host:
            store = UPGRADE.store
            def crash(record):
                store(record)
                if record["stage"] == "frozen_idle":
                    raise Crash()
            with patch.object(UPGRADE, "store", crash), self.assertRaises(Crash):
                host.upgrade()
            host.write(host.dropin, UPGRADE.LEGACY_GATE, 0o644)
            # Model the observed old helper autorestart: new journal generation,
            # unfrozen kernel cgroup, stale manager FreezerState=frozen.
            runtime = host.runtime_from(host.code / "updater.py")
            runtime.Journal(host.state / "journal.json", host.installation_id).begin_generation(str(uuid.uuid4()))
            host.stale_freezer = True
            host.commands.clear()
            yield host

    def test_explicit_recovery_upgrades_only_the_owned_legacy_gate_and_normalizes_stale_freezer(self):
        with self.legacy_gate_transaction() as host:
            with self.assertRaises(UPGRADE.UpgradeError):
                host.upgrade()
            self.assertEqual(UPGRADE.LEGACY_GATE, host.dropin.read_bytes())
            result = host.recover()
            self.assertTrue(result["preserved"])
            self.assertEqual(UPGRADE.GATE, host.dropin.read_bytes())
            self.assertLess(host.commands.index(("thaw", UPGRADE.SERVICE)), host.commands.index(("freeze", UPGRADE.SERVICE)))
            self.assertTrue(any(path.read_bytes() == UPGRADE.LEGACY_GATE for path in UPGRADE.RECEIPTS.glob("*.incomplete-*")))

    def test_legacy_gate_is_never_rewritten_for_invalid_transaction_generation_or_unknown_gate(self):
        for changed in ("generation", "gate", "scratch"):
            with self.subTest(changed=changed), self.legacy_gate_transaction() as host:
                if changed == "generation":
                    record = UPGRADE.read_json(UPGRADE.TRANSACTION)
                    record["stage"] = "switched"
                    UPGRADE.store(record)
                elif changed == "gate":
                    host.write(host.dropin, UPGRADE.LEGACY_GATE + b"# unknown\n", 0o644)
                else:
                    host.write(UPGRADE.GATE_TEMP, b"unknown", 0o644)
                before = host.dropin.read_bytes()
                with self.assertRaises(UPGRADE.UpgradeError):
                    host.recover()
                self.assertEqual(before, host.dropin.read_bytes())
                self.assertNotIn(("daemon-reload",), host.commands)
                self.assertEqual(0, host.kill_count)

    def test_interrupted_legacy_gate_replacement_retains_evidence_and_recovers(self):
        for checkpoint in ("prefix", "open", "replace"):
            with self.subTest(checkpoint=checkpoint), self.legacy_gate_transaction() as host:
                create, replace, open_file = UPGRADE.create, os.replace, os.open
                def crash_create(path, raw, mode=0o600):
                    if path == UPGRADE.GATE_TEMP:
                        create(path, raw[:30], mode)
                        raise Crash()
                    create(path, raw, mode)
                def crash_replace(source, target):
                    replace(source, target)
                    if target == UPGRADE.DROPIN:
                        raise Crash()
                def crash_open(path, *arguments, **options):
                    descriptor = open_file(path, *arguments, **options)
                    if path == UPGRADE.GATE_TEMP:
                        os.close(descriptor)
                        raise Crash()
                    return descriptor
                previous_umask = os.umask(0o077)
                try:
                    hook = patch.object(UPGRADE, "create", crash_create) if checkpoint == "prefix" else patch.object(UPGRADE.os, "replace", crash_replace) if checkpoint == "replace" else patch.object(UPGRADE.os, "open", crash_open)
                    with hook, self.assertRaises(Crash):
                        host.recover()
                    if checkpoint == "open":
                        self.assertEqual(0o644, stat.S_IMODE(UPGRADE.GATE_TEMP.stat().st_mode), "Exclusive gate scratch creation must immediately use0644 despite umask077")
                    self.assertTrue(UPGRADE.TRANSACTION.exists())
                    self.assertTrue(UPGRADE.STOP.exists())
                    self.assertTrue(host.recover()["preserved"])
                    self.assertEqual(UPGRADE.GATE, host.dropin.read_bytes())
                    self.assertFalse(UPGRADE.GATE_TEMP.exists())
                finally:
                    os.umask(previous_umask)

    def test_interruption_while_retaining_legacy_gate_audit_preserves_both_prefixes_and_recovers(self):
        with self.legacy_gate_transaction() as host:
            create = UPGRADE.create
            def crash(path, raw, mode=0o600):
                if path.parent == UPGRADE.RECEIPTS and ".incomplete-" in path.name:
                    create(path, raw[:5], mode)
                    raise Crash()
                create(path, raw, mode)
            with patch.object(UPGRADE, "create", crash), self.assertRaises(Crash):
                host.recover()
            self.assertEqual(UPGRADE.LEGACY_GATE, host.dropin.read_bytes())
            try:
                result = host.recover()
            except Exception as failure:
                self.fail("Repeated explicit recovery must retain its own interrupted audit prefix: " + type(failure).__name__)
            self.assertTrue(result["preserved"])
            audit = [path.read_bytes() for path in UPGRADE.RECEIPTS.glob("*.incomplete-*")]
            self.assertIn(UPGRADE.LEGACY_GATE[:5], audit)
            self.assertIn(UPGRADE.LEGACY_GATE, audit)

    def test_unconfirmed_freeze_never_kills_or_exchanges(self):
        with self.host() as host:
            host.freeze_works = False
            with self.assertRaisesRegex(UPGRADE.UpgradeError, "freeze_unverified"):
                host.upgrade()
            self.assertEqual(0, host.kill_count)
            self.assertEqual(0, host.exchange_count)
            self.assertFalse(host.frozen)
            self.assertTrue(host.running)
            self.assertTrue(UPGRADE.TRANSACTION.exists())
            self.assertTrue(UPGRADE.STOP.exists())

    def test_cgroup_kill_must_be_observed_empty_before_stop_can_auto_thaw(self):
        with self.host() as host:
            host.kill_settles = False
            clock = iter((0, 11))
            with patch.object(UPGRADE.time, "monotonic", side_effect=lambda: next(clock)):
                with self.assertRaisesRegex(UPGRADE.UpgradeError, "helper_not_stopped"):
                    host.upgrade()
            self.assertNotIn(("stop", UPGRADE.SERVICE), host.commands)
            self.assertEqual(0, host.exchange_count)
            self.assertTrue(host.frozen)
            self.assertTrue(UPGRADE.STOP.exists())
            self.assertTrue(UPGRADE.TRANSACTION.exists())

    def test_cgroup_disappearing_during_post_kill_events_read_requires_verified_stopped_service(self):
        for service_stopped in (True, False):
            with self.subTest(service_stopped=service_stopped), self.host() as host:
                original_events = host.events
                def disappearing_events():
                    if host.cgroup.exists() and (host.cgroup / "cgroup.kill").read_bytes() == b"1\n":
                        # Model ENOENT after an existence observation, when
                        # systemd removes its now-empty service cgroup.
                        original_events()
                        shutil.rmtree(host.cgroup)
                        host.running = not service_stopped
                    if not host.cgroup.exists():
                        raise FileNotFoundError("cgroup.events")
                    return original_events()
                with patch.object(UPGRADE, "events", disappearing_events):
                    if service_stopped:
                        self.assertTrue(host.upgrade()["preserved"])
                        self.assertEqual(1, host.exchange_count)
                    else:
                        clock = iter((0, 11))
                        with patch.object(UPGRADE.time, "monotonic", side_effect=lambda: next(clock)):
                            with self.assertRaisesRegex(UPGRADE.UpgradeError, "helper_not_stopped"):
                                host.upgrade()
                        self.assertNotIn(("stop", UPGRADE.SERVICE), host.commands)
                        self.assertEqual(0, host.exchange_count)
                        self.assertTrue(UPGRADE.STOP.exists())

    def test_missing_events_in_existing_cgroup_never_proves_empty(self):
        with self.host() as host:
            def missing_events():
                raise FileNotFoundError("cgroup.events")
            with patch.object(UPGRADE, "events", missing_events):
                with self.assertRaisesRegex(UPGRADE.UpgradeError, "helper_not_stopped"):
                    UPGRADE.cgroup_empty()
            self.assertEqual([], host.commands)

    def test_terminal_old_executor_history_and_retained_custody_are_preserved_byte_for_byte(self):
        specification = importlib.util.spec_from_file_location("upgrade_protection_fixture", ROOT / "scripts/test_update_protection.py")
        fixture = importlib.util.module_from_spec(specification)
        specification.loader.exec_module(fixture)
        with self.host() as host:
            runtime = host.runtime_from(host.code / "updater.py")
            config = UPGRADE.enrollment(runtime, host.distribution)
            journal = runtime.Journal(host.state / "journal.json", host.installation_id)
            generation = journal.value["generation"]
            operation = journal.accept(str(uuid.uuid4()), "v1.1.2", generation)[0]
            journal.preparing(operation)
            journal.finish(operation, "execution_not_available", {"source": fixture.SOURCE.copy(), "plan_id": "d" * 64,
                           "target": {"tag": "v1.1.2", "version": "1.1.2", "commit": "e" * 40, "image_digest": "sha256:" + "f" * 64}})
            self.assertTrue(journal.claim_protection(operation, generation))
            protector = fixture.PROTECT.Protector(config, journal, host.state, runtime, fixture.Engine(), secure=False)
            protector(operation)
            self.assertIsNone(journal.value["active_operation"])
            self.assertEqual(self.source_version, journal.value["operations"][operation]["executor_version"])
            self.assertTrue(journal.value["operations"][operation]["protection"]["custody_verified"])
            before = UPGRADE.idle(runtime, config)[:2]
            self.assertTrue(before[1], "The fixture must contain real retained archive/key/config files.")
            host.upgrade()
            record = UPGRADE.read_json(next(UPGRADE.RECEIPTS.glob("*.json")))
            new = host.runtime_from(host.code / "updater.py")
            after = UPGRADE.idle(new, config, private_code=Path(record["retained_old_code"]))[:2]
            self.assertEqual(before, after)

    def test_retained_schema1_apply_state_uses_the_original_generation_validator(self):
        # This deliberately narrow old-generation fixture makes the boundary
        # visible: current0.5 rejects schema1, while an exact preserved0.4
        # validator accepts it. It does not claim a managed VM apply occurred.
        specification = importlib.util.spec_from_file_location("upgrade_apply_fixture", ROOT / "scripts/test_update_apply.py")
        fixture = importlib.util.module_from_spec(specification)
        specification.loader.exec_module(fixture)
        applying = fixture.ApplyTests()
        applying.setUp()
        try:
            applying.apply()
            self.assertEqual("succeeded", applying.status()["operation"]["phase"])
            with self.host() as host:
                for name in ("apply", "protection"):
                    shutil.copytree(applying.root / name, host.state / name)
                value = json.loads(applying.journalpath.read_text())
                value["installation_id"] = host.installation_id
                for operation in value["operations"].values():
                    operation["executor_version"] = "0.4.0"
                host.write(host.state / "journal.json", json.dumps(value).encode(), 0o600)
                for path in host.state.rglob("*.json"):
                    if path.name == "journal.json":
                        continue
                    document = json.loads(path.read_text())
                    if isinstance(document, dict) and "installation_id" in document:
                        document["installation_id"] = host.installation_id
                    if path.parent.parent.name == "apply" and path.name == "state.json":
                        document["schema"] = 1
                        current_config = json.loads(host.config.read_text())
                        for name in ("old_config", "new_config"):
                            if isinstance(document[name], dict):
                                historical = dict(current_config)
                                for key in ("image_reference", "overlay_sha256"):
                                    historical[key] = document[name][key]
                                document[name] = historical
                    host.write(path, json.dumps(document).encode(), 0o600)
                old_apply = b'''import json
class Applier:
    def __init__(self, config, journal, state, api):
        self.config, self.api = config, api
    def load(self, directory, operation):
        value = self.api.read_object(directory / "state.json", 1000000, "recovery_required")
        if value["schema"] != 1 or value["installation_id"] != self.config.installation_id or value["operation_id"] != operation:
            raise self.api.Refusal("recovery_required")
        return value
'''
                host.write(host.code / "update_apply.py", old_apply, 0o644)
                host.old_hashes["update_apply.py"] = UPGRADE.digest(old_apply)
                try:
                    result = host.upgrade()
                except Exception as failure:
                    self.fail("Retained schema1 apply must be validated by the original helper generation: " + type(failure).__name__)
                original = Path(result["retained_old_code"])
                self.assertEqual(old_apply, (original / "update_apply.py").read_bytes())
                self.assertEqual(1, UPGRADE.read_json(host.state / "apply" / applying.operation / "state.json")["schema"])
                self.assertEqual("0.4.0", UPGRADE.read_json(host.state / "journal.json")["operations"][applying.operation]["executor_version"])
        finally:
            applying.doCleanups()

    def test_terminal_history_configuration_may_differ_only_in_image_and_overlay(self):
        with self.host() as host:
            runtime = host.runtime_from(host.code / "updater.py")
            current = UPGRADE.enrollment(runtime, host.distribution)
            old = {**current.value, "image_reference": "ghcr.io/adamgreenwell/wayfindr:1.1.1", "overlay_sha256": "b" * 64}
            new = {**old, "image_reference": "ghcr.io/adamgreenwell/wayfindr:1.1.2", "overlay_sha256": "c" * 64}
            state = {"old_config": old, "new_config": new}
            self.assertEqual(new, UPGRADE.historical_configuration(runtime, current, state, "succeeded").value)
            self.assertEqual(old, UPGRADE.historical_configuration(runtime, current, state, "failed_safe").value)
            self.assertEqual(old, UPGRADE.historical_configuration(runtime, current, {**state, "new_config": None}, "cancelled").value)
            for field in ("installation_id", "env_sha256", "installer_sha256", "compose_sha256", "install_dir"):
                changed = {**old, field: str(uuid.uuid4()) if field == "installation_id" else "/opt/unowned" if field == "install_dir" else "f" * 64}
                with self.subTest(field=field), self.assertRaisesRegex(UPGRADE.UpgradeError, "unowned_recovery"):
                    UPGRADE.historical_configuration(runtime, current, {**state, "old_config": changed}, "succeeded")

    def test_new_helper_admission_barrier_blocks_prepare_while_allowing_readonly_status(self):
        with self.host() as host:
            with patch.object(UPGRADE, "authenticate", side_effect=Crash()), self.assertRaises(Crash):
                host.upgrade()
            runtime = host.runtime_from(host.code / "updater.py")
            config = UPGRADE.enrollment(runtime, host.distribution)
            journal = runtime.Journal(host.state / "journal.json", host.installation_id)
            controller = runtime.Controller(config, journal)
            try:
                request = {"protocol": 1, "installation_id": host.installation_id, "nonce": "1" * 32,
                           "issued_at": int(UPGRADE.time.time()), "action": "prepare", "request_id": str(uuid.uuid4()), "release_tag": "v1.3.0"}
                with self.assertRaises(runtime.Refusal) as failure:
                    controller.dispatch(request, 0)
                self.assertEqual("operation_busy", failure.exception.reason)
                request = {key: value for key, value in request.items() if key not in {"request_id", "release_tag"}}
                request.update(action="status", nonce="2" * 32)
                self.assertIsNone(controller.dispatch(request, 0)["active_operation"])
                self.assertEqual({}, journal.value["operations"])
            finally:
                controller.workers.shutdown(wait=True)

    def test_orphan_recovery_directory_and_partial_journal_refuse_without_freeze(self):
        for kind in ("apply", "protection", "partial"):
            with self.subTest(kind=kind), self.host() as host:
                if kind == "partial":
                    (host.state / ".journal-incomplete").write_bytes(b"partial")
                else:
                    (host.state / kind / str(uuid.uuid4())).mkdir(parents=True)
                with self.assertRaisesRegex(UPGRADE.UpgradeError, "unowned_recovery|partial_state"):
                    host.upgrade()
                self.assertEqual([], host.commands)

    def test_interruptions_at_durable_stages_recover_without_mixed_code_or_lost_identity(self):
        for checkpoint in ("prepared", "frozen_idle", "staging", "switched", "starting", "verified"):
            with self.subTest(checkpoint=checkpoint), self.host() as host:
                store = UPGRADE.store
                injected = False
                def crash(value):
                    nonlocal injected
                    store(value)
                    if value["stage"] == checkpoint and not injected:
                        injected = True
                        raise Crash(checkpoint)
                with patch.object(UPGRADE, "store", crash), self.assertRaises(Crash):
                    host.upgrade()
                hashes = UPGRADE.code_hashes(host.code)
                self.assertIn(hashes, (host.old_hashes, UPGRADE.bundle(host.distribution)[0]["files"]))
                self.assertTrue(UPGRADE.TRANSACTION.exists())
                with self.assertRaisesRegex(UPGRADE.UpgradeError, "recovery_required|helper_not_running"):
                    host.upgrade()
                result = host.recover()
                self.assertEqual("0.6.0", result["helper_version"])
                self.assertEqual(host.installation_id, result["installation_id"])
                self.assertEqual(1, host.exchange_count)
                self.assertFalse(UPGRADE.TRANSACTION.exists())
                self.assertFalse(UPGRADE.STOP.exists())

    def test_crash_immediately_after_atomic_exchange_is_reconciled_by_exact_directory_hashes(self):
        with self.host() as host:
            exchange = UPGRADE.exchange
            def crash(left, right):
                exchange(left, right)
                raise Crash()
            with patch.object(UPGRADE, "exchange", crash), self.assertRaises(Crash):
                host.upgrade()
            self.assertEqual("staging", UPGRADE.read_json(UPGRADE.TRANSACTION)["stage"])
            self.assertEqual(UPGRADE.bundle(host.distribution)[0]["files"], UPGRADE.code_hashes(host.code))
            host.recover()
            self.assertEqual(1, host.exchange_count)

    def test_crash_after_initial_fsynced_scratch_before_rename_has_explicit_recovery(self):
        with self.host() as host:
            replace = os.replace
            def crash(source, destination):
                if destination == UPGRADE.TRANSACTION:
                    raise Crash()
                return replace(source, destination)
            with patch.object(UPGRADE.os, "replace", crash), self.assertRaises(Crash):
                host.upgrade()
            self.assertFalse(UPGRADE.TRANSACTION.exists())
            self.assertTrue((host.state / ".helper-upgrade.next").exists())
            self.assertTrue(host.running)
            host.recover()
            self.assertEqual(1, host.exchange_count)

    def test_startup_failure_keeps_new_generation_admission_blocked_until_recovery(self):
        with self.host() as host:
            with patch.object(UPGRADE, "authenticate", side_effect=UPGRADE.UpgradeError("startup_unverified")):
                with self.assertRaisesRegex(UPGRADE.UpgradeError, "startup_unverified"):
                    host.upgrade()
            self.assertTrue(host.running)
            self.assertTrue(UPGRADE.TRANSACTION.exists())
            self.assertFalse(UPGRADE.STOP.exists())
            host.recover()
            self.assertEqual(1, host.exchange_count)

    def test_receipt_write_crash_recovery_preserves_first_verified_generation(self):
        with self.host() as host:
            create = UPGRADE.create
            def crash(path, raw, mode=0o600):
                create(path, raw, mode)
                if path.parent == UPGRADE.RECEIPTS:
                    raise Crash()
            with patch.object(UPGRADE, "create", crash), self.assertRaises(Crash):
                host.upgrade()
            record = UPGRADE.read_json(UPGRADE.TRANSACTION)
            expected = UPGRADE.read_json(UPGRADE.RECEIPTS / (record["transaction_id"] + ".json"))
            result = host.recover()
            self.assertEqual(expected, result)

    def test_interrupted_owned_stop_module_and_receipt_prefixes_are_retained_and_recovered(self):
        for checkpoint in ("stop", "module", "receipt"):
            with self.subTest(checkpoint=checkpoint), self.host() as host:
                create = UPGRADE.create
                injected = False
                def crash(path, raw, mode=0o600):
                    nonlocal injected
                    chosen = path == UPGRADE.STOP if checkpoint == "stop" else path.parent.name.startswith(".wayfindr-updater-generation-") and path.name == "update_apply.py" if checkpoint == "module" else path.parent == UPGRADE.RECEIPTS
                    if chosen and not injected:
                        injected = True
                        host.write(path, raw[:len(raw) // 2], mode)
                        raise Crash()
                    create(path, raw, mode)
                with patch.object(UPGRADE, "create", crash), self.assertRaises(Crash):
                    host.upgrade()
                result = host.recover()
                self.assertEqual("0.6.0", result["helper_version"])
                self.assertEqual(1, host.exchange_count)
                self.assertTrue(list(UPGRADE.RECEIPTS.glob("*.incomplete-*")), "Interrupted bytes must be retained for inspection.")

    def test_helper_lock_requires_the_canonical_service_group_without_chowning_it(self):
        production_private = UPGRADE.private
        with self.host() as host:
            runtime = host.runtime_from(host.code / "updater.py")
            with patch.object(UPGRADE, "private", wraps=host.private) as checker:
                UPGRADE.enrollment(runtime, host.distribution)
                checker.assert_any_call(host.state / "helper.lock", 0o600, 1000)
            for group in (0, 1000):
                information = types.SimpleNamespace(st_gid=group, st_mode=stat.S_IFREG | 0o600, st_nlink=1)
                with patch.object(UPGRADE, "trusted"), patch.object(Path, "stat", return_value=information):
                    if group == 1000:
                        production_private(host.state / "helper.lock", 0o600, 1000)
                    else:
                        with self.assertRaisesRegex(UPGRADE.UpgradeError, "untrusted_metadata"):
                            production_private(host.state / "helper.lock", 0o600, 1000)

    def test_configuration_accepts_enrollment_and_canonical_promotion_groups_only(self):
        for group in (0, 1000, 1001):
            with self.subTest(group=group), self.host() as host:
                host.configuration_gid = group
                if group in {0, 1000}:
                    result = host.upgrade()
                    self.assertTrue(result["preserved"])
                    self.assertEqual(group, host.identity(host.config)[3])
                else:
                    with self.assertRaisesRegex(UPGRADE.UpgradeError, "untrusted_metadata"):
                        host.upgrade()
                    self.assertEqual([], host.commands)

    def test_trusted_paths_require_root_owner_safe_ancestors_and_real_files(self):
        leaf = Path("/opt/wayfindr-updater/updater.py")
        for mutation in ({"st_uid": 1000}, {"st_mode": stat.S_IFLNK | 0o777}, {"st_mode": stat.S_IFDIR | 0o775}):
            def metadata(path):
                base = {"st_uid": 0, "st_mode": stat.S_IFREG | 0o644 if path == leaf else stat.S_IFDIR | 0o755}
                if path == leaf.parent:
                    base.update(mutation)
                return types.SimpleNamespace(**base)
            with self.subTest(mutation=mutation), patch.object(Path, "lstat", metadata):
                with self.assertRaisesRegex(UPGRADE.UpgradeError, "untrusted_path"):
                    UPGRADE.trusted(leaf)

    def test_recovery_refuses_tampered_transaction_bundle_code_or_preserved_identity(self):
        for target in ("transaction", "bundle", "code", "environment"):
            with self.subTest(target=target), self.host() as host:
                with patch.object(UPGRADE, "authenticate", side_effect=Crash()), self.assertRaises(Crash):
                    host.upgrade()
                if target == "transaction":
                    value = UPGRADE.read_json(UPGRADE.TRANSACTION)
                    value["installation_id"] = str(uuid.uuid4())
                    UPGRADE.store(value)
                else:
                    path = {"bundle": host.distribution / "scripts/self-host/update_artifacts.py", "code": host.code / "update_artifacts.py", "environment": host.install / ".env"}[target]
                    with path.open("ab") as output:
                        output.write(b"\n# changed\n")
                previous = host.exchange_count
                with self.assertRaises(Exception):
                    host.recover()
                self.assertEqual(previous, host.exchange_count)
                self.assertTrue(UPGRADE.TRANSACTION.exists())

    def test_recovery_rejects_stage_and_generation_disagreement_before_freeze(self):
        for checkpoint, impossible in (("frozen_idle", "switched"), ("switched", "prepared")):
            with self.subTest(checkpoint=checkpoint), self.host() as host:
                store = UPGRADE.store
                def crash(value):
                    store(value)
                    if value["stage"] == checkpoint:
                        raise Crash()
                with patch.object(UPGRADE, "store", crash), self.assertRaises(Crash):
                    host.upgrade()
                record = UPGRADE.read_json(UPGRADE.TRANSACTION)
                record["stage"] = impossible
                UPGRADE.store(record)
                host.commands.clear()
                with self.assertRaisesRegex(UPGRADE.UpgradeError, "transaction_changed"):
                    host.recover()
                self.assertNotIn(("freeze", UPGRADE.SERVICE), host.commands)

    def test_rename_exchange_uses_linux_atomic_flag_and_refuses_failure(self):
        rename = types.SimpleNamespace(argtypes=None, restype=None)
        class Rename:
            argtypes = None
            restype = None
            def __call__(self, *arguments):
                self.arguments = arguments
                return self.result
        rename = Rename()
        for result in (0, -1):
            rename.result = result
            with patch.object(UPGRADE.ctypes, "CDLL", return_value=types.SimpleNamespace(renameat2=rename)), patch.object(UPGRADE, "sync") as sync:
                if result == 0:
                    UPGRADE.exchange(Path("/root/old"), Path("/root/new"))
                    sync.assert_called_once_with(Path("/root"))
                else:
                    with self.assertRaisesRegex(UPGRADE.UpgradeError, "atomic_exchange_failed"):
                        UPGRADE.exchange(Path("/root/old"), Path("/root/new"))
                    sync.assert_not_called()
                self.assertEqual((-100, b"/root/old", -100, b"/root/new", 2), rename.arguments)


class LegacyRecoveryTests(unittest.TestCase):
    def legacy_cli(self):
        path = ROOT / "scripts/self-host/upgrade-updater-0.5-recovery.py"
        self.assertEqual("fe494f93552246677ee8a97c55a819ab748de4910bf300123dbb6afe408d4616", hashlib.sha256(path.read_bytes()).hexdigest())
        self.assertEqual(UPGRADE.LEGACY_RECOVERY_CLI_SHA256, hashlib.sha256(path.read_bytes()).hexdigest())
        specification = importlib.util.spec_from_file_location("frozen_05_recovery", path)
        legacy = importlib.util.module_from_spec(specification)
        specification.loader.exec_module(legacy)
        return legacy

    def test_public_source_allowlists_and_legacy_target_are_exact(self):
        self.assertEqual({"0.4.0", "0.5.0"}, set(UPGRADE.PUBLISHED))
        legacy = self.legacy_cli()
        self.assertEqual("0.5.0", legacy.VERSION)
        self.assertEqual(legacy.PUBLISHED, UPGRADE.PUBLISHED["0.4.0"])
        self.assertEqual(set(UPGRADE.FILES), set(UPGRADE.PUBLISHED["0.5.0"]))
        declaration = {"schema": 1, "helper_version": "0.5.0", "protocol": 1, "files": UPGRADE.PUBLISHED["0.5.0"]}
        self.assertEqual("e8523f24327188c36e6a352cd4366977afdb96b02f56d17760d7bd8063cada29", UPGRADE.digest(UPGRADE.encoded(declaration)))
        self.assertEqual(UPGRADE.LEGACY_BUNDLE_SHA256, UPGRADE.digest(UPGRADE.encoded(declaration)))

    def test_frozen_cli_finishes_original_transaction_before_separate_successor_upgrade(self):
        successor = UPGRADE
        legacy = self.legacy_cli()
        for stage in ("prepared", "frozen_idle", "staging", "switched", "starting", "verified", "prepared_scratch"):
            with self.subTest(stage=stage), tempfile.TemporaryDirectory(prefix="wayfindr-legacy-recovery-") as directory:
                with patch.object(sys.modules[__name__], "UPGRADE", legacy):
                    host = Host(Path(directory).resolve())
                    with host.activated():
                        declaration, checksum = legacy.bundle(host.distribution)
                        legacy_target = {name: (host.distribution / "scripts/self-host" / name).read_bytes() for name in legacy.FILES}
                        store = legacy.store
                        def crash(record):
                            store(record)
                            if record["stage"] == stage.removesuffix("_scratch"):
                                raise Crash()
                        with patch.object(legacy, "store", crash), self.assertRaises(Crash):
                            host.upgrade()
                        record = legacy.read_json(legacy.TRANSACTION)
                        transaction = record["transaction_id"]
                        if stage == "prepared_scratch":
                            os.rename(legacy.TRANSACTION, host.state / ".helper-upgrade.next")
                approved = {"0.4.0": host.old_hashes, "0.5.0": declaration["files"]}
                # A complete successor distribution exists, but cannot retarget
                # the original transaction even after its code exchange.
                for name in successor.FILES:
                    host.write(host.distribution / "scripts/self-host" / name, (ROOT / "scripts/self-host" / name).read_bytes(), 0o644)
                with host.activated(), patch.object(successor, "PUBLISHED", approved), patch.object(successor, "LEGACY_BUNDLE_SHA256", checksum):
                    before = {str(path.relative_to(host.root)): path.read_bytes() for path in host.root.rglob("*") if path.is_file()}
                    commands, kills, exchanges = list(host.commands), host.kill_count, host.exchange_count
                    for recovery in (None, transaction):
                        with self.assertRaisesRegex(successor.UpgradeError, "legacy_helper_recovery_required"):
                            successor.finish(host.distribution, host.selected(), recovery=recovery)
                    self.assertEqual(commands, host.commands, "Legacy refusal must not reach any systemd command")
                    self.assertEqual((kills, exchanges), (host.kill_count, host.exchange_count))
                    self.assertEqual(before, {str(path.relative_to(host.root)): path.read_bytes() for path in host.root.rglob("*") if path.is_file()}, "No gate, journal, transaction, receipt or code bytes may change")
                # Only the frozen CLI in an exact original target tree resumes
                # the original UUID. The helper modules are never hotpatched.
                for name, raw in legacy_target.items():
                    host.write(host.distribution / "scripts/self-host" / name, raw, 0o644)
                with patch.object(sys.modules[__name__], "UPGRADE", legacy), host.activated():
                    self.assertEqual(checksum, host.selected())
                    before_commands = list(host.commands)
                    for selected_hash, selected_transaction in (("a" * 64, transaction), (checksum, str(uuid.uuid4()))):
                        with self.assertRaises(legacy.UpgradeError):
                            legacy.finish(host.distribution, selected_hash, recovery=selected_transaction)
                    self.assertEqual(before_commands, host.commands)
                    old_receipt = host.recover()
                    self.assertEqual(transaction, old_receipt["transaction_id"])
                    self.assertEqual("0.5.0", old_receipt["helper_version"])
                    old_path = legacy.RECEIPTS / (transaction + ".json")
                    old_receipt_bytes = old_path.read_bytes()
                for name in successor.FILES:
                    host.write(host.distribution / "scripts/self-host" / name, (ROOT / "scripts/self-host" / name).read_bytes(), 0o644)
                with host.activated(), patch.object(successor, "PUBLISHED", approved):
                    result = host.upgrade()
                    self.assertEqual(("0.5.0", "0.6.0"), (result["from_helper_version"], result["helper_version"]))
                    self.assertNotEqual(transaction, result["transaction_id"])
                    self.assertEqual(old_receipt_bytes, old_path.read_bytes())
                    self.assertEqual(declaration["files"], successor.code_hashes(Path(result["retained_old_code"])))

    def test_legacy_refusal_reports_only_fixed_recovery_pins(self):
        with patch.object(UPGRADE.sys, "argv", ["upgrade-updater.py", "recover", "--transaction", str(uuid.uuid4()), "--expected-bundle-sha256", "a" * 64]), \
                patch.object(UPGRADE, "finish", side_effect=UPGRADE.UpgradeError("legacy_helper_recovery_required")), \
                patch.object(UPGRADE, "exists", return_value=False), contextlib.redirect_stderr(io.StringIO()) as output:
            self.assertEqual(1, UPGRADE.main())
        result = json.loads(output.getvalue())
        self.assertEqual({"helper_version": "0.5.0", "bundle_sha256": UPGRADE.LEGACY_BUNDLE_SHA256,
                          "cli_sha256": UPGRADE.LEGACY_RECOVERY_CLI_SHA256}, result["legacy_recovery"])


class Published05UpgradeTests(UpgradeTests):
    # Exercise every interruption, admission race and custody preservation case
    # with a distinct original 0.5 generation, not just the happy path.
    source_version = "0.5.0"


if __name__ == "__main__":
    unittest.main()
