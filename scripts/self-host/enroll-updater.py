#!/usr/bin/env python3
"""Explicit installation of the restricted preparation, protection and apply host helper.

No container is started or replaced here. Existing enrollments are never changed:
helper replacement, credential rotation, and unenrollment require their own
ownership-aware workflow. Root must first adopt and review a supported install.
"""

from __future__ import annotations

import argparse
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


CONFIG_DIR = Path("/etc/wayfindr-updater")
STATE_DIR = Path("/var/lib/wayfindr-updater")
CODE_DIR = Path("/usr/local/lib/wayfindr-updater")
CLI_FILE = Path("/usr/local/bin/wayfindr-updater")
UNIT_FILE = Path("/etc/systemd/system/wayfindr-updater.service")
OFFICIAL_IMAGE = re.compile(
    r"\Aghcr\.io/adamgreenwell/wayfindr:v?(?:0|[1-9][0-9]*)"
    r"\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)"
    r"(?:@sha256:[a-f0-9]{64})?\Z"
)
APP_SERVICES = ("web", "queue", "backup-queue", "scheduler", "reverb")


class EnrollmentError(Exception):
    """An operator-safe refusal. Never contain Docker output or environment."""


def trusted(path: Path, kind: str = "file") -> None:
    """Reject symlinks and writable or unowned ancestors, including the leaf."""
    if not path.is_absolute() or ".." in path.parts:
        raise EnrollmentError("Managed paths must be absolute and canonical.")
    cursor = Path(path.anchor)
    for part in path.parts[1:]:
        cursor /= part
        try:
            information = cursor.lstat()
        except OSError:
            raise EnrollmentError("A required trusted managed path is absent.") from None
        if stat.S_ISLNK(information.st_mode):
            raise EnrollmentError("Managed paths and their ancestors cannot be symlinks.")
        if information.st_uid != 0 or information.st_mode & 0o022:
            raise EnrollmentError("Managed paths and every ancestor must be root-owned and not group/other writable.")
        expected_directory = cursor != path or kind == "directory"
        if expected_directory and not stat.S_ISDIR(information.st_mode):
            raise EnrollmentError("A managed path ancestor is not a directory.")
        if not expected_directory and not stat.S_ISREG(information.st_mode):
            raise EnrollmentError("A managed input must be a regular file.")


