#!/usr/bin/env python3
"""Independently verify a copied protective archive without extracting any file.

This verifies bytes and the declared capture inventory. It neither restores a
database nor verifies external object stores or decryption-key custody.
"""
from __future__ import annotations

import datetime
import gzip
import hashlib
import json
import math
import os
from pathlib import Path
import re
import stat
import time
import uuid


MAX_MEMBERS = 32768
MAX_MANIFEST_BYTES = 8388608
MAX_TRAILING_PADDING = 1048576
HEX = re.compile(r"[a-f0-9]{64}\Z")
COMMIT = re.compile(r"(?:[a-f0-9]{40}|[a-f0-9]{64})\Z")
VERSION = re.compile(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\Z")
MANIFEST_VERSION = re.compile(r"v?(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\Z")
UUID = re.compile(r"[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\Z")
RECEIPT_FIELDS = {"schema", "operation_id", "archive_sha256", "manifest_sha256", "archive_bytes",
                  "source", "coverage", "pruning_suppressed"}
COVERAGE_FIELDS = {"database", "local_attachments", "external_attachment_disks",
                   "external_attachments_included", "external_attachments_verified", "offsite_configured",
                   "offsite_uploaded", "offsite_verification", "erasure_ledger_present"}
MANIFEST_FIELDS = {"wayfindr_version", "wayfindr_commit", "created_at", "attachment_storage_disk",
                   "includes_local_attachment_binaries", "local_attachment_disks", "external_attachment_disks",
                   "database_dump", "app_key_fingerprints", "protective_operation", "installation_profile",
                   "archive_integrity"}


class ArchiveError(Exception):
    """A stable failure code; paths, archive content and credentials never escape."""

    def __init__(self):
        self.reason = "protective_backup_verification_failed"
        super().__init__(self.reason)


def _require(condition: bool) -> None:
    if not condition:
        raise ArchiveError()


def _integer(value, minimum=0):
    return type(value) is int and value >= minimum


def _text(value, maximum=512):
    return isinstance(value, str) and 0 < len(value) <= maximum and not re.search(r"[\x00-\x1f\x7f]", value)


def _uuid(value):
    return isinstance(value, str) and UUID.fullmatch(value) is not None and str(uuid.UUID(value)) == value


def _hex(value):
    return isinstance(value, str) and HEX.fullmatch(value) is not None


def _object(value, fields):
    return type(value) is dict and set(value) == fields


def _safe_name(value):
    if not _text(value, 256) or value.startswith("/") or "\\" in value:
        return False
    try:
        value.encode("utf-8", errors="strict")
    except UnicodeError:
        return False
    return all(part not in ("", ".", "..") for part in value.split("/"))


def _strict_json(raw):
    def pairs(items):
        result = {}
        for key, value in items:
            _require(key not in result)
            result[key] = value
        return result

    def constant(_value):
        raise ArchiveError()

    value = json.loads(raw.decode("utf-8", errors="strict"), object_pairs_hook=pairs, parse_constant=constant)
    _require(type(value) is dict)
    return value


def _receipt(receipt):
    _require(_object(receipt, RECEIPT_FIELDS))
    _require(type(receipt["schema"]) is int and receipt["schema"] == 1 and _uuid(receipt["operation_id"])
             and _hex(receipt["archive_sha256"]) and _hex(receipt["manifest_sha256"])
             and _integer(receipt["archive_bytes"], 1) and receipt["pruning_suppressed"] is True)
    source = receipt["source"]
    _require(_object(source, {"version", "commit", "profile"}) and _text(source["version"], 128)
             and VERSION.fullmatch(source["version"]) is not None and isinstance(source["commit"], str)
             and COMMIT.fullmatch(source["commit"]) is not None and source["profile"] == "image")
    coverage = receipt["coverage"]
    _require(_object(coverage, COVERAGE_FIELDS))
    _require(coverage["database"] is True and coverage["local_attachments"] is True
             and coverage["external_attachments_included"] is False and coverage["external_attachments_verified"] is False
             and _integer(coverage["external_attachment_disks"]) and type(coverage["offsite_configured"]) is bool
             and type(coverage["offsite_uploaded"]) is bool and type(coverage["erasure_ledger_present"]) is bool)
    _require((coverage["offsite_configured"] and coverage["offsite_uploaded"] and coverage["offsite_verification"] == "existence-and-size")
             or (not coverage["offsite_configured"] and not coverage["offsite_uploaded"] and coverage["offsite_verification"] == "not-configured"))


def validate_receipt(receipt: dict) -> None:
    """Validate the fixed public receipt before allocating root archive custody."""
    try:
        _receipt(receipt)
    except ArchiveError:
        raise
    except Exception:
        raise ArchiveError() from None


def _manifest(raw, receipt, expected_key_fingerprints, check):
    check()
    _require(hashlib.sha256(raw).hexdigest() == receipt["manifest_sha256"])
    value = _strict_json(raw)
    _require(_object(value, MANIFEST_FIELDS))
    source = receipt["source"]
    version = value["wayfindr_version"]
    _require(_text(version, 128) and MANIFEST_VERSION.fullmatch(version) is not None
             and version.removeprefix("v") == source["version"] and value["wayfindr_commit"] == source["commit"]
             and value["installation_profile"] == source["profile"] and value["protective_operation"] == receipt["operation_id"])
    created_at = value["created_at"]
    _require(isinstance(created_at, str) and re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})", created_at) is not None)
    _require(datetime.datetime.fromisoformat(created_at.replace("Z", "+00:00")).tzinfo is not None)
    _require(_text(value["attachment_storage_disk"]) and _text(value["database_dump"])
             and type(value["includes_local_attachment_binaries"]) is bool)
    local = value["local_attachment_disks"]
    external = value["external_attachment_disks"]
    fingerprints = value["app_key_fingerprints"]
    _require(type(local) is list and type(external) is list
             and all(_text(disk) for disk in local + external)
             and len(set(local)) == len(local) and len(set(external)) == len(external)
             and not set(local).intersection(external))
    _require(type(fingerprints) is list and 0 < len(fingerprints) <= MAX_MEMBERS
             and all(_hex(fingerprint) for fingerprint in fingerprints)
             and fingerprints == sorted(set(fingerprints)))
    if expected_key_fingerprints is not None:
        _require(type(expected_key_fingerprints) is list and 0 < len(expected_key_fingerprints) <= MAX_MEMBERS
                 and all(_hex(fingerprint) for fingerprint in expected_key_fingerprints)
                 and expected_key_fingerprints == sorted(set(expected_key_fingerprints))
                 and fingerprints == expected_key_fingerprints)
    integrity = value["archive_integrity"]
    _require(_object(integrity, {"schema", "algorithm", "members"}) and type(integrity["schema"]) is int
             and integrity["schema"] == 1 and integrity["algorithm"] == "sha256")
    inventory = integrity["members"]
    _require(type(inventory) is dict and 0 < len(inventory) <= MAX_MEMBERS and "database.sql" in inventory)
    disks = set()
    directories = {""}
    for name, facts in inventory.items():
        check()
        _require(_safe_name(name) and _object(facts, {"bytes", "sha256"})
                 and _integer(facts["bytes"]) and _hex(facts["sha256"]))
        if name != "database.sql":
            parts = name.split("/")
            _require(len(parts) >= 3 and parts[0] == "attachments" and parts[1].startswith("attachments"))
            disks.add(parts[1])
        parts = name.split("/")
        for length in range(1, len(parts)):
            directories.add("/".join(parts[:length]))
    _require(not set(inventory).intersection(directories)
             and list(inventory) == sorted(inventory)
             and inventory["database.sql"]["bytes"] > 0 and set(local) == disks
             and value["includes_local_attachment_binaries"] == bool(disks)
             and len(external) == receipt["coverage"]["external_attachment_disks"])
    return value, inventory, directories


