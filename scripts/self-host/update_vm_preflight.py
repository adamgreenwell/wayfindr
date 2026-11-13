#!/usr/bin/env python3
"""Read-only publication gate for disposable managed-update VM qualification.

``ready`` means public declarations are available for a later VM run. It does
not verify a baked image, authorize host mutation, or establish qualification.
No downloaded source is imported or executed. The VM runner must independently
verify the actual installed/pulled image, enrollment, guards and serving origin.
"""

from __future__ import annotations

import argparse
import ast
import base64
import hashlib
import http.client
import json
from pathlib import Path
import re
import socket
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


REPOSITORY = "adamgreenwell/wayfindr"
API = "https://api.github.com/repos/" + REPOSITORY
RELEASES = "https://github.com/" + REPOSITORY + "/releases"
IMAGE = "ghcr.io/" + REPOSITORY
MAX_BODY = 2_000_000
FETCH_TIMEOUT = 15
MAX_FETCHES = 32
MAX_HELPER_FETCHES = 48  # An independent third stable release plus complete helper inputs.
MAX_TAG_CHAIN = 4
STABLE = re.compile(r"v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\Z")
COMMIT = re.compile(r"(?:[0-9a-f]{40}|[0-9a-f]{64})\Z")
DIGEST = re.compile(r"sha256:[0-9a-f]{64}\Z")
SEMVER = re.compile(r"(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-((?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?\Z")
CAPABILITIES = ["plan", "status", "start", "history", "cancel"]
UPDATER = "scripts/self-host/updater.py"
INSTALLATION = "apps/server/app/Support/Updates/InstallationCapabilities.php"
PROBE = "apps/server/app/Console/Commands/HostUpdaterStatusCommand.php"
HELPER_FILES = ("updater.py", "update_protection.py", "update_apply.py", "update_artifacts.py", "protection_archive.py")
UPGRADE = "scripts/self-host/upgrade-updater.py"
PROVENANCE_FILES = ("scripts/self-host/install.sh", "docker/self-hosting/compose.yml",
                    "docker/self-hosting/compose.updater.yml", "docker/self-hosting/wayfindr-updater.service",
                    "docker/self-hosting/wayfindr-updater.conf")
REQUIRED_FILES = (
    "scripts/self-host/install.sh",
    "scripts/self-host/enroll-updater.py",
    UPDATER,
    "scripts/self-host/update_protection.py",
    "scripts/self-host/update_apply.py",
    "scripts/self-host/update_artifacts.py",
    "docker/self-hosting/compose.yml",
    "docker/self-hosting/compose.updater.yml",
    "docker/self-hosting/wayfindr-updater.service",
    "docker/self-hosting/wayfindr-updater.conf",
    "apps/server/app/Console/Commands/UpdatePlanCommand.php",
    "apps/server/app/Console/Commands/UpgradeWindowCommand.php",
    "apps/server/app/Console/Commands/ProtectiveBackupCommand.php",
    "apps/server/app/Console/Commands/ManagedApplyCommand.php",
    PROBE,
    INSTALLATION,
    "apps/server/app/Support/Updates/HostUpdaterClient.php",
    "apps/server/app/Support/Updates/OperatorUpdateAuthorization.php",
    "apps/server/app/Support/Updates/OperatorUpdatePlanReview.php",
    "apps/server/app/Http/Controllers/OperatorUpdateController.php",
    "apps/server/routes/web.php",
    "apps/server/resources/views/operator/updates.blade.php",
    "apps/server/resources/views/components/operator-update-script.blade.php",
    "apps/server/resources/views/components/operator-update-style.blade.php",
    "apps/server/resources/views/components/operator-update-resume.blade.php",
    "apps/server/lang/en/operator_updates.php",
    "apps/server/lang/de/operator_updates.php",
    "apps/server/lang/it/operator_updates.php",
)
REASONS = frozenset({
    "public_url_refused", "public_redirect_refused", "public_metadata_unavailable",
    "public_response_too_large", "public_fetch_timeout", "public_artifact_not_found",
    "public_metadata_invalid", "public_fetch_limit", "tag_identity_invalid", "tag_chain_invalid",
    "release_asset_invalid", "release_asset_missing", "release_asset_checksum_invalid",
    "release_manifest_invalid", "source_tree_invalid", "source_blob_invalid",
    "helper_protocol_unpublished", "published_release_invalid", "image_digest_invalid", "helper_not_published",
    "helper_bundle_unpublished",
})


