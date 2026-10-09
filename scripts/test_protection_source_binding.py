#!/usr/bin/env python3
"""Check backup source binding with private JSON fixtures and local PHP only.

Docker calls are captured in memory. PHP fixtures never load Wayfindr, connect
to a database, or dispatch a real backup.
"""

import base64
import copy
import importlib.util
import json
from pathlib import Path
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import types
import unittest
from unittest.mock import Mock, patch

sys.dont_write_bytecode = True
ROOT = Path(__file__).absolute().parents[1]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


UP = module("source_binding_updater", "scripts/self-host/updater.py")
PROTECT = module("source_binding_engine", "scripts/self-host/update_protection.py")
IMAGE = "sha256:" + "b" * 64
OPERATION = "1567a42e-bcc8-4bf9-8a57-6a48d107aefe"
STORAGE = "/app/apps/server/storage"
VOLUME = "wayfindr-self-hosting_wayfindr-storage"
PHP = shutil.which("php")


class Configuration:
    def __init__(self, directory):
        self.value = {"install_dir": str(directory), "compose_project": "wayfindr-self-hosting"}
        self.verifications = 0

    def verify_files(self):
        self.verifications += 1


class Capture:
    """Only inspected fixture commands succeed; side effects are never run."""

    def __init__(self):
        self.ids = {service: str(index + 1) * 64 for index, service in enumerate(PROTECT.SERVICES)}
        self.baked = {"PATH": "/usr/local/bin:/usr/bin", "APP_ENV": "production", "APP_DEBUG": "false"}
        self.overrides = {
            "APP_KEY": "fixture-encryption-key",
            "DB_CONNECTION": "pgsql", "DB_HOST": "postgres", "DB_DATABASE": "fixture_database_baseline",
            "DB_PASSWORD": "fixture-password/with=equals", "FILESYSTEM_DISK": "local",
            "AWS_ACCESS_KEY_ID": "fixture-access-key", "AWS_SECRET_ACCESS_KEY": "fixture-secret",
        }
        self.rendered = {
            "services": {"web": {"environment": self.overrides,
                                  "volumes": [{"type": "volume", "source": "wayfindr-storage", "target": STORAGE}]}},
            "volumes": {"wayfindr-storage": {"name": VOLUME}},
        }
        self.environments = {self.ids["web"]: [key + "=" + value for key, value in reversed(list({**self.baked, **self.overrides}.items()))]}
        self.mounts = {container: [{"Type": "volume", "Name": VOLUME, "Driver": "local", "Destination": STORAGE, "RW": True}]
                       for container in self.ids.values()}
        self.calls = []
        self.config_code = 0
        self.rendered_raw = None
        self.running = False
        self.run_code = 78

    def capture(self, command, *, timeout=90):
        self.calls.append(list(command))
        args = command[5:]
        if args[0] == "compose":
            if args[-3:] == ["config", "--format", "json"]:
                return self.config_code, self.rendered_raw or json.dumps(self.rendered).encode()
            if "run" in args:
                return self.run_code, b'{"schema":1}'
            raise AssertionError("Unexpected Compose command in source fixture")
        if args[:2] == ["image", "inspect"]:
            return 0, json.dumps({"env": [key + "=" + value for key, value in self.baked.items()]}).encode()
        if args[0] == "inspect":
            template, container = args[2], args[3]
            if ".Config.Env" in template:
                value = {"env": self.environments[container]}
            elif ".Mounts" in template:
                value = {"mounts": self.mounts[container]}
            else:
                value = {"image": IMAGE, "state": {"Running": self.running, "ExitCode": 0}}
            return 0, json.dumps(value).encode()
        raise AssertionError("Unexpected Docker command in source fixture")


class SourceBindingTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.fixture = Capture()
        self.config = Configuration(self.root)
        self.api = types.SimpleNamespace(Refusal=UP.Refusal, capture=self.fixture.capture,
                                         strict_json=UP.strict_json, trusted=lambda _: None, encoded=UP.encoded)
        self.engine = PROTECT.DockerEngine(self.config, self.api, self.root)
        override = self.root / "protection" / OPERATION / "image.yml"
        override.parent.mkdir(parents=True)
        override.write_bytes(UP.encoded({"services": {"web": {"image": IMAGE}}}))

    def assert_read_only(self):
        for command in self.fixture.calls:
            args = command[5:]
            allowed = args[0] == "inspect" or args[:2] == ["image", "inspect"] or (
                args[0] == "compose" and args[-3:] == ["config", "--format", "json"])
            self.assertTrue(allowed, "Source admission must finish before any side effect")

    def refusal(self, action=None):
        with self.assertRaises(UP.Refusal) as failure:
            (action or (lambda: self.engine.require_source(self.fixture.ids, IMAGE)))()
        self.assertEqual("source_changed", failure.exception.reason)
        self.assert_read_only()

    def context(self):
        return {"containers": self.fixture.ids, "environment_binding": self.engine.environment_binding(self.fixture.ids["web"]),
                "capture_binding_sha256": "d" * 64}

    def wrapper(self):
        context = self.context()
        with self.assertRaises(UP.Refusal) as failure:
            self.engine.backup(OPERATION, IMAGE, context)
        self.assertEqual("backup_failed", failure.exception.reason)
        command = next(command for command in self.fixture.calls if "run" in command[5:])
        index = command.index("-r")
        return context, command, command[index + 1], command[index + 2:]

    def test_unchanged_runtime_including_image_defaults_and_reordered_environment_passes(self):
        self.engine.require_source(self.fixture.ids, IMAGE)
        self.assertEqual(2, self.config.verifications)
        self.assert_read_only()
        inspected = [command[-1] for command in self.fixture.calls if command[5] == "inspect" and ".Mounts" in command[-2]]
        self.assertEqual(set(self.fixture.ids.values()), set(inspected))

    def test_compose_override_and_explicit_unset_of_image_default_pass(self):
        self.fixture.overrides["APP_ENV"] = "staging"
        self.fixture.overrides["APP_DEBUG"] = None
        expected = {**self.fixture.baked, **self.fixture.overrides}
        self.fixture.environments[self.fixture.ids["web"]] = [key + "=" + value for key, value in expected.items() if value is not None]
        self.engine.require_source(self.fixture.ids, IMAGE)
        self.assert_read_only()

    def test_database_attachment_and_aws_drift_is_rejected(self):
        for key in ("DB_DATABASE", "DB_PASSWORD", "FILESYSTEM_DISK", "AWS_ACCESS_KEY_ID", "AWS_SECRET_ACCESS_KEY"):
            with self.subTest(key=key):
                self.setUp()
                self.fixture.overrides[key] = "changed-after-container-start"
                self.refusal()

    def test_added_or_removed_configuration_and_runtime_variables_are_rejected(self):
        for case in ("configured_extra", "configured_missing", "runtime_extra", "runtime_missing"):
            with self.subTest(case=case):
                self.setUp()
                values = self.fixture.environments[self.fixture.ids["web"]]
                if case == "configured_extra":
                    self.fixture.overrides["NEW_SETTING"] = "new"
                elif case == "configured_missing":
                    del self.fixture.overrides["DB_DATABASE"]
                elif case == "runtime_extra":
                    values.append("UNREVIEWED_SETTING=extra")
                else:
                    self.fixture.environments[self.fixture.ids["web"]] = [item for item in values if not item.startswith("DB_DATABASE=")]
                self.refusal()

    def test_runtime_environment_rejects_duplicate_malformed_and_invalid_keys(self):
        for invalid in ("DB_DATABASE=duplicate", "no-equals", "=empty-key", "BAD-NAME=value", 42):
            with self.subTest(invalid=invalid):
                self.setUp()
                self.fixture.environments[self.fixture.ids["web"]].append(invalid)
                self.refusal()

    def test_baked_environment_rejects_duplicate_or_malformed_values(self):
        for values in (["APP_ENV=one", "APP_ENV=two"], ["bad"], None):
            with self.subTest(values=values):
                self.setUp()
                original = self.fixture.capture

                def capture(command, *, timeout=90):
                    if command[5:7] == ["image", "inspect"]:
                        self.fixture.calls.append(list(command))
                        return 0, json.dumps({"env": values}).encode()
                    return original(command, timeout=timeout)

                self.api.capture = capture
                self.refusal()

    def test_rendered_environment_rejects_non_string_values_and_invalid_keys(self):
        for key, value in (("DB_DATABASE", 7), ("DB_DATABASE", True), ("DB_DATABASE", []), ("BAD-NAME", "value")):
            with self.subTest(key=key, value=value):
                self.setUp()
                self.fixture.overrides[key] = value
                self.refusal()

    def test_render_failed_or_duplicate_json_is_rejected(self):
        for code, raw in ((1, None), (0, b'{"services":{},"services":{}}')):
            with self.subTest(code=code, raw=raw):
                self.setUp()
                self.fixture.config_code, self.fixture.rendered_raw = code, raw
                self.refusal()

    def test_original_web_volume_must_match_reviewed_storage(self):
        for key, value in (("Name", "another-installation-storage"), ("Driver", "remote-plugin"),
                           ("RW", False), ("RW", "true"), ("Type", "bind")):
            with self.subTest(key=key, value=value):
                self.setUp()
                self.fixture.mounts[self.fixture.ids["web"]][0][key] = value
                self.refusal()

    def test_each_original_application_service_must_share_the_same_volume(self):
        for service in PROTECT.SERVICES[1:]:
            with self.subTest(service=service):
                self.setUp()
                self.fixture.mounts[self.fixture.ids[service]][0]["Name"] = "other-volume"
                self.refusal()

    def test_missing_duplicate_or_malformed_runtime_storage_mount_is_rejected(self):
        for mounts in ([], None, [{"Destination": STORAGE}], [
                {"Type": "volume", "Name": VOLUME, "Driver": "local", "Destination": STORAGE, "RW": True}] * 2):
            with self.subTest(mounts=mounts):
                self.setUp()
                self.fixture.mounts[self.fixture.ids["web"]] = mounts
                self.refusal()

    def test_reviewed_volume_name_source_type_and_permissions_are_checked(self):
        for case in ("name_changed", "invalid_name", "missing_volume", "read_only", "bind", "duplicate", "missing"):
            with self.subTest(case=case):
                self.setUp()
                storage = self.fixture.rendered["services"]["web"]["volumes"]
                volume = self.fixture.rendered["volumes"]["wayfindr-storage"]
                if case == "name_changed":
                    volume["name"] = "other-volume"
                elif case == "invalid_name":
                    volume["name"] = "../other-volume"
                elif case == "missing_volume":
                    self.fixture.rendered["volumes"].clear()
                elif case == "read_only":
                    storage[0]["read_only"] = True
                elif case == "bind":
                    storage[0]["type"] = "bind"
                elif case == "duplicate":
                    storage.append(copy.deepcopy(storage[0]))
                else:
                    storage.clear()
                self.refusal()

    def test_unrelated_mounts_do_not_change_storage_identity(self):
        for mounts in self.fixture.mounts.values():
            mounts.append({"Type": "bind", "Destination": "/run/wayfindr-updater", "RW": False})
        self.fixture.rendered["services"]["web"]["volumes"].append({"type": "bind", "target": "/run/wayfindr-updater"})
        self.engine.require_source(self.fixture.ids, IMAGE)
        self.assert_read_only()

    def test_backup_source_drift_refuses_before_creating_or_bootstrapping_a_oneoff(self):
        context = self.context()
        self.fixture.calls.clear()
        self.fixture.overrides["DB_DATABASE"] = "wrong-database"
        self.refusal(lambda: self.engine.backup(OPERATION, IMAGE, context))

    def test_initial_baseline_source_drift_refuses_before_the_first_hold(self):
        self.config.installation_id = OPERATION
        self.config.value["overlay_sha256"] = "c" * 64
        source = {"version": "1.1.1", "commit": "c" * 40}
        journal = types.SimpleNamespace(status=lambda _: {"operation": {"source": source}})
        records = {container: {"id": container, "project": self.config.value["compose_project"], "service": service,
                               "image": IMAGE, "restarts": 0,
                               "state": {"Running": True, "OOMKilled": False, "Paused": False,
                                         "Restarting": False, "Dead": False, "Error": ""}}
                   for service, container in self.fixture.ids.items()}
        blocked = {name: Mock(side_effect=AssertionError("Source admission must precede " + name))
                   for name in ("window", "writers", "dependencies", "web_status", "effective_keys", "drain", "backup")}
        self.fixture.overrides["DB_PASSWORD"] = "changed-after-original-start"
        protector = PROTECT.Protector(self.config, journal, self.root, self.api, self.engine, secure=False)
        with patch.object(self.engine, "service_ids", return_value=self.fixture.ids), \
                patch.object(self.engine, "inspect", side_effect=lambda container: records[container]), \
                patch.object(self.engine, "image_source", return_value=source), \
                patch.object(self.engine, "check_selected_image"), patch.object(self.engine, "stop_supported"), \
                patch.multiple(self.engine, **blocked):
            self.refusal(lambda: protector.baseline(OPERATION))
        for method in blocked.values():
            method.assert_not_called()

    def test_stopped_web_recovery_source_drift_refuses_before_fence_oneoff(self):
        self.fixture.running = False
        self.fixture.overrides["FILESYSTEM_DISK"] = "wrong-disk"
        self.refusal(lambda: self.engine.ensure_window(self.fixture.ids, OPERATION, IMAGE))

    def test_environment_binding_is_a_private_order_independent_digest(self):
        first = self.engine.environment_binding(self.fixture.ids["web"])
        self.fixture.environments[self.fixture.ids["web"]].reverse()
        second = self.engine.environment_binding(self.fixture.ids["web"])
        self.assertEqual(first, second)
        self.assertEqual({"sha256", "keys"}, set(first))
        self.assertEqual(sorted({**self.fixture.baked, **self.fixture.overrides}), first["keys"])
        for value in self.fixture.overrides.values():
            self.assertNotIn(value, json.dumps(first))

    @unittest.skipUnless(PHP, "PHP CLI is required to execute the private wrapper fixture")
    def test_generated_php_is_valid_and_environment_refusal_precedes_bootstrap(self):
        self.fixture.overrides["DB_PASSWORD"] = 'fixture-quoted/="✌️🍻"'
        values = {**self.fixture.baked, **self.fixture.overrides}
        self.fixture.environments[self.fixture.ids["web"]] = [key + "=" + value for key, value in values.items()]
        context, command, wrapper, arguments = self.wrapper()
        self.assertIn(PROTECT.DB_QUIESCENCE_SQL, wrapper)
        lint = subprocess.run([PHP, "-l"], input="<?php\n" + wrapper, text=True, capture_output=True, timeout=15)
        self.assertEqual(0, lint.returncode, lint.stderr)
        self.assertEqual(context["environment_binding"]["keys"], json.loads(base64.b64decode(arguments[1])))
        for key in ("APP_KEY", "DB_DATABASE", "DB_PASSWORD", "AWS_ACCESS_KEY_ID", "AWS_SECRET_ACCESS_KEY"):
            self.assertNotIn(self.fixture.overrides[key], json.dumps(command, ensure_ascii=False))
        original = {**self.fixture.baked, **self.fixture.overrides}
        for case in ("changed", "missing"):
            with self.subTest(case=case):
                environment = original.copy()
                if case == "changed":
                    environment["DB_PASSWORD"] = "changed-before-PHP"
                else:
                    environment.pop("DB_DATABASE")
                result = subprocess.run([PHP, "-r", wrapper, *arguments], cwd=self.root, env=environment,
                                        text=True, capture_output=True, timeout=15)
                self.assertEqual(78, result.returncode, result.stderr)
                self.assertEqual("", result.stdout)
                self.assertEqual("", result.stderr)
        accepted = subprocess.run([PHP, "-r", wrapper, *arguments], cwd=self.root, env=original,
                                  text=True, capture_output=True, timeout=15)
        self.assertNotEqual(78, accepted.returncode)
        self.assertIn("vendor/autoload.php", accepted.stderr)

    def php_application_fixture(self):
        _, _, wrapper, arguments = self.wrapper()
        (self.root / "vendor").mkdir()
        (self.root / "bootstrap").mkdir()
        (self.root / "vendor/autoload.php").write_text(r'''<?php
namespace App\Support\Backup {
    class BackupService {
        public static function appKeyFingerprints() { return \fixture_data()["fingerprints"]; }
    }
}
namespace Symfony\Component\Console\Input {
    class ArrayInput {
        public function __construct(public array $arguments) {}
    }
}
namespace Symfony\Component\Console\Output { class ConsoleOutput {} }
namespace Illuminate\Support\Facades {
    class DB {
        public static function connection($name = null) { return new self; }
        public static function getDriverName() { return \fixture_data()["postgres_driver"] ?? "pgsql"; }
        public static function selectOne($query, $bindings = [], $useReadPdo = true) {
            file_put_contents("query-ran", $query . "\n", FILE_APPEND);
            if (\fixture_data()["postgres_failure"] ?? false) { throw new \RuntimeException("fixture database unavailable"); }
            return (object) ["writers" => \fixture_data()["postgres_writers"] ?? 0];
        }
    }
}
namespace {
    function fixture_data() { return json_decode(file_get_contents("fixture-data.json"), true, 512, JSON_THROW_ON_ERROR); }
    class FixtureConfig {
        public function get($key) { return fixture_data()[$key]; }
    }
    class FixtureApplication extends \ArrayObject {
        public function __construct() { parent::__construct(["config" => new FixtureConfig]); }
        public function make($class) { return new FixtureKernel; }
    }
    class FixtureKernel {
        public function bootstrap() { file_put_contents("bootstrap-ran", "yes"); }
        public function handle($input, $output) {
            if ($input->arguments["command"] !== "wayfindr:protective-backup") { return 79; }
            file_put_contents("backup-ran", "yes");
            echo json_encode(["fixture" => "backup", "operation" => $input->arguments["operation"]]);
            return 0;
        }
        public function terminate($input, $status) { file_put_contents("terminate-ran", "yes"); }
    }
    // Only the bounded-wait subprocess disables these built-ins. Production
    // wrapper code remains unchanged and normal fixture runs use real clocks.
    if (!function_exists("time")) {
        function time() { static $moment = 0; $result = $moment; $moment += 121; return $result; }
    }
    if (!function_exists("microtime")) {
        function microtime($asFloat = false) { static $moment = 0.0; $result = $moment; $moment += 121.0; return $asFloat ? $result : "0 " . $result; }
    }
    if (!function_exists("usleep")) { function usleep($microseconds) {} }
}
''')
        (self.root / "bootstrap/app.php").write_text("<?php return new FixtureApplication;\n")
        configuration = {
            "database": {"connections": {"pgsql": {"database": "fixture_database_baseline", "password": "fixture-db-secret"}}},
            "filesystems": {"disks": {"s3": {"key": "fixture-s3-key", "secret": "fixture-s3-secret"}}},
            "wayfindr.attachments": {"disk": "attachments"},
            "wayfindr.backup": {"offsite_disk": "s3"},
            "wayfindr.erasure": {"ledger_path": "/app/apps/server/storage/app/erasure-ledger"},
            "fingerprints": ["a" * 64],
        }
        data_path = self.root / "fixture-data.json"
        data_path.write_text(json.dumps(configuration))
        environment = {**self.fixture.baked, **self.fixture.overrides}
        digest_php = 'require "vendor/autoload.php";$a=require "bootstrap/app.php";echo hash("sha256",serialize(' + PROTECT.CAPTURE_BINDING + '));'
        digest = subprocess.run([PHP, "-r", digest_php], cwd=self.root, env=environment,
                                text=True, capture_output=True, timeout=15)
        self.assertEqual(0, digest.returncode, digest.stderr)
        self.assertRegex(digest.stdout, r"^[a-f0-9]{64}$")
        arguments[2] = digest.stdout
        return wrapper, arguments, configuration, data_path, environment

    @unittest.skipUnless(PHP, "PHP CLI is required to execute the private wrapper fixture")
    def test_effective_config_drift_refuses_after_bootstrap_before_backup_dispatch(self):
        wrapper, arguments, configuration, data_path, environment = self.php_application_fixture()
        for key in ("database", "filesystems", "wayfindr.attachments", "wayfindr.backup", "wayfindr.erasure", "fingerprints"):
            with self.subTest(key=key):
                altered = copy.deepcopy(configuration)
                altered[key] = ["changed-effective-setting"]
                data_path.write_text(json.dumps(altered))
                for name in ("bootstrap-ran", "backup-ran", "terminate-ran"):
                    (self.root / name).unlink(missing_ok=True)
                result = subprocess.run([PHP, "-r", wrapper, *arguments], cwd=self.root, env=environment,
                                        text=True, capture_output=True, timeout=15)
                self.assertEqual(78, result.returncode, result.stderr)
                self.assertTrue((self.root / "bootstrap-ran").is_file())
                self.assertFalse((self.root / "backup-ran").exists())
                self.assertFalse((self.root / "terminate-ran").exists())
                self.assertEqual("", result.stdout)
                self.assertEqual("", result.stderr)
        data_path.write_text(json.dumps(configuration))
        accepted = subprocess.run([PHP, "-r", wrapper, *arguments], cwd=self.root, env=environment,
                                  text=True, capture_output=True, timeout=15)
        self.assertEqual(0, accepted.returncode, accepted.stderr)
        self.assertEqual({"fixture": "backup", "operation": OPERATION}, json.loads(accepted.stdout))
        self.assertTrue((self.root / "backup-ran").is_file())
        self.assertTrue((self.root / "terminate-ran").is_file())

    @unittest.skipUnless(PHP, "PHP CLI is required to execute the private wrapper fixture")
    def test_postgres_idle_dispatches_and_busy_wrong_driver_or_query_error_refuses(self):
        wrapper, arguments, configuration, data_path, environment = self.php_application_fixture()
        for case in ("idle", "busy", "wrong_driver", "query_error"):
            with self.subTest(case=case):
                altered = copy.deepcopy(configuration)
                altered["postgres_writers"] = 1 if case == "busy" else 0
                altered["postgres_driver"] = "sqlite" if case == "wrong_driver" else "pgsql"
                altered["postgres_failure"] = case == "query_error"
                data_path.write_text(json.dumps(altered))
                for name in ("bootstrap-ran", "query-ran", "backup-ran", "terminate-ran"):
                    (self.root / name).unlink(missing_ok=True)
                result = subprocess.run([PHP, "-d", "disable_functions=time,microtime,usleep", "-r", wrapper, *arguments],
                                        cwd=self.root, env=environment, text=True, capture_output=True, timeout=15)
                self.assertTrue((self.root / "bootstrap-ran").is_file())
                if case == "idle":
                    self.assertEqual(0, result.returncode, result.stderr)
                    self.assertTrue((self.root / "query-ran").is_file())
                    self.assertEqual(PROTECT.DB_QUIESCENCE_SQL, (self.root / "query-ran").read_text().strip())
                    self.assertTrue((self.root / "backup-ran").is_file())
                    self.assertTrue((self.root / "terminate-ran").is_file())
                else:
                    self.assertNotEqual(0, result.returncode)
                    if case != "query_error":
                        self.assertEqual(78, result.returncode, result.stderr)
                    self.assertFalse((self.root / "backup-ran").exists())
                    self.assertFalse((self.root / "terminate-ran").exists())
                    if case != "query_error":
                        self.assertEqual("", result.stdout)
                    self.assertEqual(case != "wrong_driver", (self.root / "query-ran").exists())

    def test_postgres_quiescence_query_excludes_self_idle_other_database_and_background_backends(self):
        # SQLite evaluates this standard SQL predicate against an in-memory
        # activity fixture; no PostgreSQL connection or persistent DB exists.
        connection = sqlite3.connect(":memory:")
        self.addCleanup(connection.close)
        connection.create_function("current_database", 0, lambda: "wayfindr")
        connection.create_function("pg_backend_pid", 0, lambda: 100)
        connection.execute("CREATE TABLE pg_stat_activity (pid INTEGER, datname TEXT, backend_type TEXT, state TEXT)")
        connection.executemany("INSERT INTO pg_stat_activity VALUES (?, ?, ?, ?)", [
            (100, "wayfindr", "client backend", "active"),
            (101, "wayfindr", "client backend", "idle"),
            (102, "different-database", "client backend", "active"),
            (103, "wayfindr", "autovacuum worker", "active"),
        ])
        query = PROTECT.DB_QUIESCENCE_SQL
        self.assertEqual(0, connection.execute(query).fetchone()[0])
        for state in ("active", "idle in transaction", "idle in transaction (aborted)", None):
            with self.subTest(state=state):
                connection.execute("INSERT INTO pg_stat_activity VALUES (?, ?, ?, ?)", (200, "wayfindr", "client backend", state))
                self.assertEqual(1, connection.execute(query).fetchone()[0])
                connection.execute("DELETE FROM pg_stat_activity WHERE pid = 200")


if __name__ == "__main__":
    unittest.main()
