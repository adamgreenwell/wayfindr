"""Independently attest published release bytes and the selected Docker image.

Only fixed public Wayfindr origins are contacted. Downloaded scripts are never
executed, and the image identity probe is never started or given installation
volumes. The caller owns the private staging directory and the apply journal.
"""

from __future__ import annotations

from functools import cmp_to_key
import hashlib
import http.client
import io
import json
import os
from pathlib import Path
import re
import selectors
import signal
import stat
import subprocess
import sys
import tarfile
import time
import urllib.parse
import urllib.request


REPOSITORY = "adamgreenwell/wayfindr"
API = "https://api.github.com/repos/" + REPOSITORY
RELEASES = "https://github.com/" + REPOSITORY + "/releases"
RAW = "https://raw.githubusercontent.com/" + REPOSITORY
REGISTRY = "https://ghcr.io/v2/" + REPOSITORY
TOKEN = "https://ghcr.io/token?service=ghcr.io&scope=repository%3Aadamgreenwell%2Fwayfindr%3Apull"
IMAGE = "ghcr.io/" + REPOSITORY
DOCKER = ["/usr/bin/docker", "--host", "unix:///var/run/docker.sock", "--config", "/etc/wayfindr-updater/docker"]
MAXIMUM = 2_000_000
COPY_MAXIMUM = 900_000
TIMEOUT = 3600
DIGEST = re.compile(r"sha256:[0-9a-f]{64}\Z")
HEX = re.compile(r"[0-9a-f]{64}\Z")
COMMIT = re.compile(r"(?:[0-9a-f]{40}|[0-9a-f]{64})\Z")
CONTAINER = re.compile(r"[0-9a-f]{64}\Z")
STABLE = re.compile(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\Z")
SEMVER = re.compile(r"(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-((?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?\Z")
INDEX_TYPES = {"application/vnd.oci.image.index.v1+json", "application/vnd.docker.distribution.manifest.list.v2+json"}
MANIFEST_TYPES = {"application/vnd.oci.image.manifest.v1+json", "application/vnd.docker.distribution.manifest.v2+json"}
CONFIG_TYPES = {"application/vnd.oci.image.config.v1+json", "application/vnd.docker.container.image.v1+json"}
LAYER_TYPES = {"application/vnd.oci.image.layer.v1.tar", "application/vnd.oci.image.layer.v1.tar+gzip", "application/vnd.oci.image.layer.v1.tar+zstd", "application/vnd.docker.image.rootfs.diff.tar.gzip"}
# Docker's older classic inspect serializes container.Config rather than OCI
# Config. These exact zero values are added by its non-omitempty legacy fields:
# https://github.com/moby/moby/blob/v28.0.0/api/types/container/config.go
# https://github.com/moby/moby/blob/v28.0.0/daemon/images/image_inspect.go
# Never omit an unknown key, change a published field, or normalize containerd.
LEGACY_CONFIG_DEFAULTS = {"Hostname": "", "Domainname": "", "AttachStdin": False,
                          "AttachStdout": False, "AttachStderr": False, "Tty": False,
                          "OpenStdin": False, "StdinOnce": False, "Image": "",
                          "Volumes": None, "OnBuild": None, "WorkingDir": ""}


def _hash(raw):
    return hashlib.sha256(raw).hexdigest()


def _same(left, right):
    # Python considers True == 1; release contracts preserve JSON value types.
    return json.dumps(left, sort_keys=True, separators=(",", ":")) == json.dumps(right, sort_keys=True, separators=(",", ":"))


def platform_descriptor_matches(actual, expected):
    """Bind a container's selected manifest, allowing only ARM64 v8 spelling.

    Docker may add its selected image platform to a descriptor or omit optional
    metadata. Exact public manifest bytes (digest/type/size) remain mandatory.
    """
    if not isinstance(actual, dict) or not isinstance(expected, dict):
        return False
    base = {"mediaType", "digest", "size"}
    if (not base <= set(actual) or set(actual) - base - {"platform", "annotations"}
            or not base <= set(expected) or any(not _same(actual[key], expected[key]) for key in base)):
        return False
    if "annotations" in actual and ("annotations" not in expected or not _same(actual["annotations"], expected["annotations"])):
        return False
    if "platform" in actual:
        platform, published = actual["platform"], expected.get("platform")
        if (not isinstance(platform, dict) or not isinstance(published, dict)
                or not {"os", "architecture"} <= set(platform) or set(platform) - {"os", "architecture", "variant"}
                or platform["os"] != published.get("os") or platform["architecture"] != published.get("architecture")):
            return False
        variants = (None, "v8") if published.get("architecture") == "arm64" else (None,)
        if platform.get("variant") not in variants or published.get("variant") not in variants:
            return False
    return True


def _version(value):
    if not isinstance(value, str) or len(value) > 128:
        raise ValueError()
    match = SEMVER.fullmatch(value)
    if not match or (match[4] or "").lower() == "dev":
        raise ValueError()
    return match.groups()


def _numeric(left, right):
    return (len(left) > len(right)) - (len(left) < len(right)) or (left > right) - (left < right)


def _compare(left, right):
    a, b = _version(left), _version(right)
    for x, y in zip(a[:3], b[:3]):
        result = _numeric(x, y)
        if result:
            return result
    if not a[3] or not b[3]:
        return (not a[3]) - (not b[3])
    aa, bb = a[3].split("."), b[3].split(".")
    for x, y in zip(aa, bb):
        if x == y:
            continue
        if x.isdigit() and y.isdigit():
            return _numeric(x, y)
        if x.isdigit() != y.isdigit():
            return -1 if x.isdigit() else 1
        return (x > y) - (x < y)
    return (len(aa) > len(bb)) - (len(aa) < len(bb))


def _object(raw):
    def pairs(entries):
        result = {}
        for key, value in entries:
            if key in result:
                raise ValueError()
            result[key] = value
        return result
    def constant(_):
        raise ValueError()
    result = json.loads(raw.decode("utf-8"), object_pairs_hook=pairs, parse_constant=constant)
    if not isinstance(result, dict):
        raise ValueError()
    def depth(value, level=0):
        if level > 64:
            raise ValueError()
        if isinstance(value, dict):
            for key, item in value.items():
                if any(0xD800 <= ord(char) <= 0xDFFF for char in key):
                    raise ValueError()
                depth(item, level + 1)
        elif isinstance(value, list):
            for item in value:
                depth(item, level + 1)
        elif isinstance(value, str) and any(0xD800 <= ord(char) <= 0xDFFF for char in value):
            raise ValueError()
    depth(result)
    return result


def _text(value):
    if not isinstance(value, str) or not value.strip():
        raise ValueError()


def _condition(value, applicability=False):
    if not isinstance(value, dict):
        raise ValueError()
    kind = value.get("type")
    allowed = {"always": {"type"}, "state": {"type", "check"}, "upgrade-from": {"type", "min"}} if applicability else {"attest": {"type"}, "check": {"type", "check"}}
    if not isinstance(kind, str) or kind not in allowed or set(value) != allowed[kind]:
        raise ValueError()
    if "check" in value:
        _text(value["check"])
    if "min" in value:
        _version(value["min"])


def _manifest(value):
    required = {"schema", "version", "commit", "requires_operator_action", "minimum_upgrade_from", "actions"}
    if not isinstance(value, dict) or set(value) not in (required, required | {"notices"}) or type(value["schema"]) is not int or value["schema"] != 1:
        raise ValueError()
    _version(value["version"])
    if not isinstance(value["commit"], str) or (value["commit"] != "" and not COMMIT.fullmatch(value["commit"])):
        raise ValueError()
    if value["minimum_upgrade_from"] is not None:
        _version(value["minimum_upgrade_from"])
    if not isinstance(value["actions"], list) or type(value["requires_operator_action"]) is not bool or value["requires_operator_action"] != bool(value["actions"]):
        raise ValueError()
    seen = set()
    for key in ("actions", "notices"):
        if not isinstance(value.get(key, []), list):
            raise ValueError()
        for entry in value.get(key, []):
            base = {"release", "id", "summary", "detail", "applicability", "verification"}
            if key == "actions":
                base |= {"phase", "depends_on_release"}
            if not isinstance(entry, dict) or set(entry) not in (base, base | {"installation_profiles"}) or (key == "notices" and "installation_profiles" in entry):
                raise ValueError()
            if entry["release"] != value["version"] or not isinstance(entry["id"], str) or not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", entry["id"]) or entry["id"] in seen:
                raise ValueError()
            seen.add(entry["id"])
            _text(entry["summary"])
            _text(entry["detail"])
            _condition(entry["applicability"], True)
            _condition(entry["verification"])
            if key == "notices" and entry["applicability"]["type"] == "upgrade-from":
                raise ValueError()
            if key == "actions":
                combinations = {"before-pull": {"none"}, "after-pull": {"none", "code"}, "after-start": {"none", "code", "schema"}}
                if not isinstance(entry["phase"], str) or entry["phase"] not in combinations or not isinstance(entry["depends_on_release"], str) or entry["depends_on_release"] not in combinations[entry["phase"]]:
                    raise ValueError()
            if "installation_profiles" in entry:
                profiles = entry["installation_profiles"]
                if not isinstance(profiles, list) or not profiles or any(profile not in ("image", "host") for profile in profiles) or len(set(profiles)) != len(profiles):
                    raise ValueError()
    return value


class _Redirect(urllib.request.HTTPRedirectHandler):
    def __init__(self, allowed):
        self.allowed = allowed
        self.count = 0

    def redirect_request(self, request, fp, code, message, headers, url):
        self.count += 1
        parsed = urllib.parse.urlsplit(url)
        if self.count > 3 or parsed.scheme != "https" or parsed.hostname not in self.allowed or parsed.username is not None or parsed.password is not None or parsed.port not in (None, 443) or parsed.fragment:
            raise ValueError()
        result = super().redirect_request(request, fp, code, message, headers, url)
        if urllib.parse.urlsplit(request.full_url).hostname != parsed.hostname:
            result.remove_header("Authorization")
        return result


class _DeadlineReader:
    """Bound each socket read, including a peer slowly dripping HTTP headers.

    A socket's ordinary timeout restarts on every receive. Checking only after
    HTTPResponse.read() therefore leaves long headers/chunks unbounded. This
    wrapper checks the absolute deadline between raw reads and sets the next
    socket timeout to the remaining budget; it also serves already buffered data.
    """

    def __init__(self, reader, sock, deadline):
        self.reader, self.sock, self.deadline = reader, sock, deadline
        self.buffer = bytearray()

    def _check(self):
        remaining = self.deadline - time.monotonic()
        if remaining <= 0:
            raise ValueError()
        self.sock.settimeout(min(30, remaining))

    def _raw(self, size):
        self._check()
        result = self.reader.read1(size)
        self._check()
        return result

    def read1(self, size=-1):
        self._check()
        maximum = 65_536 if size < 0 else min(size, 65_536)
        if self.buffer:
            result = bytes(self.buffer[:maximum])
            del self.buffer[:maximum]
            return result
        return self._raw(maximum)

    def read(self, size=-1):
        if size < 0:
            raise ValueError()
        result = bytearray()
        while len(result) < size:
            chunk = self.read1(size - len(result))
            if not chunk:
                break
            result.extend(chunk)
        return bytes(result)

    def readline(self, size=-1):
        # HTTP client always supplies its header/chunk-line limit.
        maximum = 65_537 if size < 0 else min(size, 65_537)
        while True:
            self._check()
            newline = self.buffer.find(b"\n", 0, maximum)
            if newline >= 0 or len(self.buffer) >= maximum:
                count = newline + 1 if newline >= 0 else maximum
                result = bytes(self.buffer[:count])
                del self.buffer[:count]
                return result
            chunk = self._raw(min(65_536, maximum - len(self.buffer)))
            if not chunk:
                result = bytes(self.buffer)
                self.buffer.clear()
                return result
            self.buffer.extend(chunk)

    def readinto(self, buffer):
        raw = self.read(len(buffer))
        buffer[:len(raw)] = raw
        return len(raw)

    @property
    def closed(self):
        return self.reader.closed

    def close(self):
        self.reader.close()


class _DeadlineHTTPS(http.client.HTTPSConnection):
    def __init__(self, host, *, deadline, **options):
        super().__init__(host, **options)
        def response(sock, *args, **kwargs):
            result = http.client.HTTPResponse(sock, *args, **kwargs)
            result.fp = _DeadlineReader(result.fp, sock, deadline)
            return result
        self.response_class = response


class _HTTPS(urllib.request.HTTPSHandler):
    def __init__(self, deadline):
        super().__init__()
        self.deadline = deadline

    def https_open(self, request):
        return self.do_open(lambda host, **options: _DeadlineHTTPS(host, deadline=self.deadline, **options), request, context=self._context)


def _fixed_url(url):
    if not isinstance(url, str):
        raise ValueError()
    if url == TOKEN:
        return
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != "https" or parsed.username is not None or parsed.password is not None or parsed.port not in (None, 443) or parsed.fragment or parsed.query:
        raise ValueError()
    stable = r"v(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)"
    commit = r"(?:[0-9a-f]{40}|[0-9a-f]{64})"
    checks = (
        re.escape(API) + r"/(?:releases/tags/|git/ref/tags/)" + stable,
        re.escape(API) + r"/git/tags/" + commit,
        re.escape(RELEASES) + r"/download/" + stable + r"/(?:release-manifest\.json|release-image-digest\.txt)",
        re.escape(RAW) + "/" + commit + r"/(?:releases/history\.json|docker/self-hosting/compose\.yml)",
        re.escape(REGISTRY) + r"/(?:manifests|blobs)/sha256:[0-9a-f]{64}",
    )
    if not any(re.fullmatch(pattern, url) for pattern in checks):
        raise ValueError()


def _transport(url, *, headers, maximum, deadline):
    _fixed_url(url)
    parsed = urllib.parse.urlsplit(url)
    # Only release assets and public image config blobs have CDN redirects.
    allowed = set()
    if url.startswith(RELEASES + "/download/"):
        allowed = {"github.com", "release-assets.githubusercontent.com", "objects.githubusercontent.com"}
    elif url.startswith(REGISTRY + "/blobs/"):
        allowed = {"ghcr.io", "pkg-containers.githubusercontent.com"}
    if parsed.scheme != "https" or parsed.username is not None or parsed.password is not None or parsed.port not in (None, 443):
        raise ValueError()
    remaining = deadline - time.monotonic()
    if remaining <= 0:
        raise ValueError()
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), _Redirect(allowed), _HTTPS(deadline))
    request = urllib.request.Request(url, headers={"User-Agent": "Wayfindr host artifact verifier", "Accept-Encoding": "identity", **headers})
    with opener.open(request, timeout=min(30, remaining)) as response:
        if response.status != 200 or response.headers.get("Content-Encoding", "identity").lower() != "identity":
            raise ValueError()
        declared = response.headers.get("Content-Length")
        if declared is not None and (not declared.isdigit() or int(declared) > maximum):
            raise ValueError()
        output = bytearray()
        while True:
            if time.monotonic() >= deadline:
                raise ValueError()
            chunk = response.read1(min(65_536, maximum + 1 - len(output)))
            if not chunk:
                break
            output.extend(chunk)
            if len(output) > maximum:
                raise ValueError()
        if declared is not None and len(output) != int(declared):
            raise ValueError()
        return bytes(output)