class Refusal(Exception):
    """Only fixed classification codes can reach the report."""

    def __init__(self, code):
        self.code = code if isinstance(code, str) and code in REASONS else "public_metadata_unavailable"
        super().__init__(self.code)


def _stable(value):
    return isinstance(value, str) and len(value) <= 128 and STABLE.fullmatch(value) is not None


def _sha(value):
    return isinstance(value, str) and COMMIT.fullmatch(value) is not None


def _numbers(tag):
    return tuple(int(part) for part in tag[1:].split("."))


def _valid_url(url):
    if not isinstance(url, str) or len(url) > 4096:
        return False
    sha = r"(?:[0-9a-f]{40}|[0-9a-f]{64})"
    tag = r"v(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)"
    patterns = (
        re.escape(API) + r"/releases/tags/" + tag,
        re.escape(API) + r"/git/ref/tags/" + tag,
        re.escape(API) + r"/git/(?:tags|commits|blobs)/" + sha,
        re.escape(API) + r"/git/trees/" + sha + r"\?recursive=1",
        re.escape(RELEASES) + r"/download/" + tag + r"/(?:release-manifest\.json|release-image-digest\.txt)",
    )
    return any(re.fullmatch(pattern, url) for pattern in patterns)


def _valid_asset_redirect(url):
    if not isinstance(url, str) or len(url) > 8192:
        return False
    try:
        parsed = urllib.parse.urlsplit(url)
        return (
            parsed.scheme == "https"
            and parsed.hostname in {"release-assets.githubusercontent.com", "objects.githubusercontent.com"}
            and parsed.username is None and parsed.password is None
            and parsed.port is None and not parsed.fragment
            and re.fullmatch(r"/github-production-release-asset(?:-2e65be)?/[0-9]+/[0-9a-f-]{36}", parsed.path) is not None
        )
    except ValueError:
        return False


class _Redirects(urllib.request.HTTPRedirectHandler):
    def __init__(self, asset, deadline):
        self.asset = asset
        self.deadline = deadline
        self.count = 0

    def redirect_request(self, request, response, code, message, headers, url):
        self.count += 1
        if not self.asset or self.count > 3 or time.monotonic() >= self.deadline or not _valid_asset_redirect(url):
            raise Refusal("public_redirect_refused")
        return super().redirect_request(request, response, code, message, headers, url)


def public_fetch(url):
    """Fetch one bounded unauthenticated public object; no ambient proxy/auth."""
    if not _valid_url(url):
        raise Refusal("public_url_refused")
    deadline = time.monotonic() + FETCH_TIMEOUT
    asset = url.startswith(RELEASES + "/download/")
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), _Redirects(asset, deadline))
    request = urllib.request.Request(url, headers={
        "User-Agent": "Wayfindr-disposable-vm-preflight/1",
        "Accept": "application/octet-stream" if asset else "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
    })
    try:
        with opener.open(request, timeout=FETCH_TIMEOUT) as response:
            if response.status != 200:
                raise Refusal("public_metadata_unavailable")
            length = response.headers.get("Content-Length")
            if length is not None and (not length.isdigit() or len(length) > 10 or int(length) > MAX_BODY):
                raise Refusal("public_response_too_large")
            result = bytearray()
            while True:
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise Refusal("public_fetch_timeout")
                # read1 performs one underlying read, so a trickling peer cannot
                # reset an unlimited series of reads inside a single read(n).
                connection = getattr(getattr(getattr(response, "fp", None), "raw", None), "_sock", None)
                if connection is not None:
                    connection.settimeout(remaining)
                chunk = response.read1(min(65536, MAX_BODY + 1 - len(result)))
                result.extend(chunk)
                if len(result) > MAX_BODY:
                    raise Refusal("public_response_too_large")
                if not chunk:
                    return bytes(result)
    except urllib.error.HTTPError as error:
        code = "public_artifact_not_found" if error.code == 404 else "public_metadata_unavailable"
        error.close()
        raise Refusal(code) from None
    except (TimeoutError, socket.timeout):
        raise Refusal("public_fetch_timeout") from None
    except (urllib.error.URLError, OSError, http.client.HTTPException):
        raise Refusal("public_metadata_unavailable") from None