def _read(stream, count, check):
    chunks = []
    remaining = count
    while remaining:
        check()
        chunk = stream.read(min(remaining, 65536))
        check()
        _require(bool(chunk))
        chunks.append(chunk)
        remaining -= len(chunk)
    return b"".join(chunks)


def _octal(field):
    text = field.strip(b"\0 ")
    _require(re.fullmatch(rb"[0-7]{1,12}", text) is not None)
    return int(text, 8)


def _field(header, start, size):
    field = header[start:start + size].rstrip(b"\0")
    _require(b"\0" not in field)
    return field.decode("utf-8", errors="strict")


def _headers(stream, check):
    actual = {}
    directories = set()
    seen = set()
    manifest_bytes = None
    while True:
        check()
        header = _read(stream, 512, check)
        if header == bytes(512):
            _require(_read(stream, 512, check) == bytes(512))
            padding = 0
            while True:
                check()
                tail = stream.read(65536)
                check()
                if not tail:
                    break
                padding += len(tail)
                _require(padding <= MAX_TRAILING_PADDING and not tail.strip(b"\0"))
            break
        _require(header[257:263] == b"ustar\0" and header[263:265] == b"00"
                 and header[157:257] == bytes(100))
        _require(_octal(header[148:156]) == sum(header[:148] + b" " * 8 + header[156:]))
        # Reject binary/base-256 GNU numeric fields and malformed octal metadata.
        for start, size in ((100, 8), (108, 8), (116, 8), (136, 12)):
            _octal(header[start:start + size])
        size = _octal(header[124:136])
        name = _field(header, 0, 100)
        prefix = _field(header, 345, 155)
        name = (prefix + "/" if prefix else "") + name
        if name.startswith("./"):
            name = name[2:]
        kind = header[156:157]
        _require(kind in (b"0", b"\0", b"5"))
        if kind == b"5":
            name = name.rstrip("/")
            _require(size == 0 and (name == "" or _safe_name(name)))
            directories.add(name)
        else:
            _require(_safe_name(name) and (name in ("database.sql", "manifest.json") or name.startswith("attachments/")))
            _require(len(actual) < MAX_MEMBERS + 1)
        _require(name not in seen and len(seen) < MAX_MEMBERS * 4)
        seen.add(name)
        if name == "manifest.json":
            _require(size <= MAX_MANIFEST_BYTES and kind != b"5")
        digest = hashlib.sha256()
        content = []
        remaining = size
        while remaining:
            check()
            chunk = _read(stream, min(remaining, 65536), check)
            digest.update(chunk)
            if name == "manifest.json":
                content.append(chunk)
            remaining -= len(chunk)
        if size % 512:
            _require(not _read(stream, 512 - size % 512, check).strip(b"\0"))
        if kind != b"5":
            if name == "manifest.json":
                _require(manifest_bytes is None)
                manifest_bytes = b"".join(content)
            else:
                actual[name] = {"bytes": size, "sha256": digest.hexdigest()}
    _require(manifest_bytes is not None)
    return manifest_bytes, actual, directories