def _worker(request):
    if not isinstance(request, dict) or set(request) != {"url", "headers", "maximum", "deadline"} or type(request["maximum"]) is not int or not 0 < request["maximum"] <= MAXIMUM or type(request["deadline"]) not in (int, float) or not time.monotonic() < request["deadline"] <= time.monotonic() + TIMEOUT or not isinstance(request["headers"], dict) or set(request["headers"]) - {"Accept", "Authorization"}:
        raise ValueError()
    _fixed_url(request["url"])
    for key, value in request["headers"].items():
        if not isinstance(value, str) or len(value) > 16_391 or any(ord(char) < 32 or ord(char) > 126 for char in value):
            raise ValueError()
        if key == "Authorization" and (not request["url"].startswith(REGISTRY + "/") or not value.startswith("Bearer ")):
            raise ValueError()
    return _transport(**request)


def _fetch(url, *, headers, maximum, deadline):
    """Externally bound DNS, TLS, headers and body without ambient credentials.

    This child only performs a fixed-origin public read. Stopping it cannot
    cancel or race an application/Docker operation. URLs and an anonymous GHCR
    token travel over stdin, and neither errors nor that token enter logs/argv.
    """
    _fixed_url(url)
    request = json.dumps({"url": url, "headers": headers, "maximum": maximum, "deadline": deadline}, separators=(",", ":")).encode()
    if len(request) > 65_536 or deadline <= time.monotonic():
        raise ValueError()
    process = subprocess.Popen(["/usr/bin/python3", str(Path(__file__).absolute()), "--fetch"],
                               stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                               env={"PATH": "/usr/bin:/bin", "LANG": "C.UTF-8", "HOME": "/nonexistent"},
                               start_new_session=True)
    selector = selectors.DefaultSelector()
    output = bytearray()
    sent = 0
    try:
        os.set_blocking(process.stdin.fileno(), False)
        os.set_blocking(process.stdout.fileno(), False)
        selector.register(process.stdin, selectors.EVENT_WRITE)
        selector.register(process.stdout, selectors.EVENT_READ)
        while selector.get_map():
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise ValueError()
            for key, _ in selector.select(min(remaining, 1)):
                if key.fileobj is process.stdin:
                    sent += os.write(process.stdin.fileno(), request[sent:])
                    if sent == len(request):
                        selector.unregister(process.stdin)
                        process.stdin.close()
                else:
                    chunk = os.read(process.stdout.fileno(), 65_536)
                    if not chunk:
                        selector.unregister(process.stdout)
                    output.extend(chunk)
                    if len(output) > maximum:
                        raise ValueError()
        remaining = deadline - time.monotonic()
        if remaining <= 0 or process.wait(timeout=remaining) != 0:
            raise ValueError()
        return bytes(output)
    finally:
        selector.close()
        if process.poll() is None:
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            process.wait()
        process.stdin.close()
        process.stdout.close()