def file_hash(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def host_supported() -> None:
    if platform.system() != "Linux" or os.geteuid() != 0:
        raise EnrollmentError("Enrollment requires root on a supported Linux systemd host.")
    if sys.version_info < (3, 11):
        raise EnrollmentError("Python 3.11 or newer is required.")
    if not Path("/run/systemd/system").is_dir() or not Path("/usr/bin/systemctl").is_file():
        raise EnrollmentError("A running systemd host is required.")
    if not Path("/usr/bin/python3").is_file():
        raise EnrollmentError("The helper requires /usr/bin/python3.")
    if platform.machine().lower() not in {"x86_64", "amd64", "aarch64", "arm64"}:
        raise EnrollmentError("Managed helpers support Linux amd64 and arm64 only.")
    run(["/usr/bin/python3", "-c", "import sys; raise SystemExit(0 if sys.version_info >= (3, 11) else 1)"])


def run(command: list[str], *, json_output: bool = False) -> object:
    """No shell, context, environment, credentials, or unsafe error output."""
    try:
        result = subprocess.run(
            command,
            env={"PATH": "/usr/bin:/bin:/usr/sbin:/sbin", "LANG": "C"},
            capture_output=True,
            stdin=subprocess.DEVNULL,
            text=True,
            check=False,
            timeout=30,
        )
    except (OSError, subprocess.TimeoutExpired):
        raise EnrollmentError("A required host validation command failed or timed out.") from None
    if result.returncode != 0:
        raise EnrollmentError("Host validation failed; inspect Docker or systemd directly for details.")
    if not json_output:
        return result.stdout.strip()
    try:
        return json.loads(result.stdout)
    except (ValueError, TypeError):
        raise EnrollmentError("Host validation returned malformed metadata.") from None


def docker_command() -> list[str]:
    # Enrollment and the supervisor must select the same fixed host binary.
    binary = Path("/usr/bin/docker")
    trusted(binary)
    return [str(binary), "--host", "unix:///var/run/docker.sock", "--config", str(CONFIG_DIR / "docker")]


def inspect_install(install_dir: Path, canonical_compose: Path, docker: list[str]) -> str:
    trusted(install_dir, "directory")
    for filename in ("compose.yml", ".env"):
        trusted(install_dir / filename)
    if file_hash(install_dir / "compose.yml") != file_hash(canonical_compose):
        raise EnrollmentError("Enrollment accepts the reviewed official Compose file byte-for-byte; custom Compose remains externally managed.")
    info = run(docker + ["info", "--format", "{{json .}}"], json_output=True)
    if not isinstance(info, dict) or not isinstance(info.get("SecurityOptions"), list):
        raise EnrollmentError("Docker security configuration cannot be verified.")
    if any("rootless" in str(option).lower() or "userns" in str(option).lower() for option in info["SecurityOptions"]):
        raise EnrollmentError("Rootless Docker and user namespace remapping are not qualified for managed enrollment.")
    compose = docker + ["compose", "--project-directory", str(install_dir), "--env-file", str(install_dir / ".env"), "-f", str(install_dir / "compose.yml")]
    rendered = run(compose + ["config", "--format", "json"], json_output=True)
    if not isinstance(rendered, dict) or rendered.get("name") != "wayfindr-self-hosting" or not isinstance(rendered.get("services"), dict):
        raise EnrollmentError("The official Compose project could not be verified.")
    services = rendered["services"]
    web = services.get("web")
    if not isinstance(web, dict):
        raise EnrollmentError("The official web service could not be verified.")
    image = web.get("image")
    if not isinstance(image, str) or OFFICIAL_IMAGE.fullmatch(image) is None:
        raise EnrollmentError("An exact stable official release image is required; floating or custom images remain externally managed.")
    image_metadata = run(docker + ["image", "inspect", image], json_output=True)
    if not isinstance(image_metadata, list) or len(image_metadata) != 1 or not isinstance(image_metadata[0], dict):
        raise EnrollmentError("The installed image identity cannot be verified.")
    image_record = image_metadata[0]
    image_id = image_record.get("Id")
    if not isinstance(image_id, str) or re.fullmatch(r"sha256:[a-f0-9]{64}", image_id) is None:
        raise EnrollmentError("The installed image identity cannot be verified.")
    image_config = image_record.get("Config")
    if not isinstance(image_config, dict) or image_config.get("User") not in ("wayfindr", "1000", "1000:1000"):
        raise EnrollmentError("The official non-root application image user cannot be verified.")
    if image_record.get("Os") != "linux" or image_record.get("Architecture") not in {"amd64", "arm64"}:
        raise EnrollmentError("The installed image platform is unsupported.")
    expected_arch = "amd64" if platform.machine().lower() in {"amd64", "x86_64"} else "arm64"
    if image_record.get("Architecture") != expected_arch:
        raise EnrollmentError("The installed image platform differs from the host.")
    for name in APP_SERVICES:
        service = services.get(name)
        if not isinstance(service, dict) or service.get("image") != image or service.get("user") not in (None, "wayfindr", "1000", "1000:1000"):
            raise EnrollmentError("All application services must use the same official non-root image.")
        container_id = run(compose + ["ps", "-q", name])
        if not isinstance(container_id, str) or re.fullmatch(r"[a-f0-9]{12,64}", container_id) is None:
            raise EnrollmentError("All official application services must already be running.")
        inspected = run(docker + ["inspect", container_id], json_output=True)
        if not isinstance(inspected, list) or len(inspected) != 1 or not isinstance(inspected[0], dict):
            raise EnrollmentError("Running application service metadata cannot be verified.")
        container = inspected[0]
        container_config = container.get("Config")
        container_state = container.get("State")
        host_config = container.get("HostConfig")
        if not isinstance(container_config, dict) or not isinstance(container_state, dict) or not isinstance(host_config, dict):
            raise EnrollmentError("Running application service metadata is malformed.")
        labels = container_config.get("Labels")
        if not isinstance(labels, dict) or container.get("Image") != image_id or container_state.get("Running") is not True or labels.get("com.docker.compose.project") != "wayfindr-self-hosting" or labels.get("com.docker.compose.service") != name:
            raise EnrollmentError("Running containers differ from the reviewed official installation.")
        if container_config.get("User") not in ("wayfindr", "1000", "1000:1000") or host_config.get("UsernsMode") != "":
            raise EnrollmentError("Running application user mapping cannot be verified.")
        verify_process_identity(container_state.get("Pid"))
    # This draft helper cannot turn an older published image into a compatible
    # application. The fixed command is read-only and introduces no helper gate
    # into existing terminal updates.
    for command in ("wayfindr:update-plan", "wayfindr:upgrade-window", "wayfindr:protective-backup", "wayfindr:managed-apply"):
        run(compose + ["exec", "-T", "web", "php", "artisan", command, "--help"])
    contract = run(compose + ["exec", "-T", "web", "php", "artisan", "wayfindr:updater-status", "--protocol-contract"], json_output=True)
    expected = {"schema": 1, "protocol": 1, "minimum_helper_version": "0.4.0", "capabilities": ["plan", "status", "start", "history", "cancel"]}
    if not isinstance(contract, dict) or contract != expected or type(contract.get("schema")) is not int or type(contract.get("protocol")) is not int:
        raise EnrollmentError("The running application does not support the reviewed operator update protocol.")
    return image


def verify_process_identity(pid: object) -> None:
    if type(pid) is not int or pid <= 0:
        raise EnrollmentError("Running application process identity cannot be verified.")
    try:
        status = Path(f"/proc/{pid}/status").read_text()
    except OSError:
        raise EnrollmentError("Running application process identity cannot be verified.") from None
    fields = dict(line.split(":", 1) for line in status.splitlines() if ":" in line)
    if fields.get("Uid", "").split() != ["1000"] * 4 or fields.get("Gid", "").split() != ["1000"] * 4:
        raise EnrollmentError("The application must map directly to host UID:GID 1000:1000.")


def write_new(path: Path, content: bytes, mode: int, gid: int = 0) -> None:
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    try:
        os.fchmod(descriptor, mode)
        os.fchown(descriptor, 0, gid)
        with os.fdopen(descriptor, "wb", closefd=False) as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
    finally:
        os.close(descriptor)
    directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(directory)
    finally:
        os.close(directory)


def json_bytes(value: dict) -> bytes:
    return (json.dumps(value, sort_keys=True, indent=2) + "\n").encode()


def load_runtime(source: Path):
    # This source is explicitly reviewed and root-owned before execution.
    # Importing its pure helpers keeps enrollment and journal/protocol schemas
    # aligned without writing a bytecode cache into the distribution.
    runtime = types.ModuleType("wayfindr_updater_enrollment_runtime")
    runtime.__file__ = str(source)
    exec(compile(source.read_bytes(), str(source), "exec"), vars(runtime))
    return runtime


def verify_started(runtime, installation_id: str, token: str) -> None:
    deadline = time.monotonic() + 5
    while time.monotonic() < deadline:
        try:
            runtime.trusted(runtime.SOCKET, socket_node=True)
            nonce = secrets.token_hex(16)
            request = {"protocol": 1, "installation_id": installation_id, "nonce": nonce,
                       "issued_at": int(time.time()), "action": "capabilities"}
            with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:
                connection.settimeout(min(0.25, max(0.01, deadline - time.monotonic())))
                connection.connect(str(runtime.SOCKET))
                _, peer_uid, _ = struct.unpack("3i", connection.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, struct.calcsize("3i")))
                if peer_uid != 0:
                    raise EnrollmentError("The helper socket peer is not trusted root.")
                connection.sendall(runtime.envelope(request, token, "request"))
                reply = bytearray()
                while not reply.endswith(b"\n"):
                    chunk = connection.recv(min(4096, runtime.RESPONSE_MAX + 1 - len(reply)))
                    if not chunk or len(reply) + len(chunk) > runtime.RESPONSE_MAX or b"\n" in chunk[:-1]:
                        raise EnrollmentError("The helper startup response is invalid.")
                    reply.extend(chunk)
            response = runtime.unpack_envelope(bytes(reply), token, "response")
            if set(response) != {"protocol", "installation_id", "nonce", "ok", "result"} or type(response["protocol"]) is not int or response["protocol"] != 1 or response["installation_id"] != installation_id or response["nonce"] != nonce or response["ok"] is not True:
                raise EnrollmentError("The helper startup response is invalid.")
            capability = response["result"]
            if not isinstance(capability, dict) or capability.get("installation_id") != installation_id or capability.get("ownership") != "installer-managed" or capability.get("enrolled") is not True or not isinstance(capability.get("helper"), dict) or capability["helper"].get("capabilities") != ["plan", "status", "start", "history", "cancel"]:
                raise EnrollmentError("The helper startup capability report is invalid.")
            offline = runtime.Journal(STATE_DIR / "journal.json", installation_id).status()
            if offline.get("installation_id") != installation_id or not runtime.is_uuid(offline.get("generation")):
                raise EnrollmentError("The helper startup generation has not been recorded.")
            return
        except (OSError, runtime.Refusal, EnrollmentError):
            # A bounded startup wait tolerates systemd's start scheduling; no
            # identity or files are replaced on failure and no secret is logged.
            time.sleep(min(0.1, max(0, deadline - time.monotonic())))
    raise EnrollmentError("The helper did not complete authenticated startup within five seconds. Host artifacts were preserved for inspection.")


