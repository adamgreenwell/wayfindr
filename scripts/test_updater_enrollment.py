#!/usr/bin/env python3
"""Synthetic enrollment tests. Never use host Docker, systemd, or /etc writes."""

import contextlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import struct
import subprocess
import sys
import tempfile
import types
import unittest
from unittest.mock import patch


ROOT = Path(__file__).absolute().parents[1]
sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location("enroll_updater", ROOT / "scripts/self-host/enroll-updater.py")
ENROLL = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ENROLL)
IMAGE = "ghcr.io/adamgreenwell/wayfindr:v1.2.3"
IMAGE_ID = "sha256:" + "a" * 64


class EnrollmentTests(unittest.TestCase):
    def test_trusted_paths_reject_symlink_writable_and_nonroot_ancestors(self):
        target = Path("/opt/wayfindr/.env")
        for bad_mode, bad_owner in ((stat.S_IFLNK | 0o777, 0), (stat.S_IFDIR | 0o775, 0), (stat.S_IFDIR | 0o755, 1000)):
            def metadata(path):
                if path == Path("/opt/wayfindr"):
                    return types.SimpleNamespace(st_mode=bad_mode, st_uid=bad_owner)
                return types.SimpleNamespace(st_mode=stat.S_IFREG | 0o600 if path == target else stat.S_IFDIR | 0o755, st_uid=0)
            with self.subTest(mode=bad_mode, owner=bad_owner), patch.object(Path, "lstat", metadata):
                with self.assertRaises(ENROLL.EnrollmentError):
                    ENROLL.trusted(target)

    def test_canonical_root_owned_regular_file_is_accepted(self):
        target = Path("/opt/wayfindr/.env")
        with patch.object(Path, "lstat", lambda path: types.SimpleNamespace(st_mode=stat.S_IFREG | 0o600 if path == target else stat.S_IFDIR | 0o755, st_uid=0)):
            ENROLL.trusted(target)
        for target in (Path("relative/.env"), Path("/opt/../tmp/.env")):
            with self.assertRaises(ENROLL.EnrollmentError):
                ENROLL.trusted(target)

    def test_non_linux_nonroot_and_unsupported_architecture_refuse(self):
        for host, uid, arch in (("Darwin", 0, "arm64"), ("Linux", 1000, "x86_64"), ("Linux", 0, "i386")):
            with self.subTest(host=host, uid=uid, arch=arch), patch.object(ENROLL.platform, "system", return_value=host), patch.object(ENROLL.platform, "machine", return_value=arch), patch.object(ENROLL.os, "geteuid", return_value=uid), patch.object(Path, "is_dir", return_value=True), patch.object(Path, "is_file", return_value=True), patch.object(ENROLL, "run") as runner:
                with self.assertRaises(ENROLL.EnrollmentError):
                    ENROLL.host_supported()
                runner.assert_not_called()

    def test_system_python_version_is_checked_not_only_invoking_interpreter(self):
        with patch.object(ENROLL.platform, "system", return_value="Linux"), patch.object(ENROLL.platform, "machine", return_value="x86_64"), patch.object(ENROLL.os, "geteuid", return_value=0), patch.object(Path, "is_dir", return_value=True), patch.object(Path, "is_file", return_value=True), patch.object(ENROLL, "run", side_effect=ENROLL.EnrollmentError("System Python is too old")):
            with self.assertRaises(ENROLL.EnrollmentError):
                ENROLL.host_supported()

    def test_process_mapping_requires_actual_host_uid_and_gid_1000(self):
        for uid, gid in (("1000", "1000"), ("0", "1000"), ("100000", "100000"), ("1000", "0")):
            contents = "Uid:\t" + "\t".join([uid] * 4) + "\nGid:\t" + "\t".join([gid] * 4) + "\n"
            with self.subTest(uid=uid, gid=gid), patch.object(Path, "read_text", return_value=contents):
                if uid == gid == "1000":
                    ENROLL.verify_process_identity(123)
                else:
                    with self.assertRaises(ENROLL.EnrollmentError):
                        ENROLL.verify_process_identity(123)
        for pid in (None, True, "123", 0, -1):
            with self.assertRaises(ENROLL.EnrollmentError):
                ENROLL.verify_process_identity(pid)

    def installation_fixture(self, *, image=IMAGE, security=None, old_app=False, service_mutation=None):
        services = {name: {"image": image} for name in ENROLL.APP_SERVICES}
        if service_mutation:
            services["queue"].update(service_mutation)
        rendered = {"name": "wayfindr-self-hosting", "services": services}
        calls = []
        def runner(command, *, json_output=False):
            calls.append(command)
            if "info" in command:
                return {"SecurityOptions": [] if security is None else security}
            if command[-3:] == ["config", "--format", "json"]:
                return rendered
            if "image" in command:
                return [{"Id": IMAGE_ID, "Config": {"User": "wayfindr"}, "Os": "linux", "Architecture": "amd64"}]
            if "ps" in command:
                return "b" * 64
            if "inspect" in command:
                name = next(call[-1] for call in reversed(calls) if "ps" in call)
                return [{"Image": IMAGE_ID, "State": {"Running": True, "Pid": 123}, "HostConfig": {"UsernsMode": ""}, "Config": {"User": "wayfindr", "Labels": {"com.docker.compose.project": "wayfindr-self-hosting", "com.docker.compose.service": name}}}]
            if command[-2:] == ["wayfindr:update-plan", "--help"]:
                if old_app:
                    raise ENROLL.EnrollmentError("Command is unavailable")
                return "Read-only update plan"
            raise AssertionError("Unexpected synthetic Docker command")
        return runner, calls

    def inspect(self, runner):
        with patch.object(ENROLL, "trusted"), patch.object(ENROLL, "file_hash", return_value="a" * 64), patch.object(ENROLL.platform, "machine", return_value="x86_64"), patch.object(ENROLL, "verify_process_identity"), patch.object(ENROLL, "run", side_effect=runner):
            return ENROLL.inspect_install(Path("/opt/wayfindr"), Path("/root/distribution/compose.yml"), ["docker", "--host", "unix:///var/run/docker.sock", "--config", "/etc/wayfindr-updater/docker"])

    def test_exact_official_running_install_and_readonly_command_are_required(self):
        runner, calls = self.installation_fixture()
        self.assertEqual(IMAGE, self.inspect(runner))
        self.assertEqual(["exec", "-T", "web", "php", "artisan", "wayfindr:update-plan", "--help"], calls[-1][-7:])
        self.assertTrue(all("up" not in command and "pull" not in command for command in calls))
        runner, _ = self.installation_fixture(old_app=True)
        with self.assertRaises(ENROLL.EnrollmentError):
            self.inspect(runner)

    def test_custom_floating_prerelease_namespace_remap_and_mixed_services_refuse(self):
        fixtures = [dict(image="custom/wayfindr:v1.2.3"), dict(image="ghcr.io/adamgreenwell/wayfindr:latest"), dict(image="ghcr.io/adamgreenwell/wayfindr:v1.2.3-beta.1"), dict(security=["name=rootless"]), dict(security=["name=userns"]), dict(service_mutation={"image": "custom/wayfindr:v1.2.3"}), dict(service_mutation={"user": "0:0"})]
        for fixture in fixtures:
            with self.subTest(fixture=fixture), self.assertRaises(ENROLL.EnrollmentError):
                runner, _ = self.installation_fixture(**fixture)
                self.inspect(runner)

    @contextlib.contextmanager
    def synthetic_host(self):
        with tempfile.TemporaryDirectory() as temporary:
            host = Path(temporary)
            distribution = host / "distribution"
            install = host / "install"
            for path in (distribution / "scripts/self-host", distribution / "docker/self-hosting", install, host / "etc", host / "var/lib", host / "usr/local/lib", host / "usr/local/bin", host / "etc/systemd/system"):
                path.mkdir(parents=True, exist_ok=True)
            for filename in ("compose.yml", "compose.updater.yml", "wayfindr-updater.service"):
                (distribution / "docker/self-hosting" / filename).write_bytes((ROOT / "docker/self-hosting" / filename).read_bytes())
            (distribution / "scripts/self-host/updater.py").write_bytes((ROOT / "scripts/self-host/updater.py").read_bytes())
            (distribution / "scripts/self-host/install.sh").write_bytes((ROOT / "scripts/self-host/install.sh").read_bytes())
            (install / "compose.yml").write_text("# unchanged application compose\n")
            (install / ".env").write_text("APP_KEY=synthetic-secret-not-for-output\n")
            (install / "install.sh").write_bytes((ROOT / "scripts/self-host/install.sh").read_bytes())
            writes = []
            def writer(path, content, mode, gid=0):
                writes.append((path, mode, gid))
                path.write_bytes(content)
                path.chmod(mode)
            with patch.object(ENROLL, "__file__", str(distribution / "scripts/self-host/enroll-updater.py")), patch.object(ENROLL, "CONFIG_DIR", host / "etc/wayfindr-updater"), patch.object(ENROLL, "STATE_DIR", host / "var/lib/wayfindr-updater"), patch.object(ENROLL, "CODE_DIR", host / "usr/local/lib/wayfindr-updater"), patch.object(ENROLL, "CLI_FILE", host / "usr/local/bin/wayfindr-updater"), patch.object(ENROLL, "UNIT_FILE", host / "etc/systemd/system/wayfindr-updater.service"), patch.object(ENROLL, "host_supported"), patch.object(ENROLL, "trusted"), patch.object(ENROLL, "docker_command", return_value=["synthetic-docker"]), patch.object(ENROLL, "inspect_install", return_value=IMAGE) as inspect, patch.object(ENROLL, "verify_started"), patch.object(ENROLL, "write_new", side_effect=writer), patch.object(ENROLL, "run") as runner:
                yield host, install, writes, inspect, runner

    def test_enrollment_preserves_application_files_and_creates_private_identity(self):
        with self.synthetic_host() as (host, install, writes, _, runner):
            before = [(install / name).read_bytes() for name in ("compose.yml", ".env", "install.sh")]
            result = ENROLL.enroll(install)
            self.assertTrue(result["activation_required"])
            self.assertEqual(before, [(install / name).read_bytes() for name in ("compose.yml", ".env", "install.sh")])
            config = json.loads((ENROLL.CONFIG_DIR / "installation.json").read_text())
            credential = json.loads((ENROLL.CONFIG_DIR / "credential.json").read_text())
            self.assertEqual(config["installation_id"], credential["installation_id"])
            self.assertEqual(64, len(credential["token"]))
            self.assertNotIn(credential["token"], json.dumps(result))
            self.assertIn((ENROLL.CONFIG_DIR / "credential.json", 0o440, 1000), writes)
            self.assertIn((ENROLL.CONFIG_DIR / "installation.json", 0o600, 0), writes)
            self.assertIn((install / ".updater-enrolled", 0o600, 0), writes)
            journal = json.loads((ENROLL.STATE_DIR / "journal.json").read_text())
            self.assertEqual(config["installation_id"], journal["installation_id"])
            self.assertIsNone(journal["generation"])
            self.assertEqual({}, journal["operations"])
            self.assertIn((ENROLL.STATE_DIR / "journal.json", 0o600, 0), writes)
            self.assertTrue(all(call.args[0][0] == "/usr/bin/systemctl" for call in runner.call_args_list))

    def test_existing_enrollment_is_never_replaced_or_credential_rotated(self):
        with self.synthetic_host() as (_, install, writes, _, runner):
            ENROLL.enroll(install)
            before = {path: path.read_bytes() for path, _, _ in writes}
            count = len(writes)
            runner.reset_mock()
            with self.assertRaises(ENROLL.EnrollmentError):
                ENROLL.enroll(install)
            self.assertEqual(before, {path: path.read_bytes() for path in before})
            self.assertEqual(count, len(writes))
            runner.assert_not_called()
            self.assertTrue(ENROLL.enrollment_status()["enrolled"])

    def test_partial_state_or_existing_code_refuses_without_writes(self):
        for artifact in ("state", "code", "marker", "overlay", "unit", "cli"):
            with self.subTest(artifact=artifact), self.synthetic_host() as (_, install, writes, inspect, runner):
                path = {"state": ENROLL.STATE_DIR, "code": ENROLL.CODE_DIR, "marker": install / ".updater-enrolled", "overlay": install / "compose.updater.yml", "unit": ENROLL.UNIT_FILE, "cli": ENROLL.CLI_FILE}[artifact]
                if artifact in {"state", "code"}:
                    path.mkdir()
                else:
                    path.write_text("existing owned artifact")
                with self.assertRaises(ENROLL.EnrollmentError):
                    ENROLL.enroll(install)
                self.assertEqual([], writes)
                inspect.assert_not_called()
                runner.assert_not_called()

    def test_an_older_installed_controller_is_refused_before_host_preparation(self):
        with self.synthetic_host() as (_, install, writes, inspect, runner):
            (install / "install.sh").write_text("#!/bin/sh\n# Older unguarded controller\n")
            with self.assertRaises(ENROLL.EnrollmentError):
                ENROLL.enroll(install)
            self.assertEqual([], writes)
            self.assertFalse(ENROLL.CONFIG_DIR.exists())
            inspect.assert_not_called()
            runner.assert_not_called()

    def test_validation_failure_cleans_only_new_empty_docker_configuration(self):
        with self.synthetic_host() as (_, install, writes, inspect, runner):
            inspect.side_effect = ENROLL.EnrollmentError("Old application version")
            with self.assertRaises(ENROLL.EnrollmentError):
                ENROLL.enroll(install)
            self.assertEqual([], writes)
            self.assertFalse(ENROLL.CONFIG_DIR.exists())
            self.assertFalse((install / ".updater-enrolled").exists())
            runner.assert_not_called()

    def test_docker_command_discards_ambient_context_and_logs_no_secret_errors(self):
        with patch.object(ENROLL, "trusted") as trust:
            self.assertEqual(["/usr/bin/docker", "--host", "unix:///var/run/docker.sock", "--config", "/etc/wayfindr-updater/docker"], ENROLL.docker_command())
            trust.assert_called_once_with(Path("/usr/bin/docker"))
        result = types.SimpleNamespace(returncode=1, stdout="customer-content", stderr="APP_KEY=secret-value")
        with patch.object(ENROLL.subprocess, "run", return_value=result) as runner:
            with self.assertRaises(ENROLL.EnrollmentError) as error:
                ENROLL.run(["synthetic-docker"])
            self.assertNotIn("secret-value", str(error.exception))
            self.assertEqual({"PATH": "/usr/bin:/bin:/usr/sbin:/sbin", "LANG": "C"}, runner.call_args.kwargs["env"])
            self.assertNotIn("shell", runner.call_args.kwargs)

    def test_overlay_and_unit_keep_docker_and_helper_files_outside_application(self):
        overlay = (ROOT / "docker/self-hosting/compose.updater.yml").read_text()
        self.assertEqual(["web"], [line.strip().rstrip(":") for line in overlay.splitlines() if line.startswith("  ") and not line.startswith("    ") and line.strip().endswith(":")])
        self.assertEqual(2, overlay.count("read_only: true"))
        self.assertEqual(2, overlay.count("create_host_path: false"))
        self.assertNotIn("/var/run/docker.sock", overlay)
        self.assertNotIn("/var/lib/wayfindr-updater", overlay)
        self.assertNotIn("/usr/local/lib/wayfindr-updater", overlay)
        unit = (ROOT / "docker/self-hosting/wayfindr-updater.service").read_text()
        for setting in ("User=root", "Group=1000", "KillMode=control-group", "RuntimeDirectoryMode=0750", "RuntimeDirectoryPreserve=yes", "StateDirectoryMode=0700", "NoNewPrivileges=true", "ProtectSystem=strict", "ProtectHome=true", "PrivateTmp=true", "CapabilityBoundingSet=", "RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6"):
            self.assertIn(setting, unit)
        self.assertNotIn("/opt/wayfindr", unit)
        self.assertIn('WAYFINDR_UPDATER_ENABLED: "true"', overlay)
        self.assertIn("WAYFINDR_UPDATER_CREDENTIALS:", overlay)
        self.assertNotIn("WAYFINDR_UPDATER_CREDENTIAL:", overlay)

    def test_actual_canonical_image_tags_with_and_without_v_are_supported(self):
        for tag in ("1.1.1", "v1.1.1", "1.1.1@sha256:" + "a" * 64):
            self.assertIsNotNone(ENROLL.OFFICIAL_IMAGE.fullmatch("ghcr.io/adamgreenwell/wayfindr:" + tag))

    def startup_fixture(self, *, corrupt_mac=False, peer_uid=0, generation="33333333-3333-4333-8333-333333333333"):
        runtime = ENROLL.load_runtime(ROOT / "scripts/self-host/updater.py")
        installation_id = "11111111-1111-4111-8111-111111111111"
        token = "a" * 64
        class Connection:
            def __enter__(self):
                return self
            def __exit__(self, *args):
                pass
            def settimeout(self, value):
                pass
            def connect(self, value):
                pass
            def getsockopt(self, *args):
                return struct.pack("3i", 42, peer_uid, 1000)
            def sendall(self, data):
                request = runtime.unpack_envelope(data, token, "request")
                self.reply = runtime.envelope({"protocol": 1, "installation_id": installation_id, "nonce": request["nonce"], "ok": True, "result": {"installation_id": installation_id, "ownership": "installer-managed", "enrolled": True, "helper": {"capabilities": ["plan", "status"]}}}, token, "response")
                if corrupt_mac:
                    envelope = json.loads(self.reply)
                    envelope["mac"] = "0" * 64
                    self.reply = json.dumps(envelope).encode() + b"\n"
            def recv(self, maximum):
                response, self.reply = self.reply, b""
                return response
        ticks = [0]
        def monotonic():
            ticks[0] += 0.05
            return ticks[0]
        with patch.object(runtime, "trusted"), patch.object(runtime, "Journal") as journal, patch.object(ENROLL.socket, "socket", side_effect=lambda *args: Connection()), patch.object(ENROLL.socket, "SO_PEERCRED", 17, create=True), patch.object(ENROLL.time, "monotonic", side_effect=monotonic), patch.object(ENROLL.time, "sleep"):
            journal.return_value.status.return_value = {"installation_id": installation_id, "generation": generation}
            return ENROLL.verify_started(runtime, installation_id, token)

    def test_startup_requires_real_hmac_root_peer_and_durable_generation(self):
        self.startup_fixture()
        for fixture in (dict(corrupt_mac=True), dict(peer_uid=1000), dict(generation=None)):
            with self.subTest(fixture=fixture), self.assertRaises(ENROLL.EnrollmentError) as error:
                self.startup_fixture(**fixture)
            self.assertNotIn("a" * 64, str(error.exception))

    @unittest.skipUnless(shutil.which("docker"), "Docker Compose is unavailable for read-only rendering")
    def test_compose_overlay_render_preserves_worker_isolation_and_web_storage(self):
        with tempfile.TemporaryDirectory() as temporary:
            # Docker Desktop keeps its Compose plugin in the user's CLI plugin
            # directory. Render with an isolated configuration by linking only
            # that installed binary; Linux Engine's system plugin needs no link.
            plugin = Path(shutil.which("docker")).resolve().parent.parent / "cli-plugins/docker-compose"
            if plugin.is_file():
                plugin_directory = Path(temporary) / "cli-plugins"
                plugin_directory.mkdir()
                (plugin_directory / "docker-compose").symlink_to(plugin)
            environment = Path(temporary) / ".env"
            environment.write_text("POSTGRES_PASSWORD=synthetic-compose-test\nWAYFINDR_IMAGE=" + IMAGE + "\n")
            result = subprocess.run([
                shutil.which("docker"), "--host", "unix:///var/run/docker.sock", "--config", temporary,
                "compose", "--env-file", str(environment), "-f", str(ROOT / "docker/self-hosting/compose.yml"),
                "-f", str(ROOT / "docker/self-hosting/compose.updater.yml"), "config", "--format", "json",
            ], env={"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "WAYFINDR_ENV_FILE": str(environment)}, capture_output=True, text=True, timeout=30, check=False)
            self.assertEqual(0, result.returncode, "Read-only Compose rendering failed")
            rendered = json.loads(result.stdout)
            web = rendered["services"]["web"]
            mounts = {entry["target"]: entry for entry in web["volumes"]}
            self.assertIn("/app/apps/server/storage", mounts)
            self.assertIn("/data", mounts)
            self.assertIn("/config", mounts)
            for target in ("/run/wayfindr-updater", "/run/wayfindr-updater-auth/credential.json"):
                self.assertTrue(mounts[target]["read_only"])
                self.assertFalse(mounts[target]["bind"]["create_host_path"])
            for service, configuration in rendered["services"].items():
                for mount in configuration.get("volumes", []):
                    self.assertNotEqual("/var/run/docker.sock", mount.get("source"))
                    if service != "web":
                        self.assertNotIn("wayfindr-updater", mount.get("source", ""))


if __name__ == "__main__":
    unittest.main()