class Artifacts:
    """Host attestation; `provenance` passed to prepare is the fresh full plan.

    A test fetch callable takes (url, *, headers, maximum, deadline) and returns
    bytes. `api` supplies Refusal, trusted and bounded capture, as updater.py does.
    All failures deliberately omit remote bodies, paths, and command output.
    """

    def __init__(self, api, fetch=None):
        self.api = api
        self.fetch = fetch if fetch is not None else _fetch

    def _deadline(self):
        if time.monotonic() >= self.deadline:
            raise ValueError()

    def _begin(self, target, architecture, directory):
        self.deadline = time.monotonic() + TIMEOUT
        if not isinstance(directory, Path) or not directory.is_absolute() or ".." in directory.parts:
            raise ValueError()
        self.api.trusted(directory, directory=True)
        if not stat.S_ISDIR(directory.lstat().st_mode) or directory.stat().st_mode & 0o077:
            raise ValueError()
        self.directory = directory
        if not isinstance(target, dict) or set(target) != {"tag", "version", "commit", "image_digest"} or not isinstance(target["version"], str) or len(target["version"]) > 127 or not STABLE.fullmatch(target["version"]) or target["tag"] != "v" + target["version"] or not isinstance(target["commit"], str) or not COMMIT.fullmatch(target["commit"]) or not isinstance(target["image_digest"], str) or not DIGEST.fullmatch(target["image_digest"]) or architecture not in ("amd64", "arm64"):
            raise ValueError()

    def _read(self, name, maximum=MAXIMUM, digest=None, size=None):
        # Names are fixed by this module; never accept caller-supplied paths.
        self._deadline()
        path = self.directory / name
        self.api.trusted(path)
        before = path.lstat()
        if not stat.S_ISREG(before.st_mode) or before.st_mode & 0o077 or not 0 < before.st_size <= maximum:
            raise ValueError()
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        try:
            opened = os.fstat(fd)
            if not stat.S_ISREG(opened.st_mode) or opened.st_mode & 0o077 or (opened.st_dev, opened.st_ino, opened.st_size) != (before.st_dev, before.st_ino, before.st_size):
                raise ValueError()
            output = bytearray()
            while True:
                self._deadline()
                chunk = os.read(fd, min(65_536, maximum + 1 - len(output)))
                if not chunk:
                    break
                output.extend(chunk)
                if len(output) > maximum:
                    raise ValueError()
            after = os.fstat(fd)
            if len(output) != opened.st_size or (after.st_size, after.st_mtime_ns, after.st_ctime_ns) != (opened.st_size, opened.st_mtime_ns, opened.st_ctime_ns):
                raise ValueError()
        finally:
            os.close(fd)
        raw = bytes(output)
        self._deadline()
        if (size is not None and len(raw) != size) or (digest is not None and "sha256:" + _hash(raw) != digest):
            raise ValueError()
        return raw

    def _get(self, url, name, headers=None, maximum=MAXIMUM, digest=None, size=None):
        self._deadline()
        _fixed_url(url)
        raw = self.fetch(url, headers=headers or {"Accept": "application/json"}, maximum=maximum, deadline=self.deadline)
        self._deadline()
        if not isinstance(raw, bytes) or len(raw) > maximum or (size is not None and len(raw) != size) or (digest is not None and "sha256:" + _hash(raw) != digest):
            raise ValueError()
        if name is not None:
            self._save(name, raw)
        return raw

    def _save(self, name, raw):
        self._deadline()
        path = self.directory / name
        if path.exists() or path.is_symlink():
            self.api.trusted(path)
            if not stat.S_ISREG(path.lstat().st_mode) or path.stat().st_mode & 0o077 or path.stat().st_size != len(raw) or path.read_bytes() != raw:
                raise ValueError()
            return
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, "wb") as stream:
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
        fd = os.open(self.directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)

    def _command(self, command, timeout=30):
        self._deadline()
        code, raw = self.api.capture(DOCKER + command, timeout=max(1, min(timeout, int(self.deadline - time.monotonic()))))
        self._deadline()
        if code != 0 or not isinstance(raw, bytes) or len(raw) > 1_048_576:
            raise ValueError()
        return raw

    def _tag_commit(self, tag, retained=False):
        def read(url, name):
            return self._read(name) if retained else self._get(url, name)
        reference = _object(read(API + "/git/ref/tags/" + tag, "tag-ref.json"))
        if reference.get("ref") != "refs/tags/" + tag:
            raise ValueError()
        obj = reference.get("object")
        seen = set()
        for depth in range(5):
            if not isinstance(obj, dict) or not isinstance(obj.get("sha"), str) or not COMMIT.fullmatch(obj["sha"]) or obj["sha"] in seen:
                raise ValueError()
            seen.add(obj["sha"])
            if obj.get("type") == "commit":
                return obj["sha"]
            if obj.get("type") != "tag":
                raise ValueError()
            obj = _object(read(API + "/git/tags/" + obj["sha"], "tag-" + str(depth) + ".json")).get("object")
        raise ValueError()

    def _asset_url(self, release, tag, name, frozen):
        assets = release.get("assets")
        if not isinstance(assets, list) or any(not isinstance(asset, dict) for asset in assets):
            raise ValueError()
        matches = [asset for asset in assets if asset.get("name") == name]
        url = RELEASES + "/download/" + tag + "/" + name
        if len(matches) != 1 or matches[0].get("state") != "uploaded" or matches[0].get("browser_download_url") != url or matches[0].get("digest") != "sha256:" + frozen:
            raise ValueError()
        return url

    def _asset(self, release, tag, name, frozen):
        return self._get(self._asset_url(release, tag, name, frozen), name, digest="sha256:" + frozen)

    def _history(self, raw, manifest):
        history = _object(raw)
        if set(history) != {"schema", "releases"} or type(history["schema"]) is not int or history["schema"] != 1 or not isinstance(history["releases"], list) or not history["releases"] or len(history["releases"]) > 2048:
            raise ValueError()
        versions = set()
        normalized = []
        for entry in history["releases"]:
            _manifest(entry)
            version = entry["version"]
            if version in versions or _compare(version, manifest["version"]) > 0:
                raise ValueError()
            versions.add(version)
            if version == manifest["version"]:
                if entry["commit"] not in ("", manifest["commit"]):
                    raise ValueError()
                entry = {**entry, "commit": manifest["commit"]}
                if not _same(entry, manifest):
                    raise ValueError()
            normalized.append(entry)
        floor = manifest["minimum_upgrade_from"]
        coverage = floor if floor is not None and _compare(floor, "0.1.0") > 0 else "0.1.0"
        earliest = min(versions, key=cmp_to_key(_compare))
        if manifest["version"] not in versions or _compare(earliest, coverage) > 0:
            raise ValueError()
        # This is the exact transformation performed by build-manifest.php.
        baked = [entry for entry in history["releases"] if entry["version"] != manifest["version"]]
        baked.append(manifest)
        if floor is not None:
            baked = [entry for entry in baked if entry["version"] == manifest["version"] or _compare(entry["version"], floor) >= 0]
        return {"schema": 1, "releases": normalized}, {"schema": 1, "releases": baked}

    def _descriptor(self, value, types):
        if not isinstance(value, dict) or value.get("mediaType") not in types or not isinstance(value.get("digest"), str) or not DIGEST.fullmatch(value["digest"]) or type(value.get("size")) is not int or value["size"] <= 0 or "urls" in value or "data" in value:
            raise ValueError()

    def _registry(self, target, architecture):
        token = _object(self._get(TOKEN, None, maximum=65_536)).get("token")
        if not isinstance(token, str) or not token or len(token) > 16_384 or any(ord(char) < 33 or ord(char) > 126 for char in token):
            raise ValueError()
        headers = {"Authorization": "Bearer " + token, "Accept": ", ".join(sorted(INDEX_TYPES | MANIFEST_TYPES))}
        raw = self._get(REGISTRY + "/manifests/" + target["image_digest"], "image-index.json", headers, digest=target["image_digest"])
        def read(kind, digest, name, size):
            return self._get(REGISTRY + "/" + kind + "/" + digest, name, headers, digest=digest, size=size)
        return self._chain(target, architecture, raw, read)

    def _chain(self, target, architecture, raw, read):
        if "sha256:" + _hash(raw) != target["image_digest"]:
            raise ValueError()
        raw_index = raw
        index = _object(raw)
        if type(index.get("schemaVersion")) is not int or index["schemaVersion"] != 2 or index.get("mediaType") not in INDEX_TYPES or not isinstance(index.get("manifests"), list) or not 2 <= len(index["manifests"]) <= 32:
            raise ValueError()
        runnable = {}
        attestations = []
        for descriptor in index["manifests"]:
            self._descriptor(descriptor, MANIFEST_TYPES)
            platform = descriptor.get("platform")
            if not isinstance(platform, dict):
                raise ValueError()
            key = (platform.get("os"), platform.get("architecture"))
            if key == ("unknown", "unknown"):
                annotations = descriptor.get("annotations")
                if not isinstance(annotations, dict) or annotations.get("vnd.docker.reference.type") != "attestation-manifest" or not isinstance(annotations.get("vnd.docker.reference.digest"), str) or not DIGEST.fullmatch(annotations["vnd.docker.reference.digest"]):
                    raise ValueError()
                attestations.append(annotations["vnd.docker.reference.digest"])
                continue
            if key not in {( "linux", "amd64"), ("linux", "arm64")} or key in runnable or set(platform) - {"os", "architecture", "variant"} or platform.get("variant") not in ((None,) if key[1] == "amd64" else (None, "v8")):
                raise ValueError()
            runnable[key] = descriptor
        if set(runnable) != {("linux", "amd64"), ("linux", "arm64")} or any(digest not in {item["digest"] for item in runnable.values()} for digest in attestations):
            raise ValueError()
        selected = runnable[("linux", architecture)]
        self._descriptor(selected, MANIFEST_TYPES)
        raw = read("manifests", selected["digest"], "image-platform-manifest.json", selected["size"])
        manifest = _object(raw)
        if type(manifest.get("schemaVersion")) is not int or manifest["schemaVersion"] != 2 or manifest.get("mediaType") != selected["mediaType"] or "artifactType" in manifest or "subject" in manifest or not isinstance(manifest.get("layers"), list) or not 1 <= len(manifest["layers"]) <= 256:
            raise ValueError()
        config = manifest.get("config")
        self._descriptor(config, CONFIG_TYPES)
        for layer in manifest["layers"]:
            self._descriptor(layer, LAYER_TYPES)
        raw = read("blobs", config["digest"], "image-config.json", config["size"])
        image = _object(raw)
        rootfs = image.get("rootfs")
        if image.get("os") != "linux" or image.get("architecture") != architecture or image.get("variant") not in ((None,) if architecture == "amd64" else (None, "v8")) or not isinstance(rootfs, dict) or rootfs.get("type") != "layers" or not isinstance(rootfs.get("diff_ids"), list) or len(rootfs["diff_ids"]) != len(manifest["layers"]) or any(not isinstance(digest, str) or not DIGEST.fullmatch(digest) for digest in rootfs["diff_ids"]):
            raise ValueError()
        self._image_config(image.get("config"), target)
        # Public content digests never stand in for the local Docker image ID.
        # Docker classic uses the config digest; Docker's containerd store uses
        # its image target (normally the index, or a selected manifest).
        return {"platform_manifest_digest": selected["digest"], "config_digest": config["digest"],
                "image_config": image, "index_descriptor": {"mediaType": index["mediaType"],
                "digest": target["image_digest"], "size": len(raw_index)}, "platform_descriptor": selected}

    def _image_config(self, config, target):
        if not isinstance(config, dict) or config.get("User") not in ("wayfindr", "1000", "1000:1000") or config.get("Entrypoint") != ["wayfindr-entrypoint"] or config.get("Cmd") != ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]:
            raise ValueError()
        volumes = config.get("Volumes", {})
        if not isinstance(volumes, dict) or set(volumes) - {"/data", "/config"} or any(value != {} for value in volumes.values()):
            raise ValueError()
        labels = config.get("Labels")
        if not isinstance(labels, dict) or labels.get("org.opencontainers.image.source") != "https://github.com/" + REPOSITORY or labels.get("org.opencontainers.image.revision") != target["commit"] or labels.get("org.opencontainers.image.version") != target["version"]:
            raise ValueError()
        env = config.get("Env")
        if not isinstance(env, list):
            raise ValueError()
        environment = {}
        for entry in env:
            if not isinstance(entry, str) or "=" not in entry:
                raise ValueError()
            key, value = entry.split("=", 1)
            if not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*", key) or key in environment:
                raise ValueError()
            environment[key] = value
        if environment.get("WAYFINDR_VERSION") != target["tag"] or environment.get("WAYFINDR_COMMIT") != target["commit"]:
            raise ValueError()

    def _copy(self, probe, path, name):
        raw = self._command(["cp", probe + ":" + path, "-"])
        with tarfile.open(fileobj=io.BytesIO(raw), mode="r:") as archive:
            members = archive.getmembers()
            if len(members) != 1 or not members[0].isreg() or members[0].name.removeprefix("./") != path.rsplit("/", 1)[1] or not 0 < members[0].size <= COPY_MAXIMUM:
                raise ValueError()
            stream = archive.extractfile(members[0])
            if stream is None:
                raise ValueError()
            content = stream.read(COPY_MAXIMUM + 1)
            if len(content) != members[0].size:
                raise ValueError()
        self._save(name, content)
        return content

    def _local_config_matches(self, actual, published, classic):
        if not isinstance(actual, dict):
            return False
        expected = dict(published)
        if classic:
            for key, default in LEGACY_CONFIG_DEFAULTS.items():
                if key in actual and key not in published:
                    if not _same(actual[key], default):
                        return False
                    expected[key] = default
        return _same(actual, expected)

    def _local_image(self, target, architecture, chain):
        reference = IMAGE + "@" + target["image_digest"]
        # Inspect as-is: containerd's --platform inspect reports a selected
        # manifest ID even when create would report the original index ID.
        # Full JSON also works on classic engines without a Descriptor field.
        image = _object(self._command(["image", "inspect", "--format", "{{json .}}", reference]))
        published = chain["image_config"]
        local_id, descriptor = image.get("Id"), image.get("Descriptor")
        variant = image.get("Variant")
        if variant == "":
            variant = None
        if (not isinstance(local_id, str) or not DIGEST.fullmatch(local_id)
                or image.get("Os") != published["os"] or image.get("Architecture") != architecture
                or variant != published.get("variant")
                or not isinstance(image.get("RepoDigests"), list) or reference not in image["RepoDigests"]
                or any(not isinstance(value, str) for value in image["RepoDigests"])
                or not self._local_config_matches(image.get("Config"), published["config"], local_id == chain["config_digest"] and descriptor is None)
                or not _same(image.get("RootFS"), {"Type": published["rootfs"]["type"], "Layers": published["rootfs"]["diff_ids"]})):
            raise ValueError()
        if local_id == chain["config_digest"]:
            # A classic engine has no OCI target descriptor. A fabricated
            # descriptor cannot turn a config ID into a containerd target ID.
            if descriptor is not None:
                raise ValueError()
        else:
            expected = next((item for item in (chain["index_descriptor"], chain["platform_descriptor"])
                             if item["digest"] == local_id), None)
            if expected is None or not isinstance(descriptor, dict):
                raise ValueError()
            base = {"mediaType", "digest", "size"}
            if (not base <= set(descriptor) or set(descriptor) - base - {"platform", "annotations"}
                    or any(not _same(descriptor[key], expected[key]) for key in base)
                    or any(key not in expected or not _same(descriptor[key], expected[key])
                           for key in ("platform", "annotations") if key in descriptor)):
                raise ValueError()
        return {"local_image_id": local_id, "local_image_descriptor": descriptor,
                "execution_reference": IMAGE + ":" + target["version"] + "@" + target["image_digest"],
                "execution_platform": "linux/" + architecture}

    def _probe(self, target, architecture, chain, manifest_raw, baked_history):
        reference = IMAGE + "@" + target["image_digest"]
        self._command(["pull", "--platform=linux/" + architecture, reference], timeout=TIMEOUT)
        binding = self._local_image(target, architecture, chain)
        name = "wayfindr-updater-artifact-" + _hash(str(self.directory).encode())[:24]
        probe = self._command(["create", "--name", name, "--pull=never", "--platform=" + binding["execution_platform"], "--network=none", "--entrypoint", "/bin/true", binding["execution_reference"]]).decode("ascii").strip()
        if not CONTAINER.fullmatch(probe):
            raise ValueError()
        try:
            state = _object(self._command(["inspect", "--format", "{{json .}}", probe]))
            status, container_config, host = state.get("State"), state.get("Config"), state.get("HostConfig")
            descriptor = state.get("ImageManifestDescriptor")
            if (state.get("Id") != probe or state.get("Image") != binding["local_image_id"]
                    or not isinstance(container_config, dict) or container_config.get("Image") != binding["execution_reference"]
                    or container_config.get("Entrypoint") != ["/bin/true"] or not isinstance(host, dict) or host.get("NetworkMode") != "none"
                    or not isinstance(status, dict) or status.get("Status") != "created" or status.get("Running") is not False
                    or type(status.get("Pid")) is not int or status["Pid"] != 0
                    or status.get("StartedAt") != "0001-01-01T00:00:00Z" or not isinstance(state.get("Mounts"), list)
                    or (descriptor is None and binding["local_image_id"] != chain["config_digest"])
                    or (descriptor is not None and not platform_descriptor_matches(descriptor, chain["platform_descriptor"]))):
                raise ValueError()
            # Only the published image's anonymous state volumes may exist.
            # Never attach installation volumes or let an image mask identity.
            expected_volumes = set(chain["image_config"]["config"].get("Volumes", {}))
            if len(state["Mounts"]) != len(expected_volumes) or {item.get("Destination") for item in state["Mounts"] if isinstance(item, dict)} != expected_volumes or any(not isinstance(item, dict) or item.get("Type") != "volume" or not isinstance(item.get("Name"), str) or not CONTAINER.fullmatch(item["Name"]) for item in state["Mounts"]):
                raise ValueError()
            version = self._copy(probe, "/etc/wayfindr/version", "image-version")
            commit = self._copy(probe, "/etc/wayfindr/commit", "image-commit")
            release = self._copy(probe, "/etc/wayfindr/release.json", "image-release.json")
            history = self._copy(probe, "/etc/wayfindr/release-history.json", "image-release-history.json")
            if version not in (target["tag"].encode(), target["tag"].encode() + b"\n") or commit not in (target["commit"].encode(), target["commit"].encode() + b"\n") or release != manifest_raw or not _same(_object(history), baked_history):
                raise ValueError()
            # Recheck after copying: a stale pre-create inspect cannot attest a
            # different local image selected during the never-started probe.
            if not _same(self._local_image(target, architecture, chain), binding):
                raise ValueError()
            return _hash(history), binding
        finally:
            self._command(["rm", "--volumes", probe])

    def prepare(self, target, provenance, architecture, directory):
        try:
            self._begin(target, architecture, directory)
            if not isinstance(provenance, dict) or type(provenance.get("schema")) is not int or provenance["schema"] != 1 or not isinstance(provenance.get("target"), dict) or any(provenance["target"].get(key) != value for key, value in target.items()) or provenance["target"].get("image_reference") != IMAGE + ":" + target["version"] + "@" + target["image_digest"] or provenance["target"].get("platform") != "linux/" + architecture:
                raise ValueError()
            frozen = provenance.get("provenance")
            tag = target["tag"]
            urls = {"release_api_url": API + "/releases/tags/" + tag, "release_url": RELEASES + "/tag/" + tag, "manifest_url": RELEASES + "/download/" + tag + "/release-manifest.json", "digest_url": RELEASES + "/download/" + tag + "/release-image-digest.txt", "history_url": RAW + "/" + target["commit"] + "/releases/history.json"}
            if not isinstance(frozen, dict) or frozen.get("repository") != REPOSITORY or frozen.get("tag") != tag or frozen.get("commit") != target["commit"] or type(frozen.get("release_id")) is not int or frozen["release_id"] < 1 or frozen.get("history_complete") is not True or frozen.get("history_contract_from") != "0.1.0" or frozen.get("history_coverage_basis") != "validated_committed_release_contract" or any(frozen.get(key) != value for key, value in urls.items()) or any(not isinstance(frozen.get(key), str) or not HEX.fullmatch(frozen[key]) for key in ("manifest_sha256", "history_sha256", "digest_asset_sha256")):
                raise ValueError()
            release = _object(self._get(urls["release_api_url"], "release-api.json"))
            if type(release.get("id")) is not int or release["id"] != frozen["release_id"] or release.get("draft") is not False or release.get("prerelease") is not False or release.get("tag_name") != tag or release.get("html_url") != urls["release_url"] or self._tag_commit(tag) != target["commit"]:
                raise ValueError()
            manifest_raw = self._asset(release, tag, "release-manifest.json", frozen["manifest_sha256"])
            manifest = _manifest(_object(manifest_raw))
            if manifest["version"] != target["version"] or manifest["commit"] != target["commit"] or frozen.get("history_floor") != manifest["minimum_upgrade_from"]:
                raise ValueError()
            digest_raw = self._asset(release, tag, "release-image-digest.txt", frozen["digest_asset_sha256"])
            if digest_raw.rstrip(b"\r\n") != target["image_digest"].encode():
                raise ValueError()
            history_raw = self._get(urls["history_url"], "release-history.json", digest="sha256:" + frozen["history_sha256"])
            normalized, baked = self._history(history_raw, manifest)
            declarations = provenance.get("declarations")
            if not isinstance(declarations, dict) or set(declarations) != {"manifest", "history"} or not _same(declarations["manifest"], manifest) or not _same(declarations["history"], normalized["releases"]):
                raise ValueError()
            compose_raw = self._get(RAW + "/" + target["commit"] + "/docker/self-hosting/compose.yml", "compose.yml", maximum=COPY_MAXIMUM)
            if not compose_raw or b"\x00" in compose_raw:
                raise ValueError()
            compose_raw.decode("utf-8")
            chain = self._registry(target, architecture)
            baked_history_sha256, binding = self._probe(target, architecture, chain, manifest_raw, baked)
            evidence = {"schema": 2, "target": dict(target), "index_digest": target["image_digest"], "platform_manifest_digest": chain["platform_manifest_digest"], "config_digest": chain["config_digest"], "platform_manifest_descriptor": chain["platform_descriptor"], **binding,
                        "manifest_sha256": _hash(manifest_raw), "history_sha256": _hash(history_raw), "baked_history_sha256": baked_history_sha256, "digest_asset_sha256": _hash(digest_raw), "compose_sha256": _hash(compose_raw)}
            self._save("artifacts.json", (json.dumps(evidence, sort_keys=True, separators=(",", ":")) + "\n").encode())
            self._deadline()
            return evidence
        except Exception:
            raise self.api.Refusal("artifact_verification_failed") from None

    def verify(self, target, evidence, architecture, directory):
        """Recheck retained evidence and local image identity without mutation.

        Recovery trusts the originally frozen public digests, supplied again by
        the coordinator. It never fetches current release metadata, pulls an
        image, creates a probe, or rewrites an interrupted artifact receipt.
        """
        try:
            self._begin(target, architecture, directory)
            required = {"schema", "target", "index_digest", "platform_manifest_digest", "config_digest", "platform_manifest_descriptor", "local_image_id", "local_image_descriptor", "execution_reference", "execution_platform", "manifest_sha256", "history_sha256", "baked_history_sha256", "digest_asset_sha256", "compose_sha256"}
            if not isinstance(evidence, dict) or set(evidence) != required or type(evidence["schema"]) is not int or evidence["schema"] != 2 or not _same(evidence["target"], target) or evidence["index_digest"] != target["image_digest"]:
                raise ValueError()
            for key in ("index_digest", "platform_manifest_digest", "config_digest", "local_image_id"):
                if not isinstance(evidence[key], str) or not DIGEST.fullmatch(evidence[key]):
                    raise ValueError()
            for key in ("manifest_sha256", "history_sha256", "baked_history_sha256", "digest_asset_sha256", "compose_sha256"):
                if not isinstance(evidence[key], str) or not HEX.fullmatch(evidence[key]):
                    raise ValueError()
            receipt = self._read("artifacts.json")
            if receipt != (json.dumps(evidence, sort_keys=True, separators=(",", ":")) + "\n").encode():
                raise ValueError()
            manifest_raw = self._read("release-manifest.json", digest="sha256:" + evidence["manifest_sha256"])
            manifest = _manifest(_object(manifest_raw))
            if manifest["version"] != target["version"] or manifest["commit"] != target["commit"]:
                raise ValueError()
            digest_raw = self._read("release-image-digest.txt", digest="sha256:" + evidence["digest_asset_sha256"])
            if digest_raw.rstrip(b"\r\n") != target["image_digest"].encode():
                raise ValueError()
            history_raw = self._read("release-history.json", digest="sha256:" + evidence["history_sha256"])
            _, baked = self._history(history_raw, manifest)
            compose_raw = self._read("compose.yml", maximum=COPY_MAXIMUM, digest="sha256:" + evidence["compose_sha256"])
            if b"\x00" in compose_raw:
                raise ValueError()
            compose_raw.decode("utf-8")
            release = _object(self._read("release-api.json"))
            if type(release.get("id")) is not int or release["id"] < 1 or release.get("draft") is not False or release.get("prerelease") is not False or release.get("tag_name") != target["tag"] or release.get("html_url") != RELEASES + "/tag/" + target["tag"] or self._tag_commit(target["tag"], retained=True) != target["commit"]:
                raise ValueError()
            self._asset_url(release, target["tag"], "release-manifest.json", evidence["manifest_sha256"])
            self._asset_url(release, target["tag"], "release-image-digest.txt", evidence["digest_asset_sha256"])
            def read(_kind, digest, name, size):
                return self._read(name, digest=digest, size=size)
            chain = self._chain(target, architecture, self._read("image-index.json", digest=target["image_digest"]), read)
            if (chain["platform_manifest_digest"] != evidence["platform_manifest_digest"] or chain["config_digest"] != evidence["config_digest"]
                    or not _same(chain["platform_descriptor"], evidence["platform_manifest_descriptor"])):
                raise ValueError()
            version = self._read("image-version", maximum=129)
            commit = self._read("image-commit", maximum=65)
            release_raw = self._read("image-release.json", maximum=COPY_MAXIMUM)
            baked_raw = self._read("image-release-history.json", maximum=COPY_MAXIMUM, digest="sha256:" + evidence["baked_history_sha256"])
            if version not in (target["tag"].encode(), target["tag"].encode() + b"\n") or commit not in (target["commit"].encode(), target["commit"].encode() + b"\n") or release_raw != manifest_raw or not _same(_object(baked_raw), baked):
                raise ValueError()
            binding = self._local_image(target, architecture, chain)
            if not _same(binding, {key: evidence[key] for key in binding}):
                raise ValueError()
            self._deadline()
            return evidence
        except Exception:
            raise self.api.Refusal("artifact_verification_failed") from None


if __name__ == "__main__":
    # Private transport protocol. The root coordinator remains the public CLI.
    try:
        if sys.argv[1:] != ["--fetch"]:
            raise ValueError()
        raw = sys.stdin.buffer.read(65_537)
        if len(raw) > 65_536:
            raise ValueError()
        sys.stdout.buffer.write(_worker(_object(raw)))
    except Exception:
        raise SystemExit(78) from None