def _object(raw):
    def pairs(entries):
        value = {}
        for key, item in entries:
            if key in value:
                raise ValueError()
            value[key] = item
        return value

    def invalid(_):
        raise ValueError()

    try:
        value = json.loads(raw.decode("utf-8"), object_pairs_hook=pairs, parse_constant=invalid)
        if not isinstance(value, dict):
            raise ValueError()
        return value
    except (ValueError, UnicodeError, RecursionError):
        raise Refusal("public_metadata_invalid") from None


class _Reader:
    def __init__(self, fetch, maximum=MAX_FETCHES):
        self.fetch = fetch
        self.count = 0
        self.maximum = maximum
        self.cache = {}

    def raw(self, url):
        if not _valid_url(url):
            raise Refusal("public_url_refused")
        if url in self.cache:
            return self.cache[url]
        self.count += 1
        if self.count > self.maximum:
            raise Refusal("public_fetch_limit")
        try:
            raw = self.fetch(url)
        except Refusal:
            raise
        except urllib.error.HTTPError as error:
            code = "public_artifact_not_found" if error.code == 404 else "public_metadata_unavailable"
            error.close()
            raise Refusal(code) from None
        except (TimeoutError, socket.timeout):
            raise Refusal("public_fetch_timeout") from None
        except Exception:
            raise Refusal("public_metadata_unavailable") from None
        if not isinstance(raw, bytes):
            raise Refusal("public_metadata_invalid")
        if len(raw) > MAX_BODY:
            raise Refusal("public_response_too_large")
        self.cache[url] = raw
        return raw

    def object(self, url):
        return _object(self.raw(url))


def _commit(reader, tag):
    value = reader.object(API + "/git/ref/tags/" + tag)
    if value.get("ref") != "refs/tags/" + tag:
        raise Refusal("tag_identity_invalid")
    obj = value.get("object")
    seen = set()
    for depth in range(MAX_TAG_CHAIN + 1):
        if not isinstance(obj, dict) or not _sha(obj.get("sha")):
            raise Refusal("tag_identity_invalid")
        sha = obj["sha"]
        if sha in seen:
            raise Refusal("tag_chain_invalid")
        seen.add(sha)
        if obj.get("type") == "commit":
            return sha
        if obj.get("type") != "tag" or depth == MAX_TAG_CHAIN:
            raise Refusal("tag_chain_invalid")
        value = reader.object(API + "/git/tags/" + sha)
        if value.get("sha") != sha or (depth == 0 and value.get("tag") != tag):
            raise Refusal("tag_identity_invalid")
        obj = value.get("object")
    raise Refusal("tag_chain_invalid")


def _asset(reader, release, tag, name):
    assets = release.get("assets")
    if not isinstance(assets, list):
        raise Refusal("release_asset_invalid")
    selected = [asset for asset in assets if isinstance(asset, dict) and asset.get("name") == name]
    if len(selected) != 1:
        raise Refusal("release_asset_missing" if not selected else "release_asset_invalid")
    asset = selected[0]
    expected = RELEASES + "/download/" + tag + "/" + name
    if (asset.get("browser_download_url") != expected or asset.get("state") != "uploaded"
            or type(asset.get("size")) is not int or not 0 < asset["size"] <= MAX_BODY
            or not isinstance(asset.get("digest"), str) or not DIGEST.fullmatch(asset["digest"])):
        raise Refusal("release_asset_invalid")
    raw = reader.raw(expected)
    digest = hashlib.sha256(raw).hexdigest()
    if len(raw) != asset["size"] or asset["digest"] != "sha256:" + digest:
        raise Refusal("release_asset_checksum_invalid")
    return raw, digest