def enrollment_status() -> dict:
    configuration = CONFIG_DIR / "installation.json"
    if not configuration.exists():
        return {"enrolled": False, "application_apply_available": False}
    trusted(configuration)
    try:
        config = json.loads(configuration.read_text())
        if not isinstance(config, dict) or not isinstance(config.get("installation_id"), str):
            raise EnrollmentError("Existing enrollment metadata is invalid; no files were changed.")
        installation_id = str(uuid.UUID(config["installation_id"]))
    except (OSError, ValueError, TypeError, KeyError):
        raise EnrollmentError("Existing enrollment metadata is invalid; no files were changed.") from None
    expected_fields = {"schema", "installation_id", "install_dir", "compose_project", "client_uid", "client_gid", "image_reference", "compose_sha256", "env_sha256", "installer_sha256"}
    if not isinstance(config, dict) or set(config) not in (expected_fields, expected_fields | {"overlay_sha256"}) or type(config.get("schema")) is not int or config["schema"] != 1 or config["installation_id"] != installation_id or config["compose_project"] != "wayfindr-self-hosting" or type(config["client_uid"]) is not int or config["client_uid"] != 1000 or type(config["client_gid"]) is not int or config["client_gid"] != 1000 or not isinstance(config["image_reference"], str) or OFFICIAL_IMAGE.fullmatch(config["image_reference"]) is None:
        raise EnrollmentError("Existing enrollment metadata is invalid; no files were changed.")
    if any(not isinstance(config[field], str) or re.fullmatch(r"[a-f0-9]{64}", config[field]) is None for field in ("compose_sha256", "env_sha256", "installer_sha256")):
        raise EnrollmentError("Existing enrollment metadata is invalid; no files were changed.")
    if "overlay_sha256" in config and (not isinstance(config["overlay_sha256"], str) or re.fullmatch(r"[a-f0-9]{64}", config["overlay_sha256"]) is None):
        raise EnrollmentError("Existing enrollment metadata is invalid; no files were changed.")
    if not isinstance(config["install_dir"], str) or not Path(config["install_dir"]).is_absolute() or ".." in Path(config["install_dir"]).parts:
        raise EnrollmentError("Existing enrollment metadata is invalid; no files were changed.")
    credential = CONFIG_DIR / "credential.json"
    trusted(credential)
    try:
        auth = json.loads(credential.read_text())
    except (OSError, ValueError):
        raise EnrollmentError("Existing enrollment credentials are invalid; no files were changed.") from None
    if not isinstance(auth, dict) or set(auth) != {"schema", "installation_id", "token"} or type(auth.get("schema")) is not int or auth["schema"] != 1 or auth.get("installation_id") != installation_id or not isinstance(auth.get("token"), str) or re.fullmatch(r"[a-f0-9]{64}", auth["token"]) is None:
        raise EnrollmentError("Existing enrollment credentials are invalid; no files were changed.")
    return {"enrolled": True, "installation_id": installation_id, "application_apply_available": False, "helper_replacement_available": False}


