#!/usr/bin/env python3
"""Run the actual entrypoint with fake PHP and an isolated application tree."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "docker/self-hosting/docker-entrypoint.sh"


class ManagedUpgradeEntrypointTest(unittest.TestCase):
    def setUp(self):
        self.fixture = tempfile.TemporaryDirectory(prefix="wayfindr-entrypoint-")
        self.addCleanup(self.fixture.cleanup)
        self.base = Path(self.fixture.name)
        self.app = self.base / "app"
        (self.app / "storage/framework").mkdir(parents=True)
        self.bin = self.base / "bin"
        self.bin.mkdir()
        self.receipt = self.base / "php-calls"
        fake_php = self.bin / "php"
        fake_php.write_text('#!/bin/sh\nprintf "%s\\n" "$*" >> "$ENTRYPOINT_TEST_RECEIPT"\n')
        fake_php.chmod(0o700)
        self.command = self.bin / "final-command"
        self.command.write_text('#!/bin/sh\nprintf "started\\n" > "$ENTRYPOINT_TEST_STARTED"\n')
        self.command.chmod(0o700)
        self.started = self.base / "started"
        entrypoint = SOURCE.read_text()
        self.assertEqual(entrypoint.count("cd /app/apps/server"), 1)
        # Only relocate the fixed image working directory; all entrypoint logic
        # is executed intact, with neither a runtime test flag nor real artisan.
        self.script = self.base / "entrypoint.sh"
        self.script.write_text(entrypoint.replace("cd /app/apps/server", 'cd "$ENTRYPOINT_TEST_APP"'))
        self.marker = self.app / "storage/framework/managed-upgrade.json"

    def run_entrypoint(self, auto="1"):
        environment = os.environ.copy()
        environment.update({
            "PATH": f"{self.bin}:{environment['PATH']}",
            "ENTRYPOINT_TEST_RECEIPT": str(self.receipt),
            "ENTRYPOINT_TEST_APP": str(self.app),
            "ENTRYPOINT_TEST_STARTED": str(self.started),
            "WAYFINDR_AUTO_MIGRATE": auto,
            "VIEW_COMPILED_PATH": str(self.app / "bootstrap/cache/views"),
        })
        result = subprocess.run(["bash", str(self.script), str(self.command)], env=environment,
                                capture_output=True, text=True, timeout=5)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.started.read_text(), "started\n")
        return result

    def test_unheld_auto_migrate_baseline(self):
        for enabled in ("1", "true"):
            with self.subTest(enabled=enabled):
                self.receipt.unlink(missing_ok=True)
                self.run_entrypoint(enabled)
                self.assertEqual(self.receipt.read_text(), "artisan migrate --force --no-interaction\n")

    def test_unheld_workers_do_not_auto_migrate(self):
        self.run_entrypoint("0")
        self.assertFalse(self.receipt.exists())

    def test_valid_and_corrupt_markers_suppress_migration_and_survive(self):
        for contents in ('{"schema":1,"operation_id":"fixture"}', "corrupt held state", ""):
            with self.subTest(contents=contents):
                self.marker.write_text(contents)
                result = self.run_entrypoint()
                self.assertFalse(self.receipt.exists())
                self.assertEqual(self.marker.read_text(), contents)
                self.assertIn("automatic migrations suppressed", result.stderr)

    def test_directory_marker_suppresses_migration(self):
        self.marker.mkdir()
        self.run_entrypoint()
        self.assertFalse(self.receipt.exists())
        self.assertTrue(self.marker.is_dir())

    def test_dangling_symlink_marker_suppresses_migration(self):
        self.marker.symlink_to(self.base / "absent-marker")
        self.run_entrypoint()
        self.assertFalse(self.receipt.exists())
        self.assertTrue(self.marker.is_symlink())


if __name__ == "__main__":
    unittest.main()