def _manifest(raw, tag, commit):
    value = _object(raw)
    required = {"schema", "version", "commit", "requires_operator_action", "minimum_upgrade_from", "actions"}
    if (set(value) not in (required, required | {"notices"})
            or type(value.get("schema")) is not int or value["schema"] != 1
            or value.get("version") != tag[1:] or value.get("commit") != commit
            or not isinstance(value.get("actions"), list)
            or type(value.get("requires_operator_action")) is not bool
            or value["requires_operator_action"] != bool(value["actions"])
            or not isinstance(value.get("notices", []), list)):
        raise Refusal("release_manifest_invalid")
    floor = value["minimum_upgrade_from"]
    if floor is not None and (not isinstance(floor, str) or len(floor) > 128 or not SEMVER.fullmatch(floor)):
        raise Refusal("release_manifest_invalid")
    for key in ("actions", "notices"):
        for entry in value.get(key, []):
            if (not isinstance(entry, dict) or entry.get("release") != tag[1:]
                    or any(not isinstance(entry.get(field), str) or not entry[field].strip() for field in ("id", "summary", "detail"))
                    or not isinstance(entry.get("applicability"), dict) or not isinstance(entry.get("verification"), dict)):
                raise Refusal("release_manifest_invalid")
    return value


def _tree(reader, commit, wanted=REQUIRED_FILES):
    value = reader.object(API + "/git/commits/" + commit)
    tree = value.get("tree")
    if value.get("sha") != commit or not isinstance(tree, dict) or not _sha(tree.get("sha")):
        raise Refusal("source_tree_invalid")
    sha = tree["sha"]
    value = reader.object(API + "/git/trees/" + sha + "?recursive=1")
    if value.get("sha") != sha or value.get("truncated") is not False or not isinstance(value.get("tree"), list):
        raise Refusal("source_tree_invalid")
    files = {}
    seen = set()
    for entry in value["tree"]:
        if not isinstance(entry, dict) or not isinstance(entry.get("path"), str) or entry["path"] in seen:
            raise Refusal("source_tree_invalid")
        seen.add(entry["path"])
        if entry["path"] in wanted:
            if entry.get("type") != "blob" or entry.get("mode") not in ("100644", "100755") or not _sha(entry.get("sha")):
                raise Refusal("source_tree_invalid")
            files[entry["path"]] = entry["sha"]
    return sha, files


def _blob(reader, sha):
    value = reader.object(API + "/git/blobs/" + sha)
    if (value.get("sha") != sha or value.get("encoding") != "base64"
            or type(value.get("size")) is not int or not 0 < value["size"] <= MAX_BODY
            or not isinstance(value.get("content"), str)):
        raise Refusal("source_blob_invalid")
    try:
        raw = base64.b64decode(value["content"].replace("\n", ""), validate=True)
        digest = hashlib.new("sha1" if len(sha) == 40 else "sha256", b"blob " + str(len(raw)).encode() + b"\0" + raw).hexdigest()
        if len(raw) != value["size"] or digest != sha:
            raise ValueError()
        return raw.decode("utf-8")
    except (ValueError, UnicodeError):
        raise Refusal("source_blob_invalid") from None


def _php_tokens(text):
    # Strings stay indivisible, so a comment or a quoted example cannot stand
    # in for an actual declaration. This does not execute or fully parse PHP.
    pattern = re.compile(r"'(?:(?:\\.)|[^'\\])*'|\"(?:(?:\\.)|[^\"\\])*\"|/\*.*?\*/|//[^\n]*|\#[^\n]*|\s+|->|=>|[A-Za-z_][A-Za-z_0-9]*|[0-9]+|.", re.DOTALL)
    return [token for token in pattern.findall(text)
            if not token.isspace() and not token.startswith(("/*", "//", "#"))]


def _occurrences(tokens, sequence):
    return sum(tokens[index:index + len(sequence)] == sequence for index in range(len(tokens)))