def unit_install_path(install_dir: Path) -> str:
    value = str(install_dir)
    if not install_dir.is_absolute() or install_dir == Path("/") or ".." in install_dir.parts or re.fullmatch(r"/[A-Za-z0-9_./-]+", value) is None:
        raise EnrollmentError("--install-dir must be an absolute canonical directory using ASCII letters, numbers, slashes, dots, underscores or hyphens; whitespace and systemd expansion syntax are unsupported.")
    # The accepted alphabet excludes quoting, escapes, specifiers and variable
    # expansion. Quoting this literal is safe in systemd's path-list grammar.
    return value


def render_unit(template: bytes, install_dir: Path) -> bytes:
    path = unit_install_path(install_dir).encode("ascii")
    marker = b"__WAYFINDR_INSTALL_DIR__"
    if template.count(marker) != 1:
        raise EnrollmentError("The reviewed systemd template has no unique installation path marker.")
    return template.replace(marker, path)


def enroll(install_dir: Path) -> dict:
    host_supported()
    existing = enrollment_status()
    if existing["enrolled"]:
        raise EnrollmentError("This host is already enrolled. Existing identity, credentials, code and service were preserved; use status to inspect it.")
    unit_install_path(install_dir)
    if any(install_dir == protected or protected in install_dir.parents for protected in (Path("/home"), Path("/root"), Path("/run/user"), CONFIG_DIR, STATE_DIR, CODE_DIR, Path("/run/wayfindr-updater"))):
        raise EnrollmentError("The installation must be outside protected home and helper directories; use a reviewed root-owned directory such as /opt/wayfindr.")
    # Enrollment is deliberately conservative: the daemon's future privileged
    # inputs and the distribution must already be held by root, not adopted here.
    distribution = Path(__file__).absolute().parents[2]
    sources = {
        "compose": distribution / "docker/self-hosting/compose.yml",
        "overlay": distribution / "docker/self-hosting/compose.updater.yml",
        "unit": distribution / "docker/self-hosting/wayfindr-updater.service",
        "daemon": distribution / "scripts/self-host/updater.py",
        "protection": distribution / "scripts/self-host/update_protection.py",
        "apply": distribution / "scripts/self-host/update_apply.py",
        "artifacts": distribution / "scripts/self-host/update_artifacts.py",
        "archive": distribution / "scripts/self-host/protection_archive.py",
        "installer": distribution / "scripts/self-host/install.sh",
    }
    for source in sources.values():
        trusted(source)
    unit = render_unit(sources["unit"].read_bytes(), install_dir)
    trusted(install_dir, "directory")
    trusted(install_dir / "compose.yml")
    trusted(install_dir / ".env")
    trusted(install_dir / "install.sh")
    if file_hash(install_dir / "install.sh") != file_hash(sources["installer"]):
        raise EnrollmentError("The installed controller must match the reviewed guarded install.sh; enrollment never silently replaces it.")
    for path in (install_dir / ".updater-enrolled", install_dir / "compose.updater.yml", CODE_DIR, CLI_FILE, UNIT_FILE, STATE_DIR):
        if path.exists() or path.is_symlink():
            raise EnrollmentError("Existing helper artifacts require a separate recovery or replacement workflow; no artifacts were overwritten.")
        trusted(path.parent, "directory")
    if CONFIG_DIR.exists():
        trusted(CONFIG_DIR, "directory")
        if any(CONFIG_DIR.iterdir()):
            raise EnrollmentError("Existing helper configuration requires explicit recovery; no artifacts were overwritten.")
    else:
        trusted(CONFIG_DIR.parent, "directory")
    # This is the only early filesystem preparation: isolated empty Docker
    # configuration. Failure does not alter the application or expose credentials.
    configuration_preexisted = CONFIG_DIR.exists()
    CONFIG_DIR.mkdir(mode=0o700, exist_ok=True)
    (CONFIG_DIR / "docker").mkdir(mode=0o700)
    try:
        image_reference = inspect_install(install_dir, sources["compose"], docker_command())
    except Exception:
        (CONFIG_DIR / "docker").rmdir()
        if not configuration_preexisted:
            CONFIG_DIR.rmdir()
        raise
    installation_id = str(uuid.uuid4())
    runtime = load_runtime(sources["daemon"])
    overlay = sources["overlay"].read_bytes().replace(b"__WAYFINDR_INSTALLATION_ID__", installation_id.encode("ascii"))
    config = {
        "schema": 1,
        "installation_id": installation_id,
        "install_dir": str(install_dir),
        "compose_project": "wayfindr-self-hosting",
        "client_uid": 1000,
        "client_gid": 1000,
        "image_reference": image_reference,
        "compose_sha256": file_hash(install_dir / "compose.yml"),
        "env_sha256": file_hash(install_dir / ".env"),
        "installer_sha256": file_hash(install_dir / "install.sh"),
        "overlay_sha256": hashlib.sha256(overlay).hexdigest(),
    }
    credential = {"schema": 1, "installation_id": installation_id, "token": secrets.token_hex(32)}
    CODE_DIR.mkdir(mode=0o755)
    STATE_DIR.mkdir(mode=0o700)
    write_new(STATE_DIR / "journal.json", json_bytes(runtime.initial_journal(installation_id)), 0o600)
    write_new(CODE_DIR / "updater.py", sources["daemon"].read_bytes(), 0o644)
    write_new(CODE_DIR / "update_protection.py", sources["protection"].read_bytes(), 0o644)
    write_new(CODE_DIR / "update_apply.py", sources["apply"].read_bytes(), 0o644)
    write_new(CODE_DIR / "update_artifacts.py", sources["artifacts"].read_bytes(), 0o644)
    write_new(CODE_DIR / "protection_archive.py", sources["archive"].read_bytes(), 0o644)
    write_new(CLI_FILE, b'#!/bin/sh\nexec /usr/bin/python3 /usr/local/lib/wayfindr-updater/updater.py "$@"\n', 0o755)
    write_new(CONFIG_DIR / "installation.json", json_bytes(config), 0o600)
    write_new(CONFIG_DIR / "credential.json", json_bytes(credential), 0o440, 1000)
    write_new(install_dir / "compose.updater.yml", overlay, 0o644)
    write_new(install_dir / ".updater-enrolled", (installation_id + "\n").encode(), 0o600)
    write_new(UNIT_FILE, unit, 0o644)
    # An interrupted enrollment retains root-owned evidence and refuses a rerun;
    # it never overwrites a credential or active helper to 'repair' the failure.
    run(["/usr/bin/systemctl", "daemon-reload"])
    run(["/usr/bin/systemctl", "enable", "--now", "wayfindr-updater.service"])
    run(["/usr/bin/systemctl", "is-active", "--quiet", "wayfindr-updater.service"])
    verify_started(runtime, installation_id, credential["token"])
    return {"enrolled": True, "installation_id": installation_id, "application_apply_available": False, "overlay": str(install_dir / "compose.updater.yml"), "activation_required": True}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    enrollment = commands.add_parser("enroll", help="Install the host updater; do not start or recreate application containers.")
    enrollment.add_argument("--install-dir", required=True, type=Path)
    commands.add_parser("status", help="Read enrollment identity without changing helper or application files.")
    options = parser.parse_args()
    try:
        result = enroll(options.install_dir) if options.command == "enroll" else enrollment_status()
    except EnrollmentError as error:
        print(f"Enrollment refused: {error}", file=sys.stderr)
        return 1
    except OSError:
        print("Enrollment failed while writing trusted host files. Existing artifacts were preserved for explicit recovery.", file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True))
    if result.get("activation_required"):
        print("Review compose.updater.yml, then activate it explicitly from the installation directory:", file=sys.stderr)
        print("  sudo docker --host unix:///var/run/docker.sock --config /etc/wayfindr-updater/docker compose --env-file .env -f compose.yml -f compose.updater.yml up -d web", file=sys.stderr)
        print("Managed application updates require a compatible enrolled helper and authorized platform operator.", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
