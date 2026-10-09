#!/usr/bin/env python3
"""Host verifier fixtures use tarfile to build archives; verifier never does."""
import copy
import gzip
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("protection_archive", ROOT / "scripts/self-host/protection_archive.py")
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
ArchiveError = MODULE.ArchiveError
verify_archive = MODULE.verify_archive
validate_receipt = MODULE.validate_receipt
OPERATION = "80117cf8-0a4d-4709-a7d3-2635a24739cd"
COMMIT = "a" * 40


def sha(value):
    return hashlib.sha256(value).hexdigest()


def manifest(files):
    local = sorted({name.split("/")[1] for name in files if name.startswith("attachments/")})
    return {
        "wayfindr_version": "1.1.1",
        "wayfindr_commit": COMMIT,
        "created_at": "2026-10-09T19:30:00.123456Z",
        "attachment_storage_disk": "attachments",
        "includes_local_attachment_binaries": bool(local),
        "local_attachment_disks": local,
        "external_attachment_disks": ["attachments-s3"],
        "database_dump": "pg_dump (PostgreSQL) 17.6",
        "app_key_fingerprints": ["1" * 64, "2" * 64],
        "protective_operation": OPERATION,
        "installation_profile": "image",
        "archive_integrity": {
            "schema": 1, "algorithm": "sha256",
            "members": {name: {"bytes": len(data), "sha256": sha(data)} for name, data in sorted(files.items())},
        },
    }


def tar_bytes(files, raw_manifest, extra=(), format=tarfile.USTAR_FORMAT):
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w", format=format) as archive:
        root = tarfile.TarInfo("./")
        root.type = tarfile.DIRTYPE
        archive.addfile(root)
        for name, data in list(files.items()) + [("manifest.json", raw_manifest)]:
            info = tarfile.TarInfo("./" + name)
            info.size = len(data)
            archive.addfile(info, io.BytesIO(data))
        for info, data in extra:
            archive.addfile(info, io.BytesIO(data) if data is not None else None)
    return buffer.getvalue()


def receipt(archive, raw_manifest):
    return {
        "schema": 1,
        "operation_id": OPERATION,
        "archive_sha256": sha(archive),
        "manifest_sha256": sha(raw_manifest),
        "archive_bytes": len(archive),
        "source": {"version": "1.1.1", "commit": COMMIT, "profile": "image"},
        "coverage": {
            "database": True,
            "local_attachments": True,
            "external_attachment_disks": 1,
            "external_attachments_included": False,
            "external_attachments_verified": False,
            "offsite_configured": False,
            "offsite_uploaded": False,
            "offsite_verification": "not-configured",
            "erasure_ledger_present": False,
        },
        "pruning_suppressed": True,
    }


class ProtectionArchiveTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="wayfindr-archive-")
        self.addCleanup(self.temporary.cleanup)
        self.path = Path(self.temporary.name) / "archive.tar.gz"
        self.files = {"database.sql": b"-- PostgreSQL fixture\nCREATE TABLE fixture (id bigint);\n",
                      "attachments/attachments/key.bin": b"attachment fixture",
                      "attachments/attachments-old/empty.bin": b""}
        self.manifest = manifest(self.files)

    def build(self, files=None, expected=None, extra=(), raw_manifest=None, transform=None, format=tarfile.USTAR_FORMAT):
        files = self.files if files is None else files
        expected = self.manifest if expected is None else expected
        raw_manifest = (json.dumps(expected, indent=4).encode() + b"\n") if raw_manifest is None else raw_manifest
        raw_tar = tar_bytes(files, raw_manifest, extra, format)
        if transform is not None:
            raw_tar = transform(raw_tar)
        compressed = gzip.compress(raw_tar, mtime=0)
        self.path.write_bytes(compressed)
        return receipt(compressed, raw_manifest)

    def rejected(self, value):
        with self.assertRaises(ArchiveError) as raised:
            verify_archive(self.path, value)
        self.assertEqual(str(raised.exception), "protective_backup_verification_failed")

    def test_valid_archive_reports_only_counts_and_safe_public_coverage(self):
        value = self.build()
        self.assertIsNone(validate_receipt(value))
        actual = verify_archive(self.path, value)
        self.assertEqual(actual, {
            "archive_bytes": value["archive_bytes"], "members": 3,
            "database_bytes": len(self.files["database.sql"]),
            "local_attachment_files": 2, "local_attachment_bytes": len(b"attachment fixture"),
            "local_attachment_disks": 2, "external_attachment_disks": 1, "key_fingerprints": 2,
            "source": value["source"], "coverage": value["coverage"],
        })
        output = json.dumps(actual)
        for secret in ("key.bin", "empty.bin", "attachments-old", "pg_dump", "1" * 64):
            self.assertNotIn(secret, output)

    def test_no_local_binary_or_external_disk_is_a_valid_capture(self):
        files = {"database.sql": b"SQL fixture"}
        expected = manifest(files)
        expected["external_attachment_disks"] = []
        value = self.build(files, expected)
        value["coverage"]["external_attachment_disks"] = 0
        actual = verify_archive(self.path, value)
        self.assertEqual(actual["local_attachment_files"], 0)
        self.assertEqual(actual["external_attachment_disks"], 0)

    def test_published_v_prefixed_manifest_matches_canonical_source_receipt(self):
        expected = copy.deepcopy(self.manifest)
        expected["wayfindr_version"] = "v1.1.1"
        value = self.build(expected=expected)
        actual = verify_archive(self.path, value, ["1" * 64, "2" * 64])
        self.assertEqual(actual["source"]["version"], "1.1.1")
        value["source"]["version"] = "v1.1.1"
        self.rejected(value)

    def test_declared_offsite_and_ledger_facts_remain_explicit(self):
        value = self.build()
        value["coverage"].update(offsite_configured=True, offsite_uploaded=True,
                                 offsite_verification="existence-and-size", erasure_ledger_present=True)
        actual = verify_archive(self.path, value)
        self.assertEqual(actual["coverage"], value["coverage"])
        self.assertFalse(actual["coverage"]["external_attachments_verified"])

    def test_frozen_effective_key_set_must_exactly_match_the_archive(self):
        value = self.build()
        actual = verify_archive(self.path, value, ["1" * 64, "2" * 64])
        self.assertEqual(actual["key_fingerprints"], 2)
        self.assertNotIn("1" * 64, json.dumps(actual))
        for expected in ([], ["1" * 64], ["1" * 64, "3" * 64], ["2" * 64, "1" * 64],
                         ["1" * 64, "1" * 64], ["private key fixture"], True):
            with self.subTest(expected_type=type(expected).__name__):
                with self.assertRaises(ArchiveError):
                    verify_archive(self.path, value, expected)

    def test_deadline_interrupts_hashing_and_decompression_with_a_stable_failure(self):
        value = self.build()
        self.assertEqual(verify_archive(self.path, value, timeout_seconds=3600)["members"], 3)
        # Expiry on the second check interrupts the archive hash, before parse.
        with patch.object(MODULE.time, "monotonic", side_effect=[100, 100, 101]):
            with self.assertRaises(ArchiveError) as raised:
                verify_archive(self.path, value, timeout_seconds=0.5)
            self.assertEqual(str(raised.exception), "protective_backup_verification_failed")
        # This archive fits in one compressed hash chunk. Expiry later enters
        # actual USTAR header/body reads rather than merely rejecting admission.
        with patch.object(MODULE.time, "monotonic", side_effect=[100] * 12 + [101]):
            with self.assertRaises(ArchiveError):
                verify_archive(self.path, value, timeout_seconds=0.5)
        for invalid in (0, -1, 3601, True, float("inf"), float("nan"), "1"):
            with self.subTest(timeout_type=type(invalid).__name__):
                with self.assertRaises(ArchiveError):
                    verify_archive(self.path, value, timeout_seconds=invalid)

    def test_missing_files_links_and_nonregular_nodes_fail_sanitized(self):
        value = self.build()
        self.path.unlink()
        self.rejected(value)
        target = self.path.parent / "target"
        target.write_bytes(b"private fixture")
        self.path.symlink_to(target)
        self.rejected(value)
        self.path.unlink()
        self.path.mkdir()
        self.rejected(value)

    @unittest.skipUnless(hasattr(os, "mkfifo"), "POSIX FIFO admission")
    def test_fifo_archive_is_refused_without_waiting_for_a_writer(self):
        value = self.build()
        self.path.unlink()
        os.mkfifo(self.path)
        self.rejected(value)

    def test_receipt_shape_types_source_digest_and_operation_are_strict(self):
        baseline = self.build()
        mutations = [
            lambda value: value.update(schema=True),
            lambda value: value.update(private_path="/private/credential"),
            lambda value: value.pop("manifest_sha256"),
            lambda value: value.update(archive_bytes=True),
            lambda value: value.update(archive_sha256="z" * 64),
            lambda value: value.update(manifest_sha256="0" * 64),
            lambda value: value.update(operation_id="../../private"),
            lambda value: value.update(operation_id="80117cf8-0a4d-4709-a7d3-2635a24739ce"),
            lambda value: value["source"].update(version="1.1.1+private-token"),
            lambda value: value["source"].update(version="1.1.2"),
            lambda value: value["source"].update(commit="b" * 40),
            lambda value: value["source"].update(profile="host"),
            lambda value: value.update(pruning_suppressed=False),
        ]
        for mutation in mutations:
            with self.subTest(mutation=mutation):
                value = copy.deepcopy(baseline)
                mutation(value)
                self.rejected(value)

    def test_public_receipt_validation_is_independent_and_sanitized(self):
        value = receipt(b"not an archive", b"not a manifest")
        # Admission validates shape only; actual bytes are independently checked
        # after the host copies the archive into its private custody directory.
        self.assertIsNone(validate_receipt(value))
        for invalid in (None, [], {"private_key": "secret fixture"}, {**value, "archive_bytes": "secret fixture"}):
            with self.subTest(kind=type(invalid).__name__):
                with self.assertRaises(ArchiveError) as raised:
                    validate_receipt(invalid)
                self.assertEqual(str(raised.exception), "protective_backup_verification_failed")

    def test_coverage_never_falsely_claims_remote_or_failed_offsite_protection(self):
        baseline = self.build()
        mutations = [
            {"database": False}, {"local_attachments": "true"},
            {"external_attachments_verified": True}, {"external_attachments_included": True},
            {"external_attachment_disks": True}, {"external_attachment_disks": 2},
            {"offsite_configured": True}, {"offsite_uploaded": True},
            {"offsite_verification": "fully-verified"}, {"erasure_ledger_present": "false"},
        ]
        for mutation in mutations:
            with self.subTest(mutation=mutation):
                value = copy.deepcopy(baseline)
                value["coverage"].update(mutation)
                self.rejected(value)

    def test_inventory_sizes_hashes_types_and_manifest_metadata_are_checked(self):
        mutations = [
            lambda value: value.update(installation_profile="host"),
            lambda value: value.update(protective_operation="80117cf8-0a4d-4709-a7d3-2635a24739ce"),
            lambda value: value.update(wayfindr_version="1.1.2"),
            lambda value: value.update(created_at="2026-02-30T00:00:00Z"),
            lambda value: value.update(database_dump=""),
            lambda value: value.update(app_key_fingerprints=[]),
            lambda value: value.update(app_key_fingerprints=["private-key"]),
            lambda value: value.update(app_key_fingerprints=["2" * 64, "1" * 64]),
            lambda value: value.update(app_key_fingerprints=["1" * 64, "1" * 64]),
            lambda value: value.update(includes_local_attachment_binaries=False),
            lambda value: value.update(local_attachment_disks=[]),
            lambda value: value.update(external_attachment_disks=["attachments-s3", "attachments-s3"]),
            lambda value: value.update(private_secret="private fixture"),
            lambda value: value["archive_integrity"].update(schema=True),
            lambda value: value["archive_integrity"].update(algorithm="md5"),
            lambda value: value["archive_integrity"]["members"]["database.sql"].update(bytes=True),
            lambda value: value["archive_integrity"]["members"]["database.sql"].update(bytes=0),
            lambda value: value["archive_integrity"]["members"]["database.sql"].update(sha256="0" * 64),
            lambda value: value["archive_integrity"]["members"]["database.sql"].update(extra=True),
            lambda value: value["archive_integrity"]["members"].pop("database.sql"),
            lambda value: value["archive_integrity"]["members"].update({"manifest.json": {"bytes": 0, "sha256": sha(b"")}}),
        ]
        for mutation in mutations:
            with self.subTest(mutation=mutation):
                expected = copy.deepcopy(self.manifest)
                mutation(expected)
                self.rejected(self.build(expected=expected))

    def test_tampered_member_bytes_and_missing_members_are_independently_detected(self):
        for files in ({**self.files, "database.sql": b"tampered SQL"},
                      {name: data for name, data in self.files.items() if name != "database.sql"},
                      {name: data for name, data in self.files.items() if not name.endswith("key.bin")}):
            with self.subTest(files=tuple(files)):
                self.rejected(self.build(files=files))

    def test_all_link_special_pax_longname_and_unknown_members_are_rejected(self):
        kinds = [tarfile.SYMTYPE, tarfile.LNKTYPE, tarfile.CHRTYPE, tarfile.BLKTYPE,
                 tarfile.FIFOTYPE, tarfile.XHDTYPE, tarfile.XGLTYPE, tarfile.GNUTYPE_LONGNAME,
                 tarfile.GNUTYPE_SPARSE]
        for kind in kinds:
            with self.subTest(kind=kind):
                info = tarfile.TarInfo("attachments/attachments/danger")
                info.type = kind
                if kind in (tarfile.SYMTYPE, tarfile.LNKTYPE):
                    info.linkname = "database.sql"
                self.rejected(self.build(extra=[(info, None)]))
        info = tarfile.TarInfo("unexpected-private.env")
        info.size = len(b"private fixture")
        self.rejected(self.build(extra=[(info, b"private fixture")]))

    def test_duplicate_file_directory_and_unsafe_paths_are_rejected(self):
        for name in ("database.sql", "/private/credential", "../outside", "attachments/attachments/../outside",
                     "attachments//key", "attachments/attachments/\\outside", "attachments/attachments/bad\nname"):
            with self.subTest(name=name):
                info = tarfile.TarInfo(name)
                self.rejected(self.build(extra=[(info, b"")]))
        info = tarfile.TarInfo("database.sql")
        info.type = tarfile.DIRTYPE
        self.rejected(self.build(extra=[(info, None)]))

    def test_unknown_directory_and_directory_payload_are_rejected(self):
        info = tarfile.TarInfo("unexpected-directory/")
        info.type = tarfile.DIRTYPE
        self.rejected(self.build(extra=[(info, None)]))
        info = tarfile.TarInfo("attachments/")
        info.type = tarfile.DIRTYPE
        info.size = 1
        self.rejected(self.build(extra=[(info, b"x")]))

    def test_header_checksum_magic_numeric_and_data_padding_are_strict(self):
        def mutate(position, value, checksum=False):
            def transformation(raw):
                header = bytearray(raw)
                header[position:position + len(value)] = value
                if checksum:
                    first = header[:512]
                    first[148:156] = b" " * 8
                    header[148:156] = (f"{sum(first):06o}\0 ").encode()
                return bytes(header)
            return transformation
        for position, value, checksum in [(0, b"x", False), (257, b"invalid", True),
                                           (108, b"\x80", True), (263, b"99", True)]:
            with self.subTest(position=position):
                self.rejected(self.build(transform=mutate(position, value, checksum)))
        # First regular member is the database after the root directory header.
        padding = 1024 + len(self.files["database.sql"])
        self.rejected(self.build(transform=mutate(padding, b"x")))

    def test_duplicate_json_keys_and_nonfinite_constants_are_rejected(self):
        raw = json.dumps(self.manifest).encode()
        duplicate = raw.replace(b'"wayfindr_version": "1.1.1"', b'"wayfindr_version": "1.1.1", "wayfindr_version": "1.1.1"')
        self.rejected(self.build(raw_manifest=duplicate))
        nonfinite = raw.replace(b'"schema": 1', b'"schema": NaN')
        self.rejected(self.build(raw_manifest=nonfinite))

    def test_truncated_archive_gzip_crc_and_nonzero_trailing_data_fail(self):
        value = self.build()
        compressed = self.path.read_bytes()
        for broken in (compressed[:-12], compressed[:-8] + bytes([compressed[-8] ^ 1]) + compressed[-7:]):
            with self.subTest(bytes=len(broken)):
                self.path.write_bytes(broken)
                changed = copy.deepcopy(value)
                changed.update(archive_sha256=sha(broken), archive_bytes=len(broken))
                self.rejected(changed)
        self.rejected(self.build(transform=lambda raw: raw + b"nonzero ignored trailing data"))

    def test_member_manifest_and_trailing_padding_capacity_are_bounded(self):
        value = self.build()
        with patch.object(MODULE, "MAX_MEMBERS", 2):
            self.rejected(value)
        with patch.object(MODULE, "MAX_MANIFEST_BYTES", 32):
            self.rejected(value)
        self.rejected(self.build(transform=lambda raw: raw + bytes(MODULE.MAX_TRAILING_PADDING + 1)))

    def test_ustar_prefix_and_allowed_directory_headers_are_supported(self):
        name = "attachments/attachments/" + "prefix/" * 12 + "key.bin"
        files = {"database.sql": b"SQL fixture", name: b"long path fixture"}
        expected = manifest(files)
        directories = []
        parts = name.split("/")
        for length in range(1, len(parts)):
            info = tarfile.TarInfo("/".join(parts[:length]) + "/")
            info.type = tarfile.DIRTYPE
            directories.append((info, None))
        actual = verify_archive(self.path, self.build(files, expected, directories))
        self.assertEqual(actual["local_attachment_files"], 1)


if __name__ == "__main__":
    unittest.main()