def verify_archive(path: Path, receipt: dict, expected_key_fingerprints=None, *, timeout_seconds=3600) -> dict:
    """Verify the exact compressed bytes and every declared regular tar member.

    The caller retains the archive in its trusted root-owned custody directory.
    Input receipt claims about offsite copies are retained as claims; only the
    local archive byte hashes and capture inventory are independently verified.
    When supplied, the manifest key fingerprints must exactly match the host's
    frozen effective running-key set. The software deadline is checked between
    bounded reads and during member verification; callers may shorten it.
    """
    try:
        _require(type(timeout_seconds) in (int, float) and math.isfinite(timeout_seconds)
                 and 0 < timeout_seconds <= 3600)
        deadline = time.monotonic() + timeout_seconds

        def check():
            _require(time.monotonic() < deadline)

        check()
        validate_receipt(receipt)
        node = path.lstat()
        _require(stat.S_ISREG(node.st_mode))
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC | os.O_NONBLOCK)
        with os.fdopen(descriptor, "rb") as raw:
            before = os.fstat(raw.fileno())
            _require(stat.S_ISREG(before.st_mode) and before.st_size == receipt["archive_bytes"]
                     and (node.st_dev, node.st_ino) == (before.st_dev, before.st_ino))
            digest = hashlib.sha256()
            for chunk in iter(lambda: raw.read(65536), b""):
                check()
                digest.update(chunk)
            _require(digest.hexdigest() == receipt["archive_sha256"])
            raw.seek(0)
            with gzip.GzipFile(fileobj=raw, mode="rb") as stream:
                manifest_bytes, actual, directories = _headers(stream, check)
            after = os.fstat(raw.fileno())
            _require((before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns, before.st_ctime_ns)
                     == (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns, after.st_ctime_ns))
        manifest, inventory, allowed_directories = _manifest(manifest_bytes, receipt, expected_key_fingerprints, check)
        _require(actual == inventory and directories.issubset(allowed_directories))
        attachments = {name: facts for name, facts in actual.items() if name != "database.sql"}
        result = {
            "archive_bytes": receipt["archive_bytes"],
            "members": len(actual),
            "database_bytes": actual["database.sql"]["bytes"],
            "local_attachment_files": len(attachments),
            "local_attachment_bytes": sum(facts["bytes"] for facts in attachments.values()),
            "local_attachment_disks": len(manifest["local_attachment_disks"]),
            "external_attachment_disks": len(manifest["external_attachment_disks"]),
            "key_fingerprints": len(manifest["app_key_fingerprints"]),
            "source": dict(receipt["source"]),
            "coverage": dict(receipt["coverage"]),
        }
        check()
        return result
    except ArchiveError:
        raise
    except Exception:
        raise ArchiveError() from None