def _protocol(reader, files):
    updater = _blob(reader, files[UPDATER])
    try:
        module = ast.parse(updater)
        values = {}
        for statement in module.body:
            if isinstance(statement, ast.Assign):
                for target in statement.targets:
                    if isinstance(target, ast.Name) and target.id in {"VERSION", "PROTOCOL"}:
                        if target.id in values:
                            raise ValueError()
                        values[target.id] = ast.literal_eval(statement.value)
        version, protocol = values["VERSION"], values["PROTOCOL"]
        if not _stable("v" + version) or _numbers("v" + version) < (0, 4, 0) or type(protocol) is not int or protocol != 1:
            raise ValueError()
    except (ValueError, TypeError, KeyError, SyntaxError, RecursionError):
        raise Refusal("helper_protocol_unpublished") from None
    installation = _php_tokens(_blob(reader, files[INSTALLATION]))
    expected = (
        "public const HELPER_PROTOCOL = 1;",
        "public const MINIMUM_HELPER_VERSION = '0.4.0';",
        "public const REQUIRED_HELPER_CAPABILITIES = ['plan', 'status', 'start', 'history', 'cancel'];",
    )
    if any(_occurrences(installation, _php_tokens(declaration)) != 1 for declaration in expected):
        raise Refusal("helper_protocol_unpublished")
    probe = _php_tokens(_blob(reader, files[PROBE]))
    fields = (
        "$this->option('protocol-contract')",
        "'schema' => 1", "'protocol' => InstallationCapabilities::HELPER_PROTOCOL",
        "'minimum_helper_version' => InstallationCapabilities::MINIMUM_HELPER_VERSION",
        "'capabilities' => InstallationCapabilities::REQUIRED_HELPER_CAPABILITIES",
    )
    signatures = [probe[index + 4] for index in range(len(probe) - 4)
                  if probe[index:index + 4] == ["protected", "$", "signature", "="]]
    if (len(signatures) != 1 or not signatures[0].startswith(("'", '"'))
            or "wayfindr:updater-status" not in signatures[0] or "--protocol-contract" not in signatures[0]
            or any(_occurrences(probe, _php_tokens(field)) < 1 for field in fields)):
        raise Refusal("helper_protocol_unpublished")
    return version, protocol


def _identity(tag):
    return {"tag": tag, "version": tag[1:], "release_url": RELEASES + "/tag/" + tag,
            "release_id": None, "commit": None, "image_digest": None, "image_reference": None,
            "manifest_sha256": None, "digest_asset_sha256": None, "minimum_upgrade_from": None,
            "source_tree_sha": None, "helper_version": None, "helper_protocol": None,
            "required_files_present": False, "protocol_probe_present": False,
            "metadata_verified": False, "source_tree_verified": False, "required_files": {}}


def _release(reader, identity):
    tag = identity["tag"]
    value = reader.object(API + "/releases/tags/" + tag)
    if (type(value.get("id")) is not int or value["id"] < 1 or value.get("draft") is not False
            or value.get("prerelease") is not False or value.get("tag_name") != tag
            or value.get("html_url") != identity["release_url"]):
        raise Refusal("published_release_invalid")
    identity["release_id"] = value["id"]
    commit = _commit(reader, tag)
    identity["commit"] = commit
    raw, sha = _asset(reader, value, tag, "release-manifest.json")
    manifest = _manifest(raw, tag, commit)
    identity["manifest_sha256"] = sha
    identity["minimum_upgrade_from"] = manifest["minimum_upgrade_from"]
    raw, sha = _asset(reader, value, tag, "release-image-digest.txt")
    try:
        digest = raw.decode("ascii")
    except UnicodeError:
        raise Refusal("image_digest_invalid") from None
    if digest.endswith("\n"):
        digest = digest[:-1]
    if not DIGEST.fullmatch(digest):
        raise Refusal("image_digest_invalid")
    identity.update(digest_asset_sha256=sha, image_digest=digest,
                    image_reference=IMAGE + ":" + tag[1:] + "@" + digest, metadata_verified=True)
    tree, files = _tree(reader, commit)
    identity.update(source_tree_sha=tree, required_files=files)
    if set(files) != set(REQUIRED_FILES):
        raise Refusal("helper_not_published")
    identity["required_files_present"] = True
    version, protocol = _protocol(reader, files)
    identity.update(helper_version=version, helper_protocol=protocol,
                    protocol_probe_present=True, source_tree_verified=True)


def _helper_distribution(reader, identity):
    """Hash complete installed generations and enrollment inputs, without execution."""
    wanted = tuple("scripts/self-host/" + name for name in HELPER_FILES) + PROVENANCE_FILES + (UPGRADE,)
    tree, files = _tree(reader, identity["commit"], wanted)
    if tree != identity["source_tree_sha"] or set(files) - {UPGRADE} != set(wanted) - {UPGRADE}:
        raise Refusal("helper_bundle_unpublished")
    hashes = {path: hashlib.sha256(_blob(reader, sha).encode("utf-8")).hexdigest() for path, sha in files.items()}
    modules = {name: hashes["scripts/self-host/" + name] for name in HELPER_FILES}
    declaration = {"schema": 1, "helper_version": identity["helper_version"],
                   "protocol": identity["helper_protocol"], "files": modules}
    raw = (json.dumps(declaration, sort_keys=True, separators=(",", ":")) + "\n").encode()
    return {"tag": identity["tag"], "commit": identity["commit"], "source_tree_sha": tree,
            **declaration, "bundle_sha256": hashlib.sha256(raw).hexdigest(),
            "provenance_files": {path: hashes[path] for path in PROVENANCE_FILES},
            "upgrade_cli_sha256": hashes.get(UPGRADE)}


def assess(source_tag, target_tag, fetch=None, *, helper_tag=None):
    """Return a schema-1 classified publication gate without any host writes.

    ``fetch`` is an optional callable ``fetch(official_url) -> bytes`` for
    deterministic fixtures. The reader applies its URL/body/request-count
    bounds even to an injected fetch; production additionally bounds network
    timeout/redirects and never uses credentials, tokens or ambient proxies.
    """
    report = {"schema": 1, "status": "blocked", "qualification": False,
              "scope": "published_artifact_declarations", "source": None, "target": None, "reasons": [],
              "verification": {"public_release_metadata": False, "source_tree_declarations": False,
                               "baked_image": False, "release_guards": False, "host": False, "reboot": False}}
    tags = (("source", source_tag), ("target", target_tag))
    if helper_tag is not None:
        tags += (("helper", helper_tag),)
        report["helpers"] = {"source": None, "selected": None}
    for role, tag in tags:
        if not _stable(tag):
            report["reasons"].append({"code": "stable_tag_required", "role": role})
    if report["reasons"]:
        return report
    if _numbers(target_tag) <= _numbers(source_tag):
        report["reasons"].append({"code": "target_must_be_newer", "role": "pair"})
        return report
    maximum = MAX_HELPER_FETCHES if helper_tag is not None and helper_tag not in {source_tag, target_tag} else MAX_FETCHES
    reader = _Reader(public_fetch if fetch is None else fetch, maximum)
    for role, tag in (("source", source_tag), ("target", target_tag)):
        identity = _identity(tag)
        report[role] = identity
        try:
            _release(reader, identity)
        except Refusal as error:
            report["reasons"].append({"code": error.code, "role": role})
    report["verification"]["public_release_metadata"] = all(report[role]["metadata_verified"] for role in ("source", "target"))
    report["verification"]["source_tree_declarations"] = all(report[role]["source_tree_verified"] for role in ("source", "target"))
    floor = report["target"]["minimum_upgrade_from"]
    if floor is not None and _numbers(source_tag) < tuple(int(part) for part in SEMVER.fullmatch(floor).groups()[:3]):
        report["reasons"].append({"code": "upgrade_floor_not_met", "role": "pair"})
    if helper_tag is not None and not report["reasons"]:
        # An explicit helper release is independent of the application target.
        # Reuse content-addressed public objects when those tags coincide.
        selected = next((report[role] for role in ("source", "target") if report[role]["tag"] == helper_tag), None)
        try:
            if selected is None:
                selected = _identity(helper_tag)
                _release(reader, selected)
            report["helpers"]["source"] = _helper_distribution(reader, report["source"])
            report["helpers"]["selected"] = (report["helpers"]["source"] if helper_tag == source_tag
                                              else _helper_distribution(reader, selected))
        except Refusal as error:
            report["reasons"].append({"code": error.code, "role": "helper"})
    if not report["reasons"]:
        report["status"] = "ready"
    return report


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", required=True, help="Exact published stable source tag, e.g. v1.2.0")
    parser.add_argument("--target", required=True, help="Exact newer published stable target tag")
    parser.add_argument("--output", type=Path, help="Write the JSON report here; omit for stdout")
    args = parser.parse_args(argv)
    raw = json.dumps(assess(args.source, args.target), indent=2, sort_keys=True) + "\n"
    try:
        if args.output is None:
            sys.stdout.write(raw)
        else:
            args.output.write_text(raw, encoding="utf-8")
    except OSError:
        sys.stderr.write("Could not write the publication preflight report.\n")
        return 1
    return 0 if json.loads(raw)["status"] == "ready" else 2


if __name__ == "__main__":
    raise SystemExit(main())
